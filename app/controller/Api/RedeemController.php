<?php
namespace app\controller\Api;

use app\BaseController;
use app\common\exception\ApiException;
use app\service\RedemptionService;

/**
 * 兑换码（用户侧）
 *  - POST /api/redeem/activate  激活兑换码（归属到当前用户，供创建时选用）
 *  - GET  /api/redeem/codes     我的兑换码（已激活未使用 / 已绑定项目）
 */
class RedeemController extends BaseController
{
    /** 激活兑换码 */
    public function activate()
    {
        $raw = strtoupper(trim(input('post.code/s', '')));
        if ($raw === '') {
            throw new ApiException(42201, '请输入兑换码', 422);
        }
        if (mb_strlen($raw) < 6) {
            throw new ApiException(42202, '兑换码至少 6 位', 422);
        }

        $code = RedemptionService::activate($this->uid(), $raw);

        return $this->ok([
            'id'         => (int) $code['id'],
            'code'       => $raw,
            'code_mask'  => (string) $code['code_mask'],
            'unlimited'  => ((int) $code['max_uses'] === 0) ? 1 : 0,
            'used_count' => (int) $code['used_count'],
            'max_uses'   => (int) $code['max_uses'],
        ], '兑换码已激活，可用于解锁回忆录');
    }

    /** 我的兑换码列表 */
    public function codes()
    {
        return $this->ok(RedemptionService::mine($this->uid()));
    }
}
