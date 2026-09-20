// 后台「封面背景」管理页截图（先 curl 登录拿 session cookie，再注入 CDP）
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BACK = 'http://127.0.0.1:9411';
const PORT = 9342;
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

;(async () => {
  // 用 execFileSync 传数组参数，避免 Windows 下的 shell 引号问题
  const raw = execFileSync('curl', [
    '-s', '-i', '-X', 'POST', BACK + '/admin-api/login',
    '-H', 'Content-Type: application/json',
    '-d', '{"username":"admin","password":"admin888"}',
  ]).toString();
  const m = raw.match(/Set-Cookie:\s*LSSESSID=([^;\r\n]+)/i) || raw.match(/Set-Cookie:\s*([^=]+)=([^;\r\n]+)/i);
  const sess = m ? (m[2] || m[1]) : '';
  const cookieName = /LSSESSID/i.test(raw) ? 'LSSESSID' : 'PHPSESSID';
  console.log('cookie=', cookieName, sess ? sess.slice(0, 12) + '…' : '(none)');

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
  await send('Network.setCookie', { name: cookieName, value: sess, domain: '127.0.0.1', path: '/' });
  await send('Page.navigate', { url: BACK + '/admin/page/coverbg.html' });
  await sleep(3500);
  const shot = await send('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(path.join(OUT, 'admin-coverbg.png'), Buffer.from(shot.data, 'base64'));
  console.log('OK ->', path.join(OUT, 'admin-coverbg.png'));
  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch { /* ignore */ }
  process.exit(0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
