<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 公告正文的「敬语」与「署名」的单一真源
 * （2026-10-14 新增，需求见 RUNBOOK §7.37：你说「每一条公告都要有敬语和署名，署名在发布公告时可改」）
 *
 * 一句话：C 端公告详情页正文开头那句称呼（尊敬的司南用户：）和结尾的落款（司南运营团队 + 发布日期），
 *          每条公告都可以自己填，没填就回落到「系统配置 → 全局参数」里的全局默认，
 *          全局默认那一行也缺了才用代码里的出厂值。
 *
 * 回落口径（三级，与公告头图 AnnouncementCover::coverFor 同一套路）：
 *   公告自己填的 > 全局参数默认 > 出厂常量。空串与纯空格一律算「没填」。
 *
 * 为什么这里有出厂常量，而 AnnouncementDisclaimer 特意不留兜底：
 *   风险提示是「可以不显示」的功能，库里为空就是不拼，那是显式结果；
 *   敬语与署名是**每条都必须有**，「运营把参数清空了」不能变成「公告页面上没有落款」这种静默缺失。
 *   两份真相的风险用防漂移用例堵住：
 *   tests/unit/AnnouncementSalutationTest.php 断言两份种子里的默认文案与本类常量**逐字节相同**，
 *   所以改出厂值必须同时改种子，否则测试红。
 *
 * 安全口径：两个字段对外输出的是**纯文本**，C 端用 {{ }} 插值渲染（不走 v-html），
 *   所以落款里写尖括号只会显示成字面量，不会变成标签。长度在出口处统一截到 MAX_OWN_CHARS。
 */
class AnnouncementSalutation
{
    /** 全局默认敬语的参数键 */
    public const GREETING_KEY = 'announcement_greeting_default';

    /** 全局默认署名的参数键 */
    public const SIGNATURE_KEY = 'announcement_signature_default';

    /** 出厂默认敬语（种子里必须与这里逐字节相同） */
    public const DEFAULT_GREETING = '尊敬的司南用户：';

    /** 出厂默认署名（落款名；日期由系统按生效发布时间自动带，不存进这里） */
    public const DEFAULT_SIGNATURE = '司南运营团队';

    /** 公告自带值与全局默认值的长度上限，与 nft_announcements.greeting / .signature 两列的 varchar(100) 对齐 */
    public const MAX_CHARS = 100;

    /** @return string[] 两个参数键（迁移与种子按这个顺序建行） */
    public static function keys(): array
    {
        return [self::GREETING_KEY, self::SIGNATURE_KEY];
    }

    /**
     * 纯函数：一条公告最终显示的敬语
     *
     * @param string|null          $own      nft_announcements.greeting 列（公告自己填的）
     * @param array<string,string> $defaults defaults() 的结果
     */
    public static function greetingFor(?string $own, array $defaults): string
    {
        return self::pick($own, $defaults[self::GREETING_KEY] ?? '', self::DEFAULT_GREETING);
    }

    /**
     * 纯函数：一条公告最终显示的落款名（日期另算，见 dateFor()）
     *
     * @param string|null          $own      nft_announcements.signature 列（公告自己填的）
     * @param array<string,string> $defaults defaults() 的结果
     */
    public static function signatureFor(?string $own, array $defaults): string
    {
        return self::pick($own, $defaults[self::SIGNATURE_KEY] ?? '', self::DEFAULT_SIGNATURE);
    }

