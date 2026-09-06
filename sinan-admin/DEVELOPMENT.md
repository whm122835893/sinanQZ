# 司南珍藏 · 管理后台（sinan-admin）开发文档

> 版本：v2.0（BuildAdmin 数字藏品综合管理后台）· 更新日期：2026-09-05
> 定位：数字藏品平台运营管理后台，覆盖藏品全生命周期、多角色权限、库存闭环、风控安全、区块链交互与数据统计；与 C 端（jichao-art-source）数据同构、与后端（sinan-nft-backend，ThinkPHP 8.1 app/admin）接口对齐。

---

## 1. 项目概述

### 1.1 技术栈

| 层级 | 选型 | 版本 |
|------|------|------|
| 框架 | Vue 3（Composition API + `<script setup>`） | ^3.4.27 |
| 构建 | Vite 5 | ^5.3.1 |
| 路由 | vue-router（Hash 模式 + 动态权限守卫） | ^4.3.2 |
| 状态 | Pinia（admin / app） | ^2.1.7 |
| UI 组件 | **Element Plus**（主题令牌覆盖为司南红）+ @element-plus/icons-vue（全局注册） | ^2.14.5 |
| 图表 | ECharts 6（`EChart.vue` 通用容器） | ^6.1.0 |
| 日期 | dayjs | ^1.11.11 |
| 金额 | decimal.js（金额字符串传输） | ^10.4.3 |
| 请求 | axios（实例已建，联调启用） | ^1.7.2 |
| 样式 | SCSS（变量 + CSS 变量双层令牌） | ^1.77.4 |

> v1.0 的 Vant 移动端风格已在 v2.0 全面迁移为 Element Plus 桌面端（Soybean Admin 式布局），`vant` 依赖已无引用，可在下次 `npm prune` 时移除。

### 1.2 目录结构

```
sinan-admin/
├── index.html
├── vite.config.js            # @ -> src 别名，dev 代理，echarts 手动分包
├── package.json
└── src/
    ├── main.js               # 注册 Element Plus / icons / Pinia / Router
    ├── App.vue               # 根容器（含登录页判断）
    ├── api/index.js          # 全部 API（当前 Mock 实现，结构对齐后端 /admin/api/v1）
    ├── mock/db.js            # 内存数据库（联调后整体移除）
    ├── router/
    │   ├── index.js          # 路由表 + 登录守卫 + 权限码校验（403 拦截）
    │   └── menu.js           # 侧边栏菜单配置（菜单即路由，perm 权限码）
    ├── stores/
    │   ├── admin.js          # 管理员会话（token + info + permissions + 5 角色）
    │   └── app.js            # 设备识别（isMobile）
    ├── layouts/
    │   ├── AdminLayout.vue   # 浅色侧栏（可折叠）+ Header + 多标签页
    │   └── components/       # AdminSidebar / AppHeader / TabsNav
    ├── components/           # 通用组件（见 §4.4）
    ├── directive/            # v-permission 按钮权限指令
    ├── utils/
    │   ├── request.js        # http 实例 + mock() + queryList() + mockWrite()
    │   ├── maps.js           # 全部业务状态字典（30+ 字典）
    │   └── format.js         # 时间/金额/脱敏格式化 + stockPool/blindBoxPool 库存公式
    ├── styles/               # variables / mixins / global（设计令牌 + EP 变量覆盖）
    └── views/                # 按业务域分目录的页面（35+ 页面）
```

### 1.3 运行

```bash
cd sinan-admin
npm install
npm run dev        # vite --host，默认 5173
npm run build      # 产物 dist/
```

演示账号：`admin / admin123`（见 `src/api/index.js` login 的 Mock 逻辑）。敏感操作密码验证 Mock 同为 `admin123`。

---

## 2. 架构设计

### 2.1 请求层（当前 Mock、预留联调）

`src/utils/request.js` 定义了三层结构：

- `http`：axios 实例，`baseURL: '/api/admin'`，请求拦截器自动附加 `Authorization: Bearer <token>`（token 存于 `localStorage.sinan_admin_token`）。
- `mock(handler, delay)` / `mockWrite(handler, delay)`：模拟响应，统一返回 `{ code, message, data }`，异常被捕获为 `code: 1`（写操作延迟更低）。
- `queryList(list, params)`：通用列表查询 —— `keyword` 多字段模糊、其余字段等值过滤（`'all'/''` 跳过）、`page/size` 分页，返回 `{ list, total, page, size }`。

