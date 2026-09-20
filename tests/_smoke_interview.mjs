// 亲友免登录「访谈」录制页冒烟测试：覆盖生成 token 与公开录制闭环。
// 用法：node tests/_smoke_interview.mjs
const BASE = process.env.BASE || 'http://127.0.0.1:9411'

async function j(path, opts = {}) {
  const r = await fetch(BASE + path, {
    ...opts,
    headers: { 'Content-Type': 'application/json', ...(opts.headers || {}) },
  })
  let body
  try { body = await r.json() } catch { body = null }
  return { status: r.status, body }
}

// 1) 测试账号登录
const login = await j('/api/auth/test/login', { method: 'POST' })
if (login.body?.code !== 0) { console.error('LOGIN FAIL', login); process.exit(1) }
const token = login.body.data.access_token
const auth = { Authorization: `Bearer ${token}` }

// 2) 找一个 EDITABLE 项目（测试账号 uid=2）；若无章节则补建一个用于测试
const projs = await j('/api/projects', { headers: auth })
let pid = null
for (const p of projs.body?.data || []) {
  if (p.status === 'EDITABLE') { pid = p.id; break }
}
if (!pid) { console.error('NO EDITABLE PROJECT for test user'); process.exit(2) }
console.log('using project', pid)

let chapters = await j(`/api/projects/${pid}/chapters`, { headers: auth })
let chapterId = chapters.body?.data?.[0]?.id
if (!chapterId) {
  const add = await j(`/api/projects/${pid}/chapters`, {
    method: 'PATCH',
    headers: auth,
    body: JSON.stringify({ action: 'add', title: '访谈冒烟测试章节' }),
  })
  chapterId = add.body?.data?.chapter_id
  console.log('created chapter', chapterId)
}
if (!chapterId) { console.error('NO CHAPTER AVAILABLE'); process.exit(4) }
console.log('chapter', chapterId)

// 3) 生成访谈 token（需登录）
const gen = await j(`/api/projects/${pid}/interview-token`, { method: 'POST', headers: auth })
if (gen.body?.code !== 0) { console.error('GEN TOKEN FAIL', gen); process.exit(3) }
const iToken = gen.body.data.interview_token
console.log('interview_token:', iToken, '| url:', gen.body.data.interview_url)

// 5) 公开 GET 访谈页数据（无需登录，不带 Bearer）
const show = await j(`/api/interview/${iToken}`)
console.log('GET /api/interview/:token ->', show.status, JSON.stringify(show.body).slice(0, 200))
if (show.body?.code !== 0) { console.error('SHOW FAIL', show); process.exit(5) }

// 6) 公开 POST 录音（伪造 1 秒静音 webm 的 base64 占位；后端只校验落盘，不解码音频）
// 用一个极短的合法 webm 二进制 base64（足够通过 base64_decode + 扩展名校验）
const fakeB64 = 'GkXfo59ChoEBQveBAULygQRC84EIQoKEd2Vie1l9AssBryoBJ6NBwg=='
const rec = await j(`/api/interview/${iToken}/recording`, {
  method: 'POST',
  body: JSON.stringify({ chapter_id: chapterId, audio_base64: fakeB64, audio_ext: 'webm', duration: '0:03' }),
})
console.log('POST /api/interview/:token/recording ->', rec.status, JSON.stringify(rec.body).slice(0, 200))
if (rec.body?.code !== 0) { console.error('RECORD FAIL', rec); process.exit(6) }
const assetId = rec.body.data.asset_id

// 7) 公开 POST 转写（AI 接口；mock 环境返回模拟文字，真实环境可能走云端）
const asr = await j(`/api/interview/${iToken}/asr`, {
  method: 'POST',
  body: JSON.stringify({ chapter_id: chapterId, asset_id: assetId }),
})
console.log('POST /api/interview/:token/asr ->', asr.status, JSON.stringify(asr.body).slice(0, 200))
if (asr.body?.code !== 0) { console.error('ASR FAIL', asr); process.exit(7) }

// 8) 无效 token 应 404
const bad = await j('/api/interview/does-not-exist')
console.log('bad token ->', bad.status, bad.body?.msg)

console.log('\nALL INTERVIEW SMOKE CHECKS PASSED')
