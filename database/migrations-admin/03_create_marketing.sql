-- ============================================================================
-- 03_create_marketing.sql — 资格购/优先购/配额与营销补全新表（文档 4.2.3 + 4.2.4）
-- 幂等可重跑（CREATE TABLE IF NOT EXISTS）
-- P1 使用：nft_inventory_quotas（配额）、nft_qualification_*（资格购配置）；
-- 优先购/抽奖活动表随 P2 营销模块启用，先行建表不改任何现有功能。
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 4.2.3 资格购与优先购（4 张）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_qualification_configs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `collectible_id` INT UNSIGNED NOT NULL COMMENT '目标藏品（谁需要资格才能买）',
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `required_collectible_ids` JSON NULL COMMENT '资格藏品ID数组 [1,2,3]，持有任一即满足',
  `required_checkin_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计签到天数，0=不限',
  `required_invite_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计邀请人数，0=不限',
  `condition_type` TINYINT NOT NULL DEFAULT 1 COMMENT '1满足任一 2满足全部（白名单手机号始终满足）',
  `valid_start_at` DATETIME(3) NULL, `valid_end_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), UNIQUE KEY `uk_collectible` (`collectible_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='资格购配置（购买门槛，不占配额不冻结库存）';

CREATE TABLE IF NOT EXISTS `nft_qualification_whitelists` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_id` INT UNSIGNED NOT NULL COMMENT 'FK→nft_qualification_configs.id',
  `user_id` BIGINT UNSIGNED NOT NULL,
  `phone` VARCHAR(11) NOT NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1有效 0失效',
  `expires_at` DATETIME(3) NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), UNIQUE KEY `uk_config_user` (`config_id`,`user_id`), KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='资格购白名单（额外手机号，免条件）';

CREATE TABLE IF NOT EXISTS `nft_priority_sales` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL COMMENT '活动名称',
  `collectible_id` INT UNSIGNED NOT NULL COMMENT '目标藏品',
  `start_time` DATETIME(3) NOT NULL COMMENT '优先购开始（须早于等于公售开始）',
  `end_time` DATETIME(3) NOT NULL COMMENT '优先购结束',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), KEY `idx_collectible` (`collectible_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优先购活动（时间优先通道，与资格购完全独立）';

CREATE TABLE IF NOT EXISTS `nft_priority_sale_whitelists` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `priority_sale_id` INT UNSIGNED NOT NULL COMMENT 'FK→nft_priority_sales.id',
  `user_id` BIGINT UNSIGNED NOT NULL,
  `phone` VARCHAR(11) NOT NULL,
  `max_quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '最大可购数量',
  `used_quantity` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已用数量',
  `expires_at` DATETIME(3) NOT NULL COMMENT '资格有效期（精确到时分秒）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1有效 0已清理/停用',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), UNIQUE KEY `uk_sale_user` (`priority_sale_id`,`user_id`), KEY `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='优先购白名单';

-- ---------------------------------------------------------------------------
-- 4.2.4 配额与营销补全（3 张）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_inventory_quotas` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `collectible_id` INT UNSIGNED NOT NULL,
  `quota_type` TINYINT NOT NULL COMMENT '1优先购 2活动空投 3签到 4注册 5邀请 6抽奖 7其他',
  `quota_name` VARCHAR(100) NOT NULL,
  `planned_quantity` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '计划数量',
  `used_quantity` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已使用数量（不可减至小于此值）',
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0停用（停用释放未用部分回库存池）',
  `activity_id` INT UNSIGNED NULL COMMENT '关联活动ID（可选）',
  `activity_type` VARCHAR(50) NULL COMMENT 'airdrop/checkin/invite/lucky_draw/synthesis/register',
  `remark` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NOT NULL COMMENT '管理员ID',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), KEY `idx_collectible` (`collectible_id`), KEY `idx_type` (`quota_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='库存配额预留（配置时从库存池冻结）';

CREATE TABLE IF NOT EXISTS `nft_lucky_draw_activities` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(500) NULL,
  `free_chances_daily` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '每日免费次数',
  `exchange_config` JSON NULL COMMENT '兑换规则 [{collectibleId,count,chances}] 消耗藏品换抽奖次数',
  `start_time` DATETIME(3) NULL, `end_time` DATETIME(3) NULL,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽奖活动主表（查缺补漏新增：原库 prizes.activity_id 无主表）';

CREATE TABLE IF NOT EXISTS `nft_lucky_draw_chances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `activity_id` INT UNSIGNED NOT NULL,
  `source` ENUM('free','exchange','checkin','invite','airdrop','register') NOT NULL COMMENT '来源',
  `total_quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '发放数量',
  `used_quantity` INT UNSIGNED NOT NULL DEFAULT 0,
  `expire_at` DATETIME(3) NULL COMMENT '过期时间，NULL=当期有效',
  `related_id` BIGINT UNSIGNED NULL COMMENT '来源业务ID（空投记录/签到记录等）',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`), KEY `idx_user_activity` (`user_id`,`activity_id`), KEY `idx_expire` (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='抽奖次数台账（查缺补漏新增：原库无次数约束，抽奖无限次）';
