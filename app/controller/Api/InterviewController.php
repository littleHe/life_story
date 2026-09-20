<?php
namespace app\controller\Api;

use app\BaseController;
use app\common\exception\ApiException;
use app\service\AiGatewayService;
use app\service\RecordingService;
use think\facade\Db;

/**
 * 亲友免登录「访谈」录制页（公开接口）
 *
 * 链路复用用户端已有的录音 / 转写逻辑（RecordingService）：
 * - GET  /api/interview/:token          项目 + 章节列表（含已录段数 / 口述原文摘要）
 * - POST /api/interview/:token/recording 亲友免登录追加一段录音（base64）
 * - POST /api/interview/:token/asr       亲友免登录转写（写回分段 meta + 重算口述原文）
 *
 * 鉴权：凭 ls_project.interview_token 访问，仅「采集中(EDITABLE)」项目可用。
 */
class InterviewController extends BaseController
{
    /** 校验访谈 token，返回 project 行（仅采集中项目可录） */
    protected function byToken(string $token): array
    {
        if ($token === '') {
            throw new ApiException(40401, '访谈链接无效', 404);
        }
        $proj = Db::name('ls_project')
            ->where('interview_token', $token)
            ->where('status', 'EDITABLE')
            ->find();
        if (!$proj) {
            throw new ApiException(40401, '访谈链接不存在或已失效（仅「采集中」的回忆录可录制）', 404);
        }
        return $proj;
    }

    /** 校验访谈 token + 章节归属，返回章节行 */
    protected function chapterOf(string $token, int $chapterId): array
    {
        $proj = $this->byToken($token);
        if (!$chapterId) {
            throw new ApiException(42201, '缺少 chapter_id', 422);
        }
        $chapter = Db::name('ls_chapter')
            ->where('id', $chapterId)
            ->where('project_id', $proj['id'])
            ->find();
        if (!$chapter) {
            throw new ApiException(40401, '章节不存在', 404);
        }
        return $chapter;
    }

    /** GET /api/interview/:token —— 亲友免登录，返回传主信息 + 章节列表 */
    public function show($token)
    {
        $proj = $this->byToken($token);
        $chapters = Db::name('ls_chapter')
            ->where('project_id', $proj['id'])
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        $list = [];
        foreach ($chapters as $ch) {
            $segs  = RecordingService::segments((int) $ch['id']);
            $texts = [];
            foreach ($segs as $s) {
                $t = trim($s['text']);
                if ($t !== '') {
                    $texts[] = $t;
                }
            }
            $list[] = [
                'id'           => (int) $ch['id'],
                'title'        => (string) $ch['title'],
                'record_count' => count($segs),
                'transcript'   => implode("\n", $texts),
                // 回显每段录音（asset_id/url/seq/duration/text），供亲友页列出并支持删除
                'recordings'   => $segs,
            ];
        }

        return $this->ok([
            'project'  => [
                'id'          => (int) $proj['id'],
                'name'        => (string) $proj['name'],
                'real_name'   => (string) $proj['real_name'],
                'description' => (string) $proj['description'],
                'gender'      => (string) $proj['gender'],
                'avatar'      => (string) $proj['avatar'],
            ],
            'chapters' => $list,
        ]);
    }

    /** POST /api/interview/:token/recording —— 亲友免登录追加一段录音 */
    public function record($token)
    {
        $chapter = $this->chapterOf($token, (int) input('post.chapter_id/d', 0));
        $chapterId = (int) $chapter['id'];

        // 单章最多分段（匿名防刷）
        if (RecordingService::countSegments($chapterId) >= RecordingService::MAX_SEGMENTS) {
            throw new ApiException(42901, '本章录音段数已达上限（' . RecordingService::MAX_SEGMENTS . '）', 429);
        }

        $b64 = input('post.audio_base64/s', '');
        $ext = input('post.audio_ext/s', 'webm') ?: 'webm';
        if ($b64 === '') {
            throw new ApiException(42201, '缺少录音数据', 422);
        }

        $ossKey = RecordingService::saveBase64($b64, $ext);
        $res    = RecordingService::appendSegment($chapterId, $ossKey, input('post.duration/s', ''));

        return $this->ok([
            'url'      => $ossKey,
            'asset_id' => $res['asset_id'],
            'seq'      => $res['seq'],
        ]);
    }

