<?php
/**
 * 混元生图「有资源包但 Submit 仍报 NotExist」的深度判别探测（只读为主）
 *
 * 观察到的矛盾：控制台「资源包管理」里有可用额度（混元生图-免费资源包 50 次，已用 7），
 * 但 API SubmitHunyuanImageJob 仍报 ResourceUnavailable.NotExist（计费状态未知）。
 * 本脚本用多个不相关维度定位真因：
 *   A. 账户计费状态（billing:DescribeAccountBalance）——是否有余额 / 实名 / 欠费
 *   B. hunyuan 产品线 Submit 的最小请求（原样，看完整错误串与 RequestId）
 *   C. 兄弟产品线 aiart（2022-12-29）——若可用说明"用错了产品线"
 *   D. 对照只读接口
 *
 * 用法：php tests/_probe_hunyuan2.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use app\service\AiGatewayService;

function tc3x(string $host, string $service, string $action, string $version, array $payload, string $secretId, string $secretKey, string $region = 'ap-guangzhou', int $timeout = 30): array
{
    $body      = $payload === [] ? '{}' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
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
    ];
    if ($region !== '') {
        $headers[] = 'X-TC-Region: ' . $region;
    }

    $ch   = curl_init('https://' . $host . '/');
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

function dump(string $label, array $r, bool $full = false): void
{
    echo "── $label\n";
    if ($r['err'] !== '') {
        echo "   网络失败：" . $r['err'] . "\n\n";
        return;
    }
    $j    = json_decode($r['raw'], true) ?: [];
    $resp = (array) ($j['Response'] ?? []);
    if ($full) {
        echo "   HTTP {$r['http']}  " . substr(preg_replace('/\s+/', ' ', $r['raw']), 0, 900) . "\n\n";
        return;
    }
    if (isset($resp['Error'])) {
        echo "   ❌ [" . ($resp['Error']['Code'] ?? '') . "] " . ($resp['Error']['Message'] ?? '')
            . "   (RequestId " . ($resp['RequestId'] ?? '-') . ")\n\n";
    } else {
        echo "   ✅ " . substr((string) json_encode($resp, JSON_UNESCAPED_UNICODE), 0, 400) . "\n\n";
    }
}

$img  = (array) config('ai.image.tencent', []);
$sid  = trim((string) ($img['secret_id'] ?? ''));
$skey = trim((string) ($img['secret_key'] ?? ''));
$host = (string) ($img['endpoint'] ?: 'hunyuan.tencentcloudapi.com');
$svc  = (string) ($img['service'] ?: 'hunyuan');
$ver  = (string) ($img['version'] ?: '2023-09-01');

echo "\n================================================================\n";
echo " 混元生图 · 深度判别（资源包可用 但 Submit 报 NotExist）\n";
echo "================================================================\n";
echo " 当前链路 : {$host} / {$ver} / service={$svc}\n";
echo " SecretId : " . ($sid === '' ? '(未配置)' : substr($sid, 0, 6) . '******' . substr($sid, -4)) . "\n\n";

if ($sid === '' || $skey === '') {
    echo "❌ 密钥未配置\n\n";
    exit(1);
}

echo "A. 账户计费状态（billing:DescribeAccountBalance）\n";
dump('DescribeAccountBalance', tc3x('billing.tencentcloudapi.com', 'billing', 'DescribeAccountBalance', '2018-07-09', [], $sid, $skey, ''), true);

echo "A2. 账户身份（cam:GetUserAppId）\n";
dump('GetUserAppId', tc3x('cam.tencentcloudapi.com', 'cam', 'GetUserAppId', '2019-01-16', [], $sid, $skey, ''));

echo "B. hunyuan 产品线 · SubmitHunyuanImageJob（最小请求，打印完整响应）\n";
dump('hunyuan Submit 最小', tc3x($host, $svc, 'SubmitHunyuanImageJob', $ver, ['Prompt' => 'a red flower'], $sid, $skey, 'ap-guangzhou'), true);

echo "B2. hunyuan 产品线 · QueryHunyuanImageJob（只读对照）\n";
dump('hunyuan Query 假Id', tc3x($host, $svc, 'QueryHunyuanImageJob', $ver, ['JobId' => 'probe-0000'], $sid, $skey, 'ap-guangzhou'));

echo "C. 兄弟产品线 aiart（2022-12-29）· TextToImageLite（打印完整响应）\n";
dump('aiart TextToImageLite', tc3x('aiart.tencentcloudapi.com', 'aiart', 'TextToImageLite', '2022-12-29', ['Prompt' => 'a red flower', 'Resolution' => '1024:1024', 'RspImgType' => 'url'], $sid, $skey, 'ap-guangzhou'), true);

echo "C2. 兄弟产品线 aiart · SubmitTextToImageJob\n";
dump('aiart SubmitTextToImageJob', tc3x('aiart.tencentcloudapi.com', 'aiart', 'SubmitTextToImageJob', '2022-12-29', ['Prompt' => 'a red flower', 'Resolution' => '1024:1024'], $sid, $skey, 'ap-guangzhou'), true);

echo "D. hunyuan 产品线是否整体可用 · ChatCompletions（只读式最小请求）\n";
dump('hunyuan ChatCompletions', tc3x($host, $svc, 'ChatCompletions', $ver, ['Model' => 'hunyuan-lite', 'Messages' => [['Role' => 'user', 'Content' => 'hi']]], $sid, $skey, 'ap-guangzhou'));

echo "\n";
