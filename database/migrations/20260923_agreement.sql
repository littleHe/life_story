-- ============================================================================
-- 用户协议 / 隐私政策：建表 + 种子数据（ls_agreement）
--
-- 用途：线上数据库缺少 ls_agreement 表时，登录页 GET /api/agreement 会返回
--       code:10501「Table 'xxx.ls_agreement' doesn't exist」，点《用户协议》《隐私政策》
--       拿不到正文。本文件与 20260923_agreement.php 完全等价，
--       供 Navicat / phpMyAdmin 直接执行（不依赖服务器 PHP 命令行）。
--
-- 幂等：可重复执行，已存在的记录不会被覆盖（后台改过的正文不会被冲掉）。
-- ============================================================================

-- ---- 1) 建表 ----
CREATE TABLE IF NOT EXISTS `ls_agreement` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`       VARCHAR(20)  NOT NULL                COMMENT 'user=用户协议 / privacy=隐私政策',
  `title`      VARCHAR(120) NOT NULL DEFAULT ''     COMMENT '协议标题',
  `content`    LONGTEXT      NOT NULL               COMMENT '协议正文（纯文本，前端按换行渲染）',
  `status`     TINYINT      NOT NULL DEFAULT 1      COMMENT '状态 1=启用 0=停用',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户协议与隐私政策（后台可编辑）';

-- ---- 2) 种子：用户协议（仅当不存在时插入）----
INSERT INTO `ls_agreement` (`type`, `title`, `content`, `status`, `created_at`, `updated_at`)
SELECT 'user', '用户协议', '《人生回忆录》用户协议

欢迎使用「人生回忆录」！在您使用本服务前，请仔细阅读以下条款。一旦您注册、登录或使用本服务，即视为您已阅读并同意本协议。

一、服务说明
「人生回忆录」是一款帮助用户整理、记录、生成个人与家庭回忆录的应用。您可以通过口述录音、文字编辑等方式，由本平台辅助生成结构化的回忆录内容、配音及配图。

二、账号与登录
1. 您通过微信授权登录本应用，登录即代表您授权本平台获取您的微信昵称与头像，用于生成回忆录封面与展示。
2. 请您妥善保管账号，因账号保管不善导致的损失由您自行承担。

三、内容创作与知识产权
1. 您在使用本服务过程中提供的口述内容、文字、图片等素材，其相关权利归您所有。
2. 由本平台基于您的素材生成、加工的回忆录文本、配音、配图等成果，在您完成付费或解锁后归您所有，您可自由保存与分享。
3. 您承诺所提供的内容不违反法律法规，不侵犯第三方合法权益，不含色情、暴力、虚假等违规信息。

四、用户行为规范
您不得利用本服务从事以下行为：
1. 上传违法、侵权或不良信息；
2. 攻击、干扰本服务的正常运行；
3. 逆向工程、破解或非法抓取本平台数据。

五、免责声明
1. 本平台生成内容由 AI 辅助完成，可能存在误差，仅供参考，不构成任何专业建议。
2. 因网络、设备、第三方服务（如微信、云服务商）等原因导致的服务中断或数据丢失，本平台将尽力恢复，但不承担因此造成的间接损失。

六、协议变更
本平台可能适时修订本协议，修订后将在应用内公示，继续使用即视为同意修订后的协议。

七、联系我们
如对本协议有疑问，可通过应用内客服渠道与我们联系。', 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_agreement` WHERE `type` = 'user');

-- ---- 3) 种子：隐私政策（仅当不存在时插入）----
INSERT INTO `ls_agreement` (`type`, `title`, `content`, `status`, `created_at`, `updated_at`)
SELECT 'privacy', '隐私政策', '《人生回忆录》隐私政策

我们非常重视您的个人信息保护。本政策说明您在「人生回忆录」中，我们如何收集、使用、存储和保护您的信息。

一、我们收集的信息
1. 账号信息：经您授权，我们从微信获取您的昵称、头像，用于登录与展示。
2. 内容素材：您主动上传或录制的口述音频、文字、照片，用于生成回忆录。
3. 生成数据：基于您的素材由 AI 生成的文本、配音、配图等成果。
4. 设备与日志：为排查问题，我们可能记录必要的设备型号、操作日志，不含敏感内容。

二、信息的使用
1. 为您提供回忆录生成、编辑、预览与分享服务；
2. 改进产品体验、保障服务安全；
3. 经您同意后用于必要的客服与通知。

三、第三方服务
本应用使用以下第三方服务，相关数据处理受其隐私政策约束：
1. 微信开放平台：用于授权登录；
2. 腾讯云（语音识别 / 语音合成 / AI 配图 / 声音复刻等）：用于音频转写、配音与配图生成。

四、信息存储与安全
1. 您的数据存储于我们的服务器，采用加密传输与访问控制等措施；
2. 我们仅在为您提供服务所必需的时间内保留您的信息，并在您删除或注销账号后按约定处理。

五、您的权利
您有权查阅、更正您的个人信息，并可要求删除您的账号及关联数据。您可通过应用内客服渠道提出请求。

六、未成年人保护
本服务主要面向成年人及家庭使用。如涉及未成年人信息，请您在监护下使用并确保已获相应授权。

七、政策变更
我们将适时更新本政策，重大变更将通过应用内公告。

八、联系我们
如有隐私相关疑问，可通过应用内客服渠道与我们联系。', 1, NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `ls_agreement` WHERE `type` = 'privacy');

-- ---- 4) 后台菜单：系统管理 → 用户协议与隐私（按 href 去重）----
INSERT INTO `ls_admin_menu`
  (`pid`, `title`, `icon`, `href`, `target`, `sort`, `status`, `remark`, `created_at`, `updated_at`)
SELECT
  -- 系统管理菜单 id；查不到时兜底 11（与 20260923_agreement.php 的兜底一致），避免插到根级
  COALESCE(
    (SELECT id FROM (SELECT id FROM `ls_admin_menu` WHERE `title` = '系统管理' AND `pid` = 0 LIMIT 1) t),
    11
  ),
  '用户协议与隐私', 'fa fa-file-text', '/admin/page/agreement.html', '_self', 80, 1,
  '登录页协议正文（用户协议 / 隐私政策）', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM `ls_admin_menu` WHERE `href` = '/admin/page/agreement.html') t2);

-- ---- 5) 自检：应返回 2 行（user / privacy）----
-- SELECT `type`, `title`, CHAR_LENGTH(`content`) AS chars FROM `ls_agreement`;
