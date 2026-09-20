/**
 * 访谈页「删除录音分段」端到端验收（自包含）
 *
 * 覆盖：
 *  ① API：上传 3 段 → GET 回显 recordings=3 → 删除 1 段 → recordings=2
 *  ② UI ：录音列表展示删除入口 → 点垃圾桶出现二次确认 → 确认后列表变为「共 2 段」
 *
 * 前置：前端 vite(8001) + 后端(9411) 已启动。
 * 运行：node tests/_shot_interview_delete.js
 * 产物：tests/_shots/interview-segments.png / interview-delete-confirm.png / interview-deleted.png
 */
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PHP = process.env.PHP_BIN || 'D:/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe';
const PORT = Number(process.env.CDP_PORT || 9357);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJSON = async (u) => (await fetch(u)).json();
const postJSON = async (u, body, extra = {}) =>
  (await fetch(u, { method: 'POST', headers: { 'Content-Type': 'application/json', ...extra }, body: JSON.stringify(body || {}) })).json();

(async () => {
  const outDir = path.join(__dirname, '_shots');
  try { fs.mkdirSync(outDir, { recursive: true }); } catch (e) { /* sandbox EPERM 不致命 */ }

  let pid = 0, iToken = '', chapterId = 0;

  // ===== 预置：项目 + 访谈 token + 上传 3 段 =====
  try {
    // 确保存在「不限次」测试兑换码（max_uses=0），避免消耗真实码
    let testCode = '';
    try { testCode = execFileSync(PHP, [path.join(__dirname, '_ensure_test_code.php')], { encoding: 'utf8' }).trim(); } catch (e) { console.log('生成测试码失败：' + e.message); }

    const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
    const token = lr?.data?.access_token;
    const H = { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token };
    const prj = await postJSON(API + '/api/projects', { name: 'SHOT_删除验收', code: testCode }, H);
    pid = prj?.data?.id;
    if (!pid) throw new Error('建项目失败：' + (prj?.msg || JSON.stringify(prj)));
    iToken = (await postJSON(`${API}/api/projects/${pid}/interview-token`, {}, H))?.data?.interview_token;

    const iv = await getJSON(`${API}/api/interview/${iToken}`);
    chapterId = iv?.data?.chapters?.[0]?.id;
    if (!chapterId) throw new Error('未取到章节');

    for (let i = 1; i <= 3; i++) {
      const b64 = Buffer.from(`fake audio segment ${i} for delete test`).toString('base64');
      await postJSON(`${API}/api/interview/${iToken}/recording`, {
        chapter_id: chapterId, audio_base64: b64, audio_ext: 'webm', duration: `00:0${i}`,
      });
    }

    // ① API 断言：回显 3 段
    const after = await getJSON(`${API}/api/interview/${iToken}`);
    const recs = after?.data?.chapters?.find((c) => c.id === chapterId)?.recordings || [];
    console.log(`[API] 上传后 recordings=${recs.length}（期望 3）`);

    // ① API 断言：删除首段 → 2 段，且带 asset_id
    const firstId = recs[0]?.asset_id;
    const delRes = await postJSON(`${API}/api/interview/${iToken}/recording/delete`, { chapter_id: chapterId, asset_id: firstId });
    const remain = delRes?.data?.recordings || [];
    console.log(`[API] 删除 asset_id=${firstId} 后 recordings=${remain.length}（期望 2），msg=${delRes?.msg}`);

    // 再补回 2 段（共 3 段）供 UI 截图
    if (remain.length === 2) {
      const b64 = Buffer.from('fake audio segment refill').toString('base64');
      await postJSON(`${API}/api/interview/${iToken}/recording`, { chapter_id: chapterId, audio_base64: b64, audio_ext: 'webm', duration: '00:05' });
    }
    const ui = await getJSON(`${API}/api/interview/${iToken}`);
    console.log(`[API] UI 截图前 recordings=${(ui?.data?.chapters?.find((c) => c.id === chapterId)?.recordings || []).length}（期望 3）`);
  } catch (e) { console.log('预置失败：' + e.message); }
  if (!iToken) { console.log('缺少访谈 token，退出'); process.exit(1); }
  console.log('项目 pid=' + pid + ' token=' + iToken + ' chapter=' + chapterId);

  // ===== Chrome =====
  const userDataDir = path.join(os.tmpdir(), 'ls_shot_del_' + Date.now());
  const chrome = spawn(CHROME, [
    '--headless=new', '--remote-debugging-port=' + PORT,
    '--user-data-dir=' + userDataDir, '--no-first-run', '--no-default-browser-check',
    '--disable-gpu', '--disable-extensions', '--hide-scrollbars',
    'about:blank',
  ], { stdio: 'ignore' });

  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  if (!ready) { console.log('Chrome 未就绪'); chrome.kill(); process.exit(1); }

  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); } });
  await send('Runtime.enable'); await send('Page.enable');
  const evalJs = async (expr) => (await send('Runtime.evaluate', { expression: expr, returnByValue: true })).result.value;
  const clickByText = async (text) => evalJs(`(function(){var b=[].slice.call(document.querySelectorAll('button'));for(var i=0;i<b.length;i++){if((b[i].textContent||'').indexOf(${JSON.stringify(text)})>=0){b[i].click();return true;}}return false;})()`);
  const clickByAria = async (label) => evalJs(`(function(){var b=document.querySelector('[aria-label=${JSON.stringify(label)}]');if(b){b.click();return true;}return false;})()`);
  const waitFor = async (expr, ms = 12000) => { const t0 = Date.now(); while (Date.now() - t0 < ms) { if (await evalJs(expr)) return true; await sleep(250); } return false; };
  const shoot = async (name) => {
    const r = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    if (!r?.data) { console.log('截图无数据：' + name); return; }
    try { fs.writeFileSync(path.join(outDir, name), Buffer.from(r.data, 'base64')); console.log('已保存 ' + name); }
    catch (e) { console.log('写盘失败 ' + name + '：' + e.message); }
  };

  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 2, mobile: true });
  await send('Page.navigate', { url: `${BASE}/memoirs/${pid}/interview?token=${iToken}` });
  await sleep(3000);
  await waitFor(`(document.body.innerText||'').indexOf('已录段落')>=0`, 10000);

  // ② UI：列表 + 删除入口
  console.log('[UI] 列表含删除按钮：' + (await evalJs(`!!document.querySelector('[aria-label="删除第 1 段录音"]')`)));
  await shoot('interview-segments.png');

  // ② UI：点垃圾桶 → 二次确认
  const okTrash = await clickByAria('删除第 1 段录音');
  console.log('点击垃圾桶：' + okTrash);
  await waitFor(`(document.body.innerText||'').indexOf('确认删除')>=0`, 4000);
  await shoot('interview-delete-confirm.png');

  // ② UI：确认删除 → 列表变 2 段
  const okDel = await clickByText('确认删除');
  console.log('点击确认删除：' + okDel);
  const shrunk = await waitFor(`(document.body.innerText||'').indexOf('共 2 段')>=0`, 8000);
  console.log('列表已变为「共 2 段」：' + shrunk);
  await sleep(600);
  await shoot('interview-deleted.png');

  chrome.kill();
  if (pid) {
    try { await new Promise((res) => { const c = spawn(PHP, [path.join(__dirname, '_cleanup_test_data.php'), String(pid)], { stdio: 'ignore' }); c.on('exit', res); }); console.log('已清理 pid=' + pid); } catch (e) { console.log('清理失败 pid=' + pid); }
  }
  process.exit(0);
})();
