# `qa/` 是什么

本机实跑用的**功能验收脚本**（SIT / E2E）。它们**不参与构建、不参与部署**，
生产机上不该有这些文件；它们存在的意义是：改动前后各跑一次，看结论有没有变。

## 一、共用护栏（这一点是全目录最关键的约定）

目录里有 30 个测试脚本 + 1 个基座（`ls *.php` 数出来 31 个文件），这 30 个测试脚本**全部 `require bootstrap_db.php`**。
全目录只有 `bootstrap_db.php:43` 这一处创建 PDO；`git ls-files '*.php'` 数出来**已跟踪 29 个**，
没提交的是 `sit_t6b_priority_window.php`（按你要求保留未跟踪，见第四节）与 `realname_audit_modes_verify.php`
（2026-10-10 新写，等提交批准，见 `deploy/RUNBOOK.md` 7.12/7.13）。
> 顺带纠正本节原来的一句话：原文写的是「已跟踪的 PHP 文件 30 个（含基座），唯一没提交的是 t6b」，
> 按 `git ls-files` 实测少算了未跟踪那份，30 与 29 之间差的正是它。
`realname_audit_modes_verify.php` 是 2026-10-10 为「实名审核模式」新写的一份，同日又按「一证一号」扩到 **78 项、全过、连跑两轮都全绿**
—— 它是全目录这轮唯一跑过的脚本，其余的「现在还是不是绿的」仍然未知（见第三节）。
它有两道拒绝执行的闸门：

- `APP_ENV` 必须是 `sit` / `test` / `dev`，否则打印「拒绝执行…防止误操作生产数据」并 `exit(1)`；
- 库凭据只能由环境变量 `QA_DB_USER` / `QA_DB_PASS` 注入，**仓库里不存明文库口令**；
  库名/端口/主机可用 `QA_DB_NAME` / `QA_DB_PORT` / `QA_DB_HOST` 覆盖（默认 `sinan_nft` / `3306` / `127.0.0.1`）。

所以正确跑法固定是这个形状（以基线重建为例）：

```bash
# 1. 后端起来（默认 127.0.0.1:8080，命令见 ../sinan-nft-backend/README.md）
# 2. SIT 库先建好：bash ../database/deploy.sh
# 3. 跑脚本
APP_ENV=sit QA_DB_USER=<沙箱账号> QA_DB_PASS=<沙箱口令> php qa/sit_baseline_reset.php
```

需要打别处的后端时用 `QA_BASE` 覆盖基址（36 处引用都走它）；
`QA_BASE` 在 Windows 上的 `php -S` 是单线程的，并发类脚本（`sit_h*`）要配 round-robin 多端口，
这条坑记在 `deploy/RUNBOOK.md`。

`bootstrap_db.php` 还提供 `qa_seed_wallet_ledger()`：给「直接用 SQL 写余额」的夹具补一条 `recharge`
开账流水，否则 `sit_z_identity.php` 的资金恒等式（∑balance = ∑充值 − ∑消费 − ∑提现）会被夹具自己打穿。

## 二、按家族分类（31 个 PHP + 1 份历史报告 + 1 份忽略规则）

