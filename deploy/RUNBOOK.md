# 司南ART · 阿里云 ECS 上线手册

目标机：Alibaba Cloud Linux 3（2C4G）· 华东1杭州 · 宝塔面板 · 无域名，纯 IP 访问

> 端口分配：`80` = C 端 H5，`8088` = 管理后台，`127.0.0.1:8081` = 后端（不对外），`3306` = MySQL（仅本机）。
> 之所以分端口：没有域名时浏览器无法按名字区分站点，只能靠端口；后台单列端口也便于在安全组做 IP 白名单。

## 0. 安全组放行

阿里云控制台 → 实例 → 安全组 → 入方向，添加：

| 端口 | 授权对象 | 用途 |
|---|---|---|
| 22 | 建议限你自己的出口 IP | SSH |
| 80 | `0.0.0.0/0` | C 端 H5 |
| 8088 | 建议限你自己的出口 IP | 管理后台 |
| 宝塔面板端口 | 建议限你自己的出口 IP | 面板 |

**不要**放行 3306、8081、以及任何数据库端口。

注意有两层门：阿里云**安全组**（控制台侧）和机器上的 **firewalld**（宝塔侧）。两层都要放行才能访问。
宝塔装完会自动把面板端口、80、8888 加进 firewalld，缺的端口手动补：

```bash
firewall-cmd --add-port=8088/tcp --permanent && firewall-cmd --reload
firewall-cmd --list-ports
```

## 1. 装宝塔面板与运行环境

```bash
# Alibaba Cloud Linux 3 走官方一键脚本（地址以宝塔官网当前公布为准），装完打印面板地址+账号+密码
curl -sSfL https://download.bt.cn/install/install_panel.sh | bash
```

面板 → 软件商店 → 安装 **Nginx**、**MySQL 8.0**、**PHP**（版本见下）。

**PHP 版本必须是 8.4**：`composer.lock` 里的 `vendor/composer/platform_check.php` 要求 `PHP >= 8.4.1`，
装 8.2 会让后端直接 500（本地用 PHP 8.5 跑 `composer update` 时把下限写进了 lock 文件）。

宝塔「极速安装」的 PHP 8.4 预编译包**不含 fileinfo**，缺它后台上传藏品图会 `Class "finfo" not found` 崩溃。
安装脚本里也没有单独的 fileinfo 项，需手动编译一次（约 2 分钟）：

```bash
V=$(/www/server/php/84/bin/php -r "echo PHP_VERSION;")
cd /root && curl -fL -o php-$V.tar.gz https://www.php.net/distributions/php-$V.tar.gz
tar xf php-$V.tar.gz && cd php-$V/ext/fileinfo
/www/server/php/84/bin/phpize
./configure --with-php-config=/www/server/php/84/bin/php-config && make -j2 && make install
# FPM 与 CLI 用的是两个 ini，都要加
echo "extension=fileinfo" >> /www/server/php/84/etc/php.ini
echo "extension=fileinfo" >> /www/server/php/84/etc/php-cli.ini
/etc/init.d/php-fpm-84 restart
/www/server/php/84/bin/php -m | grep -i fileinfo
```

需要确认在位的扩展：`pdo_mysql` `mbstring` `openssl` `fileinfo` `gd` `curl` `zip` `bcmath`（8.4 极速包只缺 fileinfo）。

**装软件时不要把安装脚本的输出接管道**：宝塔的安装子进程会继承 stdout，`install_soft.sh | tail` 会看起来"卡死"。
改成 `install_soft.sh 1 install nginx 1.26 > /root/nginx_install.log 2>&1 &` 再读日志文件。
另外 `install_soft.sh` 的第一个参数：`1` = 极速安装（预编译包），`0` = 源码编译（慢，会去编 openssl）。

## 2. 传代码

服务器上 `git clone` 走 GitHub 可能慢，直接把本地目录打包上传更稳（ECS 入方向带宽不受那 1Mbps 限制，实测约 700KB/s）：

```bash
# 本地（Git Bash）：后端含 vendor，跳过服务器 composer install
cd /e/sinan
tar --exclude='sinan-nft-backend/runtime/*' \
    --exclude='sinan-nft-backend/.env' \
    --exclude='*/node_modules' --exclude='*/dist' \
    -czf /tmp/backend.tgz sinan-nft-backend
scp /tmp/backend.tgz root@<IP>:/www/wwwroot/
ssh root@<IP> 'cd /www/wwwroot && tar xzf backend.tgz && mv sinan-nft-backend sinan-backend && chown -R root:root sinan-backend'

# 两个前端产物
tar czf - -C sinan-art-source dist | ssh root@<IP> 'tar xzf - -C /www/wwwroot/sinan-h5 --strip-components=1 && chown -R www:www /www/wwwroot/sinan-h5'
tar czf - -C sinan-admin       dist | ssh root@<IP> 'tar xzf - -C /www/wwwroot/sinan-admin --strip-components=1 && chown -R www:www /www/wwwroot/sinan-admin'
```

**Windows 打包过去带 CRLF**，shell 脚本会报 `/usr/bin/env: 'bash\r': No such file or directory`：

```bash
cd /www/wwwroot/sinan-backend
for f in $(find . -name "*.sh" -not -path "./vendor/*"); do sed -i "s/\r$//" "$f"; done
file scripts/backup_db.sh   # 不应再出现 "with CRLF line terminators"
```

`database/` 是与后端平级的目录，不在 `sinan-nft-backend` 内，需单独上传一次：
`scp -r /e/sinan/database root@<IP>:/www/wwwroot/sinan-sql`

## 3. 建库导数据

```bash
mysql -uroot -p -e "CREATE DATABASE sinan_nft DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_general_ci;"

# 用合并版基线，一次导入。不要按 21 个拆分 SQL 手动跑 ——
# raffle_purchase_upgrade.sql 必须早于 raffle_admin_upgrade.sql，
# 顺序错会 1054 Unknown column 并静默吞掉整份文件剩余语句。
mysql -uroot -p sinan_nft < /www/wwwroot/sinan-sql/full_init.sql
# 期望：80 张表 / 982 字段 / 75 外键

# 运行时业务账号：只给 DML
mysql -uroot -p -e "
CREATE USER 'sinan_app'@'127.0.0.1' IDENTIFIED BY '<强密码>';
GRANT SELECT, INSERT, UPDATE, DELETE ON sinan_nft.* TO 'sinan_app'@'127.0.0.1';
FLUSH PRIVILEGES;"
```

MySQL 加固（`/etc/my.cnf` 的 `[mysqld]` 段，改前备份）：
`bind-address = 127.0.0.1`、`mysqlx-bind-address = 127.0.0.1`、`max_allowed_packet = 64M`、
`innodb_buffer_pool_size = 1G`（宝塔默认 256M，2C4G 机器偏小）。

**严禁执行 `database/dev-only/seed-dev.sql`**（开发联调数据）。
管理员账号不用它也有：`full_init.sql` 里已内置 `nft_admin_users` 的 `admin` 超管及完整 RBAC 权限（104 条），
但密码哈希是你本地开发时的，上线后必须重置。注意命令名是 `admin:reset-password`，账号是必填参数：

