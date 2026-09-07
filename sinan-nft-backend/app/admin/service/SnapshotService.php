<?php
declare(strict_types=1);

namespace app\admin\service;

use think\facade\Db;

/**
 * 用户快照服务：持仓快照 + 交易快照（手动触发生成，幂等可重跑）
 *
 * - 持仓快照：触发时刻的持仓状态定格（用户 × 藏品 聚合行）
 *   同日重跑 = 删除当日旧行后按当前状态重新聚合（覆盖语义）
 * - 交易快照：按业务发生日期聚合的用户交易汇总（买入/卖出/转赠/开盒/消耗）
 *   历史事实不可变，支持补跑任意历史日期；同日重跑覆盖
 */
class SnapshotService
{
    /**
     * 生成指定日期的两类快照
     *
     * @param string   $date   基准日期 Y-m-d（持仓=当日定格；交易=当日发生的流水）
     * @param int|null $userId 指定用户则只生成该用户（null=全部用户）
     * @return array{holdings:int, trades:int} 各表写入行数
     */
    public function generate(string $date, ?int $userId = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('日期格式需为 Y-m-d');
        }
        if ($userId !== null && $userId < 1) {
            throw new \InvalidArgumentException('用户ID不合法');
        }

        Db::startTrans();
        try {
            $holdings = $this->generateHoldings($date, $userId);
            $trades   = $this->generateTrades($date, $userId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return ['holdings' => $holdings, 'trades' => $trades];
    }

    /**
     * 持仓快照：当前持有状态（held/consigned/frozen）聚合定格
     * 幂等：先删该日（可选用户）旧行再插入
     */
    private function generateHoldings(string $date, ?int $userId): int
    {
        $del = Db::name('holdings_snapshots')->where('snapshot_date', $date);
        if ($userId !== null) $del->where('user_id', $userId);
        $del->delete();

        // 聚合 INSERT...SELECT：状态分布 + 藏品名快照 + 平均成本
        $sql = <<<SQL
INSERT INTO nft_holdings_snapshots
    (snapshot_date, user_id, collectible_id, collectible_name, total_count, held_count, consigned_count, frozen_count, avg_cost)
SELECT ?, uc.user_id, uc.collectible_id, c.name,
       COUNT(*),
       SUM(uc.status = 'held'),
       SUM(uc.status = 'consigned'),
       SUM(uc.status = 'frozen'),
       ROUND(AVG(uc.acquired_price), 2)
FROM nft_user_collectibles uc
INNER JOIN nft_collectibles c ON c.id = uc.collectible_id
WHERE uc.status IN ('held', 'consigned', 'frozen')
SQL;
        $bind = [$date];
        if ($userId !== null) {
            $sql .= ' AND uc.user_id = ?';
            $bind[] = $userId;
        }
        $sql .= ' GROUP BY uc.user_id, uc.collectible_id, c.name';

        Db::execute($sql, $bind);
        $del2 = Db::name('holdings_snapshots')->where('snapshot_date', $date);
        if ($userId !== null) $del2->where('user_id', $userId);
        return (int) $del2->count();
    }

    /**
     * 交易快照：按发生日期聚合（完成订单 / 寄售成交 / 转赠 / 开盒 / 合成分解）
     * 幂等：先删该日（可选用户）旧行再插入
     */
    private function generateTrades(string $date, ?int $userId): int
    {
        $del = Db::name('trade_snapshots')->where('snapshot_date', $date);
        if ($userId !== null) $del->where('user_id', $userId);
        $del->delete();

        // 边界走索引：[当日 00:00:00, 次日 00:00:00)
        $start = $date . ' 00:00:00';
        $end   = date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));

        $sql = <<<SQL
INSERT INTO nft_trade_snapshots
    (snapshot_date, user_id, buy_count, buy_amount, sell_count, sell_amount,
     transfer_in_count, transfer_out_count, blindbox_open_count, consume_count)
SELECT ?, t.user_id,
       SUM(t.buy_count), SUM(t.buy_amount), SUM(t.sell_count), SUM(t.sell_amount),
       SUM(t.transfer_in_count), SUM(t.transfer_out_count), SUM(t.blindbox_open_count), SUM(t.consume_count)
FROM (
    -- 买入：买家当日完成订单（paid_at 记支付完成）
    SELECT o.user_id, 1 AS buy_count, o.total_price AS buy_amount,
           0 AS sell_count, 0 AS sell_amount, 0 AS transfer_in_count, 0 AS transfer_out_count,
           0 AS blindbox_open_count, 0 AS consume_count
    FROM nft_orders o
    WHERE o.status = 'completed' AND o.paid_at >= ? AND o.paid_at < ?

    UNION ALL
    -- 卖出：寄售成交，卖家侧实收金额（actual_amount 为成交时点快照）
    SELECT rl.seller_id, 0, 0, 1, rl.actual_amount, 0, 0, 0, 0
    FROM nft_orders o
    INNER JOIN nft_resale_listings rl ON rl.id = o.resale_listing_id
    WHERE o.status = 'completed' AND o.source = 'market' AND o.paid_at >= ? AND o.paid_at < ?

    UNION ALL
    -- 赠出：转赠发起方（成功态 accepted，confirmed_at 记确认时间）
    SELECT tf.from_user_id, 0, 0, 0, 0, 0, 1, 0, 0
    FROM nft_transfers tf
    WHERE tf.status = 'accepted' AND tf.confirmed_at >= ? AND tf.confirmed_at < ?

    UNION ALL
    -- 受赠：转赠接收方
    SELECT tf.to_user_id, 0, 0, 0, 0, 1, 0, 0, 0
    FROM nft_transfers tf
    WHERE tf.status = 'accepted' AND tf.confirmed_at >= ? AND tf.confirmed_at < ?

    UNION ALL
    -- 开盒：奖品资产生成时间即开盒时间
    SELECT uc.user_id, 0, 0, 0, 0, 0, 0, 1, 0
    FROM nft_user_collectibles uc
    WHERE uc.source = 'blindbox' AND uc.created_at >= ? AND uc.created_at < ?

    UNION ALL
    -- 消耗：合成活动参与记录
    SELECT sr.user_id, 0, 0, 0, 0, 0, 0, 0, 1
    FROM nft_synthesis_records sr
    WHERE sr.created_at >= ? AND sr.created_at < ?

    UNION ALL
    -- 消耗：分解活动参与记录
    SELECT dr.user_id, 0, 0, 0, 0, 0, 0, 0, 1
    FROM nft_decompose_records dr
    WHERE dr.created_at >= ? AND dr.created_at < ?
) t
SQL;
        $bind = [$date, $start, $end, $start, $end, $start, $end, $start, $end, $start, $end, $start, $end, $start, $end];

        if ($userId !== null) {
            // 外层限定用户（保留全部来源 UNION，语义与全量一致）
            $sql .= ' WHERE t.user_id = ?';
            $bind[] = $userId;
        }
        $sql .= ' GROUP BY t.user_id';

        Db::execute($sql, $bind);

        $cnt = Db::name('trade_snapshots')->where('snapshot_date', $date);
        if ($userId !== null) $cnt->where('user_id', $userId);
        return (int) $cnt->count();
    }
}
