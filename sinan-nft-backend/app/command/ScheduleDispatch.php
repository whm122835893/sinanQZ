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
 *
 * crontab 示例：* * * * * cd /path/to/nft-backend && php think ScheduleDispatch >> runtime/schedule.log 2>&1
 */
class ScheduleDispatch extends Command
{
    protected function configure()
    {
        $this->setName('ScheduleDispatch')
            ->setDescription('定时发售调度：藏品/盲盒自动上架、抽签自动切换状态');
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

        $msg = sprintf(
            "[%s] collectibles_up=%d blindboxes_up=%d raffle_start=%d raffle_draw=%d",
            $now, $collectibleUp, $blindboxUp, $raffleStart, $raffleEnd
        );
        $output->writeln($msg);
        return 0;
    }
}
