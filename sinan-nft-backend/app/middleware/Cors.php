<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * CORS 跨域中间件
 */
class Cors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $request->method() === 'OPTIONS'
            ? response('', 204)
            : $next($request);

        $origin  = env('cors.ORIGIN', '*');
        $methods = env('cors.METHODS', 'GET,POST,PUT,DELETE,OPTIONS');
        $headers = env('cors.HEADERS', 'Content-Type,Authorization,X-Requested-With,token');

        // M2 修复：Allow-Credentials=true 时 Allow-Origin 不能为 *，必须回显具体源。
        // 从请求头取 Origin，与白名单（env cors.ORIGIN 逗号分隔）匹配；配置为 * 时放行所有源（仅开发）。
        $reqOrigin = (string) $request->header('origin', '');
        $allowOrigin = '';
        if ($origin === '*') {
            // 开发模式：回显请求源（Credentials=true 下浏览器不接受 *）
            $allowOrigin = $reqOrigin !== '' ? $reqOrigin : '*';
        } else {
            $allowed = array_map('trim', explode(',', (string) $origin));
            if (in_array($reqOrigin, $allowed, true)) {
                $allowOrigin = $reqOrigin;
            }
        }

        $corsHeaders = [
            'Access-Control-Allow-Methods' => $methods,
            'Access-Control-Allow-Headers' => $headers,
            'Access-Control-Max-Age'       => 3600,
        ];
        if ($allowOrigin !== '') {
            $corsHeaders['Access-Control-Allow-Origin']      = $allowOrigin;
            $corsHeaders['Access-Control-Allow-Credentials'] = env('cors.CREDENTIALS', 'true');
            $corsHeaders['Vary'] = 'Origin';
        }
        $response->header($corsHeaders);

        return $response;
    }
}
