<?php
/** S1 安全专项集成测试
 *  覆盖（对应测试计划第 5 步"安全专项"）：
 *    1. 水平越权（IDOR）：取消他人挂单 / 处理他人转赠 / 支付与取消他人订单 / 寄售他人资产
 *    2. 垂直越权：C 端 token ↔ 管理端 token 交叉访问、无 token、低权限角色高危接口复核
 *    3. JWT 攻击：alg=none / 源码默认密钥伪造 / 过期令牌 / 篡改 sub / refresh 冒充 access
 *    4. SQL 注入：C 端 id/keyword/sort/price 参数、管理端 keyword/sortField（含时间盲注）
 *    5. XSS：昵称存储型（API 层转义检查）、公告富文本消毒（script/iframe/on事件/javascript协议）
 *    6. 金额/概率参数篡改：充值金额 / 下单数量与价格 / 寄售价 K04/K05 / 求购价 / 抽奖概率参数
 *    7. 文件上传：MIME 伪造 / 扩展不一致 / polyglot / SVG / 超限 / biz 穿越 / 越权调用 / 存储扩展名
 *    8. BCRYPT 哈希：交易密码 / 管理端密码 / 短信验证码全量格式校验（无明文、无 MD5）
 *    9. 限流：短信 60s 频控、管理端登录失败锁定、C 端验证码爆破窗口（发现项评估）
 *   10. 信息泄露：异常响应无堆栈、SQL 错误细节回显评估、404 统一 JSON、debugCode 部署约束
 */
