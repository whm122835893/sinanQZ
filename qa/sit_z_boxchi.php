<?php
/** Z3 盲盒概率卡方检验（1000 次模拟，大纲一-7）
 *  取一个已配置概率的盲盒，用 PHP 模拟 1000 次开盒
 *  按配置概率做卡方检验，α=0.01 临界值
 *  χ²(0.01,3)=11.345, χ²(0.01,4)=13.277
 */
date_default_timezone_set('Asia/Shanghai');
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pass=0;$fail=0;
function T($n,$c,$d=''){global $pass,$fail;$c?$pass++:$fail++;echo($c?"  PASS ":"  FAIL ").$n.($d?" | $d":"")."\n";}
function q1($s){global $PDO;$r=$PDO->query($s);return $r?$r->fetchColumn():null;}
function q($s){global $PDO;return $PDO->query($s)->fetchAll(PDO::FETCH_ASSOC);}

echo "=== Z3 盲盒概率卡方检验（1000 次模拟）===\n";
// 找一个可开的盲盒
$box=q("SELECT id,collectible_id FROM nft_blind_boxes WHERE is_openable=1 LIMIT 1");
if(empty($box)){T('Z3-0 找到可用盲盒', false, '无可用盲盒');echo "PASS: $pass FAIL: $fail\n";exit(1);}
$box=$box[0];
echo "  盲盒 id={$box['id']} collectible_id={$box['collectible_id']}\n";

// 读取盲盒配置概率
$items=q("SELECT id,prize_collectible_id,probability FROM nft_blind_box_items WHERE blind_box_id={$box['id']} AND deleted_at IS NULL");
$probs=[];$totalP=0;
foreach($items as $it){
  $p=(float)$it['probability']*100; // 0.xxxx → 百分比
  $probs[$it['prize_collectible_id']]=$p;
  $totalP+=$p;
}
// 补足 100%（概率和 <100% 时剩余为"空奖"）
if($totalP<100){$probs[0]=100-$totalP;} // 0=空奖
echo "  配置概率: ";
foreach($probs as $cid=>$p) echo "cid=$cid(".round($p,1)."%) ";
echo " sum=".round($totalP,1)."%\n";

if(count($probs)<2){T('Z3-1 概率桶 ≥ 2',false,'桶数不足');echo "PASS: $pass FAIL: $fail\n";exit(1);}

// 1000 次模拟
$N=1000;
$observed=[];
foreach(array_keys($probs) as $cid) $observed[$cid]=0;
for($i=0;$i<$N;$i++){
  $r=mt_rand(1,10000)/100.0; // 0~100
  $cum=0;$hit=0;
  foreach($probs as $cid=>$p){
    $cum+=$p;
    if($r<=$cum){$hit=$cid;break;}
  }
  $observed[$hit]++;
}
echo "  实际分布: ";
foreach($observed as $cid=>$o) echo "cid=$cid(o=$o,".round($o/$N*100,1)."%) ";
echo "\n";

// 卡方检验（仅 E>=5 的桶）
$chi2=0;$df=0;
foreach($probs as $cid=>$p){
  $E=$N*$p/100;
  $O=$observed[$cid]??0;
  if($E>=5){$chi2+=($O-$E)**2/$E;$df++;}
}
$df=max(1,$df-1);
$crit001=[1=>6.635,2=>9.210,3=>11.345,4=>13.277,5=>15.086,6=>16.812,7=>18.475,8=>20.090,9=>21.666,10=>23.209];
$crit=$crit001[$df]??25;
echo "  χ²=".round($chi2,2)." df=$df 临界(α=0.01)=$crit\n";
T('Z3-2 概率分布无显著偏差（χ²<临界）', $chi2<$crit, "χ²=".round($chi2,2)."<=$crit");
T('Z3-3 概率之和≤100%', $totalP<=100.01, "sum=".round($totalP,2)."%");

echo "\n================ 汇总 ================\n";
echo "PASS: $pass  FAIL: $fail\n";
exit($fail>0?1:0);
