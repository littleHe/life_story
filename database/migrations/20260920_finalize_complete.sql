-- 定稿收尾：记录失败子任务类型，供前端提示「部分生成失败，可重试」
ALTER TABLE `ls_project`
    ADD COLUMN `finalize_failed` VARCHAR(255) NOT NULL DEFAULT ''
    COMMENT '定稿失败的任务类型（逗号分隔，如 ILLUSTRATE,COVER_IMAGE），用于前端提示可重试'
    AFTER `finalized_at`;
