<?php
declare(strict_types=1);

namespace app\admin\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 管理后台权限校验中间件
 *
 * 用法（路由定义时传入权限码参数）：
 *   Route::post('user/freeze', 'User/freeze')
 *       ->middleware(AdminAuth::class)
 *       ->middleware(AdminPermission::class, 'user:freeze');
 *
 * 规则：
 * 1. 超级管理员（is_super）拥有全部权限
 * 2. 其他角色按权限码集合精确匹配（Permission 依赖 AdminAuth 先注入 context）
 */
class AdminPermission
{
    public function handle(Request $request, Closure $next, string $permission = ''): Response
    {
        $admin = $request->admin ?? null;

        if (!is_array($admin) || empty($admin['admin_id'])) {
            return json(['code' => 4001, 'message' => '认证上下文缺失，请重新登录', 'data' => null]);
        }

        if ($permission === '') {
            // 未指定权限码：仅需已登录（开放接口）
            return $next($request);
        }

        if (!empty($admin['is_super']) || in_array($permission, $admin['permissions'] ?? [], true)) {
            return $next($request);
        }

        return json([
            'code'    => 4003,
            'message' => '无操作权限（' . $permission . '），请联系超级管理员',
            'data'    => null,
        ]);
    }
}
