-- ============================================================================
-- 营销活动升级迁移（幂等可重复执行）
-- 1. 合成活动加 status 开关字段（原前端开关实际无效）
-- 2. 新建抽奖活动实体表（原 activity_id 仅逻辑分组，无名称/状态/时间）
-- 3. 签到活动配置参数（enabled 开关 + 活动名称 + 起止时间）
-- ============================================================================
USE `sinan_nft`;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. 合成活动 status 字段（0 停用 / 1 启用；存量活动保持启用）
-- ---------------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_synthesis_activities' AND COLUMN_NAME = 'status'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `nft_synthesis_activities` ADD COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'状态：0停用 1启用\' AFTER `end_time`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. 抽奖活动实体表
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_lucky_draw_activities` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100) NOT NULL COMMENT '活动名称',
  `status`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '状态：0停用 1启用',
  `start_time` DATETIME(3)  NULL DEFAULT NULL COMMENT '开始时间',
  `end_time`   DATETIME(3)  NULL DEFAULT NULL COMMENT '结束时间',
  `deleted_at` DATETIME     NULL DEFAULT NULL,
  `created_at` DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽奖活动（奖项挂在 activity_id 下）';

-- ---------------------------------------------------------------------------
-- 3. 签到活动配置（system_configs）
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES
('checkin_enabled',      '0',    '签到活动开关（0停用 1启用）'),
('checkin_activity_name', '每日签到', '签到活动名称'),
('checkin_start_time',   '',     '签到活动开始时间（空=长期）'),
('checkin_end_time',     '',     '签到活动结束时间（空=长期）');
