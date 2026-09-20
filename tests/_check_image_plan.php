<?php
/**
 * 配图提示词方案自检（年代 → 画风）—— 不消耗混元生图额度，只跑「人物基线 + 年代判断 + 画风映射」。
 *
 * 用法：
 *   php tests/_check_image_plan.php                  # 用内置样例章节
 *   php tests/_check_image_plan.php <project_id>     # 用数据库里某个项目的真实章节
 *
 * 重点看两件事：
 *   ① era 判断是否合理（内容里的年代线索 → 十年一档的年代）
 *   ② style 是否随年代变化（画风由本地表决定：AiGatewayService::ERA_STYLES）
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use app\service\AiGatewayService;
use app\service\ChapterIllustrationService;
use think\facade\Db;

$projectId = (int) ($argv[1] ?? 0);

echo "\n================================================================\n";
echo " 配图提示词方案自检 · 年代 → 画风\n";
echo "================================================================\n";

$profile = [
    'name'        => '何天霸',
    'gender'      => 'male',
    'birth'       => '1932-02-02',
    'description' => '战斗老兵',
];
$person = AiGatewayService::personBrief($profile);
echo "人物外观基线：{$person}\n";

$samples = [];
if ($projectId > 0) {
    $project = Db::name('ls_project')->where('id', $projectId)->find() ?: [];
    echo "项目：{$projectId} / " . (string) ($project['real_name'] ?? '') . "\n";
    $profile['birth']       = (string) ($project['birth'] ?? $profile['birth']);
    $profile['gender']      = (string) ($project['gender'] ?? $profile['gender']);
    $profile['description'] = (string) ($project['description'] ?? $profile['description']);
    $person = AiGatewayService::personBrief($profile);
    foreach (Db::name('ls_chapter')->where('project_id', $projectId)->order('sort', 'asc')->select()->toArray() as $c) {
        $samples[] = ['title' => (string) $c['title'], 'text' => ChapterIllustrationService::transcriptOf((int) $c['id'])];
    }
} else {
    $samples = [
        ['title' => '童年时光', 'text' => '我七岁那年，家里住的是土墙老屋，天不亮就得起来烧水做饭，吃完翻山越岭去上学，先生姓陈，很严厉。'],
        ['title' => '青春年华', 'text' => '上高中那年我们搬到了市区，为了读书，父亲把家里仅有的一头牛卖了。'],
        ['title' => '参军入伍', 'text' => '那年我十九岁，瞒着家里报名参军，戴上红花坐上闷罐车，一坐就是三天三夜。'],
        ['title' => '改革开放', 'text' => '分田到户以后，家里第一次有了余粮，我攒钱买了一台收音机，全村子的人都来听。'],
        ['title' => '闲适晚年', 'text' => '现在每天帮孩子带孙子，去公园打太极，用手机跟老战友视频聊天。'],
    ];
}

$birthYear = ChapterIllustrationService::birthYearOf(['birth' => (string) $profile['birth']]);
echo '出生年份：' . ($birthYear ?: '（未知）') . "\n";
echo "兜底年代（出生年 +25）：" . (AiGatewayService::eraFromYear($birthYear + 25) ?: '（无）') . "\n";

foreach ($samples as $i => $s) {
    $t0   = microtime(true);
    // 两步：先判断年代（画风由年代定）→ 按年代算人物年龄 → 拼提示词
    $plan = AiGatewayService::imagePlanForChapter($s['title'], $s['text'], $birthYear, (string) ($profile['name'] ?? ''));
    $who  = AiGatewayService::personBrief($profile, (int) $plan['age_hint']);
    $full = AiGatewayService::composeImagePrompt($plan, $who);
    $dt   = round(microtime(true) - $t0, 1);
    echo "\n----------------------------------------------------------------\n";
    echo '【' . ($i + 1) . "】《{$s['title']}》（{$dt}s）\n";
    echo ' era   : ' . ($plan['era'] !== '' ? $plan['era_label'] : '（未判断出，用通用画风）') . "\n";
    echo ' age   : ' . ($plan['age_hint'] ? $plan['age_hint'] . ' 岁' : '（未推算，用当前年龄）') . "\n";
    echo ' style : ' . $plan['style'] . "\n";
    echo ' scene : ' . $plan['scene'] . "\n";
    echo ' 人物  : ' . $who . "\n";
    echo ' prompt: ' . mb_substr($full, 0, 170) . (mb_strlen($full) > 170 ? '…' : '') . "\n";
}

echo "\n----------------------------------------------------------------\n";
$cover = AiGatewayService::imagePlanForCover($profile, (string) ($profile['name'] ?? ''));
echo "【封面】\n era   : " . $cover['era_label'] . "\n style : " . $cover['style'] . "\n prompt: " . $cover['prompt'] . "\n";

echo "\n（本命令不调用混元生图，不消耗额度）\n\n";
