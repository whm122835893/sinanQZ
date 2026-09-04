<?php
use think\facade\Route;

Route::rule('/', function () {
    return json(['code' => 0, 'message' => '司南数字藏品后端 API', 'data' => ['version' => 'v1.0']]);
});
