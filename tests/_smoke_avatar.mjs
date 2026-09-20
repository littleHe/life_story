// 头像上传接口冒烟：POST /api/upload/avatar（需登录）→ 落盘可访问 → 项目头像落库
// 运行：node backend/tests/_smoke_avatar.mjs
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
const API = 'http://127.0.0.1:9411'
const __dirname = path.dirname(fileURLToPath(import.meta.url))
let token = ''
let failures = 0
const step = (ok, name, extra) => { if (!ok) failures++; console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra ? '  ' + extra : ''}`) }

// 1x1 红色像素 PNG
const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='

async function api(p, { method = 'GET', body, auth = true } = {}) {
  const headers = { 'Content-Type': 'application/json' }
  if (auth && token) headers['Authorization'] = 'Bearer ' + token
  const res = await fetch(API + p, { method, headers, body: body ? JSON.stringify(body) : undefined })
  return { status: res.status, json: await res.json().catch(() => ({})) }
}

;(async () => {
  token = (await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json())?.data?.access_token
  step(!!token, '登录')

  // 1. 未登录 → 401
  const anon = await fetch(API + '/api/upload/avatar', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ image_base64: PNG_B64, image_ext: 'png' }) })
  step(anon.status === 401, '未登录上传被拒 401', 'http=' + anon.status)

  // 2. 非法扩展名
  const bad = await api('/api/upload/avatar', { method: 'POST', body: { image_base64: PNG_B64, image_ext: 'exe' } })
  step(bad.json?.code === 42202, '非法扩展名被拒', 'code=' + bad.json?.code)

  // 3. 正常上传
  const up = await api('/api/upload/avatar', { method: 'POST', body: { image_base64: PNG_B64, image_ext: 'png' } })
  const url = up.json?.data?.url
  step(up.json?.code === 0 && /^\/uploads\/avatar\/user\/\d+\//.test(url || ''), '上传成功返回图池外独立地址', url)
  const img = await fetch(API + url)
  step(img.status === 200, '图片可公开访问', 'http=' + img.status)

  // 4. 建项目带头像 → 落库
  const pid = (await api('/api/projects', { method: 'POST', body: { name: 'SMOKE_头像上传', avatar: url, code: '66666688' } })).json?.data?.id
  const proj = (await api(`/api/projects/${pid}`)).json?.data
  step(proj?.avatar === url, '项目头像已落库', proj?.avatar)
  const list = (await api('/api/projects')).json?.data || []
  const item = list.find((x) => String(x.id) === String(pid))
  step(item?.avatar === url, '列表返回头像字段', item?.avatar)

  console.log('\n' + (failures === 0 ? '✅ 全部通过' : `❌ 失败 ${failures} 项`))
  console.log(`CLEANUP pid=${pid}`)
  process.exit(failures === 0 ? 0 : 1)
})().catch((e) => { console.error('异常:', e); process.exit(2) })
