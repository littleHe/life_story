-- 2026-09-15 回忆录项目表补充「传主档案」字段
-- 背景：前端新建向导会采集 性别 / 头像 / 简介，此前 ls_project 无对应列，
--       导致新建落库后这些输入被静默丢弃（刷新即消失）。
-- 回滚：ALTER TABLE `ls_project` DROP COLUMN `gender`, DROP COLUMN `avatar`, DROP COLUMN `description`;

ALTER TABLE `ls_project`
  ADD COLUMN `gender` ENUM('male','female') NOT NULL DEFAULT 'male' COMMENT '传主性别' AFTER `real_name`,
  ADD COLUMN `avatar` VARCHAR(512) NOT NULL DEFAULT '' COMMENT '传主头像（本地资源路径或外链）' AFTER `gender`,
  ADD COLUMN `description` VARCHAR(512) NOT NULL DEFAULT '' COMMENT '回忆录简介' AFTER `avatar`;
