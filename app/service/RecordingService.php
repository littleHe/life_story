<?php
namespace app\service;

use app\common\exception\ApiException;
use think\facade\Db;

/**
 * 录音分段与「口述原文」（用户端编辑页与亲友免登录访谈页共用）
 *
 * 约定：
 * - 每录一次存一条 RECORDING 资源，meta = {seq, duration, text}；seq 为段序号
 * - 章节「口述原文」= 全部 RECORDING 分段文字**按序拼合**（新增/删除段后重算，不做 append）
 * - 章节全文落一条 TRANSCRIPT 资源（由 rebuildTranscript 维护）
 */
class RecordingService
{
    /** 单章最多分段数（匿名访谈页防刷） */
    public const MAX_SEGMENTS = 60;

    /** 允许的音频扩展名 */
    private const ALLOWED_EXT = ['webm', 'mp3', 'm4a', 'mp4', 'ogg', 'wav', 'aac', 'amr'];

    /** 资源 upsert：删除同类型旧资源后写入一条新资源（清空场景只删不插） */
    public static function upsertAsset(int $chapterId, string $type, string $ossKey, array $meta): void
    {
        Db::name('ls_chapter_asset')
            ->where('chapter_id', $chapterId)
            ->where('asset_type', $type)
            ->delete();
        if ($ossKey === '' && empty($meta)) {
            return;
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
     * json_encode 遇非法 UTF-8（LLM 润色文本 / ASR 转写文本偶尔会带）会**静默返回 false** → 写空串 → 报错。
     */
    public static function encodeMeta(array $meta): string
    {
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return (is_string($json) && $json !== '') ? $json : '{}';
    }

    /** base64 音频落盘到 public/uploads/audio，返回 oss key（本地联调路径，规避 dev server multipart 限制） */
    public static function saveBase64(string $b64, string $ext = 'webm'): string
    {
        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext) ?: 'webm');
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            $ext = 'webm';
        }
        $bin = base64_decode($b64, true);
        if ($bin === false || $bin === '') {
            throw new ApiException(42201, '录音数据为空或编码错误', 422);
        }
        if (strlen($bin) > 20 * 1024 * 1024) {
            throw new ApiException(41301, '单段录音请控制在 20MB 以内', 413);
        }
        $dir = app()->getRootPath() . 'public/uploads/audio';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $name = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
        file_put_contents($dir . '/' . $name, $bin);
        return '/uploads/audio/' . $name;
    }

    /** 追加一段录音（追加而非覆盖，保留每一段原始录音）；返回 [asset_id, seq] */
    public static function appendSegment(int $chapterId, string $ossKey, string $duration = ''): array
    {
        $seq = (int) Db::name('ls_chapter_asset')
            ->where('chapter_id', $chapterId)
            ->where('asset_type', 'RECORDING')
            ->count() + 1;
        $assetId = Db::name('ls_chapter_asset')->insertGetId([
            'chapter_id' => $chapterId,
            'asset_type' => 'RECORDING',
            'oss_key'    => $ossKey,
            'meta'       => json_encode([
                'seq'      => $seq,
                'duration' => $duration,
                'text'     => '',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Db::name('ls_chapter')->where('id', $chapterId)->update(['chapter_status' => 'RECORDED']);
        return ['asset_id' => (int) $assetId, 'seq' => $seq];
    }

    /** 章节的录音分段（按 id 升序 = 录入顺序，含每段转写文字） */
    public static function segments(int $chapterId): array
    {
        $rows = Db::name('ls_chapter_asset')
            ->where('chapter_id', $chapterId)
            ->where('asset_type', 'RECORDING')
            ->order('id asc')
            ->select()
            ->toArray();
        $list = [];
        foreach ($rows as $r) {
            $meta = json_decode((string) $r['meta'], true) ?: [];
            $list[] = [
                'asset_id' => (int) $r['id'],
                'url'      => (string) $r['oss_key'],
                'seq'      => (int) ($meta['seq'] ?? 0),
                'duration' => (string) ($meta['duration'] ?? ''),
                'text'     => (string) ($meta['text'] ?? ''),
            ];
        }
        return $list;
    }

    /** 口述原文 = 多段录音文字按序拼合；无分段时清空 TRANSCRIPT */
    public static function rebuildTranscript(int $chapterId): string
    {
        $texts = [];
        foreach (self::segments($chapterId) as $seg) {
            $t = trim($seg['text']);
            if ($t !== '') {
                $texts[] = $t;
            }
        }
        $full = implode("\n", $texts);
        if ($full === '') {
            Db::name('ls_chapter_asset')
                ->where('chapter_id', $chapterId)
                ->where('asset_type', 'TRANSCRIPT')
                ->delete();
        } else {
            self::upsertAsset($chapterId, 'TRANSCRIPT', '', ['text' => $full]);
        }
        return $full;
    }

    /** 转写指定分段（$assetId=0 取最新一段）：写回分段 meta + 重算口述原文 */
    public static function transcribe(int $chapterId, int $assetId = 0, string $chapterTitle = ''): array
    {
        $query = Db::name('ls_chapter_asset')
            ->where('chapter_id', $chapterId)
            ->where('asset_type', 'RECORDING');
        if ($assetId) {
            $query->where('id', $assetId);
        }
        $asset = $query->order('id', 'desc')->find();
        if (!$asset) {
            throw new ApiException(42203, '尚未上传录音，无法转写', 422);
        }

        // oss_key 形如 /uploads/audio/xxx.webm，落到 public 下读取
        $rel = ltrim((string) $asset['oss_key'], '/');
        $abs = app()->getRootPath() . 'public/' . $rel;
        if (!is_file($abs)) {
            throw new ApiException(40402, '录音文件不存在', 404);
        }
        $ext = pathinfo($abs, PATHINFO_EXTENSION) ?: 'webm';
        if ($chapterTitle === '') {
            $chapterTitle = (string) Db::name('ls_chapter')->where('id', $chapterId)->value('title');
        }

        $text = AiGatewayService::asr($abs, $ext, $chapterTitle);

        // 1) 分段文字写回该录音的 meta（每段录音下方展示对应文字）
        $meta = json_decode((string) $asset['meta'], true) ?: [];
        $meta['text'] = $text;
        $meta['transcribed_at'] = date('Y-m-d H:i:s');
        Db::name('ls_chapter_asset')->where('id', $asset['id'])->update([
            'meta' => self::encodeMeta($meta),
        ]);

        // 2) 口述原文 = 多段录音文字拼合
        $full = self::rebuildTranscript($chapterId);
        Db::name('ls_chapter')->where('id', $chapterId)->update(['chapter_status' => 'TRANSCRIBED']);

        return [
            'text'       => $text,
            'transcript' => $full,
            'asset_id'   => (int) $asset['id'],
        ];
    }

    /** 删除一段录音（含音频文件），返回剩余分段与重算后的口述原文 */
    public static function deleteSegment(int $chapterId, int $assetId): array
    {
        $asset = Db::name('ls_chapter_asset')
            ->where('id', $assetId)
            ->where('chapter_id', $chapterId)
            ->where('asset_type', 'RECORDING')
            ->find();
        if (!$asset) {
            throw new ApiException(40402, '录音不存在或已删除', 404);
        }
        $rel = ltrim((string) $asset['oss_key'], '/');
        if ($rel !== '' && strpos($rel, 'uploads/audio/') === 0) {
            $abs = app()->getRootPath() . 'public/' . $rel;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        Db::name('ls_chapter_asset')->where('id', $assetId)->delete();

        return [
            'transcript' => self::rebuildTranscript($chapterId),
            'recordings' => self::segments($chapterId),
        ];
    }

    /** 章节已有录音段数 */
    public static function countSegments(int $chapterId): int
    {
        return (int) Db::name('ls_chapter_asset')
            ->where('chapter_id', $chapterId)
            ->where('asset_type', 'RECORDING')
            ->count();
    }
}
