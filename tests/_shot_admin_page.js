// 后台任意页面截图（curl 登录拿 LSSESSID 再注入 CDP）
// 用法：node tests/_shot_admin_page.js [page]   例：node tests/_shot_admin_page.js config
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BACK = 'http://127.0.0.1:9411';
const PORT = 9346;
const PAGE = process.argv[2] || 'coverbg';
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

;(async () => {
  const raw = execFileSync('curl', [
    '-s', '-i', '-X', 'POST', BACK + '/admin-api/login',
    '-H', 'Content-Type: application/json',
    '-d', '{"username":"admin","password":"admin888"}',
  ]).toString();
  const m = raw.match(/Set-Cookie:\s*LSSESSID=([^;\r\n]+)/i);
  const sess = m ? m[1] : '';
  if (!sess) { console.log('登录失败'); process.exit(1); }

  const userDataDir = path.join(os.tmpdir(), 'ls_admin_shot_' + Date.now());
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDataDir, '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=1280,860', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => { const m2 = JSON.parse(ev.data); if (m2.id && pending.has(m2.id)) { pending.get(m2.id)(m2.result); pending.delete(m2.id); } });
  await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');
  await send('Network.setCookie', { name: 'LSSESSID', value: sess, domain: '127.0.0.1', path: '/' });
  await send('Page.navigate', { url: BACK + '/admin/page/' + PAGE + '.html' });
  await sleep(3500);
  const shot = await send('Page.captureScreenshot', { format: 'png' });
  const file = path.join(OUT, 'admin-' + PAGE + '.png');
  fs.writeFileSync(file, Buffer.from(shot.data, 'base64'));
  console.log('OK ->', file);
  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch { /* ignore */ }
  process.exit(0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
