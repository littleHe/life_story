/**
 * 翻书欣赏页 · 文案随朗读模式切换 端到端验证：
 *  - 默认/未选朗读：显示原声（口述原文）文案，不显示 AI 润色文案
 *  - 选「原音讲述」：显示原声文案
 *  - 选「AI 润声」：才显示 AI 润色文案
 *  - 正文不得再出现历史 mock 前缀（（AI 模拟润色）/（AI 模拟语音转写）…）
 * 运行：node tests/front-e2e-preview-text.js
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = 9347;
const OUT = process.env.SHOT_DIR || path.join(__dirname, '_shots');
try { fs.mkdirSync(OUT, { recursive: true }); } catch { /* 沙箱可能禁止建目录；截图不是断言项 */ }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();
const b64 = (s) => Buffer.from(s).toString('base64');

const ORIG_TEXT = '原声口述：童年的老屋与炊烟，还有门前那棵老槐树。';
const AI_TEXT = 'AI润色：老屋炊烟，老槐树的影子，是童年最柔软的底色。';

;(async () => {
  const failures = [];
  const step = (ok, name, detail) => {
    if (ok) console.log('PASS ' + name + (detail ? '  ' + detail : ''));
    else { failures.push(name); console.log('FAIL ' + name + (detail ? '  → ' + detail : '')); }
  };

  // 预置：1 章（含 1 段录音 + 明确区分的原声/AI 文案）+ 定稿
  let previewUrl = '', pid = 0;
  {
    const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
    const H = { 'Content-Type': 'application/json', Authorization: 'Bearer ' + lr.data.access_token };
    const api = async (p, m = 'GET', b) => (await fetch(API + '/api' + p, { method: m, headers: H, body: b ? JSON.stringify(b) : undefined })).json();
    pid = (await api('/projects', 'POST', { name: 'E2E_预览文案切换', real_name: '张丽丽', code: '66666688' })).data.id;
    // 新建项目会按后台「默认章节」自动播种系统章节；不剥离的话本次章节会落到第 8 页，
    // 「翻到第 1 章页面」的断言与正文探测都会失准（系统章节没有 .body.ai → 探针直接抛错）
    const seeded = (await api(`/projects/${pid}/chapters`, 'GET')).data || [];
    for (const c of seeded) {
      await api(`/projects/${pid}/chapters`, 'PATCH', { action: 'remove', chapter_id: c.id });
    }
    const cid = (await api(`/projects/${pid}/chapters`, 'PATCH', { action: 'add', title: '第1章 童年' })).data.chapter_id;
    const s = (await api(`/chapters/${cid}/recording`, 'POST', { audio_base64: b64('seg'), audio_ext: 'webm', duration: '00:04' })).data;
    await api(`/chapters/${cid}/save`, 'POST', { title: '第1章 童年', transcript: ORIG_TEXT, polished: AI_TEXT });
    step(!!s?.asset_id, '后端预置 1 章（原声 + AI 润色文案）', 'pid=' + pid + ' cid=' + cid);
    const fin = (await api(`/projects/${pid}/finalize`, 'POST', {})).data;
    previewUrl = fin.preview_url;
    step(!!previewUrl, '定稿生成翻书预览地址', previewUrl);
  }

  const userDataDir = path.join(os.tmpdir(), 'ls_pvt_e2e_profile');
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
    if (m.method === 'Runtime.exceptionThrown') errs.push((m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text || '').split('\n')[0]);
  });
  await send('Runtime.enable'); await send('Page.enable');
  const evalJs = async (e) => (await send('Runtime.evaluate', { expression: e, returnByValue: true })).result.value;

  // 截图：写盘失败（沙箱 EPERM）不算断言失败，绝不能中断整个用例
  const shot = async (name) => {
    const s = await send('Page.captureScreenshot', { format: 'png' });
    if (s && s.data) { try { fs.writeFileSync(path.join(OUT, name), Buffer.from(s.data, 'base64')); } catch { /* ignore */ } }
  };
  await send('Emulation.setDeviceMetricsOverride', { width: 480, height: 960, deviceScaleFactor: 2, mobile: true });
  await send('Page.navigate', { url: previewUrl });
  await sleep(2600);

  // 当前可见页的正文状态
  const BODY_PROBE = `(function(){
    var ps = document.querySelectorAll('.page');
    for (var i=0;i<ps.length;i++){
      if (ps[i].style.visibility === 'hidden') continue;
      var ob = ps[i].querySelector('.body.orig'), ab = ps[i].querySelector('.body.ai');
      if (!ob) continue;
      return JSON.stringify({
        idx: i,
        origHidden: !!ob.hidden, aiHidden: !!ab.hidden,
        origText: (ob.textContent||'').slice(0,40),
        aiText: (ab.textContent||'').slice(0,40),
        mock: /（AI 模拟/.test((ob.textContent||'') + (ab.textContent||''))
      });
    }
    return JSON.stringify({ idx: -1 });
  })()`;
  const click = async (sel) => evalJs(`(function(){ var el=document.querySelector(${JSON.stringify(sel)}); if(!el) return 'notfound'; el.click(); return 'ok'; })()`);

  // 翻到第 2 页（第 1 章）
  await evalJs(`document.getElementById('btnNext').click(); 'ok'`);
  await sleep(1200);
  let d = JSON.parse(await evalJs(BODY_PROBE));
  step(d.idx === 1, '翻到第 1 章页面', 'idx=' + d.idx);
  step(d.origHidden === false && d.aiHidden === true, '默认显示原声文案、不显示 AI 润色文案', JSON.stringify(d));
  step((d.origText || '').indexOf('原声口述') === 0, '默认正文为口述原文', d.origText);
  step(d.mock === false, '正文不含历史 mock 前缀');
  await shot('pvt-default-orig.png');

  // 选「原音讲述」→ 仍是原声文案
  await click('#btnNarr'); await sleep(300);
  await click('#narrMenu div[data-mode="original"]'); await sleep(700);
  d = JSON.parse(await evalJs(BODY_PROBE));
  step(d.origHidden === false && d.aiHidden === true, '选「原音讲述」→ 显示原声文案', JSON.stringify(d));

  // 选「AI 润声」→ 切到 AI 润色文案
  await click('#btnNarr'); await sleep(300);
  await click('#narrMenu div[data-mode="ai"]'); await sleep(700);
  d = JSON.parse(await evalJs(BODY_PROBE));
  step(d.aiHidden === false && d.origHidden === true, '选「AI 润声」→ 才显示 AI 润色文案', JSON.stringify(d));
  step((d.aiText || '').indexOf('AI润色') === 0, 'AI 模式正文为润色稿', d.aiText);
  await shot('pvt-ai.png');

  // 关闭朗读 → 回到原声文案
  await click('#btnNarr'); await sleep(300);
  await click('#narrMenu div[data-mode="off"]'); await sleep(500);
  d = JSON.parse(await evalJs(BODY_PROBE));
  step(d.origHidden === false && d.aiHidden === true, '关闭朗读 → 回到原声文案', JSON.stringify(d));

  // 翻到下一页再翻回：AI 模式下的页面切换也要正确套用文案
  await click('#btnNarr'); await sleep(250);
  await click('#narrMenu div[data-mode="ai"]'); await sleep(600);
  await evalJs(`document.getElementById('btnPrev').click(); 'ok'`);
  await sleep(1200);
  await evalJs(`document.getElementById('btnNext').click(); 'ok'`);
  await sleep(1200);
  d = JSON.parse(await evalJs(BODY_PROBE));
  step(d.idx === 1 && d.aiHidden === false, 'AI 模式翻页后仍显示润色文案', JSON.stringify(d));

  step(errs.length === 0, '预览页无 JS 异常', errs.join(' | '));

  console.log('--------------------------------------------------');
  console.log('SHOTS=' + OUT);
  console.log('CLEANUP pid=' + pid);
  console.log(failures.length ? ('结果: 失败 — ' + failures.join(', ')) : '结果: 全部通过');
  try { ws.close(); } catch { /* ignore */ }
  try { chrome.kill(); } catch { /* ignore */ }
  await sleep(300);
  // 不用 fs.rmSync 清 profile：沙箱下递归删除会被强杀，把摘要输出一起吞掉
  process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
