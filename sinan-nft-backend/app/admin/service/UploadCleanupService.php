<?php
declare(strict_types=1);

namespace app\admin\service;

use app\admin\controller\UploadController;
use think\facade\Db;

/**
 * 上传图片清理服务
 *
 * 引用判定：扫描 IMAGE_COLUMNS 各图片列 + KV_COLUMNS 配置值中出现的
 *  /uploads/... URL（含绝对 URL 的 path、JSON 内嵌），软删除记录也算引用。
 * 删除策略：移入回收站 runtime/upload_trash/{与 uploads 相同的相对路径}，
 *  由管理员显式 purge 才物理删除。
 *
 * 两道「不许碰」的保护（2026-10-12 体检 RUNBOOK 7.20 S3 补的，都收在 protectedReason() 一个闸门里）：
 *  1) 只有 BIZ_DIRS 列出的业务目录参与批量清理。公告（announcement）不在其中：正文插图原先不被引用扫描
 *     覆盖（现在已补上 content，但历史数据里还可能有什么我们不知道的地方在引用它），宁可留着当孤儿也别误清。
 *  2) 刚上传还没到宽限期的文件不参与清理。「先选图 → 后点保存」这段时间里图片是零引用状态，
 *     正在这时候点清理就会把正在编辑、马上要保存的图移走。
 *
 * 这两条只管「批量清理」这条路（scanUnreferenced + trashFiles）。
 * 换图 / 彻底删除记录这类**事件驱动**的 releaseIfUnreferenced() 不受目录与宽限期限制 ——
 * 它是管理员明确的动作，且逐条重查过引用，卡着不放只会留下真垃圾。
 */
class UploadCleanupService
{
    /** 可能存上传图 URL 的列（Db::name 表名 => 列名） */
    private const IMAGE_COLUMNS = [
        'admin_users'          => ['avatar'],
        // content = 公告正文富文本，里面可以有 <img src="/uploads/...">。
        // 2026-10-12 体检发现这里原先只扫 cover_image，正文里的图一律判成「零引用」，会被清理误伤。
        'announcements'        => ['cover_image', 'content'],
        'artifacts'            => ['image'],
        'banners'              => ['image'],
        'categories'           => ['icon'],
        'collectibles'         => ['image', 'icon'],
        'community_groups'     => ['icon', 'qr_code'],
        'inbox'                => ['image'],
        'lucky_draw_prizes'    => ['prize_image'],
        'synthesis_activities' => ['image'],
        'users'                => ['avatar'],
    ];

    /** KV 配置表：值内可能嵌 JSON/文本形式的图片 URL */
    private const KV_COLUMNS = [
        'site_settings'  => 'setting_value',
        'system_configs' => 'config_value',
    ];

    private const URL_REGEX = '#/uploads/[A-Za-z0-9_./-]+\.(?:jpe?g|png|webp|gif)#i';

    /**
     * 参与清理的业务目录（须与 UploadController::BIZ_DIRS 对齐，但**故意少一个 announcement**）
     *
     * 公告正文里的 <img> 走的是富文本，历史上不参与引用扫描，一旦纳入清理就会把正在用的正文图判成
     * 「零引用」移走。2026-10-12 体检时发现原先这条只写在注释里、扫描函数其实是在递归全目录，
     * 现在由 protectedReason() 真正拦下来（扫描与移入回收站两道都过这个闸门）。
     */
    private const BIZ_DIRS = ['collection', 'blindbox', 'marketing', 'content', 'misc', 'custom', 'artifact'];

    /**
     * 新上传文件的保护宽限期（小时）
     * 上传接口先落盘、页面后保存，中间这张图在任何表里都没有引用；
     * 运营「选完图去填别的字段」那几分钟里点清理，就会把马上要用的图移走。
     */
    private const NEW_UPLOAD_GRACE_HOURS = 24;

    public static function uploadsRoot(): string
    {
        return public_path() . 'uploads';
    }

    public static function trashRoot(): string
    {
        return runtime_path() . 'upload_trash';
    }

    /**
     * 唯一的「这张图不许碰」闸门：扫描列表与移入回收站都过它，改一处两边一起生效
     *
     * @param string $biz   业务目录名（/uploads/{biz}/... 的第一段）
     * @param int    $mtime 文件修改时间（秒）
     * @param int|null $now 判定时刻，测试用；默认取当前时间
     * @return string|null 返回 null 表示可以清理；否则返回给用户看的跳过原因
     */
    public function protectedReason(string $biz, int $mtime, ?int $now = null): ?string
    {
        $now ??= time();
        if (!in_array($biz, self::BIZ_DIRS, true)) {
            return sprintf('「%s」目录不在清理名单内，不参与自动清理', $biz);
        }
        $ageHours = max(0, ($now - $mtime) / 3600);
        if ($ageHours < self::NEW_UPLOAD_GRACE_HOURS) {
            return sprintf('上传不足 %d 小时（已传 %.1f 小时），可能还没点保存', self::NEW_UPLOAD_GRACE_HOURS, $ageHours);
        }
        return null;
    }

