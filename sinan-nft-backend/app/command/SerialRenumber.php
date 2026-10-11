<?php
declare(strict_types=1);

namespace app\command;

use app\service\SerialService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;

/**
 * 存量藏品编号重编：把「比发行量还大」和「TMP- 占位」的历史编号，
 * 改成新规则下的号（1..发行量 内随机、同藏品不重号）。
 *
 * 用法：
 *   php think serial:renumber                       # 只读预览（默认）：算方案 + 存 plan 文件，绝不写库
 *   php think serial:renumber --collectible=1,2     # 只预览指定藏品
 *   php think serial:renumber --all                 # 连本来就合规的藏品也整体重排（默认只动有不合规号的）
 *   php think serial:renumber --apply --from=<plan> # 按预览过的那份方案落库
 *
 * 为什么分成「先出方案、再按方案落库」两步（而不是一条命令直接改）：
 *   新编号是随机抽的，每次计算结果都不同。若 --apply 自己重算，你点头批准的号码
 *   和真正写进库的号码就是两回事。所以 dry-run 把方案连同每行的旧编号一起存盘，
 *   --apply 只认这份文件，写之前逐行核对库里现状与预览时完全一致，对不上就整批不动。
 *
 * 落库时会同步改这三处「按编号值存的快照」（其余表都按资产行 ID 关联，不受影响）：
 *   nft_swap_plan_details.serial        （按 user_collectible_id 精确回写）
 *   nft_decompose_records.source_serial （分解台账里的源资产编号，按旧值匹配）
 *   nft_inbox.extra.serial              （到账/中奖通知里的编号，解 JSON 后按旧值匹配）
 *
 * 注意：编号是「展示 + 台账」口径，链上凭证存在 tx_hash/token_id 两列，本命令不碰。
 *       已上链（mint_status=minted）的行会被单独计数告警，改号后链下编号与铸造时记录不同。
 */
class SerialRenumber extends Command
{
    private const PLAN_VERSION = 1;

    protected function configure()
    {
        $this->setName('serial:renumber')
            ->setDescription('存量藏品编号重编（默认只读预览）：php think serial:renumber [--apply --from=<plan>]');
        $this->addOption('apply', null, Option::VALUE_NONE, '按 --from 指定的方案文件写库（省略则只读预览）');
        $this->addOption('from', null, Option::VALUE_REQUIRED, '要落库的方案文件（由只读预览生成）');
        $this->addOption('collectible', null, Option::VALUE_REQUIRED, '只处理这些藏品 ID，逗号分隔');
        $this->addOption('all', null, Option::VALUE_NONE, '已合规的藏品也整体重排（默认只重编存在不合规编号的藏品）');
    }

    protected function execute(Input $input, Output $output)
    {
        $apply = (bool) $input->getOption('apply');
        $from  = (string) ($input->getOption('from') ?: '');

        try {
            return $apply ? $this->doApply($from, $output) : $this->doPreview($input, $output);
        } catch (\Throwable $e) {
            $output->writeln('<error>执行失败：' . $e->getMessage() . '</error>');
            return 1;
        }
    }

    // ==================== 只读预览 ====================

