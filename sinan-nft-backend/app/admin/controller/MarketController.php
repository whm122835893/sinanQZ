<?php
declare(strict_types=1);

namespace app\admin\controller;

use think\facade\Db;

/**
 * 管理后台市场寄售控制器
 *
 * 覆盖：挂单列表、挂单冻结/解冻/强制下架、手续费配置（resale_fee_rate 实时生效）。
 */
class MarketController extends BaseController
{
    /**
     * GET /admin/market/list
     * 筛选：collectibleId、sellerKeyword、status、价格区间
     */
    public function list()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('resale_listings')->alias('rl');

        $collectibleId = $this->positiveInt('collectibleId');
        if ($collectibleId !== null) {
            $query->where('rl.collectible_id', $collectibleId);
        }
        $sellerKeyword = trim((string) $this->request->param('sellerKeyword', ''));
        if ($sellerKeyword !== '') {
            $query->whereExists(function ($q) use ($sellerKeyword) {
                $q->name('users')->whereRaw('nft_users.id = rl.seller_id')
                  ->where(function ($q2) use ($sellerKeyword) {
                      $q2->whereLike('nft_users.phone', "%{$sellerKeyword}%")
                         ->whereOr('nft_users.uid', $sellerKeyword);
                  });
            });
        }
        $status = (string) $this->request->param('status', '');
        if ($status !== '') {
            if ($status === 'onsale') {
                $status = 'selling';
            }
            if (in_array($status, ['selling', 'sold'], true)) {
                $query->where('rl.status', $status);
            } elseif ($status === 'cancelled') {
                // 用户自行取消（系统处置的取消走 frozen / system_delisted 派生筛选）
                $query->where('rl.status', 'cancelled')->where('rl.is_system_delisted', 0);
            } elseif ($status === 'frozen') {
                // 系统冻结：cancelled + 系统下架 + 资产仍冻结（风控审查中）
                $query->where('rl.status', 'cancelled')->where('rl.is_system_delisted', 1)
                    ->whereExists(function ($q) {
                        $q->name('user_collectibles')->whereRaw('nft_user_collectibles.id = rl.user_collectible_id')
                          ->where('nft_user_collectibles.status', 'frozen');
                    });
            } elseif ($status === 'system_delisted') {
                // 系统下架：cancelled + 系统下架 + 资产已退回持有（可重新上架）
                $query->where('rl.status', 'cancelled')->where('rl.is_system_delisted', 1)
                    ->whereExists(function ($q) {
                        $q->name('user_collectibles')->whereRaw('nft_user_collectibles.id = rl.user_collectible_id')
                          ->where('nft_user_collectibles.status', '<>', 'frozen');
                    });
            }
        }
        $minPrice = $this->request->param('minPrice');
        if ($minPrice !== null && $minPrice !== '') {
            $query->where('rl.price', '>=', (float) $minPrice);
        }
        $maxPrice = $this->request->param('maxPrice');
        if ($maxPrice !== null && $maxPrice !== '') {
            $query->where('rl.price', '<=', (float) $maxPrice);
        }
        $range = $this->dateRange();
        if ($range) {
            if ($range[0] !== '') $query->where('rl.listed_at', '>=', $range[0]);
            if ($range[1] !== '') $query->where('rl.listed_at', '<=', $range[1]);
        }

        $total = (clone $query)->count();
        $rows = $query->field('rl.*, u.uid, u.username AS seller_name, u.phone AS seller_phone,
                               c.name AS collectible_name, c.image, uc.serial, uc.status AS asset_status')
            ->join('users u', 'u.id = rl.seller_id', 'LEFT')
            ->join('collectibles c', 'c.id = rl.collectible_id', 'LEFT')
            ->join('user_collectibles uc', 'uc.id = rl.user_collectible_id', 'LEFT')
            ->order('rl.id', 'desc')
            ->page($page, $pageSize)
            ->select()->toArray();

        foreach ($rows as &$row) {
            $row['seller_phone'] = mask_phone((string) $row['seller_phone']);
        }

        return $this->paginate(camelize_keys($rows), $total, $page, $pageSize);
    }

