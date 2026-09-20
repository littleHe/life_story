<?php
/**
 * 润色自检：验证「全书上下文 + 逐章分别生成」这条路径
 *   只润色其中一章，看它是否沿用了其它章节的人名称谓（说明上下文真的传进去了）
 *
 * 用法：php tests/_check_polish.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use app\service\AiGatewayService;

$items = [
    ['title' => '童年时光', 'text' => '我小时候住在清远农村，家里一共五口人。父亲叫何天霸，会写毛笔字；母亲在家织布。'],
    ['title' => '青春年华', 'text' => '十八岁那年我去了广州的纺织厂当学徒，师傅姓陈。'],
    ['title' => '成家立业', 'text' => '二十五岁我娶了邻村的阿珍，第二年有了大儿子。'],
];

echo "\n================================================================\n";
echo " 润色自检（全书上下文 + 逐章分别生成）\n";
echo "================================================================\n";
echo ' AI_MOCK : ' . (config('ai.mock') ? 'true' : 'false') . "\n\n";

$ctx = AiGatewayService::bookContext($items);
echo "① 拼装出的全书上下文（这是要喂给模型的）\n";
echo '  ' . str_replace("\n", "\n  ", $ctx) . "\n";

echo "\n② 只润色《青春年华》一章，看是否用上了跨章信息（何天霸 / 阿珍）\n";
$t0  = microtime(true);
$out = AiGatewayService::polish('青春年华', $items[1]['text'], $ctx);
printf("  耗时 %d ms\n  --- 润色结果 ---\n", (int) round((microtime(true) - $t0) * 1000));
echo '  ' . str_replace("\n", "\n  ", $out) . "\n\n";
