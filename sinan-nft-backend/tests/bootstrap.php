<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// 测试环境：绕开 ThinkPHP Facade 容器初始化，手动加载 .env 并注入 env() / aes_encrypt 辅助
// 与 ThinkPHP 解析规则一致：段名并入键名，查找时 '.' 归一为 '_'（database.HOSTNAME → DATABASE_HOSTNAME）
// 解析结果缓存在函数内 static：PHPUnit 会用快照覆盖引导脚本产生的全局变量，全局写法会静默变空
if (!function_exists('env')) {
    function env(?string $name = null, $default = null)
    {
        static $data = null;
        if ($data === null) {
            $data    = [];
            $file    = __DIR__ . '/../.env';
            $section = '';
            foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (str_starts_with($line, '[') && str_ends_with($line, ']')) {
                    $section = strtoupper(trim(trim($line, '[]'))) . '_';
                    continue;
                }
                if (($p = strpos($line, '=')) === false) continue;
                $key = strtoupper(trim(str_replace('.', '_', substr($line, 0, $p))));
                $data[$section . $key] = trim(substr($line, $p + 1));
            }
        }
        if ($name === null) return $data;
        return $data[strtoupper(str_replace('.', '_', trim($name)))] ?? $default;
    }
}
// 直接加载应用的真实辅助函数（app/common.php 只依赖 env()，已由上面的 shim 提供），
// 避免"手抄一份实现"与线上代码走偏——线上改了，测试这边还自我感觉良好地通过。
require_once __DIR__ . '/../app/common.php';
