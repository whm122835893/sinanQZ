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

            // 接单者需持有 target 藏品
            $uc = Db::name('user_collectibles')
                ->where('user_id', $userId)
                ->where('collectible_id', $offer['target_collectible_id'])
                ->where('status', 'held')
                ->find();
            if (!$uc) {
                Db::rollback();
                return $this->fail(1001, '您未持有目标藏品，无法接受置换');
            }

            Db::name('swap_offers')->where('id', $id)->update([
                'status'      => 2,
                'accepted_by' => $userId,
                'accepted_at' => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '接受置换失败：' . $e->getMessage());
        }

        return $this->success(['id' => (int) $id, 'status' => 2]);
    }
}
