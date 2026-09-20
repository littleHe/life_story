/**
 * 兑换码「历史数据（无明文存档）」链路冒烟
 *
 * 锁定线上反馈的问题：后台「导出兑换码」导出为空（CSV 只有表头）。
 * 成因：加密功能上线前的码只存了 sha256，code_cipher 为空 → 导出查询直接过滤掉，且前端无法感知。
 *
 * 覆盖：
 *   1) 造一条历史码（只有 sha256，无密文）→ 导出应「0 条导出 + 跳过 ≥1」且 CSV 只有表头
 *   2) 回填错误明文 → 必须被拒（哈希校验）
 *   3) 回填正确明文 → archive 成功，列表 has_cipher=1
 *   4) 导出 → 含该明文，跳过数回落
 *   5) 重发新码 → 返回新明文；新明文可导出、旧明文失效
 *   6) 清理测试数据
 */
import { execSync } from 'child_process';

const API = 'http://127.0.0.1:9411';
const MYSQL = 'D:\\phpstudy_pro\\Extensions\\MySQL8.0.12\\bin\\mysql.exe';
const LEGACY = 'LEGACY00TEST'; // 模拟「明文已丢失」的历史码

let pass = 0, fail = 0;
function ok(c, m, extra = '') {
  if (c) { pass++; console.log('PASS ' + m + (extra ? ' :: ' + extra : '')); }
  else { fail++; console.log('FAIL ' + m + (extra ? ' :: ' + extra : '')); }
}
function mysql(sql) {
  return execSync(`${MYSQL} -h127.0.0.1 -uroot -proot life_story -e "${sql.replace(/"/g, '\\"')}"`, { stdio: 'pipe' }).toString();
}
/** 解析导出响应：返回 { count, matched, skipped, rows(不含表头), raw } */
async function doExport(headers, qs = 'scope=all&mark=0') {
  const r = await fetch(API + '/admin-api/code/export?' + qs, { headers });
  const raw = await r.text();
  const lines = raw.replace(/^\uFEFF/, '').trim().split('\n').filter((l) => l.trim() !== '');
  return {
    count: parseInt(r.headers.get('x-export-count') || '-1', 10),
    matched: parseInt(r.headers.get('x-export-matched') || '-1', 10),
    skipped: parseInt(r.headers.get('x-export-skipped') || '-1', 10),
    rows: lines.slice(1),
    raw,
  };
}

async function main() {
  // 1) 管理员登录
  const login = await fetch(API + '/admin-api/login', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: 'admin', password: 'admin888' }),
  });
  const cookie = (login.headers.get('set-cookie') || '').split(';')[0];
  ok(login.status === 200 && !!cookie, '管理员登录', 'HTTP ' + login.status);
  const H = { 'Content-Type': 'application/json', Cookie: cookie };

  const base = await doExport(H);
  ok(base.count >= 0 && base.skipped >= 0, '基线导出可读统计头', `count=${base.count} skipped=${base.skipped}`);

  // 2) 造历史码：只有 sha256，无 code_cipher
  mysql(`DELETE FROM ls_redemption_code WHERE code_hash = SHA2('${LEGACY}', 256)`);
  mysql(`INSERT INTO ls_redemption_code (code_hash, code_mask, status, max_uses, used_count, created_at, updated_at) VALUES (SHA2('${LEGACY}', 256), '******TEST', 'GENERATED', 1, 0, NOW(), NOW())`);
  const id = parseInt(mysql(`SELECT id FROM ls_redemption_code WHERE code_hash = SHA2('${LEGACY}', 256)`).trim().split('\n').pop(), 10);
  ok(id > 0, '已造一条无明文存档的历史码', 'id=' + id);

  // 3) 导出：命中 +1，导出不变，跳过 +1，CSV 只有表头（复现用户现象）
  const after = await doExport(H);
  ok(after.matched === base.matched + 1, '命中数包含历史码', `${base.matched} → ${after.matched}`);
  ok(after.count === base.count, '历史码不进入导出结果', `count=${after.count}`);
  ok(after.skipped === base.skipped + 1, '跳过数如实 +1（前端据此提示而非静默空文件）', `skipped=${base.skipped} → ${after.skipped}`);
  ok(!after.raw.includes(LEGACY), 'CSV 中不含无法回取的明文');

  // 4) 回填错误明文 → 拒绝
  const bad = await (await fetch(API + '/admin-api/code/backfill', {
    method: 'POST', headers: H, body: JSON.stringify({ id, code: 'WRONGCODE123' }),
  })).json();
  ok(bad.code !== 0, '回填错误明文被拒（哈希校验）', bad.msg);

  // 5) 回填正确明文 → 成功
  const good = await (await fetch(API + '/admin-api/code/backfill', {
    method: 'POST', headers: H, body: JSON.stringify({ id, code: LEGACY }),
  })).json();
  ok(good.code === 0, '回填正确明文成功', good.msg);

  const list = await (await fetch(API + '/admin-api/code/index?keyword=TEST', { headers: H })).json();
  const row = (list.data || []).find((r) => r.id === id) || {};
  ok(row.has_cipher === 1 && row.cipher_text === '已存档', '列表标记为「已存档」（可导出）', `has_cipher=${row.has_cipher} / ${row.cipher_text}`);

  const fixed = await doExport(H);
  ok(fixed.rows.some((l) => l.startsWith(LEGACY + ',')), '回填后导出包含该明文', fixed.rows.length + ' 行');
  ok(fixed.count === base.count + 1 && fixed.skipped === base.skipped, '导出数 +1、跳过数回落', `count=${fixed.count} skipped=${fixed.skipped}`);

  // 6) 重发新码：旧明文失效，新明文可导出
  const re = await (await fetch(API + '/admin-api/code/reissue', {
    method: 'POST', headers: H, body: JSON.stringify({ id }),
  })).json();
  const newCode = (re.data && re.data.code) || '';
  ok(re.code === 0 && newCode.length === 12, '重发返回 12 位新明文', newCode);

  const after2 = await doExport(H);
  ok(after2.rows.some((l) => l.startsWith(newCode + ',')), '新明文可导出');
  ok(!after2.rows.some((l) => l.startsWith(LEGACY + ',')), '旧明文已失效（导出中消失）');

  // 7) 清理
  mysql(`DELETE FROM ls_redemption_code WHERE id = ${id}`);
  const remain = mysql(`SELECT COUNT(*) c FROM ls_redemption_code WHERE id = ${id}`).trim().split('\n').pop();
  ok(remain === '0', '测试数据已清理', 'remain=' + remain);

  console.log('\n=== ' + pass + ' PASS / ' + fail + ' FAIL ===');
  process.exit(fail ? 1 : 0);
}

main().catch((e) => { console.error('ERROR', e); process.exit(1); });
