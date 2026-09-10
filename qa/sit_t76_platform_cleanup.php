<?php
/** F7-6 管理端 平台清库四重确认演练（破坏性测试——F7 最后执行）
 *  覆盖：
 *    7.6.1 影响面预览（只读/表清单/受保护声明/smsRequired）+ 权限隔离（4 角色 4003 / 无 token 4001 / 伪 token 4002 / C 端 token 4002）
 *    7.6.2 执行前参数校验（缺参/短 reason/未发码/非绑定手机号/60s 频控）
 *    7.6.3 错码拒绝 + F7-D7 修复验证：备份失败阻断清库（mysqldump 临时失效 → 5000 + 全表零变动）
 *    7.6.4 正式清库（mock 验证码 → 自动备份 → 白名单 42 表清零 / users 清空 / 受保护表原样 / 清库日志 / 审计留痕）
 *    7.6.5 验证码一次性 + 备份文件完整可恢复（restore 回基线，环境复原供 H 系列使用）
 */
date_default_timezone_set('Asia/Shanghai');
$BASE='http://127.0.0.1:8301';
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING]);
$pass=0;$fail=0;$fails=[];
function T($n,$c,$d=''){global $pass,$fail,$fails;$c?$pass++:$fail++;if(!$c)$fails[]=$n;echo($c?"  PASS ":"  FAIL ").$n.($d?" | $d":"")."\n";}
function http($m,$u,$b=null,$t=null){global $BASE;$ch=curl_init($BASE.$u);$h=['Content-Type: application/json','User-Agent: SIT-TestAgent/1.0'];if($t)$h[]="Authorization: Bearer $t";
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_HTTPHEADER=>$h,CURLOPT_POSTFIELDS=>json_encode($b?:[])]);$raw=curl_exec($ch);curl_close($ch);return json_decode($raw,true)?:['code'=>-2,'message'=>'BADJSON'];}
function v($sql){global $PDO;$r=$PDO->query($sql)->fetch(PDO::FETCH_NUM);return $r?$r[0]:null;}
function exe($sql){global $PDO;return $PDO->exec($sql);}
function login($u,$p){return (string)(http('POST','/admin/auth/login',['username'=>$u,'password'=>$p])['data']['token']??'');}
function q($sql){global $PDO;return $PDO->query($sql)->fetchAll(PDO::FETCH_ASSOC);}

// 清库白名单（与 PlatformController::CLEANUP_TABLES 一致，42 表）
$CLEAN=['wallets','wallet_transactions','verification_codes','orders','payments','user_collectibles','user_favorites',
'resale_listings','transfers','blind_box_items','blind_boxes','synthesis_records','synthesis_record_items',
'synthesis_materials','synthesis_activities','lucky_draw_records','lucky_draw_prizes','check_in_records',
'invite_records','invite_activities','airdrop_records','airdrop_snapshots','airdrop_eligibilities','airdrop_activities',
'collectibles','banners','announcements','artifacts','community_groups','qualification_whitelists','qualification_configs',
'priority_whitelists','priority_activities','inventory_quotas','destroy_records','refunds','airdrop_tasks',
'blacklist','risk_alerts','security_events','support_tickets','ticket_replies'];
// 受保护表（清库绝不触碰；两张日志表会合理增长，单独断言）
$PROT=['admin_users','admin_roles','admin_permissions','admin_role_permissions','admin_login_logs','admin_operation_logs',
'sms_configs','payment_channels','system_configs','site_settings','categories','platform_cleanup_logs'];
function snap(){global $CLEAN,$PROT;$s=[];foreach(array_merge($CLEAN,$PROT,['users']) as $t){$s[$t]=(int)v("SELECT COUNT(*) FROM nft_$t");}return $s;}

