<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 求购挂单管理（admin/buy-request:*）
 *
 * C 端入口：Resale.vue「求购」tab
 */
class BuyRequestController extends BaseController
{
    /**
     * GET /admin/buy-request/list
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('buy_requests')->alias('br')->whereNull('br.deleted_at')
            ->join('collectibles c', 'c.id = br.collectible_id', 'LEFT')
            ->join('users u', 'u.id = br.user_id', 'LEFT')
            ->field('br.*, c.name AS collectible_name, c.image AS collectible_image,
                     u.username, u.phone AS user_phone');

        $status = $this->enumParam('status', ['1','2','3','4','5']);
        if ($status !== null) $query->where('br.status', (int) $status);
        $collectibleId = $this->positiveInt('collectibleId');
        if ($collectibleId !== null) $query->where('br.collectible_id', $collectibleId);
        $userId = $this->positiveInt('userId');
        if ($userId !== null) $query->where('br.user_id', $userId);

        $total = (clone $query)->count();
        $rows = $query->order('br.id', 'desc')->page($page, $pageSize)->select()->toArray();

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * POST /admin/buy-request/:id/close  强制关闭
     */
    public function close()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        Db::name('buy_requests')->where('id', $id)->update(['status' => 3]);
        $this->audit('求购挂单', 'close', '强制关闭', ['id' => $id]);
        return $this->success(['id' => $id]);
    }

    /**
     * DELETE /admin/buy-request/:id  软删除
     */
    public function delete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        Db::name('buy_requests')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->audit('求购挂单', 'delete', '删除', ['id' => $id]);
        return $this->success(['id' => $id]);
    }
}