date_default_timezone_set('Asia/Shanghai');
$BASE='http://127.0.0.1:8301';
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING]);
$pass=0;$fail=0;$findings=[];
function T($n,$c,$d=''){global $pass,$fail;$c?$pass++:$fail++;echo($c?"  PASS ":"  FAIL ").$n.($d?" | $d":"")."\n";}
function F($id,$sev,$desc){global $findings;$findings[]="[$sev] $id $desc";echo "  >>> 发现 #$id($sev): $desc\n";}
/** HTTP：返回 [resp, elapsedMs, rawBody] */
function httpT($m,$u,$b=null,$t=null){global $BASE;$ch=curl_init($BASE.$u);$h=['Content-Type: application/json'];if($t)$h[]="Authorization: Bearer $t";
$t0=microtime(true);
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$h,CURLOPT_POSTFIELDS=>json_encode($b?:[])]);
$raw=curl_exec($ch);curl_close($ch);$ms=round((microtime(true)-$t0)*1000);
return [json_decode($raw,true)?:['code'=>-2,'message'=>'BADJSON','raw'=>$raw],$ms,(string)$raw];}
function http($m,$u,$b=null,$t=null){[$r]=httpT($m,$u,$b,$t);return $r;}
/** 原始字符串报文（绕过 json_encode，用于 NAN/INF 等边界） */
function httpBody($m,$u,$bodyStr,$t=null){global $BASE;$ch=curl_init($BASE.$u);$h=['Content-Type: application/json'];if($t)$h[]="Authorization: Bearer $t";
$t0=microtime(true);
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$h,CURLOPT_POSTFIELDS=>$bodyStr]);
$raw=curl_exec($ch);curl_close($ch);
return [json_decode($raw,true)?:['code'=>-2,'raw'=>$raw],round((microtime(true)-$t0)*1000),(string)$raw];}
function q($sql){global $PDO;return $PDO->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
function v($sql){global $PDO;$r=$PDO->query($sql)->fetch(PDO::FETCH_NUM);return $r?$r[0]:null;}
function exe($sql){global $PDO;return $PDO->exec($sql);}
function adminLogin($u,$p){$r=http('POST','/admin/auth/login',['username'=>$u,'password'=>$p]);return [(string)($r['data']['token']??''),(string)($r['data']['refresh_token']??''),$r];}
/** 与 common.php aes_encrypt 完全一致的加密（白盒夹具） */
function aesEnc($data){$key=hash('sha256',envAppKey(),true);$iv=random_bytes(16);
return base64_encode($iv.openssl_encrypt($data,'AES-256-CBC',$key,0,$iv));}
function envAppKey(){foreach(file('/workspace/sinanQZ/sinan-nft-backend/.env') as $l){if(preg_match('/^APP_KEY\s*=\s*(\S+)/i',trim($l),$m))return $m[1];}return 'sinan-nft-secret-key-2026';}
/** 注册全新用户并完成实名/交易密码/充值，返回 [id, phone, token, tradePwd] */
function newUser($tag,$balance=5000){
  $phone='138'.str_pad((string)random_int(10000000,99999999),8,'0',STR_PAD_LEFT);
  $r=http('POST','/api/auth/send-code',['phone'=>$phone,'scene'=>'register']);
  $code=$r['data']['debugCode']??null; if(!$code){echo "  FATAL send-code: ".json_encode($r,JSON_UNESCAPED_UNICODE)."\n";exit(1);}
  $r=http('POST','/api/auth/register',['phone'=>$phone,'code'=>$code,'nickname'=>"安全测试$tag"]);
  $tok=$r['data']['token']??''; if(!$tok){echo "  FATAL register: ".json_encode($r,JSON_UNESCAPED_UNICODE)."\n";exit(1);}
  $uid=(int)v("SELECT id FROM nft_users WHERE phone='$phone'");
  exe("UPDATE nft_users SET is_realname=1, realname_status=2,
    real_name='".aesEnc("测试$tag")."', id_card='".aesEnc('110101199001011234')."',
    transaction_password='".password_hash('Trade#2026',PASSWORD_BCRYPT)."' WHERE id=$uid");
  http('POST','/api/wallet/recharge',['amount'=>$balance],$tok);
  return [$uid,$phone,$tok,'Trade#2026'];
}
/** 上传 multipart：返回 [resp, elapsedMs] */
function upload($token,$filePath,$postName,$mime,$biz='misc'){
  global $BASE;$ch=curl_init($BASE.'/admin/upload/image');
  $cf=new CURLFile($filePath,$mime,$postName);
  $h=[];if($token)$h[]="Authorization: Bearer $token";
  $t0=microtime(true);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$h,
    CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['file'=>$cf,'biz'=>$biz]]);
  $raw=curl_exec($ch);curl_close($ch);
  return [json_decode($raw,true)?:['code'=>-2,'raw'=>$raw],round((microtime(true)-$t0)*1000)];
}
function b64u($s){return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function jwtSign($payload,$secret){$h=b64u(json_encode(['typ'=>'JWT','alg'=>'HS256'])).'.'.b64u(json_encode($payload));
return $h.'.'.b64u(hash_hmac('sha256',$h,$secret,true));}

echo "=============================================================\n";
echo "S1 安全专项 · 司南文创 sinanQZ · ".date('Y-m-d H:i')."\n";
echo "=============================================================\n";

/* ---------------- 测试夹具 ---------------- */
echo "\n=== 0. 环境与夹具准备 ===\n";
exe("UPDATE nft_collectibles SET is_release=1,status='onsale',onsale_at=NULL,off_sale_at=NULL,per_user_limit=99,is_resaleable=1,is_transferable=1,resale_price_mode=0 WHERE id=9001");
T('0.1 藏品 9001 开启发售+寄售（不限价）', v("SELECT is_release FROM nft_collectibles WHERE id=9001")=='1');
$adminPwdHash=password_hash('RoleTest#2026',PASSWORD_BCRYPT);
foreach([['operator_admin','运营',2],['finance_admin','财务',3],['risk_admin','风控',4],['support_admin','客服',5],['sec_lock_test','锁定测试',5]] as $a){
  exe("DELETE FROM nft_admin_users WHERE username='{$a[0]}'");
  exe("INSERT INTO nft_admin_users (username,password_hash,real_name,role_id,status,created_at,updated_at)
    VALUES ('{$a[0]}','$adminPwdHash','{$a[1]}',{$a[2]},1,NOW(),NOW())");
}
T('0.2 5 角色测试管理员就绪（含 sec_lock_test 一次性账号）', (int)v("SELECT COUNT(*) FROM nft_admin_users WHERE username IN ('operator_admin','finance_admin','risk_admin','support_admin','sec_lock_test')")==5);
[$AID,$APH,$ATOK,$APWD]=newUser('A');
[$BID,$BPH,$BTOK,$BPWD]=newUser('B');
T('0.3 用户 A/B 注册并实名+交易密码+充值', $AID>0&&$BID>0&&$AID!==$BID, "A=$AID B=$BID");
$mkOrder=function($tok,$pwd,$qty){$r=http('POST','/api/orders',['collectibleId'=>9001,'quantity'=>$qty,'paymentPassword'=>$pwd],$tok);return $r['data']['orderNo']??null;};
$pay=function($tok,$pwd,$no){return http('POST','/api/orders/'.$no.'/pay',['paymentMethod'=>'balance','paymentPassword'=>$pwd],$tok);};
$oA=$mkOrder($ATOK,$APWD,3); $pay($ATOK,$APWD,$oA);          // A 买 3 件并支付
$oB=$mkOrder($BTOK,$BPWD,2); $pay($BTOK,$BPWD,$oB);          // B 买 2 件并支付
$ucA1=(int)v("SELECT id FROM nft_user_collectibles WHERE user_id=$AID AND status='held' ORDER BY id LIMIT 1");      // A→挂单
$ucA2=(int)v("SELECT id FROM nft_user_collectibles WHERE user_id=$AID AND status='held' ORDER BY id LIMIT 1,1");    // A→价格篡改
$ucB =(int)v("SELECT id FROM nft_user_collectibles WHERE user_id=$BID AND status='held' ORDER BY id LIMIT 1");
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucA1,'price'=>150,'paymentPassword'=>$APWD],$ATOK);
$L_A=(int)($r['data']['listingId']??0);
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucB,'price'=>180,'paymentPassword'=>$BPWD],$BTOK);
$L_B=(int)($r['data']['listingId']??0);
$oPending=$mkOrder($ATOK,$APWD,1);                            // A 未支付订单
$r=http('POST','/api/transfers',['userCollectibleId'=>(int)v("SELECT id FROM nft_user_collectibles WHERE user_id=$AID AND status='held' ORDER BY id DESC LIMIT 1"),'toPhone'=>$BPH,'paymentPassword'=>$APWD],$ATOK);
$TR_AB=(int)v("SELECT id FROM nft_transfers WHERE from_user_id=$AID AND to_user_id=$BID AND status='pending' ORDER BY id DESC LIMIT 1");
T('0.4 夹具：A/B 持仓+挂单+未付订单+待处理转赠', $L_A>0&&$L_B>0&&$oPending&&$TR_AB>0&&$ucA2>0, "L_A=$L_A L_B=$L_B TR=$TR_AB ucA2=$ucA2");

