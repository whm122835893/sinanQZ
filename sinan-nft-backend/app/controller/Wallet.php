<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;
use app\service\ActivityRewardService;

use think\facade\Db;

/**
 * 钱包控制器
 */
class Wallet extends BaseController
{
    /**
     * GET /api/wallet
     * 钱包信息
     */
    public function info()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $wallet = Db::name('wallets')->where('user_id', $userId)->find();
        return $this->success([
            'balance'   => (float) ($wallet['balance'] ?? 0),
            'available' => (float) ($wallet['available'] ?? 0),
            'frozen'    => (float) ($wallet['frozen'] ?? 0),
            'points'    => (float) ($wallet['points'] ?? 0),
            'brand'     => $wallet['brand'] ?? '汇付',
        ]);
    }

    /**
     * GET /api/wallet/transactions
     * 钱包流水
     */
    public function transactions()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $p    = $this->pagination();
        $type = $this->strParam('type');

        $query = Db::name('wallet_transactions')->where('user_id', $userId)->order('created_at', 'desc');
        if ($type) $query->where('trans_type', $type);

        $total = $query->count();
        $list  = $query->limit($p['offset'], $p['pageSize'])->select()->toArray();

        return $this->paginate(array_map(fn ($t) => [
            'id'          => (int) $t['id'],
            'transType'   => $t['trans_type'],
            'direction'   => (int) $t['direction'] === 1 ? 'in' : 'out',
            'title'       => $t['title'],
            'amount'      => (float) $t['amount'],
            'balanceAfter'=> (float) $t['balance_after'],
            'createdAt'   => $t['created_at'],
        ], $list), $total, $p['page'], $p['pageSize']);
    }

    /**
     * POST /api/wallet/recharge
     * 模拟充值（仅开发联调 APP_DEBUG=true；生产禁止，真实充值必须走支付渠道回调入账）
     */
    public function recharge()
    {
        if (!(bool) env('APP_DEBUG', false)) {
            return $this->fail(4003, '充值请通过第三方支付渠道');
        }

        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $amount = round((float) $this->request->post('amount', 0), 2);
        if ($amount < 1) return $this->fail(1001, '充值金额必须大于0');
        // 联调环境单笔上限，防误操作产生脏巨额余额
        if ($amount > 10000) return $this->fail(1001, '模拟充值单笔不得超过 10000 元');

        $now = date('Y-m-d H:i:s.v');

        Db::startTrans();
        try {
            $wallet = Db::name('wallets')->where('user_id', $userId)->lock(true)->find();
            Db::name('wallets')->where('user_id', $userId)->update([
                'balance'     => Db::raw('balance + ' . $amount),
                'available'   => Db::raw('available + ' . $amount),
                'updated_at'  => $now,
            ]);
            Db::name('wallet_transactions')->insert([
                'user_id'       => $userId,
                'trans_type'    => 'recharge',
                'title'         => '模拟充值(联调)',
                'direction'     => 1,
                'amount'        => $amount,
                'balance_after' => (float) $wallet['balance'] + $amount,
                'created_at'    => $now,
            ]);
            $transactionId = (int) Db::name('wallet_transactions')->getLastInsID();
            Db::commit();

            // 充值完成 → 被邀请人完成条件（wallet 开通第三方钱包/完成充值）邀请活动结算（失败不影响充值）
            ActivityRewardService::settleQuietly(
                fn () => ActivityRewardService::settleInviteReward($userId)
            );

            return $this->success(['transactionId' => $transactionId]);
        } catch (\Throwable $e) {
            Db::rollback();
            \think\facade\Log::error('[wallet][recharge] uid=' . $userId . ' err=' . $e->getMessage());
            return $this->fail(5001, '充值失败，请稍后重试');
        }
    }
}