    /** 宽限期小时数，给接口和前端提示语用（不在这里重复写死数字） */
    public function graceHours(): int
    {
        return self::NEW_UPLOAD_GRACE_HOURS;
    }

    /** 不参与清理的业务目录，给接口和前端提示语用（以 UploadController 的白名单为准，不另抄一份） */
    public function excludedBizDirs(): array
    {
        return array_values(array_diff(UploadController::BIZ_DIRS, self::BIZ_DIRS));
    }

    /** 归一化任意输入为 /uploads/... 相对 URL；不是上传图返回 null */
    public function normalizeUploadUrl(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || stripos($raw, '/uploads/') === false) {
            return null;
        }
        if (preg_match(self::URL_REGEX, $raw, $m)) {
            $url = $m[0];
            // 去掉可能的尾部非文件名字符
            $url = rtrim($url, '.,;)\'"');
            $rel = substr($url, strlen('/uploads/'));
            foreach (explode('/', $rel) as $seg) {
                if ($seg === '' || $seg === '.' || $seg === '..') {
                    return null;
                }
            }
            return '/uploads/' . $rel;
        }
        return null;
    }

    /** 全量收集被引用 URL 集合（url => true），供批量扫描一次构建 */
    public function collectReferencedUrls(): array
    {
        $urls = [];
        foreach (self::IMAGE_COLUMNS as $table => $columns) {
            foreach ($columns as $col) {
                try {
                    $values = Db::name($table)->whereNotNull($col)->distinct(true)->column($col);
                } catch (\Throwable $e) {
                    continue;
                }
                foreach ($values as $v) {
                    $this->extractUrls((string) $v, $urls);
                }
            }
        }
        foreach (self::KV_COLUMNS as $table => $col) {
            try {
                $values = Db::name($table)->column($col);
            } catch (\Throwable $e) {
                continue;
            }
            foreach ($values as $v) {
                $this->extractUrls((string) $v, $urls);
            }
        }
        return $urls;
    }

    /** 单条 URL 是否仍被引用（LIKE 点查，供换图钩子与删除前复核） */
    public function isReferenced(string $normUrl): bool
    {
        $like = '%' . $normUrl . '%';
        foreach (self::IMAGE_COLUMNS as $table => $columns) {
            foreach ($columns as $col) {
                try {
                    if (Db::name($table)->whereLike($col, $like)->count() > 0) {
                        return true;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }
        foreach (self::KV_COLUMNS as $table => $col) {
            try {
                if (Db::name($table)->whereLike($col, $like)->count() > 0) {
                    return true;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
        return false;
    }

    /**
     * 扫描 uploads 目录中零引用文件
     *
     * 受保护的文件（业务目录不在清理范围 / 上传未过宽限期）**不进列表**，
     * 而是通过 $protected 带出来，让接口能如实告诉运营「为什么这里少了几张」。
     *
     * @param array<int, array{url:string,biz:string,size:int,mtime:string,reason:string}> $protected
     * @return array<int, array{url:string,biz:string,size:int,mtime:string}>
     */
    public function scanUnreferenced(?string $bizFilter = null, array &$protected = []): array
    {
        $referenced = $this->collectReferencedUrls();
        $root = self::uploadsRoot();
        $items = [];
        $protected = [];
        if (!is_dir($root)) {
            return $items;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $url = '/uploads/' . $rel;
            if (isset($referenced[$url])) {
                continue;
            }
            $biz = explode('/', $rel)[0];
            if ($bizFilter !== null && $biz !== $bizFilter) {
                continue;
            }
            $mtime = $file->getMTime();
            $reason = $this->protectedReason($biz, $mtime);
            if ($reason !== null) {
                $protected[] = [
                    'url'    => $url,
                    'biz'    => $biz,
                    'size'   => $file->getSize(),
                    'mtime'  => date('Y-m-d H:i:s', $mtime),
                    'reason' => $reason,
                ];
                continue;
            }
            $items[] = [
                'url'   => $url,
                'biz'   => $biz,
                'size'  => $file->getSize(),
                'mtime' => date('Y-m-d H:i:s', $mtime),
            ];
        }
        usort($items, fn ($a, $b) => strcmp($b['mtime'], $a['mtime']));
        return $items;
    }

    /**
     * 批量移入回收站（删除前逐条复核引用与保护闸门，被引用/受保护的跳过）
     *
     * 为什么勾选了还要再挡一遍：列表可以过期（打开页面后才上传的图、别的管理员刚保存的引用），
     * 真正动手前必须重新判定，而且和扫描用的是同一个 protectedReason()，两边口径不会漂。
     *
     * @param string[] $urls
     * @return array{moved:string[],referenced:string[],protected:string[],missing:string[],invalid:string[]}
     */
    public function trashFiles(array $urls): array
    {
        $result = ['moved' => [], 'referenced' => [], 'protected' => [], 'missing' => [], 'invalid' => []];
        $referencedSet = $this->collectReferencedUrls();
        foreach ($urls as $raw) {
            $norm = $this->normalizeUploadUrl((string) $raw);
            if ($norm === null) {
                $result['invalid'][] = (string) $raw;
                continue;
            }
            if (isset($referencedSet[$norm]) || $this->isReferenced($norm)) {
                $result['referenced'][] = $norm;
                continue;
            }
            $file = $this->absPath(self::uploadsRoot(), $norm);
            if (!is_file($file)) {
                $result['missing'][] = $norm;
                continue;
            }
            $biz = explode('/', substr($norm, strlen('/uploads/')))[0];
            $reason = $this->protectedReason($biz, (int) filemtime($file));
            if ($reason !== null) {
                $result['protected'][] = $norm . '（' . $reason . '）';
                continue;
            }
            $status = $this->relocate($norm, self::uploadsRoot(), self::trashRoot());
            if ($status === 'moved') {
                $result['moved'][] = $norm;
            } else {
                $result[$status][] = $norm;
            }
        }
        return $result;
    }

    /** 回收站内文件列表 */
    public function listTrash(): array
    {
        $root = self::trashRoot();
        $items = [];
        if (!is_dir($root)) {
            return $items;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $items[] = [
                'url'       => '/uploads/' . $rel,
                'size'      => $file->getSize(),
                'deletedAt' => date('Y-m-d H:i:s', $file->getMTime()),
            ];
        }
        usort($items, fn ($a, $b) => strcmp($b['deletedAt'], $a['deletedAt']));
        return $items;
    }

    /** 从回收站恢复到 uploads 原位；原位已有同名文件则失败 */
    public function restoreFiles(array $urls): array
    {
        $result = ['restored' => [], 'missing' => [], 'invalid' => [], 'conflict' => []];
        foreach ($urls as $raw) {
            $norm = $this->normalizeUploadUrl((string) $raw);
            if ($norm === null) {
                $result['invalid'][] = (string) $raw;
                continue;
            }
            $src = $this->absPath(self::trashRoot(), $norm);
            if (!is_file($src)) {
                $result['missing'][] = $norm;
                continue;
            }
            $dst = $this->absPath(self::uploadsRoot(), $norm);
            if (file_exists($dst)) {
                $result['conflict'][] = $norm;
                continue;
            }
            if (!is_dir(dirname($dst))) {
                mkdir(dirname($dst), 0755, true);
            }
            rename($src, $dst);
            $result['restored'][] = $norm;
        }
        return $result;
    }

    /** 物理删除回收站内文件 */
    public function purgeTrashFiles(array $urls): array
    {
        $result = ['purged' => [], 'missing' => [], 'invalid' => []];
        foreach ($urls as $raw) {
            $norm = $this->normalizeUploadUrl((string) $raw);
            if ($norm === null) {
                $result['invalid'][] = (string) $raw;
                continue;
            }
            $src = $this->absPath(self::trashRoot(), $norm);
            if (!is_file($src)) {
                $result['missing'][] = $norm;
                continue;
            }
            unlink($src);
            $result['purged'][] = $norm;
        }
        return $result;
    }

    /**
     * 换图/删记录钩子入口：URL 不再被引用则移入回收站（静默，不抛业务异常）
     */
    public function releaseIfUnreferenced(string $rawUrl): bool
    {
        $norm = $this->normalizeUploadUrl($rawUrl);
        if ($norm === null || $this->isReferenced($norm)) {
            return false;
        }
        return $this->relocate($norm, self::uploadsRoot(), self::trashRoot()) === 'moved';
    }

    public function bizDirs(): array
    {
        return self::BIZ_DIRS;
    }

    /** 值中出现的 /uploads/ 图片 URL 列表（支持 JSON/文本内嵌） */
    public function extractUploadUrls(string $value): array
    {
        $urls = [];
        $this->extractUrls($value, $urls);
        return array_keys($urls);
    }

    private function relocate(string $normUrl, string $fromRoot, string $toRoot): string
    {
        $src = $this->absPath($fromRoot, $normUrl);
        if (!is_file($src)) {
            return 'missing';
        }
        $dst = $this->absPath($toRoot, $normUrl);
        if (!is_dir(dirname($dst))) {
            mkdir(dirname($dst), 0755, true);
        }
        if (!rename($src, $dst)) {
            throw new \RuntimeException('文件移动失败：' . $normUrl);
        }
        return 'moved';
    }

    private function absPath(string $root, string $normUrl): string
    {
        $rel = substr($normUrl, strlen('/uploads/'));
        return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    private function extractUrls(string $value, array &$urls): void
    {
        if ($value === '' || stripos($value, '/uploads/') === false) {
            return;
        }
        if (preg_match_all(self::URL_REGEX, $value, $m)) {
            foreach ($m[0] as $u) {
                $u = rtrim($u, '.,;)\'"');
                $urls[$u] = true;
            }
        }
    }
}
