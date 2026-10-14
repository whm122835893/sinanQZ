<?php
declare(strict_types=1);

namespace tests\unit;

use app\service\AnnouncementSalutation;
use PHPUnit\Framework\TestCase;

/**
 * 公告「敬语 + 落款署名」的单一真源（2026-10-14，需求与口径见 RUNBOOK §7.37）
 *
 * 需求原话：「公告首图放在顶部居中的位置，圆角10px。每一条公告都要有敬语和署名，署名在发布公告时可改」
 * 落成的口径：
 *   - 敬语、署名各一列（可空），C 端最终值 = 这条公告自己填的 > 全局参数默认 > 出厂常量；
 *   - 落款日期不落库、运营填不了，后端按该公告实际生效时间（publish_time 优先，否则 created_at）算；
 *   - 两个字段对外都是**纯文本**，C 端用 {{ }} 渲染，不走 v-html。
 *
 * 与 AnnouncementCoverTest 的分工：那边管「子分类 + 类型默认头图 + 标签色」，这里只管敬语与署名；
 * 首图的样式（通宽居中 + 圆角 10px）也钉在这里，因为它是这次需求点名要改的那一处。
 *
 * 只测纯函数与源码事实：defaults() 要连库，留给本地起栈后的人工端到端复验（同 §7.36 的做法）。
 */
class AnnouncementSalutationTest extends TestCase
{
    private string $serviceSrc;
    private string $cmsSrc;
    private string $contentSrc;
    private string $migrationSql;
    private string $fullInitSql;

