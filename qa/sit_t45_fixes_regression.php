<?php
/** 回归脚本：F4/F5 未修复缺陷修复后回归（7 项）
 *  覆盖：K01/K04/K05（寄售开关+价格管控）、S06（置换过户）、B08（求购生成订单）、
 *        SY23（合成按 result_quantity 发产物）、RF03（抽签购 C 端入口）
 *  前置：后端 127.0.0.1:8301 已启动，MySQL sinan_nft 已导入全部 SQL（含 raffle_purchase_upgrade.sql）
 */
date_default_timezone_set('Asia/Shanghai');
$BASE='http://127.0.0.1:8301';
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING]);
$pass=0;$fail=0;$defects=[];
function T($n,$c,$d=''){global $pass,$fail;$c?$pass++:$fail++;echo($c?"  PASS ":"  FAIL ").$n.($d?" | $d":"")."\n";}
function http($m,$u,$b=null,$t=null){global $BASE;$ch=curl_init($BASE.$u);$h=['Content-Type: application/json'];if($t)$h[]="Authorization: Bearer $t";
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$h,CURLOPT_POSTFIELDS=>json_encode($b?:[])]);$raw=curl_exec($ch);curl_close($ch);return json_decode($raw,true)?:['code'=>-2,'message'=>'BADJSON'];}
function q($sql){global $PDO;return $PDO->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
function v($sql){global $PDO;$r=$PDO->query($sql)->fetch(PDO::FETCH_NUM);return $r?$r[0]:null;}
function exe($sql){global $PDO;return $PDO->exec($sql);}
// 注册新用户（直接种验证码，绕过短信）→ [uid, token]
function regUser($phone,$nick){global $PDO,$BASE;
  $PDO->exec("DELETE FROM nft_verification_codes WHERE phone='$phone'");
  $PDO->exec("INSERT INTO nft_verification_codes (phone,scene,code,expires_at,sent_at,ip,created_at) VALUES ('$phone','register','".password_hash('654321',PASSWORD_BCRYPT)."','".date('Y-m-d H:i:s',time()+600)."',NOW(),'127.0.0.1',NOW())");
  $ch=curl_init($BASE.'/api/auth/register');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'code'=>'654321','nickname'=>$nick])]);
  $j=json_decode(curl_exec($ch),true)?:[];curl_close($ch);
  $uid=$j['code']===0?(int)v("SELECT id FROM nft_users WHERE phone='$phone'"):0;
  return [$uid,(string)($j['data']['token']??'')];
}
function seedHolder($uid,$cid,$n=1){global $PDO;$ids=[];
  for($i=0;$i<$n;$i++){
    $s='SN-'.$cid.'-T'.substr((string)microtime(true),-5).$i.rand(100,999);
    $PDO->exec("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at)
      VALUES ($uid,$cid,'$s','purchase',0,NOW(),'held',NOW(),NOW())");
    $ids[]=(int)v("SELECT id FROM nft_user_collectibles WHERE serial='$s'");
  }return $ids;
}
function seedCollectible($id,$name,$extra=''){global $PDO;
  $PDO->exec("DELETE FROM nft_user_collectibles WHERE collectible_id=$id");
  $PDO->exec("DELETE FROM nft_collectibles WHERE id=$id");
  $PDO->exec("INSERT INTO nft_collectibles (id,category_id,name,subtitle,image,price,edition,circulate,sold,locked_quantity,per_user_limit,is_transferable,is_resaleable,resale_price_mode,resale_price_min,resale_price_max,status,issuer,brand,created_at,updated_at $extra)
    VALUES ($id,1,'$name','回归测试','',100,1000,0,0,0,10,1,1,0,0,0,'onsale','司南文创','司南',NOW(),NOW() $extra)");
}

echo "=== R0 数据准备 ===\n";
// 清理历史回归数据（用户/钱包/持仓/挂单/活动）—— 关闭外键检查以应对全链路引用
$oldIds=q("SELECT id FROM nft_users WHERE phone LIKE '13900009%'");
if($oldIds){$oldIds=implode(',',array_column($oldIds,'id'));
  exe("SET FOREIGN_KEY_CHECKS=0");
  foreach(['nft_wallets','nft_wallet_transactions','nft_user_collectibles','nft_swap_offers','nft_swap_records',
           'nft_buy_requests','nft_orders','nft_payments','nft_resale_listings','nft_raffle_registrations',
           'nft_synthesis_records','nft_verification_codes'] as $t){
    exe("DELETE FROM $t WHERE user_id IN ($oldIds)");
  }
  exe("DELETE FROM nft_payments WHERE order_id IN (SELECT id FROM nft_orders WHERE user_id IN ($oldIds))");
  exe("DELETE FROM nft_orders WHERE user_id IN ($oldIds)");
  exe("DELETE FROM nft_resale_listings WHERE seller_id IN ($oldIds)");
  exe("DELETE FROM nft_swap_records WHERE offer_user_id IN ($oldIds) OR accept_user_id IN ($oldIds)");
  exe("DELETE FROM nft_buy_requests WHERE accepted_by IN ($oldIds)");
  exe("DELETE FROM nft_users WHERE id IN ($oldIds)");
  exe("SET FOREIGN_KEY_CHECKS=1");
}
// 用户 A（卖家/发起方）与 B（买家/接单方）
[$uidA,$tokA]=regUser('13900009101','回归A');
[$uidB,$tokB]=regUser('13900009102','回归B');
T('R0.1 用户A/B注册',( $uidA>0 && $uidB>0 ),"uidA=$uidA uidB=$uidB");
// 实名 + 交易密码 + 钱包
$tradeHash=password_hash('Trade#2026',PASSWORD_BCRYPT);
exe("UPDATE nft_users SET is_realname=1, transaction_password='$tradeHash' WHERE id IN ($uidA,$uidB)");
foreach([$uidA,$uidB] as $u){
  exe("DELETE FROM nft_wallets WHERE user_id=$u");
  exe("INSERT INTO nft_wallets (user_id,balance,available,frozen,created_at,updated_at) VALUES ($u,10000,10000,0,NOW(),NOW())");
}
T('R0.2 实名/交易密码/钱包就绪', v("SELECT COUNT(*) FROM nft_wallets WHERE user_id IN ($uidA,$uidB) AND available=10000")==2);

// 藏品 9301~9310
seedCollectible(9301,'K01关寄售'); exe("UPDATE nft_collectibles SET is_resaleable=0 WHERE id=9301");
seedCollectible(9302,'K04固定价'); exe("UPDATE nft_collectibles SET resale_price_mode=1, resale_price_min=500, resale_price_max=500 WHERE id=9302");
seedCollectible(9303,'K04区间价'); exe("UPDATE nft_collectibles SET resale_price_mode=2, resale_price_min=100, resale_price_max=300 WHERE id=9303");
seedCollectible(9304,'K05全局价');
seedCollectible(9305,'置换A');
seedCollectible(9306,'置换B');
seedCollectible(9307,'求购标的');
seedCollectible(9308,'合成材料');
seedCollectible(9309,'合成产物');
seedCollectible(9310,'抽签发售');
// A 持仓：K0x 各 1 件 + 置换A + 求购标的×2 + 材料×2
$ucK01=seedHolder($uidA,9301)[0]; $ucK04=seedHolder($uidA,9302)[0];
$ucK04b=seedHolder($uidA,9303)[0]; $ucK05=seedHolder($uidA,9304)[0];
$ucSwapA=seedHolder($uidA,9305)[0];
$ucBq=seedHolder($uidA,9307,2);
$matIds=seedHolder($uidA,9308,2);
// B 持仓：置换B
$ucSwapB=seedHolder($uidB,9306)[0];
// 全局最高价 = 1000
exe("INSERT INTO nft_system_configs (config_key,config_value,updated_at) VALUES ('resale_price_global_max','1000',NOW())
     ON DUPLICATE KEY UPDATE config_value='1000', updated_at=NOW()");
// 清历史
exe("DELETE FROM nft_swap_offers WHERE offer_user_id IN ($uidA,$uidB)");
exe("DELETE FROM nft_buy_requests WHERE user_id IN ($uidA,$uidB)");
T('R0.3 藏品/持仓/全局价配置就绪', v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id IN ($uidA,$uidB) AND status='held'")>=8);

echo "\n=== K01 寄售开关（is_resaleable=0 拒单）===\n";
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucK01,'price'=>100,'paymentPassword'=>'Trade#2026'],$tokA);
T('K01.1 关闭寄售的藏品挂单被拒', $r['code']!==0 && strpos($r['message'],'关闭寄售')!==false, "code={$r['code']} msg={$r['message']}");
T('K01.2 失败后资产仍为 held', v("SELECT status FROM nft_user_collectibles WHERE id=$ucK01")==='held');
T('K01.3 未产生挂单', v("SELECT COUNT(*) FROM nft_resale_listings WHERE user_collectible_id=$ucK01")==0);

echo "\n=== K04 单品价格管控 ===\n";
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucK04,'price'=>600,'paymentPassword'=>'Trade#2026'],$tokA);
T('K04.1 固定价模式：偏离价被拒', $r['code']!==0 && strpos($r['message'],'固定价')!==false, "code={$r['code']} msg={$r['message']}");
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucK04,'price'=>500,'paymentPassword'=>'Trade#2026'],$tokA);
T('K04.2 固定价模式：一致价成功', $r['code']===0, json_encode($r,JSON_UNESCAPED_UNICODE));
T('K04.3 成功后资产 con-signed 状态', v("SELECT status FROM nft_user_collectibles WHERE id=$ucK04")==='consigned');
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucK04b,'price'=>400,'paymentPassword'=>'Trade#2026'],$tokA);
T('K04.4 区间价模式：超上限被拒', $r['code']!==0 && strpos($r['message'],'限价寄售')!==false, "code={$r['code']} msg={$r['message']}");
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucK04b,'price'=>200,'paymentPassword'=>'Trade#2026'],$tokA);
T('K04.5 区间价模式：区间内成功', $r['code']===0, json_encode($r,JSON_UNESCAPED_UNICODE));

