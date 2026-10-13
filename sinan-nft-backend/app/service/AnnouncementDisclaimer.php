<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 公告/新闻正文底部的「风险提示」生成器（2026-10-14 新增，口径见 RUNBOOK §7.32）
 *
 * 一句话：运营在后台发的每一条公告与新闻，C 端详情页正文下面自动接一段平台统一的风险提示，
 *          文案存在系统参数里、后台可改、实时生效不用发版，数据库的公告表一个字节都不动。
 *
 * 为什么只有这一个出口（单一收口）：
 *   公告正文对外只有一条路 —— GET /api/announcements/:id（app/controller/Content.php::announcementDetail）。
 *   公告列表与首页轮播只下发标题/摘要，没有正文可拼。所以提示拼不拼、拼什么，只由本类说了算；
 *   tests/unit/AnnouncementDisclaimerTest.php 里有一条防漂移用例盯着这件事。
 *
 * 为什么文案不在代码里写死一份当兜底：
 *   写死 = 同一个文案存在两份（代码一份、库里一份），运营改了库里的那份之后两者就永远不一致，
 *   而「以哪份为准」没人说得清。这里选择库里为唯一真相：
 *   行缺失或文案为空 → 不拼（显式结果，不静默回滚到旧文案）。
 *   所以这份 DDL 必须随版本执行，见 migrations/20261014_announcement_disclaimer.sql。
 *
 * 安全口径：config_value 是运营填的**纯文本**，本类只负责把它变成安全的 HTML ——
 *   逐行 htmlspecialchars，段落靠空行分隔，段内换行转 <br />。
 *   也就是说在提示文案里写 <b> 只会显示成字面量，不会变成标签，防止后台一处输入变成 C 端一段脚本。
 */
class AnnouncementDisclaimer
{
    /** 文案参数键（纯文本：第一行当标题，空行分段） */
    public const TEXT_KEY = 'announcement_disclaimer';

    /** 开关参数键：1=拼在公告正文底部 0=不拼（关掉即完全恢复改造前的行为） */
    public const ENABLED_KEY = 'announcement_disclaimer_enabled';

    /** 文案长度上限，与 SystemController::configSave() 的 mb_substr(…, 0, 5000) 对齐 */
    private const MAX_CHARS = 5000;

    /**
     * 读系统参数并生成 HTML 片段（本类唯一碰数据库的方法）
     *
     * 开关行缺失时按「开」处理：只有显式存 '0' 才关掉。
     * 这样新库忘了 INSERT 开关行，结果是「文案也没配 → 不拼」这种看得见的状态，
     * 而不是「配了文案却被一个不存在的开关静默关掉」。
     */
    public static function fromConfig(): string
    {
        $text = (string) Db::name('system_configs')->where('config_key', self::TEXT_KEY)->value('config_value');
        $flag = Db::name('system_configs')->where('config_key', self::ENABLED_KEY)->value('config_value');
        $enabled = $flag === null ? true : trim((string) $flag) === '1';

        return self::format($text, $enabled);
    }

    /**
     * 纯函数：纯文本 → 风险提示 HTML 片段（不包外层容器，容器由 C 端模板给，见 NoticeDetail.vue）
     *
     * 产出形态：
     *   <p class="warn-title">第一行</p><p>第二段</p><p>第三段<br />同段第二行</p>
     *
     * @param string|null $text    后台存的原始文本（\r\n / \r / \n 都收）
     * @param bool        $enabled 开关
     */
    public static function format(?string $text, bool $enabled): string
    {
        if (!$enabled) {
            return '';
        }

        // 统一换行符，再按「空行」切段；段内多行用 <br /> 连接
        $normalized = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $blocks     = preg_split('/\n{2,}/', $normalized) ?: [];

        $paragraphs = [];
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), static fn ($l) => $l !== ''));
            if ($lines) {
                $paragraphs[] = $lines;
            }
        }
        if (!$paragraphs) {
            return '';
        }

        $html     = '';
        $totalLen = 0;
        foreach ($paragraphs as $i => $lines) {
            $escaped = implode('<br />', array_map(
                static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8'),
                $lines
            ));
            $totalLen += mb_strlen(str_replace('<br />', '', $escaped), 'UTF-8');
            if ($totalLen > self::MAX_CHARS) {
                break; // 超长截断：丢整段而不是切半个字，避免留下断句
            }
            $html .= $i === 0
                ? '<p class="warn-title">' . $escaped . '</p>'
                : '<p>' . $escaped . '</p>';
        }

        return $html === '' ? '' : $html;
    }
}
