<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;
use think\facade\Log;

/**
 * 实名认证领域服务
 *
 * 统一「实名审核通过」的口径（C 端自动通过 / 管理端人工审核共用）：
 * 1. 一证一号校验：同一张身份证只允许一个账号认证通过（详见 approve 内注释）
 * 2. 状态更新：realname_status=2 + is_realname=1（解锁购买/寄售/转赠）+ 写入证号指纹
 * 3. 活动结算：注册活动（实名排位）+ 邀请活动（被邀请人完成实名）
 *    独立事务，奖励发放失败不阻断审核（记日志，可在奖励名单中排查）
 */
class RealnameService
{
    /** C 端可见的冲突提示：不带对方 UID，避免有人拿报错接口探测别人的实名情况 */
    public const OCCUPIED_MSG_PUBLIC = '该身份证已绑定其他账号，如需处理请联系客服';

    /**
     * 审核通过（自动/手动同口径）
     *
     * @param int   $userId      用户ID
     * @param array $extraUpdate 附加更新字段（C 端提交即通过时合并提交数据，
     *                           如 real_name / id_card / realname_submitted_at）
     * @return array{register: array|null, invite: array|null} 活动结算结果
     * @throws IdCardBoundException 该证号已被其他账号认证占用
     */
    public static function approve(int $userId, array $extraUpdate = []): array
    {
        $now = date('Y-m-d H:i:s');

        // 一证一号（2026-10-10）：id_card 是随机 IV 的 AES 密文，同一证号两次密文不同，
        // 所以密文列上加唯一索引拦不住重复（这就是原来记在 RUNBOOK §8 的缺陷）。
        // 改用 id_card_hash = HMAC-SHA256(归一化证号, APP_KEY) 作为比对列。
        // 占用口径：**只有认证通过的账号占用**，待审核/已驳回不占坑
        //          ——否则乱填一个证号就能把真主人的号锁在门外。
        // 本次要用的证号：C 端新提交在 extraUpdate['id_card']（刚生成的密文），
        // 人工审核取库里已有的密文。两条路都在这一处解析，口径不会分叉。
        $fingerprint = self::fingerprintFrom($userId, $extraUpdate);

        $update = array_merge($extraUpdate, [
            'realname_status'        => 2,
            'is_realname'            => 1,
            'realname_verified_at'   => $now,
            'realname_reject_reason' => '',
            'updated_at'             => $now,
        ]);
        // 密文解不开（存量异常数据）时指纹留空，不猜、也不把别人的坑当成自己的；
        // 这类行由 php think realname:id-hash 统计出来人工修复。
        if ($fingerprint !== null) $update['id_card_hash'] = $fingerprint;

        $occupy = static function () use ($userId, $fingerprint, $update): void {
            if ($fingerprint !== null) {
                // 查重与写入在同一事务内并加行锁：两个人同时提交同一证号时，
                // 后一个要么在这里看到已占用的行，要么在写指纹时撞唯一索引（升级后），不会两个都通过。
                $owner = self::findOwner($userId, $fingerprint, true);
                if ($owner) throw self::occupied($owner);
            }
            Db::name('users')->where('id', $userId)->update($update);
        };

        try {
            $fingerprint !== null ? Db::transaction($occupy) : $occupy();
        } catch (IdCardBoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // 运维把索引升级成 UNIQUE（RUNBOOK 7.13 给了一条 SQL）之后，并发漏网会在这里兜住
            if ($fingerprint !== null && stripos($e->getMessage(), 'duplicate entry') !== false) {
                throw self::occupied(['uid' => '']);
            }
            throw $e;
        }

        $settled = ['register' => null, 'invite' => null];
        ActivityRewardService::settleQuietly(function () use ($userId, &$settled) {
            $settled['register'] = ActivityRewardService::settleRegisterReward($userId);
            $settled['invite']   = ActivityRewardService::settleInviteReward($userId);
        });
        return $settled;
    }

    /**
     * 这张证号是否已被**别的**已认证账号占用（C 端提交前的早查，报错比走完整链路友好）
     *
     * @param string $idCard     明文证号（调用方已完成格式校验，这里只做归一）
     * @param int    $selfUserId 排除的账号：本账号被驳回后重新提交同一证号要放行
     * @return array{id: int, uid: string}|null
     */
    public static function occupiedBy(string $idCard, int $selfUserId): ?array
    {
        return self::findOwner($selfUserId, id_card_fingerprint($idCard));
    }

