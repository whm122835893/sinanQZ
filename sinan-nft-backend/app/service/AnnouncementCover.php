<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 公告「子分类」以及每个子分类的**默认头图 / 标签色**的单一真源
 * （2026-10-14 新增，需求见 RUNBOOK §7.36：你说「选择哪个类型都有对应的图片，并且这个图片可以上传更换」）
 *
 * 一句话：公告有哪几个子分类、每个子分类叫什么、C 端详情页顶部那张横幅默认用哪张图、
 *          类型标签是什么颜色 —— 这四个问题的答案只写在本类里一处，后台筛选、后台表单、
 *          C 端接口三边都从这里取值或对齐这里。
 *
 * 为什么要收成一个类（而不是继续在三处写 ['activity','compose','operation']）：
 *   原来「合法子分类」这个事实抄了三份（CmsController 的列表筛选 / 新建 / 编辑），
 *   C 端与后台的中文名和顺序又各抄一份。加一个「寄售公告」就得同时改六处，
 *   漏掉任何一处的表现都是**静默**的：enumParam() 对不在白名单里的值是「当成没传」而不是报错，
 *   所以漏改筛选 = 筛不出寄售公告，漏改 C 端 = 寄售公告显示成「公告」而不是「寄售公告」。
 *   tests/unit/AnnouncementCoverTest.php 用正则把三端的字面量钉在一起，漏一处就红。
 *
 * 默认图为什么存在系统参数里而不是写死在代码里：
 *   写死 = 换一张默认图要走一次发版。存 nft_system_configs 后，运营在
 *   「系统配置 → 全局参数 → 公告头图」里换完当场生效（与公告风险提示 announcement_disclaimer 同一套路，见 §7.32）。
 *   出厂那四张图在 public/uploads/notice-default/：这个目录名不在 UploadController::BIZ_DIRS 里，
 *   图片清理服务的闸门（protectedReason）从一开始就不让它进清理范围，所以不会被移进回收站。
 *
 * 优先级口径（resolve()）：**公告自己上传的封面 > 该子分类的默认图 > 都没有则空串**（C 端不渲染横幅）。
 *   清空某个子分类的默认图参数 = 该类型的新公告不再有默认横幅，但已经上传过封面的公告不受影响。
 */
class AnnouncementCover
{
    /**
     * 子分类白名单（**顺序即展示顺序**：C 端 Tab、后台筛选、后台表单都按这个顺序排）
     *
     * - label      中文名，C 端详情页与后台标签显示它
     * - color      类型标签色，同时是出厂默认头图的主色（两者必须同一色系，换色要连图一起换）
     * - cover_key  该类型默认头图的系统参数键，值形如 /uploads/notice-default/xxx.png
     */
    public const SUBTYPES = [
        'activity'      => ['label' => '活动公告', 'color' => '#C00000', 'cover_key' => 'announcement_cover_activity'],
        'compose'       => ['label' => '合成公告', 'color' => '#B08D55', 'cover_key' => 'announcement_cover_compose'],
        'consignment'   => ['label' => '寄售公告', 'color' => '#E89121', 'cover_key' => 'announcement_cover_consignment'],
        'operation'     => ['label' => '运营公告', 'color' => '#3D5A99', 'cover_key' => 'announcement_cover_operation'],
    ];

    /** 新建公告的默认子分类（后台表单预选中它） */
    public const DEFAULT_SUBTYPE = 'operation';

    /** 出厂默认头图的存放目录（相对站点根）。改名要连 public/uploads/ 下的目录与两份种子一起改 */
    public const FACTORY_DIR = '/uploads/notice-default';

    /** 合法的 #rrggbb 标签色；公告自带的 tag_color 不是这个形状时一律回落到类型色 */
    private const HEX_COLOR = '/^#[0-9a-fA-F]{6}$/';

    /** 子分类值列表（后台三处 enumParam 的白名单从这里取，别在控制器里再抄一份字面量） */
    public static function values(): array
    {
        return array_keys(self::SUBTYPES);
    }

