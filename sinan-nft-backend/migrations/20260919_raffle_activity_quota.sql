-- 20260919_raffle_activity_quota.sql
-- 补齐 nft_raffle_activities 抽签名额/抽签码配置列（RaffleService 保存与列表依赖）

ALTER TABLE `nft_raffle_activities`
    ADD COLUMN `total_supply` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '藏品总发行量（0=取藏品 edition）' AFTER `ticket_price`,
    ADD COLUMN `draw_win_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '抽签名额数（按签计，0=用 winner_count）' AFTER `winner_count`,
    ADD COLUMN `max_wins_per_user` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '单用户最大中签数' AFTER `draw_win_count`,
    ADD COLUMN `draw_code_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '购买抽签码开关' AFTER `max_wins_per_user`,
    ADD COLUMN `draw_code_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '抽签码单价' AFTER `draw_code_enabled`,
    ADD COLUMN `buy_code_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '每人购买码上限' AFTER `draw_code_price`,
    ADD COLUMN `invite_enabled` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '邀请得码开关' AFTER `buy_code_limit`,
    ADD COLUMN `invite_code_limit` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '邀请得码上限' AFTER `invite_enabled`,
    ADD COLUMN `invite_user_needed` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '每邀请N人得1码' AFTER `invite_code_limit`;
