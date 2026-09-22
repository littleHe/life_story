<?php
namespace app\controller\Api;

use app\BaseController;
use app\common\exception\ApiException;
use app\service\AiGatewayService;
use app\service\AiTaskService;
use app\service\ChapterIllustrationService;
use app\service\RedemptionService;
use app\service\SiteUrlService;
use app\service\TencentVrsService;
use app\service\VoiceService;
use think\facade\Db;

class ProjectController extends BaseController
{
    /** 项目列表（附带章节数，供前端列表展示真实进度） */
    public function index()
    {
        $list = Db::name('ls_project')
            ->where('user_id', $this->uid())
            ->order('id', 'desc')
            ->select()
            ->toArray();

        // 一次性按 project_id 聚合章节数，避免前端逐个请求（N+1）
        $counts = [];
        if ($list) {
            $ids = array_column($list, 'id');
            $rows = Db::name('ls_chapter')
                ->where('project_id', 'in', $ids)
                ->field('project_id, COUNT(*) AS c')
                ->group('project_id')
                ->select()
                ->toArray();
            foreach ($rows as $r) {
                $counts[(string) $r['project_id']] = (int) $r['c'];
            }
        }
        foreach ($list as &$p) {
            $p['chapter_count'] = $counts[(string) $p['id']] ?? 0;
            $p['preview_url']   = SiteUrlService::healPreview(
                $p['preview_url'] ?? '',
                (string) ($p['preview_token'] ?? ''),
                (string) $this->request->domain()
            );
        }
        unset($p);

        return $this->ok($list);
    }

    /** 章节列表（含状态与最新文本，供前端章节编辑页渲染） */
    public function chaptersIndex($id)
    {
        $project = Db::name('ls_project')
            ->where('id', $id)
            ->where('user_id', $this->uid())
            ->find();
        if (!$project) {
            throw new ApiException(40401, '项目不存在', 404);
        }
        $chapters = Db::name('ls_chapter')
            ->where('project_id', $id)
            ->order('sort', 'asc')
            ->select()
            ->toArray();

        $ids = array_column($chapters, 'id');
        $texts = [];        // chapter_id => [TYPE => value]
        $recordings = [];   // chapter_id => [ [url, seq, duration, text, asset_id], ... ]
        if ($ids) {
            $assets = Db::name('ls_chapter_asset')
                ->where('chapter_id', 'in', $ids)
                ->where('asset_type', 'in', ['TRANSCRIPT', 'POLISHED_TEXT', 'AI_IMAGE', 'USER_IMAGE', 'RECORDING'])
                ->select()
                ->toArray();
            foreach ($assets as $a) {
                $meta = is_array($a['meta'])
                    ? $a['meta']
                    : (json_decode((string) $a['meta'], true) ?: []);
                if ($a['asset_type'] === 'RECORDING') {
                    // 分段录音：微信式逐段保存，聚合为列表（含转写文字与序号）
                    $recordings[$a['chapter_id']][] = [
                        'url'      => $a['oss_key'],
                        'seq'      => (int) ($meta['seq'] ?? 0),
                        'duration' => $meta['duration'] ?? '',
                        'text'     => $meta['text'] ?? '',
                        'asset_id' => (int) $a['id'],
                    ];
                } elseif (in_array($a['asset_type'], ['AI_IMAGE', 'USER_IMAGE'], true)) {
                    $texts[$a['chapter_id']][$a['asset_type']] = $meta['url'] ?? $a['oss_key'] ?? '';
                } else {
                    $texts[$a['chapter_id']][$a['asset_type']] = $meta['text'] ?? $meta['content'] ?? '';
                }
            }
        }

        foreach ($chapters as &$c) {
            $cid = $c['id'];
            $c['transcript'] = $texts[$cid]['TRANSCRIPT'] ?? '';
            $c['polished']   = $texts[$cid]['POLISHED_TEXT'] ?? '';
            // 章节背景：用户自定义（USER_IMAGE）优先于 AI 生成（AI_IMAGE）
            $c['ai_image']   = $texts[$cid]['USER_IMAGE'] ?? ($texts[$cid]['AI_IMAGE'] ?? '');
            $c['audio_url']  = isset($recordings[$cid]) ? ($recordings[$cid][0]['url'] ?? '') : '';
            $segs = $recordings[$cid] ?? [];
            usort($segs, fn($x, $y) => $x['seq'] <=> $y['seq']);
            $c['recordings'] = $segs;
        }
        return $this->ok($chapters);
    }

