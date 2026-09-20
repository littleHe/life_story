/**
 * 回忆录后台 · 浏览器端回归测试（自包含，无第三方依赖）
 *
 * 用途：用真实 Chrome 打开后台全部页面，断言「无 JS 异常 + 无失败请求 + 关键 DOM 已渲染」，
 *      并额外验证菜单页的 Font Awesome 图标选择器（数据加载 / 点选写回 / 编辑回显 / 搜索过滤）。
 *
 * 前置：后端开发服务器已启动（backend 目录下 `php think run -p 9411 -H 127.0.0.1`），
 *      数据库已导入并 seed（admin / admin888）。脚本自己完成登录拿会话。
 *
 * 运行：node tests/admin-e2e.js
 * 退出码：0 = 全部通过；1 = 存在失败项
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.ADMIN_BASE || 'http://127.0.0.1:9411';
const USER = process.env.ADMIN_USER || 'admin';
const PASS = process.env.ADMIN_PASS || 'admin888';
const PORT = 9336;

const PAGES = [
  { url: '/admin/index.html', name: '后台框架', layout: 'shell', must: ['.layuimini-menu-left li', '.layuimini-logo img', '.layuimini-tab'] },
  { url: '/admin/welcome.html', name: '控制台', must: ['.layui-card', '.welcome-module', '#statPanels .panel', '#projTable', '#trend'] },
  { url: '/admin/login.html', name: '登录页', noAuth: true, must: ['.main-body', '.login-main', '.login-top', '.login-bottom', '.login-btn'] },
  // 列表页：断言「条件搜索」fieldset（有搜索的页）+ 标准 #toolbar 已渲染（刷新按钮 layuimini-btn-primary 存在于 .layui-table-tool）
  { url: '/admin/page/chapter.html', name: '系统章节', must: ['.layui-table-view', '.layui-form', '.table-search-fieldset', '.layui-table-tool .layuimini-btn-primary', '.layui-table-tool .layui-icon-search'] },
  { url: '/admin/page/defaultchapter.html', name: '默认章节', must: ['.layui-table-view', '.layui-form', '.table-search-fieldset', '.layui-table-tool .layuimini-btn-primary', '.layui-table-tool .layui-icon-search'] },
  { url: '/admin/page/user.html', name: '授权用户', must: ['.layui-table-view', '.layui-form', '.table-search-fieldset', '.layui-table-tool .layuimini-btn-primary', '.layui-table-tool .layui-icon-search'] },
  { url: '/admin/page/apilog.html', name: '接口日志', must: ['.layui-table-view', '.layui-form', '.table-search-fieldset', '.layui-table-tool .layuimini-btn-primary', '.layui-table-tool .layui-btn-danger'] },
  { url: '/admin/page/code.html', name: '兑换码', must: ['.layui-table-view', '.layui-form', '.table-search-fieldset', '.layui-table-tool .layuimini-btn-primary', '.layui-table-tool .layui-btn-normal'] },
  { url: '/admin/page/codelog.html', name: '兑换码日志', must: ['.layui-table-view', '.layui-tab', '.layui-table-tool .layuimini-btn-primary'] },
  { url: '/admin/page/slide.html', name: '首页幻灯片', must: ['.layui-table-view', '.layui-form', '.layui-table-tool .layuimini-btn-primary'] },
  { url: '/admin/page/adminuser.html', name: '管理员', must: ['.layui-table-view', '.layui-form', '.table-search-fieldset', '.layui-table-tool .layuimini-btn-primary', '.layui-table-tool .layui-icon-search'] },
  { url: '/admin/page/menu.html', name: '菜单管理', must: ['.layui-table-view', '.layui-iconpicker', '.layui-table-tool .layuimini-btn-primary'] },
];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

async function login() {
  const res = await fetch(BASE + '/admin-api/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: USER, password: PASS }),
  });
  const body = await res.json().catch(() => null);
  const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie')];
  const hit = (raw || []).map((c) => /LSSESSID=([^;]+)/.exec(c || '')).find(Boolean);
  if (!hit) throw new Error('登录未返回 LSSESSID，响应: ' + JSON.stringify(body));
  return hit[1];
}

const DOM_PROBE = `(function(){
  var q = function(s){ return document.querySelectorAll(s).length; };
  var cs = function(s){ var e = document.querySelector(s); return e ? getComputedStyle(e).display : null; };
  var h = function(s){ var e = document.querySelector(s); return e ? Math.round(e.getBoundingClientRect().height) : 0; };
  return JSON.stringify({
    title: document.title,
    loader: cs('.layuimini-loader'),
    make: cs('.layuimini-make'),
    hits: window.__HITS__ || {},
    faIcons: Array.prototype.map.call(
      document.querySelectorAll('.layuimini-menu-left i[class^=fa]'), function(i){ return i.className; }),
    // 框架布局：内容 iframe 必须撑满 .layui-body（缺 layuimini-tab 类会退回浏览器默认 150px）
    bodyH: h('.layui-body'),
    tabTitleH: h('.layuimini-tab .layui-tab-title'),
    iframeH: h('.layui-tab-item iframe')
  });
})()`;

const ICON_PROBE = `(async function(){
  var sleep = function(ms){ return new Promise(function(r){ setTimeout(r, ms); }); };
  var q = function(s){ return document.querySelectorAll(s).length; };
  var out = {};
  out.iconCount = q('.layui-iconpicker-icon-item');
  out.hasSearch = !!document.querySelector('.layui-iconpicker-search input');

  var items = document.querySelectorAll('.layui-iconpicker-icon-item');
  if (items.length) { items[0].click(); await sleep(250); }
  out.afterPick = (document.querySelector('#icon') || {}).value || null;

  var si = document.querySelector('.layui-iconpicker-search input');
  if (si) {
    var before = q('.layui-iconpicker-icon-item');
    si.value = 'book'; si.dispatchEvent(new Event('input', {bubbles:true}));
    await sleep(500);
    out.searchFiltered = before + ' -> ' + q('.layui-iconpicker-icon-item');
    si.value = ''; si.dispatchEvent(new Event('input', {bubbles:true}));
    await sleep(400);
  }

  // 真实编辑流程：首行「编辑」→ 校验图标值格式与预览 class
  var eb = document.querySelector('.layui-table-view .layui-table-body [lay-event=edit]');
  out.editBtn = !!eb;
  if (eb) {
    eb.click(); await sleep(900);
    out.editIconValue = (document.querySelector('#icon') || {}).value || null;
    var pv = document.querySelector('#icon + .layui-iconpicker .layui-iconpicker-item .fa');
    out.editPreviewClass = pv ? pv.className : null;
    var cb = document.querySelector('.layui-layer-close'); if (cb) { cb.click(); await sleep(300); }
  }
  return JSON.stringify(out);
})()`;

(async () => {
  let sid;
  try { sid = await login(); }
  catch (e) { console.log('FAIL 登录失败: ' + e.message); process.exit(1); }
  console.log('PASS 登录成功 (session ' + sid.slice(0, 8) + '...)');

  const userDataDir = path.join(os.tmpdir(), 'ls_e2e_' + Date.now());
  const chrome = spawn(CHROME, [
    '--headless=new', '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + userDataDir, '--no-first-run', '--no-default-browser-check',
    '--disable-gpu', '--disable-extensions', '--hide-scrollbars', 'about:blank',
  ], { stdio: 'ignore' });

  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) {
    try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch (e) { await sleep(250); }
  }
  if (!ready) { console.log('FAIL Chrome 未就绪，请检查 CHROME_PATH'); chrome.kill(); process.exit(1); }

  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);

  let id = 0; const pending = new Map();
  let exceptions = []; let problems = [];
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
    } else if (m.method === 'Network.responseReceived') {
      const r = m.params.response;
      // /admin-api/info 是登录页的「已登录则跳转」软探测，未登录时返回 401 属正常，不计为失败
      if (r.status >= 400 && !/\/admin-api\/info$/.test(r.url)) problems.push(r.status + ' ' + r.url.replace(BASE, ''));
    } else if (m.method === 'Network.loadingFailed') {
      problems.push('FAILED ' + m.params.errorText);
    }
  });

  await send('Runtime.enable');
  await send('Network.enable');
  await send('Page.enable');
  await send('Network.setCookie', { name: 'LSSESSID', value: sid, domain: '127.0.0.1', path: '/' });

  const failures = [];
  for (const p of PAGES) {
    exceptions = []; problems = [];
    if (p.noAuth) { await send('Network.deleteCookies', { name: 'LSSESSID', domain: '127.0.0.1' }); }
    await send('Page.navigate', { url: BASE + p.url });
    await sleep(2600);
    await send('Runtime.evaluate', {
      expression: 'window.__HITS__ = ' + JSON.stringify(
        Object.fromEntries(p.must.map((s) => [s, 0]))) + ';' +
        p.must.map((s) => `window.__HITS__[${JSON.stringify(s)}]=document.querySelectorAll(${JSON.stringify(s)}).length;`).join(''),
      returnByValue: true,
    });
    const ev = await send('Runtime.evaluate', { expression: DOM_PROBE, returnByValue: true });
    const dom = JSON.parse(ev.result.value);
    const missing = Object.keys(dom.hits).filter((k) => dom.hits[k] === 0);

    const bad = [];
    if (exceptions.length) bad.push('JS 异常: ' + exceptions[0]);
    if (problems.length) bad.push('失败请求: ' + problems.join(', '));
    if (missing.length) bad.push('缺失元素: ' + missing.join(', '));
    if (p.url.includes('index.html') && dom.loader !== 'none') bad.push('加载遮罩未隐藏');
    // 框架页：内容 iframe 必须撑满内容区（历史回归：tab 容器漏 layuimini-tab → iframe 塌成 150px，页面被压成窄带）
    if (p.layout === 'shell') {
      if (dom.tabTitleH < 30 || dom.tabTitleH > 50) bad.push('tab 标题条高度异常: ' + dom.tabTitleH + 'px');
      if (!dom.iframeH || dom.iframeH < 300) bad.push('内容 iframe 高度未撑满: ' + dom.iframeH + 'px');
      if (dom.bodyH && dom.iframeH < dom.bodyH - 60) bad.push('内容 iframe 与 .layui-body 高度不匹配: ' + dom.iframeH + '/' + dom.bodyH);
    }

    if (bad.length) { failures.push(p.name); console.log('FAIL ' + p.name.padEnd(12) + ' ' + p.url + '  → ' + bad.join(' | ')); }
    else { console.log('PASS ' + p.name.padEnd(12) + ' ' + p.url + (dom.faIcons.length ? '  图标: ' + dom.faIcons.slice(0, 3).join(' ') : '')); }
    if (p.noAuth) { await send('Network.setCookie', { name: 'LSSESSID', value: sid, domain: '127.0.0.1', path: '/' }); }
  }

  // 图标选择器专项
  await send('Page.navigate', { url: BASE + '/admin/page/menu.html' });
  await sleep(3200);
  const iconEv = await send('Runtime.evaluate', { expression: ICON_PROBE, returnByValue: true, awaitPromise: true });
  const icon = JSON.parse(iconEv.result.value);
  const iconBad = [];
  if (!icon.iconCount) iconBad.push('图标数据未加载');
  if (!/^fa /.test(String(icon.afterPick))) iconBad.push('点选未写回 #icon');
  if (icon.editBtn && !/^fa fa-/.test(String(icon.editIconValue))) iconBad.push('编辑回显值格式异常: ' + icon.editIconValue);
  if (icon.editBtn && icon.editPreviewClass !== icon.editIconValue) iconBad.push('预览 class 不一致: ' + icon.editPreviewClass);
  if (iconBad.length) { failures.push('图标选择器'); console.log('FAIL 图标选择器   ' + JSON.stringify(icon) + ' → ' + iconBad.join(' | ')); }
  else { console.log('PASS 图标选择器   图标数=' + icon.iconCount + ' 搜索=' + icon.searchFiltered + ' 编辑回显=' + icon.editIconValue + ' 预览=' + icon.editPreviewClass); }

  console.log('--------------------------------------------------');
  console.log(failures.length ? ('结果: 失败 — ' + failures.join(', ')) : '结果: 全部通过');

  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch (e) {}
  process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
