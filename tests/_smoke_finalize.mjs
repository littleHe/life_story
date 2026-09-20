// 定稿 finalize + 翻书预览页 冒烟
// 运行： node backend/tests/_smoke_finalize.mjs
const BASE = 'http://127.0.0.1:9411/api'
let token = ''
async function call(path, { method = 'GET', body, auth = true } = {}) {
  const headers = { 'Content-Type': 'application/json' }
  if (auth && token) headers['Authorization'] = 'Bearer ' + token
  const res = await fetch(BASE + path, { method, headers, body: body ? JSON.stringify(body) : undefined })
  const txt = await res.text()
  let json; try { json = JSON.parse(txt) } catch { json = { raw: txt } }
  return { status: res.status, json }
}
const b64 = (s) => Buffer.from(s).toString('base64')
let failures = 0
const step = (ok, name, extra) => { if (!ok) failures++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra ? '  ' + extra : ''}`) }

;(async () => {
  let r = await call('/auth/test/login', { method: 'POST', auth: false })
  token = r.json?.data?.access_token || ''
  step(!!token, '登录')

  r = await call('/projects', { method: 'POST', body: {
    name: 'SMOKE_翻书预览', real_name: '张丽丽', birth: '1950-06-15',
    native_place: '广东清远', description: '一位普通母亲的一生，平凡而温暖。',
    code: '66666688',
  } })
  const pid = r.json?.data?.id
  step(!!pid, '建项目', `pid=${pid}`)

  r = await call(`/projects/${pid}/chapters`, { method: 'PATCH', body: { action: 'add', title: '第一章 童年' } })
  const cid = r.json?.data?.chapter_id
  const s1 = (await call(`/chapters/${cid}/recording`, { method: 'POST', body: { audio_base64: b64('aa'), audio_ext: 'webm', duration: '00:05' } })).json?.data
  await call(`/chapters/${cid}/asr`, { method: 'POST', body: { asset_id: s1?.asset_id } })
  await call(`/chapters/${cid}/save`, { method: 'POST', body: { title: '第一章 童年', transcript: '我出生在一个小山村，童年的记忆里有炊烟和晚霞。', polished: '山村的炊烟与晚霞，是我童年最柔软的记忆。' } })
  step(!!cid, '章节+录音+转写+保存', `cid=${cid}`)

  // 未定稿前 status 非 MAKING
  let proj = (await call(`/projects/${pid}`)).json?.data
  step(proj.status !== 'MAKING', '定稿前状态不是 MAKING', 'status=' + proj.status)

  // 定稿
  r = await call(`/projects/${pid}/finalize`, { method: 'POST', body: {} })
  const fin = r.json?.data
  step(r.status === 200 && r.json?.code === 0, 'finalize 接口成功', r.json?.msg)
  step(fin?.status === 'MAKING', '定稿后状态 = MAKING', 'status=' + fin?.status)
  step(!!fin?.preview_url && fin.preview_url.indexOf('/preview/') >= 0, '生成预览地址', fin?.preview_url)
  step(!!fin?.cover_bg && fin.cover_bg.indexOf('/uploads/cover-bg/') >= 0, '未上传封面 → 从图池随机取封面背景', fin?.cover_bg)

  // 二次定稿幂等（同一 token）
  const fin2 = (await call(`/projects/${pid}/finalize`, { method: 'POST', body: {} })).json?.data
  step(fin2?.preview_token === fin?.preview_token, '重复定稿 token 幂等', fin2?.preview_token)

  // GET 项目：状态与预览地址已落库
  proj = (await call(`/projects/${pid}`)).json?.data
  step(proj.status === 'MAKING' && proj.preview_url === fin.preview_url && !!proj.finalized_at, '项目详情已落库(MAKING/url/finalized_at)')

  // 打开翻书预览页（公开、无需登录）
  const pv = await fetch(fin.preview_url)
  const html = await pv.text()
  step(pv.status === 200, '预览页可公开访问(200)', 'http=' + pv.status)
  step(/class="page /.test(html) && /翻书欣赏/.test(html), '预览页含翻书结构(书页+欣赏标题)')
  step(html.indexOf('SMOKE_翻书预览') >= 0, '封面含书名')
  step(html.indexOf('张丽丽') >= 0 && html.indexOf('广东清远') >= 0, '封面含用户基本信息(姓名/籍贯)')
  step(html.indexOf('一位普通母亲的一生') >= 0, '封面含简介')
  step(html.indexOf('第一章 童年') >= 0 && html.indexOf('山村的炊烟') >= 0, '含章节页(标题+正文)')
  step(html.indexOf(fin.cover_bg) >= 0, '封面用了选定的背景图')

  // 失效 token → 404
  const bad = await fetch(fin.preview_url.replace(fin.preview_token, 'deadbeefdeadbeef'))
  step(bad.status === 404, '无效 token 返回 404', 'http=' + bad.status)

  // 无口述内容项目定稿 → 422（新建项目默认带 6 个空系统章节，故此处因缺内容被拒 42206）
  const p2 = (await call('/projects', { method: 'POST', body: { name: 'SMOKE_空项目', code: '66666688' } })).json?.data?.id
  const empty = await call(`/projects/${p2}/finalize`, { method: 'POST', body: {} })
  step(empty.json?.code === 42206, '无口述内容定稿被拒(42206)', 'code=' + empty.json?.code)

  console.log('\n' + (failures === 0 ? '✅ 全部通过' : `❌ 失败 ${failures} 项`))
  console.log(`CLEANUP pid=${pid} pid2=${p2}`)
  process.exit(failures === 0 ? 0 : 1)
})().catch((e) => { console.error('异常:', e); process.exit(2) })
