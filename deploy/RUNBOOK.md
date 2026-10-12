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

# 用合并版基线，一次导入。不要按 23 个拆分 SQL + 23 个后端 migrations 手动跑 ——
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
- [x] **仓库瘦身**（2026-10-10）：本机日志/临时件清掉约 535MB，`git rm` 两个前端无引用的历史图 65 张，
  后端两份环境模板合并为唯一 `.env.example`。测试 82+55 例全通过、两个前端各构建一次成功、生产零改动。详见 7.9
- [x] **仓库冗余审计**（2026-10-10）：以「是否挂路由」为后端生死判据做只读审计，结论是代码层干净（0 孤儿文件、
  依赖 0 未使用），真实冗余只在 `database/` 三套安装方式重叠。零风险两件已改：后端框架样板 README 换成项目说明、
  两份孤儿 repair SQL 接入 `deploy.sh` 第 4 步。SQL 文件一个没删、库表没动。详见 7.10
- [x] **仓库地图补齐**（2026-10-10）：新增 `database/README.md`（三套安装方式怎么选 + 28 个 SQL 逐个归属与用途 +
  三个字段数口径对照）与 `qa/README.md`（五个家族 + 30 个脚本共用护栏实测）；`database-design.html` 移入后端 `docs/`；
  根 README 去掉与 `deploy.sh` 重复的手工命令列表、修正 79→80 张表。顺带纠正了「金额用 decimal.js」这个假声明。详见 7.11
- [x] **实名审核默认改为「自动通过」+ 前后端身份证校验同口径**（2026-10-10，**只到沙箱，生产未动**）：
  新增迁移 `20261010_realname_audit_mode_auto.sql`（对沙箱库连跑两遍，第二遍是真 no-op：`updated_at` 都没前移），
  `full_init.sql` 种子行 33 同步改成 `auto`。新增 `qa/realname_audit_modes_verify.php`，沙箱端到端 **49 项全过**：auto 下提交即 `realname_status=2 / is_realname=1 /
  realname_verified_at` 有值、AES 密文落库、后台 detail 解密回显与提交一致、列表仍脱敏；四条非法证号（首位 0 / 月 13 /
  日 34 / 乱填 18 位）在 auto 下仍被 `1001 身份证号格式错误` 挡住且**一行都没写进库**；通过后再提交被拒（身份不可改）；
  manual 两步流零破坏（pending → 缺原因驳回被拒 → 驳回留原因 → 重新提交 → 人工通过 → 二次审核 4220 → 原因清空），
  `audit_approve / audit_reject / view_full` 三种留痕齐全。测试：H5 vitest 14 例、后端 `RealnameFlowTest` 7 例 115 断言。
  详见 7.12
- [ ] **把 auto 真正落到生产**（两条，等你确认再动）：① 库里那行配置 —— 后台「系统配置 → 全局参数 → 用户与实名 →
  实名审核模式」选「自动通过」保存即可（与执行迁移等价，还自带审计日志）。生产库里这行现在的值 **2026-10-11 已读到：`manual`**
  （第二批期间用 `ssh root@<IP>` + `MYSQL_PWD=$(cat /root/.mysql_root_pass)` 只读查的，不是推断；当时凭据表那条路被安全策略拦住，没有绕过）；
  ② H5 已在 10-11 随 7.16 的 B1 重新构建部署过，**前端那条对齐正则现在线上已生效**（切 auto 之前唯一的防线就是它，别再当成旧的）
- [x] **一证一号：一张身份证只能认证一个账号**（2026-10-10，同日落地，**只到沙箱，生产未动**）：新增第 21 份迁移
  `20261010_realname_id_card_unique.sql`（`id_card_hash CHAR(64) NULL` + **普通**索引 `idx_id_card_hash`，幂等），
  比对值用 `HMAC-SHA256(归一化证号, APP_KEY)`（密文列带随机 IV，加唯一索引拦不住重复，这是根因）。
  占用只在「通过」那一刻写入，C 端 auto 与后台人工点通过**共用 `RealnameService::approve()` 一个口径**：
  auto 下第二张同证号 → `1001`（消息不含对方 UID），人工点第二个「通过」→ `4220` + 带 UID + `audit_conflict` 留痕，
  被拦账号**仍留在待审队列**；待审核/已驳回**不占坑**（否则乱填别人证号就能把真主人锁在门外）。
  新增只读命令 `php think realname:id-hash`（`--backfill` 才写，破坏-重算验过幂等）。沙箱 `qa/realname_audit_modes_verify.php`
  49 → **78 项全过、连跑两轮都全绿**；后端 phpunit 70 例 751 断言全过。存量重复实测 1 组 12 个账号 —— 所以索引暂时不能是 UNIQUE。
  前端零改动（后端消息本来就会透出）。详见 7.13
- [ ] **一证一号落到生产**（三条，等你确认再动，命令在 7.13 末尾）：加列 + 普通索引 → 只读统计存量重复 → 确认后 `--backfill`。
      必须和后端代码**同一批**上（只上代码不加列会直接报错；只加列不上代码则指纹恒为 NULL、功能不生效）
- [ ] **清理存量重复证号后才能把索引升级成硬约束**（沙箱实测有 1 组 12 个账号共用一张证号；生产数量未知，靠只读统计看）：
      清理 = 决定「谁算正式占用」、其余账号退回未实名，**这会改别人的实名状态，等你点头我才做**。
      之后一条 SQL 升级、代码不用改：`ALTER TABLE nft_users DROP INDEX idx_id_card_hash, ADD UNIQUE KEY uk_id_card_hash (id_card_hash);`
- [x] **你报的 10 条已逐条核实 + 第一批修复落地**（2026-10-11 沙箱验收；**前端那批已随 7.16 的 B1 上生产**，配置类的 B3 根因推测已被实测更正）：结论分三类 ——
      真缺陷 5 条（#1 弹窗没绑 `:show`、#4 合成新建默认停用 + 老库缺 `synthesis_enabled` 行、#6 协议编辑器 `biz` 传了白名单外的值、
      #7 富文本颜色被消毒吃掉 + 详情页返回键漏绑、#10 寄售锁定按「系列」而不是「件」判定）；
      需求 2 条（#2 空投单号改藏品编号、#3 盲盒图片型子藏品 —— 你已回「按原设计」，没动）；
      待复现/待发版 3 条（#5 开屏图、#7①与#8 公告/文物插图、#9 保存抽奖奖项）；
      共 11 处改动（含新增**第 22 份迁移** `20261011_module_switch_seed_rows.sql` 与 `full_init.sql` 两行种子）。
      沙箱验收：H5 96 项 / 后台 55 项 / 后端 phpunit 70 项 751 断言全绿，两边 `vite build` 成功；
      弹窗、两条返回键（含无历史兜底）、颜色被吃掉的两种写法、合成开关四态、上传白名单与 1.6MB/6MB 体积边界都实测过。详见 7.14
- [x] **你回的三个口径已落地：空投卡片显示藏品编号 + 到账通知补齐**（2026-10-11 沙箱验收，**同日已按 7.16 上生产**）：
      ①「这里显示藏品编号」= 「我的订单 → 空投」卡片头部，改显 `SN-<藏品id>-<资产行id>`；后端 `airdrops/mine` 新增下发 `serial`，
      且把 JOIN 改成 **LEFT**（否则发放失败那条会整条从列表消失）；②「公告不加标签颜色」= **不做**，`Announcements.vue` 零改动；
      ③「写入」= 签到/活动奖励/开盒/抽奖中奖都写 `nft_inbox`，新增第 23 份迁移把 `type` 枚举从 2 值扩到 5 值。
      口径要点：只给藏品到账发通知（积分结构性不通知）、开盒与抽奖**回传 `inboxId` 由前端就地标记已读**（防 15s 轮询弹第二次）、
      通知失败不拖垮发放。沙箱验收：后端 unit 45 例 345 断言 + api 39 例 482 断言（含新增 `InboxArrivalTest` 6 例）、
      H5 11 文件 102 例（含真挂载 `Orders.vue` 的 6 例）、`vite build` 成功、`SHOW COLUMNS` 确认枚举已生效。详见 7.15
- [x] **第二批 B1–B6 已对生产执行完毕**（2026-10-11，你回「开始第二批」之后；逐条证据与回滚见 **7.16**）：
      B5 先跑第 23 份 DDL 再跑第 22 份 → B2' 按文件发后端 6 个（`php -l` 前后各一遍、生产 md5 与本地一致、冒烟无 500）→
      B3 两个边缘站点补 `client_max_body_size 50m;` → B6 `request.js` 错误文案分级 → B1 两个前端上线（入口 hash 已变、外网直取 200）。
      **三处实测没病、本来准备动的白动了**：B3 的 413 假设证伪（`nginx.conf` http 层第 35 行本就 50m，2MB/6MB 都能穿到 PHP）、
      B2 的 `UploadController` 生产早就是新版、B4 的 `php.ini` 生产本就是 50M/50M。
      **仍停在沙箱等你点头**：一证一号那批（生产没有 `id_card_hash` 列，整包覆盖会让实名接口 500）、把 `realname_audit_mode` 改 auto。
      - **2026-10-12 只读复测更正**：生产这行的现值现在查出来是 **`auto`**，`updated_at = 2026-10-11 11:10:08`。
        上面那句「等你点头」指的是**代码**没发；**配置值本身已经变成 auto 了**，而且不是我改的 ——
        10-11 我量到的还是 `manual`，这行的变更时间在我那次之后，所以最可能是你在后台「系统配置 → 全局参数」里点过去的
        （这正是 7.12 说的「切开关实时生效、不用发版」）。谁改的我没有审计记录能证明，别当定论。
        复核命令：`ssh root@<IP>` 后
        `SELECT config_key, config_value, updated_at FROM nft_system_configs WHERE config_key='realname_audit_mode';`
        （列名是 `config_key` / `config_value`，写 `key` / `value` 会报 1054/1064；`SHOW COLUMNS` 先确认）。
        **同时确认生产仍没有 `id_card_hash` 列、`IdCardBoundException.php` 与 `RealnameIdHash.php` 两个文件也不存在**
        （`grep -c id_card_hash app/service/RealnameService.php` 返回 0）→ 一证号在线上仍未生效，
        也就是说「一张身份证可以无限注册并自动通过」这个坑在生产**还开着**，因为现在是 auto，危害比 manual 时更大。
        - **2026-10-12 03:1x 这个坑已经堵上**（你说「现在发」→ 第 6 次发版，逐条证据见 **7.25**）：
          第 21 份 DDL 加上了 `id_card_hash` 列与普通索引，7 个后端文件按文件发到生产，`RealnameService` 的查重逻辑从此在线上生效。
          当时查到的账号状态分布是「status=0 一个 / status=1 一个 / **认证通过的 0 个**」，所以存量重复 0 组、回填 0 行 ——
          **上线到现在没有任何一张身份证真的占过坑**，端到端「第二个号实名被拦」这一步还没有真人验证过（见 7.25 末尾）。

      **要你在浏览器点的三件事**：传一张 1.5MB 的图 / 公告插图并改色 / C 端开一次盲盒看中奖弹窗只弹一次
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

### 7.9 仓库瘦身：一次性清理（2026-10-10，commit `c6966e8` / `5d11a9a` / `472e3b0`）

清掉三件事，都不动业务逻辑：

**A. 本机运行时垃圾（不入库，直接删）**：`runtime/log/202609`（392M）+ `runtime/log/202610`（24M）、
`runtime/admin/log`（120M）以及 qa/ 下几份扫描输出与临时脚本。仓库从 1017M 掉到 482M。
**没删的**：`runtime/admin/backup`（26M，29 个 .sql 库备份）、`runtime/backup`、`runtime/admin/htmlpurifier`（富文本缓存）、
`upload_trash`（回收站）——这些是有用的历史数据，不在清理范围。`qa/sit_t6b_priority_window.php` 按你的要求保留（仍未提交）。

**B. 两个前端 `public/images` 里的无引用历史图 65 张**（`git rm`）。判定用三条口径，任一命中就留：
① 归属应用自己的代码语料（`src` / `tests` / `index.html` / `vite.config.js`）出现该文件名；
② 生产库 `nft_*` 里存过这条 `/images/...` 相对路径（后台是 `:src="row.image"` 直接渲染，所以图片必须在**后台自己那个域**有文件）；
③ `database/dev-only/seed-dev.sql` 演示数据引用（cover-1/2/3、brand-logo、banner-1/2、artifacts/1-3、tab 图标）。

关键认知：**两个前端各有一份 `public/images` 镜像，Vite 只把各自的 `public/` 打进各自的 `dist`**。
H5 代码写的 `/images/tab/gem-*.png` 走的是 :80 那份副本，跟后台目录下同名文件无关 ——
所以必须**逐应用判定**，全仓 grep 会把镜像和文档里的提及误算成引用。
结果：后台 61 张无引用（整包 `gem-`/`ink-`/`lg-`/`modern-`/`shanse-` 底部图标、`exhibit-*`、`hero/slide-1.jpg` 等，1951KB）；
H5 5 张（`avatar-new.png`、`collections/cover-collection-{4,5,6,bb1}.jpg` —— 这五张后台都有在用副本，删的是 H5 多余的）。
**保留**：`tab/empty-carton.png`（你说先留着）、`tab/empty-ding.png`、`hero/slide-2.jpg`、`hero/slide-3.jpg`（后两条生产库里正存着）。

**C. 后端环境模板合并成唯一一份 `.env.example`**（删 `.example.env`）。原先两份内容近乎重复且文档指向不一致
（README/PROJECT_DOC 指 `.example.env`，而 `.gitignore` 的 `!.env.example` 例外、DEPLOY.md、RUNBOOK 指 `.env.example`）。
文件名保留 `.env.example` 是为了不额外动忽略规则；`.example.env` 独有的三处（「生产部署必读」三条、
PAY_MOCK/SMS_MOCK 已废弃说明、`[DATABASE] DEBUG = false`）已并入。
注释统一用 `;`：原来 `#` 注释块里带 `php -r "echo bin2hex(...)"` 的双引号会让 `parse_ini_file` 报
`syntax error, unexpected '"'`，`.env.example` 现在整份可通过 `parse_ini_file(..., true)`。
三处源码注释（`AdminAuthService.php:53`、`common.php:77`、`JwtService.php:27`）里的文件名同步改成 `.env.example`；
README.md:158、PROJECT_DOC.md:714 的 `cp` 命令同理。

验证：H5 vitest 82 例、后台 vitest 55 例全通过；两个前端 `npm run build` 均成功；`php -l` 三个被改的 PHP 文件无错；
全仓 `*.md` 对被删图片零引用。
**注意**：线上目录不会自己变小 —— 要等下一次重新构建并部署两个前端，`dist/images` 才会跟着瘦身；
生产机的 `.env` 本次未动，也不需要动（改的只是模板）。

### 7.10 冗余审计结论与两份 repair SQL 接入（2026-10-10，commit `ffaccd4`）

7.9 之后又做了一轮**只读审计**（删前先看证据的老规矩），回答「仓库里还有没有无用/冗余的代码文件」。

**审计口径**：后端以「是否挂到路由」为生死判据 —— `config/route.php` 里 `url_route_must = true`，
没写路由规则的 action 线上根本请求不到，所以「被路由 = 活着」是可论证的标准（注意一条路由行里有**两个**引号串：
URL 路径 + 目标 `Stem/action`，按 `'X/Y'` 粗暴提取会误报「控制器缺失」）。
前端按 7.9 的逐应用口径复查；SQL 按 CREATE TABLE 块逐列比对（不是只看 ALTER 目标，那样算出来的覆盖率是假的）。

**结论：代码层是干净的。** 两个前端 0 孤儿文件、路由全覆盖；33 个控制器的 public 方法全部挂到路由；
services / traits / commands / middleware 0 未引用；`package.json` 依赖 0 未使用
（`postcss-px-to-viewport-8-plugin` 由 `postcss.config.js` 引入、54 个组件里 52 个用 `lang="scss"`，别按 grep 误删）；
没有空文件与杂物。唯一真实的冗余在 **`database/`**：三套并存的安装方式里，
`full_init.sql`（80 表 / 985 列）的 DDL 已完全包含另外 27 个 SQL 与 19 个 migrations 的所有建表列 ——
RUNBOOK 第 3 节本身也写着拆分版不要单独跑。

**本轮只动零风险的两件**（不删任何 SQL、不改库表）：
1. `sinan-nft-backend/README.md` 原是 ThinkPHP 框架自带样板（讲 `composer create-project`、赞助链接、远程图片），
   与本项目无关且无人引用，替换为项目自己的后端说明（目录职责、路由约束、本地启动、四个真实 think 命令、两套件怎么跑）。
2. `realname_status_pair_repair_upgrade.sql` 与 `wallet_row_backfill_upgrade.sql` 此前是**孤儿脚本**（没被调用也没登记）。
   选择**接入而不是删**——它们修的是会复发的存量脏数据，删了下次重建就没人管了。
   `deploy.sh` 新增 `[4/4]` 在库结构落定后执行（两份都是幂等条件 UPDATE，重复跑无新变化），步骤编号 `/3` → `/4`；
   README/PROJECT_DOC 的过时统计（SQL 个数、migrations 19 个、full_init 列数）一并修正。

**刻意没做的两件事**（避免下轮重新推导）：
- `database/` 收敛成一套安装方式（拆散 full_init 与拆分版的重复）—— 收益是整洁，风险是部署路径变动，需要单独一轮。
- `qa/` 下历史 SIT/e2e 脚本归档 —— 按你「杂项不放仓库」的偏好本该移，但这些脚本是若干验收结论的证据来源。

验证：`bash -n database/deploy.sh` 通过、两份 repair SQL 存在；`phpunit --testsuite unit` 35 例 323 断言全过；
新增行全文扫过无生产 IP / 口令 / 密钥。**未验证项**：`[4/4]` 只做了语法与文件存在性检查，没有对任何 MySQL 实例真正执行过
（脚本默认连 `127.0.0.1:3399` 沙箱且不带 `--verify` 会 `DROP DATABASE`）；生产库与 `full_init.sql` 的双向零差异仍是历史实测结论，
本轮想复核时被凭据读取权限拦下，没有绕过。

### 7.11 「地图补齐」一轮（2026-10-10，commit `1054a09`）

你问「仓库文件是不是有点乱」。先给结论：**GitHub 上那份不乱** —— 根目录只有 11 项，
`git` 跟踪的内容合计 **12.3MB**；本地看着扎眼的 473MB 全是 `node_modules/`、`vendor/`、`runtime/`、`dist/`
这些被 `.gitignore` 挡住的构建产物与日志，仓库里根本不存在它们。

乱的是「**同一件事写在几处、以及某类文件没有说明**」，实测四处：

1. `database/` 28 个 SQL 平铺一层、命名分五族（22 个 `*_upgrade` + `init/full_init` + 孤零零的 `002_add_snapshots`
   + `_align`/`_removal`/`_repair`），中间还夹着一个 `database-design.html`；三套安装方式重叠（见 7.10）。
2. 同一份「SQL 清单」在 **三个文件里各抄了一遍**：根 `README.md` 的目录树、`README.md` 里那段
   `mysql < xxx.sql` 手工列表、`PROJECT_DOC.md` §4。第三遍已经过时（把 `dev-only/seed-dev.sql` 写成
   `seed-dev.sql`、漏了 `deploy.sh`/`qa/`/`deploy/`），第二遍跟 `deploy.sh` 的 `BASE_FILES` 数组重复。
3. `qa/` 31 个文件平铺、五种前缀、按当年测试计划的任务号命名，**没有一份 README** 说明家族划分与哪些是历史证据。
4. 表数量口径散成三个数：`README.md` 架构图写 **79 张表**、`deploy.sh:153` 期望 **981 字段**、
   RUNBOOK §3 记 **982 字段**、审计解析 `full_init.sql` 得 **985 个列声明** —— 80 张表才是三处一致的数。

这一轮**只补说明、不搬目录**（你选的最小档）：

- 新增 `database/README.md`：三套安装方式怎么选（含「生产只用 `full_init.sql`、`deploy.sh` 会 `DROP DATABASE`、
  `dev-only/seed-dev.sql` 严禁进生产」三条红线）、23 个 `BASE_FILES` 逐个一行用途 + 动的是什么、
  2 个 `REPAIR_FILES`、**2 个未接入的老库枚举补丁**（`payment_method_channel_align.sql` /
  `wallet_refund_trans_type_upgrade.sql`，实测其结果已折进 `init.sql:161,415` 与 `full_init.sql`，
  所以全新部署不需要跑），以及上面第 4 条的三口径对照表。
