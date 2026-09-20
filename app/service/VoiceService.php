<?php
namespace app\service;

use app\common\exception\ApiException;
use think\facade\Db;
use app\service\AssetStorage;

/**
 * 有声书配音（AI 音）—— 把「润色后的文案」读成录音文件，供翻书预览播放。
 *
 * 三类朗读目标（都由定稿时的 NARRATE 异步任务触发，见 app/queue/AiJob.php）：
 *   ① 章节正文 —— 润色稿优先，缺了回退口述原文；落 ls_chapter_asset(type=AUDIO_DUBBED)
 *   ② 封面书封语 —— 书名 + 传主 + 出生年 + 籍贯 + 简介；落 ls_project.cover_audio
 *   ③ 尾页结束语 —— 后台「页尾结束文案」；落 ls_project.ending_audio
 *
 * 音色策略（resolve）：
 *   复刻音色（ls_project.voice_type 且 voice_status=ready）> 按传主性别的标准音色
 *   （男/女编号见 config/ai.php → tts.tencent.voice_male / voice_female）。
 *   复刻训练中 → wait=true，任务侧返回 __retry 等它训练完（训练失败则回落标准音色，不阻塞交付）。
 */
class VoiceService
{
    /** 章节已生成配音则直接复用（除非 force） */
    public static function currentAudio(int $chapterId): string
    {
        $row = Db::name('ls_chapter_asset')
            ->where('chapter_id', $chapterId)
            ->where('asset_type', 'AUDIO_DUBBED')
            ->order('id', 'desc')
            ->find();
        if (!$row) {
            return '';
        }
        $meta = json_decode((string) $row['meta'], true) ?: [];
        return trim((string) ($meta['url'] ?? $row['oss_key'] ?? ''));
    }

    /**
     * 解析本项目该用哪个音色。
     *
     * @return array{voice_type:int,source:string,label:string,wait:bool}
     */
    public static function resolve(array $project): array
    {
        $vt = (int) ($project['voice_type'] ?? 0);
        $st = (string) ($project['voice_status'] ?? '');

        if ($vt > 0 && $st === 'ready') {
            return [
                'voice_type' => $vt,
                'source'     => 'clone',
                'label'      => (string) ($project['voice_label'] ?? '') ?: '原主复刻音色',
                'wait'       => false,
            ];
        }
        // 复刻还在训练：等它（训练失败会写 failed，届时自动回落标准音色）
        if ($vt === 0 && ($st === 'pending' || $st === 'training')) {
            return ['voice_type' => 0, 'source' => 'clone', 'label' => '原主复刻音色', 'wait' => true];
        }

        $v = TencentTtsService::voiceTypeForGender((string) ($project['gender'] ?? 'male'));
        return [
            'voice_type' => (int) $v['voice_type'],
            'source'     => 'gender',
            'label'      => (string) $v['label'],
            'wait'       => false,
        ];
    }

    /** 章节朗读文案：润色稿优先，回退口述原文 */
    public static function textForChapter(int $chapterId): array
    {
        $pick = static function (string $type) use ($chapterId): string {
            $row = Db::name('ls_chapter_asset')
                ->where('chapter_id', $chapterId)
                ->where('asset_type', $type)
                ->order('id', 'desc')
                ->find();
            if (!$row) {
                return '';
            }
            $meta = json_decode((string) $row['meta'], true) ?: [];
            return trim((string) ($meta['text'] ?? ''));
        };

        $polished = $pick('POLISHED_TEXT');
        if ($polished !== '') {
            return ['text' => self::forSpeech($polished), 'kind' => 'polished'];
        }
        $orig = $pick('TRANSCRIPT');
        return ['text' => self::forSpeech($orig), 'kind' => 'transcript'];
    }

    /** 封面的「书封语」：一句话介绍这本书与传主 */
    public static function textForCover(array $project): string
    {
        $name  = trim((string) ($project['name'] ?? ''));
        $who   = trim((string) ($project['real_name'] ?? ''));
        $place = trim((string) ($project['native_place'] ?? ''));
        $year  = '';
        if (!empty($project['birth'])) {
            $year = substr((string) $project['birth'], 0, 4);
            $year = ctype_digit($year) ? $year : '';
        }
        $desc = trim((string) ($project['description'] ?? ''));

        $parts = [];
        if ($name !== '') {
            $parts[] = '《' . $name . '》';
        }
        if ($who !== '') {
            $line = $who;
            if ($year !== '') {
                $line .= $year . '年出生';
            }
            if ($place !== '') {
                $line .= '，' . rtrim($place, '。.，, ') . '人';
            }
            $parts[] = $line . '。';
        }
        if ($desc !== '') {
            $parts[] = rtrim($desc, '。. ') . '。';
        }
        return self::forSpeech(implode('', $parts));
    }

    /** 尾页朗读文案（后台可配；留空则不生成尾页配音） */
    public static function textForEnding(): string
    {
        $t = trim(SiteConfigService::get('preview_end_text', SiteConfigService::DEFAULT_PREVIEW_END_TEXT));
        return self::forSpeech($t);
    }

