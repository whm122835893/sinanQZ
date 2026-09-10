<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 置换挂单（C 端）
 * 对应 nft_swap_offers 表；状态：1=置换中 2=已接受 3=已取消 4=已完成 5=已过期
 */
class Swap extends BaseController
{
    /**
     * GET /api/swap-offers?status=1
     * 置换挂单池（仅置换中 status=1）
     */
    public function list()
    {
        $p = $this->pagination();

        $query = Db::name('swap_offers')->alias('s')
            ->join('users ou', 'ou.id = s.offer_user_id', 'LEFT')
            ->join('collectibles oc', 'oc.id = s.offer_collectible_id', 'LEFT')
            ->join('collectibles tc', 'tc.id = s.target_collectible_id', 'LEFT')
            ->where('s.status', 1)
            ->whereNull('s.deleted_at');

        $total = (clone $query)->count();
        $rows  = $query->order('s.id', 'desc')
            ->limit($p['offset'], $p['pageSize'])
            ->field([
                's.id', 's.offer_user_id', 's.offer_collectible_id', 's.offer_serial',
                's.target_collectible_id', 's.cash_diff', 's.remark', 's.created_at',
                'ou.username',
                'oc.name as offer_collectible_name', 'oc.image as offer_collectible_image',
                'tc.name as target_collectible_name', 'tc.image as target_collectible_image',
            ])
            ->select()->toArray();

        $items = array_map(function ($r) {
            return [
                'id'                    => (int) $r['id'],
                'offerUserName'         => $r['username'] ? mask_name((string) $r['username']) : '置换方',
                'offerCollectibleName'  => $r['offer_collectible_name'] ?? '藏品',
                'offerCollectibleImage' => $r['offer_collectible_image'] ?? '',
                'offerSerial'           => $r['offer_serial'] ?? '',
                'targetCollectibleName' => $r['target_collectible_name'] ?? '期望藏品',
                'targetCollectibleImage'=> $r['target_collectible_image'] ?? '',
                'cashDiff'              => (float) $r['cash_diff'],
                'remark'                => $r['remark'] ?? '',
                'createdAt'             => $r['created_at'],
            ];
        }, $rows);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }

