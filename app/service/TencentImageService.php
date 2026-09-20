<?php
namespace app\service;

use app\common\exception\ApiException;
use app\service\AssetStorage;

/**
 * 腾讯云「混元生图」—— 章节配图 / 封面主视觉生成
 *
 * 支持两套产品线（由 config ai.image.tencent.product 决定，默认 aiart）：
 *
 *   ┌ aiart（默认，可用）aiart.tencentcloudapi.com / 2022-12-29 / service=aiart
 *   │   SubmitTextToImageJob → QueryTextToImageJob
 *   │   请求：Prompt / Resolution / Revise / Seed / Model / LogoAdd / LogoParam
 *   │   响应：JobStatusCode（5=处理完成）、JobErrorCode/JobErrorMsg、
 *   │         ResultImage（**字符串数组**）、ResultDetails（['Success']）、RevisedPrompt（数组）
 *   │   文档：https://cloud.tencent.com/document/product/1668/88053
 *   │
 *   └ hunyuan（旧链路，已不可用）hunyuan.tencentcloudapi.com / 2023-09-01 / service=hunyuan
 *       SubmitHunyuanImageJob → QueryHunyuanImageJob
 *       2026-09 实测恒报 ResourceUnavailable.NotExist（计费状态未知，服务未开通），
 *       与控制台是否开通、资源包是否有余额无关；该产品线正迁移 TokenHub。保留代码仅为可回退。
 *       响应：ResultImage（**字符串**）、ResultDetails（[{Url}]）
 *
 * 形态是**异步任务**：提交拿 JobId → 轮询到出图 → ResultImage 下载落盘。
 * 签名是云 API 3.0 标准 TC3-HMAC-SHA256 —— 注意与「录音文件识别极速版」那套私有 HMAC-SHA1 完全不同。
 *
 * ⚠️ 前置条件：需先在「腾讯混元生图控制台」开通服务（免费资源包挂在 aiart 产品线上）。
 */
class TencentImageService
{
    /** 当前产品线：aiart（默认）| hunyuan */
    private static function product(): string
    {
        $p = strtolower(trim((string) (config('ai.image.tencent.product') ?: '')));
        if ($p === 'aiart' || $p === 'hunyuan') {
            return $p;
        }
        // 未显式配置 product 时，按 endpoint / service 猜（兼容旧的 .env）
        $guess = strtolower((string) config('ai.image.tencent.endpoint') . ' ' . config('ai.image.tencent.service'));
        return str_contains($guess, 'aiart') ? 'aiart' : 'hunyuan';
    }

    /** TC3 签名里的 service 名（默认 aiart；换产品时由 config ai.image.tencent.service 覆盖） */
    private static function service(): string
    {
        return (string) (config('ai.image.tencent.service') ?: self::product());
    }

    /** 凭据是否齐全（未配齐则走 mock，不上报错） */
    public static function configured(): bool
    {
        $c = (array) config('ai.image.tencent', []);
        return trim((string) ($c['secret_id'] ?? '')) !== ''
            && trim((string) ($c['secret_key'] ?? '')) !== '';
    }

    /**
     * 生成一张图并等待结果。
     *
     * @param string $prompt         中文提示词
     * @param string $negativePrompt 反向提示词（可空）
     * @param string $refImagePath   参考图本地绝对路径（可空；**仅 hunyuan 产品线生效**，aiart 无此入参）
     * @param string $jobId          已有任务 ID：续询（上一轮超时未出图时用）
     *
     * @return array{status:string,job_id:string,url?:string,revised_prompt?:string}
     *         status = done（已出图） | pending（超时未出图，用返回的 job_id 稍后续询）
     */
    public static function generate(
        string $prompt,
        string $negativePrompt = '',
        string $refImagePath = '',
        string $jobId = ''
    ): array {
        if (!self::configured()) {
            throw new ApiException(
                50011,
                '混元生图未配置：需要腾讯云 SecretId / SecretKey（AI_IMAGE_TENCENT_* 或用 AI_ASR_TENCENT_* 复用）',
                500
            );
        }
        $cfg = (array) config('ai.image.tencent', []);
        if ($jobId === '') {
            $jobId = self::submit($prompt, $negativePrompt, $refImagePath);
        }
        return self::wait($jobId, $cfg);
    }

