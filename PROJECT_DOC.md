# 司南珍藏（sinanQZ）数字藏品平台 · 项目文档

> 版本：v2.5 · 更新日期：2026-09-10
> 仓库：https://github.com/whm122835893/sinanQZ.git
> 定位：国风数字藏品（NFT）全链路平台，覆盖 C 端 H5 交易、B 端管理后台、ThinkPHP 后端与区块链上链。

---

## 目录

1. [项目概述](#1-项目概述)
2. [技术栈](#2-技术栈)
3. [系统架构](#3-系统架构)
4. [目录结构](#4-目录结构)
5. [C 端 H5 功能详解](#5-c-端-h5-功能详解)
6. [管理后台功能详解](#6-管理后台功能详解)
7. [后端 API 接口](#7-后端-api-接口)
8. [数据库设计](#8-数据库设计)
9. [安全与权限机制](#9-安全与权限机制)
10. [区块链上链](#10-区块链上链)
11. [部署与启动](#11-部署与启动)
12. [开发约定](#12-开发约定)

---

## 1. 项目概述

司南珍藏是一套以「国潮朱红」为品牌基调的数字藏品交易平台，单仓库包含三大子系统：

| 子系统 | 目录 | 定位 |
|--------|------|------|
| C 端 H5 | `sinan-art-source/` | 面向收藏者的移动端交易平台（Vue 3 + Vant 4） |
| 管理后台 | `sinan-admin/` | 运营管理后台，覆盖藏品全生命周期（Vue 3 + Element Plus） |
| 后端服务 | `sinan-nft-backend/` | ThinkPHP 8 多应用后端（api = C 端 / admin = 管理端） |

核心业务闭环：**藏品发售 → 购买/盲盒/合成 → 持仓 → 寄售/转赠/置换 → 链上铸造 → 审计**。

---

## 2. 技术栈

### 2.1 C 端 H5（sinan-art-source）

| 类别 | 选型 | 版本 |
|------|------|------|
| 框架 | Vue 3（Composition API + `<script setup>`） | ^3.4.27 |
| 构建 | Vite 5 | ^5.3.1 |
| 路由 | vue-router（Hash 模式） | ^4.3.2 |
| 状态 | Pinia | ^2.1.7 |
| UI 组件 | Vant 4（移动端） | ^4.9.0 |
| 请求 | axios | ^1.7.2 |
| 样式 | SCSS + postcss-px-to-viewport（移动端适配） | sass ^1.77.4 |
| 工具 | html2canvas（分享海报） | ^1.4.1 |

### 2.2 管理后台（sinan-admin）

| 类别 | 选型 | 版本 |
|------|------|------|
| 框架 | Vue 3（Composition API + `<script setup>`） | ^3.4.27 |
| 构建 | Vite 5 | ^5.3.1 |
| 路由 | vue-router（Hash 模式 + 动态权限守卫） | ^4.3.2 |
| 状态 | Pinia（admin / app / site） | ^2.1.7 |
| UI 组件 | Element Plus（主题令牌覆盖为司南红） | ^2.14.5 |
| 图表 | ECharts 6（`EChart.vue` 通用容器） | ^6.1.0 |
| 日期 | dayjs | ^1.11.11 |
| 金额 | decimal.js（金额字符串传输） | ^10.4.3 |
| 请求 | axios | ^1.7.2 |
| 样式 | SCSS（变量 + CSS 变量双层令牌） | ^1.77.4 |

### 2.3 后端（sinan-nft-backend）

| 类别 | 选型 | 版本 |
|------|------|------|
| 框架 | ThinkPHP 8（多应用模式） | ^8.0 |
| PHP | ≥ 8.0 | |
| ORM | ThinkORM | ^3.0 / ^4.0 |
| 认证 | firebase/php-jwt（双 JWT 体系） | ^7.1 |
| 多应用 | topthink/think-multi-app | ^1.1 |
| 文件系统 | topthink/think-filesystem | ^2.0 / ^3.0 |
| Excel 导出 | PhpSpreadsheet | ^5.9 |
| 数据库 | MySQL 8.0 / MariaDB 10.6+ | utf8mb4 |

---

## 3. 系统架构

```
┌─────────────┐   /api/**     ┌──────────────────────────────┐
│  C 端 H5     │ ───────────▶ │  ThinkPHP 8 多应用后端         │
│ (sinan)     │   JWT-user   │  ├─ app/api    （C 端业务）     │
└─────────────┘               │  ├─ app/admin （管理端业务）    │      ┌──────────┐
┌─────────────┐   /api/admin  │  ├─ 中间件：AdminAuth(JWT)     │ ──▶  │ MySQL 8  │
│  管理后台    │ ───────────▶ │  │         AdminPermission(RBAC)│      │ 58+ 张表  │
│ (sinan-admin)│   JWT-admin  │  └─ Service：ChainService 等     │      └──────────┘
└─────────────┘               └──────────────────────────────┘
                                      │
                                      ▼
                              ┌──────────────────┐
                              │  区块链三链适配层  │
                              │  文昌链/联盟链/蚂蚁链│
                              └──────────────────┘
```

### 3.1 双 JWT 体系

- **C 端 JWT**：密钥 `USER_SECRET`，签发用户 token，用于 `/api/**` 接口。
- **管理端 JWT**：密钥 `ADMIN_SECRET`（≥32 字节），签发管理员 token，用于 `/admin/**` 接口；额外校验账号实时状态（锁定/停用即时生效）。
- 两套密钥完全隔离，互不通用。

### 3.2 中间件链

- **C 端**：`Cors`（跨域）→ `JwtAuth`（需登录）/ `OptionalJwtAuth`（可选登录）。
- **管理端**：`AdminAuth`（JWT 认证 + 实时状态）→ `AdminPermission`（RBAC 权限码精确校验）。

---

## 4. 目录结构

```
sinanQZ/
├── sinan-art-source/          # C 端 H5（Vue 3 + Vite 5 + Pinia + Vant 4）
│   ├── public/images/         # 静态图片资源（藏品封面/图标/主题皮肤）
│   ├── src/
│   │   ├── components/        # 通用组件（AppButton/AppCard/AppNavBar/AppTabBar 等）
│   │   ├── router/            # 路由表（Hash 模式，5 Tab + 子页懒加载）
│   │   ├── stores/            # Pinia 状态（collection/user/site/order/activity/notice/iconTheme）
│   │   ├── styles/            # 全局样式（variables/mixins/global）
│   │   ├── utils/             # 工具（request/loginGate/useCountdown）
│   │   └── views/             # 页面（Home/Market/Mall/Notice/User + 业务/鉴权子页）
│   ├── index.html
│   ├── vite.config.js
│   └── package.json
│
├── sinan-admin/               # 管理后台（融合版，Vue 3 + Vite 5 + Pinia + Element Plus + ECharts）
│   ├── src/
│   │   ├── api/index.js       # 全部 API（真实联调版，调用 ThinkPHP admin 应用）
│   │   ├── components/        # 通用组件（AdminTablePage/PasswordVerify/StatCard/EChart/StatusTag 等）
│   │   ├── directives/        # v-permission 按钮权限指令
│   │   ├── layouts/           # AdminLayout（侧栏 + Header + 多标签页）
│   │   ├── router/            # 路由表 + 菜单配置（权限码过滤）
│   │   ├── stores/            # admin/app/site
│   │   ├── styles/            # element.scss/global.scss/mixins.scss/variables.scss
│   │   ├── utils/             # request/maps/format/csv
│   │   └── views/             # 35+ 业务页面（按模块分目录）
│   ├── mock-server.js         # 开发联调 Mock 服务
│   ├── vite.config.js
│   └── package.json
│
├── sinan-nft-backend/         # 后端（ThinkPHP 8 多应用）
│   ├── app/
│   │   ├── api/               # C 端业务（controller/service/middleware）
│   │   ├── admin/             # 管理端业务（controller/service/middleware/route）
│   │   ├── command/           # 命令行（ApiDoc 文档生成/ScheduleDispatch 调度）
│   │   ├── controller/        # C 端控制器（Auth/Collections/Orders/Resale/Transfers 等）
│   │   ├── middleware/        # Cors/JwtAuth/OptionalJwtAuth
│   │   ├── service/           # 业务服务（InventoryService/JwtService/RaffleService 等）
│   │   └── traits/            # JsonResponse 等
│   ├── config/                # 框架配置（app/database/cache/route 等）
│   ├── public/                # 入口（index.php/api-docs.html）
│   ├── route/                 # api.php（C 端路由）
│   └── composer.json
│
└── database/                  # 数据库脚本
    ├── init.sql               # 基础建库：33 表 / 56 外键 / 16 CHECK（幂等可重复执行）
    ├── admin_init.sql         # 管理端扩展表：管理员/角色/权限/操作日志/审批/链网络/链合约
    ├── fusion_upgrade.sql     # 融合升级迁移：三链字段、审批流、社区、资格购白名单等
    ├── fusion_final_upgrade.sql
    ├── full_feature_upgrade.sql  # 抽签购/求购/置换/分解等新功能表
    ├── marketing_activity_upgrade.sql
    ├── activity_reward_upgrade.sql
    ├── announcement_publish_upgrade.sql
    ├── artifact_status_upgrade.sql
    └── seed-dev.sql           # 开发联调种子数据（生产环境勿执行）
```

---

## 5. C 端 H5 功能详解

C 端采用 **5 个底部主 Tab + 业务子页** 的结构，Hash 路由模式适配 H5。

### 5.1 主 Tab 页面

| Tab | 路由 | 页面 | 核心功能 |
|-----|------|------|----------|
| 首页 | `/` | Home.vue | 轮播 Banner、首发日历、热门藏品、功能入口（日历/活动/抽奖）、发售倒计时 |
| 市场 | `/market` | Market.vue | 三个子 Tab：活动市场 / 自由市场 / 我的关注；寄售挂单池浏览 |
| 商城 | `/mall` | Mall.vue | 文物展馆列表，点击进入文物详情 |
| 公告 | `/notice` | Notice.vue | 公告列表，点击进入公告详情 |
| 我的 | `/user` | User.vue | 个人中心入口、钱包、藏品、订单等 |

### 5.2 用户中心子页

| 路由 | 页面 | 功能 |
|------|------|------|
| `/user/profile` | Profile.vue | 个人信息编辑（头像/昵称） |
| `/user/security` | Security.vue | 账户安全（修改登录密码/交易密码） |
| `/user/realname` | Realname.vue | 实名认证（姓名+身份证，AES 加密存储） |
| `/user/collections` | Collections.vue | 我的藏品（持仓列表，含链上状态） |
| `/user/wallet` | Wallet.vue | 我的钱包（余额/冻结/流水/充值） |
| `/user/orders` | Orders.vue | 我的订单（全部/待支付/已完成） |
| `/user/purchase` | Purchase.vue | 转赠记录（转出/转入） |
| `/user/invite` | Invite.vue | 我的好友（邀请码/邀请记录/奖励） |
| `/user/community` | Community.vue | 加入社区（官方社群入口） |
| `/user/service` | Service.vue | 我的客服（工单提交与查询） |

### 5.3 鉴权页面

| 路由 | 页面 | 功能 |
|------|------|------|
| `/auth/login` | Login.vue | 手机号 + 短信验证码 / 密码登录 |
| `/auth/register` | Register.vue | 注册（手机号+验证码+密码，支持邀请码绑定） |
| `/auth/forgot` | Forgot.vue | 找回密码（短信验证码重置） |
| `/auth/change-pwd` | ChangePwd.vue | 修改登录密码（旧密码校验） |
| `/auth/op-pwd` | OpPwd.vue | 设置/修改交易密码（寄售/转赠前置） |
| `/auth/cancel` | Cancel.vue | 注销账号 |

### 5.4 业务页面

| 路由 | 页面 | 功能 |
|------|------|------|
| `/collection/:id` | CollectionDetail.vue | 藏品详情（图片/介绍/库存/发售状态/购买/寄售） |
| `/resale/:id` | Resale.vue | 寄售挂单（上架我的藏品，设置价格） |
| `/resale-order/:id/:no` | ResaleOrder.vue | 挂单详情（购买/取消） |
| `/pay/:mode/:id/:no?` | Pay.vue | 支付页（发售支付 / 挂单支付合并路由，mode 区分） |
| `/calendar` | Calendar.vue | 司南·首发日历（发售时间表） |
| `/activity` | Activity.vue | 活动中心（签到/抽奖/合成/邀请入口） |
| `/activity/synthesis/:id` | Synthesis.vue | 合成活动（材料消耗→产物） |
| `/lottery` | Lottery.vue | 司南·抽奖（消耗抽奖次数抽奖品） |
| `/sign` | Sign.vue | 每日签到（连签奖励） |
| `/mall/:id` | ExhibitDetail.vue | 文物详情 |
| `/notice/:id` | NoticeDetail.vue | 公告详情 |

### 5.5 C 端核心业务流程

#### 5.5.1 藏品购买
1. 用户浏览藏品详情 → 选择购买数量
2. 前置校验：登录、实名认证、资格购判定
3. 创建订单 → 支付（余额/第三方）→ 扣减库存 → 生成用户持仓
4. 订单状态：pending → paid → completed

#### 5.5.2 寄售交易
1. 卖家：我的藏品 → 寄售挂单（设置价格，校验交易密码）
2. 买家：市场浏览挂单 → 购买 → 卖家收到货款（扣手续费）
3. 同一资产在售挂单唯一（数据库条件唯一约束）

#### 5.5.3 转赠
1. 转出方：选择藏品 → 输入对方手机号 → 创建转赠（校验交易密码）
2. 接收方：转赠记录 → 确认接收 / 拒绝
3. 同一资产仅一笔待确认转赠（数据库条件唯一约束）

#### 5.5.4 合成
1. 查看合成活动（材料 M:N → 产物）
2. 选择材料 → 提交 → 消耗材料持仓 → 生成产物持仓

#### 5.5.5 盲盒
1. 购买盲盒 → 开启 → 按概率随机获得子藏品
2. 子藏品概率之和 ≤ 100%，不足降级空奖

---

## 6. 管理后台功能详解

管理后台采用 **左侧菜单 + 顶部 Header + 多标签页** 布局，菜单按 RBAC 权限码过滤渲染。共 10 大模块、35+ 页面。

### 6.1 总览

| 页面 | 路由 | 功能 |
|------|------|------|
| 数据看板 | `/dashboard` | KPI（GMV/订单/用户/待办）、环比趋势图、热销榜、最新动态 |
| 数据统计 | `/statistics` | 五大报表：销售 / 用户 / 藏品 / 盲盒 / 财务对账（支持 Excel 导出） |

### 6.2 用户

| 页面 | 路由 | 功能 |
|------|------|------|
| 用户管理 | `/user` | 用户列表/搜索/详情、冻结/解冻、重置交易密码、强制下线、拉黑、恢复 |
| 实名审核 | `/user/realname` | 待审列表（脱敏）、通过/驳回（驳回必填原因）、完整查看（密码验证+审计） |

### 6.3 藏品管理

| 页面 | 路由 | 功能 |
|------|------|------|
| 藏品列表 | `/collectible` | 列表、上下架、强制售罄、寄售/转赠开关、价格管控、新建 |
| 藏品详情 | `/collectible/detail/:id` | 库存守恒审计、配额、独立空投、销毁、发售配置、持有人 |
| 编辑藏品 | `/collectible/edit/:id?` | 新建/编辑藏品全字段（含链选择） |
| 盲盒管理 | `/blindbox` | 盲盒列表、库存恒等式 |
| 盲盒配置 | `/blindbox/detail/:id` | 子藏品概率/计划数量、发售、空投、销毁、开关 |
| 优先购管理 | `/marketing/priority` | 白名单（手机号+限量+有效期）、过期清理 |
| 资格购管理 | `/marketing/qualification` | 条件配置（任一/全部）、额外白名单、独立开关 |
| 抽签购管理 | `/marketing/raffle` | 抽签活动创建/编辑/开始/开奖/取消 |

### 6.4 活动管理

| 页面 | 路由 | 功能 |
|------|------|------|
| 邀请活动 | `/marketing/invite` | 双方奖励配置、邀请记录统计 |
| 注册活动 | `/marketing/register` | 实名前 N 名档位奖励 |
| 签到活动 | `/marketing/checkin` | 签到规则表（第 N 天 → 奖励类型）、启停、连签榜 |
| 抽奖活动 | `/marketing/luckydraw` | 奖项池（概率 ≤100%）、启停、抽奖记录 |
| 合成活动 | `/marketing/synthesis` | 材料 M:N → 产物、限次配置、启停 |
| 分解活动 | `/marketing/decompose` | 分解规则配置、记录查询 |
| 奖励名单 | `/marketing/reward-records` | 全平台活动奖励记录、导出、统一发放 |

### 6.5 交易

| 页面 | 路由 | 功能 |
|------|------|------|
| 订单管理 | `/order` | 五来源筛选（公售/优先购/资格购/市场/盲盒）、取消、标记支付 |
| 退款管理 | `/order/refunds` | 退款审批（联动订单状态，大额自动转审批中心） |
| 寄售市场 | `/resale` | 挂单/求购/成交三 Tab，冻结/解冻/系统下架、市场参数配置 |
| 转赠管理 | `/transfer` | 审批 + 撤销已完成转赠（二次流转校验） |
| 求购挂单 | `/buy-request` | 求购列表、关闭、删除 |
| 置换管理 | `/swap` | 置换挂单/记录、关闭、删除 |

### 6.6 资产

| 页面 | 路由 | 功能 |
|------|------|------|
| 钱包流水 | `/wallet` | 收支统计、流水分页筛选、手续费统计、资金审计（恒等式校验）、异常监控 |

### 6.7 风控

| 页面 | 路由 | 功能 |
|------|------|------|
| 风控告警 | `/risk` | 告警列表（类型/等级筛选）、处理（填结论） |
| 客服工单 | `/tickets` | 工单列表、详情、指派、回复、状态流转 |

### 6.8 内容

| 页面 | 路由 | 功能 |
|------|------|------|
| 公告管理 | `/content/announcements` | 公告 CRUD、置顶、启停 |
| 轮播管理 | `/content/banners` | 首页轮播 CRUD、排序、启停 |
| 社区管理 | `/content/community` | 官方社群 CRUD |
| 文物展馆 | `/content/artifacts` | 文物 CRUD、上下架 |
| 站点装修 | `/content/decoration` | 主题色/按钮色/圆角/SEO 配置（C 端实时生效） |

### 6.9 区块链

| 页面 | 路由 | 功能 |
|------|------|------|
| 链上交互 | `/chain` | 三链网络配置（密钥 AES 加密）、合约登记、链上交易流水、手动补铸 |

### 6.10 系统

| 页面 | 路由 | 功能 |
|------|------|------|
| 管理员 | `/system/admins` | 管理员 CRUD、5 角色权限、重置密码、解锁、2FA/IP 白名单 |
| 操作日志 | `/system/logs` | 登录日志 + 操作日志审计 |
| 审批中心 | `/system/approvals` | 大额退款等高风险操作审批（发起→复核→执行） |
| 数据审计 | `/system/audits` | 库存恒等式/资金恒等式实时校验 |
| 数据快照 | `/system/snapshot` | 用户持仓/交易快照，手动触发生成、幂等重跑 |
| 全局参数 | `/system/config` | 系统参数 KV 配置 |
| 短信配置 | `/system/sms` | 短信渠道配置、测试发送 |
| 支付渠道 | `/system/payment` | 第三方支付配置 |
| 安全策略 | `/system/security` | 登录失败锁定阈值、大额退款审批阈值等 |
| 平台清库 | `/system/cleanup` | 四重确认（文本+密码+短信+最终确认），清除用户业务数据 |
| 回收站 | `/system/trash` | 软删除数据恢复/彻底删除 |

### 6.11 个人中心（mine）

| 功能 | 说明 |
|------|------|
| 账号信息 | 头像/昵称/角色/权限码数量 |
| 修改登录密码 | 旧密码 + 新密码（≥8 位，含字母数字）+ 确认密码 |
| 退出登录 | 清除会话跳转登录页 |
| 2FA / IP 白名单 | 规划中 |

### 6.12 预置角色与权限矩阵

| 模块 | 超级管理员 | 运营 | 财务 | 风控 | 客服 |
|------|:-:|:-:|:-:|:-:|:-:|
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

> 权限码共 91 个（20 个模块），以 `nft_admin_permissions` 表为准。

---

## 7. 后端 API 接口

后端采用 ThinkPHP 8 多应用模式：C 端接口前缀 `/api/**`，管理端接口前缀 `/admin/**`。

### 7.1 C 端接口（/api/**）

#### 公开接口（无需登录）

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/auth/send-code` | 发送短信验证码 |
| POST | `/api/auth/register` | 用户注册 |
| POST | `/api/auth/login` | 用户登录 |
| POST | `/api/auth/reset-password` | 重置密码 |
| POST | `/api/auth/logout` | 登出 |
| GET | `/api/collections/categories` | 藏品分类 |
| GET | `/api/collections/featured` | 精选藏品 |
| GET | `/api/collections/:id` | 藏品详情（可选登录） |
| GET | `/api/market/collections` | 市场藏品 |
| GET | `/api/blind-boxes` | 盲盒列表 |
| GET | `/api/synthesis/activities` | 合成活动列表 |
| GET | `/api/synthesis/activities/:id` | 合成活动详情（可选登录） |
| GET | `/api/lucky-draw/activity` | 抽奖活动 |
| GET | `/api/artifacts` | 文物列表 |
| GET | `/api/artifacts/:id` | 文物详情 |
| GET | `/api/announcements` | 公告列表 |
| GET | `/api/announcements/:id` | 公告详情 |
| GET | `/api/banners` | 轮播图 |
| GET | `/api/community/groups` | 社群列表 |
| GET | `/api/config` | 站点配置 |
| GET | `/api/resale/listings` | 寄售挂单池 |
| GET | `/api/resale/history` | 成交历史 |
| GET | `/api/buy-requests` | 求购挂单列表 |
| GET | `/api/swap-offers` | 置换挂单列表 |

#### 需 JWT 认证接口

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/api/user/profile` | 个人信息 |
| PUT | `/api/user/profile` | 更新个人信息 |
| POST | `/api/user/realname` | 实名认证 |
| POST | `/api/user/password/trade` | 设置交易密码 |
| POST | `/api/user/verify-trade-password` | 校验交易密码 |
| GET | `/api/user/collections` | 我的藏品 |
| GET | `/api/user/favorites` | 我的关注 |
| POST | `/api/collections/:id/favorite` | 关注/取消关注 |
| POST | `/api/orders` | 创建订单 |
| POST | `/api/orders/:orderNo/pay` | 支付订单 |
| POST | `/api/orders/callback` | 支付回调 |
| POST | `/api/orders/:orderNo/cancel` | 取消订单 |
| GET | `/api/orders` | 我的订单 |
| POST | `/api/resale/listings` | 创建寄售挂单 |
| POST | `/api/resale/listings/:listingId/cancel` | 取消挂单 |
| GET | `/api/resale/listings/mine` | 我的挂单 |
| POST | `/api/buy-requests` | 创建求购挂单 |
| POST | `/api/buy-requests/:id/accept` | 接受求购 |
| POST | `/api/swap-offers` | 创建置换挂单 |
| POST | `/api/swap-offers/:id/accept` | 接受置换 |
| POST | `/api/transfers` | 创建转赠 |
| POST | `/api/transfers/:transferId/handle` | 处理转赠（接收/拒绝） |
| GET | `/api/transfers/mine` | 我的转赠 |
| POST | `/api/blind-boxes/open` | 开启盲盒 |
| POST | `/api/synthesis/submit` | 提交合成 |
| GET | `/api/synthesis/records` | 合成记录 |
| POST | `/api/check-in` | 签到 |
| GET | `/api/check-in/records` | 签到记录 |
| GET | `/api/check-in/calendar` | 签到日历 |
| POST | `/api/lucky-draw/draw` | 抽奖 |
| GET | `/api/lucky-draw/records` | 抽奖记录 |
| GET | `/api/wallet` | 钱包信息 |
| GET | `/api/wallet/transactions` | 钱包流水 |
| POST | `/api/wallet/recharge` | 充值 |
| GET | `/api/invite/info` | 邀请信息 |
| GET | `/api/invite/records` | 邀请记录 |

### 7.2 管理端接口（/admin/**）

管理端接口按模块分组，均需 `AdminAuth` + `AdminPermission` 中间件。响应约定 `code=200` 成功（独立于 C 端 `code=0`）。

| 模块 | 路由前缀 | 核心接口 |
|------|----------|----------|
| 认证 | `/admin/auth` | login / refresh / profile / logout / change-password / verify-password |
| 仪表盘 | `/admin/dashboard` | overview / trend / rank / latest |
| 用户 | `/admin/users` | list / detail / assets / freeze / reset-transaction-password / force-logout / blacklist / recover |
| 实名 | `/admin/realname` | users / detail / audit / stats |
| 藏品 | `/admin/collectibles` | list / detail / create / update / release / quota / manage / destroy / delete / airdrop / swap / market-config / qualification |
| 盲盒 | `/admin/blind-boxes` | list / detail / create / update / config / release / manage / destroy / airdrop |
| 订单 | `/admin/orders` | list / detail / cancel / mark-paid / refund |
| 退款 | `/admin/refunds` | list / detail / approve / execute |
| 市场 | `/admin/market` | listings / manage / config / save-config |
| 转赠 | `/admin/transfers` | list / approve / reject / revoke |
| 营销 | `/admin/marketing` | priority / checkin / invite / lucky / synthesis / airdrop / register / reward-records |
| 钱包 | `/admin/wallet` | stats / transactions / recharge / fee / audit / abnormal |
| 内容 | `/admin/cms` | banners / announcements / agreements / artifacts / community / decoration |
| 系统 | `/admin/system` | configs / payment-channels / sms-config / security-config |
| 权限 | `/admin/permission` | admins / roles / tree / operation-logs / login-logs |
| 风控 | `/admin/security` | blacklist / risk-alerts / events |
| 工单 | `/admin/tickets` | list / detail / assign / reply / status |
| 报表 | `/admin/reports` | sales / users / collectibles / blindbox / finance / export/* |
| 快照 | `/admin/snapshots` | generate / holdings / trades / dates |
| 区块链 | `/admin/chain` | networks / contracts / transactions / mint |
| 审批 | `/admin/approvals` | list / stats / detail / handle |
| 平台 | `/admin/platform` | cleanup-logs / cleanup-preview / cleanup-send-code / cleanup-execute |
| 抽签 | `/admin/raffle` | list / detail / save / start / draw / cancel / delete |
| 求购 | `/admin/buy-request` | list / close / delete |
| 置换 | `/admin/swap` | list / records / close / delete |
| 分解 | `/admin/decompose` | rules / records |
| 回收站 | `/admin/trash` | collectibles / orders / users / banners / announcements / recover / purge |

---

## 8. 数据库设计

数据库名 `sinan_nft`，统一表前缀 `nft_`，引擎 InnoDB，字符集 utf8mb4。

### 8.1 基础库表（init.sql，33 张）

#### 用户域

| 表名 | 说明 |
|------|------|
| `nft_users` | 用户主表（手机号/实名信息加密/交易密码哈希/邀请码） |
| `nft_wallets` | 钱包（1:1 用户，balance/available/frozen） |
| `nft_wallet_transactions` | 钱包流水（收支类型，balance_after 快照） |
| `nft_verification_codes` | 验证码（code 哈希存储） |

#### 藏品域

| 表名 | 说明 |
|------|------|
| `nft_categories` | 分类 |
| `nft_collectibles` | 藏品主表（edition/sold/reserved/airdropped/destroyed/circulate、寄售/转赠开关、价格管控） |
| `nft_blind_boxes` | 盲盒扩展表 |
| `nft_blind_box_items` | 盲盒子藏品奖池（probability/quantity_limit） |
| `nft_user_collectibles` | 用户藏品持仓（每份一行，serial 序列号） |
| `nft_user_favorites` | 用户关注 |

#### 交易域

| 表名 | 说明 |
|------|------|
| `nft_orders` | 订单（source: release/priority/eligibility/market/blindbox） |
| `nft_payments` | 支付记录 |
| `nft_resale_listings` | 寄售挂单（条件唯一：同一资产在售挂单唯一） |
| `nft_transfers` | 转赠记录（条件唯一：同一资产仅一笔待确认转赠） |

#### 营销域

| 表名 | 说明 |
|------|------|
| `nft_synthesis_activities` / `materials` / `records` / `record_items` | 合成四表 |
| `nft_lucky_draw_prizes` / `records` | 抽奖奖池（含 probability）+ 流水 |
| `nft_check_in_records` | 签到记录 |
| `nft_invite_activities` / `records` | 邀请活动 + 记录 |
| `nft_airdrop_activities` / `snapshots` / `records` / `eligibilities` | 空投四表 |

#### 内容域

| 表名 | 说明 |
|------|------|
| `nft_artifacts` | 文物展馆 |
| `nft_announcements` | 公告 |
| `nft_banners` | 轮播图 |
| `nft_community_groups` | 官方社群 |
| `nft_system_configs` | 系统参数 KV |
| `nft_site_settings` | 站点装修配置 |

### 8.2 管理端扩展表（admin_init.sql）

| 表名 | 说明 |
|------|------|
| `nft_admin_users` | 管理员（role/2FA/IP 白名单/登录失败计数/锁定） |
| `nft_admin_roles` | 角色（5 预置角色） |
| `nft_admin_permissions` | 权限码（91 个，20 模块） |
| `nft_admin_role_permissions` | 角色-权限关联 |
| `nft_admin_operation_logs` | 操作日志 |
| `nft_admin_login_logs` | 登录日志 |
| `nft_qualification_configs` / `whitelists` | 资格购配置 + 白名单 |
| `nft_priority_activities` / `whitelists` | 优先购活动 + 白名单 |
| `nft_inventory_quotas` | 库存配额预留 |
| `nft_destroy_records` | 销毁记录台账 |
| `nft_refunds` | 退款记录（联动审批） |
| `nft_airdrop_tasks` | 空投任务 |
| `nft_blacklist` | 黑名单 |
| `nft_risk_alerts` | 风控告警 |
| `nft_security_events` | 安全事件 |
| `nft_support_tickets` / `ticket_replies` | 客服工单两表 |
| `nft_platform_cleanup_logs` | 平台清库日志 |
| `nft_sms_configs` | 短信配置 |
| `nft_payment_channels` | 支付渠道 |

### 8.3 融合升级表（fusion_upgrade.sql）

| 表名 | 说明 |
|------|------|
| `nft_chain_networks` | 区块链网络配置（密钥 AES 加密） |
| `nft_chain_contracts` | 链上合约登记 |
| `nft_approval_requests` | 审批工作流 |

### 8.4 全功能升级表（full_feature_upgrade.sql）

| 表名 | 说明 |
|------|------|
| `nft_raffle_activities` / `registrations` | 抽签购活动 + 报名 |
| `nft_buy_requests` | 求购挂单 |
| `nft_swap_offers` / `swap_records` | 置换挂单 + 记录 |
| `nft_decompose_rules` / `items` / `records` | 分解规则 + 记录 |

### 8.5 关键约束

- **16 个 CHECK 约束**：金额/数量非负、概率值域 0~1、防超卖（sold+locked ≤ edition）
- **56 条外键**：RESTRICT（流水/审计/主数据）、CASCADE（配置子表）、SET NULL（可空溯源）
- **2 个条件唯一生成列**：寄售同一资产在售唯一、转赠同一资产待确认唯一
- **软删除**：所有表含 `deleted_at`，删除均为软删除

### 8.6 核心恒等式

```
藏品库存恒等式：
  库存池 = 发行总量 − 已配置配额 − 已售出发售 − 已独立空投 − 已销毁
  发行总量 = 库存池 + 配额 + 已售 + 空投 + 销毁

盲盒库存恒等式：
  盲盒库存池 = 盲盒发行总量 − 盲盒已售出发售 − 盲盒已独立空投 − 盲盒已销毁

资金恒等式：
  余额 + 手续费 + 已提现 = 充值 + 奖励
```

---

## 9. 安全与权限机制

### 9.1 认证安全

- **双 JWT 隔离**：C 端与管理端使用独立密钥，互不通用。
- **密码加密**：用户登录密码、交易密码、管理员密码均使用 `password_hash`（BCRYPT）哈希存储，禁止明文。
- **实名信息加密**：真实姓名、身份证号使用 AES-256/SM4 加密存储。
- **验证码哈希**：短信验证码哈希存储，不存明文。
- **管理端实时状态**：管理端 JWT 校验时额外查询账号状态，锁定/停用即时生效。
- **登录失败锁定**：连续失败达到阈值自动锁定账号。

### 9.2 RBAC 权限

- 91 个权限码 × 5 个预置角色（超级管理员/运营/财务/风控/客服）。
- 路由层 `AdminPermission` 中间件精确校验权限码。
- 按钮级 `v-permission` 指令控制操作可见性。
- 超级管理员（is_super）绕过权限校验。

### 9.3 审计日志

- 所有敏感操作（实名全量查看、退款、清库、链上配置变更、空投、销毁等）强制写操作日志。
- 登录日志记录 IP/设备/结果。

### 9.4 审批工作流

- 大额退款等高风险操作走 `nft_approval_requests` 审批流：发起 → 复核 → 执行。
- 阈值可配置（`large_refund_approval_threshold`，默认 1000 元）。

### 9.5 平台清库四重确认

1. 红色警示，手动输入「确认清除」
2. 管理员密码验证
3. 短信验证码（超管手机）
4. 最终确认执行

执行前自动备份，操作记录审计日志。清除全部用户业务数据，保留管理员/藏品元数据/CMS/系统配置。

---

## 10. 区块链上链

### 10.1 三链适配

`ChainService` 统一封装三条链的 RPC、合约登记、铸造与流水查询：

| chain_type | 说明 | 接入方式 |
|------------|------|----------|
| `wenchang` | 文昌链（BSN-DDC） | 国家信息中心 BSN DDC 业务，国内合规首选 |
| `consortium` | 联盟链（自建） | FISCO BCOS 等自建节点，配置 RPC 即可 |
| `antchain` | 蚂蚁链（AntChain） | 蚂蚁链开放联盟链，配置 AccessKey |

### 10.2 配置流程

1. 管理后台 → 链上交互 → 上链配置
2. 填写链网络 RPC 地址与密钥（密钥 AES 加密入库，回显仅显示是否已配置）
3. 登记合约地址（已产生链上交易的合约仅可停用不可删除）
4. 测试连通性，启用网络
5. 藏品编辑时选择链类型；发售持仓未上链时可在链上流水页手动补铸

---

## 11. 部署与启动

### 11.1 数据库初始化

```bash
mysql -uroot -p < database/init.sql              # 基础库 + C 端种子
mysql -uroot -p < database/admin_init.sql        # 管理端表 + 角色/权限种子
mysql -uroot -p < database/fusion_upgrade.sql    # 融合升级（三链/审批/社区/资格购）
mysql -uroot -p < database/fusion_final_upgrade.sql
mysql -uroot -p < database/full_feature_upgrade.sql
mysql -uroot -p < database/marketing_activity_upgrade.sql
mysql -uroot -p < database/activity_reward_upgrade.sql
mysql -uroot -p < database/seed-dev.sql          # 可选：联调种子数据（生产勿执行）
```

> 以上脚本均幂等，可重复执行。

### 11.2 后端（sinan-nft-backend）

```bash
cd sinan-nft-backend
composer install
cp .example.env .env          # 修改数据库连接 / JWT 密钥（ADMIN_SECRET 需 ≥32 字节）
php think run --host 0.0.0.0 --port 8080
```

- C 端接口前缀 `/api/**`，管理端接口前缀 `/admin/**`
- 认证：`Authorization: Bearer {token}`
- 验证码：`APP_DEBUG=true` 时返回 `debugCode`，不真实下发短信

### 11.3 管理后台（sinan-admin）

```bash
cd sinan-admin
npm install
npx vite --port 5174 --host 0.0.0.0
# 生产构建：npx vite build
```

- 开发代理：`/api/admin/**` → `http://127.0.0.1:8080/admin/**`
- 默认账号：`admin / admin123`（超级管理员）

### 11.4 C 端 H5（sinan-art-source）

```bash
cd sinan-art-source
npm install
npm run dev      # vite --host，默认 5173
npm run build    # 产物 dist/
```

### 11.5 生产部署要点

- **后端**：PHP 8.1+，MySQL 8.0 / MariaDB 10.6+；生产环境关闭 `APP_DEBUG`，`ADMIN_SECRET` / `USER_SECRET` 各自独立且 ≥32 字节。
- **前端**：构建产物 `dist/` 部署到静态服务器，反向代理将 `/api/admin/**` 转发到后端 `/admin/**`，`/api/**` 转发到后端 `/api/**`。
- **安全**：管理端登录连续失败锁定、大额退款强制审批、平台清库需短信验证码 + 四步确认。
- **审计**：库存恒等式、资金恒等式可在「系统 → 数据审计」随时校验。

---

## 12. 开发约定

### 12.1 前端约定

- **路由**：Hash 模式，主 Tab 静态 import 同步加载，子页懒加载。
- **状态**：Pinia store 按领域拆分（collection/user/site/order/activity/notice）。
- **样式**：颜色一律引用 CSS 变量（`var(--color-*)` / SCSS `$color-*`），禁止硬编码色值。
- **金额**：统一使用字符串传输 + `decimal.js` 计算，展示用 `fmtMoney`（千分位 + 2 位小数）。
- **请求**：axios 实例，请求拦截器注入 token，响应拦截器统一处理 code/401。
- **C 端适配**：`postcss-px-to-viewport` 将 px 转为 vw，适配移动端。
- **管理后台**：列表页复用 `AdminTablePage` + `StatusTag`，业务状态先进 `utils/maps.js`。

### 12.2 后端约定

- **多应用**：`app/api`（C 端）/ `app/admin`（管理端），URL 前缀自动剥离。
- **响应结构**：C 端 `{ code, message, data }`，`code=0` 成功；管理端 `code=200` 成功。
- **中间件链**：C 端 `Cors → JwtAuth/OptionalJwtAuth`；管理端 `AdminAuth → AdminPermission`。
- **Service 层**：核心业务逻辑下沉到 Service（InventoryService/JwtService/RaffleService 等）。
- **审计**：敏感操作调用 `$this->audit()` 写操作日志。

### 12.3 Git 约定

- 分支：`main` 为主分支。
- 提交信息格式：`<类型>: <描述>`，如 `feat:`、`fix:`、`rename:`、`docs:`。

---

## 附录

### A. 默认账号

| 系统 | 账号 | 密码 | 说明 |
|------|------|------|------|
| 管理后台 | admin | admin123 | 超级管理员 |

### B. 业务字典

| 字典 | 取值 |
|------|------|
| ORDER_STATUS | pending 待支付 / paid 已支付 / completed 已完成 / cancelled 已取消 / refunding 退款中 / refunded 已退款 |
| ORDER_SOURCE | release 公售 / priority 优先购 / eligibility 资格购 / market 市场 / blindbox 盲盒 |
| COLLECTIBLE_STATUS | onsale 发售中 / upcoming 待发售 / soldout 已售罄 / offline 已下架 |
| RESALE_STATUS | onsale 挂单中 / frozen 已冻结 / sold 已成交 / cancelled 已取消 / system_delisted 系统下架 |
| TRANSFER_STATUS | pending 待接收 / completed 已完成 / rejected 已拒绝 / revoked 已撤销 |
| CHAIN_TYPE | wenchang 文昌链 / consortium 联盟链 / antchain 蚂蚁链 |
| ROLE | super 超级管理员 / operator 运营 / finance 财务 / risk 风控 / support 客服 |

---

> 文档版本：v2.5 · 生成日期：2026-09-10
