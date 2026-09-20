/**
 * 「专属访谈链接」弹窗 · 端到端验证（自包含，无第三方依赖）
 *
 * 覆盖：
 *  - 详情页「生成访谈链接」→ 弹出分享框，链接含 ?token=
 *  - 弹窗内新增「重新生成」按钮 + 使用说明「旧链接会立即失效」文案
 *  - 点「重新生成」（确认）→ 链接换新，旧 token 立即 404 失效、新 token 可用
 *
 * 前置：前端 vite（8001）与后端（9411）已启动。
 * 运行：node tests/front-e2e-interview-link.js
 * 退出码：0 = 全部通过；1 = 存在失败项
 */
const { spawn } = require('child_process');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = 9343;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

let collect = false;
let pageErrors = [];
function resetPhase() { pageErrors = []; }
function track(m) {
  if (!collect) return;
  if (m.method === 'Runtime.exceptionThrown') {
    const d = m.params.exceptionDetails;
    pageErrors.push(((d.exception && (d.exception.description || d.exception.value)) || d.text || '').split('\n')[0]);
  }
}

;(async () => {
  const failures = [];
  const step = (ok, name, detail) => {
    if (ok) console.log('PASS ' + name + (detail ? '  ' + detail : ''));
    else { failures.push(name); console.log('FAIL ' + name + (detail ? '  → ' + detail : '')); }
  };

  // ===== 后端预置：登录 + 建一个「采集中」项目（带章节） =====
  let token = '', uid = 0, pid = 0;
  try {
    const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
    token = lr?.data?.access_token;
    uid = lr?.data?.uid;
    const api = async (p, opt = {}) => {
      const res = await fetch(API + '/api' + p, {
        method: opt.method || 'GET',
        headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token },
        body: opt.body ? JSON.stringify(opt.body) : undefined,
      });
      return { status: res.status, json: await res.json() };
    };
    const pr = await api('/projects', { method: 'POST', body: { name: 'E2E_访谈链接_' + Date.now(), code: '66666688' } });
    pid = pr.json?.data?.id;
    await api(`/projects/${pid}/chapters`, { method: 'PATCH', body: { action: 'add', title: '链接测试章节' } });
    step(!!token && !!pid, '后端预置：登录 + 采集中项目', `pid=${pid} uid=${uid}`);
  } catch (e) {
    step(false, '后端预置', e.message);
  }
  if (!pid) { console.log('\nFAIL 缺少项目，无法继续'); process.exit(1); }

  const userDataDir = path.join(os.tmpdir(), 'ls_ivlink_e2e_' + Date.now());
  const chrome = spawn(CHROME, [
    '--headless=new', '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + userDataDir, '--no-first-run', '--no-default-browser-check',
    '--disable-gpu', '--disable-extensions', '--hide-scrollbars', 'about:blank',
  ], { stdio: 'ignore' });

  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) {
    try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); }
  }
  if (!ready) { console.log('FAIL Chrome 未就绪'); chrome.kill(); process.exit(1); }

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
    track(m);
  });
  await send('Runtime.enable');
  await send('Page.enable');

  async function evalJs(expr) {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    return r.result ? r.result.value : undefined;
  }
  async function go(url, wait = 2600) {
    resetPhase();
    await send('Page.navigate', { url: /^https?:/.test(url) ? url : BASE + url });
    await sleep(wait);
    return evalJs(PROBE);
  }
  async function clickByText(text) {
    const r = await send('Runtime.evaluate', {
      expression: `(function(){var b=[].slice.call(document.querySelectorAll('button'));for(var i=0;i<b.length;i++){if((b[i].textContent||'').indexOf(${JSON.stringify(text)})>=0){b[i].click();return true;}}return false;})()`,
      returnByValue: true,
    });
    return r.result ? r.result.value : false;
  }
  // 弹窗内按钮文字 / 链接值 / 弹窗全文
  const PROBE = `(function(){
    var dlg = document.querySelector('[role="dialog"]');
    var btns = dlg ? Array.prototype.slice.call(dlg.querySelectorAll('button')) : [];
    var inp = dlg ? dlg.querySelector('input') : null;
    return JSON.stringify({
      path: location.pathname,
      hasDialog: !!dlg,
      dialogButtons: btns.map(function(b){ return (b.textContent||'').trim(); }),
      linkValue: inp ? inp.value : '',
      dialogText: dlg ? (dlg.textContent||'') : ''
    });
  })()`;

  try {
    // 1) 注入登录态（同源后再写 localStorage）
    await go('/login', 2200);
    const userJson = JSON.stringify({ uid: uid || 0, nickname: '测试', isTest: true });
    await evalJs(`try{
      localStorage.setItem('ls_access_token', ${JSON.stringify(token)});
      localStorage.setItem('ls_refresh_token', ${JSON.stringify(token)});
      localStorage.setItem('ls_user_info', ${JSON.stringify(userJson)});
      'ok';
    }catch(e){ 'err'+e.message }`);
    const injected = await evalJs(`localStorage.getItem('ls_access_token') ? 'yes' : 'no'`);
    step(injected === 'yes', '注入登录态 token');

    // 2) 打开发起页（详情页）
    let dom = JSON.parse(await go(`/memoirs/${pid}`, 3200));
    step(dom.path.indexOf(`/memoirs/${pid}`) >= 0, '进入回忆录详情页', 'path=' + dom.path);

    // 3) 点「生成访谈链接」
    const openedGen = await clickByText('生成访谈链接');
    step(openedGen === true, '点击「生成访谈链接」按钮');
    await sleep(1800);
    dom = JSON.parse(await evalJs(PROBE));

    step(dom.hasDialog === true, '弹出「专属访谈链接」分享框');
    step(/\?token=/.test(dom.linkValue), '分享链接含 ?token=', 'url=' + dom.linkValue);
    step(dom.dialogButtons.some((b) => b.indexOf('重新生成') >= 0), '弹窗出现「重新生成」按钮', JSON.stringify(dom.dialogButtons));
    step(/(旧链接会立即失效|旧链接.*失效)/.test(dom.dialogText), '使用说明含「旧链接会立即失效」');

    const oldToken = (dom.linkValue.match(/token=([0-9a-f]+)/) || [])[1] || '';

    // 4) 点「重新生成」（自动确认弹窗）
    await evalJs('window.confirm = function(){ return true; }; "ok"');
    const clickedRegen = await clickByText('重新生成');
    step(clickedRegen === true, '点击「重新生成」按钮');
    await sleep(2200);
    const after = JSON.parse(await evalJs(PROBE));
    const newToken = (after.linkValue.match(/token=([0-9a-f]+)/) || [])[1] || '';
    step(!!newToken && newToken !== oldToken, '链接已换成新 token', `old=${oldToken.slice(0, 8)}… new=${newToken.slice(0, 8)}…`);

    // 5) 旧 token 立即失效，新 token 可用（后端直连）
    const oldRes = await fetch(`${API}/api/interview/${oldToken}`);
    step(oldRes.status === 404, '旧 token 已失效(404)', 'http=' + oldRes.status);
    const newRes = await fetch(`${API}/api/interview/${newToken}`);
    step(newRes.status === 200, '新 token 可访问(200)', 'http=' + newRes.status);

    // 6) 无 JS 异常
    step(pageErrors.length === 0, '无页面 JS 异常', pageErrors.join(' | '));
  } catch (e) {
    step(false, '测试执行异常', e.message);
  }

  console.log('');
  const failed = failures.length;
  console.log(failed ? ('FAILED — ' + failed + ' 项失败: ' + failures.join(' / ')) : 'ALL PASS');
  try { ws.close(); } catch { /* ignore */ }
  chrome.kill();
  // 测试项目留待 _cleanup_test_data.php 清理
  console.log('test project pid=' + pid);
  process.exit(failed ? 1 : 0);
})();
