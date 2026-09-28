<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 钱包服务（文档 4.6 资金账户）
 *
 * nft_wallets 行由注册流程创建（Auth::register），但存量库存在「有用户、无钱包行」
 * 的数据（早期夹具与直连 SQL 播种）。此时 lock(true)->find() 返回 null 后直接取列，
 * 在 PHP 8 下抛 ErrorException 并被业务 try/catch 转成 5001「操作失败」，
 * 充值 / 余额支付 / 结算 等资金入口整体不可用，故统一走本方法取行。
 */
class WalletService
{
    /**
     * 取用户钱包行并加行锁，缺失时创建为空钱包（必须在事务内调用）
     *
     * 并发：对不存在的行加锁只持间隙锁，两个事务同时为同一用户开通时，后者在 INSERT
     * 上撞 user_id 唯一键并整体回滚，由调用方的 catch 提示「请稍后重试」。
     */
    public static function ensureLocked(int $userId): array
    {
        $wallet = Db::name('wallets')->where('user_id', $userId)->lock(true)->find();
        if ($wallet) return $wallet;

        $now = date('Y-m-d H:i:s.v');
        Db::name('wallets')->insert([
            'user_id'    => $userId,
            'balance'    => 0,
            'available'  => 0,
            'frozen'     => 0,
            'points'     => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return Db::name('wallets')->where('user_id', $userId)->lock(true)->find();
    }
}
