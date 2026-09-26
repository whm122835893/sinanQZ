<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use think\facade\Db;

/**
 * 寄售挂单控制器
 */
class Resale extends BaseController
{
    /**
     * POST /api/resale/listings
     * 发起寄售挂单
     */
    public function create()
    {
        $userId            = $this->userId();
        $userCollectibleId = $this->intParam('userCollectibleId');
        $price             = (float) $this->request->post('price', 0);
        $paymentPassword   = $this->request->post('paymentPassword', '');

        if (!$userId) return $this->fail(2001, '未登录');
        if ($price <= 0) return $this->fail(1001, '寄售价必须大于0');

        $hash = Db::name('users')->where('id', $userId)->value('transaction_password');
        if (!$hash || !verify_password($paymentPassword, $hash)) {
            return $this->fail(2003, '交易密码错误');
        }

        Db::startTrans();
        try {
            $uc = Db::name('user_collectibles')
                ->where('id', $userCollectibleId)
                ->where('user_id', $userId)
                ->where('status', 'held')
                ->lock(true)
                ->find();
            if (!$uc) {
                Db::rollback();
                return $this->fail(1001, '藏品不存在或状态不可寄售');
            }

            // 冷却期检查
            // D1 修复：系统下架/强制下架的挂单同样落在此查询（status=cancelled），但 cooldown_until 为 NULL，
            // strtotime(null) 在 PHP8.1+ 触发 Deprecated 被 ThinkPHP 转异常（500），导致资产永久无法重新挂单。
            // NULL 视为无冷却，直接放行。
            $existing = Db::name('resale_listings')
                ->where('user_collectible_id', $userCollectibleId)
                ->where('status', 'cancelled')
                ->order('id', 'desc')
                ->find();
            if ($existing && !empty($existing['cooldown_until']) && strtotime((string) $existing['cooldown_until']) > time()) {
                Db::rollback();
                return $this->fail(1001, '寄售冷却中，请稍后再试');
            }

            $collectible = Db::name('collectibles')->where('id', $uc['collectible_id'])->find();

            // K01 寄售开关：藏品级 is_resaleable 校验（管理端可实时关闭）
            if (!$collectible || (int) $collectible['is_resaleable'] !== 1) {
                Db::rollback();
                return $this->fail(1001, '该藏品已关闭寄售，无法挂单');
            }

            // K04 单品价格管控：resale_price_mode 0不限价/1固定价/2区间价
            $priceMode = (int) $collectible['resale_price_mode'];
            if ($priceMode === 1) {
                $fixed = (float) $collectible['resale_price_min'];
                if (abs($price - $fixed) > 0.001) {
                    Db::rollback();
                    return $this->fail(1001, '该藏品为固定价寄售，寄售价必须为 ¥' . number_format($fixed, 2));
                }
            } elseif ($priceMode === 2) {
                $pMin = (float) $collectible['resale_price_min'];
                $pMax = (float) $collectible['resale_price_max'];
                if ($price < $pMin || $price > $pMax) {
                    Db::rollback();
                    return $this->fail(1001, '该藏品限价寄售，寄售价需在 ¥' . number_format($pMin, 2) . ' ~ ¥' . number_format($pMax, 2) . ' 之间');
                }
            }

            // K05 全局最高价：resale_price_global_max（管理端市场配置，实时生效）
            $globalMax = (float) Db::name('system_configs')
                ->where('config_key', 'resale_price_global_max')
                ->value('config_value');
            if ($globalMax > 0 && $price > $globalMax) {
                Db::rollback();
                return $this->fail(1001, '寄售价不能超过平台全局最高价 ¥' . number_format($globalMax, 2));
            }

            $feeRate     = (float) Db::name('system_configs')
                ->where('config_key', 'resale_fee_rate')
                ->value('config_value');
            $feeRate     = $feeRate ?: 1.0;
            $feeAmount   = round($price * $feeRate / 100, 2);
            $actualAmount = round($price - $feeAmount, 2);

            $now = date('Y-m-d H:i:s.v');
            Db::name('resale_listings')->insert([
                'seller_id'            => $userId,
                'collectible_id'       => $uc['collectible_id'],
                'user_collectible_id'  => $userCollectibleId,
                'price'                => $price,
                'fee_rate'             => $feeRate,
                'fee_amount'           => $feeAmount,
                'actual_amount'        => $actualAmount,
                'status'               => 'selling',
                'listed_at'            => $now,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);
            $listingId = (int) Db::name('resale_listings')->getLastInsID();

            Db::name('user_collectibles')->where('id', $userCollectibleId)->update([
                'status'      => 'consigned',
                'is_consigned'=> 1,
                'updated_at' => $now,
            ]);

            Db::commit();
            return $this->success([
                'listingId'    => $listingId,
                'price'        => $price,
                'feeAmount'    => $feeAmount,
                'actualAmount' => $actualAmount,
                'feeRate'      => $feeRate,
            ]);
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '挂单失败：' . $e->getMessage());
        }
    }

