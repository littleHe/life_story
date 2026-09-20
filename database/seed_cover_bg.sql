-- =====================================================================
-- 封面背景图池 · 种子数据（4 张故事感背景，AI 生成、已去水印）
-- 执行：mysql -u root -proot life_story < database/seed_cover_bg.sql
-- =====================================================================
SET NAMES utf8mb4;

INSERT INTO `ls_cover_bg` (`title`,`image`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT '乡间油菜花', '/uploads/cover-bg/cover-bg-1.png', 10, 1, '春天乡间油菜花与小路', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_cover_bg` WHERE `image` = '/uploads/cover-bg/cover-bg-1.png');

INSERT INTO `ls_cover_bg` (`title`,`image`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT '旧相册与老照片', '/uploads/cover-bg/cover-bg-2.png', 9, 1, '老木桌上摊开的旧相册', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_cover_bg` WHERE `image` = '/uploads/cover-bg/cover-bg-2.png');

INSERT INTO `ls_cover_bg` (`title`,`image`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT '湖上小船夕阳', '/uploads/cover-bg/cover-bg-3.png', 8, 1, '夕阳湖面与小木船', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_cover_bg` WHERE `image` = '/uploads/cover-bg/cover-bg-3.png');

INSERT INTO `ls_cover_bg` (`title`,`image`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT '老屋黄昏', '/uploads/cover-bg/cover-bg-4.png', 7, 1, '南方乡村老屋与藤蔓', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_cover_bg` WHERE `image` = '/uploads/cover-bg/cover-bg-4.png');
