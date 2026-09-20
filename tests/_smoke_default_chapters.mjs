// 冒烟：新建回忆录（兑换成功后）默认生成系统章节；定稿时传主头像作为封面背景兜底
const API = 'http://127.0.0.1:9411';
const AVATAR = 'https://example.com/avatar/test.jpg';

let pass = 0, fail = 0;
const step = (ok, msg, extra = '') => {
  if (ok) { pass++; console.log('  PASS', msg, extra); }
  else { fail++; console.log('  FAIL', msg, extra); }
};

async function call(path, opt = {}) {
  const res = await fetch(API + '/api' + path, {
    method: opt.method || 'GET',
    headers: { 'Content-Type': 'application/json', ...(opt.headers || {}) },
    body: opt.body ? JSON.stringify(opt.body) : undefined,
  });
  return { status: res.status, json: await res.json().catch(() => ({})) };
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  // 登录测试账号 uid=2
  const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
  const token = lr?.data?.access_token;
  const H = { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token };
  step(!!token, '测试账号登录拿到 token');

  // 1) 兑换成功后默认生成系统章节
  const ts = Date.now();
  const pr = await call('/projects', { method: 'POST', headers: H, body: { name: 'SMOKE_默认章节_' + ts, real_name: '测试传主', avatar: AVATAR, code: '66666688' } });
  const pid = pr.json?.data?.id;
  step(pr.status === 200 && !!pid, '创建项目（兑换成功）', 'pid=' + pid);

  const ch = await call(`/projects/${pid}/chapters`, { headers: H });
  const list = ch.json?.data || [];
  const expected = ['童年时光', '青春年华', '成家立业', '奋斗岁月', '闲适晚年', '人生感悟'];
  step(list.length === 6, '默认生成 6 个章节', 'actual=' + list.length);
  step(
    expected.every((t) => list.some((c) => c.title === t)),
    '章节标题与默认模板一致',
  );
  step(list.every((c) => c.source === 'SYSTEM'), '章节来源均为 SYSTEM');

  // 2) 定稿时传主头像作为封面背景兜底（无章节 USER_IMAGE 时）
  // 给首个默认章节插入口述原文，满足 finalize 的内容要求
  const cid = list[0].id;
  // 通过录音 + 转写走真实链路（AI_MOCK 下 asr 返回 mock 文本）
  const b64 = Buffer.from('fake-audio-bytes').toString('base64');
  const rec = await call(`/chapters/${cid}/recording`, { method: 'POST', headers: H, body: { audio_base64: b64, audio_ext: 'webm', duration: '00:02' } });
  const assetId = rec.json?.data?.asset_id;
  await call(`/chapters/${cid}/asr`, { method: 'POST', headers: H, body: { asset_id: assetId } });
  await sleep(200);

  const fin = await call(`/projects/${pid}/finalize`, { method: 'POST', headers: H, body: {} });
  step(fin.status === 200, '定稿成功', 'status=' + fin.status);
  step(
    fin.json?.data?.cover_bg === AVATAR,
    '封面背景回退到传主头像',
    'cover_bg=' + fin.json?.data?.cover_bg,
  );

  console.log(`\n=== _smoke_default_chapters: ${fail ? 'FAILED' : 'ALL PASS'} (${pass} pass / ${fail} fail) ===`);
  console.log('CLEANUP_PID=' + pid);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('FATAL', e); process.exit(2); });
