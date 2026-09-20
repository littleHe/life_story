-- 2026-09-15 兑换码解锁强校验
-- 1) 创建必须携带有效兑换码（后端 ProjectController@save 强校验），未解锁不落库任何数据
-- 2) 固定测试码：66666688（max_uses=0 表示不限次，可反复解锁多个项目），归属测试号 uid=2
--    明文不落库，仅存 sha256；code_mask 用于展示（测试码按完整码展示便于联调）

INSERT INTO `ls_redemption_code`
    (`code_hash`, `code_mask`, `status`, `max_uses`, `used_count`, `generated_by`, `bound_user_id`, `created_at`)
SELECT SHA2('66666688', 256), '66666688', 'ADDED', 0, 0, 0, 2, NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT `id` FROM `ls_redemption_code` WHERE `code_hash` = SHA2('66666688', 256)) AS t
);

-- 已存在时也保证「不限次 + 归属演示账号」，避免被改动后测试失败
UPDATE `ls_redemption_code`
   SET `max_uses` = 0, `status` = 'ADDED', `bound_user_id` = 2
 WHERE `code_hash` = SHA2('66666688', 256)
   AND (`max_uses` <> 0 OR `status` NOT IN ('ADDED', 'BOUND') OR `bound_user_id` IS NULL);
