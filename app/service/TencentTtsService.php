<?php
namespace app\service;

use app\common\exception\ApiException;
use app\service\AssetStorage;

/**
 * 腾讯云「语音合成 TTS」（tts.tencentcloudapi.com）—— 把 AI 润色后的文案读成有声书
 * 文档：https://cloud.tencent.com/document/product/1073/37995（TextToVoice）
 *
 * 签名是云 API 3.0 标准 TC3-HMAC-SHA256（与 ASR 极速版的私有签名不同，与混元生图同一套写法）。
 * 版本：2019-08-23，Action：TextToVoice。
 *
 * ⚠️ 单次请求文本长度有限（通用语音合成约 150 字），本服务会自动按标点切段、逐段合成后拼接。
 * ⚠️ 前置：需在「语音合成控制台」开通服务并领取免费资源包，否则报 ResourceUnavailable。
 * 音色：默认用系统音色；按传主性别分派（男 voice_male / 女 voice_female，见 config/ai.php）。
 *      做了「声音复刻」后把 ls_project.voice_type 填成复刻 VoiceType，合成时传进来即可，链路不变。
 */
class TencentTtsService
{
    /** TC3 里的 service 名 */
    private const SERVICE = 'tts';

    /** 单次合成文本上限（留点余量，实际限制约 150 字） */
    private const CHUNK = 140;

    public static function configured(): bool
    {
        $c = (array) config('ai.tts.tencent', []);
        return trim((string) ($c['secret_id'] ?? '')) !== ''
            && trim((string) ($c['secret_key'] ?? '')) !== '';
    }

    /**
     * 按传主性别取默认标准音色（还没做声音复刻时用）。
     * 编号见 config/ai.php → tts.tencent.voice_male / voice_female（env 可覆盖）。
     */
    public static function voiceTypeForGender(string $gender): array
    {
        $cfg = (array) config('ai.tts.tencent', []);
        if ($gender === 'female') {
            $id = (int) ($cfg['voice_female'] ?? 0) ?: 601010;
            return ['voice_type' => $id, 'label' => '温柔亲和女声'];
        }
        $id = (int) ($cfg['voice_male'] ?? 0) ?: 501006;
        return ['voice_type' => $id, 'label' => '沉稳大气男声'];
    }

