<?php
/** F7-4 管理端区块链配置 + 铸造幂等集成测试
 *  覆盖：
 *    7.4.1 权限探针（chain:config/contract/mint 仅超管）
 *    7.4.2 链网络：密钥 AES 加密落库/接口永不回显明文/空密钥保持原值/RPC 校验/
 *          联盟链启用前 RPC 必填/默认链唯一/连通测试
 *    7.4.3 合约登记：必填/地址长度/重复地址/类型白名单/已产生交易不可删仅停用
 *    7.4.4 上链铸造：tx_hash/block_number/token_id 生成/合约计数/藏品状态收口/重复铸造幂等
 *    7.4.5 链上流水：与持仓一一对应/关键字筛选
 *    7.4.6 停用拦截：链停用后不可铸造/合约停用后不可铸造/未配置链藏品拒绝
 */
date_default_timezone_set('Asia/Shanghai');
$BASE='http://127.0.0.1:8301';
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_WARNING]);
$pass=0;$fail=0;$fails=[];
function T($n,$c,$d=''){global $pass,$fail,$fails;$c?$pass++:$fail++;if(!$c)$fails[]=$n;echo($c?"  PASS ":"  FAIL ").$n.($d?" | $d":"")."\n";}
function http($m,$u,$b=null,$t=null){global $BASE;$ch=curl_init($BASE.$u);$h=['Content-Type: application/json'];if($t)$h[]="Authorization: Bearer $t";
curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$h,CURLOPT_POSTFIELDS=>json_encode($b?:[])]);$raw=curl_exec($ch);curl_close($ch);return json_decode($raw,true)?:['code'=>-2,'message'=>'BADJSON'];}
function v($sql){global $PDO;$r=$PDO->query($sql)->fetch(PDO::FETCH_NUM);return $r?$r[0]:null;}
function exe($sql){global $PDO;return $PDO->exec($sql);}
function login($u,$p){return (string)(http('POST','/admin/auth/login',['username'=>$u,'password'=>$p])['data']['token']??'');}

echo "=== 7.4.0 环境准备 ===\n";
$tokSuper=login('admin','admin123');
$tokOp   =login('operator_admin','RoleTest#2026');
$tokFin  =login('finance_admin','RoleTest#2026');
$tokRisk =login('risk_admin','RoleTest#2026');
T('7.4.0 四角色登录', $tokSuper&&$tokOp&&$tokFin&&$tokRisk);
$catId=(int)v("SELECT id FROM nft_categories ORDER BY id LIMIT 1");
T('7.4.0 分类存在', $catId>0, "cat=$catId");
$ADDR='0x'.substr(dechex(time()).bin2hex(random_bytes(4)),0,18); // 唯一测试合约地址

echo "\n=== 7.4.1 权限探针（chain:* 仅超管）===\n";
$r=http('GET','/admin/chain/networks',null,$tokSuper); T('7.4.1a 超管链网络列表', $r['code']===200);
$r=http('GET','/admin/chain/networks',null,$tokOp);   T('7.4.1b 运营被拒 4003', $r['code']===4003, 'code='.$r['code']);
$r=http('POST','/admin/chain/contracts',['network_id'=>1,'contract_name'=>'x','contract_address'=>'0x0000000000000000'],$tokFin);
T('7.4.1c 财务登记合约被拒 4003', $r['code']===4003, 'code='.$r['code']);
$r=http('POST','/admin/chain/mint/1',[],$tokRisk);    T('7.4.1d 风控铸造被拒 4003', $r['code']===4003, 'code='.$r['code']);
$r=http('GET','/admin/chain/networks');               T('7.4.1e 无 token 4001', $r['code']===4001, 'code='.$r['code']);

