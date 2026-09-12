<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 抽签发售核心服务
 *
 * 职责：
 * - 活动生命周期管理（草稿→报名中→抽签中→已结束）
 * - 用户报名（每人每活动 1 次：基础 1 票 1 码；更多码经「邀请好友参与」或「购买」获得；
 *   邀请/购买开关与上限均在活动上配置；所有用户均可参与）
 * - 抽签引擎（单阶段开奖：必中用户直接中签 + 剩余名额加权随机，一次完成）
 * - 中签用户购买资格校验
 */
class RaffleService
{
    /**
     * 创建/保存抽签活动
     *
     * 新增配置：totalSupply（藏品总发行量）、drawWinCount（本次抽签中签名额）、
     * buyCodeLimit（购买抽签码上限，drawCodeEnabled=1 时须>0）、
     * inviteEnabled/inviteCodeLimit/inviteUserNeeded（邀请好友参与得码：开关/上限/每 N 人得 1 码）、
     * maxWinsPerUser（单用户最大中签数）。
     * 单用户码总量 = 基础 1 + 邀请上限（开关开时）+ 购买上限（开关开时）。
     *
     * 校验：
     * - 中签总购买上限（名额 × 每人限购）≤ 藏品剩余库存（totalSupply 优先，未录入时取藏品 edition），
     *   保证每位中签者都能买满限购，不会出现中签后库存不足买不了的情况
     * - 开奖后（win_locked=1）名额/码上限/发行量/邀请与购买配置/单用户最大中签数字段锁定，禁止修改
     */
    public static function saveActivity(int $adminId, array $data): array
    {
        $collectibleId   = (int) ($data['collectibleId'] ?? 0);
        $winnerCount     = (int) ($data['winnerCount'] ?? 0);
        $drawWinCount    = (int) ($data['drawWinCount'] ?? $winnerCount);
        $totalSupply     = (int) ($data['totalSupply'] ?? 0);
        $maxWinsPerUser  = max(0, (int) ($data['maxWinsPerUser'] ?? 1));
        $drawCodeEnabled = (int) ($data['drawCodeEnabled'] ?? 0) ? 1 : 0;
        $buyCodeLimit    = max(0, (int) ($data['buyCodeLimit'] ?? 5));
        $inviteEnabled   = (int) ($data['inviteEnabled'] ?? 0) ? 1 : 0;
        $inviteCodeLimit = max(0, (int) ($data['inviteCodeLimit'] ?? 5));
        $inviteUserNeeded = max(1, (int) ($data['inviteUserNeeded'] ?? 1));
        $saleQuantity    = (int) ($data['saleQuantity'] ?? 1);
        $ticketPrice     = (float) ($data['ticketPrice'] ?? 0);
        $salePrice       = (float) ($data['salePrice'] ?? 0);
        $drawCodePrice   = (float) ($data['drawCodePrice'] ?? 0);
        $regStart        = $data['registrationStart'] ?? '';
        $regEnd          = $data['registrationEnd'] ?? '';
        $drawTime        = $data['drawTime'] ?? '';
        $purchaseStart   = $data['purchaseStart'] ?? null;
        $purchaseEnd     = $data['purchaseEnd'] ?? null;

        if ($collectibleId <= 0 || $winnerCount <= 0 || $saleQuantity <= 0) {
            throw new \InvalidArgumentException('参数缺失：藏品、中签数、每人限购');
        }
        if (!strtotime($regStart) || !strtotime($regEnd) || !strtotime($drawTime)) {
            throw new \InvalidArgumentException('时间格式不正确');
        }
        if (strtotime($regEnd) <= strtotime($regStart) || strtotime($drawTime) <= strtotime($regEnd)) {
            throw new \InvalidArgumentException('时间区间无效（报名开始 < 报名截止 < 抽签时间）');
        }

        $collectible = Db::name('collectibles')->where('id', $collectibleId)->whereNull('deleted_at')->find();
        if (!$collectible) {
            throw new \InvalidArgumentException('关联藏品不存在');
        }

        // 购买抽签码开关开启时必须配置正上限与单价
        if ($drawCodeEnabled === 1 && $buyCodeLimit <= 0) {
            throw new \InvalidArgumentException('开启购买抽签码后，购买上限必须大于 0');
        }
        if ($drawCodeEnabled === 1 && $drawCodePrice <= 0) {
            throw new \InvalidArgumentException('开启购买抽签码后，抽签码单价必须大于 0');
        }
        // 邀请开关开启时必须配置正上限
        if ($inviteEnabled === 1 && $inviteCodeLimit <= 0) {
            throw new \InvalidArgumentException('开启邀请好友得码后，邀请可得码上限必须大于 0');
        }

        // 编辑时校验锁定字段（开奖后本次中签名额锁定）
        $editing = null;
        if (!empty($data['id'])) {
            $editing = Db::name('raffle_activities')->where('id', (int) $data['id'])->find();
            if (!$editing) {
                throw new \InvalidArgumentException('活动不存在');
            }
            if ((int) ($editing['win_locked'] ?? 0) === 1) {
                $oldQuota  = (int) ($editing['draw_win_count'] ?: $editing['winner_count']);
                $oldSupply = (int) ($editing['total_supply'] ?? 0);
                $oldMaxWin = (int) ($editing['max_wins_per_user'] ?? 1);
                $oldBuyLim = (int) ($editing['buy_code_limit'] ?? 0);
                $oldBuyEn  = (int) ($editing['draw_code_enabled'] ?? 0);
                $oldInvEn  = (int) ($editing['invite_enabled'] ?? 0);
                $oldInvLim = (int) ($editing['invite_code_limit'] ?? 0);
                $oldInvNed = max(1, (int) ($editing['invite_user_needed'] ?? 1));
                if ($drawWinCount !== $oldQuota) {
                    throw new \InvalidArgumentException('该活动已开奖，本次抽签名额已锁定，不可修改');
                }
                if ($drawCodeEnabled !== $oldBuyEn || $buyCodeLimit !== $oldBuyLim) {
                    throw new \InvalidArgumentException('该活动已开奖，购买抽签码配置已锁定，不可修改');
                }
                if ($inviteEnabled !== $oldInvEn || $inviteCodeLimit !== $oldInvLim || $inviteUserNeeded !== $oldInvNed) {
                    throw new \InvalidArgumentException('该活动已开奖，邀请好友得码配置已锁定，不可修改');
                }
                if ($maxWinsPerUser !== $oldMaxWin) {
                    throw new \InvalidArgumentException('该活动已开奖，单用户最大中签数已锁定，不可修改');
                }
                if ($totalSupply !== $oldSupply) {
                    throw new \InvalidArgumentException('该活动已开奖，藏品总发行量已锁定，不可修改');
                }
            }
        }

        // 名额校验：中签总购买上限（名额 × 每人限购）不能大于藏品剩余库存，
        // 确保每位中签者都能买满限购（否则后买的中签者会碰到库存不足）
        // 剩余库存 = 总发行量（totalSupply，未录入时取藏品 edition）- 藏品已售 sold
        $supply   = $totalSupply > 0 ? $totalSupply : (int) $collectible['edition'];
        $sold     = (int) ($collectible['sold'] ?? 0);
        $stock    = max(0, $supply - $sold);
        $maxSell  = $drawWinCount * $saleQuantity;
        if ($maxSell > $stock) {
            throw new \InvalidArgumentException("中签总购买上限（名额 {$drawWinCount} × 每人限购 {$saleQuantity} = {$maxSell}）不能大于藏品剩余库存（{$stock}）");
        }

        $payload = [
            'collectible_id'        => $collectibleId,
            'name'                  => (string) ($data['name'] ?? ''),
            'description'           => (string) ($data['description'] ?? ''),
            'ticket_price'          => $ticketPrice,
            'total_supply'          => $totalSupply,
            'draw_code_enabled'     => $drawCodeEnabled,
            'draw_code_price'       => $drawCodePrice,
            'buy_code_limit'        => $buyCodeLimit,
            'invite_enabled'        => $inviteEnabled,
            'invite_code_limit'     => $inviteCodeLimit,
            'invite_user_needed'    => $inviteUserNeeded,
            'max_wins_per_user'     => $maxWinsPerUser,
            'winner_count'          => $winnerCount,
            'draw_win_count'        => max(0, $drawWinCount),
            'sale_quantity'         => $saleQuantity,
            'sale_price'            => $salePrice,
            'registration_start'    => $regStart,
            'registration_end'      => $regEnd,
            'draw_time'             => $drawTime,
            'purchase_start'        => $purchaseStart,
            'purchase_end'          => $purchaseEnd,
            'extra'                 => json_encode(['created_by' => $adminId], JSON_UNESCAPED_UNICODE),
        ];

        if ($editing) {
            Db::name('raffle_activities')->where('id', (int) $data['id'])->update($payload);
            $id = (int) $data['id'];
        } else {
            $id = (int) Db::name('raffle_activities')->insertGetId($payload);
        }

        return ['id' => $id];
    }

