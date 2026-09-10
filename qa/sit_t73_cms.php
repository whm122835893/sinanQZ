<?php
/** F7-3 管理端内容配置（CMS）集成测试
 *  覆盖：
 *    7.3.1 权限探针（cms:* 归属 super_admin/operator，其余 4003）
 *    7.3.2 轮播图：增改删/toggle 启停/排序/C 端实时生效
 *    7.3.3 公告：draft→published 生命周期/定时发布/置顶/软删/XSS 消毒五连
 *    7.3.4 官方社群：增改删/启停/C 端可见性
 *    7.3.5 文物展馆：必填校验/JSON 字段/隐藏/C 端列表详情/软删
 *    7.3.6 协议管理：白名单键/空内容拒绝/XSS 消毒（F7-D3）
 *    7.3.7 站点装修：白名单保存/颜色 HEX 校验/圆角范围/非白名单键跳过/C 端 config 实时生效
 */
date_default_timezone_set('Asia/Shanghai');
$BASE='http://127.0.0.1:8301';
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING]);
$pass=0;$fail=0;$fails=[];
function T($n,$c,$d=''){global $pass,$fail,$fails;$c?$pass++:$fail++;if(!$c)$fails[]=$n;echo($c?"  PASS ":"  FAIL ").$n.($d?" | $d":"")."\n";}
function http($m,$u,$b=null,$t=null){global $BASE;$ch=curl_init($BASE.$u);$h=['Content-Type: application/json'];if($t)$h[]="Authorization: Bearer $t";
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$h,CURLOPT_POSTFIELDS=>json_encode($b?:[])]);$raw=curl_exec($ch);curl_close($ch);return json_decode($raw,true)?:['code'=>-2,'message'=>'BADJSON'];}
function v($sql){global $PDO;$r=$PDO->query($sql)->fetch(PDO::FETCH_NUM);return $r?$r[0]:null;}

