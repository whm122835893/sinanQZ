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
  > **2026-10-13 已被 7.28 取代**：铜鼎位图换成内联 SVG 简笔画开箱（本地完成，**尚未上线**）。7.5 那节讲的抠图做法仍是史实，别删。
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
          —— **2026-10-14 02:5x 这张证真的占过坑了，第二个号也被线上拦下**（用户授权后代跑，逐条证据见 **7.34** ③；测试账号与占的坑已随清除消失，线上 `id_card_hash` 非空回到 0 行）。

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

> **2026-10-13 现状更新见 7.28**：空状态已不再是位图，改成写在组件里的内联 SVG 简笔画开箱。
> 本节保留：一是「换图只改 `AppEmpty.vue` 一处」这个口径从头到尾没变；二是那套实物图抠图做法以后还会用到。

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
—— **2026-10-14 02:59 已由我代跑并线上复验**（见 7.34 ④）：真 HEIC 在桌面浏览器解不开 → **不发请求** + 三条指引；
内容是 JPEG 而后缀 `.heic` → 前端转码后**上传成功**（就是当年被误伤那一类）；硬把 HEIC POST 给闸门 → 带真实类型与体积的专属拒绝话术。
**仍缺的是真 iPhone** 那一次（手机相册直传的解码链路我这台浏览器给不了）。

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

### 7.26 盲盒详情页两枚开关：做了，又按你的话退回（2026-10-12，**生产从头到尾没动过**）

**你怎么问的**：「盲盒在详情里面怎么没有转赠和寄售的开关」→ 我按你选的口径（只补两枚 + 防漏测试）做完了，
你听完解释后说「**那就不要这个，返回上一个版本**」→ **已退回**。下面这段的价值不在改动，在**实测结论**：
这四格现在是什么样、这开关到底改的是哪一列、以及真要补该怎么做。

**实测四格（现状 = 生产上的样子，跟本轮开头一样）**：

| 位置 | 文件 | 现在 |
|---|---|---|
| 藏品列表 | `src/views/collectible/Index.vue:365 / :373` | 转赠、寄售两枚 `el-switch`（寄售走价格弹窗＋密码） |
| 藏品详情 | `src/views/collectible/Detail.vue:359-437` | 同上，功能齐全 |
| 盲盒列表 | `src/views/blindbox/Index.vue:196 / :204` | 同上（同一列里还多一枚「开」开关 :188） |
| **盲盒详情** | `src/views/blindbox/Detail.vue:292-296` | **两行纯文字**：「转赠 / 寄售开关」→「转赠已开启 / 已关闭」，点不动 |

**数据形状（大白话，这条最容易踩）**：一个盲盒在库里是**两行** ——
`nft_blind_boxes`（盒子本身）＋ 它 `collectible_id` 指向的 `nft_collectibles` 一行
（创建时 `BlindBoxController.php:181` 打上 `tag='blindbox'`，`status='upcoming'`、`is_transferable=0 / is_resaleable=0`）。
「转赠」「寄售」这两列**只长在 collectibles 那行上**，全平台就这一份，没有「盒子一份、藏品一份」两本账。
所以**在盲盒页拨开关 = 拨那一行藏品的同一列**，不是动了别的东西；它管的是**用户手里那只还没拆封的盒子**
能不能送人、能不能整只挂出去卖 —— C 端两道校验读的就是这一列：`Transfers.php:54` 看 `is_transferable`、
`Resale.php:63` 看 `is_resaleable`（都用 `$uc['collectible_id']` 定位那一行）。

**开出来的奖品是另一回事**：`BlindBoxes::open()`（:118）把盒子那行资产 `status` 改成 `consumed`，
再新插一行 `user_collectibles` 指向 `prize_collectible_id` —— 奖品能不能转赠/寄售，看**奖品自己那行藏品**的开关，
在盲盒页拧不到它。比喻：盲盒是登记在藏品总台账上的一行「密封信封」，那两列是这一行的格子，写的是「这封信允不允许转手」；
拆开后的东西在台账上另占一行、另有一套格子。

**一条权限坑，值得留着**：盲盒页若要这功能，走的仍是 `PUT /collectibles/:id/market-config`
（`CollectibleController::marketConfig()`，:1200 起，全平台唯一写入口），要的权限是 **`collectible:market`**，**不是 `blindbox:*`**。
将来若给运营建「只有盲盒权限」的角色，他在盲盒页点开关会吃 4003「无权限」—— 那不是代码坏，是权限组没给对。

**做完又退回的那部分**（留档，将来要复用就是现成方案）：
`sinan-admin/src/views/blindbox/Detail.vue` +143/−6（`onTransferableSwitch` 二次确认、
寄售三件套 `openResaleDialog/onResaleSubmit/onResaleVerified` + 「寄售管控」弹窗 + 第三个 `PasswordVerify`，
全部复用列表页的 `toggleBlindBoxTransferable` / `toggleBlindBoxResale`，`src/api/index.js:1040 / :1025`）；
外加 `tests/market-switch.spec.js`（149 行、20 例源码扫描防漏测试：
提到这两列就必须有绑定的 `<el-switch>`、寄售不许绕过弹窗、页面不许裸拼 `market-config`、转赠确认文案四格逐字节一致）。
当时反证过：拆掉两枚开关 → 该 spec 红 2 例（开关缺失 + 寄售闸门缺失），还原 → 20 例复绿
（**差点记一次假绿**：第一条 perl 替换按多行写、开关其实写在一行上，正则空匹配不报错、测试照样全绿，靠 `diff` 备份才发现）。

**退回的证据**（都要能复核）：
`git checkout 35e427e -- sinan-admin/src/views/blindbox/Detail.vue` → `git diff 35e427e -- <该文件>` 输出 **0 行**（逐字节回到上一版）；
`rm sinan-admin/tests/market-switch.spec.js`（防漏测试连着一起删，它钉的就是那枚开关，留着必红）；
`npx vitest run` → **10 文件 / 126 例全绿**（退回前是 11 文件 / 146 例）；`npm run build` 通过，
产物里搜「无偿转赠这只盲盒」= **0 处**，「转赠已开启」这句只读文案回来了，
`dist/assets/` 里「寄售管控」只剩 1 处 —— 那是藏品详情页那份，本来就有。

**结论**：**没有第 7 次发版**。生产上后台还是 7.24（改密码弹窗）+ 7.25（一证一号）之后的状态，本轮一行没上线、一个文件没换。
这条退回不影响任何已上线的东西。若将来你想再补，上面「复用哪个函数、走哪个口、要什么权限、该配哪条防漏测试」就是完整方案。
2026-10-12 你已回过其中一件：**盲盒暂时不要「求购」开关**（不是「以后要」，是这轮明确不做），
剩下只等一件：权限组给不给非超管角色 `collectible:market`。

### 7.27 你 2026-10-12 下午批量拍的板 + 两件落地（孤儿图进回收站、存量编号重编上库）

**你的原话与对应处理**（这一小节就是「决定台账」，将来别靠回忆）：

| 你的决定 | 落到哪 | 现在的状态 |
| --- | --- | --- |
| 存量编号重编「落库」 | 见下方「二、存量编号重编」 | **已上库**（23 件） |
| 孤儿图「清」 | 见下方「一、孤儿图」 | **已移回收站**，`purge` 没跑 |
| 盲盒暂时不要「求购」开关 | 7.26 结尾已更正 | 不做，藏品页保持有、盲盒页保持没有 |
| 资金精度暂不管，后续接第三方钱包 | 本轮不动 `decimal(10,2)` 与取整口径 | 挂起；接钱包时再评估（要留痕：这是**已知延后**，不是没发现） |
| 抽奖活动的问题「按行业最优处理」 | 见下方「三、待做的行业最优批次」 | 本地做，线上要第 7 次发版 |
| **「没提到的都按照行业最优选择处理」** | 同上，剩下几项按行业常规一并进这批 | 同上 |

**一、孤儿图清理（走平台自己的可逆机制，没有 `rm`）**

先纠正我自己记错的那笔账：7.21 S5 那句「20 张零引用图 / 4.8MB」**是错的**，
错法是拿数据库快照 grep `/uploads/` 前缀数引用，而绝大多数记录存的路径没这个前缀；
basename 又只匹配到 `nft_admin_operation_logs`（上传审计行），于是把**在用的图当成了零引用**。
**正确口径只有一个：跑平台自己的扫描器** `UploadCleanupService::scanUnreferenced()`
（`qa/probe_upload_scan_readonly.php` 只读打印，不写任何东西）。当场实测：

- 可清理 **18 张 / 212,975 字节**；受保护 **8 张 / 10,424,987 字节**（受保护 = 被正文引用 / 在 24h 宽限期内 / 目录不在 `BIZ_DIRS`）；
- 全库真正被引用的上传 URL 只有 **1** 个。

执行链（`qa/trash_orphan_uploads.php`，顺序不可倒）：
① 先 `tar` 整个 `public/uploads` 存档 → md5 `de07239ed4607275cbb4792ee8a586fd`、12,868,622 字节；
② `scanUnreferenced()` 重扫 → `trashFiles()`（`rename()` 进 `runtime/upload_trash`，同盘、保留属主与权限、可 `restore`）；
③ 对「受保护」的那 8 张逐个 `releaseIfUnreferenced()`：**先重新查引用**，仍被引用的**一张没动**；
   （这句 2026-10-14 补一句免得读歪：重新查的结论是**这 8 张也确实零引用**，所以它们**一起进了回收站** ——
   「一张没动」说的是「没有一张仍被引用却被移走」，不是「8 张都留在原地」。回收站最终 26 张 = 18 + 8，见 7.31 的逐张 `isReferenced()` 复核。）
④ 重扫 + 复核：`public/uploads` **27 → 1 个文件**，`runtime/upload_trash` **26 个文件 / 10,637,962 字节**，重扫结果 **0 张可清理**。
**`purgeTrashFiles()` 从头到尾没调用**，所以现在有两层退路：回收站里能 restore，今天的 tar 里也有一份。

线上复验（都走公网 IP，不用 127.0.0.1，见 7.21 的假 404 教训）：
那张仍在用的封面图 HTTP **200**；`/api/collections/1`、`/api/market/collections`、`/api/banners` 全 `code:0`；
后台 `:8088` **200**；当天日志 `fatal|uncaught` **0 条**。

**二、存量编号重编：第一次落库当场失败，抓出一个真 bug**

预览（只读）：`php think serial:renumber` → 共 **3 个藏品、23 件资产**待改号，方案落盘
`runtime/serial_plans/renumber_20261012_181435.json`（藏品 1「大绵羊」12 件、藏品 2「青色」4 件、藏品 3「呃呃呃」7 件；
待同步的编号快照：换计划 0 行、分解台账 1 行、站内通知 2 条；`minted` 0 件，链上凭证不涉及）。

备份先行：`mysqldump --single-transaction` → `runtime/pre_renumber_20261012/sinan_nft_before_renumber.sql.gz`
（43,215 字节，md5 `1f22c41a09033d24c70379eac1c80a28`），另存改号前的 23 行清单 `serials_before.txt`。

**第一次 `--apply` 报错并整体回滚**：
`SQLSTATE[23000] 1062 Duplicate entry '1-SN-1-0247' for key 'nft_user_collectibles.uk_collectible_serial'`。
根因不是数据，是**命令自己的写号顺序**：`uk_collectible_serial` 是 `(collectible_id, serial)` 唯一索引，
而方案里资产 3 要发的新号 `SN-1-0247` **正是资产 22 当时还占着的旧号**。原实现写的是「一行挪到中转位 `TMP-R-{id}`、
紧接着就落最终号」，逐行这么走必然踩到还没改走的邻居旧号。回滚干净：跑完后 `serials_before.txt` 与库内 diff 为空、
`serial LIKE 'TMP%'` 计数 **0**，**一行没改坏**。

修法（`app/command/SerialRenumber.php`）：写号拆成**真正分开的两段** —— 先把本藏品所有待改行**整批**挪进中转位腾出旧号，
再**整批**落最终号（两段之间不夹任何落号动作）；核对阶段另加一道预检：若某行现有编号正好占了要用的中转位
`TMP-R-{资产ID}`，提前给人话报错而不是留到写库抛 1062。

沙箱证据（`qa/sit_serial_swap_collision.php`，专造**三行循环换号** A→B 旧号、B→C 旧号、C→A 旧号，
这种排布下任何「挪一格落一格」都必红）：**8 项全过**（退出码 0、无 1062、三行最终号=方案号、无 `TMP-R-*` 残留、
同藏品内不重号、站内通知里的编号快照跟着变、最大号 ≤ 发行量）。
**反证做过并留了痕**：把修复拆回逐行版（`diff` 打出被删掉的 6 行才敢信）→ 该脚本 **5 项转红**、
报错与生产那条 1062 一字不差、库内三行一个没变；`cp` 还原 → `diff` 空 → 重新全绿。夹具（临时藏品/资产/通知）跑完整体清掉。

**第二次 `--apply` 成功**（生产）：
`重编完成：23 件资产改了编号；同步换计划 0 行、分解台账 1 行、站内通知 2 条。`
复核（全部当场实测）：
- 藏品 1：12 件、最大号 947 ≤ 发行量 1000、`COUNT(DISTINCT serial)` 12；藏品 2：4 件、99 ≤ 100；藏品 3：7 件、102 ≤ 111；
- `serial LIKE 'TMP%'` **0 行**（中转位没留垃圾）；
- 改前 vs 改后 `diff` **正好 46 行**（23 删 23 加，别的行一个字没动），两份清单都存在 `runtime/pre_renumber_20261012/`；
- 快照跟上了：`nft_decompose_records` 那条 `source_serial` 现为 `SN-1-0947`，`nft_inbox` 两条 `extra.serial` 现为 `SN-1-0554`/`SN-1-0200`；
- 线上 smoke：三个 C 端接口 `code:0`、后台 200、当天 fatal 0 条。

**发版口径**（要写清楚，别糊）：本轮只往生产放了 **1 个文件** `app/command/SerialRenumber.php`（纯命令行工具，
不在任何 HTTP 路由上，Web 面一行没变），回滚点 `app/command/SerialRenumber.php.before-renumber-fix-20261012`
（md5 `809f0a4f11e86bae859a9c217387afd3`，与仓库 HEAD 逐字节相同），新文件双端 md5 `ef08ca9a3bce4b6075a6961da16fa296` 对齐，
`php -l` 通过，生产 diff 只有这两处改动。它服务于你已经点头的那次数据操作，**没有另计一次发版**；
两个前端包**一个字节没动**。

**三、待做的「行业最优」批次（本地做完、测完，线上要单独批准第 7 次发版）**
① 抽奖：`MarketingController.php:1218-1226` 的中奖资产补建挪进事务内/改 upsert，并对「已软删活动」拒绝再保存；
② 一证一号：先把 `RealnameService::approve()` 的重复键异常接住（否则并发下会冒 5001），再把 `id_card_hash` 索引升 UNIQUE；
③ 图片像素尺寸闸门（S6）与 `/uploads` 的 access_log（S4，给 S7 找根因用）；
④ 公告 `tag_color` 补后台输入口；「新建活动默认停用」**保持现状不动**（默认停用是行业常规，避免半成品被用户看见）；
⑤ 文档化一条容易误判的口径：盲盒「组」级 `is_consigned` 不等于「你手里这一件」在寄售。

### 7.28 空状态换成简笔画箱子图标（2026-10-13 本地做完并测过 → **2026-10-14 00:4x 已上线，见 7.31**）

你的原话：「空状态改成简笔画箱子图标，符合网站特点」。

**换了什么**：`AppEmpty.vue`（全站 14 个视图共用的那一个空状态组件）里那张 50,423B 的铜鼎位图，
换成写在组件内的**内联 SVG**：等轴视角的开口箱（两片后翻盖支起），箱身下沿绕一圈**回纹** ——
回纹取自平台文物图样上那圈纹饰，是「符合网站特点」的落点；C 端只有这一个空状态出口，所以 14 个页面一起变。

**为什么用内联 SVG 而不是再出一张 PNG**：
① 不用再靠 `?v=` 跟 nginx 启发式缓存较劲（JS 分片名自带 hash，换图后老访客必然看到新的）；
② 回纹的描边写的是 `$color-primary` → 编译成 `var(--color-primary)`，**后台改主题色，图标跟着变**，位图做不到；
③ 120×120 实物图 50KB，这套线条约 1KB，任何屏幕密度都不糊。

**颜色口径**（都走设计令牌，没写死十六进制）：箱体线条 `$color-text-secondary` + 整组 `opacity .55`（只做「有个箱子」，不抢文字），
回纹 `$color-primary` + `opacity .85`。主题色联动不是嘴上说说：把 `--color-primary` 临时改成 `#1A6BFF` 重渲染，回纹真的变蓝。

**验收怎么做的**（重点是「看的就是要发出去的那份」）：
- 全量 `npx vitest run` **14 文件 / 128 例全过**（含新增 `tests/app-empty.spec.js` 7 例）；`npm run build` 通过；
- 构建产物核对：`dist/assets/index-*.css` 里是 `stroke:var(--color-text-secondary)` / `stroke:var(--color-primary)`，
  `120px` 被 postcss-px-to-viewport 换成 `32vw`（375 视口下正好 120px）；
- 外观截图**不是手抄的近似版**：用一次性夹具打出组件真实渲染的 DOM，配上从 `dist` 里取出的真实 CSS
  （连 scopeId 都对齐），375 宽无头浏览器渲染 —— 看到的就是线上会看到的。夹具跑完即删，仓库里没留。
- **反证做过**：把两处修复拆掉（`description=` 改回 `text=`、往源码里塞一句 `empty-ding.png` 引用），
  防漂移那 2 例立刻转红；还原后 diff 归零、重新全绿。拆/还都用 node 字符串替换做，没用 `sed`
  （`sed` 会把 CRLF 改成 LF，整份文件在 git 里显示全改，看不清真改了哪几行）。

**顺手修的一个真 bug**：`Raffle.vue` 两处写的是 `<AppEmpty text="暂无抽签活动">`，而组件只认 `description`，
所以抽签页一直显示兜底的「暂无数据」。已改成 `description=`，并由测试盯住「不许再用 `text=`」。
（同时删掉了组件里那个从没被任何页面用过的 `image` prop。）

**没做 / 待定**：
- 后台（`:8088`）的空状态用的是 Element Plus 自带插图 `el-empty`，**这次没动**，要不要换成同一个箱子等你说；
- 两张旧位图 `empty-ding.png`(50KB) / `empty-carton.png`(5.9KB) 留在 `public/images/tab/` 当退路，**没删**；
- 页面里那些纯文字的局部空提示（如「暂无寄售」「仓库暂无藏品，先去获取吧～」）本来就没有图，未改。

**发版口径**：本轮只涉及 **H5 一个前端包**（`AppEmpty.vue` + `Raffle.vue`，另加一份测试），后端与后台一个字节没动。
**线上目前仍是铜鼎图**，要上线得重建并部署 H5 包 —— 可与第 7 次发版并批，也可单独发一次，等你点头。

### 7.29 邀请活动保存那句「未知类型」：根因与报错带位置（2026-10-13 本地做完并测过 → **2026-10-14 00:4x 已上线，见 7.31**）

**你报的原话**：「邀请活动保存显示位未知类型」→ 要的是「报错说清位置 + 类型」，范围**只改邀请这一套**
（签到 / 注册 / 抽奖 / 合成共用的那条路本轮明确不动）。

**根因（先用只读探针坐实，别猜）**：`qa/probe_invite_reward_readonly.php`（本地沙箱跑，用完即删、不进 web 目录）
把后台下拉里每一个类型逐个送进保存校验，结果：

```
  RewardGrantService::TYPES      = collectible/priority_qualification/eligibility_qualification/draw_chance/blindbox/points
  ActivityRewardService 中文标签 = 同上 + none
  差额（能显示但不能保存的类型） = none
  [报错] type=none     → 未知奖励类型 none
  [报错] type=blindbox → 盲盒奖励必须指定 blindboxId
```

两句话把症状解释完了：

1. 后台共享组件 `RewardListEditor.vue` 的下拉里**有「无奖励」(none)**，而邀请页没传 `types` 白名单，
   于是运营选得到它；后端 `RewardGrantService::TYPES` 里没有 `none`，
   `inviteSave → normalizeRewards → validate` 直接抛「**未知奖励类型 none**」——这就是你看到的那句。
   注意它不是「报错文案不好看」这么简单：发放侧（`ActivityRewardService::settleInviteReward`）同样不认 `none`，
   真让它存进档位，结算时会在同一个地方炸。所以修法是**这条选项在邀请里不该存在**，而不是把 `none` 供起来。
2. 报错不带位置。`normalizeRewards()` 循环里调 `validate()` 时把「第几档 / 第几个奖励位」丢掉了，
   八项奖励里坏一项，也只有一句「藏品 #999999 不存在」。

**改了什么（三处，全在邀请这一套里）**：

- 后端 `MarketingController.php`：新增私有 `normalizeInviteRewards($rewards, $where)`，
  规则与共用 `normalizeRewards` 一致（非空、≤10 项、类型走 `RewardGrantService::validate`），
  只是把「哪一档 · 第几位（类型中文名）」拼进报错；`inviteSave()` 的档位循环与被邀请人奖励都改走它，
  档位人数那两句也补了「第 N 档」。共用的 `ActivityRewardService::normalizeRewards()` **一个字没动**。
- 后台 `Invite.vue`：新增 `INVITE_REWARD_TYPES`（= 后端 TYPES 六种）传给两处 `RewardListEditor`，
  「无奖励」从邀请的下拉里摘掉；`REWARD_TYPES` 表里的 `none` 留着只为兜底展示历史数据。
  共享组件与签到 / 注册 / 抽奖页没传 `types`，行为不变。
- 同页保存前的前端提示同样带上位置（`第 N 档（邀请 X 人） · 第 M 个奖励位（类型）：原因`），
  只有一项时不硬写「第 1 个」。

**改完的报错长这样**（探针第 4 节实测输出）：

```
  邀请 3 人档（无奖励）：「无奖励」不能算作一项奖励，请改成具体类型或删掉这一项
  邀请 5 人档 · 第 2 个奖励位（司南币）：司南币奖励金额必须大于 0
  邀请 5 人档 · 第 2 个奖励位（盲盒）：盲盒奖励必须指定 blindboxId
  被邀请人奖励（藏品空投）：藏品奖励必须指定 collectibleId
```

**测了什么**：
- 后端 `tests/unit/InviteRewardErrorPositionTest.php`（9 例 / 全库单测 209 例绿）：反射调私有方法验不连库的分支，
  加两条防漂移 —— ① `inviteSave` 体内不得再出现 `ActivityRewardService::normalizeRewards` 与裸 `RewardGrantService::validate`；
  ② 邀请白名单必须等于后端 `TYPES`（读 PHP 常量对照，防前端自己列歪）。
- 后台 `tests/invite-reward-type-gate.spec.js`（9 例 / 全库 135 例绿）：两处 `:types` 都在、白名单不含 `none`、
  共享编辑器仍保留全量（证明没顺手改别人）、签到 / 注册 / 抽奖页不得出现 `:types=`。
- **反证做过**：用 node 脚本（不是 sed，见 7.20 的坑）把后端那两行拼接口拆回原样并 **当场打出 diff**（1030 / 1035 两行），
  测试立刻红 3 例；把前端 `:types` 两行删掉（打出 388 / 410 两行 diff），spec 立刻红 1 例；
  之后 `cp` 回备份，`diff` 输出 0 行 + 全量重跑绿。
- `npm run build` 后台包通过（Invite.vue 是真编译过的，不只是源码扫描）。

**没做 / 要你定的**：
1. ~~**盲盒在邀请里其实还是存不进去**~~（**2026-10-14 已按你的「那就修共享组件」修完，过程见 7.30，仍未发版**）：
   当时查到的是 `RewardListEditor` 只有「数量」一格、没有选具体盲盒的地方，而后端 `blindbox` 分支要求 `blindboxId`，
   签到 / 注册 / 抽奖同样中招。本轮（7.29）只做到「报错指到具体那一格」。
2. ~~**端到端没在线上验**：后台登录有图形验证码，不用脚本绕（见 7.24 同条）。你上线后在邀请活动里选一次「盲盒」保存，
   看到那句带位置的报错，就是最终确认。~~
   **2026-10-14 02:5x 已由我代跑并线上复验**（用户授权，逐条证据见 **7.34** ②）：类型下拉里**已经选不到**「无奖励」（这正是本轮要的效果），
   所以我用同一个登录态直接打那个接口送非法档位，拿到的就是那句带位置的 4220 报错
   （`被邀请人奖励 · 第 1 个奖励位（…）：「无奖励」不能算作一项奖励…`），
   而且**保存失败时一个字节都没落库**（那一行的 `tiers` md5 与 `updated_at` 一动没动，拦截在写库之前）。
   测试活动已随本轮清除，线上 `nft_invite_activities` 回到 0 行。
3. ~~**发放侧遇到历史脏数据仍会抛同一句**~~（**2026-10-14 连生产只读查过：存量 0 条**）：
   `settleInviteReward` 走的是共用校验，本轮没动。生产现值 —— `nft_invite_activities` **0 行**、
   `nft_check_in_activities` 1 行、`nft_register_activities` 1 行，三张表的奖励 JSON 里**都没有 `none`**，
   所以「历史脏数据在结算时炸」这条在当前数据量下不成立，不需要清理。新数据侧已被下拉摘掉 + 保存拦死。

**发版口径**：本轮动 **后端 1 个文件（`MarketingController.php`）+ 后台前端 1 个文件（`Invite.vue`）**，
各加一份测试；H5 与数据库一个字节没动。需要**单独点头**才能上生产（可与 7.28 那个 H5 包并成第 7 次发版）。

### 7.30 奖励编辑器选不了盲盒：共享组件补上下拉（2026-10-14 本地做完并测过 → **同日 00:4x 已上线，见 7.31**）

**你怎么点的头**：7.29 里我留了第 1 条「盲盒这一类四个活动都存不进去，修要动共享组件，等你点头」，
你的回复是「**那就修共享组件**」。所以下面这些动的不是你原先划的「只改邀请」那一小块，而是四活动共用的那一层 —— 这是你授权的，不是我顺手扩的。

**洞是什么（大白话）**：后台配奖励时那个「奖励类型」下拉里有「盲盒」这一项，但选中它之后，
下面只出现一格「数量」，**没有让你挑「发哪一个盲盒」的地方**。而后端存这条奖励时必填的是 `blindboxId`
（哪个盒）。所以只要有人真去选「盲盒」，保存必然被顶回来。用探针坐实的话说就是这样：

```
  [报错] 没选具体盲盒（旧前端就是这样） → RewardGrantException: 盲盒奖励必须指定 blindboxId
  [报错] 选了个不存在的盒             → RewardGrantException: 盲盒 #999999 不存在
  [报错] 错把藏品 id 当盲盒 id 传     → RewardGrantException: 盲盒 #9608 不存在
```

原来那行代码把「抽奖次数」和「盲盒」写在同一个 `v-else-if` 里共用一格数量输入，等于给盲盒漏配了控件 ——
抽奖次数只要一个数字就完整了，盲盒还必须指着一个具体的盒。

**改了什么（前端 5 个文件 + 后端 1 个文件，全在「盲盒」这一类上）**：

- 共享组件 `RewardListEditor.vue`：① 新增 `blindboxes` 入参（缺省空数组，老调用方不会炸）；
  ② 把「抽奖次数 / 盲盒」那一行拆开，盲盒换成**下拉（值=盲盒 id，显示=盲盒名）+ 数量**，
  盒列表为空时提示「还没有盲盒，请先到「盲盒管理」新建」，让人知道去哪儿补；
  ③ 选「盲盒」时初始化 `{ blindboxId: null, quantity: 1 }`；④ 列表摘要从「盲盒 ×1」变成「盲盒「审计盲盒」×1」。
- 四个活动页 `Invite.vue` / `Register.vue` / `CheckIn.vue` / `LuckyDraw.vue`：各自多取一次
  `getBlindBoxList`（跟后台「盲盒管理」同一个接口，`id` 来自 `blind_boxes`、`name` 是 join 藏品得来的），
  传给编辑器，并在**点保存的最前面**拦一句「盲盒奖励需选择具体盲盒（发哪一个）」，
  让运营在请求发出去之前就知道哪一格没填。后台用这个组件的页面经全仓扫描确认**只有这四个**，一个没漏。
- 后端 `ActivityRewardService::rewardLabel()`：`blindbox` 那一档原来只会输出「盲盒×1」。
  现在 join 藏品把盒名带出来（`blind_boxes` 表**本身没有 name 列**，名字在它绑定的藏品上）。
  历史数据里 `blindboxId` 是空的，这类**退回原来的「盲盒×N」**而不是抛「盲盒「#0」」——
  导出一张中奖名单不该被一条老记录整份打断。
- 共用的保存校验 `ActivityRewardService::normalizeRewards()` **仍然一个字没动**（7.29 那条口径不变）；
  组件下拉里的「无奖励」也**保留**，因为抽奖的「空奖」靠它。

**测了什么**：
- 后台 `tests/blindbox-reward-picker.spec.js` 新增 **18 例**，全库 `vitest` **153 例绿**。含三条硬约束：
  ① 组件里那条「抽奖次数 || 盲盒」共用控件的写法不得回来；② 每个 `<RewardListEditor` 出现的次数必须等于
  `:blindboxes` 绑定的次数（少绑一个页面 = 那个活动还是选不了盒）；③ 跨端契约 —— 前端交出去的键名 `blindboxId`
  必须就是后端 `validate()` 读的那个，且发放侧是拿它当 `blind_boxes.id` 去解析藏品的（**不是藏品 id**）。
- 后端 `tests/unit/BlindboxRewardWiringTest.php` 新增 **8 例**，`--testsuite unit` 全库 **217 例绿**
  （唯一那条 deprecation 属 7.29 之前就有的 `UploadImageGateTest.php:148`，与本批无关）。
- `npm run build` 后台包通过：新加的下拉是真编译过的，不只是源码扫描。
- **反证做过三次，每次都当场打出「真的拆了」的证据**（见 7.20 那个正则空匹配的坑）：
  ① `git checkout` 把组件与后端文案两处退回旧版 → 打出「组件里 `blindboxId` 出现 0 次、共用控件那行回来了」，
  测试立刻红 **前端 5 例 + 后端 4 例**；② 只把 `CheckIn.vue` 的一枚 `:blindboxes` 删掉 → 打出剩余绑定数=0，
  对应那条「一处不落」的用例立刻红 **1 例**；③ 之后 `cp` 回备份，双端 md5 与拆前逐字节一致，全量重跑绿。
- 只读探针 `qa/probe_blindbox_reward_readonly.php`（不写库）留档，跑法写在文件头。

**生产只读现值（2026-10-14 当场量，只 SELECT）**：`nft_blind_boxes` 有 **3** 个盒，
三个盒绑定的藏品都还在（名字分别是「青色」「呃呃呃」「单独的」），所以上线后下拉不会空着；
四类活动的奖励配置里**没有任何一条是盲盒**（invite 0 行、checkin 1 行、register 1 行、lucky 奖项 1 行，
`blindbox` 命中 0）—— 因为这个洞的存在，本来就存不进去，所以**没有历史数据要回填**。
本地沙箱同样是 0 条。

**没做 / 要你定的**：
1. ~~**端到端没在线上验**：后台登录有图形验证码，我不用脚本绕（与 7.24、7.29 同条）。
   上线后你在任一活动里选一次「盲盒」、挑一个盒、保存，看到奖励摘要变成「盲盒「名字」×N」就是最终确认。~~
   **2026-10-14 02:5x 已由我代跑并线上复验**（用户授权，逐条证据见 **7.34** ①）：下拉选「盲盒」后「请选择盲盒」
   那一格确实出现、三个盒都能挑、保存返回 `code:200`，奖励摘要渲染成「盲盒「青色」×1」这种形状。
   **注意仍有一处没验**：`rewardLabel` 只证明了**配置侧的显示**，「真发一个盲盒到用户账户」的**发放侧**依旧没跑过（见本条 3）。
2. **盒的藏品被删时的显示**：`rewardLabel` 查不到名字会退回「盲盒「#id」」。要做成「显示（已删除）」得再定口径，
   目前不擅自改，因为它会影响所有导出文件的文案。
3. **抽奖 `grantMode=manual` 的中奖名单**走的是同一句 `rewardLabel`，所以名单文案会一起变好；
   但**发放侧**我只读了代码没跑过（要真发资产），上线后第一次发盲盒奖励请人工盯一眼到账记录。

**发版口径**：第 7 次发版现在是 —— 后端 **2** 个文件（`MarketingController.php`=7.29、`ActivityRewardService.php`=本节）
+ 后台前端 **5** 个文件（共享组件 + 四个活动页）+ H5 空状态包（7.28）；
数据库一个字节没动，不需要迁移。仍然**单独点头**才上生产。

### 7.31 第 7 次发版上线记录（2026-10-14 00:41~00:47，你说「发」）

**这批上线的是什么**（三个来源合批发，代码基线 = commit `1738b5c`）：
7.29 邀请活动保存报错带档位/奖励位、7.30 奖励编辑器能选盲盒（共享组件 + 四个活动页 + 名单文案带盒名）、
7.28 H5 空状态换成简笔画箱子图标。**数据库没动**：不加列、不跑迁移、不回填（7.30 已实测存量里 0 条盲盒奖励）。

| 项 | 内容 |
| --- | --- |
| 后端文件（2） | `app/admin/controller/MarketingController.php` → md5 `579fd0952b4ebdb48a39760b0f8e23ee`（2,624 行 / 126,261 B，发前是 `e3f4cdb8b80423b706eec5e6094e25ad`）<br>`app/service/ActivityRewardService.php` → md5 `5ce6e8b8a79f29190eddfba221f36bca`（693 行 / 32,738 B，发前是 `07521b5be79279bacc013c69d607d75e`） |
| 后台前端 | 整包叠加解包 `/www/wwwroot/sinan-admin`，主 JS 从旧 hash 换成 `assets/index-B-XlNegR.js`（1,337,324 B，HTTP 200） |
| H5 前端 | 整包叠加解包 `/www/wwwroot/sinan-h5`，主 JS `assets/index-sR4yu9kT.js`（468,483 B，HTTP 200） |
| 落位与权限 | 后端两文件 `chown www:www` + `chmod 644`，`/www/server/php/84/bin/php -l` 两个都 `No syntax errors`；`php think clear --cache` 后 `chown -R www:www runtime` |
| 回滚点 | `/root/sinan-release7-20261014_004129`（指针文件 `/root/sinan-release7-latest`）：`backend/` 两个旧文件、`frontend/sinan-admin` 与 `frontend/sinan-h5` **整目录**拷贝、`snapshot/` 三份快照 |
| 发版前快照 | `sinan_nft_before_release7.sql.gz` 43,680 B（解压 270,429 B、80 个 `CREATE TABLE`、45 行 `INSERT`，md5 `1dd9c75e247db2f130f2539230e3e429`）；`uploads_before_release7.tar.gz` 2,383,338 B（md5 `7e3e7dfa635c2a94ec3eba23a629c6dc`）；`trash_before_release7.tar.gz` 10,485,830 B（md5 `525c999316a0827b2cba869180fcc198`） |

**回滚怎么做**：后端 = 把 `backend/` 那两个旧文件 `cp -p` 回原位 + `php think clear --cache`（实时生效，不用重启）；
前端 = `tar czf - -C <回滚点>/frontend/sinan-admin dist` 那条解包命令反过来打一次（整目录拷贝在，不靠旧分块）。

**发版过程中我自己踩的三个坑**（都当场纠正了，记下来免得下次再犯）：
1. **抄错目录**：`MarketingController.php` 的真实路径是 `app/admin/controller/`，我按 `app/controller/admin/` 抄了一次，
   `cp` 静默失败 —— 是 `md5sum` 只打出一个文件才暴露的。**教训：备份/部署后按文件数与 md5 逐条对，别看命令没报错就当成了。**
2. **mysqldump 走 unix socket 连不上**：`mysqldump -u sinan_app -p"$PASS"` 直接 `1045 Access denied for user 'sinan_app'@'localhost'` ——
   这个账号只授了 TCP（`127.0.0.1`）。平台自己的 `scripts/backup_db.sh:40` 一直是 `MYSQL_PWD="$PASS" mysqldump -h127.0.0.1 -P3306 -u…`，
   照这个写法一次就过。**顺带两个好处**：密码不进 `ps` 进程列表，`localhost` 与 `127.0.0.1` 在 MySQL 眼里是两个不同的 host。
3. **`--default-character=utf8mb4` 是无效选项**（正确名 `--default-character-set`），mysqldump 报错后我原来的管道照样写出了 `.sql.gz`，
   得到的是一个**20 字节的空 gz**，而 `gzip -t` 判它「完整」。**教训：备份要当场 `zcat | wc -c` + 数 `CREATE TABLE`，不能只测压缩包。**
   （另有一条无害提示 `need the PROCESS privilege … dumping tablespaces`，不影响导出，每日 cron 那份也一样有，规模对得上：43,680 B vs 昨日 43,257 B。）

**顺手闭环的一件事：`public/uploads` 只剩 1 个文件不是事故**（发版前只读核对，没动任何文件）
7.27 那条执行链里，第③步对 8 张「受保护」的图逐个 `releaseIfUnreferenced()`（**先重新查引用**，仍被引用才不动），
结果它们**全部零引用**，所以也进了回收站 —— 原文件里那句「仍被引用的一张没动」写得像「8 张都留在原地」，读的人会误会。
本轮用平台自己的口径复核（`/tmp` 临时只读探针，跑完即删，不写库、不动文件）：
- `collectReferencedUrls()` → 现役被引用的上传 URL **只有 1 个** = `/uploads/collection/202610/3fd703b0…png`（大绵羊封面，`isReferenced()` 反查为 `true`）；
- 回收站 26 张**逐个** `isReferenced()` 点查 → **0 张仍被引用**（含那 8 张曾受保护的：两张公告 PNG 2,397,917 B、两张公告 JPG 1,010,815 B、三张藏品 JPG、一张衍生品 JPG 860,048 B）；
- 经验证据也对得上：三个 access log 里 `/uploads/` 的 **404 = 0 条**（没有哪个页面正在找一张不存在的图）。
所以那 8 张本来就是「上传成功但没被任何记录用上」的孤儿，和 7.20 S5 说的那个机制一致。**回收站里 restore 得回来，另有两份 tar 兜底**；
`purgeTrashFiles()` 到现在为止**仍然一次没跑过**。

**线上复验（全走公网 IP，没用 127.0.0.1，见 7.21 那个假 404 的坑）**：
- 后端新代码真的在位：`normalizeInviteRewards` 命中 1 次、`第 … 档`/「个奖励位」的拼装文案在 PHP 侧（那才是报错的出处，前端没有这句话）；
  `RewardGrantService` 里「盲盒奖励必须指定 blindboxId」在位。
- 只读探针打出现新文案（真盒、真库，不发放）：`盲盒「青色」×2`、`盲盒「呃呃呃」×2`、`盲盒「单独的」×2`；
  历史无盒 id → `盲盒×1`（不抛异常）；盒 id=999999（不存在）→ `盲盒「#999999」×1`。现役 3 个盒 = `is_openable` 1 开 2 关，都有名字，下拉不会空。
- 后台产物：五个新分块 `RewardListEditor-BUuw3Mqp.js` / `Invite-CV3ZCsZn.js` / `Register-BtWFpSt-.js` / `CheckIn-BD2zOb_m.js` / `LuckyDraw-D7Vf8GCT.js`
  各自 `blindboxes` 命中 1 次、四个页面都有「需选择具体盲盒」这句；组件里 `no-data-text` 是「还没有盲盒，请先到「盲盒管理」新建」。
- H5 产物：线上正在被 `index.html` 引用的 `index-sR4yu9kT.js` 里 `app-empty__img` = 1、老图 `empty-ding` = **0**（旧分块仍留在目录里，只是没人引用了 —— 叠加解包不清目录，这是常态）。
- **浏览器实测**（这是空状态图标第一次在线上被真渲染出来）：打开 `#/market/free`，DOM 里 `.app-empty` 存在、
  其中内联 `<svg>` 有 **10 条 `path`**（与本地构建一致），文案「未找到相关藏品」；首页控制台 0 条报错。
- 公开接口 `/api/banners`、`/api/market/collections` 都 `code:0`（`/api/collections`、`/api/site/config` 返 4040 是我猜的路由名不对，不是回归）。
- 日志：后端 `runtime/log/202610/14.log` 里 `fatal|uncaught` **0 条**，SQL 日志能看到新的 `blind_boxes join collectibles` 查询；
  三个 nginx error log 今日 **0 条**新增。

**这批线上还没验的（要你真人一次，登录有图形验证码我不绕，同 7.24 / 7.29 / 7.30）**：
1. 后台任一活动里选一次「盲盒」、挑一个盒、保存 → 摘要应变成「盲盒「名字」×N」；顺手删掉盒再保存，应被「盲盒奖励需选择具体盲盒（发哪一个）」拦在本地；
2. 邀请活动存一个带「无奖励」的档位 → 报错应是带档位/奖励位的新文案；
3. 第一次真发盲盒奖励时人工盯一眼到账记录（发放侧代码只读过、没在线上跑过）。
空状态那条不在此列 —— 上面已经有浏览器实测的线上证据了。

### 7.32 公告与新闻正文底部自动附「风险提示」（2026-10-14 本地做完并测过 → **同日 02:1x 已上线，见 7.33**）

**起因**：你发来一张别家平台（iCard 爱卡集市）的公告截图，问「可以在每一条发布的公告底部加上这样的文本吗」。
截图里那三段是：① 数字藏品仅具收藏欣赏价值、官方不指导价格、严防炒作；② 对第三方工具锁单抢单、承诺收益回购、
非法集资拉人头等零容忍，封号冻结；③ 依反洗钱反诈法规禁止洗钱赌博非法集资传销，查实封禁冻结并移交司法机关。

**你定的四条口径**（AskUserQuestion 三个问题都选了推荐项）：

| 口径 | 内容 |
| --- | --- |
| 文案 | **按司南重写一版**，不照抄别家原文与品牌名（照抄有版权风险，且「以平台名义承诺回购」这类表述得跟你的实际业务对得上） |
| 落点 | **接口拼接 + 文案放后台可改**：文案存在 `nft_system_configs`，后台「系统配置 → 全局参数 → 内容与公告」改，实时生效不用发版 |
| 范围 | **公告（notice）与新闻（news）都加** —— 都是对外发的内容，口径统一最省心 |
| 数据库 | **公告表一个字节没动**：不加列、不回填、不改正文，存量公告当场就有提示（这是选「接口拼接」而不是「保存时写进正文」的直接好处） |

**改了哪些文件**：

| 文件 | 作用 |
| --- | --- |
| `app/service/AnnouncementDisclaimer.php`（**新增**） | 唯一的生成出口。`format()` 是纯函数（离线可测）：开关关掉或文案为空 → 返回空串；否则「第一行当标题、空行分段、段内回车转 `<br />`」，**逐行 `htmlspecialchars`**。`fromConfig()` 只负责读那两个参数 |
| `app/controller/Content.php` | `announcementDetail()` 响应里多一个 `disclaimer` 字段。**全仓库只有这一处拼**（列表接口连正文都不下发） |
| `app/admin/controller/SystemController.php` | `announcement_disclaimer_enabled` 进 0/1 布尔白名单，防止后台存进任意字符串 |
| `migrations/20261014_announcement_disclaimer.sql`（**新增，第 24 份 DDL**） | 补两行种子。**故意只有 `INSERT IGNORE`、没有 `UPDATE`**（与 20261010 那份归位默认值的写法不同）—— 这两个键运营会在后台改，重复执行不该把他们改过的文案冲回默认值 |
| `database/full_init.sql` | 同一条 `nft_system_configs` 种子 INSERT 追加 id 42 / 43，**该行元组数实测 38 → 40**（id 1~43、无重复）。让「全新部署」与「老库跟迁移」两条路装出同一个默认值 |
| `sinan-admin/src/views/system/Config.vue` | 新增「内容与公告」分组 + 两个键的中文 META + 一种 `multiline` 多行文本框渲染（原来最长的只有 JSON 那档 2 行高） |
| `sinan-admin/src/views/content/Announcements.vue` | 富文本编辑器下面加一句指路话：提示会自动加、去哪改、不用发版 |
| `sinan-art-source/src/stores/notice.js` + `src/views/NoticeDetail.vue` | C 端把 `disclaimer` 透出来，渲染在正文卡片**最底部**（两个正文分支之外）。（⚠️ 这句在 §7.36 被改成「挪到卡片外面单独一张白卡」，**§7.39 又并回卡内**，位置仍是「最底部、两个分支之外」，只是盒子从白卡变成一条虚线。）样式复用 `NoticeDetail.vue` 里早就写好却从没被真实数据喂过的 `.notice-detail__warning`（灰底 + 小字 + `.warn-title` 加粗），**没新画一套 CSS** |

**为什么单独下发一个字段，而不是把 HTML 直接拼进 `content`**：
`stores/notice.js` 用 `/<[a-z][\s\S]*>/i` 判断正文是不是富文本。拼进去会让**纯文本公告**也被判成富文本，
于是原来「按空行切段」的逻辑失效、段落换行得在接口里重做一遍 —— 那是给存量公告制造回归。
分两个字段还有个附带好处：v-html 注入的元素拿不到 scoped 样式的 `data-v` 属性，走模板里的
`<div class="notice-detail__warning">` 才能直接吃到现成样式。

**测了什么**（三端全量，离线）：

- 后端 `--testsuite unit` **217 → 234 例全绿**（新增 `tests/unit/AnnouncementDisclaimerTest.php` 17 例）：
  开关/空值/分段/`<br />`/CRLF 与 CR 都吃/连续空行只算一次分段/**转义**（`<script>` 变字面量、引号变实体）/
  超 5000 字丢整段（含一条「没超限要正常出来」的反面对照，防止写成永远返回空）/
  防漂移三条（只有详情接口拼、列表接口不下发正文、开关行缺失按「开」处理）/
  两份 SQL 种子的默认文案**逐字节相同**、默认文案齐四段口径且不含别家品牌名与「最终解释权」/
  跨仓两条（后台 META 有入口、C 端会渲染）。
- 后台 **153 → 158 例全绿**（`tests/announcement-disclaimer-config.spec.js` 5 例，源码扫描 —— 本仓库不装 @vue/test-utils）。
- H5 **128 → 133 例全绿**（`tests/announcement-disclaimer.spec.js` 5 例，真挂载 `NoticeDetail.vue`：
  富文本模式、纯文本模式（落在「司南文创」落款之后）、`undefined`/`''`/`null` 三种空值都不留空灰块、store 只透传不自己拼）。
- 两个前端 `npm run build` 都过；产物里查得到接线：H5 `dist/assets/NoticeDetail-BjFwr_oR.js` 有 `notice-detail__warning`，
  后台 `dist/assets/Config-C6XRjLjt.js` 有 `announcement_disclaimer_enabled`。
- **本地真库实测**（`127.0.0.1:3308` 的开发沙箱库，与生产无关；跑完把两条测试公告删回 0 条）：
  第 24 份 DDL 连跑两次仍是 2 行（幂等 ✓）；库里 `\n` 存成了真换行（398 字符含 6 个 LF，`\\n` 字面量 0 处 ——
  mysql 命令行回显时会把真换行显示成 `\n`，别被骗）；起后端打 `GET /api/announcements/:id`，
  **notice 与 news 两条都返回 659 字符的 `disclaimer`**；把开关改成 `0` **不重启进程**立刻变空串、改回 `1` 立刻恢复（实时生效 ✓）；
  顺带确认 `/admin/system/configs` 未登录是 4001、假令牌 4002，没有裸奔。
- 浏览器结构实测（本地 H5 dev + 代理到本地后端，`#/notice/987655`）：`.notice-detail__warning` 存在、
  是正文卡片的**最后一个子元素**、内含 4 个 `<p>`、`.warn-title` 计算样式 `font-weight: 700`、
  选择器 `.notice-detail__warning[data-v-a96ab1cf]` 命中现成规则。
  **像素几何没量到**：内嵌浏览器视口 0×0，而本项目 px 会被编译成 vw（`12px → 3.2vw`），所以 `getComputedStyle` 回 0px。
  这不是样式坏了，但**真机/线上看一眼版式**仍归你（见下面「没验的」）。

**反证怎么做**（拆掉修复看红，并按项目规矩打出 diff 证明真的拆了）：
一次性脚本逐个拆 5 个承重点，每个都要求原文命中 1 次，命中 0 次就报错退出 ——
**第一版脚本就是这么自曝的**：Content.php 与 NoticeDetail.vue 两条按 LF 写的匹配串命中 0 次（这仓库 PHP/Vue 文件是 CRLF），
若不看返回值就会把「没拆动」当成「拆动了但测试没红」记进台账。改成行尾无关的匹配串后重跑：

| 拆掉的东西 | 结果 |
| --- | --- |
| `Content.php` 里 `AnnouncementDisclaimer::fromConfig()` → `''` | 后端红 |
| `AnnouncementDisclaimer.php` 里 `htmlspecialchars(...)` → 原样输出 | 后端红（转义那条用例） |
| `NoticeDetail.vue` 提示块 → `v-if="false"` | H5 红 |
| `stores/notice.js` 里 `disclaimer: a.disclaimer \|\| ''` | 后端跨仓用例 + H5 都红 |
| `Config.vue` META 里 `multiline: true` | 后台红 |

拆后实测：后端 17 例 **4 红**、H5 5 例 **4 红 1 绿**（那 1 绿是「空值不该渲染」的负向用例，本来就该绿）、后台 5 例 **1 红**。
还原后 5 个文件与备份 `cmp` **全部 identical**，`grep` 残留的拆动字样 **0 处**。

**故意没改的**：

1. 公告列表页与首页公告轮播**不加** —— 它们只下发标题/摘要，没有正文可加（`test_列表接口不下发公告正文` 把这条钉住了）。
2. 没做「每篇公告单独关掉」的勾选框 —— 你选的是全局口径，要做就得加字段 + 后台表单，另说。
3. 文案里**故意没写**「最终解释权归平台所有」这类话 —— 属违法格式条款；测试直接断言它不许出现。
4. 没在代码里再写一份默认文案当兜底 —— 那样同一句话就有两份真相，运营改了库里的那份就永远对不上。
   代价是**这份 DDL 必须随版本执行**，否则提示静默不出现（用例注释与本节都写了这条）。
5. 公告 `tag_color` 后台输入口仍没做（第 8 节 13 条），没顺手带。
6. `database/init.sql`（拆分版本机重建用）的 configs 种子**没加**这两个键 —— 那份本来就停在 8 个键的历史现状
   （`realname_audit_mode`、`lucky_enabled` 等都不在），不是本轮引入的不一致。

**这批还没验的（当时要你真人）**：后台「系统配置 → 全局参数 → 内容与公告」里改一次文案并保存、拨一次开关
（后台登录有图形验证码，我不绕，同 7.24 / 7.29 / 7.30 / 7.31）；C 端真机看一眼这段提示的版式与折行。
—— 前者**已在 7.33 末尾由用户授权后代跑并闭环**（四笔 `config_save` 审计、文案 md5 复位到默认值），只剩真机版式。

**生产现值**：本地做完那一版**一行都还没上生产**，当时也没实测生产现值（那一句写在 7.32 定稿时是实话，别回头把它当成线上结论）。
后来你说「现在就上」，第 8 次发版已照下面这个顺序执行完并复验，**线上实测结果都在 7.33**。

**上线顺序（7.33 已照此执行）**：① 先只读查 `SELECT config_key,config_value FROM nft_system_configs WHERE config_key LIKE 'announcement%';`，
缺哪行跑第 24 份 DDL 补哪行（幂等，不会覆盖运营已改的值）；② 后端 3 文件（`app/service/AnnouncementDisclaimer.php` 新增、
`app/controller/Content.php`、`app/admin/controller/SystemController.php`）+ 两个前端整包；③ 回滚点照 §7.31 的规格做；
④ 上线后走公网 IP 复验 `GET /api/announcements/:id` 有 `disclaimer` 字段、拨开关看实时生效。
**注意 DDL 与代码的先后**：这次不像 §7.16 那回有强依赖 —— 代码先发只是提示暂时不出现（读不到行 → 空串），不会报错；
DDL 先发也没副作用。两条都安全，但仍建议 **DDL 先行**，少一个「上线了怎么没看到」的问号。

### 7.33 第 8 次发版上线记录（2026-10-14 02:10~02:2x，你说「现在就上」）

**上线的是 7.32 那一版**：公告与新闻详情页正文底部自动附平台《风险提示》，文案与开关在后台「系统配置 → 全局参数 → 内容与公告」。
代码基线 = commit `b5906ea`（功能 + 27 例测试）+ `83dab31`（7.32 文档）。数据库这次**动了**：只补 2 行 `nft_system_configs` 种子。

| 项 | 内容 |
| --- | --- |
| 预检发现的一个坑 | 生产后端目录是 **`/www/wwwroot/sinan-backend`**，不叫仓库名 `sinan-nft-backend`。第一遍预检按仓库名抄路径，`ls` 直接「No such file or directory」—— 与 §7.31 那条「抄错目录」是同一类错，这次靠报错自己暴露 |
| 线上没漂移 | 发前 `Content.php` md5 `c0c0f82badd1ee9605522b251920603d`（10,194 B）、`SystemController.php` md5 `f25f6ca3affe8c9d697bb935f3781a06`（28,169 B），与 git 基线 `1738b5c` 的内容**按 CRLF 归一后逐字节相等**；§7.31 那两份（`MarketingController.php` `579fd095…`、`ActivityRewardService.php` `5ce6e8b8…`）线上仍是记录值 —— 生产确实停在第 7 次发版的状态 |
| 发版前快照 | `/root/sinan-release8-20261014_021051`（指针 `/root/sinan-release8-latest`，共 41M）：`backend/` 两个旧文件（`cmp` 与线上全等）、`frontend/sinan-admin` 与 `frontend/sinan-h5` **整目录**拷贝（文件数 646 / 345 两边对得上）、`snapshot/` 里库 dump `sinan_nft_before_release8.sql.gz` 43,646 B → 解压 270,377 B、**80 个 `CREATE TABLE`**（按 §7.31 教训当场数过，不是只看压缩包完整）、`system_configs_before.tsv` 40 行（39 个键 + 表头）、`precheck.txt`、迁移文件本身（两端 md5 `70efcd47c84f5064019f19907caa95a3` 一致）、`api_announcement1_before.json`（277 B，`disclaimer` 出现 **0** 次） |
| ① DDL | 第 24 份。跑第 1 次 `exit=0`：`nft_system_configs` **39 → 41 行**，`announcement_disclaimer` = **398 字符 / 6 个真换行 / 字面 `\n` 残留 0 处 / md5 `cf8932f55fe84ff53d3e80f8e0999187`** —— 这个 md5 是我在本地先把 SQL 字面量按 MySQL 的转义规则解出来算的，线上落库与它**逐字节相同**；`announcement_disclaimer_enabled` = `1`。跑第 2 次无变化（`uk_config_key` 唯一索引在，`INSERT IGNORE` 幂等）。**再实测「不覆盖运营改动」这条设计**：把文案手工改成 7 个字的「运营改过的文案」后跑第 3 次，`SELECT` 出来**还是那 7 个字**（正因为这份 DDL 故意只有 INSERT IGNORE、没有 UPDATE），随后用同一段字面量 UPDATE 复位成 398 字符默认文案、md5 回到 `cf8932f5…` |
| ② 后端 3 文件 | 先 `scp` 到 `/root/ds-upload/` 暂存、md5 与本地对齐后才 `cp` 就位（吸取 §7.31「`cp` 静默失败」的教训，改成**先验再落位**）：`AnnouncementDisclaimer.php` `da8835713f7eb21ef772dd85b7fe473f`（4,785 B / 104 行，新增）、`Content.php` `9a79fa49a91ee70f69d5035929b9ff79`（10,643 B）、`SystemController.php` `5272df2c97c115f7b0edc4951ee9738d`（28,355 B）；三份 `www:www` + `644`，`/www/server/php/84/bin/php -l`（PHP 8.4.25）**三份都 No syntax errors**；`php think clear --cache` = Clear Successed，随后 `chown -R www:www runtime`。暂存目录已删。**没往生产 `migrations/` 放文件** —— 那目录线上停在 20260922，第 8 节里历次发版都没镜像它，这份迁移的副本留在回滚点 `snapshot/` 里 |
| ③ 两个前端整包 | 照 RUNBOOK 第 4 节那两条 `tar czf - -C <仓> dist \| ssh … --strip-components=1 && chown -R www:www …` **叠加解包**（不清目录）。入口换了：H5 `index-sR4yu9kT.js` → **`index-TARjuzYk.js`**（468,511 B）、后台 `index-B-XlNegR.js` → **`index-Dm9z2FwK.js`**（index.html 592 B mtime 已更新）；文件数 H5 345 → **388**、后台 646 → **715**，旧入口文件原地还在（老用户不 404） |
| 双端 + 公网三段 md5 | 6 个关键产物（两个 index.html、两个入口包、`NoticeDetail-BjFwr_oR.js`、`Config-C6XRjLjt.js`）**本地 = 线上磁盘 = 走公网 IP 回读**三处 md5 全等；公网直取三个 URL 都 HTTP 200，尺寸与本地一致 |

**线上行为复验（全走公网 IP，不用 127.0.0.1，见 §7.21 那个假 404 的坑）**：

- 详情接口 `GET /api/announcements/1`：`data` 的键从 `id,title,content,coverImage,type,subtype,tagColor,createdAt`
  变成末尾多一个 **`disclaimer`**，值 **439 字符**的 HTML = **1 个 `<p class="warn-title">` + 3 个 `<p>`**，四段齐（标题 / 收藏欣赏价值 / 零容忍清单 / 反洗钱反诈法律责任）。
- 列表接口 `GET /api/announcements?page=1&pageSize=5`：整份响应里 `disclaimer` **0 次**，首条仍是 `…summary,isTop,createdAt`（不下发正文）—— 收口只在一处这条在线上成立。
- **开关实时生效**：库里把 `announcement_disclaimer_enabled` 拨 `0`（不重启 PHP、不清缓存）→ 详情接口 `disclaimer` 立刻变 `""`；拨回 `1` → 立刻 439 字符。
- **线上代码里 `announcementDetail()` 没有按 `type` 分支**（把生产文件第 51~76 行读出来核对过，`'disclaimer' => AnnouncementDisclaimer::fromConfig()` 就第 74 行一处）——
  所以「新闻也带」与「公告也带」走的是同一条语句。要说清证据边界：**生产 `nft_announcements` 只有 1 条**（id=1，`notice`，`published`，正文是富文本 `<div style="text-align:center">…`），`news` **0 条**；
  我没有为了造证据去线上写内容数据，news 那一类是**本地沙箱实测过两条（notice/news 都返回 disclaimer）**+ 线上这段无分支代码。
- **浏览器实测线上页面** `http://<IP>/#/notice/1`：`.notice-detail__warning` 存在，是 `.notice-detail__content` 的**最后一个子元素**（子元素顺序 `notice-detail__block--text` → `notice-detail__warning`），
  内含 4 个 `<p>`、首个是 `.warn-title` 文本「司南风险提示」、计算样式 `font-weight: 700`、容器底色 `rgb(247,248,250)`（现成的 scoped 样式吃到了），
  非空白字符 **392** 个 = 库里 398 减去 6 个换行，结尾是「依法追究法律责任。」；控制台 **0** 条报错。
  再把开关拨 `0` 后整页重新加载（`?dscheck=off#/notice/1` 强制重开文档）：**块不存在、全文搜不到「风险提示」**，即「关掉 = 回到本改造之前」在线上成立；拨回 `1` 再加载又出现。
  **像素几何仍没量到**：内嵌浏览器视口 0×0 + 本项目 px 编译成 vw，`font-size`/`padding` 计算值回显 `0px`（同 7.32 本地那次），真机版式仍归你。
- 后台侧：`/api/admin/system/configs` 未登录仍是 `{"code":4001,"message":"未登录或令牌缺失"}`，没有因为新键裸奔；
  `SystemController.php:76` 的 0/1 白名单在线上确实是 `['cleanup_sms_required', 'market_recommend_tab_enabled', 'announcement_disclaimer_enabled']`。
- 日志：后端 `runtime/log/202610/14.log` 里 `fatal|Fatal|Uncaught|CRITICAL|Exception` **0 条**、`AnnouncementDisclaimer` 相关 **0 条**；
  `/www/wwwlogs/` 下 `nginx_error.log`、`sinan-h5.error.log`、`sinan-admin.error.log`、`sinan-backend.error.log` 今日（`2026/10/14`）**各 0 条**。

**这次我自己踩的两个坑（都不是部署问题，当场纠正）**：
1. 把远程 mysql 命令写成**双引号**包的一整串，本地 bash 先展开了 `$(…)` 与 `\$`，结果变成 `cat: /root/.mysql_root_pass: No such file or directory`
   加 `bash: -h127.0.0.1: command not found`。改成「迁移文件 `scp` 上去 + 远程命令用单引号包」一次就过。
2. `curl -o f.js -w "…$(grep -c … f.js)"` —— 命令替换在 curl 之前就求值，文件还不存在，于是打印 `No such file or directory` 与 0。
   下载其实**是成功的**（尺寸都对），重跑单条 grep 即对。**教训：校验分两步落，别把「取值」嵌进「产生这个值的那条命令」的参数里。**

**回滚怎么做**：后端 = `cp -p` 把 `backend/` 那两个旧文件放回原位（md5 `c0c0f82b…` / `f25f6ca3…`）+ `php think clear --cache`，
再把 `app/service/AnnouncementDisclaimer.php` 删掉即回到 7.31 的状态；库 = 删那 2 行（或留着，代码回滚后没人读它）；
前端 = `tar czf - -C /root/sinan-release8-20261014_021051/frontend/sinan-h5 dist | ssh …` 反着解一次（整目录拷贝在，不靠旧分块）。

**这批线上还没验的（要你真人一次，登录有图形验证码我不绕，同 7.24 / 7.29 / 7.30 / 7.31）**：
后台「系统配置 → 全局参数 → 内容与公告」里**改一次文案并保存**（看多行文本框好不好用、保存后 C 端是否即时变）、
**拨一次开关**（我在库里拨过两次，接口层即时生效已证；后台那个开关控件本身没被真人点过）。
另外 C 端真机看一眼这段的版式与折行。

**02:29~02:31 补做：上面那两件事由用户授权后我代跑了**（用户说「后台我把验证码关了」并给了后台口令，
口令**只用于这次登录，没写进任何文件 / 提交 / 本文档**）。结论：

| 验的东西 | 怎么验的 | 结果 |
| --- | --- | --- |
| 「内容与公告」分组真的出现吗 | 登录后走 `#/system/config`（先直接改 hash 没切页，**得整页重载**才渲染，同 §7.31 那条 hash-router 坑） | 分组在，行标签是「公告风险提示开关」「公告正文底部风险提示」，页面共 3 枚开关、2 个 textarea |
| 多行文本框吃到真文案了吗 | 读那个 textarea | `rows=8`、**长度 398、内含 6 个真换行** —— 库里那段经接口 → JSON → Vue → textarea 一路没丢格式 |
| 开关拨动会写库吗 | 点开关 → 弹「确认更新『公告风险提示开关』？保存后立即生效并写入审计日志」→ 点确定 | 提示「**参数已保存并实时生效**」；库里 `announcement_disclaimer_enabled` = `0`，审计 `config_save` 一条（id 123），C 端 `GET /api/announcements/1` 的 `disclaimer` **立刻变空串**（不重启、不清缓存）；再拨回来（id 124）→ 又回 439 字符 |
| 文案真能在后台改吗 | 在 textarea 里换成一段两行的测试文案 → 该行「保存」→ 确认 | 接口回的是 **1 个 `<p class="warn-title">` + 2 个 `<p>`**（126 字符），证明「第一行当标题、空行分段」这套格式规则在**运营手改的路径**上也成立（审计 id 125） |
| 改回默认了吗 | 用页面里暂存的原值写回 textarea → 保存 | 库里 `MD5(config_value)` = **`cf8932f55fe84ff53d3e80f8e0999187`**，与迁移文件的默认值**逐字节相同**；开关 = `1`；C 端浏览器实测 4 段、`.warn-title` `font-weight:700`、非空白 392 字、正文块在前提示块在后、控制台 0 报错（审计 id 126） |
| 有没有顺手改坏别的键 | 数当天的写入：`grep PUT /www/wwwlogs/sinan-admin.access.log` + `SELECT … action='config_save'` | 今天**只有那 4 次**写入，全是我点的（审计 id 123~126）。后端今日日志 `fatal|Uncaught|Exception` **0 条**，四个 nginx error log 今日 **0 条** |
| 图形验证码到底是谁关的、关了多久 | 上线后当场复核 `updated_at` + 审计 `admin_name / method / path / ip`（**原来我写「用户本轮为让我代跑而关的」，错了**） | 现值 `0`，`updated_at` = **2026-10-11 11:09:39**，审计 id 101 是一次真实的 `PUT system/configs/captcha.enable`（`admin_name=admin`）—— **不是本轮临时关的，是三天前在后台界面里关掉后一直没拨回来**；同批还关了 `realname_audit_mode`（id 102，那次改回 `auto` 是正常业务值） |

**顺带把后台这套交互的实情记下来**（跟 §7.32 写「每行一个保存」的直觉不同，别再按直觉操作）：
`el-switch` 是 `@change="onSave(cfg)"` —— **拨一下就弹确认，不需要再点保存**；`bool` 行旁边那个「关闭 / 开启」是状态片；
`multiline` 与 JSON 那些行才是靠行尾「保存」按钮触发。每个键**各自**弹一次确认框（一次改一个键，不是一键存整组）。
另外这次踩到的工具侧坑：内嵌视口 0×0 下 MessageBox 的淡入动画停在 `opacity:0`，按「可见 + 有宽度」筛活跃弹窗会把真框筛没，
筛 `.el-message-box` 的**最后一个**再点确定才对；`.el-message` 的文案才是落库成功的可靠信号。

**还剩一件真人**：C 端真机看一眼这段的版式与折行（内嵌浏览器量不到像素，见上面那条 0×0 + px→vw）。
**两件安全提醒（都升级了）**：
① `captcha.enable` 现在线上是 `0`，而且**已经开了三天没关回去**（10-11 11:09 起）—— 这等于后台登录口在无 HTTPS 的公网上
连续三天没有图形验证码挡爆破。建议**现在就拨回 `1`**。
后台**没有**这个键的开关控件（`Config.vue` 的 `META` 里没有 `captcha.enable`，所以它落在页面最下面「其他参数」分组里，
是**文本框 + 行尾「保存」**，不是开关 —— 这次我只数到 3 枚开关就是因为这个）：把值改成 `1` 保存，或者直接
`PUT /admin/system/configs/captcha.enable`；库里现在那行 `description` 写着它的含义，别和 `captcha.mode` / `captcha.provider` 搞混。
② 本次口令在公网明文过一次（第 8 节 2 条：无域名 = 无 HTTPS），而验证码又关了三天，两个风险叠加 —— 建议改一次密码。

### 7.34 第 9 轮：四项真人端到端由用户授权后代跑，跑完把本轮造的数据清除（2026-10-14 02:51~03:05，**线上数据已复原**）

**你怎么点的头**：上一条我报告「第四节 1 那四件现在我可以代跑了，但都会往生产写数据」，你的回复是
「**跑，跑完之后把本轮造出来的数据清除**」。所以下面这些生产写入是你授权的，清除范围也只到「本轮自己造的行」。

**跑之前先量了基线**（只读）：`nft_users` 2 行（id 1/2）、`nft_wallets` 2、`nft_verification_codes` 1、
`nft_invite_activities` **0**、`nft_register_activities` 1（`enabled`，tiers md5 `cd07bb09d7f33d4742ed2a054cc461ff`，`updated_at` 10-13 23:21:57.000）、
`nft_announcements` 1、`nft_blind_boxes` **3**、`nft_collectibles` **4**、审计 126 行、`public/uploads` **1 个文件**。
另外把「所有挂 `user_id` / `inviter_id` / `invitee_id` 的 36 个位置」逐表点了行数当清理宇宙（见本节末「复核」表）。

**一件绕不开的前置**（大白话）：生产短信是 mock 渠道，`APP_DEBUG=false` 时接口里那句 `debugCode` 是 `null` ——
**验证码发出去了，但没有任何地方能拿到它**，所以真人自己在生产其实注册不进第二个号。
我的做法是：在 H5 页面上真点「发送验证码」（库里确实插了一行 `scene=register`），
然后把那一行的 `code` 换成 `123456` 的 bcrypt 哈希 —— 等价于「把验证码告诉测试者」，注册接口本身走的还是真实校验（`verify_password` 命中、核销 `used_at`、建用户、建钱包）。
哈希是本地 `password_hash('123456', PASSWORD_BCRYPT, cost=10)` 现算的，验证码用完连行一起删。
**这条本身就是第 8 节 3 的一个具体代价**：短信不接真网关，C 端注册在线上对任何人都是走不通的（不只是我不能验）。

**另一件前置**：`realname_audit_mode=auto` 下「实名通过」会连带结算**注册活动**（生产那条是 `enabled`，第 1 档 = 空投藏品 #1）。
真让它发一次，就会多出一行 `nft_user_collectibles` + 空投记录 + 收件箱，还要回滚藏品配额 —— 那不是本次要验的东西。
所以我在**跑之前把注册活动临时停用**（`UPDATE … SET status='disabled' WHERE id=1`），跑完逐列复原（见复核表），
期间没有任何真实用户能触发它（C 端注册本来就进不来）。这一步改了存量行，是本轮唯一一处「改而不删」，特此写明。

| # | 验的东西（原第四节 1 那四件） | 怎么跑的 | 实测结果 | 判定 |
| --- | --- | --- | --- | --- |
| ① | §7.30：奖励编辑器能选盲盒并**存进去** | 后台「活动 → 邀请活动 → 新建活动」，添加档位 → 奖励类型选「盲盒」→ 第二个下拉挑盒 | 下拉列出**三个真盒**（单独的 / 呃呃呃 / 青色），选「青色」后该行变成「盲盒 青色 ×1」；点「创建活动」→ `POST /api/admin/marketing/invite` 回 `code:200 邀请活动已保存 data.id=1`，请求体里是 `{"type":"blindbox","blindboxId":1,"quantity":1}`，库里 `tiers` 存成 `{"rewardType":"blindbox","blindboxId":1,"quantity":1}` | **通过** —— §7.30 之前那句「只要选盲盒必然被顶回来」在线上不再成立 |
| ② | §7.29：邀请档位里的「无奖励」被摘掉、报错带位置 | 先看下拉，再用同一登录态直接打那个接口送非法档位（UI 已选不到 `none`，只能这么送） | 类型下拉只有 **6 项**：藏品空投 / 司南币 / 抽奖次数 / 优先购资格 / 资格购白名单 / 盲盒 —— **没有「无奖励」**；三次非法送分别回 `4220`：`邀请 3 人档（无奖励）：「无奖励」不能算作一项奖励，请改成具体类型或删掉这一项`、`邀请 5 人档 · 第 2 个奖励位（司南币）：司南币奖励金额必须大于 0`、`邀请 5 人档（盲盒）：盲盒奖励必须指定 blindboxId` —— 与 §7.29 探针那四条**逐字一致**；三项里只有一项时不硬写「第 1 个」也成立 | **通过**，且三次拒绝后库里那一行的 `tiers` md5 与 `updated_at` **一动没动**（拦截发生在写库之前） |
| ③ | §7.13：一张证只能认证一个账号 | H5 `#/auth/register` 真填表注册 A（139…091）→ `#/user/realname` 提交姓名+证号 → 退出注册 B（139…092）→ 提交**同一张证** | A：`POST /api/user/realname` 回 `code:0 实名认证已自动通过`，页面 toast「实名认证已通过」，库里 A `realname_status=2 / is_realname=1 / id_card_hash=2c085afb…`；B：`code:1001`「**该身份证已绑定其他账号，如需处理请联系客服**」，toast 同一句，库里 B **`realname_status=0`、姓名/证号/指纹三列全 NULL**（预检就挡回，没进队列也没占坑）；报错**不含对方 UID** | **通过** —— 这是 §7.13 上线以来第一次有证号真的占过坑（§7.25 记过「到现在还没有一张证占过坑」） |
| ④ | §7.19：HEIC / 后缀不符的图 | 后台「盲盒 → 新建」的封面口，本地现造两个文件：44 字节真 HEIC 容器（`finfo`=image/heic）、739 字节**内容是 JPEG 但后缀 `.heic`** | ① 真 HEIC：前端 `needsJpegTranscode` 认下 → `createImageBitmap` 解不开 → **一个请求都没发**，toast 是那句三条指引（截图 / 画图另存 JPG / 设置→相机→兼容性最好），封面仍「暂无图片」；② JPEG 内容 + `.heic` 后缀：前端转码后上传 → `code:200 上传成功`，落盘 `/uploads/blindbox/202610/<随机32位>.jpg`（**这就是当年被「扩展名与内容不符」误伤的那一类，现在不拦了**）；③ 绕过前端把真 HEIC 直接 POST 给闸门 → `4220`「这张图是 iPhone 的 HEIC 格式，平台暂不接收（识别为 image/heic，不足1KB）…」；④ 顺带把 PHP 内容伪装 `.png` 送进去 → `4220`「仅支持 JPG / PNG / WEBP / GIF 图片（识别为 text/x-php…）」 | **通过**（桌面浏览器侧）。**仍未验的是真 iPhone**：那要靠手机相册直传那条链路，我这台浏览器只能证明「解得开就转、解不开就指路、硬塞到后端就按内容拒」 |

**顺带查出来的一条（不是 bug，但你该知道）**：①②③④ 都过了，但**盲盒奖励「真发出去」那半条还没在线上验过** ——
`ActivityRewardService::rewardLabel()` 的「盲盒「名字」×N」只在发放/导出中奖名单时才走到，
而发放要动真资产，本轮没做（与 §7.30「没做/要你定的」第 3 条同口径）。

**清除**（你说「跑完之后把本轮造出来的数据清除」，范围就按这句话划）：
先只读列出本轮的行 → `START TRANSACTION` → 按**子表先、父表后**删 → `COMMIT` → 复点。删的六行是：

| 表 | 行 | 二次确认条件（不匹配就不删） |
| --- | --- | --- |
| `nft_wallets` | id 3、4 | `AND user_id IN (3,4)` |
| `nft_users` | id 3、4 | `AND phone IN ('13900000091','13900000092')` |
| `nft_invite_activities` | id 1 | `AND name = '端到端验证-盲盒-20261014'` |
| `nft_verification_codes` | id 6、7 | `AND phone IN ('13900000091','13900000092')` |
| `nft_register_activities` | id 1（**复原不是删**） | `SET status='enabled', updated_at='2026-10-13 23:21:57.000' WHERE id=1 AND used_count=0` |
| 磁盘 | `/uploads/blindbox/202610/56e41da9….jpg`（778 B，md5 `506168ae1c8a2a882b8ec11c80271b1e`） | 删前先 `ls` + `md5sum` 认明是本轮那张，再 `rm`；空目录 `blindbox/202610`、`blindbox` 逐层 `rmdir`（非空就删不掉，所以不可能带走别人的东西） |

**踩到的坑（这条值得记）**：第一次把 `nft_users` 放在 `nft_wallets` 前面删，直接 `ERROR 1451` ——
`fk_wallets_user` 是 `ON DELETE RESTRICT`。因为整批在**一个事务**里，报错后 mysql 客户端退出、事务**隐式回滚**，
我复点确认 `invite=1 / users=4` 一行没少，**没有发生「删一半」**。改成子表先删就过了。
顺带把 `nft_users` 的 23 个 FK 子表全查了一遍 `WHERE user_id IN (3,4)`，只有 `nft_wallets` 有 2 行，其余 0 行 ——
所以「删两个用户」不会连带影响任何存量数据，也是本轮没在别的表留下行的正面证据。

**复核（全部当场量）**：
`users 2 / wallets 2 / invite 0 / verification_codes 1 / id_card_hash 非空 0 / register_activities enabled 1` 全等于基线；
注册活动 `tiers` md5 仍是 `cd07bb09…`、`used_count` 仍 0、`created_at`/`updated_at` 都回到 10-13 23:21:57.000；
剩下两个存量账号一字未动（1 号 `status=0` 三列 NULL，2 号 `status=1` 姓名+证号 SET、指纹 NULL —— 它是 auto 之前提交的人工待审遗留，
**本轮没碰、也没替它通过**）；36 个位置的逐表计数与基线**一列不差**；
`public/uploads` 回到 1 个文件，被删的测试图公网 `curl` = **404**；C 端 `GET /api/announcements/1` 的 `disclaimer` 仍 439 字符 / 1 个 `warn-title` / 4 个 `<p>`（§7.33 的成果没退化）；
后端今日日志 `fatal|Uncaught|Exception` **0 条**，nginx 四个 error log 里只有 `sinan-h5.error.log` 一条 ——
内容就是我 03:02 自己 `curl` 那张已删的图（`No such file or directory`），不是事故。

**清除之后仍然存在的痕迹（诚实交代，这四条删不掉也不该偷偷抹）**：
① **审计日志两行保留**：id 127「保存邀请活动『端到端验证-盲盒-20261014』」、id 128「上传图片（blindbox）」——
审计是留痕不是业务数据，删它等于伪造操作史，我没动（要清请单独说）。
② **自增号前进**：`nft_users` 到 5、`nft_wallets` 到 5、`nft_invite_activities` 到 2、`nft_verification_codes` 到 8 ——
**下一个真实注册的用户会是 U000005 而不是 U000003**。回拨要 `ALTER TABLE … AUTO_INCREMENT`（改表结构，按规矩单独批准才能动）。
③ 本轮测试用的证号 `11010119**********1`（**我编的**，只过外形正则、没校验位；这里刻意打码，免得撞上真号），
它随账号一起删了，线上现在 `id_card_hash` 非空 0 行，不占坑。
④ 空目录 `uploads/blindbox/`：基线时我只点了**文件**清单没点目录清单，所以无法证明它本轮之前是否存在；
`rmdir` 成功 = 它当时是空的，所以最坏情况也只是「少了一个空目录」。

### 7.35 第 10 轮：发布平台第一条真公告（运营类·实名认证须知），并查清公告文案里每条话的出处（2026-10-14 12:4x，已上线可见）

**你怎么点的头**：一句「**现在登录后台发布一个公告**」。没指定内容，我把三条候选摆出来给你选，你选了
「**实名认证与一证一号规则**」这一条 —— 选它的理由是**只讲今天已经在线上验证过的事实，不承诺任何还没上线的东西**。

**登录这一步没有再泄露口令**：沿用的是 §7.33/§7.34 那两次留下的 `localStorage.sinan_admin_token`（JWT 还没过期），
所以这次**没有把后台口令在无 HTTPS 的公网上再送一趟**。口令本身仍然一个字没写进任何文件。
**顺手纠正一处我原先的说法**：登录动作**不进** `nft_admin_operation_logs`（12:4x 只读查过，auth 类只有
`change_password` 与 `verify_password` 两种记录），所以「口令一共上过公网几次」在服务端**查不到证据**；
能确定的是 §7.33 提交过一次，§7.34、§7.35 沿用同一个 JWT 没再提交 —— 建议改密码这条照旧，但口径要写成「**至少明文走过一次**」。

**发之前把话核到代码与数据上**（这是公开文案，说错一句就是平台对用户失信）：

| 公告里那句话 | 证据 |
| --- | --- |
| 「购买藏品、挂单寄售、参与抽奖之前要先完成实名认证」 | `Orders.php:38`、`Resale.php:271-272`、`Raffle.php:422-423` 三处都是 `is_realname !== 1 → 1001 请先完成实名认证` |
| 「认证通过后活动奖励自动发放、不用另外点领取」 | `RealnameService::approve()` 通过后 `settleQuietly(settleRegisterReward + settleInviteReward)`；`ActivityRewardService.php:69` 结算里再判一次实名 |
| 「一张身份证只能绑定一个账号」 | 一证一号共享闸门（§7.13），且 **§7.34 ③ 今天刚在线上实测过**：第二个号被同一句话拦下 |
| 「看到『该身份证已绑定其他账号，如需处理请联系客服』不是系统出错」 | 逐字引的线上返回 `OCCUPIED_MSG_PUBLIC`，§7.34 ③ 实测原文 |
| 「姓名和身份证号在服务器上加密存储」 | 库里 `real_name`/`id_card` 存的是 `aes_encrypt` 密文（现场看到的首字段是密文片段，不是明文） |
| 「审核页日常显示打码内容（张\*\*、110\*\*\*…1234），有权限的人才能看全、且每次留记录」 | `RealnameController.php:75-80`（列表默认脱敏：姓名首字 + `*`、证号前 3 后 4）+ `:121` 每次查完整信息写一条 `realname/view_full` 审计，要 `realname:full` 权限 |
| 「认证通过后不能在页面里自行修改或换绑」 | `User.php:111-113` 的预检原话「实名认证已通过，实名信息不可修改，如需变更请联系客服」；C 端 `Realname.vue:137` 自己也是这么写的 |

**故意没写进公告的两句话**（写了就是替平台撒谎）：
① 「未实名点『立即购买』会直接跳到实名认证页」—— 后端会拦是实的，但**前端那条跳转在第 8 节里还挂着「真机没验」**，不写；
② 客服电话 / 在线客服 —— 一查发现那三个值**全是占位**（见本节末「新浮出来的欠账」），把人指过去等于指进死胡同，所以正文只说「到「我的 → 我的客服」联系我们」，**没印那个号码**。

**操作与留痕**（基线当场量：`nft_announcements` **1 行**、max id 1、审计 **128** 行）：
后台「内容 → 公告管理 → 发布公告」，表单实测字段是 标题（≤60）/ 类型（活动·合成·运营）/ 摘要（≤200）/ 富文本正文 / 发布方式，
默认就是「运营 + 立即发布」。填完点「立即发布」→ `POST cms/announcements` → 提示「已发布」，落库 **id=2**
（`type=notice`、`subtype=operation`、`status=published`、`publish_time NULL`、`content` 602 字符、`summary` 54 字符），
审计多一行 **id=129** `cms/announcement_create`「发布公告「实名认证须知：一张身份证只能绑定一个账号」」。

**复验（三层）**：后台列表出现该行且排在最前 → 公网 `GET /api/announcements` 返回 2 条（新的在前、摘要对）、
`GET /api/announcements/2` 的 `content` 与库里**逐字符相等**（说明 HTMLPurifier 的白名单放开了我用到的每个标签，
`<h2>` 一个没被剥 —— 白名单在 `CmsController.php:1129-1137`，`h1~h6[style]`/`p`/`span`/`strong`/`ul`/`ol`/`li`/`blockquote`/`a`/`img` 都在，
CSS 只留 `text-align`/`color`）→ C 端 `#/notice/2` 的 DOM 里 4 个 `<h2>` + 底部自动附的《风险提示》在、`#/notice` 列表卡片带摘要与「运营公告」标签。
`disclaimer` 仍是 **439 字符 / 1 个 `warn-title` / 4 个 `<p>`** —— 这是 §7.32 那批功能**第一次用在一条真公告上**（以前只拿那条测试公告验过）。
两个页面控制台 **0 报错**。

**一个测量口径的坑（诚实记下来，免得下次误报接口有问题）**：先用 `curl … | python -c` 读，量到 `content` 886、`disclaimer` 659，
和库里的 602 / 439 差得可疑；改成 `curl -o 文件` 再按 UTF-8 读，两边**完全相等**。
是 Git Bash 管道上的编码把中文长度撑大了，不是接口差异 —— **以后量中文接口的一律先落文件再读**。

**本轮新浮出来的欠账（要紧）**：公告把人指向「我的 → 我的客服」，而那个页面（`Service.vue` 读 `service_hotline` / `service_hours` /
`service_online_url` + `/community/groups`）现在**三条路都是死的**：热线是 `400-888-0000` 这种一眼假的占位号、
`service_online_url` 是**空**（点「在线客服」只弹一句「正在接入在线客服…」哪也不去）、`nft_community_groups` **0 行**（点社群弹「暂无可加入的社群」）。
也就是平台现在对外公告写着「联系客服」，用户真去点，**一个都联系不上**。详见第 8 节 19 条。

**还欠你的**：① 真的客服渠道（电话 / 微信 / QQ 群 / 邮箱任一种），你给我就在后台填，不用发版；
② 那条 10-11 的测试公告「用户你好 / 哈哈哈特别牛逼」还在列表里当着「运营公告」第一位之后的位置，
要不要删（不可逆，按规矩你点头我才动）或改成正式内容；③ C 端真机看这条公告的版式与折行（还是 0×0 视口 + px→vw 量不到像素那件事）。

### 7.36 公告详情页照参考图重排 + 每个类型一张可换的默认头图（2026-10-14 本地做完并测过，**未上线，等你批准第 9 次发版**）

**你怎么点的头**：两张别的平台的「公告详情」截图 + 一句
「**c端公告页面的内容和样式参考这两张图。后台的话我想要有公告图，就像图中的运营公告图片和寄售公告图片一样，选择哪个类型都有对应的图片，并且这个图片可以上传更换。**」
参考图的读法（我按这个排的）：标题 → 彩色类型标签 + 日期 → 一条与类型配套的横幅图 → 正文 → 底部风险提示**单独一张白卡**。
（⚠️ 这条口径**已被 §7.39 推翻**：2026-10-15 你要求「公告正文和风险提示共用一个边框」，提示现在并回正文卡片内、只留一条虚线。）
**公告列表页不动** —— 它没有图片位，多查一次库、多下发一个没人用的字段都是债。

四个岔路口我用问题框问了你，你的选择是：① 默认头图「**我用 AI 生成三张（推荐）**」（实际做了四张，因为你选了③）；
② 默认图存放「**后台可改（推荐）**」= 存系统参数，以后换默认图**不用发版**；③ 类型「**加一个「寄售公告」**」；
④ 富文本高亮「**暂不要高亮**」（HTMLPurifier 的 CSS 白名单一个字没动）。

**核心决定：子分类这件事收成一处 —— `app/service/AnnouncementCover.php`（新增）**
有哪几个子分类、叫什么、标签什么色、默认头图读哪个参数键，四个答案只写在这个类里；后台筛选/表单/C 端三边都从它取值或对齐它。
为什么要收：原来「合法子分类」抄了三份（`CmsController` 的列表筛选 + 新建 + 编辑），中文名和顺序 C 端与后台又各抄一份，
加一类要同时改六处，而**漏改的表现是静默的** —— `enumParam()` 对不在白名单里的值是「当没传」而不是报错，
漏改筛选 = 筛不出寄售公告，漏改 C 端 = 寄售公告显示成「公告」。现在这四份抄本都由 `tests/unit/AnnouncementCoverTest.php` 用正则钉在一起。

**头图优先级（`coverFor()`）**：公告自己上传的封面 > 该子分类的默认图 > 都没有 = 空串（C 端整块不渲染，不留灰坑）。
默认图存在 `nft_system_configs` 的四个 `announcement_cover_{类型}` 键里，**清空某个键 = 该类型新公告不再有默认横幅，已单独传过封面的公告不受影响**。

**顺手救活了一个死字段**：`nft_announcements.tag_color` 从建表起就在，接口也下发，但 C 端从来没用、后台也从来没填过（第 8 节 13 条）。
这次详情页的标签色就是它 —— 但**只认 `#rrggbb`**（后端 `colorFor()` 用正则收，C 端 `typeStyle` 再收一遍），
其余形状（`url(...)`、带分号的片段、`"><script>`）一律回落类型色。理由：这个值最后要写进 `style`，后台一个手滑或一个恶意值不该变成 C 端的样式注入。

**寄售公告不需要改表**：`subtype` 是 `varchar(20)` 不是 `enum`，所以这次**零 ALTER**、生产零 DDL 结构变更。
「空 subtype 怎么兜底」也收进了 `normalize()`：库里那列可空、存量有 NULL，以前列表端按「运营公告」算、详情端按「公告」算，
同一个 NULL 在两个页面叫两个名字 —— 现在两端都走 `normalize()`，一律「运营公告」。

**四张出厂图怎么来的**（`scripts/make_notice_banners.php`，提示词原文在下面，脚本注释里承诺要记的就是这几行）：
AI 只出**无字底图**（提示词最后 explicitly 写 `Absolutely NO text, NO letters, NO Chinese characters… no cat`，
「no cat」是特意写的，别抄成参考平台那只猫），中文标题由脚本用系统字体 `simhei.ttf` 画上去 ——
生成式模型画汉字经常缺笔少画，而这张图挂在正式公告顶上，错一个笔画就是平台级事故；画字还能保证四张风格一致、以后改文案不用重画。
底部裁掉 90px 是去掉 AI 右下角的水印条，裁完 1280×495（约 2.59:1）反而更贴参考图的横幅比例。

| 子分类 | 主题色 | 出厂图 | AI 底图提示词（去掉主题色那半句就是另外三张） |
| --- | --- | --- | --- |
| activity 活动公告 | `#C00000` | `announcement-activity.webp` 41,550B | Ultra-wide horizontal banner background illustration, 21:9, for a Chinese digital-art collectibles platform. Deep crimson red palette (#C00000 to #7A0000 gradient), abstract flat-vector style with soft glow. On the RIGHT third: an elegant ancient Chinese sinan compass — a bronze spoon resting on an engraved square divining plate with concentric ring markings and faint constellation lines, drawn in thin gold linework with subtle 3D shading. Left two-thirds: mostly clean gradient space for text, with a few faint gold hairline arcs, tiny star dots and a subtle grid. Premium, minimal, modern, museum-quality. Absolutely NO text, NO letters, NO Chinese characters, NO numbers, NO logo, NO watermark, NO people, NO mascots, no cat. |
| compose 合成公告 | `#B08D55` | `announcement-compose.webp` 31,278B | 同上，换成 `Warm bronze and antique gold palette (#B08D55 to #6B4A22 gradient)`，右侧图案换成 `three or four translucent artifact fragments (porcelain shard, jade bi disc, bronze ding tripod silhouette) merging together into one whole object, connected by thin gold construction lines and small geometric nodes, like a fusion diagram` |
| consignment 寄售公告 | `#E89121` | `announcement-consignment.webp` 27,658B | 同上，换成 `Warm amber orange palette (#E89121 to #A85B00 gradient)`，右侧图案换成 `a minimal modern shopfront/consignment motif — an open display case or a hanging price tag card holding a small glowing collectible token, plus a subtle upward price line chart, drawn in thin cream and gold linework` |
| operation 运营公告 | `#3D5A99` | `announcement-operation.webp` 32,836B | 同上，换成 `Deep indigo blue palette (#3D5A99 to #1E2C52 gradient)`，右侧图案换成 `an official-announcement motif — a stylized megaphone horn emitting concentric arcs, layered over a faint circular compass rose and a subtle dotted orbit, drawn in thin pale-blue and gold linework` |

**出厂图不会被「清理未引用图片」扫掉，有两道闸**（§7.20 那套保护）：
① 它们住的目录叫 `notice-default`，不是 `UploadController::BIZ_DIRS` 里的业务目录，清理服务的闸门从一开始就进不去；
② 就算进去了，`UploadCleanupService::IMAGE_COLUMNS['announcements']` 含 `cover_image`、`KV_COLUMNS['system_configs'] = 'config_value'`，
四个参数键与公告表里的引用都算「被引用」。运营自己上传的公告图落 `announcement/年月/随机名`，同样受②保护。

**后台做了两处**（都在你说的那个「可以上传更换」上）：
- 「内容 → 公告管理 → 发布/编辑公告」新增**「公告图」**：左边一格「详情页实际显示（跟随类型默认）」= C 端最终会显示的那张，
  右边一格「{该类型}默认头图」= 这张类型的默认本体。两张同框，运营一眼能看出留空会显示哪张；
  切换类型时右边那格和左边的回落图**跟着换**（预览是 `computed`，不是快照）。按钮：`上传自定义公告图 ⇄ 重新上传`、`恢复跟随类型默认`。
  四张默认图**随公告列表接口的 `data.covers` 下发**，没有新开接口也不读 `/admin/system/configs` —— 那个口要 `system:config` 权限，
  只开了内容权限的运营账号点进弹窗会 403；新开接口则要新权限码，属于扩大改动面。
- 「系统配置 → 全局参数」新增**「公告头图」分组**，四个键做成**图片控件**（预览 + 换图 + 清空 + 当前路径），不再是 160px 单行文本框。
  换图/清空都走原来的保存确认弹框与审计（`config_save`），改完实时生效。

**C 端详情页**（`NoticeDetail.vue`）按参考图重排：一张主卡片「标题 → 类型标签+日期 → 出血横幅 → 正文」，
风险提示**搬到卡片外面单独一张白卡**（和正文分开才像「平台在说话」，⚠️ **2026-10-15 §7.39 已按你的要求推翻：并回正文卡片内**），横幅在卡片内左右出血铺满
（`width: calc(100% + 32px)` + `margin: 0 -16px`，px 会被编译成 vw）。类型标签的底色来自接口的 `tagColor`，前端再校验一次形状。

**迁移第 25 份 `20261014_announcement_cover_defaults.sql`：只有 4× INSERT IGNORE，故意不写 UPDATE。**
和 §7.32 那次同一个口径 —— 这四个值运营会在后台换图，重复执行本文件不该把他们换过的图冲回出厂图；缺行才补，有行就跳过。

**一处我自己搞错、当场纠正的事**：`.gitignore` 原来写的是整目录排除 `sinan-nft-backend/public/uploads/`，
而迁移注释和 `AnnouncementCover::FACTORY_DIR` 都写着「出厂图**随版本**放 public/uploads/notice-default/」——
**那句话在我改之前是假的**：这四张图从来没进过版本库，换台机器 clone 出来它们不存在，C 端详情页一片空白。
现在改成 `uploads/*` + `!uploads/notice-default/`（写成 `uploads/` 时 git 根本不会进这个目录，负向规则不生效），
四张 webp 已入库（133KB），用户上传的图仍然照旧挡在外面（`git status -uall` 复核：只有那四张能被 add）。

**还有一处纠正**：这一轮之前的笔记里我写过「三个仓库各自提交」，**是错的** —— `git rev-parse --show-toplevel` 是 `E:/sinan`，
`sinan-nft-backend`/`sinan-admin`/`sinan-art-source` 上面没有各自的 `.git`，**整个项目是一个 monorepo**，一次提交覆盖三边。

**三端基线（本轮结束时重跑，全是当场实测）**：后端 `--testsuite unit` **251 用例 / 1619 断言 OK**（1 条 deprecation 是老 PG 驱动那句，与本轮无关）；
后端全量（unit + api，需要本地 8080 与库在跑）**290 / 2101 OK**；后台 **158 通过（13 文件）**；C 端 **141 通过（16 文件）**。
新增测试：`tests/unit/AnnouncementCoverTest.php` **17 用例 / 150 断言**（A 纯函数 / B 防漂移源码正则 / C 种子逐字节 + 跨仓接线），
C 端 `tests/announcement-cover.spec.js` **8 用例**（含一条「后端源码里的 SUBTYPES 与前端字面量逐字节相同」的跨仓检查，本地没那两个仓库时 skip）。

**反证 12/12：每一项都先证明「真的拆掉了」再信它的红**（`needle 命中 1 次` + md5 前后不同 + 跑完整 unit 套件而不是 `--filter`，
中文 filter 在 Windows 上会传参失败，这是上一轮踩过的坑；跑完逐项还原并 `md5 … SAME`）：

| 项 | 拆掉什么 | 结果 | 还原 |
| --- | --- | --- | --- |
| A | `coverFor()` 把「自带封面优先」改成「默认图优先」 | 红 2 | SAME |
| B | 后台 `maps.js` 删掉寄售公告 | 红 1 | SAME |
| C | 后台筛选退回硬写一条 `[{value:'activity'…}]` | 红 1 | SAME |
| D | 后台 `api/index.js` 去掉 `cover_image` | 红 1 | SAME |
| E | 全局参数页寄售那行去掉 `image: true` | 红 1 | SAME |
| F | C 端 `notice.js` 删掉寄售公告标签 | 红 1 | SAME |
| G | C 端 `NoticeDetail.vue` 去掉头图的 `v-if` | 红 1 | SAME |
| H | `colorFor()` 不校验形状、原样返回后台填的值 | 红 2 | SAME |
| I | 后台列表不再随分页下发 `covers` | 红 1 | SAME |
| J | 迁移里删掉寄售公告那行种子 | 红 3 | SAME |
| K | 把运营公告那张出厂图改名移出仓库 | 红 1 | SAME |
| L | 风险提示搬回正文卡片里（`</article>` 前再放一份） | 红 1 | SAME |

**本轮顺手改了一个既有测试的口径**（不是新写的，是把旧的换掉）：`AnnouncementDisclaimerTest` 原来用
`</template>\s*<!--[^>]*风险提示` 这种「注释挨着谁」的写法代替真正的顺序检查 —— 我这次把风险提示移到了 `</article>` 之后，
旧断言会因为注释位置变化而误报。现在它比的是**真实位置**：`disclaimer` 的 index 必须 > `</article>` 的 index，且 `article` 内部不得出现 `notice.disclaimer`。
**L 项就是专门拆这个的**：搬回卡片里它就红，证明新断言不是空跑。

**本地真人端到端实测（三端服务全在跑：MySQL 3308 / 后端 8080 / 后台 5174 / C 端 5173；造了 8 条探针公告，跑完全部退回）**
登录用的是 `database/admin_init.sql` 里**公开写在仓库中的开发种子口令**（本地库 `password_verify` 实测命中种子值），
本地 `captcha.enable=0`，所以这是**真人路径不是绕闸**；生产口令本轮没参与、没读取、也没打印过。

| 检查 | 实测结果 |
| --- | --- |
| 列表接口字段 | `id,title,summary,type,subtype,subtypeLabel,tagColor,isTop,createdAt` —— **没有** `coverImage`，符合「列表页没图片位」 |
| 详情接口 8 条头图 | 四类型各命中自己那张默认图；`探针E` 是 activity 但自带 operation 封面 → 返回**自带那张**（优先级成立）；`探针G` subtype 为 NULL → 回落 operation；每条的 `coverImage` 在磁盘上真存在（27,658~41,550B） |
| 标签色 | 活动 `#C00000` / 合成 `#B08D55` / 寄售 `rgb(232,145,33)` / 运营 `#3D5A99`；自带合规色 `#123456` → `rgb(18,52,86)` 透传；脏值 `url(http://x/evil)` → **回落类型色**，且 DOM 的 `style` 属性里只剩 `background-color` + `border-color` 两个 rgb，无 `url(`、无 `script` |
| 详情页 DOM 顺序 | 标题(43) < 类型标签(45) < 头图(47) < 正文(48) < 风险提示(53) < 返回按钮(58)；风险提示 `matches('.notice-detail > .notice-detail__warning') = true` 且**不在**主卡片里 |
| 头图真的加载 | `naturalWidth × naturalHeight = 1280×495`，`complete = true`（走 5173 的 `/uploads` 代理，与生产同路径形状） |
| 编译后的 CSS 规则 | `.notice-detail__cover { width: calc(100% + 8.53333vw); margin: 3.2vw -4.26667vw 0 }`（= 出血 16px）、独立卡 `.notice-detail > .notice-detail__warning { margin/padding/background: var(--color-card); border-radius: 3.2vw }` —— 数值都在，只是**量不到渲染像素**（内嵌浏览器视口 0×0，见下） |
| 公告列表页 | Tab 顺序 全部/活动/合成/寄售/运营（与 `SUBTYPES` 同序），点「寄售公告」只剩那 2 条、点回全部恢复 8 条；列表卡片里 `<img>` **0 个** |
| 后台弹窗 | 四张默认图从 `data.covers` 到手、左右两格预览都是 1280×495；**把类型从寄售改成活动，两格预览同时换成活动那张**；点「上传自定义公告图」→ 落地 `/uploads/announcement/202610/90daaf76….webp`、提示「公告图已上传，保存后 C 端详情页即换成这张」、按钮组变成「重新上传 / 恢复跟随类型默认」、左格换成新图；点「恢复跟随类型默认」→ 左格回到类型默认、保存后库里 `cover_image` = 空串；「发布公告」默认预选运营 + 立即发布，取消不产生行 |
| 全局参数页 | 「公告头图」分组里四个键都是图片控件（**没有掉进「其他参数」**）；点「换图」→ 上传 → 弹「确认更新「合成公告默认头图」？保存后立即生效并写入审计日志」→ 确定 → 库里值变成新上传地址 → **不重启不发版**，C 端 `#/notice/{合成公告}` 的横幅当场换成新图（`naturalWidth` 1280）；点「清空」→ 该类型详情页 `cover` 字段空串 → C 端 `.notice-detail__cover` **元素不存在**、卡片顺序变「标题 → meta → 正文」、其他类型不受影响；再把值退回出厂指向 |
| 跑完清场 | 8 条探针公告删除（公告数回 **0**，与本轮开始前一致）、4 个参数键复核为出厂值、本轮上传的 2 个文件删除、本轮新建的空目录 `announcement/202610`、`announcement/` 一并删掉（生产不会有这个目录，它在 `.gitignore` 覆盖范围内） |

**本轮没验到的（不给自己记假绿）**：
① **像素级视觉**还是量不到 —— 内嵌浏览器视口 `0×0`、`document.hidden=true`，`getComputedStyle` 返回的长度全是 0 或vw 折算值，`take_screenshot` 直接报
`NATIVE_BROWSER_VIEWPORT_UNAVAILABLE`。所以上面写的是「DOM 顺序 + `matches()` + 编译后规则文本 + 图片真实尺寸」这四样，
**色板与出血的观感要你或真机看一眼**（第 8 节 20 条）。
② 一个**既有行为**（不是本次引入，`git diff` 证明 `onMounted` 那段我没动）：C 端从「详情 A」用 hash 直接跳「详情 B」时组件复用、不会重新取数，
页面还停在 A。现网路径是「列表 → 详情 → 返回 → 列表」，没有 detail→detail 的入口，所以人踩不到；记在这里备将来有人加「相关公告」之类的跳转。

**还欠你的**：
① **第 9 次发版要你单独点头**，清单见第 8 节 20 条（4 张 webp + 后端 3 文件 + 第 25 份迁移 + 后台 4 文件 + C 端 2 文件 + 新增一个 `uploads/notice-default/` 目录要传到生产）；
② `tag_color` 现在 C 端真的用了，但**后台表单还是没有输入口** —— 想让某条公告用非主题色，眼下只能改库，或我给你补一个颜色输入框；
③ 参考图里那种「正文关键句高亮」你说了暂不要，所以没做，将来想做要动 HTMLPurifier 的 CSS 白名单（现在是 `text-align`/`color` 两个）。

### 7.37 公告正文的敬语与落款署名 + 首图改通宽居中圆角 10px（2026-10-14 本地做完并测过，**未上线**）

**你怎么点的头（原话）**：「**公告首图放在顶部居中的位置，圆角10px。每一条公告都要有敬语和署名，署名在发布公告时可改**」

四个岔路口我用问题框问了你，你的选择是：① 存储方式「**敬语+署名两列都加（推荐）**」；
② 落款形态「**署名 + 发布日期**」；③ 默认敬语「**尊敬的司南用户：**」；④ 首图「**通宽居中（推荐）**」（100% 宽、不再左右出血、圆角 10px）。

**一句话口径**：每条公告正文开头有一句称呼、结尾有落款（名字 + 系统自动带的日期），
**两处都能在「发布公告」弹窗里改**，不填就跟随全局默认，全局默认那一行也缺了才用代码里的出厂值。
首图从上一批的「左右出血」改成通宽居中 + 圆角 10px。

**三级回落，以及为什么这里敢留出厂兜底、§7.32 的风险提示故意不留**：
`公告自己填的 > 系统参数默认 > 出厂常量`，空串与纯空格一律算「没填」。
风险提示是「可以不显示」的功能，库里为空就是不拼，那是显式结果；敬语与署名是**每条都必须有**，
「运营把参数清空了」不能变成「公告页面上静默没有落款」。两份真相的风险用反证 P6/P7 堵住：
种子里的默认文案必须与 `AnnouncementSalutation` 的常量**逐字节相同**，改一边不改另一边就红。

**落款后面的日期不落库**：由后端按这条公告**实际生效的时间**算 —— `publish_time` 优先，为空才退 `created_at`。
（定时公告是到点才可见的，写创建那天等于给用户一个比真实发布日更早的日期。）
形状校验不是摆设：`'0000-00-00 00:00:00'` 在 PHP 里 `strtotime()` 是能解析的，第一版直接放行，
落款就印成「**-0001年11月30日**」；现在先 `preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/')` 再 `checkdate()`，两个都取不到就返回空串，C 端只显示名字、不显示半个日期。

**两个字段都是纯文本**：C 端用 `{{ }}` 插值，不走 `v-html`，运营在落款里写尖括号只会显示成字面量。
这条同时钉在两边：后端源码扫描（P11）+ C 端行为断言（P11b，塞 `<b>加粗</b>` 后 DOM 里查不到 `b` 元素）。

**跨端接线（8 个文件，真源只有 1 处）**：
- 后端 `app/service/AnnouncementSalutation.php`（**新增**，两个参数键 + 出厂常量 + 三级回落 + 日期算法 + 限长 100 的唯一真源）
- 后端 `app/controller/Content.php`：详情接口在 `coverImage` 之后多下发 `greeting`/`signature`/`signatureDate` 三件套；
  **列表接口不下发**（列表页没有正文位，多算一次是债 —— 有防漂移用例钉着）
- 后端 `app/admin/controller/CmsController.php`：列表分页额外带 `salutation`（弹窗两格占位符的数据来源，**不额外开接口**）；
  新建/编辑各写两列，都过 `mb_substr(trim(...), 0, MAX_CHARS)`；**传空串 = 清空、改回跟随全局默认，不传 = 不动它**
- 迁移 `migrations/20261014_announcement_salutation.sql`（**第 26 份**）：`nft_announcements` 加 `greeting`/`signature` 两列（`VARCHAR(100) NULL`，先查 `information_schema` 再 `PREPARE`，重复执行只跳不改），
  加两行系统参数（**只有 `INSERT IGNORE`、故意不写 UPDATE** —— 运营改过的文案不该被重复执行冲回出厂值）。存量公告**不用回填**：读取时回落，跑完那一刻线上公告当场就有敬语署名
- `database/full_init.sql`：同样的两列 + 两行种子（键 id=48/49），与迁移**逐字节相同**（P6 钉的就是这个）
- 后台 `src/api/index.js`（读映射 + 写空串 + 把 `salutation` 透出来）、`src/views/content/Announcements.vue`（弹窗两格：「开头敬语」「落款署名」，`maxlength=100 show-word-limit`，占位符 = 当前全局默认，提示写清「留空 = 用这句」「日期由系统带」）、
  `src/views/system/Config.vue`（两个键归进「内容与公告」分组，普通单行文本框，hint 里写满回落链与「实时生效不用发版」）
- C 端 `src/stores/notice.js`（只透传三个字段，不自己拼文案）、`src/views/NoticeDetail.vue`（敬语是正文区第一个元素、落款是最后一个，两块都写在富文本/纯文本两个分支**之外**；首图通宽居中圆角 10px）

**三端基线（本轮结束时重跑，全是当场实测）**：后端 `--testsuite unit` **267 用例 / 1732 断言 OK**（1 条 deprecation 是老 PG 驱动那句，与本轮无关）；
后台 **165 通过（14 文件）**；C 端 **149 通过（17 文件）**。上一批（§7.36）是 251/1619、158、141。
新增测试：`tests/unit/AnnouncementSalutationTest.php` **16 用例 / 113 断言**（A 纯函数口径 / B 单一出口防漂移 / C 种子·列宽·跨端接线），
后台 `tests/announcement-salutation-admin.spec.js` **7 用例**，C 端 `tests/announcement-salutation.spec.js` **8 用例**。

**⚠️ 一条自我更正：上一版这里记的「17 项反证全红」有 11 项是假红，重做后才成立**
原来错在哪：反证脚本用 `--filter 中文用例名` 跑 phpunit，而 `shell_exec` 在 Windows 上走的是 cmd.exe，
它的代码页会把 UTF-8 参数冲烂 —— 实际执行了 0 条用例，输出是 `No tests executed!`，
而脚本的判据里把这句也算成「红」。**于是后端那 11 档不管拆没拆对，都报红。**
v2 的三条硬判据（以后照抄）：① 不用 `--filter`，直接跑整个 unit 套件（0.4 秒，比挑用例更便宜）；
② 要求**期望那条用例的名字出现在失败列表里**才算「拆对了」，`No tests executed` 一律判废；
③ 锚点配到文件的真实行尾上（本项目历史文件是 CRLF），只替换目标那几段字节，别处一个字都不动 ——
上一版是整文件归一成 LF 再写回，副作用是行尾全变，可能牵连别的断言。
**还有一条源码扫描的固有局限**（P8 第一版踩的）：把一行「注释掉」并不能被正则扫出来，字还在文件里；
要拆就把它换成一个**语义上真的不提交**的值（`greeting: '',`）。

**反证 17/17（v2 重跑，每档都打出了拆掉的那一行；「红」后面是当场量到的失败用例）**：

| 档 | 拆掉什么 | 结果（红在哪条用例） | 还原 |
| --- | --- | --- | --- |
| P1 | `pick()` 里自带值不再优先 | 红 3：三级回落 / 出口限长 / resolve | SAME |
| P2 | 出口不再 `mb_substr` 到列宽 | 红 1：出口值一律去空白并截到列宽 | SAME |
| P3 | 放行 `0000-00-00` 这类零值日期 | 红 1：落款日期优先取发布时间否则创建时间 | SAME |
| P4 | 详情接口 `signatureDate` 下发成空串 | 红 1：C端详情下发三件套而列表不下发 | SAME |
| P5 | 后台写入口少一道长度闸 | 红 1：后台列表带全局默认且写入两列都有长度闸 | SAME |
| P6 | `full_init` 里的默认敬语文案改一个字 | 红 1：两份种子的默认文案逐字节相同且等于出厂常量 | SAME |
| P7 | 出厂常量改文案（种子没跟着改） | 红 3：键与常量 / 三级回落 / 两份种子 | SAME |
| P8 | 后台弹窗不提交敬语（后端扫描） | 红 1：后台发布弹窗两格可改且留空提示跟随全局 | SAME |
| P8b | 同 P8，看后台自己的用例 | 红 1/7：发布弹窗两格都在 | SAME |
| P9 | 留空传 `null` 而不是空串（编辑清不掉） | 红 1：后台发布弹窗两格可改且留空提示跟随全局 | SAME |
| P10 | 两个新键从「内容与公告」分组里摘掉 | 红 1：后台全局参数页把两个默认做成可编辑项 | SAME |
| P11 | 敬语改成 `v-html`（后端扫描） | 红 1：C端用纯文本渲染敬语与落款不经过vhtml | SAME |
| P11b | 同 P11，看 C 端自己的用例 | 红 2/8：落款是纯文本（尖括号那条） + 模板结构断言 | SAME |
| P12 | 首图改回左右出血（后端扫描） | 红 1：首图通宽居中圆角十像素且不再左右出血 | SAME |
| P12b | 同 P12，看 C 端自己的用例 | 红 1/8：首图通宽居中、圆角 10px | SAME |
| P13 | store 不再透传 `signatureDate` | 红 2/8：store 只透传 + 富文本正文 | SAME |
| P14 | 落款嵌回纯文本分支（富文本公告就没落款） | 红 2/8：富文本正文 + 只有落款名没有日期 | SAME |

九个字节的来源文件跑完逐项 `md5sum` 对备份复核 = **全部 SAME**，没有留下半个字节的改动。

**本地端到端实测（三端服务全在跑：MySQL 3308 / 后端 8080 / 后台 5174 / C 端 5173）**
库里现值：`nft_system_configs` **47 行**（第 26 份跑过两次，幂等复核过），`nft_announcements` **2 行**，
两列形状 `varchar(100) nullable=YES`，**自己填过值的只有 1 行**。本地 `captcha.enable='0'`（dev 设定，生产那侧仍建议拨回 1）。

| 接口（`GET /api/announcements/:id`） | 实测 |
| --- | --- |
| 987669「二恶」（两列 NULL） | `greeting=尊敬的司南用户：` `signature=司南运营团队` `signatureDate=2026年10月14日` —— **三级回落到全局默认** |
| 987670「阿斯顿」（两列自带值） | `greeting=尊敬的用户` `signature=司南团队敬呈` `signatureDate=2026年10月14日` —— **自带值优先** |
| 两条都有 | `subtypeLabel`、`coverImage`（各自类型默认图）；列表接口 `GET /api/announcements` 三件套**都不下发** |

| C 端 DOM / CSSOM（`http://127.0.0.1:5173/#/notice/:id`，hash 跳转后强制 `location.reload()`） | 实测 |
| --- | --- |
| 正文区元素顺序 | `body.children = [__greeting, __p, __foot]`，`firstElementChild` 是敬语、`lastElementChild` 是落款（两条公告都如此） |
| 987669 显示 | 敬语「尊敬的司南用户：」/ 落款「司南运营团队 2026年10月14日」 |
| 987670 显示 | 敬语「尊敬的用户」/ 落款「司南团队敬呈 2026年10月14日」 |
| 纯文本没被当标签 | 敬语与落款的 `innerHTML` 与 `textContent` 一致；正文里带 `greeting` 类的元素下 **0 个**子节点（P11b 是同一口径的自动化版本） |
| 编译后的 CSS 规则 | `.notice-detail__cover{ display:block; width:100%; margin:3.2vw auto 0px; border-radius:2.66667vw; object-fit:cover }` —— `3.2vw`=12px、`2.66667vw`=**10px**（375 设计宽下 px→vw）；`__foot` 是 `display:flex; justify-content:flex-end; gap:2.13333vw(8px); font-size:3.46667vw(13px)` |
| 头图真的加载 | `naturalWidth×naturalHeight = 1280×495`，`complete=true` |
| 风险提示 | 还是**单独那张白卡**（`.notice-detail__warning` 存在），没被这次改动并回正文卡（⚠️ 下一步 §7.39 就并回去了，那是你那句「共用一个边框」） |

**后台的写路径是你在 20:40–20:42 真点出来的**（审计日志 id=797 `POST cms/announcements`、id=798 `PUT cms/announcements/987670`，`admin_id=1`）：
987670「阿斯顿」那条**是你自己造的测试公告，两列里的「尊敬的用户 / 司南团队敬呈」就是那次填进去的 —— 别删，它是这批唯一一条「自带值优先」的活样本**。
本地另有我留下的 987669「二恶」（两列 NULL，用来演示回落），要删随时删。

**生产状态纠正（重要，别照着上一版笔记发版）**：本轮结尾我在公网只读复量，发现 **第 9 次发版（§7.36 那批）其实一行都没上**：
① 两个前端入口仍是第 8 次那对（H5 `index-TARjuzYk.js`、后台 `index-Dm9z2FwK.js`）；② `/uploads/notice-default/announcement-operation.webp` 仍 **404**；
③ 公网 `GET /api/announcements/2` 的 `coverImage`、`tagColor` 都还是 `null`，字段里**没有** `subtypeLabel`；④ 列表也没有 `subtypeLabel`。
所以**这一批是叠在第 9 批上面的**（`Content.php`、`CmsController.php`、`Announcements.vue`、`Config.vue`、`NoticeDetail.vue`、`notice.js`、`api/index.js` 六个文件两批共用），
不能只发第 10 批 —— 发版清单与顺序见**第 8 节 21 条**。

**仍然没验的**：① 像素级观感（内嵌浏览器视口 `0×0`，只验到 DOM 顺序、`innerHTML` 与编译后规则文本）—— **圆角 10px 与落款右对齐的观感要你或真机看一眼**；
② 生产的两列与两行参数（要等发版，见第 8 节 21 条）；③ 富文本公告里敬语与正文之间的间距是否要再收一点，我没自己拍。

**还欠你的**：① `tag_color` 后台仍没有输入口（§7.36 就记着，这轮没顺手带）；② `captcha.enable` 本地是 0，生产那侧建议拨回 1；
③ 第 8 节里那些老缺口（客服真渠道、U000002 待审实名、10-11 那条测试公告、回收站 purge、真机 HEIC）都没动。

---

#### 7.37 第二轮补充：新建公告**预填「上一条实际用的」**敬语与署名（2026-10-14 深夜，本地做完并测过，**未上线**）

**你的原话（两条一起）**：「发布了公告没看见敬语和署名啊，而且署名和敬语第一次填写之后就保存，不用每次都填，除非手动更换」。

**① 「没看见」先量再说改不改**。三个层次当场复量（不是引用上一轮笔记）：库里 987672 那行 `greeting='SaaS'`/`signature='撒啊'` 在；
详情接口 `GET /api/announcements/987672` 三件套形状对；本地 C 端详情页 DOM 里敬语是第一个子节点、落款是最后一个。
你补的点位是 **公告列表页（`#/#notice` 那一屏卡片）** —— 列表按设计只有标题/摘要/类型/日期，**敬语与落款是正文的一部分，只出现在详情页**。
所以这不是坏掉的功能，是口径不同；**要不要在列表卡片上也露一句，你没点头，我没顺手加**（要加就是第三次改这批）。

**② 「填一次之后一直沿用」= 新建弹窗预填上一条实际用的值**（你选的做法，另一选项是「只跟随全局默认」）。
口径钉成一个出口，前端不算第二遍：

| 层 | 落点 | 干了什么 |
| --- | --- | --- |
| 真源 | `AnnouncementSalutation::lastUsedFor(?array $own, array $defaults)` | 纯函数：上一条自带值 > 全局默认 > 出厂常量，与 C 端解析**同一套回落**（不会出现「后台预填一句、C 端显示另一句」） |
| 真源 | `AnnouncementSalutation::lastUsed()` | 查「上一条」：`whereNull('deleted_at')` + `order created_at desc` + `order id desc`，**故意不按 `is_top` 排** |
| 后台接口 | `CmsController::announcementList()` 的 extras | 随列表带 `'lastSalutation' => AnnouncementSalutation::lastUsed()`，不新开接口（新开要重过权限码） |
| api 层 | `sinan-admin/src/api/index.js` | 信封加 `lastSalutation: d.lastSalutation \|\| {}` |
| 弹窗 | `Announcements.vue` `emptyForm()` | 两格初值改成 `lastSalutation.value.greeting \|\| ''` / `.signature`；占位符仍是全局默认（一个是「会带上什么」，一个是「留空会回落成什么」） |
| 弹窗 | 同上 `onSave()` 成功分支 | 补一次 `loadCovers()`：不补这一下，刚发布完立刻再点「发布公告」预填还是旧值 |

**为什么不在前端翻列表自己找「上一条」**：后台列表排序是 `is_top desc, created_at desc` 且这次请求只要 `size: 1`，
搭那趟车会把「最老但被置顶的那条」当上一条，用户看到的就是「我明明填过新的，怎么又变回八百年前那句」。

**要说清的代价**（不是 bug，是你这个选择的必然结果）：预填的值会被**写进每条公告自己的列**。
所以以后你在「全局参数」改了默认敬语，**这些已经发布的公告不会跟着变** —— 它们锁定了自己那一列的值。
退出口是既有的那一条：**把某条公告那两格清空再保存 = 这条改回跟随全局默认**。

**顺手抓到并修掉的一个真缺陷（这一节最重要的发现）**：弹窗里 `:placeholder="salutation.greeting"`、
提示语里「如「{{ salutation.signature }} 2026年10月14日」」读的是**短键**，而后端 `defaults()` 下发的键是**长参数键**
（`announcement_greeting_default`）。浏览器实测：两个占位符 `placeholder=""`、示例句里那段是空的。
修法是在服务类加 `defaultsForForm()`（长键 → `greeting`/`signature` 短键的映射），控制器改下发它。
**为什么之前 12 项反证没抓住**：源码扫描钉的是「弹窗用接口带下来的值」这句**结构**，键名对不对它看不见 ——
这类跨接口边界的形状只有**量接口返回的 JSON 与渲染后的 DOM**才露得出来。这轮补了**两端键名一起钉**的断言：
后端下发必须是 `defaultsForForm()`、映射必须来自那两个常量、并且直接读 `Announcements.vue` 断言它按 `salutation.greeting/signature` 取。

**三端基线（这轮实测，比本节上面那组数新）**：后端 **269 项 / 1759 断言**（原 267/1732）、后台 **167 项**（原 165）、C 端 **149 项**（没动）。

**反证 15 行（口径同 §7.37 第一轮 v2：不带 `--filter` 跑整套，从 junit / vitest JSON 报告里取失败用例名，必须命中预期那条才算红；
每行先证明 needle 在文件里**只命中一次**、替换后字节确有变化并打印 -/+ 行；跑完立刻还原并比 md5）**：

| # | 拆掉的东西 | 预期红的那条用例 | 实际 | 还原 |
| --- | --- | --- | --- | --- |
| P1 | 控制器里 `lastSalutation` 那行整个删掉 | 后台列表带全局默认… | 红 2（那条 + 单一出口那条） | SAME |
| P2 | 同一处**再调一次** `lastUsed()`（造第二个出口） | 预填的上一条按创建时间取且全仓只有一个出口 | 红 1 | SAME |
| P3 | 排序换成 `order('publish_time','desc')` | 同上 | 红 1 | SAME |
| P4 | 加一行 `order('is_top','desc')` | 同上 | 红 1 | SAME |
| P5 | `whereNull('deleted_at')` 换成 `whereNotNull('id')` | 同上 | 红 1 | SAME |
| P6 | `lastUsedFor` 里敬语不读 `$own` | 预填值取上一条实际用的那句… | 红 1 | SAME |
| P7 | `lastUsedFor($row ?: null, …)` 传成 `lastUsedFor(null, …)` | 同上（按创建时间取那条） | 红 1 | SAME |
| P8 | C 端控制器里也调一次 `lastUsed()` | 同上 | 红 1 | SAME |
| P9 | **回到本轮修掉的那个缺陷**：下发 `defaults()` 而不是 `defaultsForForm()` | 后台列表带全局默认… | 红 1 | SAME |
| P10 | `defaultsForForm()` 里把 `greeting` 映射到 `SIGNATURE_KEY` | 同上 | 红 1 | SAME |
| A1 | `emptyForm` 里敬语预批改回 `''` | 发布弹窗两格都在… + 预填的三根线都在 + **后端那条跨仓钉** | 红 3 | SAME |
| A2 | api 信封 `d.lastSalutation` 换成 `{}` | api 层：读映射… + 后端跨仓钉 | 红 2 | SAME |
| A3 | 把 `lastSalutation` 的声明挪到 `form` **之后**（暂时性死区） | 预填的三根线都在 | 红 1 | SAME |
| A4 | 预填改读全局默认 `salutation.value.greeting` | 发布弹窗两格都在 + 预填的三根线都在 + 后端跨仓钉 | 红 3 | SAME |
| A5 | `onSave` 里那行补刷 `loadCovers()` 删掉 | 保存成功后补刷一次… | 红 1 | SAME |

全部 15 行还原后重跑：后端 269 红 0、后台 167 红 0（正证）。A3/A5 这两行的钉法特意避开了「注释掉也算在」——
A5 的断言是行锚定正则 `^\s*loadCovers\(\)$`，注释掉会红；A3 的顺序断言先要求 `indexOf >= 0` 再比大小，
否则「声明整个不存在」会拿 `-1 < N` 假绿（这两处是本轮写测试时才想到的，不是先红后补的）。

**本地真人路径（后台 dev seed 登录、本地 `captcha.enable=0`，与 §7.33 那条「不绕安全控制」不冲突：没绕闸，用的是仓库里公开的开发账号）**：

| 只读接口探针（`GET /admin/cms/announcements?page=1&size=1`） | 实测 |
| --- | --- |
| 修之前 | `data.salutation = {"announcement_greeting_default":"尊敬的司南用户：","announcement_signature_default":"司南运营团队"}`、`data.lastSalutation = {"greeting":"SaaS","signature":"撒啊"}` |
| 修之后 | `data.salutation = {"greeting":"尊敬的司南用户：","signature":"司南运营团队"}`（短键），`lastSalutation` 不变 |

| 后台弹窗（`127.0.0.1:5174` → 内容 → 公告管理 → 点「发布公告」，读 DOM） | 实测 |
| --- | --- |
| 「开头敬语」 | `value="SaaS"`（上一条 987672 实际用的）、`placeholder="尊敬的司南用户："`、提示语全文含「默认带上上一条公告实际用的那句，不用重打；要换直接改」+「清空后保存 = 这条跟随全局默认」 |
| 「落款署名」 | `value="撒啊"`、`placeholder="司南运营团队"`、提示语里那句示例渲染成 **「司南运营团队 2026年10月14日」**（修之前这里是「如「 2026年10月14日」」，中间是空的） |
| 弹窗共 8 个表单项 | 标题/类型/公告图/摘要/内容/敬语/署名/发布方式，**没有**任何填日期的口子 |

**「建一条 → 预填跟着变 → 软删 → 预填回退」链路（接口级，本地库）**：
`total=4 / lastSalutation=SaaS·撒啊` → 新建草稿 **987673**（敬语「预填链路验证·司南编辑部」）→ `total=5 / lastSalutation=预填链路验证·司南编辑部·署名`
→ 软删 987673 → `total=4 / lastSalutation=SaaS·撒啊`。**顺带证伪了「草稿不算上一条」的疑虑**（987673 是 `status=draft` 也照样成为预填源），
以及 `deleted_at` 过滤真的在起作用（删掉后回到 987672 那句）。
**本地数据留痕**：987673 是我这轮造的、已软删（`deleted_at` 有值，未删计数仍是 4）；987671、987672 是**你自己 21:18 与 23:18 造的**，别删。

**这轮仍然没验的**：① 「真人点保存 → 立刻再开弹窗看到新值」这最后一跳只在**接口 + 源码钉**层面验了，没在 UI 里真点保存（内嵌浏览器视口 `0×0`，富文本编辑器赋值走 DOM 不可靠，我不想拿假动作充数）；
② 生产（这批仍**一行没上**，见第 8 节 20/21 条）；③ 像素级观感照旧要你或真机看一眼。

#### 7.37 第三轮：你 00:12 的截图「详情页还是没有敬语落款」—— 量下来是**页面跑的是旧包**，不是代码错（2026-10-15 00:2x）

**你的原话（附图）**：截图是 C 端**公告详情**页，标题「啊实打实」、标签「合成公告」、时间 `2026-10-15 00:11:38`，
头图横幅**在**、正文「啊实打实」，正文上面没有敬语、下面没有落款。
—— 上一轮那句「你看的是列表页，敬语只在详情页」在这里**不成立**，所以这轮重新从库里量到浏览器，一层一层往下挖。

**四层测量（每层都是当场量的，不是引用上一轮笔记）**：

| 层 | 怎么量的 | 实测结果 |
| --- | --- | --- |
| ① 库 | 只读 PDO 探针 `SELECT ... FROM nft_announcements ORDER BY id DESC` | **987674「啊实打实」`greeting='SaaS'`、`signature='撒啊'`**、`status=published`、`publish_time=NULL`、`created_at=2026-10-15 00:11:38`。两个值正是 987672 那句 → **顺带证明预填在真人路径上生效了**（你 00:11 那条没手打，是弹窗带上来的） |
| ② 接口 | `curl http://127.0.0.1:8080/api/announcements/987674` | `greeting:"SaaS"`、`signature:"撒啊"`、`signatureDate:"2026年10月15日"`、`coverImage:"/uploads/notice-default/announcement-compose.webp"` 三个字段**全都在** |
| ③ 当前代码渲染的 DOM | 内嵌浏览器打开 `127.0.0.1:5173/#/notice/987674`，读 `.notice-detail__greeting` / `__foot` | `greeting="SaaS"`、`foot="撒啊2026年10月15日"`、正文顺序 敬语 → 正文 → 落款 → 风险提示 —— **本地开发服务器上的这一页是对的** |
| ④ 生产（排除「你看的不会是线上吧」） | 公网只读 `GET /api/announcements` | 生产只有 **2 条**（id=1、id=2），**没有 987674**，而且列表里连 `subtypeLabel` 都没有 = 生产还是第 8 次那版后端。**所以这张图不是生产** |

**那截图是哪来的？—— 量到了唯一对得上的形状：一个比代码旧的「包」**：
本地 `sinan-art-source/dist/`（`index.html` 时间戳 **10-14 14:41**，就是第 9 批那次构建的整包）里：

- `notice-detail__cover`（§7.36 的头图）**命中 2 次** —— 旧包里**有**头图；
- `notice-detail__greeting`（§7.37 的敬语）**命中 0 次** —— 旧包里**没有**敬语落款。

**「有头图、没敬语」正好就是你那张图的样子**。而 `NoticeDetail.vue` / `stores/notice.js` 的源码改是 **10-14 21:01** 落盘、21:09 提交的，
所以任何在 21:01 之前加载、之后没再刷新过的页面（开发服务器标签页靠客户端路由跳，不重新拉 JS；或者看的本来就是 14:41 那份 `dist` 整包）
都会呈现成截图那样。代码、接口、库三层都没问题 —— **是「你看到的那一版包」旧了**。

**这轮顺手做掉的一件事（因为它是个真陷阱）**：重跑 `npm run build` 把两个整包过到新代码 ——
H5 `dist/assets/index-8xzA_hEW.js`（md5 `bfce17326cb063d19bf6eda97be709c4`，154 个文件 / 5.1MB），
后台 `dist/assets/index-qMvhze0Z.js`（md5 `f3b0a3457c874a37729ce3a09a4df70f`，153 个文件 / 8.1MB），
新 H5 包里 `notice-detail__greeting` 从 0 命中变 **2 命中**、新后台包里 `lastSalutation` **3 命中**。
**为什么必须重过**：第 8 节 21 条发版清单里「传两个前端 dist」用的就是这个目录，
**要是照 14:41 那份传上去，线上就只有 §7.36、没有 §7.37**（敬语落款整批静默丢失，而且测试全绿、看不出来）。

**给你的两句话结论（大白话）**：
① 代码是对的、库里也是对的，你那张图是**旧页面**：在 `127.0.0.1:5173` 上按 **Ctrl+F5 硬刷新**再看这条公告，就会看到开头「SaaS」和右下「撒啊 2026年10月15日」；
② 如果你看的是打包出来的整包（`dist`），那份已经重过到新代码了，重新打开即可。

**本轮没验的**：你那一页具体是哪个来源（旧标签页 / 旧整包）我没法替你查——只能证明「两种旧包形状都和截图一致，而当前代码渲染是对的」。
硬刷新后如果**还是**没有，那才是新问题，我按 ①→④ 那四层再走一遍。像素级观感照旧没验（视口 `0×0`）。

##### 第三轮追问（同一晚 00:3x，你说「这个界面还是看不到」）：又往下挖了两层

既然你说硬刷新后仍然看不到，我把上轮**没量**的两件事补上了：

| 补量 | 怎么量的 | 实测 |
| --- | --- | --- |
| ⑤ **「在 DOM 里」≠「看得见」** | 对 `.notice-detail__greeting` / `__foot` 读 `getComputedStyle` + 父链 6 层 | 敬语 `display:block`、`visibility:visible`、`opacity:1`、`color:rgb(51,51,51)`；落款 `display:flex`、`visible`、`1`、`rgb(102,102,102)`；父链上**没有**任何 `display:none` / `opacity:0`（唯一那个 `opacity:0` 是页面根节点的路由过渡，因为内嵌浏览器标签页是 hidden 状态、过渡没跑完，**是量具的问题不是页面的问题**）。字号量成 `0px` 同源：视口宽 0，`px→rem` 折算成 0 —— 这两项就是我常说的「内嵌浏览器量不到像素」 |
| ⑥ **你看的会不会是列表页** | 真人重新打开 `#/notice` 读整屏文字 | 列表页每张卡片只有 **标题 / 摘要 / 类型标签 / 日期** 四项，`notice-detail__greeting` 命中 **0** —— **列表页按设计就没有敬语落款**（它们是正文的一部分） |
| 排除项 | 全仓 `grep serviceWorker/workbox/VitePWA` → 无；phpstudy 的 nginx 站点根 `S:/www` 里没有任何 sinan 文件；`E:\sinan` 下**没有小程序端**（只有 backend / admin / art-source）；5173 进程命令行是 `vite.js --host`（开发服务器，每次从磁盘读源码） | 「旧包被缓存住」的四条常见路径全部排除 |
| 数据有没有变 | 只读探针 + 后台审计 | 00:11:38 之后**没有新公告**（未删仍 5 行，审计最后一条是 `announcement_create` id=804）→ 你看的还是 987674 那条 |

**所以剩下的两种可能只有你那边能判定**：① 那一页仍停在 21:01 之前的 JS（标签页没真的重新拉过代码）；② 你截的是**公告列表**那一屏。
**一秒自检法**（比来回猜快）：F12 → Console 贴
`document.querySelectorAll('.notice-detail__greeting').length + ' | ' + location.href` ——
打 `1 |` 就是新代码（那说明敬语在页面上，只是你那条的值是「SaaS」这种测试字，容易一眼扫过去没注意）；
打 `0 |` 就是旧代码或列表页。

**没做的一件事，以及为什么**：本来想直接用 Computer Use 看你屏幕上那个 Edge 窗口（标题「司南数字藏品…」），
被安全策略 `browser_url_policy / url_verification_required` 停下 —— 按老规矩**不绕**，所以「你那一页到底是哪个界面」这题仍是你答一次就结掉，不是我量出来的。

##### 第三轮结案（同一晚 00:4x，你答了一句就清楚了）

**你那屏是「公告列表（多张卡片）」** —— 所以这不是缺陷，是**设计如此**：敬语与落款是**正文的一部分**，只在**点进卡片之后的详情页**出现；
列表卡片按 §7.36 那版排版本来就只有 标题 / 摘要 / 类型标签 / 日期 四项（上面 ⑥ 那行量的就是这个现值）。

**同时记一条范围决定**（你选的，别下次自作主张改回去）：**「落款署名」不做到列表卡片上**，保持「只有详情页有」。
以后如果又出现「列表里看不到署名」的反馈，先按这条结案，不要再加需求。

**顺带提醒（同一个坑的另一种形态）**：本地这几条测试公告的敬语署名是**键盘乱码**（987674 与 987672 是「SaaS / 撒啊」、987671「去嗡嗡嗡 / 驱蚊器」、
987670「尊敬的用户 / 司南团队敬呈」、987669 两列 NULL），详情页上显示的就是这些字 —— 视觉上「有一行小字」很容易被当成噪声而报「看不到」。
**要改成正式句子不用发版也不用我动库**：后台「内容 → 公告管理 → 编辑」那两格，**清空保存 = 这条回落到全局默认**
（「尊敬的司南用户：」+「司南运营团队」），或直接敲正式文案。我没替你改这 5 行 —— 那是你自己造的数据，按规矩（改数据先给清单点头）等你开口。

### 7.38 「司南风险提示」这五个字改成居中（2026-10-15 00:5x，本地做完并测过，**未上线**）

**你的原话**：「司南风险提示这几个字居中显示。」

**改了一行 CSS，没有第二处**：`sinan-art-source/src/views/NoticeDetail.vue` 里 `&__warning` 内的 `:deep(.warn-title)` 加 `text-align: center;`。
为什么只有这一处：那句标题**不是前端写的字**，是后端 `AnnouncementDisclaimer` 生成的 `<p class="warn-title">…</p>`（§7.32 的口径），
C 端只有一个渲染点，而那个 `__warning` 样式块**同时服务两种盒子**（正文卡片下面的独立白卡 + 富文本块里的卡内灰块），
所以一处改动两种正文模式都跟着居中，不需要再补第二处。

**范围就停在「这几个字」**：正文段落、开头敬语、落款、风险提示的正文都**没动**（新加的防漂移用例专门钉住「正文段落不许跟着居中」，
把居中错加到 `:deep(p)` 上会红）。

**实测（浏览器里读 `getComputedStyle`，不是只看源码）**：

| 元素 | `text-align` 实测 |
| --- | --- |
| `.warn-title`（司南风险提示） | **`center`** ✅ |
| 风险提示正文第一段 | `start`（左对齐，没被带跑） |
| 敬语 `.notice-detail__greeting` | `start`（没动） |
| 正文段落 `.notice-detail__p` | `justify`（没动） |

**测试**：`tests/announcement-disclaimer.spec.js` 加 1 条（源码扫样式块，形状对齐 §7.37 那条 `__cover` 的钉法）。
**反证 2 行**（口径照 §7.37 v2：跑整套、从 JSON 报告取失败用例名、必须命中那条才算红）：

| 档 | 拆什么 | 结果 |
| --- | --- | --- |
| R1 | 删掉 `text-align: center;` 那一行 | 150 条 / 红 1 = 「这几个字居中，正文段落不跟着居中」→ **RED-AS-PREDICTED**，还原 md5 `33631a71…` **SAME** |
| R2 | 把居中错加到 `:deep(p)`（整段都居中） | 同上那条红 → **RED-AS-PREDICTED**，还原 **SAME** |

**基线**：C 端 **150**（+1）、后台 **167**、后端 **269 / 1759**（都不受这次改动影响，跑了一遍确认没连带）。

**整包又重过一次**（§7.37 第三轮那条教训的直接应用）：H5 入口换成 `index-BY_I9mXE.js`（md5 `a4d00011dec2cafbd2c1b3e53418ef61`），
新包 CSS 里量到 `warn-title{…;text-align:center}`。
**注意**：入口文件名与 md5 **每次 `npm run build` 都会变**，所以第 8 节 21 条那两个指纹只是 00:5x 的快照，
**发版前必须当场重量**（`grep -c notice-detail__greeting dist/assets/*.js` ≥1、`grep -o 'warn-title{[^}]*}' dist/assets/NoticeDetail-*.css` 里有 `text-align:center`），别照抄这里的数。

**发版归属**：`NoticeDetail.vue` 本来就在第 9 + 第 10 批那 7 个共用文件清单里，**这次不新增文件、不新增迁移、不动库**，
配置行数仍是 41 → **47**；跟着同一批发即可（第 8 节 21 条）。

**没验的**：居中之后好不好看（像素观感仍要你或真机看一眼）。`text-align` 这个**生效**是量到的，不是猜的。

### 7.39 公告正文和风险提示共用一个边框（2026-10-15 01:0x，本地做完并测过，**未上线**）

**你的原话**：「公告正文和风险提示共用一个边框」。

**这是一次口径推翻**，不是补 bug：§7.32 首次上线时把提示做成「正文卡片下面另一张白卡」，§7.36 照参考图重排时又确认了一遍
（当时写的理由是「声明和正文分开才像平台在说话」）。现在按你的要求合并 —— 提示块搬进 `<article class="notice-detail__main">`
的最底下，**和正文共用同一张卡的边框**，中间只留一条虚线。

**改了三处，都在 C 端这一个文件 + 两个测试文件里**：

| 位置 | 改了什么 |
| --- | --- |
| `NoticeDetail.vue` 模板 | 那块 `<div v-if="notice.disclaimer" … v-html>` 从 `</article>` **之后**挪到 **之前**（仍在富文本/纯文本两个分支之外，所以两种正文都有一份，且只有一份） |
| `NoticeDetail.vue` 样式 | 原来那条独立白卡的容器规则 `> .notice-detail__warning { margin: 12px $page-padding 0; padding: 14px 16px; background: $color-card; border-radius: $radius-lg; }` 换成卡内分隔 `&__main > .notice-detail__warning { margin-top: 18px; padding-top: 14px; border-top: 1px dashed $color-border; }` |
| 两个测试 | H5 `announcement-disclaimer.spec.js` 的位置断言方向翻转；**后端跨仓防漂移** `AnnouncementDisclaimerTest.php::test_C端会把风险提示渲染出来` 同样翻转（见下） |

**为什么虚线是 `dashed`**：直接复用同卡片里落款 `&__foot` 已有的分隔习惯（`border-top: 1px dashed $color-border`），
不新造第三种视觉层级。灰底小字与居中标题那套文字样式（§7.38）**一个字没动**。

**故意没改的**：`&__body &__warning`（富文本正文块里夹 `block.type === 'warning'` 那条历史形状，现网接口不产出，留着兜旧数据）
仍是卡内灰底小块 —— 它不在这次的要求里。

**跨仓那钉必须一起翻，否则「测试全绿但口径是旧的”**：后端 `AnnouncementDisclaimerTest.php` 里那条不是普通单测，
它是**读 C 端 `.vue` 源文件**的防漂移钉（当年 §7.36 就是靠它拦住「卡片里又放了一份」）。
上一版钉的是 `$bodyClose < $warn`（提示排在卡片**之外**），这次改成 `$bodyOpen < $warn < $bodyClose`（嵌在卡片**之内**），
并补一条 `assertSame(1, substr_count(...))` —— 挪进来之后「只许一份」这个约束比原来更要紧，因为卡片内已经有一份了，
再在正文分支里补一份就是页面上出现两遍。**改代码不改这一条，后端套件当场就红**（本轮第一次跑就是它红的）。

**测试基线**：后端 **269 / 1760**（断言 1759 → 1760，就是上面新加的那条）、后台 **167**、C 端 **151**（+1 条新的白卡防漂移）。

**反证 4 行**（口径照 §7.37 v2：跑**整套**、从报告取失败用例名、必须命中预期那条，还原后 md5 逐字节比）：

| 档 | 拆什么 | 结果 |
| --- | --- | --- |
| S1 | 把提示块搬回 `</article>` 之外（推翻这次的决定） | C 端 150/151、红 1 = 「富文本正文：提示嵌在正文卡片最底部…」→ **RED-AS-PREDICTED**，还原 md5 `ca489e37…` **SAME** |
| S2 | 给卡内那条重新画上白卡（`background` + `border-radius`） | C 端 150/151、红 1 = 「卡内那条提示不再自己画白卡」→ **RED-AS-PREDICTED**，还原 **SAME** |
| S3 | 后端那条跨仓位置断言掰回旧方向 | 后端 269、失败 1、红 = `test_C端会把风险提示渲染出来` → **RED-AS-PREDICTED**，还原 md5 `da9756a6…` **SAME** |
| S4 | 在正文卡片里**再塞一份**提示块（模拟真写错成两份） | 后端同上那条红（`assertSame(1, 2)` 抓住）→ **RED-AS-PREDICTED**，还原 **SAME** |

**两个当场浮出的工具坑（记下来省下一轮）**：
① `execFileSync('npx', …)` 在 Windows 上直接 ENOENT（`npx` 是 `npx.cmd`），必须走 shell；
② PHPUnit 的 JUnit 报告里通过用例是**自闭合** `<testcase …/>`，而 `<testcase>` 标签同时带 `classname="tests.unit.X"` ——
提取红灯时正则若不把 `name` 钉死在 `<testcase ` 后面、又不禁止跨 `<testcase`，就会把红灯记成**类名或前一个通过的用例名**。
第一版就是这么报出一条不相干的用例，差点当成「预期用例没红」而误判这次改动没被钉住。

**实测（浏览器里读 `getComputedStyle`，本地 H5 `127.0.0.1:5173/#/notice/987674`）**：

| 量的东西 | 实测 |
| --- | --- |
| 提示块的父元素 | `notice-detail__main`（在正文卡片里面了） |
| 是不是卡片内最后一个元素 | `true` |
| `border-top` | `dashed 1px rgb(238,238,238)`（= `$color-border`） |
| `background` / `border-radius` | `rgba(0,0,0,0)` / `0px`（**自己不再画卡**） |
| `.warn-title` 的 `text-align` | `center`（§7.38 那行没被带坏） |
| 对照：落款 `&__foot` 的 `border-top` | 同为 `dashed 1px`（同款分隔，符合预期） |

⚠️ 视口仍是 `0×0`，所以 `margin-top` / `padding-top` 这类被编译成 **vw** 的长度全部读成 `0px` —— 落款那条老规则也一样读成 0，
**这是量不到，不是 bug**（§7.36 起就记着这条限制）。边框线与颜色是 px/色值，不受影响，量到了。

**整包又重过一次**（同 §7.37/§7.38 那条教训的应用）：H5 入口换成 `index-BZqfQTQ.js`（md5 `8c93f8bbc6a90ea00877b010035fc49a`）。
编译后 CSS（`dist/assets/NoticeDetail-0-4Aeock.css`）里量到 `.notice-detail__main>.notice-detail__warning[…]{margin-top:4.8vw;padding-top:3.73333vw;border-top:1px dashed var(--color-border)}`，
且旧形状 `notice-detail__warning{margin:12px…` **grep 无命中**。发版前仍要**当场重量**（文件名与 md5 每次 build 都会变）。

**发版归属**：跟 §7.38 一样 —— `NoticeDetail.vue` 本来就在第 9 + 第 10 批那 7 个共用文件里，
**这次不新增文件、不新增迁移、不动库、不动接口**，`disclaimer` 字段一个字没改（纯前端版式）。
跟着同一批发即可（第 8 节 20 + 21 条）；上线后要多看第 8 节 21 条复验清单里的 ⑦。

**没验的**：① 观感（虚线分隔好不好看、卡内提示会不会被读成「公告自己的免责声明」而不是「平台在说话」）；
② 生产（这批仍**一行没上**）。

### 7.40 寄售与运营两张出厂首图重画（2026-10-15 02:1x，本地做完并测过，**未上线**）

**你的原话**：「寄售公告和运营公告的首图重新生成」。方向按你在选项里点的那句：**「换构图：主体更小更靠右，左半边留白更干净」**。
主题色**没动**（寄售橙 `#E89121`、运营靛蓝 `#3D5A99`，仍与类型标签同色 —— 标签色和头图同源这件事是 §7.36 定的，这次不碰）。

> ⚠️ **本节分两轮，第一轮那版已作废**：第一轮按上面那句选项做出来的是「扁平小图标 + 纯渐变」，
> 你 02:2x 追加一句「**风格要和合成公告还有活动公告一样**」把它推翻了 —— 那两张的底图是**写实 3D 中国古物**，
> 不是极简矢量。现值以本节末尾「第二轮」那张 md5 表为准。

**只换了 2 个文件的内容，文件名一个都没改**（三轮字节对照，`sinan-nft-backend/public/uploads/notice-default/`）：

| 文件 | 10-14 原图（§7.36） | 第一轮（**已作废**，风格断层） | 第二轮（**现值**） |
| --- | --- | --- | --- |
| `announcement-consignment.webp` | 27,658B `5d6863aa…` | 18,458B `78048a7b…` | **38,774B `b944b8dc593c9a48f50dde5770098cb0`** |
| `announcement-operation.webp` | 32,836B `aae08ec1…` | 17,674B `1d4d9ec8…` | **46,832B `4dd103114fca2caa1505d8856e91493a`** |

`announcement-activity.webp`（41,550B）与 `announcement-compose.webp`（31,278B）**没动**。四张仍是 `1280×495`。
**一个顺带量出来的信号**：第一轮那两张只有 18KB，而活动/合成是 41KB / 31KB —— 体积差一倍本身就是「风格不是一路」的旁证
（写实古物有材质纹理与金线，编码后必然更大；纯渐变几乎压不出字节）。下次判断「这张是不是同一套风格」，可以先看体积档位。

**不动代码、不动库、不动接口**：`AnnouncementCover` 里那四个 `cover_key` 参数值、`FACTORY_DIR` 常量、
`coverFor()` 的解析口径全是**按路径**走的，文件名不变 → 后端与两个前端**一个源文件都不用改**。
运营在后台「全局参数 → 公告头图」自己换过的图存在参数里，也不受这次出厂图更新影响（这正是 §7.36「读取时解析、不回填」的设计）。
`scripts/make_notice_banners.php` 这次也**没改**。

**第一轮的两段 prompt（已作废，别照抄，风格断层就断在这两段）**：

- 寄售（采用版）：`Ultra-wide horizontal banner background illustration, 21:9, ... Warm amber orange palette
  (#E89121 to #A85B00 smooth gradient) ... The LEFT two-thirds is almost empty, pure smooth gradient ...
  The RIGHT one-third holds ONE single small motif, occupying no more than 22% of the canvas width ...
  a minimal modern consignment price-tag card ... Nothing else on the right side: no display case, no shopfront,
  no window frame, no chart grid, no rings, no orbits. ... Absolutely NO text, NO letters, NO Chinese characters ...`
- 运营（采用版 v4）：`Ultra-wide 21:9 panoramic cinematic background, deep indigo blue monochrome (around #3D5A99),
  one completely smooth continuous gradient across the ENTIRE frame ... CRITICAL: the background must be a SINGLE
  seamless soft studio backdrop — absolutely NO rectangles, NO panels, NO blocks, NO differently-toned regions,
  NO vertical seams ... Test: no two neighbouring vertical slices of the background may differ in brightness.
  On the RIGHT side only, one small elegant three-dimensional faceted crystal prism, occupying no more than 20% ...`

**出图流水线没变**：`php scripts/make_notice_banners.php <素材目录> <输出目录>` → 裁掉底部 90px 水印带 → 左 62% 压暗 32%→0 →
叠 `SINAN · DIGITAL ART`(arial 40) / 英文标签(arial 46) / 中文类型名(simhei 190) → 缩到宽 1280 → `imagewebp` 质量 82。

**两个坑（都只在脚本的行为里，代码没写守卫，所以只能记在台账）**：

① `glob($artDir.'/'.$spec['art'].'*.png')[0]` 取的是**排序后第一个 = 文件名最旧那张**。素材目录里只要留着上一版的 PNG，
脚本会**静悄悄用旧图**，产出的 webp 与仓库现值只差几 KB，肉眼看输出根本发现不了 —— 本轮第一次就被这样骗过（把整包
`vibe_images` 直接当素材目录，四张全出了 10-14 那批的旧底图）。**做法**：每次新建一个**只放本次素材**的空目录（本轮 `qa_art_in4`），
跑完看它打印的「跳过 xxx」条数对不对（只放两张就会打「只出了 2 张」，那是正常提示不是报错）。

② 底图比例不是 21:9 时脚本**不报错**，只是产出高度跟着比例走：1792×1024 的素材会出 **1280×667**。
这条**有测试兜着**（见下面反证那行），但脚本自己不会提醒你。

**「AI 把画面切成两块板」这种分块痕迹怎么客观判**：量**每一列亮度中位数、相邻列的最大跳变 Δ**（一次性脚本 `qa_seam_probe.php` / `qa_probe2.php`，纵向抽样约 150 行/列，用完已删）。

| 底图（运营） | 最大跳变 | 跳变位置 | 判定 |
| --- | --- | --- | --- |
| 仓库旧版（§7.36 那张） | Δ9.9 | x=2016（79%，主体边缘） | **基准**：跳变只出现在主体上，背景是连续的 |
| v1 | Δ19.9 | x=1477（58%，**背景正中**） | 有硬竖边，弃 |
| v2 | Δ7.4 | x=1486（58%） | 软化了，但渲染出来仍看得出断层，弃 |
| **v4（采用）** | Δ5.8 | x=2559（100%，画幅边缘） | 背景无断层，主体在 76% 处 |
| 寄售新图（采用） | Δ3.2 | x=1917（75%，主体边缘） | 干净 |

⚠️ **这个探针必须在底图 PNG 上量，不能在成品 webp 上量**：成品左半边有「运营公告」四个 190px 大字，
字形边缘本身就是 Δ60 级的跳变，会把背景断层完全淹掉 —— 本轮第一次就在成品上量，v1 报 65.0、v2 报 18.0，
两处都落在 x≈740（≈58%），看着像「改好了」，其实量的全是字形而不是背景。**这是一次自我更正**：
原来的结论（「v2 还有拼缝」）碰巧是对的，但当时那个数字是假信号，换一张图就会误判。

**测试基线**：后端 **269 / 1760**、后台 **167**、C 端 **151** —— 与 §7.39 完全相同，**这次没加新测试**
（尺寸/格式/体积>4000B/四张同尺寸那几条，本来就被 `AnnouncementCoverTest::test_四张出厂图真的在仓库里且尺寸一致` 钉着）。

**反证 1 行**（口径照 §7.37 v2：跑**整套**、从报告里取失败用例名、必须命中预期那条、还原后逐字节比 md5）：
把坑 ② 产出的那张 **1280×667** 塞进 `announcement-operation.webp` → 整套 unit `tests=269 assertions=1759 failures=1`，
红灯用例名**正是** `test_四张出厂图真的在仓库里且尺寸一致`；还原后 md5 `1d4d9ec805116a24f350d6c954f442f4` = **SAME**。
（断言数 1760 → 1759 是「红得对」而不是「没跑」的证据：那条测试在尺寸断言处就停了，后面几条断言没执行。）

**本地端到端实测**（`127.0.0.1:5173`，挑的是 `cover_image` 为空、因此一定走类型默认图的两条公告）：

| 公告 | subtype | 页面实际加载的 `img.src` | `naturalWidth×naturalHeight` | 标签底色 |
| --- | --- | --- | --- | --- |
| 987671 | consignment | `/uploads/notice-default/announcement-consignment.webp` | 1280×495 | `rgb(232,145,33)` = `#E89121` |
| 987672 | operation | `/uploads/notice-default/announcement-operation.webp` | 1280×495 | `rgb(61,90,153)` = `#3D5A99` |

再加一层字节级：`curl` 5173 与 8080 上这两个 URL，拿到的与仓库工作区文件 **md5 逐张 SAME**。
（`getComputedStyle` 读到的 width/height 是 `0px`，还是 §7.36 起那条「视口 0×0 + px 编译成 vw」的限制，不是图坏了；
`naturalWidth` 那对才是图自己的真值。）

---

**第二轮（同日 02:2x，你的追加口径：「风格要和合成公告还有活动公告一样」）**

第一版做完给你看，你否掉了方向。这一句推翻的是**本小节第一轮**（也就是上一条那个「主体更小更靠右、左半边留白更干净」的选项），
不是 §7.36 —— 恰恰相反，它是要**回到 §7.36 那两张的路线**上去。

**「一样」我拆成了五条可核对的要素**（拿 `activity` / `compose` 两张反推出来的，下次再改风格照这五条量）：

| # | 要素 | activity / compose 现值 | 第一轮那两张（不合格） |
| --- | --- | --- | --- |
| 1 | 主体是什么 | **写实 3D 中国古物**（青铜司南勺+罗盘盘 / 玉璧+青铜鼎+碎玉片） | 扁平矢量小图标（价签、水晶棱镜） |
| 2 | 主体多大 | 占右半边约 **40–45%** 宽，纵向居中偏下、贴近右缘 | ≤20% 宽，孤零零悬在中间 |
| 3 | 背景装饰 | 淡金**发丝弧 + 星点 + 极淡网格/同心环**，主体间有**金色连线与小节点**（合成那张最明显，是「碎片连线成图」） | 纯渐变，什么都没有 |
| 4 | 质感 | 青铜锈、玉的温润、金属高光、四角暗角 | 平涂色块 |
| 5 | 体积档位 | 41,550B / 31,278B | 18,458B / 17,674B（**小一半，本身就是不合格的信号**） |

**第二轮的两段 prompt（这才是采用版；`ImageGen` 尺寸仍必须 2560×1080）**：

- 寄售（`#E89121 → #A85B00`）：`Ultra-wide horizontal banner background illustration, 21:9, for a Chinese digital-art
  collectibles platform. Warm amber orange palette (#E89121 to #A85B00 gradient), deep vignette at the corners, soft glow.
  On the RIGHT third: an ancient Chinese consignment motif rendered as a photorealistic 3D artifact under soft studio
  lighting — a polished piece of pale mutton-jade carved pendant resting on a small carved rosewood display stand, with a
  traditional ivory consignment tally tag (rounded rectangle tablet with a punched hole) hanging beside it on a thin
  braided red silk cord, and a small bronze hand scale with a dangling weight just behind. The pieces are linked by thin
  gold construction lines and tiny glowing geometric nodes, like an antique appraisal diagram. Left two-thirds: mostly
  clean gradient space for text, with a few faint gold hairline arcs, tiny star dots and a subtle grid. Premium,
  museum-quality, rich material texture (jade translucency, wood grain, bronze patina), cohesive warm palette.
  Absolutely NO text, NO letters, NO Chinese characters, NO numbers, NO logo, NO watermark, NO people, NO mascots, no cat.
  The background must be one continuous smooth gradient with no panels, no rectangles, no vertical seams.`
- 运营（`#3D5A99 → #1E2C52`）：同一副骨架，把配色换成 indigo，图案换成
  `a square bronze official seal with a carved beast knob on top, resting tilted against a half-unrolled bamboo slip
  scroll with blank slips, above a faint circular compass-rose plate engraved with concentric rings` +
  `thin pale-gold construction lines and tiny glowing geometric nodes, with a subtle dotted orbit arc sweeping behind`。
  **两句都保留了「blank slips / NO Chinese characters」**，否则模型一定会往竹简上写字。

**拼缝探针在第二轮怎么读（重要更正第一轮的读法）**：**Δ 的绝对值不是判据，跳变落在哪里才是**。
把四张参照底图一起量出来才看清：`compose` 底图自己就有 **Δ15.9 @81%**、`activity` 只有 Δ3.9 —— 因为写实古物主体边缘本来就是硬边。
所以第二轮两张（寄售 Δ20 @78%、运营 Δ17.2 @94%）**全部落在右侧主体区**，背景中段没有尖峰 → 判合格。
第一轮定的「Δ 要小于 9.9」那条阈值是**错的**，那是极简风格的图才有的量级，换成写实风格会一律误报。

**水印**：运营那张底图右下角有 `Qoder AI 生成` 字样。脚本的 `$CROP=90` 正好把底部 90px 裁掉，成品里已无残留
（**真人看过成品图确认**，探针量的是整带平均亮度差 −3.1，不足以证明小字被裁净 —— 这种小面积东西别信平均值）。

**测试基线（第二轮重跑）**：后端 **269 / 1760**、后台 **167**、C 端 **151** —— 与第一轮、与 §7.39 都相同。
**风格这件事没有任何测试钉住**（当场在三个仓库的 tests 里 grep `出厂图 / notice-default / 构图 / 留白 / webp`，
只有 `AnnouncementCoverTest` 命中，钉的全是**路径、尺寸一致、webp 类型、体积 >4000B**，没有一条与画面内容有关）
—— 所以这次推翻口径**没有防漂移测试要翻方向**，但也意味着**风格漂移只能靠人眼拦**。

**反证（第二轮对新图重跑一次，不沿用第一轮那次）**：把当前 `announcement-operation.webp` 换成一张 **1280×667** 的错尺寸产物
→ 整套 unit `tests=269 assertions=1759 failures=1`，红灯用例名 `test_四张出厂图真的在仓库里且尺寸一致`；还原后 md5 = **SAME**。

**本地端到端实测（第二轮，同一批数据）**：

| 公告 | subtype | 页面实际加载的 `img.src` | `naturalWidth×Height` | 标签底色 |
| --- | --- | --- | --- | --- |
| 987671 | consignment | `/uploads/notice-default/announcement-consignment.webp` | 1280×495 | `rgb(232,145,33)` = `#E89121` |
| 987672 | operation | `/uploads/notice-default/announcement-operation.webp` | 1280×495 | `rgb(61,90,153)` = `#3D5A99` |

`curl http://localhost:5173/uploads/notice-default/announcement-{consignment,operation}.webp` 与仓库工作区文件
**md5 逐张 SAME**（38,774B / 46,832B）。

**发版归属**：并进**第 9 + 第 10 那一批**（第 8 节 20 + 21 条）。清单**条目数不变** —— 仍是「一个新目录 4 个文件」，
只是其中两张的**内容**换了。两处要跟着改口径：① 20 条里那句「共 133KB」已过期，现在四张合计 **158,434B（约 155KB）**；
② 双端 md5 的基准按下面这张**当场量的**表（第二轮 02:2x 值），别照抄本节任何旧数字 —— 第一轮那张 18,458B / 17,674B 的表已作废。

| 四张出厂图工作区现值（2026-10-15 02:2x，第二轮） | 尺寸 | 字节 | md5 |
| --- | --- | --- | --- |
| `announcement-activity.webp` | 1280×495 | 41,550 | `c6a2626313ce12e1202baf138c5b32c3` |
| `announcement-compose.webp` | 1280×495 | 31,278 | `a914b63fc0e7d2e479cc02067e88fad4` |
| `announcement-consignment.webp` | 1280×495 | 38,774 | `b944b8dc593c9a48f50dde5770098cb0` |
| `announcement-operation.webp` | 1280×495 | 46,832 | `4dd103114fca2caa1505d8856e91493a` |

**没验的**：① 观感最终确认 —— 第二轮这两张已经和 `activity` / `compose` 同风格，但**「像不像同一套」这件事本身没有测试能钉**
（见上面第二轮那段 grep 结论），只能你或真机看一眼；② 生产（**一行没上**，这两张新图目前只存在于工作区与本地）。

### 7.41 公告列表页的分类标签跟着接口上色（2026-10-15 22:5x，本地做完并测过，**未上线**）

你的原话：**「现在公告列表的公告分类全是红的，改成和详情里面的分类一样的颜色」**。

**根因（一句话）：接口本来就逐行下发了 `tagColor`，是 C 端列表页没接。** 详情页从 §7.36 起就在读这个字段，
列表页那行却是 `<AppTag type="primary">` —— `primary` 在 `AppTag.vue` 里就是平台红 `$color-primary`（#C00000），
所以四类公告在列表上清一色红，而点进详情才变成各自的类型色。**两边颜色不一样不是算出来的分歧，是一边根本没去算。**

**先量的接口，再看代码**（顺序很重要，否则会把「前端没接」误判成「后端没发」）：本地 `GET /api/announcements?page=1&pageSize=50`
22:5x 实测六行，`subtype / subtypeLabel / tagColor` 三个键都齐（`curl -o 文件` 再按 UTF-8 读，见 §7.35 那个管道坑）：

| id | subtype | subtypeLabel | tagColor |
| --- | --- | --- | --- |
| 987675 | operation | 运营公告 | `#3D5A99` |
| 987674 | compose | 合成公告 | `#B08D55` |
| 987672 | operation | 运营公告 | `#3D5A99` |
| 987671 | consignment | 寄售公告 | `#E89121` |
| 987670 | compose | 合成公告 | `#B08D55` |
| 987669 | activity | 活动公告 | `#C00000` |

**改法按「收成单一出口」那条老规矩**（第 8 节里的单口闸门模式，AGENTS 第三节）：不往 `Notice.vue` 里再抄一遍
`NoticeDetail.vue` 的兜底正则，而是把它抽成 `src/utils/tagColor.js`，两页共用一个 `safeTagColor()`。
**抄两份的坏处本轮已经踩过一次**：同一张标签在两页一个退回主红、一个把 `'red'` 塞进 `style`，就是 §7.36 那批想消掉的分裂。

**五处改动**（全在 C 端，后端 / 迁移 / 数据库**一个字节没动**）：

| 文件 | 改了什么 |
| --- | --- |
| `src/utils/tagColor.js` | **新增**。`FALLBACK_TAG_COLOR = '#C00000'` + `safeTagColor(raw)`：只认 `^#[0-9a-fA-F]{6}$`，别的一律兜主红 |
| `src/components/AppTag.vue` | 加一个**可选** `color` 属性，非空才写 `:style="{backgroundColor}"`；三个老调用方不传就是 `null`，行为一字不变 |
| `src/stores/notice.js` | `fetchNotices()` 的映射补 `tagColor: a.tagColor \|\| ''`（早先只有详情映射带这个键） |
| `src/views/Notice.vue` | `<AppTag type="primary">` → `<AppTag :color="safeTagColor(item.tagColor)">` |
| `src/views/NoticeDetail.vue` | 删掉本地的 `FALLBACK_COLOR` 与那条内联正则，改 `import { safeTagColor }`；渲染 markup 没动（仍 `:style` 同时给背景和边框色） |

**只改颜色**：你没要求对齐两页标签的尺寸/圆角，列表仍走 `AppTag` 原来的 `small`（12px / 3px 6px），
详情仍是自己那个 `.notice-detail__type`（11px、带边框）。所以两页**同一个颜色、两种字号**，这是刻意的。

**测试**：新增 `tests/announcement-list-tag.spec.js`，**7 例** —— 4 条行为（四类四种底色 / 单条自带非主题色照接口 /
8 个脏值与缺失退回主红且不泄漏 `script`、`url(` / 列表与详情同一条公告底色一致），
3 条防漂移（兜底色与那条正则**只许**出现在 `tagColor.js`，两个页面都不得再各写一份、且必须 `from '@/utils/tagColor'`；
列表那个 `<AppTag>` 必须绑 `:color` 且**不得**再出现 `type="primary"`；store 的 `fetchNotices` 映射必须带 `tagColor`）。
基线：C 端 **151 → 158 例（17 → 18 文件）全绿**；后端 `--testsuite unit` **269 / 1760** 不变、后台 **167 / 14 文件** 不变。

**反证 5 档**（`npx vitest run --reporter=junit --outputFile=…` 整包跑，从 XML 里点名取红的那几条；每档拆完立刻还原并 `md5sum` 核 SAME）：

| 档 | 拆掉什么 | 红的用例（点名） | 还原 |
| --- | --- | --- | --- |
| A | 列表页退回写死 `type="primary"` | 四类四种底色 / 单条自带色 / 脏值兜底 / 列表与详情一致 / 防漂移「不得写死平台红」 **5 条全红** | md5 SAME |
| B | store 映射不再带 `tagColor` | 四类四种底色 / 单条自带色 / 列表与详情一致 / 防漂移「映射要带 tagColor」 **4 条全红** | md5 SAME |
| C | 守卫去掉校验、脏值直接进 `style` | 列表脏值兜底 / 详情脏值兜底（旧 spec 那条也一起红，说明两页真共用一个口）/ 防漂移「只许一个出口」 **3 条全红** | md5 SAME |
| D | `AppTag` 不再认 `color`（口子等于没有） | 列表页那 4 条行为用例全红 | md5 SAME |
| E | 详情页把 `#C00000` 再抄一份回去 | 防漂移「兜底色与正则只写在 tagColor.js」红 | md5 SAME |

⚠️ **B 档第一轮没红，是本机测试自己有个洞，改的是测试不是代码**（这是本轮最值得记住的一条）：
「列表与详情底色一致」那条原先是**先挂详情页、`flushPromises` 之后再回头读列表那个标签的 style**。
而 `stores/notice.js` 的 `fetchNotice()` 末尾有一段 sync-back —— 它会把详情那个对象 `{...旧行, ...detail}` 并回列表对应行。
于是列表标签此刻读到的已是**详情塞回去的颜色**，「列表自己少接一个字段」被这一并给掩盖了，B 档照样全绿。
**修法**：把列表那次的底色在**挂详情之前**就取下来存成字符串。通用一句：**断言「A 页和 B 页一致」时，要防着后加载的 B 页把 A 页的数据补上** ——
这类"共享 store 的回头写"会让跨页一致性测试变成恒等式。
（顺带一条工具坑，与上轮同源：vitest 的 junit XML 里 `<testcase>` 是 **`classname` 在前、`name` 在后**，
照 PHPUnit 的习惯按 `<testcase name="` 起头去匹配，会一条都提不到，表现为「拆了却没红」的假绿 —— 本轮第一次就是这么误判的，
实际 5 条已经红了。提取前先 `grep -c '<failure' 那个文件`确认结构。）

**本地真人浏览器实测**（`http://localhost:5173/#/notice`，硬刷新后 `getComputedStyle`，22:5x）：

| 页 | 量到的东西 | 结果 |
| --- | --- | --- |
| 列表 6 行 | 四张标签底色 | 运营 `rgb(61,90,153)`、合成 `rgb(176,141,85)`、寄售 `rgb(232,145,33)`、活动 `rgb(192,0,0)`，与上面那张接口表逐行对上 |
| 详情 987671「分我去饿的」 | `.notice-detail__type` | `rgb(232,145,33)`，background 与 border 同色 —— **与列表那条寄售完全一致**（这就是你要的效果） |
| `#/calendar` 8 个 `AppTag` | 回归 | 全是 `app-tag--primary`、`style` 属性为 `(none)`、底色仍来自 CSS 类 → 新 `color` 属性没碰到老调用方 |
| `#/mall/1` 3 个 `AppTag` | 回归 | 同上，`primary` 与 `warn` 都无内联样式 |

（长度量不到这件事依旧成立：postcss 把 px 折成 vw，内嵌浏览器视口 `0×0`，所以只报颜色，不报宽高 —— 见第 8 节的老口径。）

**上面那张表量的是 dev server（5173，直接吃源码），不是 dist**，所以传上线前还得按老规矩给整包过指纹（`npm run build` 重过一次，22:5x 当场量）：

| 指纹 | 命令 | 结果 |
| --- | --- | --- |
| §7.41 的新守卫**在包里且只有一份** | `grep -c '0-9a-fA-F]{6}' dist/assets/*.js` | **只**命中入口 `index-CQ9Ot2ZK.js` = 1，其余分块全 0 —— 两页共用同一个模块，编译后确实只落一份字面量 |
| 列表页落点 | `grep -l notice-item__foot dist/assets/*.js` | 入口 `index-CQ9Ot2ZK.js`（列表页没单独分块）；该文件里 `tagColor` 出现 5 次（映射 + 模板绑定） |
| §7.37 敬语 | `grep -c notice-detail__greeting dist/assets/NoticeDetail-*.js` | 1 |
| §7.38 居中 | `grep -o 'warn-title{[^}]*}' dist/assets/NoticeDetail-*.css` | 含 `text-align:center` |
| §7.39 卡内虚线 | `grep -c 'notice-detail__main>.notice-detail__warning' …css` = 1 **且** `grep -c 'notice-detail__warning{margin:12px' *.css` 全 0 | 都满足 |

22:5x 那次 build 的快照：H5 入口 `index-CQ9Ot2ZK.js` / md5 `d3132f704b78ea6509b171e0ee79fc78`，
详情分块 `NoticeDetail-DkAU1LYY.js` 与 `NoticeDetail-C3glCbPM.css`。**这些名字和 md5 每 build 一次就变，只当"是不是旧包"的对照，
重量以发版当场为准**（同 §7.37 第三轮那条铁律：新代码在源码里 ≠ 在你要传的那个包里面）。

**发版归属：还是并进「第 9 + 第 10 那一批」，清单不新增一个字节级动作**，只是 C 端那份 dist 里多含这一处改动。
但有一条**降级行为**要写明白，因为它决定了「只发一半会怎样」：本批读的是列表接口的 `tagColor`，而**这个字段是第 9 批那个
`app/controller/Content.php` 才开始下发的，生产现在还没有**。所以
① 两批一起发（默认口径）→ 列表四类四种颜色，和详情一致；
② **万一只发了 C 端 dist、后端 `Content.php` 没跟上** → `safeTagColor()` 认不到字段一律兜底，列表会变成**清一色平台红**，
   不报错、不白屏、也不会有脏值进 `style` —— 看起来就像"没改"，而不是"改坏了"。这属于可接受的安全降级，
   但发现列表全是红时**先查后端文件在不在**，别回来翻前端。

**仍然没验的**：① 生产（一行没上）；② 两页标签字号不同这件事是否合你意 —— 颜色统一了，列表 12px / 详情 11px，
你没要求对齐像素，我按「只改颜色」的最小口径做的，要一并统一说一声，那是另一处 markup。

### 7.42 「我的」页邀新卡的背景图换成公告首图那套古物风格（2026-10-15 23:5x，本地做完并测过，**未上线**）
> **⚠️ 本节已被 §7.43 推翻**（2026-10-16 01:2x）：你看了 v3 那版说「重新换张图，要有海报感，可以把白色的那个遮罩删掉」。
> 所以**浅底 + 白纱 + `opacity:.85` + 深红标题这套一个不剩**，底图 `invite-banner-bg-v3.webp` 已 `git rm`（成品在 commit `165fbd3` 里可取回）。
> 本节下面那些**量法**仍然有效、也被 §7.43 沿用：卡片 343×76 的版式几何（文字从 x=72 起、文本区 171px）、
> 「不比旧渲染差」这个对比度门槛口径、以及「水印探针要量原图不量成品」的教训 —— §7.43 只是把这条教训又往前推了一步。
> **但本节所有像素数（8.28/3.60/4.19/1.64 那张表）已经描述不了当前代码**，当前状态请以 §7.43 为准。

**你的原话**：「把我的页面邀请好友的那个卡片背景图也改成和公告首图一样的风格」。

**为什么这不是换一张文件就完事**（这一点先说清楚，因为它决定了后面所有数字）：
公告首图是**整块通宽摆在那儿、字都在图外面**，所以图可以按本来面目显示；
而邀新卡是 **343×76 的窄横条，标题和副标题就压在图上**。旧图能撑住这个版式，靠的是
`opacity: .3` 再加一层 `filter: brightness(1.6) saturate(0.7)` —— 大白话就是「把一张本来就浅的图再冲淡、再洗白」。
公告首图那套是深色古物（玉璧、红绳结、银鱼），拿同一套洗白法去压，玉璧会糊成一片灰泥，风格反而更不像。
所以**换图必须连洗白方式一起换**，我把这个冲突摆给你选，你勾的是**「折中：换图但底色仍浅」**（换同风格古物图，文字底下仍是浅色，标题深红与按钮不动）。

**美术：三代才定下来**（口径沿用第 8 节 20 条那套：生成器尺寸写死 21:9，探针量原图不量成品）

| 代 | 结果 | 为什么不用 |
| --- | --- | --- |
| v1 | 构图散、主体跨整幅 | 文字底下全是东西 |
| v2 | 玉璧在 55%~85%、红绳结伸到 40%~50% | 主体正好压在标题+副标底下；把纱推到能护住文字的位置，右边就只剩一块玉璧的边，风格看不出来 |
| **v3** | **生成时就把所有古物钉在右三分之一，左边两格多是空的象牙色绸面** | **采用** |

v3 原图 2560×1080，**右下角带「Qoder AI 生成」水印**，而卡片比例是 4.51:1，只能横切一条 568px 高的带：
切 **y=324..891（原图 30.0%~82.6%）** —— 上沿留住雕纹玉璧与素玉璧，下沿在 90% 的水印之前收住，
缩到 1280×285 后当场复查：成品右下角 6%×10% 区域内暗像素 **0 个**（水印字形是数百的量级）。

**参数是量出来的，不是看着调的**
先算清楚「文字到底铺到多宽」：卡片 343 = 16 边距×2 + 图标 44 + 间距 12 + **文本区 171** + 间距 12 + 按钮 72，
文本从 x=72 起、最远到 x=243，也就是**卡片宽度的 71%**（副标题 13 字×12px=156 装得下，装不下会换行而不会更宽）。
所以白纱的平段必须盖到 71% 之后，否则文字尾巴就坐在图上。

再把「不比现在差」写成硬门槛 —— 先量**旧渲染**（旧 jpg + 那套洗白滤镜）在文字区的最坏对比当基线，
然后扫 α × 白纱(平段→收尽) 的组合，只留两项都不劣的，再在通过者里挑「古物露得最多」的：

| 方案 | 标题最坏对比 | 副标题最坏对比 | 判定 |
| --- | --- | --- | --- |
| 旧渲染（换图前） | 8.28:1 | 3.32:1 | 基线 |
| v2 + 只把 α 调到 .55（你选折中时说的档位） | 4.56:1 | 2.19:1 | 副标劣，弃 |
| v2 + 纱 0.72→0.88, α=.80 | 8.28:1 | 3.60:1 | 过，但右边只剩玉璧边缘 |
| **v3 + 纱 0~72%→88%, α=.85（采用）** | **8.28:1** | **3.60:1** | **过，且右端是玉璧+银鱼+红绳结** |
| v3 不叠任何纱 | **4.19:1** | 1.64:1 | 劣，见下面那条 |

**浮出来的真凶不是古物，是一条金线**：v3 里那条穿过整幅的细金线在 y≈38%，正好从标题字底下过。
即使古物全在右边、文字底下看着是空的，**不叠纱时标题最坏对比也只有 4.19:1**（量到最暗像素 rgb 192,171,119，就在 x=33.9%）。
所以白纱不是"装饰性的淡出"，它挡的是这条线 —— 这也是我把 `#fff 72%` / `88%` 两个 stop 一起钉进测试的原因。

**顺带证伪了我自己先前的一个判据**：早先用"相邻列亮度跳变 <3"当"有没有缝"的门槛，量出来一直报"有缝"。
分开量才知道报的是古物轮廓本身：不叠纱时最大跳变 **18.36**，叠了纱反而降到 **10.21**，
而把图换成中性灰、只留纱的形状，跳变是 **5.22** 且摊在 16% 宽的渐变里（每列约 1.8/255）。
**结论：纱不产生缝，它把原有的边压软了**；那条阈值定错了，别再拿它当判据。

**改了哪几处**

| 文件 | 改动 |
| --- | --- |
| `sinan-art-source/public/images/invite-banner-bg-v3.webp` | 新增，1280×285，32,722 B，md5 `27f8dabe616bc8ecf46649bcd9c3868d` |
| `sinan-art-source/src/views/User.vue` | `.invite-banner::before` 的 `background` 改成「白纱渐变 + v3 图」两层，`opacity: .3 → .85`，**删掉 `filter: brightness(1.6) saturate(0.7)`**；标题/副标/按钮三个色一个没动 |
| `sinan-art-source/tests/invite-banner-art.spec.js` | 新增 9 例 |

中途那张 `invite-banner-bg-v2.webp` 从没提交过，直接删掉换 v3，**没有留下第二个待清理文件**。

**9 例测试**（`tests/invite-banner-art.spec.js`）：先把 `.invite-banner` 整块样式切出来（防止 `.asset-card` 里同名的 `&__title`/`&__icon` 串台），
再钉：只认 v3 且 jpg/v2 都不再被引用、`brightness`/`saturate` 已拆、两个纱 stop 与 `opacity: .85` 没被人改回去、
背景层 `z-index: 0` 而图标/文字/按钮都是 `z-index: 1`、三个文字色没跟着换图一起动、
SFC 里写的地址就是磁盘上那个文件（换完图 404 露白底这种低级事）、体积守在 40KB 内、
**从 webp 文件头解出宽高**并核对比例落在卡片 4.51 的 ±3% 内（比例不对 `cover` 就会把右边古物裁掉）。

**反证 8 项**（逐项拆 → 跑 → 当场还原 → 核 md5）

| # | 拆什么 | 红的是哪一例 |
| --- | --- | --- |
| 1 | url 换回旧 jpg | 「只认 v3」+「SFC 地址=磁盘文件」两例（后者顺带说明这两例是连着的） |
| 2 | 加回 `filter: brightness(1.6) saturate(0.7)` | 「旧洗白滤镜已拆」 |
| 3 | 白纱收早到 66%→80% | 「两个 stop 是量出来的」 |
| 4 | `opacity` 抬到 1 | 同上（正则只认 `.85`） |
| 5 | 文本层丢掉 `z-index: 1` | 「背景压在文字下面」 |
| 6 | 标题 `#9A1A1A` 改成 `#333333` | 「三个文字色没动」 |
| 7 | 改 webp 文件头把宽高写成 1280×340 | 「比例对得上卡片」 |
| 8 | webp 尾部追加 20KB | 「体积守在 40KB 内」 |

8 项**全部只红在对应那一例**，跑完 `User.vue` 与 webp 的 md5 都核回 SAME（`User.vue` 走内容还原、webp 走字节还原）。

**三端测试基线**：H5 **167 例（19 文件）全绿**（原 158/18，多的 9 例就是这一份新 spec）。
后端与后台这轮**一个字节没碰**（`git status` 只有 `User.vue` 改动 + 新资产 + 新 spec），
所以 unit 269/1760、admin 167 两条基线沿用 §7.41 的实测值。

**浏览器实测**（`:5173/#/user`，视口仍是 0×0，长度量不了，颜色与层叠能量）

| 量什么 | 实测 |
| --- | --- |
| `getComputedStyle(el,'::before').backgroundImage` | `linear-gradient(90deg, rgb(255,255,255) 0%, rgb(255,255,255) 72%, rgba(255,255,255,0) 88%, rgba(255,255,255,0) 100%), url("…/invite-banner-bg-v3.webp")` —— 两层都在，且纱在上、图在下 |
| `opacity` / `filter` / `z-index` | `0.85` / `none` / `0` —— 洗白滤镜确实没了 |
| 图能不能加载 | `naturalWidth×naturalHeight = 1280×285`，`curl` 走 dev server `200 / 32722 B` |
| **用 Chrome 自己把这三层画进 canvas 再逐像素量** | 标题最坏 **8.28:1**、副标最坏 **3.60:1**，最坏点背景都是 `255,255,255` —— **与上面 PHP 模型逐位相同**，说明合成数学没算错浏览器 |
| 右端露出区（x≥250px） | 平均彩度 16.5、亮度极差 214 —— 古物是真露着，不是又洗成灰 |

**dist 指纹**（22:5x 之后重跑 `npm run build`，`built in 3.02s`）：
入口 CSS `index-CDqaajkP.css` md5 `410ce87a261eadc80cfcf14a7dd87c1e`（`grep -o invite-banner-bg-…` 只命中 v3 一次，jpg 不再被引用），
入口 JS `index-DN66N4FM.js` md5 `3fac4eea5a02883f7e52a0ffc2cfb42c`，`index.html` md5 `fe556513022deb881c38a6144ca8ab8e`，
`dist/images/invite-banner-bg-v3.webp` md5 与源文件逐字节相同。
**这些名字和 md5 每 build 一次就变，只当"是不是旧包"的对照，重量以发版当场为准。**

**遗留与没验的**
① 生产一行没动；② 旧图 `public/images/invite-banner-bg.jpg`（700×394 / 45,701 B）现在**全仓库零引用**（源码只有 `User.vue` 这一处用过它，dist 里也只剩 CSS 不再指向它），
成了孤儿图 —— 但按第 8 节 15 条的口径，**清理要先用平台自己的扫描器出清单、再分影响档位、你点头才动，且先 `tar` 存档再移回收站**，这轮我不动它；
③ 343px 宽的窄横条上「右端一小簇古物」这个观感合不合你意，要你或真机看一眼 —— 内嵌浏览器视口 0×0，我只验到"像素颜色与层叠"，没验到"好看"；
④ 卡片版式（图标/文字/按钮的位置）一点没动，你要的是背景图，我没顺手改别的。


### 7.43 邀新卡改成「深色满幅海报」：白纱删掉、字改白，五套主题的邀请图标全换成位图（2026-10-16 01:2x，本地做完并测过，**未上线**）

> **⚠️ 本节有两处被 §7.44 推翻**（2026-10-16 02:1x）：① 「按钮 `#C00000` 没动」——现在按钮是透明灰板 + 白字；
> ② classic 那张 `modern-invite.png` 换成了平面信封（本节表里那行 md5 `330f1b5b…` 描述的是**换之前**那张，在 `git show HEAD~1:…` 里仍可取回）。
> ③ **本节"五套主题的邀请图标全换成位图"那半段整体已被 §7.46 换掉**：五张 `<前缀>-invite.png` 已从盘上删除，五套共用一张写实玉佩圆角小卡。
>   仍然有效的是**"图标必须走位图分支、和 calendar 同一条渲染路径"**这条结构口径（§7.46 的五条 `invite:` 依然全是 `type:'image'`）；
>   而"五套各出一张、互不相同"与"对着同主题 calendar 比"两条**已作废**。本节**海报那半段没动**（`invite-banner-bg-v4.webp` 仍是唯一底图）。
> 本节其余部分（v4 海报、纱与 opacity 的翻钉、另外四套主题的图标、那 17 + 26 例的量法）**仍然有效**，
> 连"内嵌浏览器视口 0×0 所以量不了长度"这条限制也仍然只对当时的 in-app 浏览器成立 —— §7.44 换了 headless Chrome + CDP，长度已经能量了。

**你的原话**：「重新换张图，要有海报感。可以把白色的那个遮罩删掉。然后图标也改成和日历一样风格的图标」。
我追问了两件会改结果的事，你勾的是：**「深色满幅 + 字改白」**（另一档是"图加深但文字底下仍留一块浅纱"，你没选）
和 **「五套主题各出一张」**（不是只出当前在用的那一套）。

**这一节把 §7.42 整批推翻**，所以先把"翻的是哪几条、测试怎么处理"写成一张表。
按第 8 节 24 条的口径，旧钉**翻方向而不是拔掉** —— 拔掉就变成"没人管这块了"，翻过来才叫"改回去就红"：

| §7.42 的做法 | 现在（§7.43） | 测试怎么处理 |
| --- | --- | --- |
| 底色仍浅（v3 象牙绸面） | 深色满幅（v4 海报） | 「只认 v3」翻成「只认 v4；jpg / v2 / v3 都不许再被引用」 |
| `::before` 叠一层白纱 `linear-gradient(#fff 0~72% → 88%)` | **纱整层删掉** | 新钉：`::before` 里一出现 `linear-gradient` 或白色 `rgba(255,255,255,·)` 就红 |
| `opacity: 0.85` | **不降透明度**（没有这行 = 1） | 新钉：`opacity` 只许"没有"或"等于 1"，写回 `.85` 就红 |
| 标题深红 `#9A1A1A`、副标棕 `rgba(80,50,20,.6)` | 标题 `#FFFFFF`、副标 `rgba(255,255,255,.85)`，各带一层 text-shadow | 新钉：那两个旧色值整块不许再出现 |
| 卡片白底 `#ffffff` + 红调边框/阴影 | `#1A0D10` 深底 + `rgba(255,255,255,.10)` 边框（**1px 宽度没动**，动了会挪文字起点） | 新钉：深色兜底底存在（防"图没传上去 = 白底白字空卡"） |
| 图标底板是红金渐变斜纹 | `rgba(255,255,255,.16)` + `inset 0 0 0 1px rgba(255,255,255,.28)` | 随整块一起量，不单独钉（它是装饰层） |
| 按钮 `#C00000` | **没动**（**这一行已被 §7.44 推翻**：改成透明灰板 + 白字，那条"按钮色不许跟着换图一起动"的钉也翻成了方向钉） | 沿用 §7.42 那条"按钮色不许跟着换图一起动" |
| 图标：classic 走**矢量** `AppIcon`，另外四套主题压根没写 invite（回退矢量） | 五套主题各一张**位图** | 新增一份 spec 共 26 例（见下） |

**"删掉遮罩"为什么不是删一行**：白纱和 `opacity:.85` 这两层在 §7.42 里是**扛可读性的承重墙**（当时不叠纱，标题最坏只有 4.19:1，
真凶是一条横穿整幅的金线）。删了它们，可读性就只能由**图自己**扛 —— 所以这次的图必须是"左 2/3 均匀暗底、主体钉在右端"，
并且要**重新量一遍**证明它扛得住，不能拿 §7.42 那张表说"当初量化过所以没事"。

**美术：一张就定，但两个坑是量出来的**
沿用第 8 节 20 条的口径（生成器尺寸写死 21:9，探针量原图不量成品）。原图 2560×1080，卡片比例 4.513:1，只能横切一条带：

- **切带位置由水印决定，不由构图决定**：三条候选带高度都是 567px（比例都是 4.515），差别只在竖着挪。
  探针在 raw 上把右下角「Qoder AI 生成」的水印字形定位到 **y=864..1002（原图 80.0%~92.8%）**，
  于是 `y=324..891`（§7.42 那条带）和 `y=300..867` **都会把水印切进来**，只有 **y=240..807（22.2%~74.7%）** 干净，离水印上沿还留 57px。
- **§7.42 那个"数成品右下角暗像素"的水印探针在这张图上会骗人**：深色海报的右端本来就一堆亮点 ——
  成品右下角 6%×10% 区域内亮度 >150 的像素有 **539 个**，那是玉璧高光和银鱼，不是水印。
  所以这轮的水印证据只能落在 **raw 的行定位** + "切带上下沿都在 y=864 之前"这两条上。**换图风就得换探针位置，别照搬上一轮的数。**
- **编码档位顺手量了色带风险**：同一张裁好的图 q80 = 18.5KB / q85 = 22.8KB / **q90 = 31.7KB（采用，落盘 32,466 B）**；
  左区竖带（文字底下那片暗色渐变）的"不同亮度级数 / 相邻最大台阶"是 png 参照 `10 / 5`、webp q90 `6 / 2` ——
  编码器把暗部渐变**抹得更平**了，深色海报最怕的"一道道色带"反而轻了。
- **发布图可字节复现**：拿 raw + 同一条带 + q90 重跑，出的 webp md5 与仓库里那张**逐位相同**（`41dc507c…e1bd`）。
  本轮临时目录会删（含 raw 原图），但成品在 git 里；要重做按这套口径走即可。
  ⚠️ 说清楚这句话的边界：**"可复现"是本轮当场量到的一次事实，不是一条以后还能跑的配方** ——
  raw 原图不入库（仓库只存发布资产），临时目录一删就没；以后真要在同一张图上挪切带，只能**重新生成一张 2560×1080**，
  构图细节不会一模一样。所以第 ④ 条遗留那句"改一行就行"，前提是那张 raw 还在你手上（现已不在）。

**可读性：图自己扛，量出来比 §7.42 那版好一倍**
门槛仍是第 8 节那句「不比旧渲染差」（旧渲染基线 8.28 / 3.32），**不是**绝对 4.5:1。
按 CSS 真实行盒逐像素量：标题 15px 行 y17..38、副标 12px 行 y42..59（列高 42 在卡高 76 里居中），
左界 x=72，右界取**墨迹实际右端**（标题 x=165 / 副标 x=215），白字 α=1 与 α=.85，**不含 text-shadow 的增益**（最保守口径）：

| | 标题最坏 | 副标最坏 | 采样里 <4.5:1 的像素 |
| --- | --- | --- | --- |
| §7.42 旧渲染（换图前） | 8.28:1 | 3.32:1 | — |
| §7.42 v3 + 白纱 α=.85 | 8.28:1 | 3.60:1 | — |
| **v4 深色满幅，不叠纱、不降 opacity（采用）** | **18.46:1**（最坏点 rgb 38,13,17 @x165y37） | **8.25:1**（最坏点 rgb 114,33,31 @x215y59） | **0 个**（2068 + 2592 采样） |

**顺带把「副标还能写几个字」量成了一条能测的上界**：逐列找白字对比 <4.5:1 的最左列 —— 标题行 **x=236**、副标行 **x=226**。
现在副标墨迹右端在 x=215（12 字 × 12px = 143.3px，从 x=72 起）。**所以副标最多 12 字**，第 13 个字就踩进 x≥226 的亮区。
这不是审美判断，是像素算出来的，已经钉进测试（反证 G 档：多加一个字就红）。

**这一轮最贵的一课：GD 的字号单位是「磅」，不是 CSS 像素**
我第一版探针把 CSS 的 15 / 12 直接当字号传给 `imagettftext`，FreeType 按 96dpi 换算，**字形被放大 1.33 倍**，
于是量出"副标右端撞到按钮 19px、那里对比只有 1.31:1"——**一个我自己造出来的假警报**，
我差点为此把文案从 12 字砍到 9 字、并给副标加 `white-space: nowrap` 之类的"修复"。
换成 `pt = cssPx × 0.75`（画 mock 时再 × scale）、并按 CSS 行盒取矩形之后，撞车消失，真实结论就是上面那套 18.46 / 8.25。
**记法**：以后凡是"拿 GD 画个 mock 去量前端排版"，第一步就把两套单位换算写死，并跟 DevTools 的 computed font-size 对一遍再开始量。

**图标：「和日历一样风格」翻成三条机器口径**
"一样风格"没法直接测，我拆成：① **同一个渲染分支** —— `type` 与 calendar 一样都是 `image`（不是 `svg`，否则走的是另一条 `<AppIcon>` 分支）；
② **尺寸遵循各家族自己的约定** —— `modern-*` 是「256×256 画布 + 墨迹约占 90%」（calendar 墨迹 242×224），
`gem/ink/shanse/lg-*` 是**紧裁、长边 132**（各自的 calendar 长边也都是 132）；
③ **五张互不相同、带真 alpha、没镶绿幕**（绿幕残留判据：`a>230 且 g-max(r,b)>60 且 g>90`，五张全 0.00%）。

| 主题 id（文件前缀） | 新增位图 | 画布 | 墨迹不透明占比 | 体积 | md5 |
| --- | --- | --- | --- | --- | --- |
| `classic`（`modern-`） | `tab/modern-invite.png` | 256×256（墨迹 240×229） | 58.2% | 65,986 B | `330f1b5b619a64cac14379789151ff8d` |
| `gem` | `tab/gem-invite.png` | 80×132 | 92.6% | 11,737 B | `074dff70a68c4b64b278c66ad4701161` |
| `ink` | `tab/ink-invite.png` | 105×132 | 98.3% | 22,441 B | `cde12c1f62546badfe68d6f4eff8144e` |
| `shanse` | `tab/shanse-invite.png` | 132×109 | 41.6% | 20,810 B | `9e14a90b08ea0c4ff15fa07657cae290` |
| `glass`（`lg-`） | `tab/lg-invite.png` | 132×118 | 96.5% | 30,517 B | `376a2593f49ad6fce1c64b32f67c6cd2` |

**三个坑，都记下来**：
① classic 的 invite 原本是 `{ type: 'svg', icon: 'invite' }`，另外四套主题**压根没写 invite**，
靠 `getFeatureIcon()` 末尾那句回退到 classic 的矢量 —— 所以"五套各出一张"落到代码上是 **一处换分支 + 四处补表项**，
`git diff src/stores/iconTheme.js` 就是这 5 个 `invite:` 行，同主题里 calendar/wallet 那些一个没动。
② 我第一版 `modern-invite.png` 出成**紧裁 256×244（墨迹 100%）**。单张看没问题，放进家族就露馅：
`modern-*` 的约定是 256 画布 + 90% 墨迹，紧裁的这张在 28px 的 `contain` 盒子里会比日历图标**明显大一圈**。
按约定重铺到 256×256 透明画布后墨迹 240×229，与 calendar 的 242×224 同档。
（另外四套本来就是紧裁家族，长边对齐各自 calendar，不需要重铺 —— 所以这条只影响 classic。）
③ **主题 id 是 `glass`，文件前缀才是 `lg-`**。第一版遍历脚本写 `setFeatureTheme('lg')` 直接返回 `false`，五套循环全跑空，
差点把"测试没覆盖到 glass"误报成"glass 没有图标"。表里改成 `{ id, prefix }` 成对写死之后才对上。

**改了哪几处**

| 文件 | 改动 |
| --- | --- |
| `sinan-art-source/public/images/invite-banner-bg-v4.webp` | 新增，1280×284，32,466 B，md5 `41dc507c94e0dfea5778b2a20b3ae1bd` |
| `sinan-art-source/public/images/tab/{modern,gem,ink,shanse,lg}-invite.png` | 新增五张，见上面那张表 |
| `sinan-art-source/public/images/invite-banner-bg-v3.webp` | **删除**（`git rm`，上一轮的成品；在 commit `165fbd3` 里可原样取回，32,722 B / md5 `27f8dabe616bc8ecf46649bcd9c3868d`） |
| `sinan-art-source/src/views/User.vue` | `.invite-banner`：底色/边框/阴影深色化，`::before` 收成单层 `url(v4) center/cover`（**纱与 opacity 全删**），标题副标改白并各加 text-shadow，图标底板换白色半透明 + inset 描边；**按钮 `#C00000`、版式（padding 16 / gap 12 / 44px 图标位 / 28px 图 / 字号 15-12）一个没动**，模板两处 `<img>`/`<AppIcon>` 分支也没动 |
| `sinan-art-source/src/stores/iconTheme.js` | 五处 `invite:` 条目（classic 由 svg 改 image，其余四套新增） |
| `sinan-art-source/tests/invite-banner-art.spec.js` | 9 例 → **17 例**（§7.42 那 9 条按上面的表翻方向，没删） |
| `sinan-art-source/tests/invite-icon-theme.spec.js` | 新增 **26 例** |

**17 例里新长出来的两种量法**（`invite-banner-art.spec.js`）：
① 反向钉 —— 读样式前先 `code()` 把 `//` 注释剥掉，否则"不许出现 linear-gradient"这种否定断言会被**我自己写的注释文本**绊红；
② 长度上界单独立一条 `it` —— 把"副标 ≤12 字"和"文案字符串钉住"拆成两条，
否则改文案时红的是"字符串变了"，看不出真正的原因是**踩进了亮区**。
另有：几何前提（16+44+12=72、`flex-shrink:0`、z-index 0/1 分层）全部重钉一遍，
webp 从文件头解宽高（`VP8 `/`VP8X` 两种都读）核比例 ±3%、守住 40KB、且 `w ≥ 343×3 && h ≥ 76×3`（3 倍图，暗部糊了会出色带）。
分组当场点名数是 **3 / 3 / 3 / 4 / 4 = 17**（只认 v4 那组 3 条、白纱翻红那组 3 条、文字配色那组 3 条、几何前提那组 4 条、发布图本体那组 4 条）。

**26 例（`invite-icon-theme.spec.js`）—— 三个 describe，实际 3 / 20 / 3**（当场用 `--reporter=json` 点过名，别按"感觉"报数）：
表结构 **3** 例（每条主题都写了 `features.invite` 且地址=表里那张 / `invite` 与 `calendar` 的 `type` 同为 `image` / 五张互不相同）；
位图本体 **20** 例 = 五张 × 4 条（① RGBA 且 alpha 真在用：`solidRatio>0.05` 且 `softRatio>0.005`、长边 ≥56px、≤256px；
② 绿幕残留 `greenRatio<0.005`；③ 长边与同主题 calendar 差 ±15% 以内；④ 体积 ≤80KB）——
解码是 Node 端自己做的：读 IHDR + 拼 IDAT + `zlib.inflateSync` + 按 Sub/Up/Average/Paeth 反滤波，
所以 `colorType` 必须是 6（`lg-calendar.png` 那种调色板图**只取 IHDR 宽高、不解码**，第一轮我在这儿被自己绊了一次）；
接线 **3** 例（模板里 `getFeatureIcon('invite')` 与两条分支都在 / 逐主题运行时取到的路径磁盘上真有这张文件 / 后台自定义 invite 仍优先，缺省回退 `modern-invite.png`）。

**反证 17 档**（逐项拆 → 只跑这两份 spec → 当场还原 → 逐文件核 md5）

| # | 拆什么 | 红的是哪一例 |
| --- | --- | --- |
| A | `::before` 叠回白纱 `linear-gradient` | 「不许再叠白纱」 |
| B | `opacity: .85` 回来 | 「不许降透明度」 |
| C | 标题改回 `#9A1A1A` | 「标题纯白+text-shadow」＋「旧深红/棕色不许再出现」两例 |
| D | 副标白度降到 `.6`（低于量出的 .85 下界） | 「副标近白」 |
| E | 底图地址回退 v3 | 「只认 v4」＋「SFC 地址=磁盘文件」两例 |
| F | `padding 16 → 12`（挪文字起点） | 「文字起点 = 16+44+12 = 72px」 |
| G | 副标多加一个字 | 「文案钉住」＋「12 字是量出来的上界」两例 |
| H | classic 的 invite 退回矢量分支 | 「五套都写了 image」＋「与 calendar 同分支」＋「运行时地址=表里那张」＋「自定义仍优先」四例 |
| I | `gem-invite.png` 换成不带 alpha 的整块方图 | 「带 alpha 且透明通道真在用」 |
| J | `lg-invite.png` 长边 132 → 60 | 「透明」＋「长边与 calendar 同档」两例 |
| K | 五张里两张复制成一模一样 | 「五张互不相同」 |
| L | 底图裁成 16:9 | 「cover 不会裁掉右边古物」 |
| M | 底图重存成 47KB | 「体积 ≤40KB」 |
| N | 底图降到 500×111（比例不变，糊了） | 「够 3 倍图」 |
| O | `shanse-invite.png` 轮廓镶一圈荧光绿 | 「没有绿幕残留」 |
| P | 卡片读成 `getFeatureIcon('calendar')` | 「读的是 invite」 |
| Q | **整个 `::before` 删掉**（防空转） | 「不是钉空气」＋"只认 v4"＋"z-index 分层"＋"地址=文件" 四例 |

17 档全部**只红在点名那些例**，跑完 8 个文件（2 份源码、1 张 webp、5 张图标）md5 逐位 SAME。
**H 与 Q 各带"顺带红"**（H 4 条 / Q 4 条）：这两档拆的是共用前提，
连带红的每一条都能解释为什么（H：分支一变，取数与自定义优先那两条一起失效；Q：整层没了，引用/分层/存在性一起失效），
不是测试写松 —— 台账里记明，是为了下次看到"红 4 条"不必重新怀疑。

**反证 harness 自己有个假绿，比被测代码的 bug 更贵**：
它把 vitest 的 junit 输出拿来数红条，而 Windows 下 `execFileSync('npx', …)` 抛 ENOENT 被 catch 吞掉，
`junit.xml` **根本没生成**，17 档于是全报"0 红 → OK"。加了三样才是可信的：`shell: true`、
**junit 文件不存在就 throw**、以及"本轮总用例数 <30 就 throw"的健全性闸。
第 8 节 19 条那句"反证要证明探针活着"讲的是同一件事，这轮是它的一次实测复发。

**三端测试基线**（当场重跑，不是沿用）：H5 **201 例 / 20 文件**（167 → 201，+34 = banner spec 8 + icon spec 26）全绿；
后台 **167 例 / 14 文件**全绿；后端 `--testsuite unit` **269 例 / 1760 断言** OK（仍有 1 条 deprecation，非本轮引入）。
后端与后台这轮**一个字节没碰**（`git status` 只有 2 份源码、1 份旧 spec、2 份新/改 spec、6 张新图、1 张删图）。

**浏览器实测**（`:5173/#/user`，视口仍 0×0 —— 长度量不了，颜色/层叠/像素都能量）

| 量什么 | 实测 |
| --- | --- |
| `getComputedStyle(el,'::before')` | `backgroundImage: url("…/invite-banner-bg-v4.webp")` —— **只剩一层，纱确实没了**；`opacity: 1`、`filter: none`、`backgroundSize: cover`、`zIndex: 0` |
| 卡片 | `background rgb(26,13,16)`、`border 1px rgba(255,255,255,0.1)` —— 深色兜底底在位 |
| 标题 / 副标 / 按钮 | `rgb(255,255,255)` + `rgba(0,0,0,.55) 0 1px 0`（阴影的 2px 被 px→vw 转成 `.53333vw`，0 宽视口下报 0，是测量假象不是缺陷）/ `rgba(255,255,255,.85)` / `rgb(192,0,0)` |
| 图标到底走哪条分支 | `<img src="/images/tab/modern-invite.png" naturalWidth=256>` —— **确认走位图分支**，不再是 `<AppIcon>` 那个 SVG |
| **让 Chrome 自己把图 cover 进 343×76 canvas 再逐像素量** | 标题 **18.54:1** @x165y37 bg(38,12,16)、副标 **8.29:1** @x215y59 bg(114,32,30)、两行 <4.5:1 的像素各 **0 个** |
| 与 PHP 模型对表 | 18.46 / 8.25 vs 18.54 / 8.29 —— **最坏点坐标一致、背景 rgb 差 ≤1**，差值是 Chrome `drawImage` 缩放时的取整，合成数学两边同意 |

**顺带纠正一条我自己写过的判据**（§7.42 里我用过"curl 打这张图 200 就说明文件已上"）：
这台 dev server 对**不存在的静态文件也回 200**，只是 `Content-Type: text/html`（SPA 兜底页）。
刚才实测 `invite-banner-bg-v3.webp` 已被删掉，`curl -w '%{http_code}'` 仍然报 200。
**以后打静态资源要同时看 `content_type` 是不是 `image/*`**，只看 200 会把"404"读成"存在"。

**dist 指纹**（`npm run build` 后当场重量）：`index.html` `dfdeb26eaaa58a4f633d708595beaf64`、
入口 JS `assets/index-BY-ke93N.js` `76dbedf9cfa499a10220f203bb09404d`、
入口 CSS `assets/index-BV8nMqO8.css` `7efc1c145ecb12e2a4c6b987d31a2f6c`；
CSS 里 `grep -o invite-banner-bg-…` **只命中 v4 一次**（jpg/v2/v3 都不再被引用），
`dist/images/invite-banner-bg-v4.webp` 与五张 `dist/images/tab/*-invite.png` 的 md5 与源文件逐字节相同（`public/` 整目录原样进 dist）。
**这些名字与 md5 每 build 一次就变，只当"是不是旧包"的对照，重量以发版当场为准。**

**临时目录已清**：本轮所有探针脚本、mock 图、作废 webp（反证用的那些坏资产也在里面）都在 `E:\sinan\qa_art_v4\`（9.4MB），台账写完即整目录删除
—— 沿用 §7.40 / §7.42 那两轮的口径：**仓库只存发布资产，脚本与素材不入库**（当场 grep 过：全仓库 `.png` 只有 `public/` 下那 74 个是发布件）。
**删完当场复跑 H5：201 例 / 20 文件仍全绿**，证明没有任何测试依赖那个目录里的东西
（`git status` 里剩下的 untracked 只有 `AGENTS.md` 与 `qa/sit_t6b_priority_window.php`，这两条是**按要求故意不入库**的）。

**遗留与没验的**
① **生产一行没动**，这批和 §7.41/§7.42 一样并到「第 9 + 第 10 那一批」里等你单独点头；
② **"有海报感"这件事本身没法测** —— 内嵌浏览器视口 0×0，我验到的是"层叠、颜色、像素对比度、图能加载"，
没验到"好看"，要你或真机看一眼；深色满幅 + 白字在真机高亮屏上会不会觉得暗部闷，也只有真机能回答；
③ **四套非 classic 主题的图标看不到实际效果**：可见主题由服务端 `/config` 下发（`stores/site.js:143` 会覆盖 localStorage，当前是 `classic`），
所以 `gem/ink/shanse/lg` 那四张只能靠 store 级测试 + HTTP 200（且 content-type 是 image）+ PNG 解码三条来证，**没有截图证据**；
 （**这一半已被 §7.45 推翻：五套都有 headless Chrome + CDP 的截图与合成分量实测**；但「`/config` 下发会覆盖 localStorage」那条**仍然有效**，
  §7.45 就是被它绊了一次 —— 五张全量成 classic，解法（CDP Fetch 域改写响应）见那节第三条。）
④ 按钮 `#C00000` 右端与玉璧左缘**按设计重叠一点**（海报感来自主体压边），观感不合意就说，那是把切带上沿再往下挪的事，改一行；
⑤ 旧图 `invite-banner-bg.jpg`（700×394 / 45,701 B）仍全仓库零引用、仍是孤儿，**按第 8 节 15 条口径我没动它**（要清先出清单、分档、你点头、`tar` 存档再移回收站）。

### 7.44 「邀请」按钮改成透明灰板 + classic 那张图标换成平面信封（2026-10-16 02:1x，本地做完并测过，**未上线**）

> **⚠️ 本节一半被 §7.45 换掉、图标那半段最终被 §7.46 整体作废**：按钮「透明灰板 + 白字」的配方**仍然有效**（§7.46 没碰 `User.vue`）；
> 「只换现在看到的这一张（classic 的平面信封）」这条**范围**口径已被 §7.45 推翻（改成五套各一张），而那五张又被 §7.46 全删、换成五套共用的一张写实玉佩小卡。
> 本节那三条"平面度"判据（4bit 色阶层数 ≤100、top8 集中度、墨迹占比）里，**前两条在 §7.46 被翻成反方向**（写实 = 几百档、top8 低），只有"墨迹外接框"那条还留着。

**你的原话有两条，第二条推翻第一条**：先说「邀请两个字的背景边框做成玻璃液态，图标再换一个，平面的」，
我追问三件事、你勾的是 **右侧那颗「邀请」按钮 / 字用深红 `#C00000`（跟首页 `__sign` 一致）/ 只换现在看到的这一张图标**；
乳白玻璃那版做完之后你打断并改口：**「透明灰色」**。按「最后那句为准」的口径，玻璃那版**代码没留、也没上线**，
但它的测量数和"一出现就该红"的方向钉都留下来了（见下面 H 档与色值表）。

**「透明灰」三个字里藏着三个决定，每个都是量出来的**

| 要定的事 | 候选与实测（白字对按钮板的最坏对比，AA 线 4.5:1） | 结论 |
| --- | --- | --- |
| 板子多深 | 这颗按钮整块压在海报**亮区**：底下那片 L=0.005~0.797（右端是玉璧高光）。同 α=0.62 下 `rgb(46,46,52)` → **4.98:1**；中性灰 luma 86 → 最高 **4.06**；luma 130 → 最高 **2.72** | "灰"必须是**深**灰。中灰/浅灰板配白字**数学上就通不过**，不是审美问题 |
| α 多少 | 0.55→4.20、0.58→4.51、**0.62→4.98**、0.65→5.37 | 取 0.62，给"合成算歪一点"留 0.48 余量 |
| 字什么色 | 同一块板上 `#C00000` 深红字最坏 **1.00~1.57:1**（等于看不见）；白字 4.98 | 字回到白色。**深红字只能配浅底** —— 这正是"玻璃白 + 深红字"被"透明灰"顶掉时顺带撞到的墙 |

**没写 `backdrop-filter`**：糊底会把"这台机器的 WebView 支不支持 blur"变成可读性的一部分（不支持 = 只剩 α 那一层）；
不糊底时上面那串数就是逐像素真值。观感上"透明灰"要的本来就是底图透出来，砍掉 blur 不亏。

**这轮顺手把 §7.43 那句「视口 0×0 量不了长度」的坑解掉了**
in-app 浏览器不可见时（`visibilityState=hidden`、viewport 0×0，`take_screenshot` 直接报 `NATIVE_BROWSER_VIEWPORT_UNAVAILABLE`），
改用 **headless Chrome + CDP**：`chrome --headless=new --remote-debugging-port=9333 --user-data-dir=<临时目录>`，
Node 24 原生 `WebSocket` 连 `webSocketDebuggerUrl`，`Emulation.setDeviceMetricsOverride` 固定 390×844 / dpr2 —— 长度、行盒、真实排版就都能量了。

| 量什么 | 实测 |
| --- | --- |
| 按钮外沿（新 vs 改前） | `66.45×38.38` 设备px vs `66.55×38.47` —— **同一个盒子**（差 0.1px = 亚像素噪声） |
| 「邀请」行盒 | 29×16 设备px @ 卡片相对 (291,32)（`Range.getBoundingClientRect` 取的，不是估的） |
| 白字最坏（行盒内 464 像素） | **5.12:1** @ (311,33)；<5.5:1 的只有 7 个像素 |
| 白字最坏（整块板外接框 2508 像素） | **4.69:1** @ (303,22)（那一行是边框压着的亮边，字不在那儿） |
| 计算样式 | `background:#2e2e349e`、`color:rgb(255,255,255)`、`border:1px solid rgba(255,255,255,.32)`、`backdrop-filter:none`、`box-shadow:inset 0 1px #ffffff2e`、`padding:1.86667vw 4.53333vw` |

**第二次被 px→vw 绊，这次记住了**：样式里的 `8px` 编译成 `2.13333vw`，在 390 宽下是 **8.32px 而不是 8px**。
我用 inline style 复现"改前那版"时直接写 `padding:8px 18px`，量出"新版比旧版宽 1.3px"，差点把注释写成"外沿会长一点"。
把 inline 的 px 乘上换算系数（`innerWidth/375` = 1.0401）之后差异归零。**记法：拿 JS 改样式做对照实验，px 值要先乘系数，否则是在拿两套单位比。**

**内边距是四个方向各收 1px，不是只收左右**：新增的 1px 边框要补的是**四条边**。
第一版只把横向 18→17（竖向留 8），实测外沿比改前**高 2.6 设备px** —— 上下那两条边框没补回来，
被它盖住的海报区域会长一圈，上面那串对比度读数的几何前提就不成立了。最终 `padding: 7px 17px`，
测试钉的是简写整串 `padding:\s*7px\s+17px`（`px()` helper 只取第一个数，所以必须整串匹配）。

**图标：「平面的」拆成两条机器口径**，思路与 §7.43 那三条一样 —— 不跟"我脑子里的标准答案"比，跟**同一家族**比：

| 口径 | 新 `modern-invite.png` | 被换掉的立体磨砂那张 |
| --- | --- | --- |
| 4bit 量化后的色阶层数 | **54 阶** | 229 阶 |
| 前 8 大色占墨迹比例 | **94.9%** | 0.76% |
| 精确颜色数 | 404 | — |
| 墨迹外接框 | 233×197（长边 = 同家族 calendar 墨迹 242 的 **96.3%**） | 240×229 |
| 体积 / md5 | **7,049 B** / `7939d8d861a4c0885e7d4a95c049adce` | 65,986 B / `330f1b5b619a64cac14379789151ff8d`（`git show HEAD~1:sinan-art-source/public/images/tab/modern-invite.png` 可原样取回，当场验过就是这个 md5） |

两条口径新旧之间都留了 **4 倍以上空隙**（54 vs 229 阶、94.9% vs 0.76%），所以"稍微带点立体感"不会误红，"整张渐变糊图"一定红。
主体色仍是家族那套浅灰（>50% 墨迹像素的 r,g,b 都 >180）—— 图标底板是深色卡上的半透明 tile，深色图标会糊成一团。

**这张图的生成器入库了**：`qa/gen_flat_invite_icon.py`（PIL 手绘，`BODY 240,240,243 / FLAP 208,210,222 / SEAL 201,39,52`，4× 超采样 + LANCZOS）。
与 §7.43 那批 AI 生成图不同：那批的"可复现"只是当场一次事实（raw 原图不入库），**这张是脚本画的，跑一遍产出与仓库里的字节逐位相同**（当场验过）。
⚠️ 环境依赖：本机 PIL 7.2.0 **没有** `ImageDraw.rounded_rectangle`（8.2 才有），圆角矩形是 2 个矩形 + 4 个圆拼出来的。

**改了哪几处**（4 个文件 + 1 个新脚本；后端、后台一个字节没碰）

| 文件 | 改动 |
| --- | --- |
| `sinan-art-source/src/views/User.vue` | 只动 `&__btn` 这一块：底色 `#C00000` → `rgba(46,46,52,.62)`、加 1px 半透明白边 + 内高光、`padding: 8px 18px` → `7px 17px`、注释换成上面这套数。**模板、卡片其他层、版式几何一个没动** |
| `sinan-art-source/public/images/tab/modern-invite.png` | 换图。只 classic 这一张，`gem/ink/shanse/lg` 四张按你的口径**没动** |
| `sinan-art-source/tests/invite-banner-art.spec.js` | 17 → **20 例**：原来那条"按钮保持平台主红"翻成 4 条（不许回实色红 / 透明灰板参数 / 不许靠 backdrop-filter / 外沿几何） |
| `sinan-art-source/tests/invite-icon-theme.spec.js` | 26 → **29 例**：新增一组 3 条（平面两条口径 + 墨迹体量 + 家族浅灰），另加两个 helper：4bit 量化数色阶、按 alpha>51 求墨迹外接框（都走已有的 Node 端 PNG 解码） |
| `qa/gen_flat_invite_icon.py` | 新增，出图脚本入库（理由见上：它真能字节复现） |

**反证 14 档（A~N）**：A 底色回实色红 / B α 降到实测的 0.58 / C 字回深红 / D 去掉那圈亮边 / E 内边距回旧写法 /
F 把 `backdrop-filter` 加回来 / G α 写成 1（透明灰变实色灰块）/ H 退回中途那版乳白玻璃 / I 去掉内高光 /
J 整个 `&__btn` 块删掉（防空转）/ K 图标换回立体磨砂那张 / L 换成不带 alpha 的整块灰方图 / M 墨迹缩到 60×48 / N 主体涂成深灰。
**14 档全部只红在点名的那些用例**，跑完 6 个文件（1 份源码 + 2 份 spec + 1 张 webp + 2 张图标）md5 逐位 SAME。
两档的"顺带红"记明原因：J 整块删掉连带红 4 条（含"背景层压在下面"那条 z-index 分层，按钮是它的断言对象之一）；
A 换成 `#C00000` 同时红 2 条（"不许回实色红" + "透明灰板参数"，后者要求 `rgba(46,46,52,α)`）。
**这两条一开始被我当成"测试写松"，实际是我 expect 清单没写全 —— 反证脚本自己也会造出假不符**（第 8 节 19 条第四次复发，判据：先问"这一档改的字节还会让哪条断言失效"，再决定是补清单还是改测试）。

**三端基线（当场重跑，不是沿用）**：H5 **207 例 / 20 文件**（201 → 207，+6 = banner 3 + icon 3）全绿；
后台 **167 例 / 14 文件**全绿；后端 `--testsuite unit` **269 例 / 1760 断言** OK（1 条 deprecation 是既有噪声）。

**dist 指纹**（`npm run build` 当场重量；每 build 一次就变，只当"是不是旧包"的对照）：
`index.html` `206845fe2383bddd787e5e1fadc71246`、入口 JS `assets/index-CPN5ux0z.js` `ab15831efd4db85d7eb0a869f7232ced`、
入口 CSS `assets/index-BYu61auI.css` `92033846522c735bade8d59943964f81`；
`.invite-banner__btn` 在 dist 里编译成 `background:#2e2e349e`，而全 CSS `grep -c "46,46,52"` 是 **0**（压成 hex8 了）——
**以后查色值要 `rgba()` 与 hex8 两种写法都 grep**，只 grep 一种会把"存在"读成"不存在"；
`dist/images/tab/modern-invite.png` md5 与源文件逐位相同；海报仍只引用 v4（jpg/v2/v3 零引用）。

**遗留与没验的**
① **生产一个字节没动**，这批仍并进「第 9 + 第 10 那一批」等你单独点头；
② **"透明灰好不好看"仍然没法测**：这轮量到的是对比度、几何、层叠、色值、图能加载，观感要你或真机看一眼 ——
底图细节会从板子后面透出来，这正是"透明"的代价；
③ **可读性比换图前是降的，说清楚**：旧按钮（实色 `#C00000` + 白字）不依赖底图，最坏 **6.48:1**；
新透明灰 + 白字最坏 **5.12:1**（字上）/ **4.69:1**（整块板）。都过 AA，但"透明"就是把一部分可读性交给了底图 ——
**海报以后若换成更亮的图，这块板要重量一遍**；
④ 另外四套主题（`gem/ink/shanse/lg`）的 invite 图标本轮没动，也仍然**没有截图证据**（可见主题由服务端 `/config` 下发，当前是 classic）； **【已被 §7.45 推翻：五套全部换成平面中式请柬，本条只作历史】**
⑤ 文案长度还有余量：α=0.62 下从 x=200 到 x=327 每一列白字都 ≥4.5，**「邀请」加到约 5 个字都不用重量**。


### 7.45 五套主题的「邀请」图标全换成平面中式请柬（2026-10-16 11:0x~11:2x，本地做完并测过，**未上线**）

> **⚠️ 本节整批已被同一天晚上的 §7.46 推翻并删除**（用户口径换成「图标的风格要和公告详情首图一样的风格」）：
> 五张 `<前缀>-invite.png` 已 `git rm`（md5 表在本节末尾，取回用 `git show <§7.45 那批提交>:sinan-art-source/public/images/tab/modern-invite.png`，
> 本轮已另存一份在 `E:\tmp\v46\old\`），出图脚本 `qa/gen_flat_invite_icon.py` 一并删除，spec 从 54 例重写成 22 例。
> 本节里**仍然有效**的只有两条：① 「五套都要位图、和 calendar 同一条渲染分支」（§7.46 沿用）；
> ② 那三条**方法论**教训（"对着家族比"不能实现成"比文件尺寸"、色值阈值代理观感会被反证绊倒、`/api/config` 覆盖 localStorage）—— 它们讲的是量具怎么坏，跟这批成品换没换无关。
> **已作废**：中式请柬轮廓（拱顶 0.35~0.60）、四色槽、"五张互不相同"、以及"平面 = 色阶层数少 + top8 高"那条方向（§7.46 反过来钉 ≥150 档 / top8 ≤0.65）。

用户口径：「图标再换，换成符合功能的图标。或者后台添加上传图片的功能，我自己换，就像其他图标一样」。
三个追问定下的范围：**只重画图标**（不为后台上传口写代码）、造型走**中式请柬**、覆盖**五套主题各一格**。
本批**推翻 §7.44 的「只换 classic 这一张、另四张没动」**（旧那条按惯例只加标注、不改写），§7.43 的「五套都要位图」那条口径不变。

**先说一件比这批代码更值钱的事：你选的那条「或者」路，今天就能走，不用发版。**
「后台添加上传图片的功能，我自己换」这件事**早就存在并且线上可用**，三处读下来 + 两次公网复验：
① 后端 `sinan-nft-backend/app/controller/Content.php:178,208-215` 把 `site.customIcons`（六个键）随 `/api/config` 下发；
② 写入口 `sinan-nft-backend/app/admin/controller/CmsController.php:863-868`，界面是后台「内容 → 站点装修」里的**「邀请好友图标」那一格**
（`sinan-admin/src/views/content/Decoration.vue:31-36,69-75,259-270`）；
③ C 端 `src/stores/iconTheme.js` 的 `getFeatureIcon()` 里 **custom 优先于主题表**，`src/stores/site.js:145` 在 init 时注入。
线上侧：H5 的 dist 入口 JS 里有 `setCustomIcons`，后台 dist chunk `Decoration-ZX6bs9Xo.js` 里有 `custom_icon_invite`。
**一个要紧的口径**：这一个槽是**主题无关**的 —— 你上传一次，五套主题都吃它（不像本轮这样五张各画）。
所以本轮没为上传口动一行代码，只把五张默认图重画了；两条路以后互不挡。

**画的是什么**：一张**拱顶请柬**（上半个圆 + 直边卡身 = 中式折式请柬的轮廓），四个色块、三条字线、一颗印泥：
外圈描边 → 纸面（四边各让 12）→ 三条字线（长短递减，像请柬上那三行字）→ 印泥红圆压在拱顶下沿。
五套**共用同一套轮廓**，只换四个色槽，这样「换主题」换的是家族配色而不是造型：

| 主题（文件前缀） | 外圈描边 | 纸面 | 字线 | 印泥 | 画布 |
| --- | --- | --- | --- | --- | --- |
| classic（`modern-`） | `208,210,222` | `240,240,243` | `176,180,196` | `201,39,52` | 256×256 方画布 |
| gem | `186,196,208` | `232,238,244` | `150,162,178` | `176,42,58` | 紧裁 106×132 |
| ink | `212,204,188` | `246,243,236` | `128,124,116` | `178,44,46` | 紧裁 106×132 |
| shanse | `206,164,92` | `242,226,178` | `150,112,58` | `188,50,54` | 紧裁 106×132 |
| glass（`lg-`） | `196,62,74` | `244,230,232` | `198,140,148` | `192,0,0` | 紧裁 106×132 |

**改动面只有五张图 + 一份出图脚本 + 一份 spec**：`qa/gen_flat_invite_icon.py` 从「只出 classic 一张」改成「一次出五张」
（`THEMES` 里带每套的画布模式：classic 走 256 方画布原样，其余四套 `getbbox()` 紧裁后长边缩到 132 —— 这是 §7.43 就定下的家族约定）；
`src/views/User.vue`、`src/stores/iconTheme.js`、后端、后台、数据库、迁移**一个没碰**。

**测试**：`tests/invite-icon-theme.spec.js` **29 → 54 例**。§7.44 那节只对着 modern-invite 生效的三条钉翻成五套都吃：
平面度（4bit 量化 ≤100 档 / 最常见 8 色 ≥0.55）、请柬轮廓（拱顶比例 0.35~0.60）、四个色槽占比（纸面 ≥0.5、描边 0.10~0.35、印泥 0.02~0.08）、
相对亮度 ≥0.5、墨迹撑满画布 ≥0.85；另加两条跨文件钉：**五张拱顶比例互差 ≤0.06**（防"某一偷偷换了造型"）、
**spec 里那份色槽表 = 出图脚本里那份**（正则去读 `qa/gen_flat_invite_icon.py` 的 `THEMES`，四槽逐个比，防"改了脚本忘了改图/改了图忘了改钉"）。

**三条当场踩到的，比这批代码值钱**：
① **旧钉「长边与同主题 calendar 同一档」量的其实是「文件多大」，不是「画上去多大」** —— 它只 `readPng()` 解 IHDR 拿宽高，
所以五套都跑得动、也一直绿；可我在 §7.44 刚写过另一条钉「光看文件尺寸看不出变小」。**两条钉并排放着、口径互相打脸，而打脸没人发现。**
这轮我把它升级成「墨迹外接框 ÷ 画布 ≥0.85」，才第一次真去解 `calendar` 的像素，于是四套**直接抛异常** ——
`gem/ink/shanse/lg` 那四张 calendar 是**索引色 PNG（colorType 3）**，我的解码器只吃 RGBA8（只有 `modern-calendar.png` 是 RGBA）。
**原来错在哪**：我第一版台账把这条写成"§7.43 那条钉只跑得动 classic、却被写成五张的口径"，这是**错的归因** ——
旧钉在五套上都跑过，只是它压根没解码、量的不是墨迹。真正的缺陷是**"对着家族比"这句话被实现成了"比文件尺寸"**。
现在改成量「墨迹外接框 ÷ 画布 ≥0.85」（五套都能量，不需要 calendar 可解码），classic 再单独跟 `modern-calendar.png` 的**墨迹**比一次。
② **「亮色占比 >50%」这条代理量法对烫金家族是错的**：`shanse` 的纸面是 `(242,226,178)`，蓝通道 178 差 2 就掉出 `>180` 的门槛，
实测只有 **3.4%** 的墨迹算"亮"，而这张图在深底上明明是最亮的一档。换成 WCAG 的**相对亮度**（sRGB 线性化 + 0.2126/0.7152/0.0722）后
五张 0.594~0.755，一条线过。**教训：凡是"用色值阈值代理观感"的钉，都要问一句"还有谁能造出这个数"，能造出假红的阈值就是错的量具。**
③ **`/api/config` 会覆盖 localStorage**：第一次跑五套主题的截图，五张量出来**全是 classic**（`stored:"classic"`、src 全是 `modern-invite.png`）。
根因在 `src/stores/site.js:143`：init 拿到 `cfg.site.featureIconTheme` 就 `setFeatureTheme()` 写回并把 localStorage 也刷成 classic。
量具本身没红，红的是我的前提。解法不改数据库、不挡请求：用 CDP 的 **Fetch 域**在 Response 阶段把响应里那两个主题字段改写成目标值，
五套都走**真实渲染路径**（顺带证明了「服务端下发主题」这条链是活的）。

**反证 10 档（A~J）**：逐档只红在点名的用例，跑完五张图标 + 出图脚本 + spec + `User.vue` md5 逐位 SAME。
两处当场修正：C 档（ink 整体缩到 80%）第一轮**顺带**红了平面度（top8 掉到 0.599 撞上 0.6 的线）—— 处理是**把线放到 0.55 并写下理由**
（新五张实测 0.662~0.856，§7.43 那批立体图 0.007~0.170，0.55 仍在两段之间留了 4 倍空隙），而不是接受一条"顺带红"；
G 档对"只改 spec 色槽表"的预期写宽了（占比钉有 ±45 的容差，只有防漂移钉会咬），改成只点名那一条。
**还有一次自伤**：诊断 C 档时我 `git checkout -- .../ink-invite.png`，把**还没提交的新图**退回成 §7.43 的旧立体图 —— 工作树里那份就是唯一副本。
是反证脚本自己的「基线不干净」守卫（开局先跑一遍，红 4 例即中止）把它报出来的，重跑生成器后五张 md5 复位。
**规矩：未提交的成品不许就地 `git checkout`/`restore`；要看旧版用 `git show HEAD:<path>` 取到别处。**

**浏览器实测（headless Chrome + CDP，390×844 / DPR 2，五套各一遍）**：
`<img>` 渲染 **29.11 CSS px** 见方、玻璃 tile **45.75 px**、卡片 **356.75×81.52**，五套完全一致（同一套版式，只有 src 与像素不同）。
背板是**量出来的**不是猜的：把 `<img>` 临时 `visibility:hidden`、同一矩形再拍一张，得到图标位置的**合成背板**相对亮度 **0.028**
（海报 + `::before` 那层滤镜都在里面）。五张的墨迹平均亮度 0.5638~0.6991 ⇒ 对背板 **7.81~9.61:1**，五套全过 WCAG 1.4.11 的 3:1。
分色另算（离线，配对按层叠关系）：纸面 vs 深底 **10.45~12.15**、印泥 vs 纸面 **4.47~5.76**、
描边 vs 深底 **2.65~8.95**（`glass` 那圈红包边只有 2.65，但它压的是玻璃板不是纯海报，实测那张的背板亮度就是 0.028 这一档 —— 所以它是**设计上接受的取舍，不是通过**）、
三条字线 vs 纸面 **1.82~3.75**（`classic` 最弱 1.82；这是装饰纹理不是文字，29px 下它本来就只剩一条印子，要更清楚只需把脚本里 `line` 那一档调暗）。

**dist 指纹**（`npm run build` 当场重量）：`index.html` `206845fe2383bddd787e5e1fadc71246`、入口 JS `assets/index-CPN5ux0z.js` `ab15831efd4db85d7eb0a869f7232ced`、
入口 CSS `assets/index-BYu61auI.css` `92033846522c735bade8d59943964f81` —— **三个 md5 与 §7.44 记的那份逐位相同**，
因为这批源码一行没动，只有 `public/images/tab/` 五张 PNG 换了字节。
**所以这批的发版动作小到只有一件事：传五张不带哈希的图标。** 沿用第 25 条那条提醒 —— 只挑带哈希的 `assets/` 传就一定漏，
且线上仍是旧图、**没有任何报错**。五张 `dist/` 与 `public/` 逐位 SAME，`-invite.png` 五个名字在入口 JS 里各出现 1 次，海报仍只引用 v4。
五张新图（尺寸 / 字节 / md5）：`modern-invite.png` 256×256 / 11,193 B / `3b27e28f7e0dd9d57a4d02836bb02b36`、
`gem-invite.png` 106×132 / 6,615 B / `93474a1faf6b51c000bbcec96f1d7a43`、`ink-invite.png` 106×132 / 6,732 B / `50cee65aad479a49f5653889d52a468c`、
`shanse-invite.png` 106×132 / 6,972 B / `df63e5c297cc4e9e65fb805be3704e55`、`lg-invite.png` 106×132 / 7,058 B / `753e2df518711f92c0340017fb7d4503`。

**三端基线**（都是当场重跑，不是抄上一轮）：C 端 **232 例 / 20 文件**（207 → 232，+25 全在图标 spec：29 → 54）、
后台 **167 例 / 14 文件**、后端 `--testsuite unit` **269 例 / 1760 断言**（1 条 deprecation 既有）。
**本轮收尾把临时脚本目录整个删掉后又跑了一次 C 端，仍是 232 / 20 全绿** —— 也就是这批的测试不靠任何临时产物活着。

**仍然没验的**：① 生产（一个字节没上，仍并进「第 9 + 第 10 那一批」等你单独点头）；
② **观感**：五套共用一套轮廓 ⇒ 29px 下 `gem` 与 `classic` 只差一点色相，"五套各自像自己的家族"这件事全靠色槽、不靠造型，
好不好看得你或真机看一眼 —— **十张截图留着没删**，都在仓库外面的 `E:\tmp\v45\`（未入库）：
  `p5-{classic,gem,ink,shanse,glass}.png` 是五张整卡放大、`px-{modern,gem,ink,shanse,lg}-invite.png` 是 29px 真实渲染；
  脚本逻辑都在上面这几段，要重拍我原样再跑一遍）；
③ `glass` 描边 2.65:1、`classic` 字线 1.82:1 是**取舍**不是通过；
④ 旧图 `invite-banner-bg.jpg` 仍是零引用孤儿，按第 15 条口径没动。

### 7.46 邀请图标换成「和公告详情首图同一风格」的写实玉佩圆角小卡，五套共用一张（2026-10-16 20:4x~21:2x，本地做完并测过，**未上线**）

**用户口径**：「图标的风格要和公告详情首图一样的风格」。这句话把 §7.43~§7.45 那三轮「和日历一样风格 / 平面的 / 符合功能的」全部**推翻**——
不是补充，是换标准答案：以前比的是**同主题的 calendar**，现在比的是**公告详情那四张写实古物首图**。
三个具体档位当场问定（AskUserQuestion）：
① 物件 = **玉佩 + 红绳**（就是寄售那张首图里那件古物）；② 形式 = **圆角小卡**（自带暖金渐变底，不做透明抠图，等于把首图缩成 28px 缩略图）；
③ 覆盖 = **只出一张，五套共用**（不再每套一张）。旧五张平面请柬的去向也问了一句，答**删掉**（git 历史里留得回来）。

**改动面（五处，全在 C 端 + 一个 qa 脚本）**：
| 文件 | 动作 | 指纹 |
| --- | --- | --- |
| `sinan-art-source/public/images/tab/invite-jade.webp` | **新增**，唯一一张邀请图标 | 256×256 / 9,724 B / `bac1d9799df84de057ef3c8cc70278c5` |
| `sinan-art-source/public/images/tab/{modern,gem,ink,shanse,lg}-invite.png` | **`git rm` 五张** | 原 md5 见 §7.45；当场 `git show HEAD:<path>` 取回一份放在 `E:\tmp\v46\old\`，五张 md5 与 §7.45 记的**逐位相同**（证明工作树没被搞坏） |
| `sinan-art-source/src/stores/iconTheme.js` | 五条 `invite:` 全指向同一个 URL + 文档注释写明这是**例外** | `70328e02baa594fbe7f120421c50304a` |
| `sinan-art-source/tests/invite-icon-theme.spec.js` | 整份重写，**54 → 22 例** | `7fee4b365337186bcc773243bea144f4`（21:4x 复核那两条自我更正后重抄数、重跑仍 22 例全绿；改写过程中曾为 `388783029b11cfd5a100849fd282b9a6`） |
| `qa/make_jade_invite_icon.py` | **新增**出图后处理脚本（裁水印 → 方裁 → LANCZOS 256 → 圆角遮罩 → WebP q90 → 现场量风格数） | 随本批入库 |
**没碰的**：`User.vue`（`9d54f24879742bb8c9ea435ec19e7379`，与 §7.45 逐位相同）、后端、后台、数据库、迁移 —— 不新增 SQL、不改接口。
被删掉的出图脚本 `qa/gen_flat_invite_icon.py`（§7.43 起负责五张平面请柬）一并 `git rm`。

**「和首图一样」翻成能数的三条口径**（这是本轮真正的产出；前两轮那种「对着 calendar 比」的钉法在这里全部作废）：

**⚠️ 一条自我更正（21:4x 复核时抓出来的，原来错在哪写清楚）**：这张表**第一版把两把不同的尺写进了同一列**——
「top8」那一列里首图那四行是**精确 24bit 色**的占比，新图标那一行却是**4bit 量化色**的占比，
于是看起来"图标 0.497 比首图 0.028~0.081 高一个数量级 = 图标更平面"，而下面那句 ⚠ 又把这个差异**归给了抽样分辨率**——
分辨率只是次要因素（实测首图隔 4px 抽样与全像素量的 4bit 色数差 ~20%，top8 几乎不动），**真正的差是量化 vs 精确色**。
更正办法是**一把尺量到底**（`E:\tmp\v46\measure5.py`：全像素逐点、alpha≥8 才算墨迹，4bit 与精确 24bit 两把尺**并排列出来**），
顺手还查出一件同源的事：spec 里那两个风格数原本抄的是**出图脚本压缩前**那组（344 / 0.497），
而 md5 钉的是**压缩后**的成品 ⇒ "数属于这份字节"这句话打了个折；已改成钉**成品字节**那一组（357 / 0.495），口径逐条写在常量旁边。

| 图 | 4bit 色阶层数 | top8（4bit 那把尺） | 精确 24bit 色种数 | top8（精确那把尺） | 均色（全像素） | 平均相对亮度 |
| --- | --- | --- | --- | --- | --- | --- |
| 首图 `announcement-activity` | 255 | 0.591 | 21,133 | 0.080 | (79,36,35) | 0.0615 |
| 首图 `announcement-compose` | 243 | 0.557 | 25,433 | 0.039 | (137,115,85) | 0.2088 |
| 首图 `announcement-consignment` | **414** | 0.468 | 39,684 | 0.027 | (142,93,49) | 0.1746 |
| 首图 `announcement-operation` | 359 | 0.596 | 33,023 | 0.061 | (56,59,76) | 0.0803 |
| **新图标 `invite-jade.webp`** | **357** | **0.495** | **11,038** | **0.031** | **(136,93,47)** | **0.1785** |
| 旧五张平面请柬（对照，证明阈值不是照着成品反推的） | **47~54** | **0.923~0.977** | **564~679** | **0.659~0.852** | 各主题不同 | 0.593~0.755 |

读法（这次是同一把尺下的读数，可以直接横着比）：**写实 = 连续色阶，平面 = 少数纯色块** ——
4bit 那把尺上新图标 **357 落在首图那族 243~414 之内**，精确色那把尺上 **0.031 也落在首图那族 0.027~0.080 之内**；
而旧那五张平面在同一把尺上是 47~54 档 / 0.659~0.852，**两个方向都差一个数量级**。
所以「风格和首图一样」这句话现在是**量出来的、不是形容词**，而且它落在两把尺上而不是只落在一把上。
阈值仍画在「4bit 色数 ≥150、top8 ≤0.65」（离平面那族 47~54 有近 3 倍余量，离写实那族下沿 243 也留着余量）。
色温另量「均色到四张首图均色的欧氏距离」：抄进测试的那组（首图均色按隔 2px 抽样量）是
**寄售 6.2** / 合成 43.4 / 活动 82.9 / 运营 92.9，线画在 ≤64；换成上表那组全像素均色则是 6.3 / 43.9 / 81.5 / 91.6 ——
**两把尺彼此差 ≤1.5，结论一字不变**（最近的都是寄售那张，它就是照着它画的）。
首图那边测试只钉**均色 + md5**（不钉它们的色阶数），因为它们是**后端目录里的成品**、不是 C 端的资产。

**为什么必须走 WebP，以及它欠下的一笔**：同一张 256 方图存 PNG 要 89,980 B、存 WebP q90 只要 9,724 B（**9.2 倍**），
而它在 C 端只占 28px、走的是手机 4G（第 1 条：出口带宽 1Mbps）。代价是 **vitest 这边没有 WebP 解码器**（node 标准库解不开有损 webp，
仓库里海报那张的 spec 也只会解析 webp 宽高、不解析像素）——
所以上面那四个风格数是**离线量出来抄进测试**的（量的对象 = md5 钉住的那份成品字节，口径逐条写在 spec 常量旁边），
测试真正能证的只是「**这些数属于当前这份字节**」（靠 md5 + 字节数两条钉）。
反证 C 档就是这件事的实锤：换成一张同尺寸的平面 webp，**只有 md5 和体积两条红（2 例）**，色阶/top8 那两条根本不会响 ——
**CI 没在重量像素，这条限制写进了测试的文件头注释，不许读成"像素验过了"。**

**反证 10 档（A~J，台账要求逐档重述；脚本这次留在仓库外面、可重跑，见下面那行）**：开局先跑一遍未拆钉的（**0 红 / 22**），红就中止；每档拆完立刻还原并比 8 个文件 md5。
| 档 | 拆什么 | 红几例 | 红在哪（点名） |
| --- | --- | --- | --- |
| A | gem 那一条偷偷指回旧 `gem-invite.png` | 4 | 共用指向 / 共用路径出现 5 次 / 旧字符串不许复活 / 运行时取值 |
| B | 成品文件删掉（改名或漏传） | 4 | 文件在+格式 / md5 钉 / 体积钉 / 运行时文件存在 |
| C | 换一张**平面的**同尺寸 webp | **2** | **只有 md5 + 体积**（见上面那笔欠账） |
| D | 首图标准答案改一个字节 | 1 | 四张首图 md5 防漂钉 |
| E | 渲染盒改 28×40（非方） | 1 | 28px 见方 + contain |
| F | 磨砂底板透明度 0.16→0.30 | 1 | 44px 底板那条 |
| G | 某套 invite 退回 `type:'svg'` | 5 | 指向 / calendar 同分支 / 计数 / 运行时 / custom 回落 |
| H | 拆掉后台 `customIcons` 优先分支 | 1 | 「后台上传仍优先」 |
| I | 旧平面图放回盘上 | 1 | 「五张旧图确实从盘上撤了」 |
| J | 测试里 md5 常量抄错一位 | 1 | md5 钉自己 |
**十档全部只红在点名的用例，跑完 8 个文件 md5 全部 SAME**（`strip-run.log` 留在 `E:\tmp\v46\`）。
**拆钉脚本这次也留在仓库外面了：`E:\tmp\v46\strip-tiers.py`**（未入库，与 `strip-run.log` / `strip-run2.log` 同一目录）——
它开局有那道「未拆钉必须先 0 红」的守卫、每档拆完比 8 个文件 md5，以后要重跑反证直接拿它跑，别照台账手搓一遍（手搓就是 §7.45 那次假绿的来源之一）。
**21:5x 那两条自我更正改完常量与阈值后，十档当场整个重跑了一遍**（`E:\tmp\v46\strip-run2.log`）：
A4 B4 **C2** D1 E1 F1 G5 H1 I1 J1 **一字未变**，8 个文件 md5 两轮都全 SAME，跑完单独复跑图标 spec 仍是 **22 例全绿** ——
也就是说改的是"数从哪来、怎么写的"，**没改钉的结构**，反证结论不需要跟着翻。
两条教训：① 上一版脚本 `restore()` 只覆盖不删除，I 档造出的 `gem-invite.png` 泄漏到 J 档、让 J 多红一条 —— 修法是把还原改成「覆盖 + 把 `RETIRED` 里的名字一律删掉」，
这是「证明拆钉之前别信绿」家族的第二条；② §7.45 记的那次自伤（诊断时 `git checkout --` 掉未提交的成品）本轮改成**要看旧版就 `git show HEAD:<path>` 取到 `E:\tmp\v46\old\`**，工作树一个字节没动。

**浏览器实测（headless Chrome + CDP，390×844 / DPR 2，五套各一遍）—— 这一轮量具自己连翻三次车，全记下来**：
① **第一次五套量出来逐字节相同、而且全是 classic**：`Fetch` 一个请求都没拦到。根因不是拦截规则，是 **`Page.navigate` 到同一个 URL 对已加载的 SPA 是空操作**，
`/api/config` 压根没重发。解法：每次切主题前先 `navigate('about:blank')` 再导航目标页。
② 第二次拦到了，但 `requestPaused` 事件里的 `responseBody` **长度 0** —— 得再调一次 `Fetch.getResponseBody`；
而它返回的是 `{ body, base64Encoded }`，我第一版把那个**布尔当字符串**喂给 `Buffer.from`，当场抛 `ERR_INVALID_ARG_TYPE`。
③ 第三次改写终于生效，我的**自检期望又是错的**（以为 gem 的库存图标叫 `gem-inventory.png`，实际叫 `gem-cube.png`）—— 改成只比**家族前缀**（`modern-` / `gem-` / `ink-` / `shanse-` / `lg-`）。
**规矩（第三次验证）：量具必须自证 —— 拦到几个请求、改写成功几个、页面真的换家族了没有，三样都要断言，缺一条就是假绿。**
最终读数：五套 `paused=1 patched=1`，inventory 前缀分别是 `modern- / gem- / ink- / shanse- / lg-` ⇒ 主题确实切过去了，走的是**真实渲染路径**（不改库、不挡请求）。

**「只出一张」的直接后果，顺手量成了一条事实而不是感受**：五套主题下这张邀请卡**渲染逐位相同** ——
`card-{classic,gem,ink,shanse,glass}.png` 五张 md5 全是 `8e9738794094b11b9f627718c2b450de`，28px 图标裁片五张全是 `36f756e6629914282702089fb826b2ad`，
放大 4 倍的五张全是 `4b2a3cb5bb32c8a47abc4b1f1e2b7d52`。因为卡里**唯一吃主题的就是这个图标**，而它现在五套共用。
这不是 bug，是用户选的口径；但它意味着 §7.43 以来「五套各一张、互不相同」那套验收语言在这一格上**整体失效**（测试里对应改成「共用路径正好出现 5 次」）。
几何与 §7.45 完全一致：`<img>` **29.1094 CSS px** 见方（58 设备像素）、玻璃 tile **45.75**、卡片 **356.75×81.52**、`natural` 256×256、同页资产图标 49.91px。

**对比度换了量法，并且量出一条不合格**：旧口径「墨迹压在深底上糊不糊」在这里没意义 —— 新图自带底，卡的四角就是它的背景。
改成按**出厂同一个圆角遮罩**（256 上 radius=46，缩到 58 就是 10）取"卡内像素"，四角外侧当背板：
背板相对亮度 **0.0278**（海报 + `::before` 滤镜 + tile 都在里面，与 §7.45 量的 0.028 同一档，互相印证）；
卡内均值 **0.1734** ⇒ **2.87:1，低于 WCAG 1.4.11 非文本对比 3:1 的线**；但 p95 **8.06:1**、最亮点 **10.39:1**（玉的高光），最暗处 **1.43:1**（卡的四角深棕融进海报）。
**大白话**：这张小卡不是靠"整块比底亮"跳出来的，是靠**玉的高光 + 外面那圈 tile 白环**跳出来的；卡面本身偏暗（写实图有 25.6% 的像素亮度 <0.05）。
要过 3:1 只需把暖金底提亮一档（出图脚本里加一层白底渐变），**本轮没做**，因为这是观感取舍、按规矩要你先看一眼再说。

**dist 指纹**（`npm run build` 当场重量）：`index.html` `b63173bceb8f6cd62bf723df38588f35`、入口 JS `assets/index-DwBlYmJd.js` `0786c197eac02cd678b707082bc971f2`、
入口 CSS `assets/index-BYu61auI.css` `92033846522c735bade8d59943964f81`。**CSS 那份与 §7.44/§7.45 记的逐位相同**（这批没动样式），
但 **JS 的文件名从 `index-CPN5ux0z.js` 变成 `index-DwBlYmJd.js`**（改了 `iconTheme.js`）——
所以这批**和 §7.45 不一样**：上次是"三个指纹全没变、只有图变了"（第 26 条专门警告过那个坑），这次 JS 指纹本身就变了，
**上线后只看 `index.html` 引的是哪个 JS 就能判断这批上没上**；但图标仍是不带哈希的 `images/tab/invite-jade.webp`，
所以第 25/26 条那条「只挑带哈希的 `assets/` 传就一定漏」**继续有效**。
`dist/images/tab/` 与 `public/images/tab/` 文件名清单一致（各 61 个），`invite-jade.webp` 与源逐位 SAME，五张 `-invite.png` 在 dist 里也不存在，
入口 JS 里 `/images/tab/invite-jade.webp` 出现 **5 次**、`-invite.png` **0 次**，海报仍只引用 `invite-banner-bg-v4.webp` 一处。

**三端基线**（都是当场重跑，不是抄上一轮；21:4x 两条自我更正之后**三端又各重跑了一遍**，数字一字未变）：
C 端 **200 例 / 20 文件**（232 → 200：拆掉旧图标 spec 的 54 例、换成 22 例）、
后台 **167 例 / 14 文件**（没碰）、后端 `--testsuite unit` **269 例 / 1760 断言**（1 条 deprecation 既有）。
反证跑完又单独复跑了一次图标 spec：**22 例全绿**，8 个文件 md5 与拆钉前逐位相同。

**仍然没验的**：① **生产**（一个字节没上，仍并进「第 9 + 第 10 那一批」等你单独点头）；
② **观感**：写实小卡在 28px 到底好不好看、和海报里那块玉是不是"一眼一套"，这条只能你或真机看。
截图都留在仓库外面的 `E:\tmp\v46\`（未入库）：`card-{五套主题}.png` 整卡、`icon28-{五套}.png` 真实 29px、`icon112-{五套}.png` 4 倍放大、
`compare-old-new.png`（上排 §7.45 那五张平面请柬的真实渲染、下排这一张），`raw-jade.png` 是 AI 原图、`old/` 里是从 git 取回的五张旧图；
21:4x 那两条自我更正的**留痕也在这里**：`measure5.py`（一把尺量到底那个重量脚本，六行全表就是它打印的）、
`strip-run2.log`（更正后十档反证整个重跑的日志，与 `strip-run.log` 一份对照）；
③ **卡内均值 2.87:1 没过 3:1** 是本轮**量出来的取舍**，不是通过；
④ 「和首图同色温」目前只有**均色欧氏距离 6.2** 这一条离线量，四张首图彼此色温跨度很大（activity 均色 (78,35,35)，与新图差 82.9），
所以严格说新图标对齐的是**寄售那一张**，不是"四张首图整体"—— 这是用户点名要玉佩的必然结果，但别把它读成"四张都对齐"；
⑤ 旧图 `invite-banner-bg.jpg` 仍是零引用孤儿，按第 15 条口径没动。

### 7.47 邀请图标换回「人形 + 加号」的现成矢量线条、五套共用同一份定义（2026-10-17 00:5x~02:2x，本地做完并测过，**未上线**）

**用户三句口径**（原话，第二三句是 AskUserQuestion 里选的）：
「邀请好友图标换成人形图标带加号吧」／做法 =「用现成的矢量线条（一行接线）」／覆盖 =「仍五套共用一张」。

**一句话结果**：五套主题的 `invite` 全部指回 `AppIcon.vue` 里那条现成的矢量 `invite`（人头 + 肩弧 + 一竖一横的加号），
`public/images/tab/invite-jade.webp` 从仓库撤下，**这一格现在一个字节也不占**。
§7.43~§7.46 那四批图标口径（比 calendar / 每套一张平面信封 / 五张平面中式请柬 / 一张写实玉佩圆角小卡）**整体作废**。

**"一行接线"为什么实际是两行**（这条必须在台账里留着，不然下一个人会以为能只改一行）：
字面上那一行 = 把主题表那五格从 `{type:'image', ...}` 换成 `{type:'svg', icon:'invite'}`。
但 `User.vue` 的 svg 分支写死了 `color="#C00000"` —— 那是 §7.44 之前给浅色卡定的平台深红，
而这块底板现在是深色海报上的一层磨砂白。§7.44 当场量过：深红线条对那块板最好档也只有 **1.00~1.57:1**，
这批又重算了一遍（底板合成分 rgb(42,47,51)）＝ **2.09:1**，两条都低于 WCAG 1.4.11 非文字的 3:1。
**只改那一行的话，用户看到的是一块磨砂灰板里什么都没有**，所以第二行 `#C00000` → `#FFFFFF` 是被迫加的，
我在动手前把这点和「要不要退回、只改一行」一起摆给用户看过。按钮、底板、海报、`AppIcon.vue` 本体都没动。

**改了什么（三处，都留在仓库里）**：
① `src/stores/iconTheme.js`：五处 `invite:` 统一成 `{ type: 'svg', icon: 'invite' }`，文件头那段注释按新口径重写
（写明"五套共用**同一份定义**、一个字节也不占"，并点名 §7.43~§7.46 作废）。md5 `8fd24ac949d2234acbce2d8647e99384`。
② `src/views/User.vue`：svg 分支 `color="#C00000"` → `"#FFFFFF"`，外加两行注释（一行说明位图分支只留给后台自定义上传口，
一行是「不许退回 `#C00000`」的警告 + 指向 §7.44 的实测数）。md5 `6815eb1208e3d466530c9c8fd3fb88e9`。
③ `public/images/tab/invite-jade.webp` `git rm`（256×256 / 9,724 B / md5 `bac1d9799df84de057ef3c8cc70278c5`）。
字节副本留在仓库外 `E:\tmp\v47\retired\invite-jade.webp`，出图脚本 `qa/make_jade_invite_icon.py` 顶部加了**停用横幅**
（写明成品字节在哪、要改回位图得能重出同一张、别把它当"当前实现"读）—— 这是"改口径不删证据"的老规矩。
`src/components/AppIcon.vue` **一个字节没动**（md5 `a802ff2194b47aedfdf3361a9927f3e6`）；`invite` 那条形在第 62 行：
`<circle cx="8" cy="8" r="3"/><path d="M2 20c.5-3.3 3.2-5.5 6-5.5s5.5 2.2 6 5.5"/><path d="M16 7v6M13 10h6"/>`
—— 也就是说用户这次要的图标，是四批之前那个默认图标本体。

**生产上有没有那张 webp 要清理？没有**（这条省掉了发版清单的一步）：`curl -sI http://<IP>/images/tab/invite-jade.webp`
返回的是 `200 text/html`（这台服务器对缺失静态路径走 SPA 兜底），`content_type` 一判就是没这个文件 ——
那张图从没上过线，只在本地 dist 里待过。生产上那五张 `<前缀>-invite.png` 仍是零引用孤儿，按第 15 条口径**还是没删**（要单独点头）。

**测到的（都是当场量的，抄自 `E:\tmp\v47\` 的证据文件）**：

对比度（`measure_contrast.py` + `contrast-table.txt`，五套逐位相同，因为是同一份定义）：

| 项 | 实测 | 判 |
| --- | --- | --- |
| 底板合成分 | `rgb(42, 47, 51)` | 由海报 + `#ffffff29` 磨砂层合成，CDP 取的 |
| 白线实色 vs 底板 | **13.52:1** | 过 WCAG 1.4.11 的 3:1，余量四倍多 |
| 深红 `#C00000` vs 底板 | **2.09:1** | 控制组：证明"只改一行"确实看不见，阈值也真拦得住 |
| 抗锯齿 fringe 中位 vs 底板 | 1.25:1 | **不计入判据**（WCAG 判的是形状本身的可辨识度，不是边缘过渡像素） |
| 墨迹量 | 637 px / 覆盖率 7.5%，fringe 只有 24 px | 线条画，不是色块 |
| 质心偏移 | [-0.052, -0.003] | 基本居中；加号在右、整体略偏左是头部造成的 |

几何（`cdp47-geometry.json`、`cdp47vw.js` + `vw.json`）：盒子 **28.00 CSS px**、线宽 **2.333 CSS px**（viewBox 里 2 单位 × 28/24）、
字形包络 `[2,5,17,15]`（24 单位制）= **19.83 × 17.50 CSS px**。
CI 里那个迷你 path 解释器（`parsePoints` + `glyphExtents`，支持 M/L/H/V/C 及小写相对形式）算出的包络
与浏览器 `getBBox()` **逐位一致** —— 这是这批新加的交叉验证：解释器**遇到不认识的指令就抛异常**，
不静默跳过（跳过的话下一条 `M` 会被当成上一条的延续坐标，包络就"假绿"了）。

**一条这批才看见的机制：px→vw 的不对称**。SCSS 里写的 28px 会被 postcss 折成 vw（跟着屏宽缩放），
但 `AppIcon` 的 size 是**行内 style**，不参与转换 ⇒ 图标恒定 28.00 CSS px，而底板跟着屏宽长：

| 视口 | 底板 | 图标 | 图标/底板 | 每边留白 | 溢出 |
| --- | --- | --- | --- | --- | --- |
| 320 | 37.53 | 28.00 | 0.746 | 4.77 | 无 |
| 375 | 43.98 | 28.00 | 0.637 | 7.99 | 无 |
| 390 | 45.75 | 28.00 | 0.612 | 8.88 | 无 |
| 430 | 50.45 | 28.00 | 0.555 | 11.23 | 无 |

上一版位图走 `__img` 那条 SCSS，会被放大到 29.11 px 跟着卡走；这次改矢量之后**小屏相对更满、大屏相对更小**。
没有溢出、没有裁切，但这是个**观感取舍，我没有擅自修**（修法是让那条 SCSS 尺寸规则也挂到 svg 上，一处），等你或真机看完再定。

**测试**：`tests/invite-icon-theme.spec.js` 整个重写，**25 例 / 5 组**（9 + 3 + 5 + 5 + 3），md5 `577aec021cecb4d6ebdf5496fa284061`。
① 组「五套共用同一份矢量定义」：五套各钉一条 `invite` = `{type:'svg', icon:'invite'}`、"AppIcon 里 `invite` 只出现一次"（共用的机器证明）、
分支差异是**有意的**（同主题 `calendar` 仍走 image —— §7.46 那条"和 calendar 同分支"的旧钉**翻掉了**）、运行时 `getFeatureIcon('invite')`、后台自定义上传优先且清空后回落到矢量。
② 组「三代旧位图不许复活」：主题表代码里 `invite-jade` / `<前缀>-invite.png` **0 次**、盘上文件名含 invite 的位图 **0 个**、位图分支本身必须留着（后台那个上传口靠它）。
③ 组「形状本体」：path 串逐字节钉 + 人形自证（一个头 `circle r=3` + 一条带 `c` 曲线的肩）+ 加号自证（一竖一横、等长、互相穿过中点 ⇒ 是 `+`，不是 `×` 也不是 `−`）+ 线条自证（`fill=none`／`stroke=currentColor`／`stroke-width=2`）+ 画布足迹 == CDP `getBBox`。
④ 组「看得境」：颜色钉 `#FFFFFF` 并写明不许退 `#C00000`、底板那三行 CSS 一起钉（它们变了抄进来的背景合成分就作废，这条是"背景色可追溯"的机关）、13.52:1 ≥ 3:1、内置反证 2.09:1 < 3、线宽守在 2~3 px。
⑤ 组「实测留痕」：`:size="28"` 与它是行内 style、五套那一格逐位相同（五张 CDP 截图 md5 一致，且主题确实切过 —— 用 `inventory` 前缀五套不同来证明）。
**和 §7.46 相反的一点**：这批的对比度公式（WCAG 相对亮度）**跑在 CI 里现算**，只有底板那个合成分 `rgb(42,47,51)` 是抄自 CDP；
因为这次是矢量，形状和颜色都能在 CI 里解析，不像位图那批"成品是 webp、CI 里没解码器"。
④ 组里那三行 CSS 的钉就是为了让抄进来的那个数**可追溯**，改样式必须连带重抄，不然红。
§7.45/§7.46 的实测数（webp 9,724 B / 4bit 357 档 / 均色 (136,93,47)；五张平面 PNG top8 0.923~0.977）**留在测试里当注释**，
用途写明了：证明后来那些阈值是**现场量出来的、不是反推出来的**。

四张公告出厂首图的 md5 钉**不是删掉了，是搬走了**：§7.47 把图标的"和首图同色温"比较拆掉之后，那四条字节钉在图标 spec 里没了主人，
平移到 `tests/announcement-cover.spec.js`（md5 `d28ef2dcfc8638aa329033263217fccf`），注释写明是搬来的。测试注释里也点破了：
钉的是**代码引用**，不是"文档里不许提到旧文件名" —— 为此 spec 里加了 `storeCode`（把 `/* */` 和 `//` 注释剥掉再扫），
否则新的 `iconTheme.js` 头注释合法地提到 `invite-jade` 就会把这条钉撞红（第一轮就是这么红的）。
另有一条计数上的坑记一下：`storeCode.split('invite')` 这种数法会误判（`invite` 在 `getFeatureIcon`、`customIcons` 里也出现），
改成数 `icon:\s*'invite'` 的形式 = 5 才对应"五套共用同一个图标名"。

**反证 14 档 A~N，14/14 合格**（脚本与日志在仓库外 `E:\tmp\v47\strip-tiers47.py` / `strip47-run.log`，可重跑）：
基线未拆钉 **0 红 / 207**，每档跑完 8 个文件 md5 全部 SAME。逐档：
A gem 偷偷指回位图 红 6／B 加号一横改短（`h6`→`h4`）红 3／C 加号改成斜叉 红 2／D 人头多一个 circle（成了两个头）红 3／
**E 颜色退回 `#C00000`** 红 1（这批内置的"退回错误形状"那一档）／F 磨砂底板 α 0.16→0.30 红 1／
G 海报改一个字节（底板合成色不再等于抄进来的那个）红 1／
H 顺手把同主题 `calendar` 也改成矢量（**越范围**）红 1／I 旧位图放回盘上 红 2／
**J glass 那一行整个删掉 红 5** —— 这一档最值得记：`getFeatureIcon` 在主题缺键时会**回落到 `classic` 的那份**，
运行时"五套拿到的都一样"照样成立，所以只钉运行时的话这档会假绿，**只有源码结构钉拦得住**；
K 抄进来的对比度常量改错一位 红 1／L `AppIcon` 全局线宽 2→1（28px 盒子里糊成灰）红 2／M `:size` 28→40 红 1／
N 出厂首图被换（搬过来的那条钉要拦住）红 1。

**三端基线**（2026-10-17 02:1x 三端各当场重跑）：C 端 **207 例 / 20 文件**（200 → 207 = 图标 spec 22→25 加 3 例、公告 spec 收进搬来的 4 例）、
后台 **167 例 / 14 文件**（没碰）、后端 `--testsuite unit` **269 例 / 1760 断言**（没碰，1 条 deprecation 既有）。

**dist 指纹与发版 marker**（每条都在现在的 dist 上跑过，`E:\sinan\sinan-art-source\dist`）：
入口 JS `assets/index-CxjB609F.js` `eb96ea211f8d4440d2e0abcb4d9ab0a0`、`index.html` `7f306fac59d5c59da888cf7eb2677109`、
入口 CSS `assets/index-BcRoMzhL.css` `8dfdeab87beb88caf53ae47b66df5a98`。
**CSS 的 md5 这批变了，但一条规则文本都没改** —— 原因是 Vue 生产构建的 scoped id = `getHash(文件路径 + 整个 SFC 源码)`
（`node_modules/@vitejs/plugin-vue/dist/index.mjs` 第 90 行），改了 `User.vue` 模板里那两行注释和 `color` 就换了 `data-v-…`，
每条选择器都跟着换 ⇒ 内容哈希必然变。证据：把上一版 `User.vue`（HEAD 那份）单独重跑一次到临时目录，
两份 CSS 的 `data-v-[0-9a-f]{8}` 归一后 **236,957 B 逐位相同**。
**所以"CSS md5 变了"不能再当成"样式也变了"的证据**，第 21 条 ⑩ 的分诊改用下面这些 marker（已同步改写）。
（顺带一条量具上的坑：`git show HEAD:…` 提出的是仓库里的 LF 版本，而工作树文件是 CRLF —— 那次单文件重跑用的是 LF 那份，
所以它的绝对 md5 不等于 §7.46 当年那份。这不影响结论，因为比的是**归一后的文本相等**，不是绝对 md5；
要看旧字节请从工作树备份走，别把 `git show` 的输出当成逐位等价的工作树文件。）

**留证与清理（这批）**：`E:\tmp\v47\css-proof\` 留下那两份入口 CSS 和各自的 `data-v` 归一化副本
（`index-BcRoMzhL.css` = 本批产物 `8dfdeab8…`、`index-wSNEYHFO.css` = 用 HEAD 那份 `User.vue` 单独重跑出来的 `593d2777…`），
谁要复核"CSS md5 变了但规则文本没变"就把那两个 `.norm` 再 `diff -q` 一次；
同目录里那次重跑的整个 dist（5.4M）、Chrome 临时 profile（34M）和两份 `User.vue` 中转副本**已删**（可重建，且留着只会让人误当成一份"当前产物"）。
其余留着的：`retired/invite-jade.webp`（三代口径之一的字节）、`strip/backup/`（反证基线字节）、
`strip-tiers47.py` + `strip47-run.log`（14 档可重跑）、`cdp47*.js/json/log`、`measure_contrast.py` + `contrast.json` + `contrast-table.txt` + `vw.json`、
`card-*.png` / `plate-*.png` 那 **30 张**截图（5 张整卡 + 五套 × {底板原尺寸 / `-zoom` / `-noink` / `-red` / `-again`} = 25 张）。**上一批 `E:\tmp\v46\` 那份没动**，`compare-old-new.png` 现在只能当"§7.45 vs §7.46"的历史对照，不含这一批。

marker（在 dist 上实测的命中数）：
`grep -c 'invite",size:28,color:"#FFFFFF"' dist/assets/index-*.js` = **1**（这批最新的那条接线）；
`grep -c -F 'M16 7v6M13 10h6' dist/assets/index-*.js` = **1**（加号那条形本体，比文件名 marker 更硬）；
`grep -c invite-jade dist/assets/index-*.js` = **0**、`grep -c -e "-invite.png" dist/assets/index-*.js` = **0**（三代旧口径都没混进来）；
`dist/images/tab/` **60 个**文件，与 `public/images/tab/` 一致，两边都没有任何 invite 位图；
海报 `invite-banner-bg-v4.webp` 在 CSS 里仍 1 次；按钮 `#2e2e349e` 1 次、底板 `#ffffff29` 1 次。

**两条自我更正（原来错在哪，都已在第 21 条 ⑩ 落新文）**：
① 第 21 条 ⑩ 里原来那句「`grep -o "rgba(46,46,52,[^)]*)" dist/assets/index-*.css` 应是 `.62`」**在当前 dist 上命中 0**。
原来错在哪要说准：**不是我不知道压缩器会把 rgba 折成 hex8** —— §7.44 自己的指纹段就写着
「`.invite-banner__btn` 在 dist 里编译成 `background:#2e2e349e`，而全 CSS `grep -c "46,46,52"` 是 **0**」；
同一批台账里"量到的事实"和"留给运维的判据"分别落在两处，我改了前者**没回头扫后者**。
所以真正的错因是**同一批台账内部的矛盾**，不是认知缺失。正确写法：`grep -c -F "#2e2e349e" dist/assets/index-*.css` 应为 1。
这条从 §7.44 起躺了三轮没人撞过，是因为它只在"线上没生效要分诊"时才会被拿去用 —— 现在撞上了就当场改，
并且**把这批所有 marker 都在现 dist 上跑了一遍**（上面那张 marker 表每条都带实测命中数）。
② 原来那句「图标位如果还是个单色线条小人 = 那五个 `invite:` 行没覆盖上去（旧 dist 的 JS）」现在**方向反了**：
线条小人是本批的**合格态**。判据换成 JS 里那条加号 path 的命中数（上面 marker 第二条）。

**仍然没验的**：① **生产**（一个字节没上，仍并进「第 9 + 第 10 那一批」等你单独点头）；
② **观感**：小人 + 加号在深色海报卡上好不好看、以及"图标不跟着卡缩放"在大屏上显不显小 —— 只能你或真机看
（截图在仓库外 `E:\tmp\v47\`：`card-{五套主题}.png` 整卡、`plate-{主题}.png` 底板真实尺寸、`plate-{主题}-zoom.png` 4 倍放大、
`plate-{主题}-noink.png` 挖掉墨迹的底板、`plate-{主题}-red.png` 深红控制组、`plate-{主题}-again.png` 还原复拍（逐位 SAME，见下））；
③ 后台切换功能图标主题时那一格的过渡没量（只量到"五套逐位一致"这个静息态）；
④ `invite-banner-bg.jpg` 仍是零引用孤儿，五张 `<前缀>-invite.png` 生产上也还挂着，都按第 15 条口径没动。

**两条方法论教训**：
① **CDP 里"改回原样"必须写显式值，`style.color = ''` 不是还原**。
第一轮的量法是在页面上把描边改成别的颜色再"清空"，然后要求复拍截图与首拍逐位相同 —— 红了 678 px、最大差 204。
原因：那个颜色是 Vue `:style="{ color }"` 绑上去的，清空 DOM 上的 style 之后 **Vue 不会重写**，截图里留着的是被我改坏的值。
第二轮改成显式写回 `#FFFFFF` 并打印 `getComputedStyle` 的 stroke 复核，五张 `plate-*.png` 与 `plate-*-again.png` 全部 SAME。
**规矩：往 Vue 绑定的元素上打补丁做实验，还原要写回它自己那份值，并当场把 computed 打出来；只写 `''` 会让"还原"这一步本身造假。**
② **一批台账是多个段落，改了一个事实要扫全部提到它的地方**（尤其"给运维的判据"这种不会被测试执行到的文本）。
这批撞出来的那条错（rgba 折成 hex8）在**同一批**的指纹段里已经写对了，只是第 21 条那张分诊表没跟着改 ——
测试会红的东西我有机关心，**测试管不到的散文式判据只能靠扫**。
**规矩：凡是留给"上线后当场看"的 grep 判据，必须在当批的 dist 上真跑一遍并把命中数写进台账**（这批已经这么做，上面 marker 那段每条都带实测数）；
写自我更正时也要把"错因"写到**能被复查的粒度**（"我按源码写法 grep"是表象，"同一批两处台账没对齐"才是错因）。

### 7.48 文物展览区那块头图换成「深暗底青铜方鼎」，标题副标跟着翻成白字（2026-10-17 11:0x~11:5x，本地做完并测过，**未上线**）

**用户口径**（原话一句 + AskUserQuestion 里点的三项）：
「文物展览区的顶部背景图再换一张」／主体 =「青铜重器：鼎 / 簋为主角」／明暗 =「深暗底 + 白字」／节奏 =「一次出 3 张候选给你挑」／定图 =「A 方鼎（推荐）」。

**一句话结果**：`/mall` 顶部 `.exhibit-header` 的底图从 2026-09-25 那张浅米黄青绿山水（`exhibit-header-ancient.jpg`，2560×1440 / **523,525 B**）
换成深暗底方鼎（`exhibit-header-bronze-ding-v2.webp`，1280×490 / **52,972 B** —— **比旧图小一个数量级**，这是列表页首屏），
标题与副标从深棕翻成白。模板、文案、盒子高度、字号、那层压暗渐变**一个都没动**。
**这块格子以前没人管过**：§7.x 从头到尾没碰过展览区头图，全仓库只有 `Mall.vue:94`（HEAD 那份的行号）一处引用，
测试目录里 grep `exhibit|mall|header` 也**一条都没钉过它** ⇒ 这一节是**开荒**，不是翻转旧钉（与 §7.43~§7.47 那五轮邀新卡性质不同）。

**「换图」为什么必然连着「换字色」——这不是审美，是量出来的**：
旧标题 `#2c1d0f` 压在新图上，真截图逐像素数「比背景板（把字藏掉那张）亮的点」= **0 个**，盒内最大亮度差 **0.0145**；
离线那把尺给的是 **1.25:1**。两把尺都指向同一件事：**只换图不改字色，那两行字就消失了**。
所以测试里"新字色"与"旧字色不许回来"两组钉和图本身是**同一件事**，谁单独回退都会红（C/D 两档实测就是各红 2 条）。

**改了什么（四处，都留在仓库里）**：
① `src/views/Mall.vue`（md5 `4bed0367ff45c2473efd37c4ff1110b7`；首版是 `f64744b8…`，后来只把两处注释里的对比度
   口径从「实测 20.37 / 14.52」补成「离线 20.37 / 真渲染四档最坏 12.96」，一条样式字节没动）：`url(...)` 换新文件名（前面那层
`linear-gradient(180deg, rgba(0,0,0,.08), rgba(0,0,0,.55))` **是旧图本来就带的，我留下了** —— 它是下面那两个对比度数的一部分）；
`&__title` `#2c1d0f`→`#FFFFFF`、text-shadow 从暖白辉光 `rgba(255,245,215,.6)` 换成压深黑 `rgba(0,0,0,.65)`；
`&__sub` `#5a3a1f`→`rgba(255,255,255,.85)`、同样的辉光→黑投影。**旧那两个暖白投影是给"浅底深字"用的**，在暗底上它会糊在笔画边上。
② 新增 `public/images/exhibits/exhibit-header-bronze-ding-v2.webp`（1280×490 / 52,972 B / md5 `d09524b2440f316d4553bc7a84b33df2`）。
③ 新增 `qa/make_exhibit_header_bronze.py`（md5 `ffb51beba8c58601cf7c350069b0e9ba`）：裁行 + 缩放 + WebP q82 + 体检数 + 离线对比度 + **量具自证**（同一段代码里顺手量旧深棕，量不到"很差"就当场报错）。
④ 新增 `tests/exhibit-header.spec.js`（md5 `a4bc3884b52de7bce44a469d333a6e47`；首版是 `48cd4580ccf356cc37c784ee92744710`，
   后来只把 `CONTRAST.cdp` 那四个数从"抄串档"改成"四档最坏"，见下面那张表的订正说明）：18 例 / 5 组。
**旧图故意没删**：`exhibit-header-ancient.jpg` 仍在 `public/images/exhibits/`（生产上也仍在被旧 dist 引用，回滚时正好用得到）。
删它是不可逆动作，按第二节那条规矩**等单独点头**，本轮只把引用拔掉。

**三张候选是先量过再给你挑的**（把每张按同一几何铺进文字区取最坏点；这张表**只用于横向比较，不当合规数用** —— 它的取样带和下面两套不一样）：

| 候选 | 标题最坏 | 副标最坏 | 结论 |
| --- | --- | --- | --- |
| A 方鼎（选定） | 13.25:1 | 19.21:1 | 文字区落在左半暗部，右端那只鼎不侵占到 x<205 |
| B 鼎 + 簋 | **1.89:1** | 5.18:1 | **当场淘汰**：主体太靠左，白字压在鼎身亮部上不过 3:1 |
| C 雾中重器 | 7.80:1 | 11.69:1 | 合格，但主体偏中、亮部横贯整幅 |

⚠ **这一档的脚本没落盘**（当时是一次性内联跑的，`E:\tmp\v48\` 里只有它打印出来的 `measure.txt` 那三行 + 三张原图 + 三张预览，
`find /e/tmp -name "measure*.py"` 里没有本批那把尺）。所以这三个数**可核对、不可重跑** ——
它只用来回答"三张里挑哪张"，**合规判定用的是下面那两套**（离线脚本已入库、CDP 脚本已留档），别把这张表当验收数引用。

**"海报感"这次不用你想象**：`E:\tmp\v48\prev\prev-*.png` 是三张各按真实 CSS（同一层渐变、同一行盒、同字号）铺上「文物展览区 / 司南 · 数字文物典藏」的预览。
B 那张的 1.89:1 就是在这张预览上量出来的 —— 换句话说**它的缺陷在落位之前就被拦下了**，不是等做完才发现。

**字节流水线**：AI 原图 2560×1080 / 3,433,475 B / md5 `36b6ab4aa946f2f4b9827aefe7b0abd8`（未入库，PNG 预览在仓库外）；
右下角那圈「Qoder AI生成」角标量到带 **y=1052..1068（17 行）**，裁行取 **40..1020**（离带上沿留 32 行），缩到 1280×490、WebP q82 → 52,972 B。
**这里有一条自我更正**：第一版水印检测数的是"接近纯白的像素个数"，在一张**已经裁干净**的结果上仍报 **184 个** ⇒ 误报。
原来错在哪：**锈蚀与石头的去饱和高光本身就近白**，"近白像素多"和"有角标"不是一回事。
改成**按行统计**（`max>185 且 max-min<22` 的像素，某行 ≥6 个才算一条带）并在脚本里 assert「裁切上沿必须 ≤ 水印带顶 − 15」，
它现在既不会误报也不会漏报（上表那 17 行就是它报的）。

**测到的：两套独立实现各量一遍**（口径不同，绝对值不该互相对表，只比结论）

*离线设计值*（`qa/make_exhibit_header_bronze.py`，按 390×150 盒 + 28px/13px 字 + 那层渐变合成）：
标题纯白 **20.37:1** @(159,80)、副标 85% 白 **14.52:1** @(138,97)；控制组旧深棕 **1.25:1**。

*真渲染*（headless Chrome + CDP，`E:\tmp\v48\cdp48.js` → `contrast48.txt`；每张拍三遍：正常 / 把两行字 `visibility:hidden` 当背景板 / 退回深棕控制组）：

| 视口 | 盒子 CSS px | 标题实测字号 | 标题 底最坏 / **笔画核心** | 副标 底最坏 / **笔画核心** | 判 |
| --- | --- | --- | --- | --- | --- |
| 360×780 / dpr3 | 360×144 | 26.88px | 13.67 / **13.40** | 14.07 / **11.49** | 过 |
| 390×844 / dpr3 | 390×156 | 29.12px | 13.81 / **13.02** | 14.06 / **11.64** | 过 |
| 430×932 / dpr2 | 430×172 | 32.11px | 13.81 / **12.96** | 14.06 / **11.71** | 过 |
| 768×1024 / dpr2 | 768×307.19 | 57.34px | 19.91 / **16.50** | 14.09 / **11.63** | 过 |

门槛：标题 28px 粗体按 WCAG 属**大字号** ≥3:1；副标 13px 是**正文** ≥4.5:1。四档 × 两行 = 8 个数全过，
最低的两个是 **12.96:1**（标题，出在 430 档）与 **11.49:1**（副标，出在 360 档），余量三倍和两倍半。
控制组四档都是「比背景板亮的点 **0 个**」⇒ 量具能分辨"看不见"这一档，阈值不是摆设。

> **原来错在哪（2026-10-17 当天自查出来就改了，记下来是因为这类错很省事就躲过去了）**：
> 第一版 spec 里 `CONTRAST.cdp` 那四个数**抄串了档** —— `titlePlate 13.81 / titleInk 13.02` 是 390 那一行，
> 可 `subInk 11.49` 却是 360 那一行的数（390 行是 11.64），而 `it` 的标题被我写成了「真渲染底 13.81 / 笔画核心 13.02」，
> 读起来像"这一档的两个数"。后果不是红线放宽，而是**声称的口径和实际钉的数不是一回事**：
> 下一个人按 390 那行重量会以为副标核心是 11.64，和常量对不上，反而怀疑图变了。
> 现在改成**每个指标取四档最坏**（`titlePlate 13.67 / titleInk 12.96 / subPlate 14.06 / subInk 11.49`），
> 并把四档原始读数整张贴在 spec 的常量注释里（`exhibit-header.spec.js` **第 64~70 行**，按本批入库那版 `db40884` 量的；
> 行号会随改动漂，找不到就搜「量具读数」这四个字）—— 钉最坏值才是"任何视口都不许掉"的意思。
> 改完 18 例重跑仍全绿，13 档反证**整批重跑，红点位置与条数一字未变**，I 档那一步的字面量随新数从 `13.02` 换成 `12.96`；
> 之后 `Mall.vue` 注释又补了一句口径 ⇒ 同一套 18 例 + 13 档**再跑第三遍**，仍然一字未变，三个文件 md5 跑前跑后全 SAME。

**两套数为什么差这么多**（这条必须写明，否则下一个人会以为其中一把尺坏了）：postcss 把 SCSS 里的 px 全折成 vw（基准 375），
390 视口下盒子实际是 **156 高、字 29.12px**，而离线那把尺按 390×150 / 28px 建模。
顺带从产物 CSS 里读出一条**没人写下来的细节**：`.exhibit-header__sub` 的 `letter-spacing:1px` 在 dist 里**仍是 1px**、没折成 vw
（`postcss.config.js` 的 `minPixelValue: 1` ⇒ ≤1px 不转），所以副标的字距**不随视口长**，而字号随。text-shadow 那个 `1px` 偏移同理。

**文字安全区右界**（从"藏掉字"那张板子上逐列扫出第一列亮区，390 视口 CSS px）：标题行 **x=205.7**、副标行 **x=318.7**。
⇒ 标题现在那五个字的墨迹右端远在这之前；**再加字就会踩进鼎身亮区**，白字掉到门槛以下。
所以 spec 钉了三条文案上界（「标题就是这五个字」/ 标题 ≤6 字 / 副标 ≤22 字）—— **在这块格子上改文案等于改对比度，得重量**，不是随手措辞。

**反证 12 档 + 1 个对照档**（`E:\tmp\v48\strip-tiers48.py`，日志 `strip-tiers48.txt` / `strip-tiers48.json`，可重跑）：
A 换回旧图名 红 **2**／B 换图不改数（同尺寸 q90 重编码，52,972→65,370 B）红 **1**／C 标题退回深棕 红 **2**／D 副标退回棕 红 **2**／
E 投影退回暖白辉光 红 **1**／F 删掉那层压暗渐变 红 **1**／G 盒子高度 150→160 红 **1**／H 标题加一个字 红 **1**／
I 把对比度常量调到门槛下（12.96→2.90）红 **1**／J 把控制组常量造假（0→500）红 **1**／K 选择器改名（钉的是空气）红 **5**／L md5 常量对不上 红 **1**。
**对照档（什么都没动）红 0**，跑完 `Mall.vue` / spec / webp 三个文件 md5 **全部 SAME**。
（上面把常量改成"四档最坏"之后，这 13 档**整批重跑过一次**，红点位置与条数一字未变：A2 B1 C2 D2 E1 F1 G1 H1 I1 J1 K5 L1、对照 0。）
B 与 L 两条合起来才是这条钉的完整含义：**换图不改数会红，改数不改图也会红**（§7.46 那个限制在新格子上的复用）。

**这里有一条自我更正，价值比反证本身大**（第四节 23 条②那家族的**第四次复发**）：
第一版这个驱动脚本 **12 档全报"红 0 条"**，而 vitest 的 exit code 明明是 **1** —— 我差点把它读成"这 18 条钉全是废的"。
原来错在哪：**非 TTY 下 vitest 仍然给输出上色**，红行的真实字节是
`\x1b[31m×\x1b[39m tests/exhibit-header.spec.js\x1b[2m > \x1b[22m组名\x1b[2m > \x1b[22m用例名\x1b[32m 6\x1b[2mms`，
我那条正则要的是 `× ` 和 ` > `，**都被转义符打断** ⇒ 一条也匹配不到，于是"抓到 0 条红"和"没有红"被我当成了同一件事。
修法两条：① 抓之前先 `re.sub(r"\x1b\[[0-9;]*m", "", out)` 把 ANSI 剥掉；
② **"没抓到红用例名"与"没有红用例"是两回事** —— 现在 `returncode != 0` 却抓不到名字一律判"没跑起来"，
并加了一个**什么都没动的对照档**，基线不干净直接退出，不进反证循环。
（同一批还有第二条小坑：F 档第一版"没改动任何字节"—— 工作树是 **CRLF**，我按 LF 写的匹配串。
判"这一档改没改"这件事，文本档比字符串相等、二进制档比 md5 不等，两种都必须在跑测试**之前**自证。）

**三端基线**（2026-10-17 11:4x 三端各当场重跑，不是抄上一轮）：C 端 **225 例 / 21 文件**（207 → 225 = **全部净增来自新增那一份 `exhibit-header.spec.js` 的 18 例**，其余用例一条没动）、
后台 **167 例 / 14 文件**（没碰）、后端 `--testsuite unit` **269 例 / 1760 断言**（没碰，1 条 deprecation 既有）。
**当天两次返工之后（常量订正 + 注释改写）只重跑了 C 端那一遍**（12:0x 当场 225 例全绿，返工前后各一遍）——
后台与后端**本轮连源文件都没碰**（`git status` 里 `sinan-admin/`、`sinan-nft-backend/` 零改动），所以 11:4x 那两组数仍然成立；
这条区分写明白，是免得下一个人把"三端基线"读成"三端在最终字节上各跑过一遍"。

**生产现值（当场只读复量，一个字节没写）**：
`curl http://<IP>/images/exhibits/exhibit-header-ancient.jpg` → **`200 image/jpeg` 523,525 B**（还在服役，与本地那份逐字节同尺寸）；
`curl …/exhibit-header-bronze-ding-v2.webp` → **`200 text/html` 1010 B**（SPA 兜底 ⇒ **文件不存在**）。
线上 H5 入口 JS 现在仍是 **`index-TARjuzYk.js`**（第 8 次发版那份，§7.36 起这批都没上）。
⇒ **这一批确实要传一张静态图**，与第 28 条那句「没有图要传」**正好相反**，别照抄那一批的步骤（第四节 23 条③那条判据这批又用上一次：状态码不算证据，`content_type` 才算）。

**dist 指纹**（`E:\sinan\sinan-art-source\dist`，本批产物；上一批 §7.47 记的是 `7f306fac` / `index-CxjB609F.js` `eb96ea21` / `index-BcRoMzhL.css` `8dfdeab8`）：
`index.html` `777728c0a3706a0e6787aac2a99e07e5`、入口 JS `assets/index-Zr733Myd.js` `b123d1c4e4898ad1ad436d918af44a68`、
入口 CSS `assets/index-Bw6BQyUA.css` `273f5ae3c2c685c19f95070c53de2673`、`dist/images/exhibits/exhibit-header-bronze-ding-v2.webp` `d09524b2…`（与 public 那份逐位相同）。
（这三个数在本批**当天换过一次**：注释里补了一句对比度口径 ⇒ 整个 dist 重新构建，JS/CSS 文件名哈希全变，
 而**规则本身没变** —— 新产物里那三条与上一版记在台账里的引文逐项对得上，只有 `[data-v-…]` 那串和文件名不同
 （对不上的是哈希，不是样式）。这就是下面那条「`data-v` 不许当 marker」的现场版，也是 §7.47 那条「文件名变了 ≠ 样式变了」的同一件事。）
**产物里那三条规则原文**（这才是"上线后当场看"该 grep 的东西，别照 SCSS 写法猜产物）：
`.exhibit-header[data-v-506a8be8]{position:relative;height:40vw;overflow:hidden;background:linear-gradient(180deg,#00000014,#0000008c),url(/images/exhibits/exhibit-header-bronze-ding-v2.webp) center/cover no-repeat;display:flex;flex-direction:column;justify-content:center;padding:0 4.26667vw}`、
`.exhibit-header__title[data-v-506a8be8]{…font-size:7.46667vw;font-weight:700;color:#fff;margin:0;text-shadow:0 1px 1.6vw rgba(0,0,0,.65);letter-spacing:.53333vw}`、
`.exhibit-header__sub[data-v-506a8be8]{…margin:1.6vw 0 0;font-size:3.46667vw;color:#ffffffd9;letter-spacing:1px…text-shadow:0 1px 1.06667vw rgba(0,0,0,.55)}`。
**marker（每条都在现 dist 上实测过命中数；注释改写触发重建后**又复量了一遍，五个数一个没变**）**：
`grep -c -F "exhibit-header-bronze-ding-v2.webp" dist/assets/index-*.css` = **1**；
`grep -rl -F "exhibit-header-ancient" dist/assets/ dist/index.html` = **0 个文件**（旧图名在产物里零引用，但**文件本身还在** `dist/images/exhibits/` 里，共 2 个文件）；
压暗渐变折成 hex8 `#00000014` / `#0000008c`（0.08→`14`、0.55→`8c`）—— **`grep "rgba(0,0,0,0.55)"` 在 dist 永远命中 0**，就是 §7.47 那条更正的同一条坑，这批再撞一次；
 ⚠ 但同一批里 `grep -c -F "rgba(0,0,0,.55)"`（**点零、无前导 0**那种写法）命中 **1** —— 压缩器只把**渐变的色标**折成 hex8，
 `text-shadow` 那层的 rgba **原样保留**。所以"rgba 在 dist 一定搜不到"这句话本身是过头的，**判据要写清是哪个属性、哪种写法**；
副标 85% 白折成 `#ffffffd9`（0.85→`d9`）。
⚠️ 那个 `[data-v-…]`（本批当前值 `506a8be8`，首版是 `87875503`）里的哈希**不许当 marker 用** —— 改 SFC 任何一处（连注释）都会换（§7.47 已记过并给了归一化证明，本批当天又现场撞了一次）。

**留证（都在仓库外 `E:\tmp\v48\`，未入库）**：
`cand/cand-{A-方鼎,B-鼎簋,C-雾中}.png` 三张候选原图（**文件名是中文**）、`prev/prev-{A-ding,B-dinggui,C-mist}.png` 三张按真实 CSS 铺过字的预览
（prev 目录里同一张图有 ASCII 与中文**两份命名**，共 6 个文件）、`measure.txt` + `measure.log` 那张横向对照表；
`header-{360x780-dsf3,390x844-dsf3,430x932-dsf2,768x1024-dsf2}.png` 四档整块头图，
同名 `-notext`（藏掉两行字那张，对比度的背景板）、`-brown`（深棕控制组）、`-again`（还原复拍，四张与首拍**逐位 SAME**）；
`cdp48.js` + `cdp48-geometry.json` + `cdp48-run.log` / `cdp48-run2.log`（几何与 computed 读数）、`contrast48.py` + `contrast48.txt`（两把尺的成品）、
`strip-tiers48.py` + `.txt` + `.json`（12 档 + 对照档可重跑；`.txt` 是**最后一遍**的日志，前两遍的计数被覆盖，但三遍结论一致）、
`run1~4.txt`（出图脚本四轮，含那两条报错的原文）+ `run1.utf8.txt` + `probe-out.txt`、`band-*.png` / `corner2.png` / `br-corner.png`（水印带与裁切位的放大核对图）、
`Mall.vue.keep` = **本批首版 `Mall.vue` 的那一份**（md5 `f64744b8…`）；工作树已被注释改写覆盖，**这个 md5 现在只有这份 keep 能核对**（第四节 27 条那条"成品只有一份副本"的规矩在这轮救了场）。
`chrome-profile/`（Chrome 临时档，可重建，**下一批清理时先删它**）。AI 原图 PNG 没入库，只留了 md5 与尺寸。
（⚠ 这一格首版写的是「`cand/cand-{A-ding,B-dinggui,C-mist}.png`」—— **那是 prev 目录的命名，cand 里其实是中文名**，
 写台账时把两个目录串了；按第 21 条的老规矩，文件名这种"看着无所谓"的字段错了，下一个人 `ls` 找不到就会以为证据没了。）

**仍然没验的**：① **生产**（一个字节没上，仍并进「第 9 + 第 10 那一批」等你单独点头）；
② **观感**：这张暗底方鼎配白字在真机上好不好看、和公告详情那四张写实古物首图是不是"一眼一套" —— 只能你或真机看（上面 `prev-` / `header-` 那批图可以先看）；
③ 头图**底下那排分类 tab 与新底的接缝**没量（本轮只动了头图这一块，接缝处现在是渐变最暗的一段，理论上不刺眼，但没数）；
④ 旧 `exhibit-header-ancient.jpg` 的删除没做（不可逆，等点头；它在 dist 里也还有一份）。

**刻意没做的事**：那层 `0.08 → 0.55` 的压暗渐变**保留**（它是量出来的两个对比度数的一部分，F 档一删就红）；
模板文案、盒子 150px、标题 28px / 副标 13px 全没动 —— 这批的口径只有「换一张图 + 字色跟着换」，
**别把它读成"顺手把头图区域重做了一遍"**。

**一条方法论教训（写进第四节 28 条）**：**换一张底图 = 换掉一整套像素读数**，所以顺序必须是「先量再挑 → 落位 → 重量 → 把数与字节绑死」，
而不是「挑顺眼的换上去再看有没有糊」。本轮三张候选用同一把尺先量，B 那张 1.89:1 就是这么当场淘汰的；
落位后 md5 + 体积 + 对比度常量三样在 spec 里绑成一个环：**换图不改数（B 档）红，改数不改图（L 档）也红**。










1. **带宽 1Mbps**：实测后台主 JS 1.33MB 单下就要 8.6 秒，加 CSS 约 11 秒首屏。建议改「按流量计费 + 峰值 100Mbps」，小站月成本通常几元。
2. **无域名 = 无 HTTPS**：后台登录密码当前明文走公网。要么尽快备案+上证书，要么把 8088 在安全组里锁成仅你的 IP。
3. **短信/支付仍是 mock**：真实网关需后台录入密钥并切 `provider`；注意 mock 渠道在生产（debug=false）拿不到验证码明文，见第 4 节。
4. **ICP 备案**：中国内地节点用域名访问 80/443 必须备案；纯 IP 访问暂无此约束。数字藏品业务的资质合规另需评估。
5. **业务内容（2026-10-14 03:1x 现场只读重量，纠正了本条旧版的「1 条 / 0 条」）**：
   `nft_collectibles` **4 条** —— id=1「大绵羊」`status=onsale`、`market_type=activity`、未上推荐、
   `is_resaleable=1`（寄售开关已由我于 10-11 23:07 打开，为验证新门槛），它现在就在活动市场列表里、卡片显示「暂无寄售」；
   id=2「青色」/ id=3「呃呃呃」/ id=4「单独的」都是 `upcoming`（还没到上架），建的时间分别是 10-10 23:47、10-11 10:37、10-11 22:57。
   想让「大绵羊」从市场消失：后台「藏品管理 → 转赠 / 寄售」列把「售」关掉即可。
   `nft_blind_boxes` **3 条**（id 1/2/3，分别绑上面那三个 `upcoming` 藏品，所以后台「盲盒管理」不是空的——
   7.34 ① 选盲盒时下拉里就是这三张）。自由市场仍是空（`market_type=free` 0 条）。
   `nft_resale_listings` **2 行但全是 `cancelled`**（都是用户 2 挂 id=1，10-11 00:02 挂 10 元、00:25 挂 1111 元，先后自己撤了），
   `status='selling'` 现值 **0 行**、`sold` 0 行 —— 所以**任何藏品都还没有真实成交价**，见 7.6。
   `nft_banners` 有 1 条（`/images/hero/slide-3.jpg`），所以首页顶部是静止的单图——想让它轮播，
   就去「内容 → 轮播管理」再上传第 2 张。
   用户表 2 条：`13900000001`（部署期测试号，未实名）。
   **`17587881293`（U000002）这里有一条挂了的待审核实名**：`realname_status=1`、`id_card` 已存密文、
   `updated_at=2026-10-11 16:17:35`，`is_realname` 仍是 0、`id_card_hash` 仍是 NULL ——
   也就是说那是在 `realname_audit_mode` 还是 `manual` 时提交的，而现值已是 `auto`（10-11 11:10 起），
   **auto 只管后来的提交，不会回头把这条旧申请通过**。要么你去后台「实名审核」（`#/user/realname`）里手动过它，要么把它退回；
   放着的后果是：这个人以为自己实名完了却一直没到账奖励，而 `id_card_hash` 还没占坑，
   同一张证理论上还能在另一个账号上再提交一次（**这条我是现场量出来的，之前几轮笔记都写的「两个用户都没实名」，不够准**）。
   本轮（7.34）的测试账号 id=3/4 已删除，与这条无关 —— 它的 `updated_at` 早于我动手的时间。
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
   升级 SQL 在 7.13，但那是改表结构，要你单独点头；② ~~「同一个身份证注册第二个号会被拦」在生产上**还没有真人走过一遍**~~
   （auto 模式下走一次就真占一个坑，属带副作用的验证，我不擅自替你做）—— **2026-10-14 02:5x 已由用户授权代跑并线上复验，见 7.34 ③**：
   第一个号通过、第二个号被同一句话拦下且三列全 NULL，测试账号与占的坑已清除。
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
15. **~~生产上有 20 张零引用图（4.8MB）等你勾~~**（2026-10-12 记，**同日已按你的话清完**，过程与证据见 7.27）：
    **原数字是错的**，错在用「grep 数据库快照里的 `/uploads/` 前缀」来数引用，而绝大多数记录存的路径没这个前缀，
    漏掉的 basename 只被 `nft_admin_operation_logs`（上传审计行）匹配到，于是把在用的图当成零引用。
    正确口径是用平台自己的扫描器 `UploadCleanupService::scanUnreferenced()`：当场实测 **18 张可清理 / 212,975 字节 +
    8 张受保护 / 10,424,987 字节**，全库只有 1 个 URL 真的被引用。清理已走「移回收站」（`runtime/upload_trash`，可 restore），
    `purge` 没跑，今天那份 `tar.gz` 也在，双保险。
    **2026-10-14 复核并闭环（见 7.31）**：目录只剩 1 个文件当时看着像事故，用平台自己的口径点查完是**正常结果** ——
    现役被引用的上传 URL 只有那 1 张大绵羊封面（`isReferenced() = true`），回收站 26 张**逐个**点查 **0 张仍被引用**，
    三个 access log 里 `/uploads/` 的 **404 = 0 条**。要处置的只剩一件事：回收站里的东西是 `purge` 还是继续留着（等你发话，仍没跑过）。
13. **四处「新建活动默认停用」与公告 `tag_color` 无入口**（2026-10-11，都在你报的范围之外，等定）：
    `LuckyDraw.vue:58,154`、`resale/Index.vue:87`、`marketing/Invite.vue:53`、`marketing/Register.vue:48` 仍是 `enabled: false`；
    公告的 `tag_color` 后端字段、接口下发、C 端读取都在，只有后台表单缺一个输入口。详见 7.14「查出来但故意没改的」。
    **2026-10-14 更正这半句**：当时「C 端读取都在」**是错的** —— C 端详情页那时用的是固定 `AppTag`，根本没读 `tagColor`（我这次读代码看到的）。
    现在 7.36 把它接上了（只认 `#rrggbb`，脏值回落类型色），**但后台那个输入口仍然没有**：要给某条公告用非主题色，眼下只能改库或补输入框。
16. **后台「修改密码」弹窗的修复**（2026-10-12，见 7.23 / 7.24）：**已上线**，只发后台一个前端。
    唯一没在线上验的是「真人登录后点一次看见弹窗浮出来」（后台登录有图形验证码，不用脚本绕）—— 你点一次就是最终确认。
17. **~~奖励编辑器选不了盲盒~~**（2026-10-13 发现，**2026-10-14 已按你「那就修共享组件」修完并上线，见 7.30 / 7.31**）：
    原先 `RewardListEditor.vue` 的「盲盒」那一行只有数量一格，后端 `blindbox` 分支却要求 `blindboxId`
    （当时全仓库后台前端没有一处出现这个字段），所以邀请 / 签到 / 注册 / 抽奖的「盲盒奖励」**都存不进去**。
    现在共享组件有盲盒下拉、四个活动都传了盒列表并在保存前拦空值、中奖名单文案也会带出盒名 ——
    **2026-10-14 00:4x 已随第 7 次发版上生产**，线上只读探针实测文案输出 `盲盒「青色」×2`、无盒 id 退回 `盲盒×1`、盒被删退回 `盲盒「#999999」×1`。
    生产现值：3 个盒都有名字可用，存量奖励里**没有一条盲盒**，不需要回填数据。
    ~~**待你确认的只剩真人一次**：后台选一次盒、保存，看到摘要变成「盲盒「名字」×N」就是最终确认（登录有验证码，不脚本绕）。~~
    **2026-10-14 02:5x 已由用户授权代跑确认，见 7.34 ①**（三个盒都在下拉里、保存回 `code:200`、摘要渲染成「盲盒「青色」×1」）。
    仍未验的是**发放侧**：真发一个盲盒到用户账户（要动真资产）。
18. **~~公告与新闻正文底部的「风险提示」~~**（2026-10-14 本地做完并测过，**同日 02:1x 已上线，见 7.32 / 7.33**）：
    文案按司南重写了一段三段式声明（收藏欣赏价值 / 零容忍行为清单 / 反洗钱反诈法律责任），存在 `nft_system_configs` 两个新键里，
    后台「系统配置 → 全局参数 → 内容与公告」可改可关，公告表一个字节没动。
    ~~上线要你点头~~ 已执行：第 24 份 DDL（39 → 41 行）+ 后端 3 文件 + 两个前端整包，线上复验见 7.33。
    ~~还剩真人两次~~ **第一次（后台改文案 + 拨开关）已在 7.33 末尾由用户授权后代跑闭环**：四笔 `config_save` 审计、
    C 端提示随之实时消失 / 回来、文案 md5 复位到迁移文件的默认值（逐字节相同），细节与后台交互实情（开关是 `@change` 自己弹确认）都在那节。
    **只剩一件**：C 端真机看一眼这段的版式与折行（内嵌浏览器视口 0×0 + 本项目 px 编译成 vw，只验到 DOM 结构、选择器命中与 `font-weight`，量不到像素）。
    这次代跑浮出两件安全项：`captcha.enable` 线上是 **0**，当场复核 `updated_at` 才发现**不是本轮临时关的，是 2026-10-11 11:09
    在后台界面里关掉后一直没拨回来**（审计 id 101），等于后台登录口在无 HTTPS 的公网上三天没有验证码挡爆破 —— 建议尽快改回 `1`
    （细节见 7.33：那个键在后台是「其他参数」里的文本框，不是开关）；
    后台口令在**没有 HTTPS** 的公网上明文走过一次（就是本节第 2 条那个老欠账），建议改一次密码。
19. **平台对外没有任何可用的客服渠道**（2026-10-14 12:4x 发第一条真公告时顺手量出来的，见 **7.35**）：
    C 端「我的 → 我的客服」页读的是三个系统配置 + 一张社群表，现值实测 ——
    `service_hotline = 400-888-0000`（**一眼假的占位号**）、`service_hours = 9:00 - 22:00`、
    `service_online_url` **空**（所以点「在线客服」只弹一句「正在接入在线客服…」，哪也不去）、
    `nft_community_groups` **0 行**（点「加入社群」弹「暂无可加入的社群」）。
    要命的地方在于：公告、实名被拦时那句提示、发货失败提示、`Realname.vue` 那段须知，**四处都在对用户说「请联系客服」**，
    而用户真点过去，一条路都联系不上 —— 对做数字藏品合规的平台来说，这是比「没接真短信」更靠前的缺口。
    **最便宜的修法不用发版**：在「系统 → 全局参数」页的**「客服配置」分组**（`Config.vue:22` 有登记，所以是三行普通输入框 + 行尾「保存」，
    不像 `captcha.enable` 那样掉进「其他参数」）里把 `service_hotline` 换成真号、`service_online_url` 填一个真实的
    在线入口（企业微信客服链接 / 腾讯客服 / 邮箱 `mailto:` 都行），再到「内容 → 社区管理」建一条带 QQ 群号或二维码的社群
    （那张卡片就是 C 端「我的客服」页要用的官方社群入口）。等你给渠道，我不替你编一个号码。
20. **公告头图这一批：本地全绿、生产一行没动，等你点头才发（第 9 次发版）**（2026-10-14，做完的过程见 **7.36**）：
    这批改的是「C 端公告详情页的样子 + 后台能不能换图」，**发版前生产的公告页还是老版式**。三端测试、12 项反证、本地真人端到端都过了（7.36 末尾两张表）。

    **发版前的只读预检（2026-10-14 15:3x 现场量，六条都对得上，确认生产确实没动）**：
    ① 生产**没有** `app/service/AnnouncementCover.php`；② 生产**没有** `public/uploads/notice-default/` 这个目录；
    ③ 生产**没有**第 25 份迁移文件；④ `nft_system_configs` 仍是 **41 行**，`announcement%` 只有第 8 次发版那两个键
    （`announcement_disclaimer` / `announcement_disclaimer_enabled`），**四个 `announcement_cover_*` 一条都没有**；
    ⑤ 公网 `GET /api/announcements` 里既没有 `covers` 也没有 `cover_image`，四张出厂图的 URL 现在 **404**；
    ⑥ 两个前端入口仍是第 8 次发版那对（后台 `index-Dm9z2FwK.js`、H5 `index-TARjuzYk.js`），公告表仍是 2 行 / max id 2。
    **一条对 md5 的口径提醒**（当场差点误报「生产漂移」）：线上那两个后端文件是 **CRLF**，而 `git show <commit>:<path>` 给的是 **LF**，
    直接 `md5sum` 比必然不同 —— 把两边都 `tr -d '\r'` 再 diff 是**逐字节相同**，说明生产确实停在改动前。
    **比对要用工作区文件（`md5sum sinan-nft-backend/app/...`），别拿 `git show` 的输出当基准**；上线后双端 md5 同理，两边都取部署后的实际文件。

    **要传到生产的（运行时必需 9 个字节级动作）**：
    - 后端 3 个文件：`app/service/AnnouncementCover.php`（**新增**，全站子分类/标签色/默认图的唯一真源）、
      `app/admin/controller/CmsController.php`（列表筛选/新建/编辑三处白名单改走它 + 列表随分页下发 `covers`）、
      `app/controller/Content.php`（列表下发 `subtypeLabel`/`tagColor`，详情下发 `coverImage`）。
    - **一个新目录 4 个文件**：`public/uploads/notice-default/announcement-{activity,compose,consignment,operation}.webp`
      （四张合计 **158,434B**；其中寄售与运营两张是 **2026-10-15 02:2x 重画的**，与 `activity` / `compose` 同一套写实古物风格，见 **7.40 第二轮** —— 本条旧版写的「共 133KB」是 10-14 那版四张的总数，已过期）。
      ⚠️ 它在后端站点根，**不在两个前端的 dist 包里**，整包覆盖带不到 —— 必须单独 `scp`，并且生产上这个目录现在是**不存在**的（发之前先 `ls` 确认，别把「传了」当成「传对」）。
      **双端 md5 的基准表在 7.40 末尾**（四张逐条列了尺寸/字节/md5），别照抄本节任何旧数字。
    - 迁移**第 25 份** `migrations/20261014_announcement_cover_defaults.sql`：4× `INSERT IGNORE`，**零 DDL、公告表一个字节不动**；
      `nft_system_configs` 从 41 行 → **45 行**。**故意没有 UPDATE**，重复执行安全（运营换过的默认图不会被冲回出厂图）。
    - 后台前端整包（改了 4 个源文件：`api/index.js`、`utils/maps.js`、`views/content/Announcements.vue`、`views/system/Config.vue`）。
    - C 端前端整包（改了 2 个源文件：`stores/notice.js`、`views/NoticeDetail.vue`）。
      **§7.41 又在这个方向上加了 3 改 1 新**（`views/Notice.vue`、`components/AppTag.vue`、`stores/notice.js` 再改一次 + 新增 `utils/tagColor.js`），
      三批合起来**仍是同一份 dist**，字节级动作不多一个 —— 详见下面第 22 条。

    **顺序（跟第 8 次那次一样：DDL 先行 → 后端 → 两个前端）**：回滚点 + 上传前快照 → 跑第 25 份迁移并 `SELECT` 复核 4 行都在 →
    传 3 个后端文件 + 4 张图 → **双端 md5**（`md5sum` 逐个比，包括四张图）→ 传两个前端 dist →
    上线后**走公网 IP 复验**（`127.0.0.1` 会被宝塔 `phpfpm_status` 站点截走造成假 404，这坑记在 §7.22）。

    **上线后要当场看的三件事**：① 生产那 2 条存量公告（id=1 测试公告、id=2 实名认证须知）的详情页**立刻**有横幅、
    标签显示「运营公告」—— 这是「读取时解析、不回填」的设计，本来就该当场生效，看不到就是四张图或四个参数没落地；
    ② 后台「发布公告」弹窗里那两格预览出图（没出图 = `covers` 没随列表下发，多半是后端文件没覆盖成功）；
    ③ 公网 `GET /api/announcements/2` 的 `coverImage`/`tagColor`/`subtypeLabel` 三个字段形状对（复验按 §7.35 那个坑：**先 `curl -o 文件` 再按 UTF-8 读**，管道量中文会虚高）。

    **不发版就能改的**（这次特意这么设计的，记住就不用再找我）：某个类型的默认图 → 「系统配置 → 全局参数 → 公告头图」点「换图」；
    某条公告用别的图 → 「内容 → 公告管理」编辑弹窗里传一张「公告图」，或点「恢复跟随类型默认」；想让某类型不显示横幅 → 把那个参数清空。

    **仍然没验的**：像素级观感（内嵌浏览器视口 `0×0` + 本项目 px 编译成 vw，`getComputedStyle` 的长度量不到，只验到 DOM 顺序、
    `matches()`、编译后规则文本与图片真实尺寸）。**你或真机看一眼详情页那张横幅与底部风险提示**就是这批的最终确认
    （提示现在的形状按 §7.39：在正文卡片里面、虚线分隔，不再是独立白卡）。

21. **公告敬语/落款 + 首图通宽这一批：本地全绿、生产一行没动，等你点头才发（第 10 次发版）**（2026-10-14，做完的过程见 **7.37**）：

    **⚠️ 这批不能单独发 —— 第 9 次发版其实没上。** 本轮结尾公网只读复量（四条证据在 7.37 末尾）：两个前端入口还是第 8 次那对
    （H5 `index-TARjuzYk.js`、后台 `index-Dm9z2FwK.js`）、`/uploads/notice-default/*.webp` 还是 404、
    详情接口的 `coverImage`/`tagColor` 还是 `null` 且没有 `subtypeLabel`。所以 20 条那份清单**仍然有效、还没执行**，
    这两批是叠在**同一批文件**上的（`Content.php`、`CmsController.php`、`api/index.js`、`Announcements.vue`、`Config.vue`、
    `stores/notice.js`、`NoticeDetail.vue` 七个文件两批共用）—— **按「20 条 + 本条」一起发**才自洽，别只发本条。

    **发版前先当场重量四件事（别照抄本条里的旧数，生产现值会烂掉 —— 这条自己踩过）**：
    ① `nft_system_configs` 行数（15:3x 量到的是 **41 行**，两批发完应是 **47 行** = 41 + 4 张默认头图 + 2 条敬语落款默认）；
    ② `nft_announcements` 有没有 `greeting`/`signature` 两列（`SHOW COLUMNS`）；
    ③ 生产**没有** `app/service/AnnouncementCover.php` 与 `AnnouncementSalutation.php`；④ 公告表的行数与 max(id)（发完要能对上「没多没少」）。

    **要传到生产的（在第 20 条那份清单之上再加）**：
    - 后端 **1 个新文件** `app/service/AnnouncementSalutation.php`（两个默认文案与三级回落、日期算法、限长 100 的唯一真源），
      另外 `Content.php`、`CmsController.php` 这**两个文件是两批共用**的，**只传一次最新版**即可（第 20 条列的形状已被本批覆盖）。
    - 迁移 **第 26 份** `migrations/20261014_announcement_salutation.sql`：**这条有 DDL**（`nft_announcements` 加两列），
      与第 25 份「零 DDL」不同 —— 必须用**有 ALTER 权限的 root 账号**跑，且**先跑它再传后端文件**（后端代码会读这两列）。
      幂等：DDL 先查 `information_schema` 再 `PREPARE`，参数种子只有 `INSERT IGNORE`、**故意没有 UPDATE**。
    - 后台前端整包（本批多改了 `api/index.js`、`Announcements.vue`、`Config.vue` 三个文件，与第 20 条那 4 个重叠，合并后是**同一份 dist**）。
    - C 端前端整包（本批改了 `stores/notice.js`、`views/NoticeDetail.vue`，同样并入第 20 条那份 dist）。
      **§7.41 之后这条过期了**：C 端源文件现在是 **4 改 1 新**，基线与反证档数也跟着变，以**第 22 条**为准。
    - 三端测试基线要用**这份**（10-15 01:0x 实测，含 §7.38 + §7.39）：后端 **269/1760**、后台 **167**、C 端 **151** ——
      比本节上一版写的 267/1732、165、149 新，也比第 20 条写的 251/1619、158、141 新。
      **这条已被 §7.41 超过**：C 端 22:5x 重测是 **158 / 18 文件**（后端、后台不变），发版前重跑以第 22 条那组数为准。
      **又被 §7.42 超过一次**：C 端 23:5x 重测是 **167 / 19 文件**（后端与后台这轮一个文件没碰，那两条沿用 §7.41 的 **269/1760** 与 **167 例/14 文件**），
      发版前重跑**以第 23 条那组数为准**。
      反证 **15 行 + 2 行 + 4 行 + 2 行**（§7.37 第二轮 P1~P10 + A1~A5；§7.38 R1~R2；§7.39 S1~S4；§7.40 第一轮与第二轮各 1 行，
      都是把 1280×667 错尺寸图塞进 `announcement-operation.webp`，全红如预期、还原 md5 SAME）。
      **§7.40 两轮都没改基线数字**：它只换两张 webp 的内容，三端测试一条没加没减。

    **⚠️ 两个 dist 必须是重过到新代码的那份，别用 10-14 14:41 的旧整包**（缘由见 7.37 第三轮：旧包里有 §7.36 的头图、
    **没有 §7.37 的敬语落款**，照旧包传上线就是「测试全绿但线上整批功能静默缺失」）。发版时按老规矩当场重量：
    00:5x 的快照是 H5 入口 `index-BY_I9mXE.js` / md5 `a4d00011dec2cafbd2c1b3e53418ef61`，后台入口 `index-qMvhze0Z.js` / md5 `f3b0a3457c874a37729ce3a09a4df70f`
    —— **但入口文件名与 md5 每 `npm run build` 一次就变，所以这两个数只当"是不是旧包"的对照，重量以你当场量的为准**。
    传之前先跑四条指纹（比时间戳可靠）：`grep -c notice-detail__greeting sinan-art-source/dist/assets/*.js` ≥1
    （**它在 `NoticeDetail-*.js` 那个分块里，不在入口 `index-*.js` 里** —— 只 grep 入口会假报 0，本轮差点误判）、
    `grep -c lastSalutation sinan-admin/dist/assets/*.js` ≥1、
    `grep -o 'warn-title{[^}]*}' sinan-art-source/dist/assets/NoticeDetail-*.css` 里必须含 `text-align:center`（§7.38 那句）、
    `grep -c 'notice-detail__main>.notice-detail__warning' sinan-art-source/dist/assets/NoticeDetail-*.css` = 1 **且**
    `grep -c 'notice-detail__warning{margin:12px' sinan-art-source/dist/assets/*.css` = 0（§7.39：卡内分隔那条在、旧白卡那条不在；
    01:0x 那次的实测快照是 H5 入口 `index-BZqfQTQ.js` / md5 `8c93f8bbc6a90ea00877b010035fc49a`、CSS 块 `NoticeDetail-0-4Aeock.css`）。

    **顺序（DDL 先行 → 后端 → 两个前端）**：回滚点 + 上传前快照 → 跑第 25 份（4 行参数）→ 跑第 26 份（2 列 + 2 行参数）并
    `SELECT`/`SHOW COLUMNS` 双复核 → 传后端 **4 个文件**（`AnnouncementCover.php`、`AnnouncementSalutation.php`、`Content.php`、`CmsController.php`）
    **+ 4 张 webp**（`public/uploads/notice-default/`，生产这目录现在不存在，要先 `ls` 确认再 `mkdir`）→
    **双端 md5 逐个比**（含四张图；**用工作区文件当基准，别拿 `git show` 的输出** —— 线上是 CRLF、`git show` 是 LF，直接比必然假报漂移）
    → 传两个前端 dist → 上线后**走公网 IP 复验**（`127.0.0.1` 会被宝塔 `phpfpm_status` 站点截走造成假 404，见 §7.22）。

    **上线后当场看的几件事**：① 生产存量公告（id=1、id=2）详情页**立刻**有横幅 + 开头一句「尊敬的司南用户：」+ 右下「司南运营团队 <发布日期>」
    —— 这是「读取时解析、不回填」的设计，看不到就是四个头图参数或两个默认文案参数没落地；
    ② 落款日期用的是**发布日期**不是创建日期（id=2 的 `publish_time` 与 `created_at` 都填了就应显示 `publish_time` 那天）；
    ③ 首图**通宽、左右不出血、四角圆**（`border-radius` 在 375 宽下折 10px）；
    ④ 后台「发布公告」弹窗里「开头敬语 / 落款署名」两格：**新建时就带着上一条公告实际用的值**（生产上头一条公告还没填过自己的，
      所以应当预填成当前全局默认那句），**占位符显示的是当前全局默认**（这两格若都为空 = `data.salutation` 的键名又变回长参数键了，
      见 7.37 第二轮补充那次缺陷），落款示例那句要印出「司南运营团队 <今天>」而不是「 <今天>」；清空保存后 C 端跟着回到默认那句；
    ⑤ 公网 `GET /api/announcements/2` 里 `greeting`/`signature`/`signatureDate`/`coverImage`/`subtypeLabel`/`tagColor` 六个字段形状对
    （复验按 §7.35 那个坑：**先 `curl -o 文件` 再按 UTF-8 读**，管道量中文会虚高）。
    ⑥ 详情页底部那句「司南风险提示」：**标题那五个字居中**、下面的正文段落仍然左对齐（§7.38）；
    ⑦ **它现在和正文共用同一个边框**（§7.39）：风险提示应当在正文那张白卡**里面**、落款下面、由一条**虚线**和正文分隔，
      它自己**不再是一张独立的白卡**（若你看到上下两个分开的白框 = 传的是旧 dist，按 §7.37 第三轮那套四层测量重刷）。
      这两条都只能眼看，或按 §7.38 / §7.39 那两张表读 `getComputedStyle`。
    ⑧ **寄售与运营那两张首图是 10-15 重画的**（见 **7.40 第二轮**）：新版的标志是「**右侧一件写实 3D 中国古物**（寄售＝玉佩+木座+象牙签牌+小铜秤；
      运营＝兽钮青铜官印+竹简+罗盘盘），主体间有淡金连线与小节点」，与 `activity`（司南勺+罗盘盘）、`compose`（玉璧+青铜鼎碎片连线）同一套语言。
      若看到**扁平小图标 + 大片纯渐变**（寄售是一个小价签、运营是一颗小棱镜、左半边几乎空），那是 10-15 02:1x 第一轮的作废版；
      若看到**带展柜/图表的旧寄售、megaphone 喇叭的旧运营**，那是 10-14 的 §7.36 原版。两种都按 7.40 末尾那张 md5 表逐张比对重传。
      这两张**与两个前端 dist 无关**，硬刷新解决不了，只能重传 `public/uploads/notice-default/` 里的文件。
    ⑨ **公告列表页那四类标签应当是四种颜色**（§7.41）：寄售橙 `#E89121`、合成金 `#B08D55`、运营靛 `#3D5A99`、活动红 `#C00000`，
      并且**同一行点进详情，那个标签与列表同色**。两种"没生效"长得不一样，据此分诊：
      列表清一色红而详情各有色 = 列表接口没下发 `tagColor`（后端 `Content.php` 没覆盖成功）；
      列表与详情**都**回到红 = 传的是 10-14 那份旧 dist，按 §7.37 第三轮那套四层测量重过一遍再刷。
    ⑩ **「我的」页那张邀新卡应当是「深色满幅海报 + 白字 + 透明灰按钮 + 白色线条的「人形 + 加号」图标」**
      （底图与文字口径来自 §7.43，**推翻了 §7.42 那版"浅底 + 右端一小簇古物"**；按钮口径来自 §7.44，**推翻了 §7.43 表里"按钮 `#C00000` 没动"那一行**；
      **图标口径来自 §7.47，推翻了 §7.43~§7.46 那四批的"每套一张位图 / 平面信封 / 中式请柬 / 写实玉佩小卡"**）：
      整张卡是暗底，标题纯白、副标近白，古物在右端；右侧那颗「邀请」按钮是**一块透明灰板**（`rgba(46,46,52,.62)` + 白字 + 一圈半透明白亮边），
      **能透出底下的玉璧**，不是实色红块、也不是乳白玻璃；左侧图标位**不是位图，是一条白色矢量线条画的「人形 + 加号」**，
      五套主题共用 `src/components/AppIcon.vue` 里**同一份定义**，`public/images/tab/` 下**没有任何 invite 图片文件**（这一格不占一个字节）。
      六种"没生效"长得不一样，据此分诊（**下面每条 grep 都在 §7.47 那批的 dist 上实测过命中数，别再照源码写法猜产物**）：
      卡片**左端文字底下有白纱**、标题是深红 = 传的是 §7.42 那份 dist（`grep invite-banner-bg dist/assets/index-*.css` 会看到 `-v3`）；
      卡片右端**一片死白**、什么古物都没有 = 静态文件 `public/images/invite-banner-bg-v4.webp` 没传上去（CSS 里只有文件名，它是另一个东西）；
      按钮是**实色红 `#C00000`** = `User.vue` 没覆盖成功（旧 dist 的 CSS；`grep -c -F "#2e2e349e" dist/assets/index-*.css` 命中才是新版）；
      按钮**不透底**、是一整块不透明灰 = α 被写成 1（`grep -c -F "#2e2e349e" dist/assets/index-*.css` 应是 1；若是 `#2e2e34ff` 就是 α=1。
        ⚠️ 原来这条给的判据是 `grep -o "rgba(46,46,52,[^)]*)" … 应是 .62`，**在当前 dist 永远命中 0**：压缩器把 rgba 折成 8 位 hex
        （`.62`→`9e`、`.16`→`29`）。那句从 §7.44 起就写错了，三轮没人撞过是因为只有"上线后没生效"才会拿它去 grep，§7.47 已更正）；
      图标位**只有一块磨砂灰板、里面什么都没有** = 传的是"只改主题表那一行"的半成品：线条颜色退回 `#C00000`，对那块板只有 **2.09:1**（§7.47 量的）。
        判据：`grep -c 'invite",size:28,color:"#FFFFFF"' dist/assets/index-*.js` 应是 **1**；
      图标位**还是写实玉佩 / 中式请柬 / 信封那张位图** = 传上去的还是旧 dist 的 JS。判据：
        `grep -c -F "M16 7v6M13 10h6" dist/assets/index-*.js` 应是 **1**（加号那条形本体，比文件名 marker 硬），
        `grep -c invite-jade dist/assets/index-*.js` 与 `grep -c -e "-invite.png" dist/assets/index-*.js` 都应是 **0**。
        （上一版这里写的是「invite-jade 应是 5」，§7.47 之后反过来；那张 webp **生产从来没有过**，所以这一批**没有静态图要传**，也**没有图要 curl 判在不在**。）
      另有一条**这批特有的反向检查**：五套主题下这一格应当**逐像素一样**（共用同一份定义 ⇒ 后台切功能图标主题，这一格不该变；
      若切主题图标跟着变了，说明某个主题的 `invite:` 又指回位图了）。
      这张卡**不走后台、不进数据库**，改不了也不该在后台找它 —— 但**它有一个后台口子**：「内容 → 站点装修 → 邀请好友图标」那一格
      （`customIcons.invite`）**优先于主题表**，且主题无关、一次覆盖五套（第 26 条已记，§7.47 的测试仍钉着这条优先级）；
      后台上传的那张走的是**位图分支**，所以 `<img>` 那条分支**不许删**（§7.47 里专门留了一例测试守它）。

    ⑪ **文物展览区（`/mall`）顶部那块头图应当是「深暗底青铜方鼎 + 白字」**（口径来自 **§7.48**；这块格子以前没有任何钉，第 21 条这张表里原来也没有它，是这批新加的）：
      标题纯白「文物展览区」、副标 85% 白「司南 · 数字文物典藏」，底下是一张**深暗底**的写实方鼎，鼎在右、左侧留给文字，
      上面还压着一层上浅下深的黑渐变（`rgba(0,0,0,.08) → .55`，**旧图也有这层，别当成新加的**）。
      三种"没生效"长得不一样，据此分诊（**每条 grep 都在 §7.48 那批的 dist 上实测过命中数，别照 SCSS 写法猜产物**）：
      头图**还是浅米黄的青绿山水 + 松鹤**、字是深棕 = 传的是旧 dist（§7.36 之前那份）。判据：
        `grep -c -F "exhibit-header-bronze-ding-v2.webp" dist/assets/index-*.css` 应是 **1**，
        `grep -rl -F "exhibit-header-ancient" dist/assets/ dist/index.html` 应是 **0 个文件**；
      头图**一块死黑、什么图都没有**（两行白字仍然很清楚）= CSS 传上去了、**静态图没传**。
        这批**确实有一张静态图要单独传**（`images/exhibits/…webp` 不在带哈希的 `assets/` 里，**只挑 `assets/` 传必漏**，同 §7.42/§7.43 那个坑）。
        判据：`curl -s -o /dev/null -w "%{http_code} %{content_type}" http://<IP>/images/exhibits/exhibit-header-bronze-ding-v2.webp`
        必须看到 **`200 image/webp`**（⚠️ 这台服务器对缺失静态路径回的是 **`200 text/html`** 的 SPA 兜底，
        **只看状态码会得到"文件在"的错误结论** —— 本轮生产现值就是这么量的：新图 `text/html`、旧图 `image/jpeg`）；
      图在、但**那两行字看不清 / 像糊在底里** = 只有图上去了、CSS 还是旧的（深棕字压暗底实测"比底亮的点 **0 个**"）。判据：
        `grep -c -F "color:#ffffffd9" dist/assets/index-*.css` 应是 **1**（副标那 85% 白，压缩器把 `.85` 折成 `d9`）。
      另有一条**这批特有的**：那层压暗渐变在产物里长这样 `linear-gradient(180deg,#00000014,#0000008c)`；
        拿 `grep "rgba(0,0,0,0.55)"` 去查**永远命中 0**（rgba 折 hex8，同 ⑩ 里那条已更正的坑）。
      ⚠ 那条规则上的 `[data-v-…]`（本批当前值 `506a8be8`）**不许当 marker 用** —— 改 SFC 任何一处（连注释）都会换那串哈希（§7.47 已给归一化证明；本批当天就撞了一次：一句注释把 `87875503` 换成了 `506a8be8`，整个 dist 文件名全变，规则却没变）。
      还有一条**只有人眼能拦**：这块格子的**文案长度是有物理上界的**（标题墨迹右界踩亮区的最左列在 x=205.7、副标在 x=318.7），
      所以线上若有人把标题改成六个字以上、或把副标那句改长，**对比度数就作废**，测试里有三条上界钉拦着，但**后台没有这块格子的任何口** ——
      它不走后台、不进数据库，别在「站点装修」里找它。

    **不发版就能改的**（这次特意这么设计）：全站默认敬语/落款 → 「系统配置 → 全局参数 → 内容与公告」那两格；
    单条公告用自己的 → 「内容 → 公告管理」编辑弹窗那两格（**留空保存 = 改回跟随全局默认**）；
    落款日期**没有口**，由系统按该公告实际生效时间自动带，运营改不了、也不会出现「改了日期忘了改正文」。

    **仍然没验的**：像素级观感（圆角 10px、落款右对齐、敬语与正文的间距）—— 内嵌浏览器视口 `0×0`，只验到 DOM 顺序与编译后规则文本；
    **这两批的最终确认要你或真机看一眼详情页**。生产发版要你**单独点头**，并且我会按老规矩逐批发、批内逐项留证。

22. **公告列表页分类标签跟着接口上色这一批：本地全绿、生产一行没动，仍并进「第 9 + 第 10 那一批」**（2026-10-15 22:5x，做完的过程见 **7.41**）：

    你的一句「现在公告列表的公告分类全是红的，改成和详情里面的分类一样的颜色」，量下来是**纯前端接线断了一处**：
    列表接口从第 9 批那版 `Content.php` 起就逐行下发 `tagColor`，详情页一直在读，列表页那行写死成了 `AppTag` 的 `primary`（平台红）。

    **这一批不多一个字节级动作**：后端文件、四张 webp、两份迁移、`nft_system_configs` 的行数**全都跟第 20/21 条一样**，
    只是同一份 C 端 dist 里多含这一处。要改的只有清单里的「C 端源文件」那一句 ——
    从「2 个」变成 **4 改 1 新**：`views/Notice.vue`、`views/NoticeDetail.vue`、`components/AppTag.vue`、`stores/notice.js` 四个改，
    外加**一个新源文件 `src/utils/tagColor.js`**（列表与详情共用的那个唯一出口；它打进 dist，不是要单独 `scp` 的文件）。
    后台前端这一批**没动**，第 20/21 条那份后台 dist 清单不变。

    **发版前必看的两条口径**（都在 7.41 里，这里只提最要紧的）：
    ① 基线：后端 `--testsuite unit` **269 / 1760**、后台 **167 / 14 文件**、C 端 **158 / 18 文件**（151 → 158，新增 7 例）。
    ② 反证 **5 档**（A 写死红 / B 映射不带字段 / C 守卫不校验 / D `AppTag` 不认 `color` / E 详情页再抄一份兜底色），
      每档点名用例全红、还原后 md5 SAME。其中 **B 档第一轮是假绿**，红的是我那两条测试自己的洞 ——
      `fetchNotice()` 会把详情对象并回列表那一行（store 里的 sync-back），「先挂详情再回头读列表」就把「列表少接一个字段」掩盖掉了。
      这条已经写进 7.41 并同步到 AGENTS 第三节：**跨页一致性断言，要防后加载那页把前一页的数据补上**。

    **上线后当场看**：第 21 条那张表的**第 ⑨ 条**就是这条 —— 列表四类四种颜色，且同一条在列表和详情同色。
    分诊口径也在那儿：**列表红、详情有色 → 查后端 `Content.php`；两边都红 → 传的是旧 dist**。

    **H5 那份 dist 已在 22:5x 重过到新代码**（五条字面量指纹在 7.41 末尾那张表，含 §7.37/7.38/7.39 三条老指纹仍同时成立 ——
    新包不能只量新指纹，旧的一起看才证明「是叠加、不是回退」）。但**传之前照例再 build 一次、再当场重量**：
    入口文件名与 md5 每 build 就变，那几个数只用来认「是不是旧包」。

23. **「我的」页邀新卡背景图换成公告首图那套古物风格这一批：本地全绿、生产一个字节没动，仍并进「第 9 + 第 10 那一批」**（2026-10-15 23:5x，做完的过程见 **7.42**）：
    > **⚠️ 这条描述的那版已经被 §7.43 推翻**：`invite-banner-bg-v3.webp` 已 `git rm`，浅底 + 白纱那套代码也没了。
    > 发版**照第 24 条走**，别照这条里的文件名传图（按这条传就会把线上做成"白纱盖住深色图"或直接 404 死白）。
    > 本条唯一还成立的结论是下面那句「webp 在 `public/images/` 不在 `assets/`」——它跟具体哪张图无关，第 24 条沿用。

    这批只有**一个 C 端文件 + 一张静态图**：`src/views/User.vue` 的 `.invite-banner::before`，
    和新文件 `public/images/invite-banner-bg-v3.webp`（1280×285 / 32,722 B / md5 `27f8dabe616bc8ecf46649bcd9c3868d`）。
    **后端、后台、数据库、迁移这轮一个没碰**，所以不新增任何 SQL 或接口动作。

    **发版时要多带一个字节级动作**（这是它和第 20/22 两批唯一不同的地方）：
    那张 webp 在 `public/images/` 里，**不在 dist 的 `assets/` 里**（`public/` 是整目录原样拷进 dist 的），
    所以「整包覆盖 dist」顺带就带上它了 —— 但**如果你只挑 `assets/` 里的哈希文件传，就会漏掉它**，
    症状是 CSS 里写着 `invite-banner-bg-v3.webp` 而线上 404，卡片右端**一片死白**（不是回退成旧图，旧图已经没人引用了）。
    传完照例双端 `md5sum` 那一张，数在上面。

    **两条口径**：
    ① 基线：C 端 **167 / 19 文件**（158 → 167，新增 9 例都在 `tests/invite-banner-art.spec.js`）；
      后端 `--testsuite unit` **269 / 1760**、后台 **167 / 14 文件** 两条**沿用 §7.41 的实测值，这轮没重跑**（改动面里没有一个后端/后台文件）。
    ② 反证 **8 档**，逐项点名用例红、还原后 `User.vue` 与 webp 双 md5 SAME。
      其中两项是这轮新学到的量法：**改 webp 文件头就能造出"比例不对"的包**（不用真做一张大图），
      **往文件尾部追加字节就能单独红"体积"那一例**（宽高不变，只红体积）—— 资产类测试的反证原来也能这么拆。

    **顺手证伪了一条我自己先前定的判据**：「相邻列亮度跳变 <3 才算没缝」是错的，它量的是**斜率**不是**台阶**；
    分开量之后是「不叠纱 18.36 / 叠了纱 10.21 / 只有纱 5.22」—— 纱把原有的边**压软**了，不是造出新边。
    以后判断"渐变有没有露缝"，要拿**同一张图叠与不叠**两个数比，别定绝对阈值。

    **上线后当场看**：第 21 条那张表的**第 ⑩ 条**（右端是玉璧+银鱼+红绳结；死白 = 那张 webp 没传；还是牡丹 = 旧 dist）。
    —— 这条随本条一起作废：第 ⑩ 条已按 §7.43 改写成"深色满幅 + 白字"，看的时候照新版那条对。

    **仍然没验的**：① 生产（一个字节没上）；② 343px 窄横条上「右端一小簇古物」好不好看 —— 内嵌浏览器视口 `0×0`，
    我只验到"层叠、颜色、图能加载、文字底下的像素是纯白"，没验到"好看"，这条要你或真机看一眼；
    ③ 旧图 `invite-banner-bg.jpg` 现在全仓库零引用，成了孤儿图，**按第 15 条的口径我没动它**（要清先出清单、分档、你点头、`tar` 存档再移回收站）。

24. **邀新卡改深色满幅海报 + 五套主题各出一张邀请图标这一批：本地全绿、生产一个字节没动，仍并进「第 9 + 第 10 那一批」**（2026-10-16 01:2x，做完的过程见 **7.43**，本批**推翻第 23 条**）：
    改动面是 **C 端 2 份源码 + 6 张新图 + 1 张删图 + 2 份 spec**：
    `src/views/User.vue`（`.invite-banner` 整块深色化、纱与 opacity 全删、文字改白）、
    `src/stores/iconTheme.js`（五个 `invite:` 条目）、
    新增 `public/images/invite-banner-bg-v4.webp`（1280×284 / 32,466 B / md5 `41dc507c94e0dfea5778b2a20b3ae1bd`）
    与 `public/images/tab/{modern,gem,ink,shanse,lg}-invite.png`（五张，md5 见 7.43 的表），
    **删除** `public/images/invite-banner-bg-v3.webp`。
    **后端、后台、数据库、迁移一个没碰**，不新增 SQL 与接口动作。

    **发版时的三个字节级动作**（第 23 条那一个变成三个，都在 `public/` 不在 `assets/`，所以「整包覆盖 dist」会顺带带上，但**只挑哈希文件传就一定漏**）：
    ① 传 `images/invite-banner-bg-v4.webp`；漏了症状是卡片右端**一片死白**（CSS 里只有文件名）。
    ② 传 `images/tab/` 下那**五张** `-invite.png`；漏了症状是图标位**空白**（`<img>` 的 src 在 CSS 之外，运行时 404，不会退回矢量 —— 因为表里写的就是 image 分支）。
    ③ 传完把线上 `images/invite-banner-bg-v3.webp` **删掉**（旧包整目录覆盖不会删它，它会一直躺在服务器上；留着不影响显示，但第 15 条的孤儿清单会多一项）。
    三个文件双端 `md5sum` 各核一遍。
    **复验静态文件别只看 HTTP 200**：这台 dev server / 宝塔对不存在的静态文件也回 200（SPA 兜底页），
    要连 `content_type` 一起看（`curl -o /dev/null -w '%{http_code} %{content_type}'` 应该是 `200 image/webp` 或 `200 image/png`）—— 这是 7.43 里纠正的一条我自己写过的判据。

    **三条口径**：
    ① 基线：C 端 **201 / 20 文件**（167 → 201，+34 = `invite-banner-art.spec.js` 9→17 与新增 `invite-icon-theme.spec.js` 26）；
      后台 **167 / 14 文件**、后端 `--testsuite unit` **269 / 1760**（1 条 deprecation 非本轮引入）**两条都是当场重跑，不是沿用**。
    ② 反证 **17 档**（A~Q），逐档点名用例红、跑完 8 个文件 md5 逐位 SAME；其中 H 与 Q 各"顺带红 4 条"，原因写在 7.43 里（拆的是共用前提），不是测试写松。
    ③ **这批把 §7.42 的钉翻方向而非拔掉**：白纱、`opacity:.85`、深红标题、v3 地址四类旧口径现在都是"一出现就该红"的反向钉。
      以后要再推翻这次的决定，照例先 `grep` 三份位置（`User.vue` / 两份 spec），把钉**翻回来**而不是删测试。

    **两条方法论教训，比这批代码值钱**（都写进 7.43 了，这里只提标题）：
    ① **GD 画 mock 时 `imagettftext` 的字号是「磅」不是 CSS 像素**（FreeType 按 96dpi 换算 ⇒ 像素 = pt×1.3333）。
      我照 CSS 磅值直传，字形大 33%，量出"副标撞按钮、对比 1.31:1"的**假警报**，差点为此砍文案。
      以后凡是"离线渲染 mock 去量前端排版"，先把换算写死并跟 computed font-size 对一遍。
    ② **反证 harness 也会假绿**：Windows 下 `execFileSync('npx')` 的 ENOENT 被 catch 吞掉 ⇒ `junit.xml` 没生成 ⇒ 17 档全报"0 红 OK"。
      修法是 `shell: true` + **junit 缺失就 throw** + 本轮总用例数过低就 throw。
      这是第 19 条「反证要先证明探针活着」的一次复发，而且这次红的是**量具本身**，不是被测代码。

    **上线后当场看**：第 21 条那张表的**第 ⑩ 条**（已按本条改写：深色满幅 + 白字 + 位图图标；三种"没生效"的分诊也在那条里）。

    **仍然没验的**：① 生产（一个字节没上）；② "有没有海报感"没法测 —— 视口 `0×0`，我只验到层叠、颜色、逐像素对比度、图能加载，
      没验到好看，这条要你或真机看一眼；③ **`gem/ink/shanse/glass` 那四套主题的图标没有截图证据**：
      可见主题由服务端 `/config` 下发（`stores/site.js:143` 会覆盖 localStorage，当前是 `classic`），
      那四张只能靠 store 测试 + 静态文件 content-type + PNG 解码三条来证；
      ④ 旧图 `invite-banner-bg.jpg` 仍是零引用孤儿，**按第 15 条口径没动**。

25. **「邀请」按钮改透明灰板 + classic 那张图标换平面信封这一批：本地全绿、生产一个字节没动，仍并进「第 9 + 第 10 那一批」**（2026-10-16 02:1x，做完的过程见 **7.44**，本批**不改写第 24 条的卡片口径，只推翻它表里"按钮 `#C00000` 没动"那一行**）：
    改动面是 **C 端 1 份源码 + 1 张图 + 2 份 spec + 1 个出图脚本**：
    `src/views/User.vue`（只有 `&__btn` 一块：`#C00000` → `rgba(46,46,52,.62)` + 1px 半透明白边 + 内高光 + `padding: 7px 17px`）、
    `public/images/tab/modern-invite.png`（换成平面信封，7,049 B / md5 `7939d8d861a4c0885e7d4a95c049adce`；
    **只换 classic 这一张**，`gem/ink/shanse/lg` 四张按你的口径没动），
    两份 spec 各 +3 例，新增 `qa/gen_flat_invite_icon.py`。
    **后端、后台、数据库、迁移、`src/stores/iconTheme.js` 一个没碰**，不新增 SQL 与接口动作。

    **发版时的字节动作只有一个**：`public/` 整目录原样进 dist，所以「整包覆盖 dist」会带上这张图标；
    **但只挑带哈希的 `assets/` 文件传就一定漏**（图标路径是 `/images/tab/modern-invite.png`，文件名不变、内容变，
    服务器上还是旧字节 ⇒ 用户看到的仍是立体那张，且**没有任何报错**）。传完双端 `md5sum` 核这张图，
    复验看 `content_type`（第 24 条那条判据沿用）。

    **三条口径**：
    ① 基线：C 端 **207 / 20 文件**（201 → 207，+6 = banner 17→20、icon 26→29）、后台 **167 / 14 文件**、
      后端 `--testsuite unit` **269 / 1760**（1 条 deprecation 既有）—— **三条都是当场重跑，不是沿用**。
    ② 反证 **14 档**（A~N），逐档只红在点名用例、跑完 6 个文件 md5 逐位 SAME。J 档（整块 `&__btn` 删掉）顺带红 4 条、
      A 档顺带红 2 条，原因都写在 7.44 里（拆的是共用前提），不是测试写松。
    ③ **中途那版「乳白玻璃 + 深红字」的钉翻成反向钉留下了**：`rgba(255,255,255,·)` 做按钮底、`color: #C00000`、
      `backdrop-filter` 三样现在都是"一出现就该红"。以后若要回到玻璃那版，照例先 grep `User.vue` + 两份 spec，把钉**翻回来**而不是删测试。

    **两条方法论教训，比这批代码值钱**（细节在 7.44）：
    ① **拿 JS 改样式做对照实验，px 值要先乘 `innerWidth/375`**：这个项目的样式经 px→vw 编译，
      源码里的 `8px` 在 390 宽下是 `8.32px`。我第一版对照用 inline `padding:8px 18px` 去模拟"改前"，量出 1.3px 的**假差异**，
      差点把注释写成"外沿会长一点"。**注释里的几何结论必须由浏览器量出来，不能由 CSS 源码算出来。**
    ② **in-app 浏览器视口 0×0 不是死路**：改用 headless Chrome + CDP（`--remote-debugging-port` + Node 原生 `WebSocket` +
      `Emulation.setDeviceMetricsOverride`）就能量长度、行盒、真实排版。第 23/24 条里"视口 0×0 只能验颜色不能验长度"这条限制**已经作废**。

    **上线后当场看**：第 21 条那张表的**第 ⑩ 条**（已按本条改写：按钮是透明灰板 + 白字，图标是平面信封）。

    **仍然没验的**：① 生产（一个字节没上）；② "透明灰好不好看"没法测 —— 我量到的是对比度、几何、层叠、色值，
      观感要你或真机看一眼；③ **可读性比换图前是降的**：旧实色红按钮 6.48:1（不依赖底图）→ 新透明灰 5.12:1（字上）/ 4.69:1（整块板），
      都过 AA，但**海报以后换更亮的图这块板要重量**；④ 四套非 classic 主题的图标仍无截图证据； **（④ 这半条已被 §7.45 推翻：五套都有 CDP 截图）**
      ⑤ 旧图 `invite-banner-bg.jpg` 仍是零引用孤儿，按第 15 条口径没动。


26. **五套主题的邀请图标全换成平面中式请柬这一批：本地全绿、生产一个字节没动，仍并进「第 9 + 第 10 那一批」**（2026-10-16 11:0x，做完的过程见 **7.45**，本批**推翻第 25 条里「只换 classic 这一张、另四张按你的口径没动」**，第 24 条「五套都要位图」不变）：
    > **⚠️ 本条的"发版动作 = 传五张图"已被第 27 条整体作废**：那五张 `*-invite.png` 当晚就删了，本条列的五张 md5 现在只用于"从 git 里认旧图"。
    > 本条**仍然有效**的是那三条方法论教训（家族同档要比墨迹不比文件尺寸、色值阈值代理观感会被反证绊倒、`/api/config` 覆盖 localStorage）
    > 和那段「后台上传口今天就能用、不用发版」的澄清。
    改动面是 **C 端 5 张图 + 1 个出图脚本 + 1 份 spec**：`public/images/tab/{modern,gem,ink,shanse,lg}-invite.png` 全换，
    `qa/gen_flat_invite_icon.py` 从「只出一张」改成「一次出五张」（`THEMES` 带每套的画布模式），
    `tests/invite-icon-theme.spec.js` 29 → 54 例。**`User.vue`、`iconTheme.js`、后端、后台、数据库、迁移一个没碰**，不新增 SQL 与接口动作。

    **发版的字节动作只有五张图**：这批源码一行没动，所以 `npm run build` 后 `index.html` / 入口 JS / 入口 CSS 三个 md5
    **与第 25 条记的逐位相同**（`206845fe… / ab15831e… / 92033846…`）—— 这既是好消息也是坑：
    **光看 JS/CSS 指纹根本看不出这批上没上**，五张图标又不带哈希 ⇒ 只挑 `assets/` 传必然漏（第 25 条那条提醒在这里更硬）。
    上线后复验要逐个 `md5sum` 那五张（基准表在 7.45 末尾），并按第 24 条口径看 `content_type` 是 `image/*`。

    **三条口径**：
    ① 基线：C 端 **232 例 / 20 文件**（207 → 232，+25 全在图标 spec）、后台 **167 / 14**、后端 unit **269 / 1760**（1 条 deprecation 既有）—— 三条都是当场重跑。
    ② 反证 **10 档**（A~J），逐档只红在点名用例，跑完 8 个文件（5 图 + 脚本 + spec + `User.vue`）md5 逐位 SAME。
      C 档第一轮顺带红一条 ⇒ **把 top8 那条线从 0.6 放到 0.55 并写下理由**（新五张 0.662~0.856 / 旧立体四张 0.007~0.170），不是接受一条顺带红。
    ③ 「平面的」「符合功能」这次全部翻成了能数的口径：拱顶比例 0.35~0.60、纸面占比 ≥0.5、印泥占比 0.02~0.08、
      相对亮度 ≥0.5、五张拱顶互差 ≤0.06，另有一条**跨文件防漂移钉**（spec 里的色槽表必须等于出图脚本里那份）。

    **三条方法论教训（细节在 7.45）**：
    ① **"对着家族比"这句话，被实现成了"比文件尺寸"**：旧那条"长边与同主题 calendar 同一档"只解 IHDR 拿宽高，五套都跑得动、一直绿，
      而我在 §7.44 另写过一条"光看文件尺寸看不出变小"—— 两条口径互相打脸却没人发现。这轮把它升级成量**墨迹**，才第一次去解 calendar 的像素，
      四套当场抛异常（那四张 calendar 是**索引色 PNG colorType 3**，解码器只吃 RGBA8）。
      **以后凡是"家族同档 / 和 X 一样"这类钉，先问一句：它比的是画上去的墨，还是文件头里的数。**
    ② **用色值阈值代理观感的钉，要问"还有谁能造出这个数"**：`shanse` 纸面 `(242,226,178)` 因蓝通道 178 差 2 掉出 `>180` 门槛，
      "亮色占比"实测只有 3.4%，而它明明是五张里最亮的一档 ⇒ 换成 WCAG 相对亮度后一条线过。
    ③ **`/api/config` 会覆盖 localStorage**（`stores/site.js:143`）：第一次量五套，五张全渲染成 classic，量具没红、红的是前提。
      要量非 classic 主题就用 CDP 的 **Fetch 域**在 Response 阶段改写那两个主题字段 —— 不改库、不挡请求，且走的是真实渲染路径。

    **顺手澄清一件比代码值钱的事**：用户原话里的「或者后台添加上传图片的功能，我自己换」**今天就能用，不用发版** ——
    后台「内容 → 站点装修」的**「邀请好友图标」**那一格早已存在（`Decoration.vue` → `CmsController.php:863-868` → `/api/config` 的 `customIcons` → `iconTheme.js` 里 custom 优先），
    线上两侧都复验过（H5 dist 有 `setCustomIcons`、后台 chunk `Decoration-ZX6bs9Xo.js` 有 `custom_icon_invite`）。
    **且这一个槽是主题无关的：上传一次，五套主题都吃它。** 以后用户说"我自己换图"，先带他用这条路，别再开发一遍。

    **上线后当场看**：第 21 条那张表的**第 ⑩ 条**（已按本批改写：五套主题的图标都是平面中式请柬）。

    **仍然没验的**：① 生产（一个字节没上）；② **观感**：五套共用一套轮廓，29px 下 `gem` 与 `classic` 只差一点色相，
      "各自像自己的家族"全靠色槽不靠造型，这条要你或真机看一眼；③ `glass` 描边对背板 **2.65:1**、`classic` 字线对纸面 **1.82:1** 是**取舍不是通过**；
      ④ 旧图 `invite-banner-bg.jpg` 仍是零引用孤儿，按第 15 条口径没动。

27. **邀请图标换成「和公告详情首图同一风格」的写实玉佩圆角小卡、五套共用一张这一批：本地全绿、生产一个字节没动，仍并进「第 9 + 第 10 那一批」**（2026-10-16 20:4x~21:2x，做完的过程见 **7.46**，本批**整体推翻第 26 条**（五张平面中式请柬）、并**作废第 25 条里"图标"那半段**；第 24 条「五套都要位图」不变、第 25/26 条的**按钮**口径不变）：
    **发版的字节动作只有两件事 + 一件刻意没做**：
    ① 传 C 端整包（`index.html` + `assets/`，入口 JS 从 `index-CPN5ux0z.js` 变成 `index-DwBlYmJd.js`）；
    ② 传**不带哈希**的静态图 `dist/images/tab/invite-jade.webp` → 生产 `.../public/images/tab/invite-jade.webp`（**新文件名**，`curl -sI` 一条命令就能判有没有）；
      ⚠️ **这一步已被第 28 条（§7.47）作废**：图标换回矢量线条，那张 webp 已从仓库撤下、且经 `curl` 验过**生产从来没有过它** ⇒ 现在这批**没有任何静态图要传、也没有图要判在不在**。
      （留着这行是为了记"当年要多传一张图"这件事，别照着它准备发版清单。）
    ③ **生产上那五张 `<前缀>-invite.png` 本批不再被引用，但我没删它们** —— 删生产文件不可逆、要单独点头，
      且后台「邀请好友图标」那一格理论上可能有人填过旧路径；留着只是几百 KB。**这是一件挂着的小事，不是遗漏。**
    基准指纹：`invite-jade.webp` `bac1d9799df84de057ef3c8cc70278c5`（256×256 / 9,724 B）、`index.html` `b63173bceb8f6cd62bf723df38588f35`、
    入口 JS `0786c197eac02cd678b707082bc971f2`、入口 CSS `92033846522c735bade8d59943964f81`（**CSS 与第 25/26 条记的逐位相同**，因为这批没动样式）。

    **三条口径**：
    ① 基线：C 端 **200 例 / 20 文件**（232 → 200，图标 spec 从 54 例重写成 22 例）、后台 **167 / 14**、后端 unit **269 / 1760**（1 条 deprecation 既有）—— 三条都是当场重跑。
    ② 反证 **10 档**（A~J），逐档只红在点名用例，跑完 8 个文件 md5 全部 SAME。**C 档是这批最该记住的一档**：
      换成一张**平面的**同尺寸 webp，只红 2 例（md5 + 体积），那几条风格钉**根本不响** —— 因为成品是 webp、CI 里没有解码器，那些数是抄进来的。
      所以这批的测试证的是「这些数属于这份字节」，**不是**「CI 量过像素」，这条限制写在了 spec 的文件头注释里。
    ③ 「和首图一样」翻成三条能数的口径（写实 = 4bit 色阶 ≥150 档、top8 ≤0.65、均色到首图距离 ≤64），
      21:4x 复核后**同一把尺重量了一遍**：新图标 4bit **357 档落在首图那族 243~414 之内**、
      精确 24bit 那把尺上 **0.031 也落在首图那族 0.027~0.080 之内**（旧五张平面在同一把尺上是 47~54 / 0.659~0.852）——
      "风格和首图一样"这句话现在**在两把尺上都成立**，不是只挑了一把能过的。
      并且**新量出一条不合格的**：小卡在真实渲染里对背板**只有 2.87:1**（WCAG 非文本线是 3:1），
      它是靠玉的高光（p95 8.06:1、最亮点 10.39:1）和外面那圈 tile 白环跳出来的，不是靠整块亮度。**要不要把暖金底提亮一档，等你看完截图点头**（改一行出图脚本的事）。

    **五条方法论教训（细节在 7.46）**：
    ① **量具必须自证三件事**：拦到几个请求、改写成功几个、页面真的换家族了没有 —— 这批在这三条上各翻了一次车：
      同 URL `Page.navigate` 对已加载的 SPA 是**空操作**（`/api/config` 压根没重发，五套量出来全是 classic）；
      `Fetch.requestPaused` 事件里的 `responseBody` **是空的**，要再调一次 `Fetch.getResponseBody`，而它返回的是 `{ body, base64Encoded }`（我把那个布尔当字符串喂给 `Buffer.from` 当场抛异常）；
      连"自检期望"自己也写错了（以为 gem 的库存图标叫 `gem-inventory.png`，实际叫 `gem-cube.png`）。
    ② **还原函数不能只覆盖不删除**：反证 I 档造出的 `gem-invite.png` 泄漏到 J 档、让 J 多红一条 —— 这是「证明拆钉之前别信绿」家族的第二条。
    ③ **换风格 = 换判据**：图从"透明抠图"变成"自带底的圆角卡"之后，旧那条"墨迹压在深底糊不糊"整个没意义，
      必须按**出厂同一个圆角遮罩**重新取样本。**凡是改了图像呈现形式的批次，先问一句"我那条判据还量得到东西吗"。**
    ④ **"同名换内容"和"换名字"是两种难度完全不同的分诊**：§7.43~§7.45 三批都是同名换内容（只能靠 md5 或肉眼），
      这批换成新文件名 ⇒ 一条 `curl -sI` 就能判。**以后给图标选名字时，能换名就别同名。**
    ⑤ **一张表里的每一列必须写明"哪把尺"，否则自我更正会在下一轮复核时才发生**：
      这批第一版那张风格表，把「精确 24bit 的 top8」（首图那几行）和「4bit 量化的 top8」（新图标那行）写进了同一列，
      看起来就像"新图标比首图平面一个数量级"，而当时给的解释（**归给了抽样分辨率**）听着也合理 ——
      直到 21:4x 拿**一把尺**把六行一起重量，才发现真正的差是量化 vs 精确色，分辨率只是次要因素，
      顺带又查出 spec 里那两个数是**压缩前**量的、而 md5 钉的是**压缩后**的字节。
      **规矩：写风格/像素类对比表时，先写清"口径"那一行（谁抽样、量化到几位、墨迹怎么算、压缩前还是压缩后），再填数；
      两把尺的读数列必须并排成两列，不许共用一列。**

    **顺手澄清一件比代码值钱的事**：用户选的「只出一张，五套共用」和后台那个上传口**是同一个形状**
    （「内容 → 站点装修 → 邀请好友图标」那一格也是主题无关、上传一次覆盖五套）。
    所以以后要再换这张图，**优先走后台上传（不发版、当场生效）**，而不是再跑一轮出图脚本 —— 测试里专门钉了一条「后台上传优先、清空后回落到共用那张」。

    **上线后当场看**：第 21 条那张表的**第 ⑩ 条**（已按本批改写：五套共用一张写实玉佩小卡；分诊清单新增「图标位是空白方块 = 那张 webp 没传」和「切主题图标跟着变 = 某个主题还指着旧文件名」两条）。

    **仍然没验的**：① 生产（一个字节没上）；② **观感**：28px 下这张写实小卡好不好看、和海报里那块玉是不是一眼一套，只能你或真机看
      （截图在仓库外的 `E:\tmp\v46\`，含新旧对照 `compare-old-new.png`）；③ **2.87:1 没过 3:1** 是量出来的**取舍**，不是通过；
    ④ 「同风格」严格说对齐的是**寄售那一张**首图（均色距离 6.2），另外三张彼此色温跨度很大（activity 与新图差 82.9）—— 别读成"四张都对齐"；
    ⑤ 生产上那五张旧 `-invite.png` 还挂着（本批刻意没删）；⑥ 旧图 `invite-banner-bg.jpg` 仍是零引用孤儿，按第 15 条口径没动。

28. **邀请图标换回「人形 + 加号」的现成矢量线条、五套共用同一份定义这一批：本地全绿、生产一个字节没动，仍并进「第 9 + 第 10 那一批」**（2026-10-17 00:5x~02:2x，做完的过程见 **7.47**，本批**整体推翻第 27 条**（写实玉佩圆角小卡）、并连带作废第 25/26 条里"图标"那半段（平面信封 / 中式请柬）；第 24~27 条定下的**海报底图、白字、透明灰按钮**三条口径**一个都没动**）：
    **发版的字节动作只有一件事**：
    ① 传 C 端整包（`index.html` + `assets/`，入口 JS 从 `index-DwBlYmJd.js` 变成 `index-CxjB609F.js`）；
    ② **没有静态图要传** —— 本批撤下一张 webp，而 `curl` 验过生产本来就没有它，**第 27 条那个 ② 步骤作废**；
    ③ **生产上那五张 `<前缀>-invite.png` 还是没删**（零引用孤儿，删生产文件要单独点头，仍是挂着的小事，不是遗漏）。
    基准指纹：`index.html` `7f306fac59d5c59da888cf7eb2677109`、入口 JS `index-CxjB609F.js` `eb96ea211f8d4440d2e0abcb4d9ab0a0`、
    入口 CSS `index-BcRoMzhL.css` `8dfdeab87beb88caf53ae47b66df5a98`。
    **CSS 换了 md5 但规则文本一个字没改**：Vue 生产构建的 scoped id = `getHash(路径 + 整个 SFC 源码)`，改了模板里那两行注释和 `color`
    就换了 `data-v-…`，每条选择器跟着换。证据：新旧两份 CSS 把 `data-v-[0-9a-f]{8}` 归一后 **236,957 B 逐位相同**。
    **所以这批起不许拿"CSS md5 变了"当"样式也变了"的证据**，改用第 21 条 ⑩ 那批 marker。

    **三条口径**：
    ① 基线：C 端 **207 例 / 20 文件**（200 → 207：图标 spec 22→25 加 3 例，另 **4 例是从图标 spec 搬到公告 spec 的**出厂首图字节钉 —— 是**换主人不是新增覆盖**，别说成"测试变多了"）、
      后台 **167 / 14**（没碰）、后端 unit **269 / 1760**（没碰，1 条 deprecation 既有）—— 三条都是当场重跑。
    ② 反证 **14 档 A~N**，逐档只红在点名用例，跑完 8 个文件 md5 全部 SAME（脚本 `E:\tmp\v47\strip-tiers47.py`、日志 `strip47-run.log`，可重跑）。
      两档要点名记：**E 档** = 把颜色退回 `#C00000`，即内置的"退回错误形状"那一档；**J 档** = 把 glass 那一行**整个删掉** ——
      运行时照样"五套一致"（store 缺键会**回落到 `classic` 的那份**），所以**只有源码结构钉拦得住**。
      **规矩：凡是"缺键会回落"的表，删行这种破坏只能用结构钉验，用运行时钉必然假绿。**
    ③ 「看得境」这次是**量出来的**：白线对那块磨砂板 **13.52:1**（WCAG 1.4.11 非文字 3:1 的四倍半），而 §7.44 定的深红只有 **2.09:1**。
      这两个数就是"用户要的一行接线"变成两行的全部理由，也是第 21 条 ⑩ 里「图标位空空的 = 半成品」那条分诊的依据。

    **两条方法论教训（细节在 7.47）**：
    ① **往 Vue 绑定的元素上打补丁做实验，"还原"必须写回它自己那份值**：`style.color = ''` 之后 **Vue 不会重写绑定**，
      截图里留着的是被我改坏的值。我第一轮就靠这个假还原造出"复拍不同"的差（678 px、最大 204），
      差点把「五套逐位一致」这条判据误判成量具坏了。显式写回 `#FFFFFF` 并打印 `getComputedStyle` 复核后，五张复拍全部逐位 SAME。
    ② **一批台账是多个段落，改了一个事实要扫全部提到它的地方**（散文式判据测试管不到，只能靠扫）：
      台账里那句 `grep -o "rgba(46,46,52,[^)]*)" 应是 .62` 从 §7.44 起就是错的（压缩器把 rgba 折成 8 位 hex `#2e2e349e`），
      而**同一批**的指纹段里明明已经写对了这个事实 —— 错因不是"不知道"，是**两处台账没对齐**；它躺了三轮没人撞过，
      因为那句只在"上线后没生效、要分诊"时才会被拿去用，真拿它就得到"CSS 里没这条规则"的错误结论。
      **规矩：留给"上线后当场看"的 grep，必须在当批 dist 上真跑一遍并把命中数写进台账**（第 21 条 ⑩ 现在每条判据都带实测命中数）。

    **一件刻意没做的事**：图标现在**不跟着卡缩放** —— SCSS 里的 28px 会被 postcss 折成 vw，而 `AppIcon` 的 size 是**行内 style**、不参与转换，
    于是图标恒定 28.00 CSS px，底板却按视口从 37.53 长到 50.45。四档量完：图标/底板 **0.746 → 0.637 → 0.612 → 0.555**，每边留白 4.77→11.23 px，**无溢出**。
    大屏会显得偏小，但这是**观感取舍、我没擅自修**（改法是一处：让 `__img` 那条尺寸规则也作用于 svg），等你或真机看完再定。

    **上线后当场看**：第 21 条那张表的**第 ⑩ 条**（已按本批**整段改写**：图标位应当是**白色线条的「人形 + 加号」**；
      原来那句「还是个单色线条小人 = 五个 `invite:` 行没覆盖上去」**方向反了**，已纠正；
      rgba 那条 grep 判据已换成压缩产物的写法；分诊改用 JS 里 `color:"#FFFFFF"` 与加号 path `M16 7v6M13 10h6` 两个 marker，每条都带实测命中数）。

    **仍然没验的**：① 生产（一个字节没上，等你单独点头）；② **观感**：小人在深色海报卡上好不好看、大屏偏不小 —— 只能你或真机看
      （截图在仓库外的 `E:\tmp\v47\`：整卡 `card-*.png`、底板 `plate-*.png` / `-zoom` / `-noink` / `-red` 控制组 / `-again` 复拍）；
    ③ 后台切功能图标主题时那一格的**过渡**没量，只量了静息态的逐位一致；
    ④ `invite-banner-bg.jpg` 与生产那五张 `-invite.png` 仍是零引用孤儿，按第 15 条口径没动；
    ⑤ §7.46 那条「卡内均值 2.87:1 没过 3:1」随位图一起作废，但它当初指向的问题（图标在一块暗板上够不够跳）**这次是量过并且答了**：13.52:1。


29. **文物展览区（`/mall`）顶部头图换成「深暗底青铜方鼎」、两行字跟着翻白这一批：本地全绿、生产一个字节没动，仍并进「第 9 + 第 10 那一批」**（2026-10-17 11:0x~11:5x，做完的过程见 **7.48**。这块格子**以前没有任何测试钉过、§7.x 也从没碰过**，所以本批是**开荒、不推翻任何人**；邀新卡那五轮（§7.43~§7.47）的口径**一个字节没被本批碰到**）：
    **发版的字节动作有两件，第二件容易漏**：
    ① 传 C 端整包（`index.html` + `assets/`，入口 JS 从 `index-CxjB609F.js` 变成 **`index-Zr733Myd.js`**；
      ⚠ 本批内部换过一次名：首版是 `index-xJ2yDZ_o.js`，后来一句注释触发重建才成 `Zr733Myd` —— **以现在 dist 里的这个为准**，别拿台账里出现过的前一个名字去线上找）；
    ② **必须连这张静态图一起传**：`sinan-art-source/dist/images/exhibits/exhibit-header-bronze-ding-v2.webp`
      → 生产 `/www/wwwroot/sinan-h5/images/exhibits/`（基准 md5 `d09524b2440f316d4553bc7a84b33df2`、52,972 B、1280×490）。
      **它不在带哈希的 `assets/` 里，只挑 `assets/` 传必漏**（§7.42 / §7.43 栽过同一个坑），
      漏了**不报错、不白屏**，只是那块头图变成**一片死黑**（CSS 里那层压暗渐变还在，字仍然白，所以看起来像"图坏了"而不是"图没传"）。
      ⚠ 第 28 条那句「这批没有静态图要传」**只适用于那一批**，本批重新有了 —— 别照着 28 条的步骤少传一个文件。
    ③ **旧图故意留着**：`exhibit-header-ancient.jpg`（523,525 B）本地和生产都还在，旧 dist 的 CSS 还引用它，回滚正好用得到；
      要不要清是另一个单独决定（删生产文件不可逆，按规矩等点头）。
    基准指纹（本批 dist，三条都是**注释改写、重新构建之后**当场量的）：`index.html` `777728c0a3706a0e6787aac2a99e07e5`、
    入口 JS `assets/index-Zr733Myd.js` `b123d1c4e4898ad1ad436d918af44a68`、入口 CSS `assets/index-Bw6BQyUA.css` `273f5ae3c2c685c19f95070c53de2673`。
    回滚点：这批**只动 C 端**（`Mall.vue` 一个源文件 + 一张图 + 一份新 spec + 一个新脚本，后端/库/迁移/后台前端一个字节没动），
    回滚 = 把上一批那对 `index.html + assets/` 覆盖回去，静态图不用动。

    **三条口径**：
    ① 基线：C 端 **225 例 / 21 文件**（207 → 225，**净 +18 全部是新那一份 `tests/exhibit-header.spec.js`**，其余一条没动 —— 别说成"改造了测试"）、
      后台 **167 / 14**（没碰）、后端 unit **269 / 1760**（没碰，1 条 deprecation 既有）。三条都是当场重跑。
    ② 反证 **12 档 A~L + 1 个「什么都没动」的对照档**（`E:\tmp\v48\strip-tiers48.py`，日志 `strip-tiers48.txt`/`.json`）：
      A2 B1 C2 D2 E1 F1 G1 H1 I1 J1 **K5** L1，对照档红 0，跑完三个文件 md5 全 SAME。
      两档要点名：**B 档 = 换图不改数**（同尺寸 q90 重编码，52,972→65,370 B）—— 只有 md5/体积那条响，说明**像素级那些数是绑在字节上的**；
      **L 档 = 改数不改图**（把 md5 常量改掉）—— 与 B 合起来才叫"绑死"。**规矩（§7.46 立的，这批在新格子上复用）：凡是把实测数抄进测试的地方，必须同时钉"这些数属于哪份字节"。**
      ⚠ 抄数这件事本批**当天返过一次工**（首版 `CONTRAST.cdp` 把两档的读数混成一组），改完后 18 例与这 13 档**整批重跑，红点位置一字未变**；
      原委见 §7.48 那张对比度表下面的订正框，教训落在 AGENTS 第四节 28 条⑤。
    ③ **「看得见」这次是两套独立实现互相印证**：离线 20.37 / 14.52，真渲染**四档各自最坏** **12.96（标题）/ 11.49（副标）**，
      门槛 3:1 与 4.5:1，余量三倍与两倍半；**控制组（旧深棕字）四档都是"比背景板亮的点 0 个"** —— 这条就是"换图必须连字色一起换"的证据，
      也是第 21 条 ⑪ 里「图在但字看不清 = CSS 是旧的」那条分诊的依据。

    **两条方法论教训（细节在 7.48）**：
    ① **反证 harness 第四次差点假绿**：第一版 12 档全报"红 0 条"，而 vitest 的 exit code 是 1。
      错因是**非 TTY 下 vitest 仍然上色**，红行真实字节是 `\x1b[31m×\x1b[39m tests/…\x1b[2m > \x1b[22m…`，
      我那条正则要 `× ` 和 ` > `，被转义符打断 ⇒ 一条也提不到，而"没抓到红"被我当成了"没有红"。
      **规矩加两条：抓 vitest 输出之前先剥 ANSI（`re.sub(r"\x1b\[[0-9;]*m","",out)`）；`returncode != 0` 却抓不到用例名一律判"没跑起来"，并永远先跑一个"什么都没动的对照档"，基线不干净就中止。**
      （同族前两次：§7.37 那 11 档 cmd.exe 冲烂 UTF-8 的假红、§7.43 那条 `execFileSync` ENOENT 被吞掉的假绿、§7.46 那次基线不干净。同一个毛病第四次发作，这次连"红 0"都能造出来。）
    ② **水印检测不能数"近白像素"**：第一版在**已经裁干净**的结果上报 184 个近白点 ⇒ 误报，因为锈蚀与石头的去饱和高光本身就近白。
      改成**按行统计**（`max>185 且 max-min<22`，某行 ≥6 个才算一条带）+ 脚本里 assert「裁切上沿 ≤ 水印带顶 − 15」才既不误报也不漏报。
      **通用一句（第四节 20 条②那条的同族）：先问"这个数是被我要找的东西产生的，还是被画面里那个大东西产生的"。**

    **上线后当场看**：第 21 条那张表新增的**第 ⑪ 条**（展览区头图；三条分诊各带实测 grep 命中数与 `content_type` 判据 ——
      旧 dist = 还是浅底青绿山水；静态图漏传 = 一块死黑但字清楚；只传图没传 CSS = 字看不清。
      ⚠️ 那层压暗渐变在产物里是 `linear-gradient(180deg,#00000014,#0000008c)`，**`grep "rgba(0,0,0,0.55)"` 永远命中 0**；
      `[data-v-…]` 那串哈希**不许当 marker**；副标那 85% 白折成 `#ffffffd9`）。

    **仍然没验的**：① 生产（一个字节没上，等你单独点头）；② **观感**：这张暗底方鼎配白字真机上好不好看、和公告首图那四张是不是"一眼一套"
      —— 只能你或真机看（`E:\tmp\v48\`：三张候选 `cand-*.png`、按真实 CSS 铺过字的三张预览 `prev-*.png`、四档头图 `header-*.png` 及 `-notext` / `-brown` / `-again`，四张复拍与首拍**逐位 SAME**）；
      ③ 候选横向表那三个数（13.25 / 1.89 / 7.80）**脚本没落盘、可核对不可重跑**，所以它只用于"三张挑哪张"，不当验收数引用；
      ④ 头图**底下那排分类 tab 与新底的接缝**没量；⑤ 旧 jpg 的删除没做（不可逆，等点头）。