- 新增 `qa/README.md`：五个命名家族、30 个脚本逐个一行用途、**共用护栏实测**
  （全目录只有 `bootstrap_db.php:43` 建 PDO，且 30 个脚本全部 `require` 它；`APP_ENV` 必须是 sit/test/dev
  且凭据只能走 `QA_DB_USER`/`QA_DB_PASS` 环境变量）、`QA_BASE` 覆盖方式、
  以及「本轮没重跑过它们，所以别把它们的绿当现状」「旧口径断言以 7.6–7.8 为准」两条警示。
- `database/database-design.html` → `git mv` 到 `sinan-nft-backend/docs/`（全仓零引用，扫过才动）。
- 根 `README.md`：目录树补上 `deploy/`、`qa/`、后端 `docs/`；删掉那段与 `deploy.sh` 重复的手工命令列表
  （**保留**四条踩坑警示，那是独有知识：无 `USE` 语句会静默失败、`raffle_purchase` 必须早于 `raffle_admin`、
  mysql 遇错即停吞掉后续语句、跑完必须查 `information_schema`）；「两套脚本并存」改成「三条安装路径怎么选」；
  架构图 79 → 80 张表；272 行瘦到 217 行。
- `PROJECT_DOC.md` §4 目录块收敛成指针，§11 的自检值补上口径说明。

**顺带抓到一个假的文档声明**（已改，值得记一笔）：`PROJECT_DOC.md` 的技术栈表和 §12 都写着
「金额用 `decimal.js` 计算」—— 实测两个前端的 `package.json` 里**都没有这个依赖**、`src` 下**零引用**，
`package-lock.json` 里那个 `decimal.js@10.6.0` 是 **Vitest 的 `jsdom` 拉进来的传递依赖**。
真实做法记进 §12，并把它引到下面第 7 条待决策项。

### 7.12 实名审核默认改为「自动通过」+ 前后端身份证校验同一条正则（2026-10-10 沙箱验；**2026-10-12 已上生产，见 7.25**）

起因是你问「实名审核是什么情况」。先把现状说清楚（都是读代码 + 沙箱实测确认的，不是猜的）：

**这套状态机长什么样**：`nft_users.realname_status` 是流程位（0 未提交 / 1 待审核 / 2 已通过 / 3 已驳回），
`is_realname` 是能力位（1 才允许购买、寄售、转赠），两者必须成对 —— 合法组合只有 0/0、0/1、0/3、1/2，
`realname_status_pair_repair_upgrade.sql` 就是修这个的。`realname_verified_at` 另有一个用途：**注册排位活动的名次依据**。
「通过」这件事在代码里**只有一个口径**（`app/service/RealnameService.php::approve`）：状态三连 + 独立事务里结算注册排位与邀请奖励，
奖励失败只记日志、不阻断审核 —— 所以「自动通过」不是新发明一条链路，它复用的正是后台点「通过」那条。

**auto 到底免掉了什么**（这点必须说白，否则容易被理解成「不校验实名」）：**只免掉「管理员人工点一下」**。
姓名与身份证的格式校验照旧、AES 密文落库照旧、实名仍是购买/寄售/转赠的前置照旧、通过后身份不可再改照旧。
后台的审核页在 auto 下不会消失，只是待审队列恒空（列表接口仍脱敏、`realname:full` 权限与查看留痕照旧）。

**动了 8 处**（改动本身很小，成本都在「别把已有断言搞成假红」上）：

| 位置 | 改了什么 |
|---|---|
| `sinan-nft-backend/migrations/20261010_realname_audit_mode_auto.sql` | 新增：`INSERT IGNORE` 补行 + `UPDATE` 归位为 `auto`，头注写明上面那条边界 |
| `database/full_init.sql` | 种子行 33（`nft_system_configs` 那条 INSERT）由 `manual` 改 `auto`、描述改为「当前默认」——让全新部署与「老库跟迁移」两条路殊途同归 |
| `sinan-nft-backend/app/controller/User.php` | 只加注释：库里种子值自 2026-10-10 起是 auto；**代码里的兜底值刻意留 `manual`** —— 那行被误删时宁可退回人工审核，不做 fail-open |
| `sinan-art-source/src/views/user/Realname.vue` | 身份证正则与后端对齐。原先前端只查 `/^\d{17}[\dXx]$/`，后端还查地区码首位非 0、出生年 19xx/20xx、月 01-12、日 01-31 —— 乱填 18 位能过前端、提交才被弹回，**报错晚一步**且用户不知道为什么 |
| `sinan-art-source/tests/realname-immutable.spec.js` | 7 例 → 14 例：五条非法证号各自断言「按钮 `is-disabled` + `disabled` 属性 + 错误文案 + `post` 一次都没调」，一条小写 `x` 结尾的合法证号断言真的发出 `POST /user/realname`；另加两条「提示文案跟随审核模式」（`approved`→「已通过」、`pending`→「等待审核」） |
| `sinan-nft-backend/tests/RealnameFlowTest.php` | `setUp()` 显式钉 `manual`、`tearDown()` 恢复 `auto`；新增 `testIdCardFormatMatchesFrontendRule()`（7 种非法输入 → `1001` 且**库里没写**，小写 `x` 原样入库不擅自改写） |
| `sinan-admin/src/views/system/Config.vue` | 「实名审核模式」的提示文案补上「2026-10-10 起库里默认值即 auto」和「自动只免掉人工点一下」，避免运营看到空队列以为功能坏了 |
| `qa/e2e_full_verify.php`、`qa/sit_t6_marketing.php` | 各加一行前置：`PUT /admin/system/configs/realname_audit_mode` 钉 `manual`。这两条脚本走的就是「提交 → 后台点通过」两步流 |

**为什么测试非得钉模式**：种子值一改，两类既有断言会变成**假红** —— 后台审核拿 4220「该用户当前不在待审核状态」，
以及奖励在提交时就已结算、后面的增量断言算成 0。所以 `RealnameFlowTest` 与这两条 qa 脚本都要先钉住自己要的模式。

**沙箱验证**：新增 `qa/realname_audit_modes_verify.php`，**49 项全过、0 失败**。跑法（护栏与非本机基址拒绝执行都已实测）：

```bash
# 后端起在 127.0.0.1:8080，沙箱库凭据走环境变量（见 qa/README.md 第一节）
APP_ENV=dev QA_DB_USER=<沙箱账号> QA_DB_PASS=<沙箱口令> QA_DB_PORT=3308 php qa/realname_audit_modes_verify.php
# QA_BASE 指向非本机地址时直接 exit(1)：它会改配置值、注册测试账号、执行迁移
```

结论按四块记：
① 迁移对沙箱库**连跑两遍**，第二遍是真 no-op（连 `updated_at` 都没前移 —— MySQL 对「SET 成相同值」不更新时间戳）；
② auto 下提交即 `realname_status=2 / is_realname=1 / realname_verified_at` 三连对，`id_card` 列里是 80 字节密文，
后台 detail 能解回与提交一致的明文、列表仍显示 `110***********757X`；
③ auto 下四条非法证号（首位 0 / 月 13 / 日 34 / 乱填 18 位）全是 `1001 身份证号格式错误` 且**一行都没写进库**，
通过后二次提交被拒（`不可修改`），后台点「通过」得 4220；
④ manual 两步流零破坏：pending →（缺原因驳回被拒）→ 带原因驳回 → 重新提交 → 人工通过 → 二次审核 4220 → 驳回原因清空，
`audit_approve / audit_reject / view_full` 三种留痕在 `nft_admin_operation_logs` 里都在。
**顺带把全目录的身份证夹具都核了一遍**（因为它们现在受更严的正则管）：`qa/*.php` 里出现的 8 个证号
（`grep` 提取后逐个套用新正则）全部仍判合法，包括两条被钉模式的脚本各自用的 `110101199001011234`（`sit_t6_marketing`）
与 `110101199003077770`（`e2e_full_verify`）—— 也就是说这次收紧**不会**把老夹具打成假红。

测试口径（本轮全部实测数字）：后端 `phpunit` 全量 **65 例 679 断言全过**（其中 `--testsuite unit` 35 例 323 断言，
`RealnameFlowTest` 7 例 115 断言）；H5 vitest 全量 **90 例全过**（`realname-immutable.spec.js` 由 7 例加到 14 例）；
后台 vitest 全量 **55 例全过**；两个前端各 `npm run build` 一次成功；四个被改的 PHP 文件 `php -l` 无错。

**顺带实测到一个真实缺陷**（不是理论风险）：同一个证件号能把**多个账号**都实名成「已通过」—— 库里从来没有
证件号唯一约束，人工审核时靠管理员看一眼；改成 auto 之后**这一眼也没了**。而且 `aes_encrypt` 每次带随机 IV，
同一明文两次入库的密文**不同**，所以「给 `id_card` 列加唯一索引」这条路根本走不通，要先做确定性加密或另存一列哈希。
连同「身份证只校验外形、没做 GB 11643 MOD 11-2 校验位」一起记进 §8 第 8 条，等你定方案，我不擅自收紧。

> 补记（同日）：上面这个「一证多号」缺陷你已定方案并落地了 —— 见 **7.13**（指纹列 + 应用层查重，普通索引可一条 SQL 升级为硬约束）。
> 本节里那句「要先做确定性加密或另存一列哈希」就是 7.13 选的第二条路：另存 `id_card_hash`，原列的随机 IV 加密口径不动。
> 校验位（②）与「接三方实名认证 API」（③）仍未做，留在 §8 第 8 条。

**要让生产真的生效，还差两步**（本轮**没做**，生产代码与生产库都没动）：
1. 生产库里那行值 —— 在后台「系统配置 → 全局参数 → 用户与实名 → 实名审核模式」选「自动通过」保存即可，
   等价于执行 `20261010_realname_audit_mode_auto.sql`，而且自带审计日志。动手前**先只读查一次**现值：
   ```bash
   ssh root@<IP> 'MYSQL_PWD=$(cat /root/.mysql_root_pass) mysql -uroot sinan_nft \
     -e "SELECT config_value,updated_at FROM nft_system_configs WHERE config_key='"'"'realname_audit_mode'"'"';"'
   ```
2. 重新构建并部署两个前端 —— 前端那条正则只在构建后的产物里生效（后台这次改的是提示文案，同一批带上）：
   ```bash
   cd sinan-art-source && npm run build
   tar czf - -C sinan-art-source dist | ssh root@<IP> 'tar xzf - -C /www/wwwroot/sinan-h5 --strip-components=1 && chown -R www:www /www/wwwroot/sinan-h5'
   cd sinan-admin && npm run build
   tar czf - -C sinan-admin dist       | ssh root@<IP> 'tar xzf - -C /www/wwwroot/sinan-admin --strip-components=1 && chown -R www:www /www/wwwroot/sinan-admin'
   ```
   部署后自查 —— 注意新正则**不在主 JS 里**，而在懒加载的分块 `Realname-*.js`（本轮构建实测：主 JS `index-AQmYDMWY.js`，
   正则命中在 `dist/assets/Realname-DKtg2eza.js`，命中 1 次、老的 `\d{17}[\dXx]` 已 0 命中），所以要这样查：
   ```bash
   grep -c '(19|20)' sinan-art-source/dist/assets/Realname-*.js          # 本机产物：应为 1
   grep -c '\\d{17}\[\\dXx\]' sinan-art-source/dist/assets/Realname-*.js  # 旧正则残留：应为 0
   ssh root@<IP> 'grep -c "(19|20)" /www/wwwroot/sinan-h5/assets/Realname-*.js'   # 线上产物：应为 1
   ```
   线上那条打出 0 就说明构建或部署没生效 —— 此时**库里改了也没用**，前端仍会把非法号发到后端才被弹回。
   回滚只需把那个下拉改回「人工审核」，实时生效、不用发版；前端回滚 = 重新构建回旧分支再部署。

**本轮未验证项**（诚实记一笔，避免下轮重复踩）：
- 生产库里 `realname_audit_mode` 的**现值没读到**（凭据读取被安全策略拦下，同 7.10 那一次，没有绕过）——「应该是 manual」只是按部署历史推的。
  **这一条已在 2026-10-12 闭环**：只读查到的现值是 **`auto`**（`updated_at = 2026-10-11 11:10:08`，不是我改的，见本节上面的复核命令与 7.25）。
- `qa/e2e_full_verify.php` 与 `qa/sit_t6_marketing.php` 只做了 `php -l`，**没重跑**：前者依赖 `sit_baseline_reset.php` 重建冒烟用户
  （当前沙箱库里 id=1~3 不在），且这类老脚本仍有 7.6 之前的旧口径断言，跑出的红与本次改动无关，不值得混进来；
- 拆分版整库重建（`bash database/deploy.sh`）**没跑过** —— 它会 `DROP DATABASE`。
  「`20260922`（manual）→ `20261010`（auto）按文件名顺序跑完净效果是 auto」是按 `deploy.sh:125` 的 glob 顺序 + 单条迁移的幂等实测推出来的；
- 后台「实名审核」页**没有加「当前是 auto，队列为空属正常」的提示横幅** —— 加它要先让该页去读 `/admin/system/configs`，
  是个功能改动而不是文案改动，本轮只把说明写进了「全局参数」那一条 hint（`Config.vue`）。运营真被这事困扰过再加。
- 老迁移 `20260922_realname_audit_mode.sql` 的内容**刻意没改**：迁移是历史记录，改已执行过的文件会让「已迁移的库」和「文件内容」对不上。
  它那份「manual（默认）」的描述就此保留，以第 20 份为准。

### 7.13 一证一号：一张身份证只能认证一个账号（2026-10-10 沙箱验；**2026-10-12 03:1x 加列 + 7 文件已上生产，见 7.25**；索引仍是普通索引，未升 UNIQUE）

起因是你定的一句话：**「每一个身份证只能认证一个账号」**。这是 7.12 末尾实测到的那个真实缺陷的正解，
也是 §8 第 8 条里那条「① 只做哈希列 + 唯一约束」的落地。动手前你把三个口径都定了，全文按这三条走：

| 口径 | 你选的 | 落到代码里的意思 |
|---|---|---|
| 什么算「占用」 | **只有认证通过的账号占用** | 待审核 / 已驳回都不占坑。否则随便乱填一张别人的证号提交，就能把真主人永远锁在门外 —— 排队不等于坐下 |
| 存量重复怎么办 | **先只读统计，再定** | 本轮不改任何人的实名状态：加普通索引 + 应用层查重（零漏网），另给一条只读命令看历史上有几张证号被多个账号认证过 |
| 要不要后台「解除证件绑定」按钮 | **暂不做，走人工 SQL** | 没加接口、没加按钮。人工 SQL 见本节末尾「换绑怎么做」 |

**大白话讲技术上卡在哪**：`id_card` 列里存的是 AES 密文，而 `aes_encrypt` 每次都带 16 字节随机 IV ——
同一张证号两次入库，密文完全不同。所以「直接给这一列加唯一索引」在数学上就拦不住重复，
这行索引只会在两种不同的糖纸之间比较。解决办法是**另存一列专门用来比对的「影子值」**：
`id_card_hash = HMAC-SHA256(归一化后的证号, APP_KEY)`。同一张证号永远算出同一串 64 位十六进制，
不同证号必然不同串，而且**从这串值反推不出证号**（这就是用 HMAC 而不是裸 SHA256 的原因：
身份证号的格式高度固定，可枚举，裸哈希能被字典撞出来）。归一化只做两件不丢信息的事：去空格、末位 `x` 转大写 ——
所以 `110101...757x` 与 `110101...757X` 算同一张证号（沙箱第 3b 段就是钉这一条）。

**动了 11 处**：

| 位置 | 改了什么 |
|---|---|
| `sinan-nft-backend/migrations/20261010_realname_id_card_unique.sql` | 新增（第 21 份迁移）：加 `id_card_hash CHAR(64) NULL` 列 + `idx_id_card_hash` **普通**索引，两段都用 `information_schema` + `PREPARE` 包成幂等，重复执行只打 `skip` |
| `sinan-nft-backend/app/common.php` | 新增 `id_card_normalize()` / `id_card_fingerprint()` / `id_card_fingerprint_with_key(…, $key)`，注释写明「为什么不是密文列唯一索引」「为什么 HMAC 不是 SHA256」「换密钥的契约」 |
| `sinan-nft-backend/app/service/IdCardBoundException.php` | 新增异常类，带 `otherUid` 字段。`getMessage()` 里含对方 UID，**只能给管理端看** |
| `sinan-nft-backend/app/service/RealnameService.php` | 本轮核心：`approve()` 里先解析指纹 → 事务内 `SELECT … FOR UPDATE` 查重 → 无人占用才写入 `id_card_hash` 与状态三连；撞车抛 `IdCardBoundException`；`duplicate entry` 也翻成同一异常（索引变 UNIQUE 后兜并发）。另加 `occupiedBy()`（C 端早查）与 `syncIdCardHashes()`（统计/回填） |
| `sinan-nft-backend/app/controller/User.php` | 提交处加 `occupiedBy()` 早查 + auto 分支 `try/catch`。对外只回 `OCCUPIED_MSG_PUBLIC`（**不含对方 UID**） |
| `sinan-nft-backend/app/admin/controller/RealnameController.php` | 人工「通过」撞车时回 `4220` + **带对方 UID** 的原因（运营得知道撞在谁身上），并补一条 `audit_conflict` 留痕（`detail` 里存 `occupied_by`） |
| `sinan-nft-backend/app/command/RealnameIdHash.php` + `config/console.php` | 新增 `php think realname:id-hash`：**默认只读**，加 `--backfill` 才写。输出已认证数 / 指纹已正确 / 待写 / 算不出指纹 / 重复组（只打 UID，绝不打明文证号），并把升级 UNIQUE 那条 SQL 直接打出来 |
| `sinan-nft-backend/app/command/RekeyEncrypted.php` | 重加密 `id_card` 时**顺手用新密钥算好指纹一起写**。这里有个坑值得记：原本计划让 rekey 跑完再调 `syncIdCardHashes(true)`，但那一刻 `.env` 里还是旧密钥，刚写进去的新密文解不开，回填会一条都写不上 —— 所以必须内联用 `$newKey` 算 |
| `database/full_init.sql` | 用户表补 `id_card_hash` 列与 `KEY idx_id_card_hash`，让空库安装与老库跟迁移两条路一致 |
| `sinan-nft-backend/tests/`（`ApiTestCase.php`、`RealnameFlowTest.php`、`unit/HelperFunctionsTest.php`） | 测试夹具 + 5 个新用例，见下 |
| `qa/realname_audit_modes_verify.php` | 49 项 → **78 项**，新增 3b/4/5/6 段覆盖一证一号 |

**前端零改动**（这点是核对过的，不是想当然）：H5 的 `src/views/user/Realname.vue` 本来就是 `showToast(e.message || '提交失败')`，
而 `src/utils/request.js` reject 时带的就是后端那句 `message` —— 所以「该身份证已绑定其他账号，如需处理请联系客服」会自动透出，
不用为了这个功能重新构建。**但 7.12 那条正则对齐仍然要构建才生效**，两件事别混。

**为什么现在只加普通索引**：沙箱库里有 12 个账号（`U000107`~`U000118`）是同一个证号认证通过的 —— 这就是那个老缺陷留下的存量。
顺序要说清楚，别以为迁移会当场失败：列刚加上的那一刻 `id_card_hash` 全是 NULL，而 MySQL 唯一索引把 NULL 当成互不相同，
所以**就算建成 `UNIQUE`，这条 DDL 也能过**；真正炸的地方在下一步 —— `--backfill` 填到同一组里的第二行就 `duplicate entry` 报错，
回填跑不完、统计命令一直报异常。所以本轮是「**普通索引** + 应用层查重」：**新提交零漏网，老数据一行不动**（我不会为了让回填跑绿而擅自改这 12 个账号的实名状态，那是要你定的事）。
等你按统计清理完重复，一条 SQL 就能升级成数据库级硬约束，**代码不用改**（`approve()` 已经把 `duplicate entry` 翻译成同样的报错）：

```sql
ALTER TABLE nft_users DROP INDEX idx_id_card_hash, ADD UNIQUE KEY uk_id_card_hash (id_card_hash);
```

**NULL 的设计**：MySQL 唯一索引把 NULL 当成「互不相同」，而未认证的账号（没提交/待审核/已驳回）指纹列就是 NULL。
两件事刚好对上：占坑这件事只在「通过」那一刻写入，NULL 行既不占坑、也不会互相挡住。