    /** 四个默认头图的参数键（迁移与种子按这个顺序建行） */
    public static function coverKeys(): array
    {
        return array_column(self::SUBTYPES, 'cover_key');
    }

    /** 是不是合法子分类 */
    public static function isSubtype(?string $subtype): bool
    {
        return $subtype !== null && isset(self::SUBTYPES[$subtype]);
    }

    /**
     * 归一：空值或库里存着的未知子分类，一律按「运营公告」处理
     *
     * 为什么要有一个兜底而不是「未知就显示未知」：subtype 列可空，存量公告里有 null，
     * 而 C 端与后台早就各自把 null 当 operation（stores/notice.js 的 `a.subtype || 'operation'`、
     * admin api 的 `payload.type || 'operation'`）。再留第三种兜底（"公告"）就是同一个 null
     * 在列表里叫运营公告、在详情里叫公告。兜底口径收在这一个方法里，两端都跟它一致。
     */
    public static function normalize(?string $subtype): string
    {
        return self::isSubtype($subtype) ? (string) $subtype : self::DEFAULT_SUBTYPE;
    }

    /** 中文名（未知/空 → 运营公告，见 normalize()） */
    public static function label(?string $subtype): string
    {
        return self::SUBTYPES[self::normalize($subtype)]['label'];
    }

    /** 类型标签色（十六进制，形如 #C00000） */
    public static function color(?string $subtype): string
    {
        return self::SUBTYPES[self::normalize($subtype)]['color'];
    }

    /**
     * 纯函数：单条公告的头图 = 自带封面优先，否则该类型的默认图，都没有则空串
     *
     * @param string|null    $subtype    公告的 subtype 列
     * @param string|null    $coverImage 公告的 cover_image 列
     * @param array<string,string> $defaults 子分类 → 默认图 URL，由 defaultCovers() 读一次库给出
     */
    public static function coverFor(?string $subtype, ?string $coverImage, array $defaults): string
    {
        $own = trim((string) $coverImage);
        if ($own !== '') {
            return $own;
        }
        return trim((string) ($defaults[self::normalize($subtype)] ?? ''));
    }

    /**
     * 纯函数：单条公告的类型标签色 = 公告自带的 tag_color（须是 #rrggbb）优先，否则用类型色
     *
     * 为什么要校验形状：tag_color 是后台自由填的字符串，而 C 端要把它写进 style。
     * 只认 #rrggbb 这一种形状，其余（含 "url(...)"、带分号的片段）一律回落到类型色，
     * 免得后台一个手滑或一个恶意值变成 C 端的样式注入。
     */
    public static function colorFor(?string $subtype, ?string $tagColor): string
    {
        $own = trim((string) $tagColor);
        return preg_match(self::HEX_COLOR, $own) === 1 ? strtolower($own) : self::color($subtype);
    }

    /**
     * 读四个子分类的默认头图（**一次查询**，列表接口在 map 之前调一次，别每条公告查一遍）
     *
     * 行缺失或值为空 → 该类型没有默认横幅，C 端就不渲染（与 AnnouncementDisclaimer 的
     * 「库里为唯一真相、不做代码兜底」同一口径：兜底一份就等于存在两份真相）。
     *
     * @return array<string,string> 子分类 → URL（不含自带封面的公告）
     */
    public static function defaultCovers(): array
    {
        $rows = Db::name('system_configs')
            ->whereIn('config_key', self::coverKeys())
            ->column('config_value', 'config_key');

        $covers = [];
        foreach (self::SUBTYPES as $subtype => $meta) {
            $covers[$subtype] = trim((string) ($rows[$meta['cover_key']] ?? ''));
        }
        return $covers;
    }

    /**
     * 对外输出用的单条解析：一次给出头图与标签色，接口只认这一个入口
     *
     * @param array<string,string> $defaults defaultCovers() 的结果
     * @return array{coverImage: string, tagColor: string}
     */
    public static function resolve(?string $subtype, ?string $coverImage, ?string $tagColor, array $defaults): array
    {
        return [
            'coverImage' => self::coverFor($subtype, $coverImage, $defaults),
            'tagColor'   => self::colorFor($subtype, $tagColor),
        ];
    }
}
