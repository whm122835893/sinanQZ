<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use think\Request;
use think\Response;

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
                    $key    = new Key(env('jwt.SECRET', 'sinan-nft-secret'), env('jwt.ALGO', 'HS256'));
                    $payload = JWT::decode($token, $key);
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
