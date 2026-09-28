-- ============================================================================
-- 实名状态位成对修复：把 is_realname=1 的用户的 realname_status 归位到 2（已通过）
--
-- 背景（全链路走查缺陷 15）：这两个列各自被不同代码读写，必须成对才自洽：
--   nft_users.is_realname     —— 能力位。下单/寄售/转赠/抽奖/条件空投按它放行
--                                （Orders.php:39、Resale.php:271、Raffle.php:394、
--                                 ActivityRewardService.php:69）；
--   nft_users.realname_status —— 审核工作流态。0未提交 1待审核 2已通过 3已驳回，
--                                后台实名列表/待办统计/营销人群筛选只认它
--                                （RealnameController.php:44、DashboardController.php:49、
--                                 MarketingController.php:1776）。
--   真实工作流始终成对写：提交 0/1（User.php:140）、通过 1/2（RealnameService.php:30）、
--   驳回 0/3（RealnameController.php:186）。合法组合只有 (0,0)(0,1)(0,3)(1,2)。
--
--   qa 夹具当年只写了能力位（或干脆写死 realname_status=1），本库实测两类脏数据共 230 行：
--     1) 225 行 is_realname=1 却 realname_status=0/1 —— C 端「账户安全」读 isRealName
--        显示「已认证」，「实名认证」页读 realnameStatus 显示「审核中/去填写」，
--        同一账号两页自相矛盾（实测 uid=E00001）；后台「实名审核」还把 1/1 的人
--        长期挂在待办里；
--     2) 5 行 is_realname=0 且 realname_status=1，但 real_name/id_card/realname_submitted_at
--        全空 —— 从未提交过的用户出现在「待审核」名单上，管理员点「通过」就等于
--        给空证件补审核记录并放行交易；
--     3) 营销活动按 realname_status 取人群、空投资格按 is_realname 判人，
--        两者对同一批用户给出相反结论。
--
-- 代码侧已同步修复：qa/ 下 13 个播种脚本（h1/h2/h3/h5/h77/t6/t7/t45/f4f5/full_audit/
--   baseline_reset/e2e_cond_airdrop/e2e_behavior_airdrop）改为成对写入，
--   sinan-nft-backend/tests/RealnameFlowTest.php 补了驳回态与重新提交后的成对断言。
--   本脚本解决的是存量数据。
--
-- 幂等：只命中 is_realname=1 且 realname_status<>2 的行，第二次执行影响 0 行。
-- 安全性：不写 realname_verified_at——这些用户从未提交过证件，补一个假审核时间会把
--   「实名排位」（ActivityRewardService.php:470 要求 verified_at 非空）和审核留痕一起造假。
--   保持 NULL 与修复前的实际放行行为完全一致（能力位本就是 1）。
--
-- 执行方式：注释含中文，建议带字符集执行，避免与库默认排序规则混排：
--   mysql --default-character-set=utf8mb4 -h<host> -P<port> -u<user> -p <db> < database/realname_status_pair_repair_upgrade.sql
-- 刻意不进 deploy.sh 的 BASE_FILES：与 wallet_row_backfill_upgrade.sql 同类，
--   只服务存量库的一次性数据修复。
-- ============================================================================

UPDATE `nft_users`
   SET `realname_status` = 2
 WHERE `is_realname` = 1
   AND `realname_status` <> 2;

-- 待审核但没有任何提交痕迹的行归回 0（未提交）。
-- 这类行来自 baseline_reset 早先把 realname_status 写死的播种：后台「实名审核」会把它们
-- 列为待办，而点开是空证件——管理员一旦点「通过」，就等于给从未提交的人补审核记录并放行交易。
UPDATE `nft_users`
   SET `realname_status` = 0
 WHERE `realname_status` = 1
   AND `realname_submitted_at` IS NULL
   AND (`real_name` IS NULL OR `real_name` = '');

-- 核对一：执行后应返回 0 行（四个合法组合之外的都是脏数据）
SELECT `id`, `uid`, `phone`, `is_realname`, `realname_status`
  FROM `nft_users`
 WHERE NOT (`is_realname` = 1 AND `realname_status` = 2)
   AND NOT (`is_realname` = 0 AND `realname_status` IN (0, 1, 3));

-- 核对二：执行后应返回 0 行（「待审核」必须有提交痕迹，才可能在审核台被处理）
SELECT `id`, `uid`, `phone`, `realname_status`
  FROM `nft_users`
 WHERE `realname_status` = 1
   AND `realname_submitted_at` IS NULL
   AND (`real_name` IS NULL OR `real_name` = '');
