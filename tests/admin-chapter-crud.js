/**
 * 回忆录后台 · 「系统章节」CRUD 写路径回归（真实 Chrome，自包含无第三方依赖）
 *
 * 为什么需要它：
 *   原 admin-e2e.js 只断言页面「渲染正常」，从不触发任何写操作，
 *   因此以下真实 bug 长期未被发现：
 *     1) public/static/admin/js/common.js 把取值函数当 jQuery 用（$.ajax，应为 $().ajax），
 *        导致所有经 AdminAPI 的写操作（保存/删除/生成兑换码…）静默抛错、一律不落库；
 *     2) ChapterController::save 只有 update（要求 id>0），没有 INSERT 分支，
 *        「添加章节」永远写不进库。
 *
 * 设计约定（经用户确认）：
 *   系统章节 = 全局默认参考章节模板，不归属任何项目（project_id 为 NULL）。
 *   表单不含「所属项目」选择，新增时直接写库即可。
 *
 * 本脚本用真实浏览器完整走一遍「新增 → 校验落库 → 删除 → 校验消失」，自清理、可重复运行。
 *
 * 前置：后端已启动（php think run -p 9411）
 * 运行：node tests/admin-chapter-crud.js
 * 退出码：0 = 通过；1 = 失败
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.ADMIN_BASE || 'http://127.0.0.1:9411';
const USER = process.env.ADMIN_USER || 'admin';
const PASS = process.env.ADMIN_PASS || 'admin888';
const PORT = Number(process.env.CDP_PORT || 9337);
const SHOT_DIR = path.join(__dirname, '..', 'screenshots');
const TITLE = '自动化回归章节'; // 测试用章节标题（跑完即删，不留痕）

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

async function login() {
  const res = await fetch(BASE + '/admin-api/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: USER, password: PASS }),
  });
  const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie')];
  const hit = (raw || []).map((c) => /LSSESSID=([^;]+)/.exec(c || '')).find(Boolean);
  if (!hit) throw new Error('登录未返回 LSSESSID');
  return hit[1];
}

/** 点「添加」→ 确认弹层出现（系统章节无「所属项目」选择，直接填标题即可） */
const PHASE_OPEN = `(async function(){
  var sleep = function(ms){ return new Promise(function(r){ setTimeout(r, ms); }); };
  var out = {};
  // 只统计主表格行（layui 对 fixed 列会克隆 .layui-table-fixed，直接数全部 tbody tr 会翻倍）
  out.before = document.querySelectorAll('.layui-table-main tbody tr').length;
  var addBtn = document.querySelector('.layui-table-tool [lay-event=add]');
  out.addBtn = !!addBtn;
  if (!addBtn) return JSON.stringify(out);
  addBtn.click(); await sleep(900);
  out.hasLayer = !!document.querySelector('.layui-layer');
  return JSON.stringify(out);
})()`;

/** 填标题 + 排序 + 保存 */
const PHASE_SAVE = `(async function(){
  var sleep = function(ms){ return new Promise(function(r){ setTimeout(r, ms); }); };
  var out = {};
  document.querySelector('#formBox input[name=title]').value = ${JSON.stringify(TITLE)};
  document.querySelector('#formBox input[name=sort]').value = '99';
  document.querySelector('#formBox button[lay-filter=chapterSave]').click();
  await sleep(2200);
  out.layerClosed = !document.querySelector('.layui-layer');
  return JSON.stringify(out);
})()`;

/** 关键词搜索测试章节，规避分页导致新增空章节不在首页（默认按资源数降序，空章节排末尾） */
const PHASE_SEARCH = `(async function(){
  var sleep = function(ms){ return new Promise(function(r){ setTimeout(r, ms); }); };
  var kw = document.querySelector('input[name=keyword]');
  kw.value = ${JSON.stringify(TITLE)};
  document.querySelector('#searchForm button[lay-submit]').click();
  await sleep(1600);
  var out = {};
  out.rows = document.querySelectorAll('.layui-table-main tbody tr').length;
  out.bodyText = (document.querySelector('.layui-table-main') || {}).innerText || '';
  out.hasNew = out.bodyText.indexOf(${JSON.stringify(TITLE)}) >= 0;
  return JSON.stringify(out);
})()`;

