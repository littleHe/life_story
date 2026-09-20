// 冒烟：章节录音引导接口（/api/chapters/:id/guidance）
// 验证 seed（无录音→给切入点）与 continue（带最后一段转写→给续接方向）两种模式，mock 文案兜底。
// 注：本环境 ls_project 表缺少 gender/avatar/description 等列（与 install.sql 漂移），
// 故直接插入最小可用项目+章节用于验证，不依赖 ProjectController::save 的完整建表流程。
import { execSync } from 'node:child_process';

const API = 'http://127.0.0.1:9411';
const MYSQL = 'D:\\phpstudy_pro\\Extensions\\MySQL8.0.12\\bin\\mysql.exe';

function mysql(sql) {
  // 本环境 ls_user 为空且存在 user_id 外键，验证用临时数据需临时关闭外键检查（仅本脚本内）
  return execSync(`${MYSQL} -h127.0.0.1 -uroot -proot life_story -e "SET FOREIGN_KEY_CHECKS=0; ${sql.replace(/"/g, '\\"')}"`, { stdio: 'pipe' })
    .toString();
}
function parseId(out) {
  const m = out.split('\n').map((s) => s.trim()).filter((s) => /^\d+$/.test(s));
  return m.length ? parseInt(m[m.length - 1], 10) : null;
}

let pass = 0, fail = 0;
const step = (ok, msg, extra = '') => {
  if (ok) { pass++; console.log('  PASS', msg, extra); }
  else { fail++; console.log('  FAIL', msg, extra); }
};

async function call(path, opt = {}) {
  const res = await fetch(API + '/api' + path, {
    method: opt.method || 'GET',
    headers: { 'Content-Type': 'application/json', ...(opt.headers || {}) },
    body: opt.body ? JSON.stringify(opt.body) : undefined,
  });
  return { status: res.status, json: await res.json().catch(() => ({})) };
}

(async () => {
  const ts = Date.now();
  const pname = 'SMOKE_引导_' + ts;
  const ctitle = '童年时光_' + ts;

  // 先登录拿真实 uid：测试账号 id 会随库重建变化，硬编码会导致章节归属校验 403
  const lr = await (await fetch(API + '/api/auth/test/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).json();
  const token = lr?.data?.access_token;
  const H = { 'Content-Type': 'application/json', Authorization: 'Bearer ' + token };
  step(!!token, '测试账号登录拿到 token');
  const uid = JSON.parse(Buffer.from(String(token).split('.')[1], 'base64').toString('utf8')).uid;
  step(!!uid, '从 token 解析出 uid', 'uid=' + uid);

  // 直接插入最小项目和章节（归属当前测试账号）
  mysql(`INSERT INTO ls_project (user_id,name,real_name,status,created_at) VALUES (${uid},'${pname}','测试传主','EDITABLE',NOW())`);
  const pid = parseId(mysql(`SELECT id FROM ls_project WHERE name='${pname}' ORDER BY id DESC LIMIT 1`));
  step(!!pid, '插入测试项目', 'pid=' + pid);
  mysql(`INSERT INTO ls_chapter (project_id,title,sort,source,created_at) VALUES (${pid},'${ctitle}',0,'CUSTOM',NOW())`);
  const cid = parseId(mysql(`SELECT id FROM ls_chapter WHERE project_id=${pid} AND title='${ctitle}' ORDER BY id DESC LIMIT 1`));
  step(!!cid, '插入测试章节', 'cid=' + cid);

  // 1) seed：不传 last_transcript → 模式应为 seed，给出切入点
  const seed = await call(`/chapters/${cid}/guidance`, { method: 'POST', headers: H, body: {} });
  step(seed.status === 200, 'seed 引导接口 200', 'status=' + seed.status);
  step(seed.json?.data?.mode === 'seed', '无录音时 mode=seed', 'mode=' + seed.json?.data?.mode);
  step(!!(seed.json?.data?.guidance || '').trim(), 'seed 返回非空引导文案', JSON.stringify((seed.json?.data?.guidance || '').slice(0, 20)) + '…');

  // 2) continue：带最后一段转写 → 模式应为 continue，结合已讲内容
  const last = '我小时候住在河边，夏天常去摸鱼虾，邻居家的阿黄总跟着我。';
  const cont = await call(`/chapters/${cid}/guidance`, { method: 'POST', headers: H, body: { last_transcript: last } });
  step(cont.status === 200, 'continue 引导接口 200', 'status=' + cont.status);
  step(cont.json?.data?.mode === 'continue', '有录音时 mode=continue', 'mode=' + cont.json?.data?.mode);
  step(!!(cont.json?.data?.guidance || '').trim(), 'continue 返回非空引导文案', JSON.stringify((cont.json?.data?.guidance || '').slice(0, 20)) + '…');

  // 3) 回源：写一条带文字的 RECORDING 资源，不传参也应得到 continue
  mysql(`INSERT INTO ls_chapter_asset (chapter_id,asset_type,oss_key,meta,created_at) VALUES (${cid},'RECORDING','','{"seq":1,"duration":"00:01","text":"${last}"}',NOW())`);
  const cont2 = await call(`/chapters/${cid}/guidance`, { method: 'POST', headers: H, body: {} });
  step(cont2.json?.data?.mode === 'continue', '录音落库后回源得到 continue', 'mode=' + cont2.json?.data?.mode);

  // 清理
  mysql(`DELETE FROM ls_chapter_asset WHERE chapter_id=${cid}`);
  mysql(`DELETE FROM ls_chapter WHERE id=${cid}`);
  mysql(`DELETE FROM ls_project WHERE id=${pid}`);
  console.log('  · 已清理测试项目/章节');

  console.log(`\n=== _smoke_guidance: ${fail ? 'FAILED' : 'ALL PASS'} (${pass} pass / ${fail} fail) ===`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('FATAL', e); process.exit(2); });