| 家族 | 脚本 | 是什么 |
|---|---|---|
| **基座** | `bootstrap_db.php` | 共享连接 + 上述两道护栏 + 钱包开账夹具。不是测试脚本，被其余全部脚本引用 |
| **夹具基线** | `sit_baseline_reset.php` | 重建标准冒烟用户 `id=1~8`（固定手机号），供所有 `sit_t*` 共享；必须先跑它 |
| | `demo_locked_fixture.php` | 造人工验收用的演示数据（多藏家挂单 + 求购 + 成交 + 1 条锁定中）；`--renew` 重新锁定（锁定态只保 300 秒） |
| **并发与资金安全专项** | `sit_h1_oversell.php` | 爆发超卖：200 并发下单于 `edition=100` → 恰 100 成功，`sold+locked≤edition`、serial 唯一连续 |
| | `sit_h2_race_idempotency.php` | 同钱包并发支付幂等：余额 10 元 30 笔并发 → 恰 10 成功，无负余额、资金守恒 |
| | `sit_h3_read_stability.php` | 读写混合负载一致性（MVCC 无脏读），含负例路径 |
| | `sit_h5_caps.php` | 限额判定踩 InnoDB 读视图时机的坑（读视图在**第一条读语句**固定，不是在拿到 `FOR UPDATE` 行锁之后） |
| | `sit_z_identity.php` | 全库资金恒等式复核 |
| | `sit_z_abort.php` | Z2 事务原子性审计：静态检查合成/盲盒/分解/寄售四个控制器的事务三要素 |
| **业务流程端到端** | `e2e_full_verify.php` | 大杂烩主链路：登录（图形码）/藏品/购买/公告/盲盒/合成/签到/抽奖/抽签购/实名/支付密码/充值 |
| | `e2e_cond_airdrop.php` | 条件空投发放（按手机号尾号等条件），期望值与后端同 SQL 口径动态算 |
| | `e2e_behavior_airdrop.php` | 行为空投发放（签到天数 / 邀请数 / 登录频次 / 注册窗口） |
| | `sit_batch_buy.php` | 批量购买：地板价扫描与锁定集合一致、取消批量单全部恢复 `selling`、并发双批量无交集 |
| | `sit_market_locked.php` | 挂单锁定态：下单未付 → `locked=true` 且重复下单拒 3005；取消 → 恢复 `selling` |
| | `sit_t6_marketing.php` | 营销活动专项（含 QF-D1 `qualification_whitelists` 缺 `status` 列的回归） |
| | `realname_audit_modes_verify.php` | 实名专项（审核模式 + 一证一号），**78 项**：两份迁移幂等（各连跑两遍是 no-op）→ auto 提交即通过且写入证号指纹 → auto 下三条防线仍在 → **同一张证号的第二个账号被拒、末位 `x/X` 算同一张、四个接口都不透出 `id_card_hash`** → manual 两步流零破坏 + 待审不占坑 + 管理员点重复被拦（`4220` + 对方 UID + `audit_conflict` 留痕）→ 只读统计命令跑通。**它自己多加了一道护栏**：`QA_BASE` 指向非本机地址时直接 `exit(1)`（除非 `QA_ALLOW_REMOTE=1`），因为脚本会改配置值、注册测试账号、执行迁移 —— 生产侧只需要「只读查一次 + 后台点一下 + 只读统计」，见 `../deploy/RUNBOOK.md` 7.12 与 7.13 |
| | `sit_t6b_priority_window.php` | 优先购时间窗口回归（对应运营口径）。**目前仍是未跟踪状态，按你要求保留不提交** |
| | `sit_t7_refund.php` | 退款全链路：发起→复核→执行、大额走审批中心、不能自审、资金/资产/库存三合一原子回滚 |
| | `sit_f4f5_remaining_fixes.php` | 零散缺陷回归：D1 下架重挂 / K02 转赠 / K03 求购 / K06 软删 / K07 关寄售联动 / DC03 分解入口 / RF11 中签人数 / BB35 空投盲盒 |
| | `sit_t45_fixes_regression.php` | K01/K04/K05 寄售开关与价格管控、S06 置换过户、B08 求购生成订单、SY23 合成按 `result_quantity`、RF03 抽签购 C 端入口 |
| | `sit_upload_cleanup.php` | 上传图片清理功能（幂等，可重复执行） |
| | `verify_user_tail_filter.php` | 后台按尾号筛选用户：单/多/多位尾号、非法值、与状态组合、导出分页口径 |
| **后台模块专项** | `sit_t72_rbac.php` | 权限字典完整性（91 码 + 路由绑定码全在册）、5 角色登录响应权限集与 DB 矩阵一致 |
| | `sit_t73_cms.php` | CMS：轮播增删改/toggle/排序 + C 端实时生效、`cms:*` 权限归属 |
| | `sit_t74_chain.php` | 链配置：密钥 AES 落库、接口永不回显明文、空密钥保持原值、RPC 校验 |
| | `sit_t75_logs_trash_snapshot.php` | 审计日志筛选、链配置密钥脱敏留痕、登录日志、回收站、快照 |
| | `sit_t76_platform_cleanup.php` | 平台数据清理：影响面预览（只读）+ 执行前参数校验 + 短信频控 + 四角色权限隔离 |
| | `sit_t77_markpaid.php` | 线下收款标记：`nft_payments.payment_method` 枚举与 `PaymentService::CODES`/`SystemController::CHANNEL_CODES` 对齐 |
| **安全与全量审计** | `sit_s1_security.php` | 越权专项：水平越权（IDOR）、垂直越权（C 端 token ↔ 管理端 token 交叉）、无 token、伪 token、路径穿越类探针 |
| | `sit_full_audit.php` | 全量接口审计（125 条路径，自清洁用独立号段 `139000091xx` / 藏品段 `95xx`，可重复执行） |
| **结论报告（非脚本）** | `FUNCTION_VERIFICATION_REPORT.md` | 2026-09-28 那次「全功能端到端闭环验证」的报告：结论是核心链路与后台全绿，另发现 1 中危安全缺陷 + 1 低危前端缺陷。**它是历史快照，不是当前状态** |
| | `.gitignore` | 忽略脚本跑出来的 token 缓存（`sit_tokens.json` / `sit_admin_token.txt`），别提交 |

