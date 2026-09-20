<?php
namespace app\service;

use app\common\exception\ApiException;
use think\facade\Db;

/**
 * 腾讯云「声音复刻」VRS（vrs.tencentcloudapi.com，Version 2020-08-24）
 * 文档：https://cloud.tencent.com/document/product/1283/101070
 *
 * 目的：用传主自己的录音训练专属音色，之后 TTS 用它朗读全书（"原主音色读自己的故事"）。
 *
 * 一句话复刻（TaskType=5）流程：
 *   ① GetTrainingText                 → TextId（官方要求的朗读文本，检测时用作标注）
 *   ② DetectEnvAndSoundQuality        → AudioId（音质/环境检测，音频 5~15s、≤2MB、单声道 16bit）
 *   ③ CreateVRSTask(AudioIdList=[id]) → TaskId
 *   ④ DescribeVRSTaskStatus(TaskId)   → Status(0等待/1执行中/2成功/3失败) + VoiceType
 * 拿到 VoiceType 后由 VoiceService 自动改用它朗读。
 *
 * ⚠️ 默认关闭（AI_VRS_ENABLED=0）：需先在「语音合成控制台 → 声音复刻」开通（按音色计费）。
 *     未开通时 detect/create 会返回明确的错误信息，任务记为失败原因，不影响其余交付环节。
 * 签名与其它云 API 一致：TC3-HMAC-SHA256。
 */
class TencentVrsService
{
    private const SERVICE = 'vrs';

    /** 复刻类型：5 = 一句话声音复刻 */
    private const TASK_TYPE = 5;

    public static function configured(): bool
    {
        $c = (array) config('ai.vrs', []);
        return trim((string) ($c['secret_id'] ?? '')) !== ''
            && trim((string) ($c['secret_key'] ?? '')) !== '';
    }

    /** 是否启用（配置开关 + 凭证齐全） */
    public static function enabled(): bool
    {
        return (bool) config('ai.vrs.enabled', false) && self::configured();
    }

    /**
     * 为项目训练复刻音色（幂等：已 ready 直接返回；训练中返回 pending）。
     *
     * @return array{status:string,voice_type?:int,task_id?:string,reason?:string}
     */
    public static function trainForProject(int $projectId): array
    {
        $project = Db::name('ls_project')->where('id', $projectId)->find();
        if (!$project) {
            throw new ApiException(40401, '项目不存在', 404);
        }
        $status = (string) ($project['voice_status'] ?? '');
        if ((int) ($project['voice_type'] ?? 0) > 0 && $status === 'ready') {
            return ['status' => 'ready', 'voice_type' => (int) $project['voice_type']];
        }
        // 已有训练任务在跑：接着轮询，别重复提交（重复提交会浪费音色配额）
        if ($status === 'training' && trim((string) ($project['voice_task_id'] ?? '')) !== '') {
            return self::poll($project);
        }
        if (!self::enabled()) {
            throw new ApiException(
                50031,
                '声音复刻未启用：需在「语音合成控制台 → 声音复刻」开通后把 AI_VRS_ENABLED 设为 1',
                500
            );
        }

        $sample = self::pickSample($projectId);
        if ($sample === '') {
            throw new ApiException(50032, '没有可用的录音样本（一句话复刻需要 5~15 秒清晰人声）', 422);
        }

        $gender = (string) ($project['gender'] ?? 'male') === 'female' ? 2 : 1;
        $name   = 'LS' . $projectId . '_' . date('mdHi');

        $audioId = self::detect($sample, $gender);
        $taskId  = self::create($audioId, $name, $gender);

        Db::name('ls_project')->where('id', $projectId)->update([
            'voice_status'  => 'training',
            'voice_task_id' => $taskId,
            'voice_label'   => '原主音色',
        ]);

        return ['status' => 'training', 'task_id' => $taskId];
    }

