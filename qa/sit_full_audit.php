<?php
/** 全量闭环审计：C端页面功能 + 管理端全菜单 + 业务闭环（创建/上架/购买/支付/下架/销毁/空投/白名单/资格购/优先购/抽签购/寄售/转赠/合成/分解/盲盒/求购/签到/抽奖/邀请）
 *  前置：后端 127.0.0.1:8080 已启动，MySQL sinan_nft（sinan/sinan123）
 *  自清洁：使用独立手机号段 139000091xx 与藏品段 95xx，可重复执行
 */
date_default_timezone_set('Asia/Shanghai');
$BASE='http://127.0.0.1:8080';
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING]);
$pass=0;$fail=0;$defects=[];
function T($n,$c,$d=''){global $pass,$fail;$c?$pass++:$fail++;echo($c?"  PASS ":"  FAIL ").$n.($d?" | $d":"")."\n";}
function D($n,$c,$d=''){global $defects;if($c){$defects[]=$n.($d?" | $d":"");echo "  DEFECT $n".($d?" | $d":"")."\n";}}
function http($m,$u,$b=null,$t=null){global $BASE;$ch=curl_init($BASE.$u);$h=['Content-Type: application/json'];if($t)$h[]="Authorization: Bearer $t";
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>$h,CURLOPT_POSTFIELDS=>json_encode($b?:[])]);$raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return [$code,json_decode($raw,true)?:['code'=>-2,'message'=>'BADJSON']];}
function q($sql){global $PDO;return $PDO->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
function v($sql){global $PDO;$r=$PDO->query($sql)->fetch(PDO::FETCH_NUM);return $r?$r[0]:null;}
function exe($sql){global $PDO;return $PDO->exec($sql);}
function regUser($phone,$nick,$invite=''){global $PDO,$BASE;
  $PDO->exec("DELETE FROM nft_verification_codes WHERE phone='$phone'");
  $PDO->exec("INSERT INTO nft_verification_codes (phone,scene,code,expires_at,sent_at,ip,created_at) VALUES ('$phone','register','".password_hash('654321',PASSWORD_BCRYPT)."','".date('Y-m-d H:i:s',time()+600)."',NOW(),'127.0.0.1',NOW())");
  $ch=curl_init($BASE.'/api/auth/register');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'code'=>'654321','nickname'=>$nick,'password'=>'Pass#999','inviteCode'=>$invite])]);
  $j=json_decode(curl_exec($ch),true)?:[];curl_close($ch);
  $uid=$j['code']===0?(int)v("SELECT id FROM nft_users WHERE phone='$phone'"):0;
  return [$uid,(string)($j['data']['token']??''),$j];
}
function seedHolder($uid,$cid,$n=1,$source='purchase'){global $PDO;$ids=[];
  for($i=0;$i<$n;$i++){$s='SN-'.$cid.'-A'.substr((string)microtime(true),-5).$i.rand(100,999);
    $PDO->exec("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at) VALUES ($uid,$cid,'$s','$source',0,NOW(),'held',NOW(),NOW())");
    $ids[]=(int)v("SELECT id FROM nft_user_collectibles WHERE serial='$s'");}return $ids;}
