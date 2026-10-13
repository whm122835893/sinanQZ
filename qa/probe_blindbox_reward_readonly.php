<?php
/**
 * 盲盒奖励这一路：只读探针（本地沙箱跑，不写库、不发版）。
 *
 * 目的：证明后台「奖励编辑器」新加的盲盒下拉，交出去的字段后端接得住：
 *   1. 下拉的数据源 = 后台「盲盒管理」同一个接口的数据形状（id 来自 blind_boxes，name join 自 collectibles）；
 *   2. 前端存的键名 blindboxId，validate() 认，并且引用校验（checkRef=true，会 SELECT）能在沙箱过；
 *   3. 存进去以后，名单导出文案 rewardLabel() 说的是「盲盒「名字」×N」，不再是光一句「盲盒×1」。
 *
 * 用法：cd sinan-nft-backend && php ../qa/probe_blindbox_reward_readonly.php
 * 全程只读：没有 INSERT/UPDATE/DELETE，也不发放任何资产。
 */
require __DIR__ . '/../sinan-nft-backend/vendor/autoload.php';
$app = new \think\App(__DIR__ . '/../sinan-nft-backend/');
$app->initialize();

use app\service\ActivityRewardService;
use app\service\RewardGrantService;
use think\facade\Db;

$try = static function (string $label, callable $fn): void {
    try {
        $out = $fn();
        echo '  [通过] ' . $label . ' → ' . json_encode($out, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } catch (\Throwable $e) {
        echo '  [报错] ' . $label . ' → ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    }
};

echo '== 1. 下拉数据源：后台盲盒列表接口给的两列（值=id，显示=name）==' . PHP_EOL;
$boxes = Db::name('blind_boxes')->alias('bb')
    ->join('collectibles c', 'c.id = bb.collectible_id')
    ->field('bb.id, bb.collectible_id, c.name, c.status')
    ->whereNull('c.deleted_at')->select()->toArray();
foreach ($boxes as $b) {
    echo '    盲盒 id=' . $b['id'] . ' 名字=「' . $b['name'] . '」 绑定藏品=' . $b['collectible_id']
        . ' 藏品状态=' . $b['status'] . PHP_EOL;
}
echo '  共 ' . count($boxes) . ' 个盲盒' . PHP_EOL;

echo PHP_EOL . '== 2. 前端保存的键名后端认不认（checkRef=true，会真的查引用）==' . PHP_EOL;
$firstId = (int) ($boxes[0]['id'] ?? 0);
$try('选了沙箱里真实存在的盲盒', fn () => RewardGrantService::validate('blindbox', ['blindboxId' => $firstId, 'quantity' => 1], true));
$try('没选具体盲盒（旧前端就是这样）', fn () => RewardGrantService::validate('blindbox', ['quantity' => 1], true));
$try('选了个不存在的盒', fn () => RewardGrantService::validate('blindbox', ['blindboxId' => 999999, 'quantity' => 1], true));
$try('错把藏品 id 当盲盒 id 传（藏品 9608）', fn () => RewardGrantService::validate('blindbox', ['blindboxId' => 9608, 'quantity' => 1], true));

echo PHP_EOL . '== 3. 名单导出文案 ==' . PHP_EOL;
foreach ([
    '选了盒（正常）'      => ['rewardType' => 'blindbox', 'blindboxId' => $firstId, 'quantity' => 2],
    '没选盒（历史数据）'  => ['rewardType' => 'blindbox', 'quantity' => 1],
    '盒被删了'            => ['rewardType' => 'blindbox', 'blindboxId' => 999999, 'quantity' => 1],
    '对照：藏品空投'      => ['rewardType' => 'collectible', 'collectibleId' => 9608, 'quantity' => 1],
] as $label => $reward) {
    echo '    ' . str_pad($label, 22, ' ', STR_PAD_RIGHT) . ' → ' . ActivityRewardService::rewardLabel($reward) . PHP_EOL;
}

echo PHP_EOL . '== 4. 四个活动共用的类型表与标签表 ==' . PHP_EOL;
echo '  RewardGrantService::TYPES      = ' . implode('/', RewardGrantService::TYPES) . PHP_EOL;
echo '  ActivityRewardService::TYPE_LABELS = ' . implode(' / ', array_map(
    static fn ($k, $v) => $k . '→' . $v,
    array_keys(ActivityRewardService::TYPE_LABELS),
    ActivityRewardService::TYPE_LABELS
)) . PHP_EOL;

echo PHP_EOL . '== 5. 存量配置里有没有已经存了 blindbox 的（决定历史数据要不要回填）==' . PHP_EOL;

/**
 * 递归挑出 JSON 里所有「像奖励」的节点：
 * 邀请是按档位嵌数组、签到是按天号嵌数组、注册是单个奖励，深度不定，所以一律递归。
 */
$walkRewards = static function ($node) use (&$walkRewards): array {
    if (!is_array($node)) return [];
    $out = [];
    $type = $node['type'] ?? $node['rewardType'] ?? null;
    if (is_string($type)) $out[] = $node;             // 这一层本身是一条奖励
    foreach ($node as $v) {
        if (is_array($v)) $out = array_merge($out, $walkRewards($v));
    }
    return $out;
};

// 奖励 JSON 落在哪一列，四张表不一样（当场 getFields() 量出来的，不是猜的）：
//   invite_activities.tiers + invite_activities.invitee_reward_config
//   check_in_activities.reward_config
//   register_activities.tiers
//   lucky_draw_prizes.reward_config（一行一个奖项）
$columns = [
    'invite_activities'   => ['tiers', 'invitee_reward_config'],
    'check_in_activities' => ['reward_config'],
    'register_activities' => ['tiers'],
    'lucky_draw_prizes'   => ['reward_config'],
];
foreach ($columns as $table => $cols) {
    $rows   = Db::name($table)->field('id, ' . implode(', ', $cols))->select()->toArray();
    $blind  = 0;
    $withId = 0;
    foreach ($rows as $r) {
        foreach ($cols as $col) {
            $cfg = json_decode((string) ($r[$col] ?? ''), true);
            if (!is_array($cfg)) continue;
            foreach ($walkRewards($cfg) as $rw) {
                $t = (string) ($rw['type'] ?? $rw['rewardType'] ?? '');
                if ($t !== 'blindbox') continue;
                $blind++;
                if ((int) ($rw['blindboxId'] ?? 0) > 0) $withId++;
            }
        }
    }
    echo '    ' . str_pad($table, 22, ' ', STR_PAD_RIGHT)
        . ' 记录 ' . count($rows) . ' 行；盲盒奖励项 ' . $blind . ' 个，其中已带 blindboxId 的 ' . $withId . ' 个' . PHP_EOL;
}
echo '  （已带 blindboxId=0 且盲盒奖励项>0 才需要回填；两项都是 0 说明这洞一直没被踩过——存不进去本来也存不了）' . PHP_EOL;
