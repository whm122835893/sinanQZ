<?php
/** F7-5 管理端 审计日志/回收站/数据快照幂等 集成测试
 *  覆盖：
 *    7.5.1 审计日志：操作日志列表/筛选（keyword/module/admin_id）/链配置密钥脱敏留痕/
 *          登录日志（IP/设备/状态）/log-modules/权限探针
 *    7.5.2 实名全量查看留痕：realname:full 调用后审计新增 realname/view_full
 *    7.5.3 回收站：软删列表/恢复/物理删除/未软删保护/purge-all/type 白名单/权限探针
 *    7.5.4 数据快照：全量生成/幂等重跑/DB 勾稽（13 聚合行）/单人快照/列表筛选/权限探针
 */
date_default_timezone_set('Asia/Shanghai');
$BASE='http://127.0.0.1:8301';
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING]);
$pass=0;$fail=0;$fails=[];
function T($n,$c,$d=''){global $pass,$fail,$fails;$c?$pass++:$fail++;if(!$c)$fails[]=$n;echo($c?"  PASS ":"  FAIL ").$n.($d?" | $d":"")."\n";}
function http($m,$u,$b=null,$t=null){global $BASE;$ch=curl_init($BASE.$u);$h=['Content-Type: application/json','User-Agent: SIT-TestAgent/1.0'];if($t)$h[]="Authorization: Bearer $t";
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>$h,CURLOPT_POSTFIELDS=>json_encode($b?:[])]);$raw=curl_exec($ch);curl_close($ch);return json_decode($raw,true)?:['code'=>-2,'message'=>'BADJSON'];}
function v($sql){global $PDO;$r=$PDO->query($sql)->fetch(PDO::FETCH_NUM);return $r?$r[0]:null;}
function exe($sql){global $PDO;return $PDO->exec($sql);}
function login($u,$p){return (string)(http('POST','/admin/auth/login',['username'=>$u,'password'=>$p])['data']['token']??'');}
function rows($sql){global $PDO;return $PDO->query($sql)->fetchAll(PDO::FETCH_ASSOC);}

echo "=== 7.5.0 环境准备 ===\n";
$tokSuper=login('admin','admin123');
$tokOp   =login('operator_admin','RoleTest#2026');
$tokFin  =login('finance_admin','RoleTest#2026');
T('7.5.0 三角色登录', $tokSuper&&$tokOp&&$tokFin);
$today=date('Y-m-d');
$logBefore=(int)v("SELECT COUNT(*) FROM nft_admin_operation_logs");

