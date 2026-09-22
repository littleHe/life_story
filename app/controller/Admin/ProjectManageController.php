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
        }
        unset($p);

        return $this->tableJson($list, $count);
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
