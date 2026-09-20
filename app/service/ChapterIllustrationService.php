<?php
namespace app\service;

use think\facade\Db;
use app\service\AssetStorage;

/**
 * 配图（腾讯云混元生图）—— 从 Api\ChapterController 抽出来，供「用户端接口」与「队列任务」共用一份实现。
 *
 * 两类图，都在**定稿时入队、由后台 worker 生成**（见 AiTaskService/AiJob）：
 *   ① 章节配图 ILLUSTRATE —— 人物：取传主档案（性别/出生年月/简介）→ LLM 产出「人物外观基线」；
 *      场景/年代：章节标题 + 该章口述原文 → LLM 判断本章所处年代 + 场景；
 *      **画风由年代决定**（见 AiGatewayService::ERA_STYLES）→ 提交混元生图（带传主头像作参考图）；
 *   ② 封面主视觉 COVER_IMAGE —— 传主形象 + 一生气质，画风按出生年代；用户上传过封面则不覆盖。
 *
 * 幂等：已有配图直接返回（不重复烧额度）；上一轮超时未出图的任务按 meta.job_id / cover_job_id 续询。
 * 未配置混元生图时退回本地图池随机，保证流程仍可演示。
 */
class ChapterIllustrationService
{
    /**
     * 生成（或续询）单章配图。
     *
     * @param int  $chapterId 章节 id
     * @param bool $force     强制重新生成（忽略已有配图）
     *
     * @return array{status:string,url:string,job_id:string,prompt:string,cached:bool,mock:bool}
     *         status = done（已出图，url 有效）| pending（云端仍在生成，可用同一 job_id 再调）
     *
     * @throws \RuntimeException 章节不存在 / 未配置且无兜底图（交调用方决定重试或报错）
     */
    public static function generate(int $chapterId, bool $force = false): array
    {
        $chapter = Db::name('ls_chapter')->where('id', $chapterId)->find();
        if (!$chapter) {
            throw new \RuntimeException('章节不存在：' . $chapterId);
        }

        if (!$force) {
            $exists = self::currentImage($chapterId);
            if ($exists !== '') {
                return ['status' => 'done', 'url' => $exists, 'job_id' => '', 'prompt' => '', 'cached' => true, 'mock' => false];
            }
        }

        // 未配置混元生图：退回本地图池（保证演示流程走得通）
        if (AiGatewayService::isMock('image')) {
            $pool = self::bgPool();
            if (!$pool) {
                throw new \RuntimeException(
                    '配图未配置：请设置 AI_IMAGE_TENCENT_SECRET_ID/KEY 并开通「混元生图」，或往 public/uploads/chapter-bg 放几张兜底图'
                );
            }
            $url = $pool[array_rand($pool)];
            self::upsertAsset($chapterId, 'AI_IMAGE', $url, ['url' => $url]);
            return ['status' => 'done', 'url' => $url, 'job_id' => '', 'prompt' => '', 'cached' => false, 'mock' => true];
        }

        // ① 先判断本章所处年代（画风由年代决定）→ ② 按该年代算人物当时的年龄 → ③ 人物外观基线
        $project  = Db::name('ls_project')->where('id', $chapter['project_id'])->find() ?: [];
        $birthYear = self::birthYearOf($project);
        $plan = AiGatewayService::imagePlanForChapter(
            (string) $chapter['title'],
            self::transcriptOf($chapterId),
            $birthYear,
            (string) ($project['real_name'] ?? '')
        );
        $person = AiGatewayService::personBrief([
            'name'        => (string) ($project['real_name'] ?? ''),
            'gender'      => (string) ($project['gender'] ?? ''),
            'birth'       => (string) ($project['birth'] ?? ''),
            'description' => (string) ($project['description'] ?? ''),
        ], (int) $plan['age_hint']);
        $prompt = AiGatewayService::composeImagePrompt($plan, $person);

        // ③ 参考图：传主头像（让各章人物长相一致）
        $refPath = '';
        if ((int) config('ai.image.tencent.use_avatar', 1) === 1 && !empty($project['avatar'])) {
            $refPath = self::localPathOf((string) $project['avatar']);
        }

        // 有未出图的 job_id 时续询，别重新提交（否则又烧一次额度）
        $res = TencentImageService::generate($prompt, '', $refPath, self::pendingJobId($chapterId));
        if ((string) ($res['status'] ?? '') !== 'done') {
            self::upsertAsset($chapterId, 'AI_IMAGE', '', [
                'job_id' => (string) ($res['job_id'] ?? ''),
                'prompt' => $prompt,
                'era'    => (string) $plan['era'],
                'style'  => (string) $plan['style'],
            ]);
            return [
                'status' => 'pending',
                'url'    => '',
                'job_id' => (string) ($res['job_id'] ?? ''),
                'prompt' => $prompt,
                'cached' => false,
                'mock'   => false,
            ];
        }

        $url = TencentImageService::download(
            (string) $res['url'],
            'ai',
            AssetStorage::projectDir((int) ($project['user_id'] ?? 0), (int) $chapter['project_id'], AssetStorage::KIND_BGM),
            'chapter_' . $chapterId . '.png'
        );
        self::upsertAsset($chapterId, 'AI_IMAGE', $url, [
            'url'    => $url,
            'prompt' => $prompt,
            'person' => $person,
            'era'    => (string) $plan['era'],
            'style'  => (string) $plan['style'],
        ]);

        return ['status' => 'done', 'url' => $url, 'job_id' => '', 'prompt' => $prompt, 'cached' => false, 'mock' => false];
    }