/* ---------------- 1. 水平越权 IDOR ---------------- */
echo "\n=== 1. 水平越权（IDOR）===\n";
$r=http('POST',"/api/resale/listings/$L_B/cancel",['paymentPassword'=>$APWD],$ATOK);
T('1.1 A 取消 B 的挂单被拒（1002）', ($r['code']==1002), 'code='.$r['code']);
T('1.1b B 挂单仍为 selling', v("SELECT status FROM nft_resale_listings WHERE id=$L_B")=='selling');
$r=http('POST',"/api/transfers/$TR_AB/handle",['action'=>'accept'],$ATOK);
T('1.2 A（发起方）accept 自己发起的转赠被拒（1002）', ($r['code']==1002), 'code='.$r['code']);
T('1.2b 转赠仍 pending，资产未过户', v("SELECT status FROM nft_transfers WHERE id=$TR_AB")=='pending');
$r=http('POST','/api/orders/'.$oPending.'/pay',['paymentMethod'=>'balance','paymentPassword'=>$BPWD],$BTOK);
T('1.3 B 支付 A 的订单被拒（1002）', ($r['code']==1002), 'code='.$r['code']);
$r=http('POST','/api/orders/'.$oPending.'/cancel',[],$BTOK);
T('1.4 B 取消 A 的订单被拒（1002）', ($r['code']==1002), 'code='.$r['code']);
T('1.4b A 订单仍 pending', v("SELECT status FROM nft_orders WHERE order_no='$oPending'")=='pending');
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucB,'price'=>100,'paymentPassword'=>$APWD],$ATOK);
T('1.5 A 寄售 B 的资产被拒（非本人资产）', ($r['code']==1001), 'code='.$r['code'].' msg='.$r['message']);
$r=http('POST','/api/transfers',['userCollectibleId'=>$ucB,'toPhone'=>$APH,'paymentPassword'=>$APWD],$ATOK);
T('1.6 A 转赠 B 的资产被拒（非本人资产）', ($r['code']==1001), 'code='.$r['code'].' msg='.$r['message']);
T('1.6b B 的资产状态未被破坏', in_array(v("SELECT status FROM nft_user_collectibles WHERE id=$ucB"),['consigned','held']));
$wa=(float)v("SELECT available FROM nft_wallets WHERE user_id=$AID");
T('1.7 A 钱包余额无负数', $wa>=0, "available=$wa");

/* ---------------- 2. 垂直越权 ---------------- */
echo "\n=== 2. 垂直越权（token 交叉/无 token/低权限角色）===\n";
[$STOK]=adminLogin('admin','admin123');
$r=http('GET','/admin/users',null,$ATOK);
T('2.1 C 端 token 访问 /admin/users 被拒（4001/4002）', in_array($r['code'],[4001,4002]), 'code='.$r['code']);
[$r2up]=upload($ATOK,'/etc/hostname','x.jpg','image/jpeg');
T('2.2 C 端 token 调 /admin/upload/image 被拒（4001/4002）', in_array(($r2up['code']??-1),[4001,4002]), 'code='.($r2up['code']??''));
$r=http('GET','/api/user/profile',null,$STOK);
T('2.3 管理端 token 访问 /api/user/profile 被拒（2001）', ($r['code']==2001), 'code='.$r['code']);
$r=http('GET','/api/user/profile');
T('2.4 无 token 访问 /api/user/profile 被拒（2001）', ($r['code']==2001), 'code='.$r['code']);
$r=http('GET','/admin/users');
T('2.5 无 token 访问 /admin/users 被拒（4001）', ($r['code']==4001), 'code='.$r['code']);
[$SUPPORT_TOK]=adminLogin('support_admin','RoleTest#2026');
$r=http('POST','/admin/platform/cleanup',['confirm'=>'CONFIRM_CLEANUP','force'=>true],$SUPPORT_TOK);
T('2.6 客服角色调平台清库被拒（4003）', ($r['code']==4003||$r['code']==4040), 'code='.$r['code']);
$r=http('POST','/admin/users/1/freeze',['status'=>0],$SUPPORT_TOK);
T('2.7 客服角色冻结用户被拒（4003）', ($r['code']==4003), 'code='.$r['code']);
$r=http('POST','/admin/chain/configs',['chain_code'=>'test','api_key'=>'x'],$SUPPORT_TOK);
T('2.8 客服角色改链配置被拒（4003/4040）', ($r['code']==4003||$r['code']==4040), 'code='.$r['code']);

