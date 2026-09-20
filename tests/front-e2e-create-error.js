/**
 * 新建回忆录向导 · 创建失败提示 e2e：
 *   兑换码无效/已被使用时，后端返回 40002，界面必须弹出「吐司」提示后端 msg，
 *   且弹窗保持打开（不跳转、不静默失败）。
 * 期望值不写死：先用同一个无效码直连后端拿真实 msg，再断言吐司文案与其一致。
 * 运行：node tests/front-e2e-create-error.js
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = 9361;
const BAD_CODE = process.env.E2E_CODE || 'INVALID888';
const FALLBACK_MSG = process.env.E2E_FALLBACK || '创建失败，请检查兑换码后重试';
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

;(async () => {
  const failures = [];
  const step = (ok, name, detail) => {
    if (ok) console.log('PASS ' + name + (detail ? '  ' + detail : ''));
    else { failures.push(name); console.log('FAIL ' + name + (detail ? '  → ' + detail : '')); }
  };

  const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
  const token = lr.data.access_token;
  const uid = lr.data.uid;
  step(!!token, '登录', 'uid=' + uid);

  // 直连后端拿「无效码」的真实报错文案（不依赖环境差异）
  const probeRes = await fetch(API + '/api/projects', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
    body: JSON.stringify({ name: 'E2E_ERR', real_name: '测试', avatar: '/x.jpg', code: BAD_CODE }),
  });
  const probeJson = await probeRes.json();
  const expectedMsg = probeJson.msg || '';
  step(probeJson.code !== 0 && expectedMsg.length > 0, '后端对无效兑换码返回错误信封', 'code=' + probeJson.code + ' msg=' + expectedMsg);

  const userDataDir = path.join(os.tmpdir(), 'ls_ce_e2e_' + Date.now());
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDataDir, '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=480,960', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json(); } catch { await sleep(250); } }
  if (!ready) { console.log('FAIL Chrome 未就绪'); chrome.kill(); process.exit(1); }
  const targets = await (await fetch(`http://127.0.0.1:${PORT}/json/list`)).json();
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map(); const errs = [];
  const send = (m, p = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method: m, params: p })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') errs.push(((m.params.exceptionDetails.exception || {}).description || m.params.exceptionDetails.text || '').split('\n')[0]);
  });
  await send('Runtime.enable'); await send('Page.enable'); await send('DOM.enable');
  const evalJs = async (e) => (await send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true })).result?.value;
  const clickByText = (txt) => evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf(${JSON.stringify(txt)})>=0;}); if(b){b.click();return 'ok';} return 'nobtn'; })()`);
  await send('Emulation.setDeviceMetricsOverride', { width: 480, height: 960, deviceScaleFactor: 2, mobile: true });

  await send('Page.navigate', { url: BASE + '/' });
  await sleep(1800);
  await evalJs(`localStorage.setItem('ls_access_token', ${JSON.stringify(token)}); localStorage.setItem('ls_user_info', JSON.stringify({uid:${uid || 0},nickname:'测试账号'})); 'ok'`);
  await send('Page.navigate', { url: BASE + '/memoirs' });
  await sleep(2800);

  // 打开向导
  await clickByText('新建');
  await sleep(1200);
  step(await evalJs(`!!document.querySelector('[role=dialog]')`), '向导已打开');

  // 头像现在必填：先注入本地图片上传，才能进入第二步
  const setFile = await (async () => {
    const r = await send('Runtime.evaluate', {
      expression: `(async function(){
        var inp = document.querySelector('input[type="file"][accept="image/*"]');
        if(!inp) return 'noinput';
        var resp = await fetch('${BASE}/assets/covers/cover1.jpg');
        if(!resp.ok) return 'fetchfail:'+resp.status;
        var blob = await resp.blob();
        var dt = new DataTransfer();
        dt.items.add(new File([blob], 'cover1.jpg', { type: 'image/jpeg' }));
        try { inp.files = dt.files; } catch(e) { return 'setfail:'+e.message; }
        inp.dispatchEvent(new Event('change', { bubbles: true }));
        return 'files=' + inp.files.length;
      })()`,
      returnByValue: true,
      awaitPromise: true,
    });
    return (r.result && r.result.value) || 'err';
  })();
  let avatarOk = false;
  for (let i = 0; i < 20 && !avatarOk; i++) {
    await sleep(500);
    avatarOk = !!(await evalJs(`[].slice.call(document.querySelectorAll('[role=dialog] img')).some(function(i){return (i.getAttribute('src')||'').indexOf('/uploads/avatar/')===0;})`));
  }
  step(avatarOk, '头像已上传（必填项满足）', setFile);

  // 填姓名 → 下一步
  await evalJs(`(function(){
    var inp = document.querySelector('input[placeholder="请输入姓名"]');
    var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(inp, 'E2E_报错吐司');
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    return 'ok';
  })()`);
  await sleep(400);
  // 出生年月日已改为必填（年份 1910~2010）：不填则「下一步」保持 disabled
  await evalJs(`(function(){var f=function(l,v){var s=document.querySelector('select[aria-label="'+l+'"]');if(!s)return 'no';Object.getOwnPropertyDescriptor(Object.getPrototypeOf(s),'value').set.call(s,v);s.dispatchEvent(new Event('change',{bubbles:true}));return 'ok';};return [f('年','1965'),f('月','06'),f('日','15')].join(',');})()`);
  await clickByText('下一步');
  await sleep(1000);

  // 切到手动输入并填入无效码
  await clickByText('手动输入');
  await sleep(600);
  await evalJs(`(function(){
    var inp = document.querySelector('#unlockCode');
    if(!inp) return 'noinput';
    var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(inp, ${JSON.stringify(BAD_CODE)});
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    return 'ok';
  })()`);
  await sleep(400);

  // 点击创建并解锁
  await clickByText('创建并解锁');

  // 轮询等吐司（最多 6s）
  let toastText = '';
  for (let i = 0; i < 12 && !toastText; i++) {
    await sleep(500);
    toastText = (await evalJs(`(function(){ var t=document.querySelector('[data-sonner-toast]'); return t? (t.textContent||'').trim():''; })()`)) || '';
  }
  step(toastText.length > 0, '创建失败弹出吐司提示', 'toast="' + toastText.slice(0, 60) + '"');
  // 后端 msg 干净（业务错误）→ 吐司应与之一致；msg 带堆栈（服务端异常）→ 吐司降级为兜底文案
  const cleanMsg = !/[\r\n]/.test(expectedMsg) && !/Exception|vendor\\|\.php/i.test(expectedMsg);
  if (cleanMsg) {
    step(toastText.indexOf(expectedMsg) >= 0, '吐司文案与后端 msg 一致', '期望含 "' + expectedMsg + '"');
  } else {
    step(toastText === FALLBACK_MSG, '服务端异常时吐司降级为兜底文案', 'toast="' + toastText + '"');
  }
  step(!/Exception|vendor\\|\.php|[\r\n]/.test(toastText), '吐司不泄漏内部堆栈/路径', 'toast="' + toastText.slice(0, 40) + '"');
  const toastIsError = await evalJs(`(function(){ var t=document.querySelector('[data-sonner-toast]'); return !!t && (t.getAttribute('data-type')||'') === 'error'; })()`);
  step(toastIsError, '吐司为 error 类型', String(toastIsError));

  const shot = await send('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(path.join(OUT, 'create-error-toast.png'), Buffer.from(shot.data, 'base64'));

  // 弹窗保持打开、未跳转
  step(await evalJs(`!!document.querySelector('[role=dialog]')`), '失败后弹窗保持打开（可重输）');
  const pathNow = await evalJs('location.pathname');
  step(pathNow === '/memoirs', '失败后未跳转详情页', 'path=' + pathNow);
  step(errs.length === 0, '全程无 JS 未捕获异常', errs.slice(-2).join(' | '));

  console.log('--------------------------------------------------');
  console.log('SHOTS=' + OUT);
  console.log(failures.length ? ('结果: 失败 — ' + failures.join(', ')) : '结果: 全部通过');
  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch { /* ignore */ }
  process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
