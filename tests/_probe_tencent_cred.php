<?php
/**
 * 腾讯云凭据探针：用 SecretId / SecretKey 反查「主账号 APPID」
 *
 * 用法：php tests/_probe_tencent_cred.php
 *
 * 为什么需要它：
 *   录音文件识别极速版的 URL 是  https://asr.cloud.tencent.com/asr/flash/v1/{appid}?...
 *   这里面的 {appid} 必须是「腾讯云主账号 APPID」。一旦填成别的东西（例如 GME 游戏多媒体引擎
 *   的应用号），腾讯会回：
 *     鉴权失败：请检查输入参数和 AppId 与实际调用的 AppId 是否一致。
 *   肉眼无法分辨这个 appid 对不对，于是本脚本用 TC3-HMAC-SHA256 调 CAM 的 GetUserAppId，
 *   直接问腾讯「这两把钥匙属于哪个账号、它的 APPID 是多少」，同时顺带比对服务器时间（签名有 3 分钟窗口）。
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use app\service\AiGatewayService;

$cfg       = (array) config('ai.asr.tencent', []);
$secretId  = trim((string) ($cfg['secret_id'] ?? ''));
$secretKey = trim((string) ($cfg['secret_key'] ?? ''));
$appidCfg  = trim((string) ($cfg['appid'] ?? ''));

// 命令行临时覆盖：不改 .env 就能验证另一对密钥（换账号时特别省事）
//   php tests/_probe_tencent_cred.php --appid=1307024053 --secret-id=AKID... --secret-key=...
foreach (($argv ?? []) as $arg) {
    if (strpos($arg, '--appid=') === 0) {
        $appidCfg = trim(substr($arg, 8));
    } elseif (strpos($arg, '--secret-id=') === 0) {
        $secretId = trim(substr($arg, 12));
    } elseif (strpos($arg, '--secret-key=') === 0) {
        $secretKey = trim(substr($arg, 13));
    }
}

if ($secretId === '' || $secretKey === '') {
    fwrite(STDERR, ".env 里 SecretId / SecretKey 还没填全，先补上再跑本脚本。\n");
    exit(1);
}

/**
 * 云 API 3.0 标准签名（TC3-HMAC-SHA256）调一次接口
 */
function tc3Call(string $host, string $service, string $action, string $version, string $region, string $secretId, string $secretKey, string $body): array
{
    $timestamp = time();
    $date      = gmdate('Y-m-d', $timestamp);
    $ct        = 'application/json; charset=utf-8';

    $canonicalHeaders = "content-type:{$ct}\nhost:{$host}\n";
    $signedHeaders    = 'content-type;host';
    $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', $body);

    $credentialScope = "{$date}/{$service}/tc3_request";
    $stringToSign    = "TC3-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $kDate     = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
    $kService  = hash_hmac('sha256', $service, $kDate, true);
    $kSigning  = hash_hmac('sha256', 'tc3_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $headers = [
        "Authorization: TC3-HMAC-SHA256 Credential={$secretId}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        "Content-Type: {$ct}",
        "Host: {$host}",
        "X-TC-Action: {$action}",
        "X-TC-Timestamp: {$timestamp}",
        "X-TC-Version: {$version}",
    ];
    if ($region !== '') {
        $headers[] = "X-TC-Region: {$region}";
    }

    $serverDate = '';
    $ch   = curl_init("https://{$host}/");
    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$serverDate) {
            if (stripos($line, 'date:') === 0) {
                $serverDate = trim(substr($line, 5));
            }
            return strlen($line);
        },
    ];
    if (config('ai.ssl_verify') === false) {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    } elseif (($ca = AiGatewayService::caFile()) !== '') {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'http'       => $http,
        'err'        => $err,
        'body'       => (string) $resp,
        'serverDate' => $serverDate,
        'json'       => is_string($resp) ? (array) (json_decode($resp, true) ?: []) : [],
    ];
}

echo "\n================================================================\n";
echo " 腾讯云凭据探针 · 反查主账号 APPID\n";
echo "================================================================\n";
echo ' SecretId  : ' . substr($secretId, 0, 8) . '******' . substr($secretId, -4) . ' (len=' . strlen($secretId) . ")\n";
echo ' SecretKey : ' . substr($secretKey, 0, 4) . '******' . substr($secretKey, -4) . ' (len=' . strlen($secretKey) . ")\n";
echo ' .env APPID: ' . ($appidCfg !== '' ? $appidCfg : '(空)') . "\n\n";

echo "① 调用 CAM GetUserAppId ...\n";
$r = tc3Call('cam.tencentcloudapi.com', 'cam', 'GetUserAppId', '2019-01-16', 'ap-guangzhou', $secretId, $secretKey, '{}');

echo "   HTTP {$r['http']}" . ($r['err'] !== '' ? "  curl_error: {$r['err']}" : '') . "\n";
if ($r['serverDate'] !== '') {
    $skew = time() - (int) strtotime($r['serverDate']);
    echo "   腾讯服务器时间: {$r['serverDate']}   本机时差: {$skew} 秒" . (abs($skew) > 180 ? '  ⚠️ 超过 3 分钟，签名必然失败' : '  ✅') . "\n";
}

