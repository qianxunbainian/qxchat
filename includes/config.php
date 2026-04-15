<?php
/**
 * 默认配置。安装完成后会生成 includes/config.local.php 覆盖数据库与 anon_salt。
 * 环境变量仍可覆盖默认项（未在 local 中指定时生效）。
 */
$defaults = [
    'db' => [
        'dsn' => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=phpchatroom;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ],
    'anon_salt' => getenv('ANON_SALT') ?: 'change-this-salt-in-production',
];

$localPath = __DIR__ . '/config.local.php';
if (is_file($localPath)) {
    /** @var array<string, mixed> $local */
    $local = require $localPath;
    $config = $defaults;
    if (isset($local['db']) && is_array($local['db'])) {
        $config['db'] = array_merge($defaults['db'], $local['db']);
    }
    if (isset($local['anon_salt']) && (string) $local['anon_salt'] !== '') {
        $config['anon_salt'] = (string) $local['anon_salt'];
    }
    return $config;
}

return $defaults;
