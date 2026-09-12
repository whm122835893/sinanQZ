-- ============================================================================
-- 分类管理升级脚本（幂等，可重复执行）
-- 内容：
--   1. 分类表 nft_categories 增加 scene 场景字段：
--      market  = 市场二级分类（水墨/国潮/盲盒/实物/联名…，藏品市场 tab 筛选）
--      artifact = 文物展览区分类（青铜/陶瓷/书画/玉器…，文物展馆 tab 筛选）
--   2. 种子数据：文物分类（青铜/陶瓷/书画/玉器）+ 市场分类补齐（盲盒/实物/联名）
--   3. 管理后台权限节点：cms:category（分类管理，挂在内容管理下）
-- ============================================================================

-- ----------------------------------------------------------------
-- 1. nft_categories 增加 scene 字段
-- ----------------------------------------------------------------
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_categories' AND COLUMN_NAME = 'scene');
SET @ddl := IF(@col = 0,
  'ALTER TABLE `nft_categories` ADD COLUMN `scene` VARCHAR(20) NOT NULL DEFAULT ''market'' COMMENT ''分类场景：market市场二级分类 / artifact文物展览分类'' AFTER `code`',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------
-- 2. 种子数据（code 唯一键幂等）
-- ----------------------------------------------------------------
-- 2.1 文物展览分类
INSERT INTO `nft_categories` (`name`, `code`, `scene`, `sort_order`) VALUES
  ('青铜', 'bronze',  'artifact', 1),
  ('陶瓷', 'ceramics', 'artifact', 2),
  ('书画', 'calligraphy', 'artifact', 3),
  ('玉器', 'jade',    'artifact', 4)
ON DUPLICATE KEY UPDATE `scene` = VALUES(`scene`), `sort_order` = VALUES(`sort_order`);

-- 2.2 市场二级分类（seed-dev 已有 水墨/国潮/限定，补齐盲盒/实物/联名）
INSERT INTO `nft_categories` (`name`, `code`, `scene`, `sort_order`) VALUES
  ('水墨', 'ink',      'market', 1),
  ('国潮', 'guochao',  'market', 2),
  ('盲盒', 'blindbox', 'market', 3),
  ('实物', 'physical', 'market', 4),
  ('联名', 'crossover','market', 5)
ON DUPLICATE KEY UPDATE `scene` = VALUES(`scene`), `sort_order` = VALUES(`sort_order`);

-- ----------------------------------------------------------------
-- 3. 管理后台权限节点：cms:category（uk_code 唯一键幂等）
-- ----------------------------------------------------------------
INSERT IGNORE INTO `nft_admin_permissions` (`name`, `code`, `module`, `type`, `parent_id`, `path`, `icon`, `sort_order`)
VALUES ('分类管理', 'cms:category', 'cms', 1, 1200, '/cms/category', '', 5);