**响应约定**：`code === 0` 成功；页面层以 `res.code === 0` 判定后执行 `ElMessage.success` / 局部刷新。

**联调切换**：`src/api/index.js` 中每个函数把 `mock(() => …)` 替换为 `http.get/post(...)` 即可，函数签名与返回结构保持不变；`mock/db.js` 届时整体删除。

### 2.2 路由与权限（5 角色体系）

- Hash 模式路由，`meta` 字段：`title`（页面标题）、`perm`（权限码）、`public`（免登录，仅 `/login`）。
- 全局前置守卫：未登录 → `/login?redirect=...`；已登录访问 `/login` → `/dashboard`；**无权限码 → 403 提示并跳回看板**。
- 菜单在 `src/router/menu.js` 声明（BuildAdmin 式"菜单即路由"），每项带 `perm`，侧栏按管理员 `permissions` 过滤渲染。
- 按钮级权限：`v-permission="['xxx']"` 自定义指令。

| 角色 | 权限范围 |
|------|----------|
| `super` 超级管理员 | 全部功能，含平台清库、完整实名查看、所有高风险操作 |
| `operator` 运营专员 | 藏品/盲盒/活动/CMS/基础用户管理（冻结/解冻）/订单查看 |
| `finance` 财务专员 | 订单/钱包流水/财务报表/手续费统计/收支导出 |
| `risk` 风控专员 | 黑名单/异常交易审批/风控告警/实名认证完整查看 |
| `support` 客服专员 | 工单处理/基础用户查询（仅脱敏）/用户资产查看 |

### 2.3 布局（Soybean Admin 风格）

- 左侧浅色侧边栏（白底 + 右边框），可折叠至图标模式；按菜单分组渲染。
- 顶部 Header：面包屑、全局搜索、全屏切换、主题色、用户头像下拉。
- Header 下方**多标签页导航**：可关闭/刷新/关闭其他/关闭全部，当前标签底部 2px 主色下划线。
- 主内容区白色卡片容器（圆角 8px + 柔和阴影 + padding 20px）。
- 响应式：< 769px 时侧栏变抽屉式（el-drawer），表格横向滚动，弹窗全屏。

---

## 3. UI 风格规范

### 3.1 设计理念

"国潮朱红"品牌延续：白卡片 + 司南红主色 + 鎏金辅色 + 楷体书法字点缀。所有颜色通过 `:root` CSS 变量下发，并同步覆盖 Element Plus 主题令牌。

### 3.2 色彩令牌（`styles/global.scss :root`）

| 令牌 | 值 | 用途 |
|------|-----|------|
| `--color-primary` | `#C00000` | 主色（司南红）：主按钮、激活态、价格、链接 |
| `--color-primary-dark` | `#A00000` | 主色按压态 |
| `--color-primary-light` | `#F5E0E0` | 主色浅底 |
| `--color-primary-bg` | `#FFF5F5` | 主色背景（激活胶囊、待办块、选中态） |
| `--color-gold` | `#D4A574` | 鎏金辅色（排行、进度渐变端点） |
| `--color-gold-dark` | `#B08D55` | 金色文字 |
| `--color-bg` | `#F7F8FA` | 页面背景 |
| `--color-card` | `#FFFFFF` | 卡片背景 |
| `--color-surface` | `#F2F3F5` | 内嵌面/进度条底 |
| `--color-text-primary/secondary/tertiary` | `#333 / #666 / #999` | 文字三级 |
| `--color-border` | `#EEEEEE` | 分割线 |
| `--color-success / warning / blue` | `#07c160 / #ff976a / #1989fa` | 状态扩展色 |

**Element Plus 变量覆盖**（`:root`）：`--el-color-primary: #C00000`、`--el-color-primary-dark-2: #A00000`、`--el-color-danger: #C00000`、`--el-border-color: #EEEEEE`、`--el-bg-color: #F7F8FA`、`--el-text-color-primary/regular/secondary` 对应文字三级。

### 3.3 字体与排版

