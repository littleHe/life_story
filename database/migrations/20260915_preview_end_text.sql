-- 翻书预览「尾页结束文案」参数（后台「系统管理 → 参数设置」可编辑）
-- 首行作为大标题，其余行作为正文；留空则不显示尾页。
INSERT INTO `ls_config` (`config_key`, `config_value`, `remark`, `created_at`, `updated_at`)
VALUES (
  'preview_end_text',
  '感谢欣赏\n一页页翻过的是岁月，留下的是牵挂。愿这段记忆，长暖人心。',
  '翻书预览尾页结束文案（首行为大标题，其余为正文；留空则不显示尾页）',
  NOW(), NOW()
)
ON DUPLICATE KEY UPDATE `config_key` = `config_key`;