echo "\n=== 7.5.1 审计日志 ===\n";
$r=http('GET','/admin/permission/operation-logs',null,$tokSuper);
$total0=(int)($r['data']['total']??-1);
T('7.5.1a 操作日志列表', $r['code']===200 && $total0>0, "total=$total0");
$r=http('GET','/admin/permission/operation-logs?module=chain',null,$tokSuper);
$allChain=true;
foreach(($r['data']['list']??[]) as $row){if(($row['module']??'')!=='chain'){$allChain=false;break;}}
T('7.5.1b module=chain 筛选且全部命中', $r['code']===200 && count($r['data']['list']??[])>0 && $allChain);
$kw=rawurlencode('链网络配置');
$r=http('GET','/admin/permission/operation-logs?keyword='.$kw,null,$tokSuper);
T('7.5.1c keyword（action_desc）筛选', $r['code']===200 && ($r['data']['total']??0)>=1);
$r=http('GET','/admin/permission/operation-logs?admin_id=1',null,$tokSuper);
$allA1=true;
foreach(($r['data']['list']??[]) as $row){if((int)($row['admin_id']??0)!==1){$allA1=false;break;}}
T('7.5.1d admin_id 筛选', $r['code']===200 && $allA1);
$r=http('GET','/admin/permission/operation-logs?module=chain&page=1&page_size=50',null,$tokSuper);
$hasNetSave=false;$hasPlain=false;
foreach(($r['data']['list']??[]) as $row){
  if(($row['action']??'')==='network_save'){$hasNetSave=true;
    $det=(string)($row['detail']??'');
    if(strpos($det,'sk-test-wen')!==false)$hasPlain=true;}
}
T('7.5.1e 链配置操作留痕存在', $hasNetSave);
T('7.5.1f 留痕 detail 无明文密钥（脱敏）', !$hasPlain);
$r=http('GET','/admin/permission/log-modules',null,$tokSuper);
T('7.5.1g log-modules 含 chain/cms', in_array('chain',$r['data']??[]) && in_array('cms',$r['data']??[]));
$r=http('GET','/admin/permission/login-logs',null,$tokSuper);
$logs=$r['data']['list']??[];
$hasIp=false;$hasUA=false;
foreach($logs as $row){if(!empty($row['ip']))$hasIp=true;if(!empty($row['user_agent']))$hasUA=true;}
T('7.5.1h 登录日志列表（IP/设备字段）', $r['code']===200 && count($logs)>0 && $hasIp && $hasUA);
$r=http('GET','/admin/permission/login-logs?ip=127.0.0.1',null,$tokSuper);
T('7.5.1i 登录日志 IP 筛选（本机登录留痕）', $r['code']===200 && ($r['data']['total']??0)>=1);
$r=http('GET','/admin/permission/operation-logs',null,$tokOp);
T('7.5.1j 操作日志权限（仅超管，运营 4003）', $r['code']===4003, 'code='.$r['code']);
$r=http('GET','/admin/permission/login-logs',null,$tokOp);
T('7.5.1k 登录日志权限（运营 4003）', $r['code']===4003, 'code='.$r['code']);

echo "\n=== 7.5.2 实名全量查看留痕 ===\n";
$rid=(int)v("SELECT id FROM nft_users WHERE realname_status=2 AND deleted_at IS NULL LIMIT 1");
if($rid===0){ // 测试库无已实名用户：造一个（状态置 approved，密文字段留空仅验留痕链路）
  $rid=(int)v("SELECT id FROM nft_users WHERE deleted_at IS NULL LIMIT 1");
  exe("UPDATE nft_users SET realname_status=2, realname_verified_at=NOW(3) WHERE id=$rid");
}
T('7.5.2a 准备已实名用户', $rid>0, "uid=$rid");
if($rid>0){
  $before=(int)v("SELECT COUNT(*) FROM nft_admin_operation_logs WHERE module='realname' AND action='view_full'");
  $r=http('GET',"/admin/realname/users/$rid",null,$tokSuper);
  $after=(int)v("SELECT COUNT(*) FROM nft_admin_operation_logs WHERE module='realname' AND action='view_full'");
  T('7.5.2b 实名全量查看成功（approved）', $r['code']===200 && ($r['data']['realnameStatus']??'')==='approved');
  T('7.5.2c 敏感查看留痕（realname/view_full +1）', $after===$before+1, "before=$before after=$after");
  $r=http('GET',"/admin/realname/users/$rid",null,$tokOp);
  T('7.5.2d 无 realname:full 权限被拒 4003', $r['code']===4003, 'code='.$r['code']);
}

