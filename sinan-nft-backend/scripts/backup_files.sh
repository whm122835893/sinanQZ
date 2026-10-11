#!/usr/bin/env bash
# ============================================================
# 司南珍藏 · 上传文件每日备份脚本
# ============================================================
# 整目录打包 tar.gz → 完整性校验 → 按保留天数清理
#
# 为什么单独一个脚本：`backup_db.sh` 只导数据库。图片文件此前**不在任何备份里**
# （RUNBOOK 7.20 S1）—— 数据库能回滚，图片丢了就是真没了。
#
# 用法（参数均有默认值，可按需覆盖）：
#   ./scripts/backup_files.sh
#   ./scripts/backup_files.sh -s public/uploads -o runtime/backups -k 7
#
# 恢复方法（先解到临时目录，再整体放回，**记得改回 web 用户属主**）：
#   mkdir -p /tmp/restore && tar -xzf runtime/backups/uploads_20261012_030001.tar.gz -C /tmp/restore
#   cp -a /tmp/restore/uploads/. /www/wwwroot/sinan-backend/public/uploads/
#   chown -R www:www /www/wwwroot/sinan-backend/public/uploads
set -euo pipefail

SRC_DIR="public/uploads"; OUT_DIR="runtime/backups"; KEEP_DAYS=7; LABEL=""

while getopts "s:o:k:l:" opt; do
  case "$opt" in
    s) SRC_DIR="$OPTARG" ;;
    o) OUT_DIR="$OPTARG" ;;
    k) KEEP_DAYS="$OPTARG" ;;
    l) LABEL="$OPTARG" ;;
    *) echo "用法: $0 [-s 源目录] [-o 输出目录] [-k 保留天数] [-l 文件名前缀]" >&2; exit 1 ;;
  esac
done

[ -n "$LABEL" ] || LABEL="$(basename "${SRC_DIR%/}")"
mkdir -p "$OUT_DIR"

# 输出目录套在源目录里面会把备份自己卷进备份，越滚越大，直接拒绝
case "$(readlink -f "$OUT_DIR")/" in
  "$(readlink -f "$SRC_DIR")"/*) echo "[$(date '+%F %T')] ERROR: 输出目录在源目录内，会自我嵌套：$OUT_DIR" >&2; exit 1 ;;
esac

if [ ! -d "$SRC_DIR" ]; then
  echo "[$(date '+%F %T')] SKIP: 源目录不存在，没有文件可备：$SRC_DIR"
  exit 0
fi

STAMP="$(date +%Y%m%d_%H%M%S)"
FILE="$OUT_DIR/${LABEL}_${STAMP}.tar.gz"
BEFORE="$(find "$SRC_DIR" -type f | wc -l)"

# -C 父目录 + 相对名：包里是 uploads/...，解出来不会散一地
tar -czf "$FILE" -C "$(dirname "${SRC_DIR%/}")" "$(basename "${SRC_DIR%/}")"

# 完整性校验：① 能列目录（gzip 头和 tar 结构没坏）② 条目数不少于打包前的文件数
if ! tar -tzf "$FILE" >/dev/null 2>&1; then
  echo "[$(date '+%F %T')] ERROR: 备份包打不开，已保留供排查：$FILE" >&2
  exit 1
fi
ENTRIES="$(tar -tzf "$FILE" | grep -cv '/$' || true)"
if [ "$ENTRIES" -lt "$BEFORE" ]; then
  echo "[$(date '+%F %T')] ERROR: 备份包少文件（打包前 $BEFORE 个，包里 $ENTRIES 个）：$FILE" >&2
  exit 1
fi

# 清理超过保留天数的旧包
find "$OUT_DIR" -name "${LABEL}_*.tar.gz" -mtime +"$KEEP_DAYS" -delete

echo "[$(date '+%F %T')] OK: $(du -h "$FILE" | cut -f1)  ${ENTRIES} 个文件  $FILE"
