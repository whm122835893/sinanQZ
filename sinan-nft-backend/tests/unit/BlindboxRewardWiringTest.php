<?php
declare(strict_types=1);

namespace tests\unit;

use app\service\ActivityRewardService;
use app\service\RewardGrantException;
use app\service\RewardGrantService;
use PHPUnit\Framework\TestCase;

/**
 * 盲盒奖励这一路（2026-10-13，你点头「那就修共享组件」）
 *
 * 上一轮查邀请「未知类型」时挖出来的更底层的洞：后台共享组件 RewardListEditor 的盲盒分支
 * 跟「抽奖次数」共用同一行「只填数量」的控件，全仓库后台前端没有一处出现 blindboxId。
 * 后端 validate() 要的偏偏是 blindboxId，所以四个活动（邀请/签到/注册/抽奖）里选「盲盒」
 * 一律存不进去——只能换回一句「盲盒奖励必须指定 blindboxId」，而页面上根本没有地方填。
 *
 * 前端接线（组件加下拉 + 四页传列表 + 四页保存前拦）由
 * sinan-admin/tests/blindbox-reward-picker.spec.js 钉住；
 * 这里钉后端这半边：字段语义（blindboxId = blind_boxes.id，不是藏品 id）、
 * 没填时的报错、以及名单导出文案要把盒名说清楚。
 *
 * 只跑离线分支：checkRef=false 时 validate() 不碰库，要连库的存在性校验留给 --testsuite api。
 */
