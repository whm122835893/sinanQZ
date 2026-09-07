<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'ApiDoc' => \app\command\ApiDoc::class,
        'ScheduleDispatch' => \app\command\ScheduleDispatch::class,
    ],
];
