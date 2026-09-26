<?php
declare(strict_types=1);

namespace tests;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * API 集成测试基类（HTTP 级黑盒测试）
 *
 * 前置条件（与开发/CI 环境一致）：
 *   1. 后端已启动：php think run -p 8080（APP_DEBUG=true）
 *   2. MySQL 已导入 full_init.sql 基线（sms mock 渠道启用、图形码开关关闭）
 *
 * 数据隔离：测试统一使用 1390000**** 号段与 TEST- 前缀藏品，
 * 每个用例 tearDown 级联清理，不污染业务数据。
 */
abstract class ApiTestCase extends TestCase
{
    protected const BASE = 'http://127.0.0.1:8080';
    protected const TX_PWD = 'Tx@123456';

    private static ?PDO $pdo = null;

    /** @var string[] 本用例创建的测试手机号（tearDown 级联清理） */
    protected array $phones = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $probe = self::httpProbe('GET', '/api/payments/available');
        if ($probe === null) {
            self::fail("后端未启动：请先执行 php think run -p 8080（需 APP_DEBUG=true 的开发态）");
        }
        self::healLeftovers();
    }

    protected function tearDown(): void
    {
        foreach ($this->phones as $phone) {
            $this->cleanupPhone($phone);
        }
        self::purgeTestCollectibles();
        parent::tearDown();
    }

    /**
     * 自愈：清理历史失败运行遗留的测试数据（1390000 号段用户 + TEST- 藏品）
     * 幂等，套件启动时执行一次
     */
    private static function healLeftovers(): void
    {
        $stmt = self::pdo()->query("SELECT id FROM nft_users WHERE phone LIKE '1390000%'");
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($userIds) {
            self::deleteUserData($userIds);
            $in = implode(',', array_fill(0, count($userIds), '?'));
            self::pdo()->prepare("DELETE FROM nft_users WHERE id IN ($in)")->execute($userIds);
        }
        self::purgeTestCollectibles();
    }

    /** 清理 TEST- 前缀藏品及全部子行（含历史遗留，按 FK 依赖顺序） */
    private static function purgeTestCollectibles(): void
    {
        $ids = "SELECT id FROM nft_collectibles WHERE name LIKE 'TEST-%'";
        foreach ([
            "DELETE pay FROM nft_payments pay JOIN nft_orders o ON pay.order_id = o.id WHERE o.collectible_id IN ($ids)",
            "DELETE FROM nft_orders WHERE resale_listing_id IN (SELECT id FROM nft_resale_listings WHERE collectible_id IN ($ids))",
            "DELETE FROM nft_resale_listings WHERE collectible_id IN ($ids)",
            "DELETE FROM nft_transfers WHERE collectible_id IN ($ids)",
            "DELETE FROM nft_user_collectibles WHERE collectible_id IN ($ids)",
            "DELETE FROM nft_orders WHERE collectible_id IN ($ids)",
            "DELETE FROM nft_collectibles WHERE name LIKE 'TEST-%'",
        ] as $sql) {
            self::pdo()->exec($sql);
        }
    }

    /**
     * 按 FK 依赖顺序删除用户关联子行（RESTRICT 约束要求子行先于父行）：
     * payments → 市场单 → 挂单 → 转赠 → 持仓 → 剩余订单
     */
    private static function deleteUserData(array $userIds): void
    {
        $in     = implode(',', array_fill(0, count($userIds), '?'));
        $params = $userIds;
        foreach ([
            "DELETE FROM nft_inbox WHERE user_id IN ($in)"                                    => $params,
            "DELETE FROM nft_payments WHERE user_id IN ($in)"                                 => $params,
            "DELETE FROM nft_orders WHERE user_id IN ($in) AND resale_listing_id IS NOT NULL" => $params,
            "DELETE FROM nft_resale_listings WHERE seller_id IN ($in)"                        => $params,
            "DELETE FROM nft_transfers WHERE from_user_id IN ($in) OR to_user_id IN ($in)"    => [...$params, ...$params],
            "DELETE FROM nft_user_collectibles WHERE user_id IN ($in)"                        => $params,
            "DELETE FROM nft_orders WHERE user_id IN ($in)"                                   => $params,
            "DELETE FROM nft_wallet_transactions WHERE user_id IN ($in)"                      => $params,
            "DELETE FROM nft_wallets WHERE user_id IN ($in)"                                  => $params,
        ] as $sql => $sqlParams) {
            self::pdo()->prepare($sql)->execute($sqlParams);
        }
    }

    // ============================================================
    // HTTP 客户端
    // ============================================================

    /**
     * 发起 API 请求，返回解码后的 JSON（含 code/message/data）
     */
    protected function http(string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $res = self::httpProbe($method, $uri, $body, $token);
        if ($res === null) {
            $this->fail("请求失败（后端无响应）：{$method} {$uri}");
        }
        return $res;
    }

    private static function httpProbe(string $method, string $uri, ?array $body = null, ?string $token = null): ?array
    {
        $ch = curl_init(self::BASE . $uri);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array_filter([
                'Content-Type: application/json',
                $token ? "Authorization: Bearer {$token}" : null,
            ]),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false || $code === 0) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    protected function assertOk(array $res, string $context = ''): array
    {
        // C 端成功码 0，管理端成功码 200
        $this->assertContains($res['code'] ?? null, [0, 200], "{$context} 应成功：" . json_encode($res, JSON_UNESCAPED_UNICODE));
        return $res['data'] ?? [];
    }

    // ============================================================
    // 数据库直连（造数 / 断言 / 清理）
    // ============================================================

    protected static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('mysql:host=127.0.0.1;dbname=sinan_nft;charset=utf8mb4', 'sinan', 'sinan123456', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        }
        return self::$pdo;
    }

    protected function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    protected function exec(string $sql, array $params = []): int
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // ============================================================
    // 测试数据工厂
    // ============================================================

    /** 随机测试手机号（1390000**** 段） */
    protected function phone(): string
    {
        $phone = '1390000' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $this->phones[] = $phone;
        return $phone;
    }

    /**
     * 走真实 API 注册并登录，返回 token
     * 依赖：mock 短信渠道（send-code 返回 debugCode）+ 图形码开关关闭
     */
    protected function registerAndLogin(string $phone, string $password = 'Abc123456'): string
    {
        $sent = $this->assertOk(
            $this->http('POST', '/api/auth/send-code', ['phone' => $phone, 'scene' => 'register']),
            '发送验证码'
        );
        $this->assertNotEmpty($sent['debugCode'], '开发态应回传 debugCode（检查 sms mock 渠道与 APP_DEBUG）');

        $this->assertOk(
            $this->http('POST', '/api/auth/register', [
                'phone'    => $phone,
                'code'     => $sent['debugCode'],
                'password' => $password,
                'nickname' => '测试用户' . substr($phone, -4),
            ]),
            '注册'
        );
        return $this->login($phone, $password);
    }

    protected function login(string $phone, string $password = 'Abc123456'): string
    {
        $data = $this->assertOk(
            $this->http('POST', '/api/auth/login', ['phone' => $phone, 'password' => $password]),
            '登录'
        );
        return $data['token'];
    }

    /** 管理端登录，返回 admin token */
    protected function adminLogin(string $username = 'admin', string $password = 'Admin123456'): string
    {
        $data = $this->assertOk(
            $this->http('POST', '/admin/auth/login', ['username' => $username, 'password' => $password]),
            '管理端登录'
        );
        return $data['token'];
    }

    /**
     * SQL 直造用户（跳过注册流程，用于交易链路提速）：
     * 默认已实名 + 设置交易密码 + 钱包余额
     * @return array{phone: string, token: string, userId: int}
     */
    protected function createUser(float $balance = 0.0, bool $realname = true): array
    {
        $phone = $this->phone();
        $pwdHash = password_hash('Abc123456', PASSWORD_DEFAULT);
        $txHash  = password_hash(self::TX_PWD, PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');

        $this->exec(
            "INSERT INTO nft_users (phone, username, password, transaction_password, uid, invite_code,
                is_realname, realname_status, realname_verified_at, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)",
            [$phone, '测试' . substr($phone, -4), $pwdHash, $txHash,
             'T' . substr((string) random_int(100000, 999999), 0, 8), 'IT' . random_int(100000, 999999),
             $realname ? 1 : 0, $realname ? 2 : 0, $realname ? $now : null, $now, $now]
        );
        $userId = (int) $this->fetch("SELECT id FROM nft_users WHERE phone = ?", [$phone])['id'];

        $this->exec(
            "INSERT INTO nft_wallets (user_id, balance, available, frozen, points, created_at, updated_at)
             VALUES (?, ?, ?, 0, 0, ?, ?)",
            [$userId, $balance, $balance, $now, $now]
        );
        return ['phone' => $phone, 'token' => $this->login($phone), 'userId' => $userId];
    }

    /**
     * SQL 造在售藏品（默认可转赠/可寄售/无限价）
     * @return int collectible_id
     */
    protected function createCollectible(float $price, array $overrides = []): int
    {
        $defaults = [
            'category_id' => 1, 'name' => 'TEST-' . uniqid(), 'subtitle' => '测试藏品',
            'image' => '/test.png', 'price' => $price, 'edition' => 100,
            'release_quantity' => 0, 'circulate' => 0, 'sold' => 0, 'locked_quantity' => 0,
            'per_user_limit' => 5, 'is_transferable' => 1, 'is_resaleable' => 1,
            'resale_price_mode' => 0, 'status' => 'onsale', 'created_at' => date('Y-m-d H:i:s'),
        ];
        $row = array_merge($defaults, $overrides);
        $cols = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        $this->exec("INSERT INTO nft_collectibles ({$cols}) VALUES ({$marks})", array_values($row));
        return (int) $this->fetch("SELECT id FROM nft_collectibles WHERE name = ?", [$row['name']])['id'];
    }

    // ============================================================
    // 清理
    // ============================================================

    /** 按手机号级联清理测试用户全部关联数据 */
    protected function cleanupPhone(string $phone): void
    {
        $ids = self::pdo()->prepare("SELECT id FROM nft_users WHERE phone = ?");
        $ids->execute([$phone]);
        $userIds = $ids->fetchAll(PDO::FETCH_COLUMN);
        if (!$userIds) {
            $this->exec("DELETE FROM nft_verification_codes WHERE phone = ?", [$phone]);
            return;
        }
        self::deleteUserData($userIds);
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $this->exec("DELETE FROM nft_users WHERE id IN ($in)", $userIds);
        $this->exec("DELETE FROM nft_verification_codes WHERE phone = ?", [$phone]);
    }
}
