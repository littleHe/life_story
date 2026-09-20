// 后台「历史兑换码（无明文存档）」UI 链路（CDP 零依赖）
//
// 复现并锁定用户反馈：后台「导出兑换码」导出为空（CSV 只有表头，且页面无任何提示）。
// 通过 CDP 走真实界面：
//   1) 造一条无明文存档的历史码（只有 sha256），并用批次号把它单独框出来
//   2) 打开导出对话框 → 导出该批次 → 必须弹出「导出为空」说明（而不是静默下载空文件）
//   3) 行内「回填」→ 输入原始明文 → 提交 → 落库加密存档
//   4) 再次导出 → 捕获 Blob 内容，断言含明文且有「已导出」提示
//   5) 清理测试数据
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BACK = 'http://127.0.0.1:9411';
const PORT = 9351;
const MYSQL = 'D:\\phpstudy_pro\\Extensions\\MySQL8.0.12\\bin\\mysql.exe';
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();
const mysql = (sql) => execFileSync(MYSQL, ['-h127.0.0.1', '-uroot', '-proot', 'life_story', '-e', sql], { stdio: 'pipe' }).toString();

const LEGACY = 'LEGACYUI0001';                 // 模拟历史码：明文只在管理员手上
const BATCH = 'BUI' + Date.now().toString().slice(-8);
const TODAY = new Date().toISOString().slice(0, 10);

let pass = 0, fail = 0;
const ok = (c, m, ex = '') => { if (c) { pass++; console.log('PASS ' + m + (ex ? ' :: ' + ex : '')); } else { fail++; console.log('FAIL ' + m + (ex ? ' :: ' + ex : '')); } };

