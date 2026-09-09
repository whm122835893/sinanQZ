<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 管理后台钱包财务控制器
 *
 * 覆盖：钱包流水、充值记录、手续费统计、资金守恒校验、异常资金监控。
 *
 * 资金守恒恒等式：
 *   用户余额总和 + 平台手续费 + 已提现 = 总充值 + 总奖励收入
 *   （市场成交中：买家支付 price、卖家到账 actual、差额 fee 沉淀为平台收入，
 *    因此守恒式必须计入手续费项）
 */
class WalletController extends BaseController
{
    /**
     * GET /admin/wallet/stats
     * 钱包统计卡片：今日充值/消费/奖励 + 本月充值（列表页顶部指标）
     */
    public function stats()
    {
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd   = date('Y-m-d 23:59:59');
        $monthStart = date('Y-m-01 00:00:00');

        $sumBy = function (string $type, string $from, string $to) {
            return (float) (Db::name('wallet_transactions')
                ->where('trans_type', $type)
                ->whereBetweenTime('created_at', $from, $to)
                ->sum('amount'));
        };

        return $this->success([
            'todayRecharge' => $sumBy('recharge', $todayStart, $todayEnd),
            'todayConsume'  => $sumBy('buy', $todayStart, $todayEnd),
            'todayReward'   => $sumBy('reward', $todayStart, $todayEnd),
            'todayWithdraw' => $sumBy('withdraw', $todayStart, $todayEnd),
            'monthRecharge' => $sumBy('recharge', $monthStart, $todayEnd),
            'totalBalance'  => (float) (Db::name('wallets')->sum('balance')),
            'totalFrozen'   => (float) (Db::name('wallets')->sum('frozen')),
        ]);
    }

