<?php
declare(strict_types=1);

namespace app\admin\service;

use think\facade\Db;
use think\Response;

/**
 * 管理后台 JWT 认证服务
 *
 * 与 C 端 JWT 完全隔离：独立密钥（jwt.ADMIN_*）、独立 payload（scope=admin），
 * C 端 token 无法访问 Admin 接口，Admin token 无法访问 C 端接口。
 */
class AdminAuthService
{
    /** 访问令牌有效期（秒），默认 12 小时 */
    public const ACCESS_TTL = 43200;

    /** 刷新令牌有效期（秒），默认 7 天 */
    public const REFRESH_TTL = 604800;

    /**
     * 统一失败响应（与 AdminResponse trait 的输出格式一致：code/message/data）
     * 服务类不使用控制器 trait，避免匿名类可见性问题
     */
    private static function fail(int $code = 5000, string $message = '系统内部错误', mixed $data = null): Response
    {
        return json(['code' => $code, 'message' => $message, 'data' => $data]);
    }

    /**
     * 统一成功响应
     */
    private static function success(mixed $data = null, string $message = 'ok'): Response
    {
        return json(['code' => 200, 'message' => $message, 'data' => $data]);
    }

    private static function secret(): string
    {
        // HS256 要求密钥 >= 32 字节（firebase/php-jwt 强制校验）
        $secret = (string) env('jwt.ADMIN_SECRET', 'sinan-nft-admin-jwt-secret-2026-strong-hmac-key');
        return strlen($secret) >= 32 ? $secret : str_pad($secret, 32, '#');
    }

    private static function algo(): string
    {
        return (string) env('jwt.ALGO', 'HS256');
    }

    /**
     * 签发令牌对（访问 + 刷新）
     */
    public static function issueTokens(array $admin, string $ip = ''): array
    {
        $now       = time();
        $baseClaim = [
            'iss'     => (string) env('jwt.ISSUER', 'sinan-nft'),
            'aud'     => 'sinan-admin',
            'sub'     => (int) $admin['id'],
            'role'    => (int) $admin['role_id'],
            'username' => (string) $admin['username'],
            'iat'     => $now,
            'jti'     => bin2hex(random_bytes(8)),
        ];

        $accessClaim          = $baseClaim;
        $accessClaim['scope'] = 'admin_access';
        $accessClaim['exp']   = $now + (int) env('jwt.ADMIN_EXPIRE', self::ACCESS_TTL);

        $refreshClaim          = $baseClaim;
        $refreshClaim['scope'] = 'admin_refresh';
        $refreshClaim['exp']   = $now + self::REFRESH_TTL;

        return [
            'token'         => \Firebase\JWT\JWT::encode($accessClaim, self::secret(), self::algo()),
            'refresh_token' => \Firebase\JWT\JWT::encode($refreshClaim, self::secret(), self::algo()),
            'expire_at'     => date('Y-m-d H:i:s', $accessClaim['exp']),
        ];
    }

