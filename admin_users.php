<?php
declare(strict_types=1);

/**
 * 管理后台 · 用户列表
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

$error = (string) ($_SESSION['admin_users_flash_error'] ?? '');
$success = (string) ($_SESSION['admin_users_flash_success'] ?? '');
unset($_SESSION['admin_users_flash_error'], $_SESSION['admin_users_flash_success']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newUsername = trim((string) ($_POST['new_username'] ?? ''));
    $newNickname = trim((string) ($_POST['new_nickname'] ?? ''));
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $newIsAdmin = isset($_POST['new_is_admin']) ? 1 : 0;
    $newApproved = isset($_POST['new_is_approved']) ? 1 : 0;
    if ($newUsername === '' || strlen($newUsername) < 3 || strlen($newUsername) > 20) {
        $error = '用户名长度须在 3-20 个字符之间';
    } elseif ($newNickname === '' || strlen($newNickname) < 2 || strlen($newNickname) > 20) {
        $error = '昵称长度须在 2-20 个字符之间';
    } elseif (strlen($newPassword) < 6) {
        $error = '密码至少 6 位';
    } else {
        $dup = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $dup->execute([$newUsername]);
        if ($dup->fetch()) {
            $error = '该用户名已存在';
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO users (username, nickname, password_hash, is_admin, is_frozen, is_muted, can_upload_file, is_approved, created_at)
                 VALUES (?, ?, ?, ?, 0, 0, 1, ?, ?)'
            );
            $ins->execute([$newUsername, $newNickname, password_hash($newPassword, PASSWORD_DEFAULT), $newIsAdmin, $newApproved, time()]);
            $success = '用户已添加：' . $newUsername;
        }
    }
    if ($success !== '') {
        $_SESSION['admin_users_flash_success'] = $success;
    }
    if ($error !== '') {
        $_SESSION['admin_users_flash_error'] = $error;
    }
    header('Location: admin_users.php');
    exit;
}

$rows = $pdo->query(
    'SELECT id, username, nickname, is_admin, is_frozen, is_muted, can_upload_file, is_approved, created_at
     FROM users ORDER BY id ASC'
)->fetchAll() ?: [];

$n = count($rows);

admin_layout_start('管理后台 · 用户', 'users', [
    'headline' => '用户管理',
    'subtitle' => $n > 0 ? '共 ' . $n . ' 个账号 · 点击一行进入详情' : '暂无用户数据',
]);
?>
<?php if ($success !== ''): ?>
    <div class="admin-alert admin-alert--success" role="status"><?php echo h($success); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="admin-alert admin-alert--error" role="alert"><?php echo h($error); ?></div>
<?php endif; ?>

<section class="admin-panel">
    <h2 class="admin-panel-title">添加用户</h2>
    <form method="post" class="admin-detail-form">
        <div class="admin-panel-body">
            <div class="admin-field-line">
                <label class="admin-field-label" for="new_username">用户名</label>
                <input id="new_username" name="new_username" class="admin-field-control" maxlength="20" required>
            </div>
            <div class="admin-field-line">
                <label class="admin-field-label" for="new_nickname">昵称</label>
                <input id="new_nickname" name="new_nickname" class="admin-field-control" maxlength="20" required>
            </div>
            <div class="admin-field-line">
                <label class="admin-field-label" for="new_password">初始密码</label>
                <input type="password" id="new_password" name="new_password" class="admin-field-control" minlength="6" required>
            </div>
            <label class="admin-toggle-line">
                <span class="admin-toggle-copy"><strong class="admin-toggle-name">设为管理员</strong></span>
                <input type="checkbox" name="new_is_admin" value="1" class="admin-switch" role="switch">
            </label>
            <label class="admin-toggle-line">
                <span class="admin-toggle-copy"><strong class="admin-toggle-name">审核通过</strong></span>
                <input type="checkbox" name="new_is_approved" value="1" class="admin-switch" role="switch">
            </label>
        </div>
        <div class="admin-toolbar">
            <button type="submit" class="btn-primary admin-toolbar-primary">创建用户</button>
        </div>
    </form>
</section>

<?php if ($n === 0): ?>
    <p class="admin-empty">暂无用户。</p>
<?php else: ?>
    <div class="admin-data-list" role="list">
        <div class="admin-data-list-head" aria-hidden="true">
            <span class="admin-data-col-user">用户</span>
            <span class="admin-data-col-tags">状态</span>
            <span class="admin-data-col-time">注册时间</span>
            <span class="admin-data-col-go"></span>
        </div>
        <?php foreach ($rows as $r): ?>
            <a class="admin-data-row" role="listitem" href="admin_user_edit.php?id=<?php echo (int) $r['id']; ?>">
                <div class="admin-data-col-user">
                    <span class="admin-data-name"><?php echo h((string) $r['username']); ?></span>
                    <span class="admin-data-id">#<?php echo (int) $r['id']; ?> · <?php echo h((string) $r['nickname']); ?></span>
                </div>
                <div class="admin-data-col-tags">
                    <?php if ((int) $r['is_admin'] === 1): ?>
                        <span class="admin-pill admin-pill--role">管理员</span>
                    <?php endif; ?>
                    <?php if ((int) $r['is_frozen'] === 1): ?>
                        <span class="admin-pill admin-pill--danger">冻结</span>
                    <?php endif; ?>
                    <?php if ((int) $r['is_muted'] === 1): ?>
                        <span class="admin-pill admin-pill--warn">禁言</span>
                    <?php endif; ?>
                    <?php if ((int) $r['can_upload_file'] !== 1): ?>
                        <span class="admin-pill admin-pill--muted">无文件</span>
                    <?php endif; ?>
                    <?php if ((int) $r['is_approved'] !== 1): ?>
                        <span class="admin-pill admin-pill--warn">待审核</span>
                    <?php endif; ?>
                    <?php if ((int) $r['is_frozen'] !== 1 && (int) $r['is_muted'] !== 1 && (int) $r['can_upload_file'] === 1 && (int) $r['is_admin'] !== 1 && (int) $r['is_approved'] === 1): ?>
                        <span class="admin-pill admin-pill--ok">正常</span>
                    <?php endif; ?>
                </div>
                <div class="admin-data-col-time">
                    <?php echo h(date('Y-m-d H:i', (int) $r['created_at'])); ?>
                </div>
                <span class="admin-data-chevron" aria-hidden="true">›</span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
admin_layout_end();
