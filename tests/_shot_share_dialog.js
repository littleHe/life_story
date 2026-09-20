// 截图：详情页「专属访谈链接」弹窗（含新增的「重新生成」按钮与说明文案）
const { spawn } = require('child_process');
const path = require('path');
const os = require('os');
const fs = require('fs');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = 9345;
const OUT = process.env.OUT || path.join(__dirname, '..', '..', '_shot_share_dialog.png');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

;(async () => {
  const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
  const token = lr?.data?.access_token, uid = lr?.data?.uid;
  const api = async (p, opt = {}) => (await fetch(API + '/api' + p, {
    method: opt.method || 'GET',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
    body: opt.body ? JSON.stringify(opt.body) : undefined,
  })).json();

  const pr = await api('/projects', { method: 'POST', body: { name: 'E2E_弹窗截图_' + Date.now(), code: '66666688' } });
  const pid = pr?.data?.id;
  await api(`/projects/${pid}/chapters`, { method: 'PATCH', body: { action: 'add', title: '截图测试章节' } });
  console.log('pid=' + pid);

  const userDataDir = path.join(os.tmpdir(), 'ls_shot_' + Date.now());
  const chrome = spawn(CHROME, [
    '--headless=new', '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + userDataDir, '--no-first-run', '--no-default-browser-check',
    '--disable-gpu', '--disable-extensions', '--hide-scrollbars', '--force-device-scale-factor=2',
    'about:blank',
  ], { stdio: 'ignore' });

  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); } });
  await send('Runtime.enable'); await send('Page.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });

  const evalJs = async (expr) => (await send('Runtime.evaluate', { expression: expr, returnByValue: true })).result?.value;

  await send('Page.navigate', { url: BASE + '/login' });
  await sleep(2200);
  const userJson = JSON.stringify({ uid: uid || 0, nickname: '测试', isTest: true });
  await evalJs(`localStorage.setItem('ls_access_token', ${JSON.stringify(token)});localStorage.setItem('ls_refresh_token', ${JSON.stringify(token)});localStorage.setItem('ls_user_info', ${JSON.stringify(userJson)});'ok'`);

  await send('Page.navigate', { url: `${BASE}/memoirs/${pid}` });
  await sleep(3200);
  await evalJs(`(function(){var b=[].slice.call(document.querySelectorAll('button'));for(var i=0;i<b.length;i++){if((b[i].textContent||'').indexOf('生成访谈链接')>=0){b[i].click();return true;}}return false;})()`);
  await sleep(2000);

  const shot = await send('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(OUT, Buffer.from(shot.data, 'base64'));
  console.log('saved: ' + OUT);

  ws.close(); chrome.kill();
  console.log('SHOT_PID=' + pid);
  process.exit(0);
})();
