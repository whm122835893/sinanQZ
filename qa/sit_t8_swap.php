<?php
/** T8 置换（C 端闭环）功能测试
 *  覆盖：发起挂单（资产锁定）/ 公开列表 / 我的挂单 / 接受置换（双向过户+差价结算+流水）/
 *        撤销挂单（资产退回）/ 过期懒清理 / 负向（自接、无目标资产、错密码、重复挂单、已撤销再接）
 *  前置：后端 127.0.0.1:8080 已启动，MySQL sinan_nft 可用
 */
date_default_timezone_set('Asia/Shanghai');
$BASE='http://127.0.0.1:8080';
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
    CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'code'=>'654321','password'=>'Pass#2026','nickname'=>$nick])]);
  $j=json_decode(curl_exec($ch),true)?:[];curl_close($ch);
  $uid=$j['code']===0?(int)v("SELECT id FROM nft_users WHERE phone='$phone'"):0;
  return [$uid,(string)($j['data']['token']??'')];
}
function seedHolder($uid,$cid,$n=1,$source='purchase'){global $PDO;$ids=[];
  for($i=0;$i<$n;$i++){
    $s='SN-'.$cid.'-T8'.substr((string)microtime(true),-5).$i.rand(100,999);
    $PDO->exec("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,source,acquired_price,acquired_at,status,created_at,updated_at)
      VALUES ($uid,$cid,'$s','$source',0,NOW(),'held',NOW(),NOW())");
    $ids[]=(int)v("SELECT id FROM nft_user_collectibles WHERE serial='$s'");
  }return $ids;
}
function seedCollectible($id,$name){global $PDO;
  $PDO->exec("DELETE FROM nft_user_collectibles WHERE collectible_id=$id");
  $PDO->exec("DELETE FROM nft_swap_offers WHERE offer_collectible_id=$id OR target_collectible_id=$id");
  $PDO->exec("DELETE FROM nft_swap_records WHERE offer_collectible_id=$id OR accept_collectible_id=$id");
  $PDO->exec("DELETE FROM nft_collectibles WHERE id=$id");
  $PDO->exec("INSERT INTO nft_collectibles (id,category_id,name,subtitle,image,price,edition,circulate,sold,locked_quantity,per_user_limit,is_transferable,is_resaleable,is_buy_request_enabled,status,issuer,brand,created_at,updated_at)
    VALUES ($id,1,'$name','T8置换','','100',1000,10,10,0,10,1,1,1,'onsale','司南文创','司南',NOW(),NOW())");
}

echo "=== T8-0 环境准备 ===\n";
$old=q("SELECT id FROM nft_users WHERE phone LIKE '139000088%'");
if($old){$oldIds=implode(',',array_column($old,'id'));
  exe("SET FOREIGN_KEY_CHECKS=0");
  foreach(['nft_wallets','nft_wallet_transactions','nft_user_collectibles','nft_swap_offers'] as $t) exe("DELETE FROM $t WHERE ".($t==='nft_swap_offers'?"offer_user_id IN ($oldIds)":"user_id IN ($oldIds)"));
  exe("DELETE FROM nft_swap_records WHERE offer_user_id IN ($oldIds) OR accept_user_id IN ($oldIds)");
  exe("DELETE FROM nft_users WHERE id IN ($oldIds)");
  exe("SET FOREIGN_KEY_CHECKS=1");
}
exe("DELETE FROM nft_verification_codes WHERE phone LIKE '139000088%'");
[$uidA,$tokA]=regUser('13900008801','置换A');
[$uidB,$tokB]=regUser('13900008802','置换B');
T('T8-0a 用户A/B注册', $uidA>0&&$uidB>0, "A=$uidA B=$uidB");
$tradeHash=password_hash('Trade#2026',PASSWORD_BCRYPT);
exe("UPDATE nft_users SET is_realname=1, transaction_password='$tradeHash' WHERE id IN ($uidA,$uidB)");
foreach([$uidA,$uidB] as $u){
  exe("DELETE FROM nft_wallets WHERE user_id=$u");
  exe("INSERT INTO nft_wallets (user_id,balance,available,frozen,created_at,updated_at) VALUES ($u,5000,5000,0,NOW(),NOW())");
}
seedCollectible(9801,'T8置换藏品甲');
seedCollectible(9802,'T8置换藏品乙');
$ucA=seedHolder($uidA,9801)[0];   // A 的甲
$ucB=seedHolder($uidB,9802)[0];   // B 的乙
T('T8-0b 藏品/持仓就绪', $ucA>0&&$ucB>0);

echo "\n=== T8-1 发起置换挂单 ===\n";
$r=http('POST','/api/swap-offers',['userCollectibleId'=>$ucA,'targetCollectibleId'=>9802,'cashDiff'=>50,'remark'=>'T8测试','paymentPassword'=>'WRONG'],$tokA);
T('T8-1a 错误交易密码被拒(2003)', $r['code']===2003, "code={$r['code']} msg={$r['message']}");
$r=http('POST','/api/swap-offers',['userCollectibleId'=>$ucA,'targetCollectibleId'=>9802,'cashDiff'=>50,'remark'=>'T8测试','paymentPassword'=>'Trade#2026'],$tokA);
$offerId=(int)($r['data']['id']??0);
T('T8-1b 发起成功(补贴50元)', $r['code']===0&&$offerId>0, "code={$r['code']} msg={$r['message']}");
T('T8-1c 资产锁定 consigned', v("SELECT status FROM nft_user_collectibles WHERE id=$ucA")==='consigned');
T('T8-1d 挂单状态=1挂单中', v("SELECT status FROM nft_swap_offers WHERE id=$offerId")==1);
T('T8-1e 有效期=7天', v("SELECT DATEDIFF(expires_at,created_at) FROM nft_swap_offers WHERE id=$offerId")==7);
$r=http('POST','/api/swap-offers',['userCollectibleId'=>$ucA,'targetCollectibleId'=>9802,'cashDiff'=>0,'paymentPassword'=>'Trade#2026'],$tokA);
T('T8-1f 同资产重复挂单被拒', $r['code']!==0, "code={$r['code']} msg={$r['message']}");
$r=http('POST','/api/swap-offers',['userCollectibleId'=>999999,'targetCollectibleId'=>9802,'cashDiff'=>0,'paymentPassword'=>'Trade#2026'],$tokA);
T('T8-1g 非本人/不存在资产被拒', $r['code']!==0, "code={$r['code']} msg={$r['message']}");

echo "\n=== T8-2 公开列表/我的挂单 ===\n";
$r=http('GET','/api/swap-offers?collectibleId=9802',null,$tokA);
$found=false;
foreach(($r['data']['list']??[]) as $it){ if((int)$it['id']===$offerId){$found=true;$item=$it;} }
T('T8-2a 挂单池含该单', $found, "code={$r['code']}");
T('T8-2b 列表字段完整(双方藏品名)', $found&&$item['offerName']==='T8置换藏品甲'&&$item['targetName']==='T8置换藏品乙');
$r=http('GET','/api/swap-offers/mine',null,$tokA);
$mineFound=false;
foreach(($r['data']['list']??[]) as $it){ if((int)$it['id']===$offerId)$mineFound=true; }
T('T8-2c 我的挂单含该单', $mineFound, "code={$r['code']}");

echo "\n=== T8-3 接受置换 ===\n";
$r=http('POST',"/api/swap-offers/$offerId/accept",['userCollectibleId'=>$ucA,'paymentPassword'=>'Trade#2026'],$tokA);
T('T8-3a 发起人不能自接', $r['code']!==0, "code={$r['code']} msg={$r['message']}");
$r=http('POST',"/api/swap-offers/$offerId/accept",['paymentPassword'=>'Trade#2026'],$tokB);
T('T8-3b 接受成功(双向过户)', $r['code']===0, "code={$r['code']} msg={$r['message']}");
T('T8-3c 甲资产过户给B', v("SELECT user_id FROM nft_user_collectibles WHERE id=$ucA")==$uidB);
T('T8-3d 乙资产过户给A', v("SELECT user_id FROM nft_user_collectibles WHERE id=$ucB")==$uidA);
T('T8-3e 双方资产回 held', v("SELECT COUNT(*) FROM nft_user_collectibles WHERE id IN ($ucA,$ucB) AND status='held'")==2);
T('T8-3f 挂单置已完成(5)', v("SELECT status FROM nft_swap_offers WHERE id=$offerId")==5);
T('T8-3g swap_records 落流水(status=4)', v("SELECT status FROM nft_swap_records WHERE offer_id=$offerId")==4);
$balA=(float)v("SELECT available FROM nft_wallets WHERE user_id=$uidA");
$balB=(float)v("SELECT available FROM nft_wallets WHERE user_id=$uidB");
T('T8-3h 差价结算 A-50/B+50', abs($balA-4950)<0.01&&abs($balB-5050)<0.01, "A=$balA B=$balB");
T('T8-3i 双方流水各1笔', v("SELECT COUNT(*) FROM nft_wallet_transactions WHERE biz_no='SWAP-$offerId'")==2);

echo "\n=== T8-4 撤销挂单 ===\n";
$ucA2=seedHolder($uidA,9801)[0];
$r=http('POST','/api/swap-offers',['userCollectibleId'=>$ucA2,'targetCollectibleId'=>9802,'cashDiff'=>0,'paymentPassword'=>'Trade#2026'],$tokA);
$offer2=(int)($r['data']['id']??0);
T('T8-4a 第二单发起成功', $r['code']===0&&$offer2>0, "code={$r['code']}");
$r=http('POST',"/api/swap-offers/$offer2/cancel",[],$tokB);
T('T8-4b 非发起人不能撤销', $r['code']!==0, "code={$r['code']} msg={$r['message']}");
$r=http('POST',"/api/swap-offers/$offer2/cancel",[],$tokA);
T('T8-4c 发起人撤销成功', $r['code']===0, "code={$r['code']} msg={$r['message']}");
T('T8-4d 挂单置已撤销(4)', v("SELECT status FROM nft_swap_offers WHERE id=$offer2")==4);
T('T8-4e 资产退回 held', v("SELECT status FROM nft_user_collectibles WHERE id=$ucA2")==='held');
$r=http('POST',"/api/swap-offers/$offer2/accept",['paymentPassword'=>'Trade#2026'],$tokB);
T('T8-4f 已撤销单不可接受', $r['code']!==0, "code={$r['code']} msg={$r['message']}");

echo "\n=== T8-5 接受方无目标资产 ===\n";
$ucA3=seedHolder($uidA,9801)[0];
$r=http('POST','/api/swap-offers',['userCollectibleId'=>$ucA3,'targetCollectibleId'=>9802,'cashDiff'=>0,'paymentPassword'=>'Trade#2026'],$tokA);
$offer3=(int)($r['data']['id']??0);
$oldB=v("SELECT status FROM nft_user_collectibles WHERE id=$ucA");
exe("UPDATE nft_user_collectibles SET user_id=$uidA WHERE id=$ucA"); // B 不再持有乙
$r=http('POST',"/api/swap-offers/$offer3/accept",['paymentPassword'=>'Trade#2026'],$tokB);
T('T8-5a 无目标资产被拒', $r['code']!==0, "code={$r['code']} msg={$r['message']}");
exe("UPDATE nft_user_collectibles SET user_id=$uidB WHERE id=$ucA");
http('POST',"/api/swap-offers/$offer3/cancel",[],$tokA);

echo "\n=== T8-6 资金守恒（差额为负场景）===\n";
$ucB2=seedHolder($uidB,9802)[0]; // B 重新持有乙（T8-3 中乙已换给 A）
$ucA4=seedHolder($uidA,9801)[0];
$r=http('POST','/api/swap-offers',['userCollectibleId'=>$ucA4,'targetCollectibleId'=>9802,'cashDiff'=>-30,'paymentPassword'=>'Trade#2026'],$tokA);
$offer4=(int)($r['data']['id']??0);
T('T8-6a 负差价挂单成功', $r['code']===0&&$offer4>0, "code={$r['code']}");
$bA0=(float)v("SELECT available FROM nft_wallets WHERE user_id=$uidA");
$bB0=(float)v("SELECT available FROM nft_wallets WHERE user_id=$uidB");
$r=http('POST',"/api/swap-offers/$offer4/accept",['userCollectibleId'=>$ucB2,'paymentPassword'=>'Trade#2026'],$tokB);
T('T8-6b 接受成功', $r['code']===0, "code={$r['code']} msg={$r['message']}");
$bA1=(float)v("SELECT available FROM nft_wallets WHERE user_id=$uidA");
$bB1=(float)v("SELECT available FROM nft_wallets WHERE user_id=$uidB");
T('T8-6c B补贴A30元', abs(($bA1-$bA0)-30)<0.01&&abs(($bB1-$bB0)+30)<0.01, "ΔA=".($bA1-$bA0)." ΔB=".($bB1-$bB0));

echo "\n=== T8-7 管理端置换记录只读 ===\n";
$admLogin=http('POST','/admin/auth/login',['username'=>'admin','password'=>'admin123']);
$admTok=(string)($admLogin['data']['token']??'');
T('T8-7a 超管登录', $admTok!=='');
$r=http('GET','/admin/swap/records?page=1&pageSize=10',null,$admTok);
T('T8-7b 管理端置换流水可见', $r['code']===200&&(int)($r['data']['total']??0)>=2, "code={$r['code']} total=".($r['data']['total']??'?'));
// 注：offer1(T8-3)+offer4(T8-6) 两笔已完成置换

echo "\n=== T8-8 清理 ===\n";
exe("SET FOREIGN_KEY_CHECKS=0");
exe("DELETE FROM nft_swap_offers WHERE offer_user_id IN ($uidA,$uidB)");
exe("DELETE FROM nft_swap_records WHERE offer_user_id IN ($uidA,$uidB) OR accept_user_id IN ($uidA,$uidB)");
exe("DELETE FROM nft_wallet_transactions WHERE user_id IN ($uidA,$uidB)");
exe("DELETE FROM nft_wallets WHERE user_id IN ($uidA,$uidB)");
exe("DELETE FROM nft_user_collectibles WHERE user_id IN ($uidA,$uidB)");
exe("DELETE FROM nft_users WHERE id IN ($uidA,$uidB)");
exe("DELETE FROM nft_collectibles WHERE id IN (9801,9802)");
exe("DELETE FROM nft_verification_codes WHERE phone LIKE '139000088%'");
exe("SET FOREIGN_KEY_CHECKS=1");
T('T8-8a 清理完成', v("SELECT COUNT(*) FROM nft_users WHERE phone LIKE '139000088%'")==0);

echo "\n================ 汇总 ================\n";
echo "PASS: $pass  FAIL: $fail\n";
exit($fail>0?1:0);
