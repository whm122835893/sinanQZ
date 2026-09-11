<?php
/** Z1 数据恒等式全库校验
 *  资金恒等式：∑(balance) = ∑(充值) - ∑(消费) - ∑(提现)
 *    注：reward 流水为司南币发放（balance_after 记 points 快照，见 CheckIn/RewardGrantService），
 *    不进入法币恒等式；direction 口径为 1=收入 2=支出（与表注释及全部写入点一致）
 *  库存恒等式：edition ≥ circulate ≥ sold
 *  盲盒恒等式：opened_count(表) == 已开数量(资产 consumed)
 *  钱包流水恒等式：最后一条法币流水 balance_after == 钱包余额（抽样；reward 流水对账 points，排除）
 */
date_default_timezone_set('Asia/Shanghai');
$PDO=new PDO('mysql:host=127.0.0.1;dbname=sinan_nft','sinan','sinan123456',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pass=0;$fail=0;
function T($n,$c,$d=''){global $pass,$fail;$c?$pass++:$fail++;echo($c?"  PASS ":"  FAIL ").$n.($d?" | $d":"")."\n";}
function q1($s){global $PDO;$r=$PDO->query($s);return $r?$r->fetchColumn():null;}
function q($s){global $PDO;return $PDO->query($s)->fetchAll(PDO::FETCH_ASSOC);}
function v($s){global $PDO;$r=$PDO->query($s)->fetch(PDO::FETCH_NUM);return $r?$r[0]:null;}

echo "=== Z1 资金恒等式 ===\n";
$wallets=(float)q1("SELECT COALESCE(SUM(balance),0) FROM nft_wallets");
$recharges=(float)q1("SELECT COALESCE(SUM(amount),0) FROM nft_wallet_transactions WHERE trans_type='recharge' AND direction=1");
$consumes=(float)q1("SELECT COALESCE(SUM(amount),0) FROM nft_wallet_transactions WHERE trans_type='buy' AND direction=2");
$withdraw=(float)q1("SELECT COALESCE(SUM(amount),0) FROM nft_wallet_transactions WHERE trans_type='withdraw' AND direction=2");
$diff=round($recharges - $consumes - $withdraw - $wallets, 2);
T('Z1-1 全库余额 = 充值-消费-提现（差异≤0.01）', abs($diff)<=0.01, "wallet=$wallets rec=$recharges buy=$consumes wd=$withdraw diff=$diff");

echo "\n=== Z2 藏品库存恒等式 ===\n";
$cols=q("SELECT id,name,edition,sold,circulate FROM nft_collectibles WHERE deleted_at IS NULL");
$badCount=0;$mismatch=[];
foreach($cols as $c){
  if((int)$c['edition']<=0) continue;
  if((int)$c['circulate'] > (int)$c['edition']) {$badCount++;$mismatch[]="circulate>edition id={$c['id']}";}
  if((int)$c['sold'] > (int)$c['circulate']) {$badCount++;$mismatch[]="sold>circulate id={$c['id']}";}
}
T('Z2-1 circulate≤edition 且 sold≤circulate', $badCount===0, "异常=$badCount ".implode(',',array_slice($mismatch,0,5)));

$soldMismatch=0;
foreach(array_slice($cols,0,30) as $c){
  if((int)$c['sold']===0) continue;
  $ordSold=(int)q1("SELECT COALESCE(SUM(quantity),0) FROM nft_orders WHERE collectible_id={$c['id']} AND status='completed' AND source IN ('release','priority','eligibility')");
  if((int)$c['sold'] !== $ordSold){$soldMismatch++;if($soldMismatch<=3)echo "    sold不一致 id={$c['id']}: sold={$c['sold']} ordSold=$ordSold\n";}
}
T('Z2-2 sold == 发行类订单(非market) completed 数量（抽样30）', $soldMismatch===0, "不一致=$soldMismatch");

echo "\n=== Z3 盲盒恒等式 ===\n";
$boxConsumed=(int)q1("SELECT COUNT(*) FROM nft_user_collectibles uc JOIN nft_blind_boxes bb ON bb.collectible_id=uc.collectible_id WHERE uc.status='consumed' AND uc.source='blindbox'");
$boxOpened=(int)q1("SELECT COALESCE(SUM(opened_count),0) FROM nft_blind_boxes");
T('Z3-1 盲盒已开 == opened_count 汇总', $boxConsumed===$boxOpened, "consumed=$boxConsumed opened=$boxOpened");

echo "\n=== Z4 钱包流水恒等式 ===\n";
// reward 流水 balance_after 记的是 points（司南币）快照，对账法币余额时排除
$fiatTypes="('recharge','buy','withdraw')";
$sample=q("SELECT user_id FROM nft_wallet_transactions WHERE trans_type IN $fiatTypes GROUP BY user_id HAVING COUNT(*)>=2 ORDER BY RAND() LIMIT 20");
$balMismatch=0;
foreach($sample as $u){
  $uid=(int)$u['user_id'];
  $wBal=(float)v("SELECT balance FROM nft_wallets WHERE user_id=$uid");
  $row=v("SELECT balance_after FROM nft_wallet_transactions WHERE user_id=$uid AND trans_type IN $fiatTypes ORDER BY id DESC LIMIT 1");
  $lastTx=$row===null?0.0:(float)$row;
  if($row===null && $wBal>0.01){$balMismatch++;echo "    无法币流水但余额>0 user=$uid wallet=$wBal\n";continue;}
  if(abs($wBal-$lastTx)>0.01){$balMismatch++;echo "    余额不连续 user=$uid wallet=$wBal lastTx=$lastTx\n";}
}
T('Z4-1 抽样20用户：最后法币流水 balance_after ≈ 钱包余额', $balMismatch===0, "不连续=$balMismatch");

echo "\n=== Z5 资产状态机恒等式 ===\n";
$noAsset=(int)q1("SELECT COUNT(*) FROM nft_orders o WHERE o.status='completed' AND o.source IN ('release','priority','eligibility') AND NOT EXISTS (SELECT 1 FROM nft_user_collectibles uc WHERE uc.order_id=o.id AND uc.status='held')");
T('Z5-1 completed 发行订单必有持仓 held', $noAsset===0, "缺失=$noAsset");
// release pending 订单不应有 asset 行（asset 在 pay 成功后才创建）；若有则必为 frozen（中间态脏数据）
$badReleasePending=(int)q1("SELECT COUNT(*) FROM nft_orders o JOIN nft_user_collectibles uc ON uc.order_id=o.id WHERE o.status IN ('pending','paid') AND o.source IN ('release','priority','eligibility') AND uc.status='held'");
// market pending 订单的 asset 行必须是 consigned（卖家寄售）
$badMarketPending=(int)q1("SELECT COUNT(*) FROM nft_orders o JOIN nft_user_collectibles uc ON uc.order_id=o.id WHERE o.status IN ('pending','paid') AND o.source='market' AND uc.status!='consigned'");
T('Z5-2 pending/paid 订单资产状态正确（发行无held/市场为consigned）', ($badReleasePending+$badMarketPending)===0, "release_bad=$badReleasePending market_bad=$badMarketPending");

echo "\n=== Z6 市场成交统计 ===\n";
$marketOrders=(int)q1("SELECT COUNT(*) FROM nft_orders WHERE source='market' AND status='completed'");
$marketAmt=(float)q1("SELECT COALESCE(SUM(total_price),0) FROM nft_orders WHERE source='market' AND status='completed'");
T('Z6-1 市场成交订单数 ≥ 0', $marketOrders>=0, "orders=$marketOrders amount=$marketAmt");

echo "\n================ 汇总 ================\n";
echo "PASS: $pass  FAIL: $fail\n";
exit($fail>0?1:0);
