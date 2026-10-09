# 司南珍藏 · 后端（ThinkPHP 8 多应用）

数字藏品平台的接口层，一套代码两个应用：`api`（C 端 H5）与 `admin`（管理后台）。
项目整体说明看仓库根目录的 [README.md](../README.md) 与 [PROJECT_DOC.md](../PROJECT_DOC.md)；
上线与运维集中在 [deploy/RUNBOOK.md](../deploy/RUNBOOK.md)。
框架本身的说明见 [LICENSE.txt](LICENSE.txt) 与官方手册，这份文件只讲本项目怎么用这套后端。

## 目录职责

| 位置 | 作用 |
|---|---|
| `public/index.php` | 唯一入口，Nginx 站点 root 指到这里 |
| `route/api.php` | C 端路由，统一 `/api` 前缀 |
| `app/admin/route/app.php` | 管理端路由，统一 `/admin` 前缀 |
| `app/controller/` | C 端控制器 |
| `app/admin/controller/` | 管理端控制器 |
| `app/service/`、`app/admin/service/` | 业务逻辑（钱包、库存、实名、抽奖、短信、链、快照、上传清理等） |
| `app/middleware/` | `JwtAuth`（必须登录）、`OptionalJwtAuth`（带令牌就认人、不带照常匿名）、`Cors` |
| `app/admin/middleware/` | `AdminAuth`（令牌 + 实时状态）、`AdminPermission`（权限码精确校验） |
| `app/command/` | 4 个 `think` 命令，见下 |
| `migrations/` | 增量 SQL，按文件名日期排序，也被 `../database/deploy.sh` 应用 |
| `tests/` | PHPUnit 用例 |
| `docs/DEPLOY.md` | 简版部署指南；生产实际步骤以 `../deploy/RUNBOOK.md` 为准 |

`config/route.php` 里 `url_route_must = true`：**没写进路由文件的 action，线上根本请求不到**。
所以新增接口必须先加路由；反过来，路由文件里能看到的都还在用。

## 本地跑起来

```bash
composer install                              # vendor/ 不入库，要自己装
cp .env.example .env                          # 把所有 change-me 换成真实值
php -S 127.0.0.1:8080 -t public public/router.php
```

`.env.example` 顶部那几条硬约束别绕过去：`APP_DEBUG=false` 时若 `APP_KEY` /
`JWT.SECRET` / `JWT.ADMIN_SECRET` 还留着占位串，代码会直接抛异常拒绝启动（fail-closed），
防止照抄示例配置上线。密钥各自独立生成，不要复用。

**换 `APP_KEY` 之前必须先轮换存量密文**，否则实名信息、短信密钥、支付渠道配置、
链网络密钥全部解不开（数据变砖）：

```bash
php think rekey:encrypted <旧密钥> <新密钥> --dry-run   # 先看影响面
php think rekey:encrypted <旧密钥> <新密钥>             # 再执行
```

## 四个 think 命令

| 命令 | 用途 |
|---|---|
| `think ScheduleDispatch` | 定时上架 / 定时开盒等到期任务，生产由 cron 每分钟拉起（缺了会静默故障） |
| `think admin:reset-password <账号>` | 重置后台管理员密码；省略第二个参数会随机生成一个强密码并只打印一次 |
| `think rekey:encrypted` | APP_KEY 轮换，见上 |
| `think ApiDoc` | 扫描控制器里的 `@openapi` 注释，产出 `runtime/openapi.json` 与 `public/api-docs.html` |

## 测试

```bash
php vendor/bin/phpunit --testsuite unit   # 纯逻辑：脱敏、加解密、库存计算，不碰数据库
php vendor/bin/phpunit                    # unit + api 流程套件（api 那批需要本地库和已启动的服务）
```

`tests/unit/` 是无副作用的单元测试；`tests/` 根目录那批是接口流程回归。
仓库外还有一组端到端 / 系统集成脚本在 `../qa/`，它们靠 `APP_ENV=sit` 加 `QA_DB_*`
指向沙箱库，**不要对着生产库执行**。
