<?php
declare(strict_types=1);

namespace app\controller;
use app\BaseController;
use app\service\ActivityRewardService;
use app\service\RewardGrantService;

use think\facade\Db;

/**
 * 抽奖控制器
 *
 * 活动链路（新版抽奖活动）：
 *   lucky_draw_activities（启用中 + 时间窗内）→ 参与资格判定（eligibility_type/config）
 *   → 消耗抽奖次数（lucky_draw_chances 台账；从未获得过次数的用户可免费抽，兼容存量）
 *   → 加权抽取 → 发放（grant_mode：realtime 实时到账 / manual 记录名单统一发放）
 * 奖项类型：collectible 藏品 / points 司南币 / draw_chance 抽奖次数 /
 *           priority_qualification 优先购资格 / eligibility_qualification 资格购白名单 /
 *           blindbox 盲盒 / none 谢谢参与
 */
class LuckyDraw extends BaseController
{
    /**
     * GET /api/lucky-draw/activity
     * 抽奖活动配置（奖池 + 活动元信息 + 登录态的剩余次数/参与资格）
     */
    public function activity()
    {
        $activityId = $this->intParam('activityId');
        $activity   = null;

        if ($activityId > 0) {
            $activity = Db::name('lucky_draw_activities')
                ->where('id', $activityId)
                ->whereNull('deleted_at')
                ->find();
        } else {
            // 最新启用中且在时间窗内的活动
            $acts = Db::name('lucky_draw_activities')
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->select()->toArray();
            foreach ($acts as $a) {
                if (ActivityRewardService::inWindow($a['start_time'], $a['end_time'])) {
                    $activity = $a;
                    break;
                }
            }
        }

        $activityId = $activity ? (int) $activity['id']
            : (int) (Db::name('lucky_draw_prizes')->min('activity_id') ?: 1);

        $items = Db::name('lucky_draw_prizes')
            ->where('activity_id', $activityId)
            ->whereNull('deleted_at')
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();

        // 过滤 NULL（非藏品奖），否则 NULL 作数组下标触发 PHP8.1+ 弃用告警、whereIn 出现 0
        $collectibleIds = array_values(array_unique(array_filter(array_column($items, 'collectible_id'))));
        $collectibles = $collectibleIds ? Db::name('collectibles')->whereIn('id', $collectibleIds)->column('name,image', 'id') : [];

        // 登录态：剩余抽奖次数 + 参与资格（未登录返回 null，前端引导登录）
        $chances = null;
        $eligibility = null;
        $userId = $this->userId();
        if ($userId) {
            $chances = $this->chanceSummary($userId);
            if ($activity) {
                $check = ActivityRewardService::checkEligibility(
                    $userId,
                    (string) ($activity['eligibility_type'] ?? 'all'),
                    ActivityRewardService::parseJson($activity['eligibility_config'] ?? null)
                );
                $eligibility = [
                    'eligible' => $check['eligible'],
                    'reason'   => $check['reason'],
                ];
            } else {
                $eligibility = ['eligible' => true, 'reason' => ''];
            }
        }

        return $this->success([
            'activityId' => $activityId,
            'name'       => $activity['name'] ?? '',
            'startTime'  => $activity['start_time'] ?? null,
            'endTime'    => $activity['end_time'] ?? null,
            'chances'    => $chances,
            'eligibility' => $eligibility,
            'items'      => array_map(function ($p) use ($collectibles) {
                $cid = $p['collectible_id'] !== null ? (int) $p['collectible_id'] : 0;
                return [
                    'prizeId'       => (int) $p['id'],
                    'tierName'      => $p['tier_name'],
                    'prizeName'     => $p['prize_name'] ?: $p['tier_name'],
                    'prizeType'     => $p['prize_type'],
                    'collectibleId' => $cid ?: null,
                    'name'          => $p['prize_name'] ?: ($collectibles[$cid]['name'] ?? $p['tier_name']),
                    'image'         => $p['prize_image'] ?: ($collectibles[$cid]['image'] ?? ''),
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
     * 参与抽奖（资格判定 → 消耗次数 → 加权抽取 → 发放）
     */
    public function draw()
    {
        $userId = $this->userId();
        if (!$userId) return $this->fail(2001, '未登录');

        Db::startTrans();
        try {
            // ---- 1. 定位抽奖活动（启用中 + 时间窗内；无活动实体时回退奖项最小活动号，兼容存量）----
            $activity = null;
            $acts = Db::name('lucky_draw_activities')
                ->where('status', 1)
                ->whereNull('deleted_at')
                ->order('id', 'desc')
                ->lock(true)
                ->select()->toArray();
            foreach ($acts as $a) {
                if (ActivityRewardService::inWindow($a['start_time'], $a['end_time'])) {
                    $activity = $a;
                    break;
                }
            }

            if ($activity) {
                $activityId = (int) $activity['id'];

                // 参与资格判定
                $eligibility = ActivityRewardService::checkEligibility(
                    $userId,
                    (string) ($activity['eligibility_type'] ?? 'all'),
                    ActivityRewardService::parseJson($activity['eligibility_config'] ?? null)
                );
                if (!$eligibility['eligible']) {
                    Db::rollback();
                    return $this->fail(3002, $eligibility['reason']);
                }
            } else {
                $activityId = (int) (Db::name('lucky_draw_prizes')->min('activity_id') ?: 1);
            }

            // ---- 2. 消耗抽奖次数（从未获得过次数的用户可免费抽，兼容存量）----
            if (!$this->consumeChance($userId, $activityId)) {
                Db::rollback();
                return $this->fail(3003, '抽奖次数不足，可通过签到、邀请好友等活动获得');
            }

            // ---- 3. 加权抽取 ----
            $items = Db::name('lucky_draw_prizes')
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

            // ---- 4. 先写抽奖流水（recordId 作为发放位防重标识）----
            Db::name('lucky_draw_records')->insert([
                'user_id'             => $userId,
                'prize_id'            => $winner['id'],
                'user_collectible_id' => null,
                'created_at'          => $now,
            ]);
            $recordId = (int) Db::name('lucky_draw_records')->getLastInsID();

            // ---- 5. 发放（manual 记录名单统一发放 / realtime 实时到账）----
            $grantMode  = $activity ? (string) ($activity['grant_mode'] ?? 'realtime') : 'realtime';
            $actName    = $activity['name'] ?? '抽奖活动';
            $prizeLabel = $winner['prize_name'] ?: $winner['tier_name'];

            if ($grantMode === 'manual') {
                // 记录名单 → 后台导出名单统一发放（奖品实物不实时到账）
                $entry = ['type' => $winner['prize_type']];
                $rc = ActivityRewardService::parseJson($winner['reward_config'] ?? null);
                if ($winner['prize_type'] === 'collectible') {
                    $entry['collectibleId'] = (int) ($rc['collectibleId'] ?? $winner['collectible_id'] ?? 0);
                    $entry['quantity'] = max(1, (int) ($rc['quantity'] ?? 1));
                } elseif ($winner['prize_type'] === 'points') {
                    $entry['amount'] = (float) ($rc['amount'] ?? $winner['coin_amount'] ?? 0);
                } elseif ($winner['prize_type'] !== 'none') {
                    $entry = array_merge($entry, $rc);
                }
                if ($entry['type'] !== 'none') {
                    $rewards = ActivityRewardService::normalizeRewards([$entry]);
                    ActivityRewardService::grantRewards(
                        $rewards, $userId, 'lucky_draw', $activityId, $actName, 'manual',
                        ['slot' => 'prize' . $winner['id'] . ':r' . $recordId, 'title' => $actName . '·' . $prizeLabel, 'relatedId' => $recordId]
                    );
                }
            } else {
                // realtime：collectible/points 走原有直发；新增四类走 RewardGrantService
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
                    Db::name('lucky_draw_records')->where('id', $recordId)->update(['user_collectible_id' => $userCollectibleId]);
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
                } elseif (in_array($winner['prize_type'], ['draw_chance', 'priority_qualification', 'eligibility_qualification', 'blindbox'], true)) {
                    // 新增四类奖项：按奖项 reward_config 统一发放
                    $rc = ActivityRewardService::parseJson($winner['reward_config'] ?? null);
                    $reward = RewardGrantService::validate($winner['prize_type'], $rc);
                    RewardGrantService::grant(
                        $winner['prize_type'],
                        $reward,
                        $userId,
                        'lucky_draw',
                        [
                            'title'              => $actName . '·' . $prizeLabel,
                            'activityId'         => $activityId,
                            'activityType'       => 'lucky_draw',
                            'relatedId'          => $recordId,
                            'luckyDrawActivityId' => $activityId,
                        ]
                    );
                }
            }

            Db::commit();

            $collectible = $winner['collectible_id'] ? Db::name('collectibles')->where('id', $winner['collectible_id'])->find() : null;
            return $this->success([
                'recordId'  => $recordId,
                'grantMode' => $grantMode,
                'prize' => [
                    'prizeId'     => (int) $winner['id'],
                    'tierName'    => $winner['tier_name'],
                    'prizeName'   => $prizeLabel,
                    'prizeType'   => $winner['prize_type'],
                    'name'        => $winner['prize_name'] ?: ($collectible['name'] ?? $winner['tier_name']),
                    'image'       => $winner['prize_image'] ?: ($collectible['image'] ?? ''),
                ],
                'userCollectible' => isset($userCollectibleId) && $userCollectibleId ? ['id' => $userCollectibleId, 'no' => $serial ?? ''] : null,
                'coinAmount'      => $coinAmount ?? null,
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
                'r.id as record_id', 'p.tier_name', 'p.prize_name', 'p.prize_type', 'p.prize_image', 'r.created_at',
            ])->select()->toArray();

        return $this->paginate(array_map(fn ($r) => [
            'recordId'   => (int) $r['record_id'],
            'tierName'   => $r['tier_name'],
            'prizeName'  => $r['prize_name'] ?: $r['tier_name'],
            'prizeImage' => $r['prize_image'] ?: '',
            'prizeType'  => $r['prize_type'],
            'createdAt'  => $r['created_at'],
        ], $list), $total, $p['page'], $p['pageSize']);
    }

    // =====================================================================
    // 抽奖次数台账（lucky_draw_chances）
    // =====================================================================

    /**
     * 消耗一次抽奖次数
     *
     * 优先消耗当前活动的剩余次数，其次其他活动；条件更新防并发超扣。
     * 从未获得过次数的用户返回 true（免费抽，兼容存量免费抽奖模式）。
     */
    private function consumeChance(int $userId, int $activityId): bool
    {
        $rows = Db::name('lucky_draw_chances')
            ->where('user_id', $userId)
            ->whereRaw('used_quantity < total_quantity')
            ->orderRaw("activity_id = {$activityId} DESC, id ASC")
            ->lock(true)
            ->select()->toArray();
        if (!$rows) {
            // 从未获得过次数 → 免费抽；获得过但已用完 → 拦截
            $has = Db::name('lucky_draw_chances')->where('user_id', $userId)->count();
            return $has === 0;
        }
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $ok = Db::name('lucky_draw_chances')
                ->where('id', $row['id'])
                ->whereRaw('used_quantity < total_quantity')
                ->update(['used_quantity' => Db::raw('used_quantity + 1'), 'updated_at' => $now]);
            if ($ok) {
                return true;
            }
        }
        return false;
    }

    /** 用户的抽奖次数汇总（total/used/available/free） */
    private function chanceSummary(int $userId): array
    {
        $rows = Db::name('lucky_draw_chances')->where('user_id', $userId)->select()->toArray();
        $total = array_sum(array_map(fn ($r) => (int) $r['total_quantity'], $rows));
        $used  = array_sum(array_map(fn ($r) => (int) $r['used_quantity'], $rows));
        return [
            'total'     => $total,
            'used'      => $used,
            'available' => max(0, $total - $used),
            'free'      => count($rows) === 0, // 无台账 = 免费抽奖模式
        ];
    }
}
