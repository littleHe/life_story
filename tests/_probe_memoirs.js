const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = 'http://127.0.0.1:8001';
const API = 'http://127.0.0.1:9411';
const PORT = 9355;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();
;(async () => {
  const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
  const token = lr?.data?.access_token; const uid = lr?.data?.uid;
  const dir = path.join(os.tmpdir(), 'ls_probe_' + Date.now());
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + dir, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map(); const errs = []; const logs = [];
  const send = (m, p = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method: m, params: p })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') errs.push((m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text || '').split('\n')[0]);
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') logs.push(m.params.args.map(a => a.value || a.description).join(' '));
  });
  ws.addEventListener('error', (e) => { console.log('WS ERROR', e.message); });
  await send('Runtime.enable'); await send('Page.enable');
  await send('Page.navigate', { url: BASE + '/' });
  await sleep(1500);
  const ev = async (x) => (await send('Runtime.evaluate', { expression: x, returnByValue: true })).result;
  await ev(`localStorage.setItem('ls_access_token', ${JSON.stringify(token)}); localStorage.setItem('ls_user_info', JSON.stringify({uid:${uid||0},nickname:'测试账号'})); 'ok'`);
  await send('Page.navigate', { url: BASE + '/memoirs' });
  await sleep(3500);
  const r = await ev(`JSON.stringify({ path: location.pathname, textLen: document.body.innerText.length, h3: [].slice.call(document.querySelectorAll('h3')).map(function(h){return h.textContent.trim();}), head: document.body.innerText.slice(0,200) })`);
  console.log('PAGE=', r.value);
  console.log('EXCEPTIONS=', JSON.stringify(errs));
  console.log('CONSOLE_ERRORS=', JSON.stringify(logs));
  ws.close(); chrome.kill(); await sleep(300);
  try { fs.rmSync(dir, { recursive: true, force: true }); } catch {}
  process.exit(0);
})().catch((e) => { console.log('ERR', e); process.exit(1); });
