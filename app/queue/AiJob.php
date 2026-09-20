<?php
namespace app\queue;

use app\service\AiGatewayService;
use app\service\AiTaskService;
use app\service\ChapterIllustrationService;
use app\service\RecordingService;
use app\service\TencentVrsService;
use app\service\VoiceService;
use think\facade\Db;
use think\queue\Job;

/**
 * AI 异步任务 Worker
 *
 * 执行方式（二选一）：
 *   ① 【默认，无需常驻】`php think ai:work`  —— 把队列里的活干完自动退出；
 *      由业务侧（如定稿）调 `AiTaskService::kick()` 在后台自动拉起。见 app/command/AiWork.php。
 *   ② 【常驻可选】`php think queue:work --queue ai`，适合上线后用 supervisor 守护。
 *
 * 按 task_type 分发（定稿时一次入队的全套「美化增值」任务）：
 *   VOICE_CLONE  → 用传主录音训练复刻音色（可选，默认关闭）
 *   POLISH       → 逐章汇总润色（先自动补齐未转写的录音段）
 *   ILLUSTRATE   → 逐章混元生图
 *   COVER_IMAGE  → 封面主视觉生图
 *   NARRATE      → AI 音朗读：章节正文 / 封面书封语 / 尾页结束语
 *
 * ⚠️ 配图（ILLUSTRATE / COVER_IMAGE）与复刻都是「先提交、再轮询」的异步云任务：单次执行往往
 *    拿不到结果，此时**不能算失败**，要 release 后重试（返回 __retry 标记，见 run()）。
 */
class AiJob
{
    /** 各类型允许的最大尝试次数（配图/训练要轮询，给足次数） */
    protected const MAX_ATTEMPTS = [
        'ILLUSTRATE'  => 12,
        'COVER_IMAGE' => 12,
        'NARRATE'     => 20,
        'VOICE_CLONE' => 14,
        'DUB'         => 4,
        'ASR'         => 4,
        'POLISH'      => 3,
    ];

    /** 类型默认重试间隔（秒） */
    protected const RETRY_DELAY = [
        'ILLUSTRATE'  => 15,
        'COVER_IMAGE' => 15,
        'NARRATE'     => 15,
        'VOICE_CLONE' => 45,
    ];

