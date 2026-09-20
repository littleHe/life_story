<?php
/**
 * 补充探测：SubmitTextToImageJob 的边缘参数 + ImageToImage 实测（图生图 / 参考头像）
 * 用法：php tests/_probe_i2i.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new \think\App(dirname(__DIR__));
$app->initialize();

use app\service\AiGatewayService;

function tc3x(string $host, string $service, string $action, string $version, array $payload, string $sid, string $skey, string $region = 'ap-guangzhou', int $timeout = 120): array
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

$H = 'aiart.tencentcloudapi.com';
$S = 'aiart';
$V = '2022-12-29';

echo "\n===== ① SubmitTextToImageJob 边缘参数（不耗额度：Prompt 留空）=====\n\n";
foreach ([
    'ContentImage' => ['ImageBase64' => 'x', 'ImageUrl' => 'https://x/a.png'],
    'Style'        => '国画',
    'LogExt'       => 'x',
    'Seed'         => 123,
    'Model'        => 'hunyuan-image',
    'Revise'       => 1,
    'Resolution'   => '1024:768',
] as $k => $v) {
    $r = tc3x($H, $S, 'SubmitTextToImageJob', $V, ['Prompt' => '', $k => $v], $sid, $skey);
    $e = $r['Error'] ?? null;
    $line = $e ? ($e['Code'] === 'UnknownParameter' ? '✗ 不存在' : '· ' . $e['Code']) : '✅ 接受';
    printf("   %-14s → %s\n", $k, $line);
}

echo "\n===== ② ImageToImage 实测（消耗 1 次额度）=====\n\n";
$sample = '';
foreach ((array) glob(app()->getRootPath() . 'public/uploads/avatar/user/1/*.{jpg,jpeg,png}', GLOB_BRACE) as $f) {
    if (is_file($f) && filesize($f) < 4 * 1024 * 1024) {
        $sample = $f;
        break;
    }
}
if ($sample === '') {
    echo "   无样本图，跳过\n\n";
    exit(0);
}
echo '   样本：' . basename($sample) . '（' . round(filesize($sample) / 1024) . " KB）\n";
$dim = @getimagesize($sample);
echo '   尺寸：' . ($dim ? $dim[0] . 'x' . $dim[1] : '未知') . "\n\n";

$t0 = microtime(true);
$r  = tc3x($H, $S, 'ImageToImage', $V, [
    'InputImage' => base64_encode((string) file_get_contents($sample)),
    'Prompt'     => '同一位老人中年时期的模样，穿着中山装站在乡村小学教室前，1970年代中国，写实插画风格，暖色调',
    'Strength'   => 0.35,
    'RspImgType' => 'url',
], $sid, $skey);
printf("   耗时 %d 秒\n", (int) round(microtime(true) - $t0));

if (isset($r['Error'])) {
    echo '   ❌ ' . json_encode($r['Error'], JSON_UNESCAPED_UNICODE) . "\n\n";
    exit(1);
}
echo '   keys = ' . implode(',', array_keys($r)) . "\n";
$url = $r['ResultImage'] ?? '';
if (is_array($url)) {
    $url = (string) ($url[0] ?? '');
}
echo '   ResultImage 类型 = ' . (is_array($r['ResultImage'] ?? null) ? 'array' : gettype($r['ResultImage'] ?? null)) . "\n";
echo '   URL = ' . substr((string) $url, 0, 100) . "…\n";
if ($url !== '') {
    $bin = @file_get_contents((string) $url);
    echo '   下载字节数：' . ($bin === false ? '失败' : strlen($bin)) . "\n";
    if ($bin) {
        $tmp = sys_get_temp_dir() . '/i2i_sample.png';
        file_put_contents($tmp, $bin);
        $d = @getimagesize($tmp);
        echo '   输出尺寸：' . ($d ? $d[0] . 'x' . $d[1] : '未知') . "\n";
    }
}
echo "\n";
