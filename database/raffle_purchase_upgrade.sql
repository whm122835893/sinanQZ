-- ============================================================================
-- 抽签购 C 端入口升级迁移（RF03 修复配套，幂等可重复执行）
-- 报名表增加中签已购计数，用于「中签用户每人可购买数量」限购与并发防超购
-- ============================================================================
USE `sinan_nft`;
SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_registrations' AND COLUMN_NAME = 'purchased_quantity');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_registrations` ADD COLUMN `purchased_quantity` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'中签后已购买数量（限购 sale_quantity）\' AFTER `draw_status`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