    public function fire(Job $job, $data)
    {
        $taskId = (int) ($data['task_id'] ?? 0);
        $type   = (string) ($data['type'] ?? '');
        $max    = self::MAX_ATTEMPTS[$type] ?? 3;

        if ($job->attempts() > $max) {
            $pid = (int) Db::name('ls_ai_task')->where('id', $taskId)->value('project_id');
            $this->fail($job, $data, '重试次数超限（' . $job->attempts() . '/' . $max . '）', $pid);
            return;
        }

        $task = Db::name('ls_ai_task')->where('id', $taskId)->find();
        if (!$task) {
            $job->delete();
            return;
        }

        $pid = (int) ($task['project_id'] ?? 0);

        Db::name('ls_ai_task')->where('id', $taskId)->update([
            'status'     => 'RUNNING',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $result = $this->run($task);
        } catch (\Throwable $e) {
            Db::name('ls_ai_task')->where('id', $taskId)->update([
                'status'     => 'RETRY',
                'error'      => $e->getMessage(),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            // 交付重试（间隔按类型，配图需要等云端出图）
            $job->release(self::RETRY_DELAY[$type] ?? 30);
            return;
        }

        // 云端仍在处理：不算失败，隔一会儿再来问一次（避免误判成 FAILED 而丢掉任务）
        if (is_array($result) && !empty($result['__retry'])) {
            Db::name('ls_ai_task')->where('id', $taskId)->update([
                'status'     => 'RETRY',
                'progress'   => 60,
                'error'      => (string) ($result['__reason'] ?? '等待云端出结果'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $job->release((int) ($result['__delay'] ?? (self::RETRY_DELAY[$type] ?? 15)));
            return;
        }

        // 配置/额度类硬错误：重试没意义，直接把任务判失败，把原因留给前端展示
        if (is_array($result) && !empty($result['__fail'])) {
            $this->fail($job, $data, (string) $result['__fail'], $pid);
            return;
        }

        Db::name('ls_ai_task')->where('id', $taskId)->update([
            'status'      => 'SUCCESS',
            'progress'    => 100,
            'result'      => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'error'       => '',
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
        $job->delete();

        // 最后一个任务进入终态 → 收尾把项目翻 DONE
        AiTaskService::maybeCompleteProject($pid);
    }

    protected function run(array $task)
    {
        switch ($task['task_type']) {
            case 'ASR':         return $this->asr($task);
            case 'POLISH':      return $this->polish($task);
            case 'ILLUSTRATE':  return $this->illustrate($task);
            case 'COVER_IMAGE': return $this->cover($task);
            case 'NARRATE':     return $this->narrate($task);
            case 'VOICE_CLONE': return $this->voiceClone($task);
            case 'DUB':         return $this->dub($task);
            default:            throw new \RuntimeException('unknown task type: ' . $task['task_type']);
        }
    }

    /**
     * 章节润色（定稿时逐章入队，与生图并行）
     *
     * 之前润色是定稿接口里「同步逐章跑」，章节一多接口就要等好几分钟。
     * 现在丢进队列：接口秒回，润色在后台逐章完成，哪章失败也只影响哪章。
     *
     * 顺带把「未转写的录音段」自动补转写 —— 原来靠详情页那个「AI 转写未转写的段落」按钮，
     * 现在定稿时自动做，用户不用再手动点。
     */
    protected function polish($task)
    {
        $cid = (int) ($task['chapter_id'] ?? 0);
        if ($cid <= 0) {
            throw new \RuntimeException('润色任务缺少 chapter_id');
        }
        $payload = json_decode((string) ($task['payload'] ?? ''), true) ?: [];
        $force   = !empty($payload['force']);

        $has = Db::name('ls_chapter_asset')
            ->where('chapter_id', $cid)
            ->where('asset_type', 'POLISHED_TEXT')
            ->count();
        if ($has && !$force) {
            return ['skipped' => true, 'reason' => '本章已有润色稿'];
        }

        // 1) 补齐未转写的录音段（单段失败不阻断：可能是 ASR 未配置/该段音质太差）
        $transcribed = 0;
        $failed      = 0;
        foreach (RecordingService::segments($cid) as $seg) {
            if (trim((string) $seg['text']) !== '') {
                continue;
            }
            try {
                $chapterTitle = (string) Db::name('ls_chapter')->where('id', $cid)->value('title');
                RecordingService::transcribe($cid, (int) $seg['asset_id'], $chapterTitle);
                $transcribed++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        // 2) 汇总润色：口述原文 = 各段文字拼合（转写后已重算）
        $row        = Db::name('ls_chapter_asset')
            ->where('chapter_id', $cid)
            ->where('asset_type', 'TRANSCRIPT')
            ->order('id', 'desc')
            ->find();
        $transcript = $row ? trim((string) (((array) json_decode((string) $row['meta'], true))['text'] ?? '')) : '';
        if ($transcript === '') {
            return ['skipped' => true, 'reason' => '本章没有可润色的口述原文', 'transcribed' => $transcribed];
        }

        $title    = (string) Db::name('ls_chapter')->where('id', $cid)->value('title');
        $polished = AiGatewayService::polish($title, $transcript, self::bookContextOf((int) ($task['project_id'] ?? 0)));
        if (trim($polished) === '') {
            return ['skipped' => true, 'reason' => '润色返回为空'];
        }

        RecordingService::upsertAsset($cid, 'POLISHED_TEXT', '', ['text' => $polished, 'chars' => mb_strlen($polished)]);
        Db::name('ls_chapter')->where('id', $cid)->update(['chapter_status' => 'POLISHED']);

        return ['chars' => mb_strlen($polished), 'transcribed' => $transcribed, 'transcribe_failed' => $failed];
    }

    /**
     * AI 音朗读（TTS）：把文案读成 mp3 落盘，供翻书预览播放
     *
     * payload.scope = chapter（默认，用 chapter_id）/ cover / ending
     * 章节若标了 expect_polish，会先等本章润色任务出稿（润色稿优先于口述原文朗读）。
     */
    protected function narrate($task)
    {
        $payload = json_decode((string) ($task['payload'] ?? ''), true) ?: [];
        $scope   = (string) ($payload['scope'] ?? 'chapter');
        $force   = !empty($payload['force']);
        $pid     = (int) ($task['project_id'] ?? 0);

        try {
            if ($scope === 'cover') {
                $res = VoiceService::narrateCover($pid, $force);
            } elseif ($scope === 'ending') {
                $res = VoiceService::narrateEnding($pid, $force);
            } else {
                $cid = (int) ($payload['chapter_id'] ?? ($task['chapter_id'] ?? 0));
                if ($cid <= 0) {
                    throw new \RuntimeException('朗读任务缺少 chapter_id');
                }
                // 等润色稿：润色任务还在跑就先让位（润色稿读起来才是"润色后的文案"）
                if (!empty($payload['expect_polish'])) {
                    $has = Db::name('ls_chapter_asset')
                        ->where('chapter_id', $cid)
                        ->where('asset_type', 'POLISHED_TEXT')
                        ->count();
                    if (!$has) {
                        $busy = Db::name('ls_ai_task')
                            ->where('chapter_id', $cid)
                            ->where('task_type', 'POLISH')
                            ->whereIn('status', ['PENDING', 'RUNNING', 'RETRY'])
                            ->count();
                        if ($busy) {
                            return ['__retry' => true, '__delay' => 8, '__reason' => '等待本章润色稿'];
                        }
                    }
                }
                $res = VoiceService::narrateChapter($cid, $force);
            }
        } catch (\Throwable $e) {
            // 未开通 / 未配置 / 额度耗尽 / 音色不存在：重试没意义，直接判失败并给出原因
            if (preg_match('/未开通|未配置|未启用|额度|资源包|ResourceUnavailable|PkgExhausted|AuthFailure|NotExist|InvalidParameter/u', $e->getMessage())) {
                return ['__fail' => $e->getMessage()];
            }
            throw $e;
        }

        if (($res['status'] ?? '') === 'done') {
            return $res;
        }
        if (($res['status'] ?? '') === 'skipped') {
            return ['skipped' => true, 'reason' => (string) ($res['reason'] ?? '')];
        }

        // pending：等原主音色训练完 / 语音合成稍后再试（不算失败）
        return [
            '__retry'  => true,
            '__delay'  => self::RETRY_DELAY['NARRATE'],
            '__reason' => (string) ($res['reason'] ?? '等待中'),
        ];
    }

    /**
     * 声音复刻：用传主自己的录音训练专属音色（默认关闭，见 config/ai.php → vrs.enabled）
     * 训练成功后项目 voice_type 落库，后续 NARRATE 自动改用「原主音色」。
     * 失败不影响交付：VoiceService 会按性别回落到标准音色。
     */
    protected function voiceClone($task)
    {
        $pid = (int) ($task['project_id'] ?? 0);
        if ($pid <= 0) {
            throw new \RuntimeException('声音复刻任务缺少 project_id');
        }
        // 复刻未启用：把 finalize 时标记的等待（voice_status=pending）释放，NARRATE 立即回落标准音色
        if (!TencentVrsService::enabled()) {
            Db::name('ls_project')->where('id', $pid)
                ->where('voice_status', 'pending')->update(['voice_status' => 'none']);
            return ['skipped' => true, 'reason' => '声音复刻未启用（按传主性别使用标准音色）'];
        }

        try {
            $res = TencentVrsService::trainForProject($pid);
        } catch (\Throwable $e) {
            // 样本缺失/音质检测不过/未开通等：复刻无法开始 → 释放等待、回落标准音色，不阻塞交付
            if (preg_match('/未开通|未配置|未启用|额度|上限|资源包|样本|检测|训练失败|录音样本|ResourceUnavailable|LimitExceeded|AuthFailure|NotExist/u', $e->getMessage())) {
                Db::name('ls_project')->where('id', $pid)
                    ->where('voice_status', 'pending')->update(['voice_status' => 'failed', 'voice_label' => '']);
                return ['__fail' => $e->getMessage()];
            }
            throw $e;
        }

        if (($res['status'] ?? '') === 'ready') {
            return ['voice_type' => (int) ($res['voice_type'] ?? 0)];
        }
        return [
            '__retry'  => true,
            '__delay'  => self::RETRY_DELAY['VOICE_CLONE'],
            '__reason' => (string) (($res['status'] ?? '') === 'training' ? '原主音色训练中' : ($res['reason'] ?? '等待中')),
        ];
    }

    /** 全书上下文（各章标题 + 口述原文开头），供逐章润色统一人物称谓与时间线 */
    protected static function bookContextOf(int $projectId): string
    {
        if ($projectId <= 0) {
            return '';
        }
        $chapters = Db::name('ls_chapter')->where('project_id', $projectId)->order('sort asc, id asc')->select()->toArray();
        $items    = [];
        foreach ($chapters as $c) {
            $row  = Db::name('ls_chapter_asset')
                ->where('chapter_id', (int) $c['id'])
                ->where('asset_type', 'TRANSCRIPT')
                ->order('id', 'desc')
                ->find();
            $meta = $row ? ((array) json_decode((string) $row['meta'], true)) : [];
            $items[] = ['title' => (string) $c['title'], 'text' => (string) ($meta['text'] ?? '')];
        }
        return AiGatewayService::bookContext($items);
    }

    /**
     * 封面主视觉（定稿时入队，与章节配图同一条任务链）
     * 属于「锦上添花」：用户自己上传过封面就跳过，不覆盖。
     */
    protected function cover($task)
    {
        $pid = (int) ($task['project_id'] ?? 0);
        if ($pid <= 0) {
            throw new \RuntimeException('封面配图任务缺少 project_id');
        }

        try {
            $res = ChapterIllustrationService::generateCover($pid);
        } catch (\Throwable $e) {
            if (preg_match('/未开通|未配置|额度|ResourceUnavailable|NotExist|InvalidParameter|未启用/u', $e->getMessage())) {
                return ['__fail' => $e->getMessage()];
            }
            throw $e;
        }

        if (($res['status'] ?? '') === 'done') {
            return [
                'url'     => $res['url'],
                'skipped' => !empty($res['skipped']),
                'cached'  => !empty($res['cached']),
                'mock'    => !empty($res['mock']),
            ];
        }
        if (($res['status'] ?? '') === 'skipped') {
            return ['skipped' => true, 'url' => (string) ($res['url'] ?? '')];
        }

        return [
            '__retry'  => true,
            '__delay'  => self::RETRY_DELAY['COVER_IMAGE'],
            '__reason' => '封面生成中（job=' . (string) ($res['job_id'] ?? '') . '）',
        ];
    }

    /**
     * 章节配图（腾讯混元生图，异步任务 + 轮询）
     * 复用 ChapterIllustrationService，与用户端 `/chapters/:id/illustrate` 同一份实现。
     */
    protected function illustrate($task)
    {
        $cid = (int) ($task['chapter_id'] ?? 0);
        if ($cid <= 0) {
            throw new \RuntimeException('章节配图任务缺少 chapter_id');
        }

        try {
            $res = ChapterIllustrationService::generate($cid);
        } catch (\Throwable $e) {
            // 「服务未开通 / 未配置 / 额度耗尽 / 参数非法」重试多少次都一样，
            // 直接把任务判失败（原因写进 ls_ai_task.error），别空转十几轮。
            if (preg_match('/未开通|未配置|额度|ResourceUnavailable|NotExist|InvalidParameter|未启用/u', $e->getMessage())) {
                return ['__fail' => $e->getMessage()];
            }
            throw $e;   // 网络抖动、限流等：交给上层 release 后重试
        }

        if (($res['status'] ?? '') === 'done') {
            return ['url' => $res['url'], 'cached' => !empty($res['cached']), 'mock' => !empty($res['mock'])];
        }

        // 云端还在生成 → 交给上层 release 后重试（不算失败）
        return [
            '__retry'  => true,
            '__delay'  => self::RETRY_DELAY['ILLUSTRATE'],
            '__reason' => '配图生成中（job=' . (string) ($res['job_id'] ?? '') . '）',
        ];
    }

    /** 录音转写（当前由用户端 /chapters/:id/asr 同步完成，此处预留批量场景） */
    protected function asr($task)
    {
        throw new \RuntimeException('ASR 任务暂未启用（请用 /chapters/:id/asr 同步转写）');
    }

    /** 配音（当前由用户端 /chapters/:id/dub 同步完成，此处预留） */
    protected function dub($task)
    {
        throw new \RuntimeException('DUB 任务暂未启用（请用 /chapters/:id/dub 同步生成）');
    }

    protected function fail(Job $job, $data, string $msg, int $pid = 0)
    {
        Db::name('ls_ai_task')->where('id', (int) ($data['task_id'] ?? 0))->update([
            'status'     => 'FAILED',
            'error'      => $msg,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $job->delete();

        // 进入终态 → 收尾把项目翻 DONE（会记录 finalize_failed）
        AiTaskService::maybeCompleteProject($pid);
    }
}