echo "\n=== K05 全局最高价 ===\n";
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucK05,'price'=>1500,'paymentPassword'=>'Trade#2026'],$tokA);
T('K05.1 超全局最高价被拒', $r['code']!==0 && strpos($r['message'],'全局最高价')!==false, "code={$r['code']} msg={$r['message']}");
T('K05.2 失败未产生挂单', v("SELECT COUNT(*) FROM nft_resale_listings WHERE user_collectible_id=$ucK05")==0);
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucK05,'price'=>900,'paymentPassword'=>'Trade#2026'],$tokA);
T('K05.3 低于全局最高价成功', $r['code']===0);
// 管理端实时调整全局价后再验证（模拟管理端改配置）
exe("UPDATE nft_system_configs SET config_value='800' WHERE config_key='resale_price_global_max'");
$ucK05b=seedHolder($uidA,9304)[0];
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucK05b,'price'=>900,'paymentPassword'=>'Trade#2026'],$tokA);
T('K05.4 调低全局价后原价被拒（实时生效）', $r['code']!==0 && strpos($r['message'],'全局最高价')!==false, "code={$r['code']} msg={$r['message']}");
exe("UPDATE nft_system_configs SET config_value='1000' WHERE config_key='resale_price_global_max'");

echo "\n=== S06 置换过户 ===\n";
$r=http('POST','/api/swap-offers',['offerCollectibleId'=>9305,'targetCollectibleId'=>9306,'cashDiff'=>50,'remark'=>'S06回归'],$tokA);
$offerId=$r['code']===0?(int)$r['data']['id']:0;
T('S06.1 A 发布置换单', $offerId>0, json_encode($r,JSON_UNESCAPED_UNICODE));
$balA=v("SELECT available FROM nft_wallets WHERE user_id=$uidA");
$balB=v("SELECT available FROM nft_wallets WHERE user_id=$uidB");
$r=http('POST',"/api/swap-offers/$offerId/accept",[],$tokB);
T('S06.2 B 接受置换成功', $r['code']===0, json_encode($r,JSON_UNESCAPED_UNICODE));
T('S06.3 置换单状态=5(已完成)', v("SELECT status FROM nft_swap_offers WHERE id=$offerId")=='5');
T('S06.4 双向过户：C9305→B', v("SELECT user_id FROM nft_user_collectibles WHERE id=$ucSwapA")==$uidB);
T('S06.5 双向过户：C9306→A', v("SELECT user_id FROM nft_user_collectibles WHERE id=$ucSwapB")==$uidA);
T('S06.6 过户后资产状态 held', v("SELECT COUNT(*) FROM nft_user_collectibles WHERE id IN ($ucSwapA,$ucSwapB) AND status='held'")==2);
T('S06.7 差价结算：A -50', abs(v("SELECT available FROM nft_wallets WHERE user_id=$uidA")-($balA-50))<0.001);
T('S06.8 差价结算：B +50', abs(v("SELECT available FROM nft_wallets WHERE user_id=$uidB")-($balB+50))<0.001);
T('S06.9 swap_records 写入完成记录', v("SELECT COUNT(*) FROM nft_swap_records WHERE offer_id=$offerId AND status=4")==1);
T('S06.10 资金守恒（A减=B增=50）', abs((v("SELECT available FROM nft_wallets WHERE user_id=$uidA")-$balA)+(v("SELECT available FROM nft_wallets WHERE user_id=$uidB")-$balB))<0.001);
// 二次接受拒绝
$r=http('POST',"/api/swap-offers/$offerId/accept",[],$tokB);
T('S06.11 已完成置换不可重复接受', $r['code']!==0);

