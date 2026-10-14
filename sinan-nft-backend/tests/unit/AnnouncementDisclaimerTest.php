<?php
declare(strict_types=1);

namespace tests\unit;

use app\service\AnnouncementDisclaimer;
use PHPUnit\Framework\TestCase;

/**
 * 公告/新闻正文底部的「风险提示」（2026-10-14，你说「可以在每一条发布的公告底部加上这样的文本吗」）
 *
 * 口径（RUNBOOK §7.32）：
 *   1. 文案存在 nft_system_configs，后台「系统配置 → 全局参数 → 内容与公告」可改，实时生效不发版；
 *   2. 公告表 nft_announcements 一个字节不动，不回填、不加列 —— 存量公告当场就有提示；
 *   3. 只在 GET /api/announcements/:id 这一个出口拼（公告列表与首页轮播只下发标题，没有正文可拼）；
 *   4. 公告（notice）与新闻（news）都加，草稿与未到时的定时公告本来就不对外。
 *
 * 这里钉三件事：
 *   A. 纯函数 format()：开关、空值、分段规则、**转义**（后台填的是纯文本，不许变成 C 端的标签）；
 *   B. 防漂移：全仓库只有 announcementDetail 一处拼这个字段，且公告正文对外只有那一个出口；
 *   C. 跨文件一致性：迁移与 full_init.sql 两份种子里的文案必须逐字节相同（同一个默认值写了两处），
 *      以及后台/C 端的接线还在。
 *
 * 全部离线：format() 不碰数据库，fromConfig() 只被正则读源码，不调用。
 */