    /**
     * 纯函数：落款后面那个日期，取「公告实际生效的时间」
     *
     * 为什么优先用 publish_time 而不是 created_at：定时公告是到点才对外可见的，
     * 落款写它被创建的那天，等于给用户一个比真实发布日早的日期。
     * 两个都取不到或形状不对 → 空串（C 端就只显示落款名，不显示半个日期）。
     *
     * 为什么要自己认形状而不是直接交给 strtotime：库里这类时间列允许出现 '0000-00-00 00:00:00'
     * （MySQL 的零值日期），strtotime 认它是「合法时间」，落到落款上就是「-0001年11月30日」这种
     * 用户看得见的乱码。所以先按 yyyy-mm-dd 认头一段，再用 checkdate() 过一遍日历有效性。
     */
    public static function dateFor(?string $publishTime, ?string $createdAt): string
    {
        foreach ([$publishTime, $createdAt] as $raw) {
            $value = trim((string) $raw);
            $parts = [];
            if ($value === '' || preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $value, $parts) !== 1) {
                continue;
            }
            if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                continue;
            }
            $ts = strtotime($value);
            if ($ts !== false) {
                return date('Y年n月j日', $ts);
            }
        }
        return '';
    }

    /**
     * 读全局默认文案（**一次查询**两行）
     *
     * 行缺失或值为空 → 用出厂常量顶上，保证「每条公告都有敬语署名」这条需求不会因为漏跑种子而失效。
     *
     * @return array{announcement_greeting_default: string, announcement_signature_default: string}
     */
    public static function defaults(): array
    {
        $rows = Db::name('system_configs')
            ->whereIn('config_key', self::keys())
            ->column('config_value', 'config_key');

        return [
            self::GREETING_KEY  => self::clip($rows[self::GREETING_KEY]  ?? '', self::DEFAULT_GREETING),
            self::SIGNATURE_KEY => self::clip($rows[self::SIGNATURE_KEY] ?? '', self::DEFAULT_SIGNATURE),
        ];
    }

    /**
     * 对外输出用的单条解析：接口只认这一个入口
     *
     * $defaults 由调用方给（就是 defaults() 那一次查询的结果），和 AnnouncementCover::resolve
     * 同一个形状：这样回落口径有一个出口，而纯函数部分不连库也能测。
     *
     * @param array<string,string> $defaults defaults() 的结果
     * @return array{greeting: string, signature: string, signatureDate: string}
     */
    public static function resolve(
        ?string $ownGreeting,
        ?string $ownSignature,
        ?string $publishTime,
        ?string $createdAt,
        array $defaults
    ): array {
        return [
            'greeting'      => self::greetingFor($ownGreeting, $defaults),
            'signature'     => self::signatureFor($ownSignature, $defaults),
            'signatureDate' => self::dateFor($publishTime, $createdAt),
        ];
    }

    /**
     * 后台表单用的全局默认（短键 greeting / signature）
     *
     * 为什么单独一个方法：defaults() 的键是参数键名（announcement_greeting_default），那是「系统配置」的身份证；
     * 弹窗那两格读的是 salutation.greeting / .signature（与 lastSalutation 同形）。2026-10-14 本地实测发现
     * 直接把 defaults() 下发时两个占位符与「如「司南运营团队 2026年10月14日」」那句提示都是空的 ——
     * 键名对不上，源码扫描的防漂移钉住了「用接口的值」，却钉不住「键名对不对」，只有浏览器量得出来。
     *
     * @return array{greeting: string, signature: string}
     */
    public static function defaultsForForm(): array
    {
        $d = self::defaults();
        return [
            'greeting'  => $d[self::GREETING_KEY],
            'signature' => $d[self::SIGNATURE_KEY],
        ];
    }

    /**
     * 纯函数：后台「新建公告」那两格的预填值
     *
     * 需求（2026-10-14 用户第二轮补充）：「署名在发布公告时填一次之后就一直沿用，不用每次都填，除非手动更换」。
     * 所以预填的不是全局默认，而是**上一条公告实际用的那句**；上一条没填（NULL/空）才继续按三级回落往下走。
     * 「上一条」里包含草稿与定时中的行 —— 运营刚敲进去的那句就是他此刻想要的，别因为没发布就当他没填。
     *
     * @param array<string,string|null> $own      上一条公告自带的 ['greeting' => …, 'signature' => …]
     * @param array<string,string>      $defaults defaults() 的结果
     */
    public static function lastUsedFor(?array $own, array $defaults): array
    {
        return [
            'greeting'  => self::greetingFor($own['greeting'] ?? null, $defaults),
            'signature' => self::signatureFor($own['signature'] ?? null, $defaults),
        ];
    }

    /**
     * 后台新建公告时的预填值（唯一出口，别在控制器或前端各算一遍）
     *
     * 按 created_at 取最新一条（**不按 is_top 排**：列表第一屏是置顶优先，
     *  搭那趟车会把「最老但被置顶的那条」当成上一条，预填成八百年前的文案）。
     * 一条公告都没有 → 空数组传进去，两个值就是全局默认（再缺就是出厂常量）。
     *
     * @return array{greeting: string, signature: string}
     */
    public static function lastUsed(): array
    {
        $row = Db::name('announcements')
            ->whereNull('deleted_at')
            ->field('greeting,signature')
            ->order('created_at', 'desc')
            ->order('id', 'desc')
            ->find();

        return self::lastUsedFor($row ?: null, self::defaults());
    }

    /** 三级回落：自带 > 全局默认 > 出厂 */
    private static function pick(?string $own, ?string $default, string $factory): string
    {
        $value = trim((string) $own);
        if ($value !== '') {
            return self::clip($value, $factory);
        }
        return self::clip($default, $factory);
    }

    /** 去空白 + 限长；空值直接返回兜底常量 */
    private static function clip(?string $value, string $factory): string
    {
        $clean = trim((string) $value);
        if ($clean === '') {
            return $factory;
        }
        return mb_substr($clean, 0, self::MAX_CHARS);
    }
}
