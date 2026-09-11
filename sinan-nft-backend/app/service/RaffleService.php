<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 抽签发售核心服务
 *
 * 职责：
 * - 活动生命周期管理（草稿→报名中→抽签中→已结束）
 * - 用户报名（支持多次、多票）
 * - 抽签引擎（加权随机抽取中奖者）
 * - 中签用户购买资格校验
 */
class RaffleService
{
    /**
     * 创建/保存抽签活动
     */
    public static function saveActivity(int $adminId, array $data): array
    {
        $collectibleId = (int) ($data['collectibleId'] ?? 0);
        $winnerCount   = (int) ($data['winnerCount'] ?? 0);
        $saleQuantity  = (int) ($data['saleQuantity'] ?? 1);
        $ticketPrice   = (float) ($data['ticketPrice'] ?? 0);
        $salePrice     = (float) ($data['salePrice'] ?? 0);
        $regStart      = $data['registrationStart'] ?? '';
        $regEnd        = $data['registrationEnd'] ?? '';
        $drawTime      = $data['drawTime'] ?? '';
        $purchaseStart = $data['purchaseStart'] ?? null;
        $purchaseEnd   = $data['purchaseEnd'] ?? null;

        if ($collectibleId <= 0 || $winnerCount <= 0 || $saleQuantity <= 0) {
            throw new \InvalidArgumentException('参数缺失：藏品、中签数、每人限购');
        }
        if (!strtotime($regStart) || !strtotime($regEnd) || !strtotime($drawTime)) {
            throw new \InvalidArgumentException('时间格式不正确');
        }
        if (strtotime($regEnd) <= strtotime($regStart) || strtotime($drawTime) <= strtotime($regEnd)) {
            throw new \InvalidArgumentException('时间区间无效（报名开始 < 报名截止 < 抽签时间）');
        }

        $payload = [
            'collectible_id'        => $collectibleId,
            'name'                  => (string) ($data['name'] ?? ''),
            'description'           => (string) ($data['description'] ?? ''),
            'ticket_price'          => $ticketPrice,
            'limit_per_user'        => (int) ($data['limitPerUser'] ?? 1),
            'winner_count'          => $winnerCount,
            'sale_quantity'         => $saleQuantity,
            'sale_price'            => $salePrice,
            'registration_start'    => $regStart,
            'registration_end'      => $regEnd,
            'draw_time'             => $drawTime,
            'purchase_start'        => $purchaseStart,
            'purchase_end'          => $purchaseEnd,
            'extra'                 => json_encode(['created_by' => $adminId], JSON_UNESCAPED_UNICODE),
        ];

        if (!empty($data['id'])) {
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
     * 用户报名
     *
     * @return array{success:bool, ticketCount:int, payAmount:float, winner:bool}
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

        // 查重 / 限量
        $existing = Db::name('raffle_registrations')
            ->where('activity_id', $activityId)->where('user_id', $userId)->find();

        $limit = (int) $activity['limit_per_user'];
        $payAmount = round($ticketCount * (float) $activity['ticket_price'], 2);

        Db::startTrans();
        try {
            if ($existing) {
                $newCount = (int) $existing['ticket_count'] + $ticketCount;
                if ($limit > 0 && $newCount > $limit) {
                    throw new \RuntimeException("每人限报 {$limit} 票");
                }
                Db::name('raffle_registrations')->where('id', $existing['id'])->update([
                    'ticket_count' => $newCount,
                    'pay_amount'   => round((float) $existing['pay_amount'] + $payAmount, 2),
                ]);
            } else {
                Db::name('raffle_registrations')->insert([
                    'activity_id'  => $activityId,
                    'user_id'      => $userId,
                    'ticket_count' => $ticketCount,
                    'pay_amount'   => $payAmount,
                    'pay_status'   => (float) $activity['ticket_price'] > 0 ? 0 : 1,
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return ['ticketCount' => $ticketCount, 'payAmount' => $payAmount];
    }

    /**
     * 抽签引擎（随机抽取中奖者）
     *
     * 算法：按每人每票算一张彩票，随机抽取 winner_count 个中奖者；
     * 若同一用户有超过一张中奖，则取不同的 userId 去重（按票数加权）。
     */
    public static function draw(int $activityId): array
    {
        $activity = Db::name('raffle_activities')->where('id', $activityId)->whereNull('deleted_at')->find();
        if (!$activity) throw new \RuntimeException('活动不存在');
        $winnerCount = (int) $activity['winner_count'];

        Db::startTrans();
        try {
            // 所有已支付报名
            $regs = Db::name('raffle_registrations')
                ->where('activity_id', $activityId)
                ->where('pay_status', 1)
                ->select()->toArray();

            if (count($regs) === 0) {
                Db::name('raffle_activities')->where('id', $activityId)->update(['status' => 3]);
                Db::commit();
                return [];
            }

            // 构建彩票池：用户按票数加权
            $pool = [];
            foreach ($regs as $reg) {
                for ($i = 0; $i < (int) $reg['ticket_count']; $i++) {
                    $pool[] = (int) $reg['user_id'];
                }
            }

            // Fisher-Yates 洗牌 + 顺序抽取去重
            // RF11 修复：原实现 array_unique(array_slice(...)) 在同一用户多票被连续抽中时
            // 去重后人数 < winner_count；现改为跳过已中签用户继续抽取，
            // 保证中签人数 = min(winner_count, 不同报名用户数)
            shuffle($pool);
            $pickedUserIds = [];
            foreach ($pool as $uid) {
                if (isset($pickedUserIds[$uid])) {
                    continue;
                }
                $pickedUserIds[$uid] = true;
                if (count($pickedUserIds) >= $winnerCount) {
                    break;
                }
            }
            $pickedUserIds = array_keys($pickedUserIds);

            // 更新抽签结果
            Db::name('raffle_registrations')
                ->where('activity_id', $activityId)
                ->update(['draw_status' => 2]); // 先全部标未中
            Db::name('raffle_registrations')
                ->where('activity_id', $activityId)
                ->whereIn('user_id', $pickedUserIds)
                ->update(['draw_status' => 1]);

            // 快照记录
            $snapshot = [];
            foreach ($pickedUserIds as $uid) {
                $user = Db::name('users')->where('id', $uid)->field('id, username, phone')->find();
                if ($user) $snapshot[] = $user;
            }
            Db::name('raffle_activities')->where('id', $activityId)->update([
                'status'      => 3,
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
                self::changeStatus((int) $activity['id'], 2);
                $results[(int) $activity['id']] = self::draw((int) $activity['id']);
            } catch (\Throwable $e) {
                Db::name('raffle_activities')->where('id', $activity['id'])->update(['status' => 4]);
                $results[(int) $activity['id']] = ['error' => $e->getMessage()];
            }
        }
        return $results;
    }
}
