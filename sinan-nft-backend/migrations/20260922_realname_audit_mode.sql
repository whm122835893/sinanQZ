-- 20260922_realname_audit_mode.sql
-- 实名审核模式开关（后台「全局参数」可切换，实时生效）：
--   manual = 人工审核（默认，现状：提交后进入待审核队列，管理员逐条审核）
--   auto   = 自动通过（格式校验通过后立即认证成功，与管理端审核通过同口径结算活动奖励）

INSERT INTO `nft_system_configs` (`config_key`, `config_value`, `description`)
VALUES ('realname_audit_mode', 'manual', '实名审核模式：manual=人工审核（默认） auto=自动通过（提交即认证成功）');
