<?php
/** 一次性探针：确认 ls_ai_task.task_type 与 ls_project 音色字段已就位（可删） */
require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

$t = \think\facade\Db::query("SHOW COLUMNS FROM ls_ai_task LIKE 'task_type'");
echo 'task_type = ' . $t[0]['Type'] . PHP_EOL;
$p = \think\facade\Db::query("SHOW COLUMNS FROM ls_project LIKE 'voice%'");
foreach ($p as $r) {
    echo 'project.' . $r['Field'] . ' = ' . $r['Type'] . PHP_EOL;
}
