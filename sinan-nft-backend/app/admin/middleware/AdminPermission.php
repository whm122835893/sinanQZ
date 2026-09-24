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

        if (!empty($admin['is_super'])) {
            return $next($request);
        }

        // 支持逗号分隔多权限码：命中任一即放行（组粗码 + 路由细码 OR 语义）
        $required = array_filter(array_map('trim', explode(',', $permission)));
        $owned    = $admin['permissions'] ?? [];
        foreach ($required as $code) {
            if (in_array($code, $owned, true)) {
                return $next($request);
            }
        }

        return json([
            'code'    => 4003,
            'message' => '无操作权限（' . $permission . '），请联系超级管理员',
            'data'    => null,
        ]);
    }
}
