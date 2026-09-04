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

        $response->header([
            'Access-Control-Allow-Origin'  => $origin,
            'Access-Control-Allow-Methods' => $methods,
            'Access-Control-Allow-Headers' => $headers,
            'Access-Control-Allow-Credentials' => env('cors.CREDENTIALS', 'true'),
            'Access-Control-Max-Age'       => 3600,
        ]);

        return $response;
    }
}
