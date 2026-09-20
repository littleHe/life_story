/**
 * 诊断：新建回忆录向导 DOM 逐步快照
 * 运行：node tests/_dbg_wizard.js
 * 前置：前端 8001 + 后端 9411 已启动
 */
const { spawn } = require('child_process');
const os = require('os');
const path = require('path');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const PORT = 9341;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

const DUMP = `(function(){
  function t(el){ return (el.textContent||'').replace(/\\s+/g,' ').trim().slice(0,24); }
  var btns = Array.prototype.slice.call(document.querySelectorAll('button')).map(function(b){
    return t(b) + (b.disabled ? ' [DISABLED]' : '') + ' type=' + (b.type||'');
  });
  var inputs = Array.prototype.slice.call(document.querySelectorAll('input,textarea')).map(function(i){
    return 'ph=' + JSON.stringify(i.placeholder||'') + ' id=' + (i.id||'') + ' val=' + JSON.stringify((i.value||'').slice(0,12));
  });
  var dlg = Array.prototype.slice.call(document.querySelectorAll('[role="dialog"]')).map(function(d){
    return d.textContent.replace(/\\s+/g,' ').trim().slice(0,160);
  });
  return JSON.stringify({ path: location.pathname, btns: btns, inputs: inputs, dlg: dlg });
})()`;

async function main() {
  const userDataDir = path.join(os.tmpdir(), 'ls_dbg_wizard_' + Date.now());
  const chrome = spawn(CHROME, [
    '--headless=new', '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + userDataDir, '--no-first-run', '--no-default-browser-check',
    '--disable-gpu', '--disable-extensions', '--hide-scrollbars', 'about:blank',
  ], { stdio: 'ignore' });

  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) {
    try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch (e) { await sleep(250); }
  }
  if (!ready) { console.log('Chrome 未就绪'); chrome.kill(); process.exit(1); }

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

  const log = async (label) => {
    const d = JSON.parse(await evalJs(DUMP));
    console.log('\n===== ' + label + ' =====');
    console.log('path: ' + d.path);
    console.log('buttons:'); d.btns.forEach((b) => console.log('   - ' + b));
    console.log('inputs:'); d.inputs.forEach((b) => console.log('   - ' + b));
    console.log('dialogs:'); d.dlg.forEach((b) => console.log('   - ' + b));
  };

  // 登录（走真实登录页）
  await send('Page.navigate', { url: BASE + '/login' });
  await sleep(2600);
  await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('微信一键授权登录')>=0;}); if(b){b.click(); return 1;} return 0; })()`);
  await sleep(3000);

  await send('Page.navigate', { url: BASE + '/memoirs' });
  await sleep(2800);
  await log('1. 列表页初始');

  await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.trim()==='新建';}); if(b){b.click(); return 1;} return 0; })()`);
  await sleep(1200);
  await log('2. 点「新建」后（第一步 基本信息）');

  const fillName = await evalJs(`(function(){
    var inp = document.querySelector('input[placeholder="请输入姓名"]');
    if (!inp) return 'noinput';
    var setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(inp, '端到端测试');
    inp.dispatchEvent(new Event('input', { bubbles: true }));
    return 'filled';
  })()`);
  console.log('\nfillName = ' + fillName);
  await sleep(500);
  await log('3. 填姓名后');

  // 出生年月日已改为必填（年份 1910~2010）：不填则「下一步」保持 disabled
  await evalJs(`(function(){var f=function(l,v){var s=document.querySelector('select[aria-label="'+l+'"]');if(!s)return 'no';Object.getOwnPropertyDescriptor(Object.getPrototypeOf(s),'value').set.call(s,v);s.dispatchEvent(new Event('change',{bubbles:true}));return 'ok';};return [f('年','1965'),f('月','06'),f('日','15')].join(',');})()`);
  const clickNext = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return x.textContent.indexOf('下一步')>=0;}); if(b){b.click(); return 'clicked';} return 'notfound'; })()`);
  console.log('\nclickNext = ' + clickNext);
  await sleep(1500);
  await log('4. 点「下一步」后（第二步 兑换解锁）');

  chrome.kill();
  process.exit(0);
}

main().catch((e) => { console.log('ERR ' + e.message); process.exit(1); });
