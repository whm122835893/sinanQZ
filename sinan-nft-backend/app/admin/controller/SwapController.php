<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 置换管理（admin/swap:*）
 *
 * C 端入口：Activity.vue「置换」tab
 */
class SwapController extends BaseController
{
    /**
     * GET /admin/swap/list
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('swap_offers')->alias('so')->whereNull('so.deleted_at')
            ->join('users uo', 'uo.id = so.offer_user_id', 'LEFT')
            ->join('collectibles co', 'co.id = so.offer_collectible_id', 'LEFT')
            ->join('collectibles ct', 'ct.id = so.target_collectible_id', 'LEFT')
            ->field('so.*, uo.username AS offer_user_name,
                     co.name AS offer_collectible_name, co.image AS offer_collectible_image,
                     ct.name AS target_collectible_name, ct.image AS target_collectible_image');

        $status = $this->enumParam('status', ['1','2','3','4','5','6']);
        if ($status !== null) $query->where('so.status', (int) $status);

        $total = (clone $query)->count();
        $rows = $query->order('so.id', 'desc')->page($page, $pageSize)->select()->toArray();

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * POST /admin/swap/:id/close
     */
    public function close()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        Db::name('swap_offers')->where('id', $id)->update(['status' => 4]);
        $this->audit('置换', 'close', '强制关闭', ['id' => $id]);
        return $this->success(['id' => $id]);
    }

    /**
     * GET /admin/swap/records  置换完成流水
     */
    public function records()
    {
        [$page, $pageSize] = $this->pageParams();
        $query = Db::name('swap_records')->order('id', 'desc');
        $total = (clone $query)->count();
        $rows = $query->page($page, $pageSize)->select()->toArray();
        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    public function delete()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);
        Db::name('swap_offers')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->audit('置换', 'delete', '删除', ['id' => $id]);
        return $this->success(['id' => $id]);
    }
}
