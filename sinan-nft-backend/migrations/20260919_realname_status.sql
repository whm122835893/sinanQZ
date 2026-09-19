-- 用户实名状态列（realname_status：0未提交 1待审核 2已通过 3已驳回）
-- 补齐 UserController / RealnameController / DashboardController / C 端 User 的字段依赖
ALTER TABLE nft_users
    ADD COLUMN realname_status TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT '实名状态：0未提交 1待审核 2已通过 3已驳回' AFTER is_realname;

-- 存量数据：已实名（is_realname=1）的用户回填为已通过
UPDATE nft_users SET realname_status = 2 WHERE is_realname = 1;
