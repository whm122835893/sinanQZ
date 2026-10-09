# sinanQZ 数字藏品平台（司南珍藏）

前后端 + 管理后台 + 数据库单仓库。**管理后台（sinan-admin）为两套后台的融合版**：以新后台为主干 UI 风格，移植了原后台独有的功能模块，并新增区块链上链配置（文昌链 / 联盟链 / 蚂蚁链）。原管理后台（sinan-nft-admin）已由融合版完全替代并从仓库移除，历史代码可通过 git 记录查阅。

## 目录结构

```
sinanQZ/
├── sinan-art-source/      # C 端 H5（Vue 3 + Vite 5 + Pinia + Vant 4）
├── sinan-admin/           # 管理后台（融合版，Vue 3 + Vite 5 + Pinia + Element Plus + ECharts）
├── sinan-nft-backend/     # 后端（ThinkPHP 8 多应用：api = C 端 / admin = 管理端）
│   └── docs/              # 后端部署说明 DEPLOY.md + 数据库实体模型 database-design.html
├── database/              # 数据库脚本（28 个 SQL + deploy.sh + 开发种子数据）
│   ├── README.md          # ✅ 动手前先看：三套安装方式怎么选、每个 SQL 的用途与归属
│   ├── deploy.sh          # 本机一键重建拆分版（顺序敏感 + 幂等容错；不带 --verify 会 DROP DATABASE）
│   ├── full_init.sql      # 合并版基线 80 张表（生产建库只用它，见 deploy/RUNBOOK.md 第 3 节）
│   └── dev-only/seed-dev.sql  # 开发联调演示数据（生产严禁执行）
├── deploy/                # 上线手册 RUNBOOK.md + Nginx 站点配置 + 生产 env 模板 + 抠图小工具
│   └── RUNBOOK.md         # 生产机从零到可访问的每一步，含 7.x 各功能改造的口径记录
└── qa/                    # 本机实跑的功能验收脚本（30 个 PHP，不参与构建与部署）
    └── README.md          # ✅ 五个命名家族、共用护栏（APP_ENV + 环境变量注入凭据）、每个脚本一行用途
```

> 目录里每个子系统的细节写在**它自己那份 README**里（`database/README.md`、`qa/README.md`、
> `sinan-nft-backend/README.md`、`sinan-admin/DEVELOPMENT.md`）。本文件只留地图和快速启动，
> 不再逐个 SQL 抄一遍 —— 抄两处就会有第二处过时（2026-10-10 就因为这样修过一轮过时数字）。

## 系统架构

```
┌─────────────┐   /api/**     ┌──────────────────────────────┐
│  C 端 H5     │ ───────────▶ │  ThinkPHP 8 多应用后端         │
│ (sinan)     │   JWT-user   │  ├─ app/api    （C 端业务）     │
└─────────────┘               │  ├─ app/admin （管理端业务）    │      ┌──────────┐
┌─────────────┐   /api/admin  │  ├─ 中间件：AdminAuth(JWT)     │ ──▶  │ MySQL 8  │
│  管理后台    │ ───────────▶ │  │         AdminPermission(RBAC)│      │ 80 张表   │
│ (sinan-admin)│   JWT-admin  │  └─ Service：ChainService 等     │      └──────────┘
└─────────────┘               └──────────────────────────────┘
```

关键设计：

- **双 JWT 体系**：C 端与管理端使用独立密钥签发，互不通用；管理端 token 另校验账号实时状态（锁定/停用即时生效）。
- **RBAC 权限**：91 个权限点 × 5 个预置角色，路由层通过 `AdminPermission` 中间件精确校验权限码；超级管理员（is_super）绕过校验。
- **审计日志**：所有敏感操作（实名全量查看、退款、清库、链上配置变更等）强制写操作日志。
- **三链适配层**：`ChainService` 统一封装文昌链（BSN-DDC）/ 联盟链（自建 FISCO BCOS）/ 蚂蚁链（AntChain）的 RPC、合约登记、铸造与流水查询；链网络密钥 AES 加密存储。