```bash
cd /www/wwwroot/sinan-backend
/www/server/php/84/bin/php think admin:reset-password admin
# 省略第二个参数会随机生成一个强密码并只打印一次；也可显式指定：... admin 'NewPass!123'
```

## 4. 生产配置

```bash
cd /www/wwwroot/sinan-backend
cp .env.example .env      # 再按 deploy/env.production.example 逐项改
```

改这几处，缺一不可（详见 `deploy/env.production.example` 顶部命令）：

- `APP_DEBUG = false`
- `APP_KEY` / `JWT.SECRET` / `JWT.ADMIN_SECRET` → 三个**互相独立**的 `openssl rand -hex 32`
- `HOSTPORT = 3306`（本地是 3308，别忘了改）
- `USERNAME = sinan_app` + 新密码
- `chmod 600 .env && chown www:www .env`（php-fpm 以 `www` 身份跑，`600 root:www` 是读不到的）

可写目录：

```bash
mkdir -p /www/wwwroot/sinan-backend/runtime /www/wwwroot/sinan-backend/public/uploads
chown -R www:www /www/wwwroot/sinan-backend/{runtime,public/uploads}
find /www/wwwroot/sinan-backend/runtime -type d -exec chmod 755 {} +
```

### 短信 / 支付的开关不在 .env

`.env` 里的 `SMS_MOCK` / `PAY_MOCK` 是早期遗留，**现在不生效**。真实开关在数据库：

| 开关 | 位置 | 说明 |
|---|---|---|
| 短信渠道 | `nft_sms_configs` 表 id=1 的 `provider` / `is_enabled` | 后台「系统配置 → 短信渠道」；`provider=mock, is_enabled=1` = 模拟发送 |
| 支付渠道 | `nft_payment_channels` 表 | 后台「系统配置 → 支付渠道」 |
| 图形验证码 | `nft_system_configs` 表 `captcha.enable`、`captcha.scenes` | 表名带 `nft_` 前缀，SQL 里别写错 |

mock 渠道只影响"发送"，**验证码仍是 bcrypt 落库、逐位比对**（`app/controller/Auth.php:160`）。
明文 `debugCode` 仅在 `provider=mock` **且** `APP_DEBUG=true` 时才回传（`SmsDispatchService.php:107`），
所以生产（debug=false）拿不到验证码 —— 不是"任意验证码可过"。
想在浏览器里测注册/改密：维护窗口内临时 `APP_DEBUG=true`，用返回值里的 `debugCode` 完成，测完立刻改回 false。
日常验收建议直接用密码登录（`Auth/login` 密码分支不需要短信）；本次已建好一个测试账号，账号密码见仓库外的
`E:\sinan-ops\server-access-20261007.md`（含各类口令，**不要**把这份文件放进仓库或提交）。

## 5. Nginx 站点

把 `deploy/nginx/` 三个文件传到 `/www/server/panel/vhost/nginx/`，`fastcgi_pass` 指向 `unix:/tmp/php-cgi-84.sock`：

```bash
nginx -t && nginx -s reload
```

三个站点的分工：`00-sinan-backend`（仅 `127.0.0.1:8081`，跑 ThinkPHP）、`10-sinan-h5`（80，静态 + `/api/` 原样反代）、
`20-sinan-admin`（8088，静态 + `/api/admin/*` → 后端 `/admin/*`，对应 Vite dev proxy 里的 `rewrite`）。

**本机自测别用 `http://127.0.0.1/`**：宝塔自带的 `phpfpm_status.conf` 里有 `listen 80; server_name 127.0.0.1;`，
会抢走这类请求并返回 nginx 默认 404，看起来像站点坏了。用公网 IP 或显式带 Host：

```bash
curl -i http://<IP>/api/collections/categories
curl -i -H "Host: <IP>" http://127.0.0.1/api/collections/categories
```

## 6. 定时任务（缺了会有静默故障）

`crontab -e` 追加两行。cron 环境里没有 PATH 也没有 `php` 软链，**必须写 PHP 绝对路径**：

```cron
* * * * * cd /www/wwwroot/sinan-backend && /www/server/php/84/bin/php think ScheduleDispatch >> runtime/schedule.log 2>&1
0 3 * * * /www/wwwroot/sinan-backend/scripts/backup_daily.sh >> /www/wwwroot/sinan-backend/runtime/backup.log 2>&1
```

`backup_daily.sh` 是本次新增的包装脚本（密码从 `/root/.sinan_app_pass` 读，不进 crontab）：

```bash
#!/usr/bin/env bash
export PATH=/www/server/mysql/bin:/usr/local/bin:/usr/bin:/bin
cd /www/wwwroot/sinan-backend || exit 1
PASS="$(head -1 /root/.sinan_app_pass)"
exec ./scripts/backup_db.sh -u sinan_app -p "$PASS" -k 7
```

`backup_db.sh` 已加 `--no-tablespaces`：DML-only 的 `sinan_app` 账号没有 PROCESS 权限，
不加会报 `Access denied; you need ... PROCESS privilege(s)` 并漏掉部分元信息。

验证 cron 真的在跑（等一分钟看日志有没有新行）：

```bash
crontab -l | grep -E "sinan|Schedule"
tail -3 /www/wwwroot/sinan-backend/runtime/schedule.log
systemctl is-active crond
```

备份脚本只存本机，务必再同步到 OSS 或异机，否则磁盘满了会把服务器一起带走。

## 7. 验收清单

- [x] `http://<IP>/` H5 首屏（index.html 200 / 585B）
- [x] `http://<IP>/api/collections/categories` → `{"code":0,...}` 真实数据
- [x] H5 密码登录 + `/api/wallet` 带 JWT 返回余额
- [x] `http://<IP>:8088/` 后台首页 + `/api/admin/auth/login` 发 token
- [x] 后台鉴权接口（`collectibles` `cms/categories` `chain/networks` `approvals` `market/listings`）
- [x] 定时上架 cron 每分钟落日志
- [x] 每日备份脚本手动跑通，产物 80 张表、末行 `Dump completed`
- [x] `http://<IP>:8081`、`/<IP>:888`、`<IP>:3306` 外网均不可达
- [x] `/.env`、`/runtime/`、`/api/admin/*`（在 80 端口上）全部 404
- [x] 后台上传图片：`POST /api/admin/upload/image`（字段 `file`，`biz` 白名单 collection/blindbox/marketing/content/misc/custom/**artifact**/**announcement**，≤5MB）→ 落盘 `public/uploads/<biz>/<YYYYMM>/<随机名>.jpg` → 前台可访问，`Content-Type: image/jpeg` + 30 天缓存
- [x] 后台数据看板真实渲染：登录响应下发 `roleCode=super_admin` 与 104 条权限码，看板显示"今日新增用户 1 / 累计用户 1"（即那个测试账号）
- [x] 宝塔面板 `https://<IP>:42525/<入口路径>` 外网可达（带浏览器 UA 返回 200/30KB；无 UA 返回 404 是刻意的挡扫描器）
- [x] **首页轮播图全链路**（内容 → 轮播管理）：上传图 → 新增 → C 端立即显示 → 编辑/启停/删除均可用；删除是软删，「系统 → 回收站 → banners」可恢复或彻底清除
- [x] **浏览器外壳不再涂红**（2026-10-08）：`http://<IP>/` 的 `meta[theme-color]` 与运行时值均为 `#F7F8FA`（跟页面背景色），`--color-primary` 仍为 `#C00000`；主 JS 换名后 200，首页/轮播渲染正常。详见 7.2
- [x] **发售时间在 iOS 上不再显示成「发售中」**（2026-10-08）：`toTs` 统一时间解析后重新构建上线，主 JS 变为 `index-1-OjHXhR.js`；Chromium 侧回归：卡片玻璃层仍显示「发售时间：2026.10.09 00:00」，控制台无报错。详见 7.3
- [x] **购买拦截时机**（2026-10-09）：实名在点购买/进支付页时拦，未设操作密码在要弹键盘时拦；后端 `Orders::create` 实名校验前置。主 JS 现为 `index-D3PaUYPO.js`（含 `purchaseGate`，`/user/realname`、`/auth/op-pwd` 两个跳转点在产物里可查到）。线上用未实名账号 `U000002` 实测：错密码与正确交易密码都返回 `1001 请先完成实名认证`，且未产生订单行。前端时机由 `tests/purchase-interception.spec.js` 锁住。详见 7.4
- [x] **空状态图换成铜鼎实物图**（2026-10-09，同日先换过玉质 logo、后换成铜鼎）：`/images/tab/empty-ding.png`
  （400×400 透明 PNG，50,423B）+ `?v=20261009` 破缓存已上线，主 JS 现为 `index-BnaJErg3.js`。
  市场页空列表实测：图片直接落在 `#F7F8FA` 底色上，无白方块、脚下投影已去净、轮廓无白晕，控制台干净。详见 7.5
