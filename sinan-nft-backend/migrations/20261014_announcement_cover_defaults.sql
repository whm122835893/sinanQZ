-- 20261014_announcement_cover_defaults.sql
-- 公告「类型默认头图」：四个子分类各一个系统参数（2026-10-14 运营需求，口径见 RUNBOOK §7.36）。
--
-- 效果：C 端公告详情页标题下面多一张与类型配套的横幅图。运营没给某条公告单独上传封面时，
--       就按它的子分类用这里配的默认图；上传了就用自己那张（优先级写在 app/service/AnnouncementCover.php）。
--       公告表 nft_announcements 一个字节都不动 —— 不加列、不回填，存量公告当场就有头图。
--
-- 之后要换默认图：后台「系统配置 → 全局参数 → 公告头图」，四个类型各一张，保存后实时生效、不用发版。
--       要某条公告用别的图：在「内容 → 公告管理」的编辑弹窗里给它单独传一张「公告图」。
--       要把某个类型的默认图去掉：把对应参数清空即可（该类型的新公告就不显示横幅，
--       已经单独传过封面的公告不受影响）。
--
-- 图本身在哪：随版本放在 public/uploads/notice-default/announcement-{子分类}.webp。
--       这个目录名不在 UploadController::BIZ_DIRS 白名单里，图片清理服务的闸门从一开始就进不去，
--       所以出厂图不会被「清理未引用图片」移进回收站（详见 §7.20 的两道保护）。
--
-- 幂等：只有 INSERT IGNORE，**故意不写 UPDATE**（与 20261014_announcement_disclaimer.sql 同一口径）。
--   这四个值运营会在后台换图，重复执行本文件不该把他们换过的图冲回出厂图；缺行才补，有行就跳过。
--
-- 没有 DDL：nft_announcements.subtype 是 varchar(20) 而不是 enum，
--   所以这次新增的「寄售公告 consignment」不需要改表结构，也不动列注释（生产零 ALTER）。

INSERT IGNORE INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES (
  'announcement_cover_activity',
  '/uploads/notice-default/announcement-activity.webp',
  '活动公告默认头图（C 端详情页顶部横幅；公告自己上传了封面就用它自己的，清空=该类型不显示横幅）'
);

INSERT IGNORE INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES (
  'announcement_cover_compose',
  '/uploads/notice-default/announcement-compose.webp',
  '合成公告默认头图（C 端详情页顶部横幅；公告自己上传了封面就用它自己的，清空=该类型不显示横幅）'
);

INSERT IGNORE INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES (
  'announcement_cover_consignment',
  '/uploads/notice-default/announcement-consignment.webp',
  '寄售公告默认头图（C 端详情页顶部横幅；公告自己上传了封面就用它自己的，清空=该类型不显示横幅）'
);

INSERT IGNORE INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES (
  'announcement_cover_operation',
  '/uploads/notice-default/announcement-operation.webp',
  '运营公告默认头图（C 端详情页顶部横幅；公告自己上传了封面就用它自己的，清空=该类型不显示横幅）'
);
