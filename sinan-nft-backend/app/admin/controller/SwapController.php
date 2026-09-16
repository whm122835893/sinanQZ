<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 统一置换管理（admin/swap:plans）
 *
 * 管理端仅保留统一置换（回收+按比例空投）的计划查询：
 *   plans      计划列表（含源藏品配置摘要）
 *   planDetail 计划详情（用户名单 + 资产明细，独立分页）
 * 执行入口见 CollectibleController@swap / swapPreview。
 * 原 C 端用户间置换挂单（swap_offers/swap_records）体系已下线。
 */
class SwapController extends BaseController
{
    /**
     * GET /admin/swap/plans  统一置换计划列表（回收+按比例空投记录）
     */
    public function plans()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('swap_plans');

        $newId = $this->positiveInt('newCollectibleId');
        if ($newId !== null) $query->where('new_collectible_id', $newId);

        $total = (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        // 附带各计划的源藏品配置摘要
        $planIds = array_map('intval', array_column($rows, 'id'));
        $itemRows = $planIds
            ? Db::name('swap_plan_items')->whereIn('plan_id', $planIds)->order('id')->select()->toArray()
            : [];
        $itemsByPlan = [];
        foreach ($itemRows as $it) {
            $itemsByPlan[(int) $it['plan_id']][] = [
                'collectibleId'    => (int) $it['old_collectible_id'],
                'collectibleName'  => (string) $it['old_collectible_name'],
                'ratio'            => (int) $it['ratio'],
                'recoveredCount'   => (int) $it['recovered_count'],
            ];
        }

        $list = array_map(function ($p) use ($itemsByPlan) {
            return [
                'id'               => (int) $p['id'],
                'planNo'           => (string) $p['plan_no'],
                'newCollectibleId' => (int) $p['new_collectible_id'],
                'newCollectibleName' => (string) $p['new_collectible_name'],
                'reason'           => (string) ($p['reason'] ?? ''),
                'userCount'        => (int) $p['user_count'],
                'totalRecovered'   => (int) $p['total_recovered'],
                'totalAirdropped' => (int) $p['total_airdropped'],
                'items'            => $itemsByPlan[(int) $p['id']] ?? [],
                'adminName'        => (string) $p['admin_name'],
                'createdAt'        => (string) $p['created_at'],
            ];
        }, $rows);

        return $this->paginate($list, $total, $page, $pageSize);
    }

    /**
     * GET /admin/swap/plans/:id  统一置换计划详情（源配置 + 用户名单 + 资产明细，名单/明细分页）
     */
    public function planDetail()
    {
        $id = $this->positiveInt('id');
        if ($id === null) return $this->failMissing(['id']);

        $plan = Db::name('swap_plans')->where('id', $id)->find();
        if (!$plan) {
            return $this->fail(4040, '置换计划不存在');
        }

        // 源藏品配置（全量）
        $itemRows = Db::name('swap_plan_items')->where('plan_id', $id)->order('id')->select()->toArray();
        $items = array_map(static fn ($it) => [
            'collectibleId'    => (int) $it['old_collectible_id'],
            'collectibleName'  => (string) $it['old_collectible_name'],
            'ratio'            => (int) $it['ratio'],
            'recoveredCount'   => (int) $it['recovered_count'],
        ], $itemRows);

        // 用户名单（分页：page/pageSize）
        [$uPage, $uSize] = $this->pageParams();
        $uQuery = Db::name('swap_plan_users')->where('plan_id', $id);
        $uTotal = (clone $uQuery)->count();
        $uRows = $uQuery->order('airdrop_quantity', 'desc')->order('user_id', 'asc')
            ->page($uPage, $uSize)->select()->toArray();
        $users = array_map(static function ($u) {
            $holdings = $u['holdings'] ? json_decode((string) $u['holdings'], true) : [];
            return [
                'userId'          => (int) $u['user_id'],
                'phone'           => (string) ($u['phone'] ?? ''),
                'uid'             => (string) ($u['uid'] ?? ''),
                'holdings'        => is_array($holdings) ? $holdings : [],
                'recoveredTotal'  => (int) $u['recovered_total'],
                'airdropQuantity' => (int) $u['airdrop_quantity'],
            ];
        }, $uRows);

        // 资产明细（独立分页 dPage/dPageSize，可按动作过滤：1回收 2空投）
        $dPage = max(1, (int) $this->request->param('dPage', $this->request->param('d_page', 1)));
        $dSize = (int) $this->request->param('dPageSize', $this->request->param('d_page_size', 20));
        $dSize = min(100, max(1, $dSize));
        $action = $this->enumParam('action', ['1', '2']);
        $dQuery = Db::name('swap_plan_details')->where('plan_id', $id);
        if ($action !== null) $dQuery->where('action', (int) $action);
        $dTotal = (clone $dQuery)->count();
        $dRows = $dQuery->order('id', 'asc')->page($dPage, $dSize)->select()->toArray();
        $details = array_map(static fn ($d) => [
            'userId'            => (int) $d['user_id'],
            'action'            => (int) $d['action'],
            'collectibleId'    => (int) $d['collectible_id'],
            'collectibleName'   => (string) $d['collectible_name'],
            'serial'            => (string) ($d['serial'] ?? ''),
            'userCollectibleId' => (int) $d['user_collectible_id'],
            'ratio'             => $d['ratio'] !== null ? (int) $d['ratio'] : null,
            'createdAt'         => (string) $d['created_at'],
        ], $dRows);

        return $this->success([
            'id'                 => (int) $plan['id'],
            'planNo'             => (string) $plan['plan_no'],
            'newCollectibleId'   => (int) $plan['new_collectible_id'],
            'newCollectibleName' => (string) $plan['new_collectible_name'],
            'reason'             => (string) ($plan['reason'] ?? ''),
            'userCount'          => (int) $plan['user_count'],
            'totalRecovered'     => (int) $plan['total_recovered'],
            'totalAirdropped'    => (int) $plan['total_airdropped'],
            'adminName'          => (string) $plan['admin_name'],
            'createdAt'          => (string) $plan['created_at'],
            'items'              => $items,
            'users'              => [
                'list'  => $users,
                'total' => $uTotal,
                'page'  => $uPage,
                'pageSize' => $uSize,
            ],
            'details'            => [
                'list'  => $details,
                'total' => $dTotal,
                'page'  => $dPage,
                'pageSize' => $dSize,
            ],
        ]);
    }
}
