-- ============================================================
-- 公告升级：富文本已支持（content TEXT），本次新增
--   1. status       状态：draft 草稿 / published 已发布（含定时待生效）
--   2. publish_time 定时发布时间（NULL = 创建即生效）
-- C 端可见性规则：status = 'published' 且 (publish_time IS NULL OR publish_time <= NOW())
-- ============================================================

ALTER TABLE `nft_announcements`
  ADD COLUMN `status` ENUM('draft','published') NOT NULL DEFAULT 'published' COMMENT '状态：draft草稿/published已发布（含定时待生效）' AFTER `tag_color`,
  ADD COLUMN `publish_time` DATETIME(3) NULL DEFAULT NULL COMMENT '定时发布时间（NULL=创建即生效）' AFTER `status`;

-- 管理端列表按状态筛选
-- （后端 GET /admin/cms/announcements?status=draft|published）