    /**
     * 生成（或续询）**封面主视觉**（同一个定稿任务链里的第二类配图）。
     *
     * 与章节配图的差别：封面要的是「传主形象 + 一生气质」，画风按**出生年代**定；
     * 且画面上不能有文字（AI 写中文会糊），书名由翻书页排版叠加。
     *
     * 优先级保护：用户自己上传过封面（cover_bg_src=user）就不动它。
     *
     * @return array{status:string,url:string,job_id:string,cached:bool,mock:bool,skipped:bool}
     *         status = done | pending | skipped
     */
    public static function generateCover(int $projectId, bool $force = false): array
    {
        $project = Db::name('ls_project')->where('id', $projectId)->find();
        if (!$project) {
            throw new \RuntimeException('项目不存在：' . $projectId);
        }

        $src   = (string) ($project['cover_bg_src'] ?? '');
        $cover = (string) ($project['cover_bg'] ?? '');

        // 用户自己选的封面最美：不覆盖（除非显式 force）
        if (!$force && $src === 'user') {
            return ['status' => 'skipped', 'url' => $cover, 'job_id' => '', 'cached' => true, 'mock' => false, 'skipped' => true];
        }
        // 幂等：已经是我们生成的封面，直接返回
        if (!$force && $src === 'ai' && $cover !== '' && !self::isPending($project)) {
            return ['status' => 'done', 'url' => $cover, 'job_id' => '', 'cached' => true, 'mock' => false, 'skipped' => false];
        }

        $profile = [
            'name'        => (string) ($project['real_name'] ?? ''),
            'gender'      => (string) ($project['gender'] ?? ''),
            'birth'       => (string) ($project['birth'] ?? ''),
            'description' => (string) ($project['description'] ?? ''),
        ];
        $plan   = AiGatewayService::imagePlanForCover($profile, (string) ($project['real_name'] ?? ''));
        $prompt = (string) $plan['prompt'];

        // 未配置混元生图：退回本地图池（与章节配图一致的处理）
        if (AiGatewayService::isMock('image')) {
            $pool = self::bgPool();
            if (!$pool) {
                throw new \RuntimeException('封面配图未配置：请开通混元生图，或往 public/uploads/chapter-bg 放几张兜底图');
            }
            $url = $pool[array_rand($pool)];
            Db::name('ls_project')->where('id', $projectId)->update([
                'cover_bg'     => $url,
                'cover_bg_src' => 'pool',
                'cover_job_id' => '',
            ]);
            return ['status' => 'done', 'url' => $url, 'job_id' => '', 'cached' => false, 'mock' => true, 'skipped' => false];
        }

        $refPath = '';
        if ((int) config('ai.image.tencent.use_avatar', 1) === 1 && !empty($project['avatar'])) {
            $refPath = self::localPathOf((string) $project['avatar']);
        }

        $res = TencentImageService::generate(
            $prompt,
            '',
            $refPath,
            (string) ($project['cover_job_id'] ?? '')
        );
        if ((string) ($res['status'] ?? '') !== 'done') {
            Db::name('ls_project')->where('id', $projectId)->update([
                'cover_job_id' => (string) ($res['job_id'] ?? ''),
            ]);
            return [
                'status'  => 'pending',
                'url'     => '',
                'job_id'  => (string) ($res['job_id'] ?? ''),
                'cached'  => false,
                'mock'    => false,
                'skipped' => false,
            ];
        }

        $url = TencentImageService::download(
            (string) $res['url'],
            'cover',
            AssetStorage::projectDir((int) ($project['user_id'] ?? 0), $projectId, AssetStorage::KIND_BGM),
            'cover.png'
        );
        Db::name('ls_project')->where('id', $projectId)->update([
            'cover_bg'     => $url,
            'cover_bg_src' => 'ai',
            'cover_job_id' => '',
        ]);

        return ['status' => 'done', 'url' => $url, 'job_id' => '', 'cached' => false, 'mock' => false, 'skipped' => false];
    }

    /** 该项目的封面是否正处在「已提交云端、等出图」的状态 */
    public static function isPending(array $project): bool
    {
        return trim((string) ($project['cover_job_id'] ?? '')) !== ''
            && (string) ($project['cover_bg_src'] ?? '') !== 'ai';
    }

    /** 传主出生年份（无则 0） */
    public static function birthYearOf(array $project): int
    {
        $birth = trim((string) ($project['birth'] ?? ''));
        if ($birth === '') {
            return 0;
        }
        $ts = strtotime($birth);
        return $ts ? (int) date('Y', $ts) : 0;
    }

    /** 章节当前配图 URL（AI 生成优先，其次用户上传；都没有返回空串） */
    public static function currentImage(int $chapterId): string
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
    public static function pendingJobId(int $chapterId): string
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

    /** 本章口述原文（TRANSCRIPT 累积文本） */
    public static function transcriptOf(int $chapterId): string
    {
        $row = Db::name('ls_chapter_asset')
            ->where('chapter_id', $chapterId)
            ->where('asset_type', 'TRANSCRIPT')
            ->order('id', 'desc')
            ->find();
        if (!$row) {
            return '';
        }
        $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];
        return (string) ($meta['text'] ?? '');
    }

    /** 写/覆盖一条章节资源（ossKey 与 meta 都空时表示清空） */
    public static function upsertAsset(int $chapterId, string $type, string $ossKey, array $meta): void
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
            'meta'       => self::encodeMeta($meta),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * meta 是 MySQL JSON 列：不能写空串（会报 `Invalid JSON text: "The document is empty."`）。
     * json_encode 默认遇非法 UTF-8（LLM 生成的 prompt 偶尔会带）会**静默返回 false** → 写空串 → 任务失败重试。
     * 这里用 JSON_INVALID_UTF8_SUBSTITUTE 容错，并做非空兜底，绝不让空串进 JSON 列。
     */
    public static function encodeMeta(array $meta): string
    {
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return (is_string($json) && $json !== '') ? $json : '{}';
    }

    /** 兜底图池：扫描 public/uploads/chapter-bg 目录 */
    public static function bgPool(): array
    {
        $dir = app()->getRootPath() . 'public/uploads/chapter-bg';
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
        return array_map(fn($f) => '/uploads/chapter-bg/' . basename($f), $files);
    }

    /** 把站点内相对 URL（/uploads/xxx）映射为磁盘绝对路径；外链或不存在返回空串 */
    public static function localPathOf(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $path = stripos($url, 'http') === 0 ? (string) parse_url($url, PHP_URL_PATH) : $url;
        $abs  = app()->getRootPath() . 'public' . $path;
        return is_file($abs) ? $abs : '';
    }
}
