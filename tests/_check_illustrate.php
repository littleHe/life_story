<?php
/**
 * 章节配图自检（腾讯云混元生图）
 *
 * 用法：
 *   php tests/_check_illustrate.php           # 只打印配置 + 生成两段提示词（不消耗额度）
 *   php tests/_check_illustrate.php --live    # 真生成一张图（消耗 1 次额度，约 10~60 秒）
 *
 * 覆盖链路：传主档案 → 人物外观基线（年龄自动推算）→ 章节场景提示词 → 混元生图 → 下载落盘
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use app\service\AiGatewayService;
use app\service\TencentImageService;

$live = in_array('--live', ($argv ?? []), true);
$cfg  = (array) config('ai.image.tencent', []);
$mask = static function (string $s): string {
    return $s === '' ? '(未配置)' : substr($s, 0, 6) . '******' . substr($s, -4);
};

echo "\n================================================================\n";
echo " 章节配图自检 · 腾讯云混元生图\n";
echo "================================================================\n";
echo ' driver         : ' . (string) config('ai.image.driver') . "\n";
echo ' product        : ' . (string) ($cfg['product'] ?? '(未配置→按 endpoint 推断)') . "\n";
echo ' endpoint       : ' . (string) ($cfg['endpoint'] ?? '') . ' / ' . (string) ($cfg['version'] ?? '') . "\n";
echo ' actions        : ' . (string) ($cfg['submit_action'] ?? '') . ' → ' . (string) ($cfg['query_action'] ?? '') . "\n";
echo ' SecretId       : ' . $mask((string) ($cfg['secret_id'] ?? '')) . "\n";
echo ' SecretKey      : ' . $mask((string) ($cfg['secret_key'] ?? '')) . "\n";
echo ' resolution     : ' . (string) ($cfg['resolution'] ?? '') . "\n";
echo ' revise / 头像参考: ' . (int) ($cfg['revise'] ?? 0) . ' / ' . (int) ($cfg['use_avatar'] ?? 0) . "\n";
echo ' isMock(image)  : ' . (AiGatewayService::isMock('image')
    ? 'true → 会退回本地图池随机'
    : 'false → 真实调用混元生图') . "\n";

$profile = [
    'name'        => '何天霸',
    'gender'      => 'male',
    'birth'       => '1949-08-01',
    'description' => '清远农村长大的老教师，教了四十年书',
];
$person = AiGatewayService::personBrief($profile);
echo "\n① 人物外观基线（年龄由出生年月推算：约 " . AiGatewayService::ageFromBirth($profile['birth']) . " 岁）\n";
echo "   {$person}\n";

$text   = '我小时候住在清远农村，家里一共五口人。父亲每天天不亮就下地干活，母亲在家织布。'
    . '七岁那年我上了村里的小学，先生姓陈，很严厉，会用戒尺。';
$prompt = AiGatewayService::imagePromptForChapter('童年时光', $text, $person);
echo "\n② 章节配图提示词\n";
echo "   {$prompt}\n";

if (!$live) {
    echo "\n（加 --live 会真实生成一张图，消耗 1 次额度）\n\n";
    exit(0);
}

echo "\n③ 提交混元生图并等待出图…\n";
$t0  = microtime(true);
$res = TencentImageService::generate($prompt);
echo '   status = ' . (string) ($res['status'] ?? '?') . '  job_id = ' . (string) ($res['job_id'] ?? '') . "\n";

if ((string) ($res['status'] ?? '') === 'done') {
    $url = TencentImageService::download((string) $res['url']);
    printf("   ✅ 已下载到 %s（总耗时 %d 秒）\n", $url, (int) round(microtime(true) - $t0));
    echo '   远端原图：' . (string) $res['url'] . "\n";
    if (!empty($res['revised_prompt'])) {
        echo '   扩写后的提示词：' . (string) $res['revised_prompt'] . "\n";
    }
} else {
    echo '   ⏳ 还没出图：把 AI_IMAGE_TENCENT_WAIT_MAX 调大，或等会儿重跑本命令（会按 job_id 续询）' . "\n";
}
echo "\n";