| 类别 | 字体栈 | 工具类 |
|------|--------|--------|
| 正文 | PingFang SC, Helvetica Neue, Arial | 默认 14px / 1.5 行高 |
| 数字/价格 | DIN Alternate, DIN Condensed | `.price`（加粗+主色） |
| 书法 | STKaiti / KaiTi | `.calligraphy`（品牌点缀） |
| 哈希/地址 | JetBrains Mono, Consolas | `.ch__hash` 等场景 |

### 3.4 通用样式类（global.scss）

| 类名 | 说明 |
|------|------|
| `.adm-page` | 页面容器 |
| `.adm-card` / `.adm-card__title` | 白卡片（8px 圆角 + 柔和阴影）/ 卡片标题（flex，右侧可放操作） |
| `.adm-grid` | 指标卡栅格（桌面 4 列） |
| `.adm-kv` | 键值对行（详情页） |
| `.t-secondary` / `.t-tertiary` / `.t-gold` | 辅助文字色工具类 |
| `.trend-up / .trend-down` | 环比上行（绿）/ 下行（红） |
| `.thin-scrollbar` | 4px 细滚动条 |

### 3.5 Element Plus 组件使用规范

- 表格：`el-table`，表头 `#F5F7FA`，操作列 `fixed="right"`，长文本 `show-overflow-tooltip`。
- 列表页：`AdminTablePage` 脚手架（搜索 + 筛选下拉 + 分页），空态 `el-empty`。
- 弹窗/抽屉：编辑表单用 `el-dialog`（520px，`close-on-click-modal=false`）；详情浏览用 `el-drawer`。
- 状态标签：`StatusTag` 组件封装 `el-tag`（plain），文案与颜色来自 `utils/maps.js`。
- 危险/不可逆操作：`ElMessageBox.confirm` 二次确认 → 调 API → `ElMessage.success`。
- 敏感操作（空投/销毁/清库）：`PasswordVerify` 组件验证管理员密码后才执行。

---

## 4. 功能模块

### 4.1 模块总览（菜单 = 路由，35+ 页面）

| 分组 | 路由 | 页面 | 核心功能 |
|------|------|------|----------|
| 总览 | `/dashboard` | 数据看板 | 核心指标（环比）、待办四宫格、ECharts 图表、热销榜 |
| 总览 | `/statistics` | 数据统计 | DAU/留存/用户增长/财务报表/手续费构成/热门排行 |
| 用户 | `/user` | 用户管理 | 搜索筛选、冻结、重置交易密码、黑名单、强制回收、详情 |
| 用户 | `/user/realname` | 实名审核 | 待审列表（脱敏）、通过/驳回、完整查看（密码验证） |
| 藏品 | `/collectible` | 藏品管理 | 列表、上下架、强制售罄、寄售/转赠开关、价格管控、新建 |
| 藏品 | `/collectible/detail/:id` | 藏品详情 | 库存守恒审计、配额、独立空投、销毁、发售配置、持有人 |
| 藏品 | `/collectible/edit/:id?` | 编辑藏品 | 新建/编辑藏品全字段 |
| 藏品 | `/blindbox` | 盲盒管理 | 盲盒列表、库存恒等式 |
| 藏品 | `/blindbox/detail/:id` | 盲盒配置 | 子藏品概率/计划数量、发售、空投、销毁、开关 |
| 藏品 | `/blindbox/edit/:id?` | 编辑盲盒 | 新建/编辑盲盒全字段 |
| 交易 | `/order` | 订单管理 | 五来源（公售/优先购/资格购/市场/盲盒）筛选、操作 |
| 交易 | `/order/refunds` | 退款管理 | 退款审批（联动订单状态） |
| 交易 | `/resale` | 寄售市场 | 挂单/求购/成交三 Tab，冻结/解冻/系统下架、成交走势 |
| 交易 | `/transfer` | 转赠管理 | 审批 + 撤销已完成转赠（二次流转校验） |
| 营销 | `/marketing` | 营销中心 | 活动入口聚合 + 统一奖励类型说明 |
| 营销 | `/marketing/checkin` | 签到配置 | 规则表（第 N 天 → 统一奖励类型）、启停、连签榜 |
| 营销 | `/marketing/luckydraw` | 抽奖活动 | 奖项池（概率 ≤100% 校验）、启停、抽奖记录 |
| 营销 | `/marketing/synthesis` | 合成活动 | 材料 M:N → 产物、限次配置、启停 |
| 营销 | `/marketing/invite` | 邀请活动 | 双方奖励（统一奖励类型）、邀请记录统计 |
| 营销 | `/marketing/priority` | 优先购管理 | 白名单（手机号+限量+有效期精确到时分秒）、过期清理 |
| 营销 | `/marketing/qualification` | 资格购管理 | 条件配置（任一/全部）、额外手机号白名单、独立开关 |
| 资产 | `/wallet` | 钱包流水 | 收支统计、流水分页筛选 |
| 风控 | `/risk` | 风控告警 | 告警列表（类型/等级筛选）、处理（填结论） |
| 风控 | `/tickets` | 客服工单 | 工单列表、详情抽屉、回复、关闭 |
| 内容 | `/content/announcements` | 公告管理 | 类型/状态、CRUD、浏览量 |
| 内容 | `/content/banners` | 轮播管理 | 首页轮播 CRUD、排序、启停 |
| 内容 | `/content/community` | 社区管理 | 社群 CRUD |
| 内容 | `/content/artifacts` | 文物展馆 | 文物 CRUD、上下架 |
| 内容 | `/content/audits` | 内容审核 | UGC 藏品/社区帖子审核（通过/驳回填原因） |
| 区块链 | `/chain` | 链上交互 | 合约管理（多链多合约启停）、链上交易监控、Gas 管理 |
| 系统 | `/system/admins` | 管理员 | 管理员列表（2FA/IP 白名单）、5 角色权限明细 |
| 系统 | `/system/logs` | 操作日志 | 登录日志 + 操作日志审计 |
| 系统 | `/system/approvals` | 审批中心 | 大额退款/资产修改/支付配置/清库审批工作流 |
| 系统 | `/system/config` | 站点配置 | 基础/支付/手续费/区块链节点/存储 五组配置 |
| 系统 | `/system/cleanup` | 平台清库 | 四重安全确认（文本+密码+短信+最终确认） |
| — | `/login` | 登录 | 账号密码登录 |

