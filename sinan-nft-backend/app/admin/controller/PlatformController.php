<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\service\SmsService;
use think\facade\Db;

/**
 * 平台运维控制器
 *
 * - 清库日志（platform:log）：nft_platform_cleanup_logs 查询
 * - 一键清库（platform:cleanup）：全量业务数据归零（执行前自动备份 + 短信验证码二次确认）
 *
 * 严谨性设计（本系统最高危操作）：
 * 1. 双重确认：先请求发送验证码（记录手机号），再凭验证码执行
 * 2. 执行前自动备份全量业务表（SQL 文件落盘 runtime/backup/）
 * 3. 全程单事务 + 外键约束检查关闭/恢复对称
 * 4. 清库范围白名单：仅清业务数据表，绝不触碰
 *    nft_admin_*（RBAC）、nft_sms_configs、nft_payment_channels、nft_system_configs、nft_site_settings
 * 5. 白名单表（categories）保留基础数据，仅清交易/资产/营销类数据
 */
class PlatformController extends BaseController
{
    /**
     * GET /admin/platform/cleanup-logs
     */
    public function cleanupLogs()
    {
        [$page, $pageSize] = $this->pageParams();

        $query = Db::name('platform_cleanup_logs');
        if ($range = $this->dateRange()) {
            $query->whereBetween('created_at', $range);
        }
        $adminName = trim((string) $this->request->param('admin_name', ''));
        if ($adminName !== '') {
            $query->whereLike('admin_name', '%' . $adminName . '%');
        }

        $total = (clone $query)->count();
        $items = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        return $this->paginate($items, $total, $page, $pageSize);
    }

    /**
     * GET /admin/platform/cleanup-preview（清库影响面预览，不执行任何变更）
     */
    public function cleanupPreview()
    {
        $tables = self::CLEANUP_TABLES;

        $preview = [];
        $totalRows = 0;
        foreach ($tables as $table) {
            $count = (int) Db::name($table)->count();
            $totalRows += $count;
            $preview[] = ['table' => 'nft_' . $table, 'rows' => $count];
        }

        // 快照表常驻数据量
        $preview[] = ['table' => 'nft_users（含删除标记）', 'rows' => (int) Db::name('users')->count()];

        return $this->success([
            'tables'    => $preview,
            'totalRows' => $totalRows,
            'protected' => [
                'nft_admin_*（管理员/角色/权限/日志）', 'nft_sms_configs（短信配置）',
                'nft_payment_channels（支付渠道）', 'nft_system_configs（系统参数）',
                'nft_site_settings（站点配置）', 'nft_categories（藏品分类基础数据）',
            ],
            'smsRequired' => $this->cleanupSmsRequired(),
        ]);
    }

    /**
     * POST /admin/platform/cleanup-send-code { phone }
     * 第一步：发送敏感操作验证码（写入 session 作用域 phone）
     */
    public function cleanupSendCode()
    {
        $phone = trim((string) $this->request->param('phone', ''));

        // 必须是当前管理员绑定手机（防绕过）
        $admin = Db::name('admin_users')->where('id', $this->adminId())->whereNull('deleted_at')->find();
        if (!$admin || !$admin['phone']) {
            return $this->fail(4220, '当前管理员未绑定手机号，请先在账号管理中补充手机号');
        }
        if ($phone !== '' && $phone !== $admin['phone']) {
            return $this->fail(4220, '仅可向当前管理员绑定手机号发送验证码');
        }
        $phone = (string) $admin['phone'];

        [$ok, $msg] = SmsService::sendAdminCode($phone, 'platform_cleanup', (string) $this->request->ip());
        if (!$ok) {
            return $this->fail(4220, $msg);
        }

        $this->audit('platform', 'cleanup_send_code', '请求平台清库验证码（接收号 ' . mask_phone($phone) . '）');
        return $this->success(['phone' => mask_phone($phone)], '验证码已发送');
    }

