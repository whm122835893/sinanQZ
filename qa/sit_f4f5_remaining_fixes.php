<?php
/** 回归脚本：F4/F5 剩余 8 项缺陷修复验证
 *  覆盖：D1（下架后重挂500）、K02（转赠开关）、K03（求购开关）、K06（软删除求购）、
 *        K07（关寄售联动下架）、DC03（C端分解入口）、RF11（中签人数）、BB35（空投盲盒开启）
 *  前置：后端 127.0.0.1:8301 已启动，MySQL sinan_nft 已导入全部 SQL
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
function regUser($phone,$nick){global $PDO,$BASE;
  $PDO->exec("DELETE FROM nft_verification_codes WHERE phone='$phone'");
  $PDO->exec("INSERT INTO nft_verification_codes (phone,scene,code,expires_at,sent_at,ip,created_at) VALUES ('$phone','register','".password_hash('654321',PASSWORD_BCRYPT)."','".date('Y-m-d H:i:s',time()+600)."',NOW(),'127.0.0.1',NOW())");
  $ch=curl_init($BASE.'/api/auth/register');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'code'=>'654321','nickname'=>$nick])]);
  $j=json_decode(curl_exec($ch),true)?:[];curl_close($ch);
  $uid=$j['code']===0?(int)v("SELECT id FROM nft_users WHERE phone='$phone'"):0;
  return [$uid,(string)($j['data']['token']??'')];
}
function seedHolder($uid,$cid,$n=1,$source='purchase'){global $PDO;$ids=[];
  for($i=0;$i<$n;$i++){
    $s='SN-'.$cid.'-R'.substr((string)microtime(true),-5).$i.rand(100,999);
    $PDO->exec("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at)
      VALUES ($uid,$cid,'$s','$source',0,NOW(),'held',NOW(),NOW())");
    $ids[]=(int)v("SELECT id FROM nft_user_collectibles WHERE serial='$s'");
  }return $ids;
}
function seedCollectible($id,$name){global $PDO;
  $PDO->exec("DELETE FROM nft_user_collectibles WHERE collectible_id=$id");
  $PDO->exec("DELETE FROM nft_transfers WHERE collectible_id=$id");
  $PDO->exec("DELETE FROM nft_blind_box_items WHERE prize_collectible_id=$id OR blind_box_id IN (SELECT id FROM nft_blind_boxes WHERE collectible_id=$id)");
  $PDO->exec("DELETE FROM nft_blind_boxes WHERE collectible_id=$id");
  $PDO->exec("DELETE FROM nft_decompose_items WHERE result_collectible_id=$id");
  $PDO->exec("DELETE FROM nft_collectibles WHERE id=$id");
  $PDO->exec("INSERT INTO nft_collectibles (id,category_id,name,subtitle,image,price,edition,circulate,sold,locked_quantity,per_user_limit,is_transferable,is_resaleable,is_buy_request_enabled,resale_price_mode,resale_price_min,resale_price_max,status,issuer,brand,created_at,updated_at)
    VALUES ($id,1,'$name','修复回归','',100,1000,0,0,0,10,1,1,1,0,0,0,'onsale','司南文创','司南',NOW(),NOW())");
}

echo "=== R0 数据准备 ===\n";
$oldIds=q("SELECT id FROM nft_users WHERE phone LIKE '139000081%'");
if($oldIds){$oldIds=implode(',',array_column($oldIds,'id'));
  exe("SET FOREIGN_KEY_CHECKS=0");
  foreach(['nft_wallets','nft_wallet_transactions','nft_user_collectibles','nft_buy_requests',
           'nft_raffle_registrations','nft_decompose_records'] as $t){
    exe("DELETE FROM $t WHERE user_id IN ($oldIds)");
  }
  exe("DELETE FROM nft_transfers WHERE from_user_id IN ($oldIds) OR to_user_id IN ($oldIds)");
  exe("DELETE FROM nft_orders WHERE user_id IN ($oldIds)");
  exe("DELETE FROM nft_resale_listings WHERE seller_id IN ($oldIds)");
  exe("DELETE FROM nft_users WHERE id IN ($oldIds)");
  exe("SET FOREIGN_KEY_CHECKS=1");
}
exe("DELETE FROM nft_verification_codes WHERE phone LIKE '139000081%'");
[$uidA,$tokA]=regUser('13900008101','修复回归A');
[$uidB,$tokB]=regUser('13900008102','修复回归B');
T('R0.1 用户A/B注册',($uidA>0&&$uidB>0),"uidA=$uidA uidB=$uidB");
$tradeHash=password_hash('Trade#2026',PASSWORD_BCRYPT);
exe("UPDATE nft_users SET is_realname=1, transaction_password='$tradeHash' WHERE id IN ($uidA,$uidB)");
foreach([$uidA,$uidB] as $u){
  exe("DELETE FROM nft_wallets WHERE user_id=$u");
  exe("INSERT INTO nft_wallets (user_id,balance,available,frozen,created_at,updated_at) VALUES ($u,10000,10000,0,NOW(),NOW())");
}
// 藏品：9401转赠开关 9402求购开关 9404寄售联动 9405分解源 9406分解产物 9407盲盒 9410抽签
seedCollectible(9401,'K02转赠开关');  exe("UPDATE nft_collectibles SET is_transferable=0 WHERE id=9401");
seedCollectible(9402,'K03K06求购');   exe("UPDATE nft_collectibles SET is_buy_request_enabled=0 WHERE id=9402");
seedCollectible(9404,'D1K07寄售');
seedCollectible(9405,'DC03分解源');
seedCollectible(9406,'DC03分解产物');
seedCollectible(9407,'BB35空投盲盒');
seedCollectible(9410,'RF11抽签');
exe("INSERT INTO nft_system_configs (config_key,config_value,updated_at) VALUES ('resale_price_global_max','5000',NOW())
     ON DUPLICATE KEY UPDATE config_value='5000', updated_at=NOW()");
$ucK02=seedHolder($uidA,9401)[0];
$ucD1 =seedHolder($uidA,9404)[0];
$ucDC =seedHolder($uidA,9405)[0];
$ucBB =seedHolder($uidA,9407,1,'airdrop')[0]; // 空投来源盲盒
exe("DELETE FROM nft_resale_listings WHERE user_collectible_id IN ($ucK02,$ucD1,$ucDC,$ucBB)");
exe("DELETE FROM nft_decompose_records WHERE rule_id IN (SELECT id FROM (SELECT id FROM nft_decompose_rules WHERE source_collectible_id=9405) x)");
exe("DELETE FROM nft_decompose_items WHERE rule_id IN (SELECT id FROM (SELECT id FROM nft_decompose_rules WHERE source_collectible_id=9405) x) OR result_collectible_id=9406");
exe("DELETE FROM nft_decompose_rules WHERE source_collectible_id=9405");
exe("DELETE FROM nft_raffle_activities WHERE collectible_id=9410");
T('R0.2 藏品/持仓就绪', v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id IN ($uidA,$uidB) AND status='held'")>=4);

echo "\n=== K02 转赠开关（is_transferable=0 拒绝）===\n";
$r=http('POST','/api/transfers',['userCollectibleId'=>$ucK02,'toPhone'=>'13900008102','paymentPassword'=>'Trade#2026'],$tokA);
T('K02.1 关闭转赠时发起被拒', $r['code']!==0 && strpos($r['message'],'关闭转赠')!==false, "code={$r['code']} msg={$r['message']}");
T('K02.2 失败后资产仍 held', v("SELECT status FROM nft_user_collectibles WHERE id=$ucK02")==='held');
T('K02.3 未产生转赠记录', v("SELECT COUNT(*) FROM nft_transfers WHERE user_collectible_id=$ucK02")==0);
exe("UPDATE nft_collectibles SET is_transferable=1 WHERE id=9401");
$r=http('POST','/api/transfers',['userCollectibleId'=>$ucK02,'toPhone'=>'13900008102','paymentPassword'=>'Trade#2026'],$tokA);
T('K02.4 开启转赠后发起成功', $r['code']===0, "code={$r['code']} msg={$r['message']}");
T('K02.5 成功后资产 frozen 待确认', v("SELECT status FROM nft_user_collectibles WHERE id=$ucK02")==='frozen');
$tid=(int)v("SELECT id FROM nft_transfers WHERE user_collectible_id=$ucK02 AND status='pending'");
$r=http('POST',"/api/transfers/$tid/handle",['action'=>'accept'],$tokB);
T('K02.6 受赠方接受成功', $r['code']===0, "code={$r['code']} msg={$r['message']}");
T('K02.7 资产过户给 B', v("SELECT user_id FROM nft_user_collectibles WHERE id=$ucK02")==$uidB);

echo "\n=== K03 求购开关（is_buy_request_enabled=0 拒绝）===\n";
$r=http('POST','/api/buy-requests',['collectibleId'=>9402,'price'=>100,'quantity'=>1],$tokA);
T('K03.1 关闭求购时发布被拒', $r['code']!==0 && strpos($r['message'],'未开启求购')!==false, "code={$r['code']} msg={$r['message']}");
T('K03.2 未产生求购单', v("SELECT COUNT(*) FROM nft_buy_requests WHERE collectible_id=9402")==0);
exe("UPDATE nft_collectibles SET is_buy_request_enabled=1 WHERE id=9402");
$r=http('POST','/api/buy-requests',['collectibleId'=>9402,'price'=>100,'quantity'=>1],$tokA);
T('K03.3 开启后发布成功', $r['code']===0, "code={$r['code']} msg={$r['message']}");

echo "\n=== K06 软删除藏品求购拒绝 ===\n";
exe("UPDATE nft_collectibles SET deleted_at=NOW() WHERE id=9402");
$r=http('POST','/api/buy-requests',['collectibleId'=>9402,'price'=>100,'quantity'=>1],$tokA);
T('K06.1 软删除藏品发布求购被拒', $r['code']!==0 && strpos($r['message'],'藏品不存在')!==false, "code={$r['code']} msg={$r['message']}");
T('K06.2 求购单未新增', v("SELECT COUNT(*) FROM nft_buy_requests WHERE collectible_id=9402")==1);
exe("UPDATE nft_collectibles SET deleted_at=NULL WHERE id=9402");

echo "\n=== D1 系统下架后重挂（cooldown_until=NULL 不再500）===\n";
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucD1,'price'=>100,'paymentPassword'=>'Trade#2026'],$tokA);
T('D1.1 首次挂单成功', $r['code']===0, "code={$r['code']} msg={$r['message']}");
$listingId=(int)v("SELECT id FROM nft_resale_listings WHERE user_collectible_id=$ucD1 AND status='selling'");
// 模拟管理端强制下架：cancelled + 系统下架标记 + cooldown_until=NULL（原始 bug 触发条件）
exe("UPDATE nft_resale_listings SET status='cancelled', is_system_delisted=1, system_delisted_at=NOW(), delist_reason='回归测试系统下架', cooldown_until=NULL WHERE id=$listingId");
exe("UPDATE nft_user_collectibles SET status='held', is_consigned=0 WHERE id=$ucD1");
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucD1,'price'=>100,'paymentPassword'=>'Trade#2026'],$tokA);
T('D1.2 系统下架后重新挂单成功（修复前 500）', $r['code']===0, "code={$r['code']} msg={$r['message']}");
$listing2=(int)v("SELECT id FROM nft_resale_listings WHERE user_collectible_id=$ucD1 AND status='selling'");
T('D1.3 新挂单在售', $listing2>0);

echo "\n=== K07 关闭寄售开关联动下架在售挂单 ===\n";
$admLogin=http('POST','/admin/auth/login',['username'=>'admin','password'=>'admin123']);
$admTok=(string)($admLogin['data']['token']??'');
T('K07.1 超管登录', $admTok!=='', "code={$admLogin['code']}");
$r=http('PUT',"/admin/collectibles/9404/market-config",['is_resaleable'=>0],$admTok);
T('K07.2 关闭寄售开关成功', $r['code']===200, "code={$r['code']} msg={$r['message']}");
T('K07.3 在售挂单联动下架（cancelled+系统下架）', v("SELECT status FROM nft_resale_listings WHERE id=$listing2")==='cancelled'
    && (int)v("SELECT is_system_delisted FROM nft_resale_listings WHERE id=$listing2")===1);
T('K07.4 资产退回 held', v("SELECT status FROM nft_user_collectibles WHERE id=$ucD1")==='held');
T('K07.5 资产 is_consigned 清零', (int)v("SELECT is_consigned FROM nft_user_collectibles WHERE id=$ucD1")===0);
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucD1,'price'=>100,'paymentPassword'=>'Trade#2026'],$tokA);
T('K07.6 开关关闭后新挂单被拒', $r['code']!==0 && strpos($r['message'],'关闭寄售')!==false, "code={$r['code']} msg={$r['message']}");

echo "\n=== DC03 C端分解执行入口 ===\n";
exe("DELETE FROM nft_decompose_records WHERE rule_id IN (SELECT id FROM nft_decompose_rules WHERE source_collectible_id=9405)");
exe("INSERT INTO nft_decompose_rules (name,source_collectible_id,enabled,per_user_limit,daily_limit,created_at,updated_at)
     VALUES ('DC03回归规则',9405,1,5,0,NOW(),NOW())");
$ruleId=(int)v("SELECT id FROM nft_decompose_rules WHERE source_collectible_id=9405 ORDER BY id DESC LIMIT 1");
exe("INSERT INTO nft_decompose_items (rule_id,result_collectible_id,quantity_per) VALUES ($ruleId,9406,2)");
$r=http('GET','/api/decompose/rules',null,$tokA);
$found=false;
foreach(($r['data']['list']??[]) as $ru){ if((int)$ru['ruleId']===$ruleId){ $found=true; $ruleDetail=$ru; } }
T('DC03.1 规则列表返回该规则', $found, "code={$r['code']} ruleId=$ruleId");
T('DC03.2 规则含产出明细（9406×2）', $found && count($ruleDetail['results'])===1 && (int)$ruleDetail['results'][0]['quantityPer']===2);
T('DC03.3 我的持有数=1', $found && (int)$ruleDetail['myHeldCount']===1);
$circBefore=(int)v("SELECT circulate FROM nft_collectibles WHERE id=9406");
$r=http('POST','/api/decompose/execute',['ruleId'=>$ruleId,'userCollectibleId'=>$ucDC],$tokA);
T('DC03.4 执行分解成功', $r['code']===0, "code={$r['code']} msg={$r['message']}");
T('DC03.5 源资产置 consumed', v("SELECT status FROM nft_user_collectibles WHERE id=$ucDC")==='consumed');
T('DC03.6 产物发放 2 件且归 A', v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$uidA AND collectible_id=9406 AND status='held'")==2);
T('DC03.7 decompose_records 台账写入', v("SELECT COUNT(*) FROM nft_decompose_records WHERE rule_id=$ruleId AND user_id=$uidA")==1);
T('DC03.8 产物藏品 circulate +2', ((int)v("SELECT circulate FROM nft_collectibles WHERE id=9406")-$circBefore)===2);
$r=http('GET','/api/decompose/records',null,$tokA);
T('DC03.9 我的分解记录 1 条', $r['code']===0 && (int)($r['data']['total']??-1)===1, "code={$r['code']}");
$r=http('POST','/api/decompose/execute',['ruleId'=>$ruleId,'userCollectibleId'=>$ucDC],$tokA);
T('DC03.10 已消耗资产不可重复分解', $r['code']!==0, "code={$r['code']}");

echo "\n=== RF11 中签人数=winner_count ===\n";
exe("INSERT INTO nft_raffle_activities (collectible_id,name,ticket_price,limit_per_user,winner_count,sale_quantity,sale_price,
     registration_start,registration_end,draw_time,purchase_start,purchase_end,status,created_at,updated_at)
     VALUES (9410,'RF11回归',0,5,2,2,100,'".date('Y-m-d H:i:s',time()-3600)."','".date('Y-m-d H:i:s',time()+3600)."',
     '".date('Y-m-d H:i:s',time()+7200)."','".date('Y-m-d H:i:s',time()+7200)."','".date('Y-m-d H:i:s',time()+86400)."',1,NOW(),NOW())");
$actId=(int)v("SELECT id FROM nft_raffle_activities WHERE collectible_id=9410 ORDER BY id DESC LIMIT 1");
$r=http('POST',"/api/raffle/activities/$actId/register",['ticketCount'=>3],$tokA);
T('RF11.1 A 报名 3 票', $r['code']===0, "code={$r['code']} msg={$r['message']}");
$r=http('POST',"/api/raffle/activities/$actId/register",['ticketCount'=>1],$tokB);
T('RF11.2 B 报名 1 票', $r['code']===0, "code={$r['code']} msg={$r['message']}");
$r=http('POST',"/admin/raffle/$actId/draw",[],$admTok);
T('RF11.3 管理端执行抽签', $r['code']===200, "code={$r['code']} msg={$r['message']}");
T('RF11.4 中签人数=winner_count(2)', (int)v("SELECT COUNT(*) FROM nft_raffle_registrations WHERE activity_id=$actId AND draw_status=1")===2,
    'winners='.v("SELECT COUNT(*) FROM nft_raffle_registrations WHERE activity_id=$actId AND draw_status=1"));
T('RF11.5 中签为 2 个不同用户', v("SELECT COUNT(DISTINCT user_id) FROM nft_raffle_registrations WHERE activity_id=$actId AND draw_status=1")===2);
T('RF11.6 活动置已结束(3)', v("SELECT status FROM nft_raffle_activities WHERE id=$actId")=='3');
exe("DELETE FROM nft_raffle_registrations WHERE activity_id=$actId");
exe("DELETE FROM nft_raffle_activities WHERE id=$actId");

echo "\n=== BB35 空投来源盲盒可开启 ===\n";
exe("INSERT INTO nft_blind_boxes (collectible_id,description,is_openable,opened_count) VALUES (9407,'BB35回归',1,0)");
$bbId=(int)v("SELECT id FROM nft_blind_boxes WHERE collectible_id=9407");
exe("INSERT INTO nft_blind_box_items (blind_box_id,prize_collectible_id,probability,quantity_limit,quantity_distributed)
     VALUES ($bbId,9406,1.0000,NULL,0)");
$r=http('POST','/api/blind-boxes/open',['userCollectibleId'=>$ucBB,'paymentPassword'=>'Trade#2026'],$tokA);
T('BB35.1 空投来源盲盒开启成功', $r['code']===0, "code={$r['code']} msg={$r['message']}");
T('BB35.2 盲盒资产置 consumed', v("SELECT status FROM nft_user_collectibles WHERE id=$ucBB")==='consumed');
$prizeCount=v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$uidA AND collectible_id=9406 AND blind_box_item_id IS NOT NULL");
T('BB35.3 奖品资产发放 1 件', $prizeCount===1, "prizeCount=$prizeCount");

echo "\n================ 汇总 ================\n";
echo "PASS: $pass  FAIL: $fail\n";
exit($fail>0?1:0);