    /**
     * 变更活动状态
     */
    public static function changeStatus(int $id, int $status): void
    {
        Db::name('raffle_activities')->where('id', $id)->update(['status' => $status]);
    }

    /**
     * 用户报名（参与抽签，所有用户均可参与）
     *
     * 每人每活动仅 1 次：基础 1 票 + 1 个基础抽签码；
     * 更多抽签码经「邀请好友参与」（settleInviteRewards）或「购买」获得。
     *
     * @return array{success:bool, ticketCount:int, payAmount:float, drawCodes:array, inviteRewarded:int}
     */
    public static function register(int $activityId, int $userId, int $ticketCount = 1): array
    {
        $activity = Db::name('raffle_activities')->where('id', $activityId)->whereNull('deleted_at')->find();
        if (!$activity) throw new \RuntimeException('活动不存在');
        if ((int) $activity['status'] !== 1) throw new \RuntimeException('活动不在报名中');

        $now = date('Y-m-d H:i:s');
        if ($now < $activity['registration_start'] || $now > $activity['registration_end']) {
            throw new \RuntimeException('不在报名时间内');
        }

        // 每人每活动仅参与 1 次（重复请求直接拒绝，更多码走邀请/购买）
        $existing = Db::name('raffle_registrations')
            ->where('activity_id', $activityId)->where('user_id', $userId)->find();
        if ($existing) {
            throw new \RuntimeException('您已参与本场抽签，更多抽签码可通过邀请好友或购买获得');
        }

        $drawCodes = [];
        $inviteRewarded = 0;

        Db::startTrans();
        try {
            Db::name('raffle_registrations')->insert([
                'activity_id'  => $activityId,
                'user_id'      => $userId,
                'ticket_count' => 1,
                'pay_amount'   => 0,
                'pay_status'   => 1,
            ]);

            // 参与（报名）即发放 1 个基础抽签码
            $drawCodes[] = DrawCodeService::grant($userId, DrawCodeService::SOURCE_RAFFLE, $activityId);

            // 邀请好友参与得码结算（与报名先后顺序无关，幂等补发）：
            // 1) 被邀请人首参触发其邀请人结算；
            // 2) 若自己也是邀请人（好友先于自己参与），为自己补发应得码
            $inviteRewarded = DrawCodeService::settleInviteRewards($activity, $userId);
            $inviteRewarded += DrawCodeService::settleForInviter($activity, $userId);

            // 参与后该用户在本活动的未使用码 → 已报名
            DrawCodeService::markRegistered($userId, $activityId);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            // 并发重复报名：唯一索引 uk_activity_user 兜底，转友好提示
            if (strpos($e->getMessage(), '1062') !== false || stripos($e->getMessage(), 'Duplicate entry') !== false) {
                throw new \RuntimeException('您已参与本场抽签，更多抽签码可通过邀请好友或购买获得');
            }
            throw $e;
        }

        return ['ticketCount' => 1, 'payAmount' => 0, 'drawCodes' => $drawCodes, 'inviteRewarded' => $inviteRewarded];
    }

