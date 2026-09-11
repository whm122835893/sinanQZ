<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 抽签码（用户资产）发放服务
 *
 * 抽签码是参与抽签后发放给用户的凭证，可累积；来源：
 *   1 报名 / 2 邀请 / 3 购买
 */
class DrawCodeService
{
    public const SOURCE_RAFFLE   = 1;
    public const SOURCE_INVITE   = 2;
    public const SOURCE_PURCHASE = 3;

    /**
     * 发放一个抽签码，返回码串（调用方须处于事务内，靠 uk_code 唯一索引兜底防碰撞）
     */
    public static function grant(int $userId, int $source, ?int $activityId = null): string
    {
        do {
            $code = gen_draw_code();
        } while (Db::name('user_draw_codes')->where('code', $code)->find());

        Db::name('user_draw_codes')->insert([
            'user_id'     => $userId,
            'code'        => $code,
            'source'      => $source,
            'activity_id' => $activityId,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        return $code;
    }

    /**
     * 用户持有抽签码总数（表未迁移时优雅降级为 0，避免详情页 500）
     */
    public static function count(int $userId): int
    {
        if (!self::tableExists()) return 0;
        return (int) Db::name('user_draw_codes')->where('user_id', $userId)->count();
    }

    /**
     * 某活动内用户获得的抽签码（报名发放）
     */
    public static function activityCodes(int $userId, int $activityId): array
    {
        if (!self::tableExists()) return [];
        return Db::name('user_draw_codes')
            ->where('user_id', $userId)
            ->where('activity_id', $activityId)
            ->order('id', 'asc')
            ->column('code');
    }

    /**
     * 用户全部抽签码（码号列表，按发放时间正序）
     */
    public static function codes(int $userId): array
    {
        if (!self::tableExists()) return [];
        return Db::name('user_draw_codes')
            ->where('user_id', $userId)
            ->order('id', 'asc')
            ->column('code');
    }

    /**
     * 资产表是否存在（进程内缓存判断一次）
     */
    private static function tableExists(): bool
    {
        static $exists = null;
        if ($exists === null) {
            try {
                Db::query('SELECT 1 FROM `nft_user_draw_codes` LIMIT 1');
                $exists = true;
            } catch (\Throwable $e) {
                $exists = false;
            }
        }
        return $exists;
    }
}