echo "\n=== B08 求购生成订单 ===\n";
$r=http('POST','/api/buy-requests',['collectibleId'=>9307,'price'=>100,'quantity'=>2,'remark'=>'B08回归'],$tokB);
$bqId=$r['code']===0?(int)$r['data']['id']:0;
T('B08.1 B 发布求购单', $bqId>0, json_encode($r,JSON_UNESCAPED_UNICODE));
$balA=v("SELECT available FROM nft_wallets WHERE user_id=$uidA");
$balB=v("SELECT available FROM nft_wallets WHERE user_id=$uidB");
$r=http('POST',"/api/buy-requests/$bqId/accept",[],$tokA);
T('B08.2 A 接单成功', $r['code']===0, json_encode($r,JSON_UNESCAPED_UNICODE));
T('B08.3 求购单状态=4(已成交)且回写订单号', v("SELECT status FROM nft_buy_requests WHERE id=$bqId")=='4' && v("SELECT order_no FROM nft_buy_requests WHERE id=$bqId")!=='');
$orderNo=v("SELECT order_no FROM nft_buy_requests WHERE id=$bqId");
T('B08.4 订单已生成(completed,total=200,买家B)', v("SELECT COUNT(*) FROM nft_orders WHERE order_no='$orderNo' AND user_id=$uidB AND status='completed' AND total_price=200")==1);
T('B08.5 支付记录已生成', v("SELECT COUNT(*) FROM nft_payments p JOIN nft_orders o ON o.id=p.order_id WHERE o.order_no='$orderNo' AND p.status='success' AND p.amount=200")==1);
$feeRate=(float)v("SELECT config_value FROM nft_system_configs WHERE config_key='resale_fee_rate'");
$fee=round(200*$feeRate/100,2); $net=round(200-$fee,2);
T('B08.6 B 扣款 200（含流水）', abs(v("SELECT available FROM nft_wallets WHERE user_id=$uidB")-($balB-200))<0.001 && v("SELECT COUNT(*) FROM nft_wallet_transactions WHERE user_id=$uidB AND biz_no='$orderNo'")==1);
T('B08.7 A 结算到账 '.($net).'（费率'.$feeRate.'%）', abs(v("SELECT available FROM nft_wallets WHERE user_id=$uidA")-($balA+$net))<0.001);
T('B08.8 两件资产过户给 B', v("SELECT COUNT(*) FROM nft_user_collectibles WHERE id IN (".implode(',',$ucBq).") AND user_id=$uidB AND status='held'")==2);
T('B08.9 资金守恒（B减200=A加+手续费上缴）', abs((v("SELECT available FROM nft_wallets WHERE user_id=$uidB")-$balB)+200)<0.001);