    /**
     * 校验令牌；$scope 限定 admin_access / admin_refresh
     *
     * @return array|null payload，失败返回 null
     */
    public static function verifyToken(string $token, string $scope = 'admin_access'): ?array
    {
        try {
            $key     = new \Firebase\JWT\Key(self::secret(), self::algo());
            $payload = (array) \Firebase\JWT\JWT::decode($token, $key);
            if (($payload['scope'] ?? '') !== $scope) {
                return null;
            }
            return $payload;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 登录校验（含锁定/禁用/失败计数），成功返回令牌对
     */
    public static function login(string $username, string $password, string $ip, string $userAgent): Response
    {
        $failLimit = (int) (Db::name('system_configs')->where('config_key', 'admin_login_fail_limit')->value('config_value') ?: 5);
        $lockMins  = (int) (Db::name('system_configs')->where('config_key', 'admin_lock_minutes')->value('config_value') ?: 15);
        $now       = date('Y-m-d H:i:s');

        $admin = Db::name('admin_users')->where('username', $username)->whereNull('deleted_at')->find();
        if (!$admin) {
            self::logLogin(null, $username, 2, '账号不存在', $ip, $userAgent);
            return self::fail(4001, '账号或密码错误');
        }

        // 锁定校验
        if ($admin['locked_until'] && $admin['locked_until'] > $now) {
            self::logLogin((int) $admin['id'], $username, 2, '账号锁定中', $ip, $userAgent);
            return self::fail(4002, '账号已锁定，请 ' . $admin['locked_until'] . ' 后重试');
        }

        // 禁用校验
        if ((int) $admin['status'] !== 1) {
            self::logLogin((int) $admin['id'], $username, 2, '账号禁用', $ip, $userAgent);
            return self::fail(4003, '账号已被禁用，请联系超级管理员');
        }

        // 密码校验
        if (!password_verify($password, $admin['password_hash'])) {
            $failCount = (int) $admin['login_fail_count'] + 1;
            $update    = ['login_fail_count' => $failCount, 'updated_at' => $now];
            if ($failLimit > 0 && $failCount >= $failLimit) {
                $update['locked_until'] = date('Y-m-d H:i:s', time() + $lockMins * 60);
                $update['login_fail_count'] = 0;
            }
            Db::name('admin_users')->where('id', $admin['id'])->update($update);
            self::logLogin((int) $admin['id'], $username, 2, '密码错误', $ip, $userAgent);
            return self::fail(4001, '账号或密码错误');
        }

        // 登录成功：清零失败计数、记录登录信息
        Db::name('admin_users')->where('id', $admin['id'])->update([
            'login_fail_count' => 0,
            'locked_until'     => null,
            'last_login_at'    => $now,
            'last_login_ip'    => $ip,
            'last_action_at'   => $now,
        ]);
        self::logLogin((int) $admin['id'], $username, 1, null, $ip, $userAgent);

        $tokens = self::issueTokens($admin, $ip);
        $adminInfo = self::adminInfo((int) $admin['id']);

        return self::success([
            'token'         => $tokens['token'],
            'refresh_token' => $tokens['refresh_token'],
            'expire_at'     => $tokens['expire_at'],
            'admin'         => $adminInfo,
        ]);
    }

    /**
     * 轻量认证上下文（中间件每请求调用：管理员基础信息 + 权限码集合，不含菜单树）
     *
     * @return array|null 账号无效/禁用/锁定时返回 null
     */
    public static function authContext(int $adminId): ?array
    {
        $admin = Db::name('admin_users')->alias('au')
            ->field('au.id, au.username, au.real_name, au.status, au.locked_until, au.last_action_at,
                     r.id AS role_id, r.name AS role_name, r.code AS role_code, r.status AS role_status')
            ->join('admin_roles r', 'r.id = au.role_id')
            ->where('au.id', $adminId)
            ->whereNull('au.deleted_at')
            ->find();

        if (!$admin || (int) $admin['status'] !== 1 || (int) $admin['role_status'] !== 1) {
            return null;
        }
        $now = date('Y-m-d H:i:s');
        if ($admin['locked_until'] && $admin['locked_until'] > $now) {
            return null;
        }

        $isSuper = $admin['role_code'] === 'super_admin';
        if ($isSuper) {
            $permissions = Db::name('admin_permissions')->where('status', 1)->column('code');
        } else {
            $permissions = Db::name('admin_permissions')->alias('p')
                ->join('admin_role_permissions rp', 'rp.permission_id = p.id')
                ->where('rp.role_id', $admin['role_id'])
                ->where('p.status', 1)
                ->column('p.code');
        }

        // 节流更新最后操作时间（60 秒内不重复写库）
        $lastAction = (string) ($admin['last_action_at'] ?: '');
        if ($lastAction === '' || (time() - strtotime($lastAction)) > 60) {
            try {
                Db::name('admin_users')->where('id', $adminId)->update(['last_action_at' => $now]);
            } catch (\Throwable $e) {
                // 更新失败不影响主流程
            }
        }

        return [
            'admin_id'    => (int) $admin['id'],
            'username'    => (string) $admin['username'],
            'real_name'   => (string) $admin['real_name'],
            'role_id'     => (int) $admin['role_id'],
            'role_name'   => (string) $admin['role_name'],
            'role_code'   => (string) $admin['role_code'],
            'is_super'    => $isSuper,
            'permissions' => array_values(array_unique($permissions)),
        ];
    }

    /**
     * 管理员信息（含角色、权限码集合；超管拥有全部权限标记）
     */
    public static function adminInfo(int $adminId): array
    {
        $admin = Db::name('admin_users')->alias('au')
            ->field('au.id, au.username, au.real_name, au.avatar, au.phone, au.email, au.status,
                     au.last_login_at, au.last_login_ip, r.id AS role_id, r.name AS role_name, r.code AS role_code')
            ->join('admin_roles r', 'r.id = au.role_id')
            ->where('au.id', $adminId)
            ->whereNull('au.deleted_at')
            ->find();
        if (!$admin) {
            return [];
        }

        $permissions = [];
        $menus       = [];
        $isSuper     = $admin['role_code'] === 'super_admin';

        if ($isSuper) {
            $all = Db::name('admin_permissions')->where('status', 1)->order('sort_order')->select()->toArray();
            $permissions = array_column($all, 'code');
            $menus       = self::buildMenuTree($all);
        } else {
            $all = Db::name('admin_permissions')->alias('p')
                ->join('admin_role_permissions rp', 'rp.permission_id = p.id')
                ->where('rp.role_id', $admin['role_id'])
                ->where('p.status', 1)
                ->order('p.sort_order')
                ->select()->toArray();
            $permissions = array_column($all, 'code');
            $menus       = self::buildMenuTree($all);
        }

        $admin['id']            = (int) $admin['id'];
        $admin['is_super']      = $isSuper;
        $admin['permissions']   = array_values(array_unique($permissions));
        $admin['menus']         = $menus;
        return camelize_keys($admin);
    }

    /**
     * 由权限集合构建菜单树（type=1 菜单项）
     */
    public static function buildMenuTree(array $permissions): array
    {
        $menus = array_values(array_filter($permissions, fn ($p) => (int) $p['type'] === 1));
        if (!$menus) {
            return [];
        }
        $byId = [];
        foreach ($menus as $m) {
            $byId[(int) $m['id']] = [
                'id'     => (int) $m['id'],
                'name'   => $m['name'],
                'code'   => $m['code'],
                'path'   => $m['path'] ?: '',
                'icon'   => $m['icon'] ?: '',
                'sort'   => (int) $m['sort_order'],
                'children' => [],
            ];
        }
        $tree = [];
        foreach ($byId as $id => $node) {
            $parentId = $node['id'];
            // 找到原始记录的 parent_id
            foreach ($menus as $m) {
                if ((int) $m['id'] === $id) {
                    $parentId = (int) $m['parent_id'];
                    break;
                }
            }
            if ($parentId > 0 && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$byId[$id];
            } else {
                $tree[] = &$byId[$id];
            }
        }
        return array_values($tree);
    }

    private static function logLogin(?int $adminId, string $username, int $status, ?string $reason, string $ip, string $ua): void
    {
        try {
            Db::name('admin_login_logs')->insert([
                'admin_id'   => $adminId,
                'username'   => $username,
                'status'     => $status,
                'reason'     => $reason,
                'ip'         => $ip ?: null,
                'user_agent' => mb_substr($ua, 0, 500),
                'created_at' => date('Y-m-d H:i:s.v'),
            ]);
        } catch (\Throwable $e) {
            // 日志失败不阻断登录
        }
    }
}
