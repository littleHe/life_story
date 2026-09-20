/**
 * 回忆录后台 · 页面截图工具（自包含，零第三方依赖）
 *
 * 用途：用真实 Chrome 登录后台并逐页截图，输出到 backend/screenshots/，
 *      供人工比对「参考后台（booking / EasyAdmin）」的视觉还原度。
 *      登录页在最后单独截取（此时会先清空会话 Cookie，避免被重定向到首页）。
 *
 * 前置：后端开发服务器已启动（backend 目录下 `php think run -p 9411 -H 127.0.0.1`），
 *      数据库已导入并 seed（admin / admin888）。脚本自己完成登录拿会话。
 *
 * 运行：node tests/admin-shots.js
 *      可用环境变量覆盖：CHROME_PATH / ADMIN_BASE / ADMIN_USER / ADMIN_PASS
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.ADMIN_BASE || 'http://127.0.0.1:9411';
const USER = process.env.ADMIN_USER || 'admin';
const PASS = process.env.ADMIN_PASS || 'admin888';
const PORT = Number(process.env.SHOT_PORT) || 9341;
const OUT = path.join(__dirname, '..', 'screenshots');

const PAGES = [
  ['admin-shell', '/admin/index.html'],
  ['admin-chapter', '/admin/page/chapter.html'],
  ['admin-user', '/admin/page/user.html'],
  ['admin-apilog', '/admin/page/apilog.html'],
  ['admin-code', '/admin/page/code.html'],
  ['admin-codelog', '/admin/page/codelog.html'],
  ['admin-slide', '/admin/page/slide.html'],
  ['admin-adminuser', '/admin/page/adminuser.html'],
  ['admin-menu-list', '/admin/page/menu.html'],
  ['admin-console', '/admin/welcome.html'],
];

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

(async () => {
  const sid = await login();
  fs.mkdirSync(OUT, { recursive: true });
  const profile = path.join(os.tmpdir(), 'ls_shots_profile_' + Date.now());

  const chrome = spawn(CHROME, [
    '--headless=new', '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + profile, '--no-first-run', '--no-default-browser-check',
    // 不隐藏滚动条：截图需与用户在真实浏览器里看到的一致
    '--disable-gpu', 'about:blank',
  ], { stdio: 'ignore' });

  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch (e) { await sleep(250); } }
  if (!ready) { console.log('FAIL Chrome 未就绪，请检查 CHROME_PATH'); chrome.kill(); process.exit(1); }

  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); } });

  await send('Runtime.enable');
  await send('Network.enable');
  await send('Page.enable');
  // 桌面视口，保证 grid 布局按 md 断点展开
  await send('Emulation.setDeviceMetricsOverride', { width: 1600, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Network.setCookie', { name: 'LSSESSID', value: sid, domain: '127.0.0.1', path: '/' });

  for (const [name, url] of PAGES) {
    await send('Page.navigate', { url: BASE + url });
    await sleep(3000);
    const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, fromSurface: true });
    fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(shot.data, 'base64'));
    console.log('shot ' + name);
  }

  // 登录页：清空会话 Cookie 后截图（否则会 302 到首页）
  await send('Network.clearBrowserCookies');
  await send('Page.navigate', { url: BASE + '/admin/login.html' });
  await sleep(2200);
  const loginShot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, fromSurface: true });
  fs.writeFileSync(path.join(OUT, 'admin-login.png'), Buffer.from(loginShot.data, 'base64'));
  console.log('shot admin-login');

  console.log('OUT=' + OUT);
  ws.close(); chrome.kill(); await sleep(300);
  try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) {}
  process.exit(0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
