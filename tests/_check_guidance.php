<?php
/**
 * 录音引导自检：验证两条 prompt 路径是否真的走通
 *   ① 本章没有录音（seed）→ 按「章节标题」给切入点
 *   ② 本章已有录音（continue）→ 把「整章文案」交给模型，给续接方向、且不重复已讲内容
 *
 * 用法：php tests/_check_guidance.php            # 两条都跑
 *       php tests/_check_guidance.php --seed     # 只跑无录音
 *       php tests/_check_guidance.php --continue # 只跑有录音
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use app\service\AiGatewayService;

$cfg = (array) config('ai.guidance', []);
echo "\n================================================================\n";
echo " 录音引导自检\n";
echo "================================================================\n";
echo ' AI_MOCK        : ' . (config('ai.mock') ? 'true' : 'false') . "\n";
echo ' guidance api   : ' . (trim((string) ($cfg['api'] ?? '')) !== '' ? '已配置' : '(未配置 → 会走本地兜底文案)') . "\n";
echo ' guidance model : ' . (string) ($cfg['model'] ?? '-') . "\n";

$only = (string) ($argv[1] ?? '');

if ($only !== '--continue') {
    echo "\n① [seed] 无录音 → 应按章节标题给切入点\n";
    $t0 = microtime(true);
    echo '  ' . str_replace("\n", "\n  ", AiGatewayService::guidance('童年时光', '')) . "\n";
    printf("  (%d ms)\n", (int) round((microtime(true) - $t0) * 1000));
}

if ($only !== '--seed') {
    echo "\n② [continue] 有录音 → 应按整章文案续接（不应重复已讲过的内容）\n";
    $full = "我小时候住在清远农村，家里一共五口人。\n"
        . "父亲每天天不亮就下地干活，母亲在家织布、喂鸡。\n"
        . "我七岁那年上了村里的小学，先生姓陈，很严厉，会用戒尺。\n"
        . "后来我学会了游泳，就在村口那条河里，是跟邻居阿强学的。";
    echo "  传入的整章文案（" . mb_strlen($full) . " 字）：\n";
    echo '  ' . str_replace("\n", "\n  ", $full) . "\n";
    echo "  --- 生成的引导 ---\n";
    $t0 = microtime(true);
    echo '  ' . str_replace("\n", "\n  ", AiGatewayService::guidance('童年时光', $full)) . "\n";
    printf("  (%d ms)\n", (int) round((microtime(true) - $t0) * 1000));
}

echo "\n";
