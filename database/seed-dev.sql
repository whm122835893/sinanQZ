-- ============================================================================
-- sinanQZ 开发联调种子数据（seed-dev.sql）
-- 用途：为「抽奖 / 社区 / 邀请」模块的前后端联调提供最小业务数据。
-- 说明：init.sql 按设计规范不写入任何业务 Mock 数据，本脚本仅用于
--       开发/联调环境，生产环境请勿执行。
-- 特性：固定主键 + ON DUPLICATE KEY UPDATE，幂等可重复执行。
-- 用法：mysql -uroot -p < database/seed-dev.sql
-- ============================================================================

USE `sinan_nft`;

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. 藏品分类 + 藏品（抽奖藏品奖 / 邀请奖励引用；图片为前端 public 静态资源）
-- ----------------------------------------------------------------------------
INSERT INTO `nft_categories` (`id`, `name`, `code`, `sort_order`) VALUES
  (1, '水墨', 'ink', 1),
  (2, '国潮', 'guochao', 2),
  (3, '限定', 'limited', 3)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `sort_order` = VALUES(`sort_order`);

INSERT INTO `nft_collectibles`
  (`id`, `category_id`, `name`, `subtitle`, `image`, `price`, `edition`, `circulate`, `sold`,
   `status`, `tag`, `issuer`, `is_release`, `featured`, `description`) VALUES
  (9001, 1, '龙纹罗盘', '司南珍藏系列', '/images/collections/cover-1.jpg', 399.00, 1000, 0, 0,
   'onsale', '首发', '司南数字藏品', 0, 0, '联调测试数据：抽奖一等奖。'),
  (9002, 2, '云端法器', '司南珍藏系列', '/images/collections/cover-2.jpg', 299.00, 800, 0, 0,
   'onsale', '首发', '司南数字藏品', 0, 0, '联调测试数据：抽奖三等奖。'),
  (9003, 2, '青铜面具', '司南珍藏系列', '/images/collections/cover-3.jpg', 199.00, 600, 0, 0,
   'onsale', '首发', '司南数字藏品', 0, 0, '联调测试数据：抽奖四等奖。')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `image` = VALUES(`image`), `price` = VALUES(`price`),
  `edition` = VALUES(`edition`), `status` = VALUES(`status`);

-- ----------------------------------------------------------------------------
-- 2. 抽奖奖池（activity_id=1，六档：概率合计=1.0000，转盘六扇区）
--    prize_type：collectible 藏品 / points 司南币 / none 谢谢参与
-- ----------------------------------------------------------------------------
INSERT INTO `nft_lucky_draw_prizes`
  (`id`, `activity_id`, `tier_name`, `prize_type`, `collectible_id`, `coin_amount`,
   `total`, `won`, `sort_order`, `probability`) VALUES
  (1, 1, '一等奖·龙纹罗盘', 'collectible', 9001, NULL, 5,   0, 1, 0.0200),
  (2, 1, '100 司南币',      'points',      NULL, 100.00, 20,  0, 2, 0.0800),
  (3, 1, '二等奖·云端法器', 'collectible', 9002, NULL, 3,   0, 3, 0.0200),
  (4, 1, '谢谢参与',        'none',        NULL, NULL, 500, 0, 4, 0.4000),
  (5, 1, '三等奖·青铜面具', 'collectible', 9003, NULL, 8,   0, 5, 0.0300),
  (6, 1, '5 司南币',        'points',      NULL, 5.00,  100, 0, 6, 0.4500)
ON DUPLICATE KEY UPDATE
  `tier_name` = VALUES(`tier_name`), `prize_type` = VALUES(`prize_type`),
  `collectible_id` = VALUES(`collectible_id`), `coin_amount` = VALUES(`coin_amount`),
  `total` = VALUES(`total`), `probability` = VALUES(`probability`),
  `sort_order` = VALUES(`sort_order`);

