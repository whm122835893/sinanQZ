<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 数据仪表盘控制器（文档 8.3，#8-#13，6 接口）
 * GET /admin/api/v1/dashboard/metrics          核心指标卡片
 * GET /admin/api/v1/dashboard/finance          资金监控图表
 * GET /admin/api/v1/dashboard/alerts           库存预警面板
 * GET /admin/api/v1/dashboard/activities       实时动态滚动
 * GET /admin/api/v1/dashboard/trends           趋势图数据
 * GET /admin/api/v1/dashboard/priority-stats   优先购统计
 */
class Dashboard extends AdminBase
{
    public function metrics()
    {
        $todayStart = date('Y-m-d 00:00:00');

        // 今日新增用户（未软删）
        $newUsersToday = Db::name('users')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $todayStart)
            ->count();

        // 今日销售额（今日支付的已完成订单）
        $salesToday = Db::name('orders')
            ->where('status', 'completed')
            ->where('paid_at', '>=', $todayStart)
            ->field('COALESCE(SUM(total_price), 0) AS amount, COUNT(*) AS cnt')
            ->find();
        $salesRow = $salesToday ?: ['amount' => 0, 'cnt' => 0];

        // 今日订单数（含待支付）
        $ordersToday = Db::name('orders')
            ->where('created_at', '>=', $todayStart)
            ->count();

        // 在售/待发售藏品
        $onsaleCollectibles = Db::name('collectibles')
            ->whereIn('status', ['onsale', 'upcoming'])
            ->count();

        // 在售挂单
        $activeListings = Db::name('resale_listings')
            ->where('status', 'selling')
            ->count();

        // 在售盲盒（盲盒即藏品行，状态挂在 nft_collectibles 上，文档 D-3）
        $onsaleBlindboxes = Db::name('blind_boxes')
            ->alias('bb')
            ->join('nft_collectibles c', 'bb.collectible_id = c.id')
            ->where('bb.is_openable', 1)
            ->whereIn('c.status', ['onsale', 'upcoming'])
            ->count();

        // 用户总数（未软删）
        $totalUsers = Db::name('users')->whereNull('deleted_at')->count();

