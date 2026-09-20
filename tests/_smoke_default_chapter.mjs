/**
 * 后台「默认章节」管理 API 冒烟（Node 直跑，零依赖）
 * 覆盖：列表 / 新增 / 校验落库 / 删除 / 校验消失
 * 前置：后端已启动（php think run -p 9411）
 * 运行：node tests/_smoke_default_chapter.mjs
 */
const BASE = process.env.ADMIN_BASE || 'http://127.0.0.1:9411';
const USER = process.env.ADMIN_USER || 'admin';
const PASS = process.env.ADMIN_PASS || 'admin888';
const TITLE = 'SMOKE_临时默认章节_' + Date.now();

let pass = 0, fail = 0;
const check = (ok, label, extra) => {
  if (ok) { pass++; console.log('  PASS ' + label + (extra ? '  ' + extra : '')); }
  else { fail++; console.log('  FAIL ' + label + (extra ? '  ' + extra : '')); }
};

async function login() {
  const res = await fetch(BASE + '/admin-api/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: USER, password: PASS }),
  });
  const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [res.headers.get('set-cookie')];
  const hit = (raw || []).map((c) => /LSSESSID=([^;]+)/.exec(c || '')).find(Boolean);
  if (!hit) throw new Error('登录未返回 LSSESSID');
  return hit[1];
}

const api = (sid) => async (path, method = 'GET', body = null) => {
  const opt = { method, headers: { Cookie: 'LSSESSID=' + sid } };
  if (body) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(body); }
  return (await fetch(BASE + path, opt)).json();
};

(async () => {
  const sid = await login();
  console.log('PASS 后台登录成功');
  const req = api(sid);

  // 1) 列表：应有种子数据
  const list0 = await req('/admin-api/defaultchapter/index?page=1&limit=50');
  const titles0 = (list0.data || []).map((r) => r.title);
  check(list0.code === 0, '列表接口返回 code=0');
  check(list0.count >= 6, '默认章节种子存在', 'count=' + list0.count);
  check(['童年时光', '青春年华', '成家立业', '奋斗岁月', '闲适晚年', '人生感悟'].every((t) => titles0.includes(t)),
    '6 条初始默认章节齐全');

  // 2) 新增
  const add = await req('/admin-api/defaultchapter/save', 'POST', { title: TITLE, sort: 999, status: 1, remark: 'smoke' });
  check(add.code === 0 && add.data && add.data.id > 0, '新增默认章节', 'id=' + (add.data && add.data.id));
  const newId = add.data && add.data.id;

  // 3) 校验落库 + 排序（sort=999 应排第一）
  const list1 = await req('/admin-api/defaultchapter/index?page=1&limit=50&keyword=' + encodeURIComponent(TITLE));
  check((list1.data || []).some((r) => r.title === TITLE), '新增条目已落库', 'count=' + list1.count);
  const first = (list1.data || [])[0];
  check(!!first && first.title === TITLE, '按 sort 倒序排在首位');

  // 4) 空标题拦截
  const bad = await req('/admin-api/defaultchapter/save', 'POST', { title: '', sort: 0, status: 1 });
  check(bad.code !== 0, '空标题被拦截', 'msg=' + bad.msg);

  // 5) 停用后不参与播种来源（status=0 仍可存在于列表，但不应被 seed 采用）——仅校验状态能改
  const upd = await req('/admin-api/defaultchapter/save', 'POST', { id: newId, title: TITLE, sort: 999, status: 0, remark: 'off' });
  check(upd.code === 0, '修改为停用');
  const list2 = await req('/admin-api/defaultchapter/index?page=1&limit=50&keyword=' + encodeURIComponent(TITLE));
  check((list2.data || []).some((r) => String(r.status) === '0'), '停用状态已保存');

  // 6) 删除 + 校验消失
  const del = await req('/admin-api/defaultchapter/delete', 'POST', { id: newId });
  check(del.code === 0, '删除默认章节');
  const list3 = await req('/admin-api/defaultchapter/index?page=1&limit=50&keyword=' + encodeURIComponent(TITLE));
  check(!(list3.data || []).some((r) => r.title === TITLE), '删除后列表不含该条目');

  console.log('--------------------------------------------------');
  console.log(`=== _smoke_default_chapter: ` + (fail ? 'FAIL' : 'ALL PASS') + ` (${pass} pass / ${fail} fail) ===`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.log('ERR ' + e); process.exit(1); });