/** 删除：先关键词过滤定位到第一页，循环删除所有同名测试行（兼容历史重复残留），再校验清空 */
const PHASE_DELETE = `(async function(){
  var sleep = function(ms){ return new Promise(function(r){ setTimeout(r, ms); }); };
  var TITLE = ${JSON.stringify(TITLE)};
  function collect(){
    var rows = [];
    Array.prototype.forEach.call(document.querySelectorAll('.layui-table-main tbody tr'), function(tr){
      if (tr.innerText.indexOf(TITLE) >= 0) rows.push(tr);
    });
    return rows;
  }
  async function search(){
    var kw = document.querySelector('input[name=keyword]');
    kw.value = TITLE;
    document.querySelector('#searchForm button[lay-submit]').click();
    await sleep(1500);
  }
  var out = { deleted: 0 };
  await search();
  out.before = collect().length;
  while (true) {
    var rows = collect();
    if (!rows.length) break;
    rows[0].querySelector('[lay-event=del]').click();
    await sleep(700);
    var ok = document.querySelector('.layui-layer-btn .layui-layer-btn0');
    if (out.confirmShown === undefined) out.confirmShown = !!ok;
    if (ok) ok.click();
    await sleep(1800);
    out.deleted++;
  }
  await search();
  out.after = collect().length;
  out.bodyText = (document.querySelector('.layui-table-main') || {}).innerText || '';
  return JSON.stringify(out);
})()`;

(async () => {
  fs.mkdirSync(SHOT_DIR, { recursive: true });

  let sid;
  try { sid = await login(); }
  catch (e) { console.log('FAIL 登录失败: ' + e.message); process.exit(1); }
  console.log('PASS 登录成功');

  const userDataDir = path.join(os.tmpdir(), 'ls_chap_' + Date.now());
  const chrome = spawn(CHROME, [
    '--headless=new', '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + userDataDir, '--no-first-run', '--no-default-browser-check',
    '--disable-gpu', '--disable-extensions', '--window-size=1200,900', 'about:blank',
  ], { stdio: 'ignore' });

  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) {
    try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch (e) { await sleep(250); }
  }
  if (!ready) { console.log('FAIL Chrome 未就绪'); chrome.kill(); process.exit(1); }

  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);

  let id = 0; const pending = new Map(); const exceptions = [];
  const send = (method, params = {}) => new Promise((res) => {
    const mid = ++id; pending.set(mid, res);
    ws.send(JSON.stringify({ id: mid, method, params }));
  });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') {
      const d = m.params.exceptionDetails;
      exceptions.push(((d.exception && (d.exception.description || d.exception.value)) || d.text || '').split('\n')[0]);
    }
  });

  const evalJs = async (expr, awaitPromise = false) => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise });
    return r.result && r.result.value;
  };
  const shot = async (name) => {
    const r = await send('Page.captureScreenshot', { format: 'png' });
    if (r && r.data) {
      fs.writeFileSync(path.join(SHOT_DIR, name), Buffer.from(r.data, 'base64'));
      console.log('     ↳ 截图: screenshots/' + name);
    }
  };

  await send('Runtime.enable');
  await send('Page.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 1200, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Network.setCookie', { name: 'LSSESSID', value: sid, domain: '127.0.0.1', path: '/' });

  const failures = [];
  const check = (ok, label, extra) => {
    if (ok) console.log('PASS ' + label + (extra ? '  ' + extra : ''));
    else { failures.push(label); console.log('FAIL ' + label + (extra ? '  ' + extra : '')); }
  };

  await send('Page.navigate', { url: BASE + '/admin/page/chapter.html' });
  await sleep(3000);

  // ---- 新增 ----
  const a = JSON.parse(await evalJs(PHASE_OPEN, true));
  await shot('chapter-add-dialog.png');
  check(a.addBtn, '「添加」按钮存在');
  check(a.hasLayer, '点击后弹出「添加章节」弹层');

  const b = JSON.parse(await evalJs(PHASE_SAVE, true));
  await shot('chapter-list-after-add.png');
  check(b.layerClosed, '保存后弹层自动关闭');

  // 关键词搜索把测试章节定位到首页，规避分页（数据量大时新增空章节排序靠后）
  const s = JSON.parse(await evalJs(PHASE_SEARCH, true));
  check(s.rows >= 1, '搜索后列表命中测试章节', s.rows + ' 行');
  check(s.hasNew, '列表出现新章节「' + TITLE + '」');

  const idx = await (await fetch(BASE + '/admin-api/chapter/index?page=1&limit=50&keyword=' + encodeURIComponent(TITLE), {
    headers: { Cookie: 'LSSESSID=' + sid },
  })).json();
  check((idx.data || []).some((r) => r.title === TITLE), '后端确认落库', 'count=' + idx.count);

  // ---- 删除（清理 + 验证 AdminAPI.del 路径） ----
  const c = JSON.parse(await evalJs(PHASE_DELETE, true));
  check(c.confirmShown, '删除弹出确认框');
  check(c.after === 0 && c.deleted >= 1, '删除后测试章节已从列表清空', 'before=' + c.before + ' deleted=' + c.deleted + ' after=' + c.after);
  check(c.bodyText.indexOf(TITLE) < 0, '新增行已从列表消失');

  check(exceptions.length === 0, '无 JS 异常', exceptions[0] || '');

  console.log('--------------------------------------------------');
  console.log(failures.length ? ('结果: 失败 — ' + failures.join(', ')) : '结果: 全部通过');
  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch (e) {}
  process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
