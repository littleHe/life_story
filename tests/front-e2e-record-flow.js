/**
 * 录音全链路 e2e（Chrome 虚拟麦克风）：新增章节 → 录音 → 停止 →
 * 段落立即出现并可播放、时长正常显示、转写自动完成、章节自动保存（无需点保存）。
 * 运行：node tests/front-e2e-record-flow.js
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = 9354;
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

;(async () => {
  const failures = [];
  const step = (ok, name, detail) => {
    if (ok) console.log('PASS ' + name + (detail ? '  ' + detail : ''));
    else { failures.push(name); console.log('FAIL ' + name + (detail ? '  → ' + detail : '')); }
  };

  let token = '', uid = 0, pid = 0;
  {
    const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
    token = lr.data.access_token; uid = lr.data.uid;
    const H = { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token };
    pid = (await (await fetch(API + '/api/projects', { method: 'POST', headers: H, body: JSON.stringify({ name: 'E2E_录音自动保存', code: '66666688' }) })).json()).data.id;
    step(!!pid, '后端预置项目', 'pid=' + pid);
  }

  const userDataDir = path.join(os.tmpdir(), 'ls_rec_e2e_' + Date.now());
  const chrome = spawn(CHROME, [
    '--headless=new', '--autoplay-policy=no-user-gesture-required',
    '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream',
    '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDataDir,
    '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=480,960', 'about:blank',
  ], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  if (!ready) { console.log('FAIL Chrome 未就绪'); chrome.kill(); process.exit(1); }
  function getJSON(u) { return fetch(u).then((r) => r.json()); }
  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map(); const errs = [];
  const send = (m, p = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method: m, params: p })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') errs.push(((m.params.exceptionDetails.exception || {}).description || m.params.exceptionDetails.text || '').split('\n')[0]);
  });
  await send('Runtime.enable'); await send('Page.enable');
  const evalJs = async (e) => (await send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true })).result?.value;
  await send('Emulation.setDeviceMetricsOverride', { width: 480, height: 960, deviceScaleFactor: 2, mobile: true });

  await send('Page.navigate', { url: BASE + '/' });
  await sleep(1800);
  await evalJs(`localStorage.setItem('ls_access_token', ${JSON.stringify(token)}); localStorage.setItem('ls_user_info', JSON.stringify({uid:${uid || 0},nickname:'测试账号'})); 'ok'`);
  await send('Page.navigate', { url: BASE + '/memoirs/' + pid });
  await sleep(3000);

  // 1) 新增章节
  await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('新增章节')>=0;}); if(b) b.click(); return 'ok'; })()`);
  await sleep(800);
  await evalJs(`(function(){
    var inp = document.querySelector('input[placeholder^="例如"]') || document.querySelector('input[placeholder*="第"]');
    if (!inp) return 'noinput';
    var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(inp, '第一章 童年');
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    return 'filled';
  })()`);
  await sleep(300);
  await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('添加章节')>=0 || x.textContent.trim()==='添加';}); if(b) b.click(); return 'ok'; })()`);
  await sleep(2400);

  // 2) 点「录音」录 3.5 秒，再点「停止」
  const clickRec = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.trim()==='录音';}); if(b){b.click(); return 'clicked';} return 'notfound'; })()`);
  await sleep(3500);
  const clickStop = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.trim()==='停止';}); if(b){b.click(); return 'clicked';} return 'notfound'; })()`);
  step(clickRec === 'clicked' && clickStop === 'clicked', '开始录音 3.5 秒后点停止', 'rec=' + clickRec + ' stop=' + clickStop);
  await sleep(4500); // 等上传 + 转写 + 自动保存

  // 3) 段落立即出现、可播放、时长正常
  const probe = await evalJs(`(async function(){
    var audios = [].slice.call(document.querySelectorAll('audio'));
    var out = { audioCount: audios.length };
    var el = audios[0];
    out.src = el ? el.getAttribute('src') : '';
    // 时长展示（录音行右侧的 tabular-nums 文本）
    var spans = [].slice.call(document.querySelectorAll('span.tabular-nums')).map(function(s){return s.textContent.trim();});
    out.durTexts = spans;
    // 段落转写文字
    var ps = [].slice.call(document.querySelectorAll('p')).map(function(p){return p.textContent||'';});
    out.hasAsrText = ps.some(function(t){ return t.indexOf('关于') >= 0 || t.length > 8; });
    if (el) {
      try {
        await el.play();
        await new Promise(function(r){ setTimeout(r, 1200); });
        out.playing = !el.paused;
        out.currentTime = Number(el.currentTime.toFixed(2));
        el.pause();
      } catch(e) { out.playErr = String(e && e.message || e); }
    }
    return JSON.stringify(out);
  })()`);
  const p = JSON.parse(probe || '{}');
  step(p.audioCount >= 1, '停止后段落立即出现（audio 元素就绪）', 'count=' + p.audioCount);
  step(!!p.src && p.src.indexOf('/uploads/audio/') === 0, '段落指向后端真实音频地址', p.src);
  step(p.playing === true && p.currentTime > 0, '点击即可播放（currentTime 前进）', 'playing=' + p.playing + ' t=' + p.currentTime);
  step((p.durTexts || []).some((t) => t !== '00:00' && t !== '--:--'), '录音时长正常显示（非 00:00）', JSON.stringify(p.durTexts));
  step(p.hasAsrText === true, '转写自动完成（段落下方有文字）');
  let shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  fs.writeFileSync(path.join(OUT, 'rec-flow.png'), Buffer.from(shot.data, 'base64'));

  // 4) 刷新页面 → 章节自动保存的内容仍在（未点过「保存章节」）
  await send('Page.navigate', { url: BASE + '/memoirs/' + pid });
  await sleep(3000);
  const after = await evalJs(`(function(){
    var tas = [].slice.call(document.querySelectorAll('textarea'));
    var ta = tas.find(function(t){ return (t.value||'').length > 0; }) || tas[0];
    var audios = document.querySelectorAll('audio').length;
    return JSON.stringify({ transcriptLen: ta ? ta.value.length : 0, audios: audios });
  })()`);
  const a = JSON.parse(after || '{}');
  step(a.audios >= 1 && a.transcriptLen > 20, '刷新后录音与口述原文仍在（章节已自动保存）', 'audios=' + a.audios + ' len=' + a.transcriptLen);

  // 5) 后端核对：未点保存按钮，但章节已落库
  {
    const r = await (await fetch(API + '/api/projects/' + pid + '/chapters', { headers: { Authorization: 'Bearer ' + token } })).json();
    const ch = (r.data || [])[0] || {};
    step((ch.recordings || []).length === 1, '后端章节已含 1 段录音（自动保存）', 'recordings=' + (ch.recordings || []).length);
    step((ch.transcript || '').length > 20, '后端口述原文已落库', 'len=' + (ch.transcript || '').length);
    step((ch.title || '').indexOf('童年') >= 0, '章节标题已自动保存', ch.title);
  }
  step(errs.length === 0, '全程无 JS 异常', errs.slice(-2).join(' | '));

  console.log('--------------------------------------------------');
  console.log('SHOTS=' + OUT);
  console.log('CLEANUP pid=' + pid);
  console.log(failures.length ? ('结果: 失败 — ' + failures.join(', ')) : '结果: 全部通过');
  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch { /* ignore */ }
  process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
