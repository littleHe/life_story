// 诊断：后台页面 JS 异常（用法：node tests/_dbg_admin_page.js project）
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BACK = 'http://127.0.0.1:9411';
const PAGE = process.argv[2] || 'project';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

;(async () => {
  const raw = execFileSync('curl', ['-s', '-i', '-X', 'POST', BACK + '/admin-api/login',
    '-H', 'Content-Type: application/json',
    '-d', '{"username":"admin","password":"admin888"}']).toString();
  const m = raw.match(/Set-Cookie:\s*LSSESSID=([^;\r\n]+)/i);
  const sess = m ? m[1] : '';

  const dir = path.join(os.tmpdir(), 'ls_dbg_' + Date.now());
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=9350', '--user-data-dir=' + dir, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  for (let i = 0; i < 40; i++) { try { await (await fetch('http://127.0.0.1:9350/json/version')).json(); break; } catch { await sleep(250); } }
  const t = await (await fetch('http://127.0.0.1:9350/json/list')).json();
  const ws = new WebSocket(t.find((x) => x.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pend = new Map(); const errs = []; const cons = [];
  const send = (meth, p = {}) => new Promise((r) => { const mid = ++id; pend.set(mid, r); ws.send(JSON.stringify({ id: mid, method: meth, params: p })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const m2 = JSON.parse(ev.data);
    if (m2.id && pend.has(m2.id)) { pend.get(m2.id)(m2.result); pend.delete(m2.id); return; }
    if (m2.method === 'Runtime.exceptionThrown') errs.push(((m2.params.exceptionDetails.exception || {}).description || m2.params.exceptionDetails.text || '') + ' @' + ((m2.params.exceptionDetails.scriptId || '') + ':' + (m2.params.exceptionDetails.lineNumber ?? '') + ':' + (m2.params.exceptionDetails.columnNumber ?? '')));
    if (m2.method === 'Runtime.consoleAPICalled' && m2.params.type === 'error') cons.push(m2.params.args.map((a) => a.value || a.description).join(' ').slice(0, 300));
  });
  await send('Runtime.enable'); await send('Page.enable');
  await send('Network.setCookie', { name: 'LSSESSID', value: sess, domain: '127.0.0.1', path: '/' });
  await send('Page.navigate', { url: BACK + '/admin/page/' + PAGE + '.html' });
  await sleep(4200);
  const r = await send('Runtime.evaluate', {
    expression: 'JSON.stringify({ tables: document.querySelectorAll(".layui-table-view").length, selects: document.querySelectorAll("select").length, rawTable: !!document.querySelector("#projTable") })',
    returnByValue: true,
  });
  console.log('PAGE_STATE=', r.result.value);
  console.log('EXCEPTIONS=', JSON.stringify(errs, null, 1));
  console.log('CONSOLE_ERR=', JSON.stringify(cons, null, 1));
  ws.close(); chrome.kill(); await sleep(300);
  try { fs.rmSync(dir, { recursive: true, force: true }); } catch {}
  process.exit(0);
})().catch((e) => { console.log('ERR', e); process.exit(1); });
