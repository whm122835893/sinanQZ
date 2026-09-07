-- ============================================================
-- 文物展馆升级：
--   1. status 展示状态：1展示中 0已隐藏（管理端开关/筛选 + C 端只展示 status=1）
-- 注：period/story 在管理端创建时改为可选（period 缺省取朝代，story 缺省空串）
-- ============================================================

ALTER TABLE `nft_artifacts`
  ADD COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '展示状态：1展示中 0已隐藏' AFTER `tags`;
