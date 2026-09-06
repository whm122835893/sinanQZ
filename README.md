# sinanQZ 数字藏品平台（司南珍藏）

前后端 + 管理后台 + 数据库单仓库。**管理后台为两套后台的融合版**：以新后台（sinan-admin）为主干 UI 风格，移植了原后台独有的功能模块，并新增区块链上链配置（文昌链 / 联盟链 / 蚂蚁链）。

## 目录结构

```
sinanQZ/
├── jichao-art-source/     # C 端 H5（Vue 3 + Vite 5 + Pinia + Vant 4）
├── sinan-admin/           # 管理后台（融合版，Vue 3 + Vite 5 + Pinia + Element Plus + ECharts）
├── sinan-nft-backend/     # 后端（ThinkPHP 8 多应用：api = C 端 / admin = 管理端）
├── sinan-nft-admin/       # 原管理后台（融合后仅留档参考，不再维护）
└── database/              # 数据库脚本
    ├── init.sql               # 基础建库：33 表 / 56 外键 / 16 CHECK（幂等可重复执行）
    ├── admin_init.sql          # 管理端扩展表：管理员/角色/权限/操作日志/审批/链网络/链合约
    ├── fusion_upgrade.sql      # 融合升级迁移：三链字段、审批流、社区、资格购白名单等（幂等）
    ├── fusion_final_upgrade.sql# 融合终版迁移（幂等）
    └── seed-dev.sql            # 开发联调种子数据（生产环境勿执行）
```

## 系统架构

```
┌─────────────┐   /api/**     ┌──────────────────────────────┐
│  C 端 H5     │ ───────────▶ │  ThinkPHP 8 多应用后端         │
│ (jichao)    │   JWT-user   │  ├─ app/api    （C 端业务）     │
└─────────────┘               │  ├─ app/admin （管理端业务）    │      ┌──────────┐
┌─────────────┐   /api/admin  │  ├─ 中间件：AdminAuth(JWT)     │ ──▶  │ MySQL 8  │
│  管理后台    │ ───────────▶ │  │         AdminPermission(RBAC)│      │ 58 张表   │
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

```bash
mysql -uroot -p < database/init.sql              # 基础库 + C 端种子
mysql -uroot -p < database/admin_init.sql        # 管理端表 + 角色/权限种子
mysql -uroot -p < database/fusion_upgrade.sql    # 融合升级（三链/审批/社区/资格购）
mysql -uroot -p < database/fusion_final_upgrade.sql
mysql -uroot -p < database/seed-dev.sql          # 可选：联调种子数据（生产勿执行）
```

以上脚本均幂等，可重复执行。

### 2. 后端（sinan-nft-backend）

```bash
cd sinan-nft-backend
composer install
cp .example.env .env          # 修改数据库连接 / JWT 密钥（ADMIN_SECRET 需 ≥32 字节）
php think run --host 0.0.0.0 --port 8080
```

- C 端接口前缀 `/api/**`，管理端接口前缀 `/admin/**`
- 认证：`Authorization: Bearer {token}`；敏感操作需二次校验密码（`verify-password`）
- 验证码：`APP_DEBUG=true` 时返回 `debugCode`，不真实下发短信

### 3. 管理后台（sinan-admin）

```bash
cd sinan-admin
npm install
npx vite --port 5174 --host 0.0.0.0
# 生产构建：npx vite build
```

- 开发代理：`/api/admin/**` → `http://127.0.0.1:8080/admin/**`（见 `vite.config.js`）
- 默认账号：`admin / admin123`（超级管理员）

### 4. C 端 H5（jichao-art-source）

```bash
cd jichao-art-source
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

- 后端：PHP 8.1+，MySQL 8.0 / MariaDB 10.6+；生产环境关闭 `APP_DEBUG`，`ADMIN_SECRET` / `USER_SECRET` 各自独立且 ≥32 字节
- 前端：管理后台构建产物 `dist/` 部署到静态服务器，反向代理将 `/api/admin/**` 转发到后端 `/admin/**`
- 安全：管理端登录连续失败锁定（阈值见安全策略配置）、大额退款强制审批（阈值 `large_refund_approval_threshold`，默认 1000 元）、平台清库需短信验证码 + 四步确认
- 审计：库存恒等式（发行量=已售+锁定+预留+空投+销毁+库存）、资金恒等式（余额+手续费+已提现=充值+奖励）可在「系统 → 数据审计」随时校验
