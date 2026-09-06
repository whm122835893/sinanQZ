<?php
// admin 应用全局中间件（多应用模式：仅作用于 /admin/** 请求）
return [
    // CORS 跨域（前端开发服务器与后端不同源）
    \app\middleware\Cors::class,
];