## 快速启动

### 1. 数据库

**推荐：一条命令搞定**

```bash
bash database/deploy.sh            # 重建并全量部署（基础库表 + 后端 migrations）
bash database/deploy.sh --verify   # 只重跑 SQL，不重建库
```

脚本内置正确的执行顺序与幂等容错，环境变量可覆盖：
`DB_HOST`(127.0.0.1) `DB_PORT`(3399) `DB_USER`(root) `DB_PASS`() `DB_NAME`(sinan_nft) `MYSQL_BIN`(mysql)

部署后共有 **80 张表**（字段数 981 是拆分版的自检期望值，与合并版实测 982、
`full_init.sql` 声明 985 属于三种口径，对照表见 `database/README.md`），脚本结尾会打印统计自检。

<details>
<summary>手工逐步执行？—— 不必，顺序和归属看 `database/README.md`</summary>

原来这里逐条抄过一遍 20+ 个 `mysql < xxx.sql` 命令，跟 `deploy.sh` 里的 `BASE_FILES` 数组是同一份知识，
两处各写一次就会有一处过时（2026-10-10 就修过一次过时数字）。现在**唯一权威顺序是
`database/deploy.sh` 的数组**，分类与每个文件做什么见 `database/README.md` 第二节。

> ⚠️ **踩坑警示（都是实际发生过的，仍然有效）**
>
> 1. **必须显式指定数据库名。** `mysql < file.sql` 不带库名时，若 SQL 文件内部没有
>    `USE` 语句，整份文件会因 `No database selected` **静默失败**——退出码为 0、
>    加了 `2>/dev/null` 更是什么都看不到。本项目中 `fusion_upgrade.sql`、
>    `full_feature_upgrade.sql`、`raffle_admin_upgrade.sql` 等 7 个文件内部无 `USE`。
> 2. **`raffle_purchase_upgrade.sql` 必须早于 `raffle_admin_upgrade.sql`。**
>    后者有 `AFTER \`purchased_quantity\`` 依赖，顺序颠倒报
>    `ERROR 1054 Unknown column 'purchased_quantity'`。
> 3. **mysql 客户端默认遇错即停。** 上面第 2 条一旦触发，`raffle_admin_upgrade.sql`
>    中第 149 行之后的全部语句（含 `nft_user_draw_codes` 相关）都不会执行，
>    且不产生任何额外提示。调试时应加 `--force` 观察完整错误清单。
> 4. **执行命令后务必校验** `information_schema`，不要只看是否报错：
>    ```bash
>    mysql -uroot -p sinan_nft -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='sinan_nft';"
>    # 期望：80
>    ```

</details>

空库首部署也可以直接跑合并版：`mysql -uroot -p sinan_nft < database/full_init.sql`（**生产建库就用这个文件**，
80 张表；"与运行库双向零差异"是历史实测结论，2026-10-10 未复核成功、至今是**未验证项**，见 `deploy/RUNBOOK.md` 7.10）。
历史上还有过一份 `full_schema_all.sql` + `merge_schema.py` 的旧合并版，因落后 26 个字段且自带重复 ALTER 已删除，勿再引用。

> ⚠️ `deploy.sh` 不带 `--verify` 时会先执行 `DROP DATABASE IF EXISTS`，随后重放全部 SQL。
> 它默认连 `127.0.0.1:3399`（另一套沙箱实例）。若把 `DB_PORT` 指到有数据的库上运行，
> 该库会被整库删除——执行前务必核对 `DB_HOST/DB_PORT/DB_NAME` 三个变量。

#### 三条安装路径怎么选