    /** 读源码统一吃掉 CRLF（本项目历史文件是 CRLF，新写的可能是 LF，两种都要吃） */
    private function read(string $path): string
    {
        return str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));
    }

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->serviceSrc = $this->read($root . '/app/service/AnnouncementSalutation.php');
        $this->cmsSrc     = $this->read($root . '/app/admin/controller/CmsController.php');
        $this->contentSrc = $this->read($root . '/app/controller/Content.php');
        $this->migrationSql = $this->read($root . '/migrations/20261014_announcement_salutation.sql');
        $this->fullInitSql  = $this->read($root . '/../database/full_init.sql');
    }

    /** 抠出两份 SQL 里的两个默认文案行：参数键 => [值, 描述] */
    private function seedRows(string $sql): array
    {
        $hits = [];
        $this->assertSame(
            2,
            preg_match_all(
                "/'(announcement_greeting_default|announcement_signature_default)',\s*'([^']+)',\s*'([^']*)'/",
                $sql,
                $hits,
                PREG_SET_ORDER
            ),
            '这份 SQL 里没凑齐两个 announcement_*_default 行 —— 种子被挪走或写法变了，测试要跟着改'
        );
        $rows = [];
        foreach ($hits as $h) {
            $rows[$h[1]] = [$h[2], $h[3]];
        }
        ksort($rows);
        return $rows;
    }

    /** 抠出某个方法的函数体（本项目控制器方法都是 4 空格缩进收尾） */
    private function methodBody(string $file, string $signature): string
    {
        $m = [];
        $this->assertSame(1, preg_match('/public function ' . preg_quote($signature, '/') . '([\s\S]*?)\n    \}/',
            $file, $m), '找不到 ' . $signature . '，结构变了要来看这条测试');
        return $m[1];
    }

    // ================================================ A. 纯函数口径

    public function test_两个参数键与出厂文案就是这个类里定的(): void
    {
        $this->assertSame(
            ['announcement_greeting_default', 'announcement_signature_default'],
            AnnouncementSalutation::keys()
        );
        $this->assertSame('尊敬的司南用户：', AnnouncementSalutation::DEFAULT_GREETING);
        $this->assertSame('司南运营团队', AnnouncementSalutation::DEFAULT_SIGNATURE);
        // 上限与两列的 varchar(100) 对齐（下面 C 节把它和 DDL 钉在一起）
        $this->assertSame(100, AnnouncementSalutation::MAX_CHARS);
    }

    public function test_敬语与署名三级回落自带优先全局垫底出厂兜底(): void
    {
        $defaults = [
            AnnouncementSalutation::GREETING_KEY  => '亲爱的藏友：',
            AnnouncementSalutation::SIGNATURE_KEY => '司南小助手',
        ];
        // ① 公告自己填了 → 用它自己的（这就是「署名在发布公告时可改」）
        $this->assertSame('各位同好：', AnnouncementSalutation::greetingFor('各位同好：', $defaults));
        $this->assertSame('寄售小组', AnnouncementSalutation::signatureFor('寄售小组', $defaults));
        // ② 没填 → 用全局默认
        $this->assertSame('亲爱的藏友：', AnnouncementSalutation::greetingFor(null, $defaults));
        $this->assertSame('司南小助手', AnnouncementSalutation::signatureFor('', $defaults));
        // ③ 全局默认那行缺了 / 被运营清空 → 出厂常量，保证「每条公告都有敬语署名」不会静默变成空白
        $this->assertSame('尊敬的司南用户：', AnnouncementSalutation::greetingFor('', []));
        $this->assertSame('尊敬的司南用户：', AnnouncementSalutation::greetingFor('  ', ['announcement_greeting_default' => '   ']));
        $this->assertSame('司南运营团队', AnnouncementSalutation::signatureFor(null, ['announcement_signature_default' => '']));
    }

    public function test_出口值一律去空白并截到列宽(): void
    {
        // 前端 maxlength=100 只是拦人手，落库与出口这一刀才是真闸：列宽 varchar(100)，
        // 截多了 SQL 严格模式直接报错，截少了用户看到的落款缺字
        $long = str_repeat('司', 120);
        $this->assertSame(str_repeat('司', 100), AnnouncementSalutation::greetingFor($long, []));
        $this->assertSame(str_repeat('南', 100), AnnouncementSalutation::signatureFor(str_repeat('南', 300), []));
        // 首尾空白吃掉，别把「 司南运营团队 」当成和「司南运营团队」不同的两个落款
        $this->assertSame('甲', AnnouncementSalutation::greetingFor("  甲\n ", []));
    }

    public function test_落款日期优先取发布时间否则创建时间(): void
    {
        // 定时公告是到点才可见的：落款写创建那天，等于给用户一个比真实发布日更早的日期
        $this->assertSame('2026年10月14日',
            AnnouncementSalutation::dateFor('2026-10-14 09:30:00', '2026-10-11 20:00:00'));
        // 「10月14日」而不是「10月14日」前的零：n月j日 是中文公文习惯，用户看得懂的那个版本
        $this->assertSame('2026年3月5日', AnnouncementSalutation::dateFor('2026-03-05 00:00:00', null));
        // 没有发布时间（立即发布的存量行真有 NULL）→ 退回创建时间
        $this->assertSame('2026年10月11日', AnnouncementSalutation::dateFor(null, '2026-10-11 20:00:00'));
        // 两个都取不到 / 形状不是时间 → 空串，C 端只显示落款名，不显示半个日期
        foreach ([['', ''], [null, null], ['   ', "  \n"], ['0000-00-00 00:00:00', null], ['不是时间', '']] as $pair) {
            $this->assertSame('', AnnouncementSalutation::dateFor($pair[0], $pair[1]));
        }
    }

    public function test_resolve一次给出敬语署名与日期(): void
    {
        // resolve() 自己不查库（$defaults 由调用方给，和 AnnouncementCover::resolve 同一形状），
        // 所以这条能当纯函数测：三个键的顺序就是详情接口的下发顺序
        $defaults = [
            AnnouncementSalutation::GREETING_KEY  => '亲爱的藏友：',
            AnnouncementSalutation::SIGNATURE_KEY => '司南小助手',
        ];
        $got = AnnouncementSalutation::resolve('各位同好：', '', '2026-10-14 09:30:00', '2026-10-11 20:00:00', $defaults);
        $this->assertSame(['greeting', 'signature', 'signatureDate'], array_keys($got));
        $this->assertSame('各位同好：', $got['greeting']);
        $this->assertSame('司南小助手', $got['signature']);
        $this->assertSame('2026年10月14日', $got['signatureDate']);
    }

    // ================================================ B. 单一出口（防漂移）

    public function test_出厂文案的字面量只出现在服务类里(): void
    {
        // 控制器里再抄一遍「尊敬的司南用户：」= 运营改了参数、代码里那句还在，两边显示不一样
        foreach (['\n' => $this->cmsSrc, 'C端控制器' => $this->contentSrc] as $where => $src) {
            $this->assertStringNotContainsString('尊敬的司南用户', $src,
                "{$where} 里不该出现出厂敬语字面量，取值只走 AnnouncementSalutation");
            $this->assertStringNotContainsString('司南运营团队', $src,
                "{$where} 里不该出现出厂落款字面量，取值只走 AnnouncementSalutation");
        }
    }

    public function test_C端详情下发三件套而列表不下发(): void
    {
        // 列表页只有一行标题 + 摘要，没有敬语与落款的位子；
        // 而 resolve() 每次要读一遍系统参数，逐条解析等于给列表多加一次查询
        $list = $this->methodBody($this->contentSrc, 'announcements()');
        $this->assertStringNotContainsString('AnnouncementSalutation::', $list, '列表接口不该解析敬语署名');

        $detail = $this->methodBody($this->contentSrc, 'announcementDetail()');
        $this->assertMatchesRegularExpression(
            '/\$salutation = AnnouncementSalutation::resolve\(\s*\$a\[\'greeting\'\] \?\? null,\s*\$a\[\'signature\'\] \?\? null,\s*\$a\[\'publish_time\'\] \?\? null,\s*\$a\[\'created_at\'\] \?\? null,\s*AnnouncementSalutation::defaults\(\)\s*\)/',
            $detail,
            '详情接口要走 resolve()（三级回落 + 按实际生效时间算日期），不要在这里自己拼 if-else'
        );
        foreach (['greeting', 'signature', 'signatureDate'] as $k) {
            $this->assertMatchesRegularExpression("/'{$k}'\s*=>\s*\\\$salutation\\['{$k}'\\]/", $detail,
                "详情响应缺 {$k} 字段，C 端那边就没得渲染");
        }
    }

    public function test_后台列表带全局默认且写入两列都有长度闸(): void
    {
        // ① 全局默认随列表下发：编辑弹窗要显示「留空会用哪句」。不新开接口的原因和 covers 一样 ——
        //    /admin/system/configs 要 system:config 权限，只开内容权限的运营点进去会 403
        $list = $this->methodBody($this->cmsSrc, 'announcementList()');
        $this->assertMatchesRegularExpression(
            '/\$this->paginate\([\s\S]*?\'salutation\' => AnnouncementSalutation::defaults\(\)/',
            $list,
            '后台公告列表的分页结果里要带 salutation，否则编辑弹窗显示不出「留空会用哪句」'
        );

        // ② 新建 + 编辑四处写入都要截到 MAX_CHARS（列宽 varchar(100)，超了严格模式直接报错）
        $this->assertSame(4, substr_count($this->cmsSrc, 'AnnouncementSalutation::MAX_CHARS'),
            '新建两列 + 编辑两列，四处落库都该过一次长度闸');
        foreach (['greeting', 'signature'] as $col) {
            // 每个入口都是「先判 param 有没有传，再取值」两次 × 新建与编辑两个入口 = 4
            $this->assertSame(4, substr_count($this->cmsSrc, "param('{$col}')"),
                "{$col} 应当在「新建」和「编辑」两个入口都收（少一个就是后台填了存不进去，多一个说明有第三处写入口）");
        }
    }

    // ================================================ C. 种子、列宽与跨端接线

    public function test_两份种子的默认文案逐字节相同且等于出厂常量(): void
    {
        $fromMigration = $this->seedRows($this->migrationSql);
        $fromFullInit  = $this->seedRows($this->fullInitSql);

        $this->assertSame($fromMigration, $fromFullInit,
            '「老库跟迁移」与「全新部署跑 full_init」装出来的默认敬语/落款不一致了（RUNBOOK §7.12 反复踩过的坑）');
        $this->assertSame(
            ['announcement_greeting_default', 'announcement_signature_default'],
            array_keys($fromMigration)
        );
        // 代码里的出厂常量 == 种子里的默认值：清空参数会回落到常量，两边不一致就等于有两份真相
        $this->assertSame(AnnouncementSalutation::DEFAULT_GREETING, $fromMigration[AnnouncementSalutation::GREETING_KEY][0]);
        $this->assertSame(AnnouncementSalutation::DEFAULT_SIGNATURE, $fromMigration[AnnouncementSalutation::SIGNATURE_KEY][0]);
    }

    public function test_参数描述写清了回落与日期不归这里管(): void
    {
        $rows = $this->seedRows($this->migrationSql);
        foreach ($rows as $key => [$value, $description]) {
            $this->assertStringContainsString('上限 100 字', $description,
                "{$key} 的描述要写明长度上限，否则运营填一半被截掉还以为是坏了");
            $this->assertStringContainsString('留空则用系统出厂值「' . $value . '」', $description,
                "{$key} 的描述要写明「清空不是没有，而是回落到出厂值」");
        }
        $this->assertStringContainsString('日期由系统按该公告生效时间自动生成',
            $rows[AnnouncementSalutation::SIGNATURE_KEY][1],
            '署名参数的描述要说明日期不在这里填，免得运营往这里写「2026年10月14日」');
    }

    public function test_两列列宽与服务里的长度上限对齐(): void
    {
        foreach (['greeting', 'signature'] as $col) {
            $m = [];
            $this->assertSame(1,
                preg_match('/ADD COLUMN ' . $col . ' VARCHAR\((\d+)\) NULL DEFAULT NULL/', $this->migrationSql, $m),
                "迁移里 {$col} 列的定义形状变了（可空 + 默认 NULL 是「空=跟随全局默认」的前提）");
            $this->assertSame(AnnouncementSalutation::MAX_CHARS, (int) $m[1],
                "nft_announcements.{$col} 的列宽和 MAX_CHARS 不一致：截断会静默丢字");

            $f = [];
            $this->assertSame(1,
                preg_match('/`' . $col . '` varchar\((\d+)\) COLLATE utf8mb4_unicode_ci DEFAULT NULL/', $this->fullInitSql, $f),
                "full_init.sql 里 {$col} 列的定义和迁移对不上");
            $this->assertSame((int) $m[1], (int) $f[1], "{$col} 两份 DDL 的列宽不一致");
        }
        // 两列都排在 cover_image 之后：公告的三个「这条自己可改」的字段是邻居
        $this->assertLessThan(strpos($this->fullInitSql, '`signature`'), strpos($this->fullInitSql, '`greeting`'));
    }

    public function test_迁移只补行不覆盖运营改过的文案(): void
    {
        $sql = $this->migrationSql;
        $this->assertSame(2, substr_count($sql, 'INSERT IGNORE INTO `nft_system_configs`'),
            '两个键各一条 INSERT IGNORE，缺行才补');
        $this->assertStringNotContainsString('UPDATE `nft_system_configs`', $sql,
            '带 UPDATE 的写法会把运营在后台改过的默认敬语/落款冲回出厂值');
        // DDL 幂等：先查 information_schema，列已存在就跳过，重复执行无副作用
        $this->assertSame(2, substr_count($sql, 'information_schema.COLUMNS'),
            '两个 ADD COLUMN 都该包在「列不存在才执行」的判断里');
        $this->assertSame(2, substr_count($sql, 'PREPARE stmt FROM @sql'),
            '动态 SQL 要走 PREPARE/EXECUTE，重复执行才不会因列已存在而报错');
    }

    public function test_后台发布弹窗两格可改且留空提示跟随全局(): void
    {
        $admin = dirname(__DIR__, 3) . '/sinan-admin/src';
        if (!is_dir($admin)) {
            $this->markTestSkipped('本地没有 sinan-admin 仓库，跳过跨仓检查');
        }
        $view   = $this->read($admin . '/views/content/Announcements.vue');
        $apiSrc = $this->read($admin . '/api/index.js');

        // ① 弹窗里两格都绑到表单，且占位符读的是接口带下来的全局默认（前端不抄出厂文案）
        foreach (['greeting', 'signature'] as $field) {
            $this->assertMatchesRegularExpression('/v-model="form\.' . $field . '"/', $view,
                "发布弹窗缺「{$field}」这一格，需求里的「署名在发布公告时可改」就落不了地");
            $this->assertMatchesRegularExpression('/:placeholder="salutation\.' . $field . '"/', $view,
                "{$field} 的占位符要用接口给的全局默认，写死一份就是第二处真相");
            $this->assertMatchesRegularExpression('/' . $field . ': a\.' . $field . " \|\| ''/", $view,
                '编辑已有公告时要把它自己填的值回填进弹窗');
            $this->assertMatchesRegularExpression('/' . $field . ': f\.' . $field . '\.trim\(\)/', $view,
                '保存要把弹窗的值提交上去（trim 后为空串 = 跟随全局默认）');
        }
        // ② api 层：读用列表映射、写用空串（空串 = 这条没填，后端回落全局；传 null 在编辑里是「不改这列」）
        foreach (['greeting', 'signature'] as $field) {
            $this->assertMatchesRegularExpression('/' . $field . ': s\(a\.' . $field . '\)/', $apiSrc,
                "api 层没把 {$field} 映射给弹窗（s() 是本项目统一的空值转空串）");
            $this->assertMatchesRegularExpression('/' . $field . ': payload\.' . $field . " \|\| ''/", $apiSrc,
                "{$field} 留空要传空串，别传 null 变成「不改这列」");
        }
        $this->assertMatchesRegularExpression('/salutation: d\.salutation \|\| \{\}/', $apiSrc,
            '列表返回要把后端带的 salutation 透出来，弹窗的占位符靠它');

        // ③ 需求点名的日期口径：弹窗里不该出现「自己填日期」的输入框
        $this->assertStringNotContainsString('signatureDate', $view,
            '落款日期由后端按实际生效时间算，后台不该出现填日期的口子');
    }

    public function test_后台全局参数页把两个默认做成可编辑项(): void
    {
        $page = dirname(__DIR__, 3) . '/sinan-admin/src/views/system/Config.vue';
        if (!is_file($page)) {
            $this->markTestSkipped('本地没有 sinan-admin 仓库，跳过跨仓检查');
        }
        $src = $this->read($page);
        foreach (AnnouncementSalutation::keys() as $key) {
            $this->assertMatchesRegularExpression("/{$key}:\\s*\\{[^}]*label: '/u", $src,
                "全局参数页缺「{$key}」的 META 条目：它会掉进「其他参数」，运营找不到");
            // 这两个是短句文本，不是图片也不是多行声明，用默认单行框（写了 image/multiline 反而错位）
            $this->assertMatchesRegularExpression("/{$key}:\\s*\\{[^}]*\\}/u", $src);
            $this->assertStringNotContainsString('image: true', $this->metaOf($src, $key),
                "{$key} 是文本参数，不该渲染成图片控件");
        }
        // 两个键归进「内容与公告」分组，和风险提示开关排在一起
        $this->assertMatchesRegularExpression(
            "/name: '内容与公告'[\s\S]{0,200}'announcement_greeting_default'[\s\S]{0,40}'announcement_signature_default'/",
            $src,
            '两个默认文案该挂在「内容与公告」分组里'
        );
    }

    /** 抠出 META 里某个键那一条的源码文本（用于「不该出现某属性」这类断言） */
    private function metaOf(string $src, string $key): string
    {
        $m = [];
        $this->assertSame(1, preg_match('/' . preg_quote($key, '/') . ': \{[^\n]*\}/u', $src, $m));
        return $m[0];
    }

    public function test_C端用纯文本渲染敬语与落款不经过vhtml(): void
    {
        $h5 = dirname(__DIR__, 3) . '/sinan-art-source/src';
        if (!is_dir($h5)) {
            $this->markTestSkipped('本地没有 sinan-art-source 仓库，跳过跨仓检查');
        }
        $store = $this->read($h5 . '/stores/notice.js');
        $view  = $this->read($h5 . '/views/NoticeDetail.vue');

        // store 只透传，不自己拼文案（拼了就是在前端做第二套优先级）
        foreach (['greeting', 'signature', 'signatureDate'] as $field) {
            $this->assertMatchesRegularExpression("/{$field}: a\.{$field} \|\| ''/", $store,
                "store 没透传 {$field}");
        }

        // 三个值都用 {{ }} 插值：后端给的是纯文本，走 v-html 等于把「运营填的字符串」当 HTML 解析
        foreach (['greeting', 'signature', 'signatureDate'] as $field) {
            $interp = '{{ notice.' . $field . ' }}';
            $this->assertStringContainsString($interp, $view,
                "C 端要用 {$interp} 插值渲染（不是 v-html）");
            $this->assertStringNotContainsString('v-html', $this->lineOf($view, $interp),
                "{$field} 落在了带 v-html 的元素里，纯文本口径破了");
        }

        // 位置口径：敬语在正文之前、落款在两个正文分支之外（富文本与纯文本都有落款）
        $this->assertLessThan((int) strpos($view, 'v-if="notice.richContent"'), (int) strpos($view, '{{ notice.greeting }}'),
            '敬语应排在正文块之前（详情页读起来才是「称呼 → 正文 → 落款」）');
        $this->assertMatchesRegularExpression(
            '/<div v-if="notice\.signature" class="notice-detail__foot">/', $view,
            '落款应整块条件渲染（旧后端没给字段时不留一行空白落款）'
        );
        $richPos  = (int) strpos($view, 'v-if="notice.richContent"');
        $plainPos = (int) strpos($view, 'v-else>');
        $footPos  = (int) strpos($view, 'notice-detail__foot"');
        $this->assertGreaterThan($richPos, $footPos, '落款不该嵌在富文本分支里面');
        $this->assertGreaterThan($plainPos, $footPos, '落款不该嵌在纯文本分支里面（那样富文本公告就没落款了）');

        // 写死的平台落款行已经被接口驱动的落款取代（否则一条公告会出现两个落款）
        // 只查模板段：样式注释里提一句「以前是写死的一行」是交接信息，不是代码
        $template = substr($view, 0, (int) strpos($view, '</template>'));
        $this->assertStringNotContainsString('司南文创 · 数字艺术品平台', $template,
            '模板里不该再留一行写死的落款');
    }

    /** 抠出 needle 所在的那一行（断言「这一行不该有别的东西」用） */
    private function lineOf(string $src, string $needle): string
    {
        $pos = strpos($src, $needle);
        $this->assertNotFalse($pos, "源码里找不到 {$needle}");
        $start = strrpos(substr($src, 0, $pos), "\n");
        $start = $start === false ? 0 : $start + 1;
        $end = strpos($src, "\n", $pos);
        return substr($src, $start, ($end === false ? strlen($src) : $end) - $start);
    }

    public function test_首图通宽居中圆角十像素且不再左右出血(): void
    {
        // 需求原话点名这一处：「公告首图放在顶部居中的位置，圆角10px」
        $view = dirname(__DIR__, 3) . '/sinan-art-source/src/views/NoticeDetail.vue';
        if (!is_file($view)) {
            $this->markTestSkipped('本地没有 sinan-art-source 仓库，跳过跨仓检查');
        }
        $src = $this->read($view);
        $m = [];
        $this->assertSame(1, preg_match('/&__cover \{([\s\S]*?)\n  \}/', $src, $m),
            'NoticeDetail.vue 的 __cover 样式块形状变了，这条防漂移要跟着一并改');

        $this->assertMatchesRegularExpression('/width:\s*100%;/', $m[1], '首图应通宽（不再 +32px 出血）');
        $this->assertMatchesRegularExpression('/margin:[^;]*auto[^;]*;/', $m[1], '首图应水平居中');
        $this->assertMatchesRegularExpression('/border-radius:\s*10px;/', $m[1], '首图圆角就是需求点名的 10px');
        $this->assertStringNotContainsString('calc(100% + 32px)', $m[1],
            '出血写法（宽度 +32px）与「居中」冲突，留着说明改漏了');
        $this->assertDoesNotMatchRegularExpression('/margin:[^;]*-\d+px/', $m[1],
            '负 margin 会把图推出卡片，那就不是「卡片内居中」了');
    }
}