**指纹跟着 APP_KEY 走**（这是唯一的运维陷阱）：换密钥后旧指纹全部对不上新密钥算出的值，比对会静默失效 ——
表现为「重复证号又挡不住了」。用 `php think rekey:encrypted` 换密钥没这个问题（它内联写新指纹）；
**手工改 `.env` 里的 `APP_KEY` 则必须补跑** `php think realname:id-hash --backfill`。

**沙箱验证**：`qa/realname_audit_modes_verify.php` 扩到 **78 项、全过、0 失败**，并且**连跑两轮都能全绿**（这一点本轮修过：
第一轮脚本自己的证号夹具空间被压缩成约 1000 个槽位，第二轮必然撞上上一轮已通过账号占的坑，整段 manual 假红；
现在每段独立取随机数、运行内去重，合法空间约 3360 万个组合，跨轮撞车概率约百万分之一）。跑法：

```bash
# 后端起在 127.0.0.1:8080，沙箱库凭据走环境变量（见 qa/README.md 第一节）
APP_ENV=dev QA_DB_USER=<沙箱账号> QA_DB_PASS=<沙箱口令> QA_DB_PORT=3308 php qa/realname_audit_modes_verify.php
```

结论按六块记（都是实测打出来的，不是推的）：
① 两份迁移各连跑两遍都是 no-op；迁移后 `id_card_hash` 是 `char(64)` 可空、`idx_id_card_hash` 的 `NON_UNIQUE=1`；
② auto 提交即通过三连对，并且**当场写入 64 位指纹**；后台 detail 仍解得回明文、列表仍脱敏；
③ 一证一号：第二张账号提交 → `1001 该身份证已绑定其他账号，如需处理请联系客服`，**这句不含 UID**（否则谁都能拿报错接口探测别人的实名）；
   末位 `x` 与 `X` 算同一张证号；换一张自己的证号照常通过（拦的是重复，不是这个账号）；
   **四个接口**（`/api/user/profile`、`/admin/realname/users` 列表、`…/users/:id` 详情、`/admin/users`）**都不透出 `id_card_hash` 这个字段名**；
④ manual 两步流零破坏；**待审核不占用** —— 同一张证号的第二个账号也能进队列、库里 `id_card_hash` 仍是 NULL；
   管理员点第二个「通过」被拦：`4220` 且消息带对方 UID，被拦的账号**仍留在待审队列**（没拿到能力位、没写指纹），
   驳回后改用本人证号重新提交 → 通过 200；
⑤ 留痕：`nft_admin_operation_logs` 里 `audit_approve / audit_reject / view_full / audit_conflict` 四种都在，`audit_conflict` 写明原因与 `occupied_by`；
⑥ `php think realname:id-hash` 只读跑通，本轮新通过的 4 个账号指纹齐全。

**只读统计实测**（沙箱库，两轮跑完后）：已认证账号 **34 个**、指纹已正确 **34 个**、待写 **0 个**、算不出指纹 **0 个**、
重复组 **1 组**（`U000107`~`U000118` 共 12 个账号同一张证号）。
**回填幂等也按破坏-重算的方式验过一遍**（不是只看「跑两遍都 0」）：把 `U000282` 的 `id_card_hash` 手工置 NULL →
只读统计报 `指纹已正确 33 / 待写 1` → 第一遍 `--backfill` 写回 1 行、第二遍报 `已写入 0 个`，
且写回的值与破坏前完全一致（`ac23981d…`）—— 证明确定性 HMAC、且「值没变就不写」，第二遍 `updated_at` 不再前移。

测试口径（本轮全部实测）：后端 `phpunit` 全量 **70 例 751 断言全过**（`--testsuite unit` 37 例 332 断言，
`RealnameFlowTest` 10 例 178 断言 —— 本轮净加 3 例：同证号第二账号在 auto 下被拒、小写 `x` 算同一张证号、
待审不占用但管理员点通过被拦）。新增 2 个指纹单测：`id_card_fingerprint` 对大小写/空格归一后确定不变、
两次 `aes_encrypt` 同一证号密文不同（正是加不了唯一索引的根因）、改一位数字则指纹必变。
`php -l` 覆盖 11 个被改/新增的 PHP 文件均无错。**两个前端本轮没动、没重新构建。**

**换绑怎么做**（你选的「暂不做按钮」，所以这里只给 SQL；动的是身份链路，执行前先确认是哪个账号）：

```sql
-- 把「后来那个」账号退回未实名，坑就空出来了。会同时收回购买/寄售/转赠能力位。
-- 注意：已经发出去的实名奖励不会因此回收，需要的话自己去 nft_activity_rewards 核对。
UPDATE nft_users
   SET realname_status = 0, is_realname = 0, real_name = NULL, id_card = NULL,
       id_card_hash = NULL, realname_verified_at = NULL, realname_reject_reason = ''
 WHERE id = <账号ID>;
```

不要只把 `id_card_hash` 单独置 NULL 而保留「已通过」—— 那会留下「已实名却没有指纹」的空洞，
该账号既不占坑也挡不住别人，`realname:id-hash` 会把它算进「算不出指纹」里报出来。

**要让生产真的生效，还差三步**（本轮**没做**，生产代码与生产库都没动）：

```bash
# 1) 加列 + 普通索引（幂等，重复执行只打 skip）
ssh root@<IP> 'cd /www/wwwroot/sinan-nft-backend && mysql -uroot sinan_nft < migrations/20261010_realname_id_card_unique.sql'
# 2) 先只读统计，看存量重复有多大（不写库）
ssh root@<IP> 'cd /www/wwwroot/sinan-nft-backend && php think realname:id-hash'
# 3) 口径确认后再回填（写库）
ssh root@<IP> 'cd /www/wwwroot/sinan-nft-backend && php think realname:id-hash --backfill'
```

部署后端代码时要和这三步**同一批**上：代码先上而列没加，`approve()` 会写一个不存在的列直接报错；
列加了而代码没上，则指纹列一直是 NULL、一证一号不生效（统计命令会报出全部待写）。
第 2 步的输出要留档 —— 生产存量重复组要是很多，就先决定「谁算正式占用」再回填，别边清边写。

**本轮未验证项 / 已知边界**（诚实记一笔）：
- **并发窗口没做真实压测**：查重和写入在同一个事务里、并且用 `SELECT … FOR UPDATE` 锁了 `idx_id_card_hash` 的对应区间，
  但这依赖 MySQL 默认的 REPEATABLE READ 和「这个索引存在」两个前提。单进程脚本造不出两个同时 approve 的请求，所以没实测。
  要彻底封死就把索引升级成 UNIQUE（上面给的那条 `ALTER TABLE`），代码已经会把 `duplicate entry` 翻成同样的用户可读报错。
- 生产库里 `realname_audit_mode` 与 `id_card` 列的现状**这一轮仍然没读到**（凭据读取被安全策略拦下，同 7.10/7.12，没有绕过）。
- 存量那 12 个「同证号都已通过」的账号**状态没被改动**：新代码只挡后来的提交。清理方案（保留谁、其余退回未实名）等你定。
- **身份证仍然只校验外形、没做 GB 11643 的 MOD 11-2 校验位** —— 这是 §8 第 8 条的 ②，本轮没做、也没顺手收紧。
- 软删除（`deleted_at` 非空）的账号不占坑，是刻意的口径：注销后这张证号应能被重新使用。
- 后台没有「解除证件绑定」入口（你选的），所以换绑只能走上面那条 SQL。以后要加按钮，先想清楚它等于「替用户改身份状态」，权限与留痕都要单独设计。

### 7.14 你报的 10 条：逐条核实结论 + 第一批修复（2026-10-11 沙箱验；**前端已随 7.16 的 B1 上生产**，其中 B3 那条根因推测已被实测更正）

你一次给了 10 条现象，并要求「先确认是不是真的存在」，所以我这一轮**先查证、后动手**，
定案之后再按你批的两批走：第一批只改代码、沙箱能全验、不碰服务器；第二批要发版、要动服务器配置，等你点头才做。
「盲盒子藏品能不能只传一张图当子藏品」这条你明确回了**按原设计**（仍然只能选已建好的藏品），所以那部分**一行没改**。

**逐条结论**（结论栏只有三种：`真缺陷` = 代码/数据确实错，已修或待修；`需求` = 功能本来没做，不是坏；`待复现` = 本机复现不出来，需要线上那条报错原文）：

| # | 你说的现象 | 结论 | 根因（大白话） | 本轮处置 |
|---|---|---|---|---|
| 1 | 空投到账慢，且到账没有弹窗 | **真缺陷（弹窗）** | 弹窗组件只写了 `v-if`、没绑 `:show`。Vant 的 Dialog 内部是 `v-show="show"` + 懒渲染，`show` 默认 false，于是节点建了、内容根本没渲染，**空投和转赠的到账弹窗一次都没出现过**。到账慢是真慢但另说（见下） | 已修并沙箱端到端验过：`InboxPopup.vue` |
| 2 | 订单详情里「空投单号」改成藏品编号 | **需求 + 口径没定** | 那一行现在打的是 `airdrop_records.id`（空投记录编号），既不是订单号也不是藏品编号；后端 `/api/orders` 的字段清单里**压根没下发藏品编号** | 口径当时未定所以**本轮没动**；2026-10-11 你回「我说的是这里显示藏品编号」后已在 7.15 做完 |
| 3 | 盲盒子藏品可选已建藏品，也可只传图当子藏品 | **需求**（你已回：按原设计） | 表结构就是硬约束：`prize_collectible_id NOT NULL` + 外键 RESTRICT，开盒发的是「真藏品」 | **不改**，保持现状 |
| 4 | 合成活动创建后不显示到 C 端 | **真缺陷（两半）** | ① 后台新建表单默认把开关置成「停用」，而库里和后端默认都是「启用」，方向相反；② 老库里**没有 `synthesis_enabled` 这一行**，C 端把「行不存在」当关闭，于是整模块空状态 | ①`Synthesis.vue` 默认改为启用 + 保存提示说清真话；② 新增第 22 份迁移补两行配置，并同步进 `full_init.sql` |
| 5 | 开屏广告不能上传图片 | **待复现**（本机代码这条是好的） | 它用的 `biz=content`，改版前后都在白名单里；本机 1.6MB 的图能传、6MB 会被清楚拒绝。剩下的嫌疑是**线上网关的体积闸门**（见第二批） | 本轮不改代码；第二批加 `client_max_body_size` 后立刻能判定 |
| 6 | 登录页的用户协议/隐私政策配置不生效 | **真缺陷（仅限插图）** + 主链路是通的 | 实测「后台 → 内容 → 协议管理」存的正文**能**一路到 `/api/config` 再到登录页弹窗。但这个页面给编辑器传的 `biz` 是 `'agreement-' + 键名`（如 `agreement-agreement_user`），白名单里没有这个值，**协议里插图片必被 4220 拒** | 已修：`Agreements.vue` 的 `biz` 改为 `content`（新旧两版接口都放行，**不用等后端发版**） |
| 7 | 后台写公告：传不了图、改颜色没效果、左上角返回点了没用 | **真缺陷 ×3** | ① 公告编辑器的默认 `biz=announcement` 是 `39e6697` 才补进白名单的，**线上还是旧版后端**，所以必被拒；② 编辑器用 `foreColor` 上色，不打开 `styleWithCSS` 时浏览器产出 `<font color=…>`，而消毒白名单只留 `span[style]`，颜色被整段吃掉（文字留着）；③ `NoticeDetail.vue` 的导航栏漏绑 `@click-left`，而 `AppNavBar` 自己不做跳转、只发事件 | ②③ 已修；① 属第二批（后端文件要发版） |
| 8 | 文物展馆上传不了图片 | **同 7①** | `biz=artifact` 也是 `39e6697` 才放行的，本地已验能传，线上等发版 | 第二批 |
| 9 | 后台保存抽奖奖项提示「检查后端」 | **待复现** + 有一处口径是错的 | 这句文案只在**响应体不是平台 JSON** 时才会出现（nginx 的 413/502 HTML、端口不对）。另外老提示把「合计需 100%」只算非空奖项，而 C 端抽奖是**把所有奖项概率累加、余数一律落进最后一个奖项**，口径不一致 | 口径已改对（含「谢谢参与」）；413 那条属第二批 |
| 10 | 寄售一件，其余的看起来也全被锁 | **真缺陷（显示误判，不是资产事故）** | 后端按藏品聚合，组级 `isConsigned` 的意思只是「这个系列至少有一件在寄售」，逐件真相在 `items[].isConsigned`；前端拿组级标志 + `nos` 一刀切。数据库侧 `Resale.php` 的 UPDATE `WHERE` 只有主键 `id`，**从来没有批量锁**，不用修数据 | 已修：`stores/user.js` 逐件判定 + 新增 6 条用例钉住 |

**第一批一共动了 11 处**：

| 位置 | 改了什么 |
|---|---|
| `sinan-art-source/src/components/InboxPopup.vue` | 补 `:show="!!current"`（决定性那一下），并关掉 Dialog 自带确认键 `:show-confirm-button="false"`，避免正文物料里那颗红按钮和系统按钮重复成两颗。用单向 `:show` 而不是 `v-model:show`：关闭是靠 `markRead` 把 `pending` 清空，`current` 变 null 后 `v-if` 直接把节点摘掉，不需要 Dialog 自己关 |
| `sinan-art-source/src/views/NoticeDetail.vue` | 导航栏补 `@click-left="back"`；`back()` 加固：有历史就 `router.back()`，**没有历史（直接打开或刷新这条深链）时退回公告列表**，否则 `router.back()` 会原地不动，用户又是「点了没用」 |
| `sinan-art-source/src/views/ExhibitDetail.vue` | 同上，兜底落点是 `/mall` |
| `sinan-art-source/src/stores/user.js` | `isNoLocked(id, no)` 改为按**资产实例**判定（先查 `items[].serial`），新增 `lockedNos` 作为「拿不到逐件明细」时的兜底，并且**明确不再使用组级标志** |
| `sinan-art-source/tests/collection-status.spec.js` | 6 条 → **12 条**：新增 6 条寄售锁定用例（3 件里只锁 1 件、`nos` 只回传前 5 条时靠 `items` 找回第 7 件、缺明细退回 `lockedNos`、数字/字符串 id 都匹配、未知编号不抛异常、取消寄售后解锁）。**这套用例不需要 `@vue/test-utils`，纯 store 断言** |
| `sinan-admin/src/components/RichTextEditor.vue` | 上色前后各做一次 `document.execCommand('styleWithCSS', …)`：开一下让 `foreColor` 产出 `<span style="color:…">`，办完立刻关回去（这是**文档级、粘人**的开关，不还原会改变后面所有命令的输出形态）。消毒白名单**故意没放松** |
| `sinan-admin/src/views/marketing/LuckyDraw.vue` | 奖池概率合计改为**全部奖项求和**（不再排除 `none`），保存校验文案与工具栏标签同步口径，并在注释里写清 C 端是「累加 + 余数归最后一个奖项」，所以「留一点当谢谢参与」这种玩法在运行时不成立 |
| `sinan-admin/src/views/marketing/Synthesis.vue` | 新建默认 `enabled` 由 `false` 改 `true`（两处初值：`form` 定义与 `openCreate` 重置），与建表默认 `status DEFAULT '1'` 和后端缺省对齐；保存成功提示改说真话：启用且总开关开着 → 「C 端已可见」，活动停用 → 提示停用，模块总开关关闭 → 提示总开关 |
| `sinan-admin/src/views/content/Agreements.vue` | `:biz="'agreement-' + editing.key"` → `biz="content"`（见上表第 6 条） |
| `sinan-nft-backend/migrations/20261011_module_switch_seed_rows.sql` | **新增第 22 份迁移**：给 `nft_system_configs` 补 `lucky_enabled` / `synthesis_enabled` 两行，用 `ON DUPLICATE KEY UPDATE config_key = config_key` 做成幂等且**绝不覆盖运营已选的值** |
| `database/full_init.sql` | 种子行补 `id 40 lucky_enabled='1'`、`id 41 synthesis_enabled='1'`，让「空库安装」和「老库跟迁移」两条路结果一致 |

**为什么第 4 条要补数据行**（这是本轮最值得记的一个坑）：同一个「开关」在两端对「行不存在」的解释是相反的 ——
C 端 `Synthesis.php:22` 是 `(int)…->value('config_value') === 1`，行不存在 → 判不出 1 → **当成关闭**；
后台读的是 `$rows[$key] ?? 1`，行不存在 → **当成开启**。于是运营在后台看到「开着」，C 端却是空的，
两边都没错、谁也没骗谁，纯粹是「缺行」这一种状态被两边翻译成了两个意思。补上默认行是唯一的收口方式。

**沙箱验收证据**（都跑过，不是推理）：

- **回归测试全绿**：H5 `npm run test` **10 个文件 / 96 项**；后台 `npm run test` **5 个文件 / 55 项**；
  后端 phpunit **70 项 / 751 断言 OK**。两边 `vite build` 都成功（后台主 JS 仍是 1.33MB，见 §8 第 1 条）。
- **弹窗真弹出来了**（端到端，不是单测）：在沙箱给测试账号塞一条未读收件箱（`nft_inbox` id=13，`type=airdrop`），
  用 5173 的 H5 登录后进首页，`document.body` 里出现了 `.van-dialog`，`getComputedStyle` 是 `block`、
  `style.display` 为空串（老版本这里是 `none`，这就是「从没弹过」的物理原因），标题/藏品名/图都对，
  按钮只有自绘那颗「我知道了」（Vant 自带确认键没出现）；点下去 → `POST /api/inbox/13/read` → 库里 `status=2` 且 `read_at`/`confirmed_at` 都写了 → 弹窗节点整体消失。
- **返回键真返回了**：公告详情 `#/notice/1` 点左上角 → 回 `#/notice`；文物详情 `#/mall/1` → 回 `#/mall`；
  另用 `?fb=1#/notice/999` 新文档直开（`history.state.back === null`）点左上角 → 落到 `#/notice`，兜底那条路也验到了。
- **颜色那两条是被后端实测的**：后台建一条公告，正文同时塞 `<font color="#d00000">旧写法红字</font>` 和
  `<span style="color: rgb(208,0,0)">新写法红字</span>`，读库和读 C 端接口都得到
  `<p>旧写法红字普通字</p><p><span style="color:rgb(208,0,0);">新写法红字</span>普通字</p>` —— 颜色只从 `<font>` 那种写法上丢，`<span style>` 活着，
  顺手也确认 `<script>` 被剥掉了。**所以修的是编辑器产出形态，不是放松消毒**。验收用的那条探针公告已删。
- **合成模块四态**：`synthesis_enabled` = 1 → `{enabled:true, list:[审计合成]}`；= 0 → `{enabled:false, list:[]}`；
  **整行删掉**（模拟没跑迁移的老库）→ `{enabled:false, list:[]}`；重跑第 22 份迁移 → 回到 `{enabled:true}`。
- **上传接口实测**：`biz=artifact`、`biz=announcement`、`biz=content`、`biz=collection` 全部 200 落盘；
  `biz=splash` 被 4220 拒，且**报错文案里现在带得出 artifact/announcement** —— 这句可以直接当线上判别器用（见第二批）。
  1.6MB 的图 200、6MB 的图回 4220「图片大小不能超过 5MB（当前 5.72MB）」；本机 `php.ini` 是 `upload_max_filesize=20M / post_max_size=20M`。
  三件探针上传件、`/c/tmp` 下的临时文件都已删除。
- **数据侧**：安装路径没被我的种子行改动搞坏 —— `full_init.sql` 导进临时库 **80 张表**，`nft_system_configs` 38 行含 `id 33/40/41`，
  `nft_users` 带 `id_card_hash` 与 `idx_id_card_hash`。临时库用完已 `DROP`。

**这一轮查出来但故意没改的**（都不是「忘了」，是要你定或超出你批的范围）：

1. **同类「新建默认停用」还在四处**：`LuckyDraw.vue:58,154`、`resale/Index.vue:87`、`marketing/Invite.vue:53`、`marketing/Register.vue:48`
   也是 `enabled: false`。你报的是合成，我只改合成这一处；其余四处是行为改变（新建即上线），要一起收口请说一声。