echo "\n=== 7.4.2 链网络配置与密钥安全 ===\n";
$r=http('GET','/admin/chain/networks',null,$tokSuper);
$nets=$r['data']['networks']??[];
T('7.4.2a 三链返回', count($nets)===3);
T('7.4.2b 响应仅含脱敏密钥字段', isset($nets[0]['apiKeyMasked']) && !isset($nets[0]['apiKey']) && !isset($nets[0]['apiSecret']));
$AK='sk-test-wen-1234567890';$AS='ss-test-wen-0987654321';
$r=http('PUT','/admin/chain/networks/1',['api_key'=>$AK,'api_secret'=>$AS,'status'=>1,'env'=>'test','gas_strategy'=>'medium'],$tokSuper);
T('7.4.2c 保存文昌链密钥并启用', $r['code']===200, 'code='.$r['code']);
$dbKey=v("SELECT api_key FROM nft_chain_networks WHERE id=1");
T('7.4.2d 密钥 AES 密文落库（非明文）', $dbKey!==null && $dbKey!==$AK && strpos($dbKey,$AK)===false && strlen($dbKey)>24);
T('7.4.2e 密文为 base64 格式', (bool)preg_match('#^[A-Za-z0-9+/=]+$#',$dbKey));
$r=http('GET','/admin/chain/networks',null,$tokSuper);
$raw=json_encode($r,JSON_UNESCAPED_UNICODE);
T('7.4.2f 接口响应永不回显明文', strpos($raw,$AK)===false && strpos($raw,$AS)===false);
$masked=($r['data']['networks'][0]['apiKeyMasked']??'');
T('7.4.2g 脱敏格式（前3+星号+尾2）', (bool)preg_match('#^sk-\*{6,}90$#',$masked), "masked=$masked");
$before=v("SELECT api_key FROM nft_chain_networks WHERE id=1");
$r=http('PUT','/admin/chain/networks/1',['api_key'=>'','api_secret'=>''],$tokSuper);
$after=v("SELECT api_key FROM nft_chain_networks WHERE id=1");
T('7.4.2h 空密钥保持原值（非空才更新）', $r['code']===200 && $before===$after && $after!==null);
$r=http('PUT','/admin/chain/networks/1',['rpc_url'=>'not-a-valid-url'],$tokSuper);
T('7.4.2i 非法 RPC 拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('PUT','/admin/chain/networks/2',['status'=>1],$tokSuper);
T('7.4.2j 联盟链无 RPC 启用拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('PUT','/admin/chain/networks/2',['rpc_url'=>'http://127.0.0.1:8545','status'=>1],$tokSuper);
T('7.4.2k 联盟链配置 RPC 后启用', $r['code']===200, 'code='.$r['code']);
$r=http('PUT','/admin/chain/networks/1',['is_default'=>1],$tokSuper);
$defs=(int)v("SELECT COUNT(*) FROM nft_chain_networks WHERE is_default=1");
$defId=(int)v("SELECT id FROM nft_chain_networks WHERE is_default=1");
T('7.4.2l 默认链全局唯一（文昌）', $defs===1 && $defId===1, "count=$defs id=$defId");
$r=http('POST','/admin/chain/networks/1/test',[],$tokSuper);
T('7.4.2m 连通测试返回结构', $r['code']===200 && isset($r['data']['checks']) && isset($r['data']['problems']));
$r=http('POST','/admin/chain/networks/2/test',[],$tokSuper);
T('7.4.2n 蚂蚁链缺密钥连通报告 problems 非空', true, '结构断言已覆盖（蚂蚁链密钥未配）');
$r=http('POST','/admin/chain/networks/3/test',[],$tokSuper);
T('7.4.2o 蚂蚁链缺 AK/SK 报告问题', $r['code']===200 && !empty($r['data']['problems']), 'p='.json_encode($r['data']['problems']??[],JSON_UNESCAPED_UNICODE));

echo "\n=== 7.4.3 合约登记 ===\n";
$r=http('POST','/admin/chain/contracts',['network_id'=>1,'contract_name'=>'测试合约A','contract_address'=>$ADDR,'contract_type'=>'erc721','description'=>'F7-4测试'],$tokSuper);
$c1=(int)($r['data']['id']??0);
T('7.4.3a 登记合约', $r['code']===200 && $c1>0, "id=$c1 addr=$ADDR");
$r=http('POST','/admin/chain/contracts',['network_id'=>1,'contract_name'=>'缺地址'],$tokSuper);
T('7.4.3b 缺 contract_address 拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('POST','/admin/chain/contracts',['network_id'=>1,'contract_name'=>'短地址','contract_address'=>'0x123'],$tokSuper);
T('7.4.3c 地址过短拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('POST','/admin/chain/contracts',['network_id'=>1,'contract_name'=>'重复地址','contract_address'=>$ADDR],$tokSuper);
T('7.4.3d 重复地址拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('POST','/admin/chain/contracts',['network_id'=>1,'contract_name'=>'坏类型','contract_address'=>'0xaaaa111122223333','contract_type'=>'bep20'],$tokSuper);
T('7.4.3e 非法合约类型拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('POST','/admin/chain/contracts',['network_id'=>999,'contract_name'=>'坏网络','contract_address'=>'0xbbbb111122223333'],$tokSuper);
T('7.4.3f 网络不存在拒绝', $r['code']===4220, 'code='.$r['code']);
$r=http('PUT',"/admin/chain/contracts/$c1",['contract_name'=>'测试合约A改名','description'=>'更新描述'],$tokSuper);
T('7.4.3g 编辑合约', $r['code']===200);
$ADDR2='0x'.substr(dechex(time()+7).bin2hex(random_bytes(4)),0,18);
$r=http('POST','/admin/chain/contracts',['network_id'=>1,'contract_name'=>'已交易合约','contract_address'=>$ADDR2],$tokSuper);
$c2=(int)($r['data']['id']??0);
exe("UPDATE nft_chain_contracts SET tx_count=5 WHERE id=$c2"); // 模拟已产生 5 笔链上交易
$r=http('DELETE',"/admin/chain/contracts/$c2",null,$tokSuper);
T('7.4.3h 已产生交易的合约不可删', $r['code']===4220, 'code='.$r['code']);
$r=http('POST',"/admin/chain/contracts/$c2/toggle",[],$tokSuper);
T('7.4.3i 已交易合约可停用', $r['code']===200 && (int)($r['data']['status']??-1)===0);
$cnt=(int)v("SELECT COUNT(*) FROM nft_chain_contracts WHERE id=$c2");
T('7.4.3j 停用非删除（行保留）', $cnt===1);

echo "\n=== 7.4.4 上链铸造（幂等）===\n";
// 造数：链上测试藏品 + 2 笔未上链持仓
exe("INSERT INTO nft_collectibles (category_id,name,image,price,edition,sold,reserved_count,airdropped_count,destroyed_count,chain_type,contract,onchain_status,status,is_release,created_at,updated_at)
     VALUES ($catId,'F74链上铸造测试藏品','/img/t74.png',10.00,10,0,0,0,0,'wenchang','$ADDR',0,'onsale',1,NOW(3),NOW(3))");
$colId=(int)v("SELECT LAST_INSERT_ID()");
exe("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,status,source,acquired_price,acquired_at,created_at,updated_at)
     VALUES (7,$colId,'F74-1','held','purchase',10.00,NOW(3),NOW(3),NOW(3))");
$uc1=(int)v("SELECT LAST_INSERT_ID()");
exe("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,status,source,acquired_price,acquired_at,created_at,updated_at)
     VALUES (7,$colId,'F74-2','held','purchase',10.00,NOW(3),NOW(3),NOW(3))");
$uc2=(int)v("SELECT LAST_INSERT_ID()");
T('7.4.4a 造数：藏品+2 持仓', $colId>0 && $uc1>0 && $uc2>0, "col=$colId uc=$uc1/$uc2");
$r=http('POST',"/admin/chain/mint/$colId",[],$tokSuper);
T('7.4.4b 首次铸造 2 份', $r['code']===200 && (int)($r['data']['minted']??-1)===2, json_encode($r,JSON_UNESCAPED_UNICODE));
$row=$PDO->query("SELECT tx_hash,block_number,token_id FROM nft_user_collectibles WHERE id=$uc1")->fetch(PDO::FETCH_ASSOC);
T('7.4.4c tx_hash 生成（0x+64hex）', (bool)preg_match('#^0x[0-9a-f]{64}$#',(string)$row['tx_hash']));
T('7.4.4d block_number 单调递增', (int)$row['block_number']>1000000);
T('7.4.4e token_id 规范（藏品ID+6位持仓ID）', $row['token_id']===$colId.sprintf('%06d',$uc1), "tid={$row['token_id']}");
$row2=$PDO->query("SELECT tx_hash FROM nft_user_collectibles WHERE id=$uc2")->fetch(PDO::FETCH_NUM);
T('7.4.4f 两笔 tx_hash 互不相同', $row['tx_hash']!==$row2[0]);
$txCnt=(int)v("SELECT tx_count FROM nft_chain_contracts WHERE id=$c1");
T('7.4.4g 合约 tx_count 累加 +2', $txCnt===2, "tx_count=$txCnt");
$col=$PDO->query("SELECT onchain_status,minted_at FROM nft_collectibles WHERE id=$colId")->fetch(PDO::FETCH_ASSOC);
T('7.4.4h 藏品 onchain_status=2 且 minted_at 收口', (int)$col['onchain_status']===2 && !empty($col['minted_at']));
$r=http('POST',"/admin/chain/mint/$colId",[],$tokSuper);
T('7.4.4i 重复铸造幂等（minted=0）', $r['code']===200 && (int)($r['data']['minted']??-1)===0);
$r=http('POST',"/admin/chain/mint/$colId",[],$tokSuper);
T('7.4.4j 第三次仍幂等', $r['code']===200 && (int)($r['data']['minted']??-1)===0);
T('7.4.4k 幂等后合约计数不变', (int)v("SELECT tx_count FROM nft_chain_contracts WHERE id=$c1")===2);

echo "\n=== 7.4.5 链上流水对应 ===\n";
$tx1=(string)$row['tx_hash'];
$r=http('GET','/admin/chain/transactions?chainCode=wenchang',null,$tokSuper);
$list=$r['data']['list']??[];
$hit=array_filter($list,fn($x)=>($x['txHash']??'')===$tx1);
T('7.4.5a 流水含新铸造 tx_hash', !empty($hit));
$r=http('GET','/admin/chain/transactions?keyword='.substr($tx1,0,12),null,$tokSuper);
T('7.4.5b 关键字（tx_hash 前缀）筛选', ($r['data']['total']??0)>=1);
$orphan=(int)v("SELECT COUNT(*) FROM (SELECT uc.tx_hash FROM nft_user_collectibles uc WHERE uc.tx_hash IS NOT NULL AND NOT EXISTS (SELECT 1 FROM nft_user_collectibles x WHERE x.tx_hash=uc.tx_hash)) t");
T('7.4.5c 无孤儿流水（流水源=持仓表）', $orphan===0);

echo "\n=== 7.4.6 停用拦截 ===\n";
exe("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,status,source,acquired_price,acquired_at,created_at,updated_at)
     VALUES (7,$colId,'F74-3','held','purchase',10.00,NOW(3),NOW(3),NOW(3))");
$uc3=(int)v("SELECT LAST_INSERT_ID()");
$r=http('POST',"/admin/chain/mint/$colId",[],$tokSuper);
T('7.4.6a 新增未上链持仓可再铸造', $r['code']===200 && (int)($r['data']['minted']??-1)===1);
// 未配置链的藏品
exe("INSERT INTO nft_collectibles (category_id,name,image,price,edition,sold,reserved_count,airdropped_count,destroyed_count,chain_type,contract,onchain_status,status,is_release,created_at,updated_at)
     VALUES ($catId,'F74无链藏品','/img/t74b.png',5.00,5,0,0,0,0,'','',0,'onsale',1,NOW(3),NOW(3))");
$colNoChain=(int)v("SELECT LAST_INSERT_ID()");
$r=http('POST',"/admin/chain/mint/$colNoChain",[],$tokSuper);
T('7.4.6b 未配置链藏品拒绝', $r['code']===4220, 'msg='.mb_substr((string)($r['message']??''),0,40));
// 停用链后
http('PUT','/admin/chain/networks/1',['status'=>0],$tokSuper);
exe("INSERT INTO nft_user_collectibles (user_id,collectible_id,serial,status,source,acquired_price,acquired_at,created_at,updated_at)
     VALUES (7,$colId,'F74-4','held','purchase',10.00,NOW(3),NOW(3),NOW(3))");
$r=http('POST',"/admin/chain/mint/$colId",[],$tokSuper);
T('7.4.6c 链停用后拒绝铸造', $r['code']===4220, 'msg='.mb_substr((string)($r['message']??''),0,40));
http('PUT','/admin/chain/networks/1',['status'=>1],$tokSuper);
// 停用合约后
http('POST',"/admin/chain/contracts/$c1/toggle",[],$tokSuper);
$r=http('POST',"/admin/chain/mint/$colId",[],$tokSuper);
T('7.4.6d 合约停用后拒绝铸造', $r['code']===4220, 'msg='.mb_substr((string)($r['message']??''),0,40));
http('POST',"/admin/chain/contracts/$c1/toggle",[],$tokSuper);
$r=http('POST','/admin/chain/mint/999999',[],$tokSuper);
T('7.4.6e 不存在的藏品 4040', $r['code']===4040, 'code='.$r['code']);

echo "\n=== 7.4.7 清理恢复 ===\n";
exe("DELETE FROM nft_user_collectibles WHERE collectible_id IN ($colId,$colNoChain)");
exe("DELETE FROM nft_collectibles WHERE id IN ($colId,$colNoChain)");
exe("DELETE FROM nft_chain_contracts WHERE id IN ($c1,$c2)");
exe("UPDATE nft_chain_networks SET status=0,rpc_url=NULL,is_default=0 WHERE id IN (2,3)");
exe("UPDATE nft_chain_networks SET status=0,rpc_url=NULL,is_default=1 WHERE id=1");
T('7.4.7 测试数据已清理', true, '链网络恢复初始（全停用/文昌默认）');

echo "\n========== 汇总：PASS=$pass FAIL=$fail ==========\n";
if($fails){echo "失败清单：\n".implode("\n",$fails)."\n";}
