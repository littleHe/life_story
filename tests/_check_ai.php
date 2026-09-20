<?php
/**
 * AI 通道真实连通性自检
 *
 * 用法：
 *   php tests/_check_ai.php            # 自检 guidance 通道（缺省）
 *   php tests/_check_ai.php llm        # 换成 llm / asr / tts / image
 *   php tests/_check_ai.php guidance --live   # 即使 AI_MOCK=true 也强制发一次真实请求
 *
 * 为什么需要它：AiGatewayService 在「mock 开关打开 / 通道 api 为空 / 第三方异常 / 空返回」
 * 这四种情况下都会静默回退成本地 mock 文案，页面上照样有气泡、看不出差别。
 * 本脚本把「当前到底走没走真实接口」明确打印出来，并真实发一次 HTTP 验证 key 是否有效。
 *
 * 注意：本机 `php think run`（PHP 内置服务器）每个请求都会重新载入 .env，改完刷新页面即生效，无需重启；
 *       若部署到常驻进程（php-fpm/opcache 预热 + 常驻框架）则需重启进程。
 *
 * 语音识别（channel=asr）走的是腾讯云专有签名接口，请改用：php tests/_check_asr_tencent.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

$argvList = $argv ?? [];
$channel  = 'guidance';
foreach ($argvList as $i => $a) {
    if ($i > 0 && substr($a, 0, 2) !== '--') {
        $channel = $a;
        break;
    }
}
$forceLive = in_array('--live', $argvList, true);

// asr 通道在腾讯驱动下不是通用 JSON 协议，专用的自检脚本更准
if ($channel === 'asr' && (string) \think\facade\Config::get('ai.asr.driver', 'generic') === 'tencent') {
    echo "\n[asr 通道] 当前 driver=tencent（录音文件识别极速版），请改用专用自检：\n";
    echo "  php tests/_check_asr_tencent.php            # 真实识别最近一段录音\n";
    echo "  php tests/_check_asr_tencent.php --sign     # 只打印签名原文\n\n";
    exit(0);
}

$mask = static function (string $s): string {
    if ($s === '') {
        return '(未配置)';
    }
    $len = strlen($s);
    return substr($s, 0, 6) . str_repeat('*', max(0, $len - 10)) . substr($s, -4) . " (len={$len})";
};

$mockSwitch = \think\facade\Config::get('ai.mock');
$cfg        = (array) \think\facade\Config::get('ai.' . $channel, []);
$isMock     = \app\service\AiGatewayService::isMock($channel);
$sslVerify  = \think\facade\Config::get('ai.ssl_verify');
$caFile     = \app\service\AiGatewayService::caFile();

$line = str_repeat('=', 62);
echo "\n{$line}\n AI 通道自检 · channel = {$channel}\n{$line}\n";
echo ' AI_MOCK (env 总开关)   : ' . var_export($mockSwitch, true) . "\n";
echo ' isMock() 结论          : ' . ($isMock ? 'true  ← 当前走本地 mock' : 'false ← 调用真实接口') . "\n";
echo ' ' . str_pad($channel . '.api', 22) . ': ' . (($cfg['api'] ?? '') !== '' ? $cfg['api'] : '(未配置)') . "\n";
echo ' ' . str_pad($channel . '.model', 22) . ': ' . (($cfg['model'] ?? '') !== '' ? $cfg['model'] : '(未配置)') . "\n";
echo ' ' . str_pad($channel . '.key', 22) . ': ' . $mask((string) ($cfg['key'] ?? '')) . "\n";
echo ' ' . str_pad($channel . '.timeout', 22) . ': ' . (string) ($cfg['timeout'] ?? '-') . "s\n";
echo ' AI_SSL_VERIFY          : ' . var_export($sslVerify, true) . "\n";
echo ' CA 根证书              : ' . ($caFile !== ''
    ? $caFile
    : '(未找到 → https 请求会失败；可放置 backend/runtime/ca/cacert.pem，或设 AI_SSL_VERIFY=false 排障)') . "\n";

if ($isMock) {
    $why = [];
    if ($mockSwitch) {
        $why[] = 'AI_MOCK=true（总开关打开，所有通道一律走 mock）';
    }
    if ((string) ($cfg['api'] ?? '') === '') {
        $why[] = $channel . '.api 为空';
    }
    echo "\n【结论】引导气泡文案来自本地 mock 模板，没有调用第三方接口。\n";
    echo '【原因】' . (implode('；', $why) ?: '未知') . "\n";
    echo "【怎么开真实调用】\n";
    echo "  1) 编辑 backend/.env：\n";
    echo "       AI_MOCK=false\n";
    echo "       AI_GUIDANCE_API=https://api.deepseek.com/chat/completions\n";
    echo "       AI_GUIDANCE_KEY=sk-你的key\n";
    echo "       AI_GUIDANCE_MODEL=deepseek-chat\n";
    echo "  2) 重启后端（.env 在启动时载入）：Ctrl+C 后重新 php think run -p 9411\n";
    echo "  3) 再跑一次本脚本确认 isMock() 变成 false\n";
} else {
    echo "\n【配置结论】已就绪，会走真实接口。\n";
}

// ---- 真实连通性测试（配置就绪时自动执行；否则需 --live 强制）----
$api = (string) ($cfg['api'] ?? '');
$key = (string) ($cfg['key'] ?? '');
if ($api === '' || $key === '') {
    if (!$forceLive) {
        echo "\n[真实连通性测试] 跳过（api 或 key 未配置）\n\n";
        exit(0);
    }
    echo "\n[真实连通性测试] 强制执行，但 api/key 未配置，结果必然失败\n";
}

echo "\n{$line}\n 真实连通性测试 → POST {$api}\n{$line}\n";
$payload = json_encode([
    'model'       => (string) ($cfg['model'] ?: 'deepseek-chat'),
    'temperature' => 0.85,
    'messages'    => [
        ['role' => 'system', 'content' => '你是为回忆录采集服务的「口述引导助手」，用简体中文、口语化、温暖，给出 2-4 句极简引导。'],
        ['role' => 'user', 'content' => "章节标题：《童年时光》。\n长辈还没有开始讲这一章。请给一些可以从哪些具体小事聊起的引导。"],
    ],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init($api);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => (int) ($cfg['timeout'] ?: 30),
]);
// 与 AiGatewayService::post 保持一致的证书策略，避免「脚本通过、服务失败」的假象
if (stripos($api, 'https://') === 0) {
    if ($sslVerify === false) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    } elseif ($caFile !== '') {
        curl_setopt($ch, CURLOPT_CAINFO, $caFile);
    }
}
$t0   = microtime(true);
$resp = curl_exec($ch);
$err  = curl_error($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ms   = (int) round((microtime(true) - $t0) * 1000);
curl_close($ch);

echo " HTTP 状态 : {$code}\n";
echo " 耗时      : {$ms} ms\n";
if ($err !== '') {
    echo " curl 错误 : {$err}\n";
}

$j    = json_decode((string) $resp, true);
$text = (string) ($j['choices'][0]['message']['content'] ?? ($j['error']['message'] ?? ''));
if ($code === 200 && $text !== '') {
    echo " 返回文案  : " . mb_substr(str_replace("\n", ' / ', trim($text)), 0, 120) . "\n";
    echo "\n✅ 真实通道可用：填好 .env + AI_MOCK=false 并重启后，页面气泡即由该模型生成。\n";
    echo "   （提示：把 AI_MOCK=false 后，mock 兜底仍保留——第三方异常时自动退回本地文案，不会报错打断录音。）\n\n";
} else {
    echo " 返回内容  : " . mb_substr(preg_replace('/\s+/', ' ', (string) $resp), 0, 300) . "\n";
    echo "\n❌ 真实通道调用未成功：key / 网络 / 余额 / 模型名需检查。\n";
    echo "   注意：即使这里失败，页面气泡也不会报错——会自动退回本地 mock 文案，所以必须靠本脚本才能发现。\n\n";
    exit(1);
}