-- ----------------------------------------------------------------------------
-- 3. 社区群（icon / qr_code 为前端 public 静态资源路径，生产应替换为 CDN URL）
-- ----------------------------------------------------------------------------
INSERT INTO `nft_community_groups`
  (`id`, `icon`, `name`, `description`, `qr_code`, `sort_order`, `is_active`) VALUES
  (1, '/images/tab/tab-bell.png',   '司南官方社群',   '第一时间获取发售与活动资讯', '/images/brand-logo.png', 1, 1),
  (2, '/images/tab/tab-person.png', '司南玩家交流群', '藏友互动，分享收藏心得', NULL, 2, 1)
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `description` = VALUES(`description`),
  `qr_code` = VALUES(`qr_code`), `is_active` = VALUES(`is_active`);

-- ----------------------------------------------------------------------------
-- 4. 邀请活动（邀请页横幅展示；奖励藏品为上方联调藏品，end_time NULL 表示长期有效）
-- ----------------------------------------------------------------------------
INSERT INTO `nft_invite_activities`
  (`id`, `name`, `status`, `start_time`, `end_time`,
   `inviter_collectible_id`, `inviter_quantity`, `invitee_collectible_id`, `invitee_quantity`,
   `airdrop_mode`, `description`) VALUES
  (1, '邀友注册·双方得限定藏品', 'enabled', NOW(3), NULL, 9003, 1, 9003, 1, 'realtime',
   '邀请好友注册司南珍藏，双方各得《青铜面具》限定藏品一份。')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `status` = VALUES(`status`),
  `inviter_collectible_id` = VALUES(`inviter_collectible_id`),
  `invitee_collectible_id` = VALUES(`invitee_collectible_id`),
  `description` = VALUES(`description`);

-- ----------------------------------------------------------------------------
-- 5. 发售上架标记（首页精选/市场需要 is_release=1 才可见）
-- ----------------------------------------------------------------------------
UPDATE `nft_collectibles` SET `is_release` = 1, `onsale_at` = NOW(3) - INTERVAL 1 DAY
WHERE `id` IN (9001, 9002, 9003);

-- ----------------------------------------------------------------------------
-- 6. 盲盒（藏品9004 与盲盒1:1；奖池概率合计=1，限量控制稀有度）
-- ----------------------------------------------------------------------------
INSERT INTO `nft_collectibles`
  (`id`, `category_id`, `name`, `subtitle`, `image`, `price`, `edition`, `circulate`, `sold`,
   `status`, `tag`, `issuer`, `is_release`, `featured`, `description`) VALUES
  (9004, 3, '司南秘宝盲盒', '开启得随机限定藏品', '/images/collections/cover-1.jpg', 99.00, 100, 0, 0,
   'onsale', '盲盒', '司南数字藏品', 1, 1, '联调测试数据：盲盒，购买后可开启。')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `price` = VALUES(`price`), `edition` = VALUES(`edition`),
  `status` = VALUES(`status`), `is_release` = VALUES(`is_release`);

INSERT INTO `nft_blind_boxes` (`id`, `collectible_id`, `description`, `is_openable`) VALUES
  (1, 9004, '开启后随机获得限定藏品一份', 1)
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `is_openable` = VALUES(`is_openable`);

INSERT INTO `nft_blind_box_items`
  (`id`, `blind_box_id`, `prize_collectible_id`, `probability`, `quantity_limit`, `quantity_distributed`) VALUES
  (1, 1, 9001, 0.1000, 5,   0),
  (2, 1, 9002, 0.2000, 10,  0),
  (3, 1, 9003, 0.7000, NULL, 0)
ON DUPLICATE KEY UPDATE
  `probability` = VALUES(`probability`), `quantity_limit` = VALUES(`quantity_limit`);

-- ----------------------------------------------------------------------------
-- 7. 合成活动（材料：龙纹罗盘x1 + 云端法器x1 → 产物：青铜面具；permanent 永久有效）
-- ----------------------------------------------------------------------------
INSERT INTO `nft_synthesis_activities`
  (`id`, `type`, `title`, `start_time`, `end_time`, `rules`, `result_collectible_id`,
   `per_user_limit`, `total_limit`, `used_count`, `image`) VALUES
  (1, 'permanent', '青铜面具合成计划', NOW(3) - INTERVAL 1 DAY, NULL,
   '集齐龙纹罗盘与云端法器各一份，即可合成青铜面具限定藏品。', 9003,
   0, NULL, 0, '/images/collections/cover-3.jpg')
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `rules` = VALUES(`rules`), `used_count` = 0;

