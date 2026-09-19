-- 20260919_raffle_codes_and_scene.sql
-- 修复三处 5001：
-- 1) nft_user_draw_codes 表缺失（抽签码资产表，DrawCodeService 依赖）
-- 2) nft_raffle_registrations 缺 is_force_win / win_count（报名必中与中签数）
-- 3) nft_categories 缺 scene（市场/文物分类过滤）

CREATE TABLE IF NOT EXISTS `nft_user_draw_codes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL COMMENT '所属用户',
    `code` VARCHAR(32) NOT NULL COMMENT '码串',
    `source` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '来源：1报名 2邀请 3购买 4后台',
    `activity_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '活动ID（NULL=通用码）',
    `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '状态：1未使用 2已报名 3已失效 4已中签',
    `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_code` (`code`),
    KEY `idx_user` (`user_id`),
    KEY `idx_activity` (`activity_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户抽签码资产表';

ALTER TABLE `nft_raffle_registrations`
    ADD COLUMN `is_force_win` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '是否必中：0否 1是' AFTER `draw_status`,
    ADD COLUMN `win_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '中签名额数（含加权）' AFTER `is_force_win`;

ALTER TABLE `nft_categories`
    ADD COLUMN `scene` VARCHAR(20) NOT NULL DEFAULT 'market' COMMENT '场景：market 市场二级分类 / artifact 文物展览分类' AFTER `code`;

UPDATE `nft_categories` SET `scene` = 'market' WHERE `scene` = '' OR `scene` IS NULL;
