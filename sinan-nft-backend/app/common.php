<?php
// 公共辅助函数

/**
 * 生成随机邀请码（8 位大写字母数字）
 */
function gen_invite_code(int $len = 8): string
{
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code  = '';
    for ($i = 0; $i < $len; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

/**
 * 生成展示UID（U + 6位数字）
 */
function gen_uid(int $id): string
{
    return 'U' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

/**
 * 手机号脱敏
 */
function mask_phone(string $phone): string
{
    if (strlen($phone) !== 11) return $phone;
    return substr($phone, 0, 3) . '****' . substr($phone, -4);
}

/**
 * bcrypt 哈希交易密码
 */
function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

/**
 * 验证交易密码
 */
function verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

/**
 * AES-256 加密（实名信息）
 */
function aes_encrypt(string $data): string
{
    $key = hash('sha256', env('APP_KEY', 'sinan-nft-secret-key-2026'), true);
    $iv  = openssl_random_pseudo_bytes(16);
    return base64_encode($iv . openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv));
}

/**
 * AES-256 解密
 */
function aes_decrypt(string $encoded): ?string
{
    $decoded = base64_decode($encoded);
    if (strlen($decoded) < 48) return null;
    $key = hash('sha256', env('APP_KEY', 'sinan-nft-secret-key-2026'), true);
    $iv  = substr($decoded, 0, 16);
    $ct  = substr($decoded, 16);
    $pt  = openssl_decrypt($ct, 'AES-256-CBC', $key, 0, $iv);
    return $pt !== false ? $pt : null;
}

/**
 * camelCase 转换（snake_case → camelCase）
 */
function camelize(string $str): string
{
    return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $str))));
}

/**
 * 数组所有键从 snake_case 转为 camelCase
 */
function camelize_keys(array $arr): array
{
    $result = [];
    foreach ($arr as $k => $v) {
        $nk = is_string($k) ? camelize($k) : $k;
        $result[$nk] = is_array($v) ? camelize_keys($v) : $v;
    }
    return $result;
}

/**
 * 订单号：JC + 时间戳(12) + 随机(6) = 20 字符，精确适配 nft_orders.order_no VARCHAR(20)
 * 同秒碰撞概率 1/10^6，唯一索引 uk_order_no 兜底（插入失败抛异常回滚）
 */
function gen_order_no(): string
{
    return 'JC' . date('ymdHis')
        . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * 藏品编号占位串（20字符内，保证并发插入不撞唯一索引 uk_collectible_serial）
 */
function gen_serial_placeholder(): string
{
    return 'TMP-' . bin2hex(random_bytes(8));
}
