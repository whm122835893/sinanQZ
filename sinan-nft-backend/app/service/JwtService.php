<?php
declare(strict_types=1);

namespace app\service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT 服务
 */
class JwtService
{
    /**
     * C 端 JWT 密钥：必须显式配置（jwt.SECRET，≥32 字节），
     * 禁止回落源码默认值——源码可见默认密钥等于任何人可伪造用户令牌。
     * 未配置/过短时抛异常：登录签发立即失败（fail-closed）。
     */
    public static function secret(): string
    {
        $secret = (string) env('jwt.SECRET', '');
        if ($secret === '' || strlen($secret) < 32) {
            \think\facade\Log::error('C 端 JWT 密钥未配置或不安全：请在 .env 设置 jwt.SECRET（≥32 字节随机串）');
            throw new \RuntimeException('user jwt secret not configured or too short');
        }
        // 生产环境（APP_DEBUG=false）拒绝 .example.env 占位串（change-me 标记）：防止照抄示例配置直接上线
        if (!env('APP_DEBUG', false) && stripos($secret, 'change-me') !== false) {
            \think\facade\Log::error('C 端 JWT 密钥仍为示例占位串：生产环境必须替换为随机串');
            throw new \RuntimeException('user jwt secret is a placeholder, replace it before production use');
        }
        return $secret;
    }

    public static function encode(int $userId, string $phone): string
    {
        $now  = time();
        $payload = [
            'iss' => env('jwt.ISSUER', 'sinan-nft-audience'),
            'aud' => env('jwt.AUDIENCE', 'sinan-nft-client'),
            'iat' => $now,
            'exp' => $now + (int) env('jwt.EXPIRE', 86400),
            'sub' => $userId,
            'phone' => $phone,
        ];
        return JWT::encode($payload, self::secret(), env('jwt.ALGO', 'HS256'));
    }

    public static function decode(string $token): object
    {
        $key = new Key(self::secret(), env('jwt.ALGO', 'HS256'));
        return JWT::decode($token, $key);
    }
}