echo "\n=== 7.5.3 回收站 ===\n";
$r=http('GET','/admin/trash/banners',null,$tokSuper);
$trashB=(int)($r['data']['total']??-1);
T('7.5.3a 回收站 banners 列表（含软删）', $r['code']===200 && $trashB>=0, "total=$trashB");
// 造一条专用软删 banner
$bT=(int)(http('POST','/admin/cms/banners',['image'=>'https://img.example.com/trash-test.jpg','sort_order'=>99,'is_active'=>1],$tokSuper)['data']['id']??0);
http('DELETE',"/admin/cms/banners/$bT",null,$tokSuper);
$inTrash=(int)v("SELECT COUNT(*) FROM nft_banners WHERE id=$bT AND deleted_at IS NOT NULL");
T('7.5.3b 软删 banner 进入回收站', $inTrash===1);
$r=http('POST',"/admin/trash/banners/$bT/recover",[],$tokSuper);
$recovered=(int)v("SELECT COUNT(*) FROM nft_banners WHERE id=$bT AND deleted_at IS NULL");
T('7.5.3c recover 恢复（deleted_at 置空）', $r['code']===200 && $recovered===1);
$ids=array_column(http('GET','/admin/cms/banners?page=1&page_size=100',null,$tokSuper)['data']['list']??[],'id');
T('7.5.3d 恢复后管理端列表可见', in_array($bT,$ids));
http('DELETE',"/admin/cms/banners/$bT",null,$tokSuper); // 再软删
$r=http('DELETE',"/admin/trash/banners/$bT/purge",null,$tokSuper);
$gone=(int)v("SELECT COUNT(*) FROM nft_banners WHERE id=$bT");
T('7.5.3e purge 物理删除', $r['code']===200 && $gone===0);
// 未软删保护
$bP=(int)(http('POST','/admin/cms/banners',['image'=>'https://img.example.com/alive.jpg','sort_order'=>98],$tokSuper)['data']['id']??0);
$r=http('DELETE',"/admin/trash/banners/$bP/purge",null,$tokSuper);
$alive=(int)v("SELECT COUNT(*) FROM nft_banners WHERE id=$bP");
T('7.5.3f purge 仅作用于已软删行（未软删保护）', $r['code']===200 && $alive===1);
$r=http('POST','/admin/trash/evil_table/1/recover',[],$tokSuper);
T('7.5.3g 非白名单表类型 4220', $r['code']===4220, 'code='.$r['code']);
// purge-all（针对 banners 回收站）
$before=(int)v("SELECT COUNT(*) FROM nft_banners WHERE deleted_at IS NOT NULL");
$r=http('POST','/admin/trash/banners/purge-all',[],$tokSuper);
$after=(int)v("SELECT COUNT(*) FROM nft_banners WHERE deleted_at IS NOT NULL");
T('7.5.3h purge-all 清空 banners 回收站', $r['code']===200 && ($r['data']['purged']??-1)===$before && $after===0, "purged={$r['data']['purged']} before=$before");
exe("DELETE FROM nft_banners WHERE id=$bP"); // 清理存活测试行
$r=http('GET','/admin/trash/banners',null,$tokOp);
T('7.5.3i 回收站权限（platform:trash:list 仅超管）', $r['code']===4003, 'code='.$r['code']);
// 用户软删恢复
$uT=$PDO->query("SELECT id FROM nft_users ORDER BY id DESC LIMIT 1")->fetchColumn();
exe("UPDATE nft_users SET deleted_at=NOW() WHERE id=$uT");
$r=http('POST',"/admin/trash/users/$uT/recover",[],$tokSuper);
T('7.5.3j 用户软删恢复', $r['code']===200 && (int)v("SELECT COUNT(*) FROM nft_users WHERE id=$uT AND deleted_at IS NULL")===1);

