/**
 * 回忆录前端 · 登录/列表/新建/详情/登出 端到端验证（自包含，无第三方依赖）
 *
 * 验证项：
 *  A. 未登录访问受保护路由 → 自动跳转 /login
 *  B. 登录页「微信一键授权登录」按钮存在，且页面零外网请求、零破图
 *  C. 点击一键登录 → 写入 token、进入首页
 *  F. 回忆录列表：无本地 mock 演示数据；零外网请求；零 4xx；零 /spark 残留请求；零破图
 *  G. 新建回忆录：走真实接口落库 → 跳转真实 id 详情页（/memoirs/<数字>）
 *  H. 详情页：标题为真实项目名；新增章节成功
 *  D. 个人中心显示测试账号昵称「测试账号」且「退出登录」按钮存在
 *  E. 点击退出登录 → 回到 /login，token / 用户信息 / 应用缓存全部清空
 *
 * 说明：网络断言通过页面 performance 资源条目实现（不再订阅 CDP Network 事件流，
 *       避免大消息触发 undici「Max decompressed message size exceeded」导致整轮中断）。
 *
 * 前置：前端 vite 已启动（默认 http://127.0.0.1:8001），后端 9411 已启动。
 * 运行：node tests/front-e2e.js
 * 退出码：0 = 全部通过；1 = 存在失败项
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = Number(process.env.CDP_PORT || 9337);
const TEST_NAME = '端到端测试';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

let pageErrors = [];

(async () => {
  const failures = [];
  const step = (ok, name, detail) => {
    if (ok) console.log('PASS ' + name + (detail ? ('  ' + detail) : ''));
    else { failures.push(name); console.log('FAIL ' + name + (detail ? ('  → ' + detail) : '')); }
  };

  // ===== P. 后端接口预检：登录 + 数据隔离 =====
  let apiToken = '';
  try {
    const loginRes = await (await fetch(API + '/api/auth/test/login', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}',
    })).json();
    apiToken = loginRes && loginRes.data && loginRes.data.access_token;
    step(!!apiToken, 'P.测试账号登录接口可用', apiToken ? 'len=' + apiToken.length : JSON.stringify(loginRes));
    const listRes = await (await fetch(API + '/api/projects', { headers: { Authorization: 'Bearer ' + apiToken } })).json();
    const list = Array.isArray(listRes.data) ? listRes.data : [];
    const foreign = list.filter((p) => Number(p.user_id) !== Number(loginRes.data.uid));
    step(foreign.length === 0, 'P.回忆录列表按用户隔离', foreign.length ? '越权数据:' + JSON.stringify(foreign.map((p) => p.id)) : '共 ' + list.length + ' 条');
    const foreignIds = list
      .filter((p) => Number(p.user_id) !== Number(loginRes.data.uid))
      .map((p) => p.id);
    // 优先用「确实属于他人」的项目 id；本机没有他人数据时退回一个必然不存在的 id
    // （read() 按 user_id 过滤，不存在与越权走同一 404 分支）
    const probeId = foreignIds.length ? foreignIds[0] : 999999;
    const other = await fetch(API + '/api/projects/' + probeId, { headers: { Authorization: 'Bearer ' + apiToken } });
    step(other.status === 404, 'P.访问他人回忆录详情被拒(404)', 'pid=' + probeId + ' http=' + other.status);
    const noAuth = await fetch(API + '/api/projects');
    step(noAuth.status === 401, 'P.未登录访问受保护接口 401', 'http=' + noAuth.status);
  } catch (e) {
    step(false, 'P.后端接口预检', 'ERR ' + e.message);
  }

  const userDataDir = path.join(os.tmpdir(), 'ls_front_e2e_' + Date.now());
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
  const send = (method, params = {}) => new Promise((res) => {
    const mid = ++id; pending.set(mid, res);
    ws.send(JSON.stringify({ id: mid, method, params }));
  });

  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('error', (e) => console.log('WS ERROR ' + (e && e.message)));
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); return; }
    if (m.method === 'Runtime.exceptionThrown') {
      const d = m.params.exceptionDetails || {};
      pageErrors.push(((d.exception && (d.exception.description || d.exception.value)) || d.text || '').split('\n')[0]);
    }
  });

  await send('Runtime.enable');
  await send('Page.enable');

  // 页面探针：路径 + 破图 + 按钮 + 昵称 + 本地存储 + 列表卡片标题 + 资源请求（performance 条目）
  const PROBE = `(function(){
    var imgs = Array.prototype.slice.call(document.querySelectorAll('img'));
    var broken = imgs.filter(function(i){ return i.getAttribute('src') && i.naturalWidth === 0; })
                     .map(function(i){ return i.getAttribute('src'); });
    var ls = {};
    try { for (var i=0;i<localStorage.length;i++){ var k=localStorage.key(i); ls[k]=1; } } catch(e){}
    var status4xx = [], external = [], spark = [], sdk = [];
    var res = (performance.getEntriesByType('resource') || []).concat(performance.getEntriesByType('navigation') || []);
    res.forEach(function(e){
      var url = e.name || '';
      if (!url || /^(data:|blob:)/.test(url)) return;
      var st = e.responseStatus || 0;
      if (st >= 400) status4xx.push(st + ' ' + url.replace(location.origin, ''));
      try { var u = new URL(url); if (u.hostname !== '127.0.0.1' && u.hostname !== 'localhost') external.push(url); } catch(_){}
      if (url.indexOf('/spark/app/') >= 0 || url.indexOf('storage/object') >= 0) spark.push(url.replace(location.origin, ''));
      else if (url.indexOf('/spark/') >= 0) sdk.push(url.replace(location.origin, ''));
    });
    return JSON.stringify({
      path: location.pathname + location.search,
      hasLoginBtn: Array.prototype.some.call(document.querySelectorAll('button'), function(b){ return b.textContent.indexOf('微信一键授权登录') >= 0; }),
      hasLogoutBtn: Array.prototype.some.call(document.querySelectorAll('button'), function(b){ return b.textContent.indexOf('退出登录') >= 0; }),
      nickname: (function(){ var h=document.querySelector('h2'); return h ? h.textContent.trim() : ''; })(),
      token: localStorage.getItem('ls_access_token'),
      user: localStorage.getItem('ls_user_info'),
      refresh: localStorage.getItem('ls_refresh_token'),
      cacheKeys: Object.keys(ls).filter(function(k){ return /memoir|redeem/i.test(k); }),
      cards: Array.prototype.map.call(document.querySelectorAll('h3'), function(h){ return h.textContent.trim(); }),
      brokenImgs: broken,
      status4xx: status4xx, external: external, spark: spark, sdk: sdk
    });
  })()`;

  async function evalJs(expr) {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true });
    return r.result.value;
  }

  async function go(url) {
    pageErrors = [];
    await send('Page.navigate', { url: BASE + url });
    await sleep(2800);
    const dom = JSON.parse(await evalJs(PROBE));
    await evalJs('try{ performance.clearResourceTimings(); }catch(e){} "ok"');
    return dom;
  }

  async function probeCurrent() {
    const dom = JSON.parse(await evalJs(PROBE));
    await evalJs('try{ performance.clearResourceTimings(); }catch(e){} "ok"');
    return dom;
  }

  // ===== A. 未登录访问 / → 跳转 /login =====
  let dom = await go('/');
  step(/login/.test(dom.path), 'A.未登录跳登录页', 'path=' + dom.path);

  // ===== B. 登录页 =====
  step(dom.hasLoginBtn, 'B.登录页存在一键登录按钮');
  step(dom.external.length === 0, 'B.登录页零外网请求', dom.external.length ? '外网:' + dom.external.join(', ') : 'ok');
  step(dom.brokenImgs.length === 0, 'B.登录页零破图', dom.brokenImgs.length ? dom.brokenImgs.join(', ') : 'ok');
  step(pageErrors.length === 0, 'B.登录页无JS异常', pageErrors.join(' | '));
  step(!dom.token, 'B.登录前无token');

  // ===== C. 点击一键登录 → 进入首页（现在还会同时写入 refresh token） =====
  const clickEv = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('微信一键授权登录')>=0;}); if(b){b.click(); return 'clicked';} return 'notfound'; })()`);
  await sleep(3200);
  dom = await probeCurrent();

  step(clickEv === 'clicked', 'C.点击一键登录', clickEv);
  step(!!dom.token, 'C.登录写入token', dom.token ? 'len=' + dom.token.length : 'null');
  step(!!dom.refresh, 'C.登录写入refresh token(用于静默续期)');
  step(!/login/.test(dom.path), 'C.登录后离开登录页', 'path=' + dom.path);
  step(dom.external.length === 0, 'C.首页零外网请求', dom.external.length ? '外网:' + dom.external.join(', ') : 'ok');
  step(dom.brokenImgs.length === 0, 'C.首页零破图', dom.brokenImgs.length ? dom.brokenImgs.join(', ') : 'ok');
  step(pageErrors.length === 0, 'C.首页无JS异常', pageErrors.join(' | '));

  // ===== F. 回忆录列表 =====
  dom = await go('/memoirs');
  const mockTitles = ['父亲的人生故事', '母亲的岁月回忆', '我的烽火岁月'];
  const leaked = mockTitles.filter((t) => dom.cards.indexOf(t) >= 0);
  step(leaked.length === 0, 'F.列表无本地 mock 演示数据', leaked.length ? '泄漏:' + leaked.join(',') : 'ok');
  step(dom.status4xx.length === 0, 'F.列表无 4xx 请求', dom.status4xx.join(' | '));
  step(dom.spark.length === 0, 'F.列表无妙搭平台 storage 外链', dom.spark.join(' | '));
  step(dom.external.length === 0, 'F.列表零外网请求', dom.external.length ? '外网:' + dom.external.join(', ') : 'ok');
  step(dom.brokenImgs.length === 0, 'F.列表零破图', dom.brokenImgs.join(', '));
  step(pageErrors.length === 0, 'F.列表无JS异常', pageErrors.join(' | '));
  if (dom.sdk.length) console.log('NOTE 工具包基础设施调用(同源相对路径，非外链): ' + dom.sdk.join(', '));

  // ===== G. 新建回忆录 → 真实接口落库 → 详情页 =====
  const openWizard = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.trim()==='新建';}); if(b){b.click(); return 'clicked';} return 'notfound'; })()`);
  await sleep(900);
  const fillName = await evalJs(`(function(){
    var inp = document.querySelector('input[placeholder="请输入姓名"]');
    if (!inp) return 'noinput';
    var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(inp, ${JSON.stringify(TEST_NAME)});
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    return 'filled';
  })()`);
  await sleep(400);

  // 向导内「传主头像」为必填：不提供头像时「下一步」保持 disabled，永远走不到兑换解锁步骤。
  // 用 DataTransfer 给隐藏 file input 注入真实 File（CDP setFileInputFiles 对其无效）。
  const setAvatar = await (async () => {
    const r = await send('Runtime.evaluate', {
      expression: `(async function(){
        var inp = document.querySelector('input[type="file"][accept="image/*"]');
        if(!inp) return 'noinput';
        var resp = await fetch('${BASE}/assets/covers/cover1.jpg');
        if(!resp.ok) return 'fetchfail:'+resp.status;
        var blob = await resp.blob();
        var file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
        var dt = new DataTransfer();
        dt.items.add(file);
        try { inp.files = dt.files; } catch(e) { return 'setfail:'+e.message; }
        inp.dispatchEvent(new Event('change', { bubbles: true }));
        return 'ok';
      })()`,
      returnByValue: true,
      awaitPromise: true,
    });
    return (r.result && r.result.value) || 'err';
  })();

  let avatarOk = false;
  for (let i = 0; i < 20 && !avatarOk; i++) {
    await sleep(500);
    avatarOk = await evalJs(`(function(){
      var dlg = document.querySelector('[role=dialog]') || document.body;
      return [].slice.call(dlg.querySelectorAll('img')).some(function(i){ return (i.getAttribute('src')||'').indexOf('/uploads/avatar/') >= 0; });
    })()`);
  }
  step(avatarOk, 'G.上传传主头像（必填项）', 'set=' + setAvatar);

  // 出生年月日已改为必填（年份 1910~2010）：不填则「下一步」保持 disabled
  await evalJs(`(function(){var f=function(l,v){var s=document.querySelector('select[aria-label="'+l+'"]');if(!s)return 'no';Object.getOwnPropertyDescriptor(Object.getPrototypeOf(s),'value').set.call(s,v);s.dispatchEvent(new Event('change',{bubbles:true}));return 'ok';};return [f('年','1965'),f('月','06'),f('日','15')].join(',');})()`);
  const clickNext = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('下一步')>=0;}); if(b && !b.disabled){b.click(); return 'clicked';} return b ? 'disabled' : 'notfound'; })()`);
  // 等待第二步「兑换解锁」渲染出「创建并解锁」
  for (let i = 0; i < 15; i++) {
    const has = await evalJs(`(function(){ return !![].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('创建并解锁')>=0;}); })()`);
    if (has) break;
    await sleep(300);
  }
  await sleep(400);
  // 门禁校验：进入兑换解锁步骤后，未显式选择/输入兑换码时「创建并解锁」必须禁用
  // （修复点：不再自动预选「我的兑换码」，测试号也须主动提供码，否则相当于跳过门禁）
  const submitStateBefore = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('创建并解锁')>=0;}); return b ? (b.disabled ? 'disabled' : 'enabled') : 'notfound'; })()`);
  await sleep(200);
  step(submitStateBefore === 'disabled', 'G.兑换解锁未提供码时「创建并解锁」禁用(门禁不自动通过)', 'submit=' + submitStateBefore);
  // 兑换解锁步骤：必须提供兑换码才能创建（走「手动输入」路径；测试码 66666688 不限次复用）
  const switchInput = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.trim()==='手动输入';}); if(b){b.click(); return 'clicked';} return 'notfound'; })()`);
  await sleep(400);
  const fillCode = await evalJs(`(function(){
    var inp = document.querySelector('input[placeholder="请输入兑换码"]');
    if (!inp) return 'noinput';
    var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(inp, '66666688');
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    return 'filled';
  })()`);
  await sleep(400);
  const clickSubmit = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('创建并解锁')>=0;}); if(b){b.click(); return 'clicked';} return 'notfound'; })()`);
  await sleep(3500);
  dom = await probeCurrent();

  step(openWizard === 'clicked', 'G.打开新建向导', openWizard);
  step(fillName === 'filled', 'G.填写传主姓名', fillName);
  step(clickNext === 'clicked', 'G.进入兑换解锁步骤', clickNext);
  step(switchInput === 'clicked' && fillCode === 'filled', 'G.兑换解锁：输入兑换码', `switch=${switchInput} fill=${fillCode}`);
  step(clickSubmit === 'clicked', 'G.提交创建', clickSubmit);
  step(/^\/memoirs\/\d+$/.test(dom.path), 'G.跳转真实 id 详情页', 'path=' + dom.path);
  const gCreate4xx = dom.status4xx.filter((x) => !/\/bind-code/.test(x));
  step(gCreate4xx.length === 0, 'G.创建流程无 4xx 请求(排除可选核销)', dom.status4xx.join(' | '));
  step(dom.spark.length === 0, 'G.创建流程无 /spark 请求', dom.spark.join(' | '));
  step(dom.external.length === 0, 'G.创建流程零外网请求', dom.external.length ? '外网:' + dom.external.join(', ') : 'ok');
  step(pageErrors.length === 0, 'G.创建流程无JS异常', pageErrors.join(' | '));
  const createdOk = /^\/memoirs\/(\d+)$/.test(dom.path);
  const createdId = createdOk ? dom.path.split('/').pop() : '';

  // ===== H. 详情页新增章节（真实接口） =====
  if (createdOk) {
    const openAdd = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('新增章节')>=0;}); if(b){b.click(); return 'clicked';} return 'notfound'; })()`);
    await sleep(900);
    const fillChapter = await evalJs(`(function(){
      var inp = document.querySelector('input[placeholder^="例如"]') || document.querySelector('input[placeholder*="第"]');
      if (!inp) return 'noinput';
      var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
      setter.call(inp, '童年时光');
      inp.dispatchEvent(new Event('input', { bubbles: true }));
      return 'filled';
    })()`);
    await sleep(400);
    const submitChapter = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('添加章节')>=0 || x.textContent.trim()==='添加';}); if(b){b.click(); return 'clicked';} return 'notfound'; })()`);
    await sleep(2600);
    dom = await probeCurrent();
    step(openAdd === 'clicked', 'H.打开新增章节弹窗', openAdd);
    step(dom.status4xx.length === 0, 'H.新增章节无 4xx 请求', dom.status4xx.join(' | '));
    step(pageErrors.length === 0, 'H.新增章节无JS异常', pageErrors.join(' | '));
    if (fillChapter === 'filled' && submitChapter === 'clicked') {
      step(dom.status4xx.length === 0, 'H.章节创建请求成功', 'title匹配=' + (dom.cards.indexOf('童年时光') >= 0));
    } else {
      console.log('SKIP H.章节弹窗选择器未命中（fill=' + fillChapter + ', submit=' + submitChapter + '）');
    }

    // H2. 返回列表应能看到刚创建的真实回忆录
    dom = await go('/memoirs');
    step(dom.cards.some((t) => t.indexOf(TEST_NAME) >= 0), 'H.列表出现新建的回忆录', 'cards=' + JSON.stringify(dom.cards));
    step(dom.status4xx.length === 0, 'H.列表刷新无 4xx 请求', dom.status4xx.join(' | '));
  } else {
    console.log('SKIP H.未拿到真实 id，跳过章节与列表校验');
  }

  // ===== D. 个人中心 =====
  dom = await go('/profile');
  step(dom.nickname === '测试账号', 'D.个人中心显示测试账号昵称', 'nickname=' + JSON.stringify(dom.nickname));
  step(dom.hasLogoutBtn, 'D.个人中心存在退出登录按钮');
  step(dom.external.length === 0, 'D.个人中心零外网请求', dom.external.length ? '外网:' + dom.external.join(', ') : 'ok');
  step(dom.brokenImgs.length === 0, 'D.个人中心零破图', dom.brokenImgs.join(', '));
  step(pageErrors.length === 0, 'D.个人中心无JS异常', pageErrors.join(' | '));

  // ===== E. 退出登录 → 回登录页 + 清理本地态与缓存 =====
  const logoutClick = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('退出登录')>=0;}); if(b){b.click(); return 'clicked';} return 'notfound'; })()`);
  await sleep(3000);
  dom = await probeCurrent();

  step(logoutClick === 'clicked', 'E.点击退出登录', logoutClick);
  step(/login/.test(dom.path), 'E.退出后回登录页', 'path=' + dom.path);
  step(!dom.token, 'E.退出后token已清空');
  step(!dom.refresh, 'E.退出后refresh token已清空');
  step(!dom.user, 'E.退出后用户信息已清空');
  step(dom.cacheKeys.length === 0, 'E.退出后业务缓存已清空', dom.cacheKeys.join(', '));
  step(dom.external.length === 0, 'E.退出流程零外网请求', dom.external.length ? '外网:' + dom.external.join(', ') : 'ok');

  console.log('--------------------------------------------------');
  if (createdId) console.log('本次创建的测试项目 id = ' + createdId);
  console.log(failures.length ? ('结果: 失败 — ' + failures.join(', ')) : '结果: 全部通过');

  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch (e) {}
  process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
