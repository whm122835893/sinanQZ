<?php
/**
 * 邀请活动保存：只读探针（本地沙箱跑，不写库、不发版）。
 *
 * 目的：把「运营在后台点保存时看到的那句报错」原样打出来，
 * 确认 未知奖励类型 到底是哪个类型带出来的，以及报错里到底缺了哪些位置信息。
 *
 * 用法：cd sinan-nft-backend && php ../qa/probe_invite_reward_readonly.php
 * 用完即删，不放 web 目录下。
 */
require __DIR__ . '/../sinan-nft-backend/vendor/autoload.php';
$app = new \think\App(__DIR__ . '/../sinan-nft-backend/');
$app->initialize();

use app\service\ActivityRewardService;
use app\service\RewardGrantService;

$try = static function (string $label, callable $fn): void {
    try {
        $out = $fn();
        echo "  [通过] {$label} → " . json_encode($out, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } catch (\Throwable $e) {
        echo "  [报错] {$label} → " . get_class($e) . ': ' . $e->getMessage() . PHP_EOL;
    }
};

echo "== 0. 类型全集对照 ==" . PHP_EOL;
echo '  RewardGrantService::TYPES     = ' . implode('/', RewardGrantService::TYPES) . PHP_EOL;
echo '  ActivityRewardService 中文标签 = ' . implode('/', array_keys(ActivityRewardService::TYPE_LABELS)) . PHP_EOL;
echo '  差额（能显示但不能保存的类型） = ' . implode('/', array_diff(array_keys(ActivityRewardService::TYPE_LABELS), RewardGrantService::TYPES)) . PHP_EOL;

echo PHP_EOL . '== 1. 后台奖励类型下拉里的每一项，逐条送进保存校验（单奖励位）==' . PHP_EOL;
foreach (array_keys(ActivityRewardService::TYPE_LABELS) as $type) {
    $cfg = match ($type) {
        'collectible'               => ['type' => $type, 'collectibleId' => 1, 'quantity' => 1],
        'points'                    => ['type' => $type, 'amount' => 10],
        'draw_chance'               => ['type' => $type, 'quantity' => 1],
        'priority_qualification'    => ['type' => $type, 'prioritySaleId' => 1, 'quantity' => 1],
        'eligibility_qualification' => ['type' => $type, 'collectibleId' => 1, 'quantity' => 1],
        'blindbox'                  => ['type' => $type, 'quantity' => 1],
        default                     => ['type' => $type],
    };
    $try("type={$type}", fn () => ActivityRewardService::normalizeRewards([$cfg]));
}

echo PHP_EOL . '== 2. 两档混合（第 1 档正常、第 2 档第 2 项有问题）时报错长什么样 ==' . PHP_EOL;
$try('第2档第2项 = none', fn () => ActivityRewardService::normalizeRewards([
    ['type' => 'points', 'amount' => 20],
    ['type' => 'none'],
]));
$try('第2档第2项 = 藏品不存在', fn () => ActivityRewardService::normalizeRewards([
    ['type' => 'points', 'amount' => 20],
    ['type' => 'collectible', 'collectibleId' => 999999, 'quantity' => 1],
]));

echo PHP_EOL . '== 3. 发放侧（settleInviteReward 会走同样的校验）==' . PHP_EOL;
$try('发放侧遇到 none', fn () => RewardGrantService::validate('none', []));

echo PHP_EOL . '== 4. 修完后：邀请保存自己的报错版（反射调私有方法，不连库的分支）==' . PHP_EOL;
$ref   = new ReflectionClass(app\admin\controller\MarketingController::class);
$m     = $ref->getMethod('normalizeInviteRewards');
$ctrl  = $ref->newInstanceWithoutConstructor();
$check = static fn (array $rewards, string $where) => $m->invoke($ctrl, $rewards, $where);

$cases = [
    '档位里选了「无奖励」'   => [[['type' => 'none']], '邀请 3 人档'],
    '一档两项·第二项金额 0'  => [[['type' => 'points', 'amount' => 20], ['type' => 'points', 'amount' => 0]], '邀请 5 人档'],
    '一档两项·第二项盲盒'    => [[['type' => 'points', 'amount' => 20], ['type' => 'blindbox', 'quantity' => 1]], '邀请 5 人档'],
    '被邀请人奖励·没选藏品'  => [[['type' => 'collectible']], '被邀请人奖励'],
    '正常的一档'             => [[['type' => 'points', 'amount' => 20]], '邀请 3 人档'],
];
foreach ($cases as $label => [$rewards, $where]) {
    $try($label, fn () => $check($rewards, $where));
}

