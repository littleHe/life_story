<?php
/**
 * 腾讯云语音识别（录音文件识别极速版）真实连通性自检
 *
 * 用法：
 *   php tests/_check_asr_tencent.php                       # 用最近的录音做一次真实识别
 *   php tests/_check_asr_tencent.php uploads/audio/x.m4a   # 指定音频（相对 backend/public）
 *   php tests/_check_asr_tencent.php --sign                # 只打印签名原文/URL，不发请求
 *   php tests/_check_asr_tencent.php --raw uploads/audio/x.m4a   # 打印腾讯原始 JSON 返回
 *   php tests/_check_asr_tencent.php --gateway uploads/audio/x.webm  # 走完整网关（含 ffmpeg 转码）
 *
 * 默认直连 TencentAsrService，跳过网关的格式预处理 → 传 webm 必得 4007（这是真实结论，不是 bug）；
 * 想验证「webm 能否被自动转码后送识别」，加 --gateway（需 AI_ASR_FFMPEG 或 PATH 里有 ffmpeg）。
 *
 * 为什么需要它：AiGatewayService 在「mock 总开关 / 凭据不全 / 第三方异常」时会静默回退本地 mock
 * 文案，页面上照样出文字、看不出差别。本脚本把「到底有没有真的调用腾讯」明确打出来。
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use think\facade\Config;

// .env 语法护栏：PHP ini 解析器对注释里的竖线等字符很敏感，一旦失败整个 .env 被丢弃
// → 所有环境变量静默回落默认值（表现为「配置明明填了却一直走 mock」）。定位：php tests/_check_env.php
$envFile   = dirname(__DIR__) . '/.env';
$envBroken = is_file($envFile) && parse_ini_file($envFile, true, INI_SCANNER_RAW) === false;

$argvList = $argv ?? [];
$signOnly = in_array('--sign', $argvList, true);
$showRaw  = in_array('--raw', $argvList, true);
$gateway  = in_array('--gateway', $argvList, true);   // 走 AiGatewayService（含 webm→wav 转码）
$audioArg = '';
foreach (array_slice($argvList, 1) as $a) {
    if (substr($a, 0, 2) !== '--') {
        $audioArg = $a;
        break;
    }
}

$mask = static function (string $s): string {
    if ($s === '') {
        return '(未配置)';
    }
    $len = strlen($s);
    return substr($s, 0, 6) . str_repeat('*', max(0, $len - 10)) . substr($s, -4) . " (len={$len})";
};

$cfg       = (array) Config::get('ai.asr', []);
$t         = (array) ($cfg['tencent'] ?? []);
$driver    = (string) ($cfg['driver'] ?? 'generic');
$mockSwitch = Config::get('ai.mock');
$isMock    = \app\service\AiGatewayService::isMock('asr');
$caFile    = \app\service\AiGatewayService::caFile();
$ffmpeg    = \app\service\AiGatewayService::ffmpegPath();

$line = str_repeat('=', 64);
echo "\n{$line}\n 腾讯云 ASR 自检 · 录音文件识别极速版\n{$line}\n";
echo ' AI_MOCK (env 总开关)     : ' . var_export($mockSwitch, true) . "\n";
echo ' .env 解析               : ' . ($envBroken
    ? "❌ 失败（环境变量全部丢失，必然走 mock）→ 跑 php tests/_check_env.php 定位到行"
    : '正常') . "\n";
echo ' asr.driver              : ' . $driver . "\n";
echo ' 腾讯 AppID              : ' . ((string) ($t['appid'] ?? '') !== '' ? $t['appid'] : '(未配置)') . "\n";
echo ' 腾讯 SecretId           : ' . $mask((string) ($t['secret_id'] ?? '')) . "\n";
echo ' 腾讯 SecretKey          : ' . $mask((string) ($t['secret_key'] ?? '')) . "\n";
echo ' 引擎 engine_type        : ' . ((string) ($t['engine'] ?? '') !== '' ? $t['engine'] : '(未配置，默认 16k_zh)') . "\n";
echo ' 临时热词表              : ' . ((string) ($t['hotwords'] ?? '') !== '' ? $t['hotwords'] : '(未配置)') . "\n";
echo ' isMock() 结论           : ' . ($isMock ? 'true  ← 当前走本地 mock 文案' : 'false ← 会真实调用腾讯云') . "\n";
echo ' CA 根证书               : ' . ($caFile !== '' ? $caFile : '(未找到 → https 会失败)') . "\n";
echo ' ffmpeg                  : ' . ($ffmpeg !== '' ? $ffmpeg : '(未找到 → webm 录音无法自动转码)') . "\n";

// ---- 签名原文预览（排查 4001/4002 用）----
$dbg = \app\service\TencentAsrService::debugSignature('m4a');
echo "\n{$line}\n 签名原文（timestamp 固定为样例值，仅用于核对参数拼法）\n{$line}\n";
echo $dbg['sign_origin'] . "\n";

if ($signOnly) {
    echo "\n（--sign：未发起真实请求）\n\n";
    exit(0);
}

if ($isMock) {
    $why = [];
    if ($mockSwitch) {
        $why[] = 'AI_MOCK=true（总开关打开，全部通道走 mock）';
    }
    if (trim((string) ($t['appid'] ?? '')) === '') {
        $why[] = 'AI_ASR_TENCENT_APPID 为空';
    }
    if (trim((string) ($t['secret_id'] ?? '')) === '') {
        $why[] = 'AI_ASR_TENCENT_SECRET_ID 为空';
    }
    if (trim((string) ($t['secret_key'] ?? '')) === '') {
        $why[] = 'AI_ASR_TENCENT_SECRET_KEY 为空';
    }
    echo "\n【结论】当前「录音转文字」返回的是本地 mock 文案，没有调用腾讯云。\n";
    echo '【原因】' . (implode('；', $why) ?: '未知') . "\n";
    echo "【怎么开真实调用】\n";
    echo "  1) 编辑 backend/.env：\n";
    echo "       AI_MOCK=false\n";
    echo "       AI_ASR_DRIVER=tencent\n";
    echo "       AI_ASR_TENCENT_APPID=1400622226\n";
    echo "       AI_ASR_TENCENT_SECRET_ID=你的SecretId\n";
    echo "       AI_ASR_TENCENT_SECRET_KEY=你的SecretKey\n";
    echo "     （SecretId/SecretKey 在「访问管理 → API 密钥管理」新建，只需在控制台看一次，务必抄下来）\n";
    echo "  2) 再跑一次本脚本：php tests/_check_asr_tencent.php\n\n";
    exit(1);
}

// ---- 挑一个待识别音频 ----
$pubRoot = dirname(__DIR__) . '/public/';
$audio = '';
if ($audioArg !== '') {
    $audio = is_file($audioArg) ? $audioArg : $pubRoot . ltrim($audioArg, '/\\');
} else {
    $files = glob($pubRoot . 'uploads/audio/*.{wav,m4a,mp3,ogg,mp4,aac,amr,pcm,webm}', GLOB_BRACE) ?: [];
    // ⚠️ 跳过「空录音残渣」：e2e 测试会上传空 blob，在 uploads/audio 里留下大量几字节的 webm，
    // 它们往往才是最"新"的文件 → 不过滤就会挑中垃圾，得出「格式不支持 / 识别为空」的误导结论。
    $before = count($files);
    $files = array_values(array_filter($files, static fn($f) => is_file($f) && filesize($f) >= 1024));
    $skipped = $before - count($files);
    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    $audio = (string) ($files[0] ?? '');
    if ($skipped > 0) {
        echo "（已跳过 {$skipped} 个过小的录音残渣文件）\n";
    }
}
if ($audio === '' || !is_file($audio)) {
    echo "\n❌ 没找到可测试的音频文件。\n";
    echo "   先在页面录一段音，或手动指定：php tests/_check_asr_tencent.php uploads/audio/xxx.m4a\n";
    echo "   注意：小于 1KB 的文件会被视为测试残渣自动跳过（如需强行测试请直接传路径参数）。\n\n";
    exit(1);
}
$ext = strtolower(pathinfo($audio, PATHINFO_EXTENSION) ?: '');
echo "\n{$line}\n 真实识别 → " . str_replace('\\', '/', $audio) . "\n";
echo ' 大小 ' . round(filesize($audio) / 1024, 1) . " KB · 格式 {$ext}\n";
echo ' 链路     : ' . ($gateway ? 'AiGatewayService（网关，含格式预处理/转码）' : 'TencentAsrService（直连，跳过转码）') . "\n{$line}\n";

$t0 = microtime(true);
try {
    $text = $gateway
        ? \app\service\AiGatewayService::asr($audio, $ext, '自检')
        : \app\service\TencentAsrService::recognize($audio, $ext);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    echo " 耗时     : {$ms} ms\n";
    echo " 识别文字 : " . str_replace("\n", ' / ', $text) . "\n\n";
    echo "✅ 腾讯云 ASR 通道可用。页面上「录音转文字」将返回真实识别结果（不再重复 mock 文案）。\n\n";
} catch (\Throwable $e) {
    $ms = (int) round((microtime(true) - $t0) * 1000);
    echo " 耗时     : {$ms} ms\n";
    echo " 失败原因 : " . $e->getMessage() . "\n\n";
    echo "排查顺序：\n";
    echo "  1) code=4002 鉴权失败 → 先跑 php tests/_probe_tencent_cred.php 反查密钥真正归属的主账号 APPID（多半是 AI_ASR_TENCENT_APPID 填错了，比如把 GME 应用号当成了 APPID）\n";
    echo "  2) code=4003 服务未开通 → 该账号未在语音识别控制台点「立即开通」（需实名+人脸，极速版随之一并开启）；若密钥其实来自另一个账号，就换那个账号的密钥\n";
    echo "  3) code=4007 → 音频容器不被支持。直连模式下 webm 必现（预期行为）；装 ffmpeg 或设 AI_ASR_FFMPEG 后加 --gateway 重跑，可验证自动转码是否生效\n";
    echo "  4) code=4004/4005 → 资源包耗尽 / 账户欠费\n\n";
    exit(1);
}
