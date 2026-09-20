// 后台兑换码「生成→导出」e2e（零依赖 CDP）
// 验证：生成成功弹窗含 复制/CSV/打印 三按钮；交互无未捕获异常；测后清理新增码
// 用法：node tests/admin-code-export.js
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BACK = 'http://127.0.0.1:9411';
const PORT = 9347;
const MYSQL = 'D:\\phpstudy_pro\\Extensions\\MySQL8.0.12\\bin\\mysql.exe';
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();
const q = (sql) => execFileSync(MYSQL, ['-N', '-h127.0.0.1', '-uroot', '-proot', 'life_story', '-e', sql]).toString();

let pass = 0, fail = 0;
const check = (ok, label, extra = '') => { if (ok) { pass++; console.log('PASS ' + label + (extra ? '  (' + extra + ')' : '')); } else { fail++; console.log('FAIL ' + label + (extra ? '  (' + extra + ')' : '')); } };

;(async () => {
  const raw = execFileSync('curl', ['-s', '-i', '-X', 'POST', BACK + '/admin-api/login', '-H', 'Content-Type: application/json', '-d', '{"username":"admin","password":"admin888"}']).toString();
  const m = raw.match(/Set-Cookie:\s*LSSESSID=([^;\r\n]+)/i);
  const sess = m ? m[1] : '';
  if (!sess) { console.log('登录失败'); process.exit(1); }

  const maxBefore = parseInt(q('SELECT IFNULL(MAX(id),0) FROM ls_redemption_code').trim(), 10) || 0;
  console.log('生成前 max id =', maxBefore);

  const userDataDir = path.join(os.tmpdir(), 'ls_code_export_' + Date.now());
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDataDir, '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=1280,900', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  const errs = [];
  ws.addEventListener('message', (ev) => {
    const m2 = JSON.parse(ev.data);
    if (m2.id && pending.has(m2.id)) { pending.get(m2.id)(m2.result); pending.delete(m2.id); }
    if (m2.method === 'Runtime.exceptionThrown') { errs.push(m2.params.exceptionDetails.text); }
  });
  await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');
  await send('Network.setCookie', { name: 'LSSESSID', value: sess, domain: '127.0.0.1', path: '/' });
  await send('Page.navigate', { url: BACK + '/admin/page/code.html' });
  await sleep(3500);

  const evalJs = async (expr) => { const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true }); return r && r.result ? r.result.value : undefined; };

  await evalJs(`(document.querySelector('.layui-table-tool [lay-event=generate]')||document.querySelector('[lay-event=generate]')).click(); 1`);
  await sleep(900);
  check(!!(await evalJs(`!!document.querySelector('#genBox input[name=count]')`)), '打开「生成兑换码」弹窗');

  const submit = `(async function(){
    var sleep=function(ms){return new Promise(function(r){setTimeout(r,ms);});};
    var c=document.querySelector('#genBox input[name=count]'); if(c) c.value='2';
    var mu=document.querySelector('#genBox input[name=max_uses]'); if(mu) mu.value='1';
    var btn=document.querySelector('#genBox button[lay-filter=genSave]'); if(btn) btn.click();
    await sleep(2500);
    var ta=document.querySelector('#genResult');
    var codes=(ta&&ta.value?ta.value.split('\\n'):[]).filter(Boolean);
    return JSON.stringify({
      hasBox: !!document.querySelector('#resultBox'),
      hasCopy: !!document.querySelector('#btnCopyCodes'),
      hasCsv: !!document.querySelector('#btnCsvCodes'),
      hasPrint: !!document.querySelector('#btnPrintCodes'),
      codeCount: codes.length,
      firstCode: codes[0]||''
    });
  })()`;
  const r1 = JSON.parse(await evalJs(submit));
  check(r1.hasBox, '生成成功弹出结果弹窗');
  check(r1.codeCount === 2, '弹窗明文条数=2', 'count=' + r1.codeCount);
  check(/^[A-Z0-9]{12}$/.test(r1.firstCode || ''), '明文为 12 位大写码', 'code=' + r1.firstCode);
  check(r1.hasCopy && r1.hasCsv && r1.hasPrint, '弹窗含 复制/CSV/打印 三按钮', 'copy=' + r1.hasCopy + ',csv=' + r1.hasCsv + ',print=' + r1.hasPrint);

  const shot = await send('Page.captureScreenshot', { format: 'png' });
  const file = path.join(OUT, 'admin-code-export.png');
  fs.writeFileSync(file, Buffer.from(shot.data, 'base64'));
  console.log('SHOT ->', file);

  await evalJs(`document.querySelector('#btnCsvCodes').click(); 1`);
  await sleep(700);
  await evalJs(`document.querySelector('#btnCopyCodes').click(); 1`);
  await sleep(400);
  check(errs.length === 0, '交互过程无未捕获 JS 异常', errs.slice(0, 2).join(' | '));

  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch { /* ignore */ }

  execFileSync(MYSQL, ['-N', '-h127.0.0.1', '-uroot', '-proot', 'life_story', '-e', `DELETE FROM ls_redemption_code WHERE id > ${maxBefore};`]);
  console.log('已清理新增码；剩余码：');
  console.log(q('SELECT CONCAT(id,"  ",code_mask) FROM ls_redemption_code').trim());
  console.log(`\n=== ${pass} PASS / ${fail} FAIL ===`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
