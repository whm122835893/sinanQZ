<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'ApiDoc' => \app\command\ApiDoc::class,
        'ScheduleDispatch' => \app\command\ScheduleDispatch::class,
        'rekey:encrypted' => \app\command\RekeyEncrypted::class,
        'realname:id-hash' => \app\command\RealnameIdHash::class,
        // 编号规则改 SerialService 统一取号后新增：把历史上「编号 > 发行量」和 TMP- 占位号
        // 按新规则重排（默认只读预览，--apply 才写库）。详见 RUNBOOK 与类内注释。
        'serial:renumber'  => \app\command\SerialRenumber::class,
        'admin:reset-password' => \app\command\AdminResetPassword::class,
    ],
];
