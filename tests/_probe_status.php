<?php
/**
 * 只读：复询既有 JobId，弄清 QueryTextToImageJob 的 JobStatusCode / JobStatusMsg 语义。
 * 用法：php tests/_probe_status.php [JobId]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use app\service\AiGatewayService;

function tc3x(string $host, string $service, string $action, string $version, array $payload, string $sid, string $skey, string $region = 'ap-guangzhou', int $timeout = 60): array
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
    $kDate     = hash_hmac('sha256', $date, 'TC3' . $skey, true);
    $kService  = hash_hmac('sha256', $service, $kDate, true);
    $kSigning  = hash_hmac('sha256', 'tc3_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $headers = [
        'Authorization: TC3-HMAC-SHA256 Credential=' . $sid . '/' . $scope . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
        'Content-Type: ' . $ct, 'Host: ' . $host, 'X-TC-Action: ' . $action,
        'X-TC-Timestamp: ' . $timestamp, 'X-TC-Version: ' . $version, 'X-TC-Region: ' . $region,
    ];
    $ch   = curl_init('https://' . $host . '/');
    $opts = [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout,
    ];
    if (($ca = AiGatewayService::caFile()) !== '') {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') {
        return ['Error' => ['Code' => 'CURL', 'Message' => $err]];
    }
    return (array) ((json_decode((string) $raw, true) ?: [])['Response'] ?? []);
}

$img  = (array) config('ai.image.tencent', []);
$sid  = trim((string) ($img['secret_id'] ?? ''));
$skey = trim((string) ($img['secret_key'] ?? ''));

$jobId = $argv[1] ?? '1307024053-1789875924-b5f0958e-b4a5-11f1-9d39-52540018a910-0';

echo "\n===== QueryTextToImageJob 语义（只读）=====\n\n";
$q = tc3x('aiart.tencentcloudapi.com', 'aiart', 'QueryTextToImageJob', '2022-12-29', ['JobId' => $jobId], $sid, $skey);

if (isset($q['Error'])) {
    echo json_encode($q['Error'], JSON_UNESCAPED_UNICODE) . "\n\n";
    exit(1);
}

printf("JobStatusCode = %s\n", var_export($q['JobStatusCode'] ?? null, true));
printf("JobStatusMsg  = %s\n", var_export($q['JobStatusMsg'] ?? null, true));
printf("JobErrorCode  = %s\n", var_export($q['JobErrorCode'] ?? null, true));
printf("JobErrorMsg   = %s\n", var_export($q['JobErrorMsg'] ?? null, true));
printf("ResultImage   = %s\n", is_array($q['ResultImage'] ?? null)
    ? 'array(' . count($q['ResultImage']) . ') 首项=' . substr((string) ($q['ResultImage'][0] ?? ''), 0, 80) . '…'
    : var_export($q['ResultImage'] ?? null, true));
printf("ResultDetails = %s\n", var_export($q['ResultDetails'] ?? null, true));
printf("RevisedPrompt = %s\n", is_array($q['RevisedPrompt'] ?? null)
    ? 'array(' . count($q['RevisedPrompt']) . ')'
    : var_export($q['RevisedPrompt'] ?? null, true));
echo "\n";

// 不存在的任务对照
$q2 = tc3x('aiart.tencentcloudapi.com', 'aiart', 'QueryTextToImageJob', '2022-12-29', ['JobId' => 'nope-0'], $sid, $skey);
echo '不存在任务对照：' . json_encode($q2, JSON_UNESCAPED_UNICODE) . "\n\n";
