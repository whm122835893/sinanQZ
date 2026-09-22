# 司南珍藏 · 部署与上线指南

## 一、架构与端口

| 组件 | 技术栈 | 端口 | 说明 |
|------|--------|------|------|
| C 端 | Vue 3 + Vite (`sinan-art-source`) | 5174（开发） | 生产构建 `dist/` 由 Nginx 托管 |
| 管理端 | Vue 3 + Vite (`sinan-admin`) | 5175（开发） | 同上，建议限内网/IP 白名单访问 |
| 后端 API | ThinkPHP 8 (`sinan-nft-backend`) | 8080 | 生产建议 php-fpm + Nginx 反代 |
| 数据库 | MySQL 8 (`sinan_nft`) | 3306 | 仅监听 127.0.0.1 |

前端请求经代理转发 `/api`、`/api/admin` → 后端（开发态 Vite proxy，生产态 Nginx）。

## 二、首次部署

```bash
# 1. 后端
cd sinan-nft-backend
composer install --no-dev --optimize-autoloader
cp .env.example .env      # 按注释替换全部 change-me（密钥 openssl rand -hex 32）

# 2. 数据库（导入全量基线，用 root 一次性执行）
mysql -h127.0.0.1 -uroot -p < ../database/full_init.sql

# 3. 创建运行时业务账号（仅 DML 权限，写入 .env 的 DATABASE 段）
mysql -h127.0.0.1 -uroot -p -e "
CREATE USER 'sinan_app'@'127.0.0.1' IDENTIFIED BY '<强密码>';
GRANT SELECT, INSERT, UPDATE, DELETE ON sinan_nft.* TO 'sinan_app'@'127.0.0.1';
FLUSH PRIVILEGES;"

# 4. 前端（C 端与管理端相同）
cd ../sinan-art-source && npm ci && npm run build   # 产物 dist/

# 5. 启动（开发态验证）
php think run -p 8080   # 后端
```

生产态：Nginx 托管两个 `dist/`，`/api` 与 `/api/admin` 反代到 php-fpm；全站 HTTPS。

## 三、定时任务清单（crontab）

```cron
# 定时上架/定时开盒（每分钟，必须配置，否则后台设置的定时上架不生效）
* * * * * cd /path/to/sinan-nft-backend && php think ScheduleDispatch >> runtime/schedule.log 2>&1

# 数据库每日备份（03:00，保留 7 天，可 -k 调整）
0 3 * * * cd /path/to/sinan-nft-backend && ./scripts/backup_db.sh >> runtime/backup.log 2>&1
```

注：订单 5 分钟未支付自动失效采用查询时懒判定，无需 cron；其余业务无后台任务依赖。

## 四、上线检查清单

**密钥与配置**
- [ ] `APP_DEBUG = false`（关闭后 AES/JWT 弱密钥自动 fail-closed，启动即报错即拦截）
- [ ] `APP_KEY` / `JWT.SECRET` / `JWT.ADMIN_SECRET` 均为独立随机串（`openssl rand -hex 32`）
- [ ] 数据库使用 `sinan_app` 独立账号，非 root；`.env` 权限 600
- [ ] 生产已有存量数据且需更换 `APP_KEY` 时，先执行 `php think rekey:encrypted <旧> <新> --dry-run` 预览再执行

**账号安全**
- [ ] 修改管理端默认账号 admin 的密码（或 `php think AdminResetPassword` 重置）
- [ ] 确认短信/支付渠道密钥已在后台「系统配置」录入（生产走真实通道，非 mock）

**Nginx / HTTPS**
- [ ] 全站 HTTPS（HTTP 301 跳转）；`runtime/`、`.env`、`git` 目录禁止 Web 访问
- [ ] 管理端域名/IP 白名单或 VPN 内网访问
- [ ] 上传/接口 body 大小限制、基础限流

**数据安全**
- [ ] 手动执行一次 `./scripts/backup_db.sh` 并在测试库演练恢复
- [ ] 备份文件同步到异机/对象存储（脚本仅本地保留 7 天）
- [ ] MySQL 仅监听 127.0.0.1，开启 binlog（可选，利于点恢复）

**合规（国内运营）**
- [ ] ICP 备案；数字藏品业务按当地监管要求完成相关资质/备案评估

## 五、常用运维命令

```bash
# 备份 / 恢复
./scripts/backup_db.sh -u sinan_app -p 'xxx'
gunzip -c runtime/backups/sinan_nft_YYYYMMDD_HHMMSS.sql.gz | mysql -h127.0.0.1 -uroot -p sinan_nft

# 管理员重置密码
php think AdminResetPassword

# AES 密钥轮换（换 APP_KEY 前必做）
php think rekey:encrypted <旧密钥> <新密钥> --dry-run

# 手动触发一次定时任务（验证 cron 配置）
php think ScheduleDispatch
```
