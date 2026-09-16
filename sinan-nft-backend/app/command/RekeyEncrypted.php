<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\console\input\Option;
use think\facade\Db;

/**
 * 应用层 AES 密钥轮换：将存量密文从旧 APP_KEY 重加密到新 APP_KEY
 *
 * 场景：更换 .env 的 APP_KEY 前，须先用本命令把已加密落库的敏感数据
 * （实名姓名/身份证、短信密钥、支付渠道配置、链网络密钥）按旧密钥解密、
 * 新密钥重加密；否则换密钥后 aes_decrypt 将全部返回 null（数据变砖）。
 *
 * 用法：
 *   php think rekey:encrypted <旧密钥> <新密钥>           # 实际执行
 *   php think rekey:encrypted <旧密钥> <新密钥> --dry-run # 仅统计不写库
 *
 * 幂等：已用「新密钥」加密的字段会识别并跳过，可安全重跑。
 * 若某字段用新旧密钥都无法解密（疑为明文/异常数据），仅告警并跳过，不做破坏。
 */
class RekeyEncrypted extends Command
{
    private const TARGETS = [
        'users'            => ['real_name', 'id_card'],
        'sms_configs'      => ['access_key', 'access_secret'],
        'payment_channels' => ['config'],
        'chain_networks'   => ['api_key', 'api_secret'],
    ];

    protected function configure()
    {
        $this->setName('rekey:encrypted')
            ->setDescription('轮换应用层 AES 密钥：重加密实名/密钥等存量密文');
        $this->addArgument('oldKey', Argument::REQUIRED, '旧 APP_KEY（当前 .env 中的值）');
        $this->addArgument('newKey', Argument::REQUIRED, '新 APP_KEY（≥32 字节随机串）');
        $this->addOption('dry-run', null, Option::VALUE_NONE, '仅统计预览，不实际写库');
    }

    protected function execute(Input $input, Output $output)
    {
        $oldKey = trim((string) $input->getArgument('oldKey'));
        $newKey = trim((string) $input->getArgument('newKey'));
        $dryRun = (bool) $input->getOption('dry-run');

        if ($oldKey === '' || $newKey === '') {
            $output->writeln('<error>旧密钥与新密钥都不能为空</error>');
            return 1;
        }
        if ($oldKey === $newKey) {
            $output->writeln('<error>新旧密钥相同，无需轮换</error>');
            return 1;
        }
        if (strlen($newKey) < 32) {
            $output->writeln('<error>新密钥长度不足 32 字节，建议使用 ≥32 字节随机串</error>');
            return 1;
        }

        $total = 0;
        $skipped = 0;
        $failed = 0;

        foreach (self::TARGETS as $table => $cols) {
            $colExpr = implode(',', $cols);
            try {
                $rows = Db::name($table)->field("id, {$colExpr}")->select();
            } catch (\Throwable $e) {
                $output->writeln("<warning>表 {$table} 读取失败（可能不存在，跳过）：{$e->getMessage()}</warning>");
                continue;
            }

            foreach ($rows as $row) {
                foreach ($cols as $col) {
                    $ct = (string) ($row[$col] ?? '');
                    if ($ct === '') {
                        continue;
                    }

                    $pt = $this->decrypt($ct, $oldKey);
                    if ($pt === null) {
                        if ($this->decrypt($ct, $newKey) !== null) {
                            $skipped++;
                            continue;
                        }
                        $failed++;
                        $output->writeln("<warning>无法解密（新旧密钥均失败，跳过）：{$table}#{$row['id']}.{$col}</warning>");
                        continue;
                    }

                    $total++;
                    if (!$dryRun) {
                        Db::name($table)->where('id', (int) $row['id'])->update([$col => $this->encrypt($pt, $newKey)]);
                    }
                }
            }
        }

        $mode = $dryRun ? '（dry-run，未写库）' : '（已写库）';
        $output->writeln("<info>重加密完成{$mode}：迁移 {$total} 个字段，跳过(已是新密钥) {$skipped} 个，异常 {$failed} 个</info>");
        if (!$dryRun && $total > 0) {
            $output->writeln('<comment>请立即把 .env 的 APP_KEY 更新为新密钥，并重启后端服务。</comment>');
        }
        return 0;
    }

    private function encrypt(string $data, string $key): string
    {
        $k  = hash('sha256', $key, true);
        $iv = openssl_random_pseudo_bytes(16);
        return base64_encode($iv . openssl_encrypt($data, 'AES-256-CBC', $k, 0, $iv));
    }

    private function decrypt(string $encoded, string $key): ?string
    {
        $decoded = base64_decode($encoded);
        if (strlen($decoded) < 40) {
            return null;
        }
        $k  = hash('sha256', $key, true);
        $iv = substr($decoded, 0, 16);
        $ct = substr($decoded, 16);
        $pt = openssl_decrypt($ct, 'AES-256-CBC', $k, 0, $iv);
        return $pt !== false ? $pt : null;
    }
}