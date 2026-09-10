<?php
// ============================================================================
// 司南数字藏品平台 · 管理后台路由（admin 应用）
// 多应用模式：URL 前缀 /admin/**（think-multi-app 自动剥离第一段）
// 中间件链：AdminAuth（JWT认证+实时状态）→ AdminPermission（权限码精确校验）
// 响应约定：code=200 成功（Admin 端独立于 C 端 code=0）
// ============================================================================
use think\facade\Route;
use app\admin\middleware\AdminAuth;
use app\admin\middleware\AdminPermission;

// ---------------------------------------------------------------------------
// 公开路由（无需认证）
// ---------------------------------------------------------------------------
Route::group('auth', function () {
    Route::post('login',  'AuthController/login');
    Route::post('refresh', 'AuthController/refresh');
});

// 站点品牌（登录页展示站点名/头像，未登录可访问）
Route::get('site-brand', 'CmsController/siteBrand');

// ---------------------------------------------------------------------------
// 认证路由（仅需登录）
// ---------------------------------------------------------------------------
Route::group('auth', function () {
    Route::get('profile', 'AuthController/profile');
    Route::post('logout', 'AuthController/logout');
    Route::post('change-password', 'AuthController/changePassword');
    Route::post('verify-password', 'AuthController/verifyPassword');
})->middleware(AdminAuth::class);

// ---------------------------------------------------------------------------
// 通用图片上传（仅需登录；具体业务写操作另有独立权限校验）
// ---------------------------------------------------------------------------
Route::post('upload/image', 'UploadController/image')->middleware(AdminAuth::class);

