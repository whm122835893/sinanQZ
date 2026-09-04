<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;

use think\facade\Db;

/**
 * 抽奖控制器
 */
class LuckyDraw extends BaseController
{
    /**
     * GET /api/lucky-draw/activity
     * 抽奖活动配置（奖池）
     */
    public function activity()
    {
        $activityId = $this->intParam('activityId');

        if ($activityId > 0) {
            $items = Db::name('lucky_draw_prizes')
                ->where('activity_id', $activityId)
                ->whereNull('deleted_at')
                ->order('sort_order', 'asc')
                ->select()
                ->toArray();
        } else {
            // 取第一个活动
            $activityId = Db::name('lucky_draw_prizes')->min('activity_id') ?: 1;
            $items = Db::name('lucky_draw_prizes')
                ->where('activity_id', $activityId)
                ->whereNull('deleted_at')
                ->order('sort_order', 'asc')
                ->select()
                ->toArray();
        }

        // 过滤 NULL（非藏品奖），否则 NULL 作数组下标触发 PHP8.1+ 弃用告警、whereIn 出现 0
        $collectibleIds = array_values(array_unique(array_filter(array_column($items, 'collectible_id'))));
        $collectibles = $collectibleIds ? Db::name('collectibles')->whereIn('id', $collectibleIds)->column('name,image', 'id') : [];

        return $this->success([
            'activityId' => $activityId,
            'items'      => array_map(function ($p) use ($collectibles) {
                $cid = $p['collectible_id'] !== null ? (int) $p['collectible_id'] : 0;
                return [
                    'prizeId'       => (int) $p['id'],
                    'tierName'      => $p['tier_name'],
                    'prizeType'     => $p['prize_type'],
                    'collectibleId' => $cid ?: null,
                    'name'          => $collectibles[$cid]['name'] ?? $p['tier_name'],
                    'image'         => $collectibles[$cid]['image'] ?? '',
                    'total'         => $p['total'] === null ? null : (int) $p['total'],
                    'won'           => (int) $p['won'],
                    'sortOrder'     => (int) $p['sort_order'],
                    'probability'   => (float) $p['probability'],
                ];
            }, $items),
        ]);
    }

