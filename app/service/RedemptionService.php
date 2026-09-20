<?php
namespace app\service;

use think\facade\Db;
use app\common\lib\LockManager;
use app\common\exception\ApiException;

/**
 * 兑换码服务
 *
 * 流程：后台生成(GENERATED) → 用户激活(ADDED，归属到用户) → 绑定项目(BOUND)
 * 说明：明文不落库，只存 sha256；列表展示用 code_mask。
 *       max_uses=0 表示不限次（测试码），绑定后码状态不变，可反复解锁多个项目。
 */
class RedemptionService
{
    /** 未冻结的可用码条件 */
    private static function available($query)
    {
        return $query->where(function ($q) {
            $q->where('frozen_until', null)
              ->whereOr('frozen_until', '<', date('Y-m-d H:i:s'));
        });
    }

    /**
     * 只读校验兑换码（不产生任何副作用），无效直接抛异常。
     * @return array code 行
     */
    public static function verify(int $userId, string $rawCode): array
    {
        $raw = strtoupper(trim($rawCode));
        if ($raw === '') {
            throw new ApiException(42201, '请填写兑换码', 422);
        }
        $codeHash = hash('sha256', $raw);

        $query = Db::name('ls_redemption_code')
            ->where('code_hash', $codeHash)
            ->where('status', 'in', ['GENERATED', 'ADDED']);
        $code = self::available($query)->find();

        if (!$code) {
            throw new ApiException(40902, '兑换码无效或已被使用', 409);
        }
        if (!empty($code['max_uses']) && $code['used_count'] >= $code['max_uses']) {
            throw new ApiException(40903, '兑换码已达到使用次数上限', 409);
        }
        return $code;
    }