    /** 查询训练状态并把结果写回项目（成功后 voice_type/voice_status=ready） */
    public static function poll(array $project): array
    {
        $taskId = trim((string) ($project['voice_task_id'] ?? ''));
        if ($taskId === '') {
            return ['status' => 'pending', 'reason' => '缺少复刻任务 ID'];
        }
        $d      = self::query($taskId);
        $code   = (int) ($d['Status'] ?? 0);
        $pid    = (int) $project['id'];

        if ($code === 2) {   // 训练成功
            $vt = (int) ($d['VoiceType'] ?? 0);
            if ($vt <= 0) {
                throw new ApiException(50033, '复刻成功但未返回 VoiceType', 502);
            }
            Db::name('ls_project')->where('id', $pid)->update([
                'voice_type'    => $vt,
                'voice_status'  => 'ready',
                'voice_task_id' => '',
            ]);
            return ['status' => 'ready', 'voice_type' => $vt];
        }
        if ($code === 3) {   // 训练失败：标记失败，后续按性别音色兜底，不阻塞交付
            $err = (string) ($d['ErrorMsg'] ?? '训练失败');
            Db::name('ls_project')->where('id', $pid)->update([
                'voice_status'  => 'failed',
                'voice_task_id' => '',
                'voice_label'   => '',
            ]);
            throw new ApiException(50034, '声音复刻训练失败：' . $err, 502);
        }
        return ['status' => 'pending', 'reason' => (string) ($d['StatusStr'] ?? '训练中')];
    }

    // ------------------------------------------------------------------ 内部

    /** 挑一段 5~15 秒样本：取本项目第一段真实录音，用 ffmpeg 截取 sample_sec 秒、24k 单声道 wav */
    private static function pickSample(int $projectId): string
    {
        $chapterIds = Db::name('ls_chapter')->where('project_id', $projectId)->column('id');
        if (!$chapterIds) {
            return '';
        }
        $row = Db::name('ls_chapter_asset')
            ->where('chapter_id', 'in', $chapterIds)
            ->where('asset_type', 'RECORDING')
            ->order('id', 'asc')
            ->find();
        if (!$row) {
            return '';
        }
        $rel = ltrim((string) $row['oss_key'], '/');
        $abs = app()->getRootPath() . 'public/' . $rel;
        if (!is_file($abs)) {
            return '';
        }

        $cfg   = (array) config('ai.vrs', []);
        $sec   = max(5, min(14, (int) ($cfg['sample_sec'] ?? 12)));
        $rate  = (int) ($cfg['sample_rate'] ?? 24000) ?: 24000;
        $ff    = AiGatewayService::ffmpegPath();
        if ($ff === '') {
            return '';   // 复刻必须转成单声道 wav，没 ffmpeg 就放弃（不影响其它环节）
        }
        $out = app()->getRuntimePath() . 'vrs_' . $projectId . '_' . bin2hex(random_bytes(3)) . '.wav';
        $cmd = '"' . $ff . '" -y -i "' . $abs . '" -t ' . $sec
            . ' -ac 1 -ar ' . $rate . ' -sample_fmt s16 "' . $out . '" 2>&1';
        @exec($cmd, $o, $code);
        if ($code !== 0 || !is_file($out)) {
            return '';
        }
        return $out;
    }

    /** 音质检测 → AudioId */
    private static function detect(string $absPath, int $gender): string
    {
        $textId = self::trainingTextId();
        $bin    = @file_get_contents($absPath);
        if ($bin === false || $bin === '') {
            throw new ApiException(50032, '复刻样本读取失败', 500);
        }
        $resp = self::request('DetectEnvAndSoundQuality', [
            'TextId'     => $textId,
            'AudioData'  => base64_encode($bin),
            'TypeId'     => 2,               // 2 = 音质检测
            'Codec'      => 'wav',
            'SampleRate' => (int) (config('ai.vrs.sample_rate') ?: 24000),
            'TaskType'   => self::TASK_TYPE,
        ]);
        $d    = (array) ($resp['Data'] ?? []);
        $id   = (string) ($d['AudioId'] ?? '');
        $code = (int) ($d['DetectionCode'] ?? -1);
        if ($id === '' || $code !== 0) {
            $tip = implode('；', array_map('strval', (array) ($d['DetectionTip'] ?? [])));
            throw new ApiException(
                50032,
                '复刻样本未通过检测：' . ((string) ($d['DetectionMsg'] ?? '未知原因')) . ($tip !== '' ? "（{$tip}）" : ''),
                422
            );
        }
        return $id;
    }