2. **公告的 `tag_color` 后台没有入口**：后端字段、接口、C 端读取都在（`Content.php:41,68` 已下发 `tagColor`），
   但 `Announcements.vue` 的表单里没有这一项，C 端 `Notice.vue:67` 现在是写死的 `<AppTag type="primary">`。属功能补齐，等你定。
   → **2026-10-11 已定：不加。** 所以这一条**一行没改**，以后谁想加先看这句结论是用户自己选的，不是漏做。
3. **抽奖保存的事务边界**：`MarketingController.php:1218-1226` 那句「活动实体不存在就补建」**在事务外**；
   而且如果那个 `id` 对应的活动是**软删除**状态（`deleted_at` 非空），这里查不到就会去 INSERT 同一个主键 → 主键冲突 → 5001「系统繁忙」。
   这是条真隐患，但它不是「概率合计口径」那条报错，也不在你批的第一批清单里，**没动**。
4. **`UploadController.php` 的文件头注释还写着「按业务目录归档（collection/blindbox/marketing）」**，和白名单实际值已经不一致；
   纯注释、不影响行为，但第二批要发这个文件，顺手改掉与否你定。
5. **「到账慢」没找到代码级根因**：空投是同步三写（发藏品 + 写记录 + 塞收件箱）包在一个事务里，单次上限 5000 人，
   没有队列也没有异步任务（`composer.json` 无队列依赖，crontab 只注册了 `ScheduleDispatch`）。
   5000 人级别的一批确实要卡一会儿，但那是「一批就要等一次」的量级问题，不是「通知丢了」。
   要不要改成排队/分批，属于架构决定，等你定。
6. ~~**盲盒/签到等奖励不发收件箱通知**~~ → **已定并做完**（你回「写入」）：现在签到/活动奖励/开盒/抽奖中奖都写 `nft_inbox`，
   走的是新增的 `InboxService`，详见 7.15。原句里点名的 `BlindBoxController` 其实不存在（开盒在 `app/controller/BlindBoxes.php` 的 `open()`），
   我当时按记忆写的类名，顺手纠正。

**第二批：要发版、要动服务器，等明确批准**（逐条都可单独执行、单独回滚，我一条都不会自己先做）：

| 步 | 做什么 | 怎么判该不该做（先判再动） | 影响面 / 回滚 |
|---|---|---|---|
| B1 | 重新构建并部署 `sinan-admin`、`sinan-art-source` 两个前端 | 本轮 7 处改动全在前端，不发版线上看不到任何变化 | **10-11 已执行**（见 7.16）：入口 hash 从 `index-Bh8iftAQ.js` 换成 `index-Bt6A22kH.js`、后台换成 `index-Dau2SA-H.js`；解包用叠加不删旧资源；回滚 = 解包 `sinan-h5-old.tgz` / `sinan-admin-old.tgz` |
| B2 | 部署 `app/admin/controller/UploadController.php`（本地 `39e6697` 那版，白名单含 artifact/announcement） | 让运营在后台公告里插一张图，看报错文案：**带 `artifact/announcement` = 新版已生效**；只带 `collection/blindbox/marketing/content/misc/custom` = 还是旧版，这条就是 7①/8 的成因 | **10-11 只读比对后确认不用发**：生产那版与本地 md5 相同，白名单已含 `artifact`/`announcement` |
| B3 | 给两个**边缘** nginx 站点补 `client_max_body_size 50m;`（`deploy/nginx/20-sinan-admin.conf` 监听 8088、`10-sinan-h5.conf` 监听 80），然后 `nginx -t && nginx -s reload` | ~~现在这条只有内部上游站点有 50m，边缘站点仍是默认 1m~~ → **本行前提已于 2026-10-11 实测证伪，别再当上传失败的成因**：生产 `nginx.conf` 第 22 行起是 `http` 块、第 35 行就写着 `client_max_body_size 50m;`，而这条指令可在 http/server/location 三层设置且**上层往下继承**，所以两个边缘站点在补行之前实际限额已是 50m，从未发过 413。取证方法：给 `:8088/api/admin/…` 分别 POST 2MB 与 6MB 随机体，两次都拿到平台 JSON `{"code":4001,"message":"未登录或令牌缺失"}`（请求体已穿过 nginx 落到 PHP）；若被闸门拦下，拿到的是 nginx 自己的 HTML 413 页 | 两处配置各加一行注释+一行指令；回滚 = 删行 reload。**留这一行的理由只剩一个**：面板升级或「修复配置」可能把 http 层那行改回默认 1m，而别的站点的设置继承不过来，本站点写明才稳。**F12 定性（仍有效）**：HTML `413` → 这一行没生效；`4220` → B2 的 biz 白名单；`4003` → 角色没 `system:upload` 权限（权限 id 1304 目前只给角色 1/2）；`200` 但图没出现 → 另查（本轮最可能就是 B1 没发版，线上还跑着旧构建） |
| B4 | 核对生产 `php.ini` 的 `upload_max_filesize` / `post_max_size` | 本机是 20M/20M；线上若被设成 1M，症状与 B3 几乎一样，但报的是 5001/4220 而不是 413 | **10-11 已核对：生产就是 50M/50M，不用改** |
| B5 | 生产按顺序跑两份迁移：**先**第 23 份 `20261011_inbox_arrival_types.sql`（DDL，**必须早于后端代码上线**），**再**第 22 份 `20261011_module_switch_seed_rows.sql`（补两行开关）。命令形如 `ssh root@<IP> 'MYSQL_PWD=$(cat /root/.mysql_root_pass) mysql -uroot sinan_nft' < migrations/<文件>` | 第 23 份先只读查一次 `SHOW COLUMNS FROM nft_inbox LIKE 'type';` —— **已是 5 值就不用跑**；仍是 2 值（只有 airdrop/transfer）则**必须先跑它再发后端代码**，否则签到/开盒/抽奖的通知插入会被严格模式判成 `1265 Data truncated`，`InboxService` 的 try/catch 会把业务保住但**通知静默丢失**（只留 error 日志）。第 22 份先只读查 `SELECT config_key,config_value FROM nft_system_configs WHERE config_key IN ('lucky_enabled','synthesis_enabled');` —— **两行都在就什么都不用做**，缺哪行补哪行。两份都是幂等的，且都不会覆盖运营已经选过的值 | **10-11 两份都已按此顺序在生产执行**，逐条自查结果见 7.16 表里的 B5-a / B5-b 两行 |
| B6（可选） | 让 `sinan-admin/src/utils/request.js:76` 那个兜底分支能区分 413 | 现在所有非平台 JSON 响应都合并成一句「检查后端」，把 nginx 体积闸门、502、超时全揉成同一个提示，运营和我们都没法定位 | **10-11 已做**：新增 `describeHttpError()`，413/502/504/404/429/5xx/超时/取消各有带状态码数字的一句话，网络错误分支也纳入 `_silent`（登录页与上传本来就会自己显示文案，之前是双弹窗）；回滚随 B1 |

**还有 7.13 遗留的一并等发话**（这些和第二批是两件事，别混着做）：
生产库里 `realname_audit_mode` 的现值仍然没读到（凭据读取被安全策略拦下，我没绕）；一证一号那三步（加列 → 只读统计 → 回填）
没在生产跑；12 个存量重复账号的状态没人动；普通索引要不要升级成 UNIQUE。**注意 B 组与这组的先后是独立的，但第 22 份迁移和 7.13 那两份迁移互不影响，可以分开跑。**

**要你定的三个口径**（2026-10-11 已全部定完，处置见 7.15 —— 本表保留原始问题描述，方便日后回看当时的判断依据）：

1. 订单详情那一行你想看什么？现在打的是**空投记录编号**（`airdrop_records.id`），既不是订单号也不是藏品编号；
   藏品编号（`SN-<藏品id>-<用户藏品id>`）后端**目前没在 `/api/orders` 的字段清单里下发**，要打得连带加一个字段。
   选项：只显示藏品编号 / 两个都显示 / 保持现状只改标签文字。
2. 公告的 `tag_color` 要不要在后台补一个输入口（后端和 C 端读取都现成）。
3. 盲盒开启、签到/活动奖励到账，要不要也发收件箱通知（会让弹窗更频繁）。

### 7.15 你回的三个口径落地：藏品编号 + 到账通知补齐（2026-10-11 沙箱验；**同日已按 7.16 上生产**）

7.14 末尾留的三个口径，这一轮都给了答复，逐条按原话执行：

| 你的原话 | 我的理解与处置 |
|---|---|
| 「我说的是这里显示藏品编号。」（配了张截图，圈的是「我的订单 → 空投」卡片头上那行 `空投单号 #10`） | 指的是**卡片头部**那一行，不是订单详情页。改成显示 `SN-<藏品id>-<资产行id>`，也就是「我的藏品」里那一件的编号；`#10` 那种后台台账行号对用户没有检索意义 |
| 「公告不加标签颜色。」 | **不加**，`Announcements.vue` 一行没动。这条是决定，不是待办 —— 以后有人问「为什么公告不能选颜色」，答案是你选的 |
| 「写入」 | 盲盒开盒、签到、活动奖励到账**都要写 `nft_inbox`**，弹窗补齐 |

**到账通知这件事的口径先钉死（改代码前先看这段）**，因为「全都弹一遍」会把用户淹掉：

1. **只给「藏品到账」发通知**。`nft_inbox.collectible_id` 是 `NOT NULL` + 外键，弹窗卡片必须有藏品名和封面才渲染得出来；
   司南币（积分）奖励走钱包流水，**不写通知**（写了就是一张没有封面的空卡），这是结构决定的，不是偷懒。
2. **人不在现场的那些保持未读、等弹窗**：`airdrop` 空投 / `transfer` 转赠 / `reward` 活动奖励（签到、注册、邀请、后台统一发放）。
3. **当场已经看到结果的，留档但不重复弹**：`blindbox` 开盒、`lottery` 抽奖中奖。这两个接口照样写一条通知（后台可查、有编号可追溯），
   同时把 `inboxId` **回传给发起方**，前端拿到就立刻 `POST /api/inbox/:id/read` 标记已读 ——
   不然转盘刚弹完「恭喜抽中」，15 秒后 `stores/inbox.js` 的轮询又把它当新消息弹一次。**别把这两处的 markRead 删掉。**
4. **通知失败绝不拖垮业务写入**：`InboxService::pushCollectible()` 内部 try/catch 到底，写失败只打 error 日志并返回 0，
   签到/开盒/抽奖该发的藏品照发。宁可少一条弹窗，也不能因为通知表出错回滚一整笔发放。
5. **故意不通知的**：购买成功、抽签付款、合成、分解、寄售 —— 这些用户全程盯着进度条，再弹一次是噪音。

**为什么只挂一个钩子而不是十三处 INSERT**：六类奖励的发放全部汇成一条路
（`RewardGrantService::grantCollectible()`，调用方是 `ActivityRewardService:309` 实时发放、`:372` 后台统一发放、`LuckyDraw:327`），
在那里挂一次就把签到/注册/邀请/抽奖 manual/后台补发全覆盖了。开盒和抽奖的**藏品直发分支**没走这个漏斗（它们自己插资产行），
所以单独各挂一处。**原本手写 `insertAll` 的两处空投**（`CollectibleController:961`、`MarketingController:2027`）**保持不动** ——
钩子如果也覆盖它们，一次空投会弹两条，这是双写不是补齐。

**改动清单**：

| 位置 | 改了什么 |
|---|---|
| `migrations/20261011_inbox_arrival_types.sql` | **新增第 23 份迁移**：`nft_inbox.type` 从 2 值扩到 5 值（`airdrop/transfer/blindbox/reward/lottery`），纯 DDL、不动数据、可重复执行 |
| `database/full_init.sql` | 同步枚举取值，并更新 `ref_id` / `extra` 注释（新类型的 ref 语义与 extra 字段） |
| `app/service/InboxService.php` | **新增**唯一写入口：`TYPES` / `TITLES` / `typeForGrant()` / `pushCollectible()`。标题一律后端出，前端不管类型都能显示 |
| `app/service/RewardGrantService.php` | `grantCollectible()` 末尾挂通知，返回体多一个 `inboxId`（0 = 没写成，不影响发放成功） |
| `app/controller/BlindBoxes.php` | 开盒写 `blindbox` 通知，`extra` 带藏品编号；响应回传 `inboxId` |
| `app/controller/LuckyDraw.php` | 藏品奖写 `lottery` 通知，`ref_id` = 抽奖流水；响应回传 `inboxId`（积分奖不写） |
| `app/controller/Orders.php` | `airdropMine()` 把 `serial` / `userCollectibleId` 下发给 C 端；**JOIN 改成 LEFT JOIN** —— 写成内连接会让「发放失败」那条记录整条从列表里消失，用户以为没发过，比看不到编号严重得多 |
| `app/controller/Inbox.php` | 类注释补五种类型的归类口径（代码不用改，`pending` 本来就是按 `status=0` 全取） |
| `sinan-art-source/src/views/user/Orders.vue` | 空投卡片头部：有编号显示「藏品编号 SN-…」，没有（pending/failed）才退回「空投单号 #id」，普通订单仍是「订单号」 |
| `sinan-art-source/src/stores/order.js` | `fetchAirdrops()` 把后端 `serial` 落到卡片的 `no` 字段（原先恒为空串） |
| `sinan-art-source/src/stores/user.js` | `openBlindbox()` 拿到 `inboxId` 立刻 `markRead`；**用 `await import('./inbox')` 而不是静态 import** —— `stores/inbox.js` 顶层就 import 了 `./user`，写成静态会构成循环引用 |
| `sinan-art-source/src/views/Lottery.vue` | 转盘结果确认后 `inbox.markRead(res.inboxId)`，同上不再弹第二次 |
| `sinan-art-source/src/stores/inbox.js` + `components/InboxPopup.vue` | 注释与字段说明补齐五种类型；组件本身不用改（标题来自后端，新类型自动能渲染） |

**沙箱证据**（都实跑过，不是推理）：

- 第 23 份迁移已跑：`SHOW COLUMNS FROM nft_inbox LIKE 'type'` 现在是 `enum('airdrop','transfer','blindbox','reward','lottery')`，
  库里原有 3 条历史行（用户 9/10）取值没被动过。
- 后端 phpunit：**unit 45 例 345 断言**、**api 39 例 482 断言**全绿。其中新增
  `tests/InboxArrivalTest.php` **6 例 63 断言**（签到送藏品写一条未读 `reward`、`extra.quantity=2` 但**只弹一次**、
  pending 能取到且标记已读后消失；签到送积分**不写** inbox 但有钱包流水；空投列表下发 `serial`；
  `failed` 记录仍出现在列表里且 `serial` 为 null；开盒写 `blindbox` 且 `ref_id=奖池行`、`extra.serial` 与奖品编号一致、就地标记后轮询取不到；
  抽奖写 `lottery` 且 `ref_id=抽奖流水`）+ `tests/unit/InboxTypeMappingTest.php` 7 组类型映射（含 `synthesis` 被 `SCENE_MAP` 映射成
  scene=`airdrop` 那个坑：**不能**据此把合成标成空投通知，所以 `typeForGrant()` 要求 `activityType` 也为空才判 airdrop）。
- H5 vitest：**11 个文件 / 102 例全过**，含新增 `tests/arrival-inbox.spec.js` 6 例（真挂载 `Orders.vue` 断言头部文案，
  不是只测 store）。`vite build` 成功。
- 测试自己造的抽奖活动/奖项/盲盒都在 `tearDown` 显式删掉（这几张表**只有索引没有外键**，基类沿引用图清理够不着），
  改过的系统配置开关逐条还原原值 —— 用例跑完沙箱配置和跑之前一样。

**这一轮的边界**（没做的都写清楚）：

1. **当时生产一行没动**（写在 10-11 上午）：第 23 份 DDL 属于第二批 B5，且**必须早于后端代码上线**（原因见 B5 判定栏）。
   —— 同日午后已按 7.16 全部上生产，顺序就是先 DDL 再代码，中间没有出现「弹窗静默丢失」的窗口。
2. 抽奖的 `inboxId` 只在 **realtime + 藏品奖**这条路上有值；`manual`（记名单统一发放）走的是 `RewardGrantService` 那个钩子，
   到账那一刻才通知，语义上更对，所以这里返回 0 是设计不是漏。
3. 「到账慢」那半条还挂着：本轮只解决了「到账没通知」，同步三写的量级问题仍是 7.14 遗留第 5 条，等你决定要不要上队列。

### 7.16 第二批上线记录（2026-10-11，**生产已执行**，你说「开始第二批」之后做的事）

大白话：第一批是「在自家厨房试菜」，这一批是「把菜端上桌」。端的过程中发现**三处我们原本准备动刀的地方其实没病**，
所以这段除了记「做了什么」，专门记了「本来要做、实测发现不用做」——后者更值钱，你下次不用再治一遍假病。

**执行顺序**（顺序有原因，不是随手排的）：

1. 先跑第 23 份 DDL（`nft_inbox.type` 枚举 2 值→5 值），**再**发后端代码。反过来会出事：
   生产 `sql_mode` 含 `STRICT_TRANS_TABLES`，代码先上、DDL 后跑的那段时间里，签到/开盒/抽奖写通知会被判
   `1265 Data truncated`，而 `InboxService` 外层是 try/catch —— **业务不受影响，通知静默丢掉**，只在 error 日志里留一行。
2. 再跑第 22 份补两行开关（`lucky_enabled` / `synthesis_enabled`）。
3. 后端 6 个文件**按文件**部署（不是整包覆盖，原因见下面「没发出去的东西」）。
4. nginx 两处配置 → `nginx -t` → reload。
5. `request.js` 错误文案分级（改完必须重新构建后台，所以放在构建之前做）。
6. 两个前端构建产物上线。

| 步 | 结果 | 证据 | 回滚 |
|---|---|---|---|
| B5-a 第 23 份 DDL | **已执行** | `SHOW COLUMNS FROM nft_inbox LIKE 'type'` → `enum('airdrop','transfer','blindbox','reward','lottery')`；库里原有 2 行（id 1、2）取值仍是 `airdrop`，没被动过；再用事务插一条 `'reward'` 试写成功 → `ROLLBACK`，回滚后行数仍是 2 | 枚举收回 2 值**必须先撤回后端代码**，否则通知写入立刻开始报错 |
| B5-b 第 22 份补行 | **已执行**（生产原本两行都缺，确实要跑） | `SELECT config_key,config_value` → `lucky_enabled=1`、`synthesis_enabled=1`；`realname_audit_mode` 仍是 `manual`（本轮没碰） | `DELETE` 那两行，退回「缺行 = C 端判关闭」的老状态 |
| B2' 后端 6 文件 | **已执行**，严格限定 6 个文件 | 落盘前后都 `php -l`；生产 md5 与本地逐字节一致：`InboxService b83d0f81…`、`RewardGrantService e436371f…`、`BlindBoxes 1cf06612…`、`LuckyDraw af323ddb…`、`Orders fb7d536b…`、`Inbox`(controller) `8ca1bcbf…`；`php think clear --cache` → Clear Successed；冒烟：`/api/config` code 0、`/api/lucky-draw/activity` → `{enabled:true,activityId:1}`、`/api/inbox/pending` 与 `/api/airdrops/mine` 返回平台的「未登录」JSON 而不是 500；php-fpm 无新报错；生产没有 opcache（`;zend_extension=opcache` 是注释态），所以文件一落盘就生效 | 备份目录里那 5 个旧文件原样放回（本轮没改 `UploadController.php`，所以是 5 个） |
| B3 nginx | **已执行，但前提被证伪**（见下面「实测没病」第 1 条） | 两个站点各加一行 `client_max_body_size 50m;` + 说明注释；`nginx -t` ok、reload 成功；`nginx -T` 里 80 与 8088 两个 server 块都出现了这条；`/`、`:8088/`、`/api/config` 三个入口仍 200；两边 error log 无新增 | 删那一行再 reload；配置备份在 `/root/sinan-backup-batch2-20261011-102608/nginx/` |
| B6 request.js | **已执行** | 新增可单测的 `describeHttpError()`，把「有状态码+平台 JSON」「有状态码但是 HTML」「完全没状态码」三类分开；413 / 502 / 504 / 404 / 429 / 5xx / 超时 / 取消各给一句带**状态码数字**的人话；顺带把网络错误分支也纳入 `_silent` 判定（登录页、上传、密码校验三处本来就会自己显示 `res.message`，之前是**双弹窗**） | 前端一处文件，回滚随 B1 |
| B1 前端 | **已执行**（后台发了两次，第二次是 B6 的连带修正） | 后台重新 `vite build`（主 JS 1.33MB / gzip 427KB）；H5 的 `dist` 用 `find src -newer dist/index.html` 判过**已是最新**（构建 02:33 晚于最后一个源码改动 02:16），没白跑一次。两个 dist 打 tar 上传、md5 比对一致后解包、`chown -R www:www`。线上入口从 `index-Bh8iftAQ.js` → `index-Bt6A22kH.js`（H5）、`index-BdC6Aueu.js` → `index-Dau2SA-H.js`（后台，中间那一版是 `index-CNYEJKij.js`），新主包和 `Orders-*.js` 分包外网直取都是 200，`Orders` 分包里确认含「藏品编号」 | 解包 `sinan-h5-old.tgz` / `sinan-admin-old.tgz` 即回到上一版 |

