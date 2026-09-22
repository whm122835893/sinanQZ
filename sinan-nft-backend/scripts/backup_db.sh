#!/usr/bin/env bash
# ============================================================
# 司南珍藏 · 数据库每日备份脚本
# ============================================================
# mysqldump 全库 → gzip 压缩 → 完整性校验 → 按保留天数清理
#
# 用法（参数均有默认值，可按需覆盖）：
#   ./scripts/backup_db.sh
#   ./scripts/backup_db.sh -u sinan_app -p 'xxx' -k 14
#
# 推荐 crontab（每天 03:00，日志追加到 runtime/backup.log）：
#   0 3 * * * cd /path/to/sinan-nft-backend && ./scripts/backup_db.sh >> runtime/backup.log 2>&1
#
# 恢复方法：
#   gunzip -c runtime/backups/sinan_nft_YYYYMMDD_HHMMSS.sql.gz | mysql -h127.0.0.1 -uroot sinan_nft
set -euo pipefail

HOST="127.0.0.1"; PORT="3306"; USER="root"; PASS=""
DB="sinan_nft"; OUT_DIR="runtime/backups"; KEEP_DAYS=7

while getopts "h:P:u:p:d:o:k:" opt; do
  case "$opt" in
    h) HOST="$OPTARG" ;;
    P) PORT="$OPTARG" ;;
    u) USER="$OPTARG" ;;
    p) PASS="$OPTARG" ;;
    d) DB="$OPTARG" ;;
    o) OUT_DIR="$OPTARG" ;;
    k) KEEP_DAYS="$OPTARG" ;;
    *) echo "用法: $0 [-h host] [-P port] [-u user] [-p password] [-d db] [-o 输出目录] [-k 保留天数]" >&2; exit 1 ;;
  esac
done

mkdir -p "$OUT_DIR"
STAMP="$(date +%Y%m%d_%H%M%S)"
FILE="$OUT_DIR/${DB}_${STAMP}.sql.gz"

# --single-transaction：InnoDB 一致性快照，不锁表
# MYSQL_PWD 环境变量传密，避免密码出现在进程列表
MYSQL_PWD="$PASS" mysqldump -h"$HOST" -P"$PORT" -u"$USER" \
  --single-transaction --routines --triggers --set-gtid-purged=OFF \
  "$DB" | gzip > "$FILE"

# 完整性校验：解压后末行必须是 Dump completed
if ! gunzip -c "$FILE" | tail -n 1 | grep -q "Dump completed"; then
  echo "[$(date '+%F %T')] ERROR: 备份文件不完整，已保留供排查：$FILE" >&2
  exit 1
fi

# 清理超过保留天数的旧备份
find "$OUT_DIR" -name "${DB}_*.sql.gz" -mtime +"$KEEP_DAYS" -delete

echo "[$(date '+%F %T')] OK: $(du -h "$FILE" | cut -f1)  $FILE"