### 4.2 核心业务闭环（全局规则）

#### 库存恒等式（藏品）
```
库存池 = 发行总量 − 已配置配额 − 已售出发售 − 已独立空投 − 已销毁
恒等式：发行总量 = 库存池 + 配额 + 已售 + 空投 + 销毁（详情页实时审计）
```
- 配额可在任何时间配置（发售前/中/售罄后），新增配额 ≤ 当前库存池，超发拦截。
- 独立空投：手机号批量（校验注册）→ 二次确认 → 密码验证 → 扣库存池。
- 销毁：≤ 库存池（不可销毁配额预留），不可逆。
- 强制回收/撤销转赠：校验二次流转（再次转赠/寄售/合成/盲盒消耗），已流转拦截。

#### 盲盒库存恒等式
```
盲盒库存池 = 盲盒发行总量 − 盲盒已售出发售 − 盲盒已独立空投 − 盲盒已销毁
```
- 子藏品概率之和 ≤ 100%（>100% 拦截，<100% 差额为空奖）；库存不预先冻结，开启时实时校验，不足降级空奖。

#### 资格购（独立于优先购）
- 条件：资格藏品（circulate>0）/累计签到天数/累计邀请数/额外手机号白名单；组合方式「满足任一/全部」。
- 有效期可配置；不占配额不冻结库存；优先购资格可绕过资格购。

#### 寄售开关与价格管控
- 藏品独立寄售开关；关闭时所有在售挂单自动「系统下架」。
- 价格管控：限价模式（上下限闭区间）/不限价模式；仅「已售罄/发售中」可操作。

#### 奖励类型统一
全平台活动奖励下拉：藏品 / 优先购白名单资格 / 资格购资格 / 抽奖次数 / 司南币 / 盲盒；发放时动态校验库存，不足拦截提示。

#### 平台清库（最高风险）
四重确认：红色警示手动输入「确认清除」→ 管理员密码 → 短信验证码（超管手机）→ 最终确认执行。清除全部用户业务数据，保留管理员/藏品盲盒元数据（库存重置为发行量）/CMS/系统配置；执行前自动备份，操作记录审计日志。

