<?php
declare(strict_types=1);

namespace tests\unit;

use app\admin\controller\UploadController;
use app\service\AnnouncementCover;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 公告「子分类 + 类型默认头图 + 标签色」的单一真源（2026-10-14，需求与口径见 RUNBOOK §7.36）
 *
 * 需求原话：「后台的话我想要有公告图，就像图中的运营公告图片和寄售公告图片一样，
 *             选择哪个类型都有对应的图片，并且这个图片可以上传更换。」
 * 落成的口径：C 端详情页顶部横幅 = 这条公告自己上传的封面 > 该子分类的默认图（后台可换）> 空串不渲染。
 *
 * 为什么要写这个类：加一个「寄售公告」原本要同时改六处字面量
 * （CmsController 的列表筛选/新建/编辑、后台 maps.js、后台筛选与单选、C 端 stores/notice.js），
 * 而 enumParam() 对不在白名单里的值是「当成没传」而非报错 —— 漏一处的表现是静默的：
 * 要么筛不出寄售公告，要么 C 端把它显示成「公告」。
 * 下面 B、C 两节就是把这些字面量全部钉到 AnnouncementCover::SUBTYPES 这一个真源上，漏一处就红。
 *
 * 只测纯函数与源码事实：defaultCovers() 要连库，留给本地起栈后的人工端到端复验（§7.36 有记录）。
 */
class AnnouncementCoverTest extends TestCase
{
    private string $serviceSrc;
    private string $cmsSrc;
    private string $contentSrc;

