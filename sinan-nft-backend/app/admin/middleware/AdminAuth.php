<?php
declare(strict_types=1);

namespace app\admin\middleware;

use app\admin\service\AdminJwtService;
use Closure;
use think\facade\Db;
use think\Request;
use think\Response;

/**
 * 管理员认证中间件
 * 请求头 Authorization: Bearer {admin_access_token}
 * 校验通过后注入：$request->adminId / adminRole / adminName / adminUser
 */
class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('authorization', '');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return json(['code' => 401, 'message' => '未登录或token无效', 'data' => null]);
        }

        $token   = trim(substr($authHeader, 7));
        $payload = AdminJwtService::decode($token, 'admin');
        if (!$token || !$payload) {
            return json(['code' => 401, 'message' => 'token无效或已过期', 'data' => null]);
        }

        $adminId = (int) ($payload->aid ?? 0);
        if ($adminId <= 0) {
            return json(['code' => 401, 'message' => 'token失效', 'data' => null]);
        }

        // 实时校验账号状态（禁用/锁定即时生效，弥补 JWT 无法主动失效的缺口）
        $admin = Db::name('admin_users')
            ->where('id', $adminId)
            ->field('id,username,real_name,role,status,locked_until')
            ->find();

        if (!$admin || (int) $admin['status'] !== 1) {
            return json(['code' => 401, 'message' => '账号不存在或已禁用', 'data' => null]);
        }
        if (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time()) {
            return json(['code' => 401, 'message' => '账号已锁定，请联系超级管理员', 'data' => null]);
        }

        $request->adminId   = (int) $admin['id'];
        $request->adminRole = (int) $admin['role'];
        $request->adminName = $admin['real_name'] ?: $admin['username'];
        $request->adminUser = $admin;

        return $next($request);
    }
}
