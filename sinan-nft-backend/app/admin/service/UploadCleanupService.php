<?php
declare(strict_types=1);

namespace app\admin\service;

use think\facade\Db;

/**
 * 上传图片清理服务
 *
 * 引用判定：扫描 IMAGE_COLUMNS 各图片列 + KV_COLUMNS 配置值中出现的
 *  /uploads/... URL（含绝对 URL 的 path、JSON 内嵌），软删除记录也算引用。
 * 删除策略：移入回收站 runtime/upload_trash/{与 uploads 相同的相对路径}，
 *  由管理员显式 purge 才物理删除。
 */
class UploadCleanupService
{
    /** 可能存上传图 URL 的列（Db::name 表名 => 列名） */
    private const IMAGE_COLUMNS = [
        'admin_users'          => ['avatar'],
        'announcements'        => ['cover_image'],
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

    private const BIZ_DIRS = ['collection', 'blindbox', 'marketing', 'content', 'misc', 'custom'];

    public static function uploadsRoot(): string
    {
        return public_path() . 'uploads';
    }

    public static function trashRoot(): string
    {
        return runtime_path() . 'upload_trash';
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
     * @return array<int, array{url:string,biz:string,size:int,mtime:string}>
     */
    public function scanUnreferenced(?string $bizFilter = null): array
    {
        $referenced = $this->collectReferencedUrls();
        $root = self::uploadsRoot();
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
            $url = '/uploads/' . $rel;
            if (isset($referenced[$url])) {
                continue;
            }
            $biz = explode('/', $rel)[0];
            if ($bizFilter !== null && $biz !== $bizFilter) {
                continue;
            }
            $items[] = [
                'url'   => $url,
                'biz'   => $biz,
                'size'  => $file->getSize(),
                'mtime' => date('Y-m-d H:i:s', $file->getMTime()),
            ];
        }
        usort($items, fn ($a, $b) => strcmp($b['mtime'], $a['mtime']));
        return $items;
    }

    /**
     * 批量移入回收站（删除前逐条复核引用，被引用的跳过）
     *
     * @param string[] $urls
     * @return array{moved:string[],referenced:string[],missing:string[],invalid:string[]}
     */
    public function trashFiles(array $urls): array
    {
        $result = ['moved' => [], 'referenced' => [], 'missing' => [], 'invalid' => []];
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
