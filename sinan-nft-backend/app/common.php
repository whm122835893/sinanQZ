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
 * 用户名脱敏（保留首尾，中间用 * 替换）
 */
function mask_name(string $name): string
{
    $len = mb_strlen($name);
    if ($len <= 1) return $name;
    if ($len === 2) return mb_substr($name, 0, 1) . '*';
    return mb_substr($name, 0, 1) . str_repeat('*', $len - 2) . mb_substr($name, -1);
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
 * 存储结构：base64( iv(16) . base64(ct) )，最短密文块 16 字节 → base64 后 24 字符
 * 故合法解码长度下限为 16 + 24 = 40（此前 48 会误杀 ≤11 字节明文的短实名）
 */
function aes_decrypt(string $encoded): ?string
{
    $decoded = base64_decode($encoded);
    if (strlen($decoded) < 40) return null;
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
 * 抽签码：报名后生成的抽签凭证（展示给用户），S + 日期(6) + 随机(6) = 13 字符
 */
function gen_draw_code(): string
{
    return 'S' . date('ymd')
        . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * 藏品编号占位串（20字符内，保证并发插入不撞唯一索引 uk_collectible_serial）
 */
function gen_serial_placeholder(): string
{
    return 'TMP-' . bin2hex(random_bytes(8));
}

/**
 * 默认昵称生成："司南-" + 手机号后 4 位
 */
function gen_default_nickname(string $phone): string
{
    $suffix = substr($phone, -4);
    // 理论上不会空，但兜底防止用户手机号异常时长度不足
    if ($suffix === '' || strlen($suffix) < 4) {
        $suffix = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    }
    return '司南-' . $suffix;
}

/**
 * 敏感词库（内置轻量版：注册必填的昵称字段使用，运营级词库可后续迁移到 DB / Redis）
 * 说明：
 *   - 数组里的每个元素是一个子串，采用简单的 str_contains 匹配
 *   - 命中即视为敏感，注册/改昵称时拒绝
 *   - 分四大类：政治、色情低俗、暴力辱骂、冒充平台
 */
function sensitive_words(): array
{
    static $words = null;
    if ($words !== null) return $words;

    $words = [
        // ---- 政治敏感 ----
        '反动', '颠覆', '台独', '港独', '藏独', '疆独', '法轮功', '六四',
        // ---- 色情低俗 ----
        '傻逼', '草泥马', '操你妈', '日你妈', '滚你妈', '傻B', 'SB', 'sb', 'MLGB', 'mlgb',
        '贱人', '婊子', '操逼', '傻逼逼', '二逼', '装逼', '傻逼',
        // ---- 暴力辱骂 ----
        '去死', '脑残', '废物', '垃圾', '滚蛋', '狗日', '王八', '王八蛋',
        // ---- 冒充平台/官方 ----
        '司南官方', '司南客服', '官方客服', '司南运营', '管理员', '版主', '站长', 'CEO', '腾讯客服',
        // ---- 其他 ----
        '赌博', '博彩', '比特币场外', 'USDT', '外盘',
    ];

    $words = array_values(array_unique($words));
    return $words;
}

/**
 * 昵称敏感词检测 + 过滤
 * 返回数组：{ ok: bool, nickname: string, reason: string }
 *   - ok=true  ：昵称可用（可能被替换过敏感词，但替换后仍保留 ok）
 *   - ok=false ：昵称存在敏感词且替换后为空 / 或直接拒绝（reason 字段说明原因）
 *
 * 处理策略：
 *   1. 先用 str_contains 做 O(n*m) 简单匹配（昵称最多 20 字，性能足够）
 *   2. 命中则用 * 替换对应子串（多命中多次替换）
 *   3. 替换后剩余字符 < 2 字视为无效 → 返回 ok=false
 *   4. 过滤后仍 2-20 字之间 → ok=true
 */
function filter_nickname(string $nickname): array
{
    $raw = $nickname;

    // 1. 基础清洗：去空格/HTML 标签（与 Auth::register 里 strip_tags 保持一致）
    $nickname = strip_tags(trim($nickname));

    // 2. 空昵称
    if ($nickname === '') {
        return ['ok' => false, 'nickname' => '', 'reason' => '昵称为空'];
    }

    // 3. 敏感词命中 → 替换为 *
    $hit = [];
    foreach (sensitive_words() as $w) {
        if ($w !== '' && str_contains($nickname, $w)) {
            $hit[] = $w;
            $nickname = str_replace($w, str_repeat('*', mb_strlen($w)), $nickname);
        }
    }

    // 4. 清洗后校验长度
    $len = mb_strlen($nickname);
    if ($len < 2) {
        return ['ok' => false, 'nickname' => $nickname, 'reason' => '昵称过短（过滤后不足 2 字）'];
    }
    if ($len > 20) {
        return ['ok' => false, 'nickname' => $nickname, 'reason' => '昵称过长（超过 20 字）'];
    }

    // 5. 全是星号 → 拒绝
    if (preg_match('/^[*]+$/', $nickname)) {
        return ['ok' => false, 'nickname' => $nickname, 'reason' => '昵称包含不允许的敏感内容'];
    }

    return [
        'ok'       => true,
        'nickname' => $nickname,
        'hit'      => $hit, // 命中的词（调用方可记录审计日志）
        'reason'   => $hit ? ('昵称包含敏感词：' . implode('、', $hit) . '，已自动打码') : '',
    ];
}