    /**
     * POST /api/lucky-draw/draw
     * 参与抽奖
     */
    public function draw()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        Db::startTrans();
        try {
            $activityId = Db::name('lucky_draw_prizes')->min('activity_id') ?: 1;
            $items      = Db::name('lucky_draw_prizes')
                ->where('activity_id', $activityId)
                ->whereNull('deleted_at')
                ->lock(true)
                ->select()
                ->toArray();
            if (!$items) { Db::rollback(); return $this->fail(1001, '抽奖活动未配置'); }

            // 排除已抽完的奖品
            $available = array_values(array_filter($items, function ($p) {
                return $p['total'] === null || (int) $p['won'] < (int) $p['total'];
            }));
            if (!$available) { Db::rollback(); return $this->fail(3001, '奖品已抽完'); }

            // 概率读取自数据库 probability 列；未配置（全0）时均分兜底
            $probSum = 0;
            foreach ($available as $p) $probSum += (float) $p['probability'];
            $useEqual = $probSum <= 0;

            // 加密随机数源（禁止 mt_rand/rand，防预测）
            $rand       = random_int(1, 100000000) / 100000000;
            $cumulative = 0;
            $winner     = null;
            $equalProb  = $useEqual ? 1 / count($available) : 0;

            foreach ($available as $p) {
                $cumulative += $useEqual ? $equalProb : (float) $p['probability'];
                if ($rand <= $cumulative) { $winner = $p; break; }
            }
            if (!$winner) $winner = $available[array_key_last($available)];

            $now = date('Y-m-d H:i:s.v');

            // 更新已中数量（条件更新防并发超发，DB CHECK won<=total 兜底）
            $affected = Db::name('lucky_draw_prizes')
                ->where('id', $winner['id'])
                ->whereRaw('`won` < `total`')
                ->update([
                    'won'        => Db::raw('won + 1'),
                    'updated_at' => $now,
                ]);
            if (!$affected) {
                Db::rollback();
                return $this->fail(3001, '奖品已抽完');
            }

            // 发放
            $userCollectibleId = null;
            $coinAmount        = null;
            $serial            = null;

            if ($winner['prize_type'] === 'collectible' && $winner['collectible_id']) {
                // 先插占位行取自增ID，再回写编号（count+1 方式并发下会撞唯一索引）
                Db::name('user_collectibles')->insert([
                    'user_id'        => $userId,
                    'collectible_id' => $winner['collectible_id'],
                    'serial'         => gen_serial_placeholder(),
                    'source'         => 'lucky_draw',
                    'acquired_price' => 0,
                    'acquired_at'    => $now,
                    'status'         => 'held',
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
                $userCollectibleId = (int) Db::name('user_collectibles')->getLastInsID();
                $serial = 'SN-' . $winner['collectible_id'] . '-' . str_pad((string) $userCollectibleId, 4, '0', STR_PAD_LEFT);
                Db::name('user_collectibles')->where('id', $userCollectibleId)->update([
                    'serial'     => $serial,
                    'updated_at' => $now,
                ]);
            } elseif ($winner['prize_type'] === 'points') {
                $coinAmount = (float) ($winner['coin_amount'] ?? 0);
                if ($coinAmount > 0) {
                    Db::name('wallets')->where('user_id', $userId)->update([
                        'points'     => Db::raw("points + {$coinAmount}"),
                        'updated_at' => $now,
                    ]);
                    $pointsAfter = (float) Db::name('wallets')->where('user_id', $userId)->value('points');
                    Db::name('wallet_transactions')->insert([
                        'user_id'       => $userId,
                        'trans_type'   => 'reward',
                        'title'        => '抽奖奖励',
                        'direction'    => 1,
                        'amount'       => $coinAmount,
                        'balance_after'=> $pointsAfter,
                        'created_at'   => $now,
                    ]);
                }
            }

            // 写抽奖记录
            Db::name('lucky_draw_records')->insert([
                'user_id'             => $userId,
                'prize_id'            => $winner['id'],
                'user_collectible_id' => $userCollectibleId,
                'created_at'          => $now,
            ]);
            $recordId = (int) Db::name('lucky_draw_records')->getLastInsID();

            Db::commit();

            $collectible = $winner['collectible_id'] ? Db::name('collectibles')->where('id', $winner['collectible_id'])->find() : null;
            return $this->success([
                'recordId'  => $recordId,
                'prize' => [
                    'prizeId'     => (int) $winner['id'],
                    'tierName'    => $winner['tier_name'],
                    'prizeType'   => $winner['prize_type'],
                    'name'        => $collectible['name'] ?? $winner['tier_name'],
                    'image'       => $collectible['image'] ?? '',
                ],
                'userCollectible' => $userCollectibleId ? ['id' => $userCollectibleId, 'no' => $serial ?? ''] : null,
                'coinAmount'      => $coinAmount,
            ]);
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail(5001, '抽奖失败：' . $e->getMessage());
        }
    }

    /**
     * GET /api/lucky-draw/records
     * 抽奖记录
     */
    public function records()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');
        $p = $this->pagination();

        $query = Db::name('lucky_draw_records')->alias('r')
            ->join('lucky_draw_prizes p', 'p.id = r.prize_id')
            ->where('r.user_id', $userId);

        $total = $query->count();
        $list  = $query
            ->order('r.created_at', 'desc')
            ->limit($p['offset'], $p['pageSize'])
            ->field([
                'r.id as record_id', 'p.tier_name', 'p.prize_type', 'r.created_at',
            ])->select()->toArray();

        return $this->paginate(array_map(fn ($r) => [
            'recordId'   => (int) $r['record_id'],
            'tierName'   => $r['tier_name'],
            'prizeName'  => $r['tier_name'],
            'prizeType'  => $r['prize_type'],
            'createdAt'  => $r['created_at'],
        ], $list), $total, $p['page'], $p['pageSize']);
    }
}