function makeColl($id,$name,$price=100,$edition=1000){global $PDO;
  exe("DELETE FROM nft_user_collectibles WHERE collectible_id=$id");
  exe("DELETE FROM nft_transfers WHERE collectible_id=$id");
  exe("DELETE FROM nft_resale_listings WHERE collectible_id=$id");
  exe("DELETE FROM nft_blind_boxes WHERE collectible_id=$id");
  exe("DELETE FROM nft_collectibles WHERE id=$id");
  exe("INSERT INTO nft_collectibles (id,category_id,name,subtitle,image,price,edition,circulate,sold,locked_quantity,per_user_limit,is_transferable,is_resaleable,is_buy_request_enabled,resale_price_mode,resale_price_min,resale_price_max,status,issuer,brand,created_at,updated_at)
    VALUES ($id,1,'$name','审计','',100,$edition,0,0,0,10,1,1,1,0,0,0,'onsale','司南文创','司南',NOW(),NOW())");
}

echo "========== 0. 登录与造数 ==========\n";
// 管理端
[$_,$ar]=http('POST','/admin/auth/login',['username'=>'admin','password'=>'admin123']);
$atok=(string)($ar['data']['token']??'');
T('0.1 管理端登录 admin', $atok!=='', "code={$ar['code']}");
// 清理历史审计用户
$old=q("SELECT id FROM nft_users WHERE phone LIKE '139000091%'");
if($old){$ids=implode(',',array_column($old,'id'));
  exe("SET FOREIGN_KEY_CHECKS=0");
  foreach(['nft_wallets','nft_wallet_transactions','nft_user_collectibles','nft_orders','nft_payments','nft_resale_listings','nft_transfers','nft_buy_requests','nft_swap_offers','nft_swap_records','nft_synthesis_records','nft_decompose_records','nft_raffle_registrations','nft_lucky_draw_chances','nft_activity_reward_records','nft_airdrop_records','nft_check_in_records'] as $tb){exe("DELETE FROM $tb WHERE user_id IN ($ids)");}
  exe("DELETE FROM nft_transfers WHERE from_user_id IN ($ids) OR to_user_id IN ($ids)");
  exe("DELETE FROM nft_resale_listings WHERE seller_id IN ($ids)");
  exe("DELETE FROM nft_buy_requests WHERE accepted_by IN ($ids)");
  exe("DELETE FROM nft_users WHERE id IN ($ids)");
  exe("SET FOREIGN_KEY_CHECKS=1");
}
[$uidA,$tokA,]=regUser('13900009101','审计甲');
[$uidB,$tokB,]=regUser('13900009102','审计乙');
[$uidC,$tokC,]=regUser('13900009103','审计丙');
// 探针夹具：藏品 9001（详情/管理端探针）+ 文物展品 1（详情探针），基线清库后自建
if(v("SELECT COUNT(*) FROM nft_collectibles WHERE id=9001")==0){
  exe("INSERT INTO nft_collectibles (id,category_id,name,subtitle,image,price,edition,circulate,sold,locked_quantity,per_user_limit,is_release,is_transferable,is_resaleable,is_buy_request_enabled,resale_price_mode,status,issuer,brand,created_at,updated_at)
    VALUES (9001,1,'审计探针藏品','','/img/test.png',100,1000,0,0,0,10,1,1,1,1,0,'onsale','司南文创','司南',NOW(),NOW())");
}
if(v("SELECT COUNT(*) FROM nft_artifacts WHERE id=1")==0){
  exe("INSERT INTO nft_artifacts (id,name,dynasty,image,material,period,story,created_at,updated_at)
    VALUES (1,'审计探针展品','现代','/img/test.png','纸','当代','审计夹具',NOW(),NOW())");
}
T('0.2 测试用户甲/乙/丙注册', $uidA>0&&$uidB>0&&$uidC>0, "A=$uidA B=$uidB C=$uidC");
$th=password_hash('Trade#2026',PASSWORD_BCRYPT);
exe("UPDATE nft_users SET is_realname=1, transaction_password='$th' WHERE id IN ($uidA,$uidB,$uidC)");
foreach([$uidA,$uidB,$uidC] as $u){exe("DELETE FROM nft_wallets WHERE user_id=$u");
  exe("INSERT INTO nft_wallets (user_id,balance,available,frozen,points,created_at,updated_at) VALUES ($u,100000,100000,0,0,NOW(),NOW())");
  exe("INSERT INTO nft_wallet_transactions (user_id,trans_type,title,direction,amount,balance_after,created_at) VALUES ($u,'recharge','审计开账',1,100000,100000,NOW())");}
T('0.3 实名/交易密码/钱包就绪', (int)v("SELECT COUNT(*) FROM nft_wallets WHERE user_id IN ($uidA,$uidB,$uidC) AND available=100000")===3);

echo "\n========== 1. C端公开接口（免登录） ==========\n";
$pubEndpoints=[
  ['GET','/api/collections/categories',null],
  ['GET','/api/collections/featured',null],
  ['GET','/api/collections/9001',null],
  ['GET','/api/market/collections',null],
  ['GET','/api/blind-boxes',null],
  ['GET','/api/synthesis/activities',null],
  ['GET','/api/decompose/rules',null],
  ['GET','/api/lucky-draw/activity',null],
  ['GET','/api/raffle/activities',null],
  ['GET','/api/artifacts',null],
  ['GET','/api/artifacts/1',null],
  ['GET','/api/announcements',null],
  ['GET','/api/banners',null],
  ['GET','/api/community/groups',null],
  ['GET','/api/config',null],
  ['GET','/api/resale/listings',null],
  ['GET','/api/resale/history',null],
  ['GET','/api/buy-requests',null],
];
foreach($pubEndpoints as [$m,$u,$b]){
  [$hc,$r]=http($m,$u,$b);
  $ok=($r['code']??-1)===0 && $hc===200;
  T("PUB $u", $ok, "http=$hc code=".($r['code']??'?')." msg=".($r['message']??''));
  if(!$ok) D("公开接口异常 $u", true, "http=$hc code=".($r['code']??'?')." msg=".($r['message']??''));
}

echo "\n========== 2. C端登录后接口 ==========\n";
$authEndpoints=[
  ['GET','/api/user/profile',null],['GET','/api/user/collections',null],['GET','/api/user/favorites',null],
  ['GET','/api/orders',null],['GET','/api/resale/listings/mine',null],['GET','/api/transfers/mine',null],
  ['GET','/api/synthesis/records',null],['GET','/api/decompose/records',null],['GET','/api/check-in/records',null],
  ['GET','/api/check-in/calendar?month='.date('Y-m'),null],['GET','/api/lucky-draw/records',null],
  ['GET','/api/raffle/registrations/mine',null],['GET','/api/wallet',null],['GET','/api/wallet/transactions',null],
  ['GET','/api/invite/info',null],['GET','/api/invite/records',null],
];
foreach($authEndpoints as [$m,$u,$b]){
  [$hc,$r]=http($m,$u,$b,$tokA);
  $ok=($r['code']??-1)===0 && $hc===200;
  T("AUTH $u", $ok, "http=$hc code=".($r['code']??'?')." msg=".($r['message']??''));
  if(!$ok) D("登录接口异常 $u", true, "http=$hc code=".($r['code']??'?')." msg=".($r['message']??''));
}
// 未登录访问应被拦截
[$hc,$r]=http('GET','/api/user/profile');
T('AUTH 未登录拦截', ($r['code']??-1)===2001, "code=".($r['code']??'?')." msg=".($r['message']??''));

echo "\n========== 3. 管理端全菜单接口（只读面） ==========\n";
// 巡检用真实存在的用户 id（审计脚本多次执行后自增 id 不再从 1 开始）
$probeUid=(int)v('SELECT id FROM nft_users ORDER BY id LIMIT 1') ?: 1;
$adminRead=[
  '/admin/dashboard/overview','/admin/dashboard/trend','/admin/dashboard/rank','/admin/dashboard/latest',
  "/admin/users","/admin/users/$probeUid","/admin/users/assets/$probeUid",
  '/admin/realname/users','/admin/realname/stats',
  '/admin/collectibles','/admin/collectibles/audit','/admin/collectibles/qualifications','/admin/collectibles/9001',
  '/admin/blind-boxes','/admin/blind-boxes/audit',
  '/admin/orders','/admin/orders/audit',
  '/admin/refunds',
  '/admin/market/listings','/admin/market/config',
  '/admin/transfers',
  '/admin/marketing/priority','/admin/marketing/checkin','/admin/marketing/invite','/admin/marketing/lucky',
  '/admin/marketing/synthesis','/admin/marketing/synthesis-records','/admin/marketing/airdrop','/admin/marketing/register',
  '/admin/marketing/reward-records',
  '/admin/wallet/stats','/admin/wallet/transactions','/admin/wallet/recharge','/admin/wallet/fee','/admin/wallet/audit','/admin/wallet/abnormal',
  '/admin/cms/banners','/admin/cms/categories','/admin/cms/announcements','/admin/cms/agreements','/admin/cms/artifacts','/admin/cms/community','/admin/cms/decoration',
  '/admin/system/configs','/admin/system/payment-channels','/admin/system/sms-config','/admin/system/security-config',
  '/admin/permission/admins','/admin/permission/roles','/admin/permission/tree','/admin/permission/operation-logs','/admin/permission/log-modules','/admin/permission/login-logs',
  '/admin/security/blacklist','/admin/security/risk-alerts','/admin/security/events',
  '/admin/tickets',
  '/admin/reports/sales','/admin/reports/users','/admin/reports/collectibles','/admin/reports/blindbox','/admin/reports/finance',
  '/admin/snapshots/holdings','/admin/snapshots/trades','/admin/snapshots/dates',
  '/admin/chain/networks','/admin/chain/contracts','/admin/chain/transactions',
  '/admin/approvals','/admin/approvals/stats',
  '/admin/platform/cleanup-logs','/admin/platform/cleanup-preview',
  '/admin/raffle','/admin/raffle/registrations','/admin/raffle/codes','/admin/raffle/winners','/admin/raffle/logs',
  '/admin/buy-request','/admin/swap','/admin/swap/records',
  '/admin/decompose/rules','/admin/decompose/records',
  '/admin/trash/collectibles','/admin/trash/orders','/admin/trash/users','/admin/trash/banners','/admin/trash/announcements',
];
foreach($adminRead as $u){
  [$hc,$r]=http('GET',$u,null,$atok);
  $ok=($r['code']??-1)===200 && $hc===200;
  T("ADMIN $u", $ok, "http=$hc code=".($r['code']??'?')." msg=".($r['message']??''));
  if(!$ok) D("管理接口异常 $u", true, "http=$hc code=".($r['code']??'?')." msg=".($r['message']??''));
}
// 未登录访问管理端应被拦截
[$hc,$r]=http('GET','/admin/dashboard/overview');
T('ADMIN 未登录拦截', ($r['code']??-1)!==200, "code=".($r['code']??'?'));

echo "\n========== 4. 核心闭环：创建→上架→购买→支付→下架→销毁 ==========\n";
// 4.1 创建藏品
[$_,$r]=http('POST','/admin/collectibles',['name'=>'审计核心A','category_id'=>1,'image'=>'/uploads/t.png','price'=>88,'edition'=>50,'per_user_limit'=>2,'is_transferable'=>1,'is_resaleable'=>1,'description'=>'核心闭环测试'],$atok);
$cCore=($r['code']===200)?(int)$r['data']['id']:0;
T('4.1 管理端创建藏品', $cCore>0, json_encode($r,JSON_UNESCAPED_UNICODE));
// 4.2 上架（未来开售时间拦截与成功）
[$_,$r]=http('POST',"/admin/collectibles/$cCore/release",['onsale_at'=>date('Y-m-d H:i:s',time()+3600)],$atok);
T('4.2a 上架(未来开售)', ($r['code']??-1)===200, "code={$r['code']} msg={$r['message']}");
[$_,$r]=http('POST',"/admin/collectibles/$cCore/release",['onsale_at'=>date('Y-m-d H:i:s',time()-60)],$atok);
T('4.2b 上架(立即开售)', ($r['code']??-1)===200, "code={$r['code']} msg={$r['message']}");
// 4.3 C端详情可见
[$_,$r]=http('GET',"/api/collections/$cCore",null,$tokA);
T('4.3 C端详情可见', ($r['code']??-1)===0, "code={$r['code']}");
// 4.4 下单
[$_,$r]=http('POST','/api/orders',['collectibleId'=>$cCore,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tokA);
$orderNo=($r['code']===0)?(string)($r['data']['orderNo']??''):'';
T('4.4 用户下单(创建订单)', $orderNo!=='', json_encode($r,JSON_UNESCAPED_UNICODE));
// 4.5 支付
$balBefore=(float)v("SELECT available FROM nft_wallets WHERE user_id=$uidA");
[$_,$r]=http('POST',"/api/orders/$orderNo/pay",['paymentMethod'=>'balance','paymentPassword'=>'Trade#2026'],$tokA);
T('4.5 支付成功', ($r['code']??-1)===0, "code={$r['code']} msg={$r['message']}");
$balAfter=(float)v("SELECT available FROM nft_wallets WHERE user_id=$uidA");
T('4.5b 钱包扣款 88 元', abs($balAfter-($balBefore-88))<0.001, "before=$balBefore after=$balAfter");
T('4.5c 订单状态 completed', v("SELECT status FROM nft_orders WHERE order_no='$orderNo'")==='completed');
$ucCore=(int)v("SELECT id FROM nft_user_collectibles WHERE user_id=$uidA AND collectible_id=$cCore AND status='held' ORDER BY id DESC LIMIT 1");
T('4.5d 持仓发放 1 件', $ucCore>0, "ucId=$ucCore");
T('4.6 库存 sold+1', (int)v("SELECT sold FROM nft_collectibles WHERE id=$cCore")===1);
// 4.7 下架
[$_,$r]=http('POST',"/admin/collectibles/$cCore/manage",['action'=>'off'],$atok);
T('4.7 管理端强制下架', ($r['code']??-1)===200, "code={$r['code']}");
T('4.7b 藏品状态 off', v("SELECT status FROM nft_collectibles WHERE id=$cCore")==='off');
// 4.8 销毁库存
[$_,$r]=http('POST',"/admin/collectibles/$cCore/destroy",['id'=>$cCore,'quantity'=>5,'reason'=>'审计销毁'],$atok);
T('4.8 销毁库存 5 份', ($r['code']??-1)===200, "code={$r['code']} msg={$r['message']}");
T('4.8b destroyed_count=5', (int)v("SELECT destroyed_count FROM nft_collectibles WHERE id=$cCore")===5);
// 4.9 软删除：有流通记录(已售出)的藏品禁止删除（正确业务规则）
[$_,$r]=http('DELETE',"/admin/collectibles/$cCore",null,$atok);
T('4.9 有流通记录禁止删除', ($r['code']??-1)===4220, "code={$r['code']} msg={$r['message']}");
// 4.9b 无流通藏品软删除 → 回收站
[$_,$r]=http('POST','/admin/collectibles',['name'=>'审计删除','category_id'=>1,'image'=>'/uploads/t.png','price'=>1,'edition'=>10,'per_user_limit'=>1,'is_transferable'=>1,'is_resaleable'=>1,'description'=>'删除闭环'],$atok);
$cDel=($r['code']===200)?(int)$r['data']['id']:0;
T('4.9b 建待删藏品', $cDel>0, "id=$cDel");
[$_,$r]=http('DELETE',"/admin/collectibles/$cDel",null,$atok);
T('4.9c 软删除成功', ($r['code']??-1)===200, "code={$r['code']} msg={$r['message']}");
[$_,$r]=http('GET','/admin/trash/collectibles',null,$atok);
$inTrash=false;foreach(($r['data']['list']??[]) as $it){if((int)$it['id']===$cDel){$inTrash=true;break;}}
T('4.9d 藏品进入回收站', $inTrash, "found=".var_export($inTrash,true));

echo "\n========== 5. 营销闭环 ==========\n";
// 5.1 藏品空投
[$_,$r]=http('POST','/admin/collectibles',['name'=>'审计空投','category_id'=>1,'image'=>'/uploads/t.png','price'=>10,'edition'=>100,'per_user_limit'=>10,'is_transferable'=>1,'is_resaleable'=>1,'description'=>'空投'],$atok);
$cAir=($r['code']===200)?(int)$r['data']['id']:0;T('5.1a 建空投藏品', $cAir>0);
[$_,$r]=http('POST','/admin/collectibles/airdrop',['id'=>$cAir,'users'=>['13900009101','13900009102'],'quantity'=>2,'reason'=>'审计空投'],$atok);
$ucAirB=(int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$uidB AND collectible_id=$cAir AND source='airdrop'");
T('5.1b 藏品空投 2人×2份', ($r['code']??-1)===200, "code={$r['code']} msg={$r['message']}");
T('5.1c 空投资产落库(B)',$ucAirB===2,"B持有=$ucAirB");
// 5.2 白名单/优先购
[$_,$r]=http('POST','/admin/collectibles',['name'=>'审计优先购','category_id'=>1,'image'=>'/uploads/t.png','price'=>30,'edition'=>50,'per_user_limit'=>10,'is_transferable'=>1,'is_resaleable'=>1,'description'=>'优先购'],$atok);
$cPri=($r['code']===200)?(int)$r['data']['id']:0;T('5.2a 建优先购藏品', $cPri>0);
[$_,$r]=http('POST',"/admin/collectibles/$cPri/release",['onsale_at'=>date('Y-m-d H:i:s',time()+600)],$atok);
[$_,$r]=http('POST','/admin/marketing/priority',['collectible_id'=>$cPri,'name'=>'审计优先购','status'=>'enabled','whitelist'=>[$uidB],'whitelist_max_quantity'=>2],$atok);
T('5.2b 建优先购活动+白名单', ($r['code']??-1)===200, json_encode($r,JSON_UNESCAPED_UNICODE));
[$_,$r]=http('GET',"/api/collections/$cPri",null,$tokB);
$pq=$r['data']['myPriorityQualification']??null;
T('5.2c B端优先购资格可见', is_array($pq)&&($pq['remaining']??0)===2, json_encode($pq,JSON_UNESCAPED_UNICODE));
[$_,$r]=http('POST','/api/orders',['collectibleId'=>$cPri,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tokB);
T('5.2d 白名单优先购下单成功', ($r['code']??-1)===0, "code={$r['code']} msg={$r['message']}");
// 非白名单用户（在公售前）应被拦截
[$_,$r]=http('POST','/api/orders',['collectibleId'=>$cPri,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tokC);
T('5.2e 非白名单公售前拦截', ($r['code']??-1)===1001, "code={$r['code']} msg={$r['message']}");
// 5.3 资格购
[$_,$r]=http('POST','/admin/collectibles',['name'=>'审计资格购','category_id'=>1,'image'=>'/uploads/t.png','price'=>40,'edition'=>50,'per_user_limit'=>10,'is_transferable'=>1,'is_resaleable'=>1,'description'=>'资格购'],$atok);
$cQual=($r['code']===200)?(int)$r['data']['id']:0;T('5.3a 建资格购藏品', $cQual>0);
[$_,$r]=http('POST',"/admin/collectibles/$cQual/release",['onsale_at'=>date('Y-m-d H:i:s',time()-60)],$atok);
exe("INSERT INTO nft_qualification_configs (collectible_id,is_enabled,condition_type,required_checkin_days,valid_start_at,valid_end_at) VALUES ($cQual,1,1,1,'".date('Y-m-d H:i:s',time()-60)."','".date('Y-m-d H:i:s',time()+86400)."')");
[$_,$r]=http('GET',"/api/collections/$cQual",null,$tokC);
$qual=$r['data']['qualification']??null;
T('5.3b 资格购详情返回配置', is_array($qual)&&($qual['enabled']??false)===true, json_encode($qual,JSON_UNESCAPED_UNICODE));
T('5.3c 无资格用户qualified=false', ($qual['qualified']??true)===false, "reason=".($qual['reason']??''));
[$_,$r]=http('POST','/api/orders',['collectibleId'=>$cQual,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tokC);
T('5.3d 无资格下单被拒(3004)', ($r['code']??-1)===3004, "code={$r['code']} msg={$r['message']}");
http('POST','/api/check-in',[],$tokC);
[$_,$r]=http('POST','/api/orders',['collectibleId'=>$cQual,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tokC);
T('5.3e 签到满足资格后购买成功', ($r['code']??-1)===0, "code={$r['code']} msg={$r['message']}");
// 5.4 抽签购（Raffle 全流程）
[$_,$r]=http('POST','/admin/collectibles',['name'=>'审计抽签购','category_id'=>1,'image'=>'/uploads/t.png','price'=>99,'edition'=>20,'per_user_limit'=>10,'is_transferable'=>1,'is_resaleable'=>1,'description'=>'抽签购'],$atok);
$cRaff=($r['code']===200)?(int)$r['data']['id']:0;T('5.4a 建抽签购藏品', $cRaff>0);
exe("INSERT INTO nft_raffle_activities (collectible_id,name,ticket_price,max_wins_per_user,winner_count,sale_quantity,sale_price,registration_start,registration_end,draw_time,purchase_start,purchase_end,status,created_at,updated_at)
  VALUES ($cRaff,'审计抽签购',0,2,1,1,99,'".date('Y-m-d H:i:s',time()-3600)."','".date('Y-m-d H:i:s',time()+3600)."','".date('Y-m-d H:i:s')."','".date('Y-m-d H:i:s',time()-3600)."','".date('Y-m-d H:i:s',time()+3600)."',1,NOW(),NOW())");
$raffId=(int)v("SELECT id FROM nft_raffle_activities WHERE collectible_id=$cRaff ORDER BY id DESC LIMIT 1");
[$_,$r]=http('GET',"/api/raffle/activities/$raffId",null,$tokA);
T('5.4b 抽签活动详情可查', ($r['code']??-1)===0, "code={$r['code']}");
[$_,$r]=http('POST',"/api/raffle/activities/$raffId/register",['ticketCount'=>1],$tokB);
T('5.4c B 报名 1 票', ($r['code']??-1)===0, "code={$r['code']} msg={$r['message']}");
[$_,$r]=http('POST',"/api/raffle/activities/$raffId/register",['ticketCount'=>1],$tokC);
T('5.4d C 报名 1 票', ($r['code']??-1)===0, "code={$r['code']} msg={$r['message']}");
[$_,$r]=http('POST',"/admin/raffle/$raffId/draw",[],$atok);
T('5.4e 管理端执行抽签', ($r['code']??-1)===200, "code={$r['code']} msg={$r['message']}");
$winCnt=(int)v("SELECT COUNT(*) FROM nft_raffle_registrations WHERE activity_id=$raffId AND draw_status=1");
T('5.4f 中签人数=winner_count(1)', $winCnt===1, "winners=$winCnt");
$winUser=(int)v("SELECT user_id FROM nft_raffle_registrations WHERE activity_id=$raffId AND draw_status=1 LIMIT 1");
$winTok=$winUser===$uidB?$tokB:$tokC;
$soldB=(int)v("SELECT sold FROM nft_collectibles WHERE id=$cRaff");
[$_,$r]=http('POST',"/api/raffle/activities/$raffId/purchase",['quantity'=>1],$winTok[0]??'');
// 用正确 token 重取
$winTokStr=$winUser===$uidB?$tokB:$tokC;
[$_,$r]=http('POST',"/api/raffle/activities/$raffId/purchase",['quantity'=>1],$winTokStr);
T('5.4g 中签者购买成功', ($r['code']??-1)===0, "code={$r['code']} msg={$r['message']} winner=$winUser");
T('5.4h 抽签购库存 sold+1', ((int)v("SELECT sold FROM nft_collectibles WHERE id=$cRaff")-$soldB)===1);
// 5.5 签到 + 抽奖
$ptB=(float)v("SELECT points FROM nft_wallets WHERE user_id=$uidA");
[$_,$r]=http('POST','/api/check-in',[],$tokA);
T('5.5a 签到成功', ($r['code']??-1)===0, "code={$r['code']} msg={$r['message']}");
[$_,$r]=http('POST','/api/check-in',[],$tokA);
T('5.5b 重复签到拦截', ($r['data']['already']??false)===true, json_encode($r['data'],JSON_UNESCAPED_UNICODE));
[$_,$r]=http('GET','/api/lucky-draw/activity',null,$tokA);
T('5.5c 抽奖活动查询', ($r['code']??-1)===0, "code={$r['code']}");
// 5.6 邀请
$u1c=v("SELECT invite_code FROM nft_users WHERE id=$uidA");
[$_,$r]=http('GET','/api/invite/info',null,$tokA);
T('5.6a 邀请信息', ($r['code']??-1)===0&&($r['data']['inviteCode']??'')===$u1c, "code={$r['code']}");
[$invd,$invt,]=regUser('13900009104','审计受邀',$u1c);
$irCnt=(int)v("SELECT COUNT(*) FROM nft_invite_records WHERE inviter_id=$uidA AND invitee_id=$invd AND status='registered'");
T('5.6b 注册绑定邀请码', $invd>0&&$irCnt===1, "uid=$invd irCnt=$irCnt");

echo "\n========== 6. 交易闭环 ==========\n";
makeColl(9601,'审计寄售');
$ucSell1=seedHolder($uidA,9601,1)[0];
// 6.1 寄售挂单 → 市场购买
[$_,$r]=http('POST','/api/resale/listings',['userCollectibleId'=>$ucSell1,'price'=>150,'paymentPassword'=>'Trade#2026'],$tokA);
$lid=($r['code']===0)?(int)($r['data']['id']??$r['data']['listingId']??0):0;
T('6.1a A 寄售挂单', ($r['code']??-1)===0, json_encode($r,JSON_UNESCAPED_UNICODE));
T('6.1b 挂单资产状态 consigned', v("SELECT status FROM nft_user_collectibles WHERE id=$ucSell1")==='consigned');
[$_,$r]=http('GET','/api/resale/listings',null);
$found=false;foreach(($r['data']['list']??[]) as $it){if((int)($it['listingId']??0)===$lid){$found=true;break;}}
T('6.1c 寄售池可见该挂单', $found);
$balB=(float)v("SELECT available FROM nft_wallets WHERE user_id=$uidB");
$ownA=(int)v("SELECT user_id FROM nft_user_collectibles WHERE id=$ucSell1");
[$_,$r]=http('POST','/api/orders',['resaleListingId'=>$lid,'paymentPassword'=>'Trade#2026'],$tokB);
$mOrderNo=($r['code']===0)?(string)($r['data']['orderNo']??''):'';
T('6.1d B 下单购买寄售品', $mOrderNo!=='', json_encode($r,JSON_UNESCAPED_UNICODE));
if($mOrderNo!==''){[$_,$r]=http('POST',"/api/orders/$mOrderNo/pay",['paymentMethod'=>'balance','paymentPassword'=>'Trade#2026'],$tokB);
T('6.1e B 支付寄售订单', ($r['code']??-1)===0, "code={$r['code']} msg={$r['message']}");}
T('6.1f 资产过户给 B', (int)v("SELECT user_id FROM nft_user_collectibles WHERE id=$ucSell1")===$uidB, "owner=".v("SELECT user_id FROM nft_user_collectibles WHERE id=$ucSell1"));
T('6.1g 挂单状态成交', v("SELECT status FROM nft_resale_listings WHERE id=$lid")==='sold', "status=".v("SELECT status FROM nft_resale_listings WHERE id=$lid"));
// 6.2 转赠
makeColl(9602,'审计转赠');
$ucT=seedHolder($uidA,9602,1)[0];
[$_,$r]=http('POST','/api/transfers',['userCollectibleId'=>$ucT,'toPhone'=>'13900009102','paymentPassword'=>'Trade#2026'],$tokA);
$tid=(int)v("SELECT id FROM nft_transfers WHERE user_collectible_id=$ucT ORDER BY id DESC LIMIT 1");
T('6.2a A 发起转赠', ($r['code']??-1)===0&&$tid>0, json_encode($r,JSON_UNESCAPED_UNICODE)." tid=$tid");
T('6.2b 转赠中资产 frozen', v("SELECT status FROM nft_user_collectibles WHERE id=$ucT")==='frozen');
[$_,$r]=http('POST',"/api/transfers/$tid/handle",['action'=>'accept'],$tokB);
T('6.2c B 接受转赠', ($r['code']??-1)===0, "code={$r['code']} msg={$r['message']}");
T('6.2d 资产过户 B', (int)v("SELECT user_id FROM nft_user_collectibles WHERE id=$ucT")===$uidB);
// 6.3 合成
makeColl(9603,'审计合成材料');makeColl(9604,'审计合成产物');
$matIds=seedHolder($uidA,9603,2);
exe("DELETE FROM nft_synthesis_activities WHERE title='审计合成'");
exe("INSERT INTO nft_synthesis_activities (type,title,start_time,end_time,status,rules,result_collectible_id,result_quantity,per_user_limit,total_limit,used_count,created_at,updated_at)
  VALUES ('limit','审计合成','".date('Y-m-d H:i:s',time()-3600)."','".date('Y-m-d H:i:s',time()+3600)."',1,'审计合成规则',9604,2,1,10,0,NOW(),NOW())");
$synId=(int)v("SELECT id FROM nft_synthesis_activities WHERE title='审计合成'");
exe("INSERT INTO nft_synthesis_materials (activity_id,collectible_id,count,created_at) VALUES ($synId,9603,2,NOW())");
[$_,$r]=http('POST','/api/synthesis/submit',['activityId'=>$synId],$tokA);
T('6.3a 合成提交成功', ($r['code']??-1)===0, json_encode($r,JSON_UNESCAPED_UNICODE));
T('6.3b 产物发放(2份)', (int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$uidA AND collectible_id=9604 AND status='held' AND source='synthesis'")===2);
T('6.3c 材料消耗', (int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE id IN (".implode(',',$matIds).") AND status='consumed'")===2);
// 6.4 分解
makeColl(9605,'审计分解源');makeColl(9606,'审计分解产物');
$ucDeco=seedHolder($uidA,9605,1)[0];
exe("INSERT INTO nft_decompose_rules (name,source_collectible_id,enabled,per_user_limit,daily_limit,created_at,updated_at) VALUES ('审计分解',9605,1,5,0,NOW(),NOW())");
$ruleId=(int)v("SELECT id FROM nft_decompose_rules WHERE source_collectible_id=9605 ORDER BY id DESC LIMIT 1");
exe("INSERT INTO nft_decompose_items (rule_id,result_collectible_id,quantity_per) VALUES ($ruleId,9606,2)");
[$_,$r]=http('POST','/api/decompose/execute',['ruleId'=>$ruleId,'userCollectibleId'=>$ucDeco],$tokA);
T('6.4a 分解执行成功', ($r['code']??-1)===0, json_encode($r,JSON_UNESCAPED_UNICODE));
T('6.4b 产物发放(2份)', (int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$uidA AND collectible_id=9606 AND status='held'")===2);
T('6.4c 源资产 consumed', v("SELECT status FROM nft_user_collectibles WHERE id=$ucDeco")==='consumed');
// 6.5 盲盒（创建→上架/空投→开盒）+ 空投盲盒闭环
makeColl(9608,'审计盲盒');
exe("INSERT INTO nft_blind_boxes (collectible_id,description,is_openable,opened_count) VALUES (9608,'审计盲盒',1,0)");
$bbId=(int)v("SELECT id FROM nft_blind_boxes WHERE collectible_id=9608");
exe("INSERT INTO nft_blind_box_items (blind_box_id,prize_collectible_id,probability,quantity_limit,quantity_distributed) VALUES ($bbId,9606,1.0000,10,0)");
// 盲盒空投（修复后应成功）
[$_,$r]=http('POST','/admin/blind-boxes/airdrop',['id'=>$bbId,'users'=>['13900009102'],'quantity'=>1,'reason'=>'审计盲盒空投'],$atok);
T('6.5a 盲盒空投成功', ($r['code']??-1)===200, "code={$r['code']} msg={$r['message']}");
$ucBB=(int)v("SELECT id FROM nft_user_collectibles WHERE user_id=$uidB AND collectible_id=9608 AND source='airdrop' AND status='held' ORDER BY id DESC LIMIT 1");
T('6.5b 空投盲盒资产落库', $ucBB>0, "ucId=$ucBB");
if($ucBB>0){
  [$_,$r]=http('POST','/api/blind-boxes/open',['userCollectibleId'=>$ucBB,'paymentPassword'=>'Trade#2026'],$tokB);
  T('6.5c 空投盲盒可开启', ($r['code']??-1)===0, "code={$r['code']} msg={$r['message']}");
  T('6.5d 盲盒资产 consumed', v("SELECT status FROM nft_user_collectibles WHERE id=$ucBB")==='consumed');
  T('6.5e 奖品发放', (int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$uidB AND collectible_id=9606 AND source='blindbox'")>=1);
  T('6.5f opened_count 自增', (int)v("SELECT opened_count FROM nft_blind_boxes WHERE id=$bbId")===1, "opened=".v("SELECT opened_count FROM nft_blind_boxes WHERE id=$bbId"));
}
// 6.6 求购
makeColl(9607,'审计求购');exe("UPDATE nft_collectibles SET is_buy_request_enabled=1 WHERE id=9607");
$soldForBq=seedHolder($uidA,9607,1)[0];
[$_,$r]=http('POST','/api/buy-requests',['collectibleId'=>9607,'price'=>120,'quantity'=>1,'remark'=>'审计求购'],$tokB);
$bqId=($r['code']===0)?(int)($r['data']['id']??0):0;
T('6.6a B 发布求购单', ($r['code']??-1)===0, json_encode($r,JSON_UNESCAPED_UNICODE));
[$_,$r]=http('POST',"/api/buy-requests/$bqId/accept",[],$tokA);
T('6.6b A 接单成交', ($r['code']??-1)===0, "code={$r['code']} msg={$r['message']}");
T('6.6c 求购单已完成', (int)v("SELECT status FROM nft_buy_requests WHERE id=$bqId")===4, "status=".v("SELECT status FROM nft_buy_requests WHERE id=$bqId"));
T('6.6d 求购生成订单', v("SELECT order_no FROM nft_buy_requests WHERE id=$bqId")!=='');
T('6.6e 资产过户 B', (int)v("SELECT user_id FROM nft_user_collectibles WHERE id=$soldForBq")===$uidB);

echo "\n========== 汇总 ==========\n";
echo "PASS=$pass FAIL=$fail\n";
if($defects){echo "缺陷清单：\n - ".implode("\n - ",$defects)."\n";}
else{echo "未发现缺陷。\n";}