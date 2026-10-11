<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 藏品编号服务（全项目唯一取号入口）
 *
 * 口径（2026-10-11 与需求方确认）：
 *   1. 发行 N 份 → 编号只能在 1..N 之间，任何情况下不得超出（越界即视为超发）；
 *   2. 发放时从「1..N 中尚未被占用的号」里随机取，不是按发放先后排号；
 *   3. 展示为 #7（前端去前缀去前导零），库里仍存 SN-{藏品ID}-{4位序号}；
 *   4. 已消耗（分解/合成原料）的编号不回收 —— 同一个号只能属于一个人，
 *      回收会让链上凭证、空投台账、转赠记录出现「同号两人」。
 *
 * 为什么要有这个类（修掉两个真实缺陷）：
 *   A. 旧实现里除「购买」外的 12 处发放点，编号尾段直接用 nft_user_collectibles 的
 *      全局自增行 id（注释还写着"沿用全项目 SN-{藏品ID}-{资产ID} 规范"）。
 *      实测后果：藏品 2（发行 100）拿到 SN-2-0011~0014，号段跑到自身发行量之外，
 *      平台每多发一件号就一直涨，迟早出现「编号 > 发行量」。
 *   B. 「购买」用 sold + 1，而 sold 只被购买累加、空投/开盒/合成都不加。
 *      实测后果：藏品 1 已被空投占掉 0001~0010 但 sold=0，第一个买家算出 SN-1-0001
 *      → 撞唯一索引 uk_collectible_serial → 下单 500。
 *   两条路收敛成同一个号池后，A、B 一起消失。
 *
 * 调用契约：
 *   - 必须在事务内调用。本类内部对 collectibles 行加 FOR UPDATE，
 *     同一藏品的并发取号因此被串行化；调用方若已自行加锁，这里只是幂等重取。
 *   - 唯一索引 uk_collectible_serial 保留作最后兜底：拿到锁仍撞号说明有绕过本类的写入，
 *     应当报警而不是静默改号。
 */
class SerialService
{
    public const PREFIX = 'SN-';

    /** edition 超过该值时不建全量差集（省内存），改用随机探测 */
    private const LARGE_EDITION = 100000;

    /** 随机取号的最大尝试次数，超过即判失败（宁可报错也不发重复号） */
    private const PROBE_GUARD = 20000;

    /** 取一个编号（1..发行量内随机、未占用） */
    public static function next(int $collectibleId): string
    {
        $serials = self::allocate($collectibleId, 1);
        return $serials[0];
    }

    /**
     * 连续取 count 个编号（同一事务内互不相同，且不与已占用号冲突）
     * @return string[] 形如 ['SN-2-0037', 'SN-2-0071']
     * @throws SerialException
     */
    public static function allocate(int $collectibleId, int $count = 1): array
    {
        if ($collectibleId <= 0) {
            throw new SerialException("取号失败：藏品 ID 非法（{$collectibleId}）");
        }
        if ($count < 1) {
            throw new SerialException("取号失败：本次需 {$count} 个编号，至少为 1");
        }

        // 行锁 + 顺带取发行量：避免调用方各自忘记加锁导致同号
        $c = Db::name('collectibles')->where('id', $collectibleId)->lock(true)->find();
        if (!$c) {
            throw new SerialException("取号失败：藏品 {$collectibleId} 不存在");
        }
        $edition = (int) ($c['edition'] ?? 0);

        $used    = self::usedNumbers($collectibleId);
        $numbers = $edition > 0
            ? self::pickFromPool($collectibleId, $edition, $used, $count)
            : self::pickWithoutEdition($used, $count);

        $out = [];
        foreach ($numbers as $n) {
            $out[] = self::format($collectibleId, $n);
        }
        return $out;
    }

    // ==================== 存量重编（php think serial:renumber 专用） ====================
    //
    // 编号规则改到「1..发行量内随机取」之后，历史上按资产行自增 ID / sold+1 生成的存量号
    // 有四种不合规形态：① 号已跑出发行量；② TMP- 占位串或空号；③ 同藏品重号；
    // ④ 号尾段等于资产行 ID（旧实现的指纹，此刻也许还没越界，但行 ID 只涨不落，迟早越界）。
    // 重编方案必须由本类出，命令侧只负责读库、写库与同步引用
    // —— 否则「发行量封顶 + 随机取号」这条规则就有了第二个实现处。

