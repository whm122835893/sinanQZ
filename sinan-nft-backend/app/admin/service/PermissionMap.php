<?php
declare(strict_types=1);

namespace app\admin\service;

/**
 * 权限映射（P0 静态维护）
 *
 * 权限码全集见《管理后台开发文档》第 8 章各模块接口表；
 * 角色矩阵见 7.1。P3 期升级为 nft_admin_role_permissions 库表驱动，
 * 届时本类仅作为库表缺失时的兜底。
 */
class PermissionMap
{
    /** 角色常量（与 nft_admin_roles.id 一致） */
    public const ROLE_SUPER_ADMIN = 1;
    public const ROLE_OPERATOR    = 2;
    public const ROLE_FINANCE     = 3;
    public const ROLE_RISK        = 4;
    public const ROLE_SERVICE     = 5;

    /** 角色名称 */
    private const ROLE_NAMES = [
        self::ROLE_SUPER_ADMIN => '超级管理员',
        self::ROLE_OPERATOR    => '运营',
        self::ROLE_FINANCE     => '财务',
        self::ROLE_RISK        => '风控',
        self::ROLE_SERVICE     => '客服',
    ];

    /**
     * 权限目录（module => 权限码集合）
     * 命名规则：模块:动作，多级动作用 : 连接（如 marketing:priority:whitelist）
     */
    private const CATALOG = [
        'dashboard' => ['dashboard:view'],

        'user' => [
            'user:list', 'user:detail', 'user:freeze', 'user:manage',
            'user:blacklist', 'user:recover',
        ],

        'realname' => ['realname:list', 'realname:view', 'realname:full', 'realname:audit'],

        'collectible' => [
            'collectible:list', 'collectible:create', 'collectible:detail', 'collectible:edit',
            'collectible:release', 'collectible:quota', 'collectible:relist', 'collectible:manage',
            'collectible:destroy', 'collectible:delete', 'collectible:airdrop', 'collectible:market',
            'collectible:qualification', 'collectible:audit',
        ],

        'blindbox' => [
            'blindbox:list', 'blindbox:create', 'blindbox:detail', 'blindbox:edit', 'blindbox:config',
            'blindbox:release', 'blindbox:relist', 'blindbox:manage', 'blindbox:destroy', 'blindbox:delete',
            'blindbox:airdrop', 'blindbox:recover', 'blindbox:audit',
        ],

        'order' => ['order:list', 'order:detail', 'order:manage', 'order:refund', 'order:audit', 'order:export'],

        'refund' => ['refund:list', 'refund:detail', 'refund:approve'],

        'market' => ['market:list', 'market:manage', 'market:monitor', 'market:config'],

        'transfer' => ['transfer:list', 'transfer:detail', 'transfer:manage', 'transfer:stats', 'transfer:monitor'],

        'marketing' => [
            'marketing:priority:list', 'marketing:priority:create', 'marketing:priority:detail',
            'marketing:priority:edit', 'marketing:priority:whitelist', 'marketing:priority:manage',
            'marketing:checkin:config', 'marketing:checkin:list',
            'marketing:invite:config', 'marketing:invite:list',
            'marketing:lucky:list', 'marketing:lucky:create', 'marketing:lucky:detail',
            'marketing:lucky:edit', 'marketing:lucky:prize',
            'marketing:synthesis:list', 'marketing:synthesis:create', 'marketing:synthesis:detail',
            'marketing:synthesis:edit',
            'marketing:airdrop', 'marketing:register:config', 'marketing:blindbox:stats',
        ],

        'wallet' => ['wallet:recharge', 'wallet:transaction', 'wallet:fee', 'wallet:audit', 'wallet:monitor', 'wallet:export'],

        'cms' => [
            'cms:banner:list', 'cms:banner:create', 'cms:banner:detail', 'cms:banner:edit', 'cms:banner:delete',
            'cms:announcement:list', 'cms:announcement:create', 'cms:announcement:detail', 'cms:announcement:edit', 'cms:announcement:delete',
            'cms:agreement:list', 'cms:agreement:edit',
            'cms:artifact:list', 'cms:artifact:create', 'cms:artifact:detail', 'cms:artifact:edit', 'cms:artifact:delete',
            'cms:decoration:view', 'cms:decoration:edit',
        ],

        'system' => ['system:config', 'system:security'],

        'permission' => [
            'permission:admin:list', 'permission:admin:create', 'permission:admin:detail', 'permission:admin:edit',
            'permission:admin:delete', 'permission:admin:manage',
            'permission:role:list', 'permission:role:create', 'permission:role:edit', 'permission:role:delete',
            'permission:permission:list',
            'permission:log:list', 'permission:log:detail', 'permission:log:export', 'permission:log:login',
        ],

        'security' => [
            'security:blacklist:list', 'security:blacklist:create', 'security:blacklist:delete',
            'security:alert:list', 'security:alert:detail', 'security:alert:handle',
            'security:event:list', 'security:event:detail', 'security:event:handle',
            'security:txlock:list', 'security:txlock:manage',
            'security:approval:list', 'security:approval:detail', 'security:approval:handle',
        ],

        'ticket' => ['ticket:list', 'ticket:detail', 'ticket:assign', 'ticket:reply', 'ticket:manage', 'ticket:feedback', 'ticket:compensate'],

        'report' => ['report:sales', 'report:user', 'report:collectible', 'report:blindbox', 'report:finance', 'report:export'],

        'platform' => ['platform:cleanup', 'platform:log', 'platform:backup'],
    ];