    /**
     * POST /api/resale/listings/:listingId/cancel
     * 取消寄售挂单
     */
    public function cancel()
    {
        $userId    = $this->userId();
        $listingId = $this->request->param('listingId');
        if (!$userId) return $this->fail(2001, '未登录');

        $cooldownSeconds = (int) Db::name('system_configs')
            ->where('config_key', 'resale_cooldown_seconds')
            ->value('config_value');
        $cooldownSeconds = $cooldownSeconds ?: 180;

        Db::startTrans();
        try {
            // 行锁：防止与买家下单并发（买家下单在锁内将挂单置 sold）
            $listing = Db::name('resale_listings')
                ->where('id', $listingId)
                ->where('seller_id', $userId)
                ->where('status', 'selling')
                ->lock(true)
                ->find();
            if (!$listing) {
                Db::rollback();
                return $this->fail(1002, '挂单不存在或已售出');
            }

            $now = date('Y-m-d H:i:s.v');
            Db::name('resale_listings')->where('id', $listingId)->update([
                'status'          => 'cancelled',
                'cooldown_until'  => date('Y-m-d H:i:s.v', time() + $cooldownSeconds),
                'updated_at'      => $now,
            ]);
            // 条件更新：仅当资产仍为寄售中才返还持有（防状态机跳变）
            Db::name('user_collectibles')
                ->where('id', $listing['user_collectible_id'])
                ->where('status', 'consigned')
                ->update([
                    'status'       => 'held',
                    'is_consigned' => 0,
                    'updated_at'  => $now,
                ]);

            Db::commit();
            return $this->success(['cooldownUntil' => date('Y-m-d H:i:s.v', time() + $cooldownSeconds)]);
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '取消挂单失败：' . $e->getMessage());
        }
    }

    /**
     * GET /api/resale/listings/mine
     * 我的寄售挂单
     */
    public function mine()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $p      = $this->pagination();
        $status = $this->strParam('status');

        $query = Db::name('resale_listings')->alias('l')
            ->join('collectibles c', 'c.id = l.collectible_id')
            ->where('l.seller_id', $userId)
            ->order('l.created_at', 'desc');
        if ($status) $query->where('l.status', $status);

        $total = (clone $query)->count();
        $list  = $query->limit($p['offset'], $p['pageSize'])->field([
            'l.id as listing_id', 'l.price', 'l.fee_amount', 'l.actual_amount',
            'l.status', 'l.listed_at', 'l.user_collectible_id',
            'c.name', 'c.image',
        ])->select()->toArray();

        // 查编号
        $ids = array_column($list, 'user_collectible_id');
        $noses = Db::name('user_collectibles')->whereIn('id', $ids)->column('serial', 'id');

        $items = array_map(function ($l) use ($noses) {
            return [
                'listingId'    => (int) $l['listing_id'],
                'userCollectibleId' => (int) $l['user_collectible_id'],
                'name'         => $l['name'],
                'image'        => $l['image'],
                'no'           => $noses[$l['user_collectible_id']] ?? '',
                'price'        => (float) $l['price'],
                'feeAmount'    => (float) $l['fee_amount'],
                'actualAmount' => (float) $l['actual_amount'],
                'status'       => $l['status'],
                'listedAt'     => $l['listed_at'],
            ];
        }, $list);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }

    /**
     * GET /api/resale/batch-buy/config
     * 当前用户批量购买配置（是否可用 + 单次最大数量）
     */
    public function batchBuyConfig()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $access = $this->batchBuyAccess($userId);

        return $this->success([
            'enabled' => $access['ok'],
            'limit'   => $access['ok'] ? $access['limit'] : 0,
        ]);
    }

    /**
     * POST /api/resale/batch-buy
     * 批量购买：从当前地板价（最低价）起、按价格升序锁定最多 limit 份在售挂单，
     * 生成一个市场批量订单（待支付）。实际锁定数量=min(限度, 可购挂单数)。
     */
    public function batchBuy()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $collectibleId = $this->intParam('collectibleId');
        $quantity      = $this->intParam('quantity', 0);
        if ($collectibleId <= 0 || $quantity <= 0) {
            return $this->fail(1001, '参数错误');
        }

        // 实名前置（与下单一致）
        $isRealname = Db::name('users')->where('id', $userId)->value('is_realname');
        if ((int) $isRealname !== 1) return $this->fail(1001, '请先完成实名认证');

        // 批量购买开关 + 限度（全体用户 / 指定用户）
        $access = $this->batchBuyAccess($userId);
        if (!$access['ok']) return $this->fail(1001, $access['reason']);
        $limit = $access['limit'];
        if ($quantity > $limit) $quantity = $limit;

        Db::startTrans();
        try {
            $now = date('Y-m-d H:i:s.v');

            // 从地板价起按价格升序锁定最多 quantity 份在售挂单（排除自己）
            $listings = Db::name('resale_listings')
                ->where('collectible_id', $collectibleId)
                ->where('status', 'selling')
                ->where('seller_id', '<>', $userId)
                ->order('price', 'asc')
                ->order('id', 'asc')
                ->limit($quantity)
                ->lock(true)
                ->select()
                ->toArray();

            if (!$listings) {
                Db::rollback();
                return $this->fail(1002, '暂无可购买的挂单');
            }

            $listingIds = [];
            $totalPrice = '0';
            $floorPrice = null;
            foreach ($listings as $l) {
                // 资产校验：仍属卖家且寄售中
                $uc = Db::name('user_collectibles')
                    ->where('id', $l['user_collectible_id'])
                    ->where('user_id', $l['seller_id'])
                    ->where('status', 'consigned')
                    ->lock(true)
                    ->find();
                if (!$uc) {
                    Db::rollback();
                    return $this->fail(3001, '部分藏品状态异常，请重试');
                }
                $listingIds[] = (int) $l['id'];
                $totalPrice = bcadd($totalPrice, (string) $l['price'], 2);
                if ($floorPrice === null) $floorPrice = (float) $l['price'];
            }

            $count = count($listingIds);
            if ($count < 1) {
                Db::rollback();
                return $this->fail(1002, '暂无可购买的挂单');
            }

            // 锁定所有选中挂单（置 sold 防止被其他买家重复下单）
            Db::name('resale_listings')
                ->whereIn('id', $listingIds)
                ->where('status', 'selling')
                ->update(['status' => 'sold', 'updated_at' => $now]);

            // 创建批量市场订单（source=market；batch_listing_ids 记录全部挂单）
            $orderNo = gen_order_no();
            Db::name('orders')->insert([
                'order_no'           => $orderNo,
                'user_id'            => $userId,
                'collectible_id'     => $collectibleId,
                'resale_listing_id'  => $listingIds[0],
                'batch_listing_ids'  => implode(',', $listingIds),
                'unit_price'         => $floorPrice,
                'quantity'           => $count,
                'total_price'        => $totalPrice,
                'status'             => 'pending',
                'source'             => 'market',
                'created_at'         => $now,
                'expires_at'         => date('Y-m-d H:i:s.v', time() + 300),
                'updated_at'         => $now,
            ]);

            Db::commit();
            return $this->success([
                'orderNo'    => $orderNo,
                'quantity'   => $count,
                'floorPrice' => (float) $floorPrice,
                'totalPrice' => (float) $totalPrice,
                'expiresAt'  => date('Y-m-d H:i:s.v', time() + 300),
            ]);
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '批量下单失败：' . $e->getMessage());
        }
    }

    /**
     * GET /api/resale/listings
     * 市场挂单池
     */
    public function pool()
    {
        $p             = $this->pagination();
        $collectibleId = $this->intParam('collectibleId');
        $priceMin      = $this->request->param('priceMin');
        $priceMax      = $this->request->param('priceMax');
        $sort          = $this->strParam('sort', 'price-asc');

        $query = Db::name('resale_listings')->alias('l')
            ->join('users u', 'u.id = l.seller_id')
            ->where('l.status', 'selling');
        if ($collectibleId > 0) $query->where('l.collectible_id', $collectibleId);
        if ($priceMin !== null && $priceMin !== '') $query->where('l.price', '>=', (float) $priceMin);
        if ($priceMax !== null && $priceMax !== '') $query->where('l.price', '<=', (float) $priceMax);

        switch ($sort) {
            case 'price-desc': $query->order('l.price', 'desc'); break;
            default:          $query->order('l.price', 'asc');
        }

        $total = (clone $query)->count();
        $list  = $query->limit($p['offset'], $p['pageSize'])->field([
            'l.id as listing_id', 'l.price', 'l.user_collectible_id', 'l.listed_at',
            'u.phone as seller_phone', 'uc.serial',
        ])->leftJoin('user_collectibles uc', 'uc.id = l.user_collectible_id')
            ->select()->toArray();

        $items = array_map(function ($l) {
            return [
                'listingId'    => (int) $l['listing_id'],
                'userCollectibleId' => (int) $l['user_collectible_id'],
                'no'           => $l['serial'] ?? '',
                'price'        => (float) $l['price'],
                'listedAt'     => $l['listed_at'],
                'sellerPhone'  => mask_phone($l['seller_phone']),
            ];
        }, $list);

        // 聚合：地板价 + 挂单数
        $floor    = Db::name('resale_listings')->where('status', 'selling')->min('price');
        $cnt      = Db::name('resale_listings')->where('status', 'selling')->count();

        return $this->success([
            'list'        => $items,
            'total'       => $total,
            'floorPrice'  => (float) ($floor ?? 0),
            'ordersCount' => $cnt,
            'page'        => $p['page'],
            'pageSize'    => $p['pageSize'],
            'lastPage'    => (int) ceil($total / max($p['pageSize'], 1)),
        ]);
    }

    /**
     * GET /api/resale/history?collectibleId=
     * 某藏品的成交动态（已售出的寄售挂单）
     */
    public function history()
    {
        $p             = $this->pagination();
        $collectibleId = $this->intParam('collectibleId');

        $query = Db::name('resale_listings')->alias('l')
            ->join('users u', 'u.id = l.seller_id', 'LEFT')
            ->join('user_collectibles uc', 'uc.id = l.user_collectible_id', 'LEFT')
            ->where('l.status', 'sold');
        if ($collectibleId > 0) {
            $query->where('l.collectible_id', $collectibleId);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('l.updated_at', 'desc')
            ->limit($p['offset'], $p['pageSize'])
            ->field([
                'l.id', 'l.price', 'l.updated_at',
                'u.username', 'u.phone',
                'uc.serial',
            ])
            ->select()->toArray();

        $items = array_map(function ($r) {
            return [
                'id'        => (int) $r['id'],
                'price'     => (float) $r['price'],
                'no'        => $r['serial'] ?? '',
                'fromUser'  => $r['username'] ? mask_name((string) $r['username']) : '匿名',
                'createdAt' => $r['updated_at'],
            ];
        }, $rows);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }

    /**
     * 批量购买开关与资格判定（batchBuyConfig 展示 / batchBuy 下单共用）
     *
     * 开关关闭或限度为 0 → 不可用；scope=specific 时校验手机号白名单
     *
     * @return array{ok: bool, limit: int, reason: string}
     */
    private function batchBuyAccess(int $userId): array
    {
        $enabled = (string) Db::name('system_configs')->where('config_key', 'batch_buy_enabled')->value('config_value');
        $scope   = (string) Db::name('system_configs')->where('config_key', 'batch_buy_scope')->value('config_value');
        $limit   = (int) Db::name('system_configs')->where('config_key', 'batch_buy_limit')->value('config_value');
        $users   = (string) Db::name('system_configs')->where('config_key', 'batch_buy_users')->value('config_value');

        if ($enabled !== '1' || $limit <= 0) {
            return ['ok' => false, 'limit' => 0, 'reason' => '批量购买未开启'];
        }
        if ($scope === 'specific') {
            $phone = Db::name('users')->where('id', $userId)->value('phone');
            $phoneSet = array_filter(array_map('trim', preg_split('/[\r\n]+/', $users)));
            if (!in_array($phone, $phoneSet, true)) {
                return ['ok' => false, 'limit' => 0, 'reason' => '您无批量购买权限'];
            }
        }
        return ['ok' => true, 'limit' => $limit, 'reason' => ''];
    }
}
