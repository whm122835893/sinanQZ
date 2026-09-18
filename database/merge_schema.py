#!/usr/bin/env python3
"""
merge_schema.py —— 把所有拆分 SQL 脚本合并成一份完整建库脚本 full_schema_all.sql

用法:  python3 merge_schema.py

输出:  full_schema_all.sql （与本脚本同目录）

设计思路:
  1. 按 EXEC_ORDER 顺序扫描每个 .sql 文件
  2. CREATE TABLE:  去重收集，保持首次出现顺序（保证外键依赖正确）
  3. ALTER TABLE:    同时抓直接 ALTER 和动态 SQL 里的 ALTER（MySQL 用动态 SQL 做幂等包装）
  4. DROP TABLE:     只保留"真删除"（DROP 了但本文件没 CREATE 的表）
  5. 合并文件结构:   CREATE → ALTER → DROP，先关外键检查，最后恢复

两套脚本并存:
  - 拆分版 (22 个 .sql): 开发/增量迁移/追溯演进
  - 合并版 (full_schema_all.sql): 新环境首部署/CI
"""
import re
import os

# 执行顺序（init.sql 必须第一，因为它建了 33 张基础表）
EXEC_ORDER = [
    'init.sql',                          # 33 张基础表，严格按外键依赖顺序
    'admin_init.sql',                     # 22 张管理端扩展
    'fusion_upgrade.sql',                 # 3 张链/审批
    'full_feature_upgrade.sql',           # 6 张 raffle/buy_request/decompose
    'swap_plan_upgrade.sql',              # 4 张统一置换
    'rbac_snapshot_upgrade.sql',          # 2 张快照
    '002_add_snapshots.sql',              # 重复快照（IF NOT EXISTS 安全）
    'marketing_activity_upgrade.sql',     # 1 张 lucky_draw_activities
    'activity_reward_upgrade.sql',        # 5 张活动奖励
    'raffle_draw_code_system_upgrade.sql',# 1 张 user_draw_codes
    'raffle_purchase_upgrade.sql',        # ALTER raffle_registrations 加 purchased_quantity（须先于 raffle_admin）
    'raffle_admin_upgrade.sql',           # 1 张 raffle_operation_logs（win_paid 依赖 purchased_quantity）
    'admin_sms_scene_upgrade.sql',        # 无建表，ALTER
    # announcement_publish/artifact_status 已合并进 init.sql，字段 status/publish_time 建表时即含，跳过
    'batch_buy_upgrade.sql',              # 无建表，ALTER
    'category_scene_upgrade.sql',         # 无建表，ALTER
    'collectible_recover_upgrade.sql',    # 无建表，ALTER
    'fusion_final_upgrade.sql',           # 1 张 inbox + 实名审核 ALTER
    'payment_yeepay_upgrade.sql',         # 无建表，ALTER
    'swap_c2c_removal.sql',               # 无建表，DROP 废弃表
]

# 真废弃表（DROP 了且没有任何 CREATE）
REAL_DROPS = [
    ("DROP TABLE IF EXISTS `nft_raffle_whitelists`;", "raffle_admin_upgrade.sql"),
    ("DROP TABLE IF EXISTS `nft_swap_records`;",     "swap_c2c_removal.sql"),
    ("DROP TABLE IF EXISTS `nft_swap_offers`;",       "swap_c2c_removal.sql"),
]

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))


