<?php
/**
 * 迁移 + 种子：用户协议 / 隐私政策（ls_agreement）
 *
 * 背景：登录页需「勾选同意用户协议与隐私政策」才能授权登录，协议正文需由后台维护。
 *   原登录页底部只有一行写死的提示文字，没有可管理、可查看的协议内容。
 *
 * 本脚本幂等，可重复执行：
 *   1) 建表 ls_agreement（type 唯一，user=用户协议 / privacy=隐私政策）
 *   2) 写入两份常用协议种子文本（按 type 去重，UPDATE 不覆盖已有正文，便于后台改过的不被冲掉）
 *   3) 注册后台菜单「系统管理 → 用户协议与隐私」（按 href 去重）
 *
 * 运行：php database/migrations/20260923_agreement.php
 */
require __DIR__ . '/../../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use think\facade\Db;

// ---- 1) 建表 ----
Db::execute("CREATE TABLE IF NOT EXISTS `ls_agreement` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`       VARCHAR(20)  NOT NULL                COMMENT 'user=用户协议 / privacy=隐私政策',
  `title`      VARCHAR(120) NOT NULL DEFAULT ''     COMMENT '协议标题',
  `content`    LONGTEXT      NOT NULL               COMMENT '协议正文（纯文本，前端按换行渲染）',
  `status`     TINYINT      NOT NULL DEFAULT 1      COMMENT '状态 1=启用 0=停用',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户协议与隐私政策（后台可编辑）'");
echo "table ls_agreement ready\n";

// ---- 2) 种子文本（仅当该 type 不存在时插入；已存在则跳过，避免覆盖后台手动修改）----
$userContent = <<<'DOC'
《人生回忆录》用户协议

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
如对本协议有疑问，可通过应用内客服渠道与我们联系。
DOC;

$privacyContent = <<<'DOC'
《人生回忆录》隐私政策

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
如有隐私相关疑问，可通过应用内客服渠道与我们联系。
DOC;

$seeds = [
    'user'    => ['title' => '用户协议', 'content' => $userContent],
    'privacy' => ['title' => '隐私政策', 'content' => $privacyContent],
];

foreach ($seeds as $type => $s) {
    $exist = Db::name('ls_agreement')->where('type', $type)->find();
    if ($exist) {
        echo "agreement [{$type}] exists, skip seed\n";
        continue;
    }
    Db::name('ls_agreement')->insert([
        'type'        => $type,
        'title'       => $s['title'],
        'content'     => $s['content'],
        'status'      => 1,
        'created_at'  => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);
    echo "seeded agreement [{$type}]\n";
}

// ---- 3) 后台菜单（系统管理 pid=11 下，按 href 去重）----
$href = '/admin/page/agreement.html';
$menuId = Db::name('ls_admin_menu')->where('href', $href)->value('id');
if (!$menuId) {
    $pid = (int) Db::name('ls_admin_menu')->where('title', '系统管理')->where('pid', 0)->value('id');
    $pid = $pid > 0 ? $pid : 11;
    Db::name('ls_admin_menu')->insert([
        'pid'        => $pid,
        'title'      => '用户协议与隐私',
        'icon'       => 'fa fa-file-text',
        'href'       => $href,
        'target'     => '_self',
        'sort'       => 80,
        'status'     => 1,
        'remark'     => '登录页协议正文（用户协议 / 隐私政策）',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    echo "added menu 用户协议与隐私 (pid={$pid})\n";
} else {
    echo "menu exists, skip\n";
}

echo "done.\n";
