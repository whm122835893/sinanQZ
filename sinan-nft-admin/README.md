# 司南数字藏品平台 · 管理后台（sinan-nft-admin）

基于 **Vue 3 + TypeScript + Vite 5 + Element Plus + Pinia + ECharts** 构建的数字藏品平台管理后台，配套后端为同仓库的 `sinan-nft-backend`（ThinkPHP 8 多应用，`app/admin` 模块）。设计参考 BuildAdmin 后台范式（多标签页 / 面包屑 / 权限驱动菜单 / 主题化），并融合主流数字藏品后台（鲸探 / 幻核类）的功能模块。

## 一、技术架构

| 层 | 选型 | 说明 |
| --- | --- | --- |
| 前端框架 | Vue 3.5 + `<script setup>` + TypeScript | 组合式 API，全量类型标注 |
| 构建 | Vite 5 | 开发代理 `/admin → :8080`；生产按 vue/element/echarts 分包 |
| UI | Element Plus 2.9 + @element-plus/icons-vue | 司南红主题（CSS 变量覆盖，品牌色 `#B00000`） |
| 状态 | Pinia | `stores/auth.ts`：token、管理员信息、权限码集合 |
| 图表 | ECharts 5 | 通用封装组件 `components/EChart.vue`（自动 resize/销毁） |
| 路由 | Vue Router 4 | 静态路由表 + `beforeEach` 权限守卫（无权限跳 403） |
| 请求 | Axios | `api/http.ts` 统一拦截：token 注入、401 自动跳登录、code≠200 全局提示 |
| 后端 | ThinkPHP 8（app/admin 多应用） | JWT 双密钥体系（管理员与 C 端用户隔离）、RBAC 中间件 |
| 数据库 | MySQL 8 / MariaDB | 55 张表（C 端 33 + 后台 22），83 个权限点 |

## 二、功能模块（19 组 / 48 个视图）

| 模块 | 页面 | 核心能力 |
| --- | --- | --- |
| 数据大盘 | `/dashboard` | 用户/GMV/订单/藏品指标卡、7/30 天趋势图、待办聚合 |
| 用户管理 | `/user`、`/user/:id` | 搜索筛选、冻结/恢复、重置交易密码、强制下线、拉黑、资产与订单聚合详情 |
| 实名认证 | `/realname` | 待审列表、脱敏查看、风控岗可查看完整证照 |
| 藏品管理 | `/collectible`、`/collectible/:id` | 新增/编辑/发售/配额、强制售罄/下架、销毁库存、空投、市场参数、资格购配置、库存守恒审计 |
| 盲盒管理 | `/blindbox`、`/blindbox/:id` | 盲盒+奖品池配置、发放上限校验、库存审计 |
| 订单管理 | `/order`、`/order/:id` | 全状态流转、取消/标记支付/退款、异常订单审计（4 类）+ CSV 导出 |
| 退款管理 | `/refund` | 待审列表、审批 → 执行两段式退款（资金+资产双回滚） |
| 市场寄售 | `/market` | 挂单冻结/强制下架、市场开关与费率配置 |
| 转赠管理 | `/transfer` | 转赠记录、撤销与资产回退 |
| 营销活动 | `/marketing/*`（7 页） | 优先购白名单、签到、邀请、抽奖、合成、空投、注册福利 |
| 钱包财务 | `/wallet/*`（4 页） | 流水/充值/手续费统计、异常资金监控（恒等式校验） |
| 内容管理 | `/cms/*`（5 页） | 轮播图、公告、协议、文物展馆、站点装修 |
| 系统配置 | `/system/*`（4 页） | 全局参数、**支付渠道（第三方钱包）**、**短信配置**、安全策略 |
| 权限管理 | `/permission/*`（3 页） | 管理员/角色 CRUD、权限树勾选、操作与登录日志 |
| 风控安全 | `/security/*`（3 页） | 黑名单、风控告警处置、安全事件 |
| 客服工单 | `/ticket` | 受理、回复、状态流转 |
| 数据报表 | `/report/*`（5 页） | 销售/用户/藏品/盲盒/财务对账（区间筛选、粒度自适应） |
| 平台运维 | `/platform/logs` | 清库预览、短信验证码确认、清库日志（高危双确认） |
| 个人中心 | `/profile` | 资料、修改密码 |

### 短信配置（`/system/sms`）

服务商选择（阿里云/腾讯云）、启用状态、每日发送上限、AccessKey/Secret、签名与模板 ID、测试发送；密钥仅回显掩码，保存后重新加密落库。

### 第三方钱包/支付渠道（`/system/payment`）

余额/支付宝/微信/汇付天下/银联五渠道：启停、费率、商户号、AppID、同步/异步回调地址、密钥配置；与 C 端 `payments` 表对账口径一致。

## 三、权限矩阵（5 角色 / 83 权限点）

