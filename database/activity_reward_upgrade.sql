-- ============================================================================
-- 活动奖励体系升级迁移（幂等可重复执行）
-- 1. 统一奖励名单表 nft_activity_reward_records（记录名单 → 导出 → 统一发放）
-- 2. 合成活动：合成数量 / 参与资格 / 发放方式
-- 3. 抽奖活动：参与资格 / 发放方式；奖项扩展（名称/图片/奖励配置/六类奖励类型）
-- 4. 邀请活动：邀请档位奖励 / 被邀请人奖励与完成条件 / 发放方式
-- 5. 注册活动表 nft_register_activities（实名前 N 名档位奖励）
-- 6. 签到活动：参与资格 / 发放方式（system_configs）
-- 7. 奖励发放依赖表：nft_priority_sales / nft_priority_sale_whitelists / nft_lucky_draw_chances
-- ============================================================================
USE `sinan_nft`;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. 统一奖励名单表（manual 发放模式：只记录，后台导出名单统一发放）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_activity_reward_records` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `activity_type`  ENUM('synthesis','lucky_draw','checkin','invite','register') NOT NULL COMMENT '活动类型',
  `activity_id`    INT UNSIGNED    NOT NULL COMMENT '活动ID（对应各活动表主键）',
  `activity_title` VARCHAR(100)    NOT NULL DEFAULT '' COMMENT '活动名称快照',
  `user_id`        BIGINT UNSIGNED NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `phone`          VARCHAR(11)     NOT NULL DEFAULT '' COMMENT '用户手机号快照',
  `reward_type`    ENUM('collectible','points','draw_chance','priority_qualification','eligibility_qualification','blindbox','none') NOT NULL COMMENT '奖励类型',
  `reward_config`  JSON            NULL DEFAULT NULL COMMENT '奖励配置快照（collectibleId/quantity/amount/expiresAt 等）',
  `reward_label`   VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '奖励描述（导出名单展示）',
  `status`         ENUM('pending','issued','failed','cancelled') NOT NULL DEFAULT 'pending' COMMENT '状态：pending待发放/issued已发放/failed发放失败/cancelled已取消',
  `issue_result`   VARCHAR(255)    NULL DEFAULT NULL COMMENT '统一发放结果（成功/失败原因）',
  `issued_at`      DATETIME(3)     NULL DEFAULT NULL COMMENT '实际发放时间',
  `dedupe_key`     VARCHAR(128)    NULL DEFAULT NULL COMMENT '去重键（活动+用户+奖励位，防重复入账；uk）',
  `created_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dedupe_key` (`dedupe_key`),
  KEY `idx_activity` (`activity_type`, `activity_id`, `status`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='活动奖励名单：manual 模式记录→导出→统一发放';

-- ---------------------------------------------------------------------------
-- 2. 合成活动：合成数量 / 参与资格 / 发放方式
-- ---------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_synthesis_activities' AND COLUMN_NAME = 'result_quantity');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_synthesis_activities` ADD COLUMN `result_quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT \'每次合成产出数量\' AFTER `result_collectible_id`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_synthesis_activities' AND COLUMN_NAME = 'eligibility_type');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_synthesis_activities` ADD COLUMN `eligibility_type` VARCHAR(20) NOT NULL DEFAULT \'all\' COMMENT \'参与资格类型：all/realname/checkin/invite/hold/checkin_rank\' AFTER `result_quantity`, ADD COLUMN `eligibility_config` JSON NULL DEFAULT NULL COMMENT \'参与资格配置 JSON\' AFTER `eligibility_type`, ADD COLUMN `grant_mode` ENUM(\'realtime\',\'manual\') NOT NULL DEFAULT \'realtime\' COMMENT \'奖励发放方式：realtime实时到账/manual记录名单统一发放\' AFTER `eligibility_config`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. 抽奖活动：参与资格 / 发放方式
-- ---------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_lucky_draw_activities' AND COLUMN_NAME = 'eligibility_type');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_lucky_draw_activities` ADD COLUMN `eligibility_type` VARCHAR(20) NOT NULL DEFAULT \'all\' COMMENT \'参与资格类型：all/realname/checkin/invite/hold/checkin_rank\' AFTER `end_time`, ADD COLUMN `eligibility_config` JSON NULL DEFAULT NULL COMMENT \'参与资格配置 JSON\' AFTER `eligibility_type`, ADD COLUMN `grant_mode` ENUM(\'realtime\',\'manual\') NOT NULL DEFAULT \'realtime\' COMMENT \'奖励发放方式：realtime实时到账/manual记录名单统一发放\' AFTER `eligibility_config`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3.1 抽奖奖项扩展：奖项名称/图片/奖励配置 + 六类奖励类型
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_lucky_draw_prizes' AND COLUMN_NAME = 'prize_image');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_lucky_draw_prizes` ADD COLUMN `prize_name` VARCHAR(100) NULL DEFAULT NULL COMMENT \'奖项名称（管理员填写，空则取奖档名）\' AFTER `tier_name`, ADD COLUMN `prize_image` VARCHAR(500) NULL DEFAULT NULL COMMENT \'奖项图片URL（管理员上传）\' AFTER `prize_name`, ADD COLUMN `reward_config` JSON NULL DEFAULT NULL COMMENT \'奖励配置 JSON（按 prize_type：collectibleId/blindboxId/amount/quantity/expiresAt）\' AFTER `prize_image`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_lucky_draw_prizes' AND COLUMN_NAME = 'prize_type');
SET @enum_ok := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_lucky_draw_prizes' AND COLUMN_TYPE LIKE '%draw_chance%');
SET @ddl := IF(@col = 1 AND @enum_ok = 0,
  'ALTER TABLE `nft_lucky_draw_prizes` MODIFY COLUMN `prize_type` ENUM(\'collectible\',\'points\',\'draw_chance\',\'priority_qualification\',\'eligibility_qualification\',\'blindbox\',\'none\') NOT NULL DEFAULT \'collectible\' COMMENT \'奖品类型：collectible藏品/points司南币/draw_chance抽奖次数/priority_qualification优先购资格/eligibility_qualification资格购资格/blindbox盲盒/none谢谢参与\'',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. 邀请活动：邀请档位奖励 / 被邀请人奖励与完成条件 / 发放方式
-- ---------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_invite_activities' AND COLUMN_NAME = 'tiers');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_invite_activities` ADD COLUMN `tiers` JSON NULL DEFAULT NULL COMMENT \'邀请档位奖励 JSON：[{inviteCount, rewards:[{type,...}]}] 1-50人\' AFTER `invitee_quantity`, ADD COLUMN `invitee_reward_config` JSON NULL DEFAULT NULL COMMENT \'被邀请人奖励配置 JSON（单奖励项）\' AFTER `tiers`, ADD COLUMN `invitee_conditions` JSON NULL DEFAULT NULL COMMENT \'被邀请人完成条件 JSON 数组：realname实名/wallet开通第三方钱包/checkin签到/consume消费\' AFTER `invitee_reward_config`, ADD COLUMN `grant_mode` ENUM(\'realtime\',\'manual\') NOT NULL DEFAULT \'realtime\' COMMENT \'奖励发放方式：realtime实时到账/manual记录名单统一发放\' AFTER `invitee_conditions`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4.1 用户表：实名通过时间（注册活动「实名前N名」排位依据）
-- ---------------------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_users' AND COLUMN_NAME = 'realname_verified_at');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_users` ADD COLUMN `realname_verified_at` DATETIME(3) NULL DEFAULT NULL COMMENT \'实名审核通过时间（注册活动排位依据）\' AFTER `is_realname`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. 注册活动表（注册实名奖励：实名前 N 名档位）
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `nft_register_activities` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name`        VARCHAR(100)  NOT NULL COMMENT '活动名称',
  `status`      ENUM('disabled','enabled') NOT NULL DEFAULT 'disabled' COMMENT '总开关：disabled关闭/enabled开启',
  `start_time`  DATETIME(3)   NULL DEFAULT NULL COMMENT '开始时间',
  `end_time`    DATETIME(3)   NULL DEFAULT NULL COMMENT '结束时间',
  `tiers`       JSON          NULL DEFAULT NULL COMMENT '实名前N名档位奖励 JSON：[{rankLimit, rewards:[{type,...}]}]',
  `grant_mode`  ENUM('realtime','manual') NOT NULL DEFAULT 'realtime' COMMENT '奖励发放方式：realtime实时到账/manual记录名单统一发放',
  `used_count`  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '已发放人数',
  `description` TEXT          NULL COMMENT '活动说明文案',
  `deleted_at`  DATETIME      NULL DEFAULT NULL COMMENT '软删除时间，NULL未删除',
  `created_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`  DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='注册活动表：实名前N名档位奖励';

-- ---------------------------------------------------------------------------
-- 6. 签到活动：参与资格 / 发放方式（system_configs；奖励规则升级见下）
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES
('checkin_eligibility_type',   'all', '签到参与资格类型：all/realname/checkin/invite/hold/checkin_rank'),
('checkin_eligibility_config', '',    '签到参与资格配置 JSON'),
('checkin_grant_mode',         'realtime', '签到奖励发放方式：realtime实时到账/manual记录名单统一发放'),
('checkin_reward_config',      '',    '签到奖励配置 JSON：{天: [六类奖励列表]}，空则回退 checkin_rewards 旧版司南币配置');

-- 6.1 签到记录奖励类型 ENUM 扩展
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_check_in_records' AND COLUMN_TYPE LIKE '%priority_qualification%');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_check_in_records` MODIFY COLUMN `reward_type` ENUM(\'none\',\'collectible\',\'points\',\'draw_chance\',\'priority_qualification\',\'eligibility_qualification\',\'blindbox\') NOT NULL DEFAULT \'none\' COMMENT \'奖励类型\'',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 7. 奖励发放依赖表（RewardGrantService 引用但未建）
-- ---------------------------------------------------------------------------
-- 7.1 优先购活动表（C 端下单资格判定 PurchaseQualifyService 使用）
CREATE TABLE IF NOT EXISTS `nft_priority_sales` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `collectible_id` INT UNSIGNED NOT NULL COMMENT '目标藏品ID，FK→nft_collectibles.id',
  `name`          VARCHAR(100)  NOT NULL COMMENT '活动名称',
  `status`        TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '状态：0停用 1启用',
  `start_time`    DATETIME      NOT NULL COMMENT '开始时间',
  `end_time`      DATETIME      NOT NULL COMMENT '结束时间',
  `created_at`    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_collectible_status` (`collectible_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='优先购活动表（奖励发放自动落位）';

-- 7.2 优先购白名单表（upsert 叠加限购次数）
CREATE TABLE IF NOT EXISTS `nft_priority_sale_whitelists` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `priority_sale_id` INT UNSIGNED  NOT NULL COMMENT '优先购活动ID，FK→nft_priority_sales.id',
  `user_id`         BIGINT UNSIGNED NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `phone`           VARCHAR(11)    NOT NULL COMMENT '手机号',
  `max_quantity`    INT UNSIGNED   NOT NULL DEFAULT 1 COMMENT '可购上限',
  `used_quantity`   INT UNSIGNED   NOT NULL DEFAULT 0 COMMENT '已购数量',
  `expires_at`      DATETIME       NOT NULL COMMENT '资格过期时间',
  `status`          TINYINT(1)     NOT NULL DEFAULT 1 COMMENT '状态：0失效 1有效',
  `created_at`      DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`      DATETIME(3)    NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sale_user` (`priority_sale_id`, `user_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='优先购白名单表';

-- 7.3 抽奖次数台账表（draw_chance 奖励）
CREATE TABLE IF NOT EXISTS `nft_lucky_draw_chances` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`        BIGINT UNSIGNED NOT NULL COMMENT '用户ID，FK→nft_users.id',
  `activity_id`    INT UNSIGNED    NOT NULL COMMENT '抽奖活动ID',
  `source`         VARCHAR(20)     NOT NULL DEFAULT 'free' COMMENT '来源：checkin/invite/register/airdrop/free',
  `total_quantity` INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT '发放次数',
  `used_quantity`  INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '已使用次数',
  `related_id`     BIGINT UNSIGNED NULL DEFAULT NULL COMMENT '关联业务ID',
  `created_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT '创建时间',
  `updated_at`     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3) COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_activity` (`user_id`, `activity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抽奖次数台账表';
