<?php
/**
 * 腾讯云密钥账号自检 —— 反查 .env 里那对密钥属于哪个账号（UIN / AppID）
 *
 * 用法：php tests/_check_tc_account.php
 *
 * 用途：当混元生图控制台显示「已开通 + 有免费额度」但接口仍报
 *       ResourceUnavailable.NotExist 时，用它确认「密钥所属账号」是否就是
 *       「开通了混元生图的账号」——不一致就是根因。
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use app\service\AiGatewayService;

$mask = static function (string $s): string {
    return $s === '' ? '(未配置)' : substr($s, 0, 6) . '******' . substr($s, -4);
};

/** TC3-HMAC-SHA256 通用签名调用 */
function tc3(string $host, string $service, string $action, string $version, array $payload, string $secretId, string $secretKey, string $region = 'ap-guangzhou', int $timeout = 20): array
{
    // 空负载必须是 {} 而不是 []（PHP 空数组会编码成数组，腾讯云会报 InvalidParameter）
    $body      = $payload === []
        ? '{}'
        : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    $timestamp = time();
    $date      = gmdate('Y-m-d', $timestamp);
    $ct        = 'application/json; charset=utf-8';

    $canonicalHeaders = "content-type:{$ct}\nhost:{$host}\n";
    $signedHeaders    = 'content-type;host';
    $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', $body);

    $scope        = "{$date}/{$service}/tc3_request";
    $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$scope}\n" . hash('sha256', $canonicalRequest);

    $kDate     = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
    $kService  = hash_hmac('sha256', $service, $kDate, true);
    $kSigning  = hash_hmac('sha256', 'tc3_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $headers = [
        'Authorization: TC3-HMAC-SHA256 Credential=' . $secretId . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
        'Content-Type: ' . $ct,
        'Host: ' . $host,
        'X-TC-Action: ' . $action,
        'X-TC-Timestamp: ' . $timestamp,
        'X-TC-Version: ' . $version,
        'X-TC-Region: ' . $region,
    ];

    $ch = curl_init('https://' . $host . '/');
    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
    ];
    if (($ca = AiGatewayService::caFile()) !== '') {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['http' => $http, 'err' => $err, 'raw' => (string) $raw];
}

$img = (array) config('ai.image.tencent', []);
$sid = trim((string) ($img['secret_id'] ?? ''));
$skey = trim((string) ($img['secret_key'] ?? ''));
$asrSid  = trim((string) env('AI_ASR_TENCENT_SECRET_ID', ''));
$hasImgOverride = trim((string) env('AI_IMAGE_TENCENT_SECRET_ID', '')) !== '';

echo "\n================================================================\n";
echo " 腾讯云密钥账号自检\n";
echo "================================================================\n";
echo ' 生图 SecretId      : ' . $mask($sid) . "\n";
echo ' 生图是否独立配置   : ' . ($hasImgOverride ? '是（AI_IMAGE_TENCENT_SECRET_ID 已显式填写）' : '否 → 复用 AI_ASR_TENCENT_SECRET_ID') . "\n";
echo ' ASR  SecretId      : ' . $mask($asrSid) . "\n";
echo ' 两者是否同一密钥   : ' . ($sid !== '' && $sid === $asrSid ? '是' : '否') . "\n";
echo ' 本地记录 ASR APPID : ' . (string) env('AI_ASR_TENCENT_APPID', '(未配置)') . "  （本机主账号，仅作对照）\n";

if ($sid === '' || $skey === '') {
    echo "\n❌ 密钥未配置，无法反查。\n\n";
    exit(1);
}

echo "\n① cam:GetUserAppId —— 该密钥所属账号\n";
$r = tc3('cam.tencentcloudapi.com', 'cam', 'GetUserAppId', '2019-01-16', [], $sid, $skey);
if ($r['err'] !== '') {
    echo '   请求失败：' . $r['err'] . "\n";
} else {
    $j = json_decode($r['raw'], true);
    $resp = (array) ($j['Response'] ?? []);
    if (isset($resp['Error'])) {
        echo '   ❌ [' . ($resp['Error']['Code'] ?? '') . '] ' . ($resp['Error']['Message'] ?? '') . "\n";
    } else {
        echo '   ✅ Uin      : ' . (string) ($resp['Uin'] ?? '') . "\n";
        echo '   ✅ AppId    : ' . (string) ($resp['AppId'] ?? '') . "\n";
        echo '   ✅ OwnerUin : ' . (string) ($resp['OwnerUin'] ?? '') . "\n";
        echo "   → 把上面 Uin/OwnerUin 与「混元生图控制台右上角显示的账号」核对：\n";
        echo "     一致 → 账号没问题，问题在别处；不一致 → 这就是 ResourceUnavailable 的根因。\n";
    }
}

echo "\n② sts:GetCallerIdentity —— 密钥身份（ARN，可看出是主/子账号）\n";
$r2 = tc3('sts.tencentcloudapi.com', 'sts', 'GetCallerIdentity', '2018-08-13', [], $sid, $skey, 'ap-guangzhou');
if ($r2['err'] !== '') {
    echo '   请求失败：' . $r2['err'] . "\n";
} else {
    $j2 = json_decode($r2['raw'], true);
    $resp2 = (array) ($j2['Response'] ?? []);
    if (isset($resp2['Error'])) {
        echo '   ❌ [' . ($resp2['Error']['Code'] ?? '') . '] ' . ($resp2['Error']['Message'] ?? '') . "\n";
    } else {
        echo '   AccountId : ' . (string) ($resp2['AccountId'] ?? '') . "\n";
        echo '   UserId    : ' . (string) ($resp2['UserId'] ?? '') . "\n";
        echo '   Arn       : ' . (string) ($resp2['Arn'] ?? '') . "\n";
        echo "   （Arn 形如 qcs::cam::uin/xxx:uin/xxx —— 两个 uin 不同即为子账号）\n";
    }
}

echo "\n③ 混元生图 SubmitHunyuanImageJob 最小调用 —— 复现真实报错\n";
$r3 = tc3(
    (string) ($img['endpoint'] ?: 'hunyuan.tencentcloudapi.com'),
    (string) ($img['service'] ?: 'hunyuan'),
    (string) ($img['submit_action'] ?: 'SubmitHunyuanImageJob'),
    (string) ($img['version'] ?: '2023-09-01'),
    ['Prompt' => '一朵花', 'Resolution' => '1024:768', 'Num' => 1],
    $sid,
    $skey,
    (string) ($img['region'] ?: 'ap-guangzhou')
);
$j3 = json_decode($r3['raw'], true);
$resp3 = (array) ($j3['Response'] ?? []);
if (isset($resp3['Error'])) {
    echo '   ❌ [' . ($resp3['Error']['Code'] ?? '') . '] ' . ($resp3['Error']['Message'] ?? '') . "\n";
    echo '   RequestId : ' . (string) ($resp3['RequestId'] ?? '') . "\n";
} else {
    echo '   ✅ 提交成功，JobId=' . (string) ($resp3['JobId'] ?? '') . "（说明账号/权限都正常）\n";
}

echo "\n";
