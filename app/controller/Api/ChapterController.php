<?php
namespace app\controller\Api;

use app\BaseController;
use app\common\exception\ApiException;
use app\service\AiGatewayService;
use app\service\AiTaskService;
use app\service\ChapterIllustrationService;
use app\service\RecordingService;
use app\service\TencentTtsService;
use think\facade\Db;

class ChapterController extends BaseController
{
    /** 鉴权：章节必须归属当前登录用户的项目（系统模板章节不可在用户端编辑） */
    protected function ownedChapter($id): array
    {
        $chapter = Db::name('ls_chapter')->where('id', $id)->find();
        if (!$chapter) {
            throw new ApiException(40401, '章节不存在', 404);
        }
        if ($chapter['project_id']) {
            $proj = Db::name('ls_project')
                ->where('id', $chapter['project_id'])
                ->where('user_id', $this->uid())
                ->find();
            if (!$proj) {
                throw new ApiException(40301, '无权操作该章节', 403);
            }
        } else {
            throw new ApiException(40301, '系统模板章节不可编辑', 403);
        }
        return $chapter;
    }

    /** 资源 upsert：删除该章节同类型旧资源后写入一条新资源 */
    protected function upsertAsset(int $chapterId, string $type, string $ossKey, array $meta): void
    {
        Db::name('ls_chapter_asset')
            ->where('chapter_id', $chapterId)
            ->where('asset_type', $type)
            ->delete();
        if ($ossKey === '' && empty($meta)) {
            return; // 清空场景：仅删除，不插入
        }
        Db::name('ls_chapter_asset')->insert([
            'chapter_id' => $chapterId,
            'asset_type' => $type,
            'oss_key'    => $ossKey,
            'meta'       => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 背景图池：扫描 public/uploads/chapter-bg 目录（AI 接入后改为模型出图） */
    protected function bgPool(): array
    {
        $dir = app()->getRootPath() . 'public/uploads/chapter-bg';
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
        return array_map(fn($f) => '/uploads/chapter-bg/' . basename($f), $files);
    }

    /** 追加一条录音分段（微信式：录一次存一次，支持多段）
     *  三种来源：OSS oss_key / multipart 文件 / base64 字符串
     *  本地 php think run 开发服务器对 multipart POST 支持不好，前端统一走 base64 路径。 */
    public function uploadRecording($id)
    {
        $this->ownedChapter($id);
        $file = $this->request->file('audio');
        $ossKey = input('post.oss_key/s', '');
        $duration = input('post.duration/s', '');

        if ($file) {
            $dir = app()->getRootPath() . 'public/uploads/audio';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $ext = $file->getOriginalExtension() ?: 'webm';
            $name = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            $file->move($dir . '/', $name);
            $ossKey = '/uploads/audio/' . $name;
        } elseif (($b64 = input('post.audio_base64/s', '')) !== '') {
            // 本地联调路径：前端把录音 Blob 转 base64 后随 JSON 提交，规避 dev server 的 multipart 限制
            $ext = input('post.audio_ext/s', 'webm') ?: 'webm';
            $ossKey = RecordingService::saveBase64($b64, $ext);
        }

        if (!$ossKey) {
            throw new ApiException(42201, '缺少录音文件或 oss_key', 422);
        }

        // 分段录音：追加而非覆盖（保留每一段原始录音）
        $res = RecordingService::appendSegment((int) $id, $ossKey, $duration);

        return $this->ok([
            'url'      => $ossKey,
            'asset_id' => $res['asset_id'],
            'seq'      => $res['seq'],
        ]);
    }

    /** 上传章节背景图（用户自定义，覆盖旧的自定义背景；优先于 AI 生成图展示） */
    public function uploadBackground($id)
    {
        $this->ownedChapter($id);

        $b64 = input('post.image_base64/s', '');
        if ($b64 === '') {
            throw new ApiException(42201, '缺少图片数据', 422);
        }
        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', input('post.image_ext/s', 'jpg') ?: 'jpg')) ?: 'jpg';
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            throw new ApiException(42202, '仅支持 jpg/png/webp/gif 图片', 422);
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') {
            throw new ApiException(42201, '图片数据解码失败', 422);
        }
        if (strlen($bin) > 8 * 1024 * 1024) {
            throw new ApiException(42202, '图片过大（限 8MB）', 422);
        }

        $dir = app()->getRootPath() . 'public/uploads/chapter-bg/user';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $name = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
        file_put_contents($dir . '/' . $name, $bin);
        $url = '/uploads/chapter-bg/user/' . $name;

        // 同一章节只保留一张自定义背景；上传自定义背景后清掉 AI 生成图，避免来源混淆
        $this->upsertAsset($id, 'USER_IMAGE', $url, ['url' => $url]);
        Db::name('ls_chapter_asset')->where('chapter_id', $id)->where('asset_type', 'AI_IMAGE')->delete();

        return $this->ok(['url' => $url]);
    }


    /** 转写单个录音分段（不传 asset_id 则取最新一段）：
     *  识别文字写回该分段 meta；章节「口述原文」= 全部分段文字按序拼合（删段后自动重算）。 */
    public function asr($id)
    {
        $chapter = $this->ownedChapter($id);
        $assetId = input('post.asset_id/d', 0);

        $res = RecordingService::transcribe((int) $id, (int) $assetId, (string) $chapter['title']);

        return $this->ok([
            'text'       => $res['text'],
            'transcript' => $res['transcript'],
            'asset_id'   => $res['asset_id'],
        ]);
    }

    /** 删除一段录音：同时删音频文件，并把「口述原文」重算为剩余分段文字的拼合 */
    public function deleteRecording($id)
    {
        $this->ownedChapter($id);
        $assetId = input('post.asset_id/d', 0);

        $res = RecordingService::deleteSegment((int) $id, (int) $assetId);

        return $this->ok([
            'transcript' => $res['transcript'],
            'recordings' => $res['recordings'],
        ], '录音已删除');
    }

    /** 章节的录音分段（按 seq 升序，含每段转写文字） */
    protected function recordingSegments(int $chapterId): array
    {
        return RecordingService::segments((int) $chapterId);
    }

    /** 口述原文 = 多段录音文字按序拼合；无分段时清空 TRANSCRIPT */
    protected function rebuildTranscript(int $chapterId): string
    {
        return RecordingService::rebuildTranscript((int) $chapterId);
    }

    /** 触发润色：基于章节口述原文生成润色后的故事文本 */
    public function polish($id)
    {
        $chapter = $this->ownedChapter($id);
        $base = $this->currentTranscript($id);
        if (trim($base) === '') {
            throw new ApiException(42204, '请先录入录音转写或手动填写口述原文', 422);
        }

        $text = AiGatewayService::polish(
            (string) $chapter['title'],
            $base,
            $this->bookContextForProject((int) $chapter['project_id'])
        );
        $this->upsertAsset($id, 'POLISHED_TEXT', '', ['text' => $text]);
        Db::name('ls_chapter')->where('id', $id)->update(['chapter_status' => 'POLISHED']);

        return $this->ok(['polished' => $text]);
    }

    /** 全书上下文（各章标题 + 口述原文开头），供润色时统一称谓与时间线 */
    protected function bookContextForProject(int $projectId): string
    {
        $items = [];
        $rows  = Db::name('ls_chapter')
            ->where('project_id', $projectId)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select();
        foreach ($rows as $c) {
            $items[] = [
                'title' => (string) $c['title'],
                'text'  => $this->currentTranscript((int) $c['id']),
            ];
        }
        return AiGatewayService::bookContext($items);
    }

    /**
     * 章节录音引导（AI 生成漫画式对话框提示）：
     *  - 无录音（seed）：根据章节标题给出「可以聊些什么」的切入点/举例；
     *  - 有录音（continue）：把该章**已录的全部文案**交给模型，生成「接着往下聊什么」的续接引导
     *    （问的是整章而非最后一段，避免引导重复长辈已经讲过的内容）。
     * 优先使用前端传入的 last_transcript；缺失时回源。
     */
    public function guidance($id)
    {
        $chapter = $this->ownedChapter($id);

        $transcript = trim((string) input('post.last_transcript/s', ''));
        if ($transcript === '') {
            $transcript = $this->chapterTranscript((int) $id);
        }

        $text = AiGatewayService::guidance((string) $chapter['title'], $transcript);

        return $this->ok([
            'mode'     => $transcript !== '' ? 'continue' : 'seed',
            'guidance' => $text,
        ]);
    }

    /**
     * 该章已录的全部转写文案（多段拼接）：优先取 TRANSCRIPT 累积文本，
     * 缺失时回退拼接各段录音 meta.text（按 id 升序）。
     */
    protected function chapterTranscript(int $chapterId): string
    {
        $full = trim($this->currentTranscript($chapterId));
        if ($full !== '') {
            return $full;
        }

        $parts = [];
        $segs  = Db::name('ls_chapter_asset')
            ->where('chapter_id', $chapterId)
            ->where('asset_type', 'RECORDING')
            ->order('id', 'asc')
            ->select();
        foreach ($segs as $s) {
            $m = json_decode((string) ($s['meta'] ?? ''), true) ?: [];
            $t = trim((string) ($m['text'] ?? ''));
            if ($t !== '') {
                $parts[] = $t;
            }
        }
        return implode("\n", $parts);
    }

    /**
     * 生成章节朗读配音（腾讯云语音合成 TTS）：
     *   文案优先用 AI 润色稿（POLISHED_TEXT），没有则用口述原文（TRANSCRIPT）；
     *   合成结果落盘并写 AUDIO_DUBBED 资源 —— 翻书预览页的「AI 润声」会优先播这一条。
     * 幂等：已有配音直接返回，传 force=1 才重新合成（避免重复消耗合成额度）。
     *
     * 将来做「声音复刻」：只需把 AI_TTS_TENCENT_VOICE_TYPE 换成复刻得到的 VoiceId，本方法无需改动。
     */
    public function dub($id)
    {
        $this->ownedChapter($id);
        $cid = (int) $id;

        $exist = Db::name('ls_chapter_asset')
            ->where('chapter_id', $cid)
            ->where('asset_type', 'AUDIO_DUBBED')
            ->order('id', 'desc')
            ->find();
        if ($exist && !input('post.force/d', 0)) {
            $m = json_decode((string) ($exist['meta'] ?? ''), true) ?: [];
            $u = trim((string) ($m['url'] ?? $exist['oss_key'] ?? ''));
            if ($u !== '') {
                return $this->ok(['url' => $u, 'cached' => true]);
            }
        }

        // 文案：润色稿优先，其次口述原文
        $text = '';
        $row  = Db::name('ls_chapter_asset')
            ->where('chapter_id', $cid)
            ->where('asset_type', 'POLISHED_TEXT')
            ->order('id', 'desc')
            ->find();
        if ($row) {
            $text = trim((string) ((json_decode((string) $row['meta'], true) ?: [])['text'] ?? ''));
        }
        if ($text === '') {
            $text = trim($this->chapterTranscript($cid));
        }
        if ($text === '') {
            throw new ApiException(42209, '本章还没有可朗读的文案（先录音转写，或定稿后生成润色稿）', 422);
        }

        if (AiGatewayService::isMock('tts')) {
            throw new ApiException(50026, '语音合成未配置：请填 AI_TTS_TENCENT_* 并在「语音合成控制台」开通服务', 500);
        }

        $url = TencentTtsService::synthesize($text, 'ch' . $cid);
        $this->upsertAsset($cid, 'AUDIO_DUBBED', $url, ['url' => $url, 'chars' => mb_strlen($text)]);

        return $this->ok(['url' => $url, 'chars' => mb_strlen($text)]);
    }

    /** 读取章节当前口述原文（累积文本） */
    protected function currentTranscript(int $chapterId): string
    {
        $row = Db::name('ls_chapter_asset')
            ->where('chapter_id', $chapterId)
            ->where('asset_type', 'TRANSCRIPT')
            ->order('id', 'desc')
            ->find();
        if (!$row) {
            return '';
        }
        $meta = json_decode((string) $row['meta'], true) ?: [];
        return (string) ($meta['text'] ?? '');
    }


    /**
     * 生成章节配图（AI 文生图，腾讯云混元生图）
     *
     * 真正的实现在 `ChapterIllustrationService::generate()`（队列任务同一份，别在这儿重写业务）。
     * 这里只做三件事：归属校验 → 调服务 → 把结果转成前端契约（cached / pending / url）。
     *
     * 正常流程下配图由「定稿时入队的 ILLUSTRATE 任务」自动生成；
     * 本接口留给「手动重试/重新生成」用，所以保留同步返回（幂等，已有图直接回 cached）。
     */
    public function illustrate($id)
    {
        $this->ownedChapter($id);
        $cid = (int) $id;

        try {
            $r = ChapterIllustrationService::generate($cid);
        } catch (\RuntimeException $e) {
            throw new ApiException(50001, $e->getMessage(), 500);
        }

        if (!empty($r['cached'])) {
            return $this->ok(['url' => $r['url'], 'cached' => true]);
        }
        if ((string) ($r['status'] ?? '') !== 'done') {
            return $this->ok([
                'pending' => true,
                'job_id'  => $r['job_id'],
                'prompt'  => $r['prompt'],
            ], '配图生成中，请稍后重试本章');
        }

        return $this->ok(['url' => $r['url'], 'prompt' => $r['prompt'], 'mock' => !empty($r['mock'])]);
    }

    /** 章节当前配图 URL（AI 生成优先，其次用户上传；都没有返回空串） */
    protected function currentImage(int $chapterId): string
    {
        foreach (['AI_IMAGE', 'USER_IMAGE'] as $type) {
            $row = Db::name('ls_chapter_asset')
                ->where('chapter_id', $chapterId)
                ->where('asset_type', $type)
                ->order('id', 'desc')
                ->find();
            if (!$row) {
                continue;
            }
            $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
            $url  = trim((string) ($meta['url'] ?? $row['oss_key'] ?? ''));
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    /** 上一轮未完成生图任务的 job_id（有则续询，避免重复提交烧额度） */
    protected function pendingJobId(int $chapterId): string
    {
        $row = Db::name('ls_chapter_asset')
            ->where('chapter_id', $chapterId)
            ->where('asset_type', 'AI_IMAGE')
            ->order('id', 'desc')
            ->find();
        if (!$row) {
            return '';
        }
        $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
        return trim((string) ($meta['job_id'] ?? ''));
    }

    /** 把站点内相对 URL（/uploads/xxx）映射为磁盘绝对路径；外链或不存在返回空串 */
    protected function localPathOf(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $path = stripos($url, 'http') === 0 ? (string) parse_url($url, PHP_URL_PATH) : $url;
        $abs  = app()->getRootPath() . 'public' . $path;
        return is_file($abs) ? $abs : '';
    }

    /** 定稿：标记 DONE（ai_mock=true 时跳过配音任务） */
    public function confirm($id)
    {
        $this->ownedChapter($id);
        Db::name('ls_chapter')->where('id', $id)->update([
            'chapter_status' => 'DONE',
            'text_status'    => 'POLISHED',
        ]);
        if (config('app.ai_mock')) {
            return $this->ok([]);
        }
        $taskId = AiTaskService::push('DUB', $this->uid(), ['chapter_id' => $id], $id);
        return $this->ok(['task_id' => $taskId]);
    }

    /** 单独保存章节：标题 + 口述原文 + 润色故事 + 背景图（各字段可选，按需 upsert） */
    public function save($id)
    {
        $chapter = $this->ownedChapter($id);

        $title = input('post.title/s', null);
        $transcript = input('post.transcript/s', null);
        $polished = input('post.polished/s', null);
        $background = input('post.background/s', null);

        if ($title !== null && $title !== '') {
            Db::name('ls_chapter')->where('id', $id)->update(['title' => $title]);
        }
        if ($transcript !== null) {
            if ($transcript === '') {
                Db::name('ls_chapter_asset')->where('chapter_id', $id)->where('asset_type', 'TRANSCRIPT')->delete();
            } else {
                $this->upsertAsset($id, 'TRANSCRIPT', '', ['text' => $transcript]);
            }
        }
        if ($polished !== null) {
            if ($polished === '') {
                Db::name('ls_chapter_asset')->where('chapter_id', $id)->where('asset_type', 'POLISHED_TEXT')->delete();
            } else {
                $this->upsertAsset($id, 'POLISHED_TEXT', '', ['text' => $polished]);
            }
        }
        if ($background !== null) {
            if ($background === '') {
                Db::name('ls_chapter_asset')->where('chapter_id', $id)->where('asset_type', 'AI_IMAGE')->delete();
            } else {
                $this->upsertAsset($id, 'AI_IMAGE', $background, ['url' => $background]);
            }
        }

        // 章节状态随内容推进
        $status = $chapter['chapter_status'];
        if ($background !== '' || ($polished !== null && $polished !== '')) {
            $status = 'POLISHED';
        } elseif ($transcript !== null && $transcript !== '') {
            $status = 'TRANSCRIBED';
        }
        Db::name('ls_chapter')->where('id', $id)->update(['chapter_status' => $status]);

        return $this->ok([]);
    }

}