- [x] **活动市场 / 自由市场分栏 + 推荐分类**（2026-10-09）：迁移 `20261009_market_type_and_recommend.sql` 已在生产执行（`market_type` / `is_market_recommended` 两列 + `idx_market_type` + 开关行 `market_recommend_tab_enabled=1`，存量藏品 `大绵羊` 归活动市场）。
  线上实测：`/api/market/collections` 按 tab 分别带 `marketType=activity|free`、点推荐带 `recommend=1`；「推荐」胶囊在开关开时排在「全部」左侧、切到自由市场即消失；把 `market_recommend_tab_enabled` 改 0 后胶囊立即不见（验完已改回 1）。
  初版仍沿用「有在售挂单才进市场」的老门槛，所以当时两个市场是空列表（`nft_resale_listings` 0 行），详见 7.6
- [x] **市场展示门槛改为「只看寄售开关」**（2026-10-09 二次 + 三次调整，最终口径）：藏品只要开了后台「寄售开关」就进市场，
  **不看发售状态**（未发售/发售中/已售罄/已下架都算）、也不要求有人挂单；无挂单时后端 `price=null`、C 端卡片显示「暂无寄售」，
  排序用 `COALESCE(挂单价, 发售价)`。
  线上实测：`is_resaleable` 0→1 后 `/api/market/collections?marketType=activity` 立刻返回大绵羊（`price:null, issuePrice:99, ordersCount:0`）；
  再把状态依次改成 `upcoming` / `off` / `onsale`，三次都仍能查到（验完已恢复 `onsale`），开关关回 0 则列表为空 —— 证明状态条件确已移除；
  浏览器里卡片右下角是「暂无寄售」；点进 `/resale/1` 显示「当前暂无寄售挂单，可先关注该藏品」，原来的死按钮「快捷购买」已隐藏；
  `marketType=free` 仍为空（没有藏品归属自由市场）。H5 主 JS 现为 `index-AXvyVO3J.js`。详见 7.6
- [x] **首页轮播「推荐藏品」位**（2026-10-09）：藏品列表「市场 / 推荐」列变成两枚小开关（推=市场推荐分类、播=首页轮播位，互不依赖），
  开「播」即把藏品封面插到首页轮播最前面、左上角「推荐藏品」角标、点击进藏品详情。迁移 `20261009_home_carousel.sql` 已在生产执行
  （新列 `is_home_carousel_recommended`）。线上实测：`/api/banners` 返回 `[collectible(大绵羊), banner]`，首页 2 张起自动轮播、
  角标 computed 值 `linear-gradient(135deg, rgb(192,0,0), rgb(139,0,0))` 白字 74×27 在左上 (23,23)，点首条跳到 `#/resale/1`
  （市场里的「资产交易」页，文案「当前暂无寄售挂单，可先关注该藏品」），点普通轮播图不跳；控制台无报错。
  点击落点这一处改过一轮：初版跳首发详情 `/collection/:id`，你要求改成跳市场，现为 `/resale/:id`。
  H5 主 JS 现为 `index-CJBW2nxQ.js`，后台主 JS 现为 `index-BdC6Aueu.js`。详见 7.7
- [x] **关注态刷新不丢 + 「我的关注」显示全部已关注藏品**（2026-10-09）：`market/collections` 挂 `OptionalJwtAuth`、
  前端关注全集改由 `/user/favorites` 权威提供（市场列表只增不删）、「我的关注」换成 `followedCollections`。
  线上实测（测试号 `13900000001`，带真令牌）：`/api/market/collections?marketType=all` 里大绵羊
  `isFavorite:true`，同一请求**不带令牌**为 `false` —— 证明中间件生效；`/api/user/favorites` 返回
  `id=1 name=大绵羊 price=99 marketType=activity`。浏览器（新构建 `index-CHYYfuBj.js`）：登录后市场页心形是红的 →
  **整页刷新两次仍是红的** → 「我的关注」列出该藏品（右下角「暂无寄售」，与 7.6 口径一致）；
  在「我的关注」里点心形取消 → 卡片当场消失并显示空态，回活动市场再点回来 → toast「已关注」且刷新后仍在列表里；控制台干净。
  后端两个文件已备份 `.bak-20261009d`。详见 7.8
- [x] **进市场默认落在「推荐」胶囊**（2026-10-10）：后台「推荐」总开关开着且当前是活动市场 → 一进市场默认停在「推荐」；
  开关关着 / 自由市场 / 「我的关注」→ 默认「全部」。**推荐位是空的就自动退回「全部」**（用户选定，不放空态糊用户脸）。
  只改前端两个文件（`stores/collection.js` 的 `setMarketType`、`Market.vue` 的挂载时机），后端和后台零改动。
  线上实测（新构建 `index-Bh8iftAQ.js`）：开关开 + 无推荐藏品时连发两求 `marketType=activity&recommend=1` →
  `recommend=0`，胶囊渲染成「推荐 / **全部**（高亮）/ 水墨 / 国潮」，列表有大绵羊、无空态；
  把 `id=1` 打上 `is_market_recommended=1` 后**只发一条** `recommend=1`，高亮落在「推荐」上；
  切「自由市场」→ 高亮「全部」+「未找到相关藏品」（本来就没藏品归自由市场，符合 7.6），切「我的关注」→「全部」，
  切回「活动市场」→ 又停「推荐」。验完已把 `is_market_recommended` 改回 0，其余数据未动。详见 7.6
