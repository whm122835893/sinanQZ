<?php
/** 测试基线重置：清空全部业务数据（保留管理端 RBAC / 系统配置 / 分类 / 链网络等基础设施），
 *  重建标准冒烟用户 id=1~8（固定手机号），供所有 sit_t* 脚本共享。
 *  用法：php sit_baseline_reset.php
 */
date_default_timezone_set('Asia/Shanghai');
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$PDO->exec("SET NAMES utf8mb4");

// ---- 1. 业务数据表全清（含 users，重置自增） ----
$bizTables = [
  'users','wallets','wallet_transactions','verification_codes',
  'orders','payments','user_collectibles','user_favorites',
  'resale_listings','transfers','buy_requests','refunds',
  'destroy_records','blacklist','risk_alerts','security_events',
  'support_tickets','ticket_replies','approval_requests',
  'swap_plans','swap_plan_items','swap_plan_users','swap_plan_details',
  'blind_box_items','blind_boxes',
  'synthesis_records','synthesis_record_items','synthesis_materials','synthesis_activities',
  'decompose_rules','decompose_records','decompose_items',
  'lucky_draw_records','lucky_draw_prizes','lucky_draw_activities','lucky_draw_chances',
  'check_in_records','invite_records','invite_activities',
  'airdrop_records','airdrop_snapshots','airdrop_eligibilities','airdrop_activities','airdrop_tasks',
  'collectibles','banners','announcements','artifacts','community_groups',
  'qualification_whitelists','qualification_configs',
  'priority_whitelists','priority_activities','priority_sales','priority_sale_whitelists',
  'inventory_quotas','raffle_activities','raffle_registrations','raffle_operation_logs',
  'register_activities','activity_reward_records',
  'holdings_snapshots','trade_snapshots','user_draw_codes',
];
$PDO->exec("SET FOREIGN_KEY_CHECKS=0");
foreach($bizTables as $t){ $PDO->exec("TRUNCATE TABLE `nft_$t`"); }
$PDO->exec("SET FOREIGN_KEY_CHECKS=1");
echo "[OK] 已清空 ".count($bizTables)." 张业务表\n";

// ---- 2. 重建标准冒烟用户 id=1~8 ----
// 1/2/3：实名单冒烟（优先购/资格购/邀请）；4~8：签到/白名单用户
$pwdLogin   = password_hash('Pass#2026', PASSWORD_BCRYPT);
$pwdTrade   = '$2y$12$MOtq8as9FfrvOSoK1LOjCusHuC9Y8Qc7ydjTyaZUIkBpfcTrX81fW'; // Trade#2026
$users = [
  [1,'13900000001','冒烟A',1], [2,'13900000002','冒烟B',1], [3,'13900000003','冒烟C',1],
  [4,'13800000004','签到D',0], [5,'13800000005','白名单E',0],
  [6,'13842453421','签到F',0], [7,'13878445723','签到G',0], [8,'13841161376','签到H',0],
];
$codes=['SN2026AA','SN2026BB','SN2026CC','SN2026DD','SN2026EE','SN2026FF','SN2026GG','SN2026HH'];
$st=$PDO->prepare("INSERT INTO nft_users
  (id,phone,username,avatar,uid,invite_code,is_realname,realname_status,password,transaction_password,status,created_at,updated_at)
  VALUES (?,?,?,'',?,?,?,1,?,?,1,NOW(3),NOW(3))");
foreach($users as $i=>$u){
  $st->execute([$u[0],$u[1],$u[2],'U'.str_pad((string)$u[0],6,'0',STR_PAD_LEFT),$codes[$i],$u[3],$pwdLogin,$u[3]?$pwdTrade:null]);
}
echo "[OK] 已重建用户 id=1~8\n";

// ---- 3. 钱包开账（1000 元 + recharge 流水，保持 Z1-1 恒等式） ----
foreach($users as $u){
  $PDO->exec("INSERT INTO nft_wallets (user_id,balance,available,frozen,points,brand,created_at,updated_at)
    VALUES ({$u[0]},1000,1000,0,0,'汇付',NOW(3),NOW(3))");
  $PDO->exec("INSERT INTO nft_wallet_transactions (user_id,trans_type,title,direction,amount,balance_after,created_at)
    VALUES ({$u[0]},'recharge','测试资金开账',1,1000,1000,NOW(3))");
}
echo "[OK] 已开账 8 个钱包（各 1000 元）\n";

// ---- 4. 校验 ----
$c=(int)$PDO->query("SELECT COUNT(*) FROM nft_users")->fetchColumn();
echo "users=$c  ";
$c=(int)$PDO->query("SELECT COUNT(*) FROM nft_wallets")->fetchColumn();
echo "wallets=$c  ";
$c=(int)$PDO->query("SELECT COUNT(*) FROM nft_categories")->fetchColumn();
echo "categories={$c}（保留）  ";
$c=(int)$PDO->query("SELECT COUNT(*) FROM nft_admin_users")->fetchColumn();
echo "admin_users=$c（保留）\n";
echo "基线重置完成\n";