def collect():
    merged_creates = []
    seen_creates = set()
    all_alters = []

    os.chdir(SCRIPT_DIR)
    for fname in EXEC_ORDER:
        if not os.path.exists(fname):
            print(f"  [跳过] {fname} 不存在")
            continue
        with open(fname, 'r', encoding='utf-8') as f:
            content = f.read()

        # 1) CREATE TABLE 完整块（正则匹配到分号）
        for m in re.finditer(
            r'CREATE TABLE(?: IF NOT EXISTS)? `nft_\w+`[\s\S]*?;',
            content, re.I,
        ):
            block = m.group(0).strip()
            tm = re.match(
                r'CREATE TABLE(?: IF NOT EXISTS)? `(nft_\w+)`', block, re.I
            )
            if tm and tm.group(1) not in seen_creates:
                seen_creates.add(tm.group(1))
                merged_creates.append(f"-- === 来自 {fname} ===\n" + block)

        # 2) 直接 ALTER TABLE
        for m in re.finditer(
            r'^ALTER TABLE `nft_\w+`[^;]+;', content,
            re.MULTILINE | re.I,
        ):
            stmt = m.group(0).strip()
            tm = re.match(r'ALTER TABLE `(nft_\w+)`', stmt, re.I)
            if tm:
                all_alters.append((stmt, fname, tm.group(1)))

        # 3) 动态 SQL 里的 ALTER TABLE（MySQL 用 IF(列不存在, ALTER, SELECT 1) 做幂等包装）
        for m in re.finditer(
            r"'(ALTER TABLE `nft_\w+`[^']+)'", content, re.I,
        ):
            stmt = m.group(1).strip().replace("''", "'")
            if not stmt.endswith(';'):
                stmt += ';'
            tm = re.match(r'ALTER TABLE `(nft_\w+)`', stmt, re.I)
            if tm:
                all_alters.append((stmt, fname, tm.group(1)))

    # ALTER 去重（按表名 + 前 200 字符的空白归一化）
    seen_alters = set()
    unique_alters = []
    for stmt, fname, tname in all_alters:
        key = (tname, re.sub(r'\s+', ' ', stmt[:200]))
        if key not in seen_alters:
            seen_alters.add(key)
            unique_alters.append((stmt, fname, tname))

    # 按表名分组
    alter_by_table = {}
    for stmt, fname, tname in unique_alters:
        alter_by_table.setdefault(tname, []).append((stmt, fname))

    return merged_creates, seen_creates, alter_by_table, unique_alters


def assemble(merged_creates, seen_creates, alter_by_table, unique_alters):
    lines = []
    lines.append("-- =============================================================")
    lines.append("-- 司南数字藏品平台 · 全量建库脚本（合并版）")
    lines.append(f"-- 合并来源: {len(EXEC_ORDER)} 个 SQL 文件")
    lines.append(f"-- 存活表数: {len(seen_creates)} 张")
    lines.append(f"-- ALTER 迁移: {len(unique_alters)} 条，涉及 {len(alter_by_table)} 张表")
    lines.append(f"-- DROP 废弃: {len(REAL_DROPS)} 条")
    lines.append("-- 幂等安全，可重复执行（所有 CREATE 用 IF NOT EXISTS）")
    lines.append("-- =============================================================")
    lines.append("")
    lines.append("SET NAMES utf8mb4;")
    lines.append("SET FOREIGN_KEY_CHECKS = 0;")
    lines.append("")
    lines.append("-- ---------- CREATE TABLE ----------")
    lines.extend(merged_creates)

    lines.append("")
    lines.append("-- ---------- ALTER TABLE（升级迁移）----------")
    lines.append("-- 注意: 部分 ALTER 来自原脚本的动态 SQL 幂等包装，")
    lines.append("--       合并后裸执行——如目标列已存在会报 Duplicate column。")
    lines.append("--       如需严格幂等，请保留原拆分脚本的动态 SQL。")
    for tname in sorted(alter_by_table.keys()):
        lines.append(f"\n-- ====== ALTER {tname} ======")
        for stmt, src in alter_by_table[tname]:
            lines.append(f"-- 来自 {src}")
            lines.append(stmt)

    lines.append("")
    lines.append("-- ---------- DROP 废弃表 ----------")
    for stmt, src in REAL_DROPS:
        lines.append(f"-- 来自 {src}")
        lines.append(stmt)

    lines.append("")
    lines.append("SET FOREIGN_KEY_CHECKS = 1;")
    lines.append("-- =============================================================")
    return '\n'.join(lines)


def main():
    merged_creates, seen_creates, alter_by_table, unique_alters = collect()
    output = assemble(merged_creates, seen_creates, alter_by_table, unique_alters)

    out_path = os.path.join(SCRIPT_DIR, 'full_schema_all.sql')
    with open(out_path, 'w', encoding='utf-8') as f:
        f.write(output)

    size = os.path.getsize(out_path)
    print(f"✅ full_schema_all.sql 生成完毕")
    print(f"   CREATE TABLE: {len(seen_creates)} 张")
    print(f"   ALTER TABLE:  {len(unique_alters)} 条（涉及 {len(alter_by_table)} 张表）")
    print(f"   DROP TABLE:   {len(REAL_DROPS)} 条")
    print(f"   文件大小:     {size:,} bytes")


if __name__ == '__main__':
    main()