- [ ] 手机真机再过一遍这两道拦截：未实名的号点「立即购买」应直接落到实名认证页，而不是输完密码才报错
- [ ] 后台设一个"1 分钟后定时上架" → 等 2 分钟看是否自动生效（cron 已确认每分钟跑并落日志，但这一步要先有一条藏品）
- [ ] 你自己走一遍带图形验证码的登录（本轮验收时我用 SQL 临时把 `captcha.enable` 关掉过，验完已改回 1）
- [x] 真实首屏耗时（1Mbps 固定带宽）：C 端主 JS 462KB ≈ 2.2s；后台主 JS 1.33MB ≈ 8.6s，加 CSS 366KB ≈ 2.8s，合计约 11s

### 7.1 轮播图的行为约定（2026-10-08 改造，改代码前先看这段）

| 场景 | 期望行为 | 实现位置 |
|---|---|---|
| 后台只上架 **1 张** | 首页**静止**：不自动播、不显示指示点、不响应左右滑动、DOM 里只有 1 个 `<img>` | `sinan-art-source/src/views/Home.vue` 的 `looped` 计算属性（`n > 1` 才为真），`startAuto()`、`onTouchStart()`、`renderSlides`、`home-hero__dots` 全部按它短路 |
| 上架 **≥2 张** | 3.5 秒/张无缝轮播 + 手势拖动 + 指示点 | 同上，`slides.length > 1` 分支 |
| 图片尺寸/比例 | **不改变轮播区大小**。`.home-hero__bg` 是 `position:absolute; inset:0`，图片只在这个固定层里 `object-fit:cover; object-position:center` 裁切铺满 | `Home.vue` 的 `.home-hero` / `.home-hero__slide` 样式；实测 2400×900 与 200×120 同屏时区域恒为 409×384 |
| 接口返回 0 条 | 回落到本地兜底三图（`/images/hero/slide-1~3.jpg`）并恢复轮播 | `fetchBanners()` 的 `if (list.length)` 分支 |
| 后台录入方式 | **上传文件**（不是填 URL，也不再限制三张内置图），排序、备注标题（选填，C 端不显示）、上下架；每行有「编辑 / 删除」 | `sinan-admin/src/views/content/Banners.vue` + `api/index.js` 的 `saveBanner/toggleBanner/deleteBanner` |

踩过的坑（别改回去）：
1. `nft_banners` **没有 link 列**，后台早先那列「跳转链接」是纯摆设（永远 `-`），已删。
2. 后台 `/cms/banners` 返回的是 **snake_case**（`sort_order`/`is_active`），而 C 端接口返回 camelCase。
   `api/index.js` 里 `getBanners()` 原先只取 `b.sortOrder`/`b.isActive` → 列表恒显示「#0 / 已下架」，
   启停开关也因此永远算成"当前是下架"。现已 `b.sort_order ?? b.sortOrder` 双向兜住。
3. `UploadController::BIZ_DIRS` 与前端调用的 `biz` 曾不一致：文物展馆传 `artifact`、富文本传 `announcement`
   会一律被 4220 拒掉。已把两个值加进白名单；`UploadCleanupService` 同步加了 `artifact`，
   但**故意不加 `announcement`**——公告正文里的 `<img>` 不在清理服务的引用扫描列里，纳进来会把在用的正文图误判成零引用。

### 7.2 浏览器外壳配色约定（2026-10-08 改造）

现象：iPhone Safari 打开 `http://<IP>/` 时，顶部状态条（时间那一横条）和底部浏览器工具栏区是**一整块深红**，
底部那条半透明导航栏被映成粉色。排查结论：**页面里没有任何红色大面积元素**（遍历全站节点只命中「去看看」按钮渐变和藏品卡红标签，两者 `backgroundColor` 均为透明）。红的是 `theme-color`。

`<meta name="theme-color">` 只给**浏览器外壳**上色（状态条 + 底部工具栏 + 下拉回弹区），跟页面内容无关。
原先它写死成品牌主题色 `#C00000`（司南红），而页面是浅灰 `#F7F8FA`，于是"墙浅、窗框深红"。

现在改成**跟随页面背景色**：

| 位置 | 值 / 逻辑 |
|---|---|
| `sinan-art-source/index.html` | `content="#F7F8FA"`，必须与 `styles/global.scss:11` 的 `--color-bg` 兜底值保持一致，否则首屏会先闪一条别的颜色 |
| `src/stores/site.js` `apply()` | `setAttribute('content', this.bgColor \|\| DEFAULTS.bgColor)`，即「站点装修 → 页面背景色」；品牌红仍通过 `--color-primary` 作用在按钮/文字/指示点上，**没有丢色** |

线上实测（`/api/config` 返回 `themeColor:#C00000`、`bgColor:#F7F8FA`）：
`meta[theme-color]=#F7F8FA`、`--color-bg=#F7F8FA`、`--color-primary=#C00000`、`body` 背景 `rgb(247,248,250)`，首页与轮播渲染正常。

坑（别改回去）：
1. **别把 `theme-color` 改回 `themeColor`（品牌色）**。想要红色顶栏的品牌感，正确做法是给 `.home-hero` 那块区域自己做视觉，而不是涂浏览器外壳——外壳一旦涂红，`rgba(255,255,255,0.7)` + `backdrop-filter` 的毛玻璃导航栏必然透成粉色。
2. **在后台把页面背景改成深色**（`bgColor` 为深灰/黑）时，浅色 `theme-color` 会让 iOS 用**白色**图标压在深色条上（浏览器按亮度自动反相状态条文字），出现"白字压深底"反而正常的情况，但页面正文是深色字，可读性要人眼再过一遍；若真要上深色背景，需要给 `<meta name="theme-color">` 加 `media="(prefers-color-scheme: dark)"` 第二条，并在 `apply()` 里同步 `document.documentElement.style.colorScheme`。目前**未实现**，属已知遗留。
3. 这个改动**只影响 iPhone/安卓浏览器外壳那一圈**，不影响电脑端 Chrome（PC 端 theme-color 不上色），所以本地/PC 验收看不出来，必须真机或 iPhone 模拟器验。

### 7.3 时间字符串解析约定（2026-10-08 修，iOS 与电脑显示不一致的根因）

现象：同一件藏品（`sale_time = 2026-10-09 00:00:00`），电脑 Chrome 首页卡片显示「发售时间：2026.10.09 00:00」，
iPhone Safari 显示「发售中」。

根因：后端 `/api/collections/featured` 返回的是 MySQL `datetime(3)` 原样字符串 `"2026-10-09 00:00:00.000"`。
`new Date(非 ISO 字符串)` 的行为**没有规范保证**：Chrome 能猜出来，iOS Safari 返回 Invalid Date，
`getTime()` 为 `NaN` → `saleTime` 为假值 → `getSaleStatus()` 里 `if (item.saleTime && now < item.saleTime)` 短路，
一路落到 `return 'selling'`。排除法可确认：手机首页日历组件显示的日期是 10/8（本机时钟的"今天"），
即手机本地时间确实在开售点之前，所以不可能是"真的已经开售"，只能是解析失败。

修法：新增 `src/utils/datetime.js` 的 `toTs()`，**不依赖引擎的字符串猜测**——正则取年月日时分秒，
再用 `new Date(y, m-1, d, h, mi, s)` 按本地时区构造（所有引擎一致）；带 `Z`/`±hh:mm` 的 ISO 串仍走原生解析；
秒/毫秒时间戳、纯数字串、解析失败统一有确定行为（失败返回 `0`，绝不把 `NaN` 漏给调用方）。

