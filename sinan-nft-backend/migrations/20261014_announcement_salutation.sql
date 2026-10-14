-- 20261014_announcement_salutation.sql
-- 公告正文的「敬语」与「署名」（2026-10-14 运营需求，见 RUNBOOK §7.37）：
-- 你说「每一条公告都要有敬语和署名，署名在发布公告时可改」，所以这里做两件事：
--   1) 公告表加两列，让每条公告能存自己的敬语 / 落款名（可空，空 = 跟随全局默认）；
--   2) 系统参数加两行，存全局默认的敬语与落款名（后台「系统配置 → 全局参数 → 内容与公告」可改，实时生效不用发版）。
--
-- 落款后面的**日期不在这里存**：由后端按该公告的实际生效时间（publish_time，为空则 created_at）自动带出，
--   运营不用填、也不会出现「改了日期忘了改正文」。逻辑见 app/service/AnnouncementSalutation.php::dateFor()。
--
-- 存量公告不用回填：读取时按「公告自己填的 > 全局默认 > 出厂常量」三级回落（与公告头图同一口径），
--   所以第 26 份跑完的那一刻，线上已有的公告当场就有敬语和署名，一条数据都不用改。
--
-- 幂等：
--   DDL 先查 information_schema 再执行（列已存在就跳过），重复执行无副作用；
--   参数种子只有 INSERT IGNORE、**故意不写 UPDATE**（与 20261014_announcement_disclaimer.sql 同一口径）——
--   这两个值运营会在后台改，重复执行不该把他们改过的文案冲回出厂值。
--
-- 执行（生产，DDL 需 root 账号）：
--   ssh root@<IP> 'MYSQL_PWD=$(cat /root/.mysql_root_pass) mysql -uroot sinan_nft' < 20261014_announcement_salutation.sql
-- 验证（手动执行）：
--   SHOW COLUMNS FROM nft_announcements LIKE 'greeting';
--   SHOW COLUMNS FROM nft_announcements LIKE 'signature';
--   SELECT config_key, config_value FROM nft_system_configs WHERE config_key IN ('announcement_greeting_default','announcement_signature_default');
--   SELECT COUNT(*) FROM nft_system_configs;   -- 跑前 45 行 → 跑后 47 行

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_announcements'
        AND COLUMN_NAME = 'greeting') = 0,
    "ALTER TABLE nft_announcements
        ADD COLUMN greeting VARCHAR(100) NULL DEFAULT NULL
        COMMENT '正文开头敬语（C 端详情页正文第一行）：NULL/空=用系统参数 announcement_greeting_default，真源见 app/service/AnnouncementSalutation.php'
        AFTER cover_image",
    'SELECT "skip: nft_announcements.greeting exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_announcements'
        AND COLUMN_NAME = 'signature') = 0,
    "ALTER TABLE nft_announcements
        ADD COLUMN signature VARCHAR(100) NULL DEFAULT NULL
        COMMENT '正文结尾落款名（日期由系统按生效时间自动带）：NULL/空=用系统参数 announcement_signature_default，真源见 app/service/AnnouncementSalutation.php'
        AFTER greeting",
    'SELECT "skip: nft_announcements.signature exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES (
  'announcement_greeting_default',
  '尊敬的司南用户：',
  '公告/新闻正文开头的默认敬语（C 端详情页正文第一行；单条公告在发布弹窗里填了自己的就以它为准。上限 100 字，留空则用系统出厂值「尊敬的司南用户：」）'
);

INSERT IGNORE INTO `nft_system_configs` (`config_key`, `config_value`, `description`) VALUES (
  'announcement_signature_default',
  '司南运营团队',
  '公告/新闻正文结尾的默认落款名（C 端详情页正文下方右对齐；后面的日期由系统按该公告生效时间自动生成，不用填在这里。单条公告在发布弹窗里填了自己的就以它为准。上限 100 字，留空则用系统出厂值「司南运营团队」）'
);
