<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * 购买资格判定服务（文档 5.1 资格购 / 5.2 优先购）
 *
 * 供 C 端藏品详情（联动点 10.1）与下单（联动点 10.2）复用，
 * 判定链：Step1 优先购资格 → Step2 是否开启资格购 → Step3 条件组合判定。
 *
 * 优先购与资格购完全独立；白名单是唯一"无条件"通道；
 * 资格购只是门槛，购买仍受每人限购约束。
 */
class PurchaseQualifyService
{
    /**
     * Step 1：用户对某藏品的有效优先购资格（不加行锁，供展示用）
     *
     * 有效 = 资格 expires_at > now 且 used_quantity < max_quantity 且 status=1
     *       且活动 status=1 且当前在活动窗口内。
     *
     * @return array|null 命中的白名单行（含活动信息），无资格返回 null
     */
    public static function priorityQualification(int $userId, int $collectibleId): ?array
    {
        return Db::name('priority_sale_whitelists')->alias('w')
            ->join('priority_sales ps', 'ps.id = w.priority_sale_id', 'INNER')
            ->where('ps.collectible_id', $collectibleId)
            ->where('ps.status', 1)
            ->where('w.user_id', $userId)
            ->where('w.status', 1)
            ->where('w.expires_at', '>', date('Y-m-d H:i:s'))
            ->where('ps.start_time', '<=', date('Y-m-d H:i:s'))
            ->where('ps.end_time', '>=', date('Y-m-d H:i:s'))
            ->whereRaw('w.used_quantity < w.max_quantity')
            ->field('w.*,ps.id AS sale_id,ps.name AS sale_name,ps.start_time,ps.end_time')
            ->find();
    }

    /**
     * Step 2/3：资格购条件判定（不加行锁，展示与下单共用）
     *
     * @param int   $userId     用户ID（0 = 未登录，仅返回开启状态与提示）
     * @param array $collectible 藏品行（至少含 id）
     * @return array{enabled:bool, qualified:bool, reason:string, conditionType:int, requirements:array}
     *                           reason 为未满足时的提示文案（展示用）
     */
    public static function checkEligibility(int $userId, array $collectible): array
    {
        $result = [
            'enabled'       => false,
            'qualified'     => true,
            'reason'        => '',
            'conditionType' => 1,
            'requirements'  => [],
        ];

        $config = Db::name('qualification_configs')
            ->where('collectible_id', (int) $collectible['id'])
            ->where('is_enabled', 1)
            ->find();
        if (!$config) {
            return $result; // 未开启资格购：正常购买
        }

        $result['enabled']       = true;
        $result['conditionType'] = (int) $config['condition_type'];

        $nowTs = time();
        // 有效期判定
        if (!empty($config['valid_start_at']) && strtotime((string) $config['valid_start_at']) > $nowTs) {
            $result['qualified'] = false;
            $result['reason']    = '资格购尚未开始';
            return $result;
        }
        if (!empty($config['valid_end_at']) && strtotime((string) $config['valid_end_at']) < $nowTs) {
            $result['qualified'] = false;
            $result['reason']    = '资格购有效期已结束';
            return $result;
        }

        // 未登录：仅展示开启状态
        if ($userId <= 0) {
            $result['qualified'] = false;
            $result['reason']    = '登录后查看是否具备购买资格';
            return $result;
        }

        $requiredIds   = json_decode((string) ($config['required_collectible_ids'] ?? '[]'), true) ?: [];
        $checkinDays   = (int) $config['required_checkin_days'];
        $inviteCount   = (int) $config['required_invite_count'];
        $conditionType = (int) $config['condition_type'];

        $requirements = [];
        if ($requiredIds) {
            $names = Db::name('collectibles')->whereIn('id', $requiredIds)->column('name');
            $requirements['collectible'] = '持有 ' . implode(' / ', $names);
        }
        if ($checkinDays > 0) {
            $requirements['checkin'] = "累计签到 {$checkinDays} 天";
        }
        if ($inviteCount > 0) {
            $requirements['invite'] = "累计邀请 {$inviteCount} 人";
        }
        $result['requirements'] = $requirements;

        // A. 额外手机号白名单命中（无条件通道，文档 5.1-4）
        $wl = Db::name('qualification_whitelists')
            ->where('config_id', (int) $config['id'])
            ->where('user_id', $userId)
            ->where('status', 1)
            ->find();
        $whitelistHit = $wl
            && (empty($wl['expires_at']) || strtotime((string) $wl['expires_at']) > $nowTs);

        if ($whitelistHit) {
            $result['qualified'] = true;
            return $result; // 白名单用户无需满足其他条件
        }

        $met = [];
        // B. 持有资格藏品（至少 1 个，仅统计状态 held 的持有资产）
        if ($requiredIds) {
            $held = Db::name('user_collectibles')
                ->where('user_id', $userId)
                ->whereIn('collectible_id', $requiredIds)
                ->where('status', 'held')
                ->count();
            if ($held > 0) {
                $met['collectible'] = true;
            }
        }
        // C. 累计签到天数（按去重签到日计）
        if ($checkinDays > 0) {
            $days = Db::name('check_in_records')
                ->where('user_id', $userId)
                ->distinct(true)
                ->field('check_in_date')
                ->count('check_in_date');
            if ($days >= $checkinDays) {
                $met['checkin'] = true;
            }
        }
        // D. 累计邀请人数
        if ($inviteCount > 0) {
            $invited = Db::name('invite_records')->where('inviter_id', $userId)->count();
            if ($invited >= $inviteCount) {
                $met['invite'] = true;
            }
        }

        if ($conditionType === 2) {
            // 满足全部（白名单已排除；所有已配置条件均须满足）
            $need = array_keys($requirements);
            $result['qualified'] = empty(array_diff($need, array_keys($met)));
        } else {
            // 满足任一
            $result['qualified'] = !empty($met);
        }

        if (!$result['qualified']) {
            $result['reason'] = '未获得购买资格';
        }
        return $result;
    }

    /**
     * 藏品级每人限购（per_user_limit 非 0 时覆盖系统 purchase_limit_per_user，联动点 10.2）
     */
    public static function perUserLimit(array $collectible): int
    {
        $perUserLimit = (int) ($collectible['per_user_limit'] ?? 0);
        if ($perUserLimit > 0) {
            return $perUserLimit;
        }
        $limit = (int) Db::name('system_configs')
            ->where('config_key', 'purchase_limit_per_user')
            ->value('config_value');
        return $limit ?: 5;
    }
}