已替换的 6 处重复实现（都曾是同一个 iOS bug）：
`stores/collection.js`（发售状态/倒计时）、`stores/order.js`（订单与转赠时间）、`stores/user.js`（`boughtAt`）、
`stores/activity.js`（`isEnded`：iOS 上限购活动永远判为"未结束"）、`views/Lottery.vue`（`fmtTime`）、
`views/RaffleDetail.vue`（报名截止倒计时）。

规则：**以后任何"把后端时间变成可比较的数字"，一律 `import { toTs } from '@/utils/datetime'`，不要再写 `new Date(字符串)`。**
本地跑 `node --input-type=module -e "…"` 覆盖了 14 个用例（含 `.000`、无秒、仅日期、斜杠、ISO T/Z/±偏移、秒与毫秒、null/空/垃圾值），全通过。

已知同类残留（未改）：`sinan-admin/src/views/content/Announcements.vue` 两处 `publishTime.replace(/-/g,'/')`，
后台只在桌面 Chrome/Edge 用，解析得动，暂不改；若哪天要在 iPad 上运营后台，需一并换成 `toTs`。

### 7.4 购买链路的拦截时机约定（2026-10-09 改）

产品口径（改代码前先看这段）：

- **实名认证** → 在「点击购买」时拦，直接把用户送去实名认证页；
- **未设置交易（操作）密码** → 只在「要输密码的那一步」拦，送去 `#/auth/op-pwd`。

改之前两个闸门都堆在最后：填完 6 位支付密码提交，才被后端 `Orders::create` 打回
「请先设置交易密码 / 请先完成实名认证」。后果有两层——用户白输一遍密码；
而且键盘既然弹出来了，用户会理解为「我资格没问题，只是密码不对」。

落点（`sinan-art-source/src/utils/purchaseGate.js`，两个函数各带一个模块级连点锁）：

| 时机 | 位置 | 调用 |
|---|---|---|
| 点「立即购买」 | `CollectionDetail.onBuy`、`Resale.onQuickBuy/goPay`、`ResaleOrder.onBuy` | `ensureRealname()` |
| 直接进支付页（分享链接、批量购买回跳） | `Pay.vue` `onMounted`，在拉藏品详情之前 | `ensureRealname()` |
| 点「确认支付」要弹密码键盘 | `Pay.vue` `onConfirmClick` | `ensureTradePassword()` |
| 寄售 / 转赠 / 开盲盒进入密码步骤 | `CollectionDetail.onConsign/onTransferNext/onOpenBlindbox` | `ensureTradePassword()` |

后端同步改了口径：`Orders::create` 原先「先校验交易密码、再校验实名」，
现在实名前置（且合并成一次 `users` 查询）。这样即使前端缓存过时没拦住，
服务端报错顺序也和 UI 一致，不会出现「未实名的人先收到密码错误」。
`Resale::batchBuy` 本来就是实名在前，未动。

两条容易踩的规则：

1. `userInfo.isRealName / hasTransactionPassword` 只有 `fetchUserInfo()`（即 `refreshQuietly()`）会更新，
   登录接口不返回交易密码状态。所以两个闸门在**缓存为 false 时会先跟服务端确认一次再拦**，
   否则「后台刚过审」「刚设完操作密码」都会被旧缓存误拦。
2. 缓存为 true 时直接放行、不打接口（点购买不该多一次网络往返）。
   后台撤销实名/重置交易密码导致的"缓存说已好、服务端其实没好"，由后端兜底，
   报错文案与前端一致。

回归测试：`tests/purchase-gate.spec.js`（8 例，闸门本身的判定与文案）+
`tests/purchase-interception.spec.js`（7 例，挂载 `Pay.vue`/`CollectionDetail.vue` 验时机：
未实名进不了支付页、未设密码时键盘不弹）。跑法 `cd sinan-art-source && npx vitest run`（那轮全量 39 例；
截至 2026-10-10 全量已长到 **82 例 / 10 个文件**，后续看数别拿这个 39 当基线）。

### 7.5 空状态图的来龙去脉（2026-10-09 换图）

组件只有一个：`sinan-art-source/src/components/AppEmpty.vue`，全站 14 个视图（市场三个 tab、抽奖、抽签、
签到、我的藏品/订单/钱包/社群/邀请、商城…）都用它，所以换图只改这一处 `<img>` 的 `src`。

- 现用图：`public/images/tab/empty-ding.png` = **铜鼎实物渲染图**，400×400、调色板 PNG、**50,423B**，白底与脚下投影都已抠掉。
  组件里写的是 `src="/images/tab/empty-ding.png?v=20261009"`，`?v=` 是破缓存用的（原因见本节末尾）。
- 历史版本：同一天先上过一版**玉质鼎 logo**（31,896B，`cutout-white-bg.py` 产出），已被铜鼎版替换。
- 旧图：`public/images/tab/empty-carton.png`（纸箱，5,923B）**已不再被引用**，文件还在，确认不要了可以删。

为什么尺寸是 400 而不是 120 的两倍：`postcss.config.js` 用 `postcss-px-to-viewport`（设计稿 375），
组件里写的 `width:120px` 编译成 `32vw` —— 手机上 120\~137 CSS px，电脑窗口越宽图越大
（实测 531px 宽的窗口里就是 170 CSS px，×DPR2 = 340 物理像素）。所以出 400 才在 PC 上不糊。

抠图脚本两个（本机没有 ImageMagick / ffmpeg，Python 只有 PIL 7.2、无 numpy，纯 Python 逐像素也只要 1\~2 秒），
**按源图有没有投影选**：

| 脚本 | 适用 | 原理 |
|---|---|---|
| `deploy/tools/cutout-white-bg.py` | 平面 logo、白底、**无投影**（玉鼎 logo 那张） | 背景实测 `(254,254,254)` 准纯白，主体最浅处离白也有 `d≈85`，中间 `15~80` 只剩约 1700 个像素（正好是 1\~2px 抗锯齿边），所以直接按「离白多远」算 alpha 斜坡就够干净，不用洪水填充；logo 内部的白色镂空本身就是背景色，会跟着一起透掉（这是对的，它是负空间） |
| `deploy/tools/cutout-photo-shadow.py` | 实物渲染图、**脚下有投影**（现在这张铜鼎） | 见下面三段 |

铜鼎这张为什么不能用「离白多远」：它脚下的接触阴影最暗处离白 `d≈204`，和器物中段一样深；鼎身内部又有接近纯白的
高光（`d` 低到 30）。全局阈值会**把阴影当主体留下、又把高光打出洞**。所以改成从画面四边做连通生长
（BFS，相邻像素色距 `< STEP=26` 就继续走），只把「与四边连通」的浅色区判为背景 —— 孤立的高光天然保住，
阴影是平滑渐变（每像素 `d` 只变 2\~4）能一路走到器物边缘，而器物轮廓是 1px 硬跳变（实测单像素 `d` 从 2 跳到 136 再到 335），正好停住。

第一版还是翻了一次车：两个鼎耳的尖部被吃掉一块。原因是渲染在耳尖给出了接近纯白的高光，和背景连成一片，
渐变生长顺着就漏进去了。修法是按区域分档（脚本里的 `SPLIT=0.66`）：

