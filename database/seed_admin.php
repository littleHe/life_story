<?php
/**
 * 后台管理员初始化 / 重置密码
 * 用法：php database/seed_admin.php [账号] [密码]
 *      默认：php database/seed_admin.php          → admin / admin888
 *            php database/seed_admin.php admin 123456
 *
 * 说明：密码用 password_hash(BCRYPT) 存储，绝不存明文。
 */

$root = dirname(__DIR__);
$env  = @parse_ini_file($root . '/.env', true) ?: [];

$host   = $env['DB_HOST'] ?? '127.0.0.1';
$port   = $env['DB_PORT'] ?? '3306';
$name   = $env['DB_NAME'] ?? 'life_story';
$user   = $env['DB_USER'] ?? 'root';
$pass   = $env['DB_PASS'] ?? '';
// 注意：本项目的模型/服务统一硬编码 `ls_` 表前缀，.env 里 DB_PREFIX 为空。
// 因此这里在未显式配置前缀时兜底为 ls_，避免落到 life_story.admin 这种不存在的表。
$prefix = trim($env['DB_PREFIX'] ?? '');
if ($prefix === '') {
    $prefix = 'ls_';
}

$username = $argv[1] ?? 'admin';
$password = $argv[2] ?? 'admin888';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "[ERROR] 数据库连接失败: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$table = $prefix . 'admin';
$hash  = password_hash($password, PASSWORD_BCRYPT);
$now   = date('Y-m-d H:i:s');

$sql = "INSERT INTO `{$table}` (`username`,`password`,`nickname`,`status`,`created_at`,`updated_at`)
        VALUES (:u, :p, :n, 1, :t, :t)
        ON DUPLICATE KEY UPDATE `password`=VALUES(`password`), `updated_at`=VALUES(`updated_at`)";

$stmt = $pdo->prepare($sql);
$stmt->execute([':u' => $username, ':p' => $hash, ':n' => '超级管理员', ':t' => $now]);

echo "OK  账号: {$username}" . PHP_EOL;
echo "OK  密码: {$password}" . PHP_EOL;
echo "OK  已写入 {$name}.{$table}" . PHP_EOL;
