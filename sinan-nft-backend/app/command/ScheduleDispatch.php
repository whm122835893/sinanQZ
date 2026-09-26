<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 定时发售调度（Cron 每分钟运行一次）
 *
 * 职责：
 * 1. 扫描 collectibles / blind_boxes 的 schedule_time，到期自动将 status 从 upcoming → onsale
 * 2. 扫描 blind_boxes 的 schedule_time，到期自动 open
 * 3. 扫描 raffle_activities 报名开始/结束状态切换
 * 4. 扫描过期 pending 订单自动取消并释放 locked_quantity / 恢复寄售挂单（防库存被永久锁定）
 *
 * crontab 示例：* * * * * cd /path/to/nft-backend && php think ScheduleDispatch >> runtime/schedule.log 2>&1
 */
class ScheduleDispatch extends Command
{
    protected function configure()
    {
        $this->setName('ScheduleDispatch')
            ->setDescription('定时发售调度：藏品/盲盒自动上架、抽签自动切换状态、过期订单自动取消');
    }

    protected function execute(Input $input, Output $output)
    {
        $now = date('Y-m-d H:i:s');
        $collectibleUp = 0;
        $blindboxUp = 0;
        $raffleStart = 0;
        $raffleEnd = 0;

        // 1. 藏品定时上架
        $affected = Db::name('collectibles')
            ->where('is_scheduled', 1)
            ->where('schedule_time', '<=', $now)
            ->where('status', 'upcoming')
            ->update(['status' => 'onsale', 'onsale_at' => $now]);
        $collectibleUp = (int) $affected;

        // 2. 盲盒定时上架
        $affected = Db::name('blind_boxes')
            ->alias('bb')
            ->join('collectibles c', 'c.id = bb.collectible_id')
            ->where('c.status', 'upcoming')
            ->where('bb.is_scheduled', 1)
            ->where('bb.schedule_time', '<=', $now)
            ->update(['c.status' => 'onsale', 'c.onsale_at' => $now]);
        $blindboxUp = (int) $affected;

        // 3. 抽签活动状态流转
        //    报名开始：status=0 & registration_start<=now → status=1
        $raffleStart = (int) Db::name('raffle_activities')
            ->where('status', 0)
            ->where('registration_start', '<=', $now)
            ->whereNull('deleted_at')
            ->update(['status' => 1]);
        //    报名结束：status=1 & registration_end<=now & draw_time>now → 保持 status=1 但不再允许报名
        //    抽签开始：status=1 & draw_time<=now → status=2 + 执行抽签（调用 RaffleService::tick）
        if (class_exists(\app\service\RaffleService::class)) {
            $results = \app\service\RaffleService::tick();
            foreach ($results as $aid => $result) {
                $raffleEnd++;
                $output->writeln("  [RAFFLE] activity #{$aid} drawn, winners=" . count($result));
            }
        }

        // 4. 过期 pending 订单自动取消（释放 locked_quantity / 恢复寄售挂单）
        $expiredCancelled = $this->cancelExpiredOrders($output);

        $msg = sprintf(
            "[%s] collectibles_up=%d blindboxes_up=%d raffle_start=%d raffle_draw=%d orders_expired_cancelled=%d",
            $now, $collectibleUp, $blindboxUp, $raffleStart, $raffleEnd, $expiredCancelled
        );
        $output->writeln($msg);
        return 0;
    }

    /**
     * 扫描已过期（expires_at < now）的 pending 订单，逐单取消并释放锁定的库存/挂单。
     *
     * 复用 Orders::cancel 的释放口径：
     * - release/priority/eligibility：collectibles.locked_quantity -= quantity（带 locked_quantity>=qty 守恒守卫）
     * - priority：另回退 priority_sale_whitelists.used_quantity
     * - market：将 resale_listings 由 sold 恢复为 selling（批量单恢复 batch_listing_ids 全部）
     * - raffle：抽签购订单创建即为 completed，不存在 pending，无需处理
     *
     * 每单独立事务 + try/catch，单笔失败不阻断其余订单清理。
     */
    private function cancelExpiredOrders(Output $output): int
    {
        $now = date('Y-m-d H:i:s');
        $nowV = date('Y-m-d H:i:s.v');

        $expired = Db::name('orders')
            ->where('status', 'pending')
            ->where('expires_at', '<', $nowV)
            ->where('expires_at', 'not null')
            ->order('id', 'asc')
            ->limit(500)
            ->select()
            ->toArray();

        $cancelled = 0;
        foreach ($expired as $order) {
            try {
                Db::startTrans();

                // 行锁取单，防止与用户主动取消/支付并发双写
                $locked = Db::name('orders')
                    ->where('id', $order['id'])
                    ->where('status', 'pending')
                    ->lock(true)
                    ->find();
                if (!$locked) {
                    Db::rollback();
                    continue;
                }

                Db::name('orders')->where('id', $locked['id'])->update([
                    'status'       => 'cancelled',
                    'cancelled_at' => $nowV,
                    'updated_at'   => $nowV,
                ]);

                if (in_array($locked['source'], ['release', 'priority', 'eligibility'], true)) {
                    // 条件释放锁定库存（配合 CHECK 防负数）
                    Db::name('collectibles')
                        ->where('id', $locked['collectible_id'])
                        ->whereRaw('locked_quantity >= ' . (int) $locked['quantity'])
                        ->update([
                            'locked_quantity' => Db::raw('locked_quantity - ' . (float) $locked['quantity']),
                            'updated_at'      => $nowV,
                        ]);

                    // 优先购单：回退白名单已用配额
                    if ($locked['source'] === 'priority') {
                        $wlId = Db::name('priority_sale_whitelists')->alias('w')
                            ->join('priority_sales ps', 'ps.id = w.priority_sale_id', 'INNER')
                            ->where('ps.collectible_id', $locked['collectible_id'])
                            ->where('w.user_id', $locked['user_id'])
                            ->value('w.id');
                        if ($wlId) {
                            Db::name('priority_sale_whitelists')
                                ->where('id', $wlId)
                                ->whereRaw('used_quantity >= ' . (int) $locked['quantity'])
                                ->update([
                                    'used_quantity' => Db::raw('used_quantity - ' . (int) $locked['quantity']),
                                    'updated_at'    => $now,
                                ]);
                        }
                    }
                } elseif ($locked['source'] === 'market') {
                    // 市场单：资产从未过户（支付时才过户），恢复挂单在售
                    $listingIds = [];
                    if (!empty($locked['batch_listing_ids'])) {
                        $listingIds = array_values(array_filter(array_map('intval', explode(',', $locked['batch_listing_ids']))));
                    } elseif ($locked['resale_listing_id']) {
                        $listingIds = [(int) $locked['resale_listing_id']];
                    }
                    foreach ($listingIds as $lid) {
                        Db::name('resale_listings')
                            ->where('id', $lid)
                            ->where('status', 'sold')
                            ->update(['status' => 'selling', 'updated_at' => $nowV]);
                    }
                }

                Db::commit();
                $cancelled++;
            } catch (\Throwable $e) {
                Db::rollback();
                $output->writeln("  [ORDER-EXPIRE] order #{$order['id']} cancel failed: " . $e->getMessage());
            }
        }
        return $cancelled;
    }
}