/** 从 DB 哈希行暴力还原 6 位验证码（sha256 无盐，确定性取码，不依赖日志写入时序/轮转） */
function dbCode($phone){
  $h=v("SELECT code FROM nft_verification_codes WHERE phone='$phone' AND scene='platform_cleanup' AND used_at IS NULL ORDER BY id DESC LIMIT 1");
  if(!$h)return null;
  for($i=0;$i<1000000;$i++){
    $c=str_pad((string)$i,6,'0',STR_PAD_LEFT);
    if(hash_equals((string)$h,hash('sha256',$c)))return $c;
  }
  return null;
}
function waitCode($phone,$prev=null){
  for($i=0;$i<25;$i++){
    $c=dbCode($phone);
    if($c!==null && $c!==$prev)return $c;
    usleep(400000);
  }
  return null;
}
/** 全 runtime 日志中查找 mock 渠道发送留痕（多应用隔离：runtime/log 与 runtime/admin/log 都搜） */
function smsLogLine($phone){
  $root=dirname(__DIR__).'/sinan-nft-backend/runtime';
  $files=array_merge(
    glob($root.'/log/*/*.log')?:[],
    glob($root.'/*/log/*/*.log')?:[]
  );
  if(!$files)return null;
  usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));
  foreach($files as $f){
    $c=(string)file_get_contents($f);
    $n=preg_match_all('/\[SMS\]\[mock\] to='.$phone.'[^\r\n]*?验证码：(\d{6})/u',$c,$m);
    if($n)return $m[1][count($m[1])-1];
  }
  return null;
}

