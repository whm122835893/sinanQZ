-- ============================================================
-- 司南珍藏 · 全功能升级 SQL（v3.0）
-- 新增模块：抽签发售 / 求购挂单 / 置换 / 分解藏品 / 内容审核
-- 技术债修复：定时发售调度（collectibles.schedule_time 字段）
-- 运行方式：在 init.sql 基础上执行本脚本（幂等：IF NOT EXISTS）
-- ============================================================

-- ----------------------------------------------------------------
-- 1. 抽签发售（Raffle Sale）
--    流程：B 端创建抽签活动 → 配置中签数量 → C 端用户报名 →
--    截止后系统随机抽取 → 中签用户获得购买资格 → 有效期内购买
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_raffle_activities` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `collectible_id` BIGINT UNSIGNED NOT NULL COMMENT '藏品 ID',
  `name`          VARCHAR(120) NOT NULL COMMENT '活动名称',
  `description`   TEXT NULL COMMENT '活动规则说明',
  `ticket_price`  DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '抽签报名费（0=免费）',
  `winner_count`  INT UNSIGNED NOT NULL COMMENT '中签名额数',
  `sale_quantity`  INT UNSIGNED NOT NULL COMMENT '中签用户每人可购买数量',
  `sale_price`    DECIMAL(10,2) NOT NULL COMMENT '中签后购买价',
  `registration_start` DATETIME NOT NULL COMMENT '报名开始时间',
  `registration_end`   DATETIME NOT NULL COMMENT '报名截止时间',
  `draw_time`     DATETIME NOT NULL COMMENT '抽签时间（自动执行）',
  `purchase_start` DATETIME NULL COMMENT '中签购买有效期开始',
  `purchase_end`   DATETIME NULL COMMENT '中签购买有效期结束',
  `status`        TINYINT NOT NULL DEFAULT 0 COMMENT '0草稿 1报名中 2抽签中 3已结束 4已取消',
  `draw_result`   JSON NULL COMMENT '抽签结果快照 [{user_id, user_name, phone}]',
  `extra`         JSON NULL COMMENT '扩展字段',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME NULL DEFAULT NULL,
  KEY `idx_collectible` (`collectible_id`),
  KEY `idx_status` (`status`),
  KEY `idx_draw_time` (`draw_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽签发售活动';

CREATE TABLE IF NOT EXISTS `nft_raffle_registrations` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `activity_id`   BIGINT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `ticket_count`  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '报名票数',
  `pay_amount`    DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '报名费合计',
  `pay_status`    TINYINT NOT NULL DEFAULT 0 COMMENT '0未支付 1已支付 2已退款',
  `draw_status`   TINYINT NOT NULL DEFAULT 0 COMMENT '0未抽签 1中签 2未中',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_activity_user` (`activity_id`, `user_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_draw_status` (`draw_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽签发售报名记录';

-- ----------------------------------------------------------------
-- 2. 求购挂单（Buy Request）
--    用户主动挂出求购需求，卖家可接单；接单后自动创建订单
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_buy_requests` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `collectible_id` BIGINT UNSIGNED NOT NULL COMMENT '目标藏品 ID',
  `user_id`       BIGINT UNSIGNED NOT NULL COMMENT '求购发起者',
  `price`         DECIMAL(10,2) NOT NULL COMMENT '求购单价',
  `quantity`      INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '求购数量',
  `status`        TINYINT NOT NULL DEFAULT 1 COMMENT '1求购中 2已接单 3已取消 4已成交 5已过期',
  `accepted_by`   BIGINT UNSIGNED NULL COMMENT '接单用户（卖家）',
  `accepted_at`   DATETIME NULL,
  `order_no`      VARCHAR(64) NULL COMMENT '成交后关联订单号',
  `remark`        VARCHAR(500) NULL,
  `expires_at`    DATETIME NULL COMMENT '求购有效期',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME NULL DEFAULT NULL,
  KEY `idx_collectible` (`collectible_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='求购挂单';

-- ----------------------------------------------------------------
-- 3. 置换（Swap）
--    用户 A 用藏品 X 直接换用户 B 的藏品 Y（可加差价）
--    与 Activity.vue「置换」tab 对应
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_swap_offers` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `offer_user_id` BIGINT UNSIGNED NOT NULL COMMENT '发起置换的用户',
  `offer_collectible_id` BIGINT UNSIGNED NOT NULL COMMENT '发起方给出的藏品',
  `offer_serial`  VARCHAR(64) NOT NULL COMMENT '发起方藏品序列号',
  `target_collectible_id` BIGINT UNSIGNED NOT NULL COMMENT '期望换到的藏品',
  `target_user_id` BIGINT UNSIGNED NULL COMMENT '指定换给谁（NULL=公开）',
  `cash_diff`     DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '差价（正=发起方补钱，负=对方补钱）',
  `status`        TINYINT NOT NULL DEFAULT 1 COMMENT '1挂单中 2已接受 3已拒绝 4已撤销 5已完成 6已过期',
  `accepted_by`   BIGINT UNSIGNED NULL,
  `accepted_at`   DATETIME NULL,
  `expires_at`    DATETIME NULL,
  `remark`        VARCHAR(500) NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME NULL DEFAULT NULL,
  KEY `idx_offer_user` (`offer_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_target` (`target_collectible_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='置换挂单';

