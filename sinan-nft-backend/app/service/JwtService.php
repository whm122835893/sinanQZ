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
        return JWT::encode($payload, env('jwt.SECRET'), env('jwt.ALGO', 'HS256'));
    }

    public static function decode(string $token): object
    {
        $key = new Key(env('jwt.SECRET'), env('jwt.ALGO', 'HS256'));
        return JWT::decode($token, $key);
    }
}