/* ---------------- 3. JWT 攻击 ---------------- */
echo "\n=== 3. JWT 攻击面 ===\n";
$secretEnv=(function(){foreach(file('/workspace/sinanQZ/sinan-nft-backend/.env') as $l){if(preg_match('/^SECRET\s*=\s*(.+)$/i',trim($l),$m))return trim($m[1]);}return '';})();
T('3.0 运行时 C 端密钥已配置且非源码默认值', strlen($secretEnv)>=32 && $secretEnv!=='sinan-nft-secret', 'len='.strlen($secretEnv));
$adminSecretEnv=(function(){foreach(file('/workspace/sinanQZ/sinan-nft-backend/.env') as $l){if(preg_match('/^ADMIN_SECRET\s*=\s*(.+)$/i',trim($l),$m))return trim($m[1]);}return '';})();
T('3.0b 运行时管理端密钥已配置、≥32 字节且非源码默认值（SEC-J1 修复后强制要求）', strlen($adminSecretEnv)>=32 && $adminSecretEnv!=='sinan-nft-admin-jwt-secret-2026-strong-hmac-key', 'len='.strlen($adminSecretEnv));
$hdr=b64u(json_encode(['typ'=>'JWT','alg'=>'none']));$pl=b64u(json_encode(['sub'=>$BID,'iat'=>time(),'exp'=>time()+3600]));
$r=http('GET','/api/user/profile',null,"$hdr.$pl.");
T('3.1 alg=none 令牌被拒（2001）', ($r['code']==2001), 'code='.$r['code']);
$forged=jwtSign(['iss'=>'sinan-nft-audience','aud'=>'sinan-nft-client','iat'=>time(),'exp'=>time()+3600,'sub'=>$BID,'phone'=>$BPH],'sinan-nft-secret');
$r=http('GET','/api/user/profile',null,$forged);
T('3.2 用源码默认密钥伪造 C 端令牌被拒（2001）', ($r['code']==2001), 'code='.$r['code']);
$forgedAdmin=jwtSign(['iss'=>'sinan-nft','aud'=>'sinan-admin','sub'=>1,'role'=>1,'iat'=>time(),'exp'=>time()+3600,'scope'=>'admin_access'],'sinan-nft-admin-jwt-secret-2026-strong-hmac-key');
$r=http('GET','/admin/users',null,$forgedAdmin);
T('3.3 用源码默认 ADMIN_SECRET 伪造管理端令牌被拒', in_array($r['code'],[4001,4002,4003]), 'code='.$r['code']);
$expired=jwtSign(['iss'=>'sinan-nft-audience','aud'=>'sinan-nft-client','iat'=>time()-7200,'exp'=>time()-3600,'sub'=>$AID,'phone'=>$APH],$secretEnv);
$r=http('GET','/api/user/profile',null,$expired);
T('3.4 过期令牌被拒（2001）', ($r['code']==2001), 'code='.$r['code'].' msg='.$r['message']);
$parts=explode('.',$ATOK);$payload=json_decode(base64_decode(strtr($parts[1],'-_','+/')),true);
$payload['sub']=$BID;$payload['phone']=$BPH;
$tampered=$parts[0].'.'.b64u(json_encode($payload)).'.'.$parts[2];
$r=http('GET','/api/user/profile',null,$tampered);
T('3.5 篡改 sub 未重签名令牌被拒（2001）', ($r['code']==2001), 'code='.$r['code']);
[$atok,$rtok]=adminLogin('admin','admin123');
$r=http('GET','/admin/users',null,$rtok);
T('3.6 refresh_token 冒充 access 调 /admin/users 被拒（4002）', ($r['code']==4002), 'code='.$r['code']);
$r=http('GET','/api/user/profile',null,$atok);
T('3.7 管理端 access token 调 C 端接口被拒（2001）', in_array($r['code'],[2001,2003]), 'code='.$r['code']);

/* ---------------- 4. SQL 注入 ---------------- */
echo "\n=== 4. SQL 注入探测（id/keyword/sort/price）===\n";
$SQLI=["1' OR '1'='1","1 UNION SELECT 1,2,3--","1;DROP TABLE nft_users","1 AND SLEEP(3)","1' AND updatexml(1,concat(0x7e,version()),1)--","%' UNION SELECT password FROM nft_users--"];
$noErr=function($r,$raw){$s=json_encode($r,JSON_UNESCAPED_UNICODE);return strpos($s,'SQLSTATE')===false&&strpos($s,'syntax')===false&&strpos($raw,'ThinkPHP')===false;};
$collectCnt=(int)v("SELECT COUNT(*) FROM nft_collectibles");
foreach($SQLI as $i=>$p){
  [$r,$ms,$raw]=httpT('GET','/api/collections/'.rawurlencode($p));
  T('4.1a C 端藏品详情 id 注入 #'.($i+1).' 无 SQL 错误', $noErr($r,$raw)&&$ms<2500, "code={$r['code']} {$ms}ms");
}
foreach(["' OR 1=1--","%'","\" UNION SELECT 1--","\\' OR '1'='1"] as $i=>$p){
  [$r,$ms,$raw]=httpT('GET','/api/market/collections?keyword='.rawurlencode($p));
  T('4.1b 市场列表 keyword 注入 #'.($i+1).' 无 SQL 错误', $noErr($r,$raw)&&$ms<2500, "code={$r['code']} {$ms}ms");
}
[$r,$ms,$raw]=httpT('GET','/api/market/collections?sort='.rawurlencode('price-asc;DROP TABLE nft_collectibles--'));
T('4.1c 市场列表 sort 注入（白名单外回落默认）', $noErr($r,$raw)&&$ms<2500, 'code='.$r['code']);
[$r,$ms,$raw]=httpT('GET','/api/resale/listings?priceMin='.rawurlencode("1' OR '1'='1").'&priceMax=999&sort='.rawurlencode('price-asc AND SLEEP(3)'));
T('4.1d 挂单池 priceMin/sort 注入无错误', $noErr($r,$raw)&&$ms<2500, "code={$r['code']} {$ms}ms");
[$r,$ms,$raw]=httpT('GET','/api/orders?status='.rawurlencode("pending' OR '1'='1"),null,$ATOK);
T('4.1e 我的订单 status 注入无错误', $noErr($r,$raw)&&$ms<2500, 'code='.$r['code']);
[$r,$ms,$raw]=httpT('GET','/api/wallet/transactions?type='.rawurlencode("buy' UNION SELECT 1--"),null,$ATOK);
T('4.1f 钱包流水 type 注入无错误', $noErr($r,$raw)&&$ms<2500, 'code='.$r['code']);
[$r,$ms,$raw]=httpT('GET','/api/artifacts/'.rawurlencode("1 AND SLEEP(3)"));
T('4.1g 文物详情 id 时间盲注（SLEEP(3) 不生效）', $noErr($r,$raw)&&$ms<2500, "code={$r['code']} {$ms}ms");
[$r,$ms,$raw]=httpT('GET','/admin/users?keyword='.rawurlencode("' OR 1=1--"),null,$STOK);
T('4.2a 管理端用户列表 keyword 注入无错误', $noErr($r,$raw)&&$ms<2500, "code=".($r['code']??'')." {$ms}ms");
[$r,$ms,$raw]=httpT('GET','/admin/users?keyword='.rawurlencode("1' UNION SELECT password_hash FROM nft_admin_users--"),null,$STOK);
T('4.2b 管理端 keyword UNION 注入无数据泄漏', $noErr($r,$raw)&&$ms<2500, 'code='.($r['code']??''));
[$r,$ms,$raw]=httpT('GET','/admin/orders?sortField='.rawurlencode('created_at;DROP TABLE nft_orders--').'&sortOrder=desc',null,$STOK);
T('4.2c 管理端 sortField 注入（白名单映射）无错误', $noErr($r,$raw)&&$ms<2500, 'code='.($r['code']??''));
[$r,$ms,$raw]=httpT('GET','/admin/collectibles?keyword='.rawurlencode("%' AND SLEEP(3)--"),null,$STOK);
T('4.2d 管理端藏品 keyword 时间盲注（<2.5s）', $noErr($r,$raw)&&$ms<2500, "code=".($r['code']??'')." {$ms}ms");
T('4.3 注入探测后数据完整（collectibles 行数不变）', (int)v("SELECT COUNT(*) FROM nft_collectibles")===$collectCnt);

