<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Argument;
use think\facade\Db;

/**
 * 重置管理后台管理员密码
 *
 * 用法：
 *   php think admin:reset-password admin              # 生成随机强密码
 *   php think admin:reset-password admin 'NewPass!123' # 指定新密码
 *
 * 说明：密码以 bcrypt(cost=10) 落库，与后台登录校验逻辑一致；
 * 同时清除该账号的登录失败计数与锁定状态，避免重置后仍处于锁定。
 */
class AdminResetPassword extends Command
{
    protected function configure()
    {
        $this->setName('admin:reset-password')
            ->setDescription('重置管理后台管理员密码（php think admin:reset-password <username> [password]）');
        $this->addArgument('username', Argument::REQUIRED, '管理员登录账号');
        $this->addArgument('password', Argument::OPTIONAL, '新密码（省略则自动生成随机强密码）');
    }

    protected function execute(Input $input, Output $output)
    {
        $username = trim((string) $input->getArgument('username'));
        $password = trim((string) $input->getArgument('password'));

        if ($username === '') {
            $output->writeln('<error>账号不能为空</error>');
            return 1;
        }

        $admin = Db::name('admin_users')->where('username', $username)->whereNull('deleted_at')->find();
        if (!$admin) {
            $output->writeln("<error>未找到未删除的管理员账号：{$username}</error>");
            return 1;
        }

        $generated = false;
        if ($password === '') {
            $password  = self::randomPassword(16);
            $generated = true;
        }
        if (strlen($password) < 8) {
            $output->writeln('<error>密码长度至少 8 位</error>');
            return 1;
        }

        Db::name('admin_users')->where('id', (int) $admin['id'])->update([
            'password_hash'      => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
            'login_fail_count'   => 0,
            'locked_until'       => null,
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);

        $output->writeln("<info>已重置管理员 [{$username}]（id={$admin['id']}）的密码并解除锁定</info>");
        if ($generated) {
            $output->writeln("<comment>新密码（仅本次显示，请立即复制保存）：{$password}</comment>");
        }
        return 0;
    }

    private static function randomPassword(int $len): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnpqrstuvwxyz';
        $digit = '23456789';
        $sym   = '!@#$%&*';
        $all   = $upper . $lower . $digit . $sym;

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digit[random_int(0, strlen($digit) - 1)],
            $sym[random_int(0, strlen($sym) - 1)],
        ];
        for ($i = count($chars); $i < $len; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($chars);
        return implode('', $chars);
    }
}