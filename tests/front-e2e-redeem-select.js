/**
 * 回忆录前端 · 「选择已有兑换码」核销路径端到端验证
 *
 * 背景（回归用例）：mine() 会把「已归属本人」的码列进下拉，但 bindById() 曾硬要求
 * status='ADDED'；不限次测试码首次绑定只写 bound_user_id、状态仍停在 GENERATED，
 * 于是下拉里看得见、点「创建并解锁」却返回 40902。本用例锁定该路径不再回归。
 *
 * 前置：前端 8001 + 后端 9411 已启动，且当前测试账号名下至少有一个可用（未绑定）兑换码。
 * 运行：node tests/front-e2e-redeem-select.js
 * 退出码：0 = 全部通过
 */
const { spawn } = require('child_process');
const os = require('os');
const path = require('path');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = 9343;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

(async () => {
  const failures = [];
  const step = (ok, name, detail) => {
    if (ok) console.log('PASS ' + name + (detail ? '  ' + detail : ''));
    else { failures.push(name); console.log('FAIL ' + name + (detail ? '  → ' + detail : '')); }
  };

  // ===== 前置：确认账号名下存在可用码（否则用例无意义） =====
  const login = await (await fetch(API + '/api/auth/test/login', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}',
  })).json();
  const token = login.data.access_token;
  const uid = login.data.uid;
  const mine = await (await fetch(API + '/api/redeem/codes', { headers: { Authorization: 'Bearer ' + token } })).json();
  const usable = (mine.data || []).filter((c) => c.status === 'unused');
  step(usable.length > 0, 'P.账号名下存在可用兑换码', 'count=' + usable.length + ' first=' + (usable[0] && usable[0].code_mask));

  // ===== 启动受控 Chrome =====
  const userDataDir = path.join(os.tmpdir(), 'ls_redeem_select_' + Date.now());
  const chrome = spawn(CHROME, [
    '--headless=new', '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + userDataDir, '--no-first-run', '--no-default-browser-check',
    '--disable-gpu', '--disable-extensions', '--hide-scrollbars', 'about:blank',
  ], { stdio: 'ignore' });

  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) {
    try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch (e) { await sleep(250); }
  }
  if (!ready) { console.log('FAIL Chrome 未就绪，请检查 CHROME_PATH'); chrome.kill(); process.exit(1); }

  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (m, p = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method: m, params: p })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); }
  });
  await send('Runtime.enable'); await send('Page.enable');
  const evalJs = async (e) => (await send('Runtime.evaluate', { expression: e, returnByValue: true })).result.value;

  const findBtn = (text, requireEnabled) => `(function(){
    var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf(${JSON.stringify(text)})>=0;});
    if(!b) return 'notfound';
    ${requireEnabled ? 'if(b.disabled) return "disabled";' : ''}
    b.click(); return 'clicked';
  })()`;

  // ===== 登录态注入 =====
  await send('Page.navigate', { url: BASE + '/login' });
  await sleep(2200);
  await evalJs(`localStorage.setItem('ls_access_token', ${JSON.stringify(token)});
    localStorage.setItem('ls_refresh_token', ${JSON.stringify(login.data.refresh_token || '')});
    localStorage.setItem('ls_user_info', JSON.stringify({uid:${uid},nickname:'测试账号'})); 'ok'`);

  // ===== 打开向导 → 第一步 =====
  await send('Page.navigate', { url: BASE + '/memoirs' });
  await sleep(2600);
  step((await evalJs(findBtn('新建'))) === 'clicked', 'S.打开新建向导');
  await sleep(1100);

  await evalJs(`(function(){
    var inp = document.querySelector('input[placeholder="请输入姓名"]');
    if(!inp) return 'noinput';
    var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype,'value').set;
    setter.call(inp, '选择码测试');
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    return 'filled';
  })()`);

  // 头像必填：DataTransfer 注入真实 File
  await (async () => {
    await send('Runtime.evaluate', {
      expression: `(async function(){
        var inp = document.querySelector('input[type="file"][accept="image/*"]');
        if(!inp) return 'noinput';
        var resp = await fetch('${BASE}/assets/covers/cover1.jpg');
        var blob = await resp.blob();
        var dt = new DataTransfer();
        dt.items.add(new File([blob], 'avatar.jpg', { type: 'image/jpeg' }));
        inp.files = dt.files;
        inp.dispatchEvent(new Event('change', { bubbles: true }));
        return 'ok';
      })()`,
      returnByValue: true, awaitPromise: true,
    });
  })();

  let avatarOk = false;
  for (let i = 0; i < 20 && !avatarOk; i++) {
    await sleep(500);
    avatarOk = await evalJs(`(function(){
      var dlg = document.querySelector('[role=dialog]') || document.body;
      return [].slice.call(dlg.querySelectorAll('img')).some(function(i){ return (i.getAttribute('src')||'').indexOf('/uploads/avatar/') >= 0; });
    })()`);
  }
  step(avatarOk, 'S.上传传主头像（必填项）');

  // ===== 进入兑换解锁 → 选择已有兑换码 =====
  // 出生年月日已改为必填（年份 1910~2010）：不填则「下一步」保持 disabled
  await evalJs(`(function(){var f=function(l,v){var s=document.querySelector('select[aria-label="'+l+'"]');if(!s)return 'no';Object.getOwnPropertyDescriptor(Object.getPrototypeOf(s),'value').set.call(s,v);s.dispatchEvent(new Event('change',{bubbles:true}));return 'ok';};return [f('年','1965'),f('月','06'),f('日','15')].join(',');})()`);
  step((await evalJs(findBtn('下一步', true))) === 'clicked', 'S.进入兑换解锁步骤');
  await sleep(900);

  const submitState0 = await evalJs(`(function(){
    var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('创建并解锁')>=0;});
    return b ? (b.disabled ? 'disabled' : 'enabled') : 'notfound';
  })()`);
  step(submitState0 === 'disabled', 'S.未选码时「创建并解锁」禁用', 'submit=' + submitState0);

  const hasSelect = await evalJs(`(function(){
    var dlg = document.querySelector('[role=dialog]') || document.body;
    return !!(dlg.querySelector('[role="combobox"]') || dlg.querySelector('select'));
  })()`);
  step(hasSelect, 'S.展示「选择已有兑换码」下拉');

  // 打开 Radix Select 并选第一个选项
  const pick = await evalJs(`(function(){
    var dlg = document.querySelector('[role=dialog]') || document.body;
    var trg = dlg.querySelector('[role="combobox"]');
    if(!trg) return 'notrigger';
    trg.click();
    return 'opened';
  })()`);
  await sleep(800);
  const picked = await evalJs(`(function(){
    var opts = [].slice.call(document.querySelectorAll('[role="option"]'));
    if(!opts.length) return 'nooption';
    var label = (opts[0].textContent||'').replace(/\\s+/g,' ').trim();
    opts[0].click();
    return 'picked:' + label.slice(0, 20);
  })()`);
  await sleep(600);
  step(pick === 'opened' && picked.indexOf('picked') === 0, 'S.选择下拉中的兑换码', pick + ' / ' + picked);

  const submitState1 = await evalJs(`(function(){
    var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('创建并解锁')>=0;});
    return b ? (b.disabled ? 'disabled' : 'enabled') : 'notfound';
  })()`);
  step(submitState1 === 'enabled', 'S.选码后「创建并解锁」可点', 'submit=' + submitState1);

  // ===== 提交 =====
  step((await evalJs(findBtn('创建并解锁', true))) === 'clicked', 'S.提交创建');
  await sleep(3800);

  const finalPath = await evalJs('location.pathname');
  step(/^\/memoirs\/\d+$/.test(finalPath), 'S.创建成功并跳转详情页', 'path=' + finalPath);

  const toastTxt = await evalJs(`(function(){
    var t=document.querySelector('[data-sonner-toast], [role=status]');
    return t ? (t.textContent||'').replace(/\\s+/g,' ').slice(0,60) : '';
  })()`);
  if (toastTxt) console.log('NOTE 页面提示: ' + toastTxt);

  const createdId = /^\/memoirs\/(\d+)$/.test(finalPath) ? finalPath.split('/').pop() : '';
  console.log('--------------------------------------------------');
  console.log('本次创建的测试项目 id = ' + (createdId || '(无)'));
  console.log('结果: ' + (failures.length ? '失败 — ' + failures.join(', ') : '全部通过'));

  chrome.kill();
  process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e.message); process.exit(1); });
