<?php
/** 模块6：营销活动（优先购/资格购/邀请/注册/签到/抽奖/奖励名单）集成测试
 *  注：QF-D1（qualification_whitelists 无 status 列导致 500）已在测试前修复，本脚本含回归验证
 */
date_default_timezone_set('Asia/Shanghai');
$BASE='http://127.0.0.1:8301';
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING]);
// 确保冒烟用户 id=1/2/3 实名 + 交易密码，签到用户 id=4/6/7/8 就绪
$pwdHash='$2y$12$MOtq8as9FfrvOSoK1LOjCusHuC9Y8Qc7ydjTyaZUIkBpfcTrX81fW'; // password_hash('Trade#2026', PASSWORD_BCRYPT)
$PDO->exec("UPDATE nft_users SET is_realname=1, transaction_password='$pwdHash' WHERE id IN (1,2,3)");
// 给冒烟用户补钱包（如果没有）
foreach([1,2,3] as $uid){
  $cnt=(int)$PDO->query("SELECT COUNT(*) FROM nft_wallets WHERE user_id=$uid")->fetchColumn();
  if($cnt===0){
    $PDO->exec("INSERT INTO nft_wallets (user_id,balance,frozen,total_recharge,total_withdraw,created_at,updated_at) VALUES ($uid,1000,0,1000,0,NOW(),NOW())");
  }
}
// 登录拿 token（用 seed 冒烟用户的 phone=13900000001~3）
$tok=[];
$map=['1'=>'13900000001','2'=>'13900000002','3'=>'13900000003','4'=>'13800000004','6'=>'13842453421','7'=>'13878445723','8'=>'13841161376'];
foreach($map as $uid=>$phone){
  $PDO->exec("DELETE FROM nft_verification_codes WHERE phone='$phone'");
  $PDO->exec("INSERT INTO nft_verification_codes (phone,scene,code,expires_at,sent_at,ip,created_at) VALUES ('$phone','login','".password_hash('654321',PASSWORD_BCRYPT)."','".date('Y-m-d H:i:s',time()+600)."',NOW(),'127.0.0.1',NOW())");
  $ch=curl_init($BASE.'/api/auth/login');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'code'=>'654321'])]);
  $j=json_decode(curl_exec($ch),true)?:[];curl_close($ch);
  $tok[$uid]=(string)($j['data']['token']??'');
}
// 管理端登录
$ch=curl_init($BASE.'/admin/auth/login');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
  CURLOPT_POSTFIELDS=>json_encode(['username'=>'admin','password'=>'admin123'])]);
$ar=json_decode(curl_exec($ch),true)?:[];curl_close($ch);
$atok=(string)($ar['data']['token']??'');
$pass=0;$fail=0;$defects=[];
function T($n,$c,$d=''){global $pass,$fail;$c?$pass++:$fail++;echo($c?"  PASS ":"  FAIL ").$n.($d?" | $d":"")."\n";}
function D($n,$c,$d=''){global $fail,$defects;if($c){$fail++;$defects[]=$n;$d and $n.=" | $d";echo "  DEFECT $n\n";}else echo "  PASS(无缺陷) $n\n";}
function http($m,$u,$b=null,$t=null){global $BASE;$ch=curl_init($BASE.$u);$h=['Content-Type: application/json'];if($t)$h[]="Authorization: Bearer $t";
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_HTTPHEADER=>$h,CURLOPT_POSTFIELDS=>json_encode($b?:[])]);$raw=curl_exec($ch);curl_close($ch);return json_decode($raw,true)?:['code'=>-2,'message'=>'BADJSON'];}
function httpraw($m,$u,$b=null,$t=null){global $BASE;$ch=curl_init($BASE.$u);$h=['Content-Type: application/json'];if($t)$h[]="Authorization: Bearer $t";
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>$h,CURLOPT_POSTFIELDS=>json_encode($b?:[])]);$raw=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return [$code,$raw];}
function q($sql){global $PDO;return $PDO->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
function v($sql){global $PDO;$r=$PDO->query($sql)->fetch(PDO::FETCH_NUM);return $r?$r[0]:null;}
function exe($sql){global $PDO;return $PDO->exec($sql);}
// 注册新用户（直接种验证码，绕过短信）→ [uid, token, resp]
function regUser($phone,$nick,$inviteCode){global $PDO,$BASE;
  $PDO->exec("DELETE FROM nft_verification_codes WHERE phone='$phone'");
  $PDO->exec("INSERT INTO nft_verification_codes (phone,scene,code,expires_at,sent_at,ip,created_at) VALUES ('$phone','register','".password_hash('654321',PASSWORD_BCRYPT)."','".date('Y-m-d H:i:s',time()+600)."',NOW(),'127.0.0.1',NOW())");
  $ch=curl_init($BASE.'/api/auth/register');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'code'=>'654321','nickname'=>$nick,'inviteCode'=>$inviteCode])]);
  $j=json_decode(curl_exec($ch),true)?:[];curl_close($ch);
  $uid=$j['code']===0?(int)v("SELECT id FROM nft_users WHERE phone='$phone'"):0;
  return [$uid,(string)($j['data']['token']??''),$j];
}

