<?php
namespace app\controller\Admin;

use think\facade\Db;
use app\service\RedemptionService;

/**
 * 兑换码管理（后台 session 版）
 *
 * 说明：本控制器服务于 /admin-api/code/*，
 * 与 app\controller\Admin\CodeController（旧的 /api/admin/codes JWT 开放接口）互不影响。
 */
class CodeManageController extends AdminBase
{
    protected $statusText = [
        'GENERATED' => '已生成',
        'ADDED'     => '已添加',
        'BOUND'     => '已绑定',
        'VOID'      => '已作废',
    ];

    /** GET /admin-api/code/index */
    public function index()
    {
        $keyword = trim((string) $this->request->param('keyword', ''));
        $status  = trim((string) $this->request->param('status', ''));

        $query = Db::name('ls_redemption_code')
            ->alias('c')
            ->leftJoin('ls_project p', 'p.id = c.bound_project_id')
            ->leftJoin('ls_user u', 'u.id = c.bound_user_id')
            ->field('c.id,c.code_mask,c.status,c.bound_project_id,c.bound_user_id,c.max_uses,c.used_count,'
                . 'c.frozen_until,c.generated_by,c.batch_no,c.exported_at,c.created_at,c.updated_at,'
                . 'p.name AS project_name,u.nickname AS user_nickname,'
                // 只回传「是否已存档明文」的布尔标记，不把密文发到浏览器前端
                . 'IF(c.code_cipher IS NULL OR c.code_cipher = "", 0, 1) AS has_cipher');

        if ($keyword !== '') {
            $query->where('c.code_mask', 'like', '%' . $keyword . '%');
        }
        if ($status !== '') {
            $query->where('c.status', $status);
        }

        [$list, $count] = $this->paginate($query, 'c.id desc');

        foreach ($list as &$row) {
            $row['status_text'] = $this->statusText[$row['status']] ?? $row['status'];
            $row['is_unlimited'] = ((int) $row['max_uses'] === 0) ? 1 : 0;
            $row['usage_text'] = (int) $row['max_uses'] === 0
                ? '不限次(已用' . (int) $row['used_count'] . ')'
                : (int) $row['used_count'] . '/' . (int) $row['max_uses'];
            $row['exported_text'] = empty($row['exported_at']) ? '未导出' : ('已导出 ' . $row['exported_at']);
            // 「明文存档」= 能否导出。加密功能上线前的历史行只有 sha256，无法回取，
            // 需要通过「回填」（校验哈希）或「重发新码」补齐后才能导出。
            $row['has_cipher']  = (int) $row['has_cipher'];
            $row['cipher_text'] = $row['has_cipher'] ? '已存档' : '无（不可导出）';
        }

        return $this->tableJson($list, $count);
    }

    /**
     * POST /admin-api/code/generate
     * 批量生成兑换码（明文 AES 加密落库 code_cipher，可事后/定期导出；
     * 同时仍在本次响应返回明文，方便生成时即时复制）。
     */
    public function generate()
    {
        $count   = (int) $this->request->post('count/d', 1);
        $maxUses = (int) $this->request->post('max_uses/d', 1);
        $count   = max(1, min($count, 200));

        $codes = [];
        $now   = date('Y-m-d H:i:s');
        // 批次号：日期 + 随机后缀，保证同秒内多次生成也互不冲突（用于分组导出）
        $batch = 'B' . date('YmdHis') . strtoupper(substr(md5(uniqid('', true)), 0, 4));

        for ($i = 0; $i < $count; $i++) {
            $raw    = $this->genCode();
            $hash   = hash('sha256', $raw);
            $cipher = RedemptionService::encryptCode($raw);

            Db::name('ls_redemption_code')->insert([
                'code_hash'    => $hash,
                'code_mask'    => '******' . substr($raw, -4),
                'code_cipher'  => $cipher,
                'batch_no'     => $batch,
                'status'       => 'GENERATED',
                'max_uses'     => max(0, $maxUses),
                'used_count'   => 0,
                'generated_by' => 0,
                'created_at'   => $now,
            ]);

            $codes[] = $raw;
        }

        $this->audit('code/generate', ['count' => $count, 'max_uses' => $maxUses, 'batch' => $batch]);

        return $this->ok([
            'codes'   => $codes,
            'count'   => count($codes),
            'batch_no'=> $batch,
        ], '生成成功，明文已加密存档，可随时在「导出兑换码」中汇出');
    }

