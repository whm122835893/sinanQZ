# `database/` 是什么、怎么用

这个目录放着 **28 个 SQL + 1 个部署脚本 + 1 份开发数据**。它们不是同一类东西，
按名字的相似程度去猜"该跑哪个"是会踩坑的 —— 所以先把三套安装方式的关系说清楚，再给分类清单。

## 一、三套安装方式，选哪一条

| 场景 | 用哪条路 | 命令 |
|---|---|---|
| **生产机建库**（唯一正确做法） | 合并版 `full_init.sql` | 见 `deploy/RUNBOOK.md` 第 3 节：`mysql sinan_nft < full_init.sql`，验收 80 张表 |
| **本机从零重建一个干净库**（开发/联调/SIT） | 拆分版 `deploy.sh`，按脚本内 `BASE_FILES` 顺序跑 | `bash database/deploy.sh` |
| **已有库跟上字段演进** | 后端 `sinan-nft-backend/migrations/`（23 个，按日期编号） | 逐个 `mysql` 导入，或用后端自己的迁移流程 |

> **数字口径对照**（这三个数不一样是正常的，别当成笔误，但也别当成"两条路等价"的证明）：
>
> | 数字 | 出处 | 是什么口径 |
> |---|---|---|
> | 985 | 2026-10-10 解析 `full_init.sql` 的 `CREATE TABLE` 块 | 文件里的**列声明数** |
> | 981 | `deploy.sh:153` 的期望注释 | **拆分版**跑完后 `information_schema.columns` 的实测期望值 |
> | 982 | `deploy/RUNBOOK.md` §3 | **合并版**导完后库里的实测值（同年记的，外键 75） |
>
> 80 张表这个数三处一致。至于拆分版与合并版的产物**是否逐列完全等价**，从来没有比对过 ——
> 想收口就得各建一次库再对 `information_schema.columns` 做双向差集，这是 RUNBOOK 7.10 里列明的**未验证项**。

> ⚠️ **`deploy.sh` 不带 `--verify` 时会先 `DROP DATABASE IF EXISTS`**，且默认连的是
> `127.0.0.1:3399`（另一套本机沙箱实例，见脚本第 20-24 行的 `DB_HOST/DB_PORT/DB_USER` 默认值）。
> **不要在生产机上执行它**，生产走上面第一行。
> 另外 **严禁把 `dev-only/seed-dev.sql` 导入生产** —— 那是演示数据（假用户、假藏品、假订单）。

重叠是历史造成的：`full_init.sql`（2026-09-21 生成的 v3.0 快照）已经把拆分版和当时全部增量
**合并进了一份文件**。2026-10-10 的一次只读审计按 CREATE TABLE 块逐列比对过，结论是
其余 27 个 SQL 与当时 19 个 migrations 的建表列，**全部已包含在 `full_init.sql` 里**（RUNBOOK §3 也明确写着
生产不要按拆分版手动跑）。所以拆分版的价值不在"全新部署更快"，而在**能追溯每个字段是哪次改动来的**。

## 二、文件分类清单

### 1）合并版基线（1 个，不属于 deploy.sh）

| 文件 | 说明 |
|---|---|
| `full_init.sql` | 80 张表 / 3065 行 / 208KB。生产建库就用它。内置 `admin` 超管与 104 条 RBAC 权限，**密码哈希是开发期的，上线必须重置**：`php think admin:reset-password admin` |

### 2）拆分版基础库表（23 个，`deploy.sh` 第 `[2/4]` 步按数组顺序执行）

顺序**不能随意调整**，脚本头部注释记了两条硬约束：

- `raffle_purchase_upgrade.sql` 必须早于 `raffle_admin_upgrade.sql` —— 后者有
  `AFTER \`purchased_quantity\``，颠倒会 `1054 Unknown column`；
- `mysql` 客户端默认**遇错即停且非 `--force`**，一条语句失败会静默吞掉整份文件剩余内容，
  所以"看起来跑成功了"并不等于"全部语句执行了"。`deploy.sh` 靠捕获输出与返回码来报 `[WARN]`。

