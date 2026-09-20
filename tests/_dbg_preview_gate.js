// 预览页「进门蒙层」外观 + 行为验证：截图 + 点击后是否真的开始朗读/配乐/自动翻书
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = Number(process.env.CDP_PORT || 9358);
const URL_ = process.env.PV_URL || 'http://127.0.0.1:9411/preview/32e9a34e6c99d9c474ada515';
const OUT = process.env.SHOT_DIR || os.tmpdir();
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

;(async () => {
  const ud = path.join(os.tmpdir(), 'ls_gate_profile');
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + ud, '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--autoplay-policy=user-gesture-required', '--window-size=430,860', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const t = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(t.find((x) => x.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map(); const errs = [];
  const send = (m, p = {}) => new Promise((r) => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method: m, params: p })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); }
    if (m.method === 'Runtime.exceptionThrown') errs.push((m.params.exceptionDetails || {}).text + ' ' + ((m.params.exceptionDetails || {}).exception || {}).description);
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') errs.push('[console] ' + (m.params.args || []).map((a) => a.value || a.description || '').join(' '));
  });
  const ev = async (expr) => { const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true }); return r && r.result ? r.result.value : undefined; };
  const shot = async (name) => { const s = await send('Page.captureScreenshot', { format: 'png' }); if (s && s.data) { try { fs.writeFileSync(path.join(OUT, name), Buffer.from(s.data, 'base64')); console.log('shot -> ' + path.join(OUT, name)); } catch (e) { console.log('截图写入失败(沙箱): ' + name); } } };

  await send('Runtime.enable'); await send('Page.enable');
  await send('Page.navigate', { url: URL_ });
  await sleep(2600);

  // 蒙层是否可见 + 内容
  const g1 = await ev("JSON.stringify({inCls:(document.getElementById('gate')||{}).className||'', shown:!!document.getElementById('gate') && getComputedStyle(document.getElementById('gate')).display!=='none', opacity:!!document.getElementById('gate') && getComputedStyle(document.getElementById('gate')).opacity, title:(document.querySelector('.gate .gtitle')||{}).innerText||'', sub:(document.querySelector('.gate .gsub')||{}).innerText||'', meta:(document.querySelector('.gate .gmeta')||{}).innerText||'', tip:(document.querySelector('.gate .gtip')||{}).innerText||'', narrStarted:document.querySelectorAll('.audioflag.on').length})");
  console.log('蒙层初始：', g1);
  await shot('gate-1-idle.png');

  // 用「真实输入事件」点击蒙层（Runtime.evaluate 的 .click() 不算用户手势，无法解除自动播放限制）
  async function realClickAt(x, y) {
    await send('Input.dispatchMouseEvent', { type: 'mousePressed', x: x, y: y, button: 'left', clickCount: 1 });
    await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: x, y: y, button: 'left', clickCount: 1 });
  }
  const box = await ev("(function(){var b=document.getElementById('gateBtn').getBoundingClientRect(); return JSON.stringify({x:Math.round(b.left+b.width/2), y:Math.round(b.top+b.height/2)});})()");
  const pt = JSON.parse(box || '{"x":250,"y":400}');
  console.log('点击「开始欣赏」于', pt);
  await realClickAt(pt.x, pt.y);
  await sleep(1800);
  const g2 = await ev("JSON.stringify({cls:(document.getElementById('gate')||{}).className||'', display:!!document.getElementById('gate') && getComputedStyle(document.getElementById('gate')).display, narrPlaying:document.querySelectorAll('.audioflag.on').length, bgmPaused:(document.getElementById('bgmAudio')||{}).paused, bgmBtnOn:!!document.querySelector('#btnBgm.on')})");
  console.log('点击后：', g2);
  await shot('gate-2-dismissed.png');

  // 另开一次页面验证「暂不欣赏」：收起蒙层但不出声
  await send('Page.navigate', { url: URL_ });
  await sleep(2400);
  const sk = await ev("(function(){var b=document.getElementById('gateSkip'); if(!b) return 'no-skip-btn'; var r=b.getBoundingClientRect(); return JSON.stringify({x:Math.round(r.left+r.width/2), y:Math.round(r.top+r.height/2)});})()");
  console.log('「暂不欣赏」按钮：', sk);
  if (sk && sk.charAt(0) === '{') { const sp = JSON.parse(sk); await realClickAt(sp.x, sp.y); await sleep(1400); }
  const g3 = await ev("JSON.stringify({display:!!document.getElementById('gate') && getComputedStyle(document.getElementById('gate')).display, narrPlaying:document.querySelectorAll('.audioflag.on').length, hint:(document.querySelector('.hint')||{}).innerText||''})");
  console.log('暂不欣赏后：', g3);
  await shot('gate-3-skipped.png');

  console.log('异常：', errs.length ? errs.join(' | ') : '(无)');
  try { ws.close(); } catch { /* ignore */ }
  try { chrome.kill(); } catch { /* ignore */ }
  process.exitCode = 0;
})().catch((e) => { console.log('ERR ' + e); process.exitCode = 1; });
