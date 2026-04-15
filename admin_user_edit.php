<?php
declare(strict_types=1);

/**
 * 管理后台 · 编辑用户
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

$myId = (int) $_SESSION['user_id'];
$id = (int) ($_GET['id'] ?? $_POST['user_id'] ?? 0);
if ($id <= 0) {
    header('Location: admin_users.php');
    exit;
}

$st = $pdo->prepare(
    'SELECT id, username, is_admin, is_frozen, is_muted, can_upload_file, is_approved, created_at FROM users WHERE id = ? LIMIT 1'
);
$st->execute([$id]);
$user = $st->fetch();
if (!$user) {
    header('Location: admin_users.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedId = (int) ($_POST['user_id'] ?? 0);
    if ($postedId !== $id) {
        $error = '请求无效';
    } else {
        $newName = trim((string) ($_POST['username'] ?? ''));
        $newPass = (string) ($_POST['new_password'] ?? '');
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
        $isFrozen = isset($_POST['is_frozen']) ? 1 : 0;
        $isMuted = isset($_POST['is_muted']) ? 1 : 0;
        $canUpload = isset($_POST['can_upload_file']) ? 1 : 0;
        $isApproved = isset($_POST['is_approved']) ? 1 : 0;

        if ($newName === '' || strlen($newName) < 3 || strlen($newName) > 20) {
            $error = '用户名长度须在 3–20 个字符之间';
        } elseif ($newPass !== '' && strlen($newPass) < 6) {
            $error = '新密码至少 6 位，或留空不修改';
        } elseif ($id === $myId && $isFrozen === 1) {
            $error = '不能冻结当前登录账号';
        } else {
            $dup = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1');
            $dup->execute([$newName, $id]);
            if ($dup->fetch()) {
                $error = '该用户名已被其他账号使用';
            } else {
                $cntSt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE is_admin = 1 AND id != ?');
                $cntSt->execute([$id]);
                $otherAdmins = (int) $cntSt->fetchColumn();
                if ($otherAdmins + $isAdmin < 1) {
                    $error = '至少需要保留一名管理员';
                }
            }
        }

        if ($error === '') {
            if ($newPass !== '') {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $up = $pdo->prepare(
                    'UPDATE users SET username = ?, password_hash = ?, is_admin = ?, is_frozen = ?, is_muted = ?, can_upload_file = ?, is_approved = ? WHERE id = ?'
                );
                $up->execute([$newName, $hash, $isAdmin, $isFrozen, $isMuted, $canUpload, $isApproved, $id]);
            } else {
                $up = $pdo->prepare(
                    'UPDATE users SET username = ?, is_admin = ?, is_frozen = ?, is_muted = ?, can_upload_file = ?, is_approved = ? WHERE id = ?'
                );
                $up->execute([$newName, $isAdmin, $isFrozen, $isMuted, $canUpload, $isApproved, $id]);
            }
            if ($id === $myId) {
                enforce_and_refresh_logged_in_user($pdo);
            }
            header('Location: admin_users.php');
            exit;
        }
    }
}

admin_layout_start(
    '管理后台 · ' . (string) $user['username'],
    'users',
    [
        'headline' => (string) $user['username'],
        'subtitle' => '用户 ID ' . (int) $user['id'] . ' · 注册于 ' . date('Y-m-d H:i', (int) $user['created_at']),
        'breadcrumbs' => [
            ['用户管理', 'admin_users.php'],
            ['编辑', null],
        ],
    ]
);
?>
<?php if ($error !== ''): ?>
    <div class="admin-alert admin-alert--error" role="alert"><?php echo h($error); ?></div>
<?php endif; ?>

<form method="post" class="admin-detail-form" action="">
    <input type="hidden" name="user_id" value="<?php echo (int) $id; ?>">

    <section class="admin-panel">
        <h2 class="admin-panel-title">账号信息</h2>
        <div class="admin-panel-body">
            <div class="admin-field-line">
                <label for="username" class="admin-field-label">用户名</label>
                <input type="text" id="username" name="username" class="admin-field-control" maxlength="20" required
                       value="<?php echo h((string) $user['username']); ?>" autocomplete="username">
            </div>
            <div class="admin-field-line">
                <label for="new_password" class="admin-field-label">登录密码</label>
                <input type="password" id="new_password" name="new_password" class="admin-field-control"
                       minlength="6" autocomplete="new-password" placeholder="留空表示不修改">
            </div>
        </div>
    </section>

    <section class="admin-panel">
        <h2 class="admin-panel-title">状态与权限</h2>
        <p class="admin-panel-hint">使用右侧开关即时配置，最后点击底部「保存更改」提交。</p>
        <div class="admin-panel-body admin-panel-body--toggles">
            <label class="admin-toggle-line">
                <span class="admin-toggle-copy">
                    <strong class="admin-toggle-name">管理员</strong>
                    <span class="admin-toggle-desc">可创建房间、清空记录、管理用户</span>
                </span>
                <input type="checkbox" name="is_admin" value="1" class="admin-switch" role="switch"
                    <?php echo (int) $user['is_admin'] === 1 ? ' checked' : ''; ?>>
            </label>
            <label class="admin-toggle-line">
                <span class="admin-toggle-copy">
                    <strong class="admin-toggle-name">冻结账号</strong>
                    <span class="admin-toggle-desc">无法登录与使用聊天</span>
                </span>
                <input type="checkbox" name="is_frozen" value="1" class="admin-switch" role="switch"
                    <?php echo (int) $user['is_frozen'] === 1 ? ' checked' : ''; ?>>
            </label>
            <label class="admin-toggle-line">
                <span class="admin-toggle-copy">
                    <strong class="admin-toggle-name">禁言</strong>
                    <span class="admin-toggle-desc">不可发送文字与文件</span>
                </span>
                <input type="checkbox" name="is_muted" value="1" class="admin-switch" role="switch"
                    <?php echo (int) $user['is_muted'] === 1 ? ' checked' : ''; ?>>
            </label>
            <label class="admin-toggle-line">
                <span class="admin-toggle-copy">
                    <strong class="admin-toggle-name">允许发图片 / 文件</strong>
                    <span class="admin-toggle-desc">关闭后仅可发纯文字</span>
                </span>
                <input type="checkbox" name="can_upload_file" value="1" class="admin-switch" role="switch"
                    <?php echo (int) $user['can_upload_file'] === 1 ? ' checked' : ''; ?>>
            </label>
            <label class="admin-toggle-line">
                <span class="admin-toggle-copy">
                    <strong class="admin-toggle-name">审核通过</strong>
                    <span class="admin-toggle-desc">未通过可登录但不能进入任何房间</span>
                </span>
                <input type="checkbox" name="is_approved" value="1" class="admin-switch" role="switch"
                    <?php echo (int) $user['is_approved'] === 1 ? ' checked' : ''; ?>>
            </label>
        </div>
    </section>

    <div class="admin-toolbar">
        <button type="submit" class="btn-primary admin-toolbar-primary">保存更改</button>
        <a href="admin_users.php" class="admin-toolbar-secondary">返回列表</a>
    </div>
</form>
<?php
admin_layout_end();
