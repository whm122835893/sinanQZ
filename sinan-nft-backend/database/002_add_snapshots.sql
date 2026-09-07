-- ============================================================
-- 用户快照功能：持仓快照 + 交易快照（手动触发生成）
-- 持仓快照：触发时刻的持仓状态定格（按 用户×藏品 聚合）
-- 交易快照：按业务发生日期聚合的用户交易流水汇总
-- ============================================================

-- 1. 持仓快照表
CREATE TABLE IF NOT EXISTS `nft_holdings_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `snapshot_date` date NOT NULL COMMENT '快照基准日（触发日期，同日重跑覆盖）',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `collectible_id` int(10) unsigned NOT NULL COMMENT '藏品ID，FK→nft_collectibles.id',
  `collectible_name` varchar(100) NOT NULL DEFAULT '' COMMENT '藏品名称快照（防改名后历史失真）',
  `total_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '总持有（held+consigned+frozen）',
  `held_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '持有中',
  `consigned_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '寄售中',
  `frozen_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '转赠冻结中',
  `avg_cost` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '加权平均成本（acquired_price 均值）',
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) COMMENT '生成时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_date_user_collectible` (`snapshot_date`,`user_id`,`collectible_id`),
  KEY `idx_date` (`snapshot_date`),
  KEY `idx_user` (`user_id`),
  KEY `fk_hs_collectible` (`collectible_id`),
  CONSTRAINT `fk_hs_collectible` FOREIGN KEY (`collectible_id`) REFERENCES `nft_collectibles` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_hs_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户持仓快照表（手动触发，状态定格）';

-- 2. 交易快照表（按日聚合）
CREATE TABLE IF NOT EXISTS `nft_trade_snapshots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `snapshot_date` date NOT NULL COMMENT '统计基准日（按业务发生时间归属，支持补跑任意历史日期）',
  `user_id` bigint(20) unsigned NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `buy_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '购买笔数（当日完成订单）',
  `buy_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '购买总金额（元）',
  `sell_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '寄售成交笔数（作为卖家的完成市场单）',
  `sell_amount` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT '寄售成交总金额（元）',
  `transfer_in_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '受赠件数',
  `transfer_out_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '赠出件数',
  `blindbox_open_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '开盒次数（按奖品资产生成时间）',
  `consume_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '合成/分解消耗次数（按活动记录）',
  `created_at` datetime(3) NOT NULL DEFAULT current_timestamp(3) COMMENT '生成时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_date_user` (`snapshot_date`,`user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_ts_user` FOREIGN KEY (`user_id`) REFERENCES `nft_users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户交易快照表（手动触发，按日聚合）';

-- 3. 权限：数据快照（归报表模块）
INSERT INTO `nft_admin_permissions` (`id`, `name`, `code`, `module`, `type`, `parent_id`, `path`, `icon`, `sort_order`, `status`, `created_at`, `updated_at`)
SELECT 1705, '数据快照', 'report:snapshot', 'report', 2, 1700, '', '', 5, 1, NOW(3), NOW(3)
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `nft_admin_permissions` WHERE `code` = 'report:snapshot');

-- 4. 超管角色分配（role_id=1）
INSERT INTO `nft_admin_role_permissions` (`role_id`, `permission_id`, `created_at`)
SELECT 1, p.id, NOW(3)
FROM `nft_admin_permissions` p
WHERE p.`code` = 'report:snapshot'
  AND NOT EXISTS (
    SELECT 1 FROM `nft_admin_role_permissions` rp
    WHERE rp.role_id = 1 AND rp.permission_id = p.id
  );
