<?php
/**
 * 确保存在一个「不限次」测试兑换码（max_uses=0），供 e2e 脚本建项目使用；打印明码。
 * 幂等：已存在则直接输出，不重复插入。
 *
 * 用法：php tests/_ensure_test_code.php   → stdout 打印明码（如 TESTUNLIMITED2026）
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use think\facade\Db;

$code = 'TESTUNLIMITED2026';
$hash = hash('sha256', strtoupper(trim($code)));

$row = Db::name('ls_redemption_code')->where('code_hash', $hash)->find();
if (!$row) {
    Db::name('ls_redemption_code')->insert([
        'code_hash'  => $hash,
        'code_mask'  => 'TEST',
        'status'     => 'GENERATED',
        'max_uses'   => 0,   // 0 = 不限次（测试码）
        'used_count' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

echo $code;
