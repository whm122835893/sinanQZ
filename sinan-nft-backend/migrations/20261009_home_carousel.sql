-- =====================================================================
-- 2026-10-09 首页轮播推荐位：藏品「上轮播」标记
--
-- 背景：后台每件藏品已有「活动市场推荐」开关（is_market_recommended，上推荐 tab）。
--      本次再加一枚独立的「首页轮播」开关：打开后该藏品的封面图会插到 H5 首页
--      轮播最前面，左上角带「推荐藏品」角标，点击跳到市场里该藏品的寄售页（/resale/:id，与
--      市场卡片同一入口，不是首发详情）。
--
-- 语义：
--   is_home_carousel_recommended = 1 上首页轮播 / 0 不上。
--      与市场推荐（is_market_recommended）互不依赖，可以只开一个，也可以两个都开。
--      轮播读取时要求 deleted_at IS NULL，排序 id DESC（新上架优先）。
--
-- 注意：不要用 nft_collectibles.featured 代替本列，featured 的语义是「首页推荐位」
--      （H5 首页 collections/featured 接口在用），也不要用 is_market_recommended，
--      那是市场里的「推荐」分类，两者的开关位、文案都不同。
--
-- 无需全局开关：每枚单品开关已能完全控制轮播内容（全关 = 只剩后台「轮播管理」的图）。
--
-- 幂等：DDL 先查 information_schema 再执行，重复执行无副作用。
-- 执行（生产，DDL 需 root 账号）：
--   MYSQL_PWD=$(cat /root/.mysql_root_pass) mysql -uroot sinan_nft < 20261009_home_carousel.sql
-- =====================================================================

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_collectibles'
        AND COLUMN_NAME = 'is_home_carousel_recommended') = 0,
    "ALTER TABLE nft_collectibles
        ADD COLUMN is_home_carousel_recommended TINYINT(1) UNSIGNED NOT NULL DEFAULT 0
        COMMENT '首页轮播推荐位：1 上轮播（封面图插到首页轮播最前，带「推荐藏品」角标），0 不上'
        AFTER is_market_recommended",
    'SELECT "skip: is_home_carousel_recommended exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 验证（手动执行）：
-- SELECT id, name, market_type, is_market_recommended, is_home_carousel_recommended, status
--   FROM nft_collectibles WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 20;
