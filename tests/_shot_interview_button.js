/**
 * 访谈页「录音按钮」视觉验收截图（自包含）
 *
 * 作用：建临时项目（自动播种 6 个系统默认章）→ 生成访谈 token →
 *       无头 Chrome 以手机尺寸打开访谈页 → 截「开始录音」态与「录音中」态。
 *
 * 前置：前端 vite(8001) + 后端(9411) 已启动。
 * 运行：node tests/_shot_interview_button.js
 * 产物：backend/tests/_shots/interview-idle.png / interview-recording.png
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PHP = process.env.PHP_BIN || 'D:/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe';
const PORT = Number(process.env.CDP_PORT || 9355);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

(async () => {
  const outDir = path.join(__dirname, '_shots');
  try { fs.mkdirSync(outDir, { recursive: true }); } catch (e) { /* sandbox EPERM 不致命 */ }

  // ===== 预置数据 =====
  let pid = 0, iToken = '';
  try {
    const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
    const token = lr?.data?.access_token;
    const H = { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token };
    const pr = await (await fetch(API + '/api/projects', { method: 'POST', headers: H, body: JSON.stringify({ name: 'SHOT_按钮验收', code: '66666688' }) })).json();
    pid = pr?.data?.id;
    const gr = await (await fetch(`${API}/api/projects/${pid}/interview-token`, { method: 'POST', headers: H, body: '{}' })).json();
    iToken = gr?.data?.interview_token;
  } catch (e) { console.log('预置失败：' + e.message); }
  if (!iToken) { console.log('缺少访谈 token，退出'); process.exit(1); }
  console.log('项目 pid=' + pid + ' token=' + iToken);

  const userDataDir = path.join(os.tmpdir(), 'ls_shot_btn_' + Date.now());
  const chrome = spawn(CHROME, [
    '--headless=new', '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + userDataDir, '--no-first-run', '--no-default-browser-check',
    '--disable-gpu', '--disable-extensions', '--hide-scrollbars',
    '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream',
    'about:blank',
  ], { stdio: 'ignore' });

  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  if (!ready) { console.log('Chrome 未就绪'); chrome.kill(); process.exit(1); }

  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); } });
  await send('Runtime.enable'); await send('Page.enable');
  const evalJs = async (expr) => (await send('Runtime.evaluate', { expression: expr, returnByValue: true })).result.value;
  const clickByText = async (text) => evalJs(`(function(){var b=[].slice.call(document.querySelectorAll('button'));for(var i=0;i<b.length;i++){if((b[i].textContent||'').indexOf(${JSON.stringify(text)})>=0){b[i].click();return true;}}return false;})()`);
  const waitFor = async (expr, ms = 12000) => { const t0 = Date.now(); while (Date.now() - t0 < ms) { if (await evalJs(expr)) return true; await sleep(250); } return false; };

  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 2, mobile: true });
  await send('Page.navigate', { url: `${BASE}/memoirs/${pid}/interview?token=${iToken}` });
  await sleep(3000);
  await waitFor(`(document.body.innerText||'').indexOf('开始录音')>=0`, 10000);

  const shoot = async (name) => {
    const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    if (!r?.data) { console.log('截图无数据：' + name); return; }
    try { fs.writeFileSync(path.join(outDir, name), Buffer.from(r.data, 'base64')); console.log('已保存 ' + name); }
    catch (e) { console.log('写盘失败 ' + name + '：' + e.message); }
  };

  // 1) 初始「开始录音」态
  await shoot('interview-idle.png');

  // 2) 「录音中」态（点开始录音即可；假麦克风无需权限）
  const ok = await clickByText('开始录音');
  console.log('点击开始录音：' + ok);
  await sleep(1800);
  await shoot('interview-recording.png');

  chrome.kill();
  if (pid) {
    try { await new Promise((res) => { const c = spawn(PHP, [path.join(__dirname, '_cleanup_test_data.php'), String(pid)], { stdio: 'ignore' }); c.on('exit', res); }); console.log('已清理 pid=' + pid); } catch (e) { console.log('清理失败 pid=' + pid); }
  }
  process.exit(0);
})();