    /**
     * 抽签引擎（单阶段开奖，按「签」计名额）
     *
     * 抽签球 = 报名用户的有效抽签码 = 本活动码 + 通用码（邀请奖励等无活动归属的码），
     * 每个码最多中 1 次，一人多码可多次中签。
     * 一次开奖同时完成：
     *   1. 必中用户（is_force_win=1，按 force_set_at 升序）直接获得 1 签；
     *   2. 剩余名额（签）在其余用户的码池中随机抽取（Fisher-Yates 洗牌），
     *      同一用户中签次数达到「单用户最大中签数」后其剩余码不再参与；
     * 最终一次性公布完整中签名单，每人中签次数记入 win_count，
     * 中签的抽签码标记为已中签（status=4）。
     *
     * 开奖完成后 win_locked=1，本次中签名额锁定。
     */
    public static function draw(int $activityId): array
    {
        Db::startTrans();
        try {
            // 行锁重读活动行：防并发双开奖（双击按钮 / 定时任务与手动触发同时到达）
            $activity = Db::name('raffle_activities')->where('id', $activityId)->whereNull('deleted_at')->lock(true)->find();
            if (!$activity) throw new \RuntimeException('活动不存在');
            if ((int) ($activity['win_locked'] ?? 0) === 1) throw new \RuntimeException('该活动已开奖，名额已锁定，不可重复开奖');
            $winnerCount = (int) ($activity['draw_win_count'] ?? 0) ?: (int) $activity['winner_count'];
            // 所有已支付报名
            $regs = Db::name('raffle_registrations')
                ->where('activity_id', $activityId)
                ->where('pay_status', 1)
                ->select()->toArray();

            if (count($regs) === 0) {
                Db::name('raffle_activities')->where('id', $activityId)->update([
                    'status'     => 3,
                    'win_locked' => 1,
                    'drawn_at'   => date('Y-m-d H:i:s'),
                ]);
                Db::commit();
                return [];
            }

            // ---------- 单阶段：必中直接中签 + 剩余名额随机抽取，一次完成 ----------
            // 名额（draw_win_count）按「签」计：每中一次消耗 1 签
            // 必中用户直接获得 1 签（超出名额部分按设置先后截断）
            $forcedUserIds = [];
            $forcedRegs = Db::name('raffle_registrations')
                ->where('activity_id', $activityId)
                ->where('pay_status', 1)
                ->where('is_force_win', 1)
                ->order('force_set_at', 'asc')
                ->order('id', 'asc')
                ->column('user_id');
            foreach ($forcedRegs as $uid) {
                if (count($forcedUserIds) >= $winnerCount) break;
                $forcedUserIds[] = (int) $uid;
            }
            $forcedSet = array_fill_keys($forcedUserIds, true);

            // 单用户最大中签数（0 = 不限，按持有码数自然封顶）
            $maxWinsPerUser = (int) ($activity['max_wins_per_user'] ?? 1);
            $cap = $maxWinsPerUser > 0 ? $maxWinsPerUser : PHP_INT_MAX;
            $winsByUser = [];
            foreach ($forcedUserIds as $uid) $winsByUser[$uid] = 1;

            // 抽签球 = 报名用户的有效抽签码：本活动码 + 通用码（activity_id 为空的码，
            // 如邀请注册奖励，报名任意活动时均作为加成球）；已中签(4)/已失效(3)的码不再入池
            // 历史数据无码记录时按报名票数兜底入池（此时中签码不可标记）
            $codeRows = Db::name('user_draw_codes')
                ->whereRaw('(activity_id = ' . (int) $activityId . ' OR activity_id IS NULL)')
                ->whereIn('status', [DrawCodeService::STATUS_UNUSED, DrawCodeService::STATUS_REGISTERED])
                ->order('id', 'asc')
                ->field('id, user_id')
                ->select()->toArray();
            $codesByUser = [];
            foreach ($codeRows as $cr) $codesByUser[(int) $cr['user_id']][] = (int) $cr['id'];

            $winningCodeIds = [];
            $pool = [];
            foreach ($regs as $reg) {
                $uid = (int) $reg['user_id'];
                if (isset($forcedSet[$uid])) continue; // 必中用户不重复入池
                if (!empty($codesByUser[$uid])) {
                    foreach ($codesByUser[$uid] as $codeId) {
                        $pool[] = ['uid' => $uid, 'code_id' => $codeId];
                    }
                } else {
                    for ($i = 0; $i < (int) $reg['ticket_count']; $i++) {
                        $pool[] = ['uid' => $uid, 'code_id' => null];
                    }
                }
            }

            // 必中用户占用 1 个中签码标记（保持 C 端码选中态一致）
            foreach ($forcedUserIds as $uid) {
                if (!empty($codesByUser[$uid])) $winningCodeIds[] = $codesByUser[$uid][0];
            }

            // Fisher-Yates 洗牌 + 顺序抽取：同一用户中签数达到上限后跳过其剩余码
            shuffle($pool);
            $totalWins = count($forcedUserIds);
            foreach ($pool as $ball) {
                if ($totalWins >= $winnerCount) break;
                $uid = $ball['uid'];
                if (($winsByUser[$uid] ?? 0) >= $cap) continue;
                $winsByUser[$uid] = ($winsByUser[$uid] ?? 0) + 1;
                if (!empty($ball['code_id'])) $winningCodeIds[] = $ball['code_id'];
                $totalWins++;
            }

            // 更新抽签结果（win_count = 中签次数）
            Db::name('raffle_registrations')
                ->where('activity_id', $activityId)
                ->update(['draw_status' => 2, 'win_count' => 0]); // 先全部重置为未中
            foreach ($winsByUser as $uid => $wins) {
                if ($wins <= 0) continue;
                Db::name('raffle_registrations')
                    ->where('activity_id', $activityId)
                    ->where('user_id', $uid)
                    ->update(['draw_status' => 1, 'win_count' => $wins]);
            }

            // 中签的抽签码标记为「已中签」
            if (!empty($winningCodeIds)) {
                Db::name('user_draw_codes')
                    ->whereIn('id', array_values(array_unique($winningCodeIds)))
                    ->update(['status' => DrawCodeService::STATUS_WON]);
            }

            // 快照记录（含必中标记与中签次数）
            $snapshot = [];
            foreach ($winsByUser as $uid => $wins) {
                if ($wins <= 0) continue;
                $user = Db::name('users')->where('id', $uid)->field('id, username, phone')->find();
                if ($user) {
                    $user['is_force_win'] = isset($forcedSet[$uid]) ? 1 : 0;
                    $user['win_count'] = $wins;
                    $snapshot[] = $user;
                }
            }
            Db::name('raffle_activities')->where('id', $activityId)->update([
                'status'      => 3,
                'win_locked'  => 1,
                'drawn_at'    => date('Y-m-d H:i:s'),
                'draw_result' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            ]);

            Db::commit();
            return $snapshot;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 定时任务扫描：到点抽签的活动自动执行
     */
    public static function tick(): array
    {
        $now = date('Y-m-d H:i:s');
        $autoDraws = Db::name('raffle_activities')
            ->where('status', 1)
            ->where('draw_time', '<=', $now)
            ->whereNull('deleted_at')
            ->select()->toArray();

        $results = [];
        foreach ($autoDraws as $activity) {
            try {
                // draw() 事务内行锁防重并直接落到已结束(3)，无需先切抽签中(2)；
                // 失败时保持原状态，下一轮 tick 自动重试
                $results[(int) $activity['id']] = self::draw((int) $activity['id']);
            } catch (\Throwable $e) {
                Db::name('raffle_activities')->where('id', $activity['id'])->update(['status' => 4]);
                $results[(int) $activity['id']] = ['error' => $e->getMessage()];
            }
        }
        return $results;
    }
}