    /** 取官方训练文本 ID（一句话复刻用；取不到也允许继续，检测侧会以音质为主） */
    private static function trainingTextId(): string
    {
        try {
            $resp = self::request('GetTrainingText', ['TaskType' => self::TASK_TYPE, 'Domain' => 1]);
            $list = (array) ($resp['Data']['TrainingTextList'] ?? []);
            $first = (array) ($list[0] ?? []);
            return (string) ($first['TextId'] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** 创建复刻任务 → TaskId */
    private static function create(string $audioId, string $name, int $gender): string
    {
        $resp = self::request('CreateVRSTask', [
            'VoiceName'     => $name,
            'VoiceGender'   => $gender,       // 1-male 2-female
            'VoiceLanguage' => 1,             // 1-中文
            'AudioIdList'   => [$audioId],
            'SampleRate'    => (int) (config('ai.vrs.sample_rate') ?: 24000),
            'Codec'         => 'wav',
        ]);
        $taskId = (string) ($resp['Data']['TaskId'] ?? '');
        if ($taskId === '') {
            throw new ApiException(50033, '声音复刻任务创建失败：未返回 TaskId', 502);
        }
        return $taskId;
    }

    /** 查询训练状态 */
    public static function query(string $taskId): array
    {
        $resp = self::request('DescribeVRSTaskStatus', ['TaskId' => $taskId]);
        return (array) ($resp['Data'] ?? []);
    }

    /** TC3-HMAC-SHA256 调一次 VRS 接口，返回 Response 数组 */
    private static function request(string $action, array $payload): array
    {
        if (!self::configured()) {
            throw new ApiException(50031, '声音复刻未配置：需要腾讯云 SecretId / SecretKey', 500);
        }
        $cfg       = (array) config('ai.vrs', []);
        $host      = (string) ($cfg['endpoint'] ?: 'vrs.tencentcloudapi.com');
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
            'X-TC-Version: ' . (string) ($cfg['version'] ?: '2020-08-24'),
            'X-TC-Region: ' . (string) ($cfg['region'] ?: 'ap-guangzhou'),
        ];

        $ch   = curl_init('https://' . $host . '/');
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => (int) ($cfg['timeout'] ?: 60),
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
            throw new ApiException(50034, '声音复刻请求失败：' . $err, 502);
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new ApiException(50034, "声音复刻返回非 JSON（HTTP {$http}）", 502);
        }
        $resp = (array) ($json['Response'] ?? []);
        if (isset($resp['Error'])) {
            $code = (string) ($resp['Error']['Code'] ?? '');
            $msg  = (string) ($resp['Error']['Message'] ?? '');
            throw new ApiException(50035, "声音复刻失败[{$code}]：{$msg}" . self::errorHint($code), 502);
        }
        return $resp;
    }

    private static function errorHint(string $code): string
    {
        if (stripos($code, 'ResourceUnavailable') !== false || stripos($code, 'NotExist') !== false) {
            return '（未开通声音复刻：请到「语音合成控制台 → 声音复刻」开通）';
        }
        if (stripos($code, 'LimitExceeded') !== false || stripos($code, 'Exceed') !== false) {
            return '（复刻音色数量已达上限：控制台删除旧音色或购买配额）';
        }
        if (stripos($code, 'AuthFailure') !== false) {
            return '（鉴权失败：确认密钥与声音复刻服务属同一账号）';
        }
        return '';
    }
}
