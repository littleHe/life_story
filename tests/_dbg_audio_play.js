// 诊断：真实录制的 webm 在 Chrome <audio> 里能否播放（duration=Infinity 问题定位）
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = 'http://127.0.0.1:8001';
const PORT = 9352;
const FILE = process.argv[2] || '/uploads/audio/20260915_124135_6aa8cc7fb128a.webm';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

;(async () => {
  const dir = path.join(os.tmpdir(), 'ls_audio_dbg_' + Date.now());
  const chrome = spawn(CHROME, ['--headless=new', '--autoplay-policy=no-user-gesture-required', '--remote-debugging-port=' + PORT, '--user-data-dir=' + dir, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  for (let i = 0; i < 40; i++) { try { await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json(); break; } catch { await sleep(250); } }
  const t = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
  const ws = new WebSocket(t.find((x) => x.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pend = new Map(); const cons = [];
  const send = (m, p = {}) => new Promise((r) => { const mid = ++id; pend.set(mid, r); ws.send(JSON.stringify({ id: mid, method: m, params: p })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pend.has(m.id)) { pend.get(m.id)(m.result); pend.delete(m.id); return; }
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') cons.push(m.params.args.map((a) => a.description || a.value).join(' ').slice(0, 200));
  });
  await send('Runtime.enable'); await send('Page.enable');
  await send('Page.navigate', { url: BASE + '/login' });
  await sleep(2000);
  const ev = async (x) => (await send('Runtime.evaluate', { expression: x, returnByValue: true, awaitPromise: true })).result;
  const r = await ev(`(async function(){
    var out = { file: ${JSON.stringify(FILE)} };
    var res = await fetch(${JSON.stringify(FILE)});
    out.http = res.status; out.bytes = (await res.blob()).size;
    var el = new Audio();
    el.src = ${JSON.stringify(FILE)};
    el.preload = 'auto';
    document.body.appendChild(el);
    out.loadErr = await new Promise(function(resolve){
      var done=false;
      el.addEventListener('error', function(){ if(!done){done=true;resolve('ERROR code='+el.error.code);} });
      el.addEventListener('loadedmetadata', function(){ if(!done){done=true;resolve('metadata ok');} });
      setTimeout(function(){ if(!done){done=true;resolve('timeout 4s');} }, 4000);
    });
    out.duration = el.duration;           // Infinity = 无时长元数据
    out.readyState = el.readyState;
    try {
      await el.play();
      await new Promise(function(r){ setTimeout(r, 1200); });
      out.playing = !el.paused;
      out.currentTime = el.currentTime;
      el.pause();
    } catch(e) { out.playErr = String(e && e.message || e); }
    // duration=Infinity 时的经典校正手段：跳到远端再回来
    if (out.duration === Infinity) {
      el.currentTime = 1e7;
      await new Promise(function(r){ setTimeout(r, 400); });
      out.durationAfterSeekHack = el.duration;
    }
    return JSON.stringify(out);
  })()`);
  console.log('RESULT=', r.value);
  console.log('CONSOLE_ERR=', JSON.stringify(cons));
  ws.close(); chrome.kill(); await sleep(300);
  try { fs.rmSync(dir, { recursive: true, force: true }); } catch {}
  process.exit(0);
})().catch((e) => { console.log('ERR', e); process.exit(1); });
