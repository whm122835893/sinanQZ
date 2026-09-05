<?php
declare(strict_types=1);

namespace app\admin\middleware;

use app\admin\service\PermissionMap;
use Closure;
use think\Request;
use think\Response;

/**
 * 管理员权限校验中间件
 * 用法（路由携带权限码参数）：
 *   ->middleware(\app\admin\middleware\AdminPermission::class . ':collectible:release')
 * 超管直接放行；其余角色查 PermissionMap。
 */
class AdminPermission
{
    public function handle(Request $request, Closure $next, string $perm): Response
    {
        if (empty($request->adminRole)) {
            return json(['code' => 401, 'message' => '未登录', 'data' => null]);
        }

        if (!PermissionMap::has((int) $request->adminRole, $perm)) {
            return json([
                'code'    => 403,
                'message' => '无操作权限：' . PermissionMap::roleName((int) $request->adminRole) . '角色不拥有 ' . $perm,
                'data'    => null,
            ]);
        }

        return $next($request);
    }
}
