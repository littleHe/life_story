<?php
/**
 * 迁移 + 种子：默认章节管理（ls_default_chapter）
 *
 * 背景：新建回忆录（兑换解锁）成功后需自动播种一批默认章节，原先写死在
 *      ProjectController::DEFAULT_CHAPTERS 常量里，改由后台「回忆录管理 → 默认章节」维护。
 *
 * 本脚本幂等，可重复执行：
 *   1) 建表 ls_default_chapter（不存在才建）
 *   2) 注册后台菜单「默认章节」（按 href 去重）
 *   3) 写入 6 条初始默认章节（按 title 去重）
 *
 * 运行：php database/migrations/20260915_default_chapter.php
 */
require __DIR__ . '/../../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use think\facade\Db;

// ---- 1) 建表 ----
Db::execute("CREATE TABLE IF NOT EXISTS `ls_default_chapter` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(100) NOT NULL                COMMENT '章节标题',
  `sort`       INT          NOT NULL DEFAULT 0      COMMENT '排序，倒序',
  `status`     TINYINT      NOT NULL DEFAULT 1      COMMENT '状态 1=启用 0=停用',
  `remark`     VARCHAR(255) NOT NULL DEFAULT ''     COMMENT '备注',
  `created_at` DATETIME              DEFAULT NULL,
  `updated_at` DATETIME              DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='默认章节（新建回忆录时自动生成）'");
echo "table ls_default_chapter ready\n";

// ---- 2) 菜单（挂在「回忆录管理」pid=1 下，按 href 去重）----
$href = '/admin/page/defaultchapter.html';
$menuId = Db::name('ls_admin_menu')->where('href', $href)->value('id');
if (!$menuId) {
    $pid = (int) Db::name('ls_admin_menu')->where('title', '回忆录管理')->where('pid', 0)->value('id');
    // 兜底：找不到父级就用 1
    $pid = $pid > 0 ? $pid : 1;
    Db::name('ls_admin_menu')->insert([
        'pid'        => $pid,
        'title'      => '默认章节',
        'icon'       => 'fa fa-list',
        'href'       => $href,
        'target'     => '_self',
        'sort'       => 85,
        'status'     => 1,
        'remark'     => '新建回忆录时自动生成',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    echo "added menu 默认章节 (pid={$pid})\n";
} else {
    echo "menu exists, skip\n";
}

// ---- 3) 种子数据（按 title 去重；sort 倒序 → 数字越大越靠前）----
$seeds = [
    '童年时光' => 60,
    '青春年华' => 50,
    '成家立业' => 40,
    '奋斗岁月' => 30,
    '闲适晚年' => 20,
    '人生感悟' => 10,
];
$inserted = 0;
foreach ($seeds as $title => $sort) {
    if (Db::name('ls_default_chapter')->where('title', $title)->find()) {
        continue;
    }
    Db::name('ls_default_chapter')->insert([
        'title'      => $title,
        'sort'       => $sort,
        'status'     => 1,
        'remark'     => '',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    $inserted++;
}
echo "seeded default chapters: {$inserted}\n";
echo "done\n";
