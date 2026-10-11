-- 20261010_realname_audit_mode_auto.sql
-- 实名审核模式默认值由 manual 改为 auto（2026-10-10 运营决策）。
--
--   auto   = 提交即通过：姓名与身份证格式校验通过后立即认证成功，调用 RealnameService::approve，
--            与后台人工点「通过」完全同一口径（realname_status=2 + is_realname=1 + 注册/邀请活动结算）
--   manual = 仍然保留：后台「系统配置 → 全局参数 → 用户与实名 → 实名审核模式」随时切回，实时生效、不用发版
--
-- 边界说明（避免把 auto 误解成「不校验实名」）：
--   1. 只免掉「管理员人工点一下」，不免格式校验（app/controller/User.php 的地区码/出生年月日规则照旧）；
--   2. 不免交易前置——实名仍是购买/寄售/转赠的能力开关，未提交者依旧被拦；
--   3. 身份证号与姓名仍 AES 密文落库，已通过账号重新提交仍被拒绝（auto 模式同样拒）。
--
-- 幂等：先 INSERT IGNORE 补行（仅缺行时插入），再 UPDATE 归位；重复执行不产生新变化。
-- 注：updated_at 带 ON UPDATE CURRENT_TIMESTAMP(3)，无需手工赋值。

INSERT IGNORE INTO `nft_system_configs` (`config_key`, `config_value`, `description`)
VALUES ('realname_audit_mode', 'auto', '实名审核模式：manual=人工审核 auto=自动通过（当前默认）');

UPDATE `nft_system_configs`
SET `config_value` = 'auto',
    `description`  = '实名审核模式：manual=人工审核 auto=自动通过（当前默认）'
WHERE `config_key` = 'realname_audit_mode';