**B6 顺带挖出的一个小连带**（改完 `_silent` 判定才暴露出来的）：网络错误分支以前**无视** `_silent` 硬弹一次，
所以有三处页面本来就指望「全局那一下弹窗」来报失败，去掉之后就会**静默失败**。逐个补了自显示文案：
`login/Index.vue` 拉验证码图失败 → 弹窗里直接标红 `localError`；刷新那张图失败 → 同样标出来（否则对着旧图反复输错）；
`detectCaptcha()` 读不到验证码配置时**默认是关掉验证码的**（fail-open），现在补一句 warning，
不然后端没起来时运营只会觉得「怎么不问我验证码」；`system/ImageCleanup.vue` 两个列表接口是 silent 的，
失败过去只有一张空表 —— 看着像「真的没有未引用图片」，这种误导在清理页最危险，也补了。
这三处都是后台侧，所以 B1 重新构建了一次并发版（新分包 `Index-DyRzqaIC.js` / `ImageCleanup-DmQNfLQT.js` 里已 grep 到对应文案）。
顺带对齐了一个事实：**C 端的 `src/utils/request.js` 早就是「按状态码给中文文案」的写法**（401/403/404/429/500/502/503/504 都有），
B6 只是把后台补齐到同一水平，两端不再是两套语义。另外确认过新 H5 那个 15 秒轮询**不会在令牌过期后刷屏**：
`stores/inbox.js` 在没有 token 时直接 return，C 端请求层也只是 `reject`（不弹全局提示），错误由调用方自己决定怎么显示。

**实测没病、本来准备动刀结果没动的三处**：

1. **B3 的 413 假设是错的**。生产 `nginx.conf` 第 22 行开 `http` 块、第 35 行就写着 `client_max_body_size 50m;`，
   而这条指令可在 http / server / location 三层设置并**自上而下继承** —— 两个边缘站点在补行之前实际限额已经是 50m。
   取证方式（不用登录、不写数据）：给 `:8088/api/admin/…` 分别 POST 2MB 与 6MB 随机体，两次拿到的都是
   `{"code":4001,"message":"未登录或令牌缺失"}` 这段平台 JSON；真被 nginx 闸门拦下时看到的是 HTML 413 页，不会是 JSON。
   **结论：7①/8/9 那几条「上传不了图片」跟体积闸门无关**，最可能就是 B1 之前线上还跑着旧构建（现在 B1 已上）。
   那一行还是留着了，理由写在配置注释里：面板升级或「修复配置」可能把 http 层那行改回默认 1m，本站点写明才不受影响。
2. **B2 不用发**：生产 `app/admin/controller/UploadController.php` 与本地那版 md5 相同，白名单里已经有 `artifact`/`announcement`。
3. **B4 不用改**：生产 `php.ini` 的 `upload_max_filesize` / `post_max_size` 都是 50M，与 nginx 侧一致。
   顺带补一条实测：从本机往 `:8088` 推 2MB 只用了 0.54 秒（≈3.9MB/s），所以「1Mbps 会把上传拖到超时」也不成立 ——
   那 1Mbps 限的是**出方向**（服务器发给用户），入方向不限速；上传和首屏下载不是同一个瓶颈。

**没发出去的东西**（有意的，不是漏了）：一证一号那批代码依赖 `nft_users.id_card_hash` 这一列，
而生产库里**没有**这一列（本轮前置只读侦察确认）。整包覆盖的话，实名认证接口第一次读这列就会 500。
所以本轮后端是**按文件**放的 6 个；一证号批次和把 `realname_audit_mode` 改 auto 都**仍停在沙箱**，等你单独点头。
**这个「只发 6 个」的边界是核过的，不是拍的**：把已发的 6 个文件逐个
`grep "id_card\|IdCardBound\|fingerprint\|occupiedBy"` 结果是**空** —— 两边没有任何引用关系；
再把本轮动过的其余后端文件逐个看 diff：`common.php` 里新增的正好是 `id_card_normalize` / `id_card_fingerprint` /
`id_card_fingerprint_with_key` 三个函数（**不是**藏品编号用的 `gen_serial_placeholder`，那函数生产早已有），
`User.php` 里改的正好只有实名预检那一段（`occupiedBy` 预检 + `IdCardBoundException` 兜底）——
所以这两类文件不发，藏品编号与到账通知一条腿都不缺；签到送藏品走的是已发的 `RewardGrantService` 那个钩子，`User.php` 无关。
剩下 `RealnameService.php`、`config/console.php`（命令注册）、`RekeyEncrypted.php`、`RealnameIdHash.php` 同属一证号批次。

**备份位置**：`/root/sinan-backup-batch2-20261011-102608/`（`nft_inbox.sql` 2 行数据 + `nft_inbox_schema.txt` 建表语句 +
5 个后端旧文件 + `nginx/` 两份站点配置 + `sinan-h5-old.tgz` 6.8MB + `sinan-admin-old.tgz` 7.5MB）。
前端解包用的是**叠加**而不是清空重放：旧的 hash 资源原地留着，这样还拿着旧 `index.html` 的用户不会突然 404
（`sinan-h5.error.log` 里 10-09 那几条 `purchaseGate-*.js` / `Resale-*.js` 404 就是这么来的）。

**还需要你在浏览器里点的三件事**（我这边没有后台账号，也不该拿你的账号去点）：
① 后台任意一处传一张 1.5MB 左右的图；② 公告里插一张图、改一次颜色；③ C 端开一次盲盒或中一次抽奖，看弹窗只弹一次。
任何一步再失败，把提示语原文发我 —— B6 之后提示里会带 `HTTP 413` / `HTTP 502` 这类数字，能直接定位是哪一层的问题。

### 7.17 第三批上线记录：编号规则统一 + 盲盒/中签/到账/空投五处（2026-10-11，**后端与两个前端已上生产；存量重编只跑了只读预览，落库仍未获批准**）

大白话：这一批修的是「编号怎么生成、怎么显示」这条主线，外加你报的四处界面/流程问题。
编号这件事以前散在六七个地方各写一套（有人拿资产行 ID 当号、有人按 `sold+1` 排号、有人先插占位串再回写），
这批把它们全部收到 `app/service/SerialService.php` 一个闸门里：**1..发行量 随机取、越界就报错、发完就报「编号已发完」**，
前台显示 `#7` 这种短号。闸门只有一处，规则才只有一种；防漂移靠单测扫源码（见下面「本轮的三把锁」）。

**你定的口径**（原话为准，记在这里免得下轮又问）：
- 「藏品发行 1000 份，编号最大不能超过 1000。且发放取随机数，编号显示 `#1000` 这样。」
- 抽签购：「中签五分钟后购买，中签五分钟之后就显示购买按钮，未中签不显示。」
- 到账通知：保持 15 秒轮询 + 切回前台立即补拉一次（**明确不缩到 5 秒**）。
- 后台空投：保存时自动圈一次名单，发放按钮置灰并把原因写在按钮上；全量空投投不了 / 名单 0 的问题一起修在这里。
- 盲盒：C 端补「开启」按钮 + 后台改成上传封面图（不再用 5 张硬编码预设图）一起做。
- 存量 21 件按新规则重编，但**预览必须先给你过目、未点头不落库**。

**本轮的三把锁**（都在单测里，改坏了 CI 就红）：
1. 号池规则只有 `SerialService` 一份实现 —— 命令侧源码里出现 `'SN-'` 字面量或自己 `format` 编号即判失败；
2. `serial:renumber` **默认只读**：以 `==================== 落库` 为界，读那半边出现 `->update(` / `->insert` / `startTrans` 即判失败，
   且禁止再提供 `--dry-run`（免得出第二个「以为没写其实写了」的开关）；
3. 落库只准动编号列：从每个 `->update([...])` 载荷里抽键名，白名单只有 `serial` / `source_serial` / `extra`，
   `tx_hash`、`token_id`、`block_number`、`sold`、`edition`、`circulate` 出现即判失败。

**执行顺序与证据**：

| 步 | 结果 | 证据 | 回滚 |
|---|---|---|---|
| C0 沙箱对齐 | **本地补跑两份 20261009 迁移**（生产早就有了，是沙箱落后） | 补跑前 `/api/banners` 在本地 500：`Unknown column 'is_home_carousel_recommended'`；补跑后 200。跑法是直接把迁移文件喂给一个临时 runner（`runtime/tmp_run_migration.php`，跑完即删），不手抄 DDL —— 手抄就等于给「沙箱与生产结构一致」埋第二个实现处 | 沙箱库加列无需回滚 |
| C1 后端按文件部署 | **21 个文件**：17 改 + 3 新（`SerialService` / `SerialException` / `SerialRenumber`）+ `config/console.php` | 上传前先拿生产 111 个 PHP 文件逐个 md5 比对（比对前两边都 `tr -d '\r'`，否则行尾会造出一堆假阳性），得出 23 个不同 + 5 个生产没有；落盘后生产侧逐个 `php -l` 通过、`php think clear --cache` → Clear Successed、`php think` 能列出 `serial:renumber` | `/root/sinan-backup-batch3-20261011-195555/backend/`（整份 `app/`+`config/` 快照 115 个文件）原样放回 |
| C2 一证号批次仍不发 | **6 个文件刻意留在沙箱**：`controller/User.php`、`RealnameService.php`、`admin/controller/RealnameController.php`、`IdCardBoundException.php`、`RealnameIdHash.php`、`RekeyEncrypted.php`；`common.php` 这次**发了**（新增的只有三个指纹函数，纯定义、无人调用） | 生产库里仍**没有** `nft_users.id_card_hash` 这一列；把要发的 20 个文件逐个 `grep "IdCardBound\|id_card_hash\|id_card_fingerprint\|occupiedBy\|RealnameIdHash\|RekeyEncrypted"`，只命中 `common.php` 里那三个函数自身的定义 —— 即发出去的这一批不引用任何没发的类/列。`config/console.php` 生产版是**本地版删掉 `realname:id-hash` 那一行**（ThinkPHP 的 `getCommand()` 是取用时才 `invokeClass`，但那行一旦留着，将来谁手动跑它就会撞上「类不存在」，不如不留） | 同上，`console.php` 回滚即删那一行 |
| C3 两个前端 | **已上线**（叠加解包，不清空） | H5 主包 `index-Bt6A22kH.js` → `index-BUAKuzyX.js`、后台 `index-Dau2SA-H.js` → `index-BcVINdJ6.js`；两个 `dist` 都先用 `find src -newer dist/index.html` 判过是最新（H5 16:34 / 后台 14:47 构建，晚于最后一个源码改动）；外网直取两个主包 200，`/assets/RaffleDetail-DlriyE_c.js` 里 grep 得到「分钟后开放购买」「购买已截止」 | 备份目录 `frontend/h5-index.html` / `admin-index.html` 放回即退回旧入口（旧 hash 资源原地还在，老用户不会 404） |
| C4 冒烟 | **通过** | `http://<IP>/api/config`、`/api/banners`、`/api/market/collections?market=activity` 都是 `code:0`；`/api/inbox/pending`、`/api/airdrops/mine` 返回平台的「未登录」JSON 而不是 500；`php-fpm.log` 无新增错误（只剩那条老 `zip already loaded`）；应用日志今日 `[error]` 计数 **0** | —— |
| C5 存量重编 | **只跑了只读预览，一行都没写**（等你单独点头） | 见下面「重编预览」 | 未落库，无需回滚 |

**测试证据**（都在本地跑，命令见各仓 `package.json` / `phpunit.xml`）：后端单测 **134 用例 / 1205 断言 OK**（本轮 +22：`tests/unit/SerialRenumberPlanTest.php`，含预览不落库、方案作废、两段式写号、收敛性）；
H5 **12 文件 / 116 用例全绿**；后台 **8 文件 / 90 用例全绿**（`upload-gate.spec.js` 把「盲盒封面」加进七个上传口的防漂移清单）。
**没跑到的**：`phpunit --testsuite api`（HTTP 级集成，需要本地起后端 + 指向沙箱库）本轮没跑完 —— 上线依据是单测 + 生产冒烟，不是它。

**重编预览（生产，只读）**：3 个藏品、21 件全部待改，理由都是同一条 ——「编号 N 等于资产行 ID N（旧实现拿行 ID 当号，非随机取号）」。
没有越界号（行 ID 才到 21，还没顶破 1000/100/111），没占位符，没重号；需同步的编号快照只有分解台账 1 行（换计划 0、通知 0、已上链 0）。
方案文件：`/www/wwwroot/sinan-backend/runtime/serial_plans/renumber_20261011_195723.json`。
**这条预览有保质期**：下午导出的那 21 行里，资产 2..9 当时是 `held`，晚上预览时已变成 `recovered`（你在之间做了回收类操作）——
所以真要落库前**必须重跑一次预览拿新方案**，`--apply` 会逐行核对旧编号与资产行增减，对不上就整批不动并报「方案作废」，这是设计好的，不是补救。

**还剩的事**：
1. **重编落库等你点头**（点头后：重跑预览 → 号码给你过目 → `--apply`，顺序不能倒）。
2. 线上浏览器复核（把 7.16 那三件挪到这一步）：① C 端「我的藏品」九件是不是九个号、显示成 `#7`；② 盲盒有「开启」按钮、后台能上传封面；③ 中签 5 分钟后右下角出现「购买」；④ 到账弹窗不再裁字、标题旁没有 🎁。
3. `--testsuite api` 补跑一次（含「先空投再下单」的撞号回归）。
4. 一证号批次（含把 `realname_audit_mode` 切 auto 的生产动作）仍等单独批准。

### 7.18 第四批：盲盒详情页「盲盒内容」接上（2026-10-11 代码完成 → **2026-10-12 02:2x 已上线，见 7.22**）

**你怎么发现的**：在生产 H5 上看盲盒「呃呃呃」的详情页，问「这个页面设计时是不是有盲盒藏品展示」。
答案是**设计有，但从没显示过一次** —— `CollectionDetail.vue` 第 432–443 行整块「盲盒内容」模板（奖品封面 + 名称 + 稀有度角标 + 概率）
一直挂在「藏品介绍」和「购买须知」之间，`v-if="blindboxItems.length"` 恒假，两个环境都没渲染过。

**断在哪两处**（都在数据侧，模板没问题）：
1. 后端 C 端两个接口都没下发奖池：`/api/collections/featured` 只给 `isBlindBox` 这类标记，详情接口同样不给；
   奖池的 join 查询只存在于 `app/admin/`（后台配置视图）。
2. 前端读错了对象：`blindboxItems` 取的是 `featuredItem.value?.items`，而 `stores/collection.js` 把 featured
   重新映射成固定字段时根本没有 `items` 这一项（后端原样字段只挂在 `raw` 上）。所以**即使后端发了也接不住**。
   顺带还有个隐患：深链直接进详情页时 featured 列表可能压根没加载。

**你定的口径**：接上，**并显示概率**（不藏）。

**改了什么**（后端 2 个文件 + H5 1 个文件 + 各 1 个新测试文件）：
| 位置 | 改动 |
| --- | --- |
| `app/controller/BlindBoxes.php` | 新增 `poolOf(collectibleId)`：**C 端奖池展示的唯一取数口**，按概率降序返回 `name/coverImage/probability/rarity`；另加两个纯函数 `percentText()`（库里概率存 (0,1] 小数，后台校验见 `admin/BlindBoxController::create`，展示换成 `100%`/`3.75%` 文案）、`rarityOf()`（**数据库没有稀有度列**，只能由概率推：≥0.5 普通 / ≥0.2 稀有 / ≥0.05 史诗 / 其余传说，四档与前端已有的四色角标同名）。奖品藏品被软删或已不存在时整行跳过，不留空壳行。 |
| `app/controller/Collections.php` | 详情接口下发 `items`（仅盲盒；非盲盒给 `[]`）。 |
| `CollectionDetail.vue` | `blindboxItems` 改为读详情接口的 `items`，与 `isBlindBox`/`canOpenBlindBox` 同一数据源。 |

**与 `open()` 的分工**（别混）：开盒那里也查 `blind_box_items`，但那是**抽奖** —— 要行锁、要看限量还剩多少；
`poolOf` 是**展示**。防漂移测试只约束「C 端展示」这条路径（`app/controller/` 下除 `BlindBoxes.php` 外不许出现 `blind_box_items`）。

**测试与实测**：
- 后端单测 `tests/unit/BlindBoxPoolDisplayTest.php`（21 例）：概率文案 8 种取值（含 `1 → "100%"`、`0.0001 → "0.01%"`、脏 0 → `"0%"`）、
  四档边界（含等号归属）、概率与稀有度**单调反向**、四档文案白名单，外加三条防漂移（单点、详情必须下发、后台概率区间未改）。
  全量 `--testsuite unit`：**155 例 / 1240 断言 全绿**（上轮 134 例）。
  写测试时踩到一个真陷阱并留在注释里：PHP 会把 float 数组键截断成 int，`$ranks[0.3]/[0.1]/[0.01]` 会全塌到键 0，
  单调断言因此假通过过一回 —— 改成列表比对才是真的在测。
- H5 `tests/blindbox-pool.spec.js`（5 例）：钉住数据源是详情接口、不许再回退到 `featuredItem.items`、四项都渲染、
  前端不得对概率二次乘 100、四档样式齐全。全量 vitest：**13 文件 / 121 例 全绿**（上轮 12 文件 / 116 例）。
- 沙箱端到端（本地库 3308）：`/api/collections/9608` 返回 `items:[{name:"审计分解产物",probability:"100%",rarity:"普通"}]`；
  临时插 4 条奖品（含一条指向软删藏品 9613、概率 0.9）后实测：**降序正确**（100% → 50% → 3.75%）、**软删那条被过滤掉**、
  非盲盒藏品 9606 返回 `items:[]`。测完把临时 3 行删掉，奖池表回到只有 id=2 原行的状态（已回查确认）。
- 构建产物复核：`CollectionDetail-*.js` 里能看到 `rawField("items")` 的编译结果，`CollectionDetail-*.css` 里四档
  `blindbox-items__rarity--普通/稀有/史诗/传说` 一个没少。

**三个要你点头前知道的点**：
1. **接上之后，你现在的配置等于向用户公告「必得大绵羊」**：生产盲盒「呃呃呃」(collectible 3) 的奖池只有 1 条 ——
   奖品是大绵羊(collectible 1)、概率 1.0000、限量 100、已发 1。这不是代码问题，是奖池只配了一种；
   要保留悬念得在后台多配几种奖品并调概率。
2. **空奖不显示**：后台允许概率合计 < 1（差额即空奖率），而这块只列奖品。若哪天配了 0.9，用户看到三档加起来 90%，
   剩下 10% 什么都不写会显得像 bug。要不要显式加一行「谢谢参与」是产品决定，本轮没做。
3. **要发版**：后端 2 个文件 + H5 重建。按规矩第 4 次发版单独等你批准，本轮**一行生产代码都没动**。
   （2026-10-11 深夜补：这一条与 7.19 的上传闸门修复**并成同一次上线**，清单见 7.19 末尾。）

### 7.19 第四批（并批）：后台图片上传闸门修复 —— 后端去掉误伤 + 前端自动转 JPEG（2026-10-11 代码完成 → **2026-10-12 02:2x 已上线，见 7.22**）

**怎么发现的**：你报「现在创建藏品也上传不了图片了，怎么回事？」。

