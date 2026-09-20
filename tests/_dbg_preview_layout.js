// 预览页布局侦察：截图 + 列出「书本上方 / 章节页」里到底有哪些元素（定位用户红框位置）
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = Number(process.env.CDP_PORT || 9361);
const URL_ = process.env.PV_URL || 'http://127.0.0.1:9411/preview/32e9a34e6c99d9c474ada515?gate=off';
const OUT = process.env.SHOT_DIR || __dirname;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

;(async () => {
  const ud = path.join(require('os').tmpdir(), 'ls_layout_profile');
  const chrome = spawn(CHROME, [
    '--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + ud,
    '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=430,860', 'about:blank',
  ], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const t = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(t.find((x) => x.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (m, p = {}) => new Promise((r) => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method: m, params: p })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); } });
  const ev = async (expr) => { const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true }); return r && r.result ? r.result.value : undefined; };
  const shot = async (name) => { const s = await send('Page.captureScreenshot', { format: 'png' }); if (s && s.data) { try { fs.writeFileSync(path.join(OUT, name), Buffer.from(s.data, 'base64')); console.log('shot -> ' + path.join(OUT, name)); } catch { console.log('截图写入失败(沙箱): ' + name); } } };

  await send('Runtime.enable'); await send('Page.enable');
  await send('Page.navigate', { url: URL_ });
  await sleep(2500);
  await shot('layout-1-cover.png');

  // 列出 body 直接子元素 + 书本上方区域的几何
  const layout = await ev(`(function(){
    var out=[];
    document.querySelectorAll('body *').forEach(function(el){
      var r=el.getBoundingClientRect();
      if(r.height>30 && r.width>150 && r.top<420 && el.className && typeof el.className==='string' && !/^(html|body)$/.test(el.tagName)){
        out.push(el.tagName+'.'+el.className.slice(0,40)+' top='+Math.round(r.top)+' h='+Math.round(r.height)+' w='+Math.round(r.width));
      }
    });
    return out.slice(0,25).join('\\n');
  })()`);
  console.log('--- 视口上半部分元素 ---\n' + layout);

  // 章节页数：有 page-bg（配图）的章节页数量
  const stats = await ev(`JSON.stringify({
    pages: document.querySelectorAll('.page').length,
    chapterPages: document.querySelectorAll('.page.chapter').length,
    withBg: document.querySelectorAll('.page .page-bg').length,
    coverStyle: (document.querySelector('.page.cover')||{}).getAttribute ? document.querySelector('.page.cover').getAttribute('style') : '',
    scrollH: document.documentElement.scrollHeight, winH: window.innerHeight
  })`);
  console.log('--- 配图统计 ---\n' + stats);

  try { ws.close(); } catch {}
  try { chrome.kill(); } catch {}
  process.exitCode = 0;
})().catch((e) => { console.log('ERR ' + e); process.exitCode = 1; });
