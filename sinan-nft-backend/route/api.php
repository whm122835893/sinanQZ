<?php
// API 路由配置
use think\facade\Route;

// 全局 API 前缀
Route::group('/api', function () {

    // ========== 公开接口 ==========

    // 认证（无需登录）
    Route::post('auth/send-code',   'Auth/sendCode');
    Route::post('auth/register',    'Auth/register');
    Route::post('auth/login',       'Auth/login');
    Route::post('auth/reset-password', 'Auth/resetPassword');
    Route::post('auth/logout',      'Auth/logout');

    // 藏品（无需登录；详情/合成公式支持可选登录以返回 myOwned/myAvailable）
    Route::get('collections/categories', 'Collections/categories');
    Route::get('collections/featured',   'Collections/featured');
    Route::get('collections/:id',        'Collections/detail')
        ->middleware(\app\middleware\OptionalJwtAuth::class);
    Route::get('market/collections',     'Collections/market');

    // 盲盒
    Route::get('blind-boxes', 'BlindBoxes/index');

    // 合成
    Route::get('synthesis/activities',       'Synthesis/activities');
    Route::get('synthesis/activities/:id',   'Synthesis/detail')
        ->middleware(\app\middleware\OptionalJwtAuth::class);

    // 抽奖
    Route::get('lucky-draw/activity', 'LuckyDraw/activity');

    // 文物展馆
    Route::get('artifacts',      'Artifacts/index');
    Route::get('artifacts/:id',  'Artifacts/detail');

    // 内容（无需登录）
    Route::get('announcements',              'Content/announcements');
    Route::get('announcements/:id',           'Content/announcementDetail');
    Route::get('banners',                     'Content/banners');
    Route::get('community/groups',            'Content/community');
    Route::get('config',                      'Content/siteConfig');

    // 寄售挂单池（公开）
    Route::get('resale/listings', 'Resale/pool');

    // ========== 需要 JWT 认证 ==========

    Route::group('', function () {

        // 用户
        Route::get('user/profile',            'User/profile');
        Route::put('user/profile',            'User/updateProfile');
        Route::post('user/realname',          'User/realname');
        Route::post('user/password/trade',    'User/setTradePassword');
        Route::post('user/verify-trade-password', 'User/verifyTradePassword');

        // 我的藏品
        Route::get('user/collections',        'Collections/mine');
        Route::get('user/favorites',           'Collections/favorites');
        Route::post('collections/:id/favorite', 'Collections/favorite');

        // 订单
        Route::post('orders',                 'Orders/create');
        Route::post('orders/:orderNo/pay',    'Orders/pay');
        Route::post('orders/callback',        'Orders/callback');
        Route::post('orders/:orderNo/cancel', 'Orders/cancel');
        Route::get('orders',                  'Orders/myList');

        // 寄售
        Route::post('resale/listings',             'Resale/create');
        Route::post('resale/listings/:listingId/cancel', 'Resale/cancel');
        Route::get('resale/listings/mine',          'Resale/mine');

        // 转赠
        Route::post('transfers',                'Transfers/create');
        Route::post('transfers/:transferId/handle', 'Transfers/handle');
        Route::get('transfers/mine',            'Transfers/mine');

        // 盲盒
        Route::post('blind-boxes/open',         'BlindBoxes/open');

        // 合成
        Route::post('synthesis/submit',         'Synthesis/submit');
        Route::get('synthesis/records',         'Synthesis/records');

        // 签到
        Route::post('check-in',                 'CheckIn/perform');
        Route::get('check-in/records',          'CheckIn/records');
        Route::get('check-in/calendar',         'CheckIn/calendar');

        // 抽奖
        Route::post('lucky-draw/draw',          'LuckyDraw/draw');
        Route::get('lucky-draw/records',        'LuckyDraw/records');

        // 钱包
        Route::get('wallet',                    'Wallet/info');
        Route::get('wallet/transactions',       'Wallet/transactions');
        Route::post('wallet/recharge',          'Wallet/recharge');

        // 邀请
        Route::get('invite/info',               'Invite/info');
        Route::get('invite/records',            'Invite/records');

    })->middleware(\app\middleware\JwtAuth::class);

})->middleware(\app\middleware\Cors::class);