    /**
     * 判定某藏品的存量编号是否全部符合新规则，并给出无法重编的原因。
     *
     * 整藏品判定、整藏品重编：只要有一行不合规，这个藏品就整体重排一遍。
     * 只改违规行会留下「半数随机半数排号」的混合体，新号还可能撞上未改动行的号；
     * 全量重排把 1..发行量 整个号池收回重发，两条麻烦一起消失。
     *
     * @param array<int, array{id: int, serial: ?string}> $rows 该藏品全部资产行（按 id 升序）
     * @return array{ok: bool, blocked: ?string, issues: array<int, string>, count: int}
     */
    public static function analyzeStock(int $collectibleId, int $edition, array $rows): array
    {
        $count = count($rows);
        if ($count === 0) {
            return ['ok' => true, 'blocked' => null, 'issues' => [], 'count' => 0];
        }
        if ($edition <= 0) {
            return [
                'ok'       => false,
                'blocked'  => "藏品 {$collectibleId} 未配置发行量（edition={$edition}），无法确定编号上限，请先到后台补发行量",
                'issues'   => [],
                'count'    => $count,
            ];
        }
        if ($count > $edition) {
            return [
                'ok'      => false,
                'blocked' => sprintf('藏品 %d 存量 %d 件已超出发行量 %d 份，重编前请先扩充发行量（编号最大不能超过发行量）', $collectibleId, $count, $edition),
                'issues'  => [],
                'count'   => $count,
            ];
        }

        $issues   = [];
        $firstRow = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $n  = self::parseNo($row['serial'] ?? null);
            if ($n === null) {
                $raw = trim((string) ($row['serial'] ?? ''));
                $issues[$id] = $raw === '' ? '编号为空' : '编号「' . $raw . '」无法解析（历史占位符）';
                continue;
            }
            if ($n > $edition) {
                $issues[$id] = "编号 {$n} 超出发行量 {$edition}";
                continue;
            }
            if (isset($firstRow[$n])) {
                $issues[$id] = "编号 {$n} 与资产 {$firstRow[$n]} 重复";
                if (!isset($issues[$firstRow[$n]])) {
                    $issues[$firstRow[$n]] = "编号 {$n} 与资产 {$id} 重复";
                }
                continue;
            }
            // 旧实现的指纹：编号尾段直接拿 nft_user_collectibles 的自增行 ID 拼
            // （SN-1-0001 的行 id 恰好是 1）。号没越界，但它不是「1..发行量里随机取」的号，
            // 而且行 ID 只会一路向上涨，迟早越过发行量。edition<2 时无号可换，不判违规。
            if ($n === $id && $edition >= 2) {
                $issues[$id] = "编号 {$n} 等于资产行 ID {$id}（旧实现拿行 ID 当号，非随机取号）";
                $firstRow[$n] = $id;
                continue;
            }
            $firstRow[$n] = $id;
        }