| 方案 | 文件 | 适用场景 |
|------|------|---------|
| **合并版（生产唯一用法）** | `database/full_init.sql`（208KB，单文件，80 表） | 新环境首部署、CI/CD 自动化；步骤见 `deploy/RUNBOOK.md` 第 3 节 |
| **拆分版** | `database/deploy.sh` 的 23 个 `BASE_FILES` | 本机从零重建一个干净库（顺序敏感，会 `DROP DATABASE`） |
| **增量迁移** | 后端 `sinan-nft-backend/migrations/`（19 个，按日期编号） | 已有库往前跟；新功能一律走这里，`database/` 不再新增 |

合并版把所有 CREATE / ALTER / DROP 拼成一份，一条 `mysql -uroot -p sinan_nft < database/full_init.sql` 搞定。
拆分版保留了每个升级脚本的独立语义（哪个功能加了哪些字段一目了然），支持从任意版本增量升级。
两者的逐文件用途与归属见 **`database/README.md`**。

> ⚠️ 合并版从拆分版提取 ALTER 时，原脚本的动态 SQL 幂等包装被剥去了。
> 如果目标列已存在（比如后续版本在 CREATE TABLE 里直接加了这个列），裸 ALTER 会报 `Duplicate column`。
> 合并版仅用于**空库**，已有库增量迁移请用拆分版。
> 拆分版有字段演进时，`full_init.sql` 需**手工同步**（曾用的一次性生成脚本产出的版本已落后 26 字段并删除，不再提供）。

> ✅ 表数量三处口径一致：**80 张表**。字段数有三个数字（981 / 982 / 985），因为分别是
> 拆分版自检期望、合并版当年实测、`full_init.sql` 的列声明数 —— 对照表与"两条路径是否逐列等价
> 仍未验证"这件事，写在 `database/README.md` 第一节末尾。

### 2. 后端（sinan-nft-backend）

```bash
cd sinan-nft-backend
composer install
cp .env.example .env          # 修改数据库连接；配置 APP_KEY / jwt.SECRET / jwt.ADMIN_SECRET（均需 ≥32 字节随机串）
php think run --host 0.0.0.0 --port 8080
```

- C 端接口前缀 `/api/**`，管理端接口前缀 `/admin/**`
- 认证：`Authorization: Bearer {token}`；敏感操作需二次校验密码（`verify-password`）
- 验证码：`APP_DEBUG=true` 时返回 `debugCode`，不真实下发短信
- 默认管理员 `admin / admin123`（种子数据）首次部署后必须改密：
  `php think admin:reset-password admin`（自动生成随机密码）或 `php think admin:reset-password admin '你的新密码'`

### 3. 管理后台（sinan-admin）

```bash
cd sinan-admin
npm install
npx vite --port 5174 --host 0.0.0.0
# 生产构建：npx vite build
```

- 开发代理：`/api/admin/**` → `http://127.0.0.1:8080/admin/**`（见 `vite.config.js`）
- 默认账号：`admin / admin123`（超级管理员）

### 4. C 端 H5（sinan-art-source）

```bash
cd sinan-art-source
npm install
npm run dev
```

## 管理后台功能总览（融合版）

| 模块 | 功能要点 |
| --- | --- |
| 数据看板 | GMV/订单/用户/待办、趋势图、热销榜、最新动态 |
| 数据统计 | 五大报表 tab：销售 / 用户 / 藏品 / 盲盒 / 财务对账 |
| 用户管理 | 列表/详情、冻结、强制下线、重置交易密码、拉黑、恢复 |
| 实名审核 | 待审列表、通过/驳回（驳回必填原因）、全量信息查看（独立权限+强制审计） |
| 藏品管理 | 创建/编辑（含链选择）、发售、配额、上下架、销毁、空投、资格购白名单、配额启停 |
| 盲盒管理 | 创建、配置、发售、销毁、空投、审计 |
| 订单/退款 | 订单审计、取消、标记支付、退款（大额自动转审批中心）、退款执行 |
| 寄售/转赠 | 寄售列表与管理、市场参数配置、转赠列表与撤销 |
| 营销中心 | 优先购、签到、邀请、抽奖、合成、活动空投、注册福利 |
| 钱包财务 | 统计、流水、充值、手续费、资金审计（恒等式校验）、异常监控 |
| 链上交互 | 三链网络配置（密钥加密）、合约登记、链上交易流水、手动补铸 |
| 审批中心 | 大额退款等高风险操作审批（发起→复核→执行） |
| 内容管理 | 公告、轮播、社区、文物展馆、协议、站点装修 |
| 风控安全 | 黑名单、风控告警、安全事件 |
| 客服工单 | 列表、指派、回复、状态流转 |
| 系统管理 | 管理员/角色/权限树、操作日志、登录日志、全局参数、短信配置、支付渠道、安全策略、平台清库（四步验证流）、数据审计 |

