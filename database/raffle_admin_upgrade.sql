-- ============================================================================
-- 抽签后台模块升级脚本（幂等，可重复执行）
-- 内容：
--   1. 抽签活动表：total_supply / draw_win_count / buy_code_limit /
--      invite_enabled / invite_code_limit / invite_user_needed /
--      max_wins_per_user / win_locked / drawn_at
--      （白名单功能已下线，清理 whitelist_only / limit_per_user / user_max_code 字段）
--   2. 报名记录表：is_force_win / force_set_by / force_set_at /
--      win_paid / win_paid_at / win_verified / win_verified_at / win_count（中签次数）
--   3. 抽签码资产表：status / remark
--   4. 下线清理：抽签白/黑名单表 nft_raffle_whitelists（所有人均可参与）
--   5. 新表：抽签独立操作日志 nft_raffle_operation_logs（只增不删）
-- ============================================================================

-- ----------------------------------------------------------------
-- 1. 抽签活动表 nft_raffle_activities
-- ----------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'total_supply');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_activities` ADD COLUMN `total_supply` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''藏品总发行量'' AFTER `ticket_price`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'draw_win_count');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_activities` ADD COLUMN `draw_win_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''本次抽签中签名额（开奖后锁定）'' AFTER `winner_count`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 购买抽签码：开关沿用 draw_code_enabled / draw_code_price，此处为购买上限
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'buy_code_limit');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_activities` ADD COLUMN `buy_code_limit` INT UNSIGNED NOT NULL DEFAULT 5 COMMENT ''购买抽签码上限（draw_code_enabled=1 时生效，须>0）'' AFTER `draw_win_count`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 邀请好友参与抽签得码：开关 / 可得码上限 / 每邀请 N 人参与得 1 码
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'invite_enabled');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_activities` ADD COLUMN `invite_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''邀请好友参与抽签得码开关 0关 1开'' AFTER `buy_code_limit`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'invite_code_limit');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_activities` ADD COLUMN `invite_code_limit` INT UNSIGNED NOT NULL DEFAULT 5 COMMENT ''邀请好友最多可得抽签码数（invite_enabled=1 时生效）'' AFTER `invite_enabled`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'invite_user_needed');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_activities` ADD COLUMN `invite_user_needed` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''每邀请 N 名好友参与抽签得 1 个码'' AFTER `invite_code_limit`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'max_wins_per_user');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_activities` ADD COLUMN `max_wins_per_user` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''单用户最大中签数（每个码最多中1次）0不限-按码数封顶 默认1每人1签'' AFTER `invite_user_needed`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 码上限模型重构：单用户码总量 = 基础1 + 邀请上限 + 购买上限（动态计算），废弃统一上限字段 user_max_code
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'user_max_code');
SET @ddl := IF(@col > 0,
  'ALTER TABLE `nft_raffle_activities` DROP COLUMN `user_max_code`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'win_locked');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_activities` ADD COLUMN `win_locked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''开奖后名额锁定 0未锁 1已锁'' AFTER `max_wins_per_user`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 白名单功能已下线：清理存量 whitelist_only 字段（所有人均可参与）
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'whitelist_only');
SET @ddl := IF(@col > 0,
  'ALTER TABLE `nft_raffle_activities` DROP COLUMN `whitelist_only`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 每人限报（limit_per_user）已废弃：每人参与 = 报名 1 次（基础 1 码），更多码经邀请/购买获得
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'limit_per_user');
SET @ddl := IF(@col > 0,
  'ALTER TABLE `nft_raffle_activities` DROP COLUMN `limit_per_user`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_activities' AND COLUMN_NAME = 'drawn_at');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_activities` ADD COLUMN `drawn_at` DATETIME NULL COMMENT ''实际开奖时间'' AFTER `win_locked`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 存量活动回填：draw_win_count 默认取 winner_count
UPDATE `nft_raffle_activities` SET `draw_win_count` = `winner_count` WHERE `draw_win_count` = 0 AND `winner_count` > 0;