// ---------------------------------------------------------------------------
// 仪表盘（dashboard:view）
// ---------------------------------------------------------------------------
Route::group('dashboard', function () {
    Route::get('overview', 'DashboardController/overview');
    Route::get('trend', 'DashboardController/trend');
    Route::get('rank', 'DashboardController/rank');
    Route::get('latest', 'DashboardController/latest');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'dashboard:view');

// ---------------------------------------------------------------------------
// 用户管理（user:*）
// ---------------------------------------------------------------------------
Route::group('users', function () {
    Route::get('', 'UserController/list');
    // 静态路由需注册在 :id 通配路由之前（否则 /assets/1 会被 :id 吸收）
    Route::get('assets/:id', 'UserController/assets')->middleware(AdminPermission::class, 'user:detail');
    Route::get(':id', 'UserController/detail')->middleware(AdminPermission::class, 'user:detail');
    Route::post(':id/freeze', 'UserController/freeze')->middleware(AdminPermission::class, 'user:freeze');
    Route::post(':id/reset-transaction-password', 'UserController/resetTransactionPassword')->middleware(AdminPermission::class, 'user:manage');
    Route::post(':id/force-logout', 'UserController/forceLogout')->middleware(AdminPermission::class, 'user:manage');
    Route::post(':id/blacklist', 'UserController/blacklist')->middleware(AdminPermission::class, 'user:blacklist');
    Route::post('recover', 'UserController/recover')->middleware(AdminPermission::class, 'user:recover');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'user:list');

// ---------------------------------------------------------------------------
// 实名认证（realname:*）
// ---------------------------------------------------------------------------
Route::group('realname', function () {
    Route::get('users', 'RealnameController/list');
    Route::get('users/:user_id', 'RealnameController/detail')->middleware(AdminPermission::class, 'realname:full');
    Route::post('audit', 'RealnameController/doAudit')->middleware(AdminPermission::class, 'realname:audit');
    Route::get('stats', 'RealnameController/stats');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'realname:list');

// ---------------------------------------------------------------------------
// 藏品管理（collectible:*）
// ---------------------------------------------------------------------------
Route::group('collectibles', function () {
    Route::get('', 'CollectibleController/list');
    // 静态路由需注册在 :id 通配路由之前（否则 /audit 会被 :id 吸收）
    Route::get('audit', 'CollectibleController/auditList')->middleware(AdminPermission::class, 'collectible:audit');
    Route::get('qualifications', 'CollectibleController/qualificationList');
    Route::get('qualification-whitelist/:configId', 'CollectibleController/qualificationWhitelist');
    Route::post('qualification-whitelist', 'CollectibleController/qualificationWhitelistAdd')->middleware(AdminPermission::class, 'collectible:qualification');
    Route::delete('qualification-whitelist/:id', 'CollectibleController/qualificationWhitelistRemove')->middleware(AdminPermission::class, 'collectible:qualification');
    Route::post('quota/:id/toggle', 'CollectibleController/quotaToggle')->middleware(AdminPermission::class, 'collectible:quota');
    Route::get(':id', 'CollectibleController/detail')->middleware(AdminPermission::class, 'collectible:detail');
    Route::post('', 'CollectibleController/create')->middleware(AdminPermission::class, 'collectible:create');
    Route::put(':id', 'CollectibleController/update')->middleware(AdminPermission::class, 'collectible:edit');
    Route::post(':id/release', 'CollectibleController/release')->middleware(AdminPermission::class, 'collectible:release');
    Route::post(':id/quota', 'CollectibleController/quota')->middleware(AdminPermission::class, 'collectible:quota');
    Route::post(':id/manage', 'CollectibleController/manage')->middleware(AdminPermission::class, 'collectible:manage');
    Route::post(':id/destroy', 'CollectibleController/destroy')->middleware(AdminPermission::class, 'collectible:destroy');
    Route::delete(':id', 'CollectibleController/delete')->middleware(AdminPermission::class, 'collectible:delete');
    Route::post('airdrop', 'CollectibleController/airdrop')->middleware(AdminPermission::class, 'collectible:airdrop');
    Route::post('swap', 'CollectibleController/swap')->middleware(AdminPermission::class, 'collectible:swap');
    Route::put(':id/market-config', 'CollectibleController/marketConfig')->middleware(AdminPermission::class, 'collectible:market');
    Route::post(':id/qualification', 'CollectibleController/qualification')->middleware(AdminPermission::class, 'collectible:qualification');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'collectible:list');

// ---------------------------------------------------------------------------
// 盲盒管理（blindbox:*）
// ---------------------------------------------------------------------------
Route::group('blind-boxes', function () {
    Route::get('', 'BlindBoxController/list');
    // 静态路由需注册在 :id 通配路由之前
    Route::get('audit', 'BlindBoxController/auditList')->middleware(AdminPermission::class, 'blindbox:audit');
    Route::get(':id', 'BlindBoxController/detail')->middleware(AdminPermission::class, 'blindbox:detail');
    Route::post('', 'BlindBoxController/create')->middleware(AdminPermission::class, 'blindbox:create');
    Route::put(':id', 'BlindBoxController/update')->middleware(AdminPermission::class, 'blindbox:edit');
    Route::put(':id/config', 'BlindBoxController/config')->middleware(AdminPermission::class, 'blindbox:config');
    Route::post(':id/release', 'BlindBoxController/release')->middleware(AdminPermission::class, 'blindbox:release');
    Route::post(':id/manage', 'BlindBoxController/manage')->middleware(AdminPermission::class, 'blindbox:manage');
    Route::post(':id/destroy', 'BlindBoxController/destroy')->middleware(AdminPermission::class, 'blindbox:destroy');
    Route::post('airdrop', 'BlindBoxController/airdrop')->middleware(AdminPermission::class, 'blindbox:airdrop');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'blindbox:list');

// ---------------------------------------------------------------------------
// 订单管理（order:*）
// ---------------------------------------------------------------------------
Route::group('orders', function () {
    Route::get('', 'OrderController/list');
    // 静态路由需注册在 :id 通配路由之前
    Route::get('audit', 'OrderController/auditList')->middleware(AdminPermission::class, 'order:audit');
    Route::get(':id', 'OrderController/detail')->middleware(AdminPermission::class, 'order:detail');
    Route::post(':id/cancel', 'OrderController/cancel')->middleware(AdminPermission::class, 'order:manage');
    Route::post(':id/mark-paid', 'OrderController/markPaid')->middleware(AdminPermission::class, 'order:manage');
    Route::post(':id/refund', 'OrderController/refund')->middleware(AdminPermission::class, 'order:refund');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'order:list');

// ---------------------------------------------------------------------------
// 退款管理（refund:*）
// ---------------------------------------------------------------------------
Route::group('refunds', function () {
    Route::get('', 'RefundController/list');
    Route::get(':id', 'RefundController/detail')->middleware(AdminPermission::class, 'order:detail');
    Route::post(':id/approve', 'RefundController/approve')->middleware(AdminPermission::class, 'refund:approve');
    Route::post(':id/execute', 'RefundController/execute')->middleware(AdminPermission::class, 'order:refund');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'refund:list');

// ---------------------------------------------------------------------------
// 市场寄售（market:*）
// ---------------------------------------------------------------------------
Route::group('market', function () {
    Route::get('listings', 'MarketController/list');
    Route::post('listings/:id/manage', 'MarketController/manage')->middleware(AdminPermission::class, 'market:manage');
    Route::get('config', 'MarketController/config');
    Route::post('config', 'MarketController/saveConfig')->middleware(AdminPermission::class, 'market:config');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'market:list');

// ---------------------------------------------------------------------------
// 转赠管理（transfer:*）
// ---------------------------------------------------------------------------
Route::group('transfers', function () {
    Route::get('', 'TransferController/list');
    Route::post(':id/approve', 'TransferController/approve')->middleware(AdminPermission::class, 'transfer:manage');
    Route::post(':id/reject', 'TransferController/reject')->middleware(AdminPermission::class, 'transfer:manage');
    Route::post(':id/revoke', 'TransferController/revoke')->middleware(AdminPermission::class, 'transfer:manage');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'transfer:list');

// ---------------------------------------------------------------------------
// 营销活动（marketing:*）
// ---------------------------------------------------------------------------
Route::group('marketing', function () {
    // 优先购
    Route::get('priority', 'MarketingController/priorityList');
    Route::post('priority', 'MarketingController/prioritySave')->middleware(AdminPermission::class, 'marketing:priority:manage');
    // 优先购白名单（明细/添加/移除/清理过期，均写审计日志）
    Route::get('priority-whitelist/:activityId', 'MarketingController/priorityWhitelist');
    Route::post('priority-whitelist', 'MarketingController/priorityWhitelistAdd')->middleware(AdminPermission::class, 'marketing:priority:manage');
    Route::delete('priority-whitelist/:id', 'MarketingController/priorityWhitelistRemove')->middleware(AdminPermission::class, 'marketing:priority:manage');
    Route::post('priority-whitelist/clean-expired', 'MarketingController/priorityWhitelistCleanExpired')->middleware(AdminPermission::class, 'marketing:priority:manage');
    // 签到
    Route::get('checkin', 'MarketingController/checkinConfig');
    Route::post('checkin', 'MarketingController/checkinSave')->middleware(AdminPermission::class, 'marketing:checkin:config');
    // 邀请
    Route::get('invite', 'MarketingController/inviteList');
    Route::post('invite', 'MarketingController/inviteSave')->middleware(AdminPermission::class, 'marketing:invite:config');
    // 抽奖
    Route::get('lucky', 'MarketingController/luckyList');
    Route::post('lucky', 'MarketingController/luckySave')->middleware(AdminPermission::class, 'marketing:lucky:manage');
    // 抽奖活动（新建/编辑/开关）
    Route::post('lucky-activity', 'MarketingController/luckyActivitySave')->middleware(AdminPermission::class, 'marketing:lucky:manage');
    // 合成
    Route::get('synthesis', 'MarketingController/synthesisList');
    Route::post('synthesis', 'MarketingController/synthesisSave')->middleware(AdminPermission::class, 'marketing:synthesis:manage');
    // 合成记录（多合/错合定位与对账）
    Route::get('synthesis-records', 'MarketingController/synthesisRecords');
    // 活动空投
    Route::get('airdrop', 'MarketingController/airdropList');
    Route::post('airdrop', 'MarketingController/airdropSave')->middleware(AdminPermission::class, 'marketing:airdrop');
    Route::post('airdrop/issue', 'MarketingController/airdropIssue')->middleware(AdminPermission::class, 'marketing:airdrop');
    // 注册活动（实名前N名档位奖励）
    Route::get('register', 'MarketingController/registerList');
    Route::post('register-save', 'MarketingController/registerSave')->middleware(AdminPermission::class, 'marketing:register:config');
    Route::post('register-delete', 'MarketingController/registerDelete')->middleware(AdminPermission::class, 'marketing:register:config');
    // 奖励名单（导出/统一发放）
    Route::get('reward-records', 'MarketingController/rewardRecords');
    Route::get('reward-records/export', 'MarketingController/rewardRecordsExport');
    Route::post('reward-records/issue', 'MarketingController/rewardRecordsIssue')->middleware(AdminPermission::class, 'marketing:airdrop');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'marketing:priority:list');

// ---------------------------------------------------------------------------
// 钱包财务（wallet:*）
// ---------------------------------------------------------------------------
Route::group('wallet', function () {
    Route::get('stats', 'WalletController/stats');
    Route::get('transactions', 'WalletController/transactions');
    Route::get('recharge', 'WalletController/recharge')->middleware(AdminPermission::class, 'wallet:recharge');
    Route::get('fee', 'WalletController/fee')->middleware(AdminPermission::class, 'wallet:fee');
    Route::get('audit', 'WalletController/auditList')->middleware(AdminPermission::class, 'wallet:audit');
    Route::get('abnormal', 'WalletController/abnormal')->middleware(AdminPermission::class, 'wallet:monitor');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'wallet:transaction');

// ---------------------------------------------------------------------------
// 内容管理（cms:*）
// ---------------------------------------------------------------------------
Route::group('cms', function () {
    // 轮播图
    Route::get('banners', 'CmsController/bannerList');
    Route::post('banners', 'CmsController/bannerCreate');
    Route::put('banners/:id', 'CmsController/bannerUpdate');
    Route::delete('banners/:id', 'CmsController/bannerDelete');
    Route::post('banners/:id/toggle', 'CmsController/bannerToggle');
    // 公告
    Route::get('announcements', 'CmsController/announcementList');
    Route::post('announcements', 'CmsController/announcementCreate');
    Route::put('announcements/:id', 'CmsController/announcementUpdate');
    Route::delete('announcements/:id', 'CmsController/announcementDelete');
    Route::post('announcements/:id/toggle-top', 'CmsController/announcementToggleTop');
    // 协议
    Route::get('agreements', 'CmsController/agreementList');
    Route::put('agreements/:key', 'CmsController/agreementSave')->middleware(AdminPermission::class, 'cms:agreement');
    // 文物展馆
    Route::get('artifacts', 'CmsController/artifactList');
    Route::post('artifacts', 'CmsController/artifactCreate');
    Route::put('artifacts/:id', 'CmsController/artifactUpdate');
    Route::delete('artifacts/:id', 'CmsController/artifactDelete');
    // 官方社群（C 端社区页入口）
    Route::get('community', 'CmsController/communityList');
    Route::post('community', 'CmsController/communityCreate')->middleware(AdminPermission::class, 'cms:community');
    Route::put('community/:id', 'CmsController/communityUpdate')->middleware(AdminPermission::class, 'cms:community');
    Route::delete('community/:id', 'CmsController/communityDelete')->middleware(AdminPermission::class, 'cms:community');
    // 站点装修
    Route::get('decoration', 'CmsController/decorationList');
    Route::post('decoration', 'CmsController/decorationSave');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'cms:banner');

// ---------------------------------------------------------------------------
// 系统配置（system:*）
// ---------------------------------------------------------------------------
Route::group('system', function () {
    // 全局参数
    Route::get('configs', 'SystemController/configList');
    Route::put('configs/:key', 'SystemController/configSave');
    // 支付渠道（第三方钱包配置）
    Route::get('payment-channels', 'SystemController/paymentList');
    Route::put('payment-channels/:id', 'SystemController/paymentSave')->middleware(AdminPermission::class, 'system:payment');
    // 短信配置
    Route::get('sms-config', 'SystemController/smsConfig');
    Route::put('sms-config', 'SystemController/smsSave')->middleware(AdminPermission::class, 'system:sms');
    Route::post('sms-config/test', 'SystemController/smsTest')->middleware(AdminPermission::class, 'system:sms');
    // 安全策略
    Route::get('security-config', 'SystemController/securityConfig');
    Route::put('security-config/:key', 'SystemController/securitySave')->middleware(AdminPermission::class, 'system:security');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'system:config');

// ---------------------------------------------------------------------------
// 权限管理（permission:*）
// ---------------------------------------------------------------------------
Route::group('permission', function () {
    // 管理员
    Route::get('admins', 'PermissionController/adminList');
    Route::post('admins', 'PermissionController/adminCreate');
    Route::put('admins/:id', 'PermissionController/adminUpdate');
    Route::post('admins/:id/reset-password', 'PermissionController/adminResetPassword');
    Route::post('admins/:id/unlock', 'PermissionController/adminUnlock');
    Route::delete('admins/:id', 'PermissionController/adminDelete');
    // 角色
    Route::get('roles', 'PermissionController/roleList');
    Route::get('roles/:id', 'PermissionController/roleDetail');
    Route::get('tree', 'PermissionController/permissionTree');
    Route::post('roles', 'PermissionController/roleCreate');
    Route::put('roles/:id', 'PermissionController/roleUpdate');
    Route::delete('roles/:id', 'PermissionController/roleDelete');
    // 日志
    Route::get('operation-logs', 'PermissionController/operationLogs');
    Route::get('log-modules', 'PermissionController/logModules');
    Route::get('login-logs', 'PermissionController/loginLogs');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'permission:admin');

// ---------------------------------------------------------------------------
// 风控安全（security:*）
// ---------------------------------------------------------------------------
Route::group('security', function () {
    // 黑名单
    Route::get('blacklist', 'SecurityController/blacklist');
    Route::post('blacklist', 'SecurityController/blacklistAdd');
    Route::post('blacklist/:id/lift', 'SecurityController/blacklistLift');
    // 风控告警
    Route::get('risk-alerts', 'SecurityController/riskAlerts');
    Route::post('risk-alerts/:id/handle', 'SecurityController/riskAlertHandle')->middleware(AdminPermission::class, 'security:alert');
    // 安全事件
    Route::get('events', 'SecurityController/securityEvents');
    Route::post('events/:id/handle', 'SecurityController/securityEventHandle')->middleware(AdminPermission::class, 'security:event');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'security:blacklist');

// ---------------------------------------------------------------------------
// 客服工单（ticket:*）
// ---------------------------------------------------------------------------
Route::group('tickets', function () {
    Route::get('', 'TicketController/list');
    Route::get(':id', 'TicketController/detail');
    Route::post(':id/assign', 'TicketController/assign')->middleware(AdminPermission::class, 'ticket:manage');
    Route::post(':id/reply', 'TicketController/reply')->middleware(AdminPermission::class, 'ticket:manage');
    Route::post(':id/status', 'TicketController/changeStatus')->middleware(AdminPermission::class, 'ticket:manage');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'ticket:list');

// ---------------------------------------------------------------------------
// 数据报表（report:*）
// ---------------------------------------------------------------------------
Route::group('reports', function () {
    Route::get('sales', 'ReportController/sales');
    Route::get('users', 'ReportController/users')->middleware(AdminPermission::class, 'report:user');
    Route::get('collectibles', 'ReportController/collectibles')->middleware(AdminPermission::class, 'report:collectible');
    Route::get('blindbox', 'ReportController/blindbox')->middleware(AdminPermission::class, 'report:blindbox');
    Route::get('finance', 'ReportController/finance')->middleware(AdminPermission::class, 'report:finance');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'report:sales');

// ---------------------------------------------------------------------------
// 数据快照（report:snapshot）：用户持仓/交易快照，手动触发生成、幂等重跑
// ---------------------------------------------------------------------------
Route::group('snapshots', function () {
    Route::post('generate', 'SnapshotController/generate');
    Route::get('holdings', 'SnapshotController/holdings');
    Route::get('trades', 'SnapshotController/trades');
    Route::get('dates', 'SnapshotController/dates');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'report:snapshot');

// ---------------------------------------------------------------------------
// 区块链上链（chain:*）
// 三链适配：文昌链（BSN-DDC）/ 联盟链（自建）/ 蚂蚁链（AntChain）
// ---------------------------------------------------------------------------
Route::group('chain', function () {
    // 链网络配置（密钥 AES 加密存储，非空才更新）
    Route::get('networks', 'ChainController/networks');
    Route::put('networks/:id', 'ChainController/saveNetwork');
    Route::post('networks/:id/test', 'ChainController/testNetwork');
    // 合约登记（已产生链上交易的合约仅可停用不可删除）
    Route::get('contracts', 'ChainController/contracts');
    Route::post('contracts', 'ChainController/contractCreate')->middleware(AdminPermission::class, 'chain:contract');
    Route::put('contracts/:id', 'ChainController/contractUpdate')->middleware(AdminPermission::class, 'chain:contract');
    Route::post('contracts/:id/toggle', 'ChainController/contractToggle')->middleware(AdminPermission::class, 'chain:contract');
    Route::delete('contracts/:id', 'ChainController/contractDelete')->middleware(AdminPermission::class, 'chain:contract');
    // 链上交易流水
    Route::get('transactions', 'ChainController/transactions');
    // 藏品上链铸造（幂等：仅未上链持仓）
    Route::post('mint/:id', 'ChainController/mint')->middleware(AdminPermission::class, 'chain:mint');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'chain:config');

// ---------------------------------------------------------------------------
// 审批中心（approval:*）— 大额退款复核等高风险操作
// ---------------------------------------------------------------------------
Route::group('approvals', function () {
    Route::get('', 'ApprovalController/list');
    Route::get('stats', 'ApprovalController/stats');
    Route::get(':id', 'ApprovalController/detail');
    Route::post(':id/handle', 'ApprovalController/handle')->middleware(AdminPermission::class, 'approval:manage');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'approval:list');

// ---------------------------------------------------------------------------
// 平台运维（platform:*）
// ---------------------------------------------------------------------------
Route::group('platform', function () {
    Route::get('cleanup-logs', 'PlatformController/cleanupLogs');
    Route::get('cleanup-preview', 'PlatformController/cleanupPreview');
    Route::post('cleanup-send-code', 'PlatformController/cleanupSendCode')->middleware(AdminPermission::class, 'platform:cleanup');
    Route::post('cleanup-execute', 'PlatformController/cleanupExecute')->middleware(AdminPermission::class, 'platform:cleanup');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'platform:log');

// ---------------------------------------------------------------------------
// 报表 Excel 导出（report:export:*）— P1
// ---------------------------------------------------------------------------
Route::group('reports', function () {
    Route::get('export/sales', 'ReportController/exportSales');
    Route::get('export/users', 'ReportController/exportUsers');
    Route::get('export/collectibles', 'ReportController/exportCollectibles');
    Route::get('export/blindbox', 'ReportController/exportBlindbox');
    Route::get('export/finance', 'ReportController/exportFinance');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'report:sales');

// ---------------------------------------------------------------------------
// 抽签发售（marketing:raffle:*）— P0
// ---------------------------------------------------------------------------
Route::group('raffle', function () {
    Route::get('', 'RaffleController/list');
    Route::get(':id', 'RaffleController/detail');
    Route::post('save', 'RaffleController/save');
    Route::post(':id/start', 'RaffleController/start');
    Route::post(':id/draw', 'RaffleController/draw');
    Route::post(':id/cancel', 'RaffleController/cancel');
    Route::delete(':id', 'RaffleController/delete');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'marketing:raffle:list');

// ---------------------------------------------------------------------------
// 求购挂单（market:buyrequest:*）— P0
// ---------------------------------------------------------------------------
Route::group('buy-request', function () {
    Route::get('', 'BuyRequestController/list');
    Route::post(':id/close', 'BuyRequestController/close');
    Route::delete(':id', 'BuyRequestController/delete');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'market:buyrequest:list');

// ---------------------------------------------------------------------------
// 置换（market:swap:*）— P1
// ---------------------------------------------------------------------------
Route::group('swap', function () {
    Route::get('', 'SwapController/list');
    Route::get('records', 'SwapController/records');
    Route::post(':id/close', 'SwapController/close');
    Route::delete(':id', 'SwapController/delete');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'market:swap:list');

// ---------------------------------------------------------------------------
// 分解/熔炼（marketing:decompose:*）— P1
// ---------------------------------------------------------------------------
Route::group('decompose', function () {
    Route::get('rules', 'DecomposeController/ruleList');
    Route::post('rules', 'DecomposeController/ruleSave');
    Route::post('rules/:id/toggle', 'DecomposeController/ruleToggle');
    Route::delete('rules/:id', 'DecomposeController/ruleDelete');
    Route::get('records', 'DecomposeController/records');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'marketing:decompose:list');

// ---------------------------------------------------------------------------
// 回收站（platform:trash:*）— P1
// ---------------------------------------------------------------------------
Route::group('trash', function () {
    Route::get('collectibles', 'TrashController/collectibles');
    Route::get('orders', 'TrashController/orders');
    Route::get('users', 'TrashController/users');
    Route::get('banners', 'TrashController/banners');
    Route::get('announcements', 'TrashController/announcements');
    Route::post(':type/:id/recover', 'TrashController/recover');
    Route::delete(':type/:id/purge', 'TrashController/purge');
    Route::post(':type/purge-all', 'TrashController/purgeAll');
})->middleware(AdminAuth::class)->middleware(AdminPermission::class, 'platform:trash:list');

// ---------------------------------------------------------------------------
// 兜底：未匹配路由（必须放在所有路由注册的最后）
// ---------------------------------------------------------------------------
Route::miss(function () {
    return json(['code' => 4040, 'message' => '接口不存在（admin）', 'data' => null]);
});