1. **分界线以上**只认「离白 `< 45`」的纯白为背景，不给渐变留活口 —— 画面上半部的背景本来就是干净纯白；
2. **分界线以下**才允许沿渐变生长，把投影吃掉 —— 投影只出现在器物脚下那一条带子里；
3. 再配 `D_CAP=262` 兜底：阴影最暗 204，器物暗部 300+，中间有安全间隙，长不进鼎身。

调完用「把被删掉的像素标红叠回原图」这一招验收（脚本第 5 个参数会导出整张工作分辨率的蒙版），
一眼能看出漏在哪里；只看透明图本身是看不出"少了一块"的。

后面几步两个脚本共用：alpha 硬边 `GaussianBlur(1.4)` 羽化 → 边缘像素按 `rgb=(observed-(1-a)·254)/a`
**去白边**（不解混的话叠在深色上会有一圈白晕）→ 裁到主体 bbox + 3% pad 补成正方形（组件是 `object-fit:contain`）
→ LANCZOS 缩到 400 → `quantize(256, FASTOCTREE)`（PIL 对 RGBA 只接受这个方法；194KB → 50KB，
量化误差最大 30/255，集中在红色石纹上，实际显示尺寸下看不出来）。

**缓存坑（重要）**：`index.html` 里的 JS 带 hash，换版本自然失效；但 `public/` 下的图片**路径不变、nginx 也不发
`Cache-Control`**，走的是启发式缓存，老访客会一直看到旧图 —— 本轮实测撞到两次（第二次连 `index.html` 本身都是旧的，
得用 `/?t=时间戳` 才能拿到新构建）。所以组件里那个 `?v=` 不要删，**以后换这张图顺手把 `?v=` 改一次**。
更彻底的做法是给 `/images/` 也加 `Cache-Control` 或改用带 hash 的文件名，暂未做。

### 7.6 活动市场 / 自由市场与「推荐」的口径（2026-10-09 改造，改代码前先看这段）

**一句话**：藏品自己挂「归属市场」的牌子，两个市场各看各的；「推荐」是活动市场里的一枚分类胶囊，能不能出现由后台一个总开关决定，里面有哪几件由后台逐件「上推荐」决定。

改造前的实情（别被 UI 骗了）：顶部「活动市场 / 自由市场」两个 tab 点进去是**同一份数据**，
都来自 `GET /api/market/collections`，后端没有任何市场维度，切 tab 连请求都不发（列表是首次进市场页那一次拉的）。
全仓 + 91 张表里 `market_type` / `activity_market` / `free_market` 零命中。所以这次是**先造区分，再让 UI 用上区分**。

| 事项 | 约定 | 位置 |
|---|---|---|
| 归属市场 | `nft_collectibles.market_type ENUM('activity','free') DEFAULT 'activity'`，改这一列 = 移动市场；**挂单、价格、库存都不动** | 迁移 `sinan-nft-backend/migrations/20261009_market_type_and_recommend.sql`；后台「藏品管理 → 市场 / 推荐」列 + 行尾「移至…」按钮（`CollectibleController::marketMove`） |
| 存量归属 | 全部活动市场（列默认值直接兜住，无需回填） | 同上 |
| 上推荐 | `is_market_recommended=1`；**只有活动市场的藏品能上**，移到自由市场时后端自动清零 | `CollectibleController::marketRecommend`；后台同一列的开关（非活动市场时置灰） |
| 推荐胶囊显隐 | `nft_system_configs.market_recommend_tab_enabled`：1 显示、0 不显示（行不存在时按 0 处理）；且只在活动市场 tab 出现 | 后台「系统 → 全局参数 → 寄售市场 → 活动市场「推荐」分类」布尔开关；C 端 `Market.vue` 的 `showRecommendPill` |
| 价格口径 | 两个市场、推荐分类都一样：**价格 = 该藏品在售寄售挂单的最低价**；当前无人挂单时后端返回 `price=null` + `issuePrice`（发售价），C 端卡片显示「暂无寄售」不出价格 | `Collections::market()`；C 端 `MarketCard.vue` / `ResaleItem.vue` 的 `hasListing` |
| 进市场的门槛 | **只看后台那枚「寄售开关」（`is_resaleable`）**：开就进市场。不看发售状态（`upcoming`/`onsale`/`soldout`/`off` 都能进），也不要求有人挂单；软删除除外（2026-10-09 定稿） | `Collections::market()` 的 `where('c.is_resaleable', 1)` |
| 「我的关注」 | 关注的是藏品不是市场，所以传 `marketType=all` 跨两个市场看 | `Market.vue` 的 `MARKET_OF_TAB` |
| 进市场的默认落点 | **开关开 + 活动市场 → 默认停在「推荐」；推荐位查空自动退回「全部」**。开关关 / 自由市场 / 我的关注 → 默认「全部」 | `stores/collection.js` 的 `setMarketType`；`Market.vue` 的 `onMounted`（等 `site.loaded` 才决定） |

**默认落点的细则（2026-10-10 追加，进市场首屏逻辑在这里）**：需求是「推荐 tab 开关打开就默认推荐，没打开就默认全部」。
生产上开关是开着的却**一件推荐藏品都没有**，严格按开关会让用户一进市场看到空列表，
所以口径定为「**空了就退回「全部」**」：

- 判定只发生在**进市场 / 切顶部 tab** 这一刻（`setMarketType`）。用户手动点分类、点搜索、点排序
  都不会再触发二次拉取，不会把人从「推荐」上弹走。
- 退回 = 把 `marketRecommend` 置回 `false` 再拉一次全量。所以推荐位为空时**多一次请求**，这是预期成本。
- 开关关着时**不会**多发一条 `recommend=1`（直接 `recommend=0` 一次拉完）。
- **时序坑**：`main.js` 里 `useSiteStore().init()` 没有 `await`，开关值 `marketRecommendTabEnabled`
  又不进 localStorage 缓存，冷启动时它可能**晚于市场页挂载**才到位 —— 早先的版本会在这一刻就把默认落点定死成「全部」，
  表现是"开关明明开着，首屏却不默认推荐，刷新一下才生效"。现解法是 `Market.vue` 的 `onMounted` 里
  先看 `site.loaded`，没到位就挂一次性 `watch` 等它，到位后再 `setMarketType`；`onBeforeUnmount` 里把没触发的 watch 断掉。
  **别把 `setMarketType` 挪回挂载即调用**，也别拿"给站点配置加全局开关 / 把 `init()` 改成 await"来绕 ——
  那会拖慢整个 App 的首屏。

**门槛口径变更（2026-10-09 二次 + 三次调整，改市场列表前必读）**：
初版沿用老门槛 `where('mp.orders_count','>',0)`（没有在售挂单就不进市场），结果两个市场都是空列表，
运营侧的理解是「后台把寄售开关打开，藏品就该出现在市场里」。第二次改造保留了「已发售过」这个附加条件，
用户再次否掉：**寄售开关就是唯一开关**。最终落地：

- 进市场条件 = `is_resaleable=1`，**仅此一条**（软删除的除外）。未发售、已下架的藏品只要开着开关也会露出——
  这是用户明确要的口径，别再拿发售状态去"补"一层。
