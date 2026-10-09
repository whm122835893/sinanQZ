-- =====================================================================
-- 2026-10-09 市场分栏：藏品「归属市场」标签 + 活动市场「推荐」
--
-- 背景：C 端顶部「活动市场 / 自由市场」两个 tab 原本读的是同一份数据
--      （GET /api/market/collections 不带任何市场维度过滤），本迁移把两者拆开。
--
-- 语义：
--   market_type            = 'activity' 活动市场 / 'free' 自由市场。
--                            后台改这一列 = 把藏品在两个市场之间移动；
--                            两个市场的价格口径不变，仍是寄售挂单最低价。
--   is_market_recommended  = 1 时该藏品出现在活动市场的「推荐」分类里。
--                            只有活动市场有推荐（自由市场不看这个标记）。
--   market_recommend_tab_enabled（nft_system_configs）
--                          = 活动市场二级分类里「推荐」胶囊的总开关，
--                            0 关闭（胶囊不出现）/ 1 开启（默认）。
--
-- 注意：不要用 nft_collectibles.featured 代替 is_market_recommended，
--      featured 的语义是「首页推荐位」（H5 首页 collections/featured 接口在用）。
--
-- 幂等：三段 DDL 都先查 information_schema 再执行，重复执行无副作用。
-- 执行（生产，DDL 需 root 账号）：
--   MYSQL_PWD=$(cat /root/.mysql_root_pass) mysql -uroot sinan_nft < 20261009_market_type_and_recommend.sql
-- =====================================================================

-- Step 1: 归属市场列（默认 activity，存量藏品自动全部归活动市场）
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_collectibles'
        AND COLUMN_NAME = 'market_type') = 0,
    "ALTER TABLE nft_collectibles
        ADD COLUMN market_type ENUM('activity','free') NOT NULL DEFAULT 'activity'
        COMMENT '归属市场：activity 活动市场 / free 自由市场（后台移动市场即改此列）'
        AFTER status",
    'SELECT "skip: market_type exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Step 2: 市场推荐标记列
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_collectibles'
        AND COLUMN_NAME = 'is_market_recommended') = 0,
    "ALTER TABLE nft_collectibles
        ADD COLUMN is_market_recommended TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
        COMMENT '活动市场推荐：1 上推荐（显示在推荐分类），0 未推荐；自由市场不生效'
        AFTER market_type",
    'SELECT "skip: is_market_recommended exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Step 3: 市场列表查询索引（market/collections 按 market_type + 推荐过滤）
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_collectibles'
        AND INDEX_NAME = 'idx_market_type') = 0,
    'ALTER TABLE nft_collectibles ADD INDEX idx_market_type (market_type, is_market_recommended, status)',
    'SELECT "skip: idx_market_type exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Step 4: 数据归位。列默认值已把存量行填成 activity（无需回填）；
--         这里只清理"自由市场藏品带推荐标记"的脏态——推荐只在活动市场生效。
UPDATE nft_collectibles SET is_market_recommended = 0
 WHERE is_market_recommended = 1 AND market_type <> 'activity';

-- Step 5: 推荐分类总开关（后台「系统 → 全局参数 → 寄售市场」可切换，实时生效）
INSERT INTO `nft_system_configs` (`config_key`, `config_value`, `description`)
VALUES ('market_recommend_tab_enabled', '1',
        '活动市场「推荐」分类开关：0 关闭（C 端二级分类不显示推荐） 1 开启')
ON DUPLICATE KEY UPDATE `config_key` = `config_key`;

-- 验证（手动执行）：
-- SELECT id, name, market_type, is_market_recommended, status
--   FROM nft_collectibles WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 20;
-- SELECT config_key, config_value FROM nft_system_configs
--   WHERE config_key = 'market_recommend_tab_enabled';