INSERT INTO `nft_synthesis_materials` (`id`, `activity_id`, `collectible_id`, `count`) VALUES
  (1, 1, 9001, 1),
  (2, 1, 9002, 1)
ON DUPLICATE KEY UPDATE `count` = VALUES(`count`);

-- ----------------------------------------------------------------------------
-- 8. 文物展馆（瀑布流展示）
-- ----------------------------------------------------------------------------
INSERT INTO `nft_artifacts`
  (`id`, `name`, `dynasty`, `image`, `img_height`, `material`, `period`, `size`,
   `origin`, `museum`, `level`, `specs`, `story`, `tags`) VALUES
  (1, '司南青铜罗盘', '战国', '/images/artifacts/1.jpg', 180, '青铜', '约公元前475年－前221年',
   '通高12cm，盘径14cm', '河北易县燕下墟出土', '河北博物院', '国家一级文物',
   '{"器型":"罗盘","通高":"12cm","盘径":"14cm"}',
   '司南青铜罗盘是战国时期青铜铸造工艺的巅峰之作，盘面铸有精密方位刻度，是中国古代天文与导航智慧的结晶。',
   '["青铜","礼器","战国"]'),
  (2, '云雷纹青铜鼎', '西周', '/images/artifacts/2.jpg', 220, '青铜', '约公元前1046年－前771年',
   '通高53cm，口径47cm', '陕西宝鸡青铜器之乡', '宝鸡青铜器博物院', '国家一级文物',
   '{"器型":"鼎","通高":"53cm"}',
   '云雷纹青铜鼎铸造于西周早期，鼎身遍布云雷纹饰，体现了周代礼器制度的庄严与厚重。',
   '["青铜","礼器","西周"]'),
  (3, '鎏金铜面具', '东汉', '/images/artifacts/3.jpg', 160, '铜鎏金', '约公元25年－220年',
   '高26cm，宽21cm', '四川广汉三星堆遗址', '三星堆博物馆', '国家一级文物',
   '{"器型":"面具","高":"26cm"}',
   '鎏金铜面具出土于三星堆遗址，造型神秘威严，金铜交融的工艺代表了东汉金属加工的最高水准。',
   '["铜器","面具","东汉"]')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `story` = VALUES(`story`), `tags` = VALUES(`tags`);

-- ----------------------------------------------------------------------------
-- 9. 公告/新闻（notice 公告 / news 新闻）
-- ----------------------------------------------------------------------------
INSERT INTO `nft_announcements`
  (`id`, `title`, `summary`, `content`, `cover_image`, `type`, `subtype`, `tag_color`, `is_top`) VALUES
  (1, '司南秘宝盲盒系列首发公告', '盲盒系列正式上线，开启得随机限定藏品',
   '<p>司南秘宝盲盒系列现已正式发售，每份99元，开启后随机获得限定藏品。</p>', NULL, 'notice', 'activity', '#e6a55a', 1),
  (2, '青铜面具合成计划开启', '集齐指定藏品即可合成限定青铜面具',
   '<p>合成活动长期有效，集齐龙纹罗盘与云端法器各一份即可合成青铜面具。</p>', NULL, 'notice', 'compose', '#7a9d54', 0),
  (3, '平台正式上线运营公告', '司南珍藏数字藏品平台正式上线',
   '<p>司南珍藏数字藏品平台于今日正式上线运营，欢迎各位藏友入驻。</p>', NULL, 'news', 'operation', '#5a8de6', 0)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `summary` = VALUES(`summary`), `content` = VALUES(`content`);

-- ----------------------------------------------------------------------------
-- 10. 首页轮播图
-- ----------------------------------------------------------------------------
INSERT INTO `nft_banners` (`id`, `image`, `description`, `sort_order`, `is_active`) VALUES
  (1, '/images/banner-1.jpg', '司南珍藏品牌主视觉', 1, 1),
  (2, '/images/banner-2.jpg', '秘宝盲盒首发', 2, 1)
ON DUPLICATE KEY UPDATE
  `description` = VALUES(`description`), `sort_order` = VALUES(`sort_order`);

-- ============================================================================
-- 执行完成：奖品 won 计数不清零（保留抽奖历史），重复执行仅刷新配置
-- ============================================================================