/* ---------------- 5. XSS ---------------- */
echo "\n=== 5. XSS（存储型/富文本消毒）===\n";
$ch=curl_init($BASE.'/api/user/profile');
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json',"Authorization: Bearer $ATOK"],
CURLOPT_POSTFIELDS=>json_encode(['nickname'=>'<img src=x>'])]);
$r=json_decode(curl_exec($ch),true);curl_close($ch);
$stored=v("SELECT username FROM nft_users WHERE id=$AID");
$r2=http('GET','/api/user/profile',null,$ATOK);
$reflected=$r2['data']['nickname']??'';
if($stored==='<img src=x>'&&$reflected==='<img src=x>'){
  F('SEC-X1','低危',"昵称存储型 XSS：API 层未做输出转义（'<img src=x>' 原样入库并回显，依赖前端框架转义；建议服务端剥离 HTML 标签）");
  T('5.1 昵称 XSS payload 原样存储/回显（记录发现 SEC-X1）', false, '见发现清单');
}else{
  T('5.1 昵称 XSS payload 被过滤/转义', true, "stored=$stored");
}
$ch=curl_init($BASE.'/api/user/profile');
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json',"Authorization: Bearer $ATOK"],
CURLOPT_POSTFIELDS=>json_encode(['nickname'=>'安全测试A'])]);
curl_exec($ch);curl_close($ch);
$annContent='<p>安全测试正文</p><script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)">链接</a><iframe src="//evil"></iframe>';
$r=http('POST','/admin/cms/announcements',['title'=>'SIT-XSS-安全测试公告','type'=>'notice','content'=>$annContent,'status'=>'published'],$STOK);
$annId=(int)($r['data']['id']??0);
if($annId===0)$annId=(int)v("SELECT id FROM nft_announcements WHERE title='SIT-XSS-安全测试公告' ORDER BY id DESC LIMIT 1");
T('5.2 创建含 XSS 公告（管理端，published）', $annId>0, 'annId='.$annId.' code='.($r['code']??''));
$raw=http('GET','/api/announcements');
/* 断言依据取入库 content（C 端列表仅回 summary，不回显正文——按列表断言会空转） */
$row=q("SELECT content FROM nft_announcements WHERE id=$annId");
$cont=(string)($row[0]['content']??'');
T('5.3a 公告富文本 <script> 被剥离（入库值）', $cont!==''&&strpos($cont,'<script')===false, 'len='.strlen($cont));
T('5.3b 公告富文本 on* 事件属性被剥离', $cont!==''&&strpos($cont,'onerror')===false);
T('5.3c 公告富文本 javascript: 协议被剥离', $cont!==''&&strpos($cont,'javascript:')===false);
T('5.3d 公告富文本 <iframe> 被剥离', $cont!==''&&strpos($cont,'<iframe')===false);
T('5.3e 公告正文保留（未过度清洗）', strpos($cont,'安全测试正文')!==false);
T('5.3f C 端列表不回显富文本正文（仅 summary）', strpos(json_encode($raw,JSON_UNESCAPED_UNICODE),'<script')===false&&strpos(json_encode($raw,JSON_UNESCAPED_UNICODE),'安全测试正文')===false);
exe("DELETE FROM nft_announcements WHERE id=$annId");

