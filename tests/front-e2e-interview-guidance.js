/**
 * 亲友访谈页「口述引导」e2e（Chrome 虚拟麦克风）
 *
 * 交互形态（2026-09-18 改版）：平时收成屏幕右侧的小图标（🎙️ + 「讲述引导」），
 *   点一下才展开引导气泡，点 ✕ 又缩回图标 —— 不再常驻占屏、不打扰正在讲述的老人。
 *
 * 覆盖：图标常驻可点、点击展开（seed/continue 两种标题）、请求真实发出、换一个提示可刷新、
 *       ✕ 缩回且**图标仍在**、录音中不自动弹（不打扰）、录音浮层标出章节名、其他章节按钮被锁、
 *       「放弃本段」可丢弃、接口异常时本地兜底文案仍可看到。
 *
 * 运行：node tests/front-e2e-interview-guidance.js
 * 注意：最后一轮会真实保存一段测试录音（与本用例历史行为一致），项目数据由 _cleanup_test_data.php 回收。
 */
const { spawn, spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = Number(process.env.CDP_PORT || 9373);
const MYSQL = process.env.MYSQL_BIN || 'D:\\phpstudy_pro\\Extensions\\MySQL8.0.12\\bin\\mysql.exe';
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// 选择器（与 ComicBubble / InterviewPage 的实现约定一致）
const FAB = `document.querySelector('button[aria-label^="查看讲述引导"]')`;
const BUBBLE = `document.querySelector('div[role="status"]')`;
const BY_TEXT = (t) =>
  `[].slice.call(document.querySelectorAll('button')).find(function(x){return (x.textContent||'').indexOf('${t}')>=0;})`;

;(async () => {
  const failures = [];
  const step = (ok, name, detail) => {
    if (ok) console.log('PASS ' + name + (detail ? '  ' + detail : ''));
    else { failures.push(name); console.log('FAIL ' + name + (detail ? '  → ' + detail : '')); }
  };

  // 预置：登录 → 建项目 → 拿 interview_token
  const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
  const token = lr.data.access_token;
  const H = { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token };
  const pid = (await (await fetch(API + '/api/projects', { method: 'POST', headers: H, body: JSON.stringify({ name: 'E2E_访谈引导', real_name: '引导测试', gender: 'male', code: '66666688' }) })).json()).data.id;
  const itk = (await (await fetch(API + '/api/projects/' + pid + '/interview-token', { method: 'POST', headers: H, body: '{}' })).json()).data.interview_token;
  step(!!pid && !!itk, '预置项目 + 访谈 token', `pid=${pid}`);

  const userDataDir = path.join(os.tmpdir(), 'ls_itv_guide_' + Date.now());
  const chrome = spawn(CHROME, [
    '--headless=new', '--autoplay-policy=no-user-gesture-required',
    '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream',
    '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDataDir,
    '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=480,960', 'about:blank',
  ], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 60 && !ready; i++) {
    try {
      // 必须拿到 DevTools 的 JSON（含 webSocketDebuggerUrl）：别的服务占着该端口时
      // 也可能返回 404/200 之类的响应，光看「有响应」会误判成就绪。
      const r = await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json();
      if (r && r.webSocketDebuggerUrl) ready = r;
    } catch { /* 未就绪，继续等 */ }
    if (!ready) await sleep(250);
  }
  if (!ready) {
    console.log(`FAIL Chrome 未就绪（端口 ${PORT}）`);
    console.log('  提示：该端口可能被别的服务占用，换一个即可 → CDP_PORT=9383 node tests/front-e2e-interview-guidance.js');
    chrome.kill(); process.exit(1);
  }

  const targets = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map(); const errs = []; const guideReqs = [];
  const send = (m, p = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method: m, params: p })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') errs.push(((m.params.exceptionDetails.exception || {}).description || m.params.exceptionDetails.text || '').split('\n')[0]);
    if (m.method === 'Network.requestWillBeSent' && /guidance/.test(m.params.request.url)) {
      guideReqs.push({ url: m.params.request.url, method: m.params.request.method });
    }
  });
  await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');
  const evalJs = async (e) => (await send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true })).result?.value;
  await send('Emulation.setDeviceMetricsOverride', { width: 480, height: 960, deviceScaleFactor: 2, mobile: true });

  const snap = () => evalJs(`(function(){
    var fab = ${FAB}, b = ${BUBBLE};
    return JSON.stringify({
      fab: !!fab,
      fabLabel: fab ? (fab.getAttribute('aria-label')||'') : '',
      fabHint: !!fab && fab.parentElement && (fab.parentElement.innerText||'').indexOf('讲述引导')>=0,
      bubble: !!b,
      text: b ? (b.innerText||'').replace(/\\s+/g,' ').slice(0,180) : ''
    });
  })()`);
  const clickFab = () => evalJs(`(function(){ var f=${FAB}; if(f){ f.click(); return 'clicked'; } return 'notfound'; })()`);
  const click = (t) => evalJs(`(function(){ var b=${BY_TEXT(t)}; if(b){ b.click(); return 'clicked'; } return 'notfound'; })()`);
  const shot = async (name) => {
    const s = await send('Page.captureScreenshot', { format: 'png' });
    if (s && s.data) fs.writeFileSync(path.join(OUT, name), Buffer.from(s.data, 'base64'));
  };

  // 访谈页免登录，直接打开
  await send('Page.navigate', { url: `${BASE}/memoirs/${pid}/interview?token=${itk}` });
  await sleep(3200);
  step(!!(await evalJs(`document.body.innerText.indexOf('选择章节')>=0`)), '访谈页加载成功', 'token=' + itk.slice(0, 8) + '…');

  // ① 初始：只有小图标，气泡不占屏
  const s0 = JSON.parse(await snap());
  step(s0.fab, '引导收成右侧小图标（常驻可点）', s0.fabLabel);
  step(s0.fabHint, '图标带「讲述引导」文字提示（一眼知道用途）');
  step(!s0.bubble, '默认不展开气泡（不占屏、不打扰）');
  step(guideReqs.length > 0, '切章后已预取引导（点开即看）', 'count=' + guideReqs.length);

  // ② 点图标 → 展开气泡（seed 模式）
  const c1 = await clickFab();
  await sleep(700);
  const s1 = JSON.parse(await snap());
  step(c1 === 'clicked' && s1.bubble, '点击图标展开引导气泡');
  step(/录制小建议/.test(s1.text), '无录音时标题为「录制小建议」(seed 模式)', s1.text.slice(0, 30));
  step(s1.text.length > 20 && !/正在想想/.test(s1.text), '引导文案已返回（非 loading 空态）', 'len=' + s1.text.length);
  await shot('interview-guide-seed.png');

  // ③ 「换一个提示」
  const clickRefresh = await click('换一个提示');
  await sleep(2200);
  const after = await evalJs(`(function(){ var n=${BUBBLE}; return n?(n.innerText||'').replace(/\\s+/g,' ').slice(0,180):''; })()`);
  step(clickRefresh === 'clicked', '点击「换一个提示」', clickRefresh);
  step(guideReqs.length >= 2, '换提示触发了第二次 guidance 请求', 'count=' + guideReqs.length);
  step(!!after && after.length > 20, '换提示后气泡仍有文案', (after || '').slice(0, 40));

  // ④ ✕ 缩回：气泡消失但**图标仍在**
  const clickClose = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return (x.getAttribute('aria-label')||'')==='收起引导提示';}); if(b){b.click(); return 'clicked';} return 'notfound'; })()`);
  await sleep(600);
  const s2 = JSON.parse(await snap());
  step(clickClose === 'clicked' && !s2.bubble, '点 ✕ 气泡缩回');
  step(s2.fab, '缩回后小图标仍在（可再次点开）');

  // ⑤ 录音中：不自动弹气泡；浮层大字标出章节名；其他章节按钮被锁
  const clickRec = await click('录制口述');
  await sleep(2200);
  const s3 = JSON.parse(await snap());
  step(clickRec === 'clicked' && (await evalJs(`document.body.innerText.indexOf('停止录音')>=0`)), '点击「录制口述」进入录音态');
  step(!s3.bubble, '录音中不自动弹气泡（不打扰讲述）');
  step(s3.fab, '录音中图标仍可见（想用时随时点开）');
  const pageTxt = await evalJs(`(document.body.innerText||'').replace(/\\s+/g,' ')`);
  step(/正在为《.+》录音/.test(String(pageTxt)), '录音浮层大字标出「正在为《章节名》录音」', (String(pageTxt).match(/正在为《.+?》录音/) || [''])[0]);
  const lk = JSON.parse(await evalJs(`(function(){
    var bs = [].slice.call(document.querySelectorAll('button')).filter(function(x){ return (x.textContent||'').indexOf('录音中')>=0; });
    return JSON.stringify({ n: bs.length, allDisabled: bs.every(function(b){ return b.disabled; }) });
  })()`));
  step(lk.n > 0 && lk.allDisabled, '录音中其他章节按钮被禁用并显示「录音中…」', `n=${lk.n}`);
  await shot('interview-guide-recording.png');

  // ⑥ 「放弃本段」：丢弃录音，不上传不入库
  const cDiscard = await click('放弃本段');
  await sleep(1400);
  const s4 = JSON.parse(await snap());
  step(cDiscard === 'clicked', '点「不是这一章？放弃本段」可丢弃本段');
  step(s4.fab, '停止后引导图标仍保留');
  step(!!(await evalJs(`document.body.innerText.indexOf('录制口述')>=0`)), '放弃后按钮回到「录制口述」');
  const segCount = await evalJs(`document.querySelectorAll('audio').length`);
  step(Number(segCount) === 0, '放弃的录音没有留下播放条目', 'audioEls=' + segCount);

  // ⑦ 再录一段并「完成」→ 保存 + 转写
  await click('录制口述');
  await sleep(2600);
  await click('完成');
  await sleep(7000); // 等上传 + 转写（异步）
  await clickFab();
  await sleep(600);
  const s5 = JSON.parse(await snap());
  step(!!s5.bubble, '保存后仍可点开引导气泡');

  // 诊断：假麦克风是合成音，腾讯 ASR 未必出字 —— 打印本章文字长度，便于判断是哪一种情况
  const chs0 = (await (await fetch(API + '/api/projects/' + pid + '/chapters', { headers: H })).json()).data;
  const firstCh = chs0[0] || {};
  console.log(`    [诊断] 首章录音段=${(firstCh.recordings || []).length} 文字长度=${(firstCh.transcript || '').length}`);

  // ⑧ continue 模式：直接给本章注入一段「口述原文」（模拟转写完成），再刷新页面验证。
  //    不依赖 ASR 对合成音出字，而 continue 的判据本就是「本章有没有文字」。
  const firstCid = firstCh.id;
  if (firstCid) {
    // 注意：访谈接口 GET /api/interview/:token 的口述原文是**由 RECORDING 段的文字拼合**的
    // （InterviewController 里走 RecordingService::segments），并不读 TRANSCRIPT 资源 ——
    // 用户态接口 /api/projects/:id/chapters 才读 TRANSCRIPT。注入要落在 RECORDING 段上。
    const sql = `UPDATE ls_chapter_asset SET meta = JSON_SET(meta, '$.text', '我小时候住在村东头的老屋里，天不亮就得起来烧火做饭。') WHERE chapter_id=${firstCid} AND asset_type='RECORDING';`;
    const rr = spawnSync(MYSQL, ['-h127.0.0.1', '-uroot', '-proot', 'life_story', '-e', sql], { encoding: 'utf8' });
    const err = (rr.stderr || '').split('\n').filter((l) => l && !/Using a password/i.test(l)).join(' ').trim();
    const iv = (await (await fetch(`${API}/api/interview/${itk}`)).json()).data;
    const t = (iv.chapters.find((c) => c.id === firstCid) || {}).transcript || '';
    console.log(`    [诊断] 注入 cid=${firstCid} mysql退出码=${rr.status} 访谈接口读到文字长度=${t.length}${err ? ' mysqlErr=' + err : ''}`);
  }
  await send('Page.navigate', { url: `${BASE}/memoirs/${pid}/interview?token=${itk}` });
  await sleep(3200);
  await clickFab();
  await sleep(800);
  const s6 = JSON.parse(await snap());
  step(/接着聊聊/.test(s6.text), '本章已有口述原文 → 标题变为「接着聊聊」(continue 模式)', s6.text.slice(0, 34));
  await shot('interview-guide-continue.png');

  // ⑨ 兜底：拦掉 guidance 接口 → 点开图标仍应看到本地文案（不空白、不卡 loading）
  await evalJs(`(function(){ var of=window.fetch; window.fetch=function(u,o){ var s=String((u&&u.url)||u); if(s.indexOf('/guidance')>=0){ return Promise.reject(new Error('blocked by test')); } return of.apply(this,arguments); }; })(); 'blocked'`);
  await click('换一个提示');
  await sleep(1600);
  const s7 = JSON.parse(await snap());
  step(!!s7.bubble && s7.text.length > 10 && !/正在想想/.test(s7.text), '接口异常时本地兜底文案仍可看到', String(s7.text).slice(0, 50));

  step(errs.length === 0, '无 JS 运行时异常', errs.slice(0, 3).join(' | '));

  console.log('\n' + (failures.length ? 'FAILED: ' + failures.length + ' → ' + failures.join('; ') : 'ALL PASS'));
  ws.close(); chrome.kill();

  // 清理：后端没有 DELETE /api/projects/:id 路由，复用项目自带的 PHP 清理脚本
  const PHP = process.env.PHP_BIN || 'D:\\phpstudy_pro\\Extensions\\php\\php8.2.9nts\\php.exe';
  try {
    const r = spawnSync(PHP, [path.join(__dirname, '_cleanup_test_data.php'), String(pid)], { encoding: 'utf8' });
    console.log(((r.stdout || '').trim() || 'cleanup pid=' + pid).split('\n').filter(Boolean).pop());
  } catch {
    console.log('cleanup 需手动执行：php tests/_cleanup_test_data.php ' + pid);
  }
  process.exit(failures.length ? 1 : 0);
})();
