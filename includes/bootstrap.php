<?php
declare(strict_types=1);

/**
 * 应用引导
 *
 * @copyright 千寻百念工作室
 */

date_default_timezone_set('Asia/Shanghai');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/install_state.php';

$script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
if (!is_app_installed() && $script !== 'install.php') {
    header('Location: install.php');
    exit;
}

$config = require __DIR__ . '/config.php';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/room_password.php';