    /**
     * POST /api/swap-offers
     * 发布置换挂单（需登录，需持有 offer 藏品）
     */
    public function create()
    {
        $userId = $this->userId();
        if (!$userId) {
            return $this->fail(2001, '未登录');
        }

        $offerCollectibleId = $this->intParam('offerCollectibleId');
        $offerSerial        = $this->strParam('offerSerial', '');
        $targetCollectibleId = $this->intParam('targetCollectibleId');
        $cashDiff           = (float) $this->request->post('cashDiff', 0);
        $remark             = $this->strParam('remark', '');

        if ($offerCollectibleId <= 0 || $targetCollectibleId <= 0) {
            return $this->fail(1001, '请选择置换藏品');
        }

        // 校验用户持有 offer 藏品
        $uc = Db::name('user_collectibles')
            ->where('user_id', $userId)
            ->where('collectible_id', $offerCollectibleId)
            ->where('status', 'held')
            ->find();
        if (!$uc) {
            return $this->fail(1001, '您未持有该藏品，无法发布置换');
        }

        $expiresAt = date('Y-m-d H:i:s', time() + 7 * 86400);

        $id = Db::name('swap_offers')->insertGetId([
            'offer_user_id'        => $userId,
            'offer_collectible_id' => $offerCollectibleId,
            'offer_serial'         => $offerSerial,
            'target_collectible_id'=> $targetCollectibleId,
            'cash_diff'            => $cashDiff,
            'status'               => 1,
            'remark'               => $remark,
            'expires_at'           => $expiresAt,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        return $this->success([
            'id'            => (int) $id,
            'status'        => 1,
            'expiresAt'     => $expiresAt,
        ]);
    }

    /**
     * POST /api/swap-offers/:id/accept
     * 接受置换（需登录，需持有 target 藏品）
     * S06 修复：原实现仅置状态为已接受，无持仓过户、无完成记录、状态4永不可达；
     * 现为原子完成——差价结算 + 双向过户 + 写 swap_records + 置换单完成
     */
    public function accept()
    {
        $userId = $this->userId();
        if (!$userId) {
            return $this->fail(2001, '未登录');
        }

        $id = $this->intParam('id');
        if ($id <= 0) {
            return $this->fail(1001, '置换单不存在');
        }

        Db::startTrans();
        try {
            $offer = Db::name('swap_offers')->where('id', $id)->lock(true)->find();
            if (!$offer || $offer['status'] != 1) {
                Db::rollback();
                return $this->fail(1001, '置换单不存在或已被接受');
            }
            if ((int) $offer['offer_user_id'] === (int) $userId) {
                Db::rollback();
                return $this->fail(1001, '不能接受自己发布的置换');
            }

            // 过期即关闭
            if ($offer['expires_at'] && strtotime($offer['expires_at']) < time()) {
                Db::name('swap_offers')->where('id', $id)->update(['status' => 6, 'updated_at' => date('Y-m-d H:i:s')]);
                Db::commit();
                return $this->fail(1001, '置换单已过期');
            }

            // 接单者需持有 target 藏品（锁定具体资产行，防并发转赠/寄售）
            $accepterAsset = Db::name('user_collectibles')
                ->where('user_id', $userId)
                ->where('collectible_id', $offer['target_collectible_id'])
                ->where('status', 'held')
                ->lock(true)
                ->find();
            if (!$accepterAsset) {
                Db::rollback();
                return $this->fail(1001, '您未持有目标藏品，无法接受置换');
            }

            // 发起方仍需持有 offer 藏品（挂单期间可能已消耗/转出/寄售）
            $offerAssetQuery = Db::name('user_collectibles')
                ->where('user_id', $offer['offer_user_id'])
                ->where('collectible_id', $offer['offer_collectible_id'])
                ->where('status', 'held');
            if (!empty($offer['offer_serial'])) {
                $offerAssetQuery->where('serial', $offer['offer_serial']);
            }
            $offerAsset = $offerAssetQuery->lock(true)->find();
            if (!$offerAsset) {
                Db::rollback();
                return $this->fail(1001, '置换方已不持有置换藏品，无法完成置换');
            }

            $now = date('Y-m-d H:i:s.v');

            // 差价结算：cash_diff > 0 发起方补钱给接单方；< 0 接单方补给发起方
            $cashDiff = (float) $offer['cash_diff'];
            if (abs($cashDiff) > 0.001) {
                $payerId   = $cashDiff > 0 ? (int) $offer['offer_user_id'] : (int) $userId;
                $payeeId   = $cashDiff > 0 ? (int) $userId : (int) $offer['offer_user_id'];
                $payAmount = round(abs($cashDiff), 2);

                $payerWallet = Db::name('wallets')->where('user_id', $payerId)->lock(true)->find();
                if (!$payerWallet || (float) $payerWallet['available'] < $payAmount) {
                    Db::rollback();
                    return $this->fail(1001, '补差价方余额不足，无法完成置换');
                }
                Db::name('wallets')->where('user_id', $payerId)->update([
                    'balance'    => Db::raw("balance - {$payAmount}"),
                    'available'  => Db::raw("available - {$payAmount}"),
                    'updated_at' => $now,
                ]);
                Db::name('wallet_transactions')->insert([
                    'user_id'       => $payerId,
                    'trans_type'    => 'buy',
                    'title'         => '置换补差价',
                    'direction'     => 2,
                    'amount'        => $payAmount,
                    'balance_after' => (float) $payerWallet['available'] - $payAmount,
                    'biz_no'        => 'SWAP-' . $id,
                    'created_at'    => $now,
                ]);

                $payeeWallet = Db::name('wallets')->where('user_id', $payeeId)->lock(true)->find();
                Db::name('wallets')->where('user_id', $payeeId)->update([
                    'balance'    => Db::raw("balance + {$payAmount}"),
                    'available'  => Db::raw("available + {$payAmount}"),
                    'updated_at' => $now,
                ]);
                Db::name('wallet_transactions')->insert([
                    'user_id'       => $payeeId,
                    'trans_type'    => 'reward',
                    'title'         => '置换收到差价',
                    'direction'     => 1,
                    'amount'        => $payAmount,
                    'balance_after' => (float) $payeeWallet['balance'] + $payAmount,
                    'biz_no'        => 'SWAP-' . $id,
                    'created_at'    => $now,
                ]);
            }

            // 双向过户（条件更新：仅当资产仍属原主且为 held，防状态机跳变）
            $movedA = Db::name('user_collectibles')
                ->where('id', $offerAsset['id'])
                ->where('user_id', $offer['offer_user_id'])
                ->where('status', 'held')
                ->update([
                    'user_id'        => $userId,
                    'status'         => 'held',
                    'source'         => 'transfer',
                    'acquired_at'    => $now,
                    'acquired_price' => 0,
                    'is_consigned'   => 0,
                    'updated_at'     => $now,
                ]);
            $movedB = Db::name('user_collectibles')
                ->where('id', $accepterAsset['id'])
                ->where('user_id', $userId)
                ->where('status', 'held')
                ->update([
                    'user_id'        => $offer['offer_user_id'],
                    'status'         => 'held',
                    'source'         => 'transfer',
                    'acquired_at'    => $now,
                    'acquired_price' => 0,
                    'is_consigned'   => 0,
                    'updated_at'     => $now,
                ]);
            if (!$movedA || !$movedB) {
                Db::rollback();
                return $this->fail(3001, '藏品状态异常，置换失败');
            }

            // 置换单置为已完成（原实现停在"已接受"）
            Db::name('swap_offers')->where('id', $id)->update([
                'status'      => 5,
                'accepted_by' => $userId,
                'accepted_at' => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);

            // 写置换完成记录（此前全链路无写入点，admin/swap/records 永远为空）
            Db::name('swap_records')->insert([
                'offer_id'              => $id,
                'offer_user_id'         => $offer['offer_user_id'],
                'accept_user_id'        => $userId,
                'offer_collectible_id'  => $offer['offer_collectible_id'],
                'accept_collectible_id' => $offer['target_collectible_id'],
                'cash_diff'             => $offer['cash_diff'],
                'status'                => 4,
                'created_at'            => date('Y-m-d H:i:s'),
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '接受置换失败：' . $e->getMessage());
        }

        return $this->success(['id' => (int) $id, 'status' => 5]);
    }
}
