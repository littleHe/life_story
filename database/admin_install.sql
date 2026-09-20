-- =====================================================================
-- 回忆录系统 · 后台管理模块 建表脚本
-- 执行：mysql -u root -proot life_story < database/admin_install.sql
-- 说明：本脚本只新增后台相关表，不影响前台 ls_* 业务表
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 后台管理员
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ls_admin` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL                COMMENT '登录账号',
  `password`      VARCHAR(255) NOT NULL                COMMENT '密码（password_hash 加密）',
  `nickname`      VARCHAR(50)  NOT NULL DEFAULT ''     COMMENT '昵称',
  `avatar`        VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '头像地址',
  `status`        TINYINT      NOT NULL DEFAULT 1      COMMENT '状态 1=启用 0=禁用',
  `login_num`     INT UNSIGNED NOT NULL DEFAULT 0      COMMENT '登录次数',
  `last_login_at` DATETIME              DEFAULT NULL   COMMENT '最后登录时间',
  `last_login_ip` VARCHAR(64)  NOT NULL DEFAULT ''     COMMENT '最后登录IP',
  `remark`        VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '备注',
  `created_at`    DATETIME              DEFAULT NULL,
  `updated_at`    DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台管理员';

-- ---------------------------------------------------------------------
-- 后台菜单（数据库驱动，icon 存 Font Awesome 类名，如 fa fa-book）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ls_admin_menu` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pid`        INT UNSIGNED NOT NULL DEFAULT 0      COMMENT '父级ID，0=顶级',
  `title`      VARCHAR(50)  NOT NULL                COMMENT '菜单名称',
  `icon`       VARCHAR(64)  NOT NULL DEFAULT ''     COMMENT '图标 class，如 fa fa-book',
  `href`       VARCHAR(191) NOT NULL DEFAULT ''     COMMENT '页面地址，如 /admin/page/chapter.html',
  `target`     VARCHAR(20)  NOT NULL DEFAULT '_self' COMMENT '打开方式',
  `sort`       INT          NOT NULL DEFAULT 0      COMMENT '排序，倒序',
  `status`     TINYINT      NOT NULL DEFAULT 1      COMMENT '状态 1=显示 0=隐藏',
  `remark`     VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '备注',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台菜单';

-- ---------------------------------------------------------------------
-- 首页幻灯片
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ls_slide` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(100) NOT NULL DEFAULT ''     COMMENT '标题',
  `image`      VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '图片地址',
  `link`       VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '跳转链接',
  `sort`       INT          NOT NULL DEFAULT 0      COMMENT '排序，倒序',
  `status`     TINYINT      NOT NULL DEFAULT 1      COMMENT '状态 1=启用 0=停用',
  `remark`     VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '备注',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='H5首页幻灯片';

-- ---------------------------------------------------------------------
-- 封面背景图池（用户未上传封面时随机抽取一张作为封面背景）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ls_cover_bg` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(100) NOT NULL DEFAULT ''     COMMENT '标题',
  `image`      VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '图片地址',
  `sort`       INT          NOT NULL DEFAULT 0      COMMENT '排序，倒序',
  `status`     TINYINT      NOT NULL DEFAULT 1      COMMENT '状态 1=启用 0=停用',
  `remark`     VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '备注',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='封面背景图池';

-- ---------------------------------------------------------------------
-- 默认章节（新建回忆录兑换解锁后，按本列表「启用中」条目自动播种到项目）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ls_default_chapter` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(100) NOT NULL                COMMENT '章节标题',
  `sort`       INT          NOT NULL DEFAULT 0      COMMENT '排序，倒序',
  `status`     TINYINT      NOT NULL DEFAULT 1      COMMENT '状态 1=启用 0=停用',
  `remark`     VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '备注',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='默认章节（新建回忆录时自动生成）';

-- ---------------------------------------------------------------------
-- 接口访问日志（用户行为）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ls_api_log` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '用户ID，0=未登录',
  `method`       VARCHAR(10)  NOT NULL DEFAULT ''   COMMENT '请求方法',
  `path`         VARCHAR(191) NOT NULL DEFAULT ''   COMMENT '请求路径',
  `ip`           VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '客户端IP',
  `user_agent`   VARCHAR(255) NOT NULL DEFAULT ''   COMMENT 'UA',
  `status_code`  SMALLINT     NOT NULL DEFAULT 0    COMMENT 'HTTP状态码',
  `duration_ms`  INT UNSIGNED NOT NULL DEFAULT 0    COMMENT '耗时(毫秒)',
  `request_body` TEXT                  DEFAULT NULL COMMENT '请求体(截断)',
  `created_at`   DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='接口访问日志';

-- =====================================================================
-- 菜单种子数据（icon 使用 Font Awesome 4.7 类名）
-- =====================================================================
DELETE FROM `ls_admin_menu`;
INSERT INTO `ls_admin_menu` (`id`,`pid`,`title`,`icon`,`href`,`target`,`sort`,`status`,`remark`,`created_at`,`updated_at`) VALUES
(1, 0, '回忆录管理',           'fa fa-book',          '',                               '_self', 90, 1, '', NOW(), NOW()),
(2, 1, '系统章节',             'fa fa-list-ol',       '/admin/page/chapter.html',       '_self', 90, 1, '章节内容与状态管理', NOW(), NOW()),

(3, 0, '用户管理',             'fa fa-users',         '',                               '_self', 80, 1, '', NOW(), NOW()),
(4, 3, '授权用户',             'fa fa-user-circle-o', '/admin/page/user.html',          '_self', 90, 1, '管理授权用户', NOW(), NOW()),
(5, 3, '用户行为(接口日志)',   'fa fa-history',       '/admin/page/apilog.html',        '_self', 80, 1, '接口 log', NOW(), NOW()),

(6, 0, '兑换码管理',           'fa fa-ticket',        '',                               '_self', 70, 1, '', NOW(), NOW()),
(7, 6, '兑换码',               'fa fa-key',           '/admin/page/code.html',          '_self', 90, 1, '生成与状态管理', NOW(), NOW()),
(8, 6, '兑换码日志',           'fa fa-clipboard',     '/admin/page/codelog.html',       '_self', 80, 1, '核销与锁定记录', NOW(), NOW()),

(9, 0, '运营管理',             'fa fa-picture-o',     '',                               '_self', 60, 1, '', NOW(), NOW()),
(10, 9, '首页幻灯片',          'fa fa-image',         '/admin/page/slide.html',         '_self', 90, 1, '用于首页幻灯片', NOW(), NOW()),

(11, 0, '系统管理',            'fa fa-cog',           '',                               '_self', 50, 1, '', NOW(), NOW()),
(12, 11,'菜单管理',            'fa fa-bars',          '/admin/page/menu.html',          '_self', 90, 1, '支持图标选择', NOW(), NOW()),
(13, 11,'管理员',              'fa fa-user-secret',   '/admin/page/adminuser.html',     '_self', 80, 1, '', NOW(), NOW()),
(14, 1, '封面背景',            'fa fa-photo',         '/admin/page/coverbg.html',       '_self', 80, 1, '用户未上传封面时随机抽取', NOW(), NOW()),
(15, 1, '默认章节',            'fa fa-list',          '/admin/page/defaultchapter.html','_self', 85, 1, '新建回忆录时自动生成', NOW(), NOW());
