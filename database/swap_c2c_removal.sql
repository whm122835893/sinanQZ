-- ============================================================================
-- 司南数字藏品平台 · C 端用户间置换（补差价）体系下线迁移
-- 背景：
--   原设计为用户 A 用藏品 X 换用户 B 的藏品 Y（可加现金差价），
--   现已由「管理员统一置换」替代（回收源藏品 + 按比例空投新藏品，
--   见 swap_plan_upgrade.sql，nft_swap_plans 系列表，不受本脚本影响）。
-- 处理：
--   1. C 端 /api/swap-offers* 路由与 app/controller/Swap.php 已删除
--   2. 管理端 /admin/swap 仅保留 plans / plans/:id 查询
--   3. 本脚本 DROP 历史业务表（幂等，可重复执行）
-- 注意：
--   nft_swap_plans / nft_swap_plan_items / nft_swap_plan_users /
--   nft_swap_plan_details 为统一置换专用表，保留不动。
-- ============================================================================

SET NAMES utf8mb4;
USE `sinan_nft`;

DROP TABLE IF EXISTS `nft_swap_records`;
DROP TABLE IF EXISTS `nft_swap_offers`;