### 4.3 订单状态流转

```
pending(待支付) ──> paid(已支付) ──> completed(已完成)
   │                  │
   │超时/取消          │申请退款
   ▼                  ▼
cancelled(已取消)  refunding(退款中) ──审批──> refunded(已退款)
```
订单来源（ORDER_SOURCE）：release 公售 / priority 优先购 / eligibility 资格购 / market 市场 / blindbox 盲盒。

### 4.4 通用组件

| 组件 | 说明 |
|------|------|
| `AdminTablePage.vue` | **表格列表页脚手架**：搜索 + 下拉筛选 + el-table + 分页；props：`fetch / filters / searchPlaceholder / size / defaults / hideSearch`；slot 作用域暴露 `items`；expose `refresh()` |
| `PasswordVerify.vue` | 管理员密码验证弹窗（敏感操作前置），`@verified` 回调继续 |
| `StatCard.vue` | 指标卡：icon + 数值 + 单位 + 环比趋势（tone 四色） |
| `EChart.vue` | ECharts 通用容器：传入 option 自动渲染，窗口自适应 |
| `StatusTag.vue` | 状态标签：`(value, map)` → el-tag plain |
| `AdminListPage.vue` / `DetailSheet.vue` | v1.0 移动端遗留组件（已无引用，可清理） |

---

## 5. 业务字典（utils/maps.js，30+ 字典）

| 字典 | 取值 |
|------|------|
| ORDER_STATUS | pending 待支付 / paid 已支付 / completed 已完成 / cancelled 已取消 / refunding 退款中 / refunded 已退款 / abnormal 异常 |
| ORDER_SOURCE | release 公售 / priority 优先购 / eligibility 资格购 / market 市场 / blindbox 盲盒 |
| USER_STATUS | normal 正常 / frozen 已冻结 |
| REALNAME_STATUS | approved 已实名 / pending 待审核 / rejected 已驳回 / none 未实名 |
| COLLECTIBLE_STATUS | onsale 发售中 / upcoming 待发售 / soldout 已售罄 / offline 已下架 |
| RESALE_STATUS | onsale 挂单中 / frozen 已冻结 / sold 已成交 / cancelled 已取消 / **system_delisted 系统下架** |
| TRANSFER_STATUS | pending 待接收 / completed 已完成 / rejected 已拒绝 / **revoked 已撤销** |
| REFUND_STATUS | pending 待审批 / approved 已退款 / rejected 已驳回 |
| ACTIVITY_STATUS | enabled 进行中 / disabled 已停用 |
| RESALE_PRICE_MODE | limit 限价模式 / free 不限价 |
| QUOTA_TYPES | 1 优先购 / 2 活动空投 / 3 签到 / 4 注册 / 5 邀请 / 6 抽奖 / 7 其他 |
| RISK_LEVEL / RISK_STATUS / RISK_TYPE | 高中低风险 / 待处理·处理中·已处理 / 异常交易·批量退款·批量注册·价格操纵 |
| TICKET_STATUS / TICKET_PRIORITY / TICKET_TYPE | 待处理·处理中·已关闭 / 紧急·高·普通 / 订单·退款·账号·其他 |
| CHAIN_TX_TYPE / CHAIN_TX_STATUS | Mint 铸造·Transfer 转账·Sale 交易 / 成功·上链中·失败 |
| AUDIT_STATUS / CONTENT_AUDIT_TYPE | 待审核·已通过·已驳回 / 用户自建藏品·社区帖子 |
| APPROVAL_STATUS / APPROVAL_TYPE | 待审批·已通过·已驳回 / 大额退款·资产修改·支付配置·平台清库 |
| BUY_REQUEST_STATUS | active 求购中 / delisted 已下架 |
| QUALIFY_CONDITION_TYPE | 1 满足任一 / 2 满足全部 |
| ROLE_MAP | super / operator / finance / risk / support（5 角色） |

---

## 6. 数据表设计

后端库共 **42+ 张表**（`database/init.sql`），前缀 `nft_`。管理后台各模块与物理表的映射（关键字段以 init.sql 为准）：

### 6.1 用户与资产

