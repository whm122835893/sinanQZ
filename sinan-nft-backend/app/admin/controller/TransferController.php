<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 管理后台转赠管理控制器
 *
 * 覆盖：转赠列表（双向检索）、撤销待处理转赠（资产解冻回持有方）。
 */
class TransferController extends BaseController
{
    /**
     * GET /admin/transfer/list
     * 筛选：keyword（转出/接收方）、collectibleId、status、serial
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('transfers')->alias('t');

        $keyword = trim((string) $this->request->param('keyword', ''));
        if ($keyword !== '') {
            $query->whereExists(function ($q) use ($keyword) {
                $q->name('users')->whereRaw('(nft_users.id = t.from_user_id OR nft_users.id = t.to_user_id)')
                  ->where(function ($q2) use ($keyword) {
                      $q2->whereLike('nft_users.phone', "%{$keyword}%")
                         ->whereOr('nft_users.uid', $keyword);
                  });
            });
        }
        $collectibleId = $this->positiveInt('collectibleId');
        if ($collectibleId !== null) {
            $query->where('t.collectible_id', $collectibleId);
        }
        $status = (string) $this->request->param('status', '');
        if ($status !== '' && in_array($status, ['pending', 'accepted', 'rejected', 'cancelled'], true)) {
            $query->where('t.status', $status);
        }
        $serial = trim((string) $this->request->param('serial', ''));
        if ($serial !== '') {
            $query->whereLike('uc.serial', '%' . $serial . '%');
        }
        $range = $this->dateRange();
        if ($range) {
            if ($range[0] !== '') $query->where('t.created_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('t.created_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $rows = $query->field('t.*, fu.uid AS from_uid, fu.username AS from_name,
                               tu.uid AS to_uid, tu.username AS to_name,
                               c.name AS collectible_name, c.image, uc.serial')
            ->join('users fu', 'fu.id = t.from_user_id', 'LEFT')
            ->join('users tu', 'tu.id = t.to_user_id', 'LEFT')
            ->join('collectibles c', 'c.id = t.collectible_id', 'LEFT')
            ->join('user_collectibles uc', 'uc.id = t.user_collectible_id', 'LEFT')
            ->order('t.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        foreach ($rows as &$row) {
            $row['from_phone'] = mask_phone((string) Db::name('users')->where('id', $row['from_user_id'])->value('phone'));
            $row['to_phone'] = $row['to_phone'];
        }

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * POST /admin/transfer/revoke { id, reason }
     * 撤销待处理转赠：资产从 frozen 解冻回转出方 held
     */
    public function revoke()
    {
        $missing = $this->missingParams(['id', 'reason']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $id     = (int) $this->request->param('id');
        $reason = trim((string) $this->request->param('reason'));

        Db::startTrans();
        try {
            $transfer = Db::name('transfers')->where('id', $id)->where('status', 'pending')->lock(true)->find();
            if (!$transfer) {
                Db::rollback();
                return $this->fail(4220, '转赠不存在或已处理（仅待处理状态可撤销）');
            }

            $now = date('Y-m-d H:i:s');
            $restored = Db::name('user_collectibles')
                ->where('id', $transfer['user_collectible_id'])
                ->where('status', 'frozen')
                ->update(['status' => 'held', 'updated_at' => $now]);

            if (!$restored) {
                Db::rollback();
                return $this->fail(4220, '资产状态异常（非冻结状态），请人工核查');
            }

            Db::name('transfers')->where('id', $id)->update([
                'status'       => 'cancelled',
                'confirmed_at' => $now,
                'updated_at'   => $now,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '撤销失败：' . $e->getMessage());
        }

        $this->audit('transfer', 'revoke', '撤销转赠（ID ' . $id . '）', ['reason' => $reason], 'transfer', $id);
        return $this->success(null, '转赠已撤销，资产已解冻回转出方');
    }
}
