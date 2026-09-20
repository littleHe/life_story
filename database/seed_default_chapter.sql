-- =====================================================================
-- 默认章节 · 种子数据（新建回忆录解锁后自动播种到项目）
-- 执行：mysql -u root -proot life_story < database/seed_default_chapter.sql
-- 说明：幂等，按 title 去重；排序数字越大越靠前（sort desc）
-- =====================================================================
SET NAMES utf8mb4;

INSERT INTO `ls_default_chapter` (`title`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT '童年时光', 60, 1, '', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_default_chapter` WHERE `title` = '童年时光');

INSERT INTO `ls_default_chapter` (`title`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT '青春年华', 50, 1, '', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_default_chapter` WHERE `title` = '青春年华');

INSERT INTO `ls_default_chapter` (`title`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT '成家立业', 40, 1, '', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_default_chapter` WHERE `title` = '成家立业');

INSERT INTO `ls_default_chapter` (`title`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT '奋斗岁月', 30, 1, '', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_default_chapter` WHERE `title` = '奋斗岁月');

INSERT INTO `ls_default_chapter` (`title`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT '闲适晚年', 20, 1, '', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_default_chapter` WHERE `title` = '闲适晚年');

INSERT INTO `ls_default_chapter` (`title`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT '人生感悟', 10, 1, '', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_default_chapter` WHERE `title` = '人生感悟');
