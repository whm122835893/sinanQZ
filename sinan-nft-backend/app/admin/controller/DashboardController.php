<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 管理后台数据大盘控制器
 *
 * KPI 总览卡、近 30 日交易/用户趋势、藏品销量榜、最新动态。
 * 全部聚合查询走索引（status/created_at），无敏感字段输出。
 */
class DashboardController extends BaseController
{
    /**
     * GET /admin/dashboard/overview
     * KPI 总览：用户/藏品/盲盒/订单/交易额/市场/转赠/待办
     */
    public function overview()
    {
        $today    = date('Y-m-d');
        $todayEnd = $today . ' 23:59:59';

        $userTotal    = Db::name('users')->whereNull('deleted_at')->count();
        $userToday    = Db::name('users')->whereNull('deleted_at')->whereBetweenTime('created_at', $today . ' 00:00:00', $todayEnd)->count();
        $userRealname = Db::name('users')->whereNull('deleted_at')->where('is_realname', 1)->count();

        $collectibleTotal = Db::name('collectibles')->whereNull('deleted_at')->count();
        $collectibleOnsale = Db::name('collectibles')->whereNull('deleted_at')->whereIn('status', ['upcoming', 'onsale'])->count();
        $blindBoxTotal    = Db::name('blind_boxes')->alias('bb')
            ->join('collectibles c', 'c.id = bb.collectible_id')
            ->whereNull('c.deleted_at')->count();

        $orderTotal = Db::name('orders')->count();
        $orderToday = Db::name('orders')->whereBetweenTime('created_at', $today . ' 00:00:00', $todayEnd)->count();
        $gmvTotal   = (float) Db::name('orders')->where('status', 'completed')->sum('total_price');
        $gmvToday   = (float) Db::name('orders')->where('status', 'completed')->whereBetweenTime('created_at', $today . ' 00:00:00', $todayEnd)->sum('total_price');

        $pendingOrders = Db::name('orders')->where('status', 'pending')->count();
        $listingSelling = Db::name('resale_listings')->where('status', 'selling')->count();
        $transferPending = Db::name('transfers')->where('status', 'pending')->count();

        $refundPending   = Db::name('refunds')->where('status', 1)->count();
        $ticketOpen      = Db::name('support_tickets')->whereIn('status', [1, 2])->count();
        $alertPending    = Db::name('risk_alerts')->where('status', 1)->count();

        return $this->success([
            'user' => [
                'total' => $userTotal, 'today' => $userToday, 'realname' => $userRealname,
                'realnameRate' => $userTotal > 0 ? round($userRealname / $userTotal * 100, 1) : 0,
            ],
            'collectible' => [
                'total' => $collectibleTotal, 'onsale' => $collectibleOnsale, 'blindBox' => $blindBoxTotal,
            ],
            'order' => [
                'total' => $orderTotal, 'today' => $orderToday, 'pending' => $pendingOrders,
                'gmvTotal' => round($gmvTotal, 2), 'gmvToday' => round($gmvToday, 2),
            ],
            'market' => [
                'listingSelling' => $listingSelling, 'transferPending' => $transferPending,
            ],
            'todo' => [
                'refundPending' => $refundPending, 'ticketOpen' => $ticketOpen, 'riskAlert' => $alertPending,
            ],
        ]);
    }

    /**
     * GET /admin/dashboard/trend?days=30
     * 近 N 日：每日 GMV、订单量、新增用户（默认 30 天，最大 90）
     */
    public function trend()
    {
        $days = (int) $this->request->param('days', 30);
        $days = min(90, max(7, $days));
        $start = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));

        $gmvRows = Db::name('orders')->field("DATE(created_at) AS d, COUNT(*) AS cnt, SUM(total_price) AS gmv")
            ->where('status', 'completed')->where('created_at', '>=', $start)
            ->group('d')->select()->toArray();
        $userRows = Db::name('users')->field("DATE(created_at) AS d, COUNT(*) AS cnt")
            ->whereNull('deleted_at')->where('created_at', '>=', $start)
            ->group('d')->select()->toArray();

        $gmvMap  = array_column($gmvRows, null, 'd');
        $userMap = array_column($userRows, null, 'd');

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' days'));
            $series[] = [
                'date'    => $day,
                'gmv'     => isset($gmvMap[$day]) ? round((float) $gmvMap[$day]['gmv'], 2) : 0,
                'orders'  => isset($gmvMap[$day]) ? (int) $gmvMap[$day]['cnt'] : 0,
                'newUser' => isset($userMap[$day]) ? (int) $userMap[$day]['cnt'] : 0,
            ];
        }
        return $this->success(['days' => $days, 'series' => $series]);
    }

    /**
     * GET /admin/dashboard/rank?type=collectible|category&limit=10
     * 销量榜：藏品维度（按已售件数）或类目维度
     */
    public function rank()
    {
        $type  = (string) $this->request->param('type', 'collectible');
        $limit = min(50, max(5, (int) $this->request->param('limit', 10)));

        if ($type === 'category') {
            $rows = Db::name('collectibles')->alias('c')
                ->field('cat.name AS name, SUM(c.sold) AS sold, COUNT(c.id) AS total')
                ->join('categories cat', 'cat.id = c.category_id')
                ->whereNull('c.deleted_at')
                ->group('cat.id')
                ->order('sold', 'desc')
                ->limit($limit)
                ->select()->toArray();
        } else {
            $rows = Db::name('collectibles')->alias('c')
                ->field('c.id, c.name, c.image, c.price, c.sold, c.edition, cat.name AS category_name')
                ->join('categories cat', 'cat.id = c.category_id', 'LEFT')
                ->whereNull('c.deleted_at')
                ->order('c.sold', 'desc')
                ->limit($limit)
                ->select()->toArray();
        }

        foreach ($rows as &$row) {
            $row['sold'] = (int) $row['sold'];
        }
        return $this->success(['type' => $type, 'list' => camelize_keys($rows)]);
    }

    /**
     * GET /admin/dashboard/latest
     * 最新动态：近 10 笔订单 + 近 10 位注册用户
     */
    public function latest()
    {
        $orders = Db::name('orders')->alias('o')
            ->field('o.order_no, o.total_price, o.status, o.created_at, u.uid, u.username, c.name AS collectible_name')
            ->join('users u', 'u.id = o.user_id', 'LEFT')
            ->join('collectibles c', 'c.id = o.collectible_id', 'LEFT')
            ->order('o.id', 'desc')->limit(10)->select()->toArray();

        $users = Db::name('users')
            ->field('id, uid, username, phone, is_realname, created_at')
            ->whereNull('deleted_at')
            ->order('id', 'desc')->limit(10)->select()->toArray();
        foreach ($users as &$u) {
            $u['phone'] = mask_phone((string) $u['phone']);
        }

        return $this->success(['orders' => camelize_keys($orders), 'users' => camelize_keys($users)]);
    }
}