- 价格 = 在售挂单最低价；**一件挂单都没有时 `price=null`**，C 端卡片显示「暂无寄售」而不是 `¥0`。
- 排序 = `COALESCE(mp.min_price, c.price)`（无挂单的用发售价参与排序，空价格不参与比较，避免 `NaN` 乱序）。
- 后端同时新增返回字段 `issuePrice`（发售价，仅作排序/兜底参照，卡片不展示）。
- 「我的关注」也走同一门槛（`marketType=all`），所以藏品被关掉寄售开关后会从关注列表消失 —— 这是有意的口径统一。
- C 端点进这类藏品 → `/resale/:id` 的空态文案改为「当前暂无寄售挂单，可先关注（或挂求购）」，
  并按藏品的求购开关决定是否提「挂求购」；同时**无挂单时隐藏底部「快捷购买 / 批量购买」按钮**（原来是点了没反应的死按钮）。

所以「上了推荐能不能看见」现在只取决于一件事：**该藏品的寄售开关是否打开**。
后台两处已按此提示：上推荐确认框在开关关着时会明确警告「上推荐也看不到」，寄售弹窗补了一句
「开启后该藏品就会出现在 C 端市场列表（不看发售状态，也无需等用户挂单）」。

两个坑（别改回去）：
1. **别拿 `featured` 当市场推荐**。`nft_collectibles.featured` 的语义是「首页推荐位」，
   `collections/featured` 接口和 `Edit.vue` 的首页推荐位开关都在用它，混用会把首页也改了。
2. **`market_type` 是枚举，非法值会静默查空**。`Collections::market()` 里做了白名单兜底
   （非法值一律按 `activity`），后台列表筛选同理；直接在 URL 上敲 `marketType=xxx` 是查不到东西的。
3. **别再给市场列表加发售状态条件**。`status` 管的是"一级发售"（能不能买新品），
   `is_resaleable` 管的才是"进不进二级市场"；用户已两次明确否掉把两者叠起来当门槛。

验收用命令（不改数据，只看请求）：
```bash
curl 'http://<IP>/api/market/collections?marketType=free&recommend=0'   # 自由市场
curl 'http://<IP>/api/config' | grep -o 'marketRecommendTabEnabled[^,]*' # 开关现值
# 门槛验证：把某藏品的寄售开关关掉 → 列表里应消失；打开 → 立刻出现且 price=null（卡片「暂无寄售」）
ssh root@<IP> 'MYSQL_PWD=$(cat /root/.mysql_root_pass) mysql -uroot sinan_nft \
  -e "UPDATE nft_collectibles SET is_resaleable=0 WHERE id=1"'
# 默认落点验证：开关开 + 没有推荐藏品时，H5 首屏应是「推荐」两条请求（recommend=1 查空 → recommend=0）且高亮在「全部」；
# 打上一件推荐后就只剩 recommend=1 一条、高亮在「推荐」（验完记得改回 0）
curl 'http://<IP>/api/market/collections?marketType=activity&recommend=1'
ssh root@<IP> 'MYSQL_PWD=$(cat /root/.mysql_root_pass) mysql -uroot sinan_nft \
  -e "UPDATE nft_collectibles SET is_market_recommended=1 WHERE id=1"'
```
测试覆盖：C 端 `sinan-art-source/tests/market-split.spec.js`（19 条：默认拉活动市场、切 tab 重拉、
`all` 跨市场、自由市场驳回推荐态、胶囊显隐与顺序、点推荐/点分类互斥，
以及新门槛的 3 条：有挂单取最低价、无挂单 `price=''`+`listingCount=0`、无挂单按发售价排序不出 `NaN`，
外加 2026-10-10 默认落点的 7 条：开关开且有推荐只发一条 `recommend=1`、推荐查空退成 `[1,0]` 且高亮落「全部」、
开关关只发 `[0]` 不多发、自由市场/我的关注永不默认推荐、页面级高亮胶囊文案、
**配置晚到**时挂载不发请求直到 `loaded` 翻真才按开关决定）；
`tests/market-card.spec.js`（9 条，含卡片/列表两种视图的「暂无寄售」渲染与仍可点进详情）；
后台 `sinan-admin/tests/collectible-api.spec.js` 的「市场归属与推荐」6 条（字段规整、筛选透传、三个新端点的参数，
含 2026-10-09 追加的首页轮播「播」两条）。

### 7.7 首页轮播「推荐藏品」位（2026-10-09 新增，改代码前先看这段）

需求：后台每件藏品的推荐开关拆成两枚独立开关，其中一枚控制它是否出现在 **H5 首页轮播**，
图上带「推荐藏品」角标，点击进该藏品详情页。

| 约定 | 落地 |
|---|---|
| 两枚开关互相独立 | `is_market_recommended`（推=活动市场「推荐」分类）与 `is_home_carousel_recommended`（播=首页轮播位）是两列，可单开、可都开；把藏品移到自由市场只清「推」，不动「播」 |
| 进轮播的门槛 | **只看「播」这一枚开关** + 未软删 + 有封面图。不看发售状态、不看寄售开关，与市场口径一致（见 7.6 坑 3） |
| 顺序 | 推荐藏品排在普通轮播图**前面**；藏品之间按 `id DESC`（新上架优先）。`nft_collectibles` 没有 `sort_order` 列，别以为能拖拽排序 |
| 用图 | 直接用藏品封面 `nft_collectibles.image`，**不另设轮播专用图**（用户明确否决了另传一张的方案） |
| 点击 | 藏品条目 `router.push('/resale/:id')`，**落点是市场里该藏品的寄售页**（与 `MarketCard` 同一入口），不是首发详情 `/collection/:id`；普通轮播图仍**不可点**（`nft_banners` 没有 link 列，见 7.1 坑 1） |
| 张数口径 | 藏品条目与普通图**合并计张数**：合计 1 张静止、≥2 张才自动轮播（沿用 7.1 的 `looped` 规则） |
| 全局开关 | **没有**，也不该加：每枚单品开关已能完全控制（全关 = 只剩轮播管理的图） |

实现位置：
- 迁移 `sinan-nft-backend/migrations/20261009_home_carousel.sql`（幂等加列），`database/full_init.sql` 的表定义同步。
- C 端 `app/controller/Content.php::banners()`：先查 `nft_banners`（`type:'banner'`），再查开了「播」的藏品（`type:'collectible'` + `collectibleId`），`array_merge(藏品, 图)` 返回**仍是数组**、每项仍有 `image` —— 老前端拿到只是少了角标和跳转，不会白屏。
- 后台 `app/admin/controller/CollectibleController.php::homeCarousel()` + `app/admin/route/app.php` 的 `POST :id/home-carousel`（复用 `collectible:manage` 权限码，不新增权限行），写审计 `collectible/home_carousel`。
- 后台 UI `sinan-admin/src/views/collectible/Index.vue`「市场 / 推荐」列两枚 `inline-prompt` 小开关（与旁边「赠/售」同风格）+ `api/index.js` 的 `toggleCollectibleHomeCarousel`；`adaptCollectible` 必须显式加 `isHomeCarouselRecommended`（该函数是白名单式映射，漏一项就是 `undefined`）。
- H5 `src/views/Home.vue`：`slides` 由**字符串数组改成对象数组** `{ image, type, collectibleId }`；`.home-hero__slide` 外包一层 `.home-hero__item`（`position:relative`）承载角标与点击；`tapSuppressed` 标记横向拖动过 → 吞掉浏览器补发的 click，轻点仍有效。
- 运营提示：`sinan-admin/src/views/content/Banners.vue` 顶部说明补了「播开关与这里的张数合并计算」。

