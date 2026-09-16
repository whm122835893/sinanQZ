<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 置换（C 端）
 * 对应 nft_swap_offers / nft_swap_records 表
 * 状态：1=挂单中 2=已接受 3=已拒绝 4=已撤销（本人撤销/管理端关闭） 5=已完成 6=已过期
 *
 * 链路：持有资产 → 发起置换挂单（锁定资产 consigned）→ 对方持目标藏品接受（双向过户 + 差价结算）
 *      → 生成 swap_records 流水（status=4 已完成）→ 管理端 /admin/swap 只读监督 + 强制关闭
 * 资产守恒：置换不改变 circulate/sold，仅改变资产归属（user_id）。
 */
class Swap extends BaseController
{
    /** 挂单有效期（天） */
    const EXPIRE_DAYS = 7;
    /** 单笔差价上限（元） */
    const MAX_CASH_DIFF = 100000;

    /**
     * GET /api/swap-offers?collectibleId=&page=&pageSize=
     * 置换挂单池（公开，仅挂单中且未过期）
     */
    public function list()
    {
        $this->expireStale();

        $p             = $this->pagination();
        $collectibleId = $this->intParam('collectibleId');

        $query = Db::name('swap_offers')->alias('so')
            ->join('users u', 'u.id = so.offer_user_id', 'LEFT')
            ->join('collectibles co', 'co.id = so.offer_collectible_id', 'LEFT')
            ->join('collectibles ct', 'ct.id = so.target_collectible_id', 'LEFT')
            ->where('so.status', 1)
            ->whereNull('so.deleted_at');

        if ($collectibleId > 0) {
            $query->where(function ($q) use ($collectibleId) {
                $q->whereOr('so.offer_collectible_id', $collectibleId)
                  ->whereOr('so.target_collectible_id', $collectibleId);
            });
        }

        $total = (clone $query)->count();
        $rows  = $query->order('so.id', 'desc')
            ->limit($p['offset'], $p['pageSize'])
            ->field([
                'so.id', 'so.offer_user_id', 'so.offer_collectible_id', 'so.offer_serial',
                'so.target_collectible_id', 'so.cash_diff', 'so.status', 'so.expires_at',
                'so.remark', 'so.created_at',
                'u.username',
                'co.name AS offer_name', 'co.image AS offer_image',
                'ct.name AS target_name', 'ct.image AS target_image',
            ])
            ->select()->toArray();

        $items = array_map(function ($r) {
            return [
                'id'                 => (int) $r['id'],
                'offerUserId'        => (int) $r['offer_user_id'],
                'offerUserName'     => $r['username'] ? mask_name((string) $r['username']) : '匿名用户',
                'offerCollectibleId' => (int) $r['offer_collectible_id'],
                'offerName'          => $r['offer_name'] ?? '',
                'offerImage'         => $r['offer_image'] ?? '',
                'offerSerial'        => $r['offer_serial'],
                'targetCollectibleId'=> (int) $r['target_collectible_id'],
                'targetName'         => $r['target_name'] ?? '',
                'targetImage'        => $r['target_image'] ?? '',
                'cashDiff'           => (float) $r['cash_diff'],
                'status'             => (int) $r['status'],
                'remark'             => $r['remark'] ?? '',
                'expiresAt'          => $r['expires_at'],
                'createdAt'          => $r['created_at'],
            ];
        }, $rows);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }

    /**
     * GET /api/swap-offers/mine
     * 我的置换挂单（全部状态）
     */
    public function mine()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $this->expireStale();

        $p     = $this->pagination();
        $query = Db::name('swap_offers')->alias('so')
            ->join('collectibles co', 'co.id = so.offer_collectible_id', 'LEFT')
            ->join('collectibles ct', 'ct.id = so.target_collectible_id', 'LEFT')
            ->where('so.offer_user_id', $userId)
            ->whereNull('so.deleted_at');

        $total = (clone $query)->count();
        $rows  = $query->order('so.id', 'desc')
            ->limit($p['offset'], $p['pageSize'])
            ->field(['so.*', 'co.name AS offer_name', 'co.image AS offer_image',
                     'ct.name AS target_name', 'ct.image AS target_image'])
            ->select()->toArray();

