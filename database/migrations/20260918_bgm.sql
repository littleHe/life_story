-- 背景音乐池（后台「回忆录管理 → 背景音乐」维护）
-- 用途：翻书预览页「欣赏回忆录」时，随机取一首「启用中」的音乐循环播放。
-- 音频统一放在 public/uploads/bgm/ 下；后台可上传替换，文件名无所谓。
-- 内置曲子为 mp3（64kbps 单声道 / 22050Hz，42 秒约 328KB）；原始无损 wav 一并留在同目录，需要时可用：
--   ffmpeg -i bgm-N.wav -codec:a libmp3lame -b:a 64k -ac 1 -ar 22050 -map_metadata -1 bgm-N.mp3
-- 幂等：可重复执行（建表用 IF NOT EXISTS，种子数据用 WHERE NOT EXISTS 去重）。

CREATE TABLE IF NOT EXISTS `ls_bgm` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(100) NOT NULL DEFAULT ''    COMMENT '曲名',
  `file`       VARCHAR(512) NOT NULL DEFAULT ''    COMMENT '音频地址，如 /uploads/bgm/bgm-1.mp3',
  `sort`       INT          NOT NULL DEFAULT 0     COMMENT '排序，倒序',
  `status`     TINYINT      NOT NULL DEFAULT 1     COMMENT '1=启用 0=停用',
  `remark`     VARCHAR(255) NOT NULL DEFAULT ''    COMMENT '备注',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='背景音乐池';

-- 内置 5 首示例（温馨 / 叙事感，程序合成，无第三方版权；可在后台删掉换成自己的曲子）
INSERT INTO `ls_bgm` (`title`,`file`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT * FROM (SELECT '温暖晨光',  '/uploads/bgm/bgm-1.mp3', 50, 1, '内置示例：温馨明亮，适合开篇',   NOW() AS created_at, NOW() AS updated_at) AS t
WHERE NOT EXISTS (SELECT 1 FROM `ls_bgm` WHERE `file` = '/uploads/bgm/bgm-1.mp3');

INSERT INTO `ls_bgm` (`title`,`file`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT * FROM (SELECT '岁月静好',  '/uploads/bgm/bgm-2.mp3', 48, 1, '内置示例：舒缓平和，适合长段讲述', NOW() AS created_at, NOW() AS updated_at) AS t
WHERE NOT EXISTS (SELECT 1 FROM `ls_bgm` WHERE `file` = '/uploads/bgm/bgm-2.mp3');

INSERT INTO `ls_bgm` (`title`,`file`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT * FROM (SELECT '旧时光',    '/uploads/bgm/bgm-3.mp3', 46, 1, '内置示例：怀旧低沉，适合回忆往事', NOW() AS created_at, NOW() AS updated_at) AS t
WHERE NOT EXISTS (SELECT 1 FROM `ls_bgm` WHERE `file` = '/uploads/bgm/bgm-3.mp3');

INSERT INTO `ls_bgm` (`title`,`file`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT * FROM (SELECT '家人的温度', '/uploads/bgm/bgm-4.mp3', 44, 1, '内置示例：温暖亲切，适合亲情篇',   NOW() AS created_at, NOW() AS updated_at) AS t
WHERE NOT EXISTS (SELECT 1 FROM `ls_bgm` WHERE `file` = '/uploads/bgm/bgm-4.mp3');

INSERT INTO `ls_bgm` (`title`,`file`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT * FROM (SELECT '慢慢讲述',  '/uploads/bgm/bgm-5.mp3', 42, 1, '内置示例：略带忧郁的温柔',       NOW() AS created_at, NOW() AS updated_at) AS t
WHERE NOT EXISTS (SELECT 1 FROM `ls_bgm` WHERE `file` = '/uploads/bgm/bgm-5.mp3');

-- 后台菜单：回忆录管理（pid=1）→ 背景音乐
INSERT INTO `ls_admin_menu` (`pid`,`title`,`icon`,`href`,`target`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT * FROM (SELECT 1 AS pid, '背景音乐' AS title, 'fa fa-music' AS icon, '/admin/page/bgm.html' AS href,
  '_self' AS target, 70 AS sort, 1 AS status, '欣赏回忆录时随机播放的背景音乐' AS remark,
  NOW() AS created_at, NOW() AS updated_at) AS t
WHERE NOT EXISTS (SELECT 1 FROM `ls_admin_menu` WHERE `href` = '/admin/page/bgm.html');
