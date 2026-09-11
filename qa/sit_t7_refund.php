<?php
/** F7-1 管理端退款审批流集成测试
 *  覆盖：发起→复核→执行全链路、大额≥阈值走审批中心、不能审批自己、
 *        资金/资产/库存三合一原子回滚、拒绝/驳回联动、边界拦截
 *  前置：后端 8301 已启动；nft_admin_users 需有 finance/risk 账号（脚本自建）
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
function adminLogin($u,$p){$r=http('POST','/admin/auth/login',['username'=>$u,'password'=>$p]);return [ (string)($r['data']['token']??''), $r ];}
function seedOrder($uid,$cid,$qty,$unit,$source,$status){global $PDO;
  $no='F71-'.substr((string)microtime(true),-6).rand(100,999);
  $now=date('Y-m-d H:i:s');
  $PDO->exec("INSERT INTO nft_orders (order_no,user_id,collectible_id,resale_listing_id,unit_price,quantity,total_price,status,source,created_at,paid_at,completed_at,expires_at,updated_at)
    VALUES ('$no',$uid,$cid,NULL,$unit,$qty,".($unit*$qty).",'$status','$source','$now','$now','$now','$now','$now')");
  $oid=(int)v("SELECT id FROM nft_orders WHERE order_no='$no'");
  if($oid<=0){fwrite(STDERR,"[seedOrder] 订单落库失败 order_no=$no\n");return [0,0,$no];}
  // 状态机对齐真实路径：pending 订单未支付 → 无支付单、无资产行（资产在支付成功后才创建；
  // 否则 z_abort Z2-4 / z_identity Z5-2 会判定 held 资产为 pending 订单脏数据）
  $pid=0;
  if($status!=='pending'){
    $PDO->exec("INSERT INTO nft_payments (order_id,user_id,amount,payment_method,transaction_no,status,paid_at,created_at,updated_at)
      VALUES ($oid,$uid,".($unit*$qty).",'balance','TX-F71-$oid','success','$now','$now','$now')");
    $pid=(int)v("SELECT id FROM nft_payments WHERE order_id=$oid");
    for($i=0;$i<$qty;$i++){
      $s='SN-F71-'.$oid.'-'.$i;
      $PDO->exec("INSERT INTO nft_user_collectibles (user_id,collectible_id,order_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at)
        VALUES ($uid,$cid,$oid,'$s','purchase',$unit,'$now','held','$now','$now')");
    }
  }
  // 模拟真实购买态：release+completed → sold/circulate 已累加；release+pending → 锁定库存；market → 不变
  if($source==='release'){
    if($status==='completed') exe("UPDATE nft_collectibles SET sold=sold+$qty, circulate=circulate+$qty WHERE id=$cid");
    else exe("UPDATE nft_collectibles SET locked_quantity=locked_quantity+$qty WHERE id=$cid");
  }
  return [$oid,$pid,$no];
}

echo "=== 7.1 环境准备 ===\n";
// 五角色账号（幂等）：finance/risk 归本测试使用
$adminPwdHash=password_hash('RoleTest#2026',PASSWORD_BCRYPT);
foreach([['finance_admin','财务审批','3'],['risk_admin','风控复核','4'],['operator_admin','运营','2'],['support_admin','客服','5']] as $a){
  exe("DELETE FROM nft_admin_users WHERE username='{$a[0]}'");
  exe("INSERT INTO nft_admin_users (username,password_hash,real_name,role_id,status,created_at,updated_at)
    VALUES ('{$a[0]}','$adminPwdHash','{$a[1]}',{$a[2]},1,NOW(),NOW())");
}
[$tokSuper,$r1]=adminLogin('admin','admin123');
[$tokFin,$r2]=adminLogin('finance_admin','RoleTest#2026');
[$tokRisk,$r3]=adminLogin('risk_admin','RoleTest#2026');
T('7.1.1 三管理账号登录（超管/财务/风控）', $tokSuper!=='' && $tokFin!=='' && $tokRisk!=='', "super:".( $tokSuper!==''?'OK':'NG')." fin:".($tokFin!==''?'OK':'NG')." risk:".($tokRisk!==''?'OK':'NG'));
// 越权预检：客服无 refund:approve
[$tokSup,$r4]=adminLogin('support_admin','RoleTest#2026');
$r=http('POST','/admin/refunds/999/approve',['id'=>999,'action'=>'approve'],$tokSup);
T('7.1.2 越权预检：客服调退款审批被拒(4003)', $r['code']===4003, "code={$r['code']} msg={$r['message']}");

// 测试用户 + 藏品
$oldUsers=q("SELECT id FROM nft_users WHERE phone LIKE '139000071%'");
if($oldUsers){$ids=implode(',',array_column($oldUsers,'id'));
  exe("SET FOREIGN_KEY_CHECKS=0");
  foreach(['nft_wallets','nft_wallet_transactions','nft_user_collectibles','nft_orders','nft_payments','nft_refunds'] as $t) exe("DELETE FROM $t WHERE user_id IN ($ids)");
  exe("DELETE FROM nft_users WHERE id IN ($ids)");
  exe("SET FOREIGN_KEY_CHECKS=1");
}
exe("DELETE FROM nft_orders WHERE order_no LIKE 'F71-%'");
exe("DELETE FROM nft_refunds WHERE refund_no LIKE 'RF%' AND order_id NOT IN (SELECT id FROM nft_orders)");
exe("DELETE FROM nft_approval_requests WHERE target_type='refund' AND target_id NOT IN (SELECT id FROM nft_refunds)");
$phone='13900007101';
exe("INSERT INTO nft_verification_codes (phone,scene,code,expires_at,sent_at,ip,created_at) VALUES ('$phone','register','".password_hash('654321',PASSWORD_BCRYPT)."','".date('Y-m-d H:i:s',time()+600)."',NOW(),'127.0.0.1',NOW())");
$r=json_decode((function()use($BASE,$phone){$ch=curl_init($BASE.'/api/auth/register');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'code'=>'654321','nickname'=>'退款用户'])]);$raw=curl_exec($ch);curl_close($ch);return $raw;})(),true);
$uid=(int)v("SELECT id FROM nft_users WHERE phone='$phone'");
exe("UPDATE nft_users SET is_realname=1 WHERE id=$uid");
exe("DELETE FROM nft_wallets WHERE user_id=$uid");
exe("INSERT INTO nft_wallets (user_id,balance,available,frozen,created_at,updated_at) VALUES ($uid,1000,1000,0,NOW(),NOW())");
T('7.1.3 C 端测试用户就绪', $uid>0, "uid=$uid");

// 藏品 9401~9406：初始 sold=0 circulate=0；订单造数后累计
foreach([9401,9402,9403,9404,9405,9406] as $cid){
  exe("DELETE FROM nft_user_collectibles WHERE collectible_id=$cid");
  exe("DELETE FROM nft_collectibles WHERE id=$cid");
  exe("INSERT INTO nft_collectibles (id,category_id,name,subtitle,image,price,edition,circulate,sold,locked_quantity,status,issuer,created_at,updated_at)
    VALUES ($cid,1,'退款测试$cid','','/img/test.png',100,100,0,0,0,'onsale','司南文创',NOW(),NOW())");
}
// O1 小额 2×100=200；O2 大额 2×750=1500；O3 小额 3×100=300；O4 大额 2×600=1200；O5 pending；O6 market；O7 漂移（sold 保持 0）
[$o1,,]=seedOrder($uid,9401,2,100,'release','completed');
[$o2,,]=seedOrder($uid,9402,2,750,'release','completed');
[$o3,,]=seedOrder($uid,9403,3,100,'release','completed');
[$o4,,]=seedOrder($uid,9404,2,600,'release','completed');
[$o5,,]=seedOrder($uid,9405,1,100,'release','pending');
[$o6,,]=seedOrder($uid,9401,1,100,'market','completed');
// O7：构造 sold < 退款回收量的数据漂移场景（验证 UNSIGNED 溢出防护，不累加 sold）
$no7='F71-DRIFT-'.rand(100,999);
$now7=date('Y-m-d H:i:s');
exe("INSERT INTO nft_orders (order_no,user_id,collectible_id,resale_listing_id,unit_price,quantity,total_price,status,source,created_at,paid_at,completed_at,expires_at,updated_at)
  VALUES ('$no7',$uid,9406,NULL,100,2,200,'completed','release','$now7','$now7','$now7','$now7','$now7')");
$o7=(int)v("SELECT id FROM nft_orders WHERE order_no='$no7'");
exe("INSERT INTO nft_payments (order_id,user_id,amount,payment_method,transaction_no,status,paid_at,created_at,updated_at)
  VALUES ($o7,$uid,200,'balance','TX-F71-$o7','success','$now7','$now7','$now7')");
for($i=0;$i<2;$i++) exe("INSERT INTO nft_user_collectibles (user_id,collectible_id,order_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at)
  VALUES ($uid,9406,$o7,'SN-F71-D$i','purchase',100,'$now7','held','$now7','$now7')");
T('7.1.4 七笔订单造数完成（含 1 笔漂移）', v("SELECT COUNT(*) FROM nft_orders WHERE order_no LIKE 'F71-%'")==7);

echo "\n=== 7.2 发起退款（边界拦截）===\n";
$r=http('POST',"/admin/orders/$o5/refund",['id'=>$o5,'reason'=>'测试'],$tokSuper);
T('7.2.1 非已完成订单发起被拒', $r['code']===4220, "code={$r['code']} msg={$r['message']}");
$r=http('POST',"/admin/orders/$o6/refund",['id'=>$o6,'reason'=>'测试'],$tokSuper);
T('7.2.2 市场来源订单发起被拒（走工单）', $r['code']===4220 && strpos($r['message'],'市场')!==false, "code={$r['code']} msg={$r['message']}");
$r=http('POST',"/admin/orders/$o1/refund",['id'=>$o1,'reason'=>'小额退款','amount'=>999],$tokSuper);
T('7.2.3 退款金额超订单总额被拒', $r['code']===4220, "code={$r['code']} msg={$r['message']}");
$r=http('POST',"/admin/orders/$o1/refund",['id'=>$o1,'reason'=>'小额退款'],$tokSuper);
$rf1=(int)($r['data']['refund_id']??0);
T('7.2.4 小额退款发起成功', $r['code']===200 && $rf1>0, json_encode($r,JSON_UNESCAPED_UNICODE));
T('7.2.5 订单进入 refunding 状态', v("SELECT status FROM nft_orders WHERE id=$o1")=='refunding');
T('7.2.6 退款单落库（待审批=1）', v("SELECT status FROM nft_refunds WHERE id=$rf1")=='1');
$r=http('POST',"/admin/orders/$o1/refund",['id'=>$o1,'reason'=>'重复发起'],$tokSuper);
T('7.2.7 重复发起被拒', $r['code']===4220, "code={$r['code']} msg={$r['message']}");

echo "\n=== 7.3 小额直批+执行（三合一回滚）===\n";
$r=http('POST',"/admin/refunds/$rf1/approve",['id'=>$rf1,'action'=>'approve','comment'=>'同意'],$tokFin);
T('7.3.1 财务直批成功（<1000 不进审批中心）', $r['code']===200, json_encode($r,JSON_UNESCAPED_UNICODE));
T('7.3.2 退款单状态=2 已批准', v("SELECT status FROM nft_refunds WHERE id=$rf1")=='2');
T('7.3.3 审批中心无此单审批记录', v("SELECT COUNT(*) FROM nft_approval_requests WHERE target_type='refund' AND target_id=$rf1")==0);
$bal=v("SELECT available FROM nft_wallets WHERE user_id=$uid");
$sold1=(int)v("SELECT sold FROM nft_collectibles WHERE id=9401");
$circ1=(int)v("SELECT circulate FROM nft_collectibles WHERE id=9401");
$r=http('POST',"/admin/refunds/$rf1/execute",['id'=>$rf1],$tokSuper);
T('7.3.4 执行退款成功', $r['code']===200, json_encode($r,JSON_UNESCAPED_UNICODE));
T('7.3.5 资金回退：余额 +200', abs(v("SELECT available FROM nft_wallets WHERE user_id=$uid")-($bal+200))<0.001);
T('7.3.6 退款流水（biz_no=退款单号）', v("SELECT COUNT(*) FROM nft_wallet_transactions WHERE user_id=$uid AND biz_no='".v("SELECT refund_no FROM nft_refunds WHERE id=$rf1")."'")==1);
T('7.3.7 资产回收：2 件 recovered', v("SELECT COUNT(*) FROM nft_user_collectibles WHERE order_id=$o1 AND status='recovered'")==2);
T('7.3.8 库存回滚：sold -2', ((int)v("SELECT sold FROM nft_collectibles WHERE id=9401")-$sold1)==-2);
T('7.3.9 库存回滚：circulate -2', ((int)v("SELECT circulate FROM nft_collectibles WHERE id=9401")-$circ1)==-2);
T('7.3.10 订单状态 refunded', v("SELECT status FROM nft_orders WHERE id=$o1")=='refunded');
T('7.3.11 支付单状态 refunded', v("SELECT p.status FROM nft_payments p JOIN nft_refunds r ON r.payment_id=p.id WHERE r.id=$rf1")=='refunded');
T('7.3.12 退款单状态=3 已退款', v("SELECT status FROM nft_refunds WHERE id=$rf1")=='3');
$r=http('POST',"/admin/refunds/$rf1/execute",['id'=>$rf1],$tokSuper);
T('7.3.13 重复执行被拒（状态机）', $r['code']===4220, "code={$r['code']} msg={$r['message']}");

echo "\n=== 7.3D 漂移边界（sold < 回收量，UNSIGNED 溢出防护）===\n";
$r=http('POST',"/admin/orders/$o7/refund",['id'=>$o7,'reason'=>'漂移场景'],$tokSuper);
$rf7=(int)($r['data']['refund_id']??0);
T('7.3D.1 漂移订单退款发起', $r['code']===200 && $rf7>0);
$r=http('POST',"/admin/refunds/$rf7/approve",['id'=>$rf7,'action'=>'approve','comment'=>'同意'],$tokFin);
T('7.3D.2 小额直批', $r['code']===200);
$bal=v("SELECT available FROM nft_wallets WHERE user_id=$uid");
$r=http('POST',"/admin/refunds/$rf7/execute",['id'=>$rf7],$tokSuper);
T('7.3D.3 sold=0 回收 2 件不溢出报错（曾经 1690）', $r['code']===200, json_encode($r,JSON_UNESCAPED_UNICODE));
T('7.3D.4 sold 下限钳位 0', v("SELECT sold FROM nft_collectibles WHERE id=9406")=='0');
T('7.3D.5 circulate 下限钳位 0', v("SELECT circulate FROM nft_collectibles WHERE id=9406")=='0');
T('7.3D.6 资产仍正常回收 recovered', v("SELECT COUNT(*) FROM nft_user_collectibles WHERE order_id=$o7 AND status='recovered'")==2);
T('7.3D.7 资金仍正常回退 +200', abs(v("SELECT available FROM nft_wallets WHERE user_id=$uid")-($bal+200))<0.001);

echo "\n=== 7.4 大额退款（≥1000 走审批中心）===\n";
$r=http('POST',"/admin/orders/$o2/refund",['id'=>$o2,'reason'=>'大额退款测试'],$tokSuper);
$rf2=(int)($r['data']['refund_id']??0);
T('7.4.1 大额退款发起成功', $r['code']===200 && $rf2>0);
$r=http('POST',"/admin/refunds/$rf2/approve",['id'=>$rf2,'action'=>'approve','comment'=>'财务同意'],$tokFin);
T('7.4.2 财务批准被引导至审批中心（不直接批准）', $r['code']===200 && strpos($r['message'],'审批中心')!==false, json_encode($r,JSON_UNESCAPED_UNICODE));
T('7.4.3 退款单仍为待审批=1', v("SELECT status FROM nft_refunds WHERE id=$rf2")=='1');
$apId=(int)v("SELECT id FROM nft_approval_requests WHERE target_type='refund' AND target_id=$rf2 AND status=1");
T('7.4.4 审批单已生成（status=1）', $apId>0);
T('7.4.5 审批单发起人=财务', v("SELECT applicant_id FROM nft_approval_requests WHERE id=$apId")==v("SELECT id FROM nft_admin_users WHERE username='finance_admin'"));
$r=http('POST',"/admin/refunds/$rf2/approve",['id'=>$rf2,'action'=>'approve','comment'=>'再批'],$tokFin);
T('7.4.6 重复提交审批被拒', $r['code']===4220, "code={$r['code']} msg={$r['message']}");
// 财务自审：发起人是财务自己 → 拦截
$r=http('POST',"/admin/approvals/$apId/handle",['action'=>'approve'],$tokFin);
T('7.4.7 发起人不能审批自己（4003 越权拦截）', $r['code']===4003, "code={$r['code']} msg={$r['message']}");
// 风控复核通过
$r=http('POST',"/admin/approvals/$apId/handle",['action'=>'approve','reason'=>'复核通过'],$tokRisk);
T('7.4.8 风控复核通过', $r['code']===200, json_encode($r,JSON_UNESCAPED_UNICODE));
T('7.4.9 审批单状态=2', v("SELECT status FROM nft_approval_requests WHERE id=$apId")=='2');
T('7.4.10 退款单联动为已批准=2', v("SELECT status FROM nft_refunds WHERE id=$rf2")=='2');
$bal=v("SELECT available FROM nft_wallets WHERE user_id=$uid");
$sold2=(int)v("SELECT sold FROM nft_collectibles WHERE id=9402");
$r=http('POST',"/admin/refunds/$rf2/execute",['id'=>$rf2],$tokSuper);
T('7.4.11 大额退款执行成功', $r['code']===200);
T('7.4.12 资金回退 +1500', abs(v("SELECT available FROM nft_wallets WHERE user_id=$uid")-($bal+1500))<0.001);
T('7.4.13 资产回收 2 件', v("SELECT COUNT(*) FROM nft_user_collectibles WHERE order_id=$o2 AND status='recovered'")==2);
T('7.4.14 库存 sold -2', ((int)v("SELECT sold FROM nft_collectibles WHERE id=9402")-$sold2)==-2);
T('7.4.15 订单 refunded', v("SELECT status FROM nft_orders WHERE id=$o2")=='refunded');

echo "\n=== 7.5 拒绝流（财务直拒）===\n";
$r=http('POST',"/admin/orders/$o3/refund",['id'=>$o3,'reason'=>'拒绝流测试'],$tokSuper);
$rf3=(int)($r['data']['refund_id']??0);
T('7.5.1 拒绝流退款发起', $r['code']===200 && $rf3>0);
$r=http('POST',"/admin/refunds/$rf3/approve",['id'=>$rf3,'action'=>'reject'],$tokFin);
T('7.5.2 拒绝必须填意见', $r['code']===4220, "code={$r['code']} msg={$r['message']}");
$r=http('POST',"/admin/refunds/$rf3/approve",['id'=>$rf3,'action'=>'reject','comment'=>'不满足条件'],$tokFin);
T('7.5.3 财务拒绝成功', $r['code']===200);
T('7.5.4 退款单状态=4 已拒绝', v("SELECT status FROM nft_refunds WHERE id=$rf3")=='4');
T('7.5.5 订单回滚 completed', v("SELECT status FROM nft_orders WHERE id=$o3")=='completed');
T('7.5.6 资产未回收（仍 held）', v("SELECT COUNT(*) FROM nft_user_collectibles WHERE order_id=$o3 AND status='held'")==3);

echo "\n=== 7.6 审批中心：自审批拦截 + 驳回联动 ===\n";
$r=http('POST',"/admin/orders/$o4/refund",['id'=>$o4,'reason'=>'驳回联动测试'],$tokSuper);
$rf4=(int)($r['data']['refund_id']??0);
T('7.6.1 大额退款发起', $r['code']===200 && $rf4>0);
// 超管直接批准大额退款 → 生成审批单（发起人=超管）
$r=http('POST',"/admin/refunds/$rf4/approve",['id'=>$rf4,'action'=>'approve','comment'=>'提交复核'],$tokSuper);
$ap4=(int)v("SELECT id FROM nft_approval_requests WHERE target_type='refund' AND target_id=$rf4 AND status=1");
T('7.6.2 超管批准大额→进审批中心', $r['code']===200 && $ap4>0, json_encode($r,JSON_UNESCAPED_UNICODE));
// 超管（发起人）自审 → 应被发起人/复核人分离原则拦截（4220）
$r=http('POST',"/admin/approvals/$ap4/handle",['action'=>'approve','reason'=>'自己批自己'],$tokSuper);
T('7.6.3 发起人不能审批自己（4220 分离原则）', $r['code']===4220 && strpos($r['message'],'自己')!==false, "code={$r['code']} msg={$r['message']}");
$r=http('POST',"/admin/approvals/$ap4/handle",['action'=>'reject'],$tokRisk);
T('7.6.4 驳回必须填意见', $r['code']===4220, "code={$r['code']} msg={$r['message']}");
$r=http('POST',"/admin/approvals/$ap4/handle",['action'=>'reject','reason'=>'金额存疑'],$tokRisk);
T('7.6.5 风控驳回成功', $r['code']===200, json_encode($r,JSON_UNESCAPED_UNICODE));
T('7.6.6 审批单状态=3 已驳回', v("SELECT status FROM nft_approval_requests WHERE id=$ap4")=='3');
T('7.6.7 退款单联动为已拒绝=4', v("SELECT status FROM nft_refunds WHERE id=$rf4")=='4');
T('7.6.8 订单回滚 completed', v("SELECT status FROM nft_orders WHERE id=$o4")=='completed');
T('7.6.9 审批单无待处理残留', v("SELECT COUNT(*) FROM nft_approval_requests WHERE id=$ap4 AND status=1")==0);
$r=http('POST',"/admin/approvals/$ap4/handle",['action'=>'approve','reason'=>'重复处理'],$tokRisk);
T('7.6.10 已处理审批单不可重复处理', $r['code']===4220, "code={$r['code']} msg={$r['message']}");

echo "\n=== 7.7 恒等式校验 ===\n";
// 已退款订单（O1/O2/O7）资产全部 recovered；市场单 O6 的资产仍 held（不在退款范围）
$diff=v("SELECT COUNT(*) FROM nft_user_collectibles WHERE order_id IN ($o1,$o2,$o7) AND status NOT IN ('recovered','consumed')");
T('7.7.1 已退款资产全部 recovered（无游离）', $diff==0, "游离 $diff");
$balNow=(float)v("SELECT available FROM nft_wallets WHERE user_id=$uid");
$expect=1000.0+200+1500+200; // 初始+O1+O2+O7 三笔退款
T('7.7.2 用户资金账实相符（1000+200+1500+200）', abs($balNow-$expect)<0.001, "实际 $balNow 期望 $expect");

echo "\n=== 7.8 环境清理（还原夹具，保证 z 系列全库审计干净）===\n";
// 与 7.1 开头清理对称：删 F71 订单族 + 退款/审批残留 + 还原 9401~9406 + 删 t7 专属用户
$fu=q("SELECT id FROM nft_users WHERE phone LIKE '139000071%'");
if($fu){$fids=implode(',',array_column($fu,'id'));
  exe("SET FOREIGN_KEY_CHECKS=0");
  foreach(['nft_wallets','nft_wallet_transactions','nft_user_collectibles','nft_orders','nft_payments','nft_refunds'] as $t) exe("DELETE FROM $t WHERE user_id IN ($fids)");
  exe("DELETE FROM nft_users WHERE id IN ($fids)");
  exe("SET FOREIGN_KEY_CHECKS=1");
}
exe("DELETE FROM nft_user_collectibles WHERE order_id IN (SELECT id FROM nft_orders WHERE order_no LIKE 'F71-%')");
exe("DELETE FROM nft_payments WHERE order_id IN (SELECT id FROM nft_orders WHERE order_no LIKE 'F71-%')");
exe("DELETE FROM nft_refunds WHERE order_id IN (SELECT id FROM nft_orders WHERE order_no LIKE 'F71-%')");
exe("DELETE FROM nft_approval_requests WHERE target_type='refund' AND target_id NOT IN (SELECT id FROM nft_refunds)");
exe("DELETE FROM nft_orders WHERE order_no LIKE 'F71-%'");
// 9401~9406 为多脚本共用夹具段：先清引用行（transfers/盲盒奖品/历史资产），再删藏品，避免 FK RESTRICT
exe("DELETE FROM nft_transfers WHERE collectible_id IN (9401,9402,9403,9404,9405,9406)");
exe("DELETE FROM nft_blind_box_items WHERE prize_collectible_id IN (9401,9402,9403,9404,9405,9406)");
exe("DELETE FROM nft_user_collectibles WHERE collectible_id IN (9401,9402,9403,9404,9405,9406)");
exe("DELETE FROM nft_collectibles WHERE id IN (9401,9402,9403,9404,9405,9406)");
exe("DELETE FROM nft_verification_codes WHERE phone LIKE '139000071%'");
T('7.8.1 F71 订单族已清理', v("SELECT COUNT(*) FROM nft_orders WHERE order_no LIKE 'F71-%'")==0);
T('7.8.2 测试用户已清理', v("SELECT COUNT(*) FROM nft_users WHERE phone LIKE '139000071%'")==0);
T('7.8.3 退款测试藏品已清理', v("SELECT COUNT(*) FROM nft_collectibles WHERE id IN (9401,9402,9403,9404,9405,9406)")==0);
T('7.8.4 无孤儿审批单', v("SELECT COUNT(*) FROM nft_approval_requests WHERE target_type='refund' AND target_id NOT IN (SELECT id FROM nft_refunds)")==0);

echo "\n=== 汇总 ===\n";
echo "PASS=$pass FAIL=$fail\n";
if($fail>0){echo "存在未通过项！\n";exit(1);}
echo "F7-1 退款审批流全部通过。\n";