class BlindboxRewardWiringTest extends TestCase
{
    private string $grantSrc;
    private string $labelSrc;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        // 本项目 PHP 文件是 CRLF，统一换成 \n 再匹配，正就不用每处写 \r?\n
        $this->grantSrc = str_replace("\r\n", "\n", (string) file_get_contents($root . '/app/service/RewardGrantService.php'));
        $this->labelSrc = str_replace("\r\n", "\n", (string) file_get_contents($root . '/app/service/ActivityRewardService.php'));
    }

    /** 取出 rewardLabel() 里 blindbox 那个 case 的 body */
    private function blindboxLabelCase(): string
    {
        $m = [];
        $this->assertSame(1, preg_match("#case 'blindbox':\n(.*?)\n            case '#s", $this->labelSrc, $m),
            'rewardLabel() 的 blindbox 分支结构变了，这条测试要跟着改');
        return $m[1];
    }

    // ------------------------------------------------ 1. 字段语义：盒 id，不是藏品 id

    public function test_没选具体盲盒时后端拦下并说清缺哪个字段(): void
    {
        $this->expectException(RewardGrantException::class);
        $this->expectExceptionMessage('盲盒奖励必须指定 blindboxId');
        RewardGrantService::validate('blindbox', ['quantity' => 2], false);
    }

    public function test_规范化后的键名是blindboxId(): void
    {
        // 前端下拉的 v-model 与保存 payload 用的必须是同一个键名，
        // 这条是防「前端改成 collectibleId、后端读 blindboxId」这种跨端错配
        $out = RewardGrantService::validate('blindbox', ['blindboxId' => 7, 'quantity' => 3], false);
        $this->assertSame(['rewardType' => 'blindbox', 'blindboxId' => 7, 'quantity' => 3], $out);
    }

    public function test_发放时blindboxId按blind_boxes主键解析出藏品(): void
    {
        // 盲盒下拉的 value 是 nft_blind_boxes.id；发放侧要拿它去 blind_boxes 找 collectible_id。
        // 若哪天有人把下拉 value 改成藏品 id，这里会红。
        // 注意正则一律用单引号写：双引号串里 "\$x" 会被 PHP 吃掉反斜杠，
        // 到 PCRE 就变成「行尾锚点 $」+ 字面量，永远匹配不上却不报错（本地实测踩过一次）。
        $this->assertMatchesRegularExpression(
            '/case \'blindbox\':\s+return self::grantCollectible\(\(int\) \$rewardConfig\[\'blindboxId\'\]/',
            $this->grantSrc,
            'blindbox 发放入口变了，检查它是否仍按盲盒 id 走'
        );
        $this->assertMatchesRegularExpression(
            '/private static function grantCollectible\([\s\S]{0,900}?Db::name\(\'blind_boxes\'\)->where\(\'id\', \$targetId\)->find\(\)/',
            $this->grantSrc,
            '盲盒 id → 藏品 id 的解析必须查 blind_boxes 主键'
        );
        $this->assertMatchesRegularExpression(
            '/case \'blindbox\':[\s\S]{0,900}?\$result \+= \[\'blindboxId\' => \$bid, \'quantity\' => \$qty\]/',
            $this->grantSrc,
            '校验后的规范化键名不能再变回别的写法'
        );
    }

    // ------------------------------------------------ 2. 名单导出文案要带盒名

    public function test_盲盒奖励文案带出盒名而不是光一句盲盒(): void
    {
        $body = $this->blindboxLabelCase();
        $this->assertStringContainsString("Db::name('blind_boxes')", $body, 'blindbox 文案不去查盒名，名单就只显示「盲盒×1」');
        $this->assertStringContainsString("join('collectibles c', 'c.id = bb.collectible_id')", $body,
            'blind_boxes 表没有 name 列，名字在它绑定的藏品上，必须 join 出来');
        $this->assertStringContainsString("\$reward['blindboxId']", $body, '文案要按 blindboxId 找盒');
    }

    public function test_历史数据没填盒id时退回旧文案而不是抛异常(): void
    {
        // 共享编辑器一直没有盲盒入口，存量奖励里 blindboxId 是空的。
        // 导出一张名单不该因为一条老记录整份断掉，所以这条兜底必须有。
        $body = $this->blindboxLabelCase();
        $this->assertMatchesRegularExpression('/\$bid\s*<=\s*0/', $body, 'blindboxId<=0 要有兜底分支');
        $this->assertMatchesRegularExpression('/if \(\$bid <= 0\) \{\n\s+return \$label \.\s*\'×\'/', $body,
            '兜底就是原来的「盲盒×N」，别在没选盒时编出一个「盲盒「#0」」');
    }

    public function test_盒名查不到时用id占位不显示空书名号(): void
    {
        $body = $this->blindboxLabelCase();
        $this->assertMatchesRegularExpression('/\?: \'#\' \. \$bid/', $body, '查不到名字要退回 #id 占位');
    }

    // ------------------------------------------------ 3. 跨文件一致性

    public function test_标签表覆盖全部奖励类型(): void
    {
        // 下拉能出现的类型都得有中文名，否则报错/名单里会漏出英文 key
        foreach (RewardGrantService::TYPES as $type) {
            $this->assertArrayHasKey($type, ActivityRewardService::TYPE_LABELS, "TYPE_LABELS 缺 {$type}");
            $this->assertNotSame('', ActivityRewardService::TYPE_LABELS[$type]);
        }
        // none 只在标签表里（抽奖「空奖」的展示），不在发放类型表里
        $this->assertArrayHasKey('none', ActivityRewardService::TYPE_LABELS);
        $this->assertNotContains('none', RewardGrantService::TYPES);
    }

    public function test_后端类型表里的blindbox在前端组件也有对应分支(): void
    {
        // 组件在 admin 仓库里，路径相对本仓库根
        $editor = dirname(__DIR__, 3) . '/sinan-admin/src/components/RewardListEditor.vue';
        if (!is_file($editor)) {
            $this->markTestSkipped('本地没有 sinan-admin 仓库，跳过跨仓检查');
        }
        $src = str_replace("\r\n", "\n", (string) file_get_contents($editor));
        $this->assertStringContainsString("r.type === 'blindbox'", $src);
        $this->assertStringContainsString('r.blindboxId', $src,
            '后端要 blindboxId，前端组件必须有填它的控件——这正是这次补上的东西');
        $this->assertFalse(
            str_contains($src, "r.type === 'draw_chance' || r.type === 'blindbox'"),
            '盲盒又跟抽奖次数共用一行控件了：那样前端选不出盒，后端只能报「必须指定 blindboxId」'
        );
    }
}
