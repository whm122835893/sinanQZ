-- 签到多活动化 + 签到/抽奖/合成模块开关
-- 1) 签到从单一全局配置改为活动表（新建活动模式，与抽奖/邀请一致）
-- 2) 抽奖/合成增加模块级功能开关（C端关闭时空状态）
-- 3) 合成活动支持后台软删除（已结束活动C端仍展示）
-- 4) 存量签到配置迁移为一条活动记录

CREATE TABLE IF NOT EXISTS `nft_check_in_activities` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL COMMENT '活动名称',
    `status` VARCHAR(20) NOT NULL DEFAULT 'enabled' COMMENT '状态：enabled 启用 / disabled 停用',
    `start_time` DATETIME NOT NULL COMMENT '开始时间',
    `end_time` DATETIME NULL DEFAULT NULL COMMENT '结束时间（NULL=长期有效）',
    `reward_config` TEXT NULL COMMENT '奖励JSON {day:[六类奖励]}',
    `eligibility_type` VARCHAR(20) NOT NULL DEFAULT 'all' COMMENT '参与资格类型',
    `eligibility_config` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '参与资格配置JSON',
    `grant_mode` VARCHAR(20) NOT NULL DEFAULT 'realtime' COMMENT '发放方式：realtime 实时 / manual 名单统一发放',
    `signin_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '累计签到人次',
    `deleted_at` DATETIME NULL DEFAULT NULL COMMENT '软删除时间',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status_time` (`status`, `start_time`, `end_time`),
    KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='签到活动表';

ALTER TABLE `nft_synthesis_activities`
    ADD COLUMN `deleted_at` DATETIME NULL DEFAULT NULL COMMENT '软删除时间' AFTER `updated_at`;

-- 补齐缺失列：后端 save/submit/detail 引用 result_quantity（SY23 产物数量），旧库从未创建
ALTER TABLE `nft_synthesis_activities`
    ADD COLUMN `result_quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '每次合成产出数量' AFTER `result_collectible_id`;

INSERT INTO `nft_system_configs` (`config_key`, `config_value`, `description`)
VALUES
    ('checkin_enabled', '1', '签到模块开关：0关闭 1开启（C端关闭时空状态）'),
    ('lucky_enabled', '1', '抽奖模块开关：0关闭 1开启（C端关闭时空状态）'),
    ('synthesis_enabled', '1', '合成模块开关：0关闭 1开启（C端关闭时空状态）')
ON DUPLICATE KEY UPDATE `config_key` = `config_key`;

-- 存量签到配置迁移为一条启用中的活动（幂等：无活动时才插入）
INSERT INTO `nft_check_in_activities` (`name`, `status`, `start_time`, `end_time`, `reward_config`, `eligibility_type`, `eligibility_config`, `grant_mode`, `created_at`)
SELECT
    COALESCE(NULLIF((SELECT `config_value` FROM `nft_system_configs` WHERE `config_key` = 'checkin_activity_name'), ''), '每日签到'),
    'enabled',
    COALESCE(NULLIF((SELECT `config_value` FROM `nft_system_configs` WHERE `config_key` = 'checkin_start_time'), ''), DATE_FORMAT(NOW(), '%Y-%m-01 00:00:00')),
    NULLIF((SELECT `config_value` FROM `nft_system_configs` WHERE `config_key` = 'checkin_end_time'), ''),
    (SELECT `config_value` FROM `nft_system_configs` WHERE `config_key` = 'checkin_reward_config'),
    COALESCE((SELECT `config_value` FROM `nft_system_configs` WHERE `config_key` = 'checkin_eligibility_type'), 'all'),
    COALESCE((SELECT `config_value` FROM `nft_system_configs` WHERE `config_key` = 'checkin_eligibility_config'), ''),
    COALESCE((SELECT `config_value` FROM `nft_system_configs` WHERE `config_key` = 'checkin_grant_mode'), 'realtime'),
    NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `nft_check_in_activities`);
