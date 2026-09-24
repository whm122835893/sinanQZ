-- ============================================================================
-- 退款幂等约束升级（审查 C5 配套，幂等可重复执行）
-- nft_refunds 原仅普通索引 idx_order，check-then-insert 并发窗口下同一订单
-- 可产生多条进行中/已完成退款单 → 双重退款。
-- 仿 transfers 的条件唯一生成列做法：一个订单至多一笔非撤销退款单，DB 层兜底。
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
            WHERE `status` IN (1, 2, 3, 4)
            GROUP BY `order_id` HAVING COUNT(*) > 1 LIMIT 1) THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'nft_refunds 存在同一订单多笔退款单，请先人工核对资金并清理数据';
        END IF;

        ALTER TABLE `nft_refunds`
            ADD COLUMN `active_order_id` BIGINT UNSIGNED
                GENERATED ALWAYS AS (CASE WHEN `status` IN (1, 2, 3, 4) THEN `order_id` ELSE NULL END) STORED
                COMMENT '唯一约束辅助列（生成列）：非作废退款单时取订单ID，否则 NULL',
            ADD UNIQUE KEY `uk_active_order` (`active_order_id`);
    END IF;
END$$
DELIMITER ;

CALL `upgrade_refund_idempotency`();
DROP PROCEDURE `upgrade_refund_idempotency`;
