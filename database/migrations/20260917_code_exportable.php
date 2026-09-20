<?php
/**
 * 迁移：兑换码「可事后/定期导出」支持
 *
 * 背景：原设计明文只存 sha256 + 掩码，生成成功弹窗关闭后即不可再导出。
 *      运营需要「生成多次后一次性汇出」或「定期汇出增量」，必须让明文可回取。
 *
 * 方案：生成时把明文用 AES-256-CBC 加密落库 code_cipher（密钥走 env CODE_CIPHER_KEY），
 *      导出接口解密后返回 CSV；exported_at 记录最近导出时间，支撑增量汇出。
 *      旧数据 code_cipher 为 NULL，无法回取，导出时自动跳过。
 *
 * 本脚本幂等，可重复执行：仅当列不存在时才 ALTER。
 *
 * 运行：php database/migrations/20260917_code_exportable.php
 */
require __DIR__ . '/../../vendor/autoload.php';

$app = new \think\App();
$app->initialize();

use think\facade\Db;

$table = 'ls_redemption_code';

$cols = [
    "code_cipher" => "ALTER TABLE `{$table}` ADD COLUMN `code_cipher` VARCHAR(512) NULL DEFAULT NULL COMMENT 'AES-256-CBC 加密的原始码明文(可回取，用于事后/定期导出)'",
    "batch_no"    => "ALTER TABLE `{$table}` ADD COLUMN `batch_no`    VARCHAR(32)  NULL DEFAULT NULL COMMENT '批次号：一次生成调用共享，便于分组导出'",
    "exported_at" => "ALTER TABLE `{$table}` ADD COLUMN `exported_at`  DATETIME     NULL DEFAULT NULL COMMENT '最近一次导出时间(NULL=从未导出，用于增量汇出)'",
];

foreach ($cols as $col => $sql) {
    $exists = Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    if (empty($exists)) {
        Db::execute($sql);
        echo "added column {$col}\n";
    } else {
        echo "column {$col} exists, skip\n";
    }
}

echo "done\n";