    /**
     * 激活兑换码：校验通过后归属到当前用户（GENERATED -> ADDED），
     * 之后可在「新建回忆录 → 选择已有兑换码」中直接选用。
     */
    public static function activate(int $userId, string $rawCode): array
    {
        $code = self::verify($userId, $rawCode);
        $owner = (int) ($code['bound_user_id'] ?? 0);
        if ($code['status'] === 'ADDED' && $owner && $owner !== $userId) {
            throw new ApiException(40904, '该兑换码已被其他账号激活', 409);
        }
        if ($code['status'] === 'GENERATED' || $owner === 0) {
            Db::name('ls_redemption_code')->where('id', $code['id'])->update([
                'status'        => 'ADDED',
                'bound_user_id' => $userId,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            $code['status'] = 'ADDED';
            $code['bound_user_id'] = $userId;
        }
        return $code;
    }

    /**
     * 当前用户的兑换码列表（已激活未使用 / 已绑定项目）
     * 命中条件：归属我（bound_user_id）或已绑定我的项目。
     */
    public static function mine(int $userId): array
    {
        $projectIds = Db::name('ls_project')->where('user_id', $userId)->column('id');

        $rows = Db::name('ls_redemption_code')
            ->where(function ($q) use ($userId, $projectIds) {
                $q->where('bound_user_id', $userId);
                if ($projectIds) {
                    $q->whereOr('bound_project_id', 'in', $projectIds);
                }
            })
            ->order('id', 'desc')
            ->select()
            ->toArray();
        if (!$rows) {
            return [];
        }

        // 一次性取项目名，避免逐条查询
        $names = [];
        $pids = array_filter(array_column($rows, 'bound_project_id'));
        if ($pids) {
            $names = Db::name('ls_project')->where('id', 'in', $pids)->column('name', 'id');
        }

        $list = [];
        foreach ($rows as $r) {
            $isBound = $r['status'] === 'BOUND';
            $list[] = [
                'id'            => (int) $r['id'],
                'code_mask'     => (string) $r['code_mask'],
                'status'        => $isBound ? 'used' : 'unused',
                'raw_status'    => (string) $r['status'],
                'max_uses'      => (int) $r['max_uses'],
                'used_count'    => (int) $r['used_count'],
                'unlimited'     => ((int) $r['max_uses'] === 0) ? 1 : 0,
                'project_id'    => $r['bound_project_id'] ? (int) $r['bound_project_id'] : null,
                'project_title' => $r['bound_project_id'] ? (string) ($names[$r['bound_project_id']] ?? '') : '',
                'activated_at'  => (string) $r['created_at'],
                'redeemed_at'   => $isBound ? (string) $r['updated_at'] : null,
            ];
        }
        return $list;
    }

    /**
     * 将兑换码绑定到项目（原子操作，防止并发双花 / 重复绑定）
     * @param string $rawCode 用户输入的原始码（明文，仅用于计算 hash）
     * @return array 绑定后的 code 行
     */
    public static function bind(int $userId, int $projectId, string $rawCode): array
    {
        $codeHash = hash('sha256', strtoupper(trim($rawCode))); // 只存哈希
        self::assertBindableProject($userId, $projectId);

        Db::startTrans();
        try {
            // 行锁：仅 GENERATED/ADDED 且未冻结的码可被绑定
            $query = Db::name('ls_redemption_code')
                ->where('code_hash', $codeHash)
                ->where('status', 'in', ['GENERATED', 'ADDED']);
            $code = self::available($query)->lock(true)->find();

            if (!$code) {
                Db::rollback();
                // 失败计数 -> 达阈值锁定账号 + 冻结该码
                $r = LockManager::registerFail('code_verify', $userId, $codeHash);
                if ($r['locked']) {
                    throw new ApiException(
                        42301,
                        '兑换码校验失败次数过多，账号已锁定 ' . $r['remaining'] . ' 秒',
                        423,
                        $r['remaining']
                    );
                }
                throw new ApiException(40902, '兑换码无效或已被使用', 409);
            }

            return self::consume($userId, $projectId, $code);
        } catch (\Throwable $e) {
            Db::rollback();
            if ($e instanceof ApiException) {
                throw $e;
            }
            throw new ApiException(50000, '绑定失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 用兑换码 id 绑定（「选择已有兑换码」路径：前端只有掩码，无明文）。
     * 仅允许绑定「本人已激活」的码。
     */
    public static function bindById(int $userId, int $projectId, int $codeId): array
    {
        self::assertBindableProject($userId, $projectId);

        Db::startTrans();
        try {
            // 允许 GENERATED/ADDED：码「归属到本人」即可绑定。
            // 兼容历史数据：不限次测试码首次绑定只写了 bound_user_id、状态仍停在 GENERATED，
            // 若这里只认 ADDED，「选择已有兑换码」列表里看得见却绑不上（40902）。
            $query = Db::name('ls_redemption_code')
                ->where('id', $codeId)
                ->where('status', 'in', ['GENERATED', 'ADDED'])
                ->where('bound_user_id', $userId);
            $code = self::available($query)->lock(true)->find();

            if (!$code) {
                Db::rollback();
                throw new ApiException(40902, '兑换码无效或已被使用', 409);
            }
            return self::consume($userId, $projectId, $code);
        } catch (\Throwable $e) {
            Db::rollback();
            if ($e instanceof ApiException) {
                throw $e;
            }
            throw new ApiException(50000, '绑定失败：' . $e->getMessage(), 500);
        }
    }

    /** 项目归属与重复绑定校验 */
    private static function assertBindableProject(int $userId, int $projectId): void
    {
        $project = Db::name('ls_project')
            ->where('id', $projectId)
            ->where('user_id', $userId)
            ->find();
        if (!$project) {
            throw new ApiException(40401, '项目不存在', 404);
        }
        if ($project['redemption_code_id']) {
            throw new ApiException(40901, '该项目已绑定兑换码', 409);
        }
    }

    /**
     * 事务内核销（不负责事务开启/回滚，成功后提交）。
     * 调用方必须已处于事务中且已按行锁取出 $code。
     */
    private static function consume(int $userId, int $projectId, array $code): array
    {
        // 次数上限（max_uses=0 表示不限）
        if (!empty($code['max_uses']) && $code['used_count'] >= $code['max_uses']) {
            throw new ApiException(40903, '兑换码已达到使用次数上限', 409);
        }

        $unlimited = ((int) ($code['max_uses'] ?? 0)) === 0;

        if ($unlimited) {
            // 测试码（max_uses=0）：可无限复用，不占用码状态/单一项目绑定，仅解锁项目
            Db::name('ls_project')->where('id', $projectId)->update([
                'status' => 'EDITABLE',
            ]);
            // 未归属的测试码顺手归属到当前用户，便于「我的兑换码」展示；
            // 同时把状态推进到 ADDED —— 否则 mine() 已列出该码、bindById() 却因状态不符拒收。
            if (empty($code['bound_user_id']) || $code['status'] === 'GENERATED') {
                Db::name('ls_redemption_code')->where('id', $code['id'])->update([
                    'status'        => 'ADDED',
                    'bound_user_id' => $userId,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
            }
        } else {
            // 正常码：事务内原子转移 GENERATED/ADDED -> BOUND（一码一单）
            Db::name('ls_redemption_code')->where('id', $code['id'])->update([
                'status'           => 'BOUND',
                'bound_project_id' => $projectId,
                'bound_user_id'    => $userId,
                'updated_at'       => date('Y-m-d H:i:s'),
            ]);
            Db::name('ls_project')->where('id', $projectId)->update([
                'status'             => 'EDITABLE',        // 解锁编辑
                'redemption_code_id' => $code['id'],
            ]);
        }

        Db::name('ls_redemption_code')->where('id', $code['id'])->inc('used_count')->update();

        Db::name('ls_verification_log')->insert([
            'code_id'     => $code['id'],
            'user_id'     => $userId,
            'project_id'  => $projectId,
            'method'      => 'INPUT',
            'device_info' => request()->header('User-Agent', ''),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        Db::commit();
        return $code;
    }

    // ------------------------------------------------------------------
    // 兑换码明文「加密落库 / 解密回取」（支撑事后与定期导出）
    // 仅加密存储，密钥走 env CODE_CIPHER_KEY；未配置时退回开发默认密钥，便于本地联调。
    // ------------------------------------------------------------------

    /** AES-256-CBC 加密明文兑换码，返回 base64(iv|cipher) */
    public static function encryptCode(string $raw): string
    {
        $key = self::cipherKey();
        $iv  = random_bytes(16);
        $cipher = openssl_encrypt($raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('兑换码加密失败');
        }
        return base64_encode($iv . $cipher);
    }

    /** 解密 code_cipher 还原明文；解密失败（如旧数据/密钥不符）返回空串 */
    public static function decryptCode(?string $cipher): string
    {
        if (!$cipher) {
            return '';
        }
        $bin = base64_decode($cipher, true);
        if ($bin === false || strlen($bin) <= 16) {
            return '';
        }
        $iv      = substr($bin, 0, 16);
        $payload = substr($bin, 16);
        $raw = openssl_decrypt($payload, 'AES-256-CBC', self::cipherKey(), OPENSSL_RAW_DATA, $iv);
        return $raw === false ? '' : $raw;
    }

    /** 32 字节密钥：优先 env，否则用开发默认密钥 */
    private static function cipherKey(): string
    {
        $env = (string) env('CODE_CIPHER_KEY', '');
        $seed = $env !== '' ? $env : 'life_story_dev_cipher_key_2026';
        return substr(hash('sha256', $seed, true), 0, 32);
    }
}
