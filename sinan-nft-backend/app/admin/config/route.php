<?php
// admin 应用级路由配置（think-multi-app 在路由分发前合并 app/admin/config/*，仅作用于 /admin/**）
return [
    // 管理端全部接口均显式声明于 route/app.php 且鉴权中间件挂在路由上；
    // 禁止未匹配请求回退「控制器/方法」默认分发，杜绝动词不匹配等路径旁路路由级认证
    // （控制器侧另有 BaseController fail-closed 兜底，双保险）。
    'url_route_must' => true,
];
