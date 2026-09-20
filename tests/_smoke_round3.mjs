// 本轮改动冒烟：登录续期 / 后台参数 / 后台项目状态 / 预览页（自动翻页配置+朗读数据+公司信息）
// 运行：node backend/tests/_smoke_round3.mjs
const API = 'http://127.0.0.1:9411'
let token = '', refresh = '', sess = ''
let failures = 0
const step = (ok, name, extra) => { if (!ok) failures++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra ? '  ' + extra : ''}`) }
const b64 = (s) => Buffer.from(s).toString('base64')

async function api(path, { method = 'GET', body, admin = false } = {}) {
  const headers = { 'Content-Type': 'application/json' }
  if (admin && sess) headers['Cookie'] = sess
  else if (!admin && token) headers['Authorization'] = 'Bearer ' + token
  const res = await fetch(API + path, { method, headers, body: body ? JSON.stringify(body) : undefined })
  const json = await res.json().catch(() => ({}))
  return { status: res.status, json }
}

;(async () => {
  // 1. 登录拿到 access + refresh
  let r = await api('/api/auth/test/login', { method: 'POST', body: {}, auth: false }).catch(() => null)
  {
    const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json()
    token = lr?.data?.access_token; refresh = lr?.data?.refresh_token
    step(!!token && !!refresh, '登录拿到 access+refresh', 'exp=' + lr?.data?.expires_in + 's')
  }

  // 2. refresh 换发新 access（静默续期链路）
  {
    const rr = await (await fetch(API + '/api/auth/refresh', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ refresh_token: refresh }) })).json()
    step(rr?.code === 0 && !!rr?.data?.access_token, 'refresh 静默续期可用', 'exp=' + rr?.data?.expires_in + 's')
    step(Number(rr?.data?.expires_in) > 86400, 'access 有效期已延长(>1天)', 'exp=' + rr?.data?.expires_in + 's')
  }

  // 3. 后台登录 + 参数设置
  {
    const lr = await fetch(API + '/admin-api/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: 'admin', password: 'admin888' }) })
    const sc = lr.headers.getSetCookie ? lr.headers.getSetCookie() : [lr.headers.get('set-cookie') || '']
    sess = (sc.find((c) => c.startsWith('LSSESSID=')) || sc[0] || '').split(';')[0]
    step(sess.startsWith('LSSESSID='), '后台登录', sess.slice(0, 20))
    const cfg = await api('/admin-api/config/index', { admin: true })
    step(cfg.json?.code === 0 && (cfg.json?.data?.list || []).length >= 5, '参数列表可读', (cfg.json?.data?.list || []).length + ' 项')
    const sv = await api('/admin-api/config/save', { method: 'POST', admin: true, body: { config: { preview_auto_flip_seconds: '20', company_name: '故事传承文化有限公司', service_phone: '400-000-0000', company_email: 'hi@example.com', company_address: '广东清远' } } })
    step(sv.json?.code === 0, '参数保存成功', sv.json?.msg)
    const cfg2 = await api('/admin-api/config/index', { admin: true })
    const map = Object.fromEntries((cfg2.json?.data?.list || []).map((x) => [x.key, x.value]))
    step(map.preview_auto_flip_seconds === '20', '自动翻页秒数已更新', map.preview_auto_flip_seconds)
  }

  // 4. 造一本 3 章的书并定稿
  const pr = await api('/api/projects', { method: 'POST', body: { name: 'SMOKE_翻书升级', code: '66666688', real_name: '张丽丽', birth: '1950-06-15', native_place: '广东清远', description: '一位普通母亲的一生。' } })
  const pid = pr.json?.data?.id
  for (const [i, t] of ['童年', '青年', '晚年'].entries()) {
    const cid = (await api(`/api/projects/${pid}/chapters`, { method: 'PATCH', body: { action: 'add', title: `第${i + 1}章 ${t}` } })).json?.data?.chapter_id
    const s = (await api(`/api/chapters/${cid}/recording`, { method: 'POST', body: { audio_base64: b64('seg' + i), audio_ext: 'webm', duration: '00:04' } })).json?.data
    await api(`/api/chapters/${cid}/asr`, { method: 'POST', body: { asset_id: s?.asset_id } })
    await api(`/api/chapters/${cid}/save`, { method: 'POST', body: { polished: `这是第${i + 1}章的润色正文，讲述${t}岁月的故事。` } })
  }
  const finResp = await api(`/api/projects/${pid}/finalize`, { method: 'POST', body: {} })
  const fin = finResp.json?.data
  if (!fin) console.log('  [debug finalize]', finResp.status, JSON.stringify(finResp.json).slice(0, 300))
  step(fin?.status === 'MAKING', '定稿成功', fin?.preview_url || JSON.stringify(finResp.json?.msg))

  // 5. 预览页：多页 + 自动翻页配置 + 朗读数据 + 公司信息页脚
  //    说明：新建项目会默认生成 6 个系统章节，叠加本测试新增的 3 章，共 9 章；
  //          预览页 = 封面(1) + 9 章 + 尾页(1) = 11 页；bookData 结构同序。
  {
    const res = await fetch(fin.preview_url)
    const html = await res.text()
    const pages = (html.match(/class="page /g) || []).length
    step(res.status === 200 && pages === 11, '预览页共 11 页(封面+6默认章+3章+尾页)', 'pages=' + pages)
    step(html.indexOf('data-auto="20"') >= 0, '自动翻页间隔读自后台配置(20s)')
    step(html.indexOf('btnClose') >= 0 && html.indexOf('返回上一页') >= 0, '含关闭按钮(返回上一页)')
    step(html.indexOf('自动翻页') >= 0 && html.indexOf('原音讲述') >= 0 && html.indexOf('AI 润声') >= 0, '含自动翻页与朗读选项')
    const m = html.match(/<script type="application\/json" id="bookData">([\s\S]*?)<\/script>/)
    const data = m ? JSON.parse(m[1]) : []
    // bookData 序：0=封面, 1..6=默认空章节, 7..9=本测试新增的 3 章(各带 1 段录音), 10=尾页
    step(data.length === 11 && data.slice(7, 10).every((d) => (d.audio || []).length === 1), '本测试新增 3 章各带 1 段原声录音（另有 6 个默认空章节）', JSON.stringify(data.map((d) => (d.audio || []).length)))
    step(data.filter((d) => (d.audio || []).length > 0).every((d) => ((d.origText || d.aiText) || '').length > 0), '有录音的章节均带朗读文本(TTS 兜底)')
    step(html.indexOf('故事传承文化有限公司') >= 0 && html.indexOf('400-000-0000') >= 0, '页脚含公司信息')
    // 封面背景：本例未上传章节背景 → 应从图池随机
    step(/cover-bg-\d\.png/.test(html), '封面使用图池随机背景')
  }

  // 6. 后台改状态 → 已完成
  {
    const sv = await api('/admin-api/project/save', { method: 'POST', admin: true, body: { id: pid, status: 'DONE', production_info: '精装印刷 2 册；后续漫剧 12 集（筹备中）' } })
    step(sv.json?.code === 0, '后台保存项目(状态+制作信息)', sv.json?.msg)
    const d = await api(`/admin-api/project/detail?id=${pid}`, { admin: true })
    step(d.json?.data?.status === 'DONE' && d.json?.data?.status_label === '已完成', '状态已改为「已完成」', d.json?.data?.status)
    step((d.json?.data?.production_info || '').indexOf('漫剧') >= 0, '制作信息已录入')
    const lst = await api('/admin-api/project/index?keyword=SMOKE_翻书升级', { admin: true })
    step(lst.json?.code === 0 && lst.json?.count >= 1, '项目列表可检索', 'count=' + lst.json?.count)
  }

  // 7. 还原配置
  await api('/admin-api/config/save', { method: 'POST', admin: true, body: { config: { preview_auto_flip_seconds: '30', company_name: '', service_phone: '', company_email: '', company_address: '' } } })

  console.log('\n' + (failures === 0 ? '✅ 全部通过' : `❌ 失败 ${failures} 项`))
  console.log(`CLEANUP pid=${pid}`)
  process.exit(failures === 0 ? 0 : 1)
})().catch((e) => { console.error('异常:', e); process.exit(2) })
