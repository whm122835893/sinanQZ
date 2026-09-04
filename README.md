# sinanQZ 数字藏品 H5 平台（司南珍藏）

前后端 + 数据库三段式单仓库。

## 目录结构

```
sinanQZ/
├── jichao-art-source/     # 前端（Vue 3 + Vite 5 + Pinia + Vant 4）
├── sinan-nft-backend/     # 后端（ThinkPHP 8 + JWT + MySQL/MariaDB）
└── database/              # 数据库（建表脚本 + 设计文档）
    ├── init.sql           # 完整初始化脚本：33 表 / 56 外键 / 16 CHECK / 种子数据（幂等可重复执行）
    └── database-design.html  # 数据模型设计文档
```

## 快速启动

### 1. 数据库

```bash
mysql -uroot -p < database/init.sql
# 建库 sinan_nft（utf8mb4），含测试账号与业务种子数据
```

### 2. 后端（sinan-nft-backend）

```bash
cd sinan-nft-backend
composer install
cp .example.env .env          # 按需修改数据库连接 / JWT 密钥
php think run --host 0.0.0.0 --port 8000
```

- 53 个 RESTful 接口，路由定义见 `route/api.php`
- 认证：JWT（`Authorization: Bearer {token}`），敏感操作二次校验交易密码
- Mock 说明：验证码不真实下发短信，`APP_DEBUG=true` 时登录/注册接口返回 `debugCode`

### 3. 前端（jichao-art-source）

```bash
cd jichao-art-source
npm install
npm run dev
```

## 核心业务模块

购买（首发/市场寄售）、支付、盲盒开启、合成、抽奖、寄售挂单、转赠、签到、钱包、邀请、实名认证。
