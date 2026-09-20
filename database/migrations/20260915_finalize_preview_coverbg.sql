-- =====================================================================
-- 2026-09-15 定稿与翻书预览 + 封面背景图池
-- 执行：mysql -u root -proot life_story < database/migrations/20260915_finalize_preview_coverbg.sql
-- =====================================================================

SET NAMES utf8mb4;

-- 1) ls_project：状态增加 MAKING(回忆制作中) + 预览/封面字段
ALTER TABLE `ls_project`
  MODIFY COLUMN `status` ENUM('UNPAID_LOCKED','EDITABLE','MAKING','DONE') NOT NULL DEFAULT 'UNPAID_LOCKED' COMMENT '项目状态';

ALTER TABLE `ls_project`
  ADD COLUMN `preview_token` VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '翻书预览访问令牌' AFTER `status`,
  ADD COLUMN `preview_url`   VARCHAR(512) NOT NULL DEFAULT '' COMMENT '翻书预览地址'     AFTER `preview_token`,
  ADD COLUMN `cover_bg`      VARCHAR(512) NOT NULL DEFAULT '' COMMENT '封面背景图地址'   AFTER `preview_url`,
  ADD COLUMN `finalized_at`  DATETIME DEFAULT NULL COMMENT '定稿时间' AFTER `cover_bg`;

-- 2) 封面背景图池（后台维护，用户未上传封面时随机抽取一张）
CREATE TABLE IF NOT EXISTS `ls_cover_bg` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(100) NOT NULL DEFAULT ''  COMMENT '标题',
  `image`      VARCHAR(255) NOT NULL DEFAULT ''  COMMENT '图片地址',
  `sort`       INT          NOT NULL DEFAULT 0   COMMENT '排序，倒序',
  `status`     TINYINT      NOT NULL DEFAULT 1   COMMENT '状态 1=启用 0=停用',
  `remark`     VARCHAR(255) NOT NULL DEFAULT ''  COMMENT '备注',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='封面背景图池';

-- 3) 后台菜单：回忆录管理 → 封面背景
INSERT INTO `ls_admin_menu` (`pid`,`title`,`icon`,`href`,`target`,`sort`,`status`,`remark`,`created_at`,`updated_at`)
SELECT 1, '封面背景', 'fa fa-photo', '/admin/page/coverbg.html', '_self', 80, 1, '用户未上传封面时随机抽取', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_admin_menu` WHERE `href` = '/admin/page/coverbg.html');