    /**
     * 存量证号指纹：只读统计 / 回填（php think realname:id-hash 与 rekey:encrypted 共用）
     *
     * 幂等：指纹 = 归一化明文 + 当前 APP_KEY 的 HMAC，重复执行算出的值一致；
     * 值没变就不写，所以第二遍跑是真 no-op（updated_at 不会前移）。
     * 换 APP_KEY 后旧指纹全部对不上，必须重跑回填（rekey:encrypted 已自动接上这一步）。
     *
     * @param bool $backfill false=只读统计（默认，绝不写库）  true=回填
     * @return array{scanned: int, filled: int, unchanged: int, pending: int, undecryptable: int, without_fingerprint: array, duplicate_groups: array}
     */
    public static function syncIdCardHashes(bool $backfill = false): array
    {
        $stat = [
            'scanned' => 0, 'filled' => 0, 'unchanged' => 0, 'pending' => 0, 'undecryptable' => 0,
            'without_fingerprint' => [], 'duplicate_groups' => [],
        ];

        // 已认证的账号才占用；软删除的账号不算（注销后证号应能重新使用）
        $rows = Db::name('users')
            ->field('id, uid, id_card, id_card_hash')
            ->where('is_realname', 1)
            ->whereNull('deleted_at')
            ->order('realname_verified_at', 'asc')
            ->order('id', 'asc')
            ->select();

        $byHash = [];
        foreach ($rows as $row) {
            $stat['scanned']++;
            $cipher = (string) ($row['id_card'] ?? '');
            $plain  = $cipher !== '' ? aes_decrypt($cipher) : null;
            if ($plain === null || trim($plain) === '') {
                // 已实名却算不出指纹 = 这个账号既不占用证号、也不被别人挡：需要人工修复
                $stat['undecryptable']++;
                $stat['without_fingerprint'][] = ['id' => (int) $row['id'], 'uid' => (string) $row['uid']];
                continue;
            }
            $hash = id_card_fingerprint($plain);
            $byHash[$hash][] = ['id' => (int) $row['id'], 'uid' => (string) $row['uid']];

            if ((string) ($row['id_card_hash'] ?? '') === $hash) {
                $stat['unchanged']++;
            } else {
                // 只读模式也统计「需要写多少」，好让运维先看到规模再决定跑不跑 --backfill
                $stat['pending']++;
                if ($backfill) {
                    Db::name('users')->where('id', (int) $row['id'])->update(['id_card_hash' => $hash]);
                    $stat['filled']++;
                }
            }
        }

        foreach ($byHash as $hash => $owners) {
            if ($hash !== '' && count($owners) > 1) $stat['duplicate_groups'][] = $owners;
        }
        return $stat;
    }

    /** 本次通过动作可用的指纹；拿不到合法证号（无数据/解不开）时返回 null */
    private static function fingerprintFrom(int $userId, array $extraUpdate): ?string
    {
        $cipher = array_key_exists('id_card', $extraUpdate)
            ? (string) $extraUpdate['id_card']
            : (string) Db::name('users')->where('id', $userId)->value('id_card');
        if ($cipher === '') return null;

        $plain = aes_decrypt($cipher);
        if ($plain === null || trim($plain) === '') {
            Log::warning("实名审核通过但身份证密文无法解密（user_id={$userId}）：本次不写证号指纹，请跑 php think realname:id-hash 排查");
            return null;
        }
        return id_card_fingerprint($plain);
    }

    /** @return array{id: int, uid: string}|null */
    private static function findOwner(int $excludeUserId, string $fingerprint, bool $lock = false): ?array
    {
        $query = Db::name('users')
            ->field('id, uid')
            ->where('id_card_hash', $fingerprint)
            ->where('is_realname', 1)
            ->where('id', '<>', $excludeUserId)
            ->whereNull('deleted_at');
        $row = $lock ? $query->lock(true)->find() : $query->find();
        return $row ?: null;
    }

    /** 组装异常：getMessage() 带对方 UID，只能给管理端看，C 端用 OCCUPIED_MSG_PUBLIC */
    private static function occupied(array $owner): IdCardBoundException
    {
        $uid = (string) ($owner['uid'] ?? '');
        $e = new IdCardBoundException($uid !== ''
            ? "该身份证已被账号 UID {$uid} 认证通过：一个证号只能认证一个账号，换绑走人工处理（见 RUNBOOK 7.13）"
            : '该身份证已被其他账号认证通过：一个证号只能认证一个账号，换绑走人工处理（见 RUNBOOK 7.13）');
        $e->otherUid = $uid;
        return $e;
    }
}
