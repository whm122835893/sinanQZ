-- ============================================================================
-- 钱包行补齐：为「有用户、无 nft_wallets 行」的存量用户就地开通空钱包
--
-- 背景（全链路走查缺陷 13）：nft_wallets 行由注册流程创建（app/controller/Auth.php
--   在 Db::transaction 内随用户一起 insert），但库里存在绕过该流程的用户
--   （早期 qa 夹具、直连 SQL 播种、full_init.sql 的部分用户），于是这些用户：
--     1) 充值 / 余额支付 / 求购接单 / 寄售与退款结算等入口在 lock(true)->find()
--        拿到 null 后直接取列，PHP 8 把该告警升级为 ErrorException，被业务 catch
--        转成 5001「操作失败」，资金入口整体不可用（实测 uid=50 复现，日志
--        [wallet][recharge] err=Trying to access array offset on null）；
--     2) 这也解释了全库从未出现过 source='market' 的订单——卖家侧结算必炸；
--     3) GET /api/wallet 与后台钱包统计把这批用户显示为 0，与「未开通」不可区分。
--
-- 代码侧已同步修复：新增 app/service/WalletService.php::ensureLocked，所有
--   「事务内取钱包行并改余额」的入口（充值、余额支付、卖家结算、退款入账）
--   统一在缺失时创建空钱包，不再依赖本脚本兜底。本脚本解决的是存量数据：
--   补齐后钱包口径唯一，后台「数据审计」的逐用户流水连续性核对也不再被空行干扰。
--
-- 幂等：LEFT JOIN 只命中没有钱包行的用户，第二次执行影响 0 行。
-- 安全性：只新增行，不改动任何已有余额；金额一律 0，与注册时的初始态一致
--   （余额由后续充值/结算入账，不会凭空造钱，qa/sit_z_identity.php 的 Z1 恒等式不受影响）。
--
-- 执行方式：注释含中文，建议带字符集执行，避免与库默认排序规则混排：
--   mysql --default-character-set=utf8mb4 -h<host> -P<port> -u<user> -p <db> < database/wallet_row_backfill_upgrade.sql
-- 刻意不进 deploy.sh 的 BASE_FILES：与 wallet_refund_trans_type_upgrade.sql 同类，
--   只服务存量库的一次性数据修复，新库导入基线后按需执行本脚本即可。
-- ============================================================================

INSERT INTO `nft_wallets` (`user_id`, `balance`, `available`, `frozen`, `points`, `created_at`, `updated_at`)
SELECT u.`id`, 0, 0, 0, 0, NOW(3), NOW(3)
  FROM `nft_users` u
  LEFT JOIN `nft_wallets` w ON w.`user_id` = u.`id`
 WHERE w.`id` IS NULL
   AND u.`deleted_at` IS NULL;

-- 核对：执行后应返回 0 行（软删用户保留其原有钱包行，不参与补齐）
SELECT u.`id`, u.`uid`, u.`username`
  FROM `nft_users` u
  LEFT JOIN `nft_wallets` w ON w.`user_id` = u.`id`
 WHERE w.`id` IS NULL
   AND u.`deleted_at` IS NULL;
