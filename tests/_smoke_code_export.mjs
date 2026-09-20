/**
 * 兑换码「事后/定期导出」冒烟
 * - 生成两批码（明文加密落库）
 * - 导出全部 → CSV 含全部明文
 * - 导出未导出增量（再次）→ 应为空（已被标记）
 * - 清理：按 batch_no 删除测试产生的码
 */
import { execSync } from 'child_process';

const API = 'http://127.0.0.1:9411';
const MYSQL = 'D:\\phpstudy_pro\\Extensions\\MySQL8.0.12\\bin\\mysql.exe';
let pass = 0, fail = 0;
function ok(c, m, extra = '') { if (c) { pass++; console.log('PASS ' + m + (extra ? ' :: ' + extra : '')); } else { fail++; console.log('FAIL ' + m + (extra ? ' :: ' + extra : '')); } }

function mysql(sql) {
  return execSync(`${MYSQL} -h127.0.0.1 -uroot -proot life_story -e "${sql.replace(/"/g, '\\"')}"`, { stdio: 'pipe' }).toString();
}

async function main() {
  // 1) 管理员登录拿 cookie
  const login = await fetch(API + '/admin-api/login', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: 'admin', password: 'admin888' }),
  });
  const setCookie = login.headers.get('set-cookie') || '';
  const cookie = setCookie.split(';')[0];
  ok(login.status === 200 && !!cookie, '管理员登录', 'HTTP ' + login.status);

  const headers = { 'Content-Type': 'application/json', Cookie: cookie };

  // 2) 生成两批码
  const g1 = await (await fetch(API + '/admin-api/code/generate', { method: 'POST', headers, body: JSON.stringify({ count: 3, max_uses: 1 }) })).json();
  const g2 = await (await fetch(API + '/admin-api/code/generate', { method: 'POST', headers, body: JSON.stringify({ count: 2, max_uses: 5 }) })).json();
  const codes1 = g1.data.codes, codes2 = g2.data.codes;
  const batch1 = g1.data.batch_no, batch2 = g2.data.batch_no;
  ok(codes1.length === 3 && codes2.length === 2, '两批共 5 个码生成', batch1 + ' / ' + batch2);
  ok(!!batch1 && !!batch2 && batch1 !== batch2, '两批批次号不同', batch1 + ' vs ' + batch2);

  // 3) 导出全部（标记） → CSV 含全部 5 个明文
  const expAll = await fetch(API + '/admin-api/code/export?scope=all&mark=1', { headers });
  const csvAll = await expAll.text();
  ok(expAll.headers.get('content-type').includes('text/csv'), '导出返回 CSV', expAll.headers.get('content-type'));
  ok(expAll.headers.get('content-disposition').includes('attachment'), '响应为下载附件');
  const allHit = [...codes1, ...codes2].every(c => csvAll.includes(c));
  ok(allHit, 'CSV 含全部 5 个明文', csvAll.split('\n')[1] + ' ...');

  // 4) 再导未导出增量 → 应只剩表头（已全部标记）
  const expDelta = await fetch(API + '/admin-api/code/export?scope=unexported&mark=1', { headers });
  const csvDelta = await expDelta.text();
  const deltaRows = csvDelta.trim().split('\n').filter(l => l.trim() !== '');
  ok(deltaRows.length <= 1, '增量导出已为空（被标记后不再重复）', 'rows=' + deltaRows.length);

  // 5) 按批次导出（用 batch1）→ 仅含 codes1 的 3 个
  const expB1 = await fetch(API + '/admin-api/code/export?scope=all&batch_no=' + encodeURIComponent(batch1) + '&mark=0', { headers });
  const csvB1 = await expB1.text();
  const b1Hit = codes1.every(c => csvB1.includes(c)) && !codes2.some(c => csvB1.includes(c));
  ok(b1Hit, '按批次导出仅含该批', 'codes1 in=' + codes1.every(c => csvB1.includes(c)) + ' codes2 in=' + codes2.some(c => csvB1.includes(c)));

  // 6) 清理测试码
  mysql(`DELETE FROM ls_redemption_code WHERE batch_no IN ('${batch1}','${batch2}')`);
  const remain = mysql(`SELECT COUNT(*) AS n FROM ls_redemption_code WHERE batch_no IN ('${batch1}','${batch2}')`).trim();
  ok(!remain.includes('1') && !remain.includes('2'), '测试码已清理', 'remain=' + remain.replace(/\D/g, ''));

  console.log(`\n=== ${pass} PASS / ${fail} FAIL ===`);
  process.exit(fail ? 1 : 0);
}
main().catch(e => { console.error('ERR', e); process.exit(1); });
