// 分段录音 + 分段转写追加 + 章节背景上传 端到端冒烟
// 运行： node backend/tests/_smoke_segmented.mjs
const BASE = 'http://127.0.0.1:9411/api'
let token = ''
let pid = 0
let cid = 0

async function call(path, { method = 'GET', body, auth = true } = {}) {
  const headers = { 'Content-Type': 'application/json' }
  if (auth && token) headers['Authorization'] = 'Bearer ' + token
  const res = await fetch(BASE + path, {
    method,
    headers,
    body: body ? JSON.stringify(body) : undefined,
  })
  const txt = await res.text()
  let json
  try { json = JSON.parse(txt) } catch { json = { raw: txt } }
  return { status: res.status, json }
}

const log = (ok, msg, extra) =>
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${msg}${extra ? '  ' + extra : ''}`)

let failures = 0
function check(cond, msg, extra) {
  if (!cond) failures++
  log(!!cond, msg, extra)
}

const b64 = (s) => Buffer.from(s).toString('base64')

;(async () => {
  // 1. 测试登录
  let r = await call('/auth/test/login', { method: 'POST', auth: false })
  token = r.json?.data?.access_token || ''
  check(!!token, '测试登录拿到 token', `uid=${r.json?.data?.uid}`)

  // 2. 建项目
  r = await call('/projects', { method: 'POST', body: { name: 'SMOKE_分段录音_' + Date.now(), code: '66666688' } })
  pid = r.json?.data?.id || 0
  check(!!pid, '新建项目', `pid=${pid}`)

  // 3. 加章节
  r = await call(`/projects/${pid}/chapters`, {
    method: 'PATCH',
    body: { action: 'add', title: '第一章 童年' },
  })
  cid = r.json?.data?.chapter_id || 0
  check(!!cid, '新增章节', `cid=${cid}`)

  // 4. 上传第 1 段录音
  r = await call(`/chapters/${cid}/recording`, {
    method: 'POST',
    body: { audio_base64: b64('segment-one-bytes'), audio_ext: 'webm', duration: '00:03' },
  })
  const seg1 = r.json?.data
  check(seg1?.seq === 1 && seg1?.asset_id > 0 && !!seg1?.url, '上传第 1 段录音 → seq=1', JSON.stringify(r.json?.data))

  // 5. 第 1 段转写（应追加到口述原文）
  r = await call(`/chapters/${cid}/asr`, { method: 'POST', body: { asset_id: seg1?.asset_id } })
  const asr1 = r.json?.data
  check(!!asr1?.text && !!asr1?.transcript, '第 1 段转写返回文字', `text.len=${asr1?.text?.length}`)
  const t1 = asr1?.transcript || ''

  // 6. 上传第 2 段录音
  r = await call(`/chapters/${cid}/recording`, {
    method: 'POST',
    body: { audio_base64: b64('segment-two-bytes'), audio_ext: 'webm', duration: '00:05' },
  })
  const seg2 = r.json?.data
  check(seg2?.seq === 2 && seg2?.asset_id !== seg1?.asset_id, '上传第 2 段录音 → seq=2 且 asset 不同', `asset=${seg2?.asset_id}`)

  // 7. 第 2 段转写（口述原文应累积 = 段1 + 段2，且长度增加）
  r = await call(`/chapters/${cid}/asr`, { method: 'POST', body: { asset_id: seg2?.asset_id } })
  const asr2 = r.json?.data
  check(
    (asr2?.transcript || '').length > t1.length,
    '第 2 段转写后口述原文累积增长',
    `len ${t1.length} → ${(asr2?.transcript || '').length}`,
  )

  // 8. 上传自定义背景（USER_IMAGE）
  r = await call(`/chapters/${cid}/background`, {
    method: 'POST',
    body: { image_base64: b64('dummy-png-bytes'), image_ext: 'png' },
  })
  const bgUrl = r.json?.data?.url || ''
  check(bgUrl.startsWith('/uploads/chapter-bg/user/'), '上传自定义背景成功', bgUrl)

  // 9. 生成 AI 背景（AI_IMAGE），验证列表优先返回 USER_IMAGE
  r = await call(`/chapters/${cid}/illustrate`, { method: 'POST', body: {} })
  check(r.status === 200 && r.json?.code === 0, 'AI 配图接口可用（用于验证优先级）', r.json?.data?.url)

  // 10. 保存章节（标题 + 手动口述原文 + 润色）
  r = await call(`/chapters/${cid}/save`, {
    method: 'POST',
    body: { title: '第一章 童年(已保存)', transcript: '手动录入的口述原文内容', polished: '润色后的故事' },
  })
  check(r.status === 200 && r.json?.code === 0, '单独保存章节成功', '')

  // 11. GET 章节列表：验证 recordings 数组 + 累积口述 + 背景优先级
  r = await call(`/projects/${pid}/chapters`)
  const ch = (r.json?.data || [])[0] || {}
  const recs = ch.recordings || []
  check(recs.length === 2, '章节列表返回 2 段录音', `recordings=${recs.length}`)
  check(
    recs.every((s) => !!s.text) && recs[0].seq === 1 && recs[1].seq === 2,
    '每段录音都带转写文字且按 seq 排序',
    recs.map((s) => `#${s.seq}`).join(','),
  )
  check(ch.transcript === '手动录入的口述原文内容', '保存后的口述原文为手动内容', ch.transcript)
  check(ch.ai_image === bgUrl, '章节背景优先返回用户上传图 (USER_IMAGE)', ch.ai_image)

  console.log('\n' + (failures === 0 ? '✅ 全部通过' : `❌ 失败 ${failures} 项`))
  console.log(`CLEANUP pid=${pid} cid=${cid}`)
  process.exit(failures === 0 ? 0 : 1)
})().catch((e) => {
  console.error('异常:', e)
  console.log(`CLEANUP pid=${pid} cid=${cid}`)
  process.exit(2)
})
