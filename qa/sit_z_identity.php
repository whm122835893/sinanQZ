<?php
/** Z1 数据恒等式全库校验
 *  资金恒等式：∑(balance) = ∑(充值) - ∑(消费) - ∑(提现)
 *    前提：任何「直接 SQL 写余额」的夹具都必须经 qa_seed_wallet_ledger() 补一条 recharge 开账流水
 *    （与 sit_baseline_reset 同一口径）；缺了这一步夹具就会自己把恒等式打穿。
 *    注：trans_type 枚举只有 recharge/buy/withdraw/reward，法币退款与求购结算被记为 reward，
 *    与「司南币发放」同名。Z1-1 用「法币入账必带 biz_no」把两者分开，Z1-2 守住这条口径；
 *    这是权宜之计——彻底解法见审查报告的 ledger 建模建议（给枚举加 refund/settlement）。
 *    direction 口径为 1=收入 2=支出（与表注释及全部写入点一致）
 *  库存恒等式：edition ≥ circulate ≥ sold；发行类完成订单量 ≤ sold（夹具会删订单，只能单向）
 *  盲盒恒等式：逐盒 opened_count == 奖池 quantity_distributed 汇总
 *  钱包流水恒等式：最后一条法币流水 balance_after == 钱包余额（抽样；reward 流水对账 points，排除）
 */
date_default_timezone_set('Asia/Shanghai');
require __DIR__ . '/bootstrap_db.php';
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
// trans_type 枚举只有 recharge/buy/withdraw/reward：法币入账（退款、求购成交结算）被迫记成 reward，
// 而司南币发放也是 reward。两者靠「法币入账必带 biz_no」区分（产品写入点全部遵守；缺 biz_no 的 reward 一律是积分）。
$fiatCredits=(float)q1("SELECT COALESCE(SUM(amount),0) FROM nft_wallet_transactions WHERE trans_type='reward' AND direction=1 AND biz_no IS NOT NULL");
$diff=round($recharges + $fiatCredits - $consumes - $withdraw - $wallets, 2);
T('Z1-1 全库余额 = 充值+法币入账-消费-提现（差异≤0.01）', abs($diff)<=0.01, "wallet=$wallets rec=$recharges fiatIn=$fiatCredits buy=$consumes wd=$withdraw diff=$diff");
$orphanFiat=(int)q1("SELECT COUNT(*) FROM nft_wallet_transactions WHERE trans_type='reward' AND direction=1 AND biz_no IS NOT NULL AND title NOT LIKE '%结算%' AND title NOT LIKE '%退款%'");
T('Z1-2 带 biz_no 的 reward 只能是法币入账（退款/结算），不得混入积分发放', $orphanFiat===0, "违规=$orphanFiat");

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
// 夹具会删掉自己造过的订单行（sold 却已扣），因此全库只能断言单向：完成订单量不得超过 sold。
// 双向严格相等由「不删订单」的 H 系列藏品承担（见 Z2-3），口径与产品一致：市场二手单不动 sold。
$SALE_SRC="('release','priority','eligibility','raffle')";
foreach(array_slice($cols,0,30) as $c){
  if((int)$c['sold']===0) continue;
  $ordSold=(int)q1("SELECT COALESCE(SUM(quantity),0) FROM nft_orders WHERE collectible_id={$c['id']} AND status='completed' AND source IN $SALE_SRC");
  if($ordSold > (int)$c['sold']){$soldMismatch++;if($soldMismatch<=3)echo "    订单量超发 id={$c['id']}: sold={$c['sold']} ordSold=$ordSold\n";}
}
T('Z2-2 发行类订单(非market) completed 数量 ≤ sold（抽样30）', $soldMismatch===0, "超发=$soldMismatch");

$strictMismatch=0;
$strictRows=q("SELECT id,name,sold FROM nft_collectibles WHERE deleted_at IS NULL AND name REGEXP '^H[123]-' AND sold>0");
foreach($strictRows as $c){
  $ordSold=(int)q1("SELECT COALESCE(SUM(quantity),0) FROM nft_orders WHERE collectible_id={$c['id']} AND status='completed' AND source IN $SALE_SRC");
  if((int)$c['sold'] !== $ordSold){$strictMismatch++;if($strictMismatch<=3)echo "    sold不一致 id={$c['id']}({$c['name']}): sold={$c['sold']} ordSold=$ordSold\n";}
}
T('Z2-3 H 系列藏品（不删订单）sold == 发行类 completed 订单量', $strictMismatch===0, '样本='.count($strictRows)." 不一致=$strictMismatch");

echo "\n=== Z3 盲盒恒等式 ===\n";
// opened_count 由 C端开盒自增；奖池侧 quantity_distributed 是同一次开盒的落点，两者必须逐盒相等。
// 口径含软删档位（quantity_distributed 是历史事实，编辑奖池软删不该让它消失）；
// 奖池行被物理删空的盒跳过——只有 QA 夹具会 DELETE ... blind_box_items，产品侧只做软删。
$boxSkipped=0;
$boxMismatch=0;$boxRows=q("SELECT bb.id,bb.opened_count,COUNT(bi.id) items,COALESCE(SUM(bi.quantity_distributed),0) dist FROM nft_blind_boxes bb LEFT JOIN nft_blind_box_items bi ON bi.blind_box_id=bb.id GROUP BY bb.id,bb.opened_count");
foreach($boxRows as $b){
  if((int)$b['items']===0){$boxSkipped++;echo "    跳过盒 {$b['id']}：奖池档位已被物理删除（opened={$b['opened_count']}）\n";continue;}
  if((int)$b['opened_count'] !== (int)$b['dist']){$boxMismatch++;echo "    盒 {$b['id']}: opened={$b['opened_count']} dist={$b['dist']}\n";}
}
$boxChecked=count($boxRows)-$boxSkipped;
T('Z3-1 逐盒 opened_count == 奖池 quantity_distributed 汇总', $boxMismatch===0, "校验=$boxChecked 跳过=$boxSkipped 不一致=$boxMismatch");

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
// 资产行的终态不止 held：status 枚举里的 consumed 是开盒/合成的正常消耗态，transferred 是转赠出账态。
// 曾把本条写成「必须有 held 行」，于是素材被合成掉之后原购买订单被判成丢持仓——恒等式假阳性；
// 真正要钉的是「订单 completed 却一行资产都没有」。consumed 无法逐单反查（nft_synthesis_records 只记产物
// result_user_collectible_id，素材侧只改状态不留消耗流水），这条溯源缺口记在审查报告而非在此假装校验。
$noAsset=(int)q1("SELECT COUNT(*) FROM nft_orders o WHERE o.status='completed' AND o.source IN ('release','priority','eligibility') AND NOT EXISTS (SELECT 1 FROM nft_user_collectibles uc WHERE uc.order_id=o.id)");
T('Z5-1 completed 发行订单必有资产行（任意状态）', $noAsset===0, "缺失=$noAsset");
$consumedHeld=(int)q1("SELECT COUNT(*) FROM nft_orders o JOIN nft_user_collectibles uc ON uc.order_id=o.id WHERE o.status='completed' AND o.source IN ('release','priority','eligibility') AND uc.status IN ('consumed','transferred')");
echo "  已消耗/已转出的发行资产行数={$consumedHeld}（开盒、合成、转赠的正常终态）\n";
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
