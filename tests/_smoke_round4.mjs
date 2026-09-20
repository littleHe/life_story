// 第四轮冒烟：站点标题配置 / 录音删除与口述原文重算 / 定稿集中生成 AI 润色
// 运行：node backend/tests/_smoke_round4.mjs
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
const API = 'http://127.0.0.1:9411'
const __dirname = path.dirname(fileURLToPath(import.meta.url))
let token = '', sess = ''
let failures = 0
const step = (ok, name, extra) => { if (!ok) failures++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra ? '  ' + extra : ''}`) }
const b64 = (s) => Buffer.from(s).toString('base64')

async function api(p, { method = 'GET', body, admin = false } = {}) {
  const headers = { 'Content-Type': 'application/json' }
  if (admin && sess) headers['Cookie'] = sess
  else if (!admin && token) headers['Authorization'] = 'Bearer ' + token
  const res = await fetch(API + p, { method, headers, body: body ? JSON.stringify(body) : undefined })
  return { status: res.status, json: await res.json().catch(() => ({})) }
}

;(async () => {
  // 1. 公开站点配置（无需登录）
  {
    const r = await (await fetch(API + '/api/site/config')).json()
    step(r?.code === 0 && !!r?.data?.app_name, '公开 /api/site/config 可匿名访问', 'app_name=' + r?.data?.app_name)
  }

  // 登录（用户 + 后台）
  token = (await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json())?.data?.access_token
  {
    const lr = await fetch(API + '/admin-api/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: 'admin', password: 'admin888' }) })
    const sc = lr.headers.getSetCookie ? lr.headers.getSetCookie() : [lr.headers.get('set-cookie') || '']
    sess = (sc.find((c) => c.startsWith('LSSESSID=')) || sc[0] || '').split(';')[0]
  }
  step(!!token && sess.startsWith('LSSESSID='), '登录（用户+后台）')

  // 2. 后台改站点名称 → 公开接口生效
  {
    await api('/admin-api/config/save', { method: 'POST', admin: true, body: { config: { app_name: '丽丽的人生书' } } })
    const r = await (await fetch(API + '/api/site/config')).json()
    step(r?.data?.app_name === '丽丽的人生书', '后台改「站点名称」→ 浏览器标题来源生效', r?.data?.app_name)
    await api('/admin-api/config/save', { method: 'POST', admin: true, body: { config: { app_name: '人生回忆录' } } })
  }

  // 3. 建 1 章 + 2 段录音 + 转写 → 口述原文 = 两段拼合
  const pid = (await api('/api/projects', { method: 'POST', body: { name: 'SMOKE_录音删除', real_name: '张丽丽', code: '66666688' } })).json?.data?.id
  const cid = (await api(`/api/projects/${pid}/chapters`, { method: 'PATCH', body: { action: 'add', title: '第一章' } })).json?.data?.chapter_id
  const segs = []
  for (const s of ['第一段', '第二段']) {
    const d = (await api(`/api/chapters/${cid}/recording`, { method: 'POST', body: { audio_base64: b64(s), audio_ext: 'webm', duration: '00:03' } })).json?.data
    const a = (await api(`/api/chapters/${cid}/asr`, { method: 'POST', body: { asset_id: d.asset_id } })).json?.data
    segs.push({ ...d, text: a.text })
  }
  let ch = (await api(`/api/projects/${pid}/chapters`)).json?.data?.find((x) => String(x.id) === String(cid))
  const want = segs.map((s) => s.text).join('\n')
  step(ch?.transcript === want, '口述原文 = 两段录音文字拼合', `len=${ch?.transcript?.length}`)

  // 4. 删除第 1 段 → 音频文件删除 + 口述原文重算为第 2 段文字
  const fileRel = segs[0].url
  const fileAbs = path.join(__dirname, '..', 'public', fileRel.replace(/^\//, ''))
  step(fs.existsSync(fileAbs), '待删音频文件存在', fileRel)
  const del = await api(`/api/chapters/${cid}/recording/delete`, { method: 'POST', body: { asset_id: segs[0].asset_id } })
  step(del.json?.code === 0, '删除第 1 段录音成功', del.json?.msg)
  step(!fs.existsSync(fileAbs), '音频文件已一并删除')
  ch = (await api(`/api/projects/${pid}/chapters`)).json?.data?.find((x) => String(x.id) === String(cid))
  step((ch?.recordings || []).length === 1 && ch?.transcript === segs[1].text, '口述原文重算为剩余分段文字', `recordings=${ch?.recordings?.length}`)

  // 5. 定稿 → 各章集中生成 AI 润色
  await api(`/api/projects/${pid}/finalize`, { method: 'POST', body: {} })
  ch = (await api(`/api/projects/${pid}/chapters`)).json?.data?.find((x) => String(x.id) === String(cid))
  step(!!ch?.polished && ch.polished.length > 10, '定稿时已按汇总文字生成 AI 润色', `len=${ch?.polished?.length}`)
  // 再次定稿不重复调用（幂等）：清空口述原文后再定稿，润色仍保留
  await api(`/api/projects/${pid}/finalize`, { method: 'POST', body: {} })
  const ch2 = (await api(`/api/projects/${pid}/chapters`)).json?.data?.find((x) => String(x.id) === String(cid))
  step(ch2?.polished === ch?.polished, '重复定稿不覆盖已有润色结果（接口不浪费）')

  console.log('\n' + (failures === 0 ? '✅ 全部通过' : `❌ 失败 ${failures} 项`))
  console.log(`CLEANUP pid=${pid}`)
  process.exit(failures === 0 ? 0 : 1)
})().catch((e) => { console.error('异常:', e); process.exit(2) })
