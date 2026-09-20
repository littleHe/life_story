/**
 * 详情页改造 · 视觉截图（用于人工验收）
 * 运行：node tests/front-shots-recording.js
 * 产物：tests/_shots/detail.png、tests/_shots/birth-dialog.png
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = 9339;
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();
const b64 = (s) => Buffer.from(s).toString('base64');

;(async () => {
  // 预置数据
  const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
  const token = lr?.data?.access_token; const uid = lr?.data?.uid;
  const H = { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token };
  const api = async (p, m = 'GET', b) => (await fetch(API + '/api' + p, { method: m, headers: H, body: b ? JSON.stringify(b) : undefined })).json();
  const pid = (await api('/projects', 'POST', { name: '视觉验收_分段录音', real_name: '张丽丽', birth: '1950-06-15', code: '66666688' })).data.id;
  const cid = (await api(`/projects/${pid}/chapters`, 'PATCH', { action: 'add', title: '第一章 童年' })).data.chapter_id;
  const s1 = (await api(`/chapters/${cid}/recording`, 'POST', { audio_base64: b64('a'), audio_ext: 'webm', duration: '00:12' })).data;
  await api(`/chapters/${cid}/asr`, 'POST', { asset_id: s1.asset_id });
  const s2 = (await api(`/chapters/${cid}/recording`, 'POST', { audio_base64: b64('bb'), audio_ext: 'webm', duration: '00:28' })).data;
  await api(`/chapters/${cid}/asr`, 'POST', { asset_id: s2.asset_id });

  const userDataDir = path.join(os.tmpdir(), 'ls_shots_' + Date.now());
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDataDir, '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=420,900', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  if (!ready) { console.log('Chrome 未就绪'); chrome.kill(); process.exit(1); }
  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); } });
  await send('Runtime.enable'); await send('Page.enable');
  const evalJs = async (e) => (await send('Runtime.evaluate', { expression: e, returnByValue: true })).result.value;
  await send('Emulation.setDeviceMetricsOverride', { width: 420, height: 900, deviceScaleFactor: 2, mobile: true });

  await send('Page.navigate', { url: BASE + '/' });
  await sleep(1500);
  await evalJs(`localStorage.setItem('ls_access_token', ${JSON.stringify(token)}); localStorage.setItem('ls_user_info', JSON.stringify({uid:${uid || 0},nickname:'测试账号'})); 'ok'`);
  await send('Page.navigate', { url: BASE + '/memoirs/' + pid });
  await sleep(3400);
  // 滚到章节区域
  await evalJs(`window.scrollTo(0, 380); 'ok'`);
  await sleep(600);
  let shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  fs.writeFileSync(path.join(OUT, 'detail.png'), Buffer.from(shot.data, 'base64'));

  // 打开编辑信息弹窗并截图
  await evalJs(`(function(){ window.scrollTo(0,0); var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return (x.textContent||'').indexOf('编辑信息')>=0;}); if(b) b.click(); return 'ok'; })()`);
  await sleep(900);
  shot = await send('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(path.join(OUT, 'birth-dialog.png'), Buffer.from(shot.data, 'base64'));

  console.log('SHOTS_OUT=' + OUT);
  console.log('CLEANUP pid=' + pid);
  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch { /* ignore */ }
  process.exit(0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
