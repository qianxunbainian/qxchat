<?php
declare(strict_types=1);

/**
 * 管理后台首页
 *
 * @copyright 千寻百念工作室
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/admin_layout.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = db();
enforce_and_refresh_logged_in_user($pdo);

if (!current_user_is_admin()) {
    header('Location: rooms.php');
    exit;
}

$userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$roomCount = (int) $pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();

admin_layout_start('管理后台 · 控制台', 'dashboard', [
    'headline' => '控制台',
    'subtitle' => '快捷入口与概览',
]);
?>
<div class="admin-dash-grid">
    <a href="admin_users.php" class="admin-dash-card admin-dash-card--primary">
        <span class="admin-dash-card-icon" aria-hidden="true">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </span>
        <span class="admin-dash-card-label">用户管理</span>
        <span class="admin-dash-card-stat"><?php echo $userCount; ?> 个账号</span>
        <span class="admin-dash-card-hint">资料、冻结、禁言与发文件权限</span>
    </a>
    <a href="rooms.php" class="admin-dash-card">
        <span class="admin-dash-card-icon" aria-hidden="true">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </span>
        <span class="admin-dash-card-label">群聊与房间</span>
        <span class="admin-dash-card-stat"><?php echo $roomCount; ?> 个房间</span>
        <span class="admin-dash-card-hint">创建房间与进入聊天</span>
    </a>
    <a href="admin_room_messages.php" class="admin-dash-card admin-dash-card--warn">
        <span class="admin-dash-card-icon admin-dash-card-icon--warn" aria-hidden="true">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </span>
        <span class="admin-dash-card-label">消息清理</span>
        <span class="admin-dash-card-stat">按房间清空记录</span>
        <span class="admin-dash-card-hint">删除消息与附件，保留房间</span>
    </a>
</div>
<?php
admin_layout_end();
