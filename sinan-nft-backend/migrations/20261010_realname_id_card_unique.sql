-- =====================================================================
-- 2026-10-10 一证一号：给 nft_users 加「身份证指纹」比对列
--
-- 背景：`id_card` 是 AES-256-CBC + **随机 IV** 加密存的（common.php aes_encrypt），
--      同一个证号两次落库的密文不一样，所以在这列上加唯一索引**拦不住重复证号**。
--      auto 审核模式（同日 20261010_realname_audit_mode_auto.sql）上线后，
--      一张证号可以无限注册并自动通过 —— 本迁移补上比对列，判定逻辑见 RealnameService::approve()。
--
-- 语义：
--   id_card_hash = HMAC-SHA256( 'sinan-idcard|' + 归一化证号, APP_KEY )，64 位十六进制。
--      归一化 = 去空白 + 末位 x 转大写，所以 …757X 和 …757x 算同一个证号。
--      **只有审核通过的账号才写这一列**（待审核/已驳回留 NULL）——
--      MySQL 唯一索引里 NULL 互不相等，正好让未认证的账号随便存；
--      用 HMAC 而非裸 SHA256：证号可枚举，裸摘要拖库后能暴力撞出来反推。
--
-- 为什么这里建的是**普通索引**而不是 UNIQUE：
--   存量库里可能已经有「同一证号认证了多个账号」的数据（历史缺陷留下的），
--   硬唯一索引会让这些行回填失败、迁移直接报错。先建普通索引 + 应用层查重（零漏网、不改任何人状态），
--   用 `php think realname:id-hash` 只读统计出重复组，人工处理完后再升级为硬约束（一条 SQL，代码不用改）：
--     ALTER TABLE nft_users DROP INDEX idx_id_card_hash, ADD UNIQUE KEY uk_id_card_hash (id_card_hash);
--
-- 注意：指纹跟着 APP_KEY 走。换密钥时 php think rekey:encrypted 会在重加密证号的同时按新密钥改写指纹，
--      换完再跑一次 realname:id-hash 只读核对；若绕过 rekey 直接改 .env，存量指纹会全部对不上、
--      查重静默失效，必须补跑 `php think realname:id-hash --backfill`。
--
-- 数据回填不在本文件里（MySQL 算不出带密钥的 HMAC），用命令做，幂等：
--   cd /www/wwwroot/sinan-nft-backend && php think realname:id-hash           # 只读统计
--   cd /www/wwwroot/sinan-nft-backend && php think realname:id-hash --backfill # 回填存量指纹
--
-- 幂等：DDL 先查 information_schema 再执行，重复执行无副作用（列已存在/索引已存在时跳过）。
-- 执行（生产，DDL 需 root 账号）：
--   ssh root@<IP> 'MYSQL_PWD=$(cat /root/.mysql_root_pass) mysql -uroot sinan_nft' < 20261010_realname_id_card_unique.sql
-- =====================================================================

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_users'
        AND COLUMN_NAME = 'id_card_hash') = 0,
    "ALTER TABLE nft_users
        ADD COLUMN id_card_hash CHAR(64) NULL DEFAULT NULL
        COMMENT '身份证指纹 HMAC-SHA256(归一化证号, APP_KEY)：一证一号的比对列，仅审核通过时写入，NULL=未认证'
        AFTER id_card",
    'SELECT "skip: nft_users.id_card_hash exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nft_users'
        AND INDEX_NAME = 'idx_id_card_hash') = 0,
    'ALTER TABLE nft_users ADD INDEX idx_id_card_hash (id_card_hash)',
    'SELECT "skip: idx_id_card_hash exists"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 验证（手动执行）：
--   SHOW COLUMNS FROM nft_users LIKE 'id_card_hash';
--   SHOW INDEX FROM nft_users WHERE Key_name = 'idx_id_card_hash';
--   SELECT COUNT(*) AS verified, COUNT(id_card_hash) AS hashed FROM nft_users WHERE is_realname = 1;