echo "=== 7.3.0 环境准备 ===\n";
// 幂等重建 4 角色账号（超管用内置 admin/admin123）
$hash=password_hash('RoleTest#2026',PASSWORD_BCRYPT);
foreach([['operator_admin','运营',2],['finance_admin','财务',3],['risk_admin','风控',4],['support_admin','客服',5]] as $a){
  $PDO->exec("DELETE FROM nft_admin_users WHERE username='{$a[0]}'");
  $PDO->exec("INSERT INTO nft_admin_users (username,password_hash,real_name,role_id,status,created_at,updated_at)
    VALUES ('{$a[0]}','$hash','{$a[1]}',{$a[2]},1,NOW(),NOW())");
}
function adminLogin($u,$p){$r=http('POST','/admin/auth/login',['username'=>$u,'password'=>$p]);return (string)($r['data']['token']??'');}
$tokSuper=adminLogin('admin','admin123');
$tokOp    =adminLogin('operator_admin','RoleTest#2026');
$tokFin   =adminLogin('finance_admin','RoleTest#2026');
$tokRisk  =adminLogin('risk_admin','RoleTest#2026');
$tokSup   =adminLogin('support_admin','RoleTest#2026');
T('7.3.0 五角色登录', $tokSuper&&$tokOp&&$tokFin&&$tokRisk&&$tokSup);

echo "\n=== 7.3.1 权限探针（cms:* → super_admin/operator）===\n";
$r=http('GET','/admin/cms/banners',null,$tokSuper); T('7.3.1a 超管 banners 列表', $r['code']===200);
$r=http('GET','/admin/cms/banners',null,$tokOp);   T('7.3.1b 运营 banners 列表', $r['code']===200);
$r=http('GET','/admin/cms/banners',null,$tokFin);  T('7.3.1c 财务被拒 4003', $r['code']===4003, 'code='.$r['code']);
$r=http('GET','/admin/cms/announcements',null,$tokRisk); T('7.3.1d 风控被拒 4003', $r['code']===4003, 'code='.$r['code']);
$r=http('GET','/admin/cms/community',null,$tokSup); T('7.3.1e 客服被拒 4003', $r['code']===4003, 'code='.$r['code']);
$r=http('GET','/admin/cms/banners'); T('7.3.1f 无 token 4001', $r['code']===4001, 'code='.$r['code']);
$cTok=@file_get_contents('/tmp/sit_tokens.json');
$cTok=$cTok?json_decode($cTok,true)['token']??'':'';
if($cTok){$r=http('GET','/admin/cms/banners',null,$cTok);T('7.3.1g C 端 token 隔离 4001/4002', in_array($r['code'],[4001,4002]),'code='.$r['code']);}

echo "\n=== 7.3.2 轮播图管理 ===\n";
$b1=json_decode(http('POST','/admin/cms/banners',['image'=>'https://img.example.com/b1.jpg','sort_order'=>10,'description'=>'测试轮播一','is_active'=>1],$tokSuper)['data']['id']??'0',true)?:0;
T('7.3.2a 创建轮播图', $b1>0, "id=$b1");
$r=http('POST','/admin/cms/banners',['sort_order'=>1],$tokSuper);
T('7.3.2b 缺 image 拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('POST','/admin/cms/banners',['image'=>'ftp://bad.example.com/x.jpg'],$tokSuper);
T('7.3.2c 非法 image 格式拒绝', $r['code']===4220, 'code='.$r['code']);
$b2=(int)(http('POST','/admin/cms/banners',['image'=>'/static/b2.png','sort_order'=>20,'is_active'=>1],$tokSuper)['data']['id']??0);
$r=http('PUT',"/admin/cms/banners/$b1",['description'=>'更新后的描述','sort_order'=>5],$tokSuper);
T('7.3.2d 编辑轮播图', $r['code']===200);
$ids=array_column(http('GET','/api/banners')['data']??[],'id');
T('7.3.2e C 端可见两条', in_array($b1,$ids)&&in_array($b2,$ids));
$list=http('GET','/api/banners')['data']??[];
$pos1=array_search($b1,array_column($list,'id'));$pos2=array_search($b2,array_column($list,'id'));
T('7.3.2f C 端按 sort_order 排序', $pos1!==false&&$pos2!==false&&$pos1<$pos2, "p1=$pos1 p2=$pos2");
$r=http('POST',"/admin/cms/banners/$b1/toggle",[],$tokSuper);
T('7.3.2g 停用 toggle', $r['code']===200 && (int)($r['data']['is_active']??-1)===0);
$ids=array_column(http('GET','/api/banners')['data']??[],'id');
T('7.3.2h 停用后 C 端不可见', !in_array($b1,$ids));
$r=http('POST',"/admin/cms/banners/$b1/toggle",[],$tokSuper);
$ids=array_column(http('GET','/api/banners')['data']??[],'id');
T('7.3.2i 重新启用后可见', $r['code']===200 && in_array($b1,$ids));
$r=http('DELETE',"/admin/cms/banners/$b2",null,$tokSuper);
T('7.3.2j 软删轮播图', $r['code']===200);
$ids=array_column(http('GET','/api/banners')['data']??[],'id');
T('7.3.2k 软删后 C 端不可见', !in_array($b2,$ids));
$r=http('DELETE',"/admin/cms/banners/$b2",null,$tokSuper);
T('7.3.2l 重复删除 4040', $r['code']===4040, 'code='.$r['code']);

echo "\n=== 7.3.3 公告管理与 XSS 过滤 ===\n";
$xss='<script>alert(1)</script><img src=x onerror=alert(2)><div onmouseover="alert(3)">段落</div><a href="javascript:alert(4)">点我</a><iframe src="https://evil.com/x"></iframe><b>加粗</b>正常文字';
$a1=(int)(http('POST','/admin/cms/announcements',['title'=>'XSS消毒测试公告','type'=>'notice','content'=>$xss,'status'=>'draft'],$tokSuper)['data']['id']??0);
T('7.3.3a 创建 draft 公告', $a1>0, "id=$a1");
$db=v("SELECT content FROM nft_announcements WHERE id=$a1");
T('7.3.3b DB 层 script 标签被移除', strpos($db,'<script')===false);
T('7.3.3c DB 层 onerror 事件属性被移除', stripos($db,'onerror')===false);
T('7.3.3d DB 层 onmouseover 事件属性被移除', stripos($db,'onmouseover')===false);
T('7.3.3e DB 层 javascript: 协议被移除', stripos($db,'javascript:')===false);
T('7.3.3f DB 层 iframe 标签被移除', strpos($db,'<iframe')===false);
T('7.3.3g 正常富文本保留（<b>）', strpos($db,'<b>加粗</b>')!==false);
$ids=array_column(http('GET','/api/announcements')['data']['list']??[],'id');
T('7.3.3h draft C 端列表不可见', !in_array($a1,$ids));
$r=http('GET',"/api/announcements/$a1");
T('7.3.3i draft C 端详情不可见', $r['code']!==200, 'code='.$r['code']);
http('PUT',"/admin/cms/announcements/$a1",['status'=>'published'],$tokSuper);
$ids=array_column(http('GET','/api/announcements')['data']['list']??[],'id');
T('7.3.3j published 后 C 端可见', in_array($a1,$ids));
$c=http('GET',"/api/announcements/$a1")['data']['content']??'';
T('7.3.3k C 端详情无 script/onerror', strpos($c,'<script')===false && stripos($c,'onerror')===false);
T('7.3.3l C 端详情无 javascript:/iframe', stripos($c,'javascript:')===false && strpos($c,'<iframe')===false);
$a2=(int)(http('POST','/admin/cms/announcements',['title'=>'news 类型公告','type'=>'news','content'=>'<p>新闻内容</p>','status'=>'published'],$tokSuper)['data']['id']??0);
$newsIds=array_column(http('GET','/api/announcements?type=news')['data']['list']??[],'id');
T('7.3.3m C 端 type=news 筛选', in_array($a2,$newsIds) && !in_array($a1,$newsIds));
$noticeIds=array_column(http('GET','/api/announcements?type=notice')['data']['list']??[],'id');
T('7.3.3n C 端 type=notice 筛选', in_array($a1,$noticeIds));
http('PUT',"/admin/cms/announcements/$a1",['publish_time'=>date('Y-m-d H:i:s',time()+3600)],$tokSuper);
$ids=array_column(http('GET','/api/announcements')['data']['list']??[],'id');
$r=http('GET',"/api/announcements/$a1");
T('7.3.3o 定时发布未来时间不可见', !in_array($a1,$ids) && $r['code']!==200);
http('PUT',"/admin/cms/announcements/$a1",['publish_time'=>''],$tokSuper);
$ids=array_column(http('GET','/api/announcements')['data']['list']??[],'id');
T('7.3.3p 清除定时后可见', in_array($a1,$ids));
http('POST',"/admin/cms/announcements/$a1/toggle-top",[],$tokSuper);
$list=http('GET','/api/announcements')['data']['list']??[];
T('7.3.3q 置顶后排第一', !empty($list) && (int)$list[0]['id']===$a1 && $list[0]['isTop']===true);
http('POST',"/admin/cms/announcements/$a1/toggle-top",[],$tokSuper);
http('PUT',"/admin/cms/announcements/$a1",['content'=>'<script>update()</script><u>二次注入</u>'],$tokSuper);
$db=v("SELECT content FROM nft_announcements WHERE id=$a1");
T('7.3.3r 编辑二次注入仍消毒', strpos($db,'<script')===false && strpos($db,'<u>二次注入</u>')!==false);
$r=http('POST','/admin/cms/announcements',['title'=>'非法类型','type'=>'blog','content'=>'x'],$tokSuper);
T('7.3.3s type 白名单拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('POST','/admin/cms/announcements',['title'=>'短','type'=>'notice','content'=>'x'],$tokSuper);
T('7.3.3t 标题过短拒绝', $r['code']===4220, 'code='.$r['code']);
http('DELETE',"/admin/cms/announcements/$a2",null,$tokSuper);
$ids=array_column(http('GET','/api/announcements')['data']['list']??[],'id');
$r=http('GET',"/api/announcements/$a2");
T('7.3.3u 软删后 C 端列表+详情均不可见', !in_array($a2,$ids) && $r['code']!==200);

echo "\n=== 7.3.4 官方社群 ===\n";
$g1=(int)(http('POST','/admin/cms/community',['name'=>'司南官方测试群','icon'=>'/img/community.png','description'=>'官方交流','members'=>666,'sort'=>1],$tokSuper)['data']['id']??0);
T('7.3.4a 创建社群', $g1>0, "id=$g1");
$r=http('POST','/admin/cms/community',['name'=>'短','icon'=>'/x.png'],$tokSuper);
T('7.3.4b 名称过短拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('POST','/admin/cms/community',['name'=>'无图标群'],$tokSuper);
T('7.3.4c 缺 icon 拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('PUT',"/admin/cms/community/$g1",['members'=>888,'description'=>'更新描述'],$tokSuper);
T('7.3.4d 更新社群', $r['code']===200);
$grp=http('GET',"/admin/cms/community",null,$tokSuper)['data']??[];
$mine=array_values(array_filter($grp,fn($x)=>(int)$x['id']===$g1));
T('7.3.4e 管理端回读 members=888', !empty($mine) && (int)$mine[0]['members']===888);
$ids=array_column(http('GET','/api/community/groups')['data']??[],'id');
T('7.3.4f C 端可见', in_array($g1,$ids));
http('PUT',"/admin/cms/community/$g1",['is_active'=>0],$tokSuper);
$ids=array_column(http('GET','/api/community/groups')['data']??[],'id');
T('7.3.4g 停用后 C 端不可见', !in_array($g1,$ids));
http('PUT',"/admin/cms/community/$g1",['is_active'=>1],$tokSuper);
$ids=array_column(http('GET','/api/community/groups')['data']??[],'id');
T('7.3.4h 启用后恢复可见', in_array($g1,$ids));
http('DELETE',"/admin/cms/community/$g1",null,$tokSuper);
$ids=array_column(http('GET','/api/community/groups')['data']??[],'id');
T('7.3.4i 软删后 C 端不可见', !in_array($g1,$ids));
$r=http('PUT',"/admin/cms/community/$g1",['members'=>1],$tokSuper);
T('7.3.4j 软删数据不可更新 4040', $r['code']===4040, 'code='.$r['code']);

echo "\n=== 7.3.5 文物展馆 ===\n";
$ar1=(int)(http('POST','/admin/cms/artifacts',['name'=>'青花纹瓶','dynasty'=>'明','image'=>'/img/artifact.png','material'=>'瓷器','story'=>'<script>bad()</script>明代青花','status'=>1],$tokSuper)['data']['id']??0);
T('7.3.5a 创建文物', $ar1>0, "id=$ar1");
$r=http('POST','/admin/cms/artifacts',['name'=>'无朝代','image'=>'/x.png','material'=>'铜'],$tokSuper);
T('7.3.5b 缺 dynasty 拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('POST','/admin/cms/artifacts',['name'=>'坏JSON','dynasty'=>'清','image'=>'/x.png','material'=>'玉','specs'=>'not-json'],$tokSuper);
T('7.3.5c specs 非法 JSON 拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('POST','/admin/cms/artifacts',['name'=>'好JSON','dynasty'=>'宋','image'=>'/x.png','material'=>'木','specs'=>'["高30cm","口径8cm"]','tags'=>'["宋代","木器"]'],$tokSuper);
T('7.3.5d 合法 JSON 字段通过', $r['code']===200, 'code='.$r['code']);
$ar2=(int)($r['data']['id']??0);
http('PUT',"/admin/cms/artifacts/$ar1",['status'=>0],$tokSuper);
$ids=array_column(http('GET','/api/artifacts')['data']['list']??http('GET','/api/artifacts')['data']??[],'id');
T('7.3.5e 隐藏后 C 端列表不可见', !in_array($ar1,$ids));
$r=http('GET',"/api/artifacts/$ar1");
T('7.3.5f 隐藏后 C 端详情不可见', $r['code']!==200, 'code='.$r['code']);
http('PUT',"/admin/cms/artifacts/$ar1",['status'=>1],$tokSuper);
$ids=array_column(http('GET','/api/artifacts')['data']['list']??http('GET','/api/artifacts')['data']??[],'id');
T('7.3.5g 恢复展示后可见', in_array($ar1,$ids));
$r=http('PUT',"/admin/cms/artifacts/$ar1",['story'=>'更新故事<iframe src=x></iframe>'],$tokSuper);
$db=v("SELECT story FROM nft_artifacts WHERE id=$ar1");
T('7.3.5h 文物 story 按原文存储（前端文本渲染）', $r['code']===200 && strpos($db,'<iframe')!==false, 'story 字段为纯文本语义，管理端不做 HTML 消毒');
http('DELETE',"/admin/cms/artifacts/$ar2",null,$tokSuper);
$r=http('GET',"/api/artifacts/$ar2");
T('7.3.5i 软删后 C 端详情不可见', $r['code']!==200, 'code='.$r['code']);

echo "\n=== 7.3.6 协议管理 ===\n";
$r=http('GET','/admin/cms/agreements',null,$tokSuper);
$keys=array_column($r['data']??[],'key');
T('7.3.6a 协议列表含三键', count(array_intersect(['agreement_user','agreement_privacy','agreement_digital'],$keys))===3);
$r=http('PUT','/admin/cms/agreements/bad_key',['content'=>'x'],$tokSuper);
T('7.3.6b 非白名单键拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('PUT','/admin/cms/agreements/agreement_user',['content'=>''],$tokSuper);
T('7.3.6c 空内容拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('PUT','/admin/cms/agreements/agreement_user',['content'=>str_repeat('长',100001)],$tokSuper);
T('7.3.6d 超长内容拒绝', $r['code']===4220, 'code='.$r['code']);
http('PUT','/admin/cms/agreements/agreement_user',['content'=>'<b>测试协议正文</b>'],$tokSuper);
$db=v("SELECT setting_value FROM nft_site_settings WHERE setting_key='agreement_user'");
T('7.3.6e 协议内容保存', strpos($db,'<b>测试协议正文</b>')!==false);
http('PUT','/admin/cms/agreements/agreement_user',['content'=>'<script>alert(9)</script><u>协议XSS</u>'],$tokSuper);
$db=v("SELECT setting_value FROM nft_site_settings WHERE setting_key='agreement_user'");
T('7.3.6f 协议富文本 XSS 消毒（F7-D3）', strpos($db,'<script')===false && strpos($db,'<u>协议XSS</u>')!==false, 'db='.mb_substr((string)$db,0,60));

echo "\n=== 7.3.7 站点装修 ===\n";
$orig=[];
foreach(q0("SELECT setting_key,setting_value FROM nft_site_settings WHERE setting_key IN ('site_name','theme_color','button_radius','bg_color')") as $row)$orig[$row['setting_key']]=$row['setting_value'];
$r=http('POST','/admin/cms/decoration',['settings'=>['site_name'=>'司南珍藏装修测试','theme_color'=>'#C00001','button_radius'=>'12','bg_color'=>'#F0F0F0']],$tokSuper);
T('7.3.7a 保存装修配置', $r['code']===200);
$cfg=http('GET','/api/config')['data']['site']??[];
T('7.3.7b C 端 config 实时生效 siteName', ($cfg['siteName']??'')==='司南珍藏装修测试');
T('7.3.7c C 端 config 主题色生效', ($cfg['themeColor']??'')==='#C00001');
T('7.3.7d C 端 config 圆角生效', (int)($cfg['buttonRadius']??0)===12);
$r=http('POST','/admin/cms/decoration',['settings'=>['theme_color'=>'red']],$tokSuper);
T('7.3.7e 非法 HEX 颜色拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('POST','/admin/cms/decoration',['settings'=>['button_radius'=>'25']],$tokSuper);
T('7.3.7f 圆角超范围拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('POST','/admin/cms/decoration',['settings'=>['button_radius'=>'abc']],$tokSuper);
T('7.3.7g 圆角非数字拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('POST','/admin/cms/decoration',['settings'=>['hack_key'=>'evil']],$tokSuper);
T('7.3.7h 纯非白名单键拒绝保存', $r['code']===4220, 'code='.$r['code']);
$cnt=(int)v("SELECT COUNT(*) FROM nft_site_settings WHERE setting_key='hack_key'");
T('7.3.7i 非白名单键未落库', $cnt===0);
// 恢复原值
$restore=['site_name'=>$orig['site_name']??'','theme_color'=>$orig['theme_color']??'','button_radius'=>$orig['button_radius']??'','bg_color'=>$orig['bg_color']??''];
http('POST','/admin/cms/decoration',['settings'=>$restore],$tokSuper);
$cfg=http('GET','/api/config')['data']['site']??[];
T('7.3.7j 恢复默认站点名', ($cfg['siteName']??'')!=='' && $cfg['siteName']!=='司南珍藏装修测试');

echo "\n========== 汇总：PASS=$pass FAIL=$fail ==========\n";
if($fails){echo "失败清单：\n".implode("\n",$fails)."\n";}
function q0($sql){global $PDO;return $PDO->query($sql)->fetchAll(PDO::FETCH_ASSOC);}