// ================= 6.0 造数 =================
echo "=== 6.0 测试数据准备 ===\n";
exe("DELETE FROM nft_priority_activities WHERE name LIKE 'SIT%'");
exe("DELETE FROM nft_priority_whitelists WHERE activity_id NOT IN (SELECT id FROM nft_priority_activities)");
exe("DELETE FROM nft_priority_sales WHERE name LIKE 'SIT%'");
exe("DELETE FROM nft_qualification_configs WHERE collectible_id>=9012");
exe("DELETE FROM nft_register_activities WHERE name LIKE 'SIT%'");
exe("DELETE FROM nft_invite_activities WHERE name LIKE 'SIT%'");
exe("DELETE FROM nft_lucky_draw_activities WHERE name LIKE 'SIT%'");
// 先清引用 SIT 奖品的抽奖记录（fk_ld_prize ON DELETE RESTRICT），再清奖品
exe("DELETE FROM nft_lucky_draw_records WHERE prize_id IN (SELECT id FROM nft_lucky_draw_prizes WHERE activity_id NOT IN (SELECT id FROM nft_lucky_draw_activities))");
exe("DELETE FROM nft_lucky_draw_prizes WHERE activity_id NOT IN (SELECT id FROM nft_lucky_draw_activities)");
exe("DELETE FROM nft_check_in_records WHERE user_id IN (SELECT id FROM nft_users WHERE username LIKE 'SIT%')");
exe("DELETE FROM nft_check_in_records WHERE user_id=3"); // TC-QF01 幂等：清 user3 历史签到，保证"无资格"前置成立
exe("DELETE FROM nft_activity_reward_records WHERE activity_type='checkin' AND user_id IN (6,7,8)"); // 签到档位防重键(dedupe_key)清理，保证重跑可再发放
$r=http('POST','/admin/collectibles',['name'=>'SIT优先购A','category_id'=>1,'image'=>'/uploads/t.png','price'=>10,'edition'=>100,'per_user_limit'=>10,'is_transferable'=>1,'is_resaleable'=>1,'description'=>'SIT优先购'],$atok);
$prCid=$r['data']['id']??0; T('TC-MK00a 建优先购藏品', $prCid>0, json_encode($r,JSON_UNESCAPED_UNICODE));
$r=http('POST',"/admin/collectibles/$prCid/release",['onsale_at'=>date('Y-m-d H:i:s',time()+3600)],$atok);
T('TC-MK00b 优先购藏品上架(未来开售)', ($r['code']??0)===200, "code={$r['code']} msg={$r['message']}");
$r=http('POST','/admin/collectibles',['name'=>'SIT资格购B','category_id'=>1,'image'=>'/uploads/t.png','price'=>10,'edition'=>100,'per_user_limit'=>10,'is_transferable'=>1,'is_resaleable'=>1,'description'=>'SIT资格购'],$atok);
$qfCid=$r['data']['id']??0; T('TC-MK00c 建资格购藏品', $qfCid>0);
$r=http('POST',"/admin/collectibles/$qfCid/release",['onsale_at'=>date('Y-m-d H:i:s',time()-60)],$atok);
T('TC-MK00d 资格购藏品上架', ($r['code']??0)===200);
$r=http('POST','/admin/collectibles',['name'=>'SIT抽奖C','category_id'=>1,'image'=>'/uploads/t.png','price'=>10,'edition'=>500,'per_user_limit'=>99,'is_transferable'=>1,'is_resaleable'=>1,'description'=>'SIT抽奖奖品'],$atok);
$ldPrizeCid=$r['data']['id']??0; T('TC-MK00e 建抽奖奖品藏品', $ldPrizeCid>0);
$r=http('POST',"/admin/collectibles/$ldPrizeCid/release",['onsale_at'=>date('Y-m-d H:i:s',time()-60)],$atok);
T('TC-MK00f 抽奖奖品藏品上架', ($r['code']??0)===200);

// ================= 6.1 优先购 =================
echo "\n=== 6.1 优先购 ===\n";
$r=http('POST','/admin/marketing/priority',['collectible_id'=>$prCid,'name'=>'SIT优先购','status'=>'xxx'],$atok);
T('TC-PR01a 非法status被拒', ($r['code']??0)===4220, "code={$r['code']}");
$r=http('POST','/admin/marketing/priority',['collectible_id'=>99999,'name'=>'SIT优先购','status'=>'enabled'],$atok);
T('TC-PR01b 藏品不存在被拒', ($r['code']??0)===4040, "code={$r['code']}");
// 管理端配置优先购活动 + 白名单user2（实名，可下单）
$r=http('POST','/admin/marketing/priority',['collectible_id'=>$prCid,'name'=>'SIT优先购','status'=>'enabled','whitelist'=>[2],'whitelist_max_quantity'=>2],$atok);
$paId=$r['data']['id']??0; T('TC-PR02 管理端建优先购活动+白名单', ($r['code']??0)===200&&$paId>0, json_encode($r,JSON_UNESCAPED_UNICODE));
$wlCnt=(int)v("SELECT COUNT(*) FROM nft_priority_whitelists WHERE activity_id=$paId AND user_id=2 AND max_quantity=2");
T('TC-PR02b 白名单落库', $wlCnt===1, "cnt=$wlCnt");
$r=http('POST','/admin/marketing/priority',['collectible_id'=>$prCid,'name'=>'SIT优先购2','status'=>'enabled'],$atok);
T('TC-PR02c 一物一活动约束', ($r['code']??0)===4220, "code={$r['code']}");
// MK-D1（已修复）：管理端保存时同步镜像 priority_sales/priority_sale_whitelists，C端直接生效
$r=http('GET',"/api/collections/$prCid",null,$tok['2']);
$pq=$r['data']['myPriorityQualification']??'NOTSET';
T('TC-PR03a C端详情返回优先购字段', ($r['code']??0)===0&&$pq!=='NOTSET', "code={$r['code']} pq=".json_encode($pq,JSON_UNESCAPED_UNICODE));
D('MK-D1 管理端优先购白名单C端不生效(双轨断链)', $pq===null||$pq==='NOTSET',
  "管理端priority_activities#{$paId}已配置user2白名单(max=2,status=enabled)，但C端myPriorityQualification未返回 —— 双轨同步(syncPrioritySale)未生效");