/* ---------------- 6. 金额/概率参数篡改 ---------------- */
echo "\n=== 6. 金额/概率参数篡改 ===\n";
$before=(float)v("SELECT available FROM nft_wallets WHERE user_id=$AID");
foreach([['负数',-100],['零',0],['小于1',0.5]] as [$n,$amt]){
  $r=http('POST','/api/wallet/recharge',['amount'=>$amt],$ATOK);
  T("6.1 充值金额 $n 被拒（1001）", $r['code']==1001, 'code='.$r['code']);
}
$r=http('POST','/api/wallet/recharge',['amount'=>'1; DROP TABLE nft_wallets; --'],$ATOK);
$after=(float)v("SELECT available FROM nft_wallets WHERE user_id=$AID");
T('6.2 充值金额字符串注入按 float 语义处理（无注入，到账 1.00）', $r['code']==0&&abs($after-$before-1.0)<0.001, "code={$r['code']} Δ=".round($after-$before,2));
T('6.2b wallets 表未被破坏', v("SELECT COUNT(*) FROM nft_wallets")!==null);
[$r,,]=httpBody('POST','/api/wallet/recharge','{"amount":"NAN"}',$ATOK);
$r2=(float)v("SELECT available FROM nft_wallets WHERE user_id=$AID");
T('6.3 充值字符串 "NAN" 被拒且余额不变', $r['code']!=0&&abs($r2-$after)<0.001, 'code='.$r['code']);
[$r,,]=httpBody('POST','/api/wallet/recharge','{"amount":"1e309"}',$ATOK);
$r3=(float)v("SELECT available FROM nft_wallets WHERE user_id=$AID");
T('6.4 充值 "1e309"（INF）被拒且余额不变', $r['code']!=0&&abs($r3-$r2)<0.001, 'code='.$r['code']);
$price=(float)v("SELECT price FROM nft_collectibles WHERE id=9001");
foreach([['零',0],['负数',-5]] as [$n,$qq]){
  $r=http('POST','/api/orders',['collectibleId'=>9001,'quantity'=>$qq,'paymentPassword'=>$APWD],$ATOK);
  T("6.5 下单数量 $n 被拒", $r['code']!=0, 'code='.$r['code']);
}
$r=http('POST','/api/orders',['collectibleId'=>9001,'quantity'=>'1 OR 1=1','price'=>0.01,'totalPrice'=>0.01,'unitPrice'=>0.01,'paymentPassword'=>$APWD],$ATOK);
$on=$r['data']['orderNo']??'';
$rec=$on?(q("SELECT quantity,unit_price,total_price FROM nft_orders WHERE order_no='$on'")[0]??null):null;
T('6.6 下单篡改 price/totalPrice 无效（服务端定价，非法数量回落 1）', $on!==''&&(int)$rec['quantity']===1&&abs((float)$rec['total_price']-$price)<0.001, "qty={$rec['quantity']} total={$rec['total_price']} expect=$price");
http('POST','/api/orders/'.$on.'/cancel',[],$ATOK);
$oT=$mkOrder($ATOK,$APWD,1);
$balB=(float)v("SELECT available FROM nft_wallets WHERE user_id=$AID");
$r=http('POST','/api/orders/'.$oT.'/pay',['paymentMethod'=>'balance','paymentPassword'=>$APWD,'amount'=>0.01],$ATOK);
$balA=(float)v("SELECT available FROM nft_wallets WHERE user_id=$AID");
T('6.7 支付传 amount=0.01 被忽略（按订单总额扣款）', $r['code']==0&&abs($balB-$balA-$price)<0.001, "Δ=".round($balB-$balA,2));
exe("UPDATE nft_collectibles SET resale_price_mode=2,resale_price_min=100,resale_price_max=200 WHERE id=9001");
foreach([['低于下限',50],['超上限',999],['负数',-10],['零',0]] as [$n,$pp]){
  $r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucA2,'price'=>$pp,'paymentPassword'=>$APWD],$ATOK);
  T("6.8 寄售价格 $n 被拒（K04 区间管控）", $r['code']==1001, 'code='.$r['code']);
}
$globalMaxBak=v("SELECT config_value FROM nft_system_configs WHERE config_key='resale_price_global_max'");
exe("UPDATE nft_system_configs SET config_value='300' WHERE config_key='resale_price_global_max'");
exe("UPDATE nft_collectibles SET resale_price_mode=0 WHERE id=9001");
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucA2,'price'=>99999,'paymentPassword'=>$APWD],$ATOK);
T('6.9 寄售价格超全局上限被拒（K05）', $r['code']==1001, 'code='.$r['code']);
$r=http('POST','/api/resale/listings',['userCollectibleId'=>$ucA2,'price'=>150,'paymentPassword'=>$APWD],$ATOK);
$LK=(int)($r['data']['listingId']??0);
$rec=q("SELECT price,fee_amount,actual_amount FROM nft_resale_listings WHERE id=$LK")[0]??null;
T('6.10 寄售区间内价格 150 成功且费用按配置计算', $LK>0&&abs((float)$rec['price']-150)<0.001, "fee={$rec['fee_amount']} actual={$rec['actual_amount']}");
http('POST','/api/resale/listings/'.$LK.'/cancel',[],$ATOK);
exe("UPDATE nft_system_configs SET config_value='".addslashes((string)$globalMaxBak)."' WHERE config_key='resale_price_global_max'");
exe("UPDATE nft_collectibles SET resale_price_mode=0 WHERE id=9001");
foreach([['负数',-1],['零',0]] as [$n,$pp]){
  $r=http('POST','/api/buy-requests',['collectibleId'=>9001,'price'=>$pp,'quantity'=>1,'remark'=>'x'],$ATOK);
  T("6.11 求购价格 $n 被拒", $r['code']!=0, 'code='.$r['code'].' msg='.$r['message']);
}
$r=http('POST','/api/lucky-draw/draw',['probability'=>100,'prizeId'=>1,'fixedWin'=>1,'weight'=>999],$ATOK);
T('6.12 抽奖携带 probability/prizeId 篡改参数被忽略（业务响应非异常）', isset($r['code'])&&$r['code']!=5001, 'code='.($r['code']??''));
$r=http('POST','/api/blind-boxes/open',['boxId'=>1,'probability'=>100,'rarity'=>'legend'],$ATOK);
T('6.13 盲盒开启携带 probability/rarity 参数被忽略（业务响应非异常）', isset($r['code'])&&$r['code']!=5001, 'code='.($r['code']??''));