echo "\n=== SY23 合成按 result_quantity 发产物 ===\n";
exe("DELETE FROM nft_synthesis_activities WHERE title='SY23回归'");
exe("DELETE FROM nft_synthesis_materials WHERE activity_id NOT IN (SELECT id FROM nft_synthesis_activities)");
exe("DELETE FROM nft_synthesis_records WHERE user_id=$uidA");
exe("INSERT INTO nft_synthesis_activities (type,title,start_time,end_time,status,rules,result_collectible_id,result_quantity,per_user_limit,total_limit,used_count,created_at,updated_at)
  VALUES ('limit','SY23回归','".date('Y-m-d H:i:s',time()-3600)."','".date('Y-m-d H:i:s',time()+3600)."',1,'回归测试规则',9309,3,1,10,0,NOW(),NOW())");
$synId=(int)v("SELECT id FROM nft_synthesis_activities WHERE title='SY23回归'");
exe("INSERT INTO nft_synthesis_materials (activity_id,collectible_id,count,created_at) VALUES ($synId,9308,2,NOW())");
$circBefore=(int)v("SELECT circulate FROM nft_collectibles WHERE id=9309");
$r=http('POST','/api/synthesis/submit',['activityId'=>$synId],$tokA);
T('SY23.1 提交合成成功', $r['code']===0, json_encode($r,JSON_UNESCAPED_UNICODE));
$got=(int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=$uidA AND collectible_id=9309 AND status='held' AND source='synthesis'");
T('SY23.2 产物发放 3 份（result_quantity=3）', $got===3, "实际 $got 份");
T('SY23.3 材料消耗 2 份（consumed）', v("SELECT COUNT(*) FROM nft_user_collectibles WHERE id IN (".implode(',',$matIds).") AND status='consumed'")==2);
T('SY23.4 产物 circulate +3', ((int)v("SELECT circulate FROM nft_collectibles WHERE id=9309")-$circBefore)===3);
T('SY23.5 合成记录写入', v("SELECT COUNT(*) FROM nft_synthesis_records WHERE activity_id=$synId AND user_id=$uidA")==1);
$r=http('POST','/api/synthesis/submit',['activityId'=>$synId],$tokA);
T('SY23.6 每人限次 1 拦截二次合成', $r['code']!==0);

echo "\n=== RF03 抽签购 C 端入口 ===\n";
// 造活动：报名中，收费 10 元/票，限 2 票，中签 1 人可购 2 件 @99
exe("DELETE FROM nft_raffle_activities WHERE name='RF03回归'");
exe("DELETE FROM nft_raffle_registrations WHERE activity_id NOT IN (SELECT id FROM nft_raffle_activities)");
exe("INSERT INTO nft_raffle_activities (collectible_id,name,description,ticket_price,limit_per_user,winner_count,sale_quantity,sale_price,
  registration_start,registration_end,draw_time,purchase_start,purchase_end,status,created_at,updated_at)
  VALUES (9310,'RF03回归','回归',10,2,2,2,99,'".date('Y-m-d H:i:s',time()-3600)."','".date('Y-m-d H:i:s',time()+3600)."','".date('Y-m-d H:i:s')."','".date('Y-m-d H:i:s',time()-3600)."','".date('Y-m-d H:i:s',time()+3600)."',1,NOW(),NOW())");
$raffleId=(int)v("SELECT id FROM nft_raffle_activities WHERE name='RF03回归'");
$balA=v("SELECT available FROM nft_wallets WHERE user_id=$uidA");
$r=http('GET','/api/raffle/activities',null,null);
$found=false;
foreach(($r['data']['list']??[]) as $it){ if((int)$it['activityId']===$raffleId){$found=true;break;} }
T('RF03.1 活动列表可见', $found, "code={$r['code']}");
$r=http('GET',"/api/raffle/activities/$raffleId",null,$tokA);
T('RF03.2 活动详情可查', $r['code']===0 && (int)($r['data']['saleQuantity']??0)===2, json_encode($r,JSON_UNESCAPED_UNICODE));
$r=http('POST',"/api/raffle/activities/$raffleId/register",['ticketCount'=>2],$tokA);
T('RF03.3 报名 2 票成功（收费）', $r['code']===0 && abs(($r['data']['payAmount']??0)-20)<0.001, json_encode($r,JSON_UNESCAPED_UNICODE));
T('RF03.4 报名费扣款 20 元', abs(v("SELECT available FROM nft_wallets WHERE user_id=$uidA")-($balA-20))<0.001);
T('RF03.5 报名流水（title=抽签报名费）', v("SELECT COUNT(*) FROM nft_wallet_transactions WHERE user_id=$uidA AND biz_no='RAFFLE-$raffleId'")==1);
T('RF03.6 pay_status=1（已支付）', v("SELECT pay_status FROM nft_raffle_registrations WHERE activity_id=$raffleId AND user_id=$uidA")=='1');
$r=http('POST',"/api/raffle/activities/$raffleId/register",['ticketCount'=>1],$tokA);
T('RF03.7 超限报被拒（limit=2）', $r['code']!==0);
// 未报名用户购买被拒
$r=http('POST',"/api/raffle/activities/$raffleId/purchase",['quantity'=>1],$tokB);
T('RF03.8 未报名者无法购买', $r['code']!==0);
// 模拟抽签：A 中签，活动→已抽签
exe("UPDATE nft_raffle_registrations SET draw_status=1 WHERE activity_id=$raffleId AND user_id=$uidA");
exe("UPDATE nft_raffle_activities SET status=3 WHERE id=$raffleId");
$balA=v("SELECT available FROM nft_wallets WHERE user_id=$uidA");
$soldBefore=(int)v("SELECT sold FROM nft_collectibles WHERE id=9310");
$r=http('POST',"/api/raffle/activities/$raffleId/purchase",['quantity'=>2],$tokA);
T('RF03.9 中签购买 2 件成功', $r['code']===0, json_encode($r,JSON_UNESCAPED_UNICODE));
T('RF03.10 扣款 198 元（99×2）', abs(v("SELECT available FROM nft_wallets WHERE user_id=$uidA")-($balA-198))<0.001);
T('RF03.11 订单生成（release 来源）', v("SELECT COUNT(*) FROM nft_orders WHERE user_id=$uidA AND collectible_id=9310 AND status='completed' AND source='release'")==1);
T('RF03.12 持仓 2 件（serial 唯一）', v("SELECT COUNT(DISTINCT serial) FROM nft_user_collectibles WHERE user_id=$uidA AND collectible_id=9310")==2);
T('RF03.13 库存 sold +2', ((int)v("SELECT sold FROM nft_collectibles WHERE id=9310")-$soldBefore)===2);
$r=http('POST',"/api/raffle/activities/$raffleId/purchase",['quantity'=>1],$tokA);
T('RF03.14 超限购被拒（3003）', $r['code']!==0, "code={$r['code']} msg={$r['message']}");
$r=http('GET','/api/raffle/registrations/mine',null,$tokA);
$mineOk=false;
foreach(($r['data']['list']??[]) as $it){ if((int)$it['activityId']===$raffleId && (int)$it['purchasedQuantity']===2){$mineOk=true;break;} }
T('RF03.15 我的报名记录（含已购数量）', $mineOk, json_encode($r,JSON_UNESCAPED_UNICODE));

echo "\n=== 汇总 ===\n";
echo "PASS=$pass FAIL=$fail\n";
if($fail>0){echo "存在未通过项！\n";exit(1);}
echo "7 项修复回归全部通过。\n";
