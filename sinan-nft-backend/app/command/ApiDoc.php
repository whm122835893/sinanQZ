<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 导出 OpenAPI 3.0 文档
 *
 * 用法：php think ApiDoc
 *
 * 在控制器方法 docblock 里写：
 *   @openapi({"_path":"/admin/xxx","get":{"summary":"xxx","tags":["X"]}})
 * 就能被扫进 openapi.json。
 */
class ApiDoc extends Command
{
    protected function configure()
    {
        $this->setName('ApiDoc')
            ->setDescription('导出 OpenAPI 3.0 文档到 runtime/openapi.json 和 public/api-docs.html');
    }

    protected function execute(Input $input, Output $output)
    {
        $projectRoot = rtrim(dirname((string) $this->app->getBasePath()), '/\\');
        // 多应用模式下 getBasePath() 指向 app/
        if (!is_dir($projectRoot . '/app')) {
            $projectRoot = rtrim(dirname($projectRoot), '/\\');
        }

        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title'       => '司南数字藏品平台 · 后端 API',
                'version'     => '3.0.0',
                'description' => 'ThinkPHP 8 + think-multi-app。鉴权 `Authorization: Bearer {token}`。响应 `{ code, message, data }`。',
            ],
            'servers' => [['url' => '/api', 'description' => 'API 前缀']],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                ],
            ],
            'security' => [['BearerAuth' => []]],
            'paths' => [],
        ];

        $scanned = 0;
        foreach (['admin', 'api'] as $appName) {
            $dir = $projectRoot . '/app/' . $appName . '/controller';
            if (!is_dir($dir)) continue;

            foreach (glob($dir . '/*.php') as $file) {
                $content = file_get_contents($file);
                if ($content === false) continue;

                // @openapi(JSON)  -- 简单匹配，不处理嵌套括号
                if (preg_match_all('/@openapi\s*\((.*?)\)\s*(?:\*\/|\n)/s', $content, $matches)) {
                    foreach ($matches[1] as $jsonStr) {
                        $jsonStr = trim($jsonStr);
                        $data = json_decode($jsonStr, true);
                        if (!is_array($data)) {
                            $fixed = preg_replace('/(\w+)\s*:/', '"$1":', $jsonStr);
                            $data = json_decode($fixed, true);
                        }
                        if (!is_array($data)) continue;

                        $path = $data['_path'] ?? '';
                        unset($data['_path']);
                        if ($path === '') continue;

                        if (!isset($spec['paths'][$path])) $spec['paths'][$path] = [];
                        $spec['paths'][$path] = array_merge($spec['paths'][$path], $data);
                        $scanned++;
                        $output->writeln("  + {$path} (" . basename($file) . ")");
                    }
                }
            }
        }

        @file_put_contents(
            $projectRoot . '/runtime/openapi.json',
            json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>司南数字藏品 API 文档</title>'
              . '<meta name="viewport" content="width=device-width, initial-scale=1"></head>'
              . '<body><div id="redoc"></div>'
              . '<script src="https://cdn.jsdelivr.net/npm/redoc@latest/bundles/redoc.standalone.js"></script>'
              . '<script>Redoc.init("openapi.json",{theme:{colors:{primary:{main:"#D00000"}}}});</script></body></html>';
        @file_put_contents($projectRoot . '/public/api-docs.html', $html);

        $output->writeln("");
        $output->writeln("扫描 @openapi 注释: {$scanned} 个");
        $output->writeln("JSON 文档:  runtime/openapi.json");
        $output->writeln("在线文档:  访问 public/api-docs.html （openapi.json 放在 Web 根目录）");

        return 0;
    }
}
