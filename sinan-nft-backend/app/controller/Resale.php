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
            $existing = Db::name('resale_listings')
                ->where('user_collectible_id', $userCollectibleId)
                ->where('status', 'cancelled')
                ->order('id', 'desc')
                ->find();
            if ($existing && strtotime($existing['cooldown_until']) > time()) {
                Db::rollback();
                return $this->fail(1001, '寄售冷却中，请稍后再试');
            }

            $collectible = Db::name('collectibles')->where('id', $uc['collectible_id'])->find();
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

        $total = $query->count();
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

        $total = $query->count();
        $list  = $query->limit($p['offset'], $p['pageSize'])->field([
            'l.id as listing_id', 'l.price', 'l.user_collectible_id', 'l.listed_at',
            'u.phone as seller_phone',
        ])->select()->toArray();

        $items = array_map(function ($l) {
            return [
                'listingId'    => (int) $l['listing_id'],
                'userCollectibleId' => (int) $l['user_collectible_id'],
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
}
