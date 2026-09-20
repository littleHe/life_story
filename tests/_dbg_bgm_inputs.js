// 探针：列出后台 bgm 页所有 input，确认 [name=file] 是否命中 layui upload 的 file input
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'); const path = require('path'); const os = require('os');
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BACK = 'http://127.0.0.1:9411'; const PORT = 9356;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();
;(async () => {
  const raw = execFileSync('curl', ['-s', '-i', '-X', 'POST', BACK + '/admin-api/login', '-H', 'Content-Type: application/json', '-d', '{"username":"admin","password":"admin888"}']).toString();
  const sess = (raw.match(/Set-Cookie:\s*LSSESSID=([^;\r\n]+)/i) || [])[1] || '';
  const ud = path.join(os.tmpdir(), 'ls_probe_fi_' + Date.now());
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + ud, '--no-first-run', '--disable-gpu', '--window-size=1280,900', 'about:blank'], { stdio: 'ignore' });
  let ready = null; for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const t = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(t.find((x) => x.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (m, p = {}) => new Promise((r) => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method: m, params: p })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); } });
  await send('Runtime.enable'); await send('Page.enable');
  await send('Network.setCookie', { name: 'LSSESSID', value: sess, domain: '127.0.0.1', path: '/' });
  await send('Page.navigate', { url: BACK + '/admin/page/bgm.html' }); await sleep(4000);
  const r = await send('Runtime.evaluate', { expression: `JSON.stringify(Array.from(document.querySelectorAll('input')).map(function(i){return {name:i.name,id:i.id,type:i.type,cls:i.className,val:String(i.value).slice(0,30),inForm:!!i.closest('form')}}))`, returnByValue: true });
  console.log('=== 所有 input ==='); console.log(r.result.value);
  const r2 = await send('Runtime.evaluate', { expression: `JSON.stringify(Array.from(document.querySelectorAll('form[lay-filter=coverBgForm] [name]')).map(function(i){return {tag:i.tagName,name:i.name,type:i.type||''}}))`, returnByValue: true });
  console.log('=== coverBgForm 内 [name] ==='); console.log(r2.result.value);
  const r3 = await send('Runtime.evaluate', { expression: `document.querySelectorAll('form[lay-filter=coverBgForm] input[name=file]').length + ' 个 [name=file]'`, returnByValue: true });
  console.log('=== 命中数 ===', r3.result.value);
  ws.close(); chrome.kill(); await sleep(300);
  try { fs.rmSync(ud, { recursive: true, force: true }); } catch { /* ignore */ }
  process.exit(0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
