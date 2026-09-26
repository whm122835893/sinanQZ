<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;
use app\service\JwtService;

/**
 * 可选 JWT 中间件
 * 公开接口在携带有效 token 时注入 userId（增强展示，如 myOwned/myAvailable），
 * 未携带或 token 无效时放行（userId 保持 null，接口按匿名处理）
 */
class OptionalJwtAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('authorization', '');

        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = trim(substr($authHeader, 7));
            if ($token) {
                try {
                    // 与 JwtAuth 同源解码：校验容差只在 JwtService::decode 里注入
                    $payload = JwtService::decode($token);
                    if (!empty($payload->sub)) {
                        $request->userId = (int) $payload->sub;
                    }
                } catch (\Throwable) {
                    // 无效 token 不阻断公开接口
                }
            }
        }

        return $next($request);
    }
}