    private function doPreview(Input $input, Output $output): int
    {
        $onlyIds      = $this->parseIds((string) ($input->getOption('collectible') ?: ''));
        $includeClean = (bool) $input->getOption('all');
        if ($input->getOption('from')) {
            // --from 只在 --apply 时有意义；落库请走 serial:renumber --apply --from=...
            $output->writeln('<comment>提示：--from 要配 --apply 才落库，本次仍是只读预览。</comment>');
        }

        $items  = $this->collectStock($onlyIds);
        $plan   = ['version' => self::PLAN_VERSION, 'generated_at' => date('Y-m-d H:i:s'), 'collectibles' => [], 'summary' => []];
        $blocked = $clean = $rowsToChange = 0;

        foreach ($items as $cid => $item) {
            $edition = $item['edition'];
            $rows    = $item['rows'];

            $stat = SerialService::analyzeStock($cid, $edition, $rows);
            if ($stat['blocked'] !== null) {
                $blocked++;
                $output->writeln('<warning>  · ' . $stat['blocked'] . ' —— 本藏品整体跳过，不改号</warning>');
                $plan['collectibles'][] = [
                    'id' => $cid, 'name' => $item['name'], 'edition' => $edition,
                    'skipped' => $stat['blocked'],
                ];
                continue;
            }

            $bad = $stat['issues'];
            if ($stat['ok'] && !$includeClean) {
                $clean++;
                continue;
            }

            $assign = SerialService::renumberAssignments($cid, $edition, $rows);
            $refs   = $this->referenceImpact($cid, $rows, $assign);
            $minted = 0;
            $lines  = [];
            foreach ($rows as $row) {
                $id  = (int) $row['id'];
                $new = $assign[$id];
                if (($row['mint_status'] ?? '') === 'minted') $minted++;
                $lines[] = [
                    'id'   => $id,
                    'old'  => (string) ($row['serial'] ?? ''),
                    'new'  => $new,
                    'user' => (int) $row['user_id'],
                    'st'   => (string) $row['status'],
                    'why'  => $bad[$id] ?? '整藏品重排',
                ];
            }

            $rowsToChange += count($lines);
            $output->writeln(sprintf(
                '<info>藏品 %d「%s」发行量 %d 份，存量 %d 件%s</info>',
                $cid, $item['name'], $edition, count($rows),
                $bad ? '，其中不合规 ' . count($bad) . ' 件' : '（编号本就合规，按 --all 整体重排）'
            ));
            foreach ($lines as $l) {
                $output->writeln(sprintf(
                    '    资产 %-6d 用户 %-6d %-10s  %-12s → %-12s  [%s]',
                    $l['id'], $l['user'], $l['st'], $l['old'] === '' ? '(空)' : $l['old'], $l['new'], $l['why']
                ));
            }
            $output->writeln(sprintf(
                '    需同步的编号快照：换计划 %d 行、分解台账 %d 行、站内通知 %d 条%s',
                $refs['swap'], $refs['decompose'], $refs['inbox'],
                $minted ? '；其中已上链 ' . $minted . ' 件（链上凭证不变，仅链下编号变更）' : ''
            ));

            $plan['collectibles'][] = [
                'id' => $cid, 'name' => $item['name'], 'edition' => $edition,
                'rows' => $lines, 'refs' => $refs, 'minted' => $minted,
            ];
        }

        $plan['summary'] = [
            'collectibles' => count($plan['collectibles']),
            'rows'         => $rowsToChange,
            'clean'        => $clean,
            'blocked'      => $blocked,
        ];

        if ($rowsToChange === 0) {
            $output->writeln('<info>存量编号全部合规（无越界号、无占位符、无重号），无需重编。</info>');
            return 0;
        }

        $file = $this->writePlan($plan);
        $output->writeln('');
        $output->writeln(sprintf(
            '<comment>只读预览：共 %d 个藏品、%d 件资产待改号（另有 %d 个藏品编号本就合规、%d 个被挡住）。</comment>',
            $plan['summary']['collectibles'], $rowsToChange, $clean, $blocked
        ));
        $output->writeln('  方案已存盘：' . $file);
        $output->writeln('  落库前先备份：mysqldump 导一份 sinan_nft 存档，再执行：');
        $output->writeln('    php think serial:renumber --apply --from=' . $file);
        $output->writeln('  （本命令不改发行量、不动链上凭证，改号会把这些行的 updated_at 前移）');
        return 0;
    }

    /**
     * 读存量：藏品信息 + 该藏品全部资产行（含已消耗/已转赠，它们同样占号）
     * @param int[] $onlyIds
     * @return array<int, array{name:string, edition:int, rows:array}>
     */
    private function collectStock(array $onlyIds): array
    {
        $q = Db::name('user_collectibles')
            ->field('id, user_id, collectible_id, serial, status, mint_status')
            ->order('collectible_id asc, id asc');
        if ($onlyIds) {
            $q->whereIn('collectible_id', $onlyIds);
        }
        $rows = $q->select()->toArray();

        $cids = array_values(array_unique(array_column($rows, 'collectible_id')));
        $cs   = $cids ? Db::name('collectibles')->whereIn('id', $cids)->column('name,edition,deleted_at', 'id') : [];

        $items = [];
        foreach ($cids as $cid) {
            $items[$cid] = [
                'name'    => (string) ($cs[$cid]['name'] ?? '藏品#' . $cid),
                'edition' => (int) ($cs[$cid]['edition'] ?? 0),
                'rows'    => [],
            ];
        }
        foreach ($rows as $r) {
            $items[(int) $r['collectible_id']]['rows'][] = $r;
        }
        return $items;
    }