| 文件 | 一句话用途 | 动的是什么 |
|---|---|---|
| `init.sql` | C 端主库基线 v2.2.3 | 建表 33（含 56 外键 / 16 CHECK） |
| `admin_init.sql` | 管理端扩展 v1.0.0 | 建表 22 + 角色/权限/超管/短信与支付渠道种子 |
| `fusion_upgrade.sql` | 后台融合升级（sinan-admin 主干化） | 建表 3 + 改列 2 |
| `full_feature_upgrade.sql` | 全功能升级 v3.0 | 建表 6 |
| `swap_plan_upgrade.sql` | 置换计划 | 建表 4 |
| `rbac_snapshot_upgrade.sql` | RBAC 权限字典补全 + 数据快照 | 建表 2（`nft_holdings_snapshots` / `nft_trade_snapshots`） |
| `marketing_activity_upgrade.sql` | 营销活动（幂等） | 建表 1 + 条件改列 |
| `activity_reward_upgrade.sql` | 活动奖励体系（幂等） | 建表 5 |
| `raffle_draw_code_system_upgrade.sql` | 抽签码体系（幂等） | 建表 1 |
| `raffle_purchase_upgrade.sql` | 抽签购 C 端入口（RF03 配套，幂等） | 条件改列：加 `purchased_quantity` |
| `raffle_admin_upgrade.sql` | 抽签后台模块（幂等，**依赖上一条先跑**） | 建表 1 + 条件改列 24 + 删表 1 |
| `admin_sms_scene_upgrade.sql` | `verification_codes.scene` ENUM → VARCHAR(32)（幂等） | 条件改列 1 |
| `batch_buy_upgrade.sql` | C 端市场挂单批量购买 | 改列 1 + `nft_system_configs` 开关种子 |
| `category_scene_upgrade.sql` | 分类管理升级（幂等） | 条件改列 + `nft_categories`/权限种子 |
| `collectible_recover_upgrade.sql` | 藏品回收权限补登记 v1.1.0 | **只有种子**：`nft_admin_permissions` + 超管/运营授权，无 DDL |
| `fusion_final_upgrade.sql` | 融合版最终升级 | 建表 + 改列（`nft_users` 加 `realname_status` 等）+ 1 处 UPDATE |
| `payment_yeepay_upgrade.sql` | 新增「易宝支付」渠道 | `nft_payment_channels` 种子 1 |
| `swap_c2c_removal.sql` | C 端用户间置换（补差价）体系下线 | **删表 2**（`nft_swap_records` / `nft_swap_offers`） |
| `002_add_snapshots.sql` | 用户快照功能 | 建表 2 + 权限种子 |
| `announcement_publish_upgrade.sql` | 公告发布 | 改列：`nft_announcements` 加 `status` + `publish_time` |
| `artifact_status_upgrade.sql` | 文物展馆状态 | 改列：`nft_artifacts` 加 `status` |
| `refund_idempotency_upgrade.sql` | 退款幂等约束（审查 C5 配套，幂等） | 改列 2：`nft_refunds` 加虚拟生成列 `active_order_id` + 唯一约束 |
| `upload_image_cleanup_upgrade.sql` | 上传图片清理功能 | 权限种子 3，无 DDL |

> 「条件改列」= 为了幂等，把 `ALTER` 包在 `IF 列不存在 THEN @sql ... PREPARE` 里
> （`raffle_admin_upgrade.sql` 有 48 处这类分支）。**这类文件用行首语句数会少算**，
> 判断它改了什么要看 `ADD COLUMN` 而不是数 `ALTER TABLE` 行数。


> 命名遗留：`002_add_snapshots.sql` 是唯一带数字前缀的（曾经想过编号方案，后来都用
> `*_upgrade.sql` 了，`001_` 并不存在）。别被它误导成"要按编号跑" —— 真实顺序只看 `deploy.sh` 的数组。
> `*_upgrade.sql` 里也有例外：`swap_c2c_removal.sql` 是下线、
> `realname_status_pair_repair_upgrade.sql` 是数据修补，名字里的 "upgrade" 不代表同一类。

### 3）数据修补（2 个，`deploy.sh` 第 `[4/4]` 步，在库结构落定后跑）

这两份**没有 DDL，只归位存量脏数据**，都是幂等条件 UPDATE，重复执行不产生新变化：

| 文件 | 修什么 |
|---|---|
| `realname_status_pair_repair_upgrade.sql` | `is_realname` 与 `realname_status` 必须成对（合法组合只有 0/0、0/1、0/3、1/2）；qa 夹具与 `full_init` 种子都可能只写了能力位 |
| `wallet_row_backfill_upgrade.sql` | 给「有用户、无 `nft_wallets` 行」的存量用户补空钱包；否则充值/余额支付/卖家结算会拿到 null 后报 5001。代码侧已有 `WalletService::ensureLocked` 兜底，本脚本负责把历史数据一次归位 |

### 4）未接入 deploy.sh 的老库补丁（2 个，只在特定情况下手动跑）

| 文件 | 为什么没接进去 |
|---|---|
| `payment_method_channel_align.sql` | 给 `nft_payments.payment_method` 补上 `huifu/unionpay/yeepay` 三个枚举值。**结论已经折进 `init.sql:415` 与 `full_init.sql`**，所以全新部署不需要；只有对折叠之前的老库才手动执行 |
| `wallet_refund_trans_type_upgrade.sql` | 给 `nft_wallet_transactions.trans_type` 补 `refund`。**同样已折进 `init.sql:161` 与 `full_init.sql`**，同上 |

> 这两份的判定口径可以直接复现：`grep -n "payment_method\` ENUM" database/init.sql`
> 与 `grep -n "trans_type\` ENUM" database/init.sql` —— 枚举里已含新值即为"已折叠"。