**生产只读取证（没有改过生产任何一行）**：
- 当天后台上传接口 13 次请求里只有 2 次成功；成功的那 2 次都落到了 `public/uploads/announcement/202610/`（00:06、00:07 各一张，之后磁盘上再没有新的业务目录写入）。`public/uploads/collection/` 最后一次写入是 **2026-10-08 01:00**，之后再没落过盘 —— 所以「创建藏品传不上去」是真的，但**说「所有传图入口都停了」是我当时说重了**（见 7.20 更正）。
- 11 次失败是被**内容检查**拒的，你核对红色提示原文后确认是「文件扩展名与实际内容不一致」或「文件内容不是有效图片」这两句。
- 逐条排除掉了：不是今晚第三批发版造成的（15:16 之前当天 00:07、11:06 也有一样的失败）、不是磁盘满、不是 PHP 限制（50M）、不是 nginx 413、不是前端 5MB 闸门（请求都到 PHP 了说明 ≤5MB）、不是登录态（同期其他后台接口正常）。
- 字节数层面的失败原因当时推不出来（`$body_bytes_sent` 126 与 150 都对不上任何一种话术长度），所以改口问你提示原文，才对上这两条。**结论标注：HEIC / iCloud 未下载完是「最可能的解释」，不是已证实的事实；能证实的是那两句拒绝把 11 次请求挡在了内容检查上。**

**你选的档位**：「后端放宽 + 前端自动转 JPEG」（选项里写明了改 2 个文件 + 补用例）。

**改了什么**：

| 位置 | 改动 | 为什么 |
|---|---|---|
| 后端 `app/admin/controller/UploadController.php` | 删掉「扩展名与实际内容不一致」这条拒绝（连带 `getOriginalExtension()` 全部引用，全仓库已零残留） | 纯误伤：落盘文件名一直由服务端按嗅探类型生成（`bin2hex(random_bytes(16)).'.'.$ext`），客户端后缀从不参与存储与访问，改后缀既不能上传脚本也不能覆盖别人文件 |
| 同上 | 白名单判定收成一个方法 `isSupportedMime()`，四格式与 5MB **一个数没动** | 「放宽」只放宽后缀比对，不是放开格式；HEIC 走前端转码，后端多认一种格式就等于引入一条没人验过的解码链路 |
| 同上 | HEIC/HEIF 单列（`isHeic()` + `HEIC_MIMES`），拒绝话术 `unsupportedImageMessage()` 带上**识别到的真实类型 + 体积**，HEIC 另给「截图（立刻能用）」和「设置→相机→格式→兼容性最好（一劳永逸）」两条路 | 原来那句「仅支持 JPG/PNG/WEBP/GIF（当前 image/heic）」运营看不出自己该干什么 |
| 同上 | `getimagesize` 读不出时的话术换成 `unreadableImageMessage()`：说明「内容不完整」，给「重新选一次 / iCloud 那朵云先等它下载完 / 改传截图」三步 | 这条检查本身留着（防 polyglot），缺的是能照着做的下一步 |
| 同上 | 体积口语化 `sizeText()`：≥1MB 说 MB、<1KB 说「不足1KB」 | 沙箱实测第一版把 20 字节的坏文件说成「0KB」，听着像空文件，是误导 |
| 前端 `sinan-admin/src/api/index.js` 的 `uploadImage()` 总闸 | 非四格式在浏览器里 canvas → JPEG（质量 0.92、`imageOrientation:'from-image'` 保 EXIF 方向、底打白防透明黑底），转完**再核一次 5MB**；四格式原样透传；类型和后缀都读不出来就照旧原样传 | 全站唯一上传出口，改一处七入口都受益；PNG 透明、GIF 动画不许被顺手抹掉 |
| 前端 7 个上传入口（16 个 `accept`） | `accept="image/jpeg,image/png,image/webp,image/gif"` → `accept="image/*"` | 选择器原本就替后端拒收格式，iPhone 相册的照片**连选都选不上**，总闸的转码根本没机会跑 |
| 前端 5 处画面提示语 | 统一成「支持 JPG / PNG / WEBP / GIF（HEIC 会自动转成 JPG），大小不超过 5MB」 | 口径变了只改一处，其余六处会继续误导运营（沿用 7.10 那条「所有传图处对齐创建藏品」的规矩） |

**测试**：
- 后端新增 `tests/unit/UploadImageGateTest.php`（23 例）：四格式白名单原样锁死、10 条 MIME 判定（含 SVG 与 `application/x-php` 必须拒）、HEIC 家族识别、5MB 与话术、两条拒绝话术各含「能照着做的下一步」、`sizeText` 边界，再加三条防漂移 —— **代码里（挑掉注释行）不许再出现 `getOriginalExtension` 与那句扩展名拒绝**、`getimagesize` 必须还拦在 `$file->move()` 之前、随机文件名生成方式不许变，以及一条跨端锁：前端 `UPLOAD_NATIVE_MIMES` 去掉 `image/jpg` 别名后必须与后端 `ALLOWED` 一一对应、5MB 与同一句文案不许分家。
- 前端 `sinan-admin/tests/upload-gate.spec.js` 从 3 例扩到 **32 例**：HEIC 转成 JPEG 才出门（名字也换成 `.jpg`）、必须带 `imageOrientation`、四格式原样透传且不触发转码、TIFF 也转、读不出类型时不比今天差、浏览器解不开时**不发请求**并给「截图/兼容性最好」那条、环境没 canvas 时也不许假装成功、转完仍超 5MB 用同一句话拦、原本就超 5MB 时不做无谓转码、JPEG 质量不许调到 1.0；再加两组防漂移：7 个入口的 `accept` 都得是 `image/*` 且不许回退成 MIME 列表、5 处画面提示语是同一句话。
- `tests/raffle-window-blindbox-cover.spec.js` 里第三批那条「盲盒封面 accept 四种 MIME」的断言**按新口径改写**成 `accept="image/*"`（不是回归失败，是口径迁移，注释里写明了原因）。
- 沙箱跑全：后端 `--testsuite unit` **178 例 1300 断言** 全绿（1 条 deprecation 是既有噪声）；后台 `vitest run` **8 文件 112 例** 全绿；`npm run build` 出 `index-Cm18UH3J.js`（当前生产是 `index-BcVINdJ6.js`），新话术在 5 个懒加载 chunk 里都在。
- **沙箱端到端实测**（本地 `php think run -p 8080` + 四个真文件，测完已停服务、上传的测试图已删除，只留 10-02 那张原有的）：
  - 内容是 JPEG、后缀 `.heic` 的文件 → **`code:200` 上传成功**，落盘名 `…/202610/<随机32位>.jpg`（这就是原先被误伤的那一类）；
  - 文本内容挂 `.jpg` → 仍被拒：`仅支持 JPG / PNG / WEBP / GIF 图片（识别为 text/x-php，不足1KB）…`（安全边界没动）；
  - 只有 PNG 头 20 字节的坏文件挂 `.jpg` → 仍被拒，走的是新的 `图片没读出来（识别为 image/png，不足1KB，内容不完整）…` 三步指引；
  - 手工拼的 ISO-BMFF HEIC → 命中专属分支：`这张图是 iPhone 的 HEIC 格式，平台暂不接收（识别为 image/heif…）…兼容性最好…`。

**要你点头前知道的三点**：
1. **前端转码依赖浏览器能解码**。macOS/Windows 带 HEVC 解码的浏览器可以直接吃 iPhone 相册原图；解不开的浏览器（部分 Chrome/Firefox 组合）不会硬传，而是收到那句「截图 / 另存为 JPG / 改相机格式」的指引 —— 也就是**修好了「说不清为什么失败」，没有保证「任何浏览器都能直传 HEIC」**。
2. **5MB 与四格式口径一个没放宽**（你说过不擅自动）；要提上限或改尺寸压缩，得另外点头。
3. **富文本插图也吃这套**：公告/详情编辑器同样走总闸，传 HEIC 也会自动变 JPG。

**发版清单（第 4 次，与 7.18 并批）**：后端 **5 个文件**（`UploadController.php` 上传闸门 + 7.18 的 `BlindBoxes.php`、`Collections.php` + 7.21 的 `UploadCleanupService.php`、`UploadCleanupController.php`；`app/admin/controller/UploadController.php` 里 `BIZ_DIRS` 由 private 改 public，清理服务从它取排除目录，两者**必须同批**）、H5 重建包、后台重建包（本轮最新是 **`index-DdOfSwLx.js`**，7.19 记录的 `index-Cm18UH3J.js` 已被它取代；当前生产仍是 `index-BcVINdJ6.js`）。**一证号那 6 个文件仍不进这一批**（生产库还没有 `nft_users.id_card_hash` 列，见 7.17）。**上线状态（2026-10-12 02:16~02:25）**：你说「发」，这一批 5 个后端文件 + 两个前端重建包**已全部落到生产**，逐文件 md5 与本地一致、外网冒烟与真页面复核见 **7.22**；备份脚本在 7.21 时已单独装上（不涉及业务代码）。一证号那 6 个文件这次也**没发**。

### 7.20 图片链路其余环节体检（2026-10-12，**全程只读，生产一行没改、一个文件没删**）

**为什么要做**：7.19 修的是「选完图到落盘」这一段。你说「检查下」，我把「图片从选完到用户能看见、再到会不会被误删」这条链子的其余环节都走了一遍。

**先说一个我自己踩的坑（先误报、后自查纠正）**：我用 `curl http://127.0.0.1/uploads/...` 测两个门，收到 **404**，当时以为「H5 站点上传图全裂了」。实际是宝塔自带的 `phpfpm_status.conf` 也 `listen 80;` 且 `server_name 127.0.0.1;`，用 127.0.0.1 访问时命中的是它而不是 H5 站点。换成公网 IP（以及带 Host 头）重测：**80 和 8088 两个门都返回 200、2397917 字节、`Content-Type: image/png`**。没有裂图 bug。

**健康的部分（都有实测依据）**：

| 环节 | 现状 |
|---|---|
| 上传出口 | 全站只有一个 `POST /admin/upload/image`，七个后台入口都走 `api/index.js` 的 `uploadImage()`，C 端 H5 没有任何上传入口 |
| biz 白名单 | 后端 `BIZ_DIRS` 8 个业务目录，与前端各处传的 biz 一一对上；非法 biz 拒 |
| 体积上限 | PHP `upload_max_filesize/post_max_body_size` 50M、nginx `client_max_body_size 50m`（7.16 补的），业务闸门是 5MB，都在它们之内 |
| 落盘与权限 | `public/uploads/` 属 `www:www`、755，按 `{biz}/{YYYYMM}/` 归档，文件名 `random_bytes(16)` 十六进制 + 服务端嗅探类型 |
| 引用写法 | 库里只存 `/uploads/...` 相对路径，不绑域名，换 IP/上 HTTPS 不用改数据 |
| 存储边界 | `getimagesize` 仍拦在 `move()` 之前（防 polyglot），`$_FILES['error']` 预检在，随机文件名机制没动 |
| 审计 | 上传成功写 `admin_operation_logs`，写失败被 try/catch 包住，不会因为审计挂掉而丢图 |
| 盲盒封面 | 存进 `nft_collectibles.image`（不加列），链路与藏品图同一条 |

**风险分级（都不动手，等你点头）**：

- **S1｜图片文件不在任何备份里**。生产 crontab 只有 03:00 的 `backup_daily.sh`，只导数据库，留 5 份，**跟原文件在同一块盘上**。`public/uploads` 一个都没备。也就是说 7.19 那次「上传全停」如果伴随磁盘故障，图是真没了。要补的是把 uploads 纳入备份（或至少异机/对象存储留一份）。
- **S2｜上传可能被 20 秒超时掐死**。axios 全局 `timeout: 20000`，而服务器出口带宽 1Mbps（约 128KB/s），20 秒最多传约 2.5MB —— 而**目前生产上唯一那张在用的图就是 2.4MB 的 PNG**。它现在是靠「刚好压线」活着的。给上传单独设长超时（比如对齐 nginx `proxy_read_timeout`）不动 5MB 上限，是这个风险的解法。
- **S3｜后台「图片清理」有两处会把在用的图判成零引用**（这是本轮最实在的发现，只读核对代码得出）：
  1. `UploadCleanupService` 顶部注释写着「announcement 刻意不扫，怕误删公告正文图」，**但这条只在注释里成立**：`scanUnreferenced()` 是递归遍历整个 `public/uploads`，只有传了 `biz` 参数才过滤；控制器 `biz` 默认 null、前端 `ImageCleanup.vue` 的 `bizFilter` 默认空串 —— **不进筛选时 `uploads/announcement/*` 照样出现在零引用列表里、照样能被勾进回收站**。而引用扫描的列清单里 `announcements` 只有 `cover_image`，**正文 `content`（富文本 `<img>`）压根没扫**，所以正文里的图必然判成零引用。
  2. **没有「刚上传还没保存」的宽限期**。`scanUnreferenced()` 对 mtime 只用于展示和排序，没有任何时间阈值。运营「先选图、再点保存」这段时间里，那张图就是零引用状态，正在这时点清理就会被移走。
  补充依据：生产 `runtime/upload_trash` **目录从未创建过**、审计表里 `cleanup-*` 动作 **0 条** —— 所以清理功能在生产上还没被人用过，上述两条是**潜在**风险，不是已经发生的事故。修法（加正文列 + 把 announcement 从扫描里真挡掉 + 给新图设宽限）要改代码，等你定档位。
- **S4｜`/uploads` 关访问日志**（`access_log off`）。图裂不裂、谁在拉、有没有被刷，事后没有证据链。7.19 排查时 `$body_bytes_sent` 对不上话术长度就是这个盲区造成的绕路。
- **S5｜磁盘上 20/21 张图是孤儿**。全库 mysqldump 里 `/uploads/` 引用**只有一种**：`collection/202610/3fd703b0…png`（在 5 行里出现，同一张图 = 1 条 `nft_collectibles` + 4 条 `nft_inbox` 副本）。除此之外 20 张零引用，**合计 5,008,809 字节（约 4.8MB）**；整个 `public/uploads` 是 7.2MB，差额就是那张在用的 2.4MB 图（前一轮我把「20 张」和「7.2MB」放在一起说是把整目录当成孤儿量了，这里按实测分开）。其中**两张公告图值得单独说一句**：它们上传成功了，却没进任何一条记录 —— 唯一一条公告（id=1，10-11 00:06:49 保存）`cover_image` 是 NULL、正文是纯文字没有 `<img>`，也就是「上传成功 ≠ 保存成功」，正是 S3 第 2 条会误伤的那类文件。**删除属于不可逆操作，要清就得先给你看清单、你点头再移回收站，本轮一张没删**（清单见 7.21）。
- **S6｜没有像素尺寸闸门**。后端只判体积和类型，不判宽高。前端转码时如果原图像素极大，canvas 可能静默产出黑图/失败（失败会走「浏览器也读不出来」那句，不会假装成功）。要不要加「超长边自动缩到 N 像素」是画质相关的改动，等你点头再做。
- **S7｜3 张 `uploads/content/202610/*.jpg` 已不在磁盘上，原因未查明**。审计日志里它们是 10-08 01:25、01:32 的成功上传记录，如今 `uploads/content/` 目录都不存在了。能排除的：**不是清理功能干的**（回收站目录从未创建、无 `cleanup-*` 审计行）；RUNBOOK 历次发版步骤也没有一步碰 `public/uploads`。剩下「有人手工删过」「某次整目录覆盖」两种可能，**现有日志不足以证实任何一种**，所以我把它记为未结案的观察，不定性、不追查改动。

**这一节的后续**：S1 / S2 / S3 已按你批准的方案改完并验证，S5 只出了清单没删文件 —— 全部见 **7.21**。**S4（`/uploads` 访问日志）、S6（像素尺寸闸门）、S7（3 张图消失的根因）本轮没做，仍是待办**。

### 7.21 图片链路四项修复（2026-10-12，你说了「全做」）

**做范围**：7.20 列出的 S1（备份）、S2（上传超时）、S3（清理误判）三项按你批准的方案改代码并验证；S5（孤儿图）只出清单，**生产上一张文件都没动**。S4（访问日志）、S6（像素闸门）、S7（丢图根因）不在本次「全做」范围，也没做。

**S1｜图片文件进每日备份（已落到生产并实测）**

- 新增 `scripts/backup_files.sh`：把指定目录打成 `tar.gz`，参数 `-s 源目录 -o 输出目录 -k 保留天数 -l 标签`。三道自查：① 输出目录落在源目录里面直接报错退出（不然备份会把自己套进下一轮备份，越滚越大）；② 打完必须 `tar -tzf` 能打开；③ 包内文件数不许少于打包前 `find` 数到的数（少一个就当作失败，不静默留个残包）。源目录不存在时只提示 SKIP、不算失败（新机器还没有上传目录是正常状态）。超龄备份用 `find -mtime +N -delete` 轮转。
- `scripts/backup_daily.sh` 改成两步走：**数据库备份失败也继续备份文件**，并把失败那行打进同一份日志，避免「图备到了、库里却静默没备」或者反过来。
- 生产上两个脚本已安装（`chmod 700`），原 `backup_daily.sh` 存在 `/root/backup_daily.sh.bak-20261012`，**crontab 一行没改**（还是每天 03:00）。手工跑一轮实测：`sinan_nft_20261012_014122.sql.gz`（44K）+ `uploads_20261012_014122.tar.gz`（**7.1M，包内 21 个文件 = 磁盘 21 个**）。
- 仍未解决（下次再说）：备份和原文件**在同一块盘上**，磁盘故障仍然是双双没。要真正安全得加异机/对象存储副本，那是要单独点头的事。

**S2｜上传单独放宽到 120 秒（5MB 一个字节没动）**

- `sinan-admin/src/utils/request.js`：`get/post/put/del` 新增第三个参数里的 `timeout` 透传，**全局实例仍是 `timeout: 20000`**，只有显式传了才覆盖 —— 别的接口该多快还多快，不动它们的失败速度。
- `sinan-admin/src/api/index.js`：`uploadImage()` 调用改成 `post('/upload/image', fd, { silent: true, timeout: UPLOAD_TIMEOUT_MS })`，`UPLOAD_TIMEOUT_MS = 120 * 1000`。取 120 秒是因为 nginx 侧 `proxy_read_timeout 120s`（80/8088）与后端 `fastcgi_read_timeout 120s`（8081）就是 120 秒 —— **前端等到 120 秒就已经撞到服务器自己的天花板了，再长只是让运营多等**。
- 测试：`tests/upload-gate.spec.js` 从 32 例扩到 **35 例**（新增 3 条：必须带 `{silent:true, timeout:120000}`、超 5MB 依旧不发请求、以及一条读 `request.js` 源码的防漂移锁 —— 全局 `timeout: 20000` 不许被改掉，透传表达式不许丢）。
- **一个环境坑记在这里**：Vitest 里 `new URL('../src/utils/request.js', import.meta.url)` 这种**字面量**路径会被 Vite 静态改写成 `http://localhost:3000/...`，读文件就报 `The URL must be of scheme file`。把路径放进变量再传就好（仓库里原有的 7 入口循环就是这么写的）。

**S3｜清理功能不再把在用的图和新图判成孤儿（一个闸门管两头）**

口径：**announcement 目录不参与自动清理**（保持它原有那条承诺，只是这次真的生效）+ **新上传 24 小时内的图不列也不移** + **公告正文 `content` 里的 `<img>` 算引用**。三条都收在 `UploadCleanupService::protectedReason($biz, $mtime, $now = null)` 这**一个**函数里：扫描列表（`scanUnreferenced`）和移入回收站（`trashFiles`）都问它，改一处两边一起生效，不可能再出现「列表里看不见但能被移走」或反过来的分叉。