class AnnouncementDisclaimerTest extends TestCase
{
    private string $serviceSrc;
    private string $contentSrc;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        // 本项目 PHP 文件是 CRLF，统一换成 \n 再匹配（新写的文件可能是 LF，两种都要吃）
        $read = static function (string $path): string {
            return str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));
        };
        $this->serviceSrc = $read($root . '/app/service/AnnouncementDisclaimer.php');
        $this->contentSrc = $read($root . '/app/controller/Content.php');
    }

    /** 从 SQL 里抠出 announcement_disclaimer 的 config_value（键名后紧跟的那一段字符串字面量） */
    private function sqlSeedValue(string $sql): string
    {
        $m = [];
        // 键名后紧跟一个引号收尾，所以不会误命中 announcement_disclaimer_enabled
        $this->assertSame(1, preg_match("/'announcement_disclaimer',\s*'([^']*)'/", $sql, $m),
            '没在这份 SQL 里找到 announcement_disclaimer 的种子行 —— 种子被挪走或写法变了，测试要跟着改');
        return $m[1];
    }

    // ================================================ A. format() 的口径

    public function test_开关关闭时什么都不拼完全恢复改造前(): void
    {
        $this->assertSame('', AnnouncementDisclaimer::format("司南风险提示\n\n正文", false));
    }

    public function test_文案为空或只有空白时不拼(): void
    {
        foreach (['', '   ', "\n\n", "\r\n \r\n", "\t\n"] as $blank) {
            $this->assertSame('', AnnouncementDisclaimer::format($blank, true), '空白文案不该产出一个空壳块');
        }
        $this->assertSame('', AnnouncementDisclaimer::format(null, true));
    }

    public function test_第一行当标题其余当段落(): void
    {
        $html = AnnouncementDisclaimer::format("司南风险提示\n\n第一段\n\n第二段", true);
        $this->assertSame(
            '<p class="warn-title">司南风险提示</p><p>第一段</p><p>第二段</p>',
            $html
        );
    }

    public function test_空行分段段内回车变成换行(): void
    {
        // 运营在 textarea 里手敲的换行不该被吞掉，也不该各起一段
        $html = AnnouncementDisclaimer::format("标题\n\n第一段的上一句\n第一段的下一句\n\n第二段", true);
        $this->assertSame(
            '<p class="warn-title">标题</p><p>第一段的上一句<br />第一段的下一句</p><p>第二段</p>',
            $html
        );
    }

    public function test_windows与mac换行都吃进来(): void
    {
        $crlf = AnnouncementDisclaimer::format("标题\r\n\r\n正文", true);
        $this->assertSame('<p class="warn-title">标题</p><p>正文</p>', $crlf);
        $this->assertSame($crlf, AnnouncementDisclaimer::format("标题\r\r正文", true));
    }

    public function test_连续多个空行只算一次分段(): void
    {
        $this->assertSame(
            '<p class="warn-title">标题</p><p>甲</p><p>乙</p>',
            AnnouncementDisclaimer::format("标题\n\n\n\n甲\n\n\n乙", true)
        );
    }

    public function test_文案里的尖括号被转义不会变成网页标签(): void
    {
        // 后台填的是纯文本。这一条钉的是「运营（或拿到后台权限的人）在提示文案里写脚本，
        // C 端 v-html 直接渲染」这条路走不通 —— 转义只发生在本类一处。
        $html = AnnouncementDisclaimer::format('标题<script>alert("x")</script>', true);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&quot;', $html);

        $quoted = AnnouncementDisclaimer::format("标题\n\nA &amp; B <b>粗</b> '单引号'", true);
        $this->assertStringContainsString('A &amp;amp; B &lt;b&gt;粗&lt;/b&gt; &#039;单引号&#039;', $quoted,
            '段内也要逐行转义，不能只转标题');
    }

    public function test_超过上限的段落整段丢掉而不是切半个字(): void
    {
        // 上限与 SystemController::configSave() 的 mb_substr(…, 0, 5000) 对齐；
        // 标题 2 字 + 本段 5000 字 = 5002 > 5000 → 这一段连同后面的都别要了。
        $over = str_repeat('司', 5000);
        $this->assertSame('<p class="warn-title">标题</p>',
            AnnouncementDisclaimer::format("标题\n\n{$over}\n\n尾巴", true),
            '超限要丢整段，留下断句比丢掉更难发现');

        // 反面对照：没超限的长段落要正常出来，别把这条链写成永远返回空
        $under = str_repeat('司', 4000);
        $this->assertSame('<p class="warn-title">标题</p><p>' . $under . '</p>',
            AnnouncementDisclaimer::format("标题\n\n{$under}", true));
    }

    // ================================================ B. 单一出口（防漂移）

    public function test_只有公告详情这一个出口拼风险提示(): void
    {
        // 拼的地方只该有一处；哪天有人往列表里也拼一份，C 端就会出现两份口径
        $this->assertSame(1, substr_count($this->contentSrc, 'AnnouncementDisclaimer::fromConfig()'));

        $hits = [];
        foreach (['announcements()', 'announcementDetail()'] as $method) {
            $m = [];
            $this->assertSame(1, preg_match('/public function ' . preg_quote($method, '/') . '([\s\S]*?)\n    \}/', $this->contentSrc, $m),
                'Content.php 里找不到 ' . $method . '，结构变了要来看这条测试');
            $hits[$method] = str_contains($m[1], 'AnnouncementDisclaimer');
        }
        $this->assertFalse($hits['announcements()'], '列表接口不该拼风险提示（它连正文都不下发）');
        $this->assertTrue($hits['announcementDetail()'], '详情接口必须拼风险提示');
    }

    public function test_列表接口不下发公告正文(): void
    {
        // 风险提示只拼在正文底部，所以「正文对外只有详情一个出口」是这条链的地基。
        // 若哪天有人给列表加了 content 字段，就得决定那份要不要拼提示 —— 先在这里拦住。
        $m = [];
        $this->assertSame(1, preg_match('/public function announcements\(\)([\s\S]*?)\n    \}/', $this->contentSrc, $m));
        $this->assertStringNotContainsString("'content'", $m[1], '列表接口开始下发正文了，风险提示的口径要重新过');
    }

    public function test_开关行缺失时按开启处理(): void
    {
        // 口径：只有显式存 '0' 才关掉。忘 INSERT 开关行的结果是「文案也没配→不拼」这种看得见的状态，
        // 而不是「配了文案却被一个不存在的开关静默关掉」。
        $this->assertMatchesRegularExpression('/\$flag\s*===\s*null\s*\?\s*true/', $this->serviceSrc,
            'fromConfig() 里开关缺省的口径变了，检查生产上提示会不会静默消失');
    }

    public function test_后端布尔白名单收着这个开关键(): void
    {
        $src = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/admin/controller/SystemController.php'
        ));
        $this->assertMatchesRegularExpression(
            "/in_array\\(\\\$key, \\[[^\\]]*'announcement_disclaimer_enabled'[^\\]]*\\], true\\)/",
            $src,
            '开关没进 0/1 白名单的话，后台能存进任意字符串，而 fromConfig() 只认 \'1\''
        );
    }

    // ================================================ C. 种子与跨端接线

    public function test_两份种子的默认文案逐字节相同(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($root . '/migrations/20261014_announcement_disclaimer.sql'));
        $fullInit  = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($root . '/../database/full_init.sql'));

        $fromMigration = $this->sqlSeedValue($migration);
        $fromFullInit  = $this->sqlSeedValue($fullInit);
        $this->assertSame($fromMigration, $fromFullInit,
            '「老库跟迁移」与「全新部署跑 full_init」两条路装出来的默认文案不一致了（RUNBOOK §7.12 反复踩过的坑）');
    }

    public function test_默认文案齐了四段口径且不含别家品牌(): void
    {
        $sql = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents(
            dirname(__DIR__, 2) . '/migrations/20261014_announcement_disclaimer.sql'
        ));
        $text = $this->sqlSeedValue($sql);

        $this->assertStringStartsWith('司南风险提示', $text, '第一行是标题，会被渲染成加粗的那一行');
        foreach (['仅具备收藏与欣赏价值', '零容忍', '反洗钱', '移送国家司法机关'] as $must) {
            $this->assertStringContainsString($must, $text, "风险提示缺了「{$must}」这一层口径");
        }
        foreach (['ICARD', 'iCard', '爱卡'] as $other) {
            $this->assertStringNotContainsString($other, $text, "文案里出现了别家平台名「{$other}」");
        }
        // 「最终解释权归平台所有」属违法格式条款（《合同违法行为监督处理办法》），故意不写
        $this->assertStringNotContainsString('最终解释权', $text, '这句是典型的违法格式条款，不该出现在平台声明里');

        // SQL 里写的是 \n 转义（同一行塞进 mysqldump 那种长 INSERT 才不撑破行），
        // 真正渲染前由 MySQL 解释成真换行 —— 这里按同一口径解一遍，直接验渲染结果
        $html = AnnouncementDisclaimer::format(str_replace('\n', "\n", $text), true);
        $this->assertSame(1, substr_count($html, 'class="warn-title"'), '默认文案应有 1 个加粗标题行');
        $this->assertSame(3, substr_count($html, '<p>'), '默认文案应是标题 + 三段');
        $this->assertSame(4, substr_count($html, '</p>'));
        $this->assertStringNotContainsString('<br />', $html, '默认文案每段各占一行，不该有段内换行');
    }

    public function test_迁移只补行不覆盖运营改过的文案(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/20261014_announcement_disclaimer.sql');
        $this->assertMatchesRegularExpression('/INSERT IGNORE INTO `nft_system_configs`/', $sql);
        $this->assertStringNotContainsString('UPDATE `nft_system_configs`', $sql,
            '带 UPDATE 的写法会把运营在后台改过的文案冲回默认值（20261010 那份是刻意归位，这份不是）');
    }

    public function test_后台全局参数页有这两个键的中文入口(): void
    {
        $page = dirname(__DIR__, 3) . '/sinan-admin/src/views/system/Config.vue';
        if (!is_file($page)) {
            $this->markTestSkipped('本地没有 sinan-admin 仓库，跳过跨仓检查');
        }
        $src = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($page));
        foreach (['announcement_disclaimer', 'announcement_disclaimer_enabled'] as $key) {
            $this->assertMatchesRegularExpression("/{$key}:\\s*\\{[^}]*label: '/u", $src,
                "全局参数页缺「{$key}」的 META 条目：它会掉进「其他参数」并按 160px 单行框渲染，长文案根本改不动");
        }
        $this->assertMatchesRegularExpression("/announcement_disclaimer:.*multiline: true/s", $src,
            '文案键要按多行文本框渲染，否则一段声明挤在一个小输入框里');
    }

    public function test_C端会把风险提示渲染出来(): void
    {
        $h5 = dirname(__DIR__, 3) . '/sinan-art-source/src';
        if (!is_dir($h5)) {
            $this->markTestSkipped('本地没有 sinan-art-source 仓库，跳过跨仓检查');
        }
        $store = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($h5 . '/stores/notice.js'));
        $view  = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($h5 . '/views/NoticeDetail.vue'));

        $this->assertStringContainsString("disclaimer: a.disclaimer || ''", $store, 'store 没把接口字段透出来');
        $this->assertMatchesRegularExpression(
            '/v-if="notice\.disclaimer"[^>]*class="notice-detail__warning"[^>]*v-html="notice\.disclaimer"/',
            $view,
            '详情页的提示块必须走 __warning 这套现成样式，且不能无条件渲染（空串会留一个空灰块）'
        );
        // 2026-10-14 公告详情页版式改造（§7.36）：风险提示从正文卡片里搬出来，成了正文卡片下面的独立白卡片。
        // 原来这条「</template> 紧跟提示注释」是位置代理断言，卡片一挪就红（并不是口径变了），
        // 所以换成直接量 DOM 位置：提示块必须排在正文卡片之后，且正文卡片内部不能再出现一份 ——
        // 这两条合起来才是「富文本/纯文本两种正文，提示都只在最底部出现一次」的真正口径。
        $warn = strpos($view, 'v-if="notice.disclaimer"');
        $this->assertNotFalse($warn, '详情页找不到风险提示块');
        $bodyOpen = strpos($view, '<article class="notice-detail__main"');
        $this->assertNotFalse($bodyOpen, '正文卡片类名变了，这条防漂移要跟着一并改');
        $bodyClose = strpos($view, '</article>');
        $this->assertNotFalse($bodyClose);
        $this->assertLessThan($warn, $bodyClose, '风险提示要排在正文卡片之后（自己占一张卡）');
        $this->assertStringNotContainsString(
            'notice.disclaimer',
            substr($view, $bodyOpen, $bodyClose - $bodyOpen),
            '正文卡片里不许再放一份风险提示，否则页面出现两块'
        );
        // 这块的历史依赖（为什么单独下发、为什么只拼在详情）必须留在注释里，别改成裸代码
        $this->assertMatchesRegularExpression('/<!--[^>]*风险提示/', $view);
    }
}