$resp = (array) ($r['json']['Response'] ?? []);
if (isset($resp['Error'])) {
    echo "\n❌ 密钥本身不可用：[" . ($resp['Error']['Code'] ?? '?') . '] ' . ($resp['Error']['Message'] ?? '') . "\n";
    echo "   原始返回: " . mb_substr($r['body'], 0, 400) . "\n";
    echo "\n   → SecretId/SecretKey 抄错、已删除、或属于被禁用的子账号。去 https://console.cloud.tencent.com/cam/capi 重新生成一对。\n";
    exit(1);
}

$realAppId = (string) ($resp['AppId'] ?? '');
$uin       = (string) ($resp['Uin'] ?? ($resp['OwnerUin'] ?? ''));

echo "\n✅ 密钥有效。\n";
echo "   真实主账号 APPID : {$realAppId}\n";
echo "   主账号 Uin       : {$uin}\n";
echo "   .env 里填的 APPID: {$appidCfg}\n";

echo "\n② 用这对密钥调一次 ASR 云 API（一句话识别，TC3 签名，**不需要 APPID**）...\n";
$cands = [];
foreach ((array) glob(dirname(__DIR__) . '/public/uploads/audio/*') as $f) {
    $e = strtolower((string) pathinfo($f, PATHINFO_EXTENSION));
    if (in_array($e, ['m4a', 'mp3', 'wav', 'ogg', 'aac', 'amr'], true)) {
        $cands[filemtime($f) . '-' . $f] = $f;
    }
}
krsort($cands);
$audio = $cands ? (string) reset($cands) : '';
if ($audio === '') {
    echo "   跳过（uploads/audio 下找不到可用录音）\n";
} else {
    $bin     = (string) file_get_contents($audio);
    $payload = json_encode([
        'ProjectId'      => 0,
        'SubServiceType' => 2,
        'EngSerViceType' => '16k_zh',
        'SourceType'     => 1,
        'VoiceFormat'    => strtolower((string) pathinfo($audio, PATHINFO_EXTENSION)),
        'Data'           => base64_encode($bin),
        'DataLen'        => strlen($bin),
        'FilterDirty'    => 0,
        'FilterModal'    => 1,
        'FilterPunc'     => 0,
        'ConvertNumMode' => 1,
        'WordInfo'       => 0,
    ], JSON_UNESCAPED_UNICODE);
    echo '   样本: ' . basename($audio) . ' (' . round(strlen($bin) / 1024, 1) . " KB)\n";
    $r2    = tc3Call('asr.tencentcloudapi.com', 'asr', 'SentenceRecognition', '2019-06-14', 'ap-guangzhou', $secretId, $secretKey, $payload);
    $resp2 = (array) ($r2['json']['Response'] ?? []);
    if (isset($resp2['Error'])) {
        $code = (string) ($resp2['Error']['Code'] ?? '');
        $m    = (string) ($resp2['Error']['Message'] ?? '');
        echo "   ❌ [{$code}] {$m}\n";
        if (stripos($code . $m, 'not opened') !== false || stripos($code . $m, 'unopened') !== false
            || stripos($code, 'NotOpened') !== false || stripos($code, 'UserNotRegistered') !== false) {
            echo "   → 结论：**这对密钥所属账号根本没开通语音识别 ASR 产品**，与 APPID 填什么无关。\n";
            echo "     要在这个账号（Uin {$uin}）的控制台点「立即开通」，或改用「已开通那个账号」的密钥。\n";
        } else {
            echo "   → 密钥本身可用，但 ASR 调用未通过（见上面 code）。\n";
        }
    } elseif (isset($resp2['Result'])) {
        echo '   ✅ ASR 云 API 调用成功，识别结果：' . mb_substr((string) $resp2['Result'], 0, 60) . "…\n";
        echo "   → 结论：该账号 ASR **已开通**，极速版的 4003/4002 只可能是 URL 里的 APPID 写错。\n";
    } else {
        echo '   ？ 未预期返回：' . mb_substr($r2['body'], 0, 200) . "\n";
    }
}

if ($realAppId !== '' && $realAppId === $appidCfg) {
    echo "\n🎯 APPID 一致 —— 极速版的 鉴权失败 不是 appid 引起的：\n";
    echo "   · 检查 SecretKey 是否与 SecretId 成对（控制台里一对密钥的 Key 只显示一次，重发过就要用新的）\n";
    echo "   · 检查本机时间（见上）\n";
    exit(0);
}

echo "\n❌ APPID 不一致 —— 这就是极速版报「鉴权失败：请检查 AppId 与实际调用的 AppId 是否一致」的根因。\n";
echo "   应该填: {$realAppId}\n";
echo "   现在填: " . ($appidCfg !== '' ? $appidCfg : '(空)') . "\n";
echo "\n   修法：把 backend/.env 的 AI_ASR_TENCENT_APPID 改成 {$realAppId}，然后重跑\n";
echo "         php tests/_check_asr_tencent.php\n";
