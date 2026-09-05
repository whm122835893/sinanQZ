<?php
// ============================================================================
// 管理后台路由（多应用：URL 前缀 /admin/* 自动路由到本应用）
// BaseURL：/admin/api/v1
// 响应格式沿用 C 端 {code, message, data}；认证走管理员 JWT（[JWT_ADMIN] 独立密钥）
// P1 范围：仪表盘 6 / 用户 15 / 实名 4 / 藏品 18 / 盲盒 17 / 订单 8 / 退款 4
// ============================================================================
use app\admin\middleware\AdminAuth;
use app\admin\middleware\AdminPermission;
use think\facade\Route;

Route::group('api/v1', function () {

    // ---------- 公开接口 ----------
    Route::post('auth/login',  'Auth/login');
    Route::post('auth/refresh', 'Auth/refresh');

    // ---------- 需要管理员认证 ----------
    Route::group('', function () {

        // 认证相关（任意登录角色）
        Route::post('auth/logout',  'Auth/logout');
        Route::get('auth/me',       'Auth/me');
        Route::put('auth/password', 'Auth/password');

        // ==================== 数据仪表盘（6）====================
        Route::get('dashboard/metrics',         'Dashboard/metrics')
            ->middleware(AdminPermission::class, 'dashboard:view');
        Route::get('dashboard/finance',         'Dashboard/finance')
            ->middleware(AdminPermission::class, 'dashboard:view');
        Route::get('dashboard/alerts',          'Dashboard/alerts')
            ->middleware(AdminPermission::class, 'dashboard:view');
        Route::get('dashboard/activities',      'Dashboard/activities')
            ->middleware(AdminPermission::class, 'dashboard:view');
        Route::get('dashboard/trends',          'Dashboard/trends')
            ->middleware(AdminPermission::class, 'dashboard:view');
        Route::get('dashboard/priority-stats', 'Dashboard/priorityStats')
            ->middleware(AdminPermission::class, 'dashboard:view');

        // ==================== 用户管理（15）====================
        Route::get('users',                        'Users/index')
            ->middleware(AdminPermission::class, 'user:list');
        Route::get('users/:id',                    'Users/detail')
            ->middleware(AdminPermission::class, 'user:detail');
        Route::put('users/:id/freeze',             'Users/freeze')
            ->middleware(AdminPermission::class, 'user:freeze');
        Route::put('users/:id/unfreeze',           'Users/unfreeze')
            ->middleware(AdminPermission::class, 'user:freeze');
        Route::put('users/:id/reset-tx-password',  'Users/resetTxPassword')
            ->middleware(AdminPermission::class, 'user:manage');
        Route::put('users/:id/force-logout',       'Users/forceLogout')
            ->middleware(AdminPermission::class, 'user:manage');
        Route::post('users/:id/blacklist',         'Users/blacklist')
            ->middleware(AdminPermission::class, 'user:blacklist');
        Route::delete('users/:id/blacklist',       'Users/blacklistRemove')
            ->middleware(AdminPermission::class, 'user:blacklist');
        Route::get('users/:id/wallet',              'Users/wallet')
            ->middleware(AdminPermission::class, 'user:detail');
        Route::get('users/:id/collectibles',       'Users/userCollectibles')
            ->middleware(AdminPermission::class, 'user:detail');
        Route::get('users/:id/blindboxes',         'Users/userBlindboxes')
            ->middleware(AdminPermission::class, 'user:detail');
        Route::get('users/:id/priority-qualifications', 'Users/priorityQualifications')
            ->middleware(AdminPermission::class, 'user:detail');
        Route::get('users/:id/invites',            'Users/invites')
            ->middleware(AdminPermission::class, 'user:detail');
        Route::post('users/:id/recover-collectible', 'Users/recoverCollectible')
            ->middleware(AdminPermission::class, 'user:recover');
        Route::post('users/:id/recover-blindbox',  'Users/recoverBlindbox')
            ->middleware(AdminPermission::class, 'user:recover');

        // ==================== 实名认证管理（4，只读）====================
        Route::get('realnames',              'Realnames/index')
            ->middleware(AdminPermission::class, 'realname:list');
        Route::get('realnames/:id',          'Realnames/detail')
            ->middleware(AdminPermission::class, 'realname:view');
        Route::post('realnames/:id/full',    'Realnames/full')
            ->middleware(AdminPermission::class, 'realname:full');
        Route::get('realnames/:id/audit-logs', 'Realnames/auditLogs')
            ->middleware(AdminPermission::class, 'realname:audit');

        // ==================== 藏品管理（18）====================
        Route::get('collectibles',                   'Collectibles/index')
            ->middleware(AdminPermission::class, 'collectible:list');
        Route::post('collectibles',                  'Collectibles/create')
            ->middleware(AdminPermission::class, 'collectible:create');
        Route::get('collectibles/:id',               'Collectibles/detail')
            ->middleware(AdminPermission::class, 'collectible:detail');
        Route::put('collectibles/:id',               'Collectibles/update')
            ->middleware(AdminPermission::class, 'collectible:edit');
        Route::post('collectibles/:id/release',     'Collectibles/release')
            ->middleware(AdminPermission::class, 'collectible:release');
        Route::post('collectibles/:id/quotas',      'Collectibles/quotas')
            ->middleware(AdminPermission::class, 'collectible:quota');
        Route::put('collectibles/:id/quotas/:quota_id', 'Collectibles/updateQuota')
            ->middleware(AdminPermission::class, 'collectible:quota');
        Route::post('collectibles/:id/relist',      'Collectibles/relist')
            ->middleware(AdminPermission::class, 'collectible:relist');
        Route::post('collectibles/:id/force-soldout', 'Collectibles/forceSoldout')
            ->middleware(AdminPermission::class, 'collectible:manage');
        Route::post('collectibles/:id/destroy',    'Collectibles/destroy')
            ->middleware(AdminPermission::class, 'collectible:destroy');
        Route::delete('collectibles/:id',           'Collectibles/delete')
            ->middleware(AdminPermission::class, 'collectible:delete');
        Route::post('collectibles/:id/airdrop',     'Collectibles/airdrop')
            ->middleware(AdminPermission::class, 'collectible:airdrop');
        Route::post('collectibles/:id/resale-toggle', 'Collectibles/resaleToggle')
            ->middleware(AdminPermission::class, 'collectible:market');
        Route::post('collectibles/:id/price-control', 'Collectibles/priceControl')
            ->middleware(AdminPermission::class, 'collectible:market');
        Route::post('collectibles/:id/qualification', 'Collectibles/qualification')
            ->middleware(AdminPermission::class, 'collectible:qualification');
        Route::get('collectibles/:id/audit',        'Collectibles/audit')
            ->middleware(AdminPermission::class, 'collectible:audit');
        Route::get('collectibles/:id/airdrop-records', 'Collectibles/airdropRecords')
            ->middleware(AdminPermission::class, 'collectible:detail');
        Route::get('collectibles/:id/destroy-records', 'Collectibles/destroyRecords')
            ->middleware(AdminPermission::class, 'collectible:detail');

        // ==================== 盲盒管理（17）====================
        Route::get('blindboxes',                    'BlindBoxes/index')
            ->middleware(AdminPermission::class, 'blindbox:list');
        Route::post('blindboxes',                   'BlindBoxes/create')
            ->middleware(AdminPermission::class, 'blindbox:create');
        Route::get('blindboxes/:id',                'BlindBoxes/detail')
            ->middleware(AdminPermission::class, 'blindbox:detail');
        Route::put('blindboxes/:id',                'BlindBoxes/update')
            ->middleware(AdminPermission::class, 'blindbox:edit');
        Route::post('blindboxes/:id/items',         'BlindBoxes/items')
            ->middleware(AdminPermission::class, 'blindbox:config');
        Route::put('blindboxes/:id/items/:item_id', 'BlindBoxes/updateItem')
            ->middleware(AdminPermission::class, 'blindbox:config');
        Route::delete('blindboxes/:id/items/:item_id', 'BlindBoxes/deleteItem')
            ->middleware(AdminPermission::class, 'blindbox:config');
        Route::post('blindboxes/:id/release',       'BlindBoxes/release')
            ->middleware(AdminPermission::class, 'blindbox:release');
        Route::post('blindboxes/:id/relist',        'BlindBoxes/relist')
            ->middleware(AdminPermission::class, 'blindbox:relist');
        Route::post('blindboxes/:id/force-soldout', 'BlindBoxes/forceSoldout')
            ->middleware(AdminPermission::class, 'blindbox:manage');
        Route::post('blindboxes/:id/destroy',      'BlindBoxes/destroy')
            ->middleware(AdminPermission::class, 'blindbox:destroy');
        Route::delete('blindboxes/:id',             'BlindBoxes/delete')
            ->middleware(AdminPermission::class, 'blindbox:delete');
        Route::post('blindboxes/:id/airdrop',       'BlindBoxes/airdrop')
            ->middleware(AdminPermission::class, 'blindbox:airdrop');
        Route::post('blindboxes/:id/recover',      'BlindBoxes/recover')
            ->middleware(AdminPermission::class, 'blindbox:recover');
        Route::get('blindboxes/:id/open-records',   'BlindBoxes/openRecords')
            ->middleware(AdminPermission::class, 'blindbox:detail');
        Route::get('blindboxes/:id/audit',          'BlindBoxes/audit')
            ->middleware(AdminPermission::class, 'blindbox:audit');
        Route::get('blindboxes/:id/destroy-records', 'BlindBoxes/destroyRecords')
            ->middleware(AdminPermission::class, 'blindbox:detail');

        // ==================== 订单管理（8）====================
        // 固定路径优先于 :id（ThinkPHP 按注册顺序匹配）
        Route::get('orders/abnormal',   'Orders/abnormal')
            ->middleware(AdminPermission::class, 'order:audit');
        Route::get('orders/export',     'Orders/export')
            ->middleware(AdminPermission::class, 'order:export');
        Route::get('orders',            'Orders/index')
            ->middleware(AdminPermission::class, 'order:list');
        Route::get('orders/:id',        'Orders/detail')
            ->middleware(AdminPermission::class, 'order:detail');
        Route::post('orders/:id/cancel',     'Orders/cancel')
            ->middleware(AdminPermission::class, 'order:manage');
        Route::post('orders/:id/mark-paid',  'Orders/markPaid')
            ->middleware(AdminPermission::class, 'order:manage');
        Route::post('orders/:id/refund',     'Orders/refund')
            ->middleware(AdminPermission::class, 'order:refund');
        Route::post('orders/:id/repair',     'Orders/repair')
            ->middleware(AdminPermission::class, 'order:manage');

        // ==================== 退款审批（4）====================
        Route::get('refunds',               'Refunds/index')
            ->middleware(AdminPermission::class, 'refund:list');
        Route::get('refunds/:id',           'Refunds/detail')
            ->middleware(AdminPermission::class, 'refund:detail');
        Route::post('refunds/:id/approve',  'Refunds/approve')
            ->middleware(AdminPermission::class, 'refund:approve');
        Route::post('refunds/:id/reject',   'Refunds/reject')
            ->middleware(AdminPermission::class, 'refund:approve');

        // ==================== 营销活动 · 优先购（10）====================
        // 固定路径优先于 :id（ThinkPHP 按注册顺序匹配）
        Route::get('marketing/priority',                      'MarketingPriority/index')
            ->middleware(AdminPermission::class, 'marketing:priority:list');
        Route::post('marketing/priority',                     'MarketingPriority/create')
            ->middleware(AdminPermission::class, 'marketing:priority:create');
        Route::post('marketing/priority/cleanup',             'MarketingPriority/cleanup')
            ->middleware(AdminPermission::class, 'marketing:priority:manage');
        Route::get('marketing/priority/:id',                  'MarketingPriority/detail')
            ->middleware(AdminPermission::class, 'marketing:priority:detail');
        Route::put('marketing/priority/:id',                  'MarketingPriority/update')
            ->middleware(AdminPermission::class, 'marketing:priority:edit');
        Route::get('marketing/priority/:id/whitelist',        'MarketingPriority/whitelist')
            ->middleware(AdminPermission::class, 'marketing:priority:detail');
        Route::post('marketing/priority/:id/whitelist',       'MarketingPriority/addWhitelist')
            ->middleware(AdminPermission::class, 'marketing:priority:whitelist');
        Route::post('marketing/priority/:id/whitelist/batch', 'MarketingPriority/batchWhitelist')
            ->middleware(AdminPermission::class, 'marketing:priority:whitelist');
        Route::put('marketing/priority/:id/whitelist/:wid',   'MarketingPriority/updateWhitelist')
            ->middleware(AdminPermission::class, 'marketing:priority:whitelist');
        Route::delete('marketing/priority/:id/whitelist/:wid', 'MarketingPriority/deleteWhitelist')
            ->middleware(AdminPermission::class, 'marketing:priority:whitelist');

        // ==================== 管理员账号（P0 保留）====================
        Route::get('permission/admins',      'Admins/index')
            ->middleware(AdminPermission::class, 'permission:admin:list');
        Route::get('permission/admins/:id', 'Admins/detail')
            ->middleware(AdminPermission::class, 'permission:admin:detail');
        Route::post('permission/admins',     'Admins/create')
            ->middleware(AdminPermission::class, 'permission:admin:create');

        // ==================== 日志（P0 保留）====================
        Route::get('permission/operation-logs', 'Logs/operation')
            ->middleware(AdminPermission::class, 'permission:log:list');
        Route::get('permission/login-logs',     'Logs/login')
            ->middleware(AdminPermission::class, 'permission:log:login');

    })->middleware(AdminAuth::class);

})->middleware(\app\middleware\Cors::class);
