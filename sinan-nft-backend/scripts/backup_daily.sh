#!/usr/bin/env bash
# 生产环境每日备份包装（crontab 03:00 调它）
# 密码取自 /root/.sinan_app_pass（600，仅 root 可读），不写进 crontab、不进仓库。
#
# 两步分开跑，且**数据库备份失败也要继续备图片**（RUNBOOK 7.20 S1）：
# 以前只有一条 exec 的数据库备份，图片文件完全没备。
export PATH=/www/server/mysql/bin:/usr/local/bin:/usr/bin:/bin
cd /www/wwwroot/sinan-backend || exit 1
PASS="$(head -1 /root/.sinan_app_pass)"

./scripts/backup_db.sh -u sinan_app -p "$PASS" -k 7 \
  || echo "[$(date '+%F %T')] ERROR: 数据库备份失败（继续备份图片文件）"

./scripts/backup_files.sh -s public/uploads -o runtime/backups -k 7
