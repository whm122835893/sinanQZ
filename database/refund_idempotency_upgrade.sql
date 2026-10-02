-- ============================================================================
-- 退款幂等约束升级（审查 C5 配套，幂等可重复执行）
-- nft_refunds 原仅普通索引 idx_order，check-then-insert 并发窗口下同一订单
-- 可产生多条进行中/已完成退款单 → 双重退款。
-- 仿 transfers 的条件唯一生成列做法：一个订单至多一笔「处理中/已退款」退款单，
-- DB 层兜底；已拒绝(4)不占额度，与 OrderController 申请守卫 whereIn [1,2,3] 同语义，
-- 驳回后仍可重新发起退款。
-- 注：生成列用 VIRTUAL 而非 STORED —— 本表是 FK 子表，STORED 列的 COPY 重建
-- 在 MySQL 8.0.28 上报 1215；VIRTUAL 加列与加唯一索引均可 INPLACE，
-- 且虚拟列上的唯一索引物化生效，约束语义完全一致。
-- ============================================================================
USE `sinan_nft`;
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS `upgrade_refund_idempotency`;
DELIMITER $$
CREATE PROCEDURE `upgrade_refund_idempotency`()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_refunds' AND COLUMN_NAME = 'active_order_id') THEN

        IF EXISTS (SELECT `order_id` FROM `nft_refunds`
            WHERE `status` IN (1, 2, 3)
            GROUP BY `order_id` HAVING COUNT(*) > 1 LIMIT 1) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'nft_refunds 存在同一订单多笔退款单，请先人工核对资金并清理数据';
        END IF;

        ALTER TABLE `nft_refunds`
            ADD COLUMN `active_order_id` BIGINT UNSIGNED
                GENERATED ALWAYS AS (CASE WHEN `status` IN (1, 2, 3) THEN `order_id` ELSE NULL END) VIRTUAL
                COMMENT '唯一约束辅助列（生成列）：处理中/已退款订单ID，已拒绝(4)为 NULL 允许重新发起退款',
            ALGORITHM = INPLACE;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_refunds' AND INDEX_NAME = 'uk_active_order') THEN
        ALTER TABLE `nft_refunds`
            ADD UNIQUE KEY `uk_active_order` (`active_order_id`),
            ALGORITHM = INPLACE;
    END IF;
END$$
DELIMITER ;

CALL `upgrade_refund_idempotency`();
DROP PROCEDURE `upgrade_refund_idempotency`;
