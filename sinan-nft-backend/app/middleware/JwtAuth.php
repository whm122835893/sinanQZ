<?php
declare(strict_types=1);

namespace app\middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use think\Request;
use think\Response;
use think\facade\Db;

/**
 * JWT 认证中间件（C 端）
 * 请求头 Authorization: Bearer {token}
 * 解析后将用户ID写入 $request->userId
 *
 * 安全增强（管理后台联动）：
 * - 实时校验用户状态：冻结（status=0）账号即刻拒绝访问
 * - 实时校验黑名单：is_blacklisted=1 拒绝访问
 * - 强制登出：logout_before 非空时，签发时间（iat）早于该值的令牌全部失效
 *   （管理后台「强制登出」操作即写入该字段）
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

        // 实时状态校验（冻结/黑名单/强制登出即刻生效）
        $user = Db::name('users')
            ->where('id', $request->userId)
            ->whereNull('deleted_at')
            ->field('id, status, is_blacklisted, logout_before')
            ->find();
        if (!$user) {
            return json(['code' => 2001, 'message' => '账号不存在', 'data' => null]);
        }
        if ((int) $user['status'] !== 1) {
            return json(['code' => 2003, 'message' => '账号已被冻结，请联系客服', 'data' => null]);
        }
        if ((int) $user['is_blacklisted'] === 1) {
            return json(['code' => 2003, 'message' => '账号已被列入黑名单，禁止访问', 'data' => null]);
        }
        if (!empty($user['logout_before']) && isset($payload->iat)
            && (int) $payload->iat <= strtotime((string) $user['logout_before'])) {
            return json(['code' => 2001, 'message' => '登录已失效，请重新登录', 'data' => null]);
        }

        return $next($request);
    }
}