| 表 | 说明 | 后台模块 |
|----|------|----------|
| `nft_users` | 用户主数据 + is_blacklisted/blacklist_reason | 用户管理 |
| `nft_wallets` / `nft_wallet_transactions` | 钱包 + 流水（balance_after 快照） | 钱包流水 |
| `nft_user_collectibles` | 用户藏品（每份一行，serial 序列号） | 藏品详情/回收 |
| `nft_verification_codes` / `nft_user_favorites` | 验证码 / 关注 | —（C 端） |

### 6.2 藏品与盲盒

| 表 | 说明 | 后台模块 |
|----|------|----------|
| `nft_collectibles` | 藏品主表：edition/sold/reserved_count/airdropped_count/destroyed_count/circulate、is_resaleable/resale_price_mode/min/max、is_transferable | 藏品管理 |
| `nft_blind_boxes` | 盲盒扩展：price/edition/sold/status/per_user_limit/destroyed_count/airdropped_count | 盲盒管理 |
| `nft_blind_box_items` | 子藏品奖池：probability/quantity_limit | 盲盒配置 |
| `nft_categories` | 分类 | 藏品管理 |
| `nft_inventory_quotas` | **库存配额预留**：quota_type/planned_quantity/used_quantity/status | 配额管理 |
| `nft_destroy_records` | **销毁记录台账** | 藏品/盲盒详情 |
| `nft_qualification_configs` | **资格购配置**：required_collectible_ids(JSON)/required_checkin_days/required_invite_count/condition_type | 资格购管理 |
| `nft_qualification_whitelists` | **资格购白名单**（expires_at 精确到时分秒） | 资格购管理 |

### 6.3 交易

| 表 | 说明 | 后台模块 |
|----|------|----------|
| `nft_orders` | 订单（source: release/market/priority/eligibility/blindbox） | 订单管理 |
| `nft_payments` | 支付记录 | 订单管理 |
| `nft_resale_listings` | 寄售挂单 + is_system_delisted/system_delisted_at | 寄售市场 |
| `nft_transfers` | 转赠记录 | 转赠管理 |
| `nft_refunds` | **退款记录**（nft_approvals 联动） | 退款管理 |

### 6.4 营销活动

| 表 | 说明 | 后台模块 |
|----|------|----------|
| `nft_synthesis_activities / materials / records / record_items` | 合成四表 | 合成活动 |
| `nft_lucky_draw_prizes / records` | 抽奖奖池（prize_type/prize_config/is_empty_prize）+ 流水 | 抽奖活动 |
| `nft_check_in_records` | 签到（reward_type 扩展 7 种） | 签到配置 |
| `nft_invite_activities / records` | 邀请（reward_type 同步扩展） | 邀请活动 |
| `nft_priority_sale_whitelists` | 优先购白名单（expires_at 精确到时分秒） | 优先购管理 |
| `nft_airdrop_activities / snapshots / records / eligibilities` | 空投四表 | 空投 |
| `nft_activity_rewards` | **活动奖励统一配置**（可选） | 营销中心 |

### 6.5 内容与系统

| 表 | 说明 | 后台模块 |
|----|------|----------|
| `nft_artifacts / announcements / banners / community_groups` | CMS 四表 | 内容管理 |
| `nft_content_audits` | **用户内容审核** | 内容审核 |
| `nft_admin_users` | 管理员（role TINYINT 1-5、twofa_secret、ip_whitelist、login_fail_count、locked_until） | 管理员 |
| `nft_admin_roles / permissions / role_permissions` | **RBAC 三表** | 管理员 |
| `nft_system_configs / site_settings` | 系统参数 KV | 站点配置 |

### 6.6 新增表（v2.0）

| 表 | 说明 | 后台模块 |
|----|------|----------|
| `nft_blacklist` | 黑名单（reason/expire） | 用户管理 |
| `nft_risk_alerts` | 风控告警 | 风控告警 |
| `nft_security_events` | 安全事件 | 风控 |
| `nft_support_tickets / ticket_replies` | 客服工单两表 | 客服工单 |
| `nft_platform_cleanup_logs` | 平台清库日志 | 平台清库 |
| `nft_chain_contracts / chain_transactions` | 区块链合约 + 链上交易 | 链上交互 |
| `nft_approvals` | 敏感操作审批工作流 | 审批中心 |