    /** POST /api/interview/:token/asr —— 亲友免登录转写 */
    public function asr($token)
    {
        $chapter = $this->chapterOf($token, (int) input('post.chapter_id/d', 0));
        $chapterId = (int) $chapter['id'];
        $assetId = (int) input('post.asset_id/d', 0);

        $res = RecordingService::transcribe($chapterId, $assetId, (string) $chapter['title']);

        return $this->ok([
            'text'       => $res['text'],
            'transcript' => $res['transcript'],
            'asset_id'   => $res['asset_id'],
        ]);
    }

    /**
     * POST /api/interview/:token/recording/delete —— 亲友免登录删除一段录音（录错了可删）
     * 双重校验：token 有效 + 章节属于该 token 项目（chapterOf），asset 属于该章（deleteSegment）。
     */
    public function deleteRecording($token)
    {
        $chapter   = $this->chapterOf($token, (int) input('post.chapter_id/d', 0));
        $chapterId = (int) $chapter['id'];
        $assetId   = (int) input('post.asset_id/d', 0);
        if (!$assetId) {
            throw new ApiException(42201, '缺少 asset_id', 422);
        }

        $res = RecordingService::deleteSegment($chapterId, $assetId);

        return $this->ok([
            'transcript' => $res['transcript'],
            'recordings' => $res['recordings'],
        ], '录音已删除');
    }

    /**
     * POST /api/interview/:token/guidance —— 亲友免登录录音引导（漫画气泡文案）
     * 与用户端 ChapterController::guidance 同源：无录音给「切入点」，有录音给「续接方向」。
     */
    public function guidance($token)
    {
        $chapter = $this->chapterOf($token, (int) input('post.chapter_id/d', 0));
        $chapterId = (int) $chapter['id'];

        $transcript = trim((string) input('post.last_transcript/s', ''));
        if ($transcript === '') {
            // 回源：拼接该章所有已转写录音的文字（整章文案，避免续接引导重复已讲内容）
            $parts = [];
            foreach (RecordingService::segments($chapterId) as $s) {
                $t = trim((string) ($s['text'] ?? ''));
                if ($t !== '') {
                    $parts[] = $t;
                }
            }
            $transcript = implode("\n", $parts);
        }

        $text = AiGatewayService::guidance((string) $chapter['title'], $transcript);

        return $this->ok([
            'mode'     => $transcript !== '' ? 'continue' : 'seed',
            'guidance' => $text,
        ]);
    }

    /**
     * POST /api/projects/:id/interview-token —— 项目所有者生成访谈链接 token（需登录）
     * 默认幂等（已有 token 直接复用）；传 regenerate=1 则强制换新，旧链接立即失效（作废用途）。
     */
    public function generate($id)
    {
        $uid  = $this->uid();
        $proj = Db::name('ls_project')
            ->where('id', $id)
            ->where('user_id', $uid)
            ->find();
        if (!$proj) {
            throw new ApiException(40401, '项目不存在', 404);
        }

        $regenerate = (bool) input('post.regenerate/d', 0);
        $token      = (string) ($proj['interview_token'] ?? '');
        if ($regenerate || $token === '') {
            $token = bin2hex(random_bytes(16));
            Db::name('ls_project')->where('id', $id)->update(['interview_token' => $token]);
        }

        return $this->ok([
            'interview_token' => $token,
            'interview_url'   => '/memoirs/' . $id . '/interview?token=' . $token,
        ]);
    }
}
