<?php
declare(strict_types=1);

namespace tests;

/**
 * 藏品到账通知（nft_inbox）回归
 *
 * 覆盖 2026-10-11 本轮需求"到账要有弹窗 + 空投卡片要显示藏品编号"的三条口径：
 *  1. 活动奖励（签到送藏品）到账 → 写一条未读通知，C 端 GET /api/inbox/pending 能取到；
 *  2. 积分（司南币）奖励**不写**通知 —— 弹窗卡片必须有藏品才渲染得出来，
 *     nft_inbox.collectible_id 是 NOT NULL + 外键，见 app/service/InboxService.php 类注释；
 *  3. GET /api/airdrops/mine 下发藏品编号 serial，且发放失败/待发放的记录
 *     （user_collectible_id 为 NULL）不能因为 JOIN 写成内连接而整条从列表里消失；
 *  4. 开盒与抽奖中奖也写通知，并把 inboxId 回传给发起方 —— 这两个页面当场就有结果弹窗，
 *     前端拿到 inboxId 立刻标记已读，记录留档但不会被 15s 轮询再弹一次。
 */
class InboxArrivalTest extends ApiTestCase
{
    /** @var array<string,string> 用例改过的系统配置原值，tearDown 逐条还原 */
    private array $configBackup = [];

    /** @var int[] 本用例造的签到活动 ID（父表无外键引用，基类不会自动清，必须自己删） */
    private array $activityIds = [];

    /** @var int[] 抽奖活动 / 奖项 / 盲盒：奖项只有索引没有外键，删不掉会留下悬空行 */
    private array $luckyActivityIds = [];
    private array $luckyPrizeIds = [];
    private array $blindBoxIds = [];

    protected function tearDown(): void
    {
        // 抽奖流水对 prize_id 是 RESTRICT 外键：先清流水，奖项才删得掉。
        // 这一步不能挪给基类（基类沿用户链删），因为基类清理发生在本方法之后。
        if ($this->luckyPrizeIds) {
            $in = implode(',', array_map('intval', $this->luckyPrizeIds));
            $this->exec("DELETE FROM nft_lucky_draw_records WHERE prize_id IN ({$in})");
        }
        foreach ($this->luckyPrizeIds as $id) {
            $this->exec("DELETE FROM nft_lucky_draw_prizes WHERE id = ?", [$id]);
        }
        foreach ($this->luckyActivityIds as $id) {
            $this->exec("DELETE FROM nft_lucky_draw_activities WHERE id = ?", [$id]);
        }
        // blind_box_items 对 blind_box_id 是 ON DELETE CASCADE，删父即可
        foreach ($this->blindBoxIds as $id) {
            $this->exec("DELETE FROM nft_blind_boxes WHERE id = ?", [$id]);
        }
        $this->luckyPrizeIds = $this->luckyActivityIds = $this->blindBoxIds = [];
        foreach ($this->activityIds as $id) {
            $this->exec("DELETE FROM nft_check_in_activities WHERE id = ?", [$id]);
        }
        $this->activityIds = [];
        foreach ($this->configBackup as $key => $value) {
            $this->exec("UPDATE nft_system_configs SET config_value = ? WHERE config_key = ?", [$value, $key]);
        }
        $this->configBackup = [];
        parent::tearDown();
    }

    /**
     * 临时打开一枚系统配置开关（记下原值，tearDown 还原，不污染沙箱/CI 配置）
     *
     * 用 skip 而不是 INSERT：这三枚开关都是"C 端把缺行当关闭"的敏感项
     * （见 migrations/20261011_module_switch_seed_rows.sql），测试不该顺手补行。
     */
    private function enableConfig(string $key): void
    {
        $row = $this->fetch("SELECT config_value FROM nft_system_configs WHERE config_key = ?", [$key]);
        if ($row === null) {
            $this->markTestSkipped("nft_system_configs 缺 {$key} 行，请先导入 database/full_init.sql 并执行第 22 份迁移");
        }
        $this->configBackup[$key] = (string) $row['config_value'];
        $this->exec("UPDATE nft_system_configs SET config_value = '1' WHERE config_key = ?", [$key]);
    }