        $items = array_map(function ($r) {
            return [
                'id'                 => (int) $r['id'],
                'offerCollectibleId' => (int) $r['offer_collectible_id'],
                'offerName'          => $r['offer_name'] ?? '',
                'offerImage'         => $r['offer_image'] ?? '',
                'offerSerial'        => $r['offer_serial'],
                'targetCollectibleId'=> (int) $r['target_collectible_id'],
                'targetName'         => $r['target_name'] ?? '',
                'targetImage'        => $r['target_image'] ?? '',
                'cashDiff'           => (float) $r['cash_diff'],
                'status'             => (int) $r['status'],
                'remark'             => $r['remark'] ?? '',
                'expiresAt'          => $r['expires_at'],
                'createdAt'          => $r['created_at'],
            ];
        }, $rows);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }

    /**
     * POST /api/swap-offers
     * 发起置换挂单：{userCollectibleId, targetCollectibleId, cashDiff, remark, paymentPassword}
     * cashDiff 语义：>0 发起人愿意补贴对方差价；<0 要求对方补贴差价；0 纯换
     */
    public function create()
    {
        $userId           = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $userCollectibleId = $this->intParam('userCollectibleId');
        $targetCollectibleId = $this->intParam('targetCollectibleId');
        $cashDiff          = round((float) $this->request->post('cashDiff', 0), 2);
        $remark            = mb_substr((string) $this->request->post('remark', ''), 0, 200);
        $paymentPassword   = (string) $this->request->post('paymentPassword', '');

        if ($userCollectibleId <= 0) return $this->fail(1001, '请选择要置换出的资产');
        if ($targetCollectibleId <= 0) return $this->fail(1001, '请选择目标藏品');
        if (abs($cashDiff) > self::MAX_CASH_DIFF) return $this->fail(1001, '差价金额超出限制');

        $hash = Db::name('users')->where('id', $userId)->value('transaction_password');
        if (!$hash || !verify_password($paymentPassword, $hash)) {
            return $this->fail(2003, '交易密码错误');
        }

        Db::startTrans();
        try {
            // 锁定资产：必须持有且为 held（防与寄售/转赠/分解/开盒并发）
            $uc = Db::name('user_collectibles')
                ->where('id', $userCollectibleId)
                ->where('user_id', $userId)
                ->where('status', 'held')
                ->lock(true)
                ->find();
            if (!$uc) {
                Db::rollback();
                return $this->fail(1001, '资产不存在或状态不可置换');
            }
            if ((int) $uc['collectible_id'] === $targetCollectibleId) {
                Db::rollback();
                return $this->fail(1001, '不能置换相同藏品');
            }

            // 同一资产仅允许一个挂单中的置换
            $dup = Db::name('swap_offers')
                ->where('offer_user_id', $userId)
                ->where('offer_serial', $uc['serial'])
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->lock(true)
                ->find();
            if ($dup) {
                Db::rollback();
                return $this->fail(1001, '该资产已有进行中的置换挂单');
            }

            // 目标藏品存在性校验（过滤软删除）
            $target = Db::name('collectibles')
                ->where('id', $targetCollectibleId)
                ->whereNull('deleted_at')
                ->find();
            if (!$target) {
                Db::rollback();
                return $this->fail(1001, '目标藏品不存在');
            }

            $now       = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', time() + self::EXPIRE_DAYS * 86400);

            $id = Db::name('swap_offers')->insertGetId([
                'offer_user_id'        => $userId,
                'offer_collectible_id' => (int) $uc['collectible_id'],
                'offer_serial'         => $uc['serial'],
                'target_collectible_id'=> $targetCollectibleId,
                'cash_diff'            => $cashDiff,
                'status'               => 1,
                'expires_at'            => $expiresAt,
                'remark'               => $remark,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);

            // 锁定资产（与寄售同语义：consigned + is_consigned=1）
            Db::name('user_collectibles')->where('id', $userCollectibleId)->update([
                'status'       => 'consigned',
                'is_consigned' => 1,
                'updated_at'   => $now,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '发起置换失败：' . $e->getMessage());
        }

        return $this->success([
            'id'                  => (int) $id,
            'offerCollectibleId'  => (int) $uc['collectible_id'],
            'offerSerial'         => $uc['serial'],
            'targetCollectibleId' => $targetCollectibleId,
            'cashDiff'            => $cashDiff,
            'status'              => 1,
            'expiresAt'           => $expiresAt,
        ]);
    }

    /**
     * POST /api/swap-offers/:id/accept
     * 接受置换：{userCollectibleId, paymentPassword}
     * 接受方须持有目标藏品（任意 held 资产行），双方资产原子互换 + 差价结算 + 落 swap_records
     */
    public function accept()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $id               = $this->intParam('id');
        $userCollectibleId = $this->intParam('userCollectibleId');
        $paymentPassword  = (string) $this->request->post('paymentPassword', '');

        if ($id <= 0) return $this->fail(1001, '置换单不存在');

        $hash = Db::name('users')->where('id', $userId)->value('transaction_password');
        if (!$hash || !verify_password($paymentPassword, $hash)) {
            return $this->fail(2003, '交易密码错误');
        }

        Db::startTrans();
        try {
            $offer = Db::name('swap_offers')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->lock(true)
                ->find();
            if (!$offer) {
                Db::rollback();
                return $this->fail(1001, '置换单不存在');
            }
            if ((int) $offer['status'] !== 1) {
                Db::rollback();
                return $this->fail(1001, '该置换单已不在挂单中');
            }
            if (!empty($offer['expires_at']) && strtotime((string) $offer['expires_at']) < time()) {
                Db::name('swap_offers')->where('id', $id)->update([
                    'status' => 6, 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $this->releaseAsset((string) $offer['offer_serial'], (int) $offer['offer_user_id']);
                Db::commit();
                return $this->fail(1001, '该置换单已过期');
            }
            if ((int) $offer['offer_user_id'] === (int) $userId) {
                Db::rollback();
                return $this->fail(1001, '不能接受自己发起的置换');
            }

            // 发起方资产仍须锁定有效（挂单资产 consigned 且 serial 匹配）
            $offerAsset = Db::name('user_collectibles')
                ->where('serial', $offer['offer_serial'])
                ->where('user_id', $offer['offer_user_id'])
                ->where('status', 'consigned')
                ->lock(true)
                ->find();
            if (!$offerAsset) {
                Db::rollback();
                return $this->fail(3001, '发起方资产状态异常，置换失败');
            }

            // 接受方资产：须持有目标藏品的 held 资产
            $acceptAsset = null;
            if ($userCollectibleId > 0) {
                $acceptAsset = Db::name('user_collectibles')
                    ->where('id', $userCollectibleId)
                    ->where('user_id', $userId)
                    ->where('status', 'held')
                    ->lock(true)
                    ->find();
            }
            if (!$acceptAsset) {
                $acceptAsset = Db::name('user_collectibles')
                    ->where('user_id', $userId)
                    ->where('collectible_id', $offer['target_collectible_id'])
                    ->where('status', 'held')
                    ->order('id', 'asc')
                    ->lock(true)
                    ->find();
            }
            if (!$acceptAsset || (int) $acceptAsset['collectible_id'] !== (int) $offer['target_collectible_id']) {
                Db::rollback();
                return $this->fail(1001, '您未持有该目标藏品，无法接受置换');
            }

            $now      = date('Y-m-d H:i:s');
            $cashDiff = (float) $offer['cash_diff'];

            // 差价结算：>0 发起人补贴接受方；<0 接受方补贴发起方
            if (abs($cashDiff) > 0.001) {
                if ($cashDiff > 0) {
                    $payerId = (int) $offer['offer_user_id'];
                    $payeeId = (int) $userId;
                } else {
                    $payerId = (int) $userId;
                    $payeeId = (int) $offer['offer_user_id'];
                }
                $amount = abs($cashDiff);

                $payerWallet = Db::name('wallets')->where('user_id', $payerId)->lock(true)->find();
                if (!$payerWallet || (float) $payerWallet['available'] < $amount) {
                    Db::rollback();
                    return $this->fail(1001, '差价支付方余额不足，置换失败');
                }
                $payeeWallet = Db::name('wallets')->where('user_id', $payeeId)->lock(true)->find();

                Db::name('wallets')->where('user_id', $payerId)->update([
                    'balance'    => Db::raw("balance - {$amount}"),
                    'available'  => Db::raw("available - {$amount}"),
                    'updated_at' => $now,
                ]);
                Db::name('wallet_transactions')->insert([
                    'user_id'       => $payerId,
                    'trans_type'    => 'buy',
                    'title'         => '置换差价支出',
                    'direction'     => 2,
                    'amount'        => $amount,
                    'balance_after' => (float) $payerWallet['available'] - $amount,
                    'biz_no'        => 'SWAP-' . $id,
                    'created_at'    => $now,
                ]);

                if ($payeeWallet) {
                    Db::name('wallets')->where('user_id', $payeeId)->update([
                        'balance'    => Db::raw("balance + {$amount}"),
                        'available'  => Db::raw("available + {$amount}"),
                        'updated_at' => $now,
                    ]);
                    Db::name('wallet_transactions')->insert([
                        'user_id'       => $payeeId,
                        'trans_type'    => 'reward',
                        'title'         => '置换差价收入',
                        'direction'     => 1,
                        'amount'        => $amount,
                        'balance_after' => (float) $payeeWallet['available'] + $amount,
                        'biz_no'        => 'SWAP-' . $id,
                        'created_at'    => $now,
                    ]);
                }
            }

            // 双向过户（条件更新，防并发篡改）
            $toAccept = Db::name('user_collectibles')
                ->where('id', $offerAsset['id'])
                ->where('user_id', $offer['offer_user_id'])
                ->where('status', 'consigned')
                ->update([
                    'user_id'       => $userId,
                    'status'        => 'held',
                    'source'        => 'transfer',
                    'is_consigned'  => 0,
                    'acquired_at'   => $now,
                    'acquired_price'=> $cashDiff > 0 ? $cashDiff : 0,
                    'updated_at'    => $now,
                ]);
            $toOffer = Db::name('user_collectibles')
                ->where('id', $acceptAsset['id'])
                ->where('user_id', $userId)
                ->where('status', 'held')
                ->update([
                    'user_id'       => $offer['offer_user_id'],
                    'status'        => 'held',
                    'source'        => 'transfer',
                    'is_consigned'  => 0,
                    'acquired_at'   => $now,
                    'acquired_price'=> $cashDiff < 0 ? abs($cashDiff) : 0,
                    'updated_at'    => $now,
                ]);
            if (!$toAccept || !$toOffer) {
                Db::rollback();
                return $this->fail(3001, '资产状态异常，置换失败');
            }

            // 置换单闭环：status=5 已完成
            Db::name('swap_offers')->where('id', $id)->update([
                'status'       => 5,
                'accepted_by'  => $userId,
                'accepted_at'   => $now,
                'updated_at'   => $now,
            ]);

            // 完成流水（status=4 已完成，与 z 审计口径一致）
            Db::name('swap_records')->insert([
                'offer_id'             => $id,
                'offer_user_id'        => (int) $offer['offer_user_id'],
                'accept_user_id'       => $userId,
                'offer_collectible_id' => (int) $offer['offer_collectible_id'],
                'accept_collectible_id'=> (int) $acceptAsset['collectible_id'],
                'cash_diff'            => $cashDiff,
                'status'               => 4,
                'created_at'           => $now,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '接受置换失败：' . $e->getMessage());
        }

        return $this->success([
            'id'             => (int) $id,
            'status'         => 5,
            'offerSerial'    => $offer['offer_serial'],
            'acceptSerial'   => $acceptAsset['serial'],
        ]);
    }

    /**
     * POST /api/swap-offers/:id/cancel
     * 发起人撤销挂单（status 1→4，资产退回 held）
     */
    public function cancel()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        $id = $this->intParam('id');
        if ($id <= 0) return $this->fail(1001, '置换单不存在');

        Db::startTrans();
        try {
            $offer = Db::name('swap_offers')
                ->where('id', $id)
                ->where('offer_user_id', $userId)
                ->whereNull('deleted_at')
                ->lock(true)
                ->find();
            if (!$offer) {
                Db::rollback();
                return $this->fail(1002, '置换单不存在');
            }
            if ((int) $offer['status'] !== 1) {
                Db::rollback();
                return $this->fail(1001, '该置换单已不在挂单中，无法撤销');
            }

            Db::name('swap_offers')->where('id', $id)->update([
                'status'     => 4,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->releaseAsset((string) $offer['offer_serial'], (int) $offer['offer_user_id']);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '撤销失败：' . $e->getMessage());
        }

        return $this->success(['id' => (int) $id, 'status' => 4]);
    }

    /**
     * 懒过期：把已到期的挂单置 6 并释放资产（调用方自行决定是否在事务内）
     */
    private function expireStale(): void
    {
        try {
            $stale = Db::name('swap_offers')
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->where('expires_at', '<', date('Y-m-d H:i:s'))
                ->lock(true)
                ->select()->toArray();
            foreach ($stale as $o) {
                Db::name('swap_offers')->where('id', $o['id'])->update(['status' => 6]);
                $this->releaseAsset((string) $o['offer_serial'], (int) $o['offer_user_id']);
            }
        } catch (\Throwable $e) {
            // 懒过期失败不影响主流程
        }
    }

    /**
     * 释放挂单资产（退回 held）；条件更新防误放他人资产
     */
    private function releaseAsset(string $serial, int $ownerId): void
    {
        Db::name('user_collectibles')
            ->where('serial', $serial)
            ->where('user_id', $ownerId)
            ->where('status', 'consigned')
            ->update([
                'status'       => 'held',
                'is_consigned' => 0,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
    }
}
