<?php
declare(strict_types=1);

namespace app\admin\middleware;

use app\admin\service\AdminAuthService;
use Closure;
use think\Request;
use think\Response;

/**
 * 管理后台 JWT 认证中间件
 *
 * 职责：
 * 1. 提取 Authorization: Bearer <token>（兼容 query 参数 token，用于文件下载等场景）
 * 2. 校验令牌签名与 scope=admin_access（与 C 端令牌完全隔离）
 * 3. 实时复核管理员状态（禁用/锁定/删除即刻生效，不依赖令牌剩余有效期）
 * 4. 注入轻量认证上下文 request->admin（含权限码集合）
 * 5. 节流更新 last_action_at（60 秒内不重复写库）
 */
class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);
        if ($token === '') {
            return json(['code' => 4001, 'message' => '未登录或令牌缺失', 'data' => null]);
        }

        $payload = AdminAuthService::verifyToken($token, 'admin_access');
        if ($payload === null) {
            return json(['code' => 4002, 'message' => '令牌无效或已过期，请重新登录', 'data' => null]);
        }

        $context = AdminAuthService::authContext((int) $payload['sub']);
        if ($context === null) {
            return json(['code' => 4003, 'message' => '账号不存在、已禁用或已锁定', 'data' => null]);
        }

        $request->admin = $context;

        return $next($request);
    }

    /**
     * 提取令牌：优先 Authorization 头，其次 query 参数（仅 GET）
     */
    private function extractToken(Request $request): string
    {
        $auth = (string) $request->header('authorization', '');
        if ($auth !== '' && stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        if ($auth !== '') {
            return trim($auth);
        }
        $queryToken = (string) $request->get('token', '');
        return $queryToken !== '' ? trim($queryToken) : '';
    }
}