    /**
     * 规整为合法 UTF-8。
     *
     * 提示词是「LLM 判断年代场景 + 人物外观基线」拼出来的，偶发会带回**非法字节**
     * （响应被截断在某个多字节字符中间等）。此时 json_encode() 会**静默返回 false**，
     * 请求体变成空串，服务端只会回一句莫名其妙的是 MissingParameter `Prompt`。
     * 所以在入口处先把非法字节丢掉，保证后续 JSON 一定能编码。
     */
    private static function utf8(string $s): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if ($fixed === false) {
            $fixed = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        return (string) $fixed;
    }

    /** 提交任务，返回 JobId */
    public static function submit(string $prompt, string $negativePrompt = '', string $refImagePath = ''): string
    {
        $cfg     = (array) config('ai.image.tencent', []);
        $product = self::product();
        $prompt  = self::utf8(trim($prompt));

        if ($product === 'aiart') {
            // aiart 的 SubmitTextToImageJob 只认这些参数：Styles / NegativePrompt / Num / ContentImage
            // 都会直接报 UnknownParameter（见 tests/_probe_params.php），故这里按产品线裁剪。
            $payload = [
                'Prompt'     => mb_substr($prompt, 0, 1000),
                'Resolution' => (string) ($cfg['resolution'] ?: '1024:768'),
                'Revise'     => (int) ($cfg['revise'] ?? 1),
                'LogoAdd'    => (int) ($cfg['logo_add'] ?? 1),
            ];
            $seed = (int) ($cfg['seed'] ?? 0);
            if ($seed > 0) {
                $payload['Seed'] = $seed;
            }
            $model = trim((string) ($cfg['model'] ?? ''));
            if ($model !== '') {
                $payload['Model'] = $model;
            }
            // 注：aiart 文生图无参考图入参，$refImagePath 在此产品线下被忽略（人物一致性靠提示词）
        } else {
            $payload = [
                'Prompt'     => mb_substr($prompt, 0, 1000),
                'Resolution' => (string) ($cfg['resolution'] ?: '1024:768'),
                'Num'        => 1,
                'Revise'     => (int) ($cfg['revise'] ?? 1),
                'LogoAdd'    => (int) ($cfg['logo_add'] ?? 1),
            ];
            $style = trim((string) ($cfg['style'] ?? ''));
            if ($style !== '') {
                // 仅在显式配置了风格编号时才传，避免未知编号触发 InvalidParameterValue
                $payload['Style'] = $style;
            }
            if (trim($negativePrompt) !== '') {
                $payload['NegativePrompt'] = mb_substr(trim($negativePrompt), 0, 1000);
            }
            $ref = self::refImageBase64($refImagePath);
            if ($ref !== '') {
                // 参考图：用于引导人物长相（同一个人在各章里长得一样）
                $payload['ContentImage'] = ['ImageBase64' => $ref];
            }
        }

        $res   = self::request((string) (config('ai.image.tencent.submit_action') ?: 'SubmitHunyuanImageJob'), $payload, (int) ($cfg['timeout'] ?: 60));
        $jobId = (string) ($res['JobId'] ?? '');
        if ($jobId === '') {
            throw new ApiException(50012, '混元生图提交失败：未返回 JobId', 502);
        }
        return $jobId;
    }

    /** 查询任务一次（原始 Response 数组） */
    public static function query(string $jobId): array
    {
        return self::request((string) (config('ai.image.tencent.query_action') ?: 'QueryHunyuanImageJob'), ['JobId' => $jobId], 30);
    }

    /**
     * 轮询到出图，或超过 wait_max 秒后返回 pending（交由调用方稍后续询）。
     *
     * 两套产品线的响应结构不同，这里统一归一：
     *   · aiart  ：ResultImage 是**字符串数组**；成功时 JobStatusCode=5 / JobStatusMsg=处理完成；
     *              失败看 JobErrorCode / JobErrorMsg，或 ResultDetails[i] !== 'Success'
     *   · hunyuan：ResultImage 是**字符串**；失败时 JobStatusCode ∈ {4,5}
     * 判定策略：能取到图就算成功；只在有明确错误信号时才判失败；其余一律继续等。
     */
    private static function wait(string $jobId, array $cfg): array
    {
        $deadline = time() + max(10, (int) ($cfg['wait_max'] ?? 90));
        $interval = max(1, (int) ($cfg['poll_interval'] ?? 3));
        // 「仍在进行中」的状态码：空串（字段缺失）/ 1 等待 / 2 运行；除此之外且无图 = 失败
        $running = ['', '1', '2'];

        while (true) {
            $r    = self::query($jobId);
            $url  = self::pickImageUrl($r);
            $code = (string) ($r['JobStatusCode'] ?? '');

            if ($url !== '') {
                return [
                    'status'         => 'done',
                    'job_id'         => $jobId,
                    'url'            => $url,
                    'revised_prompt' => self::pickText($r['RevisedPrompt'] ?? ''),
                ];
            }

            // 明确的失败信号：错误码/错误信息非空，或 aiart 的子任务状态不是 Success
            $errMsg = trim((string) ($r['JobErrorCode'] ?? '') . ' ' . (string) ($r['JobErrorMsg'] ?? ''));
            $badSub = self::hasFailedSubTask($r);
            if (!$errMsg && !$badSub && !in_array($code, $running, true)) {
                // 非「进行中」的状态码 + 无图 = 任务没成功（如 hunyuan 的 4/5）
                $errMsg = 'JobStatusCode=' . $code . ' ' . (string) ($r['JobStatusMsg'] ?? '');
            }
            if ($errMsg !== '' || $badSub) {
                throw new ApiException(
                    50013,
                    '混元生图未出图：' . trim($errMsg . ' ' . (string) ($r['JobStatusMsg'] ?? '')),
                    502
                );
            }

            if (time() >= $deadline) {
                return ['status' => 'pending', 'job_id' => $jobId];
            }
            sleep($interval);
        }
    }

