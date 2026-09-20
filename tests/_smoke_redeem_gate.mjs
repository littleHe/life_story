// 冒烟：创建回忆录必须兑换码解锁（无效码不得落库）+ 固定测试码 66666688 可无限复用
// 运行：node backend/tests/_smoke_redeem_gate.mjs
const API = 'http://127.0.0.1:9411'
let token = ''
let failures = 0
const step = (ok, name, extra) => { if (!ok) failures++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra ? '  ' + extra : ''}`) }
const created = []

async function api(p, { method = 'GET', body } = {}) {
  const headers = { 'Content-Type': 'application/json' }
  if (token) headers['Authorization'] = 'Bearer ' + token
  const res = await fetch(API + p, { method, headers, body: body ? JSON.stringify(body) : undefined })
  return { status: res.status, json: await res.json().catch(() => ({})) }
}
const countProjects = async () => ((await api('/api/projects')).json?.data || []).length

;(async () => {
  token = (await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json())?.data?.access_token
  step(!!token, '测试账号登录（uid=2）')
  const before = await countProjects()

  // 1) 不带兑换码 → 拒绝且不落库
  {
    const r = await api('/api/projects', { method: 'POST', body: { name: 'E2E_无码创建', real_name: '测试' } })
    step(r.json?.code !== 0 && r.json?.code === 42203, '不填兑换码 → 拒绝创建', `code=${r.json?.code} msg=${r.json?.msg}`)
    step((await countProjects()) === before, '不填兑换码 → 未产生项目')
  }

  // 2) 无效兑换码 → 拒绝且不落库
  {
    const r = await api('/api/projects', { method: 'POST', body: { name: 'E2E_错码创建', real_name: '测试', code: 'ZZZZ9999' } })
    step(r.json?.code === 40902, '无效兑换码 → 拒绝创建', `code=${r.json?.code} msg=${r.json?.msg}`)
    step((await countProjects()) === before, '无效兑换码 → 未产生项目')
  }

  // 3) 激活固定测试码 66666688
  {
    const r = await api('/api/redeem/activate', { method: 'POST', body: { code: '66666688' } })
    step(r.json?.code === 0, '激活测试码 66666688', `msg=${r.json?.msg}`)
  }

  // 4) 我的兑换码列表包含该码（可无限使用）
  let codeId = 0
  {
    const list = (await api('/api/redeem/codes')).json?.data || []
    const hit = list.find((c) => c.code_mask === '66666688')
    codeId = hit?.id || 0
    step(!!hit && hit.unlimited === 1, '我的兑换码包含 66666688 且标记不限次', `id=${hit?.id} status=${hit?.status}`)
  }

  // 5) 手输码创建 → 解锁（EDITABLE）
  {
    const r = await api('/api/projects', { method: 'POST', body: { name: 'E2E_测试码手输', real_name: '测试', code: '66666688' } })
    const id = r.json?.data?.id
    step(r.json?.code === 0 && !!id, '手输 66666688 → 创建成功', `id=${id}`)
    if (id) {
      created.push(id)
      const p = (await api(`/api/projects/${id}`)).json?.data
      step(p?.status === 'EDITABLE', '创建后项目直接解锁（EDITABLE）', `status=${p?.status}`)
    }
  }

  // 6) 同码再创建一次 → 不限次可复用
  {
    const r = await api('/api/projects', { method: 'POST', body: { name: 'E2E_测试码复用', real_name: '测试', code: '66666688' } })
    const id = r.json?.data?.id
    step(r.json?.code === 0 && !!id, '同码再次创建成功（不限次）', `id=${id}`)
    if (id) created.push(id)
  }

  // 7) 下拉选择（code_id）创建 → 走 bindById 路径
  {
    const r = await api('/api/projects', { method: 'POST', body: { name: 'E2E_测试码选择', real_name: '测试', code_id: codeId } })
    const id = r.json?.data?.id
    step(r.json?.code === 0 && !!id, '按 code_id 选择已激活码 → 创建成功', `id=${id}`)
    if (id) created.push(id)
  }

  // 8) 选择别人的/不存在的 code_id → 拒绝
  {
    const r = await api('/api/projects', { method: 'POST', body: { name: 'E2E_非法code_id', real_name: '测试', code_id: 999999 } })
    step(r.json?.code === 40902, '非法 code_id → 拒绝创建', `code=${r.json?.code}`)
  }

  console.log('\ncreated pids: ' + created.join(' '))
  console.log(failures === 0 ? 'ALL PASS' : `FAILURES: ${failures}`)
  process.exit(failures === 0 ? 0 : 1)
})()
