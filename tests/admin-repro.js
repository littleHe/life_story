/**
 * 回忆录后台 · 控制台/列表页「样式错乱」复现 + 几何探针（自包含，零第三方依赖）
 *
 * 用途：在贴近用户实际窗口宽度（默认 1020×760，恰好在 layui md 断点 992 之上）下，
 *      用真实 Chrome 打开 shell 与控制台页，截图并输出关键元素的盒模型与溢出清单，
 *      用于定位「元素错位 / 横向溢出 / 滚动条异常」类问题。
 *
 * 运行：node tests/admin-repro.js
 *      环境变量：CHROME_PATH / ADMIN_BASE / ADMIN_USER / ADMIN_PASS / REPRO_PORT / REPRO_W / REPRO_H
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.ADMIN_BASE || 'http://127.0.0.1:9411';
const USER = process.env.ADMIN_USER || 'admin';
const PASS = process.env.ADMIN_PASS || 'admin888';
const PORT = Number(process.env.REPRO_PORT) || 9355;
const W = Number(process.env.REPRO_W) || 1020;
const H = Number(process.env.REPRO_H) || 760;
const OUT = path.join(__dirname, '..', 'screenshots');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

async function login() {
  const res = await fetch(BASE + '/admin-api/login', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: USER, password: PASS }),
  });
  const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie')];
  const hit = (raw || []).map((c) => /LSSESSID=([^;]+)/.exec(c || '')).find(Boolean);
  if (!hit) throw new Error('登录未返回 LSSESSID');
  return hit[1];
}

// 几何探针：返回关键容器盒模型 + 横向溢出元素清单
const PROBE = `(() => {
  const box = (sel) => {
    const e = document.querySelector(sel);
    if (!e) return null;
    const b = e.getBoundingClientRect();
    return { x: Math.round(b.x), y: Math.round(b.y), w: Math.round(b.width), h: Math.round(b.height) };
  };
  const boxes = (sel) => [...document.querySelectorAll(sel)].map((e) => {
    const b = e.getBoundingClientRect();
    return { x: Math.round(b.x), y: Math.round(b.y), w: Math.round(b.width), h: Math.round(b.height) };
  });
  const out = {
    innerW: innerWidth, innerH: innerHeight,
    docScrollW: document.documentElement.scrollWidth,
    docScrollH: document.documentElement.scrollHeight,
    bodyW: Math.round(document.body.getBoundingClientRect().width),
    bodyH: Math.round(document.body.getBoundingClientRect().height),
    hasHScroll: document.documentElement.scrollWidth > innerWidth,
    layuiminiContainer: box('.layuimini-container'),
    layuiminiMain: box('.layuimini-main'),
    layuiBody: box('.layui-body'),
    tabContent: box('.layui-tab-content'),
    iframe: box('.layui-tab-item iframe'),
    md8: box('.layuimini-main > .layui-row > .layui-col-md8'),
    md4: box('.layuimini-main > .layui-row > .layui-col-md4'),
    cardsMd8: boxes('.layuimini-main .layui-col-md8 .layui-card'),
    cardsMd4: boxes('.layuimini-main .layui-col-md4 .layui-card'),
    welcomeModule: box('.welcome-module'),
    statPanelCount: document.querySelectorAll('#statPanels > div').length,
    statPanels: boxes('#statPanels > div'),
    quickCount: document.querySelectorAll('.layuimini-qiuck-module').length,
    quick: boxes('.layuimini-qiuck-module'),
    trend: box('#trend'),
    projTable: box('#projTable'),
    overflowX: [], overflowY: []
  };
  document.querySelectorAll('body *').forEach((e) => {
    if (e.tagName === 'IFRAME') return;
    const b = e.getBoundingClientRect();
    if (!b.width && !b.height) return;
    const cls = (e.className && e.className.toString ? e.className.toString() : '').slice(0, 48);
    const tag = e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (cls ? '.' + cls.split(' ').join('.') : '');
    if (b.right > innerWidth + 1 || b.left < -1) out.overflowX.push(tag + ' [' + Math.round(b.left) + ',' + Math.round(b.right) + '] vs ' + innerWidth);
    if (b.bottom > document.documentElement.scrollHeight + 1) out.overflowY.push(tag + ' [' + Math.round(b.top) + ',' + Math.round(b.bottom) + ']');
  });
  out.overflowX = out.overflowX.slice(0, 12);
  out.overflowY = out.overflowY.slice(0, 6);
  return JSON.stringify(out);
})()`;

async function evaluate(send, expr) {
  const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
  return r && r.result ? r.result.value : null;
}

(async () => {
  const sid = await login();
  fs.mkdirSync(OUT, { recursive: true });
  const profile = path.join(os.tmpdir(), 'ls_repro_profile_' + Date.now());

  const chrome = spawn(CHROME, [
    '--headless=new', '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + profile, '--no-first-run', '--no-default-browser-check',
    '--disable-gpu', 'about:blank',
  ], { stdio: 'ignore' });

  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch (e) { await sleep(250); } }
  if (!ready) { console.log('FAIL Chrome 未就绪'); chrome.kill(); process.exit(1); }

  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); } });

  await send('Runtime.enable');
  await send('Network.enable');
  await send('Page.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: 1, mobile: false });
  await send('Network.setCookie', { name: 'LSSESSID', value: sid, domain: '127.0.0.1', path: '/' });

  const targets2 = [
    ['repro-shell', '/admin/index.html', 4200],
    ['repro-console', '/admin/welcome.html', 3200],
  ];

  for (const [name, url, wait] of targets2) {
    await send('Page.navigate', { url: BASE + url });
    await sleep(wait);
    const shot = await send('Page.captureScreenshot', { format: 'png', fromSurface: true });
    fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(shot.data, 'base64'));
    console.log('--- ' + name + ' (' + W + 'x' + H + ') ---');
    console.log(await evaluate(send, PROBE));
  }

  // 在框架内实际打开一个列表页，验证「内容区撑满 + iframe 内页面正常渲染」
  await send('Page.navigate', { url: BASE + '/admin/index.html' });
  await sleep(4500);

  // 跨 iframe 行为实测：内容页（控制台）里的快捷入口点击后，父框架是否新开 tab
  console.log('快捷入口跨 iframe 实测: ' + await evaluate(send, `(function(){
    var before = document.querySelectorAll('.layuimini-tab .layui-tab-title li').length;
    var fr = document.querySelector('.layuimini-tab .layui-tab-item.layui-show iframe');
    if (!fr || !fr.contentDocument) return 'iframe 不可访问';
    var link = fr.contentDocument.querySelector('[layuimini-content-href="/admin/page/chapter.html"]');
    if (!link) return '内容页内未找到快捷入口链接';
    link.click();
    return 'clicked, before=' + before;
  })()`));
  await sleep(2000);
  console.log('  点击后 tab 数量: ' + await evaluate(send, `document.querySelectorAll('.layuimini-tab .layui-tab-title li').length`));

  // 侧边菜单点击（菜单项用的是 layuimini-href）→ 截图
  console.log('侧边菜单点击: ' + await evaluate(send, `(function(){
    var a = document.querySelector('.layuimini-menu-left a[layuimini-href*="chapter"]');
    if (!a) return 'not-found';
    a.click();
    return 'clicked:' + a.getAttribute('layuimini-href');
  })()`));
  await sleep(4000);
  const listShot = await send('Page.captureScreenshot', { format: 'png', fromSurface: true });
  fs.writeFileSync(path.join(OUT, 'repro-list-in-shell.png'), Buffer.from(listShot.data, 'base64'));
  console.log('--- repro-list-in-shell (' + W + 'x' + H + ') ---');
  console.log(await evaluate(send, PROBE));

  ws.close(); chrome.kill(); await sleep(300);
  try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) {}
  process.exit(0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