echo "=== 7.6.0 环境准备 ===\n";
$tokSuper=login('admin','admin123');
// 四角色测试账号幂等重建（与 t72 一致）
$hash=password_hash('RoleTest#2026',PASSWORD_BCRYPT);
foreach([['operator_admin',2],['finance_admin',3],['risk_admin',4],['support_admin',5]] as $a){
  exe("DELETE FROM nft_admin_users WHERE username='{$a[0]}'");
  exe("INSERT INTO nft_admin_users (username,password_hash,real_name,role_id,status,created_at,updated_at)
       VALUES ('{$a[0]}','$hash','演练',{$a[1]},1,NOW(),NOW())");
}
$tokOp  =login('operator_admin','RoleTest#2026');
$tokFin =login('finance_admin','RoleTest#2026');
$tokRisk=login('risk_admin','RoleTest#2026');
$tokSup =login('support_admin','RoleTest#2026');
T('7.6.0a 五角色登录', $tokSuper&&$tokOp&&$tokFin&&$tokRisk&&$tokSup);

// C 端测试用户（用于 JWT 域隔离探针）
exe("DELETE FROM nft_verification_codes WHERE phone='13800000000' AND scene='platform_cleanup'");
$cp='137'.substr((string)time(),-8);
exe("INSERT INTO nft_verification_codes (phone,scene,code,expires_at,sent_at,ip,created_at) VALUES
     ('$cp','register','".password_hash('654321',PASSWORD_BCRYPT)."','".date('Y-m-d H:i:s',time()+600)."',
      '".date('Y-m-d H:i:s')."','127.0.0.1','".date('Y-m-d H:i:s')."')");
$rc=http('POST','/api/auth/register',['phone'=>$cp,'code'=>'654321','nickname'=>'清库演练']);
$tokC=(string)($rc['data']['token']??'');
T('7.6.0b C 端测试用户注册', $tokC!=='', json_encode($rc,JSON_UNESCAPED_UNICODE));

$existing=array_map('current',q("SHOW TABLES"));
$missingT=[];
foreach($CLEAN as $t)if(!in_array('nft_'.$t,$existing,true))$missingT[]='nft_'.$t;
T('7.6.0c 清库白名单 42 表全部在库', !$missingT, implode(',',$missingT));
$base=snap();
echo "基线: users={$base['users']} orders={$base['orders']} collectibles={$base['collectibles']} admin_users={$base['admin_users']}\n";

echo "\n=== 7.6.1 预览只读与权限隔离 ===\n";
$r=http('GET','/admin/platform/cleanup-preview',null,$tokSuper);
T('7.6.1a 超管预览成功', $r['code']===200 && isset($r['data']['tables']), "code={$r['code']}");
$names=array_column($r['data']['tables']??[],'table');
T('7.6.1b 预览含核心交易表', in_array('nft_orders',$names)&&in_array('nft_collectibles',$names)&&in_array('nft_users（含删除标记）',$names));
T('7.6.1c 预览 totalRows>0', (int)($r['data']['totalRows']??0)>0, 'totalRows='.($r['data']['totalRows']??'-'));
T('7.6.1d 预览声明受保护表', strpos(json_encode($r['data']['protected']??[],JSON_UNESCAPED_UNICODE),'nft_admin_')!==false);
T('7.6.1e 预览提示需短信验证码', ($r['data']['smsRequired']??null)===true);
T('7.6.1f 预览只读零变动', snap()==$base);

$toks=['运营'=>$tokOp,'财务'=>$tokFin,'风控'=>$tokRisk,'客服'=>$tokSup];
foreach($toks as $rn=>$tk){
  $r1=http('GET','/admin/platform/cleanup-preview',null,$tk);
  $r2=http('POST','/admin/platform/cleanup-send-code',[],$tk);
  $r3=http('POST','/admin/platform/cleanup-execute',['code'=>'123456','reason'=>'越权尝试清库'],$tk);
  T("7.6.1g $rn 三端点越权均 4003", $r1['code']===4003&&$r2['code']===4003&&$r3['code']===4003,
    "preview={$r1['code']} send={$r2['code']} exec={$r3['code']}");
}
$r=http('POST','/admin/platform/cleanup-execute',['code'=>'123456','reason'=>'无token尝试'],null);
T('7.6.1h 无 token 4001', $r['code']===4001, $r['code']);
$r=http('POST','/admin/platform/cleanup-execute',['code'=>'123456','reason'=>'伪token尝试'],'fake.token.here');
T('7.6.1i 伪 token 4002', $r['code']===4002, $r['code']);
$r=http('POST','/admin/platform/cleanup-execute',['code'=>'123456','reason'=>'C端token尝试'],$tokC);
T('7.6.1j C 端 token 调管理端 4002', $r['code']===4002, $r['code']);

echo "\n=== 7.6.2 执行前参数校验 ===\n";
$r=http('POST','/admin/platform/cleanup-execute',[],$tokSuper);
T('7.6.2a 缺参拒绝 4220', $r['code']===4220, $r['message']);
$r=http('POST','/admin/platform/cleanup-execute',['code'=>'123456'],$tokSuper);
T('7.6.2b 缺 reason 拒绝 4220', $r['code']===4220);
$r=http('POST','/admin/platform/cleanup-execute',['code'=>'123456','reason'=>'清库'],$tokSuper);
T('7.6.2c reason<5字拒绝', $r['code']===4220 && strpos($r['message'],'5')!==false, $r['message']);
$r=http('POST','/admin/platform/cleanup-execute',['code'=>'123456','reason'=>'未发码直接执行'],$tokSuper);
T('7.6.2d 未发码执行拒绝', $r['code']===4220, $r['message']);
$r=http('POST','/admin/platform/cleanup-send-code',['phone'=>'13900000000'],$tokSuper);
T('7.6.2e 非绑定手机号拒绝', $r['code']===4220 && strpos($r['message'],'绑定')!==false, $r['message']);
$r=http('POST','/admin/platform/cleanup-send-code',[],$tokSuper);
T('7.6.2f 绑定手机号发码成功（脱敏回显）', $r['code']===200 && strpos((string)($r['data']['phone']??''),'****')!==false,
  json_encode($r['data']??[],JSON_UNESCAPED_UNICODE));
$r=http('POST','/admin/platform/cleanup-send-code',[],$tokSuper);
T('7.6.2g 60s 内重发频控', $r['code']===4220 && strpos($r['message'],'频繁')!==false, $r['message']);

echo "\n=== 7.6.3 错码拒绝与备份失败阻断（F7-D7）===\n";
$code1=waitCode('13800000000');
T('7.6.3a 验证码可从 DB 哈希还原（sha256 暴力核验）', $code1!==null && strlen((string)$code1)===6, "code1=$code1");
$wrong=($code1==='123456')?'654321':'123456';
$r=http('POST','/admin/platform/cleanup-execute',['code'=>$wrong,'reason'=>'错码执行尝试'],$tokSuper);
T('7.6.3b 错误验证码拒绝', $r['code']===4220 && strpos($r['message'],'验证码错误')!==false, $r['message']);

// F7-D7：mysqldump 临时失效 → 备份失败必须阻断清库
// 阻断场景基线：7.6.2f 发码插入的验证码行属测试自身合法写入（发码业务），不属被阻断清库的变动
$blkBase=snap();
rename('/usr/bin/mysqldump','/usr/bin/mysqldump.bak');
$r=http('POST','/admin/platform/cleanup-execute',['code'=>$code1,'reason'=>'备份失败应阻断清库演练'],$tokSuper);
rename('/usr/bin/mysqldump.bak','/usr/bin/mysqldump');
T('7.6.3c 备份失败阻断清库返回 5000', $r['code']===5000 && strpos($r['message'],'阻断')!==false,
  json_encode($r,JSON_UNESCAPED_UNICODE));
// 阻断后零变动：业务/受保护数据表必须原样（对照阻断前基线）；审计日志增长是正确行为（阻断留痕），单独断言
$blkOk=true;$blkDiff=[];
foreach(array_merge($CLEAN,['users']) as $t){$c=(int)v("SELECT COUNT(*) FROM nft_$t");if($c!==$blkBase[$t]){$blkOk=false;$blkDiff[]="nft_$t:{$blkBase[$t]}->$c";}}
foreach(array_diff($PROT,['platform_cleanup_logs','admin_operation_logs']) as $t){
  $c=(int)v("SELECT COUNT(*) FROM nft_$t");if($c!==$blkBase[$t]){$blkOk=false;$blkDiff[]="nft_$t:{$blkBase[$t]}->$c";}
}
T('7.6.3d 阻断后业务与受保护表零变动', $blkOk, implode(';',$blkDiff));
$blkLog=(int)v("SELECT COUNT(*) FROM nft_admin_operation_logs");
T('7.6.3d-2 阻断审计新增留痕', $blkLog>$base['admin_operation_logs'], "{$base['admin_operation_logs']}->$blkLog");
$blk=v("SELECT action_desc FROM nft_admin_operation_logs WHERE module='platform' AND action='cleanup_execute' ORDER BY id DESC LIMIT 1");
T('7.6.3e 阻断审计留痕', strpos((string)$blk,'阻断')!==false, (string)$blk);

echo "\n=== 7.6.4 四重确认正式清库 ===\n";
$r=http('POST','/admin/platform/cleanup-send-code',[],$tokSuper);
T('7.6.4a 旧码已核销可立即重发', $r['code']===200, json_encode($r,JSON_UNESCAPED_UNICODE));
$code2=waitCode('13800000000',$code1);
T('7.6.4b 新验证码下发且不同于旧码', $code2!==null && $code2!==$code1, "code2=$code2");
$activeUsers=(int)v("SELECT COUNT(*) FROM nft_users WHERE deleted_at IS NULL");
$ordersN=(int)v("SELECT COUNT(*) FROM nft_orders");
$r=http('POST','/admin/platform/cleanup-execute',['code'=>$code2,'reason'=>'F7-6集成测试清库演练'],$tokSuper);
T('7.6.4c 四重确认后清库执行成功', $r['code']===200, json_encode($r,JSON_UNESCAPED_UNICODE));
$bp=(string)($r['data']['backupPath']??'');
T('7.6.4d 返回有效备份路径', strpos($bp,'.sql')!==false && strpos($bp,'失败')===false, $bp);
T('7.6.4e 影响用户数与基线一致', (int)($r['data']['affectedUsers']??-1)===$activeUsers,
  "resp=".($r['data']['affectedUsers']??'?')." base=$activeUsers");
T('7.6.4f 影响订单数与基线一致', (int)($r['data']['affectedOrders']??-1)===$ordersN,
  "resp=".($r['data']['affectedOrders']??'?')." base=$ordersN");

$zero=true;$nz=[];
foreach($CLEAN as $t){$c=(int)v("SELECT COUNT(*) FROM nft_$t");if($c>0){$zero=false;$nz[]="nft_$t=$c";}}
T('7.6.4g 白名单 42 表全部清零', $zero, implode(';',$nz));
T('7.6.4h users 物理清空（含软删）', (int)v('SELECT COUNT(*) FROM nft_users')===0);
$protOk=true;$diff=[];
foreach(array_diff($PROT,['platform_cleanup_logs','admin_operation_logs']) as $t){
  $c=(int)v("SELECT COUNT(*) FROM nft_$t");
  if($c!==$base[$t]){$protOk=false;$diff[]="nft_$t:{$base[$t]}->$c";}
}
T('7.6.4i 受保护 10 表原样未动', $protOk, implode(';',$diff));
T('7.6.4j 清库日志新增 1 条', (int)v('SELECT COUNT(*) FROM nft_platform_cleanup_logs')===$base['platform_cleanup_logs']+1);
$log=q("SELECT * FROM nft_platform_cleanup_logs ORDER BY id DESC LIMIT 1")[0]??[];
T('7.6.4k 清库日志字段完整（status/admin_id/备份/原因/影响面）',
  ($log['status']??0)==1 && (int)($log['admin_id']??0)===1 && ($log['admin_name']??'')!=='' && !empty($log['backup_path'])
  && ($log['reason']??'')!=='' && (int)($log['affected_users']??-1)===$activeUsers && (int)($log['affected_orders']??-1)===$ordersN,
  json_encode($log,JSON_UNESCAPED_UNICODE));
$aud=(int)v("SELECT COUNT(*) FROM nft_admin_operation_logs WHERE module='platform' AND action='cleanup_send_code'");
T('7.6.4l 发码审计留痕（≥3 次）', $aud>=3, "count=$aud");
$aud2=v("SELECT action_desc FROM nft_admin_operation_logs WHERE module='platform' AND action='cleanup_execute' ORDER BY id DESC LIMIT 1");
T('7.6.4m 执行审计留痕（原因/影响/备份）', strpos((string)$aud2,'执行平台清库')!==false && strpos((string)$aud2,'备份')!==false, (string)$aud2);

echo "\n=== 7.6.5 验证码一次性与备份可恢复性 ===\n";
$r=http('POST','/admin/platform/cleanup-execute',['code'=>$code2,'reason'=>'验证码一次性核验尝试'],$tokSuper);
T('7.6.5a 验证码不可复用（清库后码表已清）', $r['code']===4220, $r['message']);
T('7.6.5b 备份文件存在且>100KB', $bp!=='' && is_file($bp) && filesize($bp)>100000, "$bp ".(is_file($bp)?filesize($bp):'-').'B');
$dump=$bp!==''?(string)file_get_contents($bp):'';
T('7.6.5c 备份含全量结构与数据（users/admin_users）',
  strpos($dump,'CREATE TABLE `nft_users`')!==false && strpos($dump,'INSERT INTO `nft_users`')!==false
  && strpos($dump,'CREATE TABLE `nft_admin_users`')!==false);
$out=(string)shell_exec('mysql -h127.0.0.1 -usinan -psinan123456 sinan_nft < '.escapeshellarg($bp).' 2>&1');
$rest=snap();$ok=true;$diff2=[];
// 备份为清库执行前瞬间快照：演练自身产生的验证码行与审计留痕合法包含在备份内（≥基线即可）
$volatile=['verification_codes','admin_operation_logs','platform_cleanup_logs','admin_login_logs'];
foreach($rest as $t=>$c){
  if(in_array($t,$volatile,true)){
    if((int)$c<(int)$base[$t]){$ok=false;$diff2[]="nft_$t:{$base[$t]}->$c(应≥基线)";}
  }elseif((int)$base[$t]!==(int)$c){$ok=false;$diff2[]="nft_$t:{$base[$t]}->$c";}
}
T('7.6.5d 备份恢复后业务/受保护表回基线（环境复原）', $ok && strpos($out,'ERROR')===false,
  implode(';',array_slice($diff2,0,5)).($out?' | mysql:'.trim($out):''));
T('7.6.5e 恢复后超管可登录', login('admin','admin123')!=='');
sleep(1);
T('7.6.5f mock 渠道发送留痕在应用日志（多 runtime 全查）', smsLogLine('13800000000')!==null);

echo "\n==== F7-6 结果: PASS $pass / FAIL $fail ====\n";
if($fails){echo "失败项:\n".implode("\n",$fails)."\n";exit(1);}
exit(0);
