/**
 * 新建回忆录向导 · 头像 e2e：
 *  1) 点击头像不再自动分配预设头像（回归用户反馈的 bug）
 *  2) 真实选择本地图片后上传成功（CDP DOM.setFileInputFiles）
 *  3) 创建回忆录后头像落库并可展示
 * 运行：node tests/front-e2e-avatar.js
 */
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = 9356;
const IMG = process.env.AVATAR_IMG || 'D:\\Work\\2026\\AiWork\\life_story\\front\\public\\assets\\covers\\cover1.jpg';
const OUT = path.join(__dirname, '_shots');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

;(async () => {
  const failures = [];
  const step = (ok, name, detail) => {
    if (ok) console.log('PASS ' + name + (detail ? '  ' + detail : ''));
    else { failures.push(name); console.log('FAIL ' + name + (detail ? '  → ' + detail : '')); }
  };

  let token = '', uid = 0, pid = 0;
  const netLog = [];
  {
    const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
    token = lr.data.access_token; uid = lr.data.uid;
    step(!!token, '登录', 'uid=' + uid);
  }

  const userDataDir = path.join(os.tmpdir(), 'ls_av_e2e_' + Date.now());
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
  await send('Emulation.setDeviceMetricsOverride', { width: 480, height: 960, deviceScaleFactor: 2, mobile: true });

  await send('Page.navigate', { url: BASE + '/' });
  await sleep(1800);
  await evalJs(`localStorage.setItem('ls_access_token', ${JSON.stringify(token)}); localStorage.setItem('ls_user_info', JSON.stringify({uid:${uid || 0},nickname:'测试账号'})); 'ok'`);
  await send('Page.navigate', { url: BASE + '/memoirs' });
  await sleep(2800);

  // 打开向导
  await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.trim()==='新建';}); if(b) b.click(); return 'ok'; })()`);
  await sleep(1200);

  const AV_PROBE = `(function(){
    var dlg = document.querySelector('[role=dialog]') || document.body;
    var imgs = [].slice.call(dlg.querySelectorAll('img')).map(function(i){return i.getAttribute('src')||'';});
    var avatarImg = imgs.find(function(s){ return s.indexOf('/uploads/avatar/') === 0 || s.indexOf('/assets/avatars/') === 0; });
    return JSON.stringify({ avatarImg: avatarImg || '', hasPreset: imgs.some(function(s){ return s.indexOf('/assets/avatars/') === 0; }) });
  })()`;

  let d = JSON.parse(await evalJs(AV_PROBE));
  step(d.avatarImg === '' && d.hasPreset === false, '打开向导时头像为空（无预设头像）', JSON.stringify(d));

  // 1) 回归：点击头像不再自动出现头像
  await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('[role=dialog] button, body button')).find(function(x){return x.textContent.indexOf('上传头像')>=0 || (x.querySelector && x.querySelector('.rounded-full'));}); if(b) b.click(); return 'ok'; })()`);
  await sleep(900);
  d = JSON.parse(await evalJs(AV_PROBE));
  step(d.avatarImg === '' && d.hasPreset === false, '点击头像不会自动出现头像（需自行选择文件）', JSON.stringify(d));

  // 2) 用 DataTransfer 给隐藏 input 注入真实 File 并派发 change（比 CDP setFileInputFiles 可靠）
  const setFile = await (async () => {
    const r = await send('Runtime.evaluate', {
      expression: `(async function(){
        var inp = document.querySelector('input[type="file"][accept="image/*"]');
        if(!inp) return 'noinput';
        var resp = await fetch('${BASE}/assets/covers/cover1.jpg');
        if(!resp.ok) return 'fetchfail:'+resp.status;
        var blob = await resp.blob();
        var file = new File([blob], 'cover1.jpg', { type: 'image/jpeg' });
        var dt = new DataTransfer();
        dt.items.add(file);
        try { inp.files = dt.files; } catch(e) { return 'setfail:'+e.message; }
        inp.dispatchEvent(new Event('change', { bubbles: true }));
        return 'files=' + inp.files.length;
      })()`,
      returnByValue: true,
      awaitPromise: true,
    });
    return (r.result && r.result.value) || 'err';
  })();
  await sleep(5000);
  d = JSON.parse(await evalJs(AV_PROBE));
  step(typeof setFile === 'string' && setFile.indexOf('files=') === 0, '已通过文件选择器设置本地图片', setFile);
  const avOk = d.avatarImg.indexOf('/uploads/avatar/') === 0 || d.avatarImg.indexOf('://127.0.0.1:8001/uploads/avatar/') >= 0 || d.avatarImg.indexOf('://127.0.0.1/uploads/avatar/') >= 0;
  // 轮询最多 8s，捕捉上传完成或 toast 报错
  let toastTxt = '';
  for (let i = 0; i < 16 && !avOk; i++) {
    await sleep(500);
    d = JSON.parse(await evalJs(AV_PROBE));
    toastTxt = await evalJs(`(function(){ var t=document.querySelector('[data-sonner-toast], .sonner-toast, [role=status]'); return t? (t.textContent||'').slice(0,60):''; })()`);
    if (d.avatarImg.indexOf('/uploads/avatar/') === 0) break;
  }
  step(avOk, '上传成功，头像预览指向后端独立地址', d.avatarImg + ' | toast=' + toastTxt);
  let shot = await send('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(path.join(OUT, 'avatar-uploaded.png'), Buffer.from(shot.data, 'base64'));

  // 3) 填姓名 → 下一步 → 创建并解锁（核销码可选，409 不影响落库）
  await evalJs(`(function(){
    var inp = document.querySelector('input[placeholder="请输入姓名"]');
    if (!inp) return 'noinput';
    var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(inp, 'E2E_头像上传');
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    return 'filled';
  })()`);
  await sleep(400);
  // 出生年月日已改为必填（年份 1910~2010）：不填则「下一步」保持 disabled
  await evalJs(`(function(){var f=function(l,v){var s=document.querySelector('select[aria-label="'+l+'"]');if(!s)return 'no';var st=Object.getOwnPropertyDescriptor(Object.getPrototypeOf(s),'value').set;st.call(s,v);s.dispatchEvent(new Event('change',{bubbles:true}));return 'ok';};return [f('年','1965'),f('月','06'),f('日','15')].join(',');})()`);
  await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('下一步')>=0;}); if(b) b.click(); return 'ok'; })()`);
  await sleep(1200);
  await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('创建并解锁')>=0;}); if(b) b.click(); return 'ok'; })()`);
  await sleep(4000);
  const pathNow = await evalJs('location.pathname');
  const m = /^\/memoirs\/(\d+)$/.exec(pathNow || '');
  step(!!m, '创建回忆录并跳转真实 id 详情页', 'path=' + pathNow);
  if (m) {
    pid = m[1];
    const proj = await (await fetch(API + '/api/projects/' + pid, { headers: { Authorization: 'Bearer ' + token } })).json();
    const av = proj?.data?.avatar || '';
    step(av.indexOf('/uploads/avatar/') === 0, '项目头像已落库（用户自己上传的图）', av);
    shot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(path.join(OUT, 'avatar-detail.png'), Buffer.from(shot.data, 'base64'));
  }
  step(errs.length === 0, '全程无 JS 异常', errs.slice(-2).join(' | '));

  console.log('--------------------------------------------------');
  console.log('SHOTS=' + OUT);
  console.log('CLEANUP pid=' + (pid || ''));
  console.log(failures.length ? ('结果: 失败 — ' + failures.join(', ')) : '结果: 全部通过');
  ws.close(); chrome.kill();
  await sleep(300);
  try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch { /* ignore */ }
  process.exit(failures.length ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