CREATE TABLE IF NOT EXISTS `nft_swap_records` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `offer_id`      BIGINT UNSIGNED NOT NULL,
  `offer_user_id` BIGINT UNSIGNED NOT NULL,
  `accept_user_id` BIGINT UNSIGNED NOT NULL,
  `offer_collectible_id` BIGINT UNSIGNED NOT NULL,
  `accept_collectible_id` BIGINT UNSIGNED NOT NULL,
  `cash_diff`     DECIMAL(10,2) NOT NULL DEFAULT 0,
  `status`        TINYINT NOT NULL DEFAULT 1 COMMENT '1资金冻结 2藏品A转出 3藏品B转出 4完成 5失败回滚',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_offer` (`offer_id`),
  KEY `idx_users` (`offer_user_id`, `accept_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='置换完成记录';

-- ----------------------------------------------------------------
-- 4. 分解藏品（Decompose / Refine）
--    指定藏品可分解为指定藏品 1~N 个（配置化，类似合成的反向）
--    B 端配置分解公式 → C 端用户持有藏品可分解 → 后端扣旧加新
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_decompose_rules` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(120) NOT NULL COMMENT '分解规则名称',
  `source_collectible_id` BIGINT UNSIGNED NOT NULL COMMENT '分解的源藏品',
  `enabled`       TINYINT NOT NULL DEFAULT 1,
  `per_user_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '每人限分解次数（0=不限）',
  `daily_limit`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '平台每日上限（0=不限）',
  `start_time`    DATETIME NULL,
  `end_time`      DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`    DATETIME NULL DEFAULT NULL,
  KEY `idx_source` (`source_collectible_id`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分解规则';

CREATE TABLE IF NOT EXISTS `nft_decompose_items` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `rule_id`       BIGINT UNSIGNED NOT NULL,
  `result_collectible_id` BIGINT UNSIGNED NOT NULL COMMENT '分解后产出的藏品',
  `quantity_per`  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '每分解 1 件源藏品产出的数量',
  KEY `idx_rule` (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分解产出明细';

CREATE TABLE IF NOT EXISTS `nft_decompose_records` (
  `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `rule_id`       BIGINT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `source_collectible_id` BIGINT UNSIGNED NOT NULL,
  `source_serial` VARCHAR(64) NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_user` (`user_id`),
  KEY `idx_rule` (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分解执行记录';

-- ----------------------------------------------------------------
-- 5. 给 collectibles 加 schedule_time（定时发售调度）
--    原表有 onsale_at 作为发售时间点，但没有"到点自动上架"的调度字段
-- ----------------------------------------------------------------
-- MySQL 8.0 不支持 ADD COLUMN IF NOT EXISTS（MariaDB 语法），改用 information_schema 幂等判断
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_collectibles' AND COLUMN_NAME = 'schedule_time');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_collectibles` ADD COLUMN `schedule_time` DATETIME NULL COMMENT \'定时上架时间（到点自动从 upcoming→onsale）\' AFTER `onsale_at`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_collectibles' AND COLUMN_NAME = 'is_scheduled');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_collectibles` ADD COLUMN `is_scheduled` TINYINT NOT NULL DEFAULT 0 COMMENT \'是否定时发售（1=启用）\' AFTER `schedule_time`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_blind_boxes' AND COLUMN_NAME = 'schedule_time');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_blind_boxes` ADD COLUMN `schedule_time` DATETIME NULL COMMENT \'定时上架时间\' AFTER `opened_count`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_blind_boxes' AND COLUMN_NAME = 'is_scheduled');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_blind_boxes` ADD COLUMN `is_scheduled` TINYINT NOT NULL DEFAULT 0 COMMENT \'是否定时发售\' AFTER `schedule_time`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 6. 回收站：给核心业务表补 deleted_at 软删除字段
--    （init.sql 注释说"所有删除均为软删除"，但实际有些表没加）
-- ----------------------------------------------------------------
-- nft_banners 已有 deleted_at？先检查
-- ALTER TABLE `nft_banners` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL;
-- ALTER TABLE `nft_announcements` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL;
-- ALTER TABLE `nft_orders` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL;
-- ALTER TABLE `nft_resale_listings` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL;
-- ALTER TABLE `nft_transfers` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL;
-- ALTER TABLE `nft_blind_boxes` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL;
-- （这些表在 init.sql 中已定义 deleted_at，无需重复加）