    /**
     * GET /admin-api/code/export
     * 汇出兑换码明文（CSV 下载）。支持：
     *  - scope=all        导出全部可回取码（code_cipher 非空）
     *  - scope=unexported 仅导出从未导出过的（增量，适合定期汇出）
     *  - batch_no=xxx     按批次过滤
     *  - date_from/date_to 按创建日期过滤
     *  - mark=1           导出后标记 exported_at（增量汇出不会重复导出）
     * 旧数据（code_cipher 为空 / 解密失败）无法回取，自动跳过；
     * 跳过条数经响应头 X-Export-Skipped 回传（另有 X-Export-Count / X-Export-Matched），
     * 前端据此提示，避免「静默下载到只有表头的空文件」。
     */
    public function export()
    {
        $scope    = (string) $this->request->param('scope', 'all');
        $batchNo  = trim((string) $this->request->param('batch_no', ''));
        $dateFrom = trim((string) $this->request->param('date_from', ''));
        $dateTo   = trim((string) $this->request->param('date_to', ''));
        $mark     = (int) $this->request->param('mark/d', 1);

        $query = Db::name('ls_redemption_code');
        if ($scope === 'unexported') {
            $query->whereNull('exported_at');
        }
        if ($batchNo !== '') {
            $query->where('batch_no', $batchNo);
        }
        if ($dateFrom !== '') {
            $query->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo !== '') {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        // 命中筛选条件的全部记录数（含无法回取的历史数据，用于如实反馈「跳过了多少条」）
        $matched = (int) (clone $query)->count();

        $rows = (clone $query)
            ->whereNotNull('code_cipher')
            ->where('code_cipher', '<>', '')
            ->order('id', 'asc')
            ->select()->toArray();

        $lines = [];
        $ids   = [];
        foreach ($rows as $r) {
            $plain = RedemptionService::decryptCode($r['code_cipher'] ?? null);
            if ($plain === '') {
                continue; // 解密失败（密钥不符/数据异常）跳过
            }
            $lines[] = [
                $plain,
                $this->statusText[$r['status']] ?? $r['status'],
                (string) ($r['batch_no'] ?? ''),
                (string) $r['created_at'],
            ];
            $ids[] = (int) $r['id'];
        }

        // 标记已导出（增量汇出的关键：下次 unexported 不会重复）
        if ($mark && $ids) {
            Db::name('ls_redemption_code')->where('id', 'in', $ids)
                ->update(['exported_at' => date('Y-m-d H:i:s')]);
        }

        $csv  = "兑换码,状态,批次,创建时间\n";
        foreach ($lines as $l) {
            $csv .= implode(',', array_map([$this, 'csvCell'], $l)) . "\n";
        }

        $filename = 'redemption_codes_' . date('YmdHis') . '.csv';
        $content  = "\xEF\xBB\xBF" . $csv; // BOM 防 Excel 乱码

        $this->audit('code/export', ['scope' => $scope, 'count' => count($lines), 'marked' => $mark]);

        // 导出条数/跳过条数通过响应头回传：前端据此提示，避免「下载到只有表头的空文件」而无感知
        return response($content)->header([
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Export-Count'      => (string) count($lines),
            'X-Export-Matched'    => (string) $matched,
            'X-Export-Skipped'    => (string) max(0, $matched - count($lines)),
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * POST /admin-api/code/backfill —— 为历史数据回填明文（哈希校验通过才写入）
     *
     * 加密功能上线前的码只存了 sha256，明文无法反推；若管理员仍保留原始明文，
     * 可在此回填：系统先比对 sha256 是否与库中一致，一致才加密存档，之后即可导出。
     * 校验不通过一律拒绝，因此不会污染既有数据。
     */
    public function backfill()
    {
        $id  = (int) $this->request->post('id/d', 0);
        $raw = trim((string) $this->request->post('code', ''));
        if ($id <= 0 || $raw === '') {
            return $this->fail('请填写兑换码明文');
        }

        $code = Db::name('ls_redemption_code')->where('id', $id)->find();
        if (empty($code)) {
            return $this->fail('兑换码不存在');
        }

        // 系统校验用的是 strtoupper(trim())，这里按同样规则比对，同时兼容原始大小写写法
        $hit = null;
        foreach (array_unique([$raw, strtoupper($raw), strtolower($raw)]) as $cand) {
            if ($cand !== '' && hash('sha256', $cand) === (string) $code['code_hash']) {
                $hit = $cand;
                break;
            }
        }
        if ($hit === null) {
            return $this->fail('明文校验不通过：与库中哈希不一致，请核对后重试');
        }

        $mask = '******' . substr($hit, -4);
        Db::name('ls_redemption_code')->where('id', $id)->update([
            'code_cipher' => RedemptionService::encryptCode($hit),
            'code_mask'   => $mask,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $this->audit('code/backfill', ['id' => $id]);

        return $this->ok(['code_mask' => $mask], '明文校验通过，已加密存档，现在可以导出了');
    }

    /**
     * POST /admin-api/code/reissue —— 重置明文（重新签发）
     *
     * 适用于明文已彻底丢失、或客户丢失兑换码需要补发：生成新的明文并更新哈希/掩码/密文。
     * 旧明文立即失效；码的状态、绑定关系、使用次数保持不变。明文只在本次响应返回一次。
     */
    public function reissue()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }

        $code = Db::name('ls_redemption_code')->where('id', $id)->find();
        if (empty($code)) {
            return $this->fail('兑换码不存在');
        }

        $raw  = $this->genCode();
        $mask = '******' . substr($raw, -4);
        Db::name('ls_redemption_code')->where('id', $id)->update([
            'code_hash'   => hash('sha256', $raw),
            'code_mask'   => $mask,
            'code_cipher' => RedemptionService::encryptCode($raw),
            'exported_at' => null,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
        $this->audit('code/reissue', ['id' => $id]);

        return $this->ok(['code' => $raw, 'code_mask' => $mask], '已重新签发新明文，旧码立即失效');
    }

    /** CSV 单元格转义（含逗号/引号/换行则用引号包裹并转义双引号） */
    private function csvCell($v): string
    {
        $s = (string) $v;
        if (strpbrk($s, ",\\\n\r\"") !== false) {
            return '"' . str_replace('"', '""', $s) . '"';
        }
        return $s;
    }

    /** POST /admin-api/code/save —— 调整状态 / 次数上限 */
    public function save()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }

        $code = Db::name('ls_redemption_code')->where('id', $id)->find();
        if (empty($code)) {
            return $this->fail('兑换码不存在');
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];

        $status = (string) $this->post('status');
        if ($status !== '') {
            if (!array_key_exists($status, $this->statusText)) {
                return $this->fail('状态值不合法');
            }
            if ($code['status'] === 'BOUND' && $status === 'GENERATED') {
                return $this->fail('已绑定的兑换码不能退回未使用状态');
            }
            $data['status'] = $status;
        }

        if ($this->request->has('max_uses')) {
            $data['max_uses'] = max(0, (int) $this->request->post('max_uses/d', 0));
        }

        Db::name('ls_redemption_code')->where('id', $id)->update($data);
        $this->audit('code/save', ['id' => $id, 'data' => $data]);

        return $this->ok([], '保存成功');
    }

    /** POST /admin-api/code/void —— 作废 */
    public function void()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }
        Db::name('ls_redemption_code')->where('id', $id)->update([
            'status'     => 'VOID',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit('code/void', ['id' => $id]);
        return $this->ok([], '已作废');
    }

    /** POST /admin-api/code/delete —— 有核销记录的码不允许删除（外键保护） */
    public function delete()
    {
        $id = (int) $this->request->post('id/d', 0);
        if ($id <= 0) {
            return $this->fail('参数错误');
        }

        $logCount = Db::name('ls_verification_log')->where('code_id', $id)->count();
        if ($logCount > 0) {
            return $this->fail('该兑换码已有 ' . $logCount . ' 条核销记录，不能删除，请改为作废');
        }

        $boundCount = Db::name('ls_project')->where('redemption_code_id', $id)->count();
        if ($boundCount > 0) {
            return $this->fail('该兑换码已绑定项目，不能删除，请改为作废');
        }

        Db::name('ls_redemption_code')->where('id', $id)->delete();
        $this->audit('code/delete', ['id' => $id]);

        return $this->ok([], '删除成功');
    }

    /** 生成 12 位大写随机码 */
    protected function genCode(): string
    {
        return strtoupper(substr(md5(uniqid('', true) . random_bytes(8)), 0, 12));
    }
}