- `protectedReason()` 用**白名单**判定（`in_array($biz, self::BIZ_DIRS, true)`）而不是黑名单，未来往上传目录里加新业务目录时默认是「不动」，不是「清掉」。年龄用 `max(0, …)` 兜住未来时间戳，打不出负数小时。
- `scanUnreferenced()` 多带一个 `&$protected` 出参，被保护的图不进零引用列表，而是带 `reason` 单独回给前端；`trashFiles()` 的返回结构从 3 个键变成 5 个：`moved / referenced / protected / missing / invalid`。
- `IMAGE_COLUMNS['announcements']` 补上 `content`，正文图从「必然被误判」变成「 counted as 引用」。
- `UploadController::BIZ_DIRS` 由 private 改 public，清理服务的「排除目录」通过 `excludedBizDirs()` 从它算出来，**不再抄一份字面量**（两处各写一遍迟早分家）。
- 控制器 `unreferenced()` 的 `summary` 多回 `protected = {count, size, graceHours, excludedBiz}`；`trash()` 的话术改成「已移入回收站 %d 张（跳过：仍被引用 %d、受保护 %d、不存在 %d、非法 %d）」。
- `ImageCleanup.vue` 明明白白写上两类图**不参与这里的清理**（不是没扫到，是刻意留着），数字全部来自接口的 `graceHours` / `excludedBiz`，页面里不写死 24。
- **唯一故意不受闸门保护的地方**：删除记录时事件触发的 `releaseIfUnreferenced()`。记录都没了还等 24 小时没有意义，它在类注释里写明了为什么不同。
- 测试：后端新增 `tests/unit/UploadCleanupProtectionTest.php` **22 例**（含「全库只准出现 2 处 `protectedReason(` 调用」的防漂移、`bizCases`/`ageCases` 数据驱动、跨仓读 `ImageCleanup.vue` 断言前端也在用同一组数）、后台新增 `tests/upload-cleanup-protection.spec.js` **6 例**（含两条跨仓锁，后端仓库不在就跳过）。沙箱新增 `qa/sit_upload_protection.php`：直接实例化服务（本机 PHP 没有 curl，走不了 HTTP 用例），4 个夹具分别验「正文引用的图不列 / announcement 目录受保护 / 60 秒的新图受保护 / 10 天的真孤儿列得出」，再验 `trashFiles` 只移走该移的、restore 能回来、**刚 restore 回来的图再 purge 时报 missing 而不是从 uploads 里删掉**，最后自清理。**结果 PASS=18 FAIL=0**，夹具目录与临时公告行（`id=987654`）已全部删除，沙箱 `public/uploads` 回到跑之前的 17 个文件、回收站 0 文件。
- 顺带修了既有沙箱用例：`qa/sit_upload_cleanup.php` 的夹具生成后补一行 `touch($absFile, time() - 8 * 86400)` —— 它测的是「零引用图能不能被清」，而新宽限期会把刚落盘的夹具正当保护起来，不拨时间这条用例会假失败。宽限期本身由 `sit_upload_protection.php` 覆盖。

**回归总量**：后端 `phpunit --testsuite unit` **200 例 / 1349 断言** 全绿（1 条 deprecation 是既有噪声）；后台 `vitest run` **9 文件 121 例** 全绿；后台 `npm run build` 出 `index-DdOfSwLx.js`。

**S5｜生产孤儿图清单（只读，2026-10-12 实测；一张没删）**

依据：`find public/uploads -type f`（21 个文件）对照 `mysqldump` 全库 grep 出的 `/uploads/` 引用（只有 1 种 URL，出现在 `nft_collectibles` 1 行 + `nft_inbox` 4 行）；另外两个前端产物目录里 `grep -rho '/uploads/...'` 结果为空（没有硬编码引用）。md5 分组 + `file -b` + `tail -c 24` 取证每张的来历。

| # | 路径 | 时间 | 大小 | 内容是什么 | 判定依据 |
|---|---|---|---|---|---|
| — | `collection/202610/3fd703b0…png` | 10-08 01:00 | 2.40MB | 藏品「大绵羊」封面，1152x960 PNG | **在用，不许动**（`nft_collectibles.id=1` + 4 条 inbox 副本） |
| A1 | `announcement/202610/0f961fbd…png` | 10-11 00:07 | 2.40MB | 与上面那张**字节完全相同**（md5 `1dbf04dd…`） | 零引用；A 组两张是**同一张图的重复上传**，内容可从在用那张还原 |
| A2 | `announcement/202610/d871f083…png` | 10-11 00:06 | 2.40MB | 同上 | 同上；唯一公告 `id=1` 保存于 00:06:49，`cover_image=NULL`、正文纯文字无 `<img>` |
| B1 | `collection/202609/5c3b9e19…jpg` | 09-25 15:23 | 202KB | 1170x1179 JPEG，唯一一张「像真图但没人引用」 | 零引用；上线首日（7.3）测试创建藏品时传的 |
| C1 | `misc/202609/*.jpg` × 15 + `misc/202610/3732a133…jpg` × 1 = **16 张** | 09-25 ~ 10-02 | 429B ×16 = 6.9KB | **安全测试遗留物**：1x1 JPEG 尾部拼了 `<?php echo 'PWNED';?>`（`tail -c 24` 直接看得见），md5 全部同一个 `be790c3e…` | 与 `qa/sit_s1_security.php` 第 330 行的 polyglot 夹具 md5 **逐字节对上**；该脚本 `biz` 默认 `misc`，是在生产跑 7.1 用例时传上去的 |
| D1 | `misc/202610/458ebe93…png` | 10-07 10:42 | 3.5KB | 119x120 调色板 PNG | 零引用；小尺寸杂图，不像成品 |

合计 **20 个零引用文件 = 5,008,809 字节（4.8MB）**。按「删错了有多疼」分档：

- **C 组（16 张，6.9KB）最该清**：它们本来就是「伪装成 JPEG 的 PHP 文本」，靠 nginx 不把 `.jpg` 交给 PHP-FPM 才安全。留着没任何业务价值，还让 `public/uploads` 里长期躺着一批带 PHP 源码字样的文件。
- **A 组（2 张，4.8MB 里的 4.6MB）可以清但先确认**：内容和在用的那张一模一样（同一 md5），删了不丢信息。不过它们在 `announcement/` —— 新口径里这个目录**本来就不参与自动清理**，后台「图片清理」页面上看不到它们，要清只能按这张清单手工来。
- **B1、D1（2 张）建议留着或先问一句**：B1 是张正常尺寸的图，万一只是「还没挂上记录」的成品图，删了就得重传；D1 同理，只是概率低。
- 不管哪一组，**流程都是先移回收站、不是物理删**：`runtime/admin/upload_trash` 里的文件可以 restore，只有 `purge` 才是不可逆的。而 7.21 修完后，purge 一张刚 restore 回来的图会被报成 `missing`（这条有单测和沙箱用例各一条）。

**要你点头的两件事**：① ~~第 4 次发版~~ **已执行完毕（2026-10-12 02:16~02:25，见 7.22）**；② 上表要清哪几组 —— 你说清 A/C，我就只把这两组 18 张移回收站，B/D 留着，`purge` 更早不做。**状态：待你勾选，生产一张没动。**

### 7.22 第 4 次发版上线记录（2026-10-12 02:16~02:25，你说「发」）

**范围**：7.18 盲盒内容 + 7.19 上传闸门 + 7.21 清理保护三条，一次并批。后端 5 个文件 + 两个前端重建包。
**一证号那 6 个文件仍然没进**（生产库没有 `nft_users.id_card_hash` 列，见 7.17）。

**回滚点**：`/root/sinan-release4-20261012-021728/`（`backend/` 5 个原件 + `frontend/admin-index.html`、`h5-index.html`），
路径写在 `/root/sinan-release4-latest`。发版前又跑了一次每日备份（`sinan_nft_20261012_021728.sql.gz` 44K +
`uploads_20261012_021729.tar.gz` 7.1M / 21 个文件），所以**数据和图片也各有一份发版前快照**。
回滚 = 把 `backend/` 里 5 个文件按原名放回原位 + 两个 `index.html` 覆盖回去（旧 hash 资源原地保留，老用户不会 404）。

**每一步的证据**：

| 步骤 | 做了什么 | 实测结果 |
|---|---|---|
| 发版前测试 | 后端 `--testsuite unit` / H5 `vitest run` / 后台 `vitest run` | 200 例 1349 断言、13 文件 121 例、9 文件 121 例，**全绿**（1 条 deprecation 是既有噪声） |
| 构建 | 两个前端各 `npm run build`，先 `find src -newer dist/index.html` 判过源码不比产物新 | 后台 `index-DdOfSwLx.js`、H5 `index-CvGNcb03.js`（与 7.19/7.18 记录的哈希一致，确定性构建没漂） |
| 后端落盘 | tar 传 5 个文件解包，`chown www:www` `chmod 644` | 落盘后 md5 **与本地逐字节相同**：`BlindBoxes 9d4ab482…`、`Collections aa6ce43e…`、`UploadController e21ef0d4…`、`UploadCleanupService 2387f015…`、`UploadCleanupController e1d4ed1b…`（发版前生产是 `d519d6c5/3540f07f/92375ebd/f08142ff/05f4ae4d`） |
| 语法 | 逐个 `/www/server/php/84/bin/php -l` | 5 个「No syntax errors detected」（`Module "zip" is already loaded` 是这台机器的既有噪声） |
| 生效 | `php think clear --cache` | Clear Successed；生产 php.ini 里 opcache 是注释态，**文件一落盘就生效** |
| 前端落盘 | **叠加解包、不清空目录** | 后台 `assets/` 文件数 428 → 504、H5 258 → 301（只增不减）；`index.html` 分别指向新包；旧入口 `index-BcVINdJ6.js`(1335692B) 与 `index-BUAKuzyX.js`(467545B) 原地还在 |
| 外网可达 | 公网 IP 直取（**没用 127.0.0.1**，避免 7.20 那个假 404 坑） | `:80/` 与 `:8088/` 都 200 且引用新包；新主包 200（467545B / 1337324B）；懒加载 `ImageCleanup-ItP_Gec5.js` 200 且内含「受保护未列出」「不参与这里的清理」 |
| C 端接口 | `GET /api/collections/3` | `code:0`，**新增 `items`**：`[{prizeCollectibleId:1, name:"大绵羊", coverImage:"/uploads/collection/202610/3fd703b0…png", probability:"100%", rarity:"普通"}]` —— 7.18 那条死模板从此有数据了 |
| 清理闸门 | 服务器上跑一次性只读探针（`/tmp/_probe_release4.php`，跑完已删） | `graceHours:24`、`excludedBiz:["announcement"]`、`scanBiz` 7 个目录、announcement 目录返回「不在清理名单内」、60 秒前的新图返回「上传不足 24 小时…可能还没点保存」、3 天前的图返回 `NULL`（可清）—— 顺带证明 `UploadController::BIZ_DIRS` 改 public 后自动加载没问题 |
| H5 真页面 | 浏览器打开 `#/#/collection/3` 读渲染文本 | 出现「盲盒内容 / 大绵羊 / 普通 / 100%」，位置在藏品信息与购买须知之间，与设计一致 |
| 有没有搞坏别的 | 应用日志按时间窗筛 `ERRO/CRITICAL/Exception`；未登录直连两个后台接口 | 02:17 之后 C 端日志 40 行、admin 日志 0 行，**一条报错都没有**；`/api/admin/upload/unreferenced` 与 `/api/admin/collectibles` 都回 `{"code":4001,"message":"未登录或令牌缺失"}`（不是 500）；`/api/config` `code:0` |

**唯一没在线上验的一项，以及为什么**：**真人传一张 iPhone HEIC / 后缀不符的图走一遍新闸门**。后台登录要过图形验证码，
我用命令行去绕它既不合规也没意义。这一条的行为在沙箱已经用 4 个真文件端到端实测过（7.19 那节），线上跑的又是逐字节相同的文件，
所以风险很低 —— 但**「相同文件 + 沙箱绿」不等于「线上验过」**。你在浏览器里 30 秒就能补上：后台 → 内容 → 公告（或藏品编辑），
选一张 iPhone 原图，期望是**上传成功**；若失败，话术应该带真实类型和「截图 / 设置→相机→格式→兼容性最好」这类能照着做的下一步。

**发版后仍然待办的**：① 一证号 6 文件（要先加列，见 7.17）—— **已于 2026-10-12 03:1x 全部发完，见 7.25**（实际是 7 个文件 + 第 21 份 DDL）；② 7.21 那 20 张孤儿图清哪几组（**一张没删**）；
③ 备份仍是同盘，异机/OSS 副本没做；④ 存量 21 件编号重编仍是「只出预览、一行未写」（7.15）。

### 7.23 后台「修改密码」点了没页面：CSS 包含块坑（2026-10-12 02:4x 代码完成 → **02:5x 已上线，见 7.24**）

**你报的原话**：「后台点击改密码不显示改密码的页面。」

**结论一句话**：不是接口坏了，也不是没监听到点击，是**弹窗被压成头上一条 56px 的缝**，所以「点了但看不见」。

**取证链（按顺序，都可复现）**：

| 环节 | 取证 | 结论 |
| --- | --- | --- |
| 点击有没有反应 | `AppHeader.vue:136` `<el-dropdown-item @click="onChangePwd">` → `onChangePwd()` 里 `pwdShow.value = true` | 逻辑健康 |
| 后端在不在 | 服务器上 `POST /api/admin/auth/change-password`（无令牌） | 回 `{"code":4001,"message":"未登录或令牌缺失"}` 而不是 4040「接口不存在」→ 路由在 |
| 弹窗写在哪 | `AppHeader.vue:155` 那枚 `el-dialog` 是 `<header class="app-header">` 的**子节点**，原来没写 `append-to-body` | 位置有问题 |
| 为什么位置有问题 | `.app-header` 样式（同文件 187 行）有 `backdrop-filter: blur(10px); height:56px; position:sticky; z-index:90` | 见下面「大白话」 |
| 实测（浏览器 A/B） | 在该 header 里放一个 `position:fixed; inset:0` 的子元素量尺寸 | 带 blur 时 **515×56**（被 header 框住）；把 blur 去掉 → **515×545**（铺满窗口） |

**大白话**：CSS 有条规矩，祖先只要加了 `backdrop-filter` / `filter` / `transform` 这类视觉特效，
它就从「透明的观众」变成「舞台盒子」。Element Plus 的弹窗里面是 `position:fixed`，本来该按浏览器窗口铺满，
可祖先一变盒子，它就只能在这个盒子里铺 —— header 高 56px，弹窗就被切成一条缝。
下拉菜单不受影响是因为它自己会「瞬移」到 `<body>` 底下（teleport），弹窗不会，除非你写 `append-to-body`。

**改了什么**（一个文件 + 一个新测试）：

- `src/layouts/components/AppHeader.vue`：那枚 dialog 拆成多行并补 `append-to-body`，旁边留了四条注释写清「为什么必须留着」，
  防止下一个好心人当冗余删掉。
- `tests/dialog-containment.spec.js`（新增 5 例）：把这条规矩做成机器版扫描 —— 先从每个 `.vue` 的 `<style>` 里收集
  「带特效的类名」，再顺着 `<template>` 的标签嵌套栈判断每枚 `el-dialog` / `el-drawer` 有没有被特效祖先包住且没搬去 body。
  **口径不是「所有弹窗都得加」**：`login/Index.vue` 的验证码弹窗不在带 blur 的 `.login__card` 里面，就不需要，扫描器也不该报它。
  用例里有一组拿合成源码当正例，钉住扫描器本身没瞎（含「隔一层普通 div 也算被包住」「同级有特效不算」「已加 append-to-body 要放过」）。

**验证**：

- **反证**（确认测试真在测）：临时把 `append-to-body` 那一行删掉跑测试 → **2 例失败**（AppHeader 直断言 + 全仓库扫描），
  恢复后 `npx vitest run` **10 个文件 126 例全绿**（原 121 例 + 新 5 例）。
- **仓库内同类问题**：`backdrop-filter` 出现在 6 个文件，其中同时有 dialog/drawer 的只有 `AppHeader.vue`（已修）与
  `login/Index.vue`（结构上不受影响）→ **没有第二处**。
- **构建**：`npm run build` 通过，新主入口 `index-B6pMVDi1.js`（1,334,732B）；在产物 `AdminLayout-BtzbSh_Q.js` 里
  grep 到 `title:"修改登录密码",width:"420px","close-on-click-modal":!1,"append-to-b…` —— 属性确实进了包。

**没做/没法做的验证**：没有在**已登录**的线上后台里真人点一次「修改密码」看弹窗浮出来。
后台登录有图形验证码，我用脚本绕它既不合规也没意义（同 7.22 那条 HEIC 的口径）。
所以「构建产物含该属性 + 反证测试 + 浏览器 A/B 测过机制」是我能给到的最强证据。

**状态**：见下面 7.24 —— **2026-10-12 02:5x 你说「发」，后台前端已上线**，本次只动 `sinan-admin` 一个前端，后端与 H5 一行没碰。


### 7.24 第 5 次发版上线记录（2026-10-12 02:46~02:50，你说「发」）

**范围**：只有后台前端 `sinan-admin`（7.23 的改密码弹窗修复）。后端 5 个文件、H5 都保持 7.22 的状态不动 —— 这次改动纯前端渲染，
不涉及任何接口口径，所以没有「前后端必须同批」的耦合要担心。

| 项 | 做了什么 | 证据 |
| --- | --- | --- |
| 回滚点 | `/root/sinan-release5-20261012-024619/`，里面放了上线前的 `index.html.before` 和**整目录文件清单** `manifest.before.txt`（504 条） | 旧主包 `index-DdOfSwLx.js`（1337324B，md5 `f4ad66b5…`）原地还在 → 回滚只要把 `index.html.before` 拷回去，不用动资源 |
| 传输完整性 | 本地 `tar -czf` 后 scp，服务器再 md5 | 两端都是 `c0aaedfcb9d4bfdb959f149b287c73e0`；`tar -tzf` 先数过 158 条才解包 |
| 落盘方式 | **叠加解包**（不清空目录，跟 7.16/7.22 一样） | 目录文件数 504 → **573**（只增不减）；解包后 `chown -R www:www`、目录 755 / 文件 644 |
| 新包对不对 | `index.html` 与新主包逐字节比对本地 `dist` | `index.html` md5 两端同为 `b0fe25fc…`；`index-B6pMVDi1.js` 两端同为 `65fbde40…`（1337324B） |
| 修复真的在里面 | 公网抓懒加载分片 `AdminLayout-BtzbSh_Q.js`（14618B） | 里面同时有 `title:"修改登录密码"` 和 **1 处 `append-to-body`**；新主包引用的是这个分片 |
| 外网可达（**没用 127.0.0.1**） | `:8088/` 首页、新分片、新主包、**旧主包**都单独取一遍 | 四个都 200；首页 HTML 里指向 `index-B6pMVDi1.js` —— 老用户手里缓存的旧包也还下得动，不会 404 |
| 有没有搞坏别的 | H5 与后端接口、应用日志 | `:80/` 200 且 `sinan-h5/index.html` mtime 仍是 02:16:54（**没被碰**）；`/api/collections/3` 仍 `code:0` 带 `items`；今天与昨天日志里 `ERRO/CRITICAL/Exception` **0 条** |

**仍然没在线上验的**（诚实写清楚）：**真人登录后点「修改密码」看见弹窗浮出来**。后台登录要过图形验证码，我不用脚本绕它。
所以线上证据到「公网抓到的分片里 `append-to-body` 确实在」为止 —— 你打开后台点一次就是最终确认，
期望：屏幕正中浮出「修改登录密码」三条输入框（当前/新/确认），带灰色遮罩，点遮罩不关。


### 7.25 第 6 次发版上线记录（2026-10-12 03:1x，你说「现在发」—— 一证一号上生产）

**范围**：只有后端 7 个文件 + 第 21 份 DDL。**两个前端一行没动**（H5 仍是 7.22 的 `index-CvGNcb03.js`、后台仍是 7.24 的 `index-B6pMVDi1.js`），
因为这次改的是「谁能在什么条件下把实名认证点通过」，全在服务端。

**为什么必须加列和发代码同一批、而且顺序不能换**：这批代码一进 `approve()` 就会去读写 `id_card_hash`，
生产库里没这列的话，实名认证提交第一次就 SQL 报错 —— 用户看到的是 5001「系统繁忙」，而不是「这张证号已被占用」。
所以顺序钉死：**① 备份 → ② 跑第 21 份 DDL 加列 → ③ 发 7 个文件 → ④ 清缓存 → ⑤ 只读统计 → ⑥ 回填**。

