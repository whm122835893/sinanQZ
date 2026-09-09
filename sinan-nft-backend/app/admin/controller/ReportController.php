<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\traits\ExcelExport;
use think\facade\Db;

/**
 * 数据报表控制器
 *
 * - 销售报表（report:sales）：GMV/订单量趋势、支付方式分布、渠道转化
 * - 用户报表（report:user）：注册趋势、实名率、活跃留存
 * - 藏品报表（report:collectible）：发售/流通/库存分布、TOP 持仓
 * - 盲盒报表（report:blindbox）：开盒量趋势、奖池分布
 * - 财务对账（report:finance）：收入构成、手续费、充值/退款/提现总账
 *
 * 严谨性设计：
 * - 默认最近 30 天，最长可查 366 天（防止全表扫描）
 * - 金额统一 round(x, 2)，日期粒度自动适配区间长度（>92 天按月）
 */
class ReportController extends BaseController
{
    use ExcelExport;
    /**
     * GET /admin/reports/sales
     */
    public function sales()
    {
        $range = $this->reportRange(30);
        [$start, $end] = $range;
        $monthly = $this->isMonthly($start, $end);
        $format = $monthly ? '%Y-%m' : '%Y-%m-%d';

        // 1. GMV 与订单量趋势（仅已完成订单计入）
        $trendRows = Db::name('orders')
            ->fieldRaw("DATE_FORMAT(created_at, '{$format}') AS stat_date,
                        COUNT(*) AS order_count,
                        COALESCE(SUM(total_price), 0) AS gmv,
                        COALESCE(SUM(quantity), 0) AS quantity")
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->group('stat_date')
            ->order('stat_date', 'asc')
            ->select()->toArray();

        // 2. 来源分布（发售/优先购/资格购/市场寄售）
        $sourceRows = Db::name('orders')
            ->fieldRaw("source, COUNT(*) AS order_count, COALESCE(SUM(total_price), 0) AS gmv")
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->group('source')
            ->select()->toArray();
        // orders.source 存字符串枚举（release/market/priority/eligibility），非数字
        $sourceMap = ['release' => '发售', 'market' => '市场寄售', 'priority' => '优先购', 'eligibility' => '资格购'];

        // 3. 支付方式分布
        $payRows = Db::name('payments')->alias('p')
            ->fieldRaw("p.payment_method, COUNT(*) AS pay_count, COALESCE(SUM(p.amount), 0) AS amount")
            ->where('p.status', 'success')
            ->whereBetween('p.created_at', [$start, $end])
            ->group('p.payment_method')
            ->select()->toArray();

        // 4. TOP 藏品销售榜（按 GMV）
        $topCollectibles = Db::name('orders')->alias('o')
            ->fieldRaw('o.collectible_id, c.name, c.image AS cover_image, COUNT(*) AS order_count,
                        COALESCE(SUM(o.total_price), 0) AS gmv, COALESCE(SUM(o.quantity), 0) AS quantity')
            ->join('collectibles c', 'c.id = o.collectible_id')
            ->where('o.status', 'completed')
            ->whereBetween('o.created_at', [$start, $end])
            ->group('o.collectible_id, c.name, c.image')
            ->order('gmv', 'desc')
            ->limit(10)
            ->select()->toArray();

        // 5. 汇总
        $summary = Db::name('orders')
            ->fieldRaw("COUNT(*) AS order_total, COALESCE(SUM(total_price), 0) AS gmv_total,
                        COALESCE(SUM(quantity), 0) AS quantity_total,
                        COUNT(DISTINCT user_id) AS buyer_count")
            ->where('status', 'completed')
            ->whereBetween('created_at', [$start, $end])
            ->find();
        // 客单价
        $summary['avg_order_amount'] = $summary['order_total'] > 0
            ? round((float) $summary['gmv_total'] / (int) $summary['order_total'], 2) : 0;

        return $this->success([
            'range'    => ['start' => $start, 'end' => $end, 'granularity' => $monthly ? 'month' : 'day'],
            'summary'  => [
                'orderTotal'     => (int) $summary['order_total'],
                'gmvTotal'       => round((float) $summary['gmv_total'], 2),
                'quantityTotal'  => (int) $summary['quantity_total'],
                'buyerCount'     => (int) $summary['buyer_count'],
                'avgOrderAmount' => (float) $summary['avg_order_amount'],
            ],
            'trend'    => $trendRows,
            'sources'  => array_map(fn ($r) => [
                'source'      => $r['source'],
                'sourceName'  => $sourceMap[$r['source']] ?? ('来源' . $r['source']),
                'orderCount'  => (int) $r['order_count'],
                'gmv'         => round((float) $r['gmv'], 2),
            ], $sourceRows),
            'payments' => array_map(fn ($r) => [
                'method'    => $r['payment_method'],
                'payCount'  => (int) $r['pay_count'],
                'amount'    => round((float) $r['amount'], 2),
            ], $payRows),
            'topCollectibles' => $topCollectibles,
        ]);
    }

    /**
     * GET /admin/reports/users
     */
    public function users()
    {
        $range = $this->reportRange(30);
        [$start, $end] = $range;
        $monthly = $this->isMonthly($start, $end);
        $format = $monthly ? '%Y-%m' : '%Y-%m-%d';

        // 1. 注册趋势
        $regTrend = Db::name('users')
            ->fieldRaw("DATE_FORMAT(created_at, '{$format}') AS stat_date, COUNT(*) AS reg_count")
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$start, $end])
            ->group('stat_date')
            ->order('stat_date', 'asc')
            ->select()->toArray();

        // 2. 总量与实名率
        $total = (int) Db::name('users')->whereNull('deleted_at')->count();
        $realname = (int) Db::name('users')->whereNull('deleted_at')->where('is_realname', 1)->count();
        $frozen = (int) Db::name('users')->whereNull('deleted_at')->where('status', 0)->count();
        $blacklisted = (int) Db::name('users')->whereNull('deleted_at')->where('is_blacklisted', 1)->count();
        $newInRange = (int) Db::name('users')->whereNull('deleted_at')->whereBetween('created_at', [$start, $end])->count();

        // 3. 持有用户分布（按持仓数量分层）
        $holdingBuckets = [
            ['label' => '0 份（未持有）', 'count' => 0],
            ['label' => '1~3 份（轻度）', 'count' => 0],
            ['label' => '4~10 份（中度）', 'count' => 0],
            ['label' => '11+ 份（重度）', 'count' => 0],
        ];
        $holdingRows = Db::name('user_collectibles')
            ->fieldRaw('user_id, COUNT(*) AS hold_count')
            ->whereIn('status', ['held', 'consigned', 'frozen'])
            ->group('user_id')
            ->select()->toArray();
        $holdingUsers = count($holdingRows);
        foreach ($holdingRows as $row) {
            $c = (int) $row['hold_count'];
            if ($c >= 11) {
                $holdingBuckets[3]['count']++;
            } elseif ($c >= 4) {
                $holdingBuckets[2]['count']++;
            } elseif ($c >= 1) {
                $holdingBuckets[1]['count']++;
            }
        }
        $holdingBuckets[0]['count'] = max(0, $total - $holdingUsers);

        // 4. 注册渠道分布（users 无 source 字段：以 invite_records 被邀请关系推导注册渠道）
        $invitedCount = (int) Db::name('invite_records')->alias('ir')
            ->join('users u', 'u.id = ir.invitee_id')
            ->whereNull('u.deleted_at')
            ->whereBetween('u.created_at', [$start, $end])
            ->group('ir.invitee_id')
            ->count();
        $sourceRows = [
            ['source' => 1, 'sourceName' => '手机号注册', 'count' => max(0, $newInRange - $invitedCount)],
            ['source' => 2, 'sourceName' => '邀请注册',   'count' => min($invitedCount, $newInRange)],
        ];

        // 5. 邀请关系 TOP
        $topInviters = Db::name('invite_records')->alias('ir')
            ->fieldRaw('ir.inviter_id, u.uid, u.username, COUNT(*) AS invite_count')
            ->join('users u', 'u.id = ir.inviter_id')
            ->whereBetween('ir.created_at', [$start, $end])
            ->group('ir.inviter_id, u.uid, u.username')
            ->order('invite_count', 'desc')
            ->limit(10)
            ->select()->toArray();

        return $this->success([
            'range'   => ['start' => $start, 'end' => $end, 'granularity' => $monthly ? 'month' : 'day'],
            'summary' => [
                'total'       => $total,
                'realname'    => $realname,
                'realnameRate' => $total > 0 ? round($realname / $total * 100, 1) : 0,
                'frozen'      => $frozen,
                'blacklisted' => $blacklisted,
                'newInRange'  => $newInRange,
                'holdingUsers' => $holdingUsers,
            ],
            'regTrend' => $regTrend,
            'holdingBuckets' => $holdingBuckets,
            'sources' => array_map(fn ($r) => [
                'source'    => $r['source'],
                'sourceName' => $r['sourceName'],
                'count'     => (int) $r['count'],
            ], $sourceRows),
            'topInviters' => $topInviters,
        ]);
    }

    /**
     * GET /admin/reports/collectibles
     */
    public function collectibles()
    {
        $range = $this->reportRange(30);
        [$start, $end] = $range;

        // 1. 藏品总览
        $summary = [
            'total'      => (int) Db::name('collectibles')->whereNull('deleted_at')->count(),
            'onsale'     => (int) Db::name('collectibles')->whereNull('deleted_at')->whereIn('status', ['upcoming', 'onsale'])->count(),
            'soldout'    => (int) Db::name('collectibles')->whereNull('deleted_at')->where('status', 'soldout')->count(),
            'delisted'   => (int) Db::name('collectibles')->whereNull('deleted_at')->where('status', 'delisted')->count(),
            'isBlindbox' => (int) Db::name('collectibles')->alias('c')
                ->join('blind_boxes bb', 'bb.collectible_id = c.id')
                ->whereNull('c.deleted_at')->count(),
        ];

        // 2. 分类分布
        $categoryRows = Db::name('collectibles')->alias('c')
            ->fieldRaw('c.category_id, cat.name AS category_name, COUNT(*) AS cnt,
                        COALESCE(SUM(c.sold), 0) AS sold_total, COALESCE(SUM(c.circulate), 0) AS circulate_total')
            ->join('categories cat', 'cat.id = c.category_id')
            ->whereNull('c.deleted_at')
            ->group('c.category_id, cat.name')
            ->select()->toArray();

        // 3. 发售趋势（区间内新增藏品）
        $monthly = $this->isMonthly($start, $end);
        $format = $monthly ? '%Y-%m' : '%Y-%m-%d';
        $issueTrend = Db::name('collectibles')
            ->fieldRaw("DATE_FORMAT(created_at, '{$format}') AS stat_date, COUNT(*) AS new_count,
                        COALESCE(SUM(edition), 0) AS edition_total")
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$start, $end])
            ->group('stat_date')
            ->order('stat_date', 'asc')
            ->select()->toArray();

        // 4. 市场寄售概览
        $market = [
            'selling'   => (int) Db::name('resale_listings')->where('status', 'selling')->count(),
            'sold'      => (int) Db::name('resale_listings')->where('status', 'sold')->count(),
            'cancelled' => (int) Db::name('resale_listings')->where('status', 'cancelled')->count(),
            'sellingAmount' => round((float) Db::name('resale_listings')->where('status', 'selling')->sum('price'), 2),
            'soldAmount'    => round((float) Db::name('resale_listings')->where('status', 'sold')->sum('price'), 2),
            'feeAmount'     => round((float) Db::name('resale_listings')->where('status', 'sold')->sum('fee_amount'), 2),
        ];

        // 5. TOP 流通藏品
        $topCirculate = Db::name('collectibles')
            ->field('id, name, image AS cover_image, edition, sold, circulate, destroyed_count, airdropped_count, reserved_count')
            ->whereNull('deleted_at')
            ->order('circulate', 'desc')
            ->limit(10)
            ->select()->toArray();

        return $this->success([
            'range'     => ['start' => $start, 'end' => $end, 'granularity' => $monthly ? 'month' : 'day'],
            'summary'   => $summary,
            'categories' => $categoryRows,
            'issueTrend' => $issueTrend,
            'market'    => $market,
            'topCirculate' => $topCirculate,
        ]);
    }

    /**
     * GET /admin/reports/blindbox
     */
    public function blindbox()
    {
        $range = $this->reportRange(30);
        [$start, $end] = $range;

        // 1. 盲盒总览
        $summary = [
            'total'    => (int) Db::name('blind_boxes')->alias('bb')
                ->join('collectibles c', 'c.id = bb.collectible_id')->whereNull('c.deleted_at')->count(),
            'openedTotal' => (int) Db::name('blind_boxes')->alias('bb')
                ->join('collectibles c', 'c.id = bb.collectible_id')
                ->whereNull('c.deleted_at')->sum('bb.opened_count'),
        ];

        // 2. 开盒趋势（开盒即生成 source=blindbox 的奖品持仓，按其 created_at 统计）
        $monthly = $this->isMonthly($start, $end);
        $format = $monthly ? '%Y-%m' : '%Y-%m-%d';
        $openTrend = Db::name('user_collectibles')
            ->fieldRaw("DATE_FORMAT(created_at, '{$format}') AS stat_date, COUNT(*) AS open_count")
            ->where('source', 'blindbox')
            ->whereBetween('created_at', [$start, $end])
            ->group('stat_date')
            ->order('stat_date', 'asc')
            ->select()->toArray();

        // 3. 奖池分布（各子藏品发放占比）
        $prizeRows = Db::name('blind_box_items')->alias('bbi')
            ->fieldRaw('bbi.prize_collectible_id, c.name AS prize_name, COUNT(DISTINCT bbi.blind_box_id) AS box_count,
                        COALESCE(SUM(bbi.quantity_distributed), 0) AS distributed_total,
                        COALESCE(SUM(bbi.quantity_limit), 0) AS limit_total')
            ->join('collectibles c', 'c.id = bbi.prize_collectible_id')
            ->whereNull('c.deleted_at')
            ->group('bbi.prize_collectible_id, c.name')
            ->order('distributed_total', 'desc')
            ->limit(20)
            ->select()->toArray();

        // 4. 各盲盒开盒排行（盲盒价格取父藏品 price）
        $boxRanking = Db::name('blind_boxes')->alias('bb')
            ->fieldRaw('bb.id, c.name, bb.opened_count, c.price,
                        (SELECT COUNT(*) FROM nft_blind_box_items i WHERE i.blind_box_id = bb.id) AS prize_count')
            ->join('collectibles c', 'c.id = bb.collectible_id')
            ->whereNull('c.deleted_at')
            ->order('bb.opened_count', 'desc')
            ->limit(10)
            ->select()->toArray();

        return $this->success([
            'range'   => ['start' => $start, 'end' => $end, 'granularity' => $monthly ? 'month' : 'day'],
            'summary' => $summary,
            'openTrend' => $openTrend,
            'prizes'  => $prizeRows,
            'boxRanking' => $boxRanking,
        ]);
    }

    /**
     * GET /admin/reports/finance（财务对账总账）
     */
    public function finance()
    {
        $range = $this->reportRange(30);
        [$start, $end] = $range;

        // 1. 资金总账（恒等式各分量）
        $rechargeIn = (float) Db::name('wallet_transactions')->where('trans_type', 'recharge')->where('direction', 1)
            ->whereBetween('created_at', [$start, $end])->sum('amount');
        $rewardIn = (float) Db::name('wallet_transactions')->where('trans_type', 'reward')->where('direction', 1)
            ->whereBetween('created_at', [$start, $end])->sum('amount');
        $buyOut = (float) Db::name('wallet_transactions')->where('trans_type', 'buy')->where('direction', 2)
            ->whereBetween('created_at', [$start, $end])->sum('amount');
        $withdrawOut = (float) Db::name('wallet_transactions')->where('trans_type', 'withdraw')->where('direction', 2)
            ->whereBetween('created_at', [$start, $end])->sum('amount');

        // 2. 退款总额
        $refundAmount = (float) Db::name('refunds')->where('status', 3)
            ->whereBetween('refunded_at', [$start, $end])->sum('amount');

        // 3. 市场手续费（平台收入；resale_listings 无 sold_at，以 sold 状态行的 updated_at 为成交时间）
        $feeAmount = (float) Db::name('resale_listings')->where('status', 'sold')
            ->whereBetween('updated_at', [$start, $end])->sum('fee_amount');

        // 4. 支付渠道对账
        $channelRows = Db::name('payments')->alias('p')
            ->fieldRaw("p.payment_method, COUNT(*) AS pay_count, COALESCE(SUM(p.amount), 0) AS amount_total")
            ->where('p.status', 'success')
            ->whereBetween('p.created_at', [$start, $end])
            ->group('p.payment_method')
            ->select()->toArray();
        $refundedRows = Db::name('payments')
            ->fieldRaw("payment_method, COUNT(*) AS refund_count, COALESCE(SUM(amount), 0) AS refund_amount")
            ->where('status', 'refunded')
            ->whereBetween('updated_at', [$start, $end])
            ->group('payment_method')
            ->select()->toArray();

        // 5. 全站余额快照
        $balanceSnapshot = [
            'balanceTotal'  => round((float) Db::name('wallets')->sum('balance'), 2),
            'availableTotal' => round((float) Db::name('wallets')->sum('available'), 2),
            'frozenTotal'   => round((float) Db::name('wallets')->sum('frozen'), 2),
            'walletCount'   => (int) Db::name('wallets')->count(),
        ];

        return $this->success([
            'range'   => ['start' => $start, 'end' => $end],
            'ledger'  => [
                'rechargeIn'   => round($rechargeIn, 2),
                'rewardIn'     => round($rewardIn, 2),
                'buyOut'       => round($buyOut, 2),
                'withdrawOut'  => round($withdrawOut, 2),
                'refundAmount' => round($refundAmount, 2),
                'feeIncome'    => round($feeAmount, 2),
            ],
            'channels' => array_map(function ($r) use ($refundedRows) {
                $refunded = null;
                foreach ($refundedRows as $rr) {
                    if ($rr['payment_method'] === $r['payment_method']) {
                        $refunded = $rr;
                        break;
                    }
                }
                return [
                    'method'       => $r['payment_method'],
                    'payCount'     => (int) $r['pay_count'],
                    'amountTotal'  => round((float) $r['amount_total'], 2),
                    'refundCount'  => $refunded ? (int) $refunded['refund_count'] : 0,
                    'refundAmount' => $refunded ? round((float) $refunded['refund_amount'], 2) : 0,
                    'netAmount'    => round((float) $r['amount_total'] - ($refunded ? (float) $refunded['refund_amount'] : 0), 2),
                ];
            }, $channelRows),
            'balanceSnapshot' => $balanceSnapshot,
        ]);
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 报表时间区间（默认最近 N 天，最长 366 天）
     *
     * @return array{0:string,1:string}
     */
    private function reportRange(int $defaultDays): array
    {
        $range = $this->dateRange();
        if ($range === null) {
            $start = date('Y-m-d 00:00:00', strtotime('-' . ($defaultDays - 1) . ' days'));
            $end   = date('Y-m-d 23:59:59');
            return [$start, $end];
        }
        [$start, $end] = $range;
        // 区间过长保护
        if (strtotime($end) - strtotime($start) > 366 * 86400) {
            $start = date('Y-m-d 00:00:00', strtotime($end) . ' -366 days');
        }
        if ($start === '') {
            $start = date('Y-m-d 00:00:00', strtotime('-' . ($defaultDays - 1) . ' days'));
        }
        if ($end === '') {
            $end = date('Y-m-d 23:59:59');
        }
        return [$start, $end];
    }

    /**
     * 区间是否超过 92 天（按月聚合）
     */
    private function isMonthly(string $start, string $end): bool
    {
        return (strtotime($end) - strtotime($start)) > 92 * 86400;
    }

    // ============================================================
    // Excel 导出 —— 直接调用对应的 report 方法取原始数据，再格式化
    // ============================================================

    public function exportSales()
    {
        $json = json_decode(json_encode($this->sales()->getData(true)), true);
        $data = $json['data'] ?? [];

        $trendRows = array_map(fn ($r) => [
            $r['stat_date'], $r['order_count'], round((float) $r['gmv'], 2), $r['quantity']
        ], $data['trend'] ?? []);

        $sourceMap = ['release' => '发售', 'market' => '市场寄售', 'priority' => '优先购', 'eligibility' => '资格购'];
        $sourceRows = array_map(fn ($r) => [
            $sourceMap[$r['source']] ?? '来源' . $r['source'], $r['order_count'], round((float) $r['gmv'], 2)
        ], $data['sources'] ?? []);

        $payRows = array_map(fn ($r) => [
            $r['payment_method'], $r['pay_count'], round((float) $r['amount'], 2)
        ], $data['payments'] ?? []);

        $topRows = array_map(fn ($r) => [
            $r['name'], $r['order_count'], round((float) $r['gmv'], 2), $r['quantity']
        ], $data['topCollectibles'] ?? []);

        return $this->excelExport('销售报表_' . date('Ymd_His'), [
            ['sheet' => '汇总', 'headers' => ['区间', '订单总数', 'GMV', '件数', '买家数', '客单价'], 'rows' => [[
                ($data['range']['start'] ?? '') . ' ~ ' . ($data['range']['end'] ?? ''),
                $data['summary']['orderTotal'] ?? 0,
                $data['summary']['gmvTotal'] ?? 0,
                $data['summary']['quantityTotal'] ?? 0,
                $data['summary']['buyerCount'] ?? 0,
                $data['summary']['avgOrderAmount'] ?? 0,
            ]]],
            ['sheet' => '趋势', 'headers' => ['日期', '订单量', 'GMV', '件数'], 'rows' => $trendRows],
            ['sheet' => '来源分布', 'headers' => ['来源', '订单量', 'GMV'], 'rows' => $sourceRows],
            ['sheet' => '支付方式', 'headers' => ['支付方式', '笔数', '金额'], 'rows' => $payRows],
            ['sheet' => 'TOP藏品', 'headers' => ['藏品', '订单数', 'GMV', '件数'], 'rows' => $topRows],
        ]);
    }

    public function exportUsers()
    {
        $json = json_decode(json_encode($this->users()->getData(true)), true);
        $data = $json['data'] ?? [];

        $regRows = array_map(fn ($r) => [$r['stat_date'], $r['reg_count']], $data['regTrend'] ?? []);
        $holdingRows = array_map(fn ($b) => [$b['label'], $b['count']], $data['holdingBuckets'] ?? []);
        $topInvRows = array_map(fn ($r) => [$r['username'] ?? $r['uid'] ?? '', $r['invite_count']], $data['topInviters'] ?? []);

        return $this->excelExport('用户报表_' . date('Ymd_His'), [
            ['sheet' => '汇总', 'headers' => ['区间', '总用户', '实名用户', '实名率', '冻结', '黑名单', '区间新增', '持仓用户'], 'rows' => [[
                ($data['range']['start'] ?? '') . ' ~ ' . ($data['range']['end'] ?? ''),
                $data['summary']['total'] ?? 0,
                $data['summary']['realname'] ?? 0,
                ($data['summary']['realnameRate'] ?? 0) . '%',
                $data['summary']['frozen'] ?? 0,
                $data['summary']['blacklisted'] ?? 0,
                $data['summary']['newInRange'] ?? 0,
                $data['summary']['holdingUsers'] ?? 0,
            ]]],
            ['sheet' => '注册趋势', 'headers' => ['日期', '注册数'], 'rows' => $regRows],
            ['sheet' => '持仓分层', 'headers' => ['层级', '人数'], 'rows' => $holdingRows],
            ['sheet' => '邀请TOP', 'headers' => ['邀请人', '邀请数'], 'rows' => $topInvRows],
        ]);
    }

    public function exportCollectibles()
    {
        $json = json_decode(json_encode($this->collectibles()->getData(true)), true);
        $data = $json['data'] ?? [];

        $catRows = array_map(fn ($r) => [$r['category_name'], $r['cnt'], $r['sold_total'], $r['circulate_total']], $data['categories'] ?? []);
        $issueRows = array_map(fn ($r) => [$r['stat_date'], $r['new_count'], $r['edition_total']], $data['issueTrend'] ?? []);
        $topCircRows = array_map(fn ($r) => [$r['name'], $r['edition'], $r['sold'], $r['circulate'], $r['destroyed_count'], $r['airdropped_count']], $data['topCirculate'] ?? []);

        return $this->excelExport('藏品报表_' . date('Ymd_His'), [
            ['sheet' => '总览', 'headers' => ['区间', '总藏品', '在售中', '已售罄', '已下架', '盲盒数量', '寄售中', '寄售GMV', '已成交GMV', '平台手续费'], 'rows' => [[
                ($data['range']['start'] ?? '') . ' ~ ' . ($data['range']['end'] ?? ''),
                $data['summary']['total'] ?? 0,
                $data['summary']['onsale'] ?? 0,
                $data['summary']['soldout'] ?? 0,
                $data['summary']['delisted'] ?? 0,
                $data['summary']['isBlindbox'] ?? 0,
                $data['market']['selling'] ?? 0,
                $data['market']['sellingAmount'] ?? 0,
                $data['market']['soldAmount'] ?? 0,
                $data['market']['feeAmount'] ?? 0,
            ]]],
            ['sheet' => '分类分布', 'headers' => ['分类', '藏品数', '累计售出', '累计流通'], 'rows' => $catRows],
            ['sheet' => '发售趋势', 'headers' => ['日期', '新增', '发行总量'], 'rows' => $issueRows],
            ['sheet' => 'TOP流通', 'headers' => ['藏品', '发行量', '已售', '流通量', '销毁', '空投'], 'rows' => $topCircRows],
        ]);
    }

    public function exportBlindbox()
    {
        $json = json_decode(json_encode($this->blindbox()->getData(true)), true);
        $data = $json['data'] ?? [];

        $openRows = array_map(fn ($r) => [$r['stat_date'], $r['open_count']], $data['openTrend'] ?? []);
        $prizeRows = array_map(fn ($r) => [$r['prize_name'], $r['distributed_total'], $r['limit_total']], $data['prizes'] ?? []);
        $rankingRows = array_map(fn ($r) => [$r['name'], $r['opened_count'], $r['price'], $r['prize_count']], $data['boxRanking'] ?? []);

        return $this->excelExport('盲盒报表_' . date('Ymd_His'), [
            ['sheet' => '汇总', 'headers' => ['区间', '盲盒总数', '累计开盒', '奖品总数'], 'rows' => [[
                ($data['range']['start'] ?? '') . ' ~ ' . ($data['range']['end'] ?? ''),
                $data['summary']['total'] ?? 0,
                $data['summary']['openedTotal'] ?? 0,
                ($data['summary']['prizeTotal'] ?? ''),
            ]]],
            ['sheet' => '开盒趋势', 'headers' => ['日期', '开盒数'], 'rows' => $openRows],
            ['sheet' => '奖池分布', 'headers' => ['奖品', '已发放', '限量'], 'rows' => $prizeRows],
            ['sheet' => '盲盒排行', 'headers' => ['盲盒', '开盒数', '价格', '奖品数'], 'rows' => $rankingRows],
        ]);
    }

    public function exportFinance()
    {
        $json = json_decode(json_encode($this->finance()->getData(true)), true);
        $data = $json['data'] ?? [];

        $ledgerRows = [[
            $data['ledger']['rechargeIn'] ?? 0,
            $data['ledger']['rewardIn'] ?? 0,
            $data['ledger']['buyOut'] ?? 0,
            $data['ledger']['withdrawOut'] ?? 0,
            $data['ledger']['refundAmount'] ?? 0,
            $data['ledger']['feeIncome'] ?? 0,
        ]];

        $channelRows = array_map(fn ($r) => [
            $r['payment_method'] ?? $r['method'] ?? '', $r['payCount'] ?? 0, $r['amountTotal'] ?? 0,
            $r['refundCount'] ?? 0, $r['refundAmount'] ?? 0, $r['netAmount'] ?? 0,
        ], $data['channels'] ?? []);

        $balanceRows = [[
            $data['balanceSnapshot']['balanceTotal'] ?? 0,
            $data['balanceSnapshot']['availableTotal'] ?? 0,
            $data['balanceSnapshot']['frozenTotal'] ?? 0,
            $data['balanceSnapshot']['walletCount'] ?? 0,
        ]];

        return $this->excelExport('财务报表_' . date('Ymd_His'), [
            ['sheet' => '资金总账', 'headers' => ['充值入账', '奖励入账', '购买支出', '提现支出', '退款总额', '手续费收入'], 'rows' => $ledgerRows],
            ['sheet' => '渠道对账', 'headers' => ['支付方式', '支付笔数', '支付金额', '退款笔数', '退款金额', '净额'], 'rows' => $channelRows],
            ['sheet' => '余额快照', 'headers' => ['总余额', '可用余额', '冻结余额', '钱包数'], 'rows' => $balanceRows],
        ]);
    }
}
