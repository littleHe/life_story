-- 2026-09-18：修正 ls_chapter.project_id 结构漂移
-- 问题：install.sql 已声明 project_id 为 NULL（系统默认参考章节不归属任何项目），
--       但运行库该列是 NOT NULL，导致后台「系统章节 / 添加」插入 NULL 报 10500/字段错误。
-- 修复：放宽为可空，与 install.sql、后台 Admin/ChapterController::save() 一致。
-- 应用：mysql -h127.0.0.1 -uroot -proot life_story < 本文件
ALTER TABLE `ls_chapter`
    MODIFY COLUMN `project_id` BIGINT UNSIGNED NULL COMMENT '归属项目；系统默认参考章节为 NULL';