    /** 读源码统一吃掉 CRLF（本项目历史文件是 CRLF，新写的可能是 LF，两种都要吃） */
    private function read(string $path): string
    {
        return str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));
    }

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->serviceSrc = $this->read($root . '/app/service/AnnouncementCover.php');
        $this->cmsSrc     = $this->read($root . '/app/admin/controller/CmsController.php');
        $this->contentSrc = $this->read($root . '/app/controller/Content.php');
    }

    /** 抠出两份 SQL 里的四个默认图行：子分类 => [图片地址, 描述] */
    private function coverSeedRows(string $sql): array
    {
        $hits = [];
        $this->assertSame(
            4,
            preg_match_all(
                "/'announcement_cover_(activity|compose|consignment|operation)',\s*'([^']+)',\s*'([^']*)'/",
                $sql,
                $hits,
                PREG_SET_ORDER
            ),
            '这份 SQL 里没凑齐四个 announcement_cover_* 行 —— 种子被挪走或写法变了，测试要跟着改'
        );
        $rows = [];
        foreach ($hits as $h) {
            $rows[$h[1]] = [$h[2], $h[3]];
        }
        ksort($rows);
        return $rows;
    }

    // ================================================ A. 纯函数口径

    public function test_子分类白名单就这四个且顺序即展示顺序(): void
    {
        $this->assertSame(['activity', 'compose', 'consignment', 'operation'], AnnouncementCover::values());
        $this->assertSame(
            ['活动公告', '合成公告', '寄售公告', '运营公告'],
            array_column(AnnouncementCover::SUBTYPES, 'label')
        );
        // 新建公告默认选中、以及未知值的兜底，都必须是这个，别各自另立一套
        $this->assertSame('operation', AnnouncementCover::DEFAULT_SUBTYPE);
        $this->assertContains(AnnouncementCover::DEFAULT_SUBTYPE, AnnouncementCover::values());
    }

    public function test_每个子分类都有中文标签与主题色和默认图键(): void
    {
        foreach (AnnouncementCover::SUBTYPES as $subtype => $meta) {
            $this->assertMatchesRegularExpression('/^[\x{4e00}-\x{9fff}]{4}$/u', $meta['label'],
                "{$subtype} 的标签应是四个汉字（C 端标签位宽度按它排的）");
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $meta['color'],
                "{$subtype} 的主题色必须是 #rrggbb，它会被写进 C 端 style");
            $this->assertSame('announcement_cover_' . $subtype, $meta['cover_key'],
                "{$subtype} 的默认图参数键名要和子分类同名，后台「公告头图」分组按这个对应关系排");
        }
        $this->assertSame(
            ['announcement_cover_activity', 'announcement_cover_compose',
             'announcement_cover_consignment', 'announcement_cover_operation'],
            AnnouncementCover::coverKeys()
        );
    }

    public function test_未知或空的子分类一律按运营公告处理(): void
    {
        // subtype 列可空，存量行里真有 null；两端早就把 null 当 operation，
        // 这里再留第三种兜底（"公告"）就会出现「列表叫运营公告、详情叫公告」
        foreach ([null, '', '   ', 'consign', 'ACTIVITY', 'sale'] as $dirty) {
            $this->assertSame('operation', AnnouncementCover::normalize($dirty));
            $this->assertSame('运营公告', AnnouncementCover::label($dirty));
            $this->assertSame('#3D5A99', AnnouncementCover::color($dirty));
        }
        $this->assertTrue(AnnouncementCover::isSubtype('consignment'));
        $this->assertFalse(AnnouncementCover::isSubtype(null));
    }

    public function test_头图是自带封面优先其次类型默认都没有就空串(): void
    {
        $defaults = ['operation' => '/uploads/notice-default/announcement-operation.webp',
                     'consignment' => '/uploads/notice-default/announcement-consignment.webp'];

        // ① 公告自己传了图 → 用它的，且把首尾空白吃掉
        $this->assertSame('/uploads/a.jpg',
            AnnouncementCover::coverFor('operation', ' /uploads/a.jpg ', $defaults));
        // ② 没传（空串 / null / 全空白）→ 用该类型的默认图
        foreach (['', null, "  \n "] as $blank) {
            $this->assertSame('/uploads/notice-default/announcement-operation.webp',
                AnnouncementCover::coverFor('operation', $blank, $defaults));
            $this->assertSame('/uploads/notice-default/announcement-consignment.webp',
                AnnouncementCover::coverFor('consignment', $blank, $defaults));
        }
        // ③ 未知子分类按运营公告取默认图（和 normalize 一个口径）
        $this->assertSame('/uploads/notice-default/announcement-operation.webp',
            AnnouncementCover::coverFor('whatever', '', $defaults));
        // ④ 该类型默认图被清空 / 参数行缺失 → 空串，C 端整块不渲染，不留灰坑
        $this->assertSame('', AnnouncementCover::coverFor('activity', '', ['operation' => '/x.webp']));
        $this->assertSame('', AnnouncementCover::coverFor('operation', '', ['operation' => '   ']));
        $this->assertSame('', AnnouncementCover::coverFor('operation', '', []));
    }

    public function test_标签色只认六位十六进制其余回落类型色(): void
    {
        // tag_color 是后台自由填的字符串，而它要进 C 端的 style：只收 #rrggbb，
        // 形状不对就回类型色，别把 "url(...)"、带分号的片段原样写下去
        $this->assertSame('#123456', AnnouncementCover::colorFor('operation', '#123456'));
        $this->assertSame('#3d5a99', AnnouncementCover::colorFor('operation', ' #3D5A99 '));
        foreach (['#fff', 'red', 'url(evil)', '#1234567', '#GGHHII', '', null, '   ', '0;opacity:0'] as $dirty) {
            $this->assertSame('#3D5A99', AnnouncementCover::colorFor('operation', $dirty),
                '非法 tag_color 应回落运营公告主题色');
        }
        $this->assertSame('#E89121', AnnouncementCover::colorFor('consignment', ''));
    }

    public function test_resolve一次给出头图与标签色(): void
    {
        $defaults = ['consignment' => '/uploads/notice-default/announcement-consignment.webp'];
        $this->assertSame(
            ['coverImage' => '/uploads/mine.png', 'tagColor' => '#123456'],
            AnnouncementCover::resolve('consignment', '/uploads/mine.png', '#123456', $defaults)
        );
        $this->assertSame(
            ['coverImage' => '/uploads/notice-default/announcement-consignment.webp', 'tagColor' => '#E89121'],
            AnnouncementCover::resolve('consignment', '', 'nope', $defaults)
        );
    }

    // ================================================ B. 单一出口（防漂移）

    public function test_后台控制器不再自己抄一份子分类字面量(): void
    {
        // 以前列表筛选 / 新建 / 编辑各写一遍 ['activity','compose','operation']，加一类要改三处
        $this->assertSame(3, substr_count($this->cmsSrc, "enumParam('subtype', AnnouncementCover::values())"),
            'CmsController 里 subtype 白名单应当有三处（列表筛选 / 新建 / 编辑）都取自 AnnouncementCover::values()');
        $this->assertSame(2, substr_count($this->cmsSrc, 'AnnouncementCover::normalize('),
            '新建与编辑落库前都该过一次 normalize()');
        $this->assertStringNotContainsString("['activity','compose'", str_replace(' ', '', $this->cmsSrc),
            'CmsController 里不该再出现手抄的子分类字面量数组 —— 白名单只有 AnnouncementCover::SUBTYPES 一处');
    }

    public function test_后台列表接口把四个默认图随分页一起下发(): void
    {
        // 编辑弹窗要显示「选这个类型会配哪张图」。不新开接口的原因：/admin/system/configs 要
        // system:config 权限，只开内容权限的运营点进去会 403；顺带也少一次请求。
        $m = [];
        $this->assertSame(1, preg_match('/public function announcementList\(\)([\s\S]*?)\n    \}/', $this->cmsSrc, $m),
            'CmsController 的后台公告列表方法改名了，这条防漂移要跟着一并改');
        $this->assertMatchesRegularExpression(
            '/\$this->paginate\([\s\S]*?\'covers\' => AnnouncementCover::defaultCovers\(\)/',
            $m[1],
            '后台公告列表的分页结果里要带 covers，否则编辑弹窗显示不出该类型默认头图'
        );
    }

    public function test_C端详情下发头图而列表不下发(): void
    {
        // 列表页没有图片位：多查一次库、多下发一个没人用的字段都是债（§7.36 的决定）
        $list = $this->methodBody('announcements()');
        $this->assertStringNotContainsString('coverFor(', $list, '列表接口不该解析头图');
        $this->assertStringNotContainsString('defaultCovers(', $list, '列表接口不该读默认图配置');
        $this->assertMatchesRegularExpression("/'subtypeLabel'\s*=> AnnouncementCover::label/", $list);
        $this->assertMatchesRegularExpression("/'tagColor'\s*=> AnnouncementCover::colorFor/", $list);

        $detail = $this->methodBody('announcementDetail()');
        $this->assertMatchesRegularExpression(
            "/'coverImage'\s*=>\s*AnnouncementCover::coverFor\\(\\\$a\\['subtype'\\], \\\$a\\['cover_image'\\], AnnouncementCover::defaultCovers\\(\\)\\)/",
            $detail,
            '详情页头图必须走 coverFor（自带封面优先，回落类型默认图）'
        );
        $this->assertMatchesRegularExpression("/'tagColor'\s*=>\s*AnnouncementCover::colorFor/", $detail);
        $this->assertMatchesRegularExpression("/'subtypeLabel'\s*=>\s*AnnouncementCover::label/", $detail);
    }

    /** 抠出某个方法的函数体（本项目控制器方法都是 4 空格缩进收尾） */
    private function methodBody(string $signature): string
    {
        $m = [];
        $this->assertSame(1, preg_match('/public function ' . preg_quote($signature, '/') . '([\s\S]*?)\n    \}/',
            $this->contentSrc, $m), 'Content.php 找不到 ' . $signature . '，结构变了要来看这条测试');
        return $m[1];
    }

    public function test_公告图有专属上传目录且出厂目录不参与清理(): void
    {
        // ① 后台「公告图」上传走 biz=announcement，目录在上传白名单里才收得下
        $this->assertContains('announcement', UploadController::BIZ_DIRS,
            'UploadController::BIZ_DIRS 少了 announcement，后台上传公告图会被 4220 挡掉');
        // ② 出厂图所在目录不是业务目录，清理服务递归扫的时候进不了它的闸门
        $factory = trim(AnnouncementCover::FACTORY_DIR, '/');
        $this->assertNotContains($factory, UploadController::BIZ_DIRS,
            '出厂图目录 ' . $factory . ' 一旦进了 BIZ_DIRS，「清理未引用图片」就可能把默认横幅收走');

        $cleanup = $this->read(dirname(__DIR__, 2) . '/app/admin/service/UploadCleanupService.php');
        $ref = new ReflectionClass(\app\admin\service\UploadCleanupService::class);
        $dirs = $ref->getReflectionConstant('BIZ_DIRS');
        $this->assertNotFalse($dirs);
        $this->assertNotContains($factory, (array) $dirs->getValue());
        // 公告自己的封面与正文图要被引用扫描认下来，否则在用图会被判零引用
        $this->assertMatchesRegularExpression("/'announcements'\s*=>\s*\['cover_image',\s*'content'\]/", $cleanup);
    }

    // ================================================ C. 种子与跨端接线

    public function test_两份种子的四个默认图逐字节相同(): void
    {
        $root = dirname(__DIR__, 2);
        $fromMigration = $this->coverSeedRows($this->read($root . '/migrations/20261014_announcement_cover_defaults.sql'));
        $fromFullInit  = $this->coverSeedRows($this->read($root . '/../database/full_init.sql'));

        $this->assertSame($fromMigration, $fromFullInit,
            '「老库跟迁移」与「全新部署跑 full_init」装出来的默认头图不一致了（RUNBOOK §7.12 反复踩过的坑）');
        $this->assertSame(
            ['activity', 'compose', 'consignment', 'operation'],
            array_keys($fromMigration)
        );
    }

    public function test_默认图参数键与键名一一对应且地址指到出厂目录(): void
    {
        $rows = $this->coverSeedRows($this->read(dirname(__DIR__, 2) . '/migrations/20261014_announcement_cover_defaults.sql'));
        foreach (AnnouncementCover::SUBTYPES as $subtype => $meta) {
            $this->assertSame(AnnouncementCover::FACTORY_DIR . '/announcement-' . $subtype . '.webp',
                $rows[$subtype][0], "参数 {$meta['cover_key']} 的默认图地址不合约定（目录/文件名都按子分类拼）");
            $this->assertStringContainsString($meta['label'], $rows[$subtype][1],
                '参数描述要带上类型中文名，后台全局参数页就靠它让运营认出自己改的是哪一类');
            $this->assertStringContainsString('清空=该类型不显示横幅', $rows[$subtype][1],
                '描述里要写清「清空」的语义，不然运营以为清空是恢复出厂图');
        }
    }

    public function test_四张出厂图真的在仓库里且尺寸一致(): void
    {
        $dir = dirname(__DIR__, 2) . '/public/uploads/notice-default';
        $sizes = [];
        foreach (AnnouncementCover::SUBTYPES as $subtype => $meta) {
            $file = $dir . '/announcement-' . $subtype . '.webp';
            $this->assertFileExists($file,
                "迁移把 {$meta['cover_key']} 指向了 {$file}，但文件不在版本里 —— 上线后该类公告会显示不出横幅");
            $this->assertGreaterThan(4000, filesize($file), "{$subtype} 的出厂图小得不像一张横幅");
            $info = @getimagesize($file);
            $this->assertNotFalse($info, "{$subtype} 的出厂图读不出尺寸（不是图片？）");
            $this->assertSame(IMAGETYPE_WEBP, $info[2], '出厂图统一 WEBP：横幅要小，PNG 太占包');
            $sizes[$subtype] = [$info[0], $info[1]];
        }
        $this->assertSame(count(array_unique(array_map('serialize', $sizes))), 1,
            '四张出厂图尺寸必须一致，否则 C 端横幅区高度会随类型跳');
        // 后台/C 端提示文案承诺的就是这个尺寸（1280×495，约 2.59:1），改了图要连提示一起改
        $this->assertSame([1280, 495], $sizes['operation']);
    }

    public function test_迁移只补行不覆盖运营换过的图(): void
    {
        $sql = $this->read(dirname(__DIR__, 2) . '/migrations/20261014_announcement_cover_defaults.sql');
        $this->assertSame(4, substr_count($sql, 'INSERT IGNORE INTO `nft_system_configs`'),
            '四个键各一条 INSERT IGNORE，缺行才补');
        $this->assertStringNotContainsString('UPDATE `nft_system_configs`', $sql,
            '带 UPDATE 的写法会把运营在后台换过的默认图冲回出厂图');
        // 这次没有 DDL：subtype 是 varchar(20) 而不是 enum，加一类不需要改表
        $this->assertStringNotContainsString('ALTER TABLE', $sql,
            'subtype 列是 varchar(20)，本次「加寄售公告」不该出现 ALTER');
    }

    public function test_后台maps与筛选和表单都用同一套四类(): void
    {
        $admin = dirname(__DIR__, 3) . '/sinan-admin/src';
        if (!is_dir($admin)) {
            $this->markTestSkipped('本地没有 sinan-admin 仓库，跳过跨仓检查');
        }
        $maps   = $this->read($admin . '/utils/maps.js');
        $view   = $this->read($admin . '/views/content/Announcements.vue');
        $apiSrc = $this->read($admin . '/api/index.js');

        // ① 后台标签表：键与中文标签要和后端一字不差（含顺序）
        $m = [];
        $this->assertSame(1, preg_match('/export const NOTICE_TYPE = \{([\s\S]*?)\n\}/', $maps, $m),
            '后台 maps.js 的 NOTICE_TYPE 结构变了，这条防漂移要跟着一并改');
        $hits = [];
        preg_match_all("/([a-z]+):\\s*\\{\\s*label: '([^']+)'/u", $m[1], $hits, PREG_SET_ORDER);
        $local = [];
        foreach ($hits as $h) {
            $local[$h[1]] = $h[2];
        }
        $this->assertSame($local, array_map(fn ($x) => $x['label'], AnnouncementCover::SUBTYPES),
            '后台公告类型标签与后端 SUBTYPES 的中文名或顺序不一致了');

        // ② 筛选与单选都从 NOTICE_TYPE 派生，不再各抄一份字面量
        $this->assertStringContainsString('Object.entries(NOTICE_TYPE)', $view,
            '筛选项应当由 NOTICE_TYPE 派生；手抄一份的话加一类又要多改一处');
        $this->assertStringContainsString('v-for="(m, k) in NOTICE_TYPE"', $view,
            '弹窗里的类型单选也应当由 NOTICE_TYPE 派生');
        $this->assertStringNotContainsString("value: 'consignment'", $view,
            '弹窗与筛选都派生自 NOTICE_TYPE 后，视图里不该再留第四份字面量');

        // ③ 「公告图」的上传口与保存字段
        $this->assertStringContainsString("uploadImage(file, 'announcement')", $view,
            '后台公告图要传到 announcement 目录（和上传白名单、清理保护对齐）');
        $this->assertStringContainsString('恢复跟随类型默认', $view,
            '要能一键退回「跟随类型默认头图」，否则运营换过一次图就回不去了');
        $this->assertStringContainsString('coverImage: f.coverImage', $view, '表单值要真的提交上去');
        $this->assertMatchesRegularExpression('/cover_image: payload\.coverImage \|\| \'\'/', $apiSrc,
            'api 层留空要传空串（后端把空串当「跟随类型默认」），别传 null 变成「不改这列」');

        // ④ 提示文案承诺的图尺寸要和出厂图一致
        $this->assertStringContainsString('1280×495', $view, '弹窗里的尺寸提示要跟着出厂图的实际像素改');
    }

    public function test_后台全局参数页把四个默认图做成图片控件(): void
    {
        $page = dirname(__DIR__, 3) . '/sinan-admin/src/views/system/Config.vue';
        if (!is_file($page)) {
            $this->markTestSkipped('本地没有 sinan-admin 仓库，跳过跨仓检查');
        }
        $src = $this->read($page);
        foreach (AnnouncementCover::coverKeys() as $key) {
            $this->assertMatchesRegularExpression("/{$key}:\\s*\\{[^}]*label: '/u", $src,
                "全局参数页缺「{$key}」的 META 条目：它会掉进「其他参数」并按 160px 单行框渲染，路径根本没法换");
            $this->assertMatchesRegularExpression("/{$key}:\\s*\\{[^}]*image: true/u", $src,
                "{$key} 要按图片控件渲染，光让运营敲路径等于没做这个功能");
        }
        // 四个键归到同一个分组，运营才知道「公告头图」在哪一页
        $this->assertMatchesRegularExpression("/name: '公告头图'[\s\S]{0,400}announcement_cover_activity/", $src);
        $this->assertStringContainsString("uploadImage(file, 'announcement')", $src,
            '默认图的上传也要走 announcement 业务目录（5MB 闸、类型嗅探、引用扫描都在那条链上）');
    }

    public function test_C端会渲染头图并按后端兜底口径显示类型(): void
    {
        $h5 = dirname(__DIR__, 3) . '/sinan-art-source/src';
        if (!is_dir($h5)) {
            $this->markTestSkipped('本地没有 sinan-art-source 仓库，跳过跨仓检查');
        }
        $store = $this->read($h5 . '/stores/notice.js');
        $view  = $this->read($h5 . '/views/NoticeDetail.vue');

        // store：三个字段都是原样透传，不在前端重做优先级
        $this->assertMatchesRegularExpression("/coverImage: a\.coverImage \|\| ''/", $store);
        $this->assertMatchesRegularExpression("/tagColor: a\.tagColor \|\| ''/", $store);
        $this->assertStringContainsString('labelOf(a)', $store, '列表与详情应共用同一个标签兜底出口');
        // 兜底要和后端 normalize() 同口径：没标类型的按运营公告算
        $this->assertMatchesRegularExpression("/categoryLabels\\[a\\.subtype \\|\\| 'operation'\\]/", $store);

        // 中文标签字面量逐一对齐后端（C 端这份只是接口没带 subtypeLabel 时的兜底）
        $m = [];
        $this->assertSame(1, preg_match('/const categoryLabels = \{([\s\S]*?)\n  \}/', $store, $m));
        $hits = [];
        preg_match_all("/([a-z]+):\\s*'([^']+)'/u", $m[1], $hits, PREG_SET_ORDER);
        $local = [];
        foreach ($hits as $h) {
            $local[$h[1]] = $h[2];
        }
        $this->assertSame($local, array_map(fn ($x) => $x['label'], AnnouncementCover::SUBTYPES),
            'C 端分类标签与后端 SUBTYPES 的中文名或顺序不一致了（列表 Tab 会先露馅）');

        // 视图：有图才渲染横幅，标签色走 style 绑定
        $this->assertMatchesRegularExpression(
            '/v-if="notice\.coverImage"[^>]*class="notice-detail__cover"/',
            $view,
            '头图必须条件渲染（后端给的 coverImage 为空 = 该类型默认图被清空，此时不该留一个空 img）'
        );
        $this->assertStringContainsString(':src="notice.coverImage"', $view);
        $this->assertStringContainsString(':style="typeStyle"', $view,
            '类型标签底色要用接口给的 tagColor，不能在 C 端再抄一份颜色表');
        $this->assertMatchesRegularExpression('/const typeStyle = computed\(/', $view);
        // 版式顺序（参考图口径）：标题 → 标签+日期 → 头图 → 正文，风险提示在正文卡片之后单独一张卡
        $this->assertLessThan(
            strpos($view, 'notice-detail__body'),
            strpos($view, 'notice-detail__cover'),
            '头图应排在正文之前'
        );
    }
}
