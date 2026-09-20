/**
 * 亲友免登录「访谈」录制页 · 端到端验证（自包含，无第三方依赖）
 *
 * 覆盖：
 *  - 公开页：免登录凭 ?token= 直接打开，展示传主信息与章节列表
 *  - 录音闭环：点「录制口述」→ 录一段 → 「停止录音」→ 自动上传 + 转写，章节出现播放器与口述原文
 *  - 无效 token：展示「访谈链接无法打开」兜底
 *
 * 前置：前端 vite（8001）与后端（9411）已启动。
 * 运行：node tests/front-e2e-interview.js
 * 退出码：0 = 全部通过；1 = 存在失败项
 */
const { spawn } = require('child_process');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = 9341;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

let collect = false;
let pageErrors = [];
function resetPhase() { pageErrors = []; }
function track(m) {
  if (!collect) return;
  if (m.method === 'Runtime.exceptionThrown') {
    const d = m.params.exceptionDetails;
    pageErrors.push(((d.exception && (d.exception.description || d.exception.value)) || d.text || '').split('\n')[0]);
  }
}

;(async () => {
  const failures = [];
  const step = (ok, name, detail) => {
    if (ok) console.log('PASS ' + name + (detail ? '  ' + detail : ''));
    else { failures.push(name); console.log('FAIL ' + name + (detail ? '  → ' + detail : '')); }
  };

  // ===== 后端预置：登录 + 建项目/章节 + 生成访谈 token =====
  let token = '', pid = 0, cid = 0, iToken = '';
  try {
    const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
    token = lr?.data?.access_token;
    const H = () => ({ 'Content-Type': 'application/json', Authorization: 'Bearer ' + token });
    const api = async (p, opt = {}) => {
      const res = await fetch(API + '/api' + p, { method: opt.method || 'GET', headers: H(), body: opt.body ? JSON.stringify(opt.body) : undefined });
      return { status: res.status, json: await res.json() };
    };
    const pr = await api('/projects', { method: 'POST', body: { name: 'E2E_访谈页_' + Date.now(), code: '66666688' } });
    pid = pr.json?.data?.id;
    const cr = await api(`/projects/${pid}/chapters`, { method: 'PATCH', body: { action: 'add', title: '访谈测试章节' } });
    cid = cr.json?.data?.chapter_id;
    const gr = await api(`/projects/${pid}/interview-token`, { method: 'POST', body: {} });
    iToken = gr.json?.data?.interview_token;
    step(!!token && !!pid && !!cid && !!iToken, '后端预置 项目/章节/访谈token', `pid=${pid} cid=${cid} token=${iToken ? 'ok' : 'missing'}`);
  } catch (e) {
    step(false, '后端预置', e.message);
  }

  if (!iToken) { console.log('\nFAIL 缺少访谈 token，无法继续'); process.exit(1); }

  const userDataDir = path.join(os.tmpdir(), 'ls_interview_e2e_' + Date.now());
  const chrome = spawn(CHROME, [
    '--headless=new', '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + userDataDir, '--no-first-run', '--no-default-browser-check',
    '--disable-gpu', '--disable-extensions', '--hide-scrollbars',
    '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream',
    'about:blank',
  ], { stdio: 'ignore' });

  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) {
    try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); }
  }
  if (!ready) { console.log('FAIL Chrome 未就绪'); chrome.kill(); process.exit(1); }

  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    track(m);
  });
  await send('Runtime.enable'); await send('Network.enable'); await send('Page.enable');
  const evalJs = async (expr) => (await send('Runtime.evaluate', { expression: expr, returnByValue: true })).result.value;
  const clickByText = async (text) => (await send('Runtime.evaluate', {
    expression: `(function(){var b=[].slice.call(document.querySelectorAll('button'));for(var i=0;i<b.length;i++){if(b[i].textContent.indexOf(${JSON.stringify(text)})>=0){b[i].click();return true;}}return false;})()`,
    returnByValue: true,
  })).result.value;
  const waitFor = async (expr, ms = 12000) => {
    const t0 = Date.now();
    while (Date.now() - t0 < ms) {
      if (await evalJs(expr)) return true;
      await sleep(300);
    }
    return false;
  };

  // ===== 用例 1：有效 token 公开页加载 =====
  collect = true; resetPhase();
  await send('Page.navigate', { url: `${BASE}/memoirs/${pid}/interview?token=${iToken}` });
  await sleep(2500);
  const nameShown = await waitFor(`(document.body.innerText||'').indexOf('为 TA 口述故事')>=0`, 8000);
  step(nameShown, '公开页加载（免登录）展示传主邀请语');
  const chapterShown = await evalJs(`(document.body.innerText||'').indexOf('访谈测试章节')>=0`);
  step(chapterShown, '公开页展示章节列表');

  // ===== 用例 2：录音闭环 =====
  const recBtn = await clickByText('录制口述');
  step(recBtn === true, '点击「录制口述」开始录音');
  await sleep(1600);
  const stopBtn = await clickByText('停止录音');
  step(stopBtn === true, '点击「停止录音」结束录音');
  // 等待自动上传 + 转写：章节出现 <audio> 播放器，且口述原文非空
  const audioReady = await waitFor(`!!document.querySelector('audio[src]')`, 15000);
  step(audioReady, '录音自动上传并生成可播放音频');
  const transcriptReady = await waitFor(`(function(){var ps=[].slice.call(document.querySelectorAll('p'));for(var i=0;i<ps.length;i++){if((ps[i].textContent||'').length>4 && ps[i].textContent.indexOf('口述原文')<0) return true;}return false;})()`, 15000);
  step(transcriptReady, '转写文字回流到口述原文');
  step(pageErrors.length === 0, '录制过程无 JS 异常', pageErrors.slice(0, 3).join(' | '));

  // ===== 用例 3：无效 token 兜底 =====
  collect = true; resetPhase();
  await send('Page.navigate', { url: `${BASE}/memoirs/${pid}/interview?token=deadbeefdeadbeefdeadbeefdeadbeef` });
  await sleep(2500);
  const errShown = await waitFor(`(document.body.innerText||'').indexOf('访谈链接无法打开')>=0`, 8000);
  step(errShown, '无效 token 展示兜底提示');

  // ===== 清理 =====
  chrome.kill();
  if (pid) {
    try {
      await new Promise((res) => {
        const cleanup = spawn('php', [path.join(__dirname, '_cleanup_test_data.php'), String(pid)], { stdio: 'ignore' });
        cleanup.on('exit', res);
      });
      console.log('已清理测试项目 pid=' + pid);
    } catch (e) { console.log('清理失败（手动清理 pid=' + pid + '）：' + e.message); }
  }

  console.log('\n' + (failures.length ? `存在失败项（${failures.length}）：` + failures.join('；') : 'ALL INTERVIEW E2E PASSED'));
  process.exit(failures.length ? 1 : 0);
})();