/* ---------------- 7. 文件上传 ---------------- */
echo "\n=== 7. 文件上传校验 ===\n";
$tmp=sys_get_temp_dir().'/sit_sec';
@mkdir($tmp,0755,true);
file_put_contents("$tmp/evil.php",'<?php echo "PWNED"; system($_GET["c"]);');
[$r]=upload($STOK,"$tmp/evil.php",'evil.jpg','image/jpeg');
T('7.1 PHP 内容伪装 image/jpeg 被拒（4220）', ($r['code']==4220), 'code='.($r['code']??''));
[$r]=upload($STOK,"$tmp/evil.php",'evil.php.jpg','image/jpeg');
T('7.2 PHP 内容 .php.jpg 双扩展被拒（内容检测）', ($r['code']==4220), 'code='.($r['code']??''));
file_put_contents("$tmp/xss.svg",'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script></svg>');
[$r]=upload($STOK,"$tmp/xss.svg",'xss.svg','image/svg+xml');
T('7.3 SVG（含 script/onload）被拒（MIME 白名单）', ($r['code']==4220), 'code='.($r['code']??''));
$jpg=base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwcJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPDs0NDT/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oACAEBAAA/APn+v//Z');
file_put_contents("$tmp/poly.jpg",$jpg."<?php echo 'PWNED';?>");
[$r]=upload($STOK,"$tmp/poly.jpg",'poly.jpg','image/jpeg');
$url=$r['data']['url']??'';$extOk=$url!==''&&preg_match('/\.jpg$/',$url);
if($extOk){
  /* 文件字节本身含 PWNED 字面量（polyglot 特性），判据应为"未被当作 PHP 执行"：
     请求 ?c=id，若执行则 system('id') 输出含 uid=；静态返回则只有图片字节 */
  $ch=curl_init($BASE.$url.'?c=id');curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);$body=curl_exec($ch);$ct=curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
  T('7.4 polyglot（真图+PHP 尾注）强制 .jpg 存储且静态返回不执行', strpos($body,'uid=')===false&&strpos($ct,'image/jpeg')!==false, "url=$url ct=$ct");
}else{
  T('7.4 polyglot 被拒绝（更严格策略亦通过）', true, 'code='.($r['code']??''));
}
file_put_contents("$tmp/big.jpg",str_pad($jpg,6*1024*1024,"\0"));
[$r]=upload($STOK,"$tmp/big.jpg",'big.jpg','image/jpeg');
T('7.5 超 5MB 文件被拒（4220）', ($r['code']==4220), 'code='.($r['code']??''));
[$r]=upload($STOK,"$tmp/poly.jpg",'ok.jpg','image/jpeg','../../evil');
T('7.6 biz=../../evil 路径穿越被拒（4220）', ($r['code']==4220), 'code='.($r['code']??''));
T('7.6b 无穿越目录残留', !is_dir('/workspace/sinanQZ/sinan-nft-backend/public/evil'));
[$r]=upload('',$tmp.'/poly.jpg','x.jpg','image/jpeg');
T('7.7 无 token 上传被拒（4001）', in_array(($r['code']??-1),[4001,-2]), 'code='.($r['code']??''));

/* ---------------- 8. BCRYPT 与敏感存储 ---------------- */
echo "\n=== 8. BCRYPT 哈希与敏感存储 ===\n";
$rows=q("SELECT transaction_password FROM nft_users WHERE transaction_password IS NOT NULL AND transaction_password<>''");
$ok=0;$bad=[];foreach($rows as $x){preg_match('/^\$2y\$\d+\$/',(string)$x['transaction_password'])?$ok++:$bad[]=substr($x['transaction_password'],0,10);}
T('8.1 用户交易密码全部 BCRYPT', count($rows)>0&&$ok===count($rows), "$ok/".count($rows).( $bad?' bad='.implode(',',$bad):''));
$rows=q("SELECT password_hash FROM nft_admin_users WHERE password_hash<>''");
$ok=0;foreach($rows as $x){preg_match('/^\$2y\$\d+\$/',(string)$x['password_hash'])&&$ok++;}
T('8.2 管理员密码全部 BCRYPT', count($rows)>0&&$ok===count($rows), "$ok/".count($rows));
$rows=q("SELECT code FROM nft_verification_codes WHERE used_at IS NULL");
$ok=0;foreach($rows as $x){preg_match('/^\$2y\$\d+\$/',(string)$x['code'])&&$ok++;}
T('8.3 短信验证码哈希存储（无明文）', $ok===count($rows), "$ok/".count($rows));
$rows=q("SELECT transaction_password p FROM nft_users WHERE transaction_password REGEXP '^[0-9a-f]{32}$'");
T('8.4 无 MD5 弱哈希（32 位 hex）', count($rows)===0);
$rn=v("SELECT real_name FROM nft_users WHERE id=$AID");
T('8.5 实名姓名 AES 密文落库（非明文）', $rn!==null&&$rn!==''&&strpos((string)$rn,'测试A')===false&&strlen(base64_decode((string)$rn))>=40, 'len='.strlen((string)$rn));
$idc=v("SELECT id_card FROM nft_users WHERE id=$AID");
T('8.6 身份证号 AES 密文落库', $idc!==null&&$idc!==''&&!preg_match('/^\d{17}[\dXx]$/',(string)$idc), 'len='.strlen((string)$idc));

