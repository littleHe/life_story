<?php
namespace app\service;

use app\common\exception\ApiException;

/**
 * 腾讯云语音识别 · 录音文件识别极速版（FlashRecognition）
 * 文档：https://cloud.tencent.com/document/api/1093/52097
 *
 * 为什么选极速版：
 *   - 同步返回（一次 HTTPS POST 拿到结果），不用回调、不用轮询任务状态；
 *   - 30 分钟音频约 10 秒返回，≤2 小时 / ≤100MB 都吃；
 *   - 每次录音（分段）都只是一次 HTTP 调用，天然契合「录一段转一段」的产品形态。
 *
 * 与云 API 3.0 的区别（重要）：
 *   本接口不是 TC3-HMAC-SHA256，而是「URL 参数按字典序排序 + HMAC-SHA1」的私有签名风格：
 *     signOrigin = 'POST' . 'asr.cloud.tencent.com' . '/asr/flash/v1/' . {appid} . '?' . {排序后的 query}
 *     signature  = base64( hmac_sha1(signOrigin, secretKey) )
 *   音频原始二进制直接放 POST body（Content-Type: application/octet-stream）。
 *
 * 凭证：AppID + SecretId + SecretKey（云 API 密钥，访问管理 → API 密钥管理）。
 */
class TencentAsrService
{
    private const HOST = 'asr.cloud.tencent.com';
    private const PATH = '/asr/flash/v1/';

    /**
     * 极速版支持的容器/编码 → voice_format 取值映射。
     * ⚠️ webm 不在官方支持列表里（wav/pcm/ogg-opus/speex/silk/mp3/m4a/aac/amr），
     *    浏览器 MediaRecorder 默认产出 audio/webm，必须转码或改用其它容器。
     */
    private const VOICE_FORMAT_MAP = [
        'wav'  => 'wav',
        'pcm'  => 'pcm',
        'mp3'  => 'mp3',
        'm4a'  => 'm4a',
        'mp4'  => 'm4a',   // MediaRecorder('audio/mp4') 落盘为 .m4a/.mp4，同为 AAC in MP4
        'aac'  => 'aac',
        'amr'  => 'amr',
        'ogg'  => 'ogg-opus',
        'opus' => 'ogg-opus',
    ];

    /** 腾讯返回码 →（可读原因，是否可重试） */
    private const ERROR_HINT = [
        4001 => ['参数不合法（详见 message）', false],
        4002 => ['鉴权失败：可能是 SecretId / SecretKey 抄错、密钥与 URL 里的 AppID 不属于同一腾讯云账号、或服务器时间偏差超过 3 分钟；可用 php tests/_probe_tencent_cred.php 反查密钥真正归属的 APPID', false],
        4003 => ['该 AppID 未开通「录音文件识别」（极速版随控制台「立即开通」一键开启）；请到语音识别控制台开通，并确认 API 密钥与该 APPID 属于同一个账号', false],
        4004 => ['资源包耗尽：免费额度用尽，**或当前引擎不在免费额度内**（大模型 16k_zh_en / 16k_zh_en_2.0 要单独买大模型资源包，先切回 16k_zh 即可免费跑），请到语音识别控制台查看资源包或开通后付费', false],
        4005 => ['账户欠费停止服务，请充值', false],
        4006 => ['调用并发超限（普通版 20 并发 / 大模型版 5 并发）', true],
        4007 => ['音频解码失败：上传音频格式与 voice_format 不一致（webm 需先转成 wav/m4a/ogg-opus）', false],
        4008 => ['音频上传超时', true],
        4009 => ['连接被断开', true],
        4010 => ['上传了未知文本消息', false],
        4011 => ['音频数据太大（>100MB）', false],
        4012 => ['音频数据为空', false],
        5001 => ['服务端偶发失败（负载/网络抖动），重试即可', true],
        5002 => ['识别失败（偶发可重试，频繁出现请提工单）', true],
        5003 => ['识别超时（偶发可重试）', true],
    ];

    /** 该扩展名能否直接送腾讯（无需转码） */
    public static function supportsExt(string $ext): bool
    {
        return isset(self::VOICE_FORMAT_MAP[strtolower($ext)]);
    }

