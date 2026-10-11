-- =====================================================================
-- 2026-10-11 补齐两枚模块开关的配置行：lucky_enabled / synthesis_enabled
--
-- 背景（这是「合成活动创建后 C 端不显示」的第二个成因，第一个见后台新建表单默认停用）：
--   C 端判开关的方式是「值必须等于 1 才算开」，缺行时 value() 返回 NULL → (int)NULL = 0 → **视为关闭**：
--     app/controller/Synthesis.php:22（列表直接给空状态）、:145（提交合成被拦）
--     app/controller/LuckyDraw.php:31、:133（抽奖同理）
--   后台读同一枚开关的方式却是「缺行就当作已开启」：
--     app/admin/controller/MarketingController.php:792  $rows[$key] ?? 1
--   两边默认值正好相反 —— 库里没有这两行时，后台开关显示「已开启」，C 端却什么都不给看，
--   界面上没有任何一处提示不一致，运营只能得出"活动没生效"的结论。
--
-- 为什么"缺行"会真实存在：
--   `20260919_checkin_activities_and_module_switches.sql` 给**老库**补种过这三行，
--   但 `database/full_init.sql` 的种子 INSERT（id 1~39）里从来没有 lucky_enabled / synthesis_enabled，
--   只有 checkin_enabled。也就是说凡是拿这份 dump 建起来的库（新环境、重装、测试库）天生缺行。
--   本次同时补 dump 与这条迁移，两条安装路径对齐。
--
-- 语义：'1' = 开启，与 20260919 那次种的值保持一致。
--   **ON DUPLICATE KEY UPDATE 用的是空更新（config_key = config_key）**：
--   已存在的行绝不被覆盖 —— 运营如果亲手关过抽奖/合成，这条迁移不会偷偷给他打开。
--   依赖 nft_system_configs.uk_config_key 唯一键（建表语句里就有）。
--
-- 幂等：可重复执行；第二次起只走 ON DUPLICATE 分支，零行变更。
-- 执行（生产，DDL 需 root 账号；本文件只 INSERT，不锁表、不动结构）：
--   ssh root@<IP> 'MYSQL_PWD=$(cat /root/.mysql_root_pass) mysql -uroot sinan_nft' < 20261011_module_switch_seed_rows.sql
--   或走 database/deploy.sh 按文件名顺序应用（排在 20261010_realname_id_card_unique.sql 之后）。
--
-- 执行后请回后台「营销活动 → 合成/抽奖」确认模块开关的真实状态，这一枚开关从此与 C 端同源。
-- 详见 deploy/RUNBOOK.md 7.14。
-- =====================================================================

INSERT INTO `nft_system_configs` (`config_key`, `config_value`, `description`)
VALUES
    ('lucky_enabled',     '1', '抽奖模块开关：0关闭 1开启（C端关闭时空状态）'),
    ('synthesis_enabled', '1', '合成模块开关：0关闭 1开启（C端关闭时空状态）')
ON DUPLICATE KEY UPDATE `config_key` = `config_key`;

-- 验证（手动执行，应看到三行且 config_value 与后台开关一致）：
--   SELECT config_key, config_value, updated_at FROM nft_system_configs
--     WHERE config_key IN ('lucky_enabled', 'synthesis_enabled', 'checkin_enabled')
--     ORDER BY config_key;
