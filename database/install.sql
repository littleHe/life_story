-- ============================================================
-- 回忆录系统 · 数据库建表脚本 (MySQL 8.0+ / InnoDB / utf8mb4)
-- 与设计文档《三、数据模型(ER)》严格对齐
-- 导入：mysql -u<user> -p <db> < database/install.sql
-- 注意：生产环境请改用迁移工具(phinx/自定义)，本文件为初始落地版
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 1. 用户表
-- ----------------------------
DROP TABLE IF EXISTS `ls_user`;
CREATE TABLE `ls_user` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unionid`     VARCHAR(64)  NOT NULL COMMENT '微信 unionid（跨端主键）',
  `openid`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '当前端 openid',
  `nickname`    VARCHAR(128) NOT NULL DEFAULT '',
  `avatar`      VARCHAR(512) NOT NULL DEFAULT '',
  `role`        TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=普通用户 1=管理员',
  `status`      TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1=正常 0=禁用',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_unionid` (`unionid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户';

-- ----------------------------
-- 2. 项目(回忆录)表
-- ----------------------------
DROP TABLE IF EXISTS `ls_project`;
CREATE TABLE `ls_project` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`            BIGINT UNSIGNED NOT NULL,
  `name`               VARCHAR(128) NOT NULL DEFAULT '' COMMENT '回忆录标题',
  `real_name`          VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '传主真实姓名',
  `gender`             ENUM('male','female') NOT NULL DEFAULT 'male' COMMENT '传主性别',
  `avatar`             VARCHAR(512) NOT NULL DEFAULT '' COMMENT '传主头像（本地资源路径或外链）',
  `description`        VARCHAR(512) NOT NULL DEFAULT '' COMMENT '回忆录简介',
  `birth`              DATE NULL COMMENT '出生日期',
  `native_place`       VARCHAR(128) NOT NULL DEFAULT '' COMMENT '籍贯',
  `status`             ENUM('UNPAID_LOCKED','EDITABLE','MAKING','DONE') NOT NULL DEFAULT 'UNPAID_LOCKED',
  `preview_token`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '翻书预览访问令牌',
  `preview_url`        VARCHAR(512) NOT NULL DEFAULT '' COMMENT '翻书预览地址',
  `cover_bg`           VARCHAR(512) NOT NULL DEFAULT '' COMMENT '封面背景图地址',
  `cover_bg_src`       VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '封面来源：user(用户上传)/ai(混元生成)/avatar(传主头像)/pool(后台图池)',
  `cover_job_id`       VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '封面生图任务ID（续询用，出图后清空）',
  `cover_audio`        VARCHAR(512) NOT NULL DEFAULT '' COMMENT '封面页 AI 旁白音频地址',
  `ending_audio`       VARCHAR(512) NOT NULL DEFAULT '' COMMENT '结束页 AI 旁白音频地址',
  `voice_type`         INT NOT NULL DEFAULT 0 COMMENT '声音复刻成功后的音色 ID（0=未复刻，用标准音色）',
  `voice_status`       ENUM('none','pending','training','ready','failed') NOT NULL DEFAULT 'none' COMMENT '声音复刻状态',
  `voice_task_id`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '声音复刻任务 ID',
  `voice_label`        VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '当前使用音色名称（复刻/标准）',
  `finalized_at`       DATETIME     DEFAULT NULL COMMENT '定稿时间',
  `finalize_failed`    VARCHAR(255) NOT NULL DEFAULT '' COMMENT '定稿失败的任务类型（逗号分隔），用于前端提示可重试',
  `chapter_locked`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '基础架构锁定后不可改基础信息',
  `redemption_code_id` BIGINT UNSIGNED NULL COMMENT '绑定的兑换码(一码一单)',
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  UNIQUE KEY `uk_code` (`redemption_code_id`),
  CONSTRAINT `fk_project_user` FOREIGN KEY (`user_id`) REFERENCES `ls_user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='回忆录项目';

-- ----------------------------
-- 3. 章节表
-- ----------------------------
DROP TABLE IF EXISTS `ls_chapter`;
CREATE TABLE `ls_chapter` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id`    BIGINT UNSIGNED NULL,
  `title`         VARCHAR(255) NOT NULL DEFAULT '',
  `sort`          INT NOT NULL DEFAULT 0,
  `source`        ENUM('SYSTEM','CUSTOM') NOT NULL DEFAULT 'SYSTEM' COMMENT '系统预设/用户自定义',
  `text_status`   ENUM('ORIGINAL','POLISHED','INCONSISTENT') NOT NULL DEFAULT 'ORIGINAL',
  `chapter_status`ENUM('EMPTY','RECORDED','TRANSCRIBED','POLISHED','DONE') NOT NULL DEFAULT 'EMPTY',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  CONSTRAINT `fk_chapter_project` FOREIGN KEY (`project_id`) REFERENCES `ls_project` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='章节';

-- ----------------------------
-- 4. 章节资源表（录音/转写/配图/双音频等）
-- ----------------------------
DROP TABLE IF EXISTS `ls_chapter_asset`;
CREATE TABLE `ls_chapter_asset` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chapter_id`  BIGINT UNSIGNED NOT NULL,
  `asset_type`  ENUM('RECORDING','TRANSCRIPT','POLISHED_TEXT','USER_IMAGE','AI_IMAGE','AUDIO_ORIGINAL','AUDIO_DUBBED') NOT NULL,
  `oss_key`     VARCHAR(512) NOT NULL DEFAULT '' COMMENT 'OSS/COS 对象 key（大文件只存 key）',
  `meta`        LONGTEXT NULL COMMENT '附加元数据(时长/尺寸/模型/文本等)（5.6 兼容：原 JSON 类型）',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chapter` (`chapter_id`),
  CONSTRAINT `fk_asset_chapter` FOREIGN KEY (`chapter_id`) REFERENCES `ls_chapter` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='章节资源';

-- ----------------------------
-- 5. 兑换码表（只存哈希；状态机见设计文档 4.1）
-- ----------------------------
DROP TABLE IF EXISTS `ls_redemption_code`;
CREATE TABLE `ls_redemption_code` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_hash`        VARCHAR(128) NOT NULL COMMENT 'sha256(大写去空格原始码)',
  `code_mask`        VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '展示用尾号掩码(可选)',
  `code_cipher`      VARCHAR(512) NULL     DEFAULT NULL COMMENT 'AES-256-CBC 加密的原始码明文(可回取，用于事后/定期导出)',
  `batch_no`         VARCHAR(32)  NULL     DEFAULT NULL COMMENT '批次号：一次生成调用共享，便于分组导出',
  `exported_at`      DATETIME     NULL     DEFAULT NULL COMMENT '最近一次导出时间(NULL=从未导出，用于增量汇出)',
  `status`           ENUM('GENERATED','ADDED','BOUND','VOID') NOT NULL DEFAULT 'GENERATED',
  `bound_project_id` BIGINT UNSIGNED NULL,
  `bound_user_id`    BIGINT UNSIGNED NULL,
  `generated_by`     BIGINT UNSIGNED NULL,
  `max_uses`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最大核销次数，0=不限(测试码)',
  `used_count`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已核销次数',
  `frozen_until`     DATETIME NULL COMMENT '核销失败超限临时冻结，到期前不可绑定',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code_hash` (`code_hash`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_code_project` FOREIGN KEY (`bound_project_id`) REFERENCES `ls_project` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='兑换码';

-- ----------------------------
-- 6. 核销/绑定日志
-- ----------------------------
DROP TABLE IF EXISTS `ls_verification_log`;
CREATE TABLE `ls_verification_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code_id`     BIGINT UNSIGNED NOT NULL,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `project_id`  BIGINT UNSIGNED NULL,
  `method`      ENUM('SELECT','INPUT') NOT NULL,
  `device_info` VARCHAR(512) NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_code` (`code_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_vlog_code` FOREIGN KEY (`code_id`) REFERENCES `ls_redemption_code` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='核销日志';

-- ----------------------------
-- 7. 安全锁定日志（限流/锁定审计）
-- ----------------------------
DROP TABLE IF EXISTS `ls_security_lock_log`;
CREATE TABLE `ls_security_lock_log` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL COMMENT '未登录场景填 0，用 scope 区分',
  `scope`       VARCHAR(32) NOT NULL COMMENT 'auth/code_verify/global/ip 等',
  `lock_type`   ENUM('SOFT','HARD') NOT NULL DEFAULT 'SOFT',
  `reason`      VARCHAR(255) NOT NULL DEFAULT '',
  `ttl_sec`     INT NOT NULL DEFAULT 0 COMMENT '0=需人工解封',
  `unlocked_at` DATETIME NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_scope` (`user_id`,`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='安全锁定日志';

-- ----------------------------
-- 8. 声音档案（克隆授权合规）
-- ----------------------------
DROP TABLE IF EXISTS `ls_voice_profile`;
CREATE TABLE `ls_voice_profile` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `cloned`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `consent`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否单独授权声音克隆',
  `consent_at`  DATETIME NULL,
  `model_ref`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '云厂商声音模型引用',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`),
  CONSTRAINT `fk_voice_user` FOREIGN KEY (`user_id`) REFERENCES `ls_user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='声音档案';

-- ----------------------------
-- 9. AI 异步任务表（进度/结果追踪，实际执行走 think-queue）
-- ----------------------------
DROP TABLE IF EXISTS `ls_ai_task`;
CREATE TABLE `ls_ai_task` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `project_id`   BIGINT UNSIGNED NULL,
  `chapter_id`   BIGINT UNSIGNED NULL,
  `task_type`    ENUM('ASR','POLISH','ILLUSTRATE','COVER_IMAGE','DUB','NARRATE','VOICE_CLONE') NOT NULL,
  `status`       ENUM('PENDING','RUNNING','SUCCESS','FAILED','RETRY') NOT NULL DEFAULT 'PENDING',
  `progress`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100',
  `payload`      LONGTEXT NULL,
  `result`       LONGTEXT NULL,
  `error`        VARCHAR(512) NOT NULL DEFAULT '',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `finished_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chapter_status` (`chapter_id`,`status`),
  KEY `idx_project_type` (`project_id`,`task_type`,`status`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AI 异步任务';

-- ----------------------------
-- 10. think-queue 数据库驱动所需表（异步 AI 任务队列）
--     connector=database 时由 think-queue 自动建表，此处为显式备份
-- ----------------------------
DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue`     VARCHAR(255) NOT NULL,
  `payload`   LONGTEXT NOT NULL,
  `attempts`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `reserve_time`   INT UNSIGNED NULL,
  `available_time` INT UNSIGNED NOT NULL DEFAULT 0,
  `create_time`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_queue` (`queue`(191)),
  KEY `idx_reserve` (`reserve_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='队列任务（think-queue yunwuxin 版：列名为 reserve_time/available_time/create_time）';

DROP TABLE IF EXISTS `failed_jobs`;
CREATE TABLE `failed_jobs` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `connection` VARCHAR(255) NOT NULL,
  `queue`     VARCHAR(255) NOT NULL,
  `payload`   LONGTEXT NOT NULL,
  `exception` LONGTEXT NOT NULL,
  `failed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='失败任务';

SET FOREIGN_KEY_CHECKS = 1;