    /**
     * 新建项目：必须携带有效兑换码才可创建（未解锁不得进入下一步）。
     * 支持两种凭据：
     *   - code    手动输入的明文兑换码
     *   - code_id 从「我的兑换码」中选择（前端只有掩码，故按 id 绑定）
     * 任一凭据无效 → 不落库任何数据并直接报错。
     */
    public function save()
    {
        $name = input('post.name/s', '');
        if (!$name) {
            throw new ApiException(42201, '标题必填', 422);
        }

        $rawCode = strtoupper(trim(input('post.code/s', '')));
        $codeId  = (int) input('post.code_id/d', 0);
        if ($rawCode === '' && $codeId <= 0) {
            throw new ApiException(42203, '请输入或选择兑换码解锁后才能创建回忆录', 422);
        }

        // 1) 先只读校验（无效则此处即抛错，不留任何脏数据）
        if ($codeId <= 0) {
            RedemptionService::verify($this->uid(), $rawCode);
        }

        $id = Db::name('ls_project')->insertGetId([
            'user_id'    => $this->uid(),
            'name'       => $name,
            'real_name'  => input('post.real_name/s', ''),
            'gender'     => input('post.gender/s', 'male') === 'female' ? 'female' : 'male',
            'avatar'     => input('post.avatar/s', ''),
            'description'=> input('post.description/s', ''),
            'birth'      => input('post.birth/s', null),
            'native_place'=> input('post.native_place/s', ''),
            'status'     => 'UNPAID_LOCKED',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        // 2) 核销解锁；并发/状态变化导致失败时回滚掉刚建的项目，保证「未解锁 = 无项目」
        try {
            $code = $codeId > 0
                ? RedemptionService::bindById($this->uid(), (int) $id, $codeId)
                : RedemptionService::bind($this->uid(), (int) $id, $rawCode);
        } catch (\Throwable $e) {
            Db::name('ls_project')->where('id', $id)->delete();
            throw $e;
        }

        // 3) 兑换成功解锁后，按后台「默认章节」管理列表播种一套系统章节（已有章节则不重复生成）
        if ((int) Db::name('ls_chapter')->where('project_id', $id)->count() === 0) {
            $this->seedDefaultChapters((int) $id);
        }

        return $this->ok([
            'id'         => (int) $id,
            'status'     => 'EDITABLE',
            'unlimited'  => ((int) ($code['max_uses'] ?? 0) === 0) ? 1 : 0,
        ], '创建成功，回忆录已解锁');
    }

    /** 章节增删/排序（基础架构锁定前可用） */
    public function chapters($id)
    {
        $project = Db::name('ls_project')->where('id', $id)->where('user_id', $this->uid())->find();
        if (!$project) {
            throw new ApiException(40401, '项目不存在', 404);
        }
        if ($project['chapter_locked']) {
            throw new ApiException(42302, '基础架构已锁定，不可修改章节', 423);
        }

        $action = input('post.action/s', '');
        if ($action === 'add') {
            $title = trim(input('post.title/s', '新章节'));
            if ($title === '') {
                $title = '新章节';
            }
            // 同一项目内标题重复则不重复入库，直接返回已存在章节（避免脏数据）
            $exist = Db::name('ls_chapter')
                ->where('project_id', $id)
                ->where('title', $title)
                ->find();
            if ($exist) {
                return $this->ok(['chapter_id' => $exist['id'], 'duplicated' => true], '章节已存在，未重复添加');
            }
            // 回忆录章节上限 8 个
            $sort = Db::name('ls_chapter')->where('project_id', $id)->count();
            if ($sort >= 8) {
                throw new ApiException(42207, '章节最多 8 个，请先合并或删除后再新增', 422);
            }
            $cid  = Db::name('ls_chapter')->insertGetId([
                'project_id' => $id,
                'title'      => $title,
                'sort'       => $sort,
                'source'     => 'CUSTOM',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return $this->ok(['chapter_id' => $cid, 'duplicated' => false]);
        }
        if ($action === 'remove') {
            $cid = input('post.chapter_id/d', 0);
            Db::name('ls_chapter')->where('id', $cid)->where('project_id', $id)->delete();
            return $this->ok([]);
        }
        if ($action === 'sort') {
            $order = input('post.order/a', []); // [{id, sort}]
            foreach ($order as $o) {
                Db::name('ls_chapter')->where('id', $o['id'] ?? 0)
                    ->where('project_id', $id)
                    ->update(['sort' => (int) ($o['sort'] ?? 0)]);
            }
            return $this->ok([]);
        }
        throw new ApiException(42202, '未知 action', 422);
    }

    /** 项目详情 */
    public function read($id)
    {
        $project = Db::name('ls_project')
            ->where('id', $id)
            ->where('user_id', $this->uid())
            ->find();
        if (!$project) {
            throw new ApiException(40401, '项目不存在', 404);
        }
        // 预览地址自愈：库里若存的是 127.0.0.1/localhost（本地定稿写入的历史数据），
        // 按当前站点域名重建，避免线上「预览」跳到打不开的本机地址。
        $project['preview_url'] = SiteUrlService::healPreview(
            $project['preview_url'] ?? '',
            (string) ($project['preview_token'] ?? ''),
            (string) $this->request->domain()
        );
        return $this->ok($project);
    }

    /** 更新项目基础信息（名称/传主姓名/出生/籍贯） */
    public function update($id)
    {
        $project = Db::name('ls_project')
            ->where('id', $id)
            ->where('user_id', $this->uid())
            ->find();
        if (!$project) {
            throw new ApiException(40401, '项目不存在', 404);
        }
        $data = [];
        $name = input('post.name/s', null);
        if ($name !== null) {
            $data['name'] = $name;
        }
        $real = input('post.real_name/s', null);
        if ($real !== null) {
            $data['real_name'] = $real;
        }
        $gender = input('post.gender/s', null);
        if ($gender !== null) {
            $data['gender'] = $gender === 'female' ? 'female' : 'male';
        }
        $avatar = input('post.avatar/s', null);
        if ($avatar !== null) {
            $data['avatar'] = $avatar;
        }
        $desc = input('post.description/s', null);
        if ($desc !== null) {
            $data['description'] = $desc;
        }
        $birth = input('post.birth/s', null);
        if ($birth !== null) {
            $data['birth'] = $birth ?: null;
        }
        $native = input('post.native_place/s', null);
        if ($native !== null) {
            $data['native_place'] = $native;
        }
        if ($data) {
            Db::name('ls_project')->where('id', $id)->update($data);
        }
        return $this->ok([]);
    }

    /** 绑定核销兑换码（事务防双花） */
    public function bindCode($id)
    {
        $rawCode = input('post.code/s', '');
        if (!$rawCode) {
            throw new ApiException(42201, '请填写兑换码', 422);
        }
        $code = RedemptionService::bind($this->uid(), (int) $id, $rawCode);
        return $this->ok(['code' => $code], '绑定成功，项目已解锁');
    }

    /**
     * 提交定稿：状态置 MAKING（回忆制作中），确定封面背景，生成翻书预览地址。
     * 封面背景优先级：用户上传(首章 USER_IMAGE) > 后台封面背景图池随机。
     */
    public function finalize($id)
    {
        $project = Db::name('ls_project')
            ->where('id', $id)
            ->where('user_id', $this->uid())
            ->find();
        if (!$project) {
            throw new ApiException(40401, '项目不存在', 404);
        }

        // 入场先自愈：把上次定稿可能被 kill/超时遗留的孤儿任务重置，并拉起 worker 续跑
        AiTaskService::rescue();

        $chapters = Db::name('ls_chapter')->where('project_id', $id)->select()->toArray();
        if (!$chapters) {
            throw new ApiException(42205, '请先创建至少一个章节再定稿', 422);
        }
        $ids = array_column($chapters, 'id');
        $hasContent = Db::name('ls_chapter_asset')
            ->where('chapter_id', 'in', $ids)
            ->where('asset_type', 'in', ['TRANSCRIPT', 'POLISHED_TEXT', 'RECORDING'])
            ->count();
        if (!$hasContent) {
            throw new ApiException(42206, '请先录入至少一段口述内容再定稿', 422);
        }
        // 至少要有一段「真实录音」：声音复刻需要音频样本，只有手打文字无法复刻
        $hasRecording = Db::name('ls_chapter_asset')
            ->where('chapter_id', 'in', $ids)
            ->where('asset_type', 'RECORDING')
            ->count();
        if (!$hasRecording) {
            throw new ApiException(42208, '请至少录制一段口述（声音复刻需要真实录音样本）后再定稿', 422);
        }

        // 1) 封面首帧：已定则不覆盖；否则 用户上传 > 传主头像 > 后台图池随机。
        //    （真正的「混元生图封面」由下面 2.4) 的 COVER_IMAGE 任务异步生成，出图后会替换这里的首帧。
        //     唯一不替换的是「用户自己上传的封面」—— 那是用户的审美，必须尊重。）
        $coverBg  = (string) ($project['cover_bg'] ?? '');
        $coverSrc = (string) ($project['cover_bg_src'] ?? '');
        if ($coverSrc === '' && $coverBg !== '') {
            // 老数据没有来源标记：按值反推，避免把用户上传的封面误当成兜底图覆盖掉
            if (!empty($project['avatar']) && $coverBg === (string) $project['avatar']) {
                $coverSrc = 'avatar';
            } elseif (strpos($coverBg, '/cover-bg/cover-bg-') !== false) {
                $coverSrc = 'pool';
            } elseif (strpos($coverBg, '/chapter-bg/ai/') !== false || strpos($coverBg, '/chapter-bg/cover/') !== false) {
                $coverSrc = 'ai';
            } else {
                $coverSrc = 'user';
            }
        }
        if ($coverBg === '') {
            $userImg = Db::name('ls_chapter_asset')
                ->where('chapter_id', 'in', $ids)
                ->where('asset_type', 'USER_IMAGE')
                ->order('id', 'asc')
                ->find();
            if ($userImg) {
                $meta = json_decode((string) $userImg['meta'], true) ?: [];
                $coverBg  = (string) ($meta['url'] ?? $userImg['oss_key'] ?? '');
                $coverSrc = 'user';
            }
        }
        // 1.5) 基础信息中已要求必填的传主头像，作为封面背景兜底（优先于随机图池）
        if ($coverBg === '' && !empty($project['avatar'])) {
            $coverBg  = (string) $project['avatar'];
            $coverSrc = 'avatar';
        }
        if ($coverBg === '') {
            $pool = Db::name('ls_cover_bg')->where('status', 1)->column('image');
            if ($pool) {
                $coverBg  = (string) $pool[array_rand($pool)];
                $coverSrc = 'pool';
            }
        }

        // 2) 预览地址（幂等：已有 token 则复用）
        $token = (string) ($project['preview_token'] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(12));
        }
        // 站点根地址：优先 .env 的 APP_URL；未配置才回落当前请求域名。
        // （不能用裸 $request->domain()：本地开发会把 127.0.0.1:9411 写进库，导到线上打不开）
        $url = SiteUrlService::preview($token, (string) $this->request->domain());

        Db::name('ls_project')->where('id', $id)->update([
            'status'        => 'MAKING',
            'cover_bg'      => $coverBg,
            'cover_bg_src'  => $coverSrc,
            'preview_token' => $token,
            'preview_url'   => $url,
            'finalized_at'  => date('Y-m-d H:i:s'),
        ]);

        // 2.4) 声音复刻（可选）：用传主录音训练「原主音色」，训练成功后全书朗读自动换音色。
        //      默认关闭（AI_VRS_ENABLED=0）时跳过，VoiceService 按性别用标准音色，链路不受影响。
        $aiQueued = 0;
        if (TencentVrsService::enabled()
            && (int) ($project['voice_type'] ?? 0) <= 0
            && (string) ($project['voice_status'] ?? '') !== 'training') {
            // 先标记「复刻中」：让下面的 NARRATE 任务进入等待（VoiceService::resolve 见到 pending/training 返回 wait），
            // 避免克隆音色还没训练完、章节就先用性别标准音色读完（满足需求③「用原主音色朗读」）。
            // ⚠️ 若复刻最终失败/未启用，voiceClone 处理器会把 voice_status 改回 failed/none，等待自动解除、回落标准音色。
            if ((string) ($project['voice_status'] ?? '') !== 'pending') {
                Db::name('ls_project')->where('id', $id)->update(['voice_status' => 'pending']);
            }
            AiTaskService::push('VOICE_CLONE', $this->uid(), ['project_id' => (int) $id], null, (int) $id);
            $aiQueued++;
        }

        // 2.5) 封面主视觉：任务化生成（画风按传主出生年代）。
        //      用户自己上传过封面 → 不动；已有未完成的封面任务 → 不重复入队。
        if ($coverSrc !== 'user') {
            $coverRunning = Db::name('ls_ai_task')
                ->where('project_id', $id)
                ->where('task_type', 'COVER_IMAGE')
                ->whereIn('status', ['PENDING', 'RUNNING', 'RETRY'])
                ->count();
            if (!$coverRunning) {
                AiTaskService::push('COVER_IMAGE', $this->uid(), ['project_id' => (int) $id], null, (int) $id);
                $aiQueued++;
            }
        }

        // 2.6) AI 音朗读：封面书封语 + 尾页结束语（章节正文在 2.7 随润色稿一起排）。
        //      已有音频 / 已有运行中任务就跳过（幂等）。
        $topNarrRunning = Db::name('ls_ai_task')
            ->where('project_id', $id)
            ->where('task_type', 'NARRATE')
            ->whereNull('chapter_id')
            ->whereIn('status', ['PENDING', 'RUNNING', 'RETRY'])
            ->count();
        if (!$topNarrRunning) {
            if (trim((string) ($project['cover_audio'] ?? '')) === '') {
                AiTaskService::push('NARRATE', $this->uid(), ['scope' => 'cover'], null, (int) $id);
                $aiQueued++;
            }
            if (trim((string) ($project['ending_audio'] ?? '')) === ''
                && VoiceService::textForEnding() !== '') {
                AiTaskService::push('NARRATE', $this->uid(), ['scope' => 'ending'], null, (int) $id);
                $aiQueued++;
            }
        }

        // 2.7) 逐章：配图 + 润色 + 朗读，**全部任务化**，定稿接口秒回。
        //      原来润色是在本接口里同步逐章跑（章节一多就要等几分钟），配图则是「进详情页自动跑」
        //      （每次打开都打接口、反复烧额度）。现在统一入队，由后台 worker 干完即退。
        //      已有成果的项一律跳过 —— 重复点定稿不会重复烧额度。
        $polishQueued = 0;
        $narrQueued   = 0;
        foreach ($chapters as $c) {
            $cid = (int) $c['id'];

            if (ChapterIllustrationService::currentImage($cid) === ''
                && !Db::name('ls_ai_task')
                    ->where('chapter_id', $cid)
                    ->where('task_type', 'ILLUSTRATE')
                    ->whereIn('status', ['PENDING', 'RUNNING', 'RETRY'])
                    ->count()) {
                AiTaskService::push('ILLUSTRATE', $this->uid(), ['chapter_id' => $cid], $cid, $id);
                $aiQueued++;
            }

            $hasPolished = (bool) Db::name('ls_chapter_asset')
                ->where('chapter_id', $cid)
                ->where('asset_type', 'POLISHED_TEXT')
                ->count();
            if (!$hasPolished
                && !Db::name('ls_ai_task')
                    ->where('chapter_id', $cid)
                    ->where('task_type', 'POLISH')
                    ->whereIn('status', ['PENDING', 'RUNNING', 'RETRY'])
                    ->count()) {
                AiTaskService::push('POLISH', $this->uid(), ['chapter_id' => $cid], $cid, $id);
                $polishQueued++;
                $aiQueued++;
            }

            if (VoiceService::currentAudio($cid) === ''
                && !Db::name('ls_ai_task')
                    ->where('chapter_id', $cid)
                    ->where('task_type', 'NARRATE')
                    ->whereIn('status', ['PENDING', 'RUNNING', 'RETRY'])
                    ->count()) {
                AiTaskService::push('NARRATE', $this->uid(), [
                    'scope'      => 'chapter',
                    'chapter_id' => $cid,
                    // 本章有（或本次会生成）润色稿 → 朗读润色文案，而不是口述原文
                    'expect_polish' => $hasPolished ? 0 : 1,
                ], $cid, $id);
                $narrQueued++;
                $aiQueued++;
            }
        }

        // 有新增任务，或者项目仍有未终态的（被中断的）任务 → 拉起 worker（也负责 resume 卡住的运行）
        $hasOpen = (int) Db::name('ls_ai_task')
            ->where('project_id', $id)
            ->whereIn('status', ['PENDING', 'RUNNING', 'RETRY'])
            ->count();
        if ($aiQueued > 0 || $hasOpen > 0) {
            AiTaskService::kick();
        }

        // 兜底：本次没有任何任务需要跑（全部已有成果）→ 直接收尾翻 DONE
        AiTaskService::maybeCompleteProject($id);

        return $this->ok([
            'status'        => (Db::name('ls_project')->where('id', $id)->value('status')) ?: 'MAKING',
            'preview_token' => $token,
            'preview_url'   => $url,
            'cover_bg'      => $coverBg,
            'queued'        => [
                'total'   => $aiQueued,
                'polish'  => $polishQueued,
                'narrate' => $narrQueued,
            ],
        ], '已提交定稿：润色、配图、配音正在后台自动进行');
    }

    /** 内置兜底默认章节（仅当 ls_default_chapter 尚未初始化、一条都没有时使用） */
    private const DEFAULT_CHAPTERS = [
        '童年时光',
        '青春年华',
        '成家立业',
        '奋斗岁月',
        '闲适晚年',
        '人生感悟',
    ];

    /**
     * 为项目批量写入默认系统章节（source=SYSTEM，按管理列表顺序排 sort）。
     *
     * 章节来源：后台「回忆录管理 → 默认章节」维护的「启用中」条目（sort 倒序）。
     * 兜底：若该表尚未初始化（0 条），用内置模板，避免新部署出现「零默认章节」；
     *      若表里有数据但全部停用，则尊重后台设置，不生成任何默认章节。
     */
    private function seedDefaultChapters(int $projectId): void
    {
        $titles = Db::name('ls_default_chapter')
            ->where('status', 1)
            ->order('sort desc, id asc')
            ->column('title');

        if (empty($titles) && (int) Db::name('ls_default_chapter')->count() === 0) {
            $titles = self::DEFAULT_CHAPTERS;
        }
        if (empty($titles)) {
            return;
        }

        $rows = [];
        $i = 0;
        // 与「最多 8 章」的上限保持一致：后台若配了超过 8 条启用模板，只取前 8 条
        foreach (array_slice($titles, 0, 8) as $title) {
            $rows[] = [
                'project_id' => $projectId,
                'title'      => (string) $title,
                'sort'       => $i++,
                'source'     => 'SYSTEM',
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
        Db::name('ls_chapter')->insertAll($rows);
    }
}