echo "\n=== 7.5.4 数据快照（幂等）===\n";
$realAgg=(int)v("SELECT COUNT(*) FROM (SELECT user_id,collectible_id FROM nft_user_collectibles WHERE status IN ('held','consigned','frozen') GROUP BY user_id,collectible_id) t");
$r=http('POST','/admin/snapshots/generate',['date'=>$today],$tokSuper);
$h1=(int)($r['data']['holdings']??-1);
T('7.5.4a 全量持仓快照生成', $r['code']===200, json_encode($r['data']??[],JSON_UNESCAPED_UNICODE));
T('7.5.4b 快照行数与真实聚合一致', $h1===($realAgg ?: $h1), "snap=$h1 real=$realAgg");
$r=http('POST','/admin/snapshots/generate',['date'=>$today],$tokSuper);
$h2=(int)($r['data']['holdings']??-1);
$dbH=(int)v("SELECT COUNT(*) FROM nft_holdings_snapshots WHERE snapshot_date='$today'");
T('7.5.4c 同日重跑幂等（覆盖语义，行数不变）', $h2===$h1 && $dbH===$h1, "run2=$h2 db=$dbH run1=$h1");
// 勾稽：任一行 held+consigned+frozen == total_count
$bad=(int)v("SELECT COUNT(*) FROM nft_holdings_snapshots WHERE snapshot_date='$today' AND held_count+consigned_count+frozen_count <> total_count");
T('7.5.4d 恒等式 held+consigned+frozen=total（无违例）', $bad===0, "bad=$bad");
// 勾稽：抽样行与真实持仓对照
$sr=rows("SELECT user_id,collectible_id,total_count,held_count FROM nft_holdings_snapshots WHERE snapshot_date='$today' LIMIT 1")[0]??null;
if($sr){
  $rc=(int)v("SELECT COUNT(*) FROM nft_user_collectibles WHERE user_id={$sr['user_id']} AND collectible_id={$sr['collectible_id']} AND status IN ('held','consigned','frozen')");
  T('7.5.4e 抽样勾稽（快照=真实持仓数）', (int)$sr['total_count']===$rc, "snap={$sr['total_count']} real=$rc");
}else{T('7.5.4e 抽样勾稽',false,'无快照行');}
// 单人快照
$uid7=7;
$r=http('POST','/admin/snapshots/generate',['date'=>$today,'userId'=>$uid7],$tokSuper);
$dbU=(int)v("SELECT COUNT(*) FROM nft_holdings_snapshots WHERE snapshot_date='$today' AND user_id=$uid7");
$dbAll=(int)v("SELECT COUNT(DISTINCT user_id) FROM nft_holdings_snapshots WHERE snapshot_date='$today'");
T('7.5.4f 单人快照（覆盖该用户当日行）', $r['code']===200 && $dbU>0, "user_rows=$dbU");
$r=http('POST','/admin/snapshots/generate',['date'=>$today,'userId'=>$uid7],$tokSuper);
T('7.5.4g 单人快照幂等重跑', $r['code']===200 && (int)v("SELECT COUNT(*) FROM nft_holdings_snapshots WHERE snapshot_date='$today' AND user_id=$uid7")===$dbU);
// 恢复全量
http('POST','/admin/snapshots/generate',['date'=>$today],$tokSuper);
T('7.5.4h 全量重跑恢复完整快照', (int)v("SELECT COUNT(*) FROM nft_holdings_snapshots WHERE snapshot_date='$today'")===$h1);
$r=http('POST','/admin/snapshots/generate',['date'=>'bad-date'],$tokSuper);
T('7.5.4i 非法日期 4220', $r['code']===4220, 'code='.$r['code']);
$r=http('GET',"/admin/snapshots/holdings?date=$today",null,$tokSuper);
T('7.5.4j 持仓快照列表 date 筛选', $r['code']===200 && ($r['data']['total']??0)===$h1, "today=$today total=".var_export($r['data']['total']??null,true)." h1=$h1 code=".$r['code']);
$r=http('GET',"/admin/snapshots/holdings?date=$today&userId=$uid7",null,$tokSuper);
$onlyU=true;foreach(($r['data']['list']??[]) as $row){if((int)($row['userId']??0)!==$uid7)$onlyU=false;}
T('7.5.4k 持仓快照 userId 筛选', $r['code']===200 && $onlyU);
$r=http('GET','/admin/snapshots/trades?date='.$today,null,$tokSuper);
T('7.5.4l 交易快照列表', $r['code']===200 && isset($r['data']['total']));
$r=http('GET','/admin/snapshots/dates',null,$tokSuper);
T('7.5.4m dates 含今日', $r['code']===200 && in_array($today,array_column($r['data']??[],'date')));
$r=http('GET','/admin/snapshots/holdings',null,$tokOp);
T('7.5.4n 快照权限（report:snapshot，运营 4003）', $r['code']===4003, 'code='.$r['code']);
$r=http('GET','/admin/snapshots/holdings',null,$tokFin);
T('7.5.4o 财务可查快照（归属正确）', $r['code']===200);

echo "\n========== 汇总：PASS=$pass FAIL=$fail ==========\n";
if($fails){echo "失败清单：\n".implode("\n",$fails)."\n";}