        return ['ok' => $issues === [], 'blocked' => null, 'issues' => $issues, 'count' => $count];
    }

    /**
     * 生成整藏品重编方案：每一行分到 1..发行量 内互不相同的随机号。
     * 纯函数，不读库 —— dry-run 与 --apply 必须能拿到同一份方案给用户过目。
     *
     * 发号时刻意避开「新号 == 资产行 ID」：那种号看起来仍像旧实现的产物，
     * 下一轮巡检又会把它判成不合规、再重排一遍，方案就收敛不了。
     * 号池被占满到只剩这一个选择时（edition 极小且存量==发行量）只能接受，属已知边界。
     *
     * @param array<int, array{id: int, serial: ?string}> $rows
     * @return array<int, string> 资产行 id => 新编号串
     * @throws SerialException 发行量不足（调用方应先用 analyzeStock 拦掉）
     */
    public static function renumberAssignments(int $collectibleId, int $edition, array $rows): array
    {
        $rows  = array_values($rows);
        $count = count($rows);
        if ($count === 0) return [];

        // used 传空：整个号池都收回重发，所以新号之间不重复即可，不必避开旧号。
        // 存量刚好等于发行量时号池就是全集，直接给全量（避免随机抽样在满载时空转报错）。
        $numbers = $count >= $edition ? range(1, $edition) : self::pickFromPool($collectibleId, $edition, [], $count);
        shuffle($numbers);

        // 贪心分配：优先挑一个不等于本行 ID 的号
        $ids   = array_map(static fn ($r) => (int) $r['id'], $rows);
        $picks = [];
        $free  = $numbers;
        foreach ($ids as $id) {
            $take = array_key_first($free);
            foreach ($free as $k => $cand) {
                if ($cand !== $id) { $take = $k; break; }
            }
            $picks[] = $free[$take];
            unset($free[$take]);
        }

        // 兜底修补：贪心可能把最后一行逼到「只剩等于自己 ID 的号」，
        // 与任意一行交换若能两边都不撞 ID 就换一次，让方案一次到位。
        foreach ($ids as $i => $id) {
            if ($picks[$i] !== $id || count($ids) < 2) continue;
            foreach ($ids as $j => $otherId) {
                if ($j === $i) continue;
                if ($picks[$j] !== $id && $picks[$i] !== $otherId) {
                    [$picks[$i], $picks[$j]] = [$picks[$j], $picks[$i]];
                    break;
                }
            }
        }

        $assign = [];
        foreach ($rows as $i => $row) {
            $assign[(int) $row['id']] = self::format($collectibleId, $picks[$i]);
        }
        return $assign;
    }

    /**
     * 从 1..edition 的号池里随机取 count 个未占用号
     * @param array<int, true> $used
     * @return int[]
     */
    private static function pickFromPool(int $collectibleId, int $edition, array $used, int $count): array
    {
        // 越界旧号（重编脚本还没跑的存量）不在池内，不影响可用数
        $inPool = 0;
        foreach ($used as $n => $_) {
            if ($n >= 1 && $n <= $edition) $inPool++;
        }
        $available = $edition - $inPool;
        if ($available < $count) {
            throw new SerialException(sprintf(
                '藏品 %d 的编号已发完：发行量 %d 份，已占用 %d 个号，本次需要 %d 个（编号不会超过发行量，如需继续发放请先扩充发行量）',
                $collectibleId, $edition, $inPool, $count
            ));
        }

        if ($edition <= self::LARGE_EDITION) {
            // 中小发行量：算差集后随机取下标，等价于「在未用号里真随机抽」且必然不重复
            $free   = array_values(array_diff(range(1, $edition), array_keys($used)));
            $picked = [];
            $guard  = 0;
            while (count($picked) < $count && $guard++ < self::PROBE_GUARD) {
                $n = $free[random_int(0, count($free) - 1)];
                $picked[$n] = true;
            }
            if (count($picked) < $count) {
                throw new SerialException("藏品 {$collectibleId} 取号异常：随机抽样连续 {$guard} 次仍未凑够 {$count} 个号");
            }
            return array_keys($picked);
        }

        // 超大发行量：不建差集，随机探测（占用稀疏时命中率极高）
        $picked = [];
        $tries  = 0;
        while (count($picked) < $count) {
            if (++$tries > self::PROBE_GUARD) {
                throw new SerialException("藏品 {$collectibleId} 取号失败：号池接近占满，随机探测 {$tries} 次仍不足 {$count} 个号");
            }
            $n = random_int(1, $edition);
            if (isset($used[$n]) || isset($picked[$n])) continue;
            $picked[$n] = true;
        }
        return array_keys($picked);
    }

    /**
     * 发行量未配置（edition<=0，历史脏数据兜底）：不封顶，用「已用最大号 +1」递增，
     * 至少保证同藏品内不重号；正常藏品不该走到这里，留日志便于回头清理。
     * @param array<int, true> $used
     * @return int[]
     */
    private static function pickWithoutEdition(array $used, int $count): array
    {
        $max     = $used ? max(array_keys($used)) : 0;
        $numbers = [];
        for ($i = 1; $i <= $count; $i++) {
            $numbers[] = $max + $i;
        }
        return $numbers;
    }

    /**
     * 该藏品已占用的号（行只要插过就永久占用，含寄售中/已转赠/已消耗/已上链）
     * @return array<int, true> 号 => true；无法解析的串（占位符 TMP-xxx）忽略
     */
    public static function usedNumbers(int $collectibleId): array
    {
        $rows = Db::name('user_collectibles')->where('collectible_id', $collectibleId)->column('serial');
        $used = [];
        foreach ($rows as $serial) {
            $n = self::parseNo($serial);
            if ($n !== null) $used[$n] = true;
        }
        return $used;
    }

    /** 拼编号串：SN-{藏品ID}-{4位序号}（序号超 4 位原样输出，不截断，保证唯一） */
    public static function format(int $collectibleId, int $no): string
    {
        return self::PREFIX . $collectibleId . '-' . str_pad((string) $no, 4, '0', STR_PAD_LEFT);
    }

    /** 从编号串取纯序号；不是编号（占位符/空/畸形）返回 null */
    public static function parseNo(?string $serial): ?int
    {
        if ($serial === null || $serial === '') return null;
        if (!preg_match('/^' . preg_quote(self::PREFIX, '/') . '\d+-(\d+)$/', $serial, $m)) return null;
        $n = (int) $m[1];
        return $n > 0 ? $n : null;
    }
}
