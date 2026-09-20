-- ---------------------------------------------------------------------------
-- 2026-09-20  定稿「美化增值」任务链补全：AI 润色任务化 + AI 音配音（NARRATE）+ 声音复刻（VOICE_CLONE）
--
-- 背景：定稿后要自动跑 4 件事 —— ①逐章汇总润色 ②章节混元生图 ③用原主音色朗读润色文案
--      ④封面/尾页也用 AI 音（按传主性别选男声/女声）。全挂 ai:work 任务链，干完即退。
--
-- 本次新增/改动：
--   1) ls_ai_task.task_type 增加 NARRATE（AI 音朗读）、VOICE_CLONE（声音复刻训练）
--   2) ls_project 增加配音与音色字段：
--        cover_audio    封面书封语音频
--        ending_audio   尾页结束语音频
--        voice_type     复刻音色 VoiceType（0=未复刻，用按性别的标准音色）
--        voice_status   '' / pending / training / ready / failed
--        voice_task_id  复刻任务 ID（轮询用，成功后清空）
--        voice_label    音色备注（如「原主音色」）
-- 说明：章节正文朗读仍存 ls_chapter_asset(type=AUDIO_DUBBED)，不改结构。
-- 幂等：重复执行会因字段已存在报 1060/1265，可忽略。
-- ---------------------------------------------------------------------------

ALTER TABLE `ls_ai_task`
  MODIFY COLUMN `task_type` ENUM('ASR','POLISH','ILLUSTRATE','COVER_IMAGE','DUB','NARRATE','VOICE_CLONE') NOT NULL;

ALTER TABLE `ls_project`
  ADD COLUMN `cover_audio`    VARCHAR(512) NOT NULL DEFAULT ''  COMMENT '封面书封语配音(AI音)' AFTER `cover_job_id`,
  ADD COLUMN `ending_audio`   VARCHAR(512) NOT NULL DEFAULT ''  COMMENT '尾页结束语配音(AI音)' AFTER `cover_audio`,
  ADD COLUMN `voice_type`     INT UNSIGNED NOT NULL DEFAULT 0   COMMENT '复刻音色VoiceType(0=未复刻，按性别用标准音色)' AFTER `ending_audio`,
  ADD COLUMN `voice_status`   VARCHAR(16)  NOT NULL DEFAULT ''  COMMENT '音色复刻状态:空/pending/training/ready/failed' AFTER `voice_type`,
  ADD COLUMN `voice_task_id`  VARCHAR(64)  NOT NULL DEFAULT ''  COMMENT '声音复刻任务ID(轮询用)' AFTER `voice_status`,
  ADD COLUMN `voice_label`    VARCHAR(64)  NOT NULL DEFAULT ''  COMMENT '音色备注(如 原主音色)' AFTER `voice_task_id`;
