/**
 * 第四轮浏览器 e2e：浏览器标题（后台配置）、录音分段文字+删除联动、首页真实数据。
 * 运行：node tests/front-e2e-round4.js
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = 9348;
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();
const b64 = (s) => Buffer.from(s).toString('base64');

;(async () => {
  const failures = [];
  const step = (ok, name, detail) => {
    if (ok) console.log('PASS ' + name + (detail ? '  ' + detail : ''));
    else { failures.push(name); console.log('FAIL ' + name + (detail ? '  → ' + detail : '')); }
  };

  // 后端预置：1 章 + 2 段录音 + 转写
  let token = '', uid = 0, pid = 0, cid = 0, seg2text = '';
  {
    const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
    token = lr.data.access_token; uid = lr.data.uid;
    const H = { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token };
    const api = async (p, m = 'GET', b) => (await fetch(API + '/api' + p, { method: m, headers: H, body: b ? JSON.stringify(b) : undefined })).json();
    pid = (await api('/projects', 'POST', { name: 'E2E_标题录音首页', real_name: '张丽丽', code: '66666688' })).data.id;
    cid = (await api(`/projects/${pid}/chapters`, 'PATCH', { action: 'add', title: '第一章' })).data.chapter_id;
    const texts = [];
    for (const s of ['一', '二']) {
      const d = (await api(`/chapters/${cid}/recording`, 'POST', { audio_base64: b64(s), audio_ext: 'webm', duration: '00:03' })).data;
      const a = (await api(`/chapters/${cid}/asr`, 'POST', { asset_id: d.asset_id })).data;
      texts.push(a.text);
    }
    seg2text = texts[1];
    step(!!pid && !!cid, '后端预置 1 章 2 段录音并转写', `pid=${pid}`);
  }

  const userDataDir = path.join(os.tmpdir(), 'ls_r4_e2e_' + Date.now());
  const chrome = spawn(CHROME, ['--headless=new', '--autoplay-policy=no-user-gesture-required', '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDataDir, '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=480,960', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  if (!ready) { console.log('FAIL Chrome 未就绪'); chrome.kill(); process.exit(1); }
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
  const evalJs = async (e) => (await send('Runtime.evaluate', { expression: e, returnByValue: true })).result.value;
  await send('Emulation.setDeviceMetricsOverride', { width: 480, height: 960, deviceScaleFactor: 2, mobile: true });

  // ===== 1. 浏览器标题：后台「站点名称」控制 =====
  await send('Page.navigate', { url: BASE + '/' });
  await sleep(4500); // 给工具包足够的覆盖窗口，验证守护校正
  let title = await evalJs('document.title');
  step(title === '人生回忆录' && title.indexOf('{{') < 0, '浏览器标题来自后台配置（非 {{appName}} 占位符）', JSON.stringify(title));

  // ===== 2. 详情页：分段文字 + 删除联动 =====
  await evalJs(`localStorage.setItem('ls_access_token', ${JSON.stringify(token)}); localStorage.setItem('ls_user_info', JSON.stringify({uid:${uid || 0},nickname:'测试账号'})); 'ok'`);
  await send('Page.navigate', { url: BASE + '/memoirs/' + pid });
  await sleep(3200);
  const probeSegs = `(function(){
    var rows = [].slice.call(document.querySelectorAll('button')).filter(function(b){return (b.textContent||'').trim()==='删除';});
    var ps = [].slice.call(document.querySelectorAll('p')).map(function(p){return p.textContent||'';});
    var tas = [].slice.call(document.querySelectorAll('textarea'));
    var ta = tas.find(function(t){ return (t.value||'').length > 0; }) || tas[0];
    return JSON.stringify({ delBtns: rows.length, hasNoAsrHint: ps.some(function(t){return t.indexOf('本段尚未转写')>=0;}), transcript: ta ? ta.value : '' });
  })()`;
  let d = JSON.parse(await evalJs(probeSegs));
  step(d.delBtns === 2, '两段录音各有「删除」按钮', 'delBtns=' + d.delBtns);
  step(d.transcript.length > 40, '口述原文为两段拼合', 'len=' + d.transcript.length);
  let shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  fs.writeFileSync(path.join(OUT, 'r4-segments.png'), Buffer.from(shot.data, 'base64'));

  // 删除第 1 段（绕过 confirm）
  await evalJs(`window.confirm = function(){ return true; }; (function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return (x.textContent||'').trim()==='删除';}); if(b) b.click(); return 'ok'; })()`);
  await sleep(2400);
  d = JSON.parse(await evalJs(probeSegs));
  step(d.delBtns === 1, '删除后只剩 1 段', 'delBtns=' + d.delBtns);
  step(d.transcript === seg2text, '口述原文重算为剩余分段文字', 'len=' + d.transcript.length);
  step(errs.length === 0, '详情页无 JS 异常', errs.join(' | '));
  shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  fs.writeFileSync(path.join(OUT, 'r4-after-delete.png'), Buffer.from(shot.data, 'base64'));

  // ===== 3. 首页真实数据 =====
  await send('Page.navigate', { url: BASE + '/' });
  await sleep(3400);
  const home = await evalJs(`(function(){
    var t = document.body.innerText;
    return JSON.stringify({
      hasReal: t.indexOf('E2E_标题录音首页') >= 0 || t.indexOf('张丽丽的回忆录') >= 0,
      hasFake: ['我的军旅岁月','我的厨房记忆','我的农场故事'].some(function(x){return t.indexOf(x)>=0;}),
      heading: t.indexOf('我的回忆录') >= 0 ? '我的回忆录' : (t.indexOf('示例回忆录') >= 0 ? '示例回忆录' : ''),
      emptyHint: t.indexOf('还没有回忆录') >= 0
    });
  })()`);
  const h = JSON.parse(home);
  step(h.heading === '我的回忆录', '首页区块为「我的回忆录」（真实数据）', h.heading);
  step(h.hasReal === true, '展示当前用户真实回忆录');
  step(h.hasFake === false, '不再展示假示例数据', h.hasFake ? '仍存在假数据' : 'ok');
  step(errs.length === 0, '首页无 JS 异常', errs.slice(-2).join(' | '));
  shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  fs.writeFileSync(path.join(OUT, 'r4-home.png'), Buffer.from(shot.data, 'base64'));

  console.log('--------------------------------------------------');
  console.log('SHOTS=' + OUT);
  console.log('CLEANUP pid=' + pid);
  console.log(failures.length ? ('结果: 失败 — ' + failures.join(', ')) : '结果: 全部通过');
  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch { /* ignore */ }
  process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
