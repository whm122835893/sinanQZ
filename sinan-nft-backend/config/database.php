<?php
// 数据库配置文件
return [
    'default'         => env('database.TYPE', 'mysql'),
    'connections'     => [
        'mysql' => [
            'type'            => env('database.TYPE', 'mysql'),
            'hostname'        => env('database.HOSTNAME', '127.0.0.1'),
            'database'        => env('database.DATABASE', 'sinan_nft'),
            'username'        => env('database.USERNAME', 'root'),
            'password'        => env('database.PASSWORD', ''),
            'hostport'        => env('database.HOSTPORT', 3306),
            'dsn'             => '',
            'params'          => [],
            'charset'         => env('database.CHARSET', 'utf8mb4'),
            'collation'       => 'utf8mb4_unicode_ci',
            'prefix'          => env('database.PREFIX', 'nft_'),
            'deploy'          => 0,
            'rw_separate'     => false,
            'master_num'      => 1,
            'slave_no'        => '',
            'fields_strict'   => true,
            'break_reconnect' => true,
            'trigger_sql'     => env('database.DEBUG', true),
            'auto_timestamp'  => false,
            'datetime_format' => 'Y-m-d H:i:s.v',
        ],
    ],
];
