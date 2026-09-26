<?php
// admin 应用全局中间件（多应用模式：仅作用于 /admin/** 请求）
return [
    // CORS 跨域（前端开发服务器与后端不同源）
    \app\middleware\Cors::class,
    // C3 修复：AdminAuth 提为应用级兜底中间件，防止 url_route_must=false 回退分发绕过认证
    \app\admin\middleware\AdminAuth::class,
];
