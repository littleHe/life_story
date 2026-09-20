// 后台「背景音乐」CRUD 回归：编辑弹窗能打开 + 回填正确 + 改标题能落库 + 添加弹窗能打开
//
// 背景：bgm.html 由 coverbg.html 复制而来，隐藏域沿用了 name="file"，
// 而 layui upload 会在表单内注入 <input type="file" name="file" class="layui-upload-file">，
// 同名导致 form.val() 命中文件域 → InvalidStateError → 编辑弹窗根本打不开。
// 本用例以「弹窗是否打开 + 字段是否回填 + 值是否落库」锁定该回归。
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BACK = process.env.BACK || 'http://127.0.0.1:9411';
const PORT = Number(process.env.CDP_PORT || 9357);
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

let pass = 0, fail = 0;
const step = (c, m, ex = '') => { if (c) { pass++; console.log('PASS ' + m + (ex ? ' :: ' + ex : '')); } else { fail++; console.log('FAIL ' + m + (ex ? ' :: ' + ex : '')); } };

;(async () => {
  // 管理员 session
  const raw = execFileSync('curl', ['-s', '-i', '-X', 'POST', BACK + '/admin-api/login', '-H', 'Content-Type: application/json', '-d', '{"username":"admin","password":"admin888"}']).toString();
  const sess = (raw.match(/Set-Cookie:\s*LSSESSID=([^;\r\n]+)/i) || [])[1] || '';
  step(!!sess, '管理员登录成功');
  if (!sess) { console.log('\n=== ' + pass + ' PASS / ' + fail + ' FAIL ==='); process.exit(1); }
  const AH = { 'Content-Type': 'application/json', Cookie: 'LSSESSID=' + sess };
  const adminApi = async (p, m = 'GET', b) => (await fetch(BACK + '/admin-api' + p, { method: m, headers: AH, body: b ? JSON.stringify(b) : undefined })).json();

  // 通过 API 读取（JSON 走 HTTP，中文编码安全；不走 mysql.exe）
  const idx0 = await adminApi('/bgm/index');
  const rows = idx0.data || [];
  step(rows.length > 0, '背景音乐池有数据', 'count=' + idx0.count);
  const target = rows[0];
  const origTitle = String(target.title || '');
  const origFile = String(target.file || '');
  step(!!origFile, '第一条有音频地址', origFile);

  // 固定 profile 目录：既避免每跑一次堆一个新目录，也避免用 rmSync 清理（会触发沙箱强杀，摘要输出被吞）
  const userDataDir = path.join(os.tmpdir(), 'ls_bgm_crud_profile');
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDataDir, '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=1366,900', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map(); const logs = []; const reqs = [];
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); }
    if (m.method === 'Runtime.exceptionThrown') { const d = m.params.exceptionDetails || {}; logs.push('[EXC] ' + (d.text || '') + ' ' + ((d.exception && d.exception.description) || '')); }
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') logs.push('[ERR] ' + (m.params.args || []).map((a) => a.value || a.description || '').join(' '));
    if (m.method === 'Network.requestWillBeSent') reqs.push(m.params.request.method + ' ' + m.params.request.url);
  });
  const evalJs = async (expr) => { const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); return r && r.result ? r.result.value : undefined; };

  await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');
  await send('Network.setCookie', { name: 'LSSESSID', value: sess, domain: '127.0.0.1', path: '/' });
  await send('Page.navigate', { url: BACK + '/admin/page/bgm.html' });
  await sleep(4000);

  const rowCount = await evalJs("document.querySelectorAll('.layui-table-body tbody tr').length");
  step(rowCount > 0, '表格已渲染', 'rows=' + rowCount);

  // ---- ① 点「编辑」应打开弹窗（回归点）----
  logs.length = 0;
  await send('Runtime.evaluate', { expression: "document.querySelector('[lay-event=edit]').click();" });
  await sleep(1200);
  const dlg = await evalJs("JSON.stringify({layers:document.querySelectorAll('.layui-layer').length, title:(document.querySelector('.layui-layer-title')||{}).innerText||'', exc:window.__exc||0})");
  const d = JSON.parse(dlg || '{}');
  step(d.layers >= 1, '点「编辑」弹出编辑弹窗（此前抛 InvalidStateError 打不开）', 'layers=' + d.layers);
  step(String(d.title).indexOf('编辑背景音乐') >= 0, '弹窗标题正确', d.title || '(空)');
  step(logs.filter((l) => l.indexOf('InvalidStateError') >= 0).length === 0, '无 InvalidStateError 异常', logs.length ? logs.join(' | ') : '(无异常)');

  // ---- ② 回填正确：标题 = 原值，音频地址非空 ----
  const filled = await evalJs("JSON.stringify({title:(document.querySelector('.layui-layer input[name=title]')||{}).value, audio:document.querySelector('#imageInput') ? document.querySelector('#imageInput').value : '', preview:(document.querySelector('.layui-layer audio')||{}).getAttribute ? (document.querySelector('.layui-layer audio')||{}).getAttribute('src') : ''})");
  const f = JSON.parse(filled || '{}');
  step(String(f.title) === origTitle, '标题已回填为原值', JSON.stringify(f.title));
  step(String(f.audio) === origFile, '音频地址已回填到隐藏域', String(f.audio));
  step(String(f.preview) === origFile, '弹窗内音频预览 src 正确', String(f.preview));

  // ---- ③ 改标题保存 → 落库 ----
  const NEW_TITLE = 'E2E_BGM_TITLE_' + Date.now();
  await evalJs(`(function(){var el=document.querySelector('.layui-layer input[name=title]'); el.value=${JSON.stringify(NEW_TITLE)}; return el.value;})()`);
  reqs.length = 0;
  await send('Runtime.evaluate', { expression: "document.querySelector('.layui-layer [lay-filter=coverBgSave]').click();" });
  await sleep(2000);
  const saveReq = reqs.find((u) => u.indexOf('/admin-api/bgm/save') >= 0);
  step(!!saveReq, '点「保存」触发 /admin-api/bgm/save', saveReq || '(未捕获)');
  const afterSave = await adminApi('/bgm/index');
  const savedRow = (afterSave.data || []).find((r) => r.id === target.id);
  step(!!savedRow && String(savedRow.title) === NEW_TITLE, '标题已写库', savedRow ? String(savedRow.title) : '(未找到)');
  step(!!savedRow && String(savedRow.file) === origFile, '音频地址未被误改', savedRow ? String(savedRow.file) : '');

  // ---- ④ 还原标题（避免污染演示数据）----
  await sleep(400);
  await evalJs(`(function(){var b=document.querySelector('[lay-event=edit]'); if(b) b.click(); return 1;})()`);
  await sleep(1000);
  await evalJs(`(function(){var el=document.querySelector('.layui-layer input[name=title]'); if(!el) return 0; el.value=${JSON.stringify(origTitle)}; return 1;})()`);
  await send('Runtime.evaluate', { expression: "document.querySelector('.layui-layer [lay-filter=coverBgSave]').click();" });
  await sleep(1800);
  const afterRestore = await adminApi('/bgm/index');
  const restRow = (afterRestore.data || []).find((r) => r.id === target.id);
  step(!!restRow && String(restRow.title) === origTitle, '标题已还原为原值', restRow ? String(restRow.title) : '(未找到)');

  // ---- ⑤ 「添加音乐」弹窗也应能打开 ----
  await sleep(400);
  await send('Runtime.evaluate', { expression: "document.querySelector('[lay-event=add]').click();" });
  await sleep(1200);
  const addDlg = await evalJs("JSON.stringify({title:(document.querySelector('.layui-layer-title')||{}).innerText||'', audio:(document.querySelector('#imageInput')||{}).value, idField:(document.querySelector('.layui-layer input[name=id]')||{}).value})");
  const ad = JSON.parse(addDlg || '{}');
  step(String(ad.title).indexOf('添加背景音乐') >= 0, '「添加音乐」弹窗可打开', ad.title || '(空)');
  step(ad.audio === '' || ad.audio === undefined, '新增时音频地址已清空（不残留上一首）', JSON.stringify(ad.audio));

  const shot = await send('Page.captureScreenshot', { format: 'png' });
  if (shot && shot.data) { try { fs.writeFileSync(path.join(OUT, 'admin-bgm-edit.png'), Buffer.from(shot.data, 'base64')); } catch { /* ignore */ } }

  try { ws.close(); } catch { /* ignore */ }
  try { chrome.kill(); } catch { /* ignore */ }

  console.log('\n=== ' + pass + ' PASS / ' + fail + ' FAIL ===');
  // 不用 process.exit()：stdout 被重定向到文件时是异步写，立即退出会把最后一行摘要吞掉
  process.exitCode = fail ? 1 : 0;
})().catch((e) => { console.log('ERR ' + e); process.exitCode = 1; });

process.on('uncaughtException', (e) => { console.log('UNCAUGHT ' + (e && e.message || e)); process.exitCode = 1; });
process.on('unhandledRejection', (e) => { console.log('UNHANDLED ' + (e && e.message || e)); process.exitCode = 1; });