    /**
     * 合成章节朗读（润色稿优先）→ 落 AUDIO_DUBBED。
     *
     * @param bool $force true=重新生成（手动重试）
     * @return array{status:string,url?:string,chars?:int,voice?:int,source?:string,label?:string,kind?:string,reason?:string}
     */
    public static function narrateChapter(int $chapterId, bool $force = false): array
    {
        if (!$force && self::currentAudio($chapterId) !== '') {
            return ['status' => 'done', 'cached' => true, 'url' => self::currentAudio($chapterId)];
        }
        $chapter = Db::name('ls_chapter')->where('id', $chapterId)->find();
        if (!$chapter) {
            throw new ApiException(40403, '章节不存在', 404);
        }
        $project = Db::name('ls_project')->where('id', (int) $chapter['project_id'])->find();
        if (!$project) {
            throw new ApiException(40401, '项目不存在', 404);
        }

        $voice = self::resolve($project);
        if (!empty($voice['wait'])) {
            return ['status' => 'pending', 'reason' => '等待原主音色复刻完成'];
        }

        $t = self::textForChapter($chapterId);
        if ($t['text'] === '') {
            return ['status' => 'skipped', 'reason' => '本章还没有可朗读的文案'];
        }

        $url = TencentTtsService::synthesize(
            $t['text'],
            'ch' . $chapterId,
            (int) $voice['voice_type'],
            AssetStorage::projectDir((int) ($project['user_id'] ?? 0), (int) $project['id'], AssetStorage::KIND_AUDIO),
            'chapter_' . $chapterId . '.mp3'
        );
        RecordingService::upsertAsset($chapterId, 'AUDIO_DUBBED', $url, [
            'url'        => $url,
            'chars'      => mb_strlen($t['text']),
            'kind'       => $t['kind'],       // polished | transcript
            'voice_type' => (int) $voice['voice_type'],
            'voice_from' => (string) $voice['source'],   // clone | gender
            'voice_label' => (string) $voice['label'],
        ]);

        return [
            'status'  => 'done',
            'url'     => $url,
            'chars'   => mb_strlen($t['text']),
            'kind'    => $t['kind'],
            'voice'   => (int) $voice['voice_type'],
            'source'  => (string) $voice['source'],
            'label'   => (string) $voice['label'],
        ];
    }

    /** 合成封面书封语 → 落 ls_project.cover_audio */
    public static function narrateCover(int $projectId, bool $force = false): array
    {
        $project = Db::name('ls_project')->where('id', $projectId)->find();
        if (!$project) {
            throw new ApiException(40401, '项目不存在', 404);
        }
        if (!$force && trim((string) ($project['cover_audio'] ?? '')) !== '') {
            return ['status' => 'done', 'cached' => true, 'url' => (string) $project['cover_audio']];
        }
        $voice = self::resolve($project);
        if (!empty($voice['wait'])) {
            return ['status' => 'pending', 'reason' => '等待原主音色复刻完成'];
        }
        $text = self::textForCover($project);
        if ($text === '') {
            return ['status' => 'skipped', 'reason' => '封面没有可朗读的文案'];
        }

        $url = TencentTtsService::synthesize(
            $text,
            'cover' . $projectId,
            (int) $voice['voice_type'],
            AssetStorage::projectDir((int) ($project['user_id'] ?? 0), $projectId, AssetStorage::KIND_AUDIO),
            'cover.mp3'
        );
        Db::name('ls_project')->where('id', $projectId)->update(['cover_audio' => $url]);

        return ['status' => 'done', 'url' => $url, 'chars' => mb_strlen($text), 'voice' => (int) $voice['voice_type']];
    }

    /** 合成尾页结束语 → 落 ls_project.ending_audio */
    public static function narrateEnding(int $projectId, bool $force = false): array
    {
        $project = Db::name('ls_project')->where('id', $projectId)->find();
        if (!$project) {
            throw new ApiException(40401, '项目不存在', 404);
        }
        if (!$force && trim((string) ($project['ending_audio'] ?? '')) !== '') {
            return ['status' => 'done', 'cached' => true, 'url' => (string) $project['ending_audio']];
        }
        $voice = self::resolve($project);
        if (!empty($voice['wait'])) {
            return ['status' => 'pending', 'reason' => '等待原主音色复刻完成'];
        }
        $text = self::textForEnding();
        if ($text === '') {
            return ['status' => 'skipped', 'reason' => '后台未配置尾页结束文案'];
        }

        $url = TencentTtsService::synthesize(
            $text,
            'end' . $projectId,
            (int) $voice['voice_type'],
            AssetStorage::projectDir((int) ($project['user_id'] ?? 0), $projectId, AssetStorage::KIND_AUDIO),
            'ending.mp3'
        );
        Db::name('ls_project')->where('id', $projectId)->update(['ending_audio' => $url]);

        return ['status' => 'done', 'url' => $url, 'chars' => mb_strlen($text), 'voice' => (int) $voice['voice_type']];
    }

    /** 朗读前的文本规整：去掉多余空白（换行当空格），避免合成时出现怪停顿 */
    private static function forSpeech(string $text): string
    {
        $t = preg_replace('/[\r\n]+/u', ' ', trim($text));
        $t = preg_replace('/[ \t\x{3000}]+/u', ' ', (string) $t);
        return trim((string) $t);
    }
}
