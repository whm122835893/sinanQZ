-- 抽奖活动表补齐：参与资格判定 + 发放方式三列
-- lucky_draw_activities.eligibility_type: all/whitelist/collectible（参与资格类型）
-- lucky_draw_activities.eligibility_config: JSON（白名单/持有藏品等配置）
-- lucky_draw_activities.grant_mode: realtime 实时到账 / manual 记录名单统一发放
ALTER TABLE nft_lucky_draw_activities
    ADD COLUMN eligibility_type VARCHAR(20) NOT NULL DEFAULT 'all'
    COMMENT '参与资格类型：all 全体 / whitelist 白名单 / collectible 持有藏品' AFTER end_time,
    ADD COLUMN eligibility_config JSON NULL
    COMMENT '资格配置 JSON（白名单用户/藏品 ID 列表等）' AFTER eligibility_type,
    ADD COLUMN grant_mode VARCHAR(20) NOT NULL DEFAULT 'realtime'
    COMMENT '发放方式：realtime 实时到账 / manual 统一发放' AFTER eligibility_config;