### 6.7 关键字段对照

| 前端字段（camelCase） | 物理字段（snake_case） |
|------------------------|--------------------------|
| `edition / sold / reservedCount / airdroppedCount / destroyedCount / circulate` | `nft_collectibles.edition / sold / reserved_count / airdropped_count / destroyed_count / circulate` |
| `isResaleable / resalePriceMode / resalePriceMin / resalePriceMax` | `is_resaleable / resale_price_mode / resale_price_min / resale_price_max` |
| `conditionType / requiredCheckinDays / requiredInviteCount` | `nft_qualification_configs.condition_type / required_checkin_days / required_invite_count` |
| `quotaType / plannedQuantity / usedQuantity` | `nft_inventory_quotas.quota_type / planned_quantity / used_quantity` |

---

## 7. 核心业务逻辑

### 7.1 库存公式（前后端一致）

- `stockPool(c)`（藏品）= edition − sold − lockedQuantity − reservedCount − airdroppedCount − destroyedCount
- `blindBoxPool(b)`（盲盒）= edition − sold − airdroppedCount − destroyedCount
- 与后端 `InventoryService`（`app/service/InventoryService.php`）同公式；详情页展示恒等式审计。

### 7.2 资格购判定流程（后端强制校验）

```
Step 1: 持有优先购资格？ → 是：可直接购买（覆盖资格购限制）
Step 2: 藏品开启资格购？ → 否：正常购买
Step 3: 白名单 ∨ 持有资格藏品 ∨ 累计签到达标 ∨ 累计邀请达标（任一/全部）
        → 均不满足：拦截「未获得购买资格」
```

### 7.3 退款联动

```
退款单 approve → 退款单 approved（handle_time）→ 订单 refunded → 钱包回流 + 资产回收
大额退款走 nft_approvals 审批工作流
```

### 7.4 安全与审计

- 全部敏感操作（空投/销毁/清库/强制回收/寄售开关关闭）二次确认 + 密码验证 + 审计日志。
- 实名信息：列表脱敏（maskPhone/maskName/maskIdNo），完整查看需密码验证并记录审计。
- 登录安全：失败计数自动锁定、2FA、IP 白名单（Mock 演示）。

---

## 8. 联调指南（Mock → 真实后端）

1. **替换 API**：`src/api/index.js` 中 `mock(...)` → `http.get/http.post`，路径约定 `/admin/api/v1/**`；`http` 实例与 Token 注入已就绪。
2. **删除 Mock**：移除 `src/mock/db.js` 及 `request.js` 中 `mock/mockWrite/queryList/nextId`。
3. **响应结构**：后端返回 `{ code, message, data }`，列表接口 `data = { list, total, page, size }`。
4. **鉴权**：登录后 `adminStore.setSession({ token, admin })`；Admin JWT 与 C 端 JWT 完全隔离（不同密钥）。
5. **动态菜单**：`router/menu.js` 可替换为后端下发 menus + permissions（权限树 key 已与路由对齐）。
6. **强校验移交后端**：库存恒等式、盲盒/抽奖概率合计、资格购判定、退款资金流、审计日志落库。

---

## 9. 开发约定

- 新增列表页：直接复用 `AdminTablePage` + `StatusTag`，业务状态先进 `utils/maps.js`。
- 颜色一律引用 CSS 变量（`var(--color-*)` / SCSS `$color-*`），禁止硬编码色值。
- 危险操作（冻结/售罄/销毁/退款/下架/删除）必须 `ElMessageBox.confirm` 二次确认；涉及资产的加 `PasswordVerify`。
- 金额展示统一 `fmtMoney`（千分位 + 2 位小数），数字 `fmtNumber`，空值显示 `-`；敏感个人信息用 `maskPhone/maskName/maskIdNo` 脱敏。
- 组件命名：业务卡片用 `adm-card` + `__block` BEM 扩展；页面容器一律 `adm-page`。
- 状态字段一律小写下划线（`realnameStatus` 等业务字段沿用 Mock 命名，联调时由后端转换层对齐）。
- 图表：统一走 `EChart.vue` 容器，折线渐变填充、饼图明亮配色（主色/鎏金/蓝/绿/橙循环）。
