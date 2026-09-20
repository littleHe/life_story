// 冒烟：访谈链接「重新生成」——新 token 生效、旧 token 立即失效
const BASE = 'http://127.0.0.1:9411'

async function j(path, opts = {}) {
  const res = await fetch(BASE + path, opts)
  let body = null
  try { body = await res.json() } catch { /* html */ }
  return { status: res.status, body }
}

let pass = 0, fail = 0
const step = (ok, msg) => { ok ? (pass++, console.log('PASS ' + msg)) : (fail++, console.log('FAIL ' + msg)) }

// 1) 测试账号登录（uid=2）
const login = await j('/api/auth/test/login', {
  method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}',
})
const token = login.body?.data?.access_token
step(!!token, '登录测试账号')
const auth = { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' }

// 2) 找一个 EDITABLE 项目
const projs = await j('/api/projects', { headers: auth })
const pid = (projs.body?.data || []).find((p) => p.status === 'EDITABLE')?.id
if (!pid) { console.error('NO EDITABLE PROJECT'); process.exit(2) }
console.log('using project', pid)

// 3) 幂等生成（不带 regenerate）
const g1 = await j(`/api/projects/${pid}/interview-token`, { method: 'POST', headers: auth, body: '{}' })
step(g1.body?.code === 0 && !!g1.body.data.interview_token, '首次生成 interview_token')
const tokA = g1.body.data.interview_token

// 4) 再点一次（幂等）应复用同一 token
const g2 = await j(`/api/projects/${pid}/interview-token`, { method: 'POST', headers: auth, body: '{}' })
step(g2.body?.data?.interview_token === tokA, '重复调用幂等复用同一 token')

// 5) 旧 token 可用
const oldShow = await j(`/api/interview/${tokA}`)
step(oldShow.status === 200 && oldShow.body?.code === 0, '重新生成前：旧 token 可正常访问')

// 6) 重新生成
const g3 = await j(`/api/projects/${pid}/interview-token`, {
  method: 'POST', headers: auth, body: JSON.stringify({ regenerate: 1 }),
})
const tokB = g3.body?.data?.interview_token
step(!!tokB && tokB !== tokA, '重新生成返回了不同的新 token')

// 7) 旧 token 立即失效
const oldGone = await j(`/api/interview/${tokA}`)
step(oldGone.status === 404 && oldGone.body?.code === 40401, '重新生成后：旧 token 立即 404 失效')

// 8) 新 token 可用
const newShow = await j(`/api/interview/${tokB}`)
step(newShow.status === 200 && newShow.body?.code === 0, '重新生成后：新 token 可正常访问')

console.log(`\n${pass} passed, ${fail} failed`)
process.exit(fail ? 1 : 0)