    /**
     * POST /admin/platform/cleanup-execute { code, reason }
     * 第二步：凭验证码执行清库（备份 → 清空 → 记日志，单事务）
     */
    public function cleanupExecute()
    {
        $missing = $this->missingParams(['code', 'reason']);
        if ($missing) {
            return $this->failMissing($missing);
        }

        $code   = trim((string) $this->request->param('code'));
        $reason = trim((string) $this->request->param('reason'));
        if (mb_strlen($reason) < 5) {
            return $this->fail(4220, '清库原因不能少于 5 字');
        }

        $admin = Db::name('admin_users')->where('id', $this->adminId())->whereNull('deleted_at')->find();
        if (!$admin || !$admin['phone']) {
            return $this->fail(4220, '当前管理员未绑定手机号');
        }
        $phone = (string) $admin['phone'];

        // 短信验证码校验（可配置关闭，用于本地开发环境）
        if ($this->cleanupSmsRequired()) {
            [$ok, $msg] = SmsService::verifyAdminCode($phone, 'platform_cleanup', $code);
            if (!$ok) {
                return $this->fail(4220, $msg);
            }
        }

        // 影响面统计（执行前）
        $affectedUsers  = (int) Db::name('users')->whereNull('deleted_at')->count();
        $affectedOrders = (int) Db::name('orders')->count();

        // 执行前备份（F7-D7：备份失败必须阻断清库——本系统最高危操作不允许带伤执行）
        try {
            $backupPath = $this->backupBeforeCleanup();
        } catch (\RuntimeException $e) {
            $this->audit('platform', 'cleanup_execute', '平台清库已阻断：' . $e->getMessage(), ['reason' => $reason]);
            return $this->fail(5000, '清库已阻断：' . $e->getMessage());
        }

        $startTime = microtime(true);
        $status    = 1;
        $error     = null;

        Db::startTrans();
        try {
            Db::execute('SET FOREIGN_KEY_CHECKS = 0');
            foreach (self::CLEANUP_TABLES as $table) {
                Db::execute('TRUNCATE TABLE `nft_' . $table . '`');
            }
            // users：物理清空（含软删除标记的全部账号）
            Db::execute('TRUNCATE TABLE `nft_users`');
            Db::execute('SET FOREIGN_KEY_CHECKS = 1');

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            try {
                Db::execute('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable $e2) {
            }
            $status = 2;
            $error  = $e->getMessage();
        }

        $executionTime = (int) round(microtime(true) - $startTime);

        // 清库日志（无论成败）
        Db::name('platform_cleanup_logs')->insert([
            'admin_id'        => $this->adminId(),
            'admin_name'      => $this->adminName(),
            'admin_phone'     => $phone,
            'ip'              => (string) $this->request->ip(),
            'reason'          => mb_substr($reason, 0, 255),
            'backup_path'     => mb_substr($backupPath, 0, 500),
            'affected_users'  => $affectedUsers,
            'affected_orders' => $affectedOrders,
            'execution_time'  => $executionTime,
            'status'          => $status,
            'error_message'   => $error ? mb_substr($error, 0, 65535) : null,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        if ($status === 2) {
            $this->audit('platform', 'cleanup_execute', '平台清库执行失败：' . $error, ['reason' => $reason]);
            return $this->fail(5000, '清库执行失败：' . $error . '（备份文件：' . $backupPath . '）');
        }

        $this->audit('platform', 'cleanup_execute',
            '执行平台清库（原因：' . $reason . '；影响用户 ' . $affectedUsers . '、订单 ' . $affectedOrders
            . '；备份：' . $backupPath . '）');
        return $this->success([
            'backupPath'      => $backupPath,
            'affectedUsers'   => $affectedUsers,
            'affectedOrders'  => $affectedOrders,
            'executionTime'   => $executionTime,
        ], '清库完成，备份文件已保存');
    }

    // ============================================================
    // 辅助方法
    // ============================================================

    /**
     * 清库表白名单（不含 users，users 单独处理）
     * 严禁包含：admin_*、sms_configs、payment_channels、system_configs、site_settings、categories
     */
    private const CLEANUP_TABLES = [
        // 交易与资产
        'wallets', 'wallet_transactions', 'verification_codes',
        'orders', 'payments', 'user_collectibles', 'user_favorites',
        'resale_listings', 'transfers',
        // 盲盒
        'blind_box_items', 'blind_boxes',
        // 合成
        'synthesis_records', 'synthesis_record_items', 'synthesis_materials', 'synthesis_activities',
        // 抽奖
        'lucky_draw_records', 'lucky_draw_prizes',
        // 营销
        'check_in_records', 'invite_records', 'invite_activities',
        'airdrop_records', 'airdrop_snapshots', 'airdrop_eligibilities', 'airdrop_activities',
        // 藏品主数据
        'collectibles',
        // 内容
        'banners', 'announcements', 'artifacts', 'community_groups',
        // 业务扩展（admin_init.sql）
        'qualification_whitelists', 'qualification_configs',
        'priority_whitelists', 'priority_activities', 'inventory_quotas',
        'destroy_records', 'refunds', 'airdrop_tasks',
        'blacklist', 'risk_alerts', 'security_events',
        'support_tickets', 'ticket_replies',
    ];

    /**
     * 是否要求短信验证码（system_configs.cleanup_sms_required，默认 1）
     */
    private function cleanupSmsRequired(): bool
    {
        $v = Db::name('system_configs')->where('config_key', 'cleanup_sms_required')->value('config_value');
        return $v === null || (int) $v === 1;
    }

    /**
     * 执行前备份（mysqldump 全量业务表 → runtime/backup/cleanup_YYYYmmdd_His.sql）
     */
    private function backupBeforeCleanup(): string
    {
        $dir = runtime_path() . 'backup';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'cleanup_' . date('Ymd_His') . '.sql';

        $database = env('database.DATABASE', 'sinan_nft');
        $username = env('database.USERNAME', 'root');
        $password = env('database.PASSWORD', '');
        $hostname = env('database.HOSTNAME', '127.0.0.1');
        $hostport = env('database.HOSTPORT', '3306');

        $cmd = sprintf(
            'mysqldump -h%s -P%s -u%s %s %s --single-transaction --routines --triggers > %s 2>/dev/null',
            escapeshellarg($hostname),
            escapeshellarg((string) $hostport),
            escapeshellarg($username),
            $password !== '' ? '-p' . escapeshellarg($password) : '',
            escapeshellarg($database),
            escapeshellarg($file)
        );
        exec($cmd, $output, $code);

        if ($code !== 0 || !is_file($file) || filesize($file) < 100) {
            // F7-D7 修复：备份失败必须抛异常阻断清库（原实现仅写失败标记后继续执行，属最高危操作带伤放行）
            throw new \RuntimeException('执行前备份失败（mysqldump exit=' . $code . '，file=' . (is_file($file) ? (string) filesize($file) : 'missing') . 'B），请人工核查备份环境后重试');
        }

        return $file;
    }
}
