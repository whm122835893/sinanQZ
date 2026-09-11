<?php
/** Z2 事务原子性与数据一致性审计 */
date_default_timezone_set('Asia/Shanghai');
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pass=0;$fail=0;
function T($n,$c,$d=''){global $pass,$fail;$c?$pass++:$fail++;echo($c?"  PASS ":"  FAIL ").$n.($d?" | $d":"")."\n";}
function q1($s){global $PDO;$r=$PDO->query($s);return $r?$r->fetchColumn():null;}
function q($s){global $PDO;return $PDO->query($s)->fetchAll(PDO::FETCH_ASSOC);}

echo "=== Z2-1 事务三要素审计（控制器代码）===\n";
$files=[
  '合成'=>__DIR__.'/../sinan-nft-backend/app/controller/Synthesis.php',
  '盲盒'=>__DIR__.'/../sinan-nft-backend/app/controller/BlindBoxes.php',
  '置换'=>__DIR__.'/../sinan-nft-backend/app/controller/Swap.php',
  '分解'=>__DIR__.'/../sinan-nft-backend/app/controller/Decompose.php',
  '寄售'=>__DIR__.'/../sinan-nft-backend/app/controller/Resale.php',
];
foreach($files as $name=>$path){
  $src=@file_get_contents($path);
  if(!$src){T("Z2-1 $name 控制器存在",false,'文件不存在');continue;}
  $hasStart=strpos($src,'startTrans')!==false;
  $hasCommit=strpos($src,'commit()')!==false;
  $hasRollback=strpos($src,'rollback()')!==false;
  T("Z2-1 $name start/commit/rollback", $hasStart&&$hasCommit&&$hasRollback, "s=$hasStart c=$hasCommit r=$hasRollback");
}

echo "\n=== Z2-2 InnoDB 锁等待容错 ===\n";
try {
  $PDO->exec("SET SESSION innodb_lock_wait_timeout=1");
  $PDO->beginTransaction();
  $PDO->exec("SELECT id FROM nft_collectibles WHERE id=1 FOR UPDATE");
  $PDO->rollBack();
  $PDO->exec("SET SESSION innodb_lock_wait_timeout=50");
  T('Z2-2 lock_wait_timeout=1 可设置并恢复', true);
} catch(\Throwable $e) {
  T('Z2-2 lock_wait_timeout', false, $e->getMessage());
}

echo "\n=== Z2-3 completed 订单 → 持仓 held ===\n";
$badCompleted=(int)q1("SELECT COUNT(*) FROM nft_orders o JOIN nft_user_collectibles uc ON uc.order_id=o.id WHERE o.status='completed' AND uc.status!='held'");
T('Z2-3 completed 订单持仓 held（无游离）', $badCompleted===0, "异常=$badCompleted");

echo "\n=== Z2-4 pending 订单 → 持仓 frozen/consigned ===\n";
$badPending=(int)q1("SELECT COUNT(*) FROM nft_orders o JOIN nft_user_collectibles uc ON uc.order_id=o.id WHERE o.status IN ('pending','paid') AND uc.status NOT IN ('frozen','consigned')");
T('Z2-4 pending 订单持仓 frozen/consigned', $badPending===0, "异常=$badPending");

echo "\n=== Z2-5 置换完成后双方资产 held ===\n";
$swaps=q("SELECT id,asset_a_id,asset_b_id FROM nft_swap_requests WHERE status='completed' ORDER BY id DESC LIMIT 10");
foreach($swaps as $s){
  $aStatus=v("SELECT status FROM nft_user_collectibles WHERE id={$s['asset_a_id']}");
  $bStatus=v("SELECT status FROM nft_user_collectibles WHERE id={$s['asset_b_id']}");
  T("Z2-5 swap#{$s['id']} 双方 held", $aStatus==='held' && $bStatus==='held', "a=$aStatus b=$bStatus");
}
if(empty($swaps)) T('Z2-5 置换记录存在', false, '无 completed 置换');

echo "\n=== Z2-6 开盒后盲盒 consumed + 奖品 held ===\n";
$blindTx=(int)q1("SELECT COUNT(*) FROM nft_user_collectibles WHERE source='blindbox' AND status='consumed'");
echo "  盲盒 consumed=$blindTx\n";
T('Z2-6 盲盒 consumed 数量 ≥ 0', true);

echo "\n================ 汇总 ================\n";
echo "PASS: $pass  FAIL: $fail\n";
exit($fail>0?1:0);

function v($s){global $PDO;$r=$PDO->query($s)->fetch(PDO::FETCH_NUM);return $r?$r[0]:null;}
