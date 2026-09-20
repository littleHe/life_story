// 复现后台「背景音乐」页点「编辑」报错：抓 console + exception + 网络请求
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BACK = 'http://127.0.0.1:9411';
const PORT = Number(process.env.CDP_PORT || 9355);
const PAGE = process.env.ADMIN_PAGE || '/admin/page/bgm.html';
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

;(async () => {
  const raw = execFileSync('curl', ['-s', '-i', '-X', 'POST', BACK + '/admin-api/login', '-H', 'Content-Type: application/json', '-d', '{"username":"admin","password":"admin888"}']).toString();
  const m = raw.match(/Set-Cookie:\s*LSSESSID=([^;\r\n]+)/i);
  const sess = m ? m[1] : '';
  if (!sess) { console.log('登录失败'); process.exit(1); }

  const userDataDir = path.join(os.tmpdir(), 'ls_dbg_bgm_' + Date.now());
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDataDir, '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=1280,900', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map(); const logs = []; const reqs = [];
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const m2 = JSON.parse(ev.data);
    if (m2.id && pending.has(m2.id)) { pending.get(m2.id)(m2.result); pending.delete(m2.id); }
    if (m2.method === 'Runtime.consoleAPICalled') {
      logs.push('[' + m2.params.type + '] ' + (m2.params.args || []).map((a) => a.value !== undefined ? a.value : (a.description || a.type)).join(' '));
    }
    if (m2.method === 'Runtime.exceptionThrown') {
      const d = m2.params.exceptionDetails || {};
      logs.push('[EXCEPTION] ' + (d.text || '') + ' :: ' + ((d.exception && (d.exception.description || d.exception.value)) || '') + ' @' + (d.url || '') + ':' + (d.lineNumber || 0));
    }
    if (m2.method === 'Network.requestWillBeSent') reqs.push(m2.params.request.method + ' ' + m2.params.request.url);
    if (m2.method === 'Network.responseReceived' && m2.params.response.status >= 400) reqs.push('  ← ' + m2.params.response.status + ' ' + m2.params.response.url);
  });
  await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');
  await send('Network.setCookie', { name: 'LSSESSID', value: sess, domain: '127.0.0.1', path: '/' });
  await send('Page.navigate', { url: BACK + PAGE });
  await sleep(4000);

  const before = await send('Runtime.evaluate', { expression: "JSON.stringify({rows:document.querySelectorAll('.layui-table-body tbody tr').length, editBtns:document.querySelectorAll('[lay-event=edit]').length})", returnByValue: true });
  console.log('页面就绪：', before.result && before.result.value);

  logs.length = 0; reqs.length = 0;
  const click = await send('Runtime.evaluate', { expression: "(function(){var b=document.querySelector('[lay-event=edit]'); if(!b) return 'no-btn'; b.click(); return 'clicked';})()", returnByValue: true });
  console.log('点击结果：', click.result && click.result.value);
  await sleep(1500);

  const after = await send('Runtime.evaluate', { expression: "JSON.stringify({layers:document.querySelectorAll('.layui-layer').length, title:(document.querySelector('.layui-layer-title')||{}).innerText||''})", returnByValue: true });
  console.log('弹窗状态：', after.result && after.result.value);

  console.log('\n--- console / exception ---');
  console.log(logs.length ? logs.join('\n') : '(无)');
  console.log('\n--- 网络 ---');
  console.log(reqs.length ? reqs.join('\n') : '(无)');

  const shot = await send('Page.captureScreenshot', { format: 'png' });
  if (shot && shot.data) fs.writeFileSync(path.join(OUT, 'dbg-admin-bgm.png'), Buffer.from(shot.data, 'base64'));

  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch { /* ignore */ }
  process.exit(0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
