-- ============================================================================
-- 抽签码体系升级迁移（幂等可重复执行）
-- 1) 抽签码升级为「用户资产」：报名成功 / 邀请好友 / 购买 三种来源发放，可累积
-- 2) 抽签活动增加「购买抽签码」开关与独立单价（与藏品中签价 sale_price 分离）
-- 3) 报名改为免费，移除旧的报名记录单码字段
-- ============================================================================
USE `sinan_nft`;
SET NAMES utf8mb4;

-- 1. 用户抽签码资产表
CREATE TABLE IF NOT EXISTS `nft_user_draw_codes` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     BIGINT UNSIGNED NOT NULL COMMENT '所属用户',
  `code`        VARCHAR(20) NOT NULL COMMENT '抽签码展示串（S+日期6+随机6）',
  `source`      TINYINT NOT NULL DEFAULT 1 COMMENT '来源：1报名 2邀请 3购买',
  `activity_id` BIGINT UNSIGNED NULL COMMENT '来源活动 id（报名发放时非空）',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_user` (`user_id`),
  KEY `idx_activity_user` (`activity_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户抽签码（报名/邀请/购买发放）';

-- 2. 抽签活动：购买抽签码开关 + 独立单价
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'draw_code_enabled');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_activities` ADD COLUMN `draw_code_enabled` TINYINT NOT NULL DEFAULT 0 COMMENT ''是否开放购买抽签码 0关 1开'' AFTER `ticket_price`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'draw_code_price');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_activities` ADD COLUMN `draw_code_price` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT ''抽签码购买单价（与 sale_price 分离）'' AFTER `draw_code_enabled`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. 清理旧的报名记录单码字段（改为资产表后不再使用）
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_registrations' AND COLUMN_NAME = 'draw_code');
SET @ddl := IF(@col > 0,
  'ALTER TABLE `nft_raffle_registrations` DROP COLUMN `draw_code`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;