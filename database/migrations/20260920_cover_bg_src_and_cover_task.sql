-- ---------------------------------------------------------------------------
-- 20260920 配图链路增强：封面来源标记 + 封面生图任务
--
-- 背景：定稿后的配图改为「任务化」（ILLUSTRATE 逐章 + COVER_IMAGE 封面），
--       封面需要记录「当前封面是谁给的」，否则无法判断能不能被 AI 出图覆盖：
--         user   = 用户自己上传 → 永久保留，AI 不动它
--         ai     = 混元生成 → 幂等依据
--         avatar = 传主头像兜底 → 出图后可覆盖
--         pool   = 后台封面图池随机 → 出图后可覆盖
--
-- 注意：ls_chapter.project_id 的放宽见 20260918_chapter_project_id_nullable.sql。
-- ---------------------------------------------------------------------------

-- 1) 封面来源 + 生图任务 ID（续询用）
ALTER TABLE `ls_project`
  ADD COLUMN `cover_bg_src` VARCHAR(16) NOT NULL DEFAULT '' COMMENT '封面来源：user/ai/avatar/pool' AFTER `cover_bg`,
  ADD COLUMN `cover_job_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '封面生图任务ID（续询用，出图后清空）' AFTER `cover_bg_src`;

-- 2) 任务类型增加封面配图（ENUM 需整体重写）
ALTER TABLE `ls_ai_task`
  MODIFY COLUMN `task_type` ENUM('ASR','POLISH','ILLUSTRATE','COVER_IMAGE','DUB') NOT NULL;

-- 3) 项目级任务（封面）按 项目+类型+状态 查询，补个联合索引
ALTER TABLE `ls_ai_task`
  ADD KEY `idx_project_type` (`project_id`,`task_type`,`status`);

-- 4) 老数据回填：已有封面按值反推来源，避免把用户上传的封面当成兜底图覆盖
UPDATE `ls_project`
   SET `cover_bg_src` = CASE
       WHEN `cover_bg` = '' THEN ''
       WHEN `cover_bg` = `avatar` THEN 'avatar'
       WHEN `cover_bg` LIKE '%/cover-bg/cover-bg-%' THEN 'pool'
       WHEN `cover_bg` LIKE '%/chapter-bg/ai/%' OR `cover_bg` LIKE '%/chapter-bg/cover/%' THEN 'ai'
       ELSE 'user'
   END
 WHERE `cover_bg_src` = '';