| 角色 | code | 权限数 | 职责边界 |
| --- | --- | --- | --- |
| 超级管理员 | `super_admin` | 83（全部） | 含平台清库、完整实名查看、所有高风险操作 |
| 运营 | `operator` | 56 | 藏品/盲盒管理、活动配置、CMS、基础用户管理、订单查看 |
| 财务 | `finance` | 18 | 订单管理、退款审批、钱包流水、财务报表 |
| 风控 | `risk` | 26 | 黑名单、风控告警、实名完整查看、异常交易处理 |
| 客服 | `support` | 12 | 工单处理、基础用户查询（仅脱敏信息） |

权限点按模块分布：collectible 13、blindbox 10、marketing 10、user 6、wallet 5、report 5、cms 5、order 5、system 4、security 3、market 3、permission 3、platform 2、realname 2、refund 2、ticket 2、transfer 2、dashboard 1。

**校验链路**：路由 `meta.permission` → `auth.hasPermission()` 拦截跳 403；按钮级 `v-permission` 指令；后端 `AdminAuth`（JWT）+ `AdminPermission`（权限码）双重校验，前端隐藏不等于后端放行。

## 四、快速部署

### 1. 环境

- PHP ≥ 8.1（`pdo_mysql mbstring xml curl`）、MySQL 8 / MariaDB 10.6+、Node.js ≥ 18
- 后端依赖：`composer install`；前端依赖：`npm install`

### 2. 数据库初始化

```bash
mysql -uroot -p < database/init.sql       # C 端 33 表（可幂等重跑）
mysql -uroot -p < database/admin_init.sql  # 后台 22 表 + 角色/权限/管理员种子
```

### 3. 后端配置（`sinan-nft-backend/.env`）

```ini
[DATABASE]
HOSTNAME = 127.0.0.1
DATABASE = sinan_nft
USERNAME = root
PASSWORD = your_password

[JWT]
ADMIN_SECRET = <≥32 字节随机串>   # 管理员令牌签名，与 C 端 USER_SECRET 隔离
```

启动：`php think run --host 0.0.0.0 --port 8080`（生产用 Nginx + PHP-FPM，root 指向 `public/`）。

### 4. 前端启动

```bash
cd sinan-nft-admin
npm install
npm run dev        # http://localhost:5173，代理 /admin → 127.0.0.1:8080
npm run build      # 产物 dist/，部署时由 Nginx 反代 /admin 到后端
```

### 5. 默认账号

| 账号 | 密码 | 说明 |
| --- | --- | --- |
| `admin` | `Admin@123456` | 超级管理员，**首次登录后立即修改** |

其他角色账号由超管在「权限管理 → 管理员」创建并分配角色。

## 五、安全机制

- **JWT 双密钥**：admin/access 与用户端令牌互不通用，`aud` 校验 `sinan-admin`；48 小时过期 + refresh 轮换。
- **登录防爆破**：连续 5 次失败锁定 30 分钟；登录日志记录 IP/UA。
- **操作审计**：全部敏感操作（冻结、退款、清库、配置变更等）写入 `nft_admin_operation_logs`，含前后快照。
- **数据脱敏**：手机号 `138****1234`、证件号前 3 后 4；完整信息需 `realname:full` 权限。
- **高危双确认**：平台清库需「预览 → 短信验证码 → 确认」三步，先自动备份。
- **资金恒等式**：`balance = available + frozen` 全链路事务校验；退款两段式（审批→执行）。
- **库存守恒**：`edition = sold + 在途 + available_pool + reserved + airdropped + destroyed`，发售/销毁/空投均在事务内扣减。

## 六、接口约定

- 前缀 `/admin/*`；除 `auth/login`、`auth/refresh` 外均需 `Authorization: Bearer <token>`。
- 统一响应：`{ code, message, data }`，`code=200` 成功；分页为 `data.list/total/page/pageSize`。
- 时间参数 `startDate`/`endDate`（`YYYY-MM-DD` 自动补全天边界）；报表区间默认近 30 天、上限 366 天，超 92 天自动按月聚合。

## 七、目录结构

```
sinan-nft-admin/
├── src/
│   ├── api/            # 按域拆分的接口层（12 个文件）
│   ├── components/     # EChart 封装
│   ├── layout/         # 侧边栏 + 导航栏 + 标签页 + 菜单配置
│   ├── router/         # 静态路由表 + 权限守卫
│   ├── stores/         # Pinia（auth）
│   ├── styles/         # 司南红主题 + 通用样式类
│   ├── utils/          # format.ts（枚举映射/脱敏）、useListPage.ts（列表页组合式）
│   └── views/          # 19 个模块 48 个视图
└── vite.config.ts      # 代理与分包
```

配套后端见 `../sinan-nft-backend/app/admin/`（控制器/中间件/服务/路由同构分层）。