    /**
     * 角色 => 权限码集合（超管为全量）
     * 依据文档 7.1 角色矩阵：
     * - 运营：藏品/盲盒/活动配置/CMS/基础用户管理（冻结解冻）/订单查看
     * - 财务：订单管理/钱包流水/充值记录/财务报表/手续费统计/收支导出
     * - 风控：黑名单/异常交易审批/风控告警/实名完整查看
     * - 客服：工单处理/基础用户查询（脱敏）/用户资产查看
     */
    private const ROLE_PERMISSIONS = [
        self::ROLE_OPERATOR => [
            'dashboard:view',
            'user:list', 'user:detail', 'user:freeze', 'user:manage', 'user:recover',
            'collectible:list', 'collectible:create', 'collectible:detail', 'collectible:edit',
            'collectible:release', 'collectible:quota', 'collectible:relist', 'collectible:manage',
            'collectible:destroy', 'collectible:delete', 'collectible:airdrop', 'collectible:market',
            'collectible:qualification', 'collectible:audit',
            'blindbox:list', 'blindbox:create', 'blindbox:detail', 'blindbox:edit', 'blindbox:config',
            'blindbox:release', 'blindbox:relist', 'blindbox:manage', 'blindbox:destroy', 'blindbox:delete',
            'blindbox:airdrop', 'blindbox:recover', 'blindbox:audit',
            'marketing:priority:list', 'marketing:priority:create', 'marketing:priority:detail',
            'marketing:priority:edit', 'marketing:priority:whitelist', 'marketing:priority:manage',
            'marketing:checkin:config', 'marketing:checkin:list',
            'marketing:invite:config', 'marketing:invite:list',
            'marketing:lucky:list', 'marketing:lucky:create', 'marketing:lucky:detail',
            'marketing:lucky:edit', 'marketing:lucky:prize',
            'marketing:synthesis:list', 'marketing:synthesis:create', 'marketing:synthesis:detail',
            'marketing:synthesis:edit',
            'marketing:airdrop', 'marketing:register:config', 'marketing:blindbox:stats',
            'cms:banner:list', 'cms:banner:create', 'cms:banner:detail', 'cms:banner:edit', 'cms:banner:delete',
            'cms:announcement:list', 'cms:announcement:create', 'cms:announcement:detail', 'cms:announcement:edit', 'cms:announcement:delete',
            'cms:agreement:list', 'cms:agreement:edit',
            'cms:artifact:list', 'cms:artifact:create', 'cms:artifact:detail', 'cms:artifact:edit', 'cms:artifact:delete',
            'cms:decoration:view', 'cms:decoration:edit',
            'order:list', 'order:detail',
        ],
        self::ROLE_FINANCE => [
            'dashboard:view',
            'order:list', 'order:detail', 'order:manage', 'order:refund', 'order:audit', 'order:export',
            'refund:list', 'refund:detail', 'refund:approve',
            'wallet:recharge', 'wallet:transaction', 'wallet:fee', 'wallet:audit', 'wallet:monitor', 'wallet:export',
            'market:config',
            'report:sales', 'report:finance', 'report:export',
        ],
        self::ROLE_RISK => [
            'dashboard:view',
            'security:blacklist:list', 'security:blacklist:create', 'security:blacklist:delete',
            'security:alert:list', 'security:alert:detail', 'security:alert:handle',
            'security:event:list', 'security:event:detail', 'security:event:handle',
            'security:txlock:list', 'security:txlock:manage',
            'security:approval:list', 'security:approval:detail', 'security:approval:handle',
            'realname:list', 'realname:view', 'realname:full', 'realname:audit',
            'user:list', 'user:detail', 'user:blacklist',
            'order:audit',
            'transfer:list', 'transfer:detail', 'transfer:monitor',
            'market:list', 'market:monitor',
            'wallet:monitor',
        ],
        self::ROLE_SERVICE => [
            'dashboard:view',
            'ticket:list', 'ticket:detail', 'ticket:assign', 'ticket:reply', 'ticket:manage', 'ticket:feedback',
            'user:list', 'user:detail',
        ],
    ];

    /**
     * 全量权限码列表
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::CATALOG));
    }

    /**
     * 角色的权限码列表
     */
    public static function permissionsForRole(int $role): array
    {
        if ($role === self::ROLE_SUPER_ADMIN) {
            return self::all();
        }
        return self::ROLE_PERMISSIONS[$role] ?? [];
    }

    /**
     * 角色是否拥有某权限
     */
    public static function has(int $role, string $perm): bool
    {
        return in_array($perm, self::permissionsForRole($role), true);
    }

    /**
     * 角色名称
     */
    public static function roleName(int $role): string
    {
        return self::ROLE_NAMES[$role] ?? '未知角色';
    }
}