    /** 三处按编号值存快照的表，各有多少行要跟着改 */
    private function referenceImpact(int $cid, array $rows, array $assign): array
    {
        $oldToNew = [];
        foreach ($rows as $row) {
            $old = (string) ($row['serial'] ?? '');
            if ($old !== '' && $old !== $assign[(int) $row['id']]) $oldToNew[$old] = $assign[(int) $row['id']];
        }
        $ucIds = array_map(static fn ($r) => (int) $r['id'], $rows);

        $swap = Db::name('swap_plan_details')->whereIn('user_collectible_id', $ucIds)->count();

        $decompose = $oldToNew
            ? Db::name('decompose_records')->where('source_collectible_id', $cid)->whereIn('source_serial', array_keys($oldToNew))->count()
            : 0;

        $inbox = 0;
        if ($oldToNew) {
            foreach ($this->inboxCandidates($cid, $oldToNew) as $c) {
                $inbox++;
            }
        }
        return ['swap' => $swap, 'decompose' => $decompose, 'inbox' => $inbox];
    }

    /** 通知表按 collectible_id 缩小范围后，在 PHP 侧解 JSON 匹配旧编号（避免 JSON SQL 的转义坑） */
    private function inboxCandidates(int $cid, array $oldToNew): array
    {
        $out = [];
        $rows = Db::name('inbox')->where('collectible_id', $cid)->field('id, extra')->select();
        foreach ($rows as $r) {
            $extra = json_decode((string) ($r['extra'] ?? ''), true);
            if (is_array($extra) && isset($extra['serial']) && isset($oldToNew[(string) $extra['serial']])) {
                $out[] = ['id' => (int) $r['id'], 'serial' => (string) $extra['serial']];
            }
        }
        return $out;
    }