/* ---------------- 9. 限流 ---------------- */
echo "\n=== 9. 限流（短信/登录/爆破窗口）===\n";
$pr='138'.str_pad((string)random_int(10000000,99999999),8,'0',STR_PAD_LEFT);
$r1=http('POST','/api/auth/send-code',['phone'=>$pr,'scene'=>'register']);
$r2=http('POST','/api/auth/send-code',['phone'=>$pr,'scene'=>'register']);
T('9.1 同手机号 60s 内重发验证码被拒（1001 频控）', $r1['code']==0&&$r2['code']==1001, 'r1='.$r1['code'].' r2='.$r2['code']);
$brute='138'.str_pad((string)random_int(10000000,99999999),8,'0',STR_PAD_LEFT);
http('POST','/api/auth/send-code',['phone'=>$brute,'scene'=>'login']);
$lastCode=null;
for($i=1;$i<=6;$i++){
  $r=http('POST','/api/auth/login',['phone'=>$brute,'code'=>'000000']);
  if($r['code']!=1001){$lastCode=$r['code'];break;}
}
if($lastCode===null){
  F('SEC-R1','中危','C 端登录验证码无爆破防护：6 位码 5 分钟有效期内可无限次尝试（连续 6 次错误仍可继续），建议增加每手机号/每 IP 尝试次数限制');
  T('9.2 连续 6 次错误验证码仍可尝试（记录发现 SEC-R1）', false, '见发现清单');
}else{
  T('9.2 连续错误验证码触发限制', true, "code=$lastCode");
}
$lockHappened=false;
for($i=1;$i<=5;$i++){$r=adminLogin('sec_lock_test','WrongPass#'.$i)[2];if($r['code']==4002)$lockHappened=true;}
[$t3,,$r3]=adminLogin('sec_lock_test','RoleTest#2026');
$locked=v("SELECT locked_until FROM nft_admin_users WHERE username='sec_lock_test'");
T('9.3 管理端连续 5 次错误密码触发锁定', ($r3['code']==4002||$r3['code']==4001)&&$locked>date('Y-m-d H:i:s'), 'code='.$r3['code'].' locked_until='.$locked);
T('9.3b 锁定期间正确密码也拒绝', $t3==='', 'token='.($t3?'有':'无'));
$logs=(int)v("SELECT COUNT(*) FROM nft_admin_login_logs WHERE username='sec_lock_test' AND status=2");
T('9.4 登录失败留痕（admin_login_logs）', $logs>=5, "fail_logs=$logs");
exe("UPDATE nft_admin_users SET locked_until=NULL,login_fail_count=0 WHERE username='sec_lock_test'");
$r=http('POST','/api/lucky-draw/draw',[],$BTOK);
T('9.5 抽奖次数消耗受台账约束（B 无篡改参数时业务响应正常）', isset($r['code'])&&$r['code']!=5001, 'code='.($r['code']??''));

/* ---------------- 10. 信息泄露 ---------------- */
echo "\n=== 10. 信息泄露 ===\n";
[$r,,$raw]=httpBody('POST','/api/wallet/recharge','{"amount":"NAN"}',$ATOK);
$leak=strpos($raw,'SQLSTATE')!==false||strpos($raw,'syntax')!==false;
if($leak){
  F('SEC-I1','低危','异常响应回显 SQL 错误细节（SQLSTATE/语法信息），生产环境可能暴露表结构；建议 fail(5001) 不拼接 $e->getMessage()');
  T('10.1 异常响应回显 SQL 细节（记录发现 SEC-I1）', false, substr($raw,0,100));
}else{
  T('10.1 异常路径响应无 SQL 细节泄露', true, substr($raw,0,80));
}
$stackLeak=strpos($raw,'Stack trace')!==false||strpos($raw,'/workspace/')!==false||strpos($raw,'vendor/')!==false;
T('10.2 异常响应无堆栈/路径泄露（统一 JSON）', !$stackLeak);
[$r,,$raw2]=httpT('GET','/api/non-exist-path-404');
T('10.3 404 路径返回统一 JSON（无框架调试页）', strpos($raw2,'ThinkPHP')===false&&strpos($raw2,'<!DOCTYPE')===false, substr($raw2,0,60));
$pr2='138'.str_pad((string)random_int(10000000,99999999),8,'0',STR_PAD_LEFT);
$r=http('POST','/api/auth/send-code',['phone'=>$pr2,'scene'=>'register']);
$hasDebug=isset($r['data']['debugCode'])&&$r['data']['debugCode'];
T('10.4 APP_DEBUG=true 时验证码明文回显（Mock 设计；生产部署必须关闭 APP_DEBUG）', $hasDebug, 'debugCode='.($hasDebug?'返回':'未返回'));

/* ---------------- 汇总 ---------------- */
echo "\n=============================================================\n";
echo "安全专项汇总：PASS=$pass FAIL=$fail\n";
echo "发现（".count($findings)." 项）：\n".(count($findings)?implode("\n",$findings):"（无）")."\n";
exit($fail>0?1:0);
