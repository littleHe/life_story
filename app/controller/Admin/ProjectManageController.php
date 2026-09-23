<?php
namespace app\controller\Admin;

use think\facade\Db;
use app\service\PrintExportService;
use app\service\SiteUrlService;

/**
 * 回忆录项目管理（后台）：查看项目、录入书籍/漫剧制作信息、变更状态（如 制作中 → 已完成）
 */
class ProjectManageController extends AdminBase
{
    private const STATUS = ['UNPAID_LOCKED' => '待解锁', 'EDITABLE' => '采集中', 'MAKING' => '回忆制作中', 'DONE' => '已完成'];

    /** GET /admin-api/project/index?keyword=&status= */
    public function index()
    {
        $query = Db::name('ls_project');
        $keyword = (string) $this->post('keyword') ?: (string) $this->request->get('keyword', '');
        $status  = (string) $this->post('status')  ?: (string) $this->request->get('status', '');
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereOr('name', 'like', '%' . $keyword . '%')
                  ->whereOr('real_name', 'like', '%' . $keyword . '%');
            });
        }
        if ($status !== '' && isset(self::STATUS[$status])) {
            $query->where('status', $status);
        }
        [$list, $count] = $this->paginate($query, 'id desc');

        // 一次性补齐章节数，避免 N+1
        $ids = array_column($list, 'id');
        $counts = [];
        if ($ids) {
            $rows = Db::name('ls_chapter')->where('project_id', 'in', $ids)
                ->field('project_id, COUNT(*) AS c')->group('project_id')->select()->toArray();
            foreach ($rows as $r) {
                $counts[(string) $r['project_id']] = (int) $r['c'];
            }
        }

        // 一次性补齐「AI 任务」：按 task_type 汇总每个项目的任务状态，供后台「AI任务」列分几行展示。
        // 背景：项目翻「已完成」的唯一条件是「该项目已无任何 PENDING/RUNNING/RETRY 任务」，
        // 所以把每一类任务推进到哪一步直接摆出来，卡在哪一环一眼可见。
        $aiByProject = [];
        if ($ids) {
            $rows = Db::name('ls_ai_task')
                ->where('project_id', 'in', $ids)
                ->field('project_id, task_type, status, updated_at')
                ->select()
                ->toArray();
            foreach ($rows as $r) {
                $aiByProject[(string) $r['project_id']][(string) $r['task_type']][] = $r;
            }
        }

        foreach ($list as &$p) {
            $p['status_label']  = self::STATUS[$p['status']] ?? $p['status'];
            $p['chapter_count'] = $counts[(string) $p['id']] ?? 0;
            // 预览地址自愈：历史数据里存过 http://127.0.0.1:9411/... （本地定稿写入），
            // 后台点「预览」会跳到打不开的本机地址 → 按当前站点域名重建。
            $p['preview_url'] = SiteUrlService::healPreview(
                $p['preview_url'] ?? '',
                (string) ($p['preview_token'] ?? ''),
                (string) $this->request->domain()
            );

            // AI 任务：分类型展示推进状态（已完成 / 进行中 / 排队中 / 部分完成 / 未开始 / 失败）
            $byType = $aiByProject[(string) $p['id']] ?? [];
            $p['ai_tasks'] = self::buildAiTasks($byType);
            // 仍有任务停在非终态时，给出「最久未更新」的分钟数，用于识别卡住（0 = 没有未完成任务）
            $p['ai_stall'] = self::aiStallMinutes($byType);
        }
        unset($p);

        return $this->tableJson($list, $count);
    }

    /** AI 任务类型 → 后台展示名（数组顺序即列表里的展示顺序） */
    private const AI_TYPE_LABEL = [
        'POLISH'      => 'AI润色',
        'COVER_IMAGE' => 'AI背景',
        'ILLUSTRATE'  => 'AI配图',
        'NARRATE'     => 'AI配音',
        'VOICE_CLONE' => 'AI复刻',
    ];

    /**
     * 汇总单个项目的 AI 任务，按类型返回「展示名 + 状态文案 + 状态色」，供后台分几行展示。
     * - 同一类型有多条任务时（如 6 个章节各一条配音），按整体推进情况折叠成一句话；
     * - 项目若一条 AI 任务都没有（还没定稿），返回空数组 → 前端显示 “-”。
     */
    private static function buildAiTasks(array $byType): array
    {
        $hasAny = !empty($byType);
        $out = [];
        foreach (self::AI_TYPE_LABEL as $key => $label) {
            $rows = $byType[$key] ?? [];
            if (!$rows) {
                if ($hasAny) {
                    $out[] = ['key' => $key, 'label' => $label, 'status' => '未开始', 'color' => '#9e9e9e'];
                }
                continue;
            }
            $n = ['run' => 0, 'wait' => 0, 'ok' => 0, 'fail' => 0];
            foreach ($rows as $r) {
                switch ((string) ($r['status'] ?? '')) {
                    case 'RUNNING':
                        $n['run']++; break;
                    case 'PENDING':
                    case 'RETRY':
                        $n['wait']++; break;
                    case 'SUCCESS':
                    case 'SKIPPED':
                    case 'DONE':
                        $n['ok']++; break;
                    case 'FAILED':
                        $n['fail']++; break;
                }
            }
            if ($n['run'] > 0) {
                $out[] = ['key' => $key, 'label' => $label, 'status' => '进行中', 'color' => '#1890ff'];
            } elseif ($n['wait'] > 0) {
                $out[] = ['key' => $key, 'label' => $label, 'status' => $n['ok'] > 0 ? '部分完成' : '排队中', 'color' => '#fa8c16'];
            } elseif ($n['fail'] > 0 && $n['ok'] > 0) {
                $out[] = ['key' => $key, 'label' => $label, 'status' => '部分失败', 'color' => '#f5222d'];
            } elseif ($n['fail'] > 0) {
                $out[] = ['key' => $key, 'label' => $label, 'status' => '失败', 'color' => '#f5222d'];
            } else {
                $out[] = ['key' => $key, 'label' => $label, 'status' => '已完成', 'color' => '#52c41a'];
            }
        }
        return $out;
    }

    /** 仍有非终态（PENDING/RUNNING/RETRY）任务时，返回最早那条的静默分钟数；没有则 0 */
    private static function aiStallMinutes(array $byType): int
    {
        $oldest = 0;
        foreach ($byType as $rows) {
            foreach ($rows as $r) {
                if (!in_array((string) ($r['status'] ?? ''), ['PENDING', 'RUNNING', 'RETRY'], true)) {
                    continue;
                }
                $ts = strtotime((string) ($r['updated_at'] ?? ''));
                if ($ts > 0 && ($oldest === 0 || $ts < $oldest)) {
                    $oldest = $ts;
                }
            }
        }
        return $oldest > 0 ? (int) floor((time() - $oldest) / 60) : 0;
    }

    /** GET /admin-api/project/detail?id= */
    public function detail()
    {
        $id = (int) $this->request->param('id/d', 0);
        $p = Db::name('ls_project')->where('id', $id)->find();
        if (!$p) {
            return $this->fail('项目不存在');
        }
        $p['status_label'] = self::STATUS[$p['status']] ?? $p['status'];
        // 同 index()：编辑表单里也展示自愈后的预览地址，避免把本机地址又存回去
        $p['preview_url'] = SiteUrlService::healPreview(
            $p['preview_url'] ?? '',
            (string) ($p['preview_token'] ?? ''),
            (string) $this->request->domain()
        );
        return $this->ok($p);
    }

    /** POST /admin-api/project/save  可编辑：书名/传主/简介/籍贯/状态/制作信息/封面背景/预览地址 */
    public function save()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }
        $project = Db::name('ls_project')->where('id', $id)->find();
        if (!$project) {
            return $this->fail('项目不存在');
        }

        $data = [];
        foreach (['name', 'real_name', 'description', 'native_place', 'production_info', 'cover_bg', 'preview_url'] as $field) {
            $v = $this->post($field);
            if ($v !== '') {
                $data[$field] = $v;
            } elseif ($field === 'production_info' || $field === 'description' || $field === 'native_place') {
                // 这几个字段允许清空（显式传空串）
                $raw = (string) $this->request->post($field, null);
                if ($raw === '') {
                    $data[$field] = '';
                }
            }
        }

        $status = (string) $this->post('status');
        if ($status !== '') {
            if (!isset(self::STATUS[$status])) {
                return $this->fail('非法状态');
            }
            $data['status'] = $status;
            // 置为已完成时补齐定稿时间（未定稿过的场景）
            if ($status === 'DONE' && empty($project['finalized_at'])) {
                $data['finalized_at'] = date('Y-m-d H:i:s');
            }
        }

        if (!$data) {
            return $this->fail('没有需要保存的修改');
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        Db::name('ls_project')->where('id', $id)->update($data);
        $this->audit('project/save', ['id' => $id, 'fields' => array_keys($data)]);

        return $this->ok(['id' => $id], '保存成功');
    }

    /**
     * GET /admin-api/project/export?id=
     * 汇出该回忆录的「印刷包」：背景图素材 + 人物基础信息 + 章节内容，打包为 zip 下载。
     */
    public function export()
    {
        $id = (int) $this->request->param('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }
        try {
            $url = PrintExportService::export($id);
        } catch (\Throwable $e) {
            return $this->fail('汇出失败：' . $e->getMessage());
        }
        $this->audit('project/export', ['id' => $id]);
        return $this->ok(['url' => $url], '汇出成功');
    }
}
