-- ============================================================================
-- 批量购买升级（C 端市场挂单批量购买）
--   1. nft_orders 新增 batch_listing_ids：批量市场单关联的多个挂单ID（逗号分隔），
--      非空表示该订单为「批量购买」订单（一次买入多份地板价挂单）。
--   2. nft_system_configs 新增批量购买运营配置键。
-- ============================================================================

ALTER TABLE `nft_orders`
  ADD COLUMN `batch_listing_ids` VARCHAR(512) NULL DEFAULT NULL
    COMMENT '批量市场单关联的挂单ID列表（逗号分隔），非空表示批量购买订单'
    AFTER `resale_listing_id`;

INSERT INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES
  ('batch_buy_enabled', '0',    '批量购买开关（1=开启 0=关闭）'),
  ('batch_buy_scope',   'all',  '批量购买适用范围（all=全体用户 specific=指定用户）'),
  ('batch_buy_limit',   '1',    '批量购买单次最大数量（全体用户或指定用户共用）'),
  ('batch_buy_users',   '',     '指定用户手机号列表（换行分隔，仅 batch_buy_scope=specific 时生效）')
ON DUPLICATE KEY UPDATE
  `config_value` = VALUES(`config_value`),
  `description`  = VALUES(`description`);