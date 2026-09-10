<?php
declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\facade\Db;

/**
 * 求购挂单（C 端）
 * 对应 nft_buy_requests 表；状态：1=求购中 2=已接单 3=已关闭 4=已成交 5=已过期
 */
class BuyRequest extends BaseController
{
    /**
     * GET /api/buy-requests?collectibleId=
     * 某藏品的求购挂单列表（仅求购中 status=1）
     */
    public function list()
    {
        $p             = $this->pagination();
        $collectibleId = $this->intParam('collectibleId');

        $query = Db::name('buy_requests')->alias('br')
            ->join('users u', 'u.id = br.user_id', 'LEFT')
            ->where('br.status', 1)
            ->whereNull('br.deleted_at');
        if ($collectibleId > 0) {
            $query->where('br.collectible_id', $collectibleId);
        }

        $total = (clone $query)->count();
        $rows  = $query->order('br.price', 'desc')
            ->order('br.id', 'desc')
            ->limit($p['offset'], $p['pageSize'])
            ->field([
                'br.id', 'br.collectible_id', 'br.user_id', 'br.price',
                'br.quantity', 'br.status', 'br.remark', 'br.expires_at',
                'br.created_at',
                'u.username', 'u.phone',
            ])
            ->select()->toArray();

        $items = array_map(function ($r) {
            return [
                'id'            => (int) $r['id'],
                'collectibleId' => (int) $r['collectible_id'],
                'userId'        => (int) $r['user_id'],
                'userName'      => $r['username'] ? mask_name((string) $r['username']) : '匿名用户',
                'price'         => (float) $r['price'],
                'quantity'      => (int) $r['quantity'],
                'status'        => (int) $r['status'],
                'remark'        => $r['remark'] ?? '',
                'expiresAt'     => $r['expires_at'],
                'createdAt'     => $r['created_at'],
            ];
        }, $rows);

        return $this->paginate($items, $total, $p['page'], $p['pageSize']);
    }

    /**
     * POST /api/buy-requests
     * 发布求购挂单（需登录）
     */
    public function create()
    {
        $userId = $this->userId();
        if (!$userId) {
            return $this->fail(2001, '未登录');
        }

        $collectibleId = $this->intParam('collectibleId');
        $price         = (float) $this->request->post('price', 0);
        $quantity      = max(1, $this->intParam('quantity', 1));
        $remark        = $this->strParam('remark', '');

        if ($collectibleId <= 0) {
            return $this->fail(1001, '请选择求购藏品');
        }
        if ($price <= 0) {
            return $this->fail(1001, '求购单价必须大于 0');
        }

        $collectible = Db::name('collectibles')->where('id', $collectibleId)->find();
        if (!$collectible) {
            return $this->fail(1001, '藏品不存在');
        }

        $expiresAt = date('Y-m-d H:i:s', time() + 7 * 86400);

        $id = Db::name('buy_requests')->insertGetId([
            'collectible_id' => $collectibleId,
            'user_id'        => $userId,
            'price'          => $price,
            'quantity'       => $quantity,
            'status'         => 1,
            'remark'         => $remark,
            'expires_at'     => $expiresAt,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return $this->success([
            'id'            => (int) $id,
            'collectibleId' => $collectibleId,
            'price'         => $price,
            'quantity'      => $quantity,
            'status'        => 1,
            'expiresAt'     => $expiresAt,
        ]);
    }

    /**
     * POST /api/buy-requests/:id/accept
     * 卖家接单（需登录，需持有该藏品）
     */
    public function accept()
    {
        $userId = $this->userId();
        if (!$userId) {
            return $this->fail(2001, '未登录');
        }

        $id = $this->intParam('id');
        if ($id <= 0) {
            return $this->fail(1001, '求购单不存在');
        }

        Db::startTrans();
        try {
            $br = Db::name('buy_requests')->where('id', $id)->lock(true)->find();
            if (!$br || $br['status'] != 1) {
                Db::rollback();
                return $this->fail(1001, '求购单不存在或已被接单');
            }

            // 卖家需持有该藏品（status=held）
            $uc = Db::name('user_collectibles')
                ->where('user_id', $userId)
                ->where('collectible_id', $br['collectible_id'])
                ->where('status', 'held')
                ->find();
            if (!$uc) {
                Db::rollback();
                return $this->fail(1001, '您未持有该藏品，无法接单');
            }

            // 更新求购单状态为已接单
            Db::name('buy_requests')->where('id', $id)->update([
                'status'       => 2,
                'accepted_by'  => $userId,
                'accepted_at'  => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '接单失败：' . $e->getMessage());
        }

        return $this->success([
            'id'       => (int) $id,
            'status'   => 2,
            'no'       => (string) ($uc['serial'] ?? ''),
        ]);
    }
}
