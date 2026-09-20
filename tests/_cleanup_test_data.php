<?php
// 清理冒烟测试产生的临时数据（仅测试账号 uid=2 的项目）
// 用法：php backend/tests/_cleanup_test_data.php [pid...]
$dsn = 'mysql:host=127.0.0.1;port=3306;dbname=life_story;charset=utf8mb4';
$pdo = new PDO($dsn, 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$ids = array_slice($argv, 1);
if (!$ids) {
    // 列出 uid=2 的项目，供人工确认
    $rows = $pdo->query("SELECT id,name,status,created_at FROM ls_project WHERE user_id=2 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo "pid={$r['id']}\t{$r['status']}\t{$r['name']}\t{$r['created_at']}\n";
    }
    exit(0);
}

foreach ($ids as $pid) {
    $pid = (int) $pid;
    $cids = $pdo->query("SELECT id FROM ls_chapter WHERE project_id={$pid}")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cids as $cid) {
        $pdo->exec("DELETE FROM ls_chapter_asset WHERE chapter_id={$cid}");
        try { $pdo->exec("DELETE FROM ls_ai_task WHERE chapter_id={$cid}"); } catch (Throwable $e) {}
    }
    $pdo->exec("DELETE FROM ls_chapter WHERE project_id={$pid}");
    $pdo->exec("DELETE FROM ls_project WHERE id={$pid}");
    echo "cleaned pid={$pid} chapters=" . count($cids) . "\n";
}