## 权限矩阵（预置角色）

| 模块 | 超级管理员 | 运营 | 财务 | 风控 | 客服 |
| --- | :-: | :-: | :-: | :-: | :-: |
| 数据看板/统计 | ✓ | ✓ | ✓ | ✓ | ✓ |
| 用户管理 | ✓ | ✓ | - | 部分 | 查看 |
| 实名审核 | ✓ | ✓ | - | ✓ | - |
| 藏品/盲盒 | ✓ | ✓ | - | - | - |
| 订单/退款 | ✓ | ✓ | ✓ | - | 查看 |
| 审批中心 | ✓ | - | 查看 | ✓ | - |
| 钱包财务 | ✓ | - | ✓ | 监控 | - |
| 链上配置 | ✓ | - | - | - | - |
| 营销中心 | ✓ | ✓ | - | - | - |
| 内容管理 | ✓ | ✓ | - | - | - |
| 风控安全 | ✓ | - | - | ✓ | - |
| 工单 | ✓ | - | - | - | ✓ |
| 系统管理 | ✓ | - | - | - | - |

> 权限码共 91 个（20 个模块）。角色权限可在「系统 → 管理员/角色管理」中调整；权限码以 `nft_admin_permissions` 表为准。

## 区块链上链配置

支持三条链（`nft_collectibles.chain_type`）：

| chain_type | 说明 | 接入方式 |
| --- | --- | --- |
| `wenchang` | 文昌链（BSN-DDC） | 国家信息中心 BSN DDC 业务，国内合规首选 |
| `consortium` | 联盟链（自建） | FISCO BCOS 等自建节点，配置 RPC 即可 |
| `antchain` | 蚂蚁链（AntChain） | 蚂蚁链开放联盟链，配置 AccessKey |

配置流程（管理后台 → 链上交互 → 上链配置）：

1. 填写链网络 RPC 地址与密钥（密钥 AES 加密入库，回显仅显示是否已配置）
2. 登记合约地址（已产生链上交易的合约仅可停用不可删除）
3. 测试连通性，启用网络
4. 藏品编辑时选择链类型；发售持仓未上链时可在链上流水页手动补铸（`chain:mint` 权限）

## 部署要点

- 后端：PHP 8.1+，MySQL 8.0 / MariaDB 10.6+；生产环境关闭 `APP_DEBUG`，`jwt.SECRET` / `jwt.ADMIN_SECRET` 各自独立且 ≥32 字节
- 密钥轮换：`APP_KEY`（实名/短信/支付/链网络密钥的 AES 密钥）生产必须显式配置；更换前先执行 `php think rekey:encrypted <旧KEY> <新KEY>` 重加密存量密文，再更新 `.env` 的 `APP_KEY`（生产未配置或仍为默认弱密钥时后端拒绝加解密）
- 前端：管理后台构建产物 `dist/` 部署到静态服务器，反向代理将 `/api/admin/**` 转发到后端 `/admin/**`
- 安全：管理端登录连续失败锁定（阈值见安全策略配置）、大额退款强制审批（阈值 `large_refund_approval_threshold`，默认 1000 元）、平台清库需短信验证码 + 四步确认
- 审计：库存恒等式（发行量=已售+锁定+预留+空投+销毁+库存）、资金恒等式（余额+手续费+已提现=充值+奖励）可在「系统 → 数据审计」随时校验