验收命令：
```bash
curl 'http://<IP>/api/banners'    # 开了「播」的藏品应排在数组最前，type=collectible
# 打开/关闭某件藏品的轮播位（后台点两下也行）
ssh root@<IP> 'MYSQL_PWD=$(cat /root/.mysql_root_pass) mysql -uroot sinan_nft \
  -e "UPDATE nft_collectibles SET is_home_carousel_recommended=1 WHERE id=1"'
```
测试覆盖：H5 `sinan-art-source/tests/home-carousel.spec.js`（4 条：藏品排最前且带角标、点藏品跳 `/resale/:id`（市场寄售页）而点普通图不跳、
只有一张时不轮播但角标仍在、接口失败回落本地三图且无角标）；
后台 `collectible-api.spec.js` 的「播」两条（缺列兜底成关、布尔转 0/1 打到 `home-carousel`）。

坑（别改回去）：轮播上藏品的点击落点是**市场**（`/resale/:id`），不是一级发售详情（`/collection/:id`）——
这条 2026-10-09 已改过一轮，初版误跳首发页。若某件藏品只开了「播」而寄售开关是关的，市场页会是
「当前暂无寄售挂单」的空态（读接口不卡 `is_resaleable`，所以不报错）；后台开「播」的确认框此时会提示这一点。

### 7.8 关注（心形）与「我的关注」的口径（2026-10-09 修复「刷新一次关注就没了」）

你报的现象：点完关注心形亮了，**刷新一次页面关注就没了**。两个原因叠在一起，缺一不可地修：

| 环节 | 老问题 | 现在的口径 |
|---|---|---|
| 后端市场列表 | `GET /api/market/collections` 没挂认登录的中间件 → 后端一律按匿名用户返回 `isFavorite:false`，刷新后前端拿它覆盖本地，关注"掉了" | 该路由挂 `app\middleware\OptionalJwtAuth`：带令牌就认人、不带照常匿名（接口仍公开，不影响未登录浏览） |
| 前端关注态来源 | `fetchMarket()` 拿市场列表**重建**整个 favorites 集合 | 关注全集只由 `GET /api/user/favorites` 说了算（`fetchFavorites()`）；市场列表**只增不删**（把 `isFavorite:true` 的行补进来，绝不删本地已有 id） |
| 「我的关注」列表 | 从市场列表里筛 `isFavorite` → 后台没开寄售开关的藏品永远不出现 | 改用 `store.followedCollections`：市场行优先（有挂单最低价），市场查不到的用 `/user/favorites` 的精简行兜底，卡片显示「暂无寄售」 |
| 时机 | 只有进市场页才拉关注 | `App.vue` 监听登录态：登录即 `fetchFavorites()` + 起收件箱轮询，登出即 `clearFavorites()` + 停轮询（`immediate:true`，冷启动带 token 也会拉） |
| 点关注 | 先改本地再调接口 | 先调接口，成功才改本地；关注成功后回源一次 `fetchFavorites()` 补全列表；失败直接抛给调用方 toast，心形保持原样 |

实现位置：
- 后端 `sinan-nft-backend/route/api.php`（`market/collections` 加中间件）、`app/controller/Collections.php::favorites()`（响应新增 `marketType`，前端据此标归属市场）。
- H5 `src/stores/collection.js`（`favoriteRows` / `fetchFavorites` / `clearFavorites` / `followedCollections` / `toggleFavorite`）、`src/App.vue`（登录态 watch）、`src/views/market/MarketFollowing.vue`（数据源换成 `followedCollections`）。

验收命令：
```bash
# 带令牌 vs 匿名：同一条藏品的 isFavorite 必须一个是 true 一个是 false
curl 'http://<IP>/api/market/collections?marketType=all' -H "Authorization: Bearer <token>"
curl 'http://<IP>/api/user/favorites' -H "Authorization: Bearer <token>"   # 未登录返回 code=2001
```
测试覆盖：H5 `tests/favorite-state.spec.js`（17 条）——未登录不打关注接口、市场列表只增不删（刷新不丢）、
回源失败保留现有态、登出清空、点关注接口失败心形不动、取消关注列表当场消失、
「我的关注」含市场查不到的藏品、跟随关键词与价格排序、`App.vue` 登录/登出联动。

坑（别改回去）：
1. `market/collections` 上的 `OptionalJwtAuth` 不能摘——它是关注态能扛刷新的前提；摘掉后接口不报错，只是 `isFavorite` 恒 false，症状和这次一模一样。
2. `fetchMarket()` 末尾那段同步**只能 push 不能 splice**。市场列表不是全集（只覆盖开了寄售开关的藏品），拿它当全集去删就会复现丢关注。
3. 线上目前只有 1 件藏品且它开了寄售开关，所以"关注了不在市场里的藏品"这条只在单测里验到；后台再上架一件**不开寄售开关**的藏品后，值得在 H5 里复看一次它的关注态与「我的关注」。

## 8. 已知遗留（需你后续决策）

1. **带宽 1Mbps**：实测后台主 JS 1.33MB 单下就要 8.6 秒，加 CSS 约 11 秒首屏。建议改「按流量计费 + 峰值 100Mbps」，小站月成本通常几元。
2. **无域名 = 无 HTTPS**：后台登录密码当前明文走公网。要么尽快备案+上证书，要么把 8088 在安全组里锁成仅你的 IP。
3. **短信/支付仍是 mock**：真实网关需后台录入密钥并切 `provider`；注意 mock 渠道在生产（debug=false）拿不到验证码明文，见第 4 节。
4. **ICP 备案**：中国内地节点用域名访问 80/443 必须备案；纯 IP 访问暂无此约束。数字藏品业务的资质合规另需评估。
5. **业务内容只有一条**：`nft_collectibles` 现有 1 条（id=1「大绵羊」，`status=onsale`，2026-10-08 01:01 建，
   2026-10-09 起归属**活动市场**、未上推荐；**寄售开关已由我打开**（原先是 0，为验证新门槛），
   所以它现在就在活动市场列表里，卡片显示「暂无寄售」）。
   想让它从市场消失：后台「藏品管理 → 转赠 / 寄售」列把「售」关掉即可。
   `nft_blind_boxes` 仍为 0 条；自由市场仍是空（还没有藏品被移过去）。
   `nft_resale_listings` 0 条在售挂单，所以**任何藏品都还没有真实成交价**，见 7.6。
   `nft_banners` 有 1 条（`/images/hero/slide-3.jpg`），所以首页顶部是静止的单图——想让它轮播，
   就去「内容 → 轮播管理」再上传第 2 张。用户表 2 条：`13900000001`（部署期测试号）与 `17587881293`（U000002），都还没实名。
6. **浅色外壳不适配深色背景**：`theme-color` 现在跟 `bgColor` 走（见 7.2），但只考虑了浅色背景这一种常态。
   若日后在「站点装修」里把页面背景改成深色，浏览器状态条的文字反相与 `color-scheme` 还没联动处理，需要真机再过一遍。