    /**
     * 造一条生效中的签到活动（奖励挂在"连续签到第 1 天"）
     *
     * @param array $dayOneRewards 六类奖励中的若干项，如 [['rewardType'=>'collectible', ...]]
     */
    private function createCheckinActivity(array $dayOneRewards): int
    {
        $name = 'TEST-到账通知活动' . uniqid();
        $this->exec(
            "INSERT INTO nft_check_in_activities
                (name, status, start_time, end_time, reward_config, eligibility_type, eligibility_config, grant_mode, created_at, updated_at)
             VALUES (?, 'enabled', ?, NULL, ?, 'all', '', 'realtime', ?, ?)",
            [
                $name,
                date('Y-m-d H:i:s', strtotime('-1 hour')),
                json_encode(['1' => $dayOneRewards], JSON_UNESCAPED_UNICODE),
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
            ]
        );
        $id    = (int) $this->fetch("SELECT id FROM nft_check_in_activities WHERE name = ?", [$name])['id'];
        $this->activityIds[] = $id;
        return $id;
    }

    /** 该用户当前的通知行（按 id 升序，便于断言"这一笔新写的"） */
    private function inboxRows(int $userId): array
    {
        $stmt = self::pdo()->prepare("SELECT * FROM nft_inbox WHERE user_id = ? ORDER BY id ASC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function test_签到送藏品_写入未读到账通知并被pending接口取回(): void
    {
        $this->enableConfig('checkin_enabled');
        $cid   = $this->createCollectible(10.00);
        $actId = $this->createCheckinActivity([
            ['rewardType' => 'collectible', 'collectibleId' => $cid, 'quantity' => 2],
        ]);
        $user  = $this->createUser();

        $this->assertCount(0, $this->inboxRows($user['userId']), '签到前不该有历史通知');

        $data = $this->assertOk($this->http('POST', '/api/check-in', [], $user['token']), '签到');
        $this->assertSame('collectible', $data['reward']['type'], '签到应实时发出藏品奖励');

        $rows = $this->inboxRows($user['userId']);
        $this->assertCount(1, $rows, '发 2 份也只写一条通知：份数记在 extra，避免"发几份弹几次"');
        $row = $rows[0];
        $this->assertSame('reward', $row['type'], '活动奖励到账归类为 reward');
        $this->assertSame(0, (int) $row['status'], '用户不在发放现场，通知保持未读等着弹窗');
        $this->assertSame($cid, (int) $row['collectible_id']);
        $this->assertSame($actId, (int) $row['ref_id'], 'ref_id 要能回溯到来源活动');
        $this->assertStringContainsString('活动奖励', (string) $row['title']);
        $extra = json_decode((string) $row['extra'], true);
        $this->assertSame(2, (int) ($extra['quantity'] ?? 0));
        $this->assertSame('checkin', (string) ($extra['scene'] ?? ''), 'extra.scene 用于事后区分是哪类活动发的');

        // C 端弹窗数据源
        $pending = $this->assertOk($this->http('GET', '/api/inbox/pending', null, $user['token']), '收件箱未读');
        $items   = $pending['list'] ?? [];
        $this->assertCount(1, $items);
        $this->assertSame((int) $row['id'], (int) $items[0]['id']);
        $this->assertSame('reward', $items[0]['type']);
        $this->assertNotEmpty($items[0]['name'], '弹窗卡片要有藏品名快照');

        // 关闭弹窗（标记已读）后不再出现
        $this->assertOk($this->http('POST', '/api/inbox/' . $row['id'] . '/read', ['confirmed' => 1], $user['token']), '标记已读');
        $after = $this->assertOk($this->http('GET', '/api/inbox/pending', null, $user['token']), '再次拉取');
        $this->assertCount(0, $after['list'] ?? []);
    }

    public function test_签到送积分_不写到账通知(): void
    {
        $this->enableConfig('checkin_enabled');
        $actId = $this->createCheckinActivity([
            ['rewardType' => 'points', 'amount' => 8],
        ]);
        $user = $this->createUser();

        $data = $this->assertOk($this->http('POST', '/api/check-in', [], $user['token']), '签到');
        $this->assertSame('points', $data['reward']['type']);
        $this->assertCount(0, $this->inboxRows($user['userId']), '积分到账走钱包流水，不写 inbox（弹窗卡片必须有藏品）');
        $this->assertGreaterThan(0, (int) $this->fetch("SELECT COUNT(*) c FROM nft_wallet_transactions WHERE user_id = ? AND trans_type = 'reward'", [$user['userId']])['c'], '积分要有钱包流水');
        $this->assertGreaterThan(0, $actId);
    }

    public function test_空投列表下发藏品编号(): void
    {
        $cid  = $this->createCollectible(20.00);
        $user = $this->createUser();
        $now  = date('Y-m-d H:i:s');

        $this->exec(
            "INSERT INTO nft_user_collectibles
                (user_id, collectible_id, serial, source, acquired_price, acquired_at, status, created_at, updated_at)
             VALUES (?, ?, ?, 'airdrop', 0, ?, 'held', ?, ?)",
            [$user['userId'], $cid, 'SN-' . $cid . '-0001', $now, $now, $now]
        );
        $ucid = (int) self::pdo()->lastInsertId();
        $this->exec(
            "INSERT INTO nft_airdrop_records
                (activity_id, task_id, user_id, phone, collectible_id, user_collectible_id, quantity, status, issued_at, created_at, updated_at)
             VALUES (NULL, 0, ?, ?, ?, ?, 1, 'issued', ?, ?, ?)",
            [$user['userId'], $user['phone'], $cid, $ucid, $now, $now, $now]
        );

        $list = $this->assertOk($this->http('GET', '/api/airdrops/mine', null, $user['token']), '空投记录')['list'] ?? [];
        $this->assertCount(1, $list);
        $this->assertSame('SN-' . $cid . '-0001', $list[0]['serial'], 'C 端卡片头部显示的就是这个编号');
        $this->assertSame($ucid, (int) $list[0]['userCollectibleId']);
    }

    /**
     * 发放失败/待发放的记录没有资产行 → serial 为 null，但记录本身必须在列表里。
     * 这里防的是把 LEFT JOIN 写成内连接：那样一来"有一笔没到账"的记录会整条消失，
     * 用户以为没发过，比看不到编号严重得多。
     */
    public function test_空投记录无资产行时仍返回且编号为空(): void
    {
        $cid  = $this->createCollectible(20.00);
        $user = $this->createUser();
        $now  = date('Y-m-d H:i:s');

        $this->exec(
            "INSERT INTO nft_airdrop_records
                (activity_id, task_id, user_id, phone, collectible_id, user_collectible_id, quantity, status, created_at, updated_at)
             VALUES (NULL, 0, ?, ?, ?, NULL, 1, 'failed', ?, ?)",
            [$user['userId'], $user['phone'], $cid, $now, $now]
        );

        $list = $this->assertOk($this->http('GET', '/api/airdrops/mine', null, $user['token']), '空投记录')['list'] ?? [];
        $this->assertCount(1, $list, '失败记录也要在列表里可见');
        $this->assertSame('failed', $list[0]['status']);
        $this->assertNull($list[0]['serial'], '没有资产行就没有编号，前端据此退回显示记录 ID');
    }

    /**
     * 开盲盒：写一条 blindbox 通知，并把 inboxId 回传给发起方。
     *
     * 防的是"弹两次"：开盒页当场已经有结果弹窗，若通知留在未读态，
     * stores/inbox.js 的 15s 轮询会把同一份奖品再弹一次。后端的处理是照写通知（留档、
     * 后台可查）+ 回传 inboxId，前端拿到就标记已读 —— 这里把这条约定钉死。
     */
    public function test_开盲盒_写通知并回传inboxId供前端就地标记已读(): void
    {
        $boxCid   = $this->createCollectible(30.00);                 // 盲盒本体藏品
        $prizeCid = $this->createCollectible(30.00);                 // 奖池里的子藏品
        $now      = date('Y-m-d H:i:s');

        $this->exec(
            "INSERT INTO nft_blind_boxes (collectible_id, description, is_openable, created_at, updated_at)
             VALUES (?, 'TEST-到账通知盲盒', 1, ?, ?)",
            [$boxCid, $now, $now]
        );
        $boxId = (int) self::pdo()->lastInsertId();
        $this->blindBoxIds[] = $boxId;
        $this->exec(
            "INSERT INTO nft_blind_box_items
                (blind_box_id, prize_collectible_id, probability, quantity_limit, created_at, updated_at)
             VALUES (?, ?, 1.0000, NULL, ?, ?)",
            [$boxId, $prizeCid, $now, $now]
        );
        $itemId = (int) self::pdo()->lastInsertId();

        $user = $this->createUser();
        $this->exec(
            "INSERT INTO nft_user_collectibles
                (user_id, collectible_id, serial, source, acquired_price, acquired_at, status, created_at, updated_at)
             VALUES (?, ?, ?, 'purchase', 30, ?, 'held', ?, ?)",
            [$user['userId'], $boxCid, 'SN-' . $boxCid . '-0001', $now, $now, $now]
        );
        $ucId = (int) self::pdo()->lastInsertId();

        $data = $this->assertOk(
            $this->http('POST', '/api/blind-boxes/open',
                ['userCollectibleId' => $ucId, 'paymentPassword' => self::TX_PWD], $user['token']),
            '开盲盒'
        );
        $serial  = (string) $data['prize']['no'];
        $inboxId = (int) ($data['inboxId'] ?? 0);
        $this->assertGreaterThan(0, $inboxId, '开盒要写通知并回传通知 ID');

        $row = $this->fetch("SELECT * FROM nft_inbox WHERE id = ?", [$inboxId]);
        $this->assertNotNull($row);
        $this->assertSame('blindbox', $row['type']);
        $this->assertSame($user['userId'], (int) $row['user_id']);
        $this->assertSame($prizeCid, (int) $row['collectible_id'], '通知卡片要指向开出来的奖品，不是盲盒本体');
        $this->assertSame($itemId, (int) $row['ref_id'], 'ref_id = 奖池配置行，便于回溯出货概率');
        $this->assertSame($serial, (string) json_decode((string) $row['extra'], true)['serial']);
        $this->assertSame(0, (int) $row['status'], '后端写进来就是未读，是否留档由前端标记');

        // 前端 markRead 走的就是这个接口：标记后轮询再也取不到
        $this->assertOk($this->http('POST', '/api/inbox/' . $inboxId . '/read', ['confirmed' => 1], $user['token']), '就地标记已读');
        $pending = $this->assertOk($this->http('GET', '/api/inbox/pending', null, $user['token']), '收件箱未读');
        $this->assertCount(0, $pending['list'] ?? [], '开盒当场已确认，不该再被 15s 轮询弹第二次');
        $this->assertSame(2, (int) $this->fetch("SELECT status FROM nft_inbox WHERE id = ?", [$inboxId])['status'], '记录留档');
    }

    /**
     * 抽奖中奖（realtime 藏品奖）：写一条 lottery 通知并回传 inboxId。
     *
     * 类型必须是 lottery 而不是 reward —— 抽奖结果页同样当场弹窗，前端据此标记已读；
     * 若误标成 reward，就会被当成"异步到账"再弹一次。
     */
    public function test_抽奖中奖藏品_写lottery类型通知并回传inboxId(): void
    {
        $this->enableConfig('lucky_enabled');
        $cid = $this->createCollectible(40.00);
        $now = date('Y-m-d H:i:s');

        $name = 'TEST-到账通知抽奖' . uniqid();
        $this->exec(
            "INSERT INTO nft_lucky_draw_activities
                (name, status, start_time, end_time, eligibility_type, eligibility_config, grant_mode, created_at, updated_at)
             VALUES (?, 1, ?, NULL, 'all', NULL, 'realtime', ?, ?)",
            [$name, date('Y-m-d H:i:s', strtotime('-1 hour')), $now, $now]
        );
        $actId = (int) $this->fetch("SELECT id FROM nft_lucky_draw_activities WHERE name = ?", [$name])['id'];
        $this->luckyActivityIds[] = $actId;

        $this->exec(
            "INSERT INTO nft_lucky_draw_prizes
                (activity_id, tier_name, prize_name, prize_type, collectible_id, total, won, sort_order, probability, created_at, updated_at)
             VALUES (?, '传说', 'TEST-到账通知奖品', 'collectible', ?, 5, 0, 0, 1.0000, ?, ?)",
            [$actId, $cid, $now, $now]
        );
        $prizeId = (int) self::pdo()->lastInsertId();
        $this->luckyPrizeIds[] = $prizeId;

        $user = $this->createUser();
        $data = $this->assertOk($this->http('POST', '/api/lucky-draw/draw', [], $user['token']), '抽奖');
        $this->assertSame($prizeId, (int) $data['prize']['prizeId'], '活动里只有这一个奖项，必然命中');
        $this->assertSame('TEST-到账通知奖品', $data['prize']['prizeName']);

        $inboxId = (int) ($data['inboxId'] ?? 0);
        $this->assertGreaterThan(0, $inboxId, '藏品奖要写到账通知');
        $row = $this->fetch("SELECT * FROM nft_inbox WHERE id = ?", [$inboxId]);
        $this->assertSame('lottery', $row['type'], '抽奖中奖独立归类为 lottery');
        $this->assertSame($cid, (int) $row['collectible_id']);
        $this->assertSame((int) $data['recordId'], (int) $row['ref_id'], 'ref_id = 抽奖流水，可回溯到具体一次抽奖');
        $extra = json_decode((string) $row['extra'], true);
        $this->assertSame($data['userCollectible']['no'], $extra['serial'], '通知里的编号要和资产行一致');
        $this->assertStringContainsString('TEST-到账通知奖品', (string) $extra['reason']);

        $this->assertOk($this->http('POST', '/api/inbox/' . $inboxId . '/read', ['confirmed' => 1], $user['token']), '就地标记已读');
        $this->assertCount(0, $this->assertOk($this->http('GET', '/api/inbox/pending', null, $user['token']), '收件箱未读')['list'] ?? []);
    }
}