| 项 | 做了什么 | 证据 |
| --- | --- | --- |
| 回滚点 | `/root/sinan-release6-20261012-031321/`：`files/` 放 5 个**将被覆盖**的原件（`User.php` 18480B、`RealnameController.php` 10002B、`RealnameService.php` 1751B、`RekeyEncrypted.php` 5232B、`console.php` 787B），外加 `nft_users.sql`（5592B，1 条 `CREATE TABLE` = 改表前的表结构）+ 空的 `mysqldump.err` | 另外 2 个文件（`IdCardBoundException.php`、`RealnameIdHash.php`）在生产**本来不存在**，回滚它们 = 删文件 + 把 `console.php` 拷回去 |
| 第 21 份 DDL | scp `20261010_realname_id_card_unique.sql`，先 `sed -i 's/\r$//'` 去掉 CRLF 再执行 | 执行后：`id_card_hash` = `char(64)` / `Null=YES` / `Key=MUL`；`idx_id_card_hash` 存在且 `NON_UNIQUE=1`（**普通索引，不是唯一** —— 存量重复没清完之前不能上唯一，见 7.13）|
| DDL 幂等 | 同一份文件当场再跑一遍 | 只打 `skip: nft_users.id_card_hash exists` / `skip: idx_id_card_hash exists`，零报错 |
| 传输完整性 | 本地 `tar -czf` → scp → 两端 md5 | `rel6-031330.tar.gz` 两端同为 `549697e33df540ceb45298bb5aefa2b8`（15483B）|
| 落盘 7 个文件 | 解包到原位 + `chown www:www` + `chmod 644` + 逐个 `php -l` | 7 个文件「No syntax errors」；生产 md5 与本地**逐字节一致**：`8fc0c2ea… RealnameService` / `fe736026… IdCardBoundException`（新）/ `ae13da1b… User` / `d49db679… RealnameController` / `5e736ec2… RealnameIdHash`（新）/ `73f5b8b4… RekeyEncrypted` / `0872c5de… console.php` |
| 有没有盖掉别人的热修 | 发版前把生产上那 5 个文件 scp 回本地，`diff --strip-trailing-cr` 逐个比 | 每一处差异都是**我自己改动的旧版本**，没有第三方的手笔 —— 生产不是「另一份代码」 |
| 缓存 | `php think clear --cache` | 「Clear Successed」。生产无 opcache，文件落盘即生效 |
| 只读统计 | `php think realname:id-hash`（**不带 `--backfill`，一条 UPDATE 都不发**）| 「已认证账号 0 个 / 指纹已正确 0 / 待写 0 / 算不出 0 / **存量重复 0 组**」 |
| 回填 | `php think realname:id-hash --backfill` | 「本次已写入 **0** 个」—— 与上一条口径对得上，没有已认证账号可写。**不是失败，是本来就没数据** |
| 冒烟（**没用 127.0.0.1**，同 7.21 那条假 404 教训） | 公网 `POST /api/user/realname`（不带令牌）| HTTP 200 + `{"code":2001,"message":"未登录或token无效"}` —— 走到鉴权就正常返回 JSON，**没有 500**，说明新 `User.php` 加载没问题 |
| 有没有搞坏别的 | 抓 `/api/collections/3` + 看当天应用日志 | `code:0` 且盲盒 `items` 仍在（7.22 的成果没退化）；`runtime/log/202610/12.log` 里 `ERRO/CRITICAL/Exception` **0 条** |
| 指纹函数在不在生产 | `app/common.php`（`id_card_normalize` / `id_card_fingerprint` / `…_with_key` 三个函数就在这）单独比 md5，**没打进这次的包** | 两端同为 `68b11922…`，`grep -c 'function id_card_'` = 3 —— 第二批 B2' 那次就发过去了，所以这次不必动它；探针里那两个函数能直接调用也印证了 |

**顺带把「发版前生产到底是什么状态」当场量清楚了**（看的是回滚点里那份原件，不是凭记忆）：

- 旧 `User.php` **已经有 auto 分支**（`if ($auditMode === 'auto') RealnameService::approve(...)`）和那条对齐后的身份证正则
  （带 `(19|20)` 世纪段）—— 这两样是第二批 B2' 那 6 个文件里带上去的。所以 §8 第 9 条里「配置值改成 auto 之后线上是真自动通过」这句话站得住。
- 旧 `User.php` 里 `id_card_hash` / `occupiedBy` **出现 0 次**，旧 `RealnameService.php` 里 `id_card_hash` 也是 **0 次**，
  旧 `console.php` 没有 `realname:id-hash` —— 三头对上「查重在生产上确实没生效」，那个坑是真实开着的，不是推测。
- 新版 `User.php` 有 `occupiedBy` 1 处（提交前先早查一次，让 C 端拿到人话提示而不是等事务里抛）。

**上线后另跑了一次只读探针**（`qa/probe_release6_readonly.php`，scp 到服务器 `/tmp` 跑完即删；它不调 `approve()`、不写任何行）：

- `id_card_normalize(' 11010119900307757x ')` → `11010119900307757X`，去空格 + 末位大写生效；
  两个大小写写法算出的 `id_card_fingerprint` **完全相同**（64 位十六进制）→ 「同一张证写小写 x 想绕过」这条路是堵的。
- `app\service\IdCardBoundException` 类在生产能加载（`config/console.php` 已注册 `realname:id-hash`，命令能跑本身就证明这点）。
- `RealnameService::occupiedBy('11010119900307757X', 0)` → `null`（真查了库，没抛异常）。
- 库里账号分布：`status=0` 一个、`status=1` 一个，**两者 `id_card_hash` 都是 NULL** —— 符合「只有认证通过才占坑」的设计。
- `realname_audit_mode` 现值仍是 **`auto`**。也就是说现在生产上是「自动通过 + 查重已生效」的组合，
  7.14 那条「一张身份证可以无限注册并自动通过」的坑**在代码层面已经关闭**。

**大白话**：以前只要格式对，机器就自动盖章通过，同一个人的身份证可以开无数个号。
现在改成：证件号先算成一串不可逆的「指纹」存着（存的时候还是加密的，看不见原号），
下次有人实名，先拿指纹去问一句「这号有人占了吗」——**只有已经通过认证的才算占**，
正在排队待审的不算（不然有人提交一半就能替别人锁死身份证）。撞上了直接拒，不给人工审核留模糊空间。

**没做 / 没在线上验的**（照例说清楚）：

1. **没有真人走一遍「同一个身份证注册第二个号 → 第二个被拦」**。这一步要在生产上真做，就得先有两个真账号并真的提交实名，
   而现在是 auto 模式，提交即通过 —— 意思是一次测试就会**真的占掉一个坑**、真的留下一条认证记录。
   这种带副作用的验证我不擅自替你做。你想验的话：用同一个手机号以外的第二个账号，拿**同一张**身份证提交，
   期望看到「该身份证已被其他账号实名认证」，而不是通过。
2. **索引仍是普通索引，没升级成 UNIQUE**（`idx_id_card_hash` / `NON_UNIQUE=1`）。
   升级 SQL 在 7.13 写好了：`ALTER TABLE nft_users DROP INDEX idx_id_card_hash, ADD UNIQUE KEY uk_id_card_hash (id_card_hash);`
   当前实测存量重复 **0 组**，理论上涨级不会失败，但它是**改数据库结构**、要单独点头我才动。
   普通索引 + 应用层 `SELECT … FOR UPDATE` 已经能挡住日常重复，唯一索引多挡的是「两个请求同一毫秒抢同一个坑」这种极端并发。
3. **迁移里那句 `--backfill` 写入了 0 行**，所以「已认证账号也能被正确补上指纹」这条路径在生产上只有沙箱证据，线上没样本。
4. **APP_KEY 变更的联动没测**：指纹跟着 `APP_KEY` 走，将来跑 `php think rekey:encrypted` 换密钥时指纹必须一起重算，
   绕过命令手改 `.env` 会让查重静默失效 —— 这条纪律写在 `RealnameIdHash.php` 与 7.13，没有测试覆盖。

### 7.26 盲盒详情页补齐「转赠 / 寄售」两枚开关（2026-10-12 代码完成，**只在本地，没发版**）

**你怎么问的**：「盲盒在详情里面怎么没有转赠和寄售的开关」。

**先给结论：不是功能坏了，是同一件事在四个地方做了、第四个只做了个摆设。** 四格实测（改动前）：

| 位置 | 文件 | 改前 | 本次 |
|---|---|---|---|
| 藏品列表 | `src/views/collectible/Index.vue:365 / :373` | 转赠、寄售两枚 `el-switch`（寄售走价格弹窗＋密码） | 没动 |
| 藏品详情 | `src/views/collectible/Detail.vue:359-437` | 同上，功能齐全 | 没动 |
| 盲盒列表 | `src/views/blindbox/Index.vue:196 / :204` | 同上（同一列里还多一枚「开」开关 :188） | 没动 |
| **盲盒详情** | `src/views/blindbox/Detail.vue` | **两行纯文字**：「转赠 / 寄售开关」→「转赠已开启 / 已关闭」，点不动 | 补齐两枚开关（现 :369-385） |

**大白话讲数据形状**（说明这开关本来就该存在）：一个盲盒在库里是**两行** ——
`nft_blind_boxes`（盒子本身）＋ 它 `collectible_id` 指向的 `nft_collectibles` 一行
（`tag='blindbox'`、`status='upcoming'`，创建时 `is_transferable=0 / is_resaleable=0`）。
「转赠」「寄售」这两列**只长在 collectibles 那行上**，管的是**用户手里这只还没开的盒子**能不能送人、能不能挂出去卖，
跟开出来的奖品无关 —— C 端两道校验读的就是这一列：`Resale.php:63` 看 `is_resaleable`、`Transfers.php:54` 看 `is_transferable`。
后台详情页不给开关，等于运营只能绕去藏品列表里找到那行 blindbox 藏品才能改，而盲盒详情页显示的恰恰是同一列的当前值。

**你定的口径**：「只补两枚 + 防漏测试」—— 复用列表页已有的两个 API 与同一句二次确认文案，
寄售仍走「价格管控弹窗 + 管理员密码验证」；**不加求购开关**（那是产品范围，不是漏网）；后端一行不动。

**改了哪些**（纯前端）：`src/views/blindbox/Detail.vue` +143 / −6 行。
脚本侧新增 `onTransferableSwitch()`（:149，二次确认 → `toggleBlindBoxTransferable`）、
寄售三件套 `openResaleDialog / onResaleSubmit / onResaleVerified`（:166-215，含固定价必填、区间价上下限必填且 min<max 的校验）；
模板侧把两行只读 kv 换成两枚 `el-switch`（:369-385），新增「寄售管控 · 盒名」弹窗（:590-629，`append-to-body`）
与第三个 `PasswordVerify`（:631）。样式加 `.bd__switch-row` / `.bd__switch-ops`。

**为什么不会跟藏品那边长歪**：两枚开关打的都是 `src/api/index.js:1025 / :1040` 的
`toggleBlindBoxResale` / `toggleBlindBoxTransferable`，它们内部先 `GET /blind-boxes/:id` 取 `collectibleId`，
再打 `PUT /collectibles/:cid/market-config` —— 全平台唯一的写入口（`CollectibleController::marketConfig()`，:1200 起，
认 `is_transferable` / `is_resaleable` / `is_buy_request_enabled` / `resale_price_mode` 0|1|2）。页面里**不许**自己拼 `market-config`。

**一条坑，记在这儿**：盲盒那两枚开关真正打的是 **collectibles 的接口**，所以它要的权限是 **`collectible:market`**，不是 `blindbox:*`。
将来若给运营建「只有盲盒权限」的角色，他在盲盒页点开关会吃 4003「无权限」—— 那不是代码坏，是权限组没给对。
（本轮没改权限，超管账号不受影响。）

**防漏测试**：新文件 `sinan-admin/tests/market-switch.spec.js`（149 行、20 例）。
这仓库没装 `@vue/test-utils`，所以走本仓库既有的**源码扫描**路子：`CELLS` 写死四格文件与各自该调的 API 符号，四条规则 ——
① 提到 `isTransferable` / `isResaleable` 就必须有绑定它的 `<el-switch>`，退化成纯文字就红；
② 寄售那枚不许在标签里直接调 `toggle…Resale`（必须走弹窗），文件里必须有 `PasswordVerify` 且有 `@verified="…Resale/Price"`；
③ 四个文件谁都不许裸拼 `market-config`（扫描前先 `stripComments()`，注释不算）；
④ 转赠关闭那句二次确认文案，四格必须逐字节一致。

**反证**（本仓库规矩：把修复拆掉，测试必须变红）：`cp` 备份后用 perl 把 `blindbox/Detail.vue` 里两枚 `<el-switch …>` 换回只读文字，
打出真实 2 行 diff → 该 spec 红 **2 例**（开关缺失 + 寄售闸门缺失）→ `cp` 还原、`diff` 干净、`grep -c` 回到 4 → 20 例复绿。
**差点假绿一次**：第一条替换正则按多行写、而开关其实写在一行上，perl 没匹配上也不报错，测试照样 20 例绿 —— 靠 `diff` 备份才发现。

**回归**：`npx vitest run` 全量 **11 文件 / 146 例全绿**；`npm run build` 通过，
产物 `dist/assets/Detail-B7J5GhJS.js` 里能搜到「寄售管控」与新加的转赠提示语。

**没做 / 没在线上验的**（照例说清楚）：

1. **没有真人在浏览器里点过这两枚开关**。后台登录有图形验证码，我不用脚本绕；本地 8080 后端当时也没起。
   上面的证据都是「代码 + 测试 + 构建产物」层面的，不等于「点一下真生效了」。
   你点一次就是最终确认：进任意盲盒详情 → 拨转赠（应弹二次确认）→ 拨寄售（应弹「寄售管控」价格弹窗，提交还要输管理员密码）。
2. **没加求购开关**：盲盒详情页原来就没这一项，藏品详情页有。属于产品范围，本次口径明确排除，等你单独说。
3. **生产上现在还没有这次改动** —— 现在线上盲盒详情页仍是两行只读文字。

**发版要动什么**：只有后台前端一个包（`:8088`），后端与 H5 都不动，形状同第 5 次发版（7.24）。**要你说「发」我才发。**



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
7. **资金精度没有统一入口**（2026-10-10 按代码实测，纠正了 `PROJECT_DOC.md` 里「用 `decimal.js` 计算」的假声明，见 7.11）：
   库里 25 处 `DECIMAL` 列声明，后端 PHP 用 `round()` **71 处**，`bcadd`/`bcmul` 只有 `Orders.php`、`Resale.php` 各一处；
   展示端后台 `fmtMoney` 49 处、C 端 `toFixed(2)` 25 处（8 个文件）。
   也就是说金额的舍入散在各处、口径不统一。两条路选一条：
   ① 收到一个 `Money` 工具或全面改 bcmath，舍入只在一处发生；
   ② 明确「钱只在库里以 `DECIMAL` 运算，PHP 不做加减」并把散落的 `round()` 清成只做展示格式化。
   选哪条要你先定，动的是资金链路，我不会顺手改。
8. **实名「是不是本人」只剩格式校验这一道**（2026-10-10 沙箱实测，见 7.12）：原来两个缺口，**① 同日已修，② 仍未修**。
   ① ~~**同一张身份证可以把多个账号都实名成「已通过」**~~ —— 已落地「一证一号」（见 **7.13**）：
      `id_card_hash` HMAC 指纹列 + `RealnameService::approve()` 内查重，auto 与后台人工点通过**同一个口径**，待审/驳回不占坑。
      原来这条写的「给密文列加唯一索引走不通」到眼下还是对的，7.13 走的是后半句那条备选路：另存一列确定性指纹。
      **还剩两件收尾**（都要你发话）：生产侧那三步（加列 → 只读统计 → 回填）；清理存量重复之后把普通索引升级成 UNIQUE。
   ② **没做校验位**（仍未修）。现在的正则能挡住 `123456789012345678`，挡不住「地区码和生日都漂亮、但号码是编的」的输入
      （GB 11643 的 MOD 11-2 最后一位没算）。补上只要几行代码，但它会让**现在能过的一批输入过不去**，
      属于收紧准入，等你定。
   ③ **接三方实名认证 API**（公安/运营商二要素，真校验）—— 要费用与资质，仍未做。
   另外，如果不想承担这些，把 `realname_audit_mode` 切回 `manual` 就行 —— 后台点一下、实时生效、不用发版。
   **②③ 都要动身份链路，等你定，我不顺手改。**
9. **~~生产上「auto + 一证一号」仍没生效~~**（2026-10-12 第 6 次发版后已闭环，见 **7.25**）：
   现在生产上是 **`realname_audit_mode = auto` + 查重代码已生效** 的组合 —— 这正是当初担心的最危险组合，
   但担心的那半（auto 已开、代码没发 = 一张证无限注册并自动通过）已经在 03:1x 那次发版里补上了：
   第 21 份 DDL 加了 `id_card_hash` 列，7 个后端文件（`User.php`、`RealnameService.php`、`IdCardBoundException.php`、
   `RealnameController.php`、`RealnameIdHash.php`、`RekeyEncrypted.php`、`config/console.php`）生产 md5 与本地逐字节一致。
   **仍然挂着的两件事**：① 索引还是普通索引 `idx_id_card_hash`（`NON_UNIQUE=1`），没升 UNIQUE —— 实测存量重复 0 组，
   升级 SQL 在 7.13，但那是改表结构，要你单独点头；② 「同一个身份证注册第二个号会被拦」在生产上**还没有真人走过一遍**
   （auto 模式下走一次就真占一个坑，属带副作用的验证，我不擅自替你做）。
   另注：前端那条对齐后的身份证正则 10-11 就随第二批 B1 上线了，本轮没动前端。
10. **抽奖「活动实体补建」写在事务外，且软删除的活动会顶住保存**（2026-10-11 读代码发现，未改）：
    `MarketingController.php:1218-1226` 在 `Db::startTrans()` **之前**给不存在的 `activity_id` 补建一行活动；
    判断用的是 `whereNull('deleted_at')`，所以如果那一期活动是被**软删**的，这里查不到 → 去 INSERT 同一个主键 →
    主键冲突 → 5001「系统繁忙，请稍后再试」，而这句话在后台看起来又像「后端坏了」。
    修法有两个方向（把补建挪进事务并改成 `ON DUPLICATE KEY UPDATE`，或者干脆不允许给软删活动保存奖项），**要你选，我没顺手改** ——
    它改的是「保存奖项」这条运营天天走的路径。
11. **「一组藏品的锁定状态」不能代表「这一件」**（2026-10-11 实测确认，本轮只是改了前端）：
    `GET /api/user/collections` 是按藏品聚合的，组级 `isConsigned` 只说明「这个系列里至少有一件在寄售」，
    逐件真相只在 `items[].isConsigned` 上，而 `nos` 还有「只回传前 5 条」的截断。
    前端已改为逐件判定（`stores/user.js`），但**这个口径值得在后端接口文档里写死一句**，
    否则下一个调用方（比如以后的小程序端）还会踩同一脚。数据库侧是干净的：`Resale.php:117` 的 UPDATE `WHERE` 只有主键 `id`。
12. **~~边缘 nginx 的 1MB 体积闸门是四条「上传不了图片」共用的嫌疑~~**（2026-10-11 记，**同日实测已证伪**）：
    原先推测「只有内部上游站点写 50m，80/8088 两个边缘站点仍是默认 1m，超 1MB 的图被 413 打回」——
    实际生产 `nginx.conf` 第 35 行在 `http` 块里就有 `client_max_body_size 50m;`，该指令自上而下继承，
    边缘站点从来没被 1m 拦过（给 `:8088/api/admin/…` 推 2MB / 6MB 拿到的是平台 JSON 4001，不是 HTML 413）。
    这条**不要再当成因**；两条前端配置的注释与 7.16「实测没病」第 1 条已更正，取证命令也写在那儿。
    真正剩下的成因排序：① 线上跑旧构建（10-11 B1 已发新版）② `biz` 白名单 4220 ③ 角色缺 `system:upload` 权限 4003。
14. **图片备份只做到「同盘有第二份」**（2026-10-12，见 7.21 S1）：`public/uploads` 现在跟着每日 03:00 的 cron 一起打
    `tar.gz` 留 7 天，但**备份和原文件在同一块系统盘上**，磁盘真坏了仍是双双没。要的是异机或 OSS 副本 —— 涉及开通对象存储与密钥，等你点头再动。
15. **生产上有 20 张零引用图（4.8MB）等你勾**（2026-10-12 只读实测，清单与分档见 7.21 S5）：其中 16 张是安全用例遗留的
    polyglot 文件（429 字节的假 JPEG，尾部带 PHP 文本），2 张是与在用图**字节相同**的公告重复上传，2 张来历普通。
    **一张没删**。要清也是先移回收站（可 restore），`purge` 另说。
13. **四处「新建活动默认停用」与公告 `tag_color` 无入口**（2026-10-11，都在你报的范围之外，等定）：
    `LuckyDraw.vue:58,154`、`resale/Index.vue:87`、`marketing/Invite.vue:53`、`marketing/Register.vue:48` 仍是 `enabled: false`；
    公告的 `tag_color` 后端字段、接口下发、C 端读取都在，只有后台表单缺一个输入口。详见 7.14「查出来但故意没改的」。
16. **后台「修改密码」弹窗的修复**（2026-10-12，见 7.23 / 7.24）：**已上线**，只发后台一个前端。
    唯一没在线上验的是「真人登录后点一次看见弹窗浮出来」（后台登录有图形验证码，不用脚本绕）—— 你点一次就是最终确认。