$r=http('POST','/api/orders',['collectibleId'=>$prCid,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tok['2']);
$used2=(int)v("SELECT used_quantity FROM nft_priority_sale_whitelists WHERE priority_sale_id=(SELECT id FROM nft_priority_sales WHERE collectible_id=$prCid) AND user_id=2");
T('TC-PR03b 白名单用户公售前优先购下单成功', ($r['code']??0)===0, "code={$r['code']} msg={$r['message']}");
T('TC-PR03c 同步白名单used_quantity+1', $used2===1, "used=$used2");
// 奖励发放通道：在同一 sale 行上叠加白名单（RewardGrantService::grantPriorityQualification 模拟）
$psId=(int)v("SELECT id FROM nft_priority_sales WHERE collectible_id=$prCid ORDER BY id DESC LIMIT 1");
exe("INSERT INTO nft_priority_sale_whitelists (priority_sale_id,user_id,phone,max_quantity,used_quantity,expires_at,status) VALUES ($psId,1,'13800000001',2,0,'".date('Y-m-d H:i:s',time()+86400)."',1)");
$r=http('GET',"/api/collections/$prCid",null,$tok['1']);
$pq=$r['data']['myPriorityQualification']??null;
T('TC-PR04 详情展示优先购资格', $pq&&($pq['remaining']??0)===2&&($pq['saleId']??0)===$psId, json_encode($pq,JSON_UNESCAPED_UNICODE));
$r=http('POST','/api/orders',['collectibleId'=>$prCid,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tok['1']);
$used=(int)v("SELECT used_quantity FROM nft_priority_sale_whitelists WHERE priority_sale_id=$psId AND user_id=1");
$osrc=v("SELECT source FROM nft_orders WHERE user_id=1 AND collectible_id=$prCid ORDER BY id DESC LIMIT 1");
T('TC-PR05 公售前优先购下单成功', ($r['code']??0)===0, "code={$r['code']} msg={$r['message']}");
T('TC-PR05b 订单source=priority', $osrc==='priority', "source=$osrc");
T('TC-PR05c used_quantity+1', $used===1, "used=$used");
$r=http('POST','/api/orders',['collectibleId'=>$prCid,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tok['1']);
$used=(int)v("SELECT used_quantity FROM nft_priority_sale_whitelists WHERE priority_sale_id=$psId AND user_id=1");
T('TC-PR06 第二次优先购used=2', ($r['code']??0)===0&&$used===2, "code={$r['code']} used=$used");
$r=http('POST','/api/orders',['collectibleId'=>$prCid,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tok['1']);
T('TC-PR07 资格耗尽回落公售拦截', ($r['code']??0)===1001, "code={$r['code']} msg={$r['message']}");
exe("UPDATE nft_priority_sale_whitelists SET used_quantity=0 WHERE priority_sale_id=$psId AND user_id=1");
$r=http('POST','/api/orders',['collectibleId'=>$prCid,'quantity'=>3,'paymentPassword'=>'Trade#2026'],$tok['1']);
T('TC-PR08 单次超过max被拒3004', ($r['code']??0)===3004, "code={$r['code']} msg={$r['message']}");
exe("UPDATE nft_priority_sale_whitelists SET used_quantity=2 WHERE priority_sale_id=$psId AND user_id=1");
// 过期资格：user2（TC-PR02 白名单经 syncPrioritySale 已镜像出该行，改为置过期）
exe("UPDATE nft_priority_sale_whitelists SET expires_at='".date('Y-m-d H:i:s',time()-60)."' WHERE priority_sale_id=$psId AND user_id=2");
$r=http('GET',"/api/collections/$prCid",null,$tok['2']);
$pq2=$r['data']['myPriorityQualification']??null;
$r2=http('POST','/api/orders',['collectibleId'=>$prCid,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tok['2']);
T('TC-PR09 过期资格不生效', $pq2===null&&($r2['code']??0)===1001, "pq=".json_encode($pq2)." orderCode={$r2['code']}");
// 活动窗口结束：user3
exe("INSERT INTO nft_priority_sale_whitelists (priority_sale_id,user_id,phone,max_quantity,used_quantity,expires_at,status) VALUES ($psId,3,'13800000003',2,0,'".date('Y-m-d H:i:s',time()+86400)."',1)");
exe("UPDATE nft_priority_sales SET end_time='".date('Y-m-d H:i:s',time()-60)."' WHERE id=$psId");
$r=http('GET',"/api/collections/$prCid",null,$tok['3']);
$pq3=$r['data']['myPriorityQualification']??null;
T('TC-PR10 活动结束资格失效', $pq3===null, "pq=".json_encode($pq3));
exe("UPDATE nft_priority_sales SET end_time='".date('Y-m-d H:i:s',time()+86400)."' WHERE id=$psId");
// 管理端白名单管理 API（priority_whitelists 体系自身功能）
$r=http('POST','/admin/marketing/priority-whitelist',['activity_id'=>$paId,'phone'=>'13800000005','max_quantity'=>1],$atok);
T('TC-PR11a 白名单单添加', ($r['code']??0)===200);
$r=http('POST','/admin/marketing/priority-whitelist',['activity_id'=>$paId,'phone'=>'13800000005'],$atok);
T('TC-PR11b 白名单幂等去重', ($r['code']??0)===4220, "code={$r['code']}");
$r=http('POST','/admin/marketing/priority-whitelist',['activity_id'=>$paId,'phone'=>'13999999999'],$atok);
T('TC-PR11c 非注册手机号被拒', ($r['code']??0)===4040, "code={$r['code']}");
$pwId=(int)v("SELECT id FROM nft_priority_whitelists WHERE activity_id=$paId AND user_id=5");
$r=http('DELETE',"/admin/marketing/priority-whitelist/$pwId",[],$atok);
T('TC-PR11d 白名单移除', ($r['code']??0)===200&&(int)v("SELECT COUNT(*) FROM nft_priority_whitelists WHERE id=$pwId")===0);
exe("INSERT INTO nft_priority_whitelists (activity_id,user_id,phone,max_quantity,used_quantity,expires_at,status) VALUES ($paId,6,'13800000006',1,0,'".date('Y-m-d H:i:s',time()-60)."',1)");
$r=http('POST','/admin/marketing/priority-whitelist/clean-expired',['activity_id'=>$paId],$atok);
T('TC-PR12 过期白名单自动清理', ($r['code']??0)===200&&(int)($r['data']['cleaned']??0)===1, json_encode($r['data'],JSON_UNESCAPED_UNICODE));

// ================= 6.2 资格购（含QF-D1回归） =================
echo "\n=== 6.2 资格购 ===\n";
exe("INSERT INTO nft_qualification_configs (collectible_id,is_enabled,condition_type,required_collectible_ids,required_checkin_days,required_invite_count,valid_start_at,valid_end_at)
     VALUES ($qfCid,1,1,'[]',1,1,'".date('Y-m-d H:i:s',time()-60)."','".date('Y-m-d H:i:s',time()+86400)."')");
$qcId=(int)v("SELECT id FROM nft_qualification_configs WHERE collectible_id=$qfCid");
// QF-D1 回归：详情不再500（修复前 qualification_whitelists 查询 status 列导致 SQLSTATE 异常）
$r=http('GET',"/api/collections/$qfCid",null,$tok['3']);
$qual=$r['data']['qualification']??null;
T('QF-D1回归 资格购详情不再500', ($r['code']??0)===0&&is_array($qual)&&($qual['enabled']??false)===true, "code={$r['code']} qual=".json_encode($qual,JSON_UNESCAPED_UNICODE));
T('TC-QF01 无资格用户qualified=false', ($qual['qualified']??true)===false&&($qual['reason']??'')!=='', "reason=".($qual['reason']??''));
$r=http('POST','/api/orders',['collectibleId'=>$qfCid,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tok['3']);
T('TC-QF01b 无资格用户下单被拒3004', ($r['code']??0)===3004, "code={$r['code']} msg={$r['message']}");
// user3 签到 → 任一条件满足
$r=http('POST','/api/check-in',[],$tok['3']);
T('TC-QF02a user3签到满足任一条件', ($r['code']??0)===0, "code={$r['code']} msg={$r['message']}");
$r=http('GET',"/api/collections/$qfCid",null,$tok['3']);
T('TC-QF02b 签到后qualified=true', ($r['data']['qualification']['qualified']??false)===true);
$r=http('POST','/api/orders',['collectibleId'=>$qfCid,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tok['3']);
$osrc=v("SELECT source FROM nft_orders WHERE user_id=3 AND collectible_id=$qfCid ORDER BY id DESC LIMIT 1");
T('TC-QF02c 资格购下单成功', ($r['code']??0)===0, "code={$r['code']} msg={$r['message']}");
T('TC-QF02d 订单source=eligibility', $osrc==='eligibility', "source=$osrc");
// 全部条件：签到1+邀请1
exe("UPDATE nft_qualification_configs SET condition_type=2 WHERE id=$qcId");
$r=http('POST','/api/orders',['collectibleId'=>$qfCid,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tok['3']);
T('TC-QF03 全部条件未满足被拒', ($r['code']??0)===3004, "code={$r['code']} msg={$r['message']}");
// 白名单通道（管理端API添加 user2）
$r=http('POST','/admin/collectibles/qualification-whitelist',['config_id'=>$qcId,'phones'=>['13800000002'],'expires_at'=>date('Y-m-d H:i:s',time()+86400)],$atok);
T('TC-QF04a 管理端添加资格购白名单', ($r['code']??0)===200&&(int)($r['data']['added']??0)===1, json_encode($r,JSON_UNESCAPED_UNICODE));
$r=http('GET',"/api/collections/$qfCid",null,$tok['2']);
T('TC-QF04b 白名单用户qualified=true', ($r['data']['qualification']['qualified']??false)===true);
$r=http('POST','/api/orders',['collectibleId'=>$qfCid,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tok['2']);
T('TC-QF04c 白名单用户购买成功', ($r['code']??0)===0, "code={$r['code']} msg={$r['message']}");
// user1：签到+邀请 → 全部满足（uk_invitee 唯一：先清历史再造数）
exe("DELETE FROM nft_invite_records WHERE invitee_id=100");
exe("INSERT INTO nft_invite_records (inviter_id,invitee_id,invite_code,status) VALUES (1,100,'".v("SELECT invite_code FROM nft_users WHERE id=1")."','registered')");
http('POST','/api/check-in',[],$tok['1']);
$r=http('POST','/api/orders',['collectibleId'=>$qfCid,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tok['1']);
T('TC-QF06 签到✓邀请✓全部条件通过', ($r['code']??0)===0, "code={$r['code']} msg={$r['message']}");
// 未开始：全员拦截（含白名单 user2）
exe("UPDATE nft_qualification_configs SET valid_start_at='".date('Y-m-d H:i:s',time()+3600)."' WHERE id=$qcId");
$r=http('GET',"/api/collections/$qfCid",null,$tok['2']);
$reason=$r['data']['qualification']['reason']??'';
$r2=http('POST','/api/orders',['collectibleId'=>$qfCid,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tok['2']);
T('TC-QF07 未开始全员拦截', strpos($reason,'尚未开始')!==false&&($r2['code']??0)===3004, "reason=$reason code={$r2['code']}");
// 关闭 → 恢复公售
exe("UPDATE nft_qualification_configs SET valid_start_at='".date('Y-m-d H:i:s',time()-60)."', is_enabled=0 WHERE id=$qcId");
$r=http('GET',"/api/collections/$qfCid",null,$tok['2']);
T('TC-QF08a 关闭后enabled=false', ($r['data']['qualification']['enabled']??true)===false);
$r=http('POST','/api/orders',['collectibleId'=>$qfCid,'quantity'=>1,'paymentPassword'=>'Trade#2026'],$tok['2']);
$osrc=v("SELECT source FROM nft_orders WHERE user_id=2 AND collectible_id=$qfCid ORDER BY id DESC LIMIT 1");
T('TC-QF08b 关闭后公售购买source=release', ($r['code']??0)===0&&$osrc==='release', "code={$r['code']} source=$osrc");

// ================= 6.3 邀请 =================
$dynPhone=fn($tag)=>"139".str_pad((string)random_int(10000000,99999999),8,"0",STR_PAD_LEFT); // 每次运行独立手机号，保证幂等
echo "\n=== 6.3 邀请活动 ===\n";
$u1Code=v("SELECT invite_code FROM nft_users WHERE id=1");
$r=http('GET','/api/invite/info',null,$tok['1']);
T('TC-IV01 邀请信息', ($r['code']??0)===0&&($r['data']['inviteCode']??'')===$u1Code, "code={$r['code']} inviteeCount=".($r['data']['inviteeCount']??'?'));
[$inv1Id,$inv1Tok,$r]=regUser($dynPhone('a'),'SIT邀请A',$u1Code);
$irCnt=(int)v("SELECT COUNT(*) FROM nft_invite_records WHERE inviter_id=1 AND invitee_id=$inv1Id AND status='registered'");
T('TC-IV02 注册绑定邀请码', $inv1Id>0&&$inv1Tok!==''&&$irCnt===1, "uid=$inv1Id irCnt=$irCnt reg=".json_encode($r,JSON_UNESCAPED_UNICODE));
[$inv2Id,$inv2Tok,$r]=regUser($dynPhone('b'),'SIT邀请B','ZZZZ9999');
$irCnt=(int)v("SELECT COUNT(*) FROM nft_invite_records WHERE invitee_id=$inv2Id");
T('TC-IV03 无效邀请码静默忽略', $inv2Id>0&&$irCnt===0, "uid=$inv2Id irCnt=$irCnt");
$r=http('GET','/api/invite/records',null,$tok['1']);
$found=false;foreach(($r['data']['list']??[]) as $row){if(strpos($row['inviteePhone']??'','****')!==false){$found=true;break;}}
T('TC-IV04 邀请记录列表(手机号脱敏)', ($r['code']??0)===0&&$found, "total=".($r['data']['total']??'?'));
$r=http('POST','/admin/marketing/invite',['name'=>'SIT邀请活动','status'=>'enabled',
  'tiers'=>[['inviteCount'=>1,'rewards'=>[['type'=>'points','amount'=>50]]]],
  'invitee_reward_config'=>['type'=>'points','amount'=>20],
  'invitee_conditions'=>['realname'],'grant_mode'=>'realtime'],$atok);
$ivActId=$r['data']['id']??0; T('TC-IV05 配置邀请活动', ($r['code']??0)===200&&$ivActId>0, json_encode($r,JSON_UNESCAPED_UNICODE));
$r=http('POST','/admin/marketing/invite',['name'=>'SIT邀请活动2','status'=>'enabled','tiers'=>[['inviteCount'=>1,'rewards'=>[['type'=>'points','amount'=>1]]],['inviteCount'=>1,'rewards'=>[['type'=>'points','amount'=>1]]]]],$atok);
T('TC-IV05b 档位重复被拒', ($r['code']??0)===4220, "code={$r['code']} msg={$r['message']}");
$r=http('POST','/admin/marketing/invite',['name'=>'SIT邀请活动3','status'=>'enabled','tiers'=>[['inviteCount'=>99,'rewards'=>[['type'=>'points','amount'=>1]]]]],$atok);
T('TC-IV05c 档位人数超限被拒', ($r['code']??0)===4220, "code={$r['code']}");
// inv1 实名 → 双方结算
$r=http('POST','/api/user/realname',['realName'=>'司南测试甲','idCard'=>'110101199001011234'],$inv1Tok);
T('TC-IV06a0 被邀请人提交实名', ($r['code']??0)===0, "code={$r['code']} msg={$r['message']}");
$p1Before=(float)v("SELECT points FROM nft_wallets WHERE user_id=1");
$pInv1Before=(float)v("SELECT points FROM nft_wallets WHERE user_id=$inv1Id");
$r=http('POST','/admin/realname/audit',['user_id'=>$inv1Id,'action'=>'approve'],$atok);
T('TC-IV06a 被邀请人实名审核', ($r['code']??0)===200, json_encode($r,JSON_UNESCAPED_UNICODE));
$p1After=(float)v("SELECT points FROM nft_wallets WHERE user_id=1");
$pInv1After=(float)v("SELECT points FROM nft_wallets WHERE user_id=$inv1Id");
T('TC-IV06b 邀请人档位奖励+50', abs($p1After-$p1Before-50)<0.001, "before=$p1Before after=$p1After");
T('TC-IV06c 被邀请人奖励+20', abs($pInv1After-$pInv1Before-20)<0.001, "before=$pInv1Before after=$pInv1After");
$rr1=(int)v("SELECT COUNT(*) FROM nft_activity_reward_records WHERE activity_type='invite' AND activity_id=$ivActId AND user_id=1 AND reward_type='points'");
$rr2=(int)v("SELECT COUNT(*) FROM nft_activity_reward_records WHERE activity_type='invite' AND activity_id=$ivActId AND user_id=$inv1Id");
T('TC-IV06d 奖励名单留痕(双方)', $rr1===1&&$rr2===1, "inviterRows=$rr1 inviteeRows=$rr2");
D('MK-D3 实名结算SQL语法错误致奖励未发放', ($rr1+$rr2)===0,
  'settleRegisterReward/settleInviteReward 活动查询 where(...,\'OR\') 第4参生成悬空 OR → SQLSTATE 1064，被 settleQuietly 吞异常，邀请/注册实名结算从未生效');
// 幂等：被邀请人签到再次触发结算 → 不重复
$p1B=(float)v("SELECT points FROM nft_wallets WHERE user_id=1");
http('POST','/api/check-in',[],$inv1Tok);
$p1A=(float)v("SELECT points FROM nft_wallets WHERE user_id=1");
$rr=(int)v("SELECT COUNT(*) FROM nft_activity_reward_records WHERE activity_type='invite' AND activity_id=$ivActId AND user_id=1");
T('TC-IV07 结算幂等不重复发放', abs($p1A-$p1B)<0.001&&$rr===1, "delta=".($p1A-$p1B)." rows=$rr");

// ================= 6.4 注册活动 =================
echo "\n=== 6.4 注册活动（实名前N名） ===\n";
$rnBase=(int)v("SELECT COUNT(*) FROM nft_users WHERE is_realname=1 AND deleted_at IS NULL");
$nextRank=$rnBase+1;
$r=http('POST','/admin/marketing/register-save',['name'=>'SIT注册活动','status'=>'enabled','tiers'=>[['rankLimit'=>$nextRank,'rewards'=>[['type'=>'points','amount'=>66]]]],'grant_mode'=>'realtime'],$atok);
$rgActId=$r['data']['id']??0; T('TC-RG01 创建注册活动(档位=第'.$nextRank.'名)', ($r['code']??0)===200&&$rgActId>0, "realnameBase=$rnBase resp=".json_encode($r,JSON_UNESCAPED_UNICODE));
$r=http('POST','/admin/marketing/register-save',['name'=>'SITx','status'=>'enabled','tiers'=>[['rankLimit'=>5,'rewards'=>[['type'=>'points','amount'=>1]]],['rankLimit'=>5,'rewards'=>[['type'=>'points','amount'=>1]]]]],$atok);
T('TC-RG01b 档位重复被拒', ($r['code']??0)===4220, "code={$r['code']}");
$r=http('POST','/admin/marketing/register-save',['name'=>'SITx','status'=>'enabled','tiers'=>[['rankLimit'=>5,'rewards'=>[]]]],$atok);
T('TC-RG01c 空奖励被拒', ($r['code']??0)===4220, "code={$r['code']}");
[$rg1Id,$rg1Tok,$r]=regUser($dynPhone('c'),'SIT注册甲','');
$r=http('POST','/api/user/realname',['realName'=>'司南测试丙','idCard'=>'110101199001017890'],$rg1Tok);
$pB=(float)v("SELECT points FROM nft_wallets WHERE user_id=$rg1Id");
usleep(1100000); // 隔秒，保证 realname_verified_at 可比
$r=http('POST','/admin/realname/audit',['user_id'=>$rg1Id,'action'=>'approve'],$atok);
$pA=(float)v("SELECT points FROM nft_wallets WHERE user_id=$rg1Id");
$rankRow=v("SELECT (SELECT COUNT(*) FROM nft_users u2 WHERE u2.is_realname=1 AND u2.realname_verified_at<u1.realname_verified_at)+1 FROM nft_users u1 WHERE u1.id=$rg1Id");
T('TC-RG02 恰好第N名获奖+66', ($r['code']??0)===200&&$rankRow==$nextRank&&abs($pA-$pB-66)<0.001, "rank=$rankRow/N=$nextRank before=$pB after=$pA resp=".json_encode($r['data']??[],JSON_UNESCAPED_UNICODE));
[$rg2Id,$rg2Tok,$r]=regUser($dynPhone('d'),'SIT注册乙','');
$r=http('POST','/api/user/realname',['realName'=>'司南测试丁','idCard'=>'110101199001015678'],$rg2Tok);
$pB=(float)v("SELECT points FROM nft_wallets WHERE user_id=$rg2Id");
usleep(1100000);
$r=http('POST','/admin/realname/audit',['user_id'=>$rg2Id,'action'=>'approve'],$atok);
$pA=(float)v("SELECT points FROM nft_wallets WHERE user_id=$rg2Id");
T('TC-RG03 第N+1名无奖励', ($r['code']??0)===200&&abs($pA-$pB)<0.001, "before=$pB after=$pA");
$r=http('POST','/admin/marketing/register-delete',['id'=>$rgActId],$atok);
$st=v("SELECT status FROM nft_register_activities WHERE id=$rgActId");
$notDel=(int)v("SELECT deleted_at IS NULL FROM nft_register_activities WHERE id=$rgActId");
T('TC-RG04 已发放活动删除转停用', ($r['code']??0)===200&&$st==='disabled'&&$notDel===1, "status=$st resp=".json_encode($r,JSON_UNESCAPED_UNICODE));

// ================= 6.5 签到 =================
echo "\n=== 6.5 签到 ===\n";
exe("DELETE FROM nft_check_in_records WHERE user_id IN (6,7,8)"); // 清理历史签到，保证连签断言幂等
exe("DELETE FROM nft_activity_reward_records WHERE activity_type='checkin' AND user_id IN (6,7,8)"); // 清理档位防重键，保证重跑奖励可再发放
$p6B=(float)v("SELECT points FROM nft_wallets WHERE user_id=6");
$r=http('POST','/api/check-in',[],$tok['6']);
$p6A=(float)v("SELECT points FROM nft_wallets WHERE user_id=6");
T('TC-CK01a 旧版首签+5司南币', ($r['code']??0)===0&&abs($p6A-$p6B-5)<0.001, "day=".($r['data']['day']??'?')." before=$p6B after=$p6A");
$rec=v("SELECT COUNT(*) FROM nft_check_in_records WHERE user_id=6 AND check_in_date='".date('Y-m-d')."'");
T('TC-CK01b 签到记录落库', $rec===1);
$r=http('POST','/api/check-in',[],$tok['6']);
$p6A2=(float)v("SELECT points FROM nft_wallets WHERE user_id=6");
T('TC-CK02 重复签到拦截', ($r['data']['already']??false)===true&&abs($p6A2-$p6A)<0.001, "already=".var_export($r['data']['already']??null,true));
// 连签造数：-i 天前为连续第 (7-i) 天（昨天=第6天），签到今日即第7天
for($i=6;$i>=1;$i--){exe("INSERT INTO nft_check_in_records (user_id,check_in_date,consecutive_days,reward_type,reward_amount,created_at) VALUES (6,'".date('Y-m-d',strtotime("-$i day"))."',".(7-$i).",'points',5,NOW())");}
exe("DELETE FROM nft_check_in_records WHERE user_id=6 AND check_in_date='".date('Y-m-d')."'");
$p6B=(float)v("SELECT points FROM nft_wallets WHERE user_id=6");
$r=http('POST','/api/check-in',[],$tok['6']);
$p6A=(float)v("SELECT points FROM nft_wallets WHERE user_id=6");
T('TC-CK03 连签第7天+30', ($r['code']??0)===0&&($r['data']['day']??0)===7&&abs($p6A-$p6B-30)<0.001, "day=".($r['data']['day']??'?')." delta=".($p6A-$p6B));
// 旧版流水 balance_after 语义
$wt=(float)v("SELECT balance_after FROM nft_wallet_transactions WHERE user_id=6 AND trans_type='reward' ORDER BY id DESC LIMIT 1");
$pts=(float)v("SELECT points FROM nft_wallets WHERE user_id=6");
D('CK-D1 旧版签到流水balance_after记balance而非points', abs($wt-$pts)>0.001,
  "流水balance_after=$wt(=balance+奖励额) ≠ points后值$pts —— points型奖励流水勾稽断裂（新版grantPoints写points后值，旧版签到写balance+amount，语义不一致）");
// 新版六类奖励
$r=http('POST','/admin/marketing/checkin',['enabled'=>1,'name'=>'SIT签到活动','reward_config'=>['1'=>[['type'=>'points','amount'=>10]],'2'=>[['type'=>'points','amount'=>15]]],'eligibility_type'=>'all','grant_mode'=>'realtime'],$atok);
T('TC-CK04a 启用新版签到配置', ($r['code']??0)===200, "code={$r['code']} msg={$r['message']}");
$p7B=(float)v("SELECT points FROM nft_wallets WHERE user_id=7");
$r=http('POST','/api/check-in',[],$tok['7']);
$p7A=(float)v("SELECT points FROM nft_wallets WHERE user_id=7");
T('TC-CK04b 新版第1天+10', ($r['code']??0)===0&&abs($p7A-$p7B-10)<0.001, "day=".($r['data']['day']??'?')." delta=".($p7A-$p7B));
$r=http('GET','/api/check-in/calendar?month='.date('Y-m'),null,$tok['7']);
T('TC-CK05 签到日历', ($r['code']??0)===0, "code={$r['code']} data=".substr(json_encode($r['data']??[],JSON_UNESCAPED_UNICODE),0,120));
$r=http('GET','/api/check-in/records',null,$tok['7']);
T('TC-CK05b 签到记录API+连签数', ($r['code']??0)===0&&($r['data']['currentStreak']??0)===1, "streak=".($r['data']['currentStreak']??'?'));
http('POST','/admin/marketing/checkin',['grant_mode'=>'manual','reward_config'=>['1'=>[['type'=>'points','amount'=>25]]]],$atok);
$p8B=(float)v("SELECT points FROM nft_wallets WHERE user_id=8");
$r=http('POST','/api/check-in',[],$tok['8']);
$p8A=(float)v("SELECT points FROM nft_wallets WHERE user_id=8");
$pendId=(int)v("SELECT id FROM nft_activity_reward_records WHERE activity_type='checkin' AND user_id=8 AND status='pending' ORDER BY id DESC LIMIT 1");
T('TC-CK06a manual模式入待发放名单', ($r['code']??0)===0&&$pendId>0&&abs($p8A-$p8B)<0.001, "pendingId=$pendId delta=".($p8A-$p8B));
$r=http('POST','/admin/marketing/reward-records/issue',['record_ids'=>[$pendId]],$atok);
$p8A=(float)v("SELECT points FROM nft_wallets WHERE user_id=8");
$st=v("SELECT status FROM nft_activity_reward_records WHERE id=$pendId");
T('TC-CK06b 统一发放到账+25', ($r['code']??0)===200&&abs($p8A-$p8B-25)<0.001&&$st==='issued', "status=$st delta=".($p8A-$p8B));
// 资格拦截：仅限实名 → 非实名(inv2)签到无奖励
http('POST','/admin/marketing/checkin',['eligibility_type'=>'realname','grant_mode'=>'realtime','reward_config'=>['1'=>[['type'=>'points','amount'=>10]]]],$atok);
$pInv2B=(float)v("SELECT points FROM nft_wallets WHERE user_id=$inv2Id");
$r=http('POST','/api/check-in',[],$inv2Tok);
$pInv2A=(float)v("SELECT points FROM nft_wallets WHERE user_id=$inv2Id");
$desc=v("SELECT reward_description FROM nft_check_in_records WHERE user_id=$inv2Id ORDER BY id DESC LIMIT 1");
T('TC-CK07 非实名签到无奖励', ($r['code']??0)===0&&abs($pInv2A-$pInv2B)<0.001&&strpos((string)$desc,'不符合活动参与资格')!==false, "desc=$desc");
http('POST','/admin/marketing/checkin',['enabled'=>0,'eligibility_type'=>'all','grant_mode'=>'realtime'],$atok);
echo "  (签到配置已还原 enabled=0)\n";

// ================= 6.6 抽奖 =================
echo "\n=== 6.6 抽奖 ===\n";
$r=http('POST','/admin/marketing/lucky-activity',['name'=>'SIT抽奖A','status'=>0,'start_time'=>date('Y-m-d H:i:s',time()-60),'end_time'=>date('Y-m-d H:i:s',time()+86400),'eligibility_type'=>'all','grant_mode'=>'realtime'],$atok);
$ldA=$r['data']['id']??0; T('TC-LD01 创建抽奖活动', ($r['code']??0)===200&&$ldA>0, json_encode($r,JSON_UNESCAPED_UNICODE));
$r=http('POST','/admin/marketing/lucky',['activity_id'=>$ldA,'prizes'=>[
  ['tier_name'=>'一等奖','prize_type'=>'points','coin_amount'=>100,'total'=>10,'probability'=>0.6],
  ['tier_name'=>'二等奖','prize_type'=>'points','coin_amount'=>50,'total'=>10,'probability'=>0.7]]],$atok);
T('TC-LD02a 概率和>100%被拒', ($r['code']??0)===4220, "code={$r['code']} msg={$r['message']}");
$r=http('POST','/admin/marketing/lucky',['activity_id'=>$ldA,'prizes'=>[
  ['tier_name'=>'一等奖','prize_type'=>'points','coin_amount'=>100,'total'=>10,'probability'=>0.3],
  ['tier_name'=>'谢谢参与','prize_type'=>'none','total'=>10,'probability'=>0.2]]],$atok);
T('TC-LD02b 概率和<100%被拒', ($r['code']??0)===4220, "code={$r['code']} msg={$r['message']}");
$r=http('POST','/admin/marketing/lucky-activity',['id'=>$ldA,'name'=>'SIT抽奖A','status'=>1],$atok);
T('TC-LD02c 概率不完整禁止启用', ($r['code']??0)===4220, "code={$r['code']} msg={$r['message']}");
$r=http('POST','/admin/marketing/lucky',['activity_id'=>$ldA,'prizes'=>[
  ['tier_name'=>'司南币','prize_type'=>'points','coin_amount'=>10,'total'=>500,'probability'=>0.5,'sort_order'=>1],
  ['tier_name'=>'藏品','prize_type'=>'collectible','collectible_id'=>$ldPrizeCid,'total'=>100,'probability'=>0.2,'sort_order'=>2],
  ['tier_name'=>'抽奖次数','prize_type'=>'draw_chance','reward_config'=>['quantity'=>1],'total'=>300,'probability'=>0.2,'sort_order'=>3],
  ['tier_name'=>'谢谢参与','prize_type'=>'none','total'=>500,'probability'=>0.1,'sort_order'=>4]]],$atok);
T('TC-LD03 保存四类奖项', ($r['code']??0)===200, "code={$r['code']} msg={$r['message']}");
$r=http('POST','/admin/marketing/lucky-activity',['id'=>$ldA,'name'=>'SIT抽奖A','status'=>1,'start_time'=>date('Y-m-d H:i:s',time()-60),'end_time'=>date('Y-m-d H:i:s',time()+86400),'eligibility_type'=>'all','grant_mode'=>'realtime'],$atok);
T('TC-LD03b 启用抽奖活动', ($r['code']??0)===200, "code={$r['code']} msg={$r['message']}");
$r=http('GET','/api/lucky-draw/activity',null,$tok['7']);
$items=count($r['data']['items']??[]);
$free=$r['data']['chances']['free']??null;
T('TC-LD04 C端活动配置(4奖项)', ($r['code']??0)===0&&$items===4, "items=$items activityId=".($r['data']['activityId']??'?'));
// 预置300次抽奖次数给user7（否则免费模式首次中"抽奖次数"后即耗尽拦截）
exe("DELETE FROM nft_lucky_draw_chances WHERE user_id=7");
exe("INSERT INTO nft_lucky_draw_chances (user_id,activity_id,source,total_quantity,used_quantity) VALUES (7,$ldA,'checkin',300,0)");
$dist=[];$err=0;$errSample='';
$p7B=(float)v("SELECT points FROM nft_wallets WHERE user_id=7");
$ucsB=(int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=7 AND collectible_id=$ldPrizeCid AND source='lucky_draw'");
$circB=(int)v("SELECT circulate FROM nft_collectibles WHERE id=$ldPrizeCid");
$t0=microtime(true);
for($i=0;$i<300;$i++){
  $r=http('POST','/api/lucky-draw/draw',[],$tok['7']);
  if(($r['code']??-1)===0){$k=$r['data']['prize']['prizeName']??'?';$dist[$k]=($dist[$k]??0)+1;}
  else{$err++;if($errSample==='')$errSample="code={$r['code']} msg={$r['message']}";}
}
$dt=round(microtime(true)-$t0,1);
$p7A=(float)v("SELECT points FROM nft_wallets WHERE user_id=7");
$ucsA=(int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id=7 AND collectible_id=$ldPrizeCid AND source='lucky_draw'");
$circA=(int)v("SELECT circulate FROM nft_collectibles WHERE id=$ldPrizeCid");
$recCnt=(int)v("SELECT COUNT(*) FROM nft_lucky_draw_records r JOIN nft_lucky_draw_prizes p ON p.id=r.prize_id WHERE p.activity_id=$ldA AND r.user_id=7");
$ptsWin=$dist['司南币']??0;$colWin=$dist['藏品']??0;$dcWin=$dist['抽奖次数']??0;$noneWin=$dist['谢谢参与']??0;
echo "  抽奖300次 耗时{$dt}s 失败=$err 分布: 司南币=$ptsWin 藏品=$colWin 次数=$dcWin 谢谢=$noneWin\n";
T('TC-LD05a 300抽全部成功', $err===0, $errSample);
T('TC-LD05b 抽奖记录=300', $recCnt===300, "recCnt=$recCnt");
T('TC-LD05c 司南币勾稽(10×中奖)', abs(($p7A-$p7B)-10*$ptsWin)<0.001, "delta=".($p7A-$p7B)." wins=$ptsWin");
T('TC-LD05d 藏品持仓+circulate勾稽', ($ucsA-$ucsB)===$colWin&&($circA-$circB)===$colWin, "uc=".($ucsA-$ucsB)." circ=".($circA-$circB)." wins=$colWin");
$chTot=(int)v("SELECT IFNULL(SUM(total_quantity),0) FROM nft_lucky_draw_chances WHERE user_id=7");
$chUsed=(int)v("SELECT IFNULL(SUM(used_quantity),0) FROM nft_lucky_draw_chances WHERE user_id=7");
T('TC-LD05e 次数台账(300+中奖次数=total,used=300)', $chTot===300+$dcWin&&$chUsed===300, "total=$chTot used=$chUsed wins=$dcWin");
$exp=['司南币'=>0.5,'藏品'=>0.2,'抽奖次数'=>0.2,'谢谢参与'=>0.1];$chi=0;$allIn=true;$detail=[];
foreach($exp as $k=>$p){$act=$dist[$k]??0;$e=300*$p;$sig=sqrt(300*$p*(1-$p));$z=abs($act-$e)/$sig;$chi+=($act-$e)**2/$e;
  $in=$z<=3;$allIn=$allIn&&$in;$detail[]="$k:act=$act/exp=".round($e,1)."/z=".round($z,2);}
T('TC-LD05f 分布符合配置概率(3σ+χ²)', $allIn&&$chi<=16.27, implode(' ',$detail)." χ²=".round($chi,2)."(df=3,α=0.001临界16.27)");
exe("DELETE FROM nft_lucky_draw_chances WHERE user_id=8");
exe("INSERT INTO nft_lucky_draw_chances (user_id,activity_id,source,total_quantity,used_quantity) VALUES (8,$ldA,'checkin',1,1)");
$r=http('POST','/api/lucky-draw/draw',[],$tok['8']);
T('TC-LD06 次数耗尽拦截3003', ($r['code']??0)===3003, "code={$r['code']} msg={$r['message']}");
// 奖品抽完：独占活动B
$r=http('POST','/admin/marketing/lucky-activity',['name'=>'SIT抽奖B','status'=>0],$atok);
$ldB=$r['data']['id']??0;
http('POST','/admin/marketing/lucky',['activity_id'=>$ldB,'prizes'=>[['tier_name'=>'独占奖','prize_type'=>'points','coin_amount'=>5,'total'=>1,'probability'=>1.0]]],$atok);
http('POST','/admin/marketing/lucky-activity',['id'=>$ldB,'name'=>'SIT抽奖B','status'=>1],$atok);
http('POST','/admin/marketing/lucky-activity',['id'=>$ldA,'name'=>'SIT抽奖A','status'=>0],$atok);
$r=http('POST','/api/lucky-draw/draw',[],$tok['6']); // user6无台账→免费
$r2=http('POST','/api/lucky-draw/draw',[],$tok['6']);
T('TC-LD07 奖品抽完拦截3001', ($r['code']??0)===0&&($r2['code']??0)===3001, "first={$r['code']} second={$r2['code']} msg={$r2['message']}");
// manual模式：活动C
$r=http('POST','/admin/marketing/lucky-activity',['name'=>'SIT抽奖C','status'=>0,'grant_mode'=>'manual'],$atok);
$ldC=$r['data']['id']??0;
http('POST','/admin/marketing/lucky',['activity_id'=>$ldC,'prizes'=>[['tier_name'=>'待发奖','prize_type'=>'points','coin_amount'=>88,'total'=>10,'probability'=>1.0]]],$atok);
http('POST','/admin/marketing/lucky-activity',['id'=>$ldC,'name'=>'SIT抽奖C','status'=>1,'grant_mode'=>'manual'],$atok);
http('POST','/admin/marketing/lucky-activity',['id'=>$ldB,'name'=>'SIT抽奖B','status'=>0],$atok);
$p4B=(float)v("SELECT points FROM nft_wallets WHERE user_id=4");
$r=http('POST','/api/lucky-draw/draw',[],$tok['4']);
$p4A=(float)v("SELECT points FROM nft_wallets WHERE user_id=4");
$pendId=(int)v("SELECT id FROM nft_activity_reward_records WHERE activity_type='lucky_draw' AND user_id=4 AND status='pending' ORDER BY id DESC LIMIT 1");
T('TC-LD08a manual抽奖入待发放', ($r['code']??0)===0&&$pendId>0&&abs($p4A-$p4B)<0.001, "pendingId=$pendId grantMode=".($r['data']['grantMode']??'?'));
$r=http('POST','/admin/marketing/reward-records/issue',['activity_type'=>'lucky_draw'],$atok);
$p4A=(float)v("SELECT points FROM nft_wallets WHERE user_id=4");
T('TC-LD08b 统一发放+88', abs($p4A-$p4B-88)<0.001, "delta=".($p4A-$p4B));
// 参与资格：仅实名
$r=http('POST','/admin/marketing/lucky-activity',['name'=>'SIT抽奖D','status'=>0,'eligibility_type'=>'realname','grant_mode'=>'realtime'],$atok);
$ldD=$r['data']['id']??0;
http('POST','/admin/marketing/lucky',['activity_id'=>$ldD,'prizes'=>[['tier_name'=>'实名专享','prize_type'=>'points','coin_amount'=>3,'total'=>10,'probability'=>1.0]]],$atok);
http('POST','/admin/marketing/lucky-activity',['id'=>$ldD,'name'=>'SIT抽奖D','status'=>1,'eligibility_type'=>'realname','grant_mode'=>'realtime'],$atok);
http('POST','/admin/marketing/lucky-activity',['id'=>$ldC,'name'=>'SIT抽奖C','status'=>0],$atok);
$r=http('POST','/api/lucky-draw/draw',[],$tok['8']);
T('TC-LD09a 非实名抽奖被拒3002', ($r['code']??0)===3002, "code={$r['code']} msg={$r['message']}");
exe("DELETE FROM nft_lucky_draw_chances WHERE user_id=1");
$r=http('POST','/api/lucky-draw/draw',[],$tok['1']);
T('TC-LD09b 实名用户可抽', ($r['code']??0)===0, "code={$r['code']} msg={$r['message']}");
$r=http('GET','/api/lucky-draw/records',null,$tok['7']);
T('TC-LD10 抽奖记录API', ($r['code']??0)===0&&($r['data']['total']??0)>=300, "total=".($r['data']['total']??'?'));
http('POST','/admin/marketing/lucky-activity',['id'=>$ldD,'name'=>'SIT抽奖D','status'=>0],$atok);

// ================= 6.7 奖励名单 =================
echo "\n=== 6.7 奖励名单 ===\n";
$r=http('GET','/admin/marketing/reward-records?activity_type=invite&status=issued',null,$atok);
T('TC-RR01 名单筛选(邀请/已发放)', ($r['code']??0)===200&&($r['data']['total']??-1)>=2, "total=".($r['data']['total']??'?'));
$stats=$r['data']['stats']??[];
T('TC-RR02 统计卡片', is_array($stats)&&(isset($stats['issued'])||isset($stats['pending'])), json_encode($stats,JSON_UNESCAPED_UNICODE));
[$code,$raw]=httpraw('GET','/admin/marketing/reward-records/export?activity_type=invite',null,$atok);
$hasBom=substr($raw,0,3)==="\xEF\xBB\xBF";
$hasHead=strpos($raw,'记录ID')!==false&&strpos($raw,'活动类型')!==false;
T('TC-RR03 CSV导出(BOM+表头)', $code===200&&$hasBom&&$hasHead, "http=$code bom=".var_export($hasBom,true)." len=".strlen($raw));
$r=http('GET','/admin/marketing/reward-records?user_id=999999',null,$atok);
T('TC-RR04 空结果正常返回', ($r['code']??0)===200&&($r['data']['total']??-1)===0, "total=".($r['data']['total']??'?'));

echo "\n========== 模块6完成: PASS=$pass FAIL=$fail ==========\n";
if($defects){echo "缺陷: ".implode(' | ',$defects)."\n";}