-- ----------------------------------------------------------------
-- 2. 报名记录表 nft_raffle_registrations
-- ----------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_registrations' AND COLUMN_NAME = 'win_count');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_registrations` ADD COLUMN `win_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''中签次数（每次中签可购中签后限购数量件）'' AFTER `draw_status`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 存量中签记录回填：旧开奖逻辑每人最多 1 签，按 1 回填
UPDATE `nft_raffle_registrations` SET `win_count` = 1 WHERE `draw_status` = 1 AND `win_count` = 0;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_registrations' AND COLUMN_NAME = 'is_force_win');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_registrations` ADD COLUMN `is_force_win` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''强制必中标记 0否 1是'' AFTER `draw_status`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_registrations' AND COLUMN_NAME = 'force_set_by');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_registrations` ADD COLUMN `force_set_by` BIGINT UNSIGNED NULL COMMENT ''设置必中的管理员ID'' AFTER `is_force_win`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_registrations' AND COLUMN_NAME = 'force_set_at');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_registrations` ADD COLUMN `force_set_at` DATETIME NULL COMMENT ''设置必中时间'' AFTER `force_set_by`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_registrations' AND COLUMN_NAME = 'win_paid');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_registrations` ADD COLUMN `win_paid` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''管理员标记已付款 0否 1是'' AFTER `purchased_quantity`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_registrations' AND COLUMN_NAME = 'win_paid_at');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_registrations` ADD COLUMN `win_paid_at` DATETIME NULL COMMENT ''标记付款时间'' AFTER `win_paid`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_registrations' AND COLUMN_NAME = 'win_verified');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_registrations` ADD COLUMN `win_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''已核销 0否 1是'' AFTER `win_paid_at`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_raffle_registrations' AND COLUMN_NAME = 'win_verified_at');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_raffle_registrations` ADD COLUMN `win_verified_at` DATETIME NULL COMMENT ''核销时间'' AFTER `win_verified`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 3. 抽签码资产表 nft_user_draw_codes
-- ----------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_user_draw_codes' AND COLUMN_NAME = 'status');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_user_draw_codes` ADD COLUMN `status` TINYINT NOT NULL DEFAULT 1 COMMENT ''状态 1未使用 2已报名 3已失效'' AFTER `activity_id`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 抽签码状态注释补充 4已中签（开奖时中签的码标记为该状态）
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_user_draw_codes' AND COLUMN_NAME = 'status'
    AND COLUMN_COMMENT NOT LIKE '%已中签%');
SET @ddl := IF(@col > 0,
  'ALTER TABLE `nft_user_draw_codes` MODIFY COLUMN `status` TINYINT NOT NULL DEFAULT 1 COMMENT ''状态 1未使用 2已报名 3已失效 4已中签''',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_user_draw_codes' AND COLUMN_NAME = 'remark');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_user_draw_codes` ADD COLUMN `remark` VARCHAR(255) NULL COMMENT ''备注（作废原因等）'' AFTER `status`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_user_draw_codes' AND INDEX_NAME = 'idx_activity_status');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_user_draw_codes` ADD KEY `idx_activity_status` (`activity_id`, `status`)',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 4. 白名单功能已下线：清理抽签白/黑名单表（所有人均可参与抽签）
-- ----------------------------------------------------------------
DROP TABLE IF EXISTS `nft_raffle_whitelists`;

-- ----------------------------------------------------------------
-- 5. 抽签独立操作日志表 nft_raffle_operation_logs（只增不删，不提供删除接口）
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_raffle_operation_logs` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `admin_id`    BIGINT UNSIGNED NULL COMMENT '操作管理员 ID',
  `admin_name`  VARCHAR(64) NULL COMMENT '操作管理员名称',
  `activity_id` BIGINT UNSIGNED NULL COMMENT '关联抽签活动 ID',
  `action`      VARCHAR(50) NOT NULL COMMENT '动作标识 set_force_win/cancel_force_win/change_quota/change_buy_limit/change_total_supply/draw/code_import/... ',
  `action_desc` VARCHAR(255) NOT NULL COMMENT '动作中文描述',
  `detail`      JSON NULL COMMENT '变更明细（前后值/名单）',
  `ip`          VARCHAR(45) NULL COMMENT '操作 IP',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_activity` (`activity_id`),
  KEY `idx_admin` (`admin_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽签独立操作日志（不可删除）';
