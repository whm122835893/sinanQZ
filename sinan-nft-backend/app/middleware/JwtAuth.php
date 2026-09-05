<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use think\Request;
use think\Response;

/**
 * JWT 认证中间件
 * 请求头 Authorization: Bearer {token}
 * 解析后将用户ID写入 $request->userId
 */
class JwtAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('authorization', '');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return json(['code' => 2001, 'message' => '未登录或token无效', 'data' => null]);
        }

        $token = trim(substr($authHeader, 7));
        if (!$token) {
            return json(['code' => 2001, 'message' => 'token为空', 'data' => null]);
        }

        try {
            $key   = new Key(env('jwt.SECRET', 'sinan-nft-secret'), env('jwt.ALGO', 'HS256'));
            $payload = JWT::decode($token, $key);
            $request->userId = $payload->sub ?? null;
            if (!$request->userId) {
                return json(['code' => 2001, 'message' => 'token失效', 'data' => null]);
            }
        } catch (ExpiredException) {
            return json(['code' => 2001, 'message' => 'token已过期', 'data' => null]);
        } catch (\Throwable $e) {
            return json(['code' => 2001, 'message' => 'token无效', 'data' => null]);
        }

        // 管理端强制登出黑名单（TTL=JWT有效期；缓存不可用时跳过，保持向后兼容）
        try {
            if (cache('force_logout_' . $request->userId)) {
                return json(['code' => 2001, 'message' => '账号已被强制登出，请重新登录', 'data' => null]);
            }
        } catch (\Throwable $e) {
            // 缓存异常时跳过踢出检查
        }

        return $next($request);
    }
}
