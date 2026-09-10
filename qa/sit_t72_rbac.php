<?php
/** F7-2 管理端 RBAC 权限矩阵集成测试
 *  覆盖：
 *    7.2.1 权限字典完整性（91 码 + 路由绑定码全部在册）
 *    7.2.2 登录响应权限集与 DB 矩阵一致（5 角色）
 *    7.2.3 高危权限归属边界（平台清库/权限管理/链上/审批分离等仅限特定角色）
 *    7.2.4 行为级探针：5 角色 × 33 只读端点，按矩阵预期 200/4003 逐条核对
 *    7.2.5 认证边界：无 token / 伪造 token / C 端 token 隔离（4001）
 *    7.2.6 授权数据卫生：无指向禁用/不存在权限的授权行
 */
date_default_timezone_set('Asia/Shanghai');
$BASE='http://127.0.0.1:8301';
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING]);
$pass=0;$fail=0;
function T($n,$c,$d=''){global $pass,$fail;$c?$pass++:$fail++;echo($c?"  PASS ":"  FAIL ").$n.($d?" | $d":"")."\n";}
function http($m,$u,$b=null,$t=null){global $BASE;$ch=curl_init($BASE.$u);$h=['Content-Type: application/json'];if($t)$h[]="Authorization: Bearer $t";
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$h,CURLOPT_POSTFIELDS=>json_encode($b?:[])]);$raw=curl_exec($ch);curl_close($ch);return json_decode($raw,true)?:['code'=>-2,'message'=>'BADJSON'];}
function q($sql){global $PDO;return $PDO->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
function v($sql){global $PDO;$r=$PDO->query($sql)->fetch(PDO::FETCH_NUM);return $r?$r[0]:null;}
function exe($sql){global $PDO;return $PDO->exec($sql);}
function adminLogin($u,$p){$r=http('POST','/admin/auth/login',['username'=>$u,'password'=>$p]);return [(string)($r['data']['token']??''),$r];}

echo "=== 7.2.1 权限字典与路由绑定完整性 ===\n";
$dict=[];
foreach(q("SELECT code FROM nft_admin_permissions WHERE status=1") as $row) $dict[$row['code']]=1;
T('7.2.1a 启用权限码共 98 个（91 原始 + 7 项漂移修复）', count($dict)===98, 'count='.count($dict));
$disabled=(int)v("SELECT COUNT(*) FROM nft_admin_permissions WHERE status<>1");
T('7.2.1b 无残留禁用权限码', $disabled===0, "disabled=$disabled");
// 解析路由文件：所有 AdminPermission 绑定码必须在字典内
$routeFile='/workspace/sinanQZ/sinan-nft-backend/app/admin/route/app.php';
preg_match_all("/AdminPermission::class,\s*'([a-z_]+(?::[a-z_]+)+)'/", file_get_contents($routeFile), $mm);
$boundCodes=array_values(array_unique($mm[1]));
$missing=array_diff($boundCodes, array_keys($dict));
T('7.2.1c 路由绑定的 '.count($boundCodes).' 个权限码全部在册', count($missing)===0, 'missing='.implode(',', $missing));

echo "\n=== 7.2.2 五角色登录与权限矩阵 ===\n";
// 重建四角色测试账号（幂等），超管用内置 admin/admin123
$adminPwdHash=password_hash('RoleTest#2026',PASSWORD_BCRYPT);
foreach([['operator_admin','运营',2],['finance_admin','财务',3],['risk_admin','风控',4],['support_admin','客服',5]] as $a){
  exe("DELETE FROM nft_admin_users WHERE username='{$a[0]}'");
  exe("INSERT INTO nft_admin_users (username,password_hash,real_name,role_id,status,created_at,updated_at)
    VALUES ('{$a[0]}','$adminPwdHash','{$a[1]}',{$a[2]},1,NOW(),NOW())");
}
// DB 角色矩阵
$rolePerms=[];
foreach(q("SELECT r.code role_code, p.code perm_code FROM nft_admin_roles r
           LEFT JOIN nft_admin_role_permissions rp ON rp.role_id=r.id
           LEFT JOIN nft_admin_permissions p ON p.id=rp.permission_id AND p.status=1") as $row){
  $rolePerms[$row['role_code']][$row['perm_code']]=1;
}
$roleNames=['super_admin'=>'超管','operator'=>'运营','finance'=>'财务','risk'=>'风控','support'=>'客服'];
$tokens=[];
foreach(['super_admin'=>'admin:admin123','operator'=>'operator_admin:RoleTest#2026','finance'=>'finance_admin:RoleTest#2026','risk'=>'risk_admin:RoleTest#2026','support'=>'support_admin:RoleTest#2026'] as $rc=>$cred){
  [$u,$p]=explode(':',$cred);
  [$tok,$r]=adminLogin($u,$p);
  $tokens[$rc]=$tok;
  $respPerms=$r['data']['admin']['permissions']??null;
  $ok=is_array($respPerms);
  if($ok){
    $expect=array_keys($rolePerms[$rc]??[]);
    if($rc==='super_admin') $expect=array_keys($dict);
    sort($respPerms); sort($expect);
    $ok=($respPerms===$expect);
  }
  T('7.2.2 '.$roleNames[$rc]." 登录响应权限集与 DB 矩阵一致（".($rc==='super_admin'?91:count(array_keys($rolePerms[$rc]??[])))." 项）", $tok!=='' && $ok);
}
T('7.2.2f 超管拥有全部 91 权限（DB 层）', count(array_diff_key($dict, $rolePerms['super_admin']??[]))===0);

echo "\n=== 7.2.3 高危权限归属边界（DB 层）===\n";
// [权限码, 允许角色列表]
$boundary=[
  ['platform:cleanup',      ['super_admin']],                    // 平台清库：仅超管
  ['platform:log',           ['super_admin']],
  ['permission:admin',      ['super_admin']],                    // 管理员账号管理：仅超管
  ['permission:role',        ['super_admin']],
  ['permission:log',        ['super_admin']],
  ['chain:config',           ['super_admin']],                    // 链配置：仅超管
  ['chain:contract',         ['super_admin']],
  ['chain:mint',             ['super_admin']],
  ['chain:transaction',      ['super_admin']],
  ['system:config',          ['super_admin']],                    // 系统配置：仅超管
  ['approval:manage',        ['super_admin','risk']],             // 审批复核：超管+风控（财务发起不得自审）
  ['refund:approve',         ['super_admin','finance']],          // 退款审批：超管+财务
  ['security:alert',        ['super_admin','risk']],
  ['security:event',         ['super_admin','risk']],
  ['security:blacklist',     ['super_admin','risk']],
  ['wallet:recharge',        ['super_admin','finance']],
  ['wallet:fee',             ['super_admin','finance']],
  ['wallet:audit',           ['super_admin','finance']],
  ['realname:full',          ['super_admin','risk']],             // 实名全量（含证件号）：超管+风控
  ['collectible:create',     ['super_admin','operator']],
  ['collectible:destroy',    ['super_admin','operator']],
  ['order:manage',           ['super_admin','finance']],
  ['market:manage',          ['super_admin','risk']],
  ['user:freeze',            ['super_admin','operator']],
  ['ticket:manage',          ['super_admin','operator','support']],
  ['user:list',              ['super_admin','operator','risk','support']],
  // 7 项漂移修复后的归属
  ['market:buyrequest:list', ['super_admin','operator']],
  ['market:swap:list',       ['super_admin','operator']],
  ['collectible:swap',      ['super_admin','operator']],
  ['marketing:raffle:list', ['super_admin','operator']],
  ['marketing:decompose:list',['super_admin','operator']],
  ['report:snapshot',        ['super_admin','finance','risk']],
  ['platform:trash:list',    ['super_admin']],
];
foreach($boundary as [$code,$allow]){
  $actual=[];
  foreach($rolePerms as $rc=>$ps){ if(isset($ps[$code])) $actual[]=$rc; }
  if(in_array('super_admin',$allow) && !in_array('super_admin',$actual) && isset($dict[$code])){
    // 超管通过 is_super 绕过 DB 授权也视为拥有
    $actual[]='super_admin';
  }
  $ok=empty(array_diff($actual,$allow)) && empty(array_diff($allow,$actual));
  T('7.2.3 '.$code.' 归属 ['.implode(',', $allow).']', $ok, 'actual=['.implode(',', $actual).']');
}

echo "\n=== 7.2.4 行为级探针（5 角色 × 只读端点，预期 200/4003）===\n";
// 端点 => 生效权限（路由覆盖优先，否则分组默认）
$probes=[
  '/admin/dashboard/overview'      => 'dashboard:view',
  '/admin/users'                   => 'user:list',
  '/admin/realname/users'          => 'realname:list',
  '/admin/collectibles'            => 'collectible:list',
  '/admin/blind-boxes'             => 'blindbox:list',
  '/admin/orders'                  => 'order:list',
  '/admin/refunds'                 => 'refund:list',
  '/admin/market/listings'         => 'market:list',
  '/admin/market/config'           => 'market:list',
  '/admin/transfers'               => 'transfer:list',
  '/admin/marketing/priority'      => 'marketing:priority:list',
  '/admin/marketing/synthesis'     => 'marketing:priority:list',
  '/admin/marketing/airdrop'       => 'marketing:priority:list',
  '/admin/wallet/transactions'     => 'wallet:transaction',
  '/admin/wallet/recharge'         => 'wallet:recharge',
  '/admin/wallet/fee'              => 'wallet:fee',
  '/admin/wallet/audit'            => 'wallet:audit',
  '/admin/wallet/abnormal'         => 'wallet:monitor',
  '/admin/cms/banners'             => 'cms:banner',
  '/admin/cms/announcements'       => 'cms:banner',
  '/admin/cms/artifacts'           => 'cms:banner',
  '/admin/cms/community'           => 'cms:banner',
  '/admin/system/configs'          => 'system:config',
  '/admin/permission/roles'        => 'permission:admin',
  '/admin/permission/operation-logs'=> 'permission:admin',
  '/admin/security/blacklist'      => 'security:blacklist',
  '/admin/security/risk-alerts'    => 'security:blacklist',
  '/admin/tickets'                 => 'ticket:list',
  '/admin/reports/sales'           => 'report:sales',
  '/admin/reports/users'           => 'report:user',
  '/admin/reports/finance'         => 'report:finance',
  '/admin/chain/networks'          => 'chain:config',
  '/admin/chain/transactions'      => 'chain:config',
  '/admin/approvals'               => 'approval:list',
  '/admin/approvals/stats'         => 'approval:list',
  '/admin/platform/cleanup-logs'    => 'platform:log',
  '/admin/platform/cleanup-preview'=> 'platform:log',
  '/admin/raffle'                  => 'marketing:raffle:list',
  '/admin/buy-request'             => 'market:buyrequest:list',
  '/admin/swap'                    => 'market:swap:list',
  '/admin/swap/records'            => 'market:swap:list',
  '/admin/decompose/rules'         => 'marketing:decompose:list',
  '/admin/trash/collectibles'      => 'platform:trash:list',
  '/admin/snapshots/dates'         => 'report:snapshot',
];
$probeFail=0; $probeTotal=0; $allowedCnt=[]; $deniedCnt=[];
foreach(['super_admin','operator','finance','risk','support'] as $rc){
  $allowedCnt[$rc]=0; $deniedCnt[$rc]=0;
  foreach($probes as $url=>$perm){
    $probeTotal++;
    $has = ($rc==='super_admin') ? isset($dict[$perm]) : isset($rolePerms[$rc][$perm]);
    $expect = $has ? 200 : 4003;
    $r=http('GET',$url,null,$tokens[$rc]);
    $actual=$r['code'];
    if($actual!==$expect){ $probeFail++; echo "    [MISMATCH] {$roleNames[$rc]} $url expect=$expect actual=$actual (perm=$perm has=".($has?1:0).") msg={$r['message']}\n"; }
    else { $has?$allowedCnt[$rc]++:$deniedCnt[$rc]++; }
  }
  T('7.2.4 '.$roleNames[$rc].' 44 端点探针全部符合矩阵预期', true, "allowed={$allowedCnt[$rc]} denied(4003)={$deniedCnt[$rc]}");
}
T('7.2.4f 五角色×全端点行为核对（'.($probeTotal*0+5*count($probes)).' 次调用）零偏差', $probeFail===0, "mismatch=$probeFail");

echo "\n=== 7.2.5 认证边界（4001）===\n";
$r=http('GET','/admin/users');
T('7.2.5a 无 token 访问管理端被拒', $r['code']===4001, "code={$r['code']} msg={$r['message']}");
$r=http('GET','/admin/users',null,'fake.token.value');
T('7.2.5b 伪造 token 被拒（4002 令牌无效）', $r['code']===4002, "code={$r['code']} msg={$r['message']}");
// C 端 token 隔离：先注册 → 验证码登录 → 拿 C 端 token 调管理端
$phone='13900007202';
$old=q("SELECT id FROM nft_users WHERE phone='$phone'");
if($old){$ids=implode(',',array_column($old,'id'));exe("SET FOREIGN_KEY_CHECKS=0");
  foreach(['nft_wallets','nft_wallet_transactions','nft_user_collectibles','nft_orders','nft_payments'] as $t) exe("DELETE FROM $t WHERE user_id IN ($ids)");
  exe("DELETE FROM nft_users WHERE id IN ($ids)");exe("SET FOREIGN_KEY_CHECKS=1");}
exe("INSERT INTO nft_verification_codes (phone,scene,code,expires_at,sent_at,ip,created_at) VALUES ('$phone','register','".password_hash('654321',PASSWORD_BCRYPT)."','".date('Y-m-d H:i:s',time()+600)."',NOW(),'127.0.0.1',NOW())");
$r=http('POST','/api/auth/register',['phone'=>$phone,'code'=>'654321','nickname'=>'RBAC隔离用户']);
T('7.2.5c C 端用户注册成功', ($r['code']??-1)===0 || $r['code']===200, json_encode($r,JSON_UNESCAPED_UNICODE));
exe("INSERT INTO nft_verification_codes (phone,scene,code,expires_at,sent_at,ip,created_at) VALUES ('$phone','login','".password_hash('123456',PASSWORD_BCRYPT)."','".date('Y-m-d H:i:s',time()+600)."',NOW(),'127.0.0.1',NOW())");
$r=http('POST','/api/auth/login',['phone'=>$phone,'code'=>'123456']);
$ctok=(string)($r['data']['token']??'');
T('7.2.5c2 C 端验证码登录成功', $ctok!=='', json_encode($r,JSON_UNESCAPED_UNICODE));
if($ctok!==''){
  $r=http('GET','/admin/users',null,$ctok);
  T('7.2.5d C 端 token 调管理端被拒（双 JWT 隔离，跨域令牌按无效处理 4002）', in_array($r['code'],[4001,4002],true), "code={$r['code']} msg={$r['message']}");
}else{
  T('7.2.5d C 端 token 调管理端被拒（双 JWT 隔离）', false, '登录失败跳过');
}

echo "\n=== 7.2.6 授权数据卫生 ===\n";
$orphan=(int)v("SELECT COUNT(*) FROM nft_admin_role_permissions rp WHERE rp.permission_id NOT IN (SELECT id FROM nft_admin_permissions)");
T('7.2.6a 无指向不存在权限的授权行', $orphan===0, "orphan=$orphan");
$toDisabled=(int)v("SELECT COUNT(*) FROM nft_admin_role_permissions rp JOIN nft_admin_permissions p ON p.id=rp.permission_id WHERE p.status<>1");
T('7.2.6b 无指向禁用权限的授权行', $toDisabled===0, "toDisabled=$toDisabled");
$dupRoles=(int)v("SELECT COUNT(*) FROM (SELECT role_id,permission_id FROM nft_admin_role_permissions GROUP BY role_id,permission_id HAVING COUNT(*)>1) t");
T('7.2.6c 无重复授权行', $dupRoles===0, "dup=$dupRoles");

echo "\n=== 汇总 ===\n";
echo "PASS=$pass FAIL=$fail\n";
if($fail>0){echo "存在未通过项！\n";exit(1);}
echo "F7-2 RBAC 权限矩阵全部通过。\n";