;(async () => {
  // 管理员 session
  const raw = execFileSync('curl', ['-s', '-i', '-X', 'POST', BACK + '/admin-api/login', '-H', 'Content-Type: application/json', '-d', '{"username":"admin","password":"admin888"}']).toString();
  const m = raw.match(/Set-Cookie:\s*LSSESSID=([^;\r\n]+)/i);
  const sess = m ? m[1] : '';
  if (!sess) { console.log('登录失败'); process.exit(1); }

  // 造历史码：有 sha256、无 code_cipher（加密功能上线前的数据形态），并归到独立批次便于框定
  mysql(`DELETE FROM ls_redemption_code WHERE code_hash = SHA2('${LEGACY}', 256)`);
  mysql(`INSERT INTO ls_redemption_code (code_hash, code_mask, status, batch_no, max_uses, used_count, created_at, updated_at) VALUES (SHA2('${LEGACY}', 256), '******0001', 'GENERATED', '${BATCH}', 1, 0, NOW(), NOW())`);
  const id = parseInt(mysql(`SELECT id FROM ls_redemption_code WHERE batch_no='${BATCH}'`).trim().split('\n').pop(), 10);
  ok(id > 0, '已造无明文存档的历史码', `id=${id} batch=${BATCH}`);

  const userDataDir = path.join(os.tmpdir(), 'ls_admin_legacy_' + Date.now());
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDataDir, '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=1440,900', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id2 = 0; const pending = new Map(); const jsErrors = [];
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id2; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg.result); pending.delete(msg.id); }
    if (msg.method === 'Runtime.exceptionThrown') jsErrors.push(msg.params.exceptionDetails.text || 'exception');
  });
  const ev = async (expr) => { const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); return r.result ? r.result.value : undefined; };
  const shot = async (name) => {
    const r = await send('Page.captureScreenshot', { format: 'png' });
    if (r && r.data) { fs.writeFileSync(path.join(OUT, name), Buffer.from(r.data, 'base64')); console.log('SHOT -> ' + path.join(OUT, name)); }
  };

  await send('Runtime.enable'); await send('Page.enable');
  await send('Network.setCookie', { name: 'LSSESSID', value: sess, domain: '127.0.0.1', path: '/' });
  await send('Page.navigate', { url: BACK + '/admin/page/code.html' });
  await sleep(3800);

  // 列表应把该行标为「无」
  const listText = await ev("document.body.innerText");
  ok(listText.indexOf('明文存档') >= 0, '列表出现「明文存档」列');

  // ---- 1) 导出该批次：必须提示「导出为空」，而不是静默下载 ----
  await ev("document.querySelector('[lay-event=export]').click();");
  await sleep(900);
  await ev("var i=document.querySelector('#expBatch'); i.value='" + BATCH + "'; i.dispatchEvent(new Event('input',{bubbles:true}));");
  await ev("document.querySelector('#btnDoExport').click();");
  await sleep(2200);
  const alertText = await ev("(document.querySelector('.layui-layer-dialog .layui-layer-content')||{}).innerText || ''");
  ok(alertText.indexOf('导出为空') >= 0 || alertText.indexOf('没有可导出的明文') >= 0, '弹出「导出为空」说明与处理办法', alertText.slice(0, 60).replace(/\n/g, ' '));
  ok(alertText.indexOf('回填') >= 0, '说明中给出「回填 / 重发新码」解决路径');
  await shot('admin-code-legacy-empty.png');
  await ev("layer.closeAll();");
  await sleep(600);

  // ---- 2) 行内「回填」：错误明文应被拒 ----
  await ev("document.querySelector('[lay-event=backfill]').click();");
  await sleep(900);
  const bfOpen = await ev("document.body.innerText.indexOf('校验并存档') >= 0");
  ok(bfOpen === true, '「回填」对话框已打开');
  await ev("var i=document.querySelector('#backfillBox input[name=code]'); i.value='WRONGCODE999'; i.dispatchEvent(new Event('input',{bubbles:true}));");
  await ev("document.querySelector('#backfillBox [lay-filter=backfillSave]').click();");
  await sleep(1500);
  const cipherAfterBad = mysql(`SELECT IF(code_cipher IS NULL,'NULL','SET') s FROM ls_redemption_code WHERE id=${id}`).trim().split('\n').pop();
  ok(cipherAfterBad === 'NULL', '错误明文被拒，未写入密文', 'cipher=' + cipherAfterBad);
  await ev("layer.closeAll();");
  await sleep(600);

  // ---- 3) 回填正确明文 → 存档成功 ----
  await ev("document.querySelector('[lay-event=backfill]').click();");
  await sleep(900);
  await ev("var i=document.querySelector('#backfillBox input[name=code]'); i.value='" + LEGACY + "'; i.dispatchEvent(new Event('input',{bubbles:true}));");
  await ev("document.querySelector('#backfillBox [lay-filter=backfillSave]').click();");
  await sleep(1800);
  const cipherAfterOk = mysql(`SELECT IF(code_cipher IS NULL,'NULL','SET') s FROM ls_redemption_code WHERE id=${id}`).trim().split('\n').pop();
  ok(cipherAfterOk === 'SET', '正确明文校验通过并加密存档', 'cipher=' + cipherAfterOk);
  const msgText = await ev("(document.querySelector('.layui-layer-msg')||{}).innerText || ''");
  ok(String(msgText).indexOf('已加密存档') >= 0, '页面提示已加密存档', msgText);
  const rowHas = await ev("document.body.innerText.indexOf('已存档') >= 0");
  ok(rowHas === true, '列表该行变为「已存档」');
  await shot('admin-code-legacy-backfilled.png');

  // ---- 4) 再导出：捕获 Blob 内容 + 提示 ----
  await ev(`(function(){ window.__csv=null; var o=URL.createObjectURL; URL.createObjectURL=function(b){ try{ var f=new FileReader(); f.onload=function(){ window.__csv=f.result; }; f.readAsText(b); }catch(e){} return 'blob:mock'; }; })();`);
  await ev("document.querySelector('[lay-event=export]').click();");
  await sleep(900);
  await ev("var i=document.querySelector('#expBatch'); i.value='" + BATCH + "'; i.dispatchEvent(new Event('input',{bubbles:true}));");
  await ev("document.querySelector('#btnDoExport').click();");
  await sleep(2200);
  const csv = await ev("window.__csv || ''");
  ok(typeof csv === 'string' && csv.indexOf(LEGACY) >= 0, '导出 CSV 含该明文', String(csv).replace(/\r?\n/g, ' | ').slice(0, 90));
  ok(typeof csv === 'string' && csv.indexOf('兑换码,状态,批次,创建时间') >= 0, 'CSV 表头正确');
  const toast = await ev("(document.querySelector('.layui-layer-msg')||{}).innerText || ''");
  ok(String(toast).indexOf('已导出 1') >= 0, '提示已导出 1 个兑换码', toast);
  await shot('admin-code-legacy-exported.png');

  ok(jsErrors.length === 0, '交互过程无未捕获 JS 异常', jsErrors.join('; ') || 'none');

  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch { /* ignore */ }

  // 清理
  mysql(`DELETE FROM ls_redemption_code WHERE id=${id}`);
  const left = mysql(`SELECT COUNT(*) FROM ls_redemption_code WHERE id=${id}`).trim().split('\n').pop();
  ok(left === '0', '测试码已清理', 'left=' + left);

  console.log(`\n=== ${pass} PASS / ${fail} FAIL ===`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
