<?php
declare(strict_types=1);

namespace app\service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use think\facade\Db;

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
        // 生产环境（APP_DEBUG=false）拒绝 .env.example 占位串（change-me 标记）：防止照抄示例配置直接上线
        if (!env('APP_DEBUG', false) && stripos($secret, 'change-me') !== false) {
            \think\facade\Log::error('C 端 JWT 密钥仍为示例占位串：生产环境必须替换为随机串');
            throw new \RuntimeException('user jwt secret is a placeholder, replace it before production use');
        }
        return $secret;
    }

    /**
     * Firebase JWT 的 iat/nbf/exp 校验共用全局 $leeway（默认 0），iat 领先服务器瞬时即抛 BeforeValidException。
     * iatAfterLogout() 会把令牌 iat 抬到 logout_before + 1（最多领先服务器 1 秒），
     * 所以解码必须先留 2 秒容差，否则「改密后当场换发/重新登录」的首个请求就是 401。
     */
    private static function init(): void
    {
        JWT::$leeway = 2;
    }

    public static function encode(int $userId, string $phone): string
    {
        $now  = self::iatAfterLogout($userId);
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

    /**
     * 签发时间：iat 只有秒精度，而 JwtAuth 按 `iat <= logout_before` 判失效。
     * 若在同一秒内先重置密码（写 logout_before）再重新登录，刚签出的令牌会立即被判死，
     * 客户端表现为「登录成功但首个请求 401」。这里把 iat 抬到该秒之后：
     * 旧令牌的失效判定不受影响（仍要求 iat <= logout_before），新会话则当场可用。
     */
    private static function iatAfterLogout(int $userId): int
    {
        $now = time();
        $logoutBefore = Db::name('users')->where('id', $userId)->value('logout_before');
        if (empty($logoutBefore)) {
            return $now;
        }
        try {
            $ts = (new \DateTimeImmutable((string) $logoutBefore, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception $e) {
            return $now;
        }
        return $now <= $ts ? $ts + 1 : $now;
    }

    public static function decode(string $token): object
    {
        self::init();
        $key = new Key(self::secret(), env('jwt.ALGO', 'HS256'));
        return JWT::decode($token, $key);
    }
}
