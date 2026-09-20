-- =====================================================================
-- 2026-09-15 后台参数设置 + 回忆录项目管理（制作信息）
-- 执行：mysql -u root -proot life_story < database/migrations/20260915_admin_config_project.sql
-- =====================================================================

SET NAMES utf8mb4;

-- 1) 站点参数（键值）：翻书预览自动翻页秒数、公司信息等
CREATE TABLE IF NOT EXISTS `ls_config` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_key`   VARCHAR(64)  NOT NULL             COMMENT '参数键',
  `config_value` VARCHAR(512) NOT NULL DEFAULT ''  COMMENT '参数值',
  `remark`       VARCHAR(255) NOT NULL DEFAULT ''  COMMENT '说明',
  `created_at`   DATETIME              DEFAULT NULL,
  `updated_at`   DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点参数设置';

INSERT INTO `ls_config` (`config_key`,`config_value`,`remark`,`created_at`,`updated_at`)
SELECT 'app_name', '人生回忆录', '站点名称（浏览器标题）', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_config` WHERE `config_key`='app_name');

INSERT INTO `ls_config` (`config_key`,`config_value`,`remark`,`created_at`,`updated_at`)
SELECT 'preview_auto_flip_seconds', '30', '翻书预览自动翻页间隔（秒），0=关闭', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_config` WHERE `config_key`='preview_auto_flip_seconds');

INSERT INTO `ls_config` (`config_key`,`config_value`,`remark`,`created_at`,`updated_at`)
SELECT 'company_name', '', '公司名称', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_config` WHERE `config_key`='company_name');

INSERT INTO `ls_config` (`config_key`,`config_value`,`remark`,`created_at`,`updated_at`)
SELECT 'company_email', '', '公司邮箱', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_config` WHERE `config_key`='company_email');

INSERT INTO `ls_config` (`config_key`,`config_value`,`remark`,`created_at`,`updated_at`)
SELECT 'service_phone', '', '客服电话', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_config` WHERE `config_key`='service_phone');

INSERT INTO `ls_config` (`config_key`,`config_value`,`remark`,`created_at`,`updated_at`)
SELECT 'company_address', '', '联系地址', NOW(), NOW() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_config` WHERE `config_key`='company_address');

-- 2) 项目：书籍/漫剧制作信息（由后台录入）
ALTER TABLE `ls_project`
  ADD COLUMN `production_info` VARCHAR(1024) NOT NULL DEFAULT '' COMMENT '制作信息（书籍/漫剧等，后台录入）' AFTER `cover_bg`;

-- 3) 后台菜单：系统管理 → 参数设置；回忆录管理 → 回忆录项目
INSERT INTO `ls_admin_menu` (`pid`,`title`,`icon`,`href`,`target`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT 11, '参数设置', 'fa fa-sliders', '/admin/page/config.html', '_self', 70, 1, '自动翻页/公司信息等', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_admin_menu` WHERE `href` = '/admin/page/config.html');

INSERT INTO `ls_admin_menu` (`pid`,`title`,`icon`,`href`,`target`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT 1, '回忆录项目', 'fa fa-bookmark', '/admin/page/project.html', '_self', 85, 1, '制作信息与状态管理', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_admin_menu` WHERE `href` = '/admin/page/project.html');