    private function writePlan(array $plan): string
    {
        $dir = runtime_path() . 'serial_plans';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . '/renumber_' . date('Ymd_His') . '.json';
        file_put_contents($file, json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $file;
    }

    // ==================== 按方案落库 ====================

    private function doApply(string $from, Output $output): int
    {
        if ($from === '' || !is_file($from)) {
            $output->writeln('<error>--apply 需要一个存在的方案文件：--from=runtime/serial_plans/renumber_xxx.json（先用不带 --apply 的预览生成）</error>');
            return 1;
        }
        $plan = json_decode((string) file_get_contents($from), true);
        if (!is_array($plan) || ($plan['version'] ?? null) !== self::PLAN_VERSION || empty($plan['collectibles'])) {
            $output->writeln('<error>方案文件格式不对或为空，拒绝执行：' . $from . '</error>');
            return 1;
        }

        // 先整体核对，任何一处对不上就一行都不改（免得改到一半留下「半新半旧」的号池）
        $verified = [];
        foreach ($plan['collectibles'] as $item) {
            if (!empty($item['skipped'])) continue;
            $cid    = (int) $item['id'];
            $expect = $item['rows'] ?? [];
            if (!$expect) continue;

            $edition = (int) Db::name('collectibles')->where('id', $cid)->value('edition');
            if ($edition !== (int) $item['edition']) {
                $output->writeln("<error>藏品 {$cid} 的发行量已从 {$item['edition']} 变为 {$edition}，方案作废，请重跑预览</error>");
                return 1;
            }

            $current = Db::name('user_collectibles')->whereIn('id', array_column($expect, 'id'))
                ->column('serial', 'id');
            if (count($current) !== count($expect)) {
                $output->writeln("<error>藏品 {$cid} 的资产行有增减（方案里 " . count($expect) . " 行，库里匹配到 " . count($current) . " 行），方案作废，请重跑预览</error>");
                return 1;
            }
            $seen = [];
            foreach ($expect as $row) {
                $id = (int) $row['id'];
                if ((string) ($current[$id] ?? '') !== (string) $row['old']) {
                    $output->writeln("<error>资产 {$id} 的编号已变（方案里 {$row['old']}，库里 " . ($current[$id] ?? '行不存在') . '），方案作废，请重跑预览</error>');
                    return 1;
                }
                $no = SerialService::parseNo((string) $row['new']);
                if ($no === null || $no > $edition || isset($seen[$no])) {
                    $output->writeln("<error>方案本身不合法：藏品 {$cid} 的新编号 {$row['new']} 越界或重号</error>");
                    return 1;
                }
                $seen[$no] = true;
            }

            // 同藏品没被方案覆盖的行，不能占用方案要发的新号
            $other = Db::name('user_collectibles')->where('collectible_id', $cid)
                ->whereNotIn('id', array_column($expect, 'id'))->column('serial');
            foreach ($other as $serial) {
                $n = SerialService::parseNo($serial);
                if ($n !== null && isset($seen[$n])) {
                    $output->writeln("<error>藏品 {$cid} 有方案外的资产行已占用新编号 {$serial}，方案作废，请重跑预览</error>");
                    return 1;
                }
            }

            $verified[] = ['cid' => $cid, 'rows' => $expect];
        }

        if (!$verified) {
            $output->writeln('<info>方案里没有待改的行，无需写库。</info>');
            return 0;
        }

        $changed = $swapSync = $decomposeSync = $inboxSync = 0;
        Db::startTrans();
        try {
            foreach ($verified as $v) {
                $cid  = $v['cid'];
                $maps = [];   // 旧编号 => 新编号（只收真正会变的那些行）
                foreach ($v['rows'] as $row) {
                    if ((string) $row['old'] !== (string) $row['new']) {
                        $maps[(string) $row['old']] = (string) $row['new'];
                    }
                }

                // 通知快照先在改资产行之前捞出来：extra 里存的还是旧编号，匹配得上
                $inboxPatches = $maps ? $this->inboxCandidates($cid, $maps) : [];

                foreach ($v['rows'] as $row) {
                    $id  = (int) $row['id'];
                    $new = (string) $row['new'];
                    if ($new === (string) $row['old']) continue;

                    // 两段式写号：先挪到 TMP-R-{资产ID}（parseNo 认不出、也不在号池里），
                    // 再落最终号。一步写的话，新号可能正好撞上还没改走的别人的旧号，
                    // 直接踩 uk_collectible_serial 唯一索引报错。
                    Db::name('user_collectibles')->where('id', $id)->update(['serial' => 'TMP-R-' . $id]);
                    Db::name('user_collectibles')->where('id', $id)->update(['serial' => $new]);
                    $changed++;

                    // 换计划明细按资产行 ID 精确回写，不靠编号值匹配
                    $swapSync += Db::name('swap_plan_details')->where('user_collectible_id', $id)->update(['serial' => $new]);
                }

                // 分解台账只存了源资产编号，按旧值匹配
                foreach ($maps as $old => $new) {
                    if ($old === '') continue;
                    $decomposeSync += Db::name('decompose_records')
                        ->where('source_collectible_id', $cid)
                        ->where('source_serial', $old)
                        ->update(['source_serial' => $new]);
                }

                foreach ($inboxPatches as $c) {
                    $extra = json_decode((string) Db::name('inbox')->where('id', $c['id'])->value('extra'), true);
                    if (!is_array($extra)) continue;
                    $extra['serial'] = $maps[$c['serial']] ?? $c['serial'];
                    Db::name('inbox')->where('id', $c['id'])->update(['extra' => json_encode($extra, JSON_UNESCAPED_UNICODE)]);
                    $inboxSync++;
                }
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            $output->writeln('<error>写库失败，已整体回滚：' . $e->getMessage() . '</error>');
            return 1;
        }

        $output->writeln("<info>重编完成：{$changed} 件资产改了编号；同步换计划 {$swapSync} 行、分解台账 {$decomposeSync} 行、站内通知 {$inboxSync} 条。</info>");
        $output->writeln('<comment>建议接着核验：SELECT collectible_id, MAX(CAST(SUBSTRING_INDEX(serial, \'-\', -1) AS UNSIGNED)) AS max_no, COUNT(*) AS cnt');
        $output->writeln('           FROM nft_user_collectibles GROUP BY collectible_id;  每个藏品的 max_no 都应 ≤ 发行量。</comment>');
        return 0;
    }

    /** @return int[] */
    private function parseIds(string $raw): array
    {
        $ids = [];
        foreach (preg_split('/[,\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            $n = (int) $p;
            if ($n > 0) $ids[] = $n;
        }
        return array_values(array_unique($ids));
    }
}
