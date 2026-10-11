-- =====================================================================
-- 2026-10-11（二）收件箱通知类型扩容：新增 blindbox / reward / lottery
--
-- 背景：
--   nft_inbox 是 C 端「到账弹窗」的唯一数据源（app/controller/Inbox.php:26 读未读，
--   sinan-art-source/src/components/InboxPopup.vue 弹），但建库以来 type 只有两枚取值：
--     enum('airdrop','transfer') —— 只有独立/活动空投与转赠会往里写。
--   盲盒开盒、签到/注册/邀请奖励、抽奖中奖这些"藏品到账"全都不写，用户只能自己翻库存，
--   与"收到东西应该被通知一下"的预期不符（本轮需求确认：这三类也要写入）。
--
-- 本迁移做什么（只放宽枚举取值，不动任何数据、不加列、不改索引）：
--   airdrop  空投到账（原有）
--   transfer 转赠到账（原有）
--   blindbox 盲盒开盒中奖
--   reward   活动奖励到账（签到 / 注册福利 / 邀请奖励 / 后台统一发放）
--   lottery  抽奖中奖（藏品类奖项）
--   枚举只是"增加成员"，已有行的 'airdrop'/'transfer' 原值保留，MySQL 不会重写数据页。
--
-- 为什么不用现成的 airdrop 顶替（省一次 DDL）：
--   type 是弹窗与后续排查唯一的分类依据，把开盒写成 airdrop 会让「谁收到了什么」这件事
--   在数据层面永久失真，事后想分也分不开。多三个取值的代价只是一条 MODIFY，值得。
--
-- 明确不通知的两类，写在这里免得被当成漏配：
--   1) 积分（司南币）奖励：nft_inbox.collectible_id 是 NOT NULL 且带外键，
--      弹窗卡片必须有一件藏品才能渲染，积分到账走钱包流水（nft_wallet_transactions，已在写）。
--   2) 合成产出 / 分解所得 / 购买与抽签付款：用户当场就看着结果，再弹一次是重复打扰。
--      其中盲盒与抽奖虽然也当场显示结果，但接口会把 inboxId 回传给前端就地标记已读
--      —— 记录留档、不二次弹窗，见 app/service/InboxService.php 顶部说明。
--
-- 幂等：MODIFY COLUMN 可重复执行，第二次起是 no-op（取值集合相同）。
-- 执行（生产，DDL 需 root 账号；建议低峰执行，8.0 下枚举扩容走 INSTANT/在元数据层，不复制表）：
--   ssh root@<IP> 'MYSQL_PWD=$(cat /root/.mysql_root_pass) mysql -uroot sinan_nft' < 20261011_inbox_arrival_types.sql
--   或走 database/deploy.sh 按文件名顺序应用（排在 20261011_module_switch_seed_rows.sql 之后）。
--
-- ⚠ 顺序要求：**这条必须先于后端代码上线**。
--   代码先上、枚举没扩，则严格模式下写 'blindbox' 会报 1265 Data truncated，
--   虽然 InboxService 内部已 try/catch 兜住不会拖垮发放事务，但通知会静默丢失。
--   反过来先跑这条 DDL 再上代码，中间窗口里新取值没人写，完全无害。
--
-- 执行后自查（应看到 5 个取值）：
--   SHOW COLUMNS FROM nft_inbox LIKE 'type';
-- 详见 deploy/RUNBOOK.md 7.15。
-- =====================================================================

ALTER TABLE `nft_inbox`
    MODIFY COLUMN `type` enum('airdrop','transfer','blindbox','reward','lottery')
        COLLATE utf8mb4_unicode_ci NOT NULL
        COMMENT '通知类型：airdrop空投/transfer转赠/blindbox开盒中奖/reward活动奖励/lottery抽奖中奖';