        return $this->success([
            'newUsersToday'     => $newUsersToday,
            'salesToday'        => number_format((float) $salesRow['amount'], 2, '.', ''),
            'paidOrdersToday'   => (int) $salesRow['cnt'],
            'ordersToday'       => $ordersToday,
            'onsaleCollectibles' => $onsaleCollectibles,
            'activeListings'    => $activeListings,
            'onsaleBlindboxes'  => $onsaleBlindboxes,
            'totalUsers'        => $totalUsers,
        ]);
    }

    /**
     * #9 GET /dashboard/finance 资金监控图表（days=7/30）
     * 每日充值 / 每日销售 / 每日退款
     */
    public function finance()
    {
        $days = $this->intParam('days', 7);
        if (!in_array($days, [7, 30], true)) {
            $days = 7;
        }
        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

        // 日期序列（近 N 天）
        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = date('Y-m-d', strtotime('-' . $i . ' days'));
        }

        // 每日充值（wallet_transactions recharge 入账）
        $recharge = Db::name('wallet_transactions')
            ->where('trans_type', 'recharge')
            ->where('direction', 1)
            ->where('created_at', '>=', $since)
            ->field("DATE(created_at) AS d, SUM(amount) AS total")
            ->group('d')
            ->select()
            ->toArray();
        // 每日销售（completed 订单按支付日）
        $sales = Db::name('orders')
            ->where('status', 'completed')
            ->where('paid_at', '>=', $since)
            ->field("DATE(paid_at) AS d, SUM(total_price) AS total")
            ->group('d')
            ->select()
            ->toArray();
        // 每日退款（已执行退款单）
        $refunds = Db::name('refunds')
            ->where('status', 4)
            ->where('refunded_at', '>=', $since)
            ->field("DATE(refunded_at) AS d, SUM(amount) AS total")
            ->group('d')
            ->select()
            ->toArray();

        $map = function (array $rows): array {
            $m = [];
            foreach ($rows as $r) {
                $m[(string) $r['d']] = (float) $r['total'];
            }
            return $m;
        };
        $rechargeMap = $map($recharge);
        $salesMap = $map($sales);
        $refundMap = $map($refunds);

        $series = [];
        $totalRecharge = $totalSales = $totalRefund = 0.0;
        foreach ($dates as $d) {
            $r = $rechargeMap[$d] ?? 0.0;
            $s = $salesMap[$d] ?? 0.0;
            $f = $refundMap[$d] ?? 0.0;
            $totalRecharge += $r;
            $totalSales += $s;
            $totalRefund += $f;
            $series[] = ['date' => $d, 'recharge' => round($r, 2), 'sales' => round($s, 2), 'refund' => round($f, 2)];
        }

        return $this->success([
            'days'   => $days,
            'series' => $series,
            'totals' => [
                'recharge' => round($totalRecharge, 2),
                'sales'    => round($totalSales, 2),
                'refund'   => round($totalRefund, 2),
            ],
        ]);
    }

    /**
     * #10 GET /dashboard/alerts 库存预警面板（低库存/库存异常/盲盒短缺）
     * 库存池 = edition − sold − locked − reserved − airdropped − destroyed（4.3.1）
     */
    public function alerts()
    {
        // ---- 低库存（在售/待售藏品：库存池 ≤ 5 或 ≤ 发行量 5%）----
        $lowStock = [];
        $abnormal = [];
        $collectibles = Db::name('collectibles')
            ->whereNull('deleted_at')
            ->whereIn('status', ['onsale', 'upcoming', 'soldout'])
            ->field('id,name,status,edition,sold,locked_quantity,reserved_count,airdropped_count,destroyed_count,circulate')
            ->select()
            ->toArray();
        foreach ($collectibles as $c) {
            $pool = (int) $c['edition'] - (int) $c['sold'] - (int) $c['locked_quantity']
                - (int) $c['reserved_count'] - (int) $c['airdropped_count'] - (int) $c['destroyed_count'];

            // 库存守恒异常（4.3.1 ①②）
            $sumCounters = (int) $c['sold'] + (int) $c['locked_quantity'] + (int) $c['reserved_count']
                + (int) $c['airdropped_count'] + (int) $c['destroyed_count'];
            if ($pool < 0 || $sumCounters > (int) $c['edition'] || (int) $c['circulate'] > (int) $c['edition']) {
                $abnormal[] = [
                    'collectibleId' => (int) $c['id'],
                    'name'          => (string) $c['name'],
                    'status'        => (string) $c['status'],
                    'issue'         => $pool < 0 ? '库存池为负（计数器超发）' : ((int) $c['circulate'] > (int) $c['edition'] ? '流通量超过发行量' : '计数器总和超过发行量'),
                    'edition'       => (int) $c['edition'],
                    'stockPool'     => $pool,
                    'circulate'     => (int) $c['circulate'],
                ];
            } elseif (in_array($c['status'], ['onsale', 'upcoming'], true)) {
                $threshold = max(5, (int) ceil((int) $c['edition'] * 0.05));
                if ($pool <= $threshold) {
                    $lowStock[] = [
                        'collectibleId' => (int) $c['id'],
                        'name'          => (string) $c['name'],
                        'status'        => (string) $c['status'],
                        'edition'       => (int) $c['edition'],
                        'stockPool'     => $pool,
                        'threshold'     => $threshold,
                    ];
                }
            }
        }

        // ---- 盲盒短缺（在售盲盒库存池 ≤ 5）----
        $blindboxShortage = [];
        $blindboxes = Db::name('blind_boxes')->alias('bb')
            ->join('nft_collectibles c', 'c.id = bb.collectible_id')
            ->whereNull('c.deleted_at')
            ->whereIn('c.status', ['onsale', 'upcoming'])
            ->field('c.id,c.name,c.status,c.edition,c.sold,c.locked_quantity,c.reserved_count,c.airdropped_count,c.destroyed_count')
            ->select()
            ->toArray();
        foreach ($blindboxes as $c) {
            $pool = (int) $c['edition'] - (int) $c['sold'] - (int) $c['locked_quantity']
                - (int) $c['reserved_count'] - (int) $c['airdropped_count'] - (int) $c['destroyed_count'];
            if ($pool <= 5) {
                $blindboxShortage[] = [
                    'collectibleId' => (int) $c['id'],
                    'name'          => (string) $c['name'],
                    'status'        => (string) $c['status'],
                    'edition'       => (int) $c['edition'],
                    'stockPool'     => $pool,
                ];
            }
        }

        return $this->success([
            'lowStock'        => array_slice($lowStock, 0, 20),
            'lowStockCount'   => count($lowStock),
            'abnormal'        => array_slice($abnormal, 0, 20),
            'abnormalCount'   => count($abnormal),
            'blindboxShortage' => array_slice($blindboxShortage, 0, 20),
            'blindboxShortageCount' => count($blindboxShortage),
        ]);
    }

    /**
     * #11 GET /dashboard/activities 实时动态滚动（limit 默认 20）
     * 聚合：新订单 / 新用户 / 盲盒开启 / 寄售上架
     */
    public function activities()
    {
        $limit = $this->intParam('limit', 20);
        if ($limit < 1 || $limit > 100) {
            $limit = 20;
        }

        $events = [];

        // 近期订单（购买动态）
        $orders = Db::name('orders')->alias('o')
            ->join('nft_users u', 'u.id = o.user_id', 'LEFT')
            ->join('nft_collectibles c', 'c.id = o.collectible_id', 'LEFT')
            ->order('o.id', 'desc')
            ->limit($limit)
            ->field('o.id,o.order_no,o.total_price,o.status,o.created_at,u.username,c.name AS collectible_name')
            ->select()
            ->toArray();
        foreach ($orders as $o) {
            $events[] = [
                'type'      => 'order',
                'typeText'  => '购买',
                'content'   => ($o['username'] ?: '用户') . ' 创建订单 ' . $o['order_no']
                    . '（' . ($o['collectible_name'] ?: '藏品') . '，¥' . number_format((float) $o['total_price'], 2, '.', '') . '）',
                'status'    => (string) $o['status'],
                'createdAt' => (string) $o['created_at'],
            ];
        }

        // 新注册用户
        $users = Db::name('users')
            ->whereNull('deleted_at')
            ->order('id', 'desc')
            ->limit($limit)
            ->field('id,username,created_at')
            ->select()
            ->toArray();
        foreach ($users as $u) {
            $events[] = [
                'type'      => 'register',
                'typeText'  => '注册',
                'content'   => '新用户 ' . $u['username'] . ' 完成注册',
                'status'    => '',
                'createdAt' => (string) $u['created_at'],
            ];
        }

        // 盲盒开启（奖品资产 source=blindbox）
        $opens = Db::name('user_collectibles')->alias('uc')
            ->join('nft_users u', 'u.id = uc.user_id', 'LEFT')
            ->join('nft_collectibles c', 'c.id = uc.collectible_id', 'LEFT')
            ->where('uc.source', 'blindbox')
            ->order('uc.id', 'desc')
            ->limit($limit)
            ->field('uc.id,uc.created_at,u.username,c.name AS collectible_name')
            ->select()
            ->toArray();
        foreach ($opens as $op) {
            $events[] = [
                'type'      => 'blindbox_open',
                'typeText'  => '开盒',
                'content'   => ($op['username'] ?: '用户') . ' 开启盲盒获得「' . ($op['collectible_name'] ?: '藏品') . '」',
                'status'    => '',
                'createdAt' => (string) $op['created_at'],
            ];
        }

        // 寄售上架
        $listings = Db::name('resale_listings')->alias('rl')
            ->join('nft_users u', 'u.id = rl.seller_id', 'LEFT')
            ->join('nft_collectibles c', 'c.id = rl.collectible_id', 'LEFT')
            ->order('rl.id', 'desc')
            ->limit($limit)
            ->field('rl.id,rl.price,rl.status,rl.created_at,u.username,c.name AS collectible_name')
            ->select()
            ->toArray();
        foreach ($listings as $l) {
            $events[] = [
                'type'      => 'listing',
                'typeText'  => '寄售',
                'content'   => ($l['username'] ?: '用户') . ' 上架寄售「' . ($l['collectible_name'] ?: '藏品')
                    . '」（¥' . number_format((float) $l['price'], 2, '.', '') . '）',
                'status'    => (string) $l['status'],
                'createdAt' => (string) $l['created_at'],
            ];
        }

        // 按时间倒序取前 N 条
        usort($events, function (array $a, array $b) {
            return strcmp($b['createdAt'], $a['createdAt']);
        });

        return $this->success(['list' => array_slice($events, 0, $limit)]);
    }

    /**
     * #12 GET /dashboard/trends 趋势图数据（days + metric=sales/orders/blindbox）
     */
    public function trends()
    {
        $days = $this->intParam('days', 7);
        if (!in_array($days, [7, 30], true)) {
            $days = 7;
        }
        $metric = $this->strParam('metric') ?? 'sales';
        if (!in_array($metric, ['sales', 'orders', 'blindbox'], true)) {
            return $this->invalid('metric 必须为 sales/orders/blindbox');
        }

        $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = date('Y-m-d', strtotime('-' . $i . ' days'));
        }

        switch ($metric) {
            case 'sales': // 每日销售额
                $rows = Db::name('orders')
                    ->where('status', 'completed')
                    ->where('paid_at', '>=', $since)
                    ->field("DATE(paid_at) AS d, SUM(total_price) AS v")
                    ->group('d')
                    ->select()
                    ->toArray();
                $label = '销售额（元）';
                break;
            case 'orders': // 每日订单数
                $rows = Db::name('orders')
                    ->where('created_at', '>=', $since)
                    ->field("DATE(created_at) AS d, COUNT(*) AS v")
                    ->group('d')
                    ->select()
                    ->toArray();
                $label = '订单数（笔）';
                break;
            default: // blindbox 每日开盒数
                $rows = Db::name('user_collectibles')
                    ->where('source', 'blindbox')
                    ->where('created_at', '>=', $since)
                    ->field("DATE(created_at) AS d, COUNT(*) AS v")
                    ->group('d')
                    ->select()
                    ->toArray();
                $label = '开盒数（次）';
        }

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['d']] = (float) $r['v'];
        }

        $series = [];
        $total = 0.0;
        foreach ($dates as $d) {
            $v = $map[$d] ?? 0.0;
            $total += $v;
            $series[] = ['date' => $d, 'value' => round($v, 2)];
        }

        return $this->success([
            'days'   => $days,
            'metric' => $metric,
            'label'  => $label,
            'series' => $series,
            'total'  => round($total, 2),
        ]);
    }

    /**
     * #13 GET /dashboard/priority-stats 优先购统计（发放总量/已用/剩余）
     */
    public function priorityStats()
    {
        // 启用中的活动
        $activities = Db::name('priority_sales')
            ->where('status', 1)
            ->field('id,name,collectible_id,start_time,end_time')
            ->select()
            ->toArray();
        $activityIds = array_column($activities, 'id');

        $totalGranted = $totalUsed = 0;
        $validCount = 0;
        $byActivity = [];
        if ($activityIds) {
            $rows = Db::name('priority_sale_whitelists')
                ->whereIn('priority_sale_id', $activityIds)
                ->where('status', 1)
                ->field('priority_sale_id, COUNT(*) AS cnt, SUM(max_quantity) AS granted, SUM(used_quantity) AS used')
                ->group('priority_sale_id')
                ->select()
                ->toArray();
            $map = [];
            foreach ($rows as $r) {
                $map[(int) $r['priority_sale_id']] = $r;
                $totalGranted += (int) $r['granted'];
                $totalUsed += (int) $r['used'];
                $validCount += (int) $r['cnt'];
            }
            foreach ($activities as $a) {
                $r = $map[(int) $a['id']] ?? null;
                $byActivity[] = [
                    'activityId' => (int) $a['id'],
                    'name'       => (string) $a['name'],
                    'window'     => ['start' => (string) $a['start_time'], 'end' => (string) $a['end_time']],
                    'whitelistCount' => (int) ($r['cnt'] ?? 0),
                    'granted'    => (int) ($r['granted'] ?? 0),
                    'used'       => (int) ($r['used'] ?? 0),
                    'remaining'  => (int) ($r['granted'] ?? 0) - (int) ($r['used'] ?? 0),
                ];
            }
        }

        return $this->success([
            'summary' => [
                'activeActivities' => count($activities),
                'validWhitelists'  => $validCount,
                'totalGranted'     => $totalGranted,
                'totalUsed'        => $totalUsed,
                'totalRemaining'   => $totalGranted - $totalUsed,
            ],
            'byActivity' => $byActivity,
        ]);
    }
}
