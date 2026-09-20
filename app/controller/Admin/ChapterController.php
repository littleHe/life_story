<?php
namespace app\controller\Admin;

use think\facade\Db;

/**
 * 回忆录管理 · 系统章节
 *
 * 说明：系统章节是「全局默认参考章节模板」，不归属任何项目（project_id 为 NULL）。
 * 仅用于在用户端编辑回忆录时，提供可挑选 / 可自定义的预设参考章节；
 * 用户选定或自定义后，由用户端接口写入对应项目的章节（project_id 有值、source=CUSTOM）。
 */
class ChapterController extends AdminBase
{
    /** 章节状态中文映射 */
    protected $statusText = [
        'EMPTY'       => '未录制',
        'RECORDED'    => '已录音',
        'TRANSCRIBED' => '已转写',
        'POLISHED'    => '已润色',
        'DONE'        => '已完成',
    ];

    /**
     * GET /admin-api/chapter/index
     * 管理全部章节：系统预设（project_id 为 NULL）与用户自定义（归属项目、source=CUSTOM）都会入库，
     * 靠 source 字段区分；列表展示「资源数」（ls_chapter_asset 按 chapter_id 计数），默认按资源数降序。
     */
    public function index()
    {
        $keyword = trim((string) $this->request->param('keyword', ''));
        $status  = trim((string) $this->request->param('chapter_status', ''));
        $source  = trim((string) $this->request->param('source', ''));   // ''=全部 SYSTEM/CUSTOM

        // 资源数：每个章节关联的 ls_chapter_asset 行数
        $assetSql = '(SELECT COUNT(*) FROM ls_chapter_asset a WHERE a.chapter_id = c.id)';

        $query = Db::name('ls_chapter')
            ->alias('c')
            ->field('c.id, c.title, c.sort, c.source, c.text_status, c.chapter_status, '
                . 'c.created_at, c.updated_at, ' . $assetSql . ' AS asset_count');

        if ($source !== '') {
            $query->where('c.source', $source);
        }
        if ($keyword !== '') {
            $query->where('c.title', 'like', '%' . $keyword . '%');
        }
        if ($status !== '') {
            $query->where('c.chapter_status', $status);
        }

        // 排序：支持 layui 表头点击（sort/order 参数），默认按资源数降序
        $sortField = trim((string) $this->request->param('sort', ''));
        $sortOrder = strtolower(trim((string) $this->request->param('order', '')));
        $allowed   = ['asset_count' => 'asset_count', 'sort' => 'c.sort', 'id' => 'c.id', 'created_at' => 'c.created_at'];
        $order     = (isset($allowed[$sortField]) && in_array($sortOrder, ['asc', 'desc'], true))
            ? $allowed[$sortField] . ' ' . $sortOrder
            : 'asset_count DESC, c.id DESC';

        [$list, $count] = $this->paginate($query, $order);

        foreach ($list as &$row) {
            $row['chapter_status_text'] = $this->statusText[$row['chapter_status']] ?? $row['chapter_status'];
            $row['source_text']         = $row['source'] === 'SYSTEM' ? '系统预设' : '用户自定义';
            $row['asset_count']         = (int) ($row['asset_count'] ?? 0);
        }

        return $this->tableJson($list, $count);
    }

    /** GET /admin-api/chapter/detail?id= —— 章节 + 资源明细 */
    public function detail()
    {
        $id      = (int) $this->request->param('id/d', 0);
        $chapter = Db::name('ls_chapter')->where('id', $id)->find();
        if (empty($chapter)) {
            return $this->fail('章节不存在');
        }

        $assets = Db::name('ls_chapter_asset')
            ->where('chapter_id', $id)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        foreach ($assets as &$asset) {
            $asset['meta'] = $asset['meta'] ? json_decode($asset['meta'], true) : null;
        }

        $chapter['chapter_status_text'] = $this->statusText[$chapter['chapter_status']] ?? $chapter['chapter_status'];

        return $this->ok(['chapter' => $chapter, 'assets' => $assets]);
    }

    /**
     * POST /admin-api/chapter/save
     * 系统默认章节不归属任何项目（project_id 为 NULL），仅作为给用户挑选/自定义的参考模板。
     * 编辑：仅允许改标题/排序（章节内容由用户端产生）。
     */
    public function save()
    {
        $id    = (int) $this->request->post('id/d', 0);
        $title = (string) $this->post('title');
        if ($title === '') {
            return $this->fail('章节标题不能为空');
        }

        $sort = (int) $this->request->post('sort/d', 0);
        $now  = date('Y-m-d H:i:s');

        if ($id > 0) {
            if (empty(Db::name('ls_chapter')->where('id', $id)->find())) {
                return $this->fail('章节不存在');
            }
            Db::name('ls_chapter')->where('id', $id)->update([
                'title'      => $title,
                'sort'       => $sort,
                'updated_at' => $now,
            ]);
            $this->audit('chapter/save', ['id' => $id]);

            return $this->ok(['id' => $id], '保存成功');
        }

        // 新增系统默认章节：不关联项目，供用户后续在回忆录中选用或自定义
        $newId = Db::name('ls_chapter')->insertGetId([
            'project_id'     => null,
            'title'          => $title,
            'sort'           => $sort,
            'source'         => 'SYSTEM',   // 后台添加 = 系统预设参考章节
            'text_status'    => 'ORIGINAL',
            'chapter_status' => 'EMPTY',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        $this->audit('chapter/save', ['id' => $newId]);

        return $this->ok(['id' => $newId], '新增成功');
    }

    /** POST /admin-api/chapter/delete —— 连同章节资源一起删除（先算子表，避免外键报错） */
    public function delete()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }

        Db::startTrans();
        try {
            Db::name('ls_chapter_asset')->where('chapter_id', $id)->delete();
            Db::name('ls_chapter')->where('id', $id)->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('删除失败：' . $e->getMessage());
        }

        $this->audit('chapter/delete', ['id' => $id]);
        return $this->ok([], '删除成功');
    }
}
