<?php
declare(strict_types=1);

/**
 * 入口重定向
 *
 * @copyright 千寻百念工作室
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: rooms.php');
    exit;
}

header('Location: login.php');
exit;
