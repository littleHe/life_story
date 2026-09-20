/**
 * 翻书欣赏页（/preview/{token}）端到端验证：进门蒙层（点击才出声）、多页翻页、
 * 关闭按钮、自动翻页默认开启（?narr=off 时）、朗读选项、尾页结束文案、
 * 「可朗读」按钮不翻页、默认进入即「原音讲述」。
 * 运行：node tests/front-e2e-preview.js   （SHOT_DIR 可指定截图目录）
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = 9345;
const OUT = process.env.SHOT_DIR || path.join(__dirname, '_shots');
try { fs.mkdirSync(OUT, { recursive: true }); } catch { /* 沙箱可能禁止建目录；截图不是断言项 */ }
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();
const b64 = (s) => Buffer.from(s).toString('base64');

;(async () => {
  const failures = [];
  const step = (ok, name, detail) => {
    if (ok) console.log('PASS ' + name + (detail ? '  ' + detail : ''));
    else { failures.push(name); console.log('FAIL ' + name + (detail ? '  → ' + detail : '')); }
  };

  // 预置：3 章各 1 段录音 + 转写 + 润色，定稿
  let previewUrl = '', pid = 0;
  {
    const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
    const H = { 'Content-Type': 'application/json', Authorization: 'Bearer ' + lr.data.access_token };
    const api = async (p, m = 'GET', b) => (await fetch(API + '/api' + p, { method: m, headers: H, body: b ? JSON.stringify(b) : undefined })).json();
    pid = (await api('/projects', 'POST', { name: 'E2E_翻书欣赏', code: '66666688', real_name: '张丽丽', birth: '1950-06-15', native_place: '广东清远', description: '一位普通母亲的一生，平凡而温暖。' })).data.id;
    // 新建项目会按后台「默认章节」自动播种 6 个系统章节（童年时光/青春年华/…），
    // 本测试只验证「3 章 + 封面 + 尾页 = 5 页」，故先清空默认章节再添加本次测试用的 3 章，
    // 否则页码与「可朗读」入口所在的页都会偏移。
    const seeded = (await api(`/projects/${pid}/chapters`, 'GET')).data || [];
    for (const c of seeded) {
      await api(`/projects/${pid}/chapters`, 'PATCH', { action: 'remove', chapter_id: c.id });
    }
    for (const [i, t] of ['童年', '青年', '晚年'].entries()) {
      const cid = (await api(`/projects/${pid}/chapters`, 'PATCH', { action: 'add', title: `第${i + 1}章 ${t}` })).data.chapter_id;
      const s = (await api(`/chapters/${cid}/recording`, 'POST', { audio_base64: b64('s' + i), audio_ext: 'webm', duration: '00:05' })).data;
      await api(`/chapters/${cid}/asr`, 'POST', { asset_id: s.asset_id });
      await api(`/chapters/${cid}/save`, 'POST', { polished: `第${i + 1}章正文：关于${t}岁月的温暖记忆。` });
    }
    const fin = (await api(`/projects/${pid}/finalize`, 'POST', {})).data;
    previewUrl = fin.preview_url;
    step(!!previewUrl, '后端预置 3 章并定稿（已清空默认章节）', 'pid=' + pid);
  }

  const userDataDir = path.join(os.tmpdir(), 'ls_pv_e2e_profile');
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
  // 真实输入事件点击：Runtime.evaluate 里的 .click() 不算用户手势，无法解除浏览器自动播放限制
  const realClick = async (sel) => {
    const box = await evalJs(`(function(){var b=document.querySelector(${JSON.stringify(sel)}); if(!b) return ''; var r=b.getBoundingClientRect(); return Math.round(r.left+r.width/2)+','+Math.round(r.top+r.height/2);})()`);
    if (!box) return false;
    const [x, y] = String(box).split(',').map(Number);
    await send('Input.dispatchMouseEvent', { type: 'mousePressed', x: x, y: y, button: 'left', clickCount: 1 });
    await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: x, y: y, button: 'left', clickCount: 1 });
    return true;
  };
  await send('Emulation.setDeviceMetricsOverride', { width: 480, height: 960, deviceScaleFactor: 2, mobile: true });

  // 结构/翻页部分用 ?narr=off&gate=off（关闭默认朗读与进门蒙层），保证翻页断言确定
  await send('Page.navigate', { url: previewUrl + '?narr=off&gate=off' });
  await sleep(3000);

  const PROBE = `(function(){
    return JSON.stringify({
      pages: document.querySelectorAll('.page').length,
      counter: document.getElementById('counter') ? document.getElementById('counter').textContent : '',
      autoOn: document.getElementById('btnAuto') ? document.getElementById('btnAuto').classList.contains('on') : null,
      narrOn: document.getElementById('btnNarr') ? document.getElementById('btnNarr').classList.contains('on') : null,
      hint: (document.querySelector('.hint')||{}).textContent || '',
      menuActive: (function(){ var a=document.querySelector('#narrMenu div.active'); return a ? a.getAttribute('data-mode') : null; })(),
      endTitle: (function(){ var t=document.querySelector('.page.ending .endtitle'); return t ? t.textContent : ''; })(),
      endPageNo: (function(){ var t=document.querySelector('.page.ending .pageno'); return t ? t.textContent : ''; })(),
      flagText: (function(){ var ps=document.querySelectorAll('.page'); for (var i=0;i<ps.length;i++){ if (ps[i].style.visibility==='hidden') continue; var f=ps[i].querySelector('.audioflag'); return f ? f.textContent : ''; } return ''; })(),
      hasClose: !!document.getElementById('btnClose'),
      hasNarr: !!document.getElementById('btnNarr'),
      visiblePage: (function(){ var ps=document.querySelectorAll('.page'); for (var i=0;i<ps.length;i++){ if (ps[i].style.visibility!=='hidden') return i; } return -1; })()
    });
  })()`;

  let d = JSON.parse(await evalJs(PROBE));
  step(d.pages === 5, '共 5 页(封面+3章+尾页)', 'pages=' + d.pages);
  step(d.counter === '1 / 5', '初始在封面 1/5', 'counter=' + d.counter);
  step(d.autoOn === true, '?narr=off 时自动翻页默认开启(间隔来自后台配置)', 'autoOn=' + d.autoOn);
  step(d.endTitle === '感谢欣赏' && d.endPageNo === '5 / 5', '尾页含后台可编辑的结束文案', JSON.stringify({ t: d.endTitle, no: d.endPageNo }));
  step(d.hasClose && d.hasNarr, '含关闭按钮与朗读按钮');
  await shot('pv-1-cover.png');

  // 连续翻 4 页：重点验证第2页 → 第3页 → 第4页 → 第5页(尾页)
  for (let i = 2; i <= 5; i++) {
    await evalJs(`document.getElementById('btnNext').click(); 'ok'`);
    await sleep(1250);
    d = JSON.parse(await evalJs(PROBE));
    step(d.counter === i + ' / 5' && d.visiblePage === i - 1, `翻到第 ${i} 页`, `counter=${d.counter} visible=${d.visiblePage}`);
    if (i === 3) await shot('pv-3-page.png');
  }
  step(d.counter === '5 / 5', '翻到最后一页(尾页) 5/5', d.counter);
  await shot('pv-5-ending.png');

  // 上一页可用 + 封面键
  await evalJs(`document.getElementById('btnPrev').click(); 'ok'`);
  await sleep(1250);
  d = JSON.parse(await evalJs(PROBE));
  step(d.counter === '4 / 5', '上一页回退到 4/5', d.counter);
  await evalJs(`document.getElementById('btnCover').click(); 'ok'`);
  await sleep(1250);
  d = JSON.parse(await evalJs(PROBE));
  step(d.counter === '1 / 5', '「封面」回到 1/5', d.counter);

  // 朗读菜单：原音讲述
  await evalJs(`document.getElementById('btnNarr').click(); 'ok'`);
  await sleep(400);
  const menuOpen = await evalJs(`document.getElementById('narrMenu').classList.contains('open')`);
  step(menuOpen === true, '朗读菜单可展开');
  await evalJs(`document.querySelector('#narrMenu div[data-mode="original"]').click(); 'ok'`);
  await sleep(600);
  const narrOn = await evalJs(`document.getElementById('btnNarr').classList.contains('on')`);
  step(narrOn === true, '选择「原音讲述」后朗读开启');
  await shot('pv-narration.png');

  /* ============ 进门蒙层 + 默认朗读 + 「可朗读」按钮 + 自动翻页协同（不再互斥） ============ */
  // 不带参数进入：先出现「进门蒙层」（把浏览器要求的那一次点击做成封面卡），点「开始欣赏」后三件事一起启动
  await send('Page.navigate', { url: previewUrl });
  await sleep(2600);
  const GATE_PROBE = `(function(){
    var g = document.getElementById('gate');
    var bgm = document.getElementById('bgmAudio');
    return JSON.stringify({
      shown: !!g && getComputedStyle(g).display !== 'none',
      title: (document.querySelector('.gate .gtitle')||{}).innerText || '',
      btn: (document.querySelector('.gate .gbtn')||{}).innerText || '',
      hasSkip: !!document.getElementById('gateSkip'),
      autoOn: document.getElementById('btnAuto').classList.contains('on'),
      narrOn: document.getElementById('btnNarr').classList.contains('on'),
      narrPlaying: document.querySelectorAll('.audioflag.on').length > 0,
      bgmPlaying: !!bgm && !bgm.paused && !bgm.ended,
      hint: (document.querySelector('.hint')||{}).textContent || ''
    });
  })()`;

  let g = JSON.parse(await evalJs(GATE_PROBE));
  step(g.shown === true && g.btn.indexOf('开始欣赏') >= 0, '进入时出现「进门蒙层」且有「开始欣赏」按钮', JSON.stringify({ shown: g.shown, btn: g.btn, title: g.title }));
  step(g.title.length > 0, '蒙层展示回忆录标题（即将进入的感觉）', g.title);
  step(g.hasSkip === true, '蒙层提供「暂不欣赏」退出路径（不然挡住关闭按钮）');
  step(g.autoOn === false && g.narrPlaying === false, '蒙层期间不出声、不自动翻页（等用户点击）', JSON.stringify({ autoOn: g.autoOn, narr: g.narrPlaying }));
  await shot('pv-gate.png');

  const gateClicked = await realClick('#gateBtn');
  await sleep(1800);
  g = JSON.parse(await evalJs(GATE_PROBE));
  step(gateClicked === true && g.shown === false, '点「开始欣赏」后蒙层淡出', 'shown=' + g.shown);
  step(g.autoOn === true, '点击后自动翻页开始计时', 'autoOn=' + g.autoOn);
  step(g.narrPlaying === true, '点击后朗读开始（章节「可朗读」入口变停止态）', 'narrPlaying=' + g.narrPlaying);
  step(g.bgmPlaying === true, '点击后背景音乐开始播放', 'bgmPlaying=' + g.bgmPlaying);
  step(g.hint.indexOf('轻触屏幕') < 0, '已通过蒙层拿到手势，不再提示「轻触屏幕开始」', g.hint);
  step(g.narrOn === true, '默认进入即「原音讲述」', 'narrOn=' + g.narrOn);

  // 「可朗读」按钮：点击只控制朗读、不翻页（同一 tick 内比较页码，页码总数随章节数变化，故只比「是否不变」）
  await evalJs(`document.getElementById('btnNext').click(); 'ok'`);
  await sleep(1250);
  d = JSON.parse(await evalJs(PROBE));
  step(d.visiblePage === 1 && d.flagText.indexOf('可朗读') >= 0, '章节页右上角有「可朗读」入口', JSON.stringify({ visible: d.visiblePage, flag: d.flagText }));
  const flagRes = await evalJs(`(function(){
    var ps=document.querySelectorAll('.page'), f=null;
    for (var i=0;i<ps.length;i++){ if (ps[i].style.visibility==='hidden') continue; var ff=ps[i].querySelector('.audioflag'); if (ff){ f=ff; break; } }
    var before=document.getElementById('counter').textContent;
    var flagBefore=f ? f.textContent : '';
    if (f) f.click();
    return JSON.stringify({ before: before, after: document.getElementById('counter').textContent, flagBefore: flagBefore, flagAfter: f ? f.textContent : '' });
  })()`);
  const fr = JSON.parse(flagRes);
  step(fr.before === fr.after, '点「可朗读」不翻页', JSON.stringify({ before: fr.before, after: fr.after }));
  // 用例里的录音是合成音频（无法真正解码出声），所以不硬断言切到「停止朗读」；
  // 只锁定入口在点击后仍是合法文案（真机有声时 updateNarrUI 会切到「⏸ 停止朗读」）
  step(fr.flagAfter.indexOf('可朗读') >= 0 || fr.flagAfter.indexOf('停止朗读') >= 0, '点「可朗读」后入口仍是合法开启/停止状态', JSON.stringify({ before: fr.flagBefore, after: fr.flagAfter }));

  // 「暂不欣赏」：只收起蒙层，不主动出声，退回「轻触屏幕」兜底
  await send('Page.navigate', { url: previewUrl });
  await sleep(2400);
  const skipClicked = await realClick('#gateSkip');
  await sleep(1400);
  g = JSON.parse(await evalJs(GATE_PROBE));
  step(skipClicked === true && g.shown === false, '点「暂不欣赏」收起蒙层', 'shown=' + g.shown);
  step(g.narrPlaying === false, '「暂不欣赏」不主动出声', 'narrPlaying=' + g.narrPlaying);
  step(g.hint.indexOf('轻触屏幕') >= 0, '「暂不欣赏」后提示轻触屏幕可开始声音', g.hint);

  // ?gate=off：嵌入/自动化场景可关掉蒙层，直接回到「免点击尝试出声」
  await send('Page.navigate', { url: previewUrl + '?gate=off' });
  await sleep(2600);
  const gateOff = JSON.parse(await evalJs(GATE_PROBE));
  step(gateOff.shown === false, '?gate=off 时不显示蒙层', 'shown=' + gateOff.shown);

  // 点「自动翻页」开关
  await send('Page.navigate', { url: previewUrl + '?narr=off&gate=off' });
  await sleep(2600);
  await evalJs(`document.getElementById('btnAuto').click(); 'ok'`);
  await sleep(300);
  d = JSON.parse(await evalJs(PROBE));
  step(d.autoOn === false && d.narrOn === false, '点「自动翻页」可关闭自动翻页', JSON.stringify({ auto: d.autoOn, narr: d.narrOn }));
  await evalJs(`document.getElementById('btnAuto').click(); 'ok'`);
  await sleep(300);
  d = JSON.parse(await evalJs(PROBE));
  step(d.autoOn === true && d.narrOn === false, '自动翻页开启时朗读保持关闭', JSON.stringify({ auto: d.autoOn, narr: d.narrOn }));
  await evalJs(`document.getElementById('btnNarr').click(); 'ok'`); await sleep(250);
  await evalJs(`document.querySelector('#narrMenu div[data-mode="ai"]').click(); 'ok'`);
  await sleep(400);
  d = JSON.parse(await evalJs(PROBE));
  step(d.narrOn === true && d.autoOn === true, '选「AI 润声」后自动翻页仍保持开启（两者协同）', JSON.stringify({ auto: d.autoOn, narr: d.narrOn }));

  step(errs.length === 0, '预览页无 JS 异常', errs.join(' | '));

  console.log('--------------------------------------------------');
  console.log('SHOTS=' + OUT);
  console.log('CLEANUP pid=' + pid);
  console.log(failures.length ? ('结果: 失败 — ' + failures.join(', ')) : '结果: 全部通过');
  try { ws.close(); } catch { /* ignore */ }
  try { chrome.kill(); } catch { /* ignore */ }
  // 不用 fs.rmSync 清 profile：沙箱下递归删除会被强杀，直接把摘要输出吞掉（Chrome 临时目录交给系统清理）
  process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
