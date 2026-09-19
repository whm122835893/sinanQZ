-- 20260919_orders_source_raffle.sql
-- orders.source 枚举扩展：抽签购渠道（中签购买订单 source='raffle'，
-- 与公售/优先购/资格购/市场寄售并列，保持三渠道独立可追溯）

ALTER TABLE `nft_orders`
    MODIFY COLUMN `source` ENUM('release','market','priority','eligibility','raffle')
    NOT NULL DEFAULT 'release'
    COMMENT '订单来源：release 公售 / market 市场寄售 / priority 优先购 / eligibility 资格购 / raffle 抽签购';