## 三、后端 migrations 与这里的分工

`sinan-nft-backend/migrations/`（23 个，文件名形如 `20261009_market_type_and_recommend.sql`）
是**随功能迭代追加的增量**，按日期编号、面向"已有库往前跟"。
上面拆分版那 23 个是**建库期的历史沉淀**，此后不再新增文件 —— 新功能一律走 migrations。
（2026-10-10 审计时两者仍是重叠的：migrations 的建表列也全在 `full_init.sql` 覆盖范围内 ——
 说明生产导入 `full_init.sql` 后不需要重跑 migrations，但**新增的迁移必须单独执行**，
 判断方法是比对库里有没有那一列。）
> 第 20 份 `20261010_realname_audit_mode_auto.sql` 是个例外：**没有 DDL，只归位一行配置**
> （`realname_audit_mode` 由 manual 改为 auto）。这类"改数据不改结构"的迁移，判断方法要换成
> 比对库里的值，而不是比对列 —— 详见 `deploy/RUNBOOK.md` 7.12。
> 第 21 份 `20261010_realname_id_card_unique.sql` 是**有 DDL** 的那一类（`nft_users` 加 `id_card_hash CHAR(64) NULL`
> 列 + 普通索引 `idx_id_card_hash`），两段都用 `information_schema` 判断包成幂等，重复执行只打 `skip`。
> 它配套的**结构之外**还有一步数据动作，SQL 文件本身做不了（指纹要带 `APP_KEY` 才能算，MySQL 里没有这个密钥）：
> `php think realname:id-hash` 先只读统计存量重复，确认口径后再加 `--backfill` 回填。见 `deploy/RUNBOOK.md` 7.13。
> 第 22 份 `20261011_module_switch_seed_rows.sql` 同属"改数据不改结构"，而且是第 20 份那种**补行**的继续：
> 给 `nft_system_configs` 补 `lucky_enabled` / `synthesis_enabled` 两行（值 `'1'`）。
> 这里有个值得记的坑：同一个开关在两端对"行不存在"的解释**是相反的** ——
> C 端 `(int)…->value('config_value') === 1` 把缺行当成**关闭**，后台 `$rows[$key] ?? 1` 把缺行当成**开启**，
> 于是老库上"后台看着开着、C 端却是空状态"。用 `ON DUPLICATE KEY UPDATE config_key = config_key` 写成幂等，
> **绝不覆盖运营已经选过的值**（沙箱实测：先置 `0` 再跑仍是 `0`；整行删掉再跑才补回 `1`）。详见 `deploy/RUNBOOK.md` 7.14。
> 第 23 份 `20261011_inbox_arrival_types.sql` 回到**有 DDL** 那一类，但只有一句 `ALTER … MODIFY COLUMN`：
> 把 `nft_inbox.type` 的枚举从 2 值（`airdrop`/`transfer`）扩到 5 值（再加 `blindbox`/`reward`/`lottery`），
> 用来承接签到、开盒、抽奖中奖的到账通知。枚举**只加值、不改已有取值**，所以库里历史行一行都不会被动（沙箱实测 3 条旧行原样）。
> ⚠ 这一份有个**顺序硬约束**：DDL 必须**先于**后端代码上线。生产 MySQL 是严格模式，
> 代码先上、枚举还是 2 值时，写入新类型会撞 `1265 Data truncated for column 'type'`；
> `InboxService` 的 try/catch 会把业务保住（藏品照发），代价是**通知静默丢失**，只在日志里留一条 error。
> 自查：`SHOW COLUMNS FROM nft_inbox LIKE 'type';` 看到 5 个取值即为已生效。详见 `deploy/RUNBOOK.md` 7.15。

## 四、其他文件

| 文件 | 说明 |
|---|---|
| `deploy.sh` | 本机一键重建：`[1/4]` 建库 → `[2/4]` 23 个基础 SQL → `[3/4]` 23 个 migrations（脚本按 `migrations/*.sql` 通配 glob，新增文件自动进队列）→ `[4/4]` 2 个数据修补，末尾打印表数量统计。`--verify` 只重跑 SQL 不重建库 |
| `dev-only/seed-dev.sql` | 开发联调演示数据，**禁止进生产** |
| `database-design.html` | 实体模型设计 v2.0（715 行，浏览器打开看关系图）。已于 2026-10-10 移到 `../sinan-nft-backend/docs/`，不再和 SQL 混在一层 |

## 五、想收敛的话（已知遗留，未做）

三套安装方式重叠是这个目录唯一的真冗余。彻底收敛（例如把拆分版归档成 `legacy/`、只留一条主路径）
要同时改 `deploy.sh`、README、PROJECT_DOC 与 RUNBOOK §3 的引用，属于会动到部署路径的改动，
2026-10-10 那轮审计明确**暂缓**，理由记在 `deploy/RUNBOOK.md` 7.10。
