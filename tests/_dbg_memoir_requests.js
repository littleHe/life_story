// 复现 /memoirs/:id 的请求风暴：统计重复请求 + 是否发生了 audio 元素重建（React 重挂载）
// 用法：node tests/_dbg_memoir_requests.js [pid]
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = Number(process.env.CDP_PORT || 9361);
const PID = Number(process.argv[2] || 12);
const OBSERVE_MS = Number(process.env.OBSERVE_MS || 12000);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

;(async () => {
  const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
  const token = lr.data.access_token, uid = lr.data.uid;
  console.log(`登录 uid=${uid} 目标 /memoirs/${PID}`);

  const ud = path.join(os.tmpdir(), 'ls_req_profile');
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + ud,
    '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=430,900', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const t = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(t.find((x) => x.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const reqs = [];   // {url, method, status, range, mime, size}
  const byId = new Map();
  const send = (m, p = {}) => new Promise((r) => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method: m, params: p })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (e) => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Network.requestWillBeSent') {
      const r = m.params.request;
      if (/\.(m4a|webm|wav|mp3|ogg|aac)(\?|$)/i.test(r.url)) {
        const rec = { id: m.params.requestId, url: r.url, method: r.method, range: r.headers && (r.headers.Range || r.headers.range) || '', status: 0, mime: '', size: 0 };
        reqs.push(rec); byId.set(m.params.requestId, rec);
      }
    }
    if (m.method === 'Network.responseReceived') {
      const rec = byId.get(m.params.requestId);
      if (rec) { rec.status = m.params.response.status; rec.mime = m.params.response.mimeType; }
    }
    if (m.method === 'Network.loadingFinished') {
      const rec = byId.get(m.params.requestId);
      if (rec) rec.size = m.params.encodedDataLength;
    }
  });
  const ev = async (expr) => { const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true }); return r && r.result ? r.result.value : undefined; };

  await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');

  // 在页面脚本执行前就装好计数器：audio 元素被插入的次数（= React 是否重挂载）+ 各接口调用次数
  await send('Page.addScriptToEvaluateOnNewDocument', {
    source: `(function(){
      window.__audioAdds = 0; window.__apiCalls = {};
      var origFetch = window.fetch;
      window.fetch = function(input, init){
        try {
          var u = (typeof input === 'string') ? input : (input && input.url) || '';
          var k = u.replace(/^https?:\\/\\/[^/]+/, '').replace(/\\/\\d+/g, '/:id').split('?')[0];
          window.__apiCalls[k] = (window.__apiCalls[k] || 0) + 1;
        } catch(e){}
        return origFetch.apply(this, arguments);
      };
      var origOpen = XMLHttpRequest.prototype.open;
      XMLHttpRequest.prototype.open = function(m, u){
        try {
          var k = String(u).replace(/^https?:\\/\\/[^/]+/, '').replace(/\\/\\d+/g, '/:id').split('?')[0];
          window.__apiCalls[k] = (window.__apiCalls[k] || 0) + 1;
        } catch(e){}
        return origOpen.apply(this, arguments);
      };
      document.addEventListener('DOMContentLoaded', function(){
        var mo = new MutationObserver(function(muts){
          muts.forEach(function(mu){
            [].forEach.call(mu.addedNodes, function(n){
              if (n.nodeType !== 1) return;
              if (n.tagName === 'AUDIO') window.__audioAdds++;
              else if (n.querySelectorAll) window.__audioAdds += n.querySelectorAll('audio').length;
            });
          });
        });
        mo.observe(document.documentElement, { childList: true, subtree: true });
      });
    })();`,
  });

  await send('Emulation.setDeviceMetricsOverride', { width: 430, height: 900, deviceScaleFactor: 2, mobile: true });
  await send('Page.navigate', { url: BASE + '/' });
  await sleep(1500);
  await ev(`localStorage.setItem('ls_access_token', ${JSON.stringify(token)}); localStorage.setItem('ls_user_info', JSON.stringify({uid:${uid},nickname:'测试账号'})); 'ok'`);

  await send('Page.navigate', { url: BASE + '/memoirs/' + PID });
  await sleep(OBSERVE_MS);

  const dom = await ev(`JSON.stringify({
    audioCount: document.querySelectorAll('audio').length,
    audioAdds: window.__audioAdds,
    apiCalls: window.__apiCalls,
    srcs: [].slice.call(document.querySelectorAll('audio')).map(function(a){ return a.getAttribute('src'); })
  })`);
  console.log('\n--- DOM / 调用统计 ---');
  console.log(dom);

  // 按 URL 聚合
  const agg = new Map();
  for (const r of reqs) {
    const key = r.url.split('?')[0];
    if (!agg.has(key)) agg.set(key, { n: 0, ranges: [], sizes: [], statuses: [] });
    const a = agg.get(key);
    a.n++; a.ranges.push(r.range || '-'); a.sizes.push(r.size); a.statuses.push(r.status);
  }
  console.log('\n--- 音频类请求按 URL 聚合 ---');
  [...agg.entries()].sort((a, b) => b[1].n - a[1].n).forEach(([u, a]) => {
    console.log(`x${a.n}  ${u}`);
    console.log(`      range=[${a.ranges.join(', ')}]  size=[${a.sizes.join(', ')}]  status=[${[...new Set(a.statuses)].join(',')}]`);
  });
  console.log(`\n音频类请求总数 = ${reqs.length}（去重后 ${agg.size} 个 URL）`);

  const shot = await send('Page.captureScreenshot', { format: 'png' });
  const f = path.join(process.env.SHOT_DIR || os.tmpdir(), 'memoir-requests.png');
  try { fs.writeFileSync(f, Buffer.from(shot.data, 'base64')); console.log('截图: ' + f); } catch { /* ignore */ }

  try { ws.close(); } catch { /* ignore */ }
  try { chrome.kill(); } catch { /* ignore */ }
  process.exitCode = 0;
})().catch((e) => { console.log('ERR ' + e); process.exitCode = 1; });
