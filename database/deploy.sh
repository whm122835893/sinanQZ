#!/usr/bin/env bash
# =====================================================================
# 司南ART 数据库完整部署脚本
#
# 用法：
#   bash database/deploy.sh            # 重建并全量部署
#   bash database/deploy.sh --verify   # 只做校验，不重建
#
# 关键点（踩坑记录）：
#   mysql -u... < file.sql  不带数据库名时，若 SQL 文件内部没有 USE 语句，
#   整份文件会因为 "No database selected" 静默失败。因此必须显式传数据库名。
# =====================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_DIR="$ROOT/database"
MIG_DIR="$ROOT/sinan-nft-backend/migrations"

MYSQL_BIN="${MYSQL_BIN:-mysql}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3399}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_NAME="${DB_NAME:-sinan_nft}"

MYSQL_ARGS=("$MYSQL_BIN" -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_ARGS+=(-p"$DB_PASS")

# ---------------------------------------------------------------- 基础库表
# 顺序依据 README.md「快速开始」章节，不可随意调整。
# 特别注意：raffle_purchase_upgrade.sql 提供 purchased_quantity 列，
#           raffle_admin_upgrade.sql 中有 AFTER `purchased_quantity` 依赖，
#           顺序颠倒会导致 1054 Unknown column 并中断该文件中后续全部语句。
# 注意：mysql 客户端默认遇错即停（非 --force），单条失败会静默吞掉整个文件剩余内容。
BASE_FILES=(
  init.sql
  admin_init.sql
  fusion_upgrade.sql
  full_feature_upgrade.sql
  swap_plan_upgrade.sql
  rbac_snapshot_upgrade.sql
  marketing_activity_upgrade.sql
  activity_reward_upgrade.sql
  raffle_draw_code_system_upgrade.sql
  raffle_purchase_upgrade.sql
  raffle_admin_upgrade.sql
  admin_sms_scene_upgrade.sql
  batch_buy_upgrade.sql
  category_scene_upgrade.sql
  collectible_recover_upgrade.sql
  fusion_final_upgrade.sql
  payment_yeepay_upgrade.sql
  swap_c2c_removal.sql
  # ---- 以下 4 个为 README 未登记的补丁，按语义排在后方 ----
  002_add_snapshots.sql
  announcement_publish_upgrade.sql
  artifact_status_upgrade.sql
  refund_idempotency_upgrade.sql
)

apply_sql() {
  local file="$1" label="$2"
  local out rc
  out=$("${MYSQL_ARGS[@]}" "$DB_NAME" < "$file" 2>&1)
  rc=$?
  if [ $rc -eq 0 ] && [ -z "$out" ]; then
    echo "  [OK]   ${label}"
    return 0
  fi
  # 幂等错误码：对象已存在，属「重复应用」，不算失败
  #   1060 duplicate column / 1061 duplicate key / 1062 duplicate entry / 1091 can't drop
  if echo "$out" | grep -qE "^ERROR (1060|1061|1062|1091)" && \
     ! echo "$out" | grep -E "^ERROR" | grep -qvE "^ERROR (1060|1061|1062|1091)"; then
    echo "  [SKIP] ${label} (对象已存在，幂等跳过)"
    return 0
  fi
  # 脚本内的 SELECT 打印＝正常执行，不是错误（如 SELECT 1 / SHOW COLUMNS 校验输出）
  if ! echo "$out" | grep -qE "^ERROR"; then
    local n; n=$(echo "$out" | wc -l | tr -d ' ')
    echo "  [OK]   ${label} (附带 ${n} 行脚本输出)"
    return 0
  fi
  echo "  [FAIL] ${label}: $out"
  return 1
}

echo "=============================================="
echo " 司南ART 数据库部署 -> ${DB_NAME} @ ${DB_HOST}:${DB_PORT}"
echo "=============================================="

if [ "${1:-}" != "--verify" ]; then
  "${MYSQL_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  echo "[1/3] 数据库已重建"
else
  echo "[1/3] 跳过重建（--verify 模式）"
fi

echo "[2/3] 应用基础库表 SQL"
pass=0; fail=0
for f in "${BASE_FILES[@]}"; do
  path="$DB_DIR/$f"
  [ -f "$path" ] || { echo "  [SKIP] $f (文件不存在)"; continue; }
  if apply_sql "$path" "$f"; then pass=$((pass+1)); else fail=$((fail+1)); fi
done
echo "      基础 SQL: 成功 $pass / 告警 $fail"

echo "[3/3] 应用后端 migrations"
mpass=0; mfail=0
for path in "$MIG_DIR"/*.sql; do
  [ -e "$path" ] || continue
  if apply_sql "$path" "migrations/$(basename "$path")"; then mpass=$((mpass+1)); else mfail=$((mfail+1)); fi
done
echo "      迁移 SQL: 成功 $mpass / 告警 $mfail"

echo "----------------------------------------------"
echo " 部署结果统计"
echo "----------------------------------------------"
"${MYSQL_ARGS[@]}" "$DB_NAME" -e "
SELECT
  (SELECT COUNT(*) FROM information_schema.tables  WHERE table_schema='${DB_NAME}') AS tables,
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='${DB_NAME}') AS columns,
  (SELECT COUNT(*) FROM nft_system_configs)  AS system_configs,
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='${DB_NAME}' AND column_name='release_quantity') AS release_qty_col,
  (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema='${DB_NAME}' AND constraint_type='FOREIGN KEY') AS foreign_keys,
  (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name IN ('nft_raffle_activities','nft_raffle_registrations')) AS raffle_tables;
" 2>&1

# 期望值：tables=80  columns=981  foreign_keys=75  release_qty_col=1  raffle_tables=2
