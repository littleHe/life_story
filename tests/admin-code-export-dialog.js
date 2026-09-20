// 后台「导出兑换码」对话框 → 真实触发 /admin-api/code/export 请求（CDP 零依赖）
// 流程：API 生成 2 个码 → 打开页面 → 点「导出兑换码」→ 点「导出 CSV」→ 断言请求 URL 正确 → 清理
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BACK = 'http://127.0.0.1:9411';
const PORT = 9348;
const MYSQL = 'D:\\phpstudy_pro\\Extensions\\MySQL8.0.12\\bin\\mysql.exe';
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();
const mysql = (sql) => execFileSync(MYSQL, ['-h127.0.0.1', '-uroot', '-proot', 'life_story', '-e', sql], { stdio: 'pipe' }).toString();

let pass = 0, fail = 0;
const ok = (c, m, ex = '') => { if (c) { pass++; console.log('PASS ' + m + (ex ? ' :: ' + ex : '')); } else { fail++; console.log('FAIL ' + m + (ex ? ' :: ' + ex : '')); } };

;(async () => {
  // 管理员 session（同时用于 API 生成与页面注入）
  const raw = execFileSync('curl', ['-s', '-i', '-X', 'POST', BACK + '/admin-api/login', '-H', 'Content-Type: application/json', '-d', '{"username":"admin","password":"admin888"}']).toString();
  const m = raw.match(/Set-Cookie:\s*LSSESSID=([^;\r\n]+)/i);
  const sess = m ? m[1] : '';
  if (!sess) { console.log('登录失败'); process.exit(1); }

  // 生成 2 个码
  const g = await (await fetch(BACK + '/admin-api/code/generate', { method: 'POST', headers: { 'Content-Type': 'application/json', Cookie: 'LSSESSID=' + sess }, body: JSON.stringify({ count: 2, max_uses: 1 }) })).json();
  const batch = g.data.batch_no;
  ok(g.data.codes.length === 2, '预生成 2 个码', batch);

  const userDataDir = path.join(os.tmpdir(), 'ls_admin_expdlg_' + Date.now());
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDataDir, '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=1280,900', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map(); const reqUrls = [];
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const m2 = JSON.parse(ev.data);
    if (m2.id && pending.has(m2.id)) { pending.get(m2.id)(m2.result); pending.delete(m2.id); }
    if (m2.method === 'Network.requestWillBeSent' && m2.params && m2.params.request) reqUrls.push(m2.params.request.url);
  });
  await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');
  await send('Network.setCookie', { name: 'LSSESSID', value: sess, domain: '127.0.0.1', path: '/' });
  await send('Page.navigate', { url: BACK + '/admin/page/code.html' });
  await sleep(3500);

  // 打开导出对话框
  await send('Runtime.evaluate', { expression: "var b=document.querySelector('[lay-event=export]'); if(b) b.click();" });
  await sleep(1000);
  const dlgShown = await send('Runtime.evaluate', { expression: "!!document.querySelector('.layui-layer-title') && document.body.innerText.indexOf('导出范围')>=0", returnByValue: true });
  ok(dlgShown.result && dlgShown.result.value === true, '导出对话框已打开');

  // 点「导出 CSV」
  reqUrls.length = 0;
  await send('Runtime.evaluate', { expression: "document.querySelector('#btnDoExport').click();" });
  await sleep(1800);
  const hit = reqUrls.find((u) => u.indexOf('/admin-api/code/export') >= 0);
  ok(!!hit, '点击导出触发 /admin-api/code/export 请求', hit || '(未捕获)');
  ok(!!hit && hit.indexOf('scope=all') >= 0, '请求带 scope 参数', (hit || '').split('?')[1] || '');

  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch { /* ignore */ }

  // 清理
  mysql(`DELETE FROM ls_redemption_code WHERE batch_no='${batch}'`);
  const left = mysql(`SELECT COUNT(*) AS n FROM ls_redemption_code WHERE batch_no='${batch}'`).replace(/[^0-9]/g, '');
  ok(left === '' || left === '0', '测试码已清理', 'left=' + (left || '0'));

  console.log(`\n=== ${pass} PASS / ${fail} FAIL ===`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