    /**
     * GET /admin/wallet/transactions
     * 钱包流水：trans_type/title/bizNo/userId 筛选
     */
    public function transactions()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('wallet_transactions')->alias('t');
        $transType = (string) $this->request->param('transType', '');
        if ($transType !== '' && in_array($transType, ['recharge', 'buy', 'withdraw', 'reward'], true)) {
            $query->where('t.trans_type', $transType);
        }
        $userId = $this->positiveInt('userId');
        if ($userId !== null) {
            $query->where('t.user_id', $userId);
        }
        $userKeyword = trim((string) $this->request->param('userKeyword', ''));
        if ($userKeyword !== '') {
            $query->whereExists(function ($q) use ($userKeyword) {
                $q->name('users')->whereRaw('nft_users.id = t.user_id')
                  ->where(function ($q2) use ($userKeyword) {
                      $q2->whereLike('nft_users.phone', "%{$userKeyword}%")
                         ->whereOr('nft_users.uid', $userKeyword);
                  });
            });
        }
        $bizNo = trim((string) $this->request->param('bizNo', ''));
        if ($bizNo !== '') {
            $query->whereLike('t.biz_no', '%' . $bizNo . '%');
        }
        // 关键词搜索：流水标题 / 用户（用户名/UID/手机号）（AdminTablePage 统一发送 keyword）
        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('t.title', '%' . $keyword . '%')
                    ->whereOr('u.username', 'like', '%' . $keyword . '%')
                    ->whereOr('u.uid', $keyword)
                    ->whereOr('u.phone', 'like', '%' . $keyword . '%');
            });
        }
        $range = $this->dateRange();
        if ($range) {
            if ($range[0] !== '') $query->where('t.created_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('t.created_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $rows = $query->field('t.*, u.uid, u.username, u.phone AS user_phone')
            ->join('users u', 'u.id = t.user_id', 'LEFT')
            ->order('t.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        foreach ($rows as &$row) {
            $row['user_phone'] = $row['user_phone'] ? mask_phone((string) $row['user_phone']) : '';
        }

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * GET /admin/wallet/recharge
     * 充值记录（支付成功流水聚合）
     */
    public function recharge()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('wallet_transactions')->alias('t')
            ->where('t.trans_type', 'recharge');
        $userKeyword = trim((string) $this->request->param('userKeyword', ''));
        if ($userKeyword !== '') {
            $query->whereExists(function ($q) use ($userKeyword) {
                $q->name('users')->whereRaw('nft_users.id = t.user_id')
                  ->where(function ($q2) use ($userKeyword) {
                      $q2->whereLike('nft_users.phone', "%{$userKeyword}%")
                         ->whereOr('nft_users.uid', $userKeyword);
                  });
            });
        }
        $range = $this->dateRange();
        if ($range) {
            if ($range[0] !== '') $query->where('t.created_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('t.created_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $totalAmount = (float) (clone $query)->sum('t.amount');
        $rows = $query->field('t.*, u.uid, u.username')
            ->join('users u', 'u.id = t.user_id', 'LEFT')
            ->order('t.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        $result = [
            'list'      => camelize_keys($rows),
            'total'     => $total,
            'totalAmount' => round($totalAmount, 2),
            'page'      => $page,
            'pageSize'  => $pageSize,
        ];
        return $this->success($result);
    }

    /**
     * GET /admin/wallet/fee
     * 手续费统计：总额/按日趋势/按藏品分布
     */
    public function fee()
    {
        $totalFee = (float) Db::name('resale_listings')->where('status', 'sold')->sum('fee_amount');
        $totalDeal = Db::name('resale_listings')->where('status', 'sold')->count();

        // 近 30 日趋势
        $trend = Db::name('resale_listings')
            ->field("DATE(updated_at) AS d, COUNT(*) AS cnt, SUM(fee_amount) AS fee")
            ->where('status', 'sold')
            ->where('updated_at', '>=', date('Y-m-d 00:00:00', strtotime('-29 days')))
            ->group('d')->select()->toArray();

        // 按藏品分布 TOP10
        $top = Db::name('resale_listings')->alias('rl')
            ->field('c.name, COUNT(*) AS deal_count, SUM(rl.fee_amount) AS fee_total')
            ->join('collectibles c', 'c.id = rl.collectible_id', 'LEFT')
            ->where('rl.status', 'sold')
            ->group('rl.collectible_id')
            ->order('fee_total', 'desc')
            ->limit(10)
            ->select()->toArray();

        return $this->success([
            'totalFee'   => round($totalFee, 2),
            'totalDeal'  => $totalDeal,
            'trend'      => array_map(fn ($r) => ['date' => $r['d'], 'count' => (int) $r['cnt'], 'fee' => round((float) $r['fee'], 2)], $trend),
            'top'        => array_map(fn ($r) => ['name' => $r['name'], 'dealCount' => (int) $r['deal_count'], 'feeTotal' => round((float) $r['fee_total'], 2)], $top),
        ]);
    }

    /**
     * GET /admin/wallet/audit
     * 资金守恒校验（一键全量核对，输出恒等式与差异明细）
     * （命名避免与 BaseController::audit() 审计日志方法签名冲突）
     */
    public function auditList()
    {
        // 1. 恒等式各分量
        $balanceTotal = (float) Db::name('wallets')->sum('balance');
        $feeTotal     = (float) Db::name('resale_listings')->where('status', 'sold')->sum('fee_amount');
        $rechargeTotal = (float) Db::name('wallet_transactions')->where('trans_type', 'recharge')->where('direction', 1)->sum('amount');
        $rewardTotal  = (float) Db::name('wallet_transactions')->where('trans_type', 'reward')->where('direction', 1)->sum('amount');
        $withdrawTotal = (float) Db::name('wallet_transactions')->where('trans_type', 'withdraw')->where('direction', 2)->sum('amount');
        $buyTotal     = (float) Db::name('wallet_transactions')->where('trans_type', 'buy')->where('direction', 2)->sum('amount');

        $left  = round($balanceTotal + $feeTotal + $withdrawTotal, 2);
        $right = round($rechargeTotal + $rewardTotal, 2);
        $diff  = round($left - $right, 2);
        $conserved = abs($diff) < 0.01;

        // 2. 逐用户核对：wallet.balance vs 流水累计（最后一笔 balance_after 一致性）
        $mismatches = [];
        $wallets = Db::name('wallets')->field('user_id, balance, available, frozen')->select()->toArray();
        $checkedUsers = count($wallets);
        foreach ($wallets as $wallet) {
            $signed = (float) Db::name('wallet_transactions')
                ->where('user_id', $wallet['user_id'])
                ->sum(Db::raw('CASE WHEN direction = 1 THEN amount ELSE -amount END'));
            if (abs($signed - (float) $wallet['balance']) > 0.01) {
                $mismatches[] = [
                    'userId'    => (int) $wallet['user_id'],
                    'uid'       => Db::name('users')->where('id', $wallet['user_id'])->value('uid'),
                    'walletBalance' => (float) $wallet['balance'],
                    'flowSum'   => round($signed, 2),
                    'gap'       => round((float) $wallet['balance'] - $signed, 2),
                ];
            }
            // 负余额检测
            if ((float) $wallet['balance'] < 0 || (float) $wallet['available'] < 0 || (float) $wallet['frozen'] < 0) {
                $mismatches[] = [
                    'userId'    => (int) $wallet['user_id'],
                    'uid'       => Db::name('users')->where('id', $wallet['user_id'])->value('uid'),
                    'walletBalance' => (float) $wallet['balance'],
                    'available' => (float) $wallet['available'],
                    'frozen'    => (float) $wallet['frozen'],
                    'issue'     => '存在负余额',
                ];
            }
        }

        // 3. 钱包缺失检测：有用户但无钱包记录
        $missingWallets = (int) Db::name('users')->alias('u')
            ->whereNull('u.deleted_at')
            ->whereNotExists(function ($q) {
                $q->name('wallets')->whereRaw('nft_wallets.user_id = u.id');
            })->count();

        $result = [
            'formula' => '用户余额总和 + 平台手续费 + 已提现 = 总充值 + 总奖励收入',
            'balanceTotal'   => round($balanceTotal, 2),
            'feeTotal'       => round($feeTotal, 2),
            'withdrawTotal'  => round($withdrawTotal, 2),
            'rechargeTotal'  => round($rechargeTotal, 2),
            'rewardTotal'    => round($rewardTotal, 2),
            'buyTotal'       => round($buyTotal, 2),
            'left'           => $left,
            'right'          => $right,
            'diff'           => $diff,
            'conserved'      => $conserved,
            'checkedUsers'   => $checkedUsers,
            'mismatchCount'  => count($mismatches),
            'mismatches'     => array_slice($mismatches, 0, 100),
            'missingWallets' => $missingWallets,
        ];

        $this->audit('wallet', 'audit', '执行资金守恒校验（' . ($conserved ? '通过' : '差异 ' . $diff) . '）');

        return $this->success($result, $conserved ? '资金守恒校验通过' : '资金守恒校验发现差异：' . $diff . ' 元，请核查流水');
    }

    /**
     * GET /admin/wallet/abnormal
     * 异常资金监控：大额流水（阈值可配）、高频交易、负余额
     */
    public function abnormal()
    {
        $threshold = (float) (Db::name('system_configs')->where('config_key', 'large_recharge_alert')->value('config_value') ?: 10000);

        // 大额流水
        $largeTrans = Db::name('wallet_transactions')->alias('t')
            ->field('t.*, u.uid, u.username')
            ->join('users u', 'u.id = t.user_id', 'LEFT')
            ->where('t.amount', '>=', $threshold)
            ->order('t.id', 'desc')
            ->limit(50)
            ->select()->toArray();

        // 高频交易（24 小时内 > 50 笔的用户）
        $frequent = Db::name('wallet_transactions')
            ->field('user_id, COUNT(*) AS cnt, SUM(amount) AS total_amount')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
            ->group('user_id')
            ->having('cnt >= 50')
            ->order('cnt', 'desc')
            ->limit(20)
            ->select()->toArray();
        foreach ($frequent as &$f) {
            $f['uid'] = Db::name('users')->where('id', $f['user_id'])->value('uid');
            $f['total_amount'] = round((float) $f['total_amount'], 2);
        }

        // 负余额
        $negative = Db::name('wallets')->alias('w')
            ->field('w.user_id, w.balance, w.available, w.frozen, u.uid')
            ->join('users u', 'u.id = w.user_id', 'LEFT')
            ->whereRaw('w.balance < 0 OR w.available < 0 OR w.frozen < 0')
            ->limit(50)
            ->select()->toArray();

        // 冻结金额异常（frozen 大户）
        $bigFrozen = Db::name('wallets')->alias('w')
            ->field('w.user_id, w.frozen, w.balance, u.uid')
            ->join('users u', 'u.id = w.user_id', 'LEFT')
            ->where('w.frozen', '>', 0)
            ->order('w.frozen', 'desc')
            ->limit(20)
            ->select()->toArray();

        return $this->success([
            'threshold'     => $threshold,
            'largeTrans'    => camelize_keys($largeTrans),
            'frequent'      => camelize_keys($frequent),
            'negative'      => camelize_keys($negative),
            'bigFrozen'     => camelize_keys($bigFrozen),
        ]);
    }
}
