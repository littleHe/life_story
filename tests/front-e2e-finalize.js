/**
 * 定稿流程 + 翻书预览 · 端到端验证（自包含，无第三方依赖）
 *
 * 覆盖：
 *  - 定稿前「生成访谈链接」为高亮(secondary)且可用
 *  - 点击「提交定稿」→ 状态变「回忆制作中」并落库(status=MAKING)
 *  - 定稿后「生成访谈链接」不再高亮(outline)且禁用
 *  - 出现「欣赏回忆录」入口
 *  - 翻书预览页可公开打开、含封面(基本信息+简介)与章节页，可翻页
 *
 * 运行：node tests/front-e2e-finalize.js
 * 退出码：0 全部通过
 */
const { spawn, execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const CHROME = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_PATH || 'D:/phpstudy_pro/Extensions/MySQL8.0.12/bin/mysql.exe';
const BASE = process.env.FRONT_BASE || 'http://127.0.0.1:8001';
const API = process.env.BACK_BASE || 'http://127.0.0.1:9411';
const PORT = 9341;
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

  // ===== 后端预置：项目 + 章节 + 录音 + 转写 + 保存；并置为 EDITABLE（采集中）=====
  let token = '', uid = 0, pid = 0;
  const H = () => ({ 'Content-Type': 'application/json', Authorization: 'Bearer ' + token });
  const api = async (p, m = 'GET', b) => (await fetch(API + '/api' + p, { method: m, headers: H(), body: b ? JSON.stringify(b) : undefined })).json();
  try {
    const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
    token = lr?.data?.access_token; uid = lr?.data?.uid;
    pid = (await api('/projects', 'POST', {
      name: 'E2E_定稿翻书', real_name: '张丽丽', birth: '1950-06-15', native_place: '广东清远',
      description: '一位普通母亲的一生，平凡而温暖，值得被认真记录。', code: '66666688',
    })).data.id;
    // 新建项目会按后台「默认章节」自动播种系统章节，先清掉，否则页码会从 9 页起算、断言全部偏移
    const seeded = (await api(`/projects/${pid}/chapters`, 'GET')).data || [];
    for (const c of seeded) {
      await api(`/projects/${pid}/chapters`, 'PATCH', { action: 'remove', chapter_id: c.id });
    }
    const cid = (await api(`/projects/${pid}/chapters`, 'PATCH', { action: 'add', title: '第一章 童年' })).data.chapter_id;
    const s1 = (await api(`/chapters/${cid}/recording`, 'POST', { audio_base64: b64('aa'), audio_ext: 'webm', duration: '00:06' })).data;
    await api(`/chapters/${cid}/asr`, 'POST', { asset_id: s1.asset_id });
    await api(`/chapters/${cid}/save`, 'POST', { title: '第一章 童年', transcript: '我出生在小山村，童年的记忆里有炊烟和晚霞。', polished: '山村的炊烟与晚霞，是我童年最柔软的记忆。' });
    // 置为 EDITABLE，模拟「采集中」状态（以便验证定稿前的按钮高亮）
    execSync(`"${MYSQL}" -h127.0.0.1 -P3306 -uroot -proot life_story -e "UPDATE ls_project SET status='EDITABLE' WHERE id=${pid}"`, { stdio: 'ignore' });
    step(!!pid, '后端预置项目/章节/转写，并置为采集中', `pid=${pid}`);
  } catch (e) {
    step(false, '后端预置', e.message);
  }

  const userDataDir = path.join(os.tmpdir(), 'ls_fin_e2e_profile');
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=' + PORT, '--user-data-dir=' + userDataDir, '--no-first-run', '--disable-gpu', '--hide-scrollbars', '--window-size=420,940', 'about:blank'], { stdio: 'ignore' });
  let ready = null;
  for (let i = 0; i < 40 && !ready; i++) { try { ready = await getJSON(`http://127.0.0.1:${PORT}/json/version`); } catch { await sleep(250); } }
  if (!ready) { console.log('FAIL Chrome 未就绪'); chrome.kill(); process.exit(1); }
  const targets = await getJSON(`http://127.0.0.1:${PORT}/json/list`);
  const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
  let id = 0; const pending = new Map();
  const send = (method, params = {}) => new Promise((res) => { const mid = ++id; pending.set(mid, res); ws.send(JSON.stringify({ id: mid, method, params })); });
  await new Promise((r) => ws.addEventListener('open', r));
  ws.addEventListener('message', (ev) => { const m = JSON.parse(ev.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m.result); pending.delete(m.id); } });
  await send('Runtime.enable'); await send('Page.enable');
  const evalJs = async (e) => (await send('Runtime.evaluate', { expression: e, returnByValue: true })).result.value;

  // 截图：写盘失败（沙箱 EPERM）不算断言失败，绝不能中断整个用例
  const shot = async (name) => {
    const s = await send('Page.captureScreenshot', { format: 'png' });
    if (s && s.data) { try { fs.writeFileSync(path.join(OUT, name), Buffer.from(s.data, 'base64')); } catch { /* ignore */ } }
  };
  await send('Emulation.setDeviceMetricsOverride', { width: 420, height: 940, deviceScaleFactor: 2, mobile: true });

  await send('Page.navigate', { url: BASE + '/' });
  await sleep(1500);
  await evalJs(`localStorage.setItem('ls_access_token', ${JSON.stringify(token)}); localStorage.setItem('ls_user_info', JSON.stringify({uid:${uid || 0},nickname:'测试账号'})); 'ok'`);
  await send('Page.navigate', { url: BASE + '/memoirs/' + pid });
  await sleep(3400);

  const PROBE = `(function(){
    var btns = [].slice.call(document.querySelectorAll('button'));
    var t = function(b){return (b.textContent||'').trim();};
    var link = btns.find(function(b){return t(b)==='生成访谈链接';});
    var prev = btns.find(function(b){return (b.textContent||'').indexOf('欣赏回忆录')>=0;});
    var fin  = btns.find(function(b){return (b.textContent||'').indexOf('提交定稿')>=0 || (b.textContent||'').indexOf('回忆制作中')>=0;});
    var badge = '';
    var nodes = document.querySelectorAll('span,div');
    for (var i=0;i<nodes.length;i++){
      var tx=(nodes[i].textContent||'').trim();
      if (tx==='回忆制作中' || tx==='已完成' || tx==='采集中' || tx==='待采集'){ badge=tx; break; }
    }
    return JSON.stringify({
      hasLink: !!link, linkDisabled: link? !!link.disabled : null,
      linkSecondary: link? /bg-secondary/.test(link.className) : null,
      hasPreview: !!prev, finText: fin? t(fin) : '', badge: badge
    });
  })()`;

  let d = JSON.parse(await evalJs(PROBE));
  step(d.hasLink && d.linkDisabled === false, '定稿前「生成访谈链接」可用', 'disabled=' + d.linkDisabled);
  step(d.linkSecondary === true, '定稿前「生成访谈链接」高亮(secondary)', 'secondary=' + d.linkSecondary);
  step(d.hasPreview === false, '定稿前无「欣赏回忆录」入口');
  await shot('finalize-before.png');

  // 滚动到「提交定稿」并点击
  const clickFin = await evalJs(`(function(){ var b=[].slice.call(document.querySelectorAll('button')).find(function(x){return (x.textContent||'').indexOf('提交定稿')>=0;}); if(b){b.click(); return 'clicked';} return 'notfound'; })()`);
  await sleep(2600);
  d = JSON.parse(await evalJs(PROBE));
  step(clickFin === 'clicked', '点击「提交定稿」', clickFin);
  step(d.badge === '回忆制作中', '状态徽章变为「回忆制作中」', 'badge=' + JSON.stringify(d.badge));
  step(d.linkDisabled === true, '定稿后「生成访谈链接」禁用', 'disabled=' + d.linkDisabled);
  step(d.linkSecondary === false, '定稿后「生成访谈链接」不再高亮(outline)', 'secondary=' + d.linkSecondary);
  step(d.hasPreview === true, '出现「欣赏回忆录」入口');
  step(d.finText === '回忆制作中', '底部按钮变为「回忆制作中」', 'fin=' + JSON.stringify(d.finText));

  await evalJs(`window.scrollTo(0,0); 'ok'`); await sleep(400);
  await shot('finalize-after.png');

  // 落库校验：状态 MAKING + preview_url
  const proj = (await api(`/projects/${pid}`)).data;
  step(proj.status === 'MAKING', '项目状态已落库为 MAKING', 'status=' + proj.status);
  step(!!proj.preview_url && proj.preview_url.indexOf('/preview/') >= 0, '后端已生成预览地址', proj.preview_url);
  step(!!proj.cover_bg && proj.cover_bg.indexOf('/uploads/cover-bg/') >= 0, '未上传封面 → 已随机选封面背景', proj.cover_bg);

  // 打开翻书预览页（直接在后端地址）
  await send('Page.navigate', { url: proj.preview_url });
  await sleep(2600);
  const pv = await evalJs(`(function(){
    return JSON.stringify({
      pages: document.querySelectorAll('.page').length,
      counter: document.getElementById('counter') ? document.getElementById('counter').textContent : '',
      coverTitle: document.querySelector('.cover .ctitle') ? document.querySelector('.cover .ctitle').textContent : '',
      hasReal: document.body.innerText.indexOf('张丽丽') >= 0,
      hasDesc: document.body.innerText.indexOf('一位普通母亲的一生') >= 0,
      hasChapter: document.body.textContent.indexOf('第一章 童年') >= 0
    });
  })()`);
  const p = JSON.parse(pv);
  step(p.pages >= 2, '预览页含 ≥2 页(封面+章节)', 'pages=' + p.pages);
  step(p.coverTitle.indexOf('E2E_定稿翻书') >= 0, '封面显示书名', 'title=' + p.coverTitle);
  step(p.hasReal && p.hasDesc, '封面含基本信息与简介', 'real=' + p.hasReal + ' desc=' + p.hasDesc);
  step(p.hasChapter === true, '含章节页内容');
  await shot('preview-cover.png');
  // 翻一页
  await evalJs(`(function(){ var b=document.getElementById('btnNext'); if(b) b.click(); return 'ok'; })()`);
  await sleep(1400);
  const c2 = await evalJs(`document.getElementById('counter') ? document.getElementById('counter').textContent : ''`);
  step(c2.indexOf('2') >= 0, '点击「下一页」翻到第 2 页', 'counter=' + c2);
  await shot('preview-chapter.png');

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
