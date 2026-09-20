<?php
/**
 * aiart 参数契约探测（**不消耗额度**）
 *
 * 手法：所有请求都带一个「必然被拒」的合法参数（Prompt 为空串）。
 *   - 未知参数 → UnknownParameter（参数名不存在）
 *   - 已知参数 → 会在 Prompt 校验上失败（InvalidParameterValue / MissingParameter）
 * 无论哪种，都**不会创建任务**，故不消耗资源包。
 * 另用假 JobId 探 Query 类接口（只读）。
 *
 * 用法：php tests/_probe_params.php
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

$H = 'aiart.tencentcloudapi.com';
$S = 'aiart';
$V = '2022-12-29';

/** 结果归类 */
function label(array $r): string
{
    if (!isset($r['Error'])) {
        if (isset($r['JobId'])) {
            return '⚠️ 竟然创建了任务 JobId=' . $r['JobId'];
        }
        return '✅ 无错误（keys: ' . implode(',', array_slice(array_keys($r), 0, 6)) . '）';
    }
    $code = (string) $r['Error']['Code'];
    $msg  = (string) $r['Error']['Message'];
    if ($code === 'UnknownParameter') {
        return '✗ 参数不存在（' . $msg . '）';
    }
    return '· ' . $code . '：' . $msg;
}

echo "\n================ aiart 参数契约探测（不耗额度）================\n\n";

// ---------- 0. 基线：空 Prompt 必须被拒 ----------
echo "【0】基线：{'Prompt': ''}（须被拒，验证不创建任务）\n";
foreach (['SubmitTextToImageJob', 'TextToImageLite', 'ImageToImage'] as $a) {
    $r = tc3x($H, $S, $a, $V, ['Prompt' => ''], $sid, $skey);
    echo "   $a → " . label($r) . "\n";
}
echo "\n";

// ---------- 1. SubmitTextToImageJob 参数集 ----------
echo "【1】SubmitTextToImageJob 接受哪些参数？\n";
$cands = [
    'Styles'         => ['国画'],
    'NegativePrompt' => '模糊',
    'Resolution'     => '1024:768',
    'LogoAdd'        => 1,
    'LogoParam'      => ['LogoUrl' => 'https://x/a.png'],
    'Revise'         => 1,
    'Num'            => 1,
    'RspImgType'     => 'url',
    'Seed'           => 123,
    'Clarity'        => 1,
    'Model'          => 'hunyuan-image',
];
foreach ($cands as $k => $v) {
    $r = tc3x($H, $S, 'SubmitTextToImageJob', $V, ['Prompt' => '', $k => $v], $sid, $skey);
    printf("   %-16s → %s\n", $k, label($r));
}
echo "\n";

// ---------- 2. TextToImageLite 参数 + 分辨率枚举 ----------
echo "【2】TextToImageLite 接受哪些参数？\n";
foreach ($cands as $k => $v) {
    $r = tc3x($H, $S, 'TextToImageLite', $V, ['Prompt' => '', $k => $v], $sid, $skey);
    printf("   %-16s → %s\n", $k, label($r));
}
echo "\n   分辨率枚举试探（非法值 '0:0' 让服务端回显合法集合）：\n";
$r = tc3x($H, $S, 'TextToImageLite', $V, ['Prompt' => 'x', 'Resolution' => '0:0'], $sid, $skey);
echo '   → ' . label($r) . "\n\n";

// ---------- 3. ImageToImage 参数 ----------
echo "【3】ImageToImage 接受哪些参数？\n";
foreach (['Styles' => ['国画'], 'Resolution' => '1024:768', 'Strength' => 0.6, 'RspImgType' => 'url', 'InputImage' => 'x', 'InputUrl' => 'https://x/a.png', 'LogoAdd' => 1, 'NegativePrompt' => '模糊'] as $k => $v) {
    $r = tc3x($H, $S, 'ImageToImage', $V, ['Prompt' => '', $k => $v], $sid, $skey);
    printf("   %-16s → %s\n", $k, label($r));
}
echo "\n";

// ---------- 4. 各 Query 接口用假 JobId ----------
echo "【4】Query 类接口（假 JobId，只读）\n";
foreach (['QueryTextToImageJob', 'QueryTextToImageProJob', 'QueryHunyuanImageJob', 'QueryPortraitModelJob'] as $a) {
    $r = tc3x($H, $S, $a, $V, ['JobId' => 'probe-0'], $sid, $skey);
    echo "   $a → " . label($r) . "\n";
}
echo "\n";

// ---------- 5. 同步文生图是否真能出图已在 _probe_aiart 验证 ----------
echo "【5】同步接口 TextToImageLite 的返回字段结构（已实测可出图，此处仅提示）\n";
echo "   ResultImage / Seed / RequestId\n\n";