## 三、读这些脚本时要知道的三件事

1. **绝大多数脚本本轮没重跑过**，所以「现在是不是还全绿」未知、不要当结论用。
   2026-10-10 这一天跑过的只有两处：新写的 `realname_audit_modes_verify.php`（49 项 → 加完一证一号 **78 项，连跑两轮都全绿**，见第二节），
   以及后端 phpunit（全量 **70 例 751 断言全过**；`--testsuite unit` 37 例 332 断言）。
   `e2e_full_verify.php` 与 `sit_t6_marketing.php` 因为「实名默认值改成 auto」被各加了一行钉 `manual` 的前置，
   **只做了 `php -l`、没整跑**（前者还依赖 `sit_baseline_reset.php` 先把冒烟用户 id=1~3 建回来）。
   跑实名那份脚本会**每次新注册 5 个 `139********` 账号并留下实名数据**（沙箱库攒多了不影响结论，
   要清就按手机号删；`php think realname:id-hash` 的统计口径只算 `is_realname=1` 且未软删的行）。
2. **口径以代码和 `deploy/RUNBOOK.md` §7.6–7.8 为准**，不以脚本头注为准。已确认与旧口径不符的地方：
   市场可见性现在**只看「寄售开关」**（不看发售状态、不看有无挂单）；关注态只认
   `/user/favorites`。脚本里凡是按「已发售才进市场」「有挂单才算在架」写的断言，都是旧口径。
3. 名字里的任务号（`t45`/`t6`/`t72`…`t77`/`z_`）来自当年那份测试计划的编号，**不是执行顺序**。
   `*_upgrade.sql` 结尾的脚本对应 `database/` 里的同名 SQL，跑之前要确认库已经打过。

## 四、待清理项（2026-10-10 记录，未动）

- **18 个文件**（17 个 `.php` + `FUNCTION_VERIFICATION_REPORT.md`）留着**口令形态的字符串**（值故意不抄在这份文档里 ——
  一份讲「仓库禁止明文口令」的说明自己把口令复制一遍就自相矛盾了）。原来这里写的是「7 个文件」，
  那是只数了我当时点名的几处；按 `grep -rl admin123 qa/ | wc -l` 实测是 18 个，就此更正。分三类看：
  ① **内置演示超管** `admin/admin123` —— 绝大多数就是它，出现在登录行与注释里（`full_init.sql` 自带 `admin`，
  报告里也写了要立刻改），不是生产凭据；
  ② **沙箱库账号/口令**一处（`qa/sit_full_audit.php:3` 的头注，形如 `MySQL sinan_nft（<账号>/<口令>）`）——
  这一条最该先换掉，它是库口令而不是演示账号密码，虽然只是本机沙箱库，但和 `bootstrap_db.php` 立的
  「凭据只能走环境变量注入」直接冲突；
  ③ **脚本自定的测试口令**（C 端注册用的一个固定密码、四角色重建用的另一个），
  新写的 `realname_audit_modes_verify.php` 属于这类：头注 `:19` 提演示超管、`:86` 是 C 端注册口令、
  `:145` 与 `:147` 是后台登录行。
  三类都建议下一轮换成占位符或环境变量注入；本轮**没有**动它们（改动会连带影响 17 个脚本的可跑性，
  而你要求过「删/改之前先给证据」，这里只把清单和实测数字摆出来）。
- `sit_z_abort.php` / `sit_z_boxchi.php` 这类只做静态检查或概率检验的，跟业务回归混在一层，
  子目录归档（`active/` + `archive/`）是 2026-10-10 那轮**明确暂缓**的事，理由见 `deploy/RUNBOOK.md` 7.10。
