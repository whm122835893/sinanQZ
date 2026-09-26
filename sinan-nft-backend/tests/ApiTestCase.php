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
    private static ?array $edges = null;

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

    /** 清理 TEST- 前缀藏品及其全部关联行（含历史遗留） */
    private static function purgeTestCollectibles(): void
    {
        $ids = self::pdo()
            ->query("SELECT id FROM nft_collectibles WHERE name LIKE 'TEST-%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::purge('nft_collectibles', array_map('intval', $ids));
    }

    /**
     * 自愈：清理历史失败运行遗留的测试数据（1390000 号段用户 + TEST- 藏品）
     * 幂等，套件启动时执行一次
     */
    private static function healLeftovers(): void
    {
        $userIds = self::pdo()
            ->query("SELECT id FROM nft_users WHERE phone LIKE '1390000%'")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::purge('nft_users', array_map('intval', $userIds));
        self::purgeTestCollectibles();
    }

    /**
     * 删除指定表的行，并连带清掉所有引用它们的子行。
     *
     * RESTRICT 外键的引用关系由 information_schema 实时推导（沿引用链不动点展开），
     * 因此新增业务表不会让这里悄悄失效——之前手写删除清单就是因为漏了 nft_synthesis_records
     * 等表，导致整套用例在 setUpBeforeClass 直接报错。
     * CASCADE 交由数据库级联，SET NULL 由数据库置空，都不需要（也不应该）手动删，
     * 否则会把与被测数据无关的业务行一起带走。
     *
     * @param string $table 目标表（带 nft_ 前缀）
     * @param int[]  $ids   目标主键
     */
    private static function purge(string $table, array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return;
        }

        $collected = [$table => $ids];
        $queue     = [[$table, $ids]];
        $edges     = self::restrictEdges();

        while ($queue) {
            [$parent, $pending] = array_shift($queue);
            $in = implode(',', $pending);
            foreach ($edges as [$child, $column, $referenced]) {
                if ($referenced !== $parent) {
                    continue;
                }
                // 取子行主键（而非外键列值）：既用于按 id 删除，也作为下一层展开的入口
                $rows = self::pdo()
                    ->query("SELECT id FROM `$child` WHERE `$column` IN ($in)")
                    ->fetchAll(PDO::FETCH_COLUMN);
                $fresh = array_values(array_diff(array_map('intval', $rows), $collected[$child] ?? []));
                if ($fresh) {
                    $collected[$child] = array_merge($collected[$child] ?? [], $fresh);
                    $queue[]           = [$child, $fresh];
                }
            }
        }

        // 无外键约束、但按业务列挂着测试数据的表：不删会累积孤儿行（不参与顺序推导）
        foreach (self::LOOSE_REFS as [$looseTable, $looseColumn, $refTable]) {
            if (!isset($collected[$refTable]) || $looseTable === $table) {
                continue;
            }
            self::pdo()->exec("DELETE FROM `$looseTable` WHERE `$looseColumn` IN (" . implode(',', $collected[$refTable]) . ')');
        }

        // 逐轮删除：DELETE IGNORE 会跳过仍被子行引用的行，因此无需精确推导层级顺序；
        // 某轮删不掉任何行说明仍有表未纳入引用图，直接报错而不是静默留脏数据。
        $remaining = $collected;
        while (array_filter($remaining)) {
            $touched = false;
            foreach ($remaining as $t => $ids) {
                if (!$ids) {
                    continue;
                }
                $in        = implode(',', $ids);
                $affected  = (int) self::pdo()->exec("DELETE IGNORE FROM `$t` WHERE id IN ($in)");
                $touched   = $touched || $affected > 0;
                if ($affected > 0) {
                    $still = self::pdo()
                        ->query("SELECT id FROM `$t` WHERE id IN ($in)")
                        ->fetchAll(PDO::FETCH_COLUMN);
                    $remaining[$t] = array_map('intval', $still);
                }
            }
            if (!$touched) {
                $stuck = array_filter($remaining);
                throw new RuntimeException(
                    '测试数据清理受阻，存在未纳入外键引用图的表：' . json_encode(array_map('count', $stuck))
                );
            }
        }
    }

    /** RESTRICT 外键边：[子表, 子表列, 父表]，进程内缓存 */
    private static function restrictEdges(): array
    {
        if (self::$edges === null) {
            $rows = self::pdo()
                ->query(
                    "SELECT k.TABLE_NAME child, k.COLUMN_NAME col, k.REFERENCED_TABLE_NAME parent
                       FROM information_schema.KEY_COLUMN_USAGE k
                       JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                         ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                      WHERE k.CONSTRAINT_SCHEMA = DATABASE()
                        AND k.REFERENCED_TABLE_NAME IS NOT NULL
                        AND r.DELETE_RULE = 'RESTRICT'"
                )
                ->fetchAll(PDO::FETCH_ASSOC);
            self::$edges = array_map(static fn (array $r) => [$r['child'], $r['col'], $r['parent']], $rows);
        }
        return self::$edges;
    }

    /** 关联列没有外键约束的表：[表, 列, 其指向的父表] */
    private const LOOSE_REFS = [
        ['nft_activity_reward_records', 'user_id', 'nft_users'],
        ['nft_buy_requests', 'user_id', 'nft_users'],
        ['nft_buy_requests', 'collectible_id', 'nft_collectibles'],
        ['nft_decompose_records', 'user_id', 'nft_users'],
        ['nft_holdings_snapshots', 'user_id', 'nft_users'],
        ['nft_holdings_snapshots', 'collectible_id', 'nft_collectibles'],
        ['nft_lucky_draw_chances', 'user_id', 'nft_users'],
        ['nft_priority_sale_whitelists', 'user_id', 'nft_users'],
        ['nft_raffle_registrations', 'user_id', 'nft_users'],
        ['nft_risk_alerts', 'user_id', 'nft_users'],
        ['nft_security_events', 'user_id', 'nft_users'],
        ['nft_swap_plan_users', 'user_id', 'nft_users'],
        ['nft_trade_snapshots', 'user_id', 'nft_users'],
        ['nft_user_draw_codes', 'user_id', 'nft_users'],
        ['nft_priority_sales', 'collectible_id', 'nft_collectibles'],
        ['nft_raffle_activities', 'collectible_id', 'nft_collectibles'],
    ];

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

    /**
     * 直连应用自身配置的数据源（.env [DATABASE]）。
     * 刻意不回落默认端口/账号：本机另有监听 3306 的 MySQL 实例，
     * 一旦连错，tearDown 的级联删除会作用在无关库上。
     */
    protected static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = (string) env('database.HOSTNAME', '');
            $port = (int) env('database.HOSTPORT', 0);
            $name = (string) env('database.DATABASE', '');
            $user = (string) env('database.USERNAME', '');
            $pass = (string) env('database.PASSWORD', '');
            if ($host === '' || $name === '' || $port <= 0 || $user === '') {
                self::fail('.env [DATABASE] 缺少 HOSTNAME/HOSTPORT/DATABASE/USERNAME，测试拒绝以默认数据源运行');
            }
            self::$pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
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
    protected function adminLogin(string $username = 'admin', string $password = 'admin123'): string
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
        self::purge('nft_users', array_map('intval', $userIds));
        $this->exec("DELETE FROM nft_verification_codes WHERE phone = ?", [$phone]);
    }
}