    /**
     * POST /admin/market/manage { id, action(freeze/unfreeze/delist), reason }
     * freeze：挂单资产冻结（selling → 资产 frozen，挂单保持 selling 但不可成交？）
     * 严谨实现：freeze = 系统下架（is_system_delisted=1 + status=cancelled + 资产回 held）；
     * delist 与 freeze 的区别：delist 附带资产冻结（frozen）以便风控审查。
     */
    public function manage()
    {
        $missing = $this->missingParams(['id', 'action', 'reason']);
        if ($missing) {
            return $this->failMissing($missing);
        }
        $id     = (int) $this->request->param('id');
        $action = (string) $this->request->param('action');
        $reason = trim((string) $this->request->param('reason'));

        if (!in_array($action, ['freeze', 'unfreeze', 'delist'], true)) {
            return $this->fail(4220, 'action 仅允许 freeze / unfreeze / delist');
        }

        Db::startTrans();
        try {
            $listing = Db::name('resale_listings')->where('id', $id)->lock(true)->find();
            if (!$listing) {
                Db::rollback();
                return $this->fail(4040, '挂单不存在');
            }

            $now = date('Y-m-d H:i:s');
            $ucCond = ['id' => $listing['user_collectible_id']];

            switch ($action) {
                case 'freeze': // 冻结挂单：下架 + 资产冻结（风控）
                    if ($listing['status'] !== 'selling') {
                        throw new \Exception('仅「在售中」挂单可冻结');
                    }
                    Db::name('resale_listings')->where('id', $id)->update([
                        'status' => 'cancelled', 'is_system_delisted' => 1,
                        'system_delisted_at' => $now, 'delist_reason' => mb_substr($reason, 0, 255),
                        'updated_at' => $now,
                    ]);
                    Db::name('user_collectibles')->where($ucCond)->where('status', 'consigned')
                        ->update(['status' => 'frozen', 'is_consigned' => 0, 'updated_at' => $now]);
                    break;

                case 'unfreeze': // 解冻：资产恢复持有（可重新上架）
                    if ($listing['status'] !== 'cancelled' || (int) $listing['is_system_delisted'] !== 1) {
                        throw new \Exception('仅系统冻结的挂单可解冻');
                    }
                    Db::name('user_collectibles')->where($ucCond)->where('status', 'frozen')
                        ->update(['status' => 'held', 'updated_at' => $now]);
                    Db::name('resale_listings')->where('id', $id)->update([
                        'delist_reason' => '解冻：' . mb_substr($reason, 0, 200), 'updated_at' => $now,
                    ]);
                    break;

                case 'delist': // 普通强制下架：资产回 held
                    if ($listing['status'] !== 'selling') {
                        throw new \Exception('仅「在售中」挂单可强制下架');
                    }
                    Db::name('resale_listings')->where('id', $id)->update([
                        'status' => 'cancelled', 'is_system_delisted' => 1,
                        'system_delisted_at' => $now, 'delist_reason' => mb_substr($reason, 0, 255),
                        'updated_at' => $now,
                    ]);
                    Db::name('user_collectibles')->where($ucCond)->where('status', 'consigned')
                        ->update(['status' => 'held', 'is_consigned' => 0, 'updated_at' => $now]);
                    break;
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5000, '操作失败：' . $e->getMessage());
        }

        $actionDesc = ['freeze' => '冻结挂单', 'unfreeze' => '解冻挂单', 'delist' => '强制下架挂单'][$action];
        $this->audit('market', $action, $actionDesc . '（ID ' . $id . '）', ['reason' => $reason], 'resale_listing', $id);
        return $this->success(null, $actionDesc . '成功');
    }

    /**
     * GET /admin/market/config — 手续费配置读取
     */
    public function config()
    {
        $feeRate = (float) Db::name('system_configs')->where('config_key', 'resale_fee_rate')->value('config_value');
        $globalMax = (float) Db::name('system_configs')->where('config_key', 'resale_price_global_max')->value('config_value');
        $cooldown = (int) Db::name('system_configs')->where('config_key', 'resale_cooldown_seconds')->value('config_value');

        // 手续费统计摘要
        $totalFee = (float) Db::name('resale_listings')->where('status', 'sold')->sum('fee_amount');
        $soldCount = Db::name('resale_listings')->where('status', 'sold')->count();

        return $this->success([
            'feeRate' => $feeRate,
            'globalMax' => $globalMax,
            'cooldownSeconds' => $cooldown,
            'stats' => ['totalFee' => round($totalFee, 2), 'soldCount' => $soldCount],
        ]);
    }

    /**
     * POST /admin/market/config { fee_rate, global_max?, cooldown_seconds? }
     * 手续费配置（实时生效，写审计）
     */
    public function saveConfig()
    {
        $feeRate = $this->request->param('fee_rate');
        if ($feeRate === null || $feeRate === '') {
            return $this->fail(4220, '请提供 fee_rate');
        }
        $feeRate = (float) $feeRate;
        if ($feeRate < 0 || $feeRate > 20) {
            return $this->fail(4220, '手续费率需在 0~20% 之间');
        }

        $now = date('Y-m-d H:i:s');
        Db::name('system_configs')->where('config_key', 'resale_fee_rate')
            ->update(['config_value' => number_format($feeRate, 2, '.', ''), 'updated_at' => $now]);

        if ($this->request->param('global_max') !== null && $this->request->param('global_max') !== '') {
            $globalMax = (float) $this->request->param('global_max');
            if ($globalMax < 1) {
                return $this->fail(4220, '全局最高价不合法');
            }
            Db::name('system_configs')->where('config_key', 'resale_price_global_max')
                ->update(['config_value' => (string) (int) $globalMax, 'updated_at' => $now]);
        }
        if ($this->request->param('cooldown_seconds') !== null && $this->request->param('cooldown_seconds') !== '') {
            $cooldown = (int) $this->request->param('cooldown_seconds');
            if ($cooldown < 0 || $cooldown > 86400 * 7) {
                return $this->fail(4220, '冷却时间需在 0 秒~7 天之间');
            }
            Db::name('system_configs')->where('config_key', 'resale_cooldown_seconds')
                ->update(['config_value' => (string) $cooldown, 'updated_at' => $now]);
        }

        $this->audit('market', 'save_config', '更新市场手续费配置（费率 ' . $feeRate . '%）',
            ['fee_rate' => $feeRate]);
        return $this->success(null, '手续费配置已保存并实时生效');
    }
}
