// 预览页「背景音乐」真实行为验证（CDP）
//
// 验证四件事（都是曾经出问题的地方）：
//   ① <audio id="bgmAudio"> 能被脚本取到（曾经它写在 <script> 之后 → 取到 null → 永远不出声）
//   ② 无手势时不播、**任意首次手势后自动起播**（浏览器自动播放策略）—— 用 ?gate=off 关掉进门蒙层单独验
//   ③ 「音乐」按钮的真实语义：未播放→点击播放；播放中→点击静音（曾经初始状态就是"开"，一点反而关掉）
//   ④ 默认（带蒙层）时：蒙层期间不出声，点「开始欣赏」后 BGM 起播
//
// 用法：node tests/_dbg_preview_bgm.js [preview_token]
//   不传 token 则自动从 ls_project 取最近一个 preview_token
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BACK = 'http://127.0.0.1:9411';
const PORT = Number(process.env.CDP_PORT || 9347);
const MYSQL = 'D:\\phpstudy_pro\\Extensions\\MySQL8.0.12\\bin\\mysql.exe';
const OUT_DIR = process.env.SHOT_DIR || path.join(__dirname, '_shots');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();

function tokenFromDb() {
  try {
    const out = execFileSync(MYSQL, ['-h127.0.0.1', '-uroot', '-proot', 'life_story', '-N', '-e',
      "select preview_token from ls_project where preview_token<>'' order by id desc limit 1;"]).toString().trim();
    return out;
  } catch (e) { return ''; }
}

let pass = 0, fail = 0;
const step = (ok, name, extra = '') => {
  console.log(`  ${ok ? '✅' : '❌'} ${name}${extra ? '  ' + extra : ''}`);
  ok ? pass++ : fail++;
};

;(async () => {
  const token = process.argv[2] || tokenFromDb();
  if (!token) { console.log('拿不到 preview_token：先在页面提交定稿，或手动传入'); process.exit(1); }
  const url = `${BACK}/preview/${token}`;
  console.log(`预览页: ${url}\n`);

  const userDataDir = path.join(os.tmpdir(), 'ls_bgm_profile');
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + userDataDir, '--no-first-run', '--disable-gpu', '--window-size=1280,860', 'about:blank'],
    { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); } });
  await send('Runtime.enable'); await send('Page.enable');

  // ②③ 段用 ?gate=off：关掉进门蒙层，单独验证「手势驱动 BGM」的老语义
  const urlNoGate = url + (url.indexOf('?') >= 0 ? '&' : '?') + 'gate=off';
  await send('Page.navigate', { url: urlNoGate });
  await sleep(2600);

  const evalJs = async (expr, userGesture = false) =>
    (await send('Runtime.evaluate', { expression: expr, returnByValue: true, userGesture })).result.value;

  const probe = () => evalJs(`(function(){
    var a = document.getElementById('bgmAudio'), b = document.getElementById('btnBgm');
    return JSON.stringify({
      hasEl: !!a,
      src: a ? (a.getAttribute('src') || '') : '',
      paused: a ? a.paused : null,
      readyState: a ? a.readyState : null,
      vol: a ? a.volume : null,
      loop: a ? a.loop : null,
      scriptRan: !!(b && b.title && b.title !== '背景音乐'),
      btnOn: b ? b.classList.contains('on') : null,
      btnTitle: b ? b.title : null
    });
  })()`);

  console.log('— 载入后（尚无任何用户手势）—');
  const s0 = JSON.parse(await probe());
  console.log('  ' + JSON.stringify(s0));
  step(s0.hasEl === true, '脚本能取到 <audio id="bgmAudio">（非 null）');
  step(/\.mp3$/.test(s0.src), '音频源已随机指向 mp3', s0.src);
  step(s0.loop === true, 'loop 循环播放已开启');
  step(Number(s0.vol) > 0 && Number(s0.vol) <= 0.3, '音量在低音量档（避免盖过人声）', 'vol=' + s0.vol);
  step(s0.paused === true, '无手势时保持未播放（符合浏览器自动播放策略）');
  step(s0.scriptRan === true, 'BGM 脚本段已执行（按钮提示被 JS 改写）', s0.btnTitle);

  console.log('— 模拟「任意首次手势」（在页面空白处按下鼠标）—');
  await send('Input.dispatchMouseEvent', { type: 'mousePressed', x: 640, y: 430, button: 'left', clickCount: 1 });
  await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: 640, y: 430, button: 'left', clickCount: 1 });
  await sleep(1500);
  const s1 = JSON.parse(await probe());
  console.log('  ' + JSON.stringify(s1));
  step(s1.paused === false, '首次手势后自动开始播放（默认播放生效）');
  step(s1.btnOn === true, '按钮高亮状态由真实播放状态驱动');

  console.log('— 点击「音乐」按钮（应静音）—');
  const rect = JSON.parse(await evalJs(`(function(){var b=document.getElementById('btnBgm');var r=b.getBoundingClientRect();return JSON.stringify({x:Math.round(r.left+r.width/2),y:Math.round(r.top+r.height/2)});})()`));
  const clickAt = async (x, y) => {
    await send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
    await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
  };
  await clickAt(rect.x, rect.y);
  await sleep(700);
  const s2 = JSON.parse(await probe());
  console.log('  ' + JSON.stringify(s2));
  step(s2.paused === true, '播放中点击按钮 → 静音');
  step(s2.btnOn === false, '静音后按钮取消高亮');
  step(/关闭/.test(s2.btnTitle || ''), '按钮提示文案同步为"已关闭"', s2.btnTitle);

  console.log('— 再点一次（应恢复播放）—');
  await clickAt(rect.x, rect.y);
  await sleep(900);
  const s3 = JSON.parse(await probe());
  console.log('  ' + JSON.stringify(s3));
  step(s3.paused === false, '再次点击 → 恢复播放');
  step(s3.btnOn === true, '恢复后按钮重新高亮');

  console.log('— 默认进入（带进门蒙层）：蒙层期间不出声，点「开始欣赏」后起播 —');
  await send('Page.navigate', { url });
  await sleep(2400);
  const g0 = JSON.parse(await probe());
  const gateShown = await evalJs("(function(){var g=document.getElementById('gate');return !!g && getComputedStyle(g).display!=='none';})()");
  step(gateShown === true, '进入时显示进门蒙层');
  step(g0.paused === true, '蒙层期间背景音乐不出声（等用户点击）');
  const gb = JSON.parse(await evalJs("(function(){var b=document.getElementById('gateBtn');var r=b.getBoundingClientRect();return JSON.stringify({x:Math.round(r.left+r.width/2),y:Math.round(r.top+r.height/2)});})()"));
  await clickAt(gb.x, gb.y);
  await sleep(1600);
  const g1 = JSON.parse(await probe());
  step(g1.paused === false, '点「开始欣赏」后背景音乐起播');
  step(g1.btnOn === true, '起播后「音乐」按钮高亮');

  const shot = await send('Page.captureScreenshot', { format: 'png' });
  const f = path.join(OUT_DIR, 'preview-bgm.png');
  try { fs.writeFileSync(f, Buffer.from(shot.data, 'base64')); console.log('\n截图: ' + f); } catch { console.log('\n截图写入被沙箱拒绝（不影响结论）'); }

  console.log(`\n========== 结果：${pass} 通过 / ${fail} 失败 ==========`);
  try { ws.close(); } catch { /* ignore */ }
  try { chrome.kill(); } catch { /* ignore */ }
  // 不用 fs.rmSync 清 profile：沙箱下递归删除会被强杀，连摘要都打不出来
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