    /**
     * 从查询响应里取图片 URL。
     * aiart：ResultImage 为字符串数组（取首项）；hunyuan：ResultImage 为字符串。
     * 兼容 ResultDetails[0].Url（部分版本的 hunyuan）。
     */
    private static function pickImageUrl(array $r): string
    {
        $img = $r['ResultImage'] ?? '';
        if (is_array($img)) {
            $img = (string) ($img[0] ?? '');
        }
        $img = trim((string) $img);
        if ($img !== '') {
            return $img;
        }
        foreach ((array) ($r['ResultDetails'] ?? []) as $d) {
            if (is_array($d) && !empty($d['Url'])) {
                return trim((string) $d['Url']);
            }
        }
        return '';
    }

    /** aiart 的 ResultDetails 是 ['Success', ...]；出现非 Success 即为子任务失败 */
    private static function hasFailedSubTask(array $r): bool
    {
        foreach ((array) ($r['ResultDetails'] ?? []) as $d) {
            if (is_string($d) && $d !== '' && strcasecmp($d, 'Success') !== 0) {
                return true;
            }
        }
        return false;
    }

    /** RevisedPrompt 在 aiart 下是数组、hunyuan 下是字符串 —— 统一取首项 */
    private static function pickText($v): string
    {
        if (is_array($v)) {
            return (string) ($v[0] ?? '');
        }
        return (string) $v;
    }

    /**
     * 生成图下载到本地 public/uploads，返回可访问的 URL 路径。
     *
     * @param string $imageUrl 云端图片地址
     * @param string $prefix   旧目录前缀（仅在不传 $dir 时用于兜底目录名）
     * @param string $dir      指定落盘绝对目录（传了就用它，便于按 年月/用户_回忆录 归档）
     * @param string $name     指定文件名（不含目录；不传则随机名）
     */
    public static function download(string $imageUrl, string $prefix = 'ai', string $dir = '', string $name = ''): string
    {
        if ($dir === '') {
            $dir = app()->getRootPath() . 'public/uploads/chapter-bg/' . $prefix;
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if ($name === '') {
            $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        }
        if (!preg_match('/\.(png|jpe?g|webp)$/i', $name)) {
            $name .= '.png';
        }

        $ch   = curl_init($imageUrl);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
        ];
        if (stripos($imageUrl, 'https://') === 0) {
            if (config('ai.ssl_verify') === false) {
                $opts[CURLOPT_SSL_VERIFYPEER] = false;
                $opts[CURLOPT_SSL_VERIFYHOST] = 0;
            } elseif (($ca = AiGatewayService::caFile()) !== '') {
                $opts[CURLOPT_CAINFO] = $ca;
            }
        }
        curl_setopt_array($ch, $opts);
        $bin  = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($bin === false || $bin === '' || $http >= 400) {
            throw new ApiException(50014, '生成图下载失败：' . ($err !== '' ? $err : "HTTP {$http}"), 502);
        }

        file_put_contents($dir . '/' . $name, $bin);
        return AssetStorage::dirToUrl($dir) . '/' . $name;
    }

