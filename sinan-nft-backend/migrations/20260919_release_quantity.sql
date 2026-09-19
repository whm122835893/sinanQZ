-- =====================================================================
-- 2026-09-19 分批发售：nft_collectibles.release_quantity 字段
--
-- 用途：藏品 1000 份，管理员可分 300/300/400 分批上架
-- 语义：
--   NULL 或 0 = 全部上架（按 edition 卖，原有行为）
--   正整数 N  = 上架 N 份（sold + locked_quantity 不可超过 N）
--
-- 影响面：
--   1. POST /admin/collectible/release  接收 release_quantity 参数
--   2. POST /admin/blindbox/release      接收 release_quantity 参数
--   3. POST /api/orders                  发售下单时原子检查 sold+locked<=release_quantity
--   4. POST /api/raffle/activities/:id/purchase  抽签购买同步限制
--   5. InventoryService::saleable()      统一计算"实际可卖数量"
-- =====================================================================

-- Step 1: 加字段（使用通用 DDL，不依赖 IF NOT EXISTS）
ALTER TABLE nft_collectibles
    ADD COLUMN release_quantity INT UNSIGNED NULL DEFAULT NULL
    COMMENT '当前上架份数（分批发售用；NULL/0=全部上架）'
    AFTER edition;

-- Step 2: 数据回填（存量藏品统一置 NULL = 全部上架，保持原有行为）
UPDATE nft_collectibles SET release_quantity = NULL WHERE release_quantity = 0;

-- Step 3: 验证（可选，手动执行）
-- SELECT id, name, edition, release_quantity, sold, status
-- FROM nft_collectibles
-- WHERE deleted_at IS NULL
-- ORDER BY id DESC
-- LIMIT 20;
