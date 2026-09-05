<?php
declare(strict_types=1);

namespace app\admin\service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * 管理员 JWT 服务
 * 与 C 端用户 JWT 密钥完全隔离（env [JWT_ADMIN] 段），claim 携带 typ=admin 防止跨端混用
 */
class AdminJwtService
{
    /**
     * 签发访问令牌（默认 2 小时）
     */
    public static function accessToken(int $adminId, int $role, string $username): string
    {
        return self::encode($adminId, $role, $username, 'admin', (int) env('jwt_admin.ACCESS_EXPIRE', 7200));
    }

    /**
     * 签发刷新令牌（默认 7 天）
     */
    public static function refreshToken(int $adminId, int $role, string $username): string
    {
        return self::encode($adminId, $role, $username, 'admin_refresh', (int) env('jwt_admin.REFRESH_EXPIRE', 604800));
    }

    private static function encode(int $adminId, int $role, string $username, string $type, int $ttl): string
    {
        $now = time();
        $payload = [
            'iss'      => env('jwt_admin.ISSUER', 'sinan-nft-admin'),
            'aud'      => env('jwt_admin.AUDIENCE', 'sinan-admin-client'),
            'iat'      => $now,
            'exp'      => $now + $ttl,
            'typ'      => $type,
            'aid'      => $adminId,
            'role'     => $role,
            'username' => $username,
        ];
        return JWT::encode($payload, self::secret(), env('jwt_admin.ALGO', 'HS256'));
    }

    /**
     * 解码并校验令牌类型
     * @return object|null 解码失败或类型不符返回 null
     */
    public static function decode(string $token, string $expectType = 'admin'): ?object
    {
        try {
            $key     = new Key(self::secret(), env('jwt_admin.ALGO', 'HS256'));
            $payload = JWT::decode($token, $key);
            if (($payload->typ ?? '') !== $expectType) {
                return null;
            }
            return $payload;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function secret(): string
    {
        return env('jwt_admin.SECRET', 'sinan-admin-jwt-secret-fallback-key-32bytes!');
    }
}
