<?php
/**
 * 迁移：ls_project 增加 interview_token（亲友免登录「访谈」录制页的公开凭证）
 *
 * - 由项目所有者在详情页点「生成访谈链接」时按需生成（bin2hex(random_bytes(16))）
 * - 公开接口 GET /api/interview/{token} 与 POST /api/interview/{token}/recording 凭此访问
 * - 幂等：字段/索引已存在时跳过
 *
 * 运行：php database/migrations/20260915_interview_token.php
 */
require __DIR__ . '/../../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use think\facade\Db;

$table  = 'ls_project';
$column = 'interview_token';
$index  = 'idx_interview_token';

$db = Db::connect();
$dbName = config('database.connections.mysql.database');

$hasCol = (int) $db->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
    [$dbName, $table, $column]
)[0]['c'];

if (!$hasCol) {
    $db->execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` varchar(64) NOT NULL DEFAULT '' COMMENT '亲友访谈链接 token（免登录录制凭证）' AFTER `preview_url`");
    echo "added column {$table}.{$column}\n";
} else {
    echo "column {$table}.{$column} exists, skip\n";
}

$hasIdx = (int) $db->query(
    "SELECT COUNT(*) c FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?",
    [$dbName, $table, $index]
)[0]['c'];

if (!$hasIdx) {
    $db->execute("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`{$column}`)");
    echo "added index {$index}\n";
} else {
    echo "index {$index} exists, skip\n";
}

echo "done\n";