    /** 参考图：本地文件 → base64（混元生图限制：jpg/png、单张 base64 后 < 8MB） */
    private static function refImageBase64(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return '';
        }
        if ((int) filesize($path) > 8 * 1024 * 1024) {
            return '';
        }
        $bin = (string) file_get_contents($path);
        return $bin !== '' ? base64_encode($bin) : '';
    }

    /** TC3-HMAC-SHA256 调一次混元生图接口，返回 Response 数组（错误直接抛可读异常） */
    private static function request(string $action, array $payload, int $timeout): array
    {
        $cfg      = (array) config('ai.image.tencent', []);
        $host     = (string) ($cfg['endpoint'] ?: 'aiart.tencentcloudapi.com');
        $secretId = trim((string) ($cfg['secret_id'] ?? ''));
        $secretKey = trim((string) ($cfg['secret_key'] ?? ''));

        $body      = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            // 宁可报清楚，也不要发一个空请求体让服务端回莫须有的 MissingParameter
            throw new ApiException(50015, '混元生图请求体编码失败：' . json_last_error_msg(), 502);
        }
        $timestamp = time();
        $date      = gmdate('Y-m-d', $timestamp);
        $ct        = 'application/json; charset=utf-8';

        // 规范请求串：canonical headers 只有 content-type;host（X-TC-* 头不参与签名）
        $canonicalHeaders = "content-type:{$ct}\nhost:{$host}\n";
        $signedHeaders    = 'content-type;host';
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', $body);

        $scope        = "{$date}/" . self::service() . '/tc3_request';
        $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $kDate     = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $kService  = hash_hmac('sha256', self::service(), $kDate, true);
        $kSigning  = hash_hmac('sha256', 'tc3_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $headers = [
            'Authorization: TC3-HMAC-SHA256 Credential=' . $secretId . '/' . $scope
                . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
            'Content-Type: ' . $ct,
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Version: ' . (string) ($cfg['version'] ?: '2022-12-29'),
            'X-TC-Region: ' . (string) ($cfg['region'] ?: 'ap-guangzhou'),
        ];

        $ch   = curl_init('https://' . $host . '/');
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
        ];
        if (config('ai.ssl_verify') === false) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        } elseif (($ca = AiGatewayService::caFile()) !== '') {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new ApiException(50015, '混元生图请求失败：' . $err, 502);
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new ApiException(
                50015,
                "混元生图返回非 JSON（HTTP {$http}）：" . mb_substr((string) $raw, 0, 200),
                502
            );
        }
        $resp = (array) ($json['Response'] ?? []);
        if (isset($resp['Error'])) {
            $code = (string) ($resp['Error']['Code'] ?? '');
            $msg  = (string) ($resp['Error']['Message'] ?? '');
            throw new ApiException(50016, "混元生图失败[{$code}]：{$msg}" . self::errorHint($code), 502);
        }
        return $resp;
    }

    /** 常见错误码 → 处理建议（省掉一次翻文档） */
    private static function errorHint(string $code): string
    {
        if (stripos($code, 'ResourceUnavailable') !== false) {
            if (self::product() === 'hunyuan') {
                return '（hunyuan 产品线（SubmitHunyuanImageJob）已不可用：该链路恒报"计费状态未知"，'
                    . '与控制台是否开通、资源包余额无关。请用 aiart 产品线：'
                    . 'AI_IMAGE_TENCENT_PRODUCT=aiart / AI_IMAGE_TENCENT_ENDPOINT=aiart.tencentcloudapi.com）';
            }
            return '（aiart 产品线未开通或免费资源包耗尽：请到「腾讯云混元生图控制台 → 资源包管理」确认额度，'
                . '或到「设置」开通后付费；也可跑 php tests/_probe_hunyuan2.php 只读体检）';
        }
        if (stripos($code, 'RequestLimitExceeded') !== false) {
            return '（并发/频率超限：混元生图默认仅 1 个并发，请稍后逐张重试）';
        }
        if (stripos($code, 'AuthFailure') !== false || stripos($code, 'UnauthorizedOperation') !== false) {
            return '（鉴权/权限不足：确认密钥所属账号已开通混元生图，子账号需授予 QcloudHunyuanFullAccess / QcloudAIArtFullAccess）';
        }
        if (stripos($code, 'UnknownParameter') !== false) {
            return '（请求参数不被该产品线接受：aiart 的 SubmitTextToImageJob 不认 Styles/NegativePrompt/Num/ContentImage，'
                . '详见 tests/_probe_params.php 的实测契约）';
        }
        if (stripos($code, 'TextIllegalDetected') !== false || stripos($code, 'ImageIllegalDetected') !== false) {
            return '（内容审核不通过：换一种描述或换一张参考头像）';
        }
        if (stripos($code, 'TextLengthExceed') !== false) {
            return '（提示词过长：已截断到 1000 字，可再精简章节描述）';
        }
        return '';
    }
}