    /**
     * 识别本地音频文件，返回文字。
     *
     * @param string $audioPath 音频绝对路径
     * @param string $ext       扩展名（决定 voice_format）
     */
    public static function recognize(string $audioPath, string $ext): string
    {
        $cfg = (array) config('ai.asr.tencent', []);
        $appid = trim((string) ($cfg['appid'] ?? ''));
        $secretId = trim((string) ($cfg['secret_id'] ?? ''));
        $secretKey = trim((string) ($cfg['secret_key'] ?? ''));
        if ($appid === '' || $secretId === '' || $secretKey === '') {
            throw new ApiException(50001, '腾讯云 ASR 未配置完整：需要 AppID / SecretId / SecretKey', 500);
        }

        $ext = strtolower($ext);
        if (!self::supportsExt($ext)) {
            throw new ApiException(
                50006,
                "腾讯云 ASR 不支持 {$ext} 格式（支持 wav/pcm/ogg-opus/speex/silk/mp3/m4a/aac/amr）；"
                . '请在支持的浏览器重新录制，或配置 AI_ASR_FFMPEG 让后端自动转码',
                422
            );
        }

        $bin = @file_get_contents($audioPath);
        if ($bin === false || $bin === '') {
            throw new ApiException(50002, '录音文件读取失败', 500);
        }
        if (strlen($bin) > 100 * 1024 * 1024) {
            throw new ApiException(50007, '单次录音超过 100MB，请缩短录音时长', 422);
        }

        $params = self::buildParams($appid, $secretId, $ext, $cfg);
        ksort($params);
        $query = self::buildQuery($params);
        $signOrigin = 'POST' . self::HOST . self::PATH . $appid . '?' . $query;
        $signature = base64_encode(hash_hmac('sha1', $signOrigin, $secretKey, true));

        $url = 'https://' . self::HOST . self::PATH . $appid . '?' . $query;
        $raw = self::postBinary($url, $signature, $bin, (int) ($cfg['timeout'] ?? 120));

        $code = (int) ($raw['code'] ?? -1);
        if ($code !== 0) {
            [$hint, $retryable] = self::ERROR_HINT[$code] ?? ['未知错误', false];
            $msg = (string) ($raw['message'] ?? '');
            throw new ApiException(
                50003,
                "腾讯云 ASR 失败(code={$code})：{$hint}" . ($msg !== '' ? "；{$msg}" : '')
                . ($retryable ? '（可重试）' : ''),
                502
            );
        }

        $text = self::extractText($raw);
        if ($text === '') {
            throw new ApiException(50004, '腾讯云 ASR 未返回有效文本（可能是静音或纯噪声）', 502);
        }
        return $text;
    }

    /**
     * 拼装请求参数（除 appid 外全部走 query；appid 在 URL path 里）。
     */
    private static function buildParams(string $appid, string $secretId, string $ext, array $cfg): array
    {
        $params = [
            'secretid'      => $secretId,
            'engine_type'   => (string) ($cfg['engine'] ?? '') !== '' ? (string) $cfg['engine'] : '16k_zh',
            'voice_format'  => self::VOICE_FORMAT_MAP[$ext],
            'timestamp'     => time(),
            // 以下为可选调优项（数值越小越「原汁原味」）
            'word_info'           => 0,   // 不要词级时间戳
            'first_channel_only'  => 1,   // 只识别首声道（多声道按 声道数*时长 计费）
            'convert_num_mode'    => 1,   // 智能转阿拉伯数字
            'filter_dirty'        => (int) ($cfg['filter_dirty'] ?? 0),
            'filter_modal'        => (int) ($cfg['filter_modal'] ?? 1),  // 1=部分过滤「嗯/啊」等语气词
            'filter_punc'         => (int) ($cfg['filter_punc'] ?? 0),   // 0=保留标点
            'speaker_diarization' => 0,   // 回忆录是单人叙述，不开说话人分离
        ];
        $hotwordId = trim((string) ($cfg['hotword_id'] ?? ''));
        if ($hotwordId !== '') {
            $params['hotword_id'] = $hotwordId;
        }
        // 临时热词表：人名、地名、方言词能显著提升字准率，如「何天霸|11,清远|5」
        $hotwords = trim((string) ($cfg['hotwords'] ?? ''));
        if ($hotwords !== '') {
            $params['hotword_list'] = $hotwords;
        }
        return $params;
    }

    /** 按 RFC3986 编码拼接 query（签名原文必须与 URL 上的 query 完全一致） */
    private static function buildQuery(array $params): string
    {
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
        }
        return implode('&', $pairs);
    }

    /** POST 原始音频二进制（Authorization 直接放签名，不带 TC3 前缀） */
    private static function postBinary(string $url, string $signature, string $bin, int $timeout): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bin,
            CURLOPT_HTTPHEADER     => [
                'Host: ' . self::HOST,
                'Authorization: ' . $signature,
                'Content-Type: application/octet-stream',
                'Content-Length: ' . strlen($bin),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
        ];
        // 与 AiGatewayService 一致的证书策略（本机 phpstudy 常缺 CA 根证书）
        if (config('ai.ssl_verify') === false) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        } elseif (($ca = AiGatewayService::caFile()) !== '') {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new ApiException(50005, '腾讯云 ASR 请求失败：' . $err, 502);
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new ApiException(50005, "腾讯云 ASR 返回非 JSON（HTTP {$http}）：" . mb_substr((string) $body, 0, 200), 502);
        }
        return $json;
    }

    /** 从 flash_result 提取文本（多声道时按顺序拼接） */
    private static function extractText(array $raw): string
    {
        $list = $raw['flash_result'] ?? [];
        if (!is_array($list)) {
            return '';
        }
        $texts = [];
        foreach ($list as $item) {
            $t = trim((string) ($item['text'] ?? ''));
            if ($t !== '') {
                $texts[] = $t;
            }
        }
        return trim(implode('', $texts));
    }

    /**
     * 调试用：输出签名原文（不发起请求），排查 4001/4002 时非常好用。
     */
    public static function debugSignature(string $ext = 'wav'): array
    {
        $cfg = (array) config('ai.asr.tencent', []);
        $appid = trim((string) ($cfg['appid'] ?? ''));
        $params = self::buildParams($appid, trim((string) ($cfg['secret_id'] ?? '')), $ext, $cfg);
        $params['timestamp'] = 1700000000; // 固定值，方便肉眼比对
        ksort($params);
        $query = self::buildQuery($params);
        return [
            'sign_origin' => 'POST' . self::HOST . self::PATH . $appid . '?' . $query,
            'url'         => 'https://' . self::HOST . self::PATH . $appid . '?' . $query,
        ];
    }
}
