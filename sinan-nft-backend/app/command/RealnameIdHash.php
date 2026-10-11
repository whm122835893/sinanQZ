<?php
declare(strict_types=1);

namespace app\command;

use app\service\RealnameService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/**
 * 一证一号：身份证指纹列的只读统计与存量回填
 *
 * 用法：
 *   php think realname:id-hash            # 只读统计（默认，绝不写库）：存量重复、缺指纹的账号
 *   php think realname:id-hash --backfill # 给所有「已审核通过」的账号回填指纹（幂等，可反复跑）
 *
 * 为什么需要它：
 *   迁移 20261010_realname_id_card_unique.sql 只能建列，算不出指纹（HMAC 的密钥在 .env 里，
 *   MySQL 侧也解不开随机 IV 的密文），存量已认证账号的指纹必须用本命令按当前 APP_KEY 补。
 *
 * 幂等：指纹 = HMAC(归一化证号, APP_KEY)，值相同不写，所以第二遍跑是真正 no-op（updated_at 不前移）。
 * 换密钥：rekey:encrypted 会在重加密证号时按新密钥同步改写指纹；绕过它直接改 .env 的话，
 * 存量指纹全部对不上、查重静默失效，必须补跑一次本命令的 --backfill。
 *
 * 输出只带 UID，绝不打印证件号明文或密文。
 */
class RealnameIdHash extends Command
{
    protected function configure()
    {
        $this->setName('realname:id-hash')
            ->setDescription('统计/回填身份证指纹（一证一号）：php think realname:id-hash [--backfill]');
        $this->addOption('backfill', null, Option::VALUE_NONE, '写库回填存量指纹（省略则只读统计）');
    }

    protected function execute(Input $input, Output $output)
    {
        $backfill = (bool) $input->getOption('backfill');

        try {
            $stat = RealnameService::syncIdCardHashes($backfill);
        } catch (\Throwable $e) {
            $output->writeln('<error>执行失败：' . $e->getMessage() . '</error>');
            $output->writeln('<comment>若是 Unknown column \'id_card_hash\'，先执行 migrations/20261010_realname_id_card_unique.sql</comment>');
            return 1;
        }

        $mode = $backfill ? '回填' : '只读统计';
        $output->writeln("[realname:id-hash] {$mode}：已认证账号 {$stat['scanned']} 个");
        $output->writeln("  指纹已正确：{$stat['unchanged']} 个");
        $output->writeln("  指纹待写/需修正：{$stat['pending']} 个" . ($backfill ? "（本次已写入 {$stat['filled']} 个）" : ''));
        $output->writeln("  算不出指纹（证件列空或密文解不开）：{$stat['undecryptable']} 个");

        foreach ($stat['without_fingerprint'] as $row) {
            $output->writeln("    - UID {$row['uid']}（id={$row['id']}）：不占用证号，需人工核实实名数据");
        }

        if ($stat['duplicate_groups']) {
            $output->writeln("<warning>  同一证号认证了多个账号：" . count($stat['duplicate_groups']) . " 组（历史缺陷遗留）</warning>");
            foreach ($stat['duplicate_groups'] as $i => $group) {
                $uids = implode('、', array_map(static fn ($r) => $r['uid'] . "(id={$r['id']})", $group));
                $output->writeln("    组" . ($i + 1) . "：" . $uids . ' —— 按实名通过时间先后排列（同一时刻按 id），谁算正式占用请人工确认');
            }
            $output->writeln('  处理完后可把索引升级成硬约束（代码不用改）：');
            $output->writeln('    ALTER TABLE nft_users DROP INDEX idx_id_card_hash, ADD UNIQUE KEY uk_id_card_hash (id_card_hash);');
        } else {
            $output->writeln('  存量重复：0 组');
        }

        if (!$backfill && ($stat['filled'] > 0 || $stat['undecryptable'] > 0 || $stat['duplicate_groups'])) {
            $output->writeln('<comment>只读模式没有写库。确认口径后跑：php think realname:id-hash --backfill</comment>');
        }
        return 0;
    }
}