    /**
     * 合成一段文本为 mp3，落盘到 public/uploads，返回可访问的相对 URL。
     * 文本过长时自动切段拼接（按标点优先断开，避免把词切断）。
     *
     * @param int    $voiceType 指定音色（0 = 用配置里的默认音色）；复刻音色由调用方传进来
     * @param string $dir       指定落盘绝对目录（传了就用它，便于按 年月/用户_回忆录 归档）
     * @param string $name      指定文件名（不含目录；不传则随机名）
     */
    public static function synthesize(string $text, string $prefix = 'ch', int $voiceType = 0, string $dir = '', string $name = ''): string
    {
        if (!self::configured()) {
            throw new ApiException(
                50021,
                '语音合成未配置：需要腾讯云 SecretId / SecretKey（AI_TTS_TENCENT_* 或用 AI_ASR_TENCENT_* 复用）',
                500
            );
        }
        $text = trim((string) preg_replace('/\s+/u', '', $text));
        if ($text === '') {
            throw new ApiException(50022, '没有可朗读的文案', 422);
        }

        $chunks = self::split($text);
        $bin    = '';
        foreach ($chunks as $i => $part) {
            $bin .= self::one($part, $i, $voiceType);
        }
        if ($bin === '') {
            throw new ApiException(50023, '语音合成未返回音频数据', 502);
        }

        if ($dir === '') {
            $dir = app()->getRootPath() . 'public/uploads/tts';
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if ($name === '') {
            $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        }
        if (!preg_match('/\.mp3$/i', $name)) {
            $name .= '.mp3';
        }
        file_put_contents($dir . '/' . $name, $bin);

        return AssetStorage::dirToUrl($dir) . '/' . $name;
    }

    /** 按标点切成不超过 CHUNK 字的片段（找不到标点则硬切） */
    public static function split(string $text): array
    {
        $parts = [];
        $rest  = $text;
        while (mb_strlen($rest) > self::CHUNK) {
            $head  = mb_substr($rest, 0, self::CHUNK);
            $cut   = 0;
            // 从后往前找最近的断句标点
            for ($i = mb_strlen($head) - 1; $i > (int) (self::CHUNK * 0.5); $i--) {
                if (mb_strpos('。！？；：，、', mb_substr($head, $i, 1)) !== false) {
                    $cut = $i + 1;
                    break;
                }
            }
            if ($cut === 0) {
                $cut = self::CHUNK;
            }
            $parts[] = mb_substr($rest, 0, $cut);
            $rest    = mb_substr($rest, $cut);
        }
        if (mb_strlen($rest) > 0) {
            $parts[] = $rest;
        }
        return $parts;
    }

    /** 合成单个片段，返回 mp3 二进制 */
    private static function one(string $text, int $seq, int $voiceType = 0): string
    {
        $cfg       = (array) config('ai.tts.tencent', []);
        $payload   = [
            'Text'       => $text,
            'SessionId'  => 'ls' . $seq . '-' . bin2hex(random_bytes(4)),
            'VoiceType'  => $voiceType > 0 ? $voiceType : (int) ($cfg['voice_type'] ?? 1001),
            'Codec'      => (string) ($cfg['codec'] ?: 'mp3'),
            'SampleRate' => (int) ($cfg['sample_rate'] ?? 16000),
            'Speed'      => (float) ($cfg['speed'] ?? 0),
            'Volume'     => (float) ($cfg['volume'] ?? 0),
        ];
        $resp = self::request('TextToVoice', $payload, (int) ($cfg['timeout'] ?: 60));
        $b64  = (string) ($resp['Audio'] ?? '');
        if ($b64 === '') {
            throw new ApiException(50023, '语音合成未返回音频数据', 502);
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') {
            throw new ApiException(50023, '语音合成音频解码失败', 502);
        }
        return $bin;
    }

    /** TC3-HMAC-SHA256 调一次 TTS 接口，返回 Response 数组 */
    private static function request(string $action, array $payload, int $timeout): array
    {
        $cfg       = (array) config('ai.tts.tencent', []);
        $host      = (string) ($cfg['endpoint'] ?: 'tts.tencentcloudapi.com');
        $secretId  = trim((string) ($cfg['secret_id'] ?? ''));
        $secretKey = trim((string) ($cfg['secret_key'] ?? ''));

        $body      = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp = time();
        $date      = gmdate('Y-m-d', $timestamp);
        $ct        = 'application/json; charset=utf-8';

        $canonicalHeaders = "content-type:{$ct}\nhost:{$host}\n";
        $signedHeaders    = 'content-type;host';
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n" . hash('sha256', $body);

        $scope        = "{$date}/" . self::SERVICE . '/tc3_request';
        $stringToSign = "TC3-HMAC-SHA256\n{$timestamp}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $kDate     = hash_hmac('sha256', $date, 'TC3' . $secretKey, true);
        $kService  = hash_hmac('sha256', self::SERVICE, $kDate, true);
        $kSigning  = hash_hmac('sha256', 'tc3_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $headers = [
            'Authorization: TC3-HMAC-SHA256 Credential=' . $secretId . '/' . $scope
                . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature,
            'Content-Type: ' . $ct,
            'Host: ' . $host,
            'X-TC-Action: ' . $action,
            'X-TC-Timestamp: ' . $timestamp,
            'X-TC-Version: ' . (string) ($cfg['version'] ?: '2019-08-23'),
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
            throw new ApiException(50024, '语音合成请求失败：' . $err, 502);
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new ApiException(50024, "语音合成返回非 JSON（HTTP {$http}）", 502);
        }
        $resp = (array) ($json['Response'] ?? []);
        if (isset($resp['Error'])) {
            $code = (string) ($resp['Error']['Code'] ?? '');
            $msg  = (string) ($resp['Error']['Message'] ?? '');
            throw new ApiException(50025, "语音合成失败[{$code}]：{$msg}" . self::errorHint($code), 502);
        }
        return $resp;
    }

    private static function errorHint(string $code): string
    {
        if (stripos($code, 'ResourceUnavailable') !== false || stripos($code, 'ResourceNotFound') !== false) {
            return '（未开通语音合成或额度耗尽：请到「语音合成控制台」开通并领取免费资源包）';
        }
        if (stripos($code, 'PkgExhausted') !== false) {
            return '（资源包额度不足：请到「语音合成控制台 → 资源包管理」领取/购买免费资源包）';
        }
        if (stripos($code, 'AuthFailure') !== false) {
            return '（鉴权失败：确认密钥与语音合成服务属同一账号）';
        }
        if (stripos($code, 'InvalidParameter') !== false) {
            return '（参数不合法：多为 VoiceType 音色编号不存在，换一个音色 ID 再试）';
        }
        if (stripos($code, 'RequestLimitExceeded') !== false) {
            return '（频率超限：TTS 默认 QPS 20，稍后重试）';
        }
        return '';
    }
}
