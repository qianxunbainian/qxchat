<?php
declare(strict_types=1);

/**
 * 管理后台 · 房间消息清理
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

$error = (string) ($_SESSION['admin_room_messages_flash_error'] ?? '');
$success = (string) ($_SESSION['admin_room_messages_flash_success'] ?? '');
unset($_SESSION['admin_room_messages_flash_error'], $_SESSION['admin_room_messages_flash_success']);
$defaultClearRoomId = (int) ($_GET['clear_room_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clearRoomId = (int) ($_POST['clear_room_id'] ?? 0);
    if (!isset($_POST['confirm_clear']) || (string) $_POST['confirm_clear'] !== '1') {
        $error = '请勾选确认后再清空聊天记录';
    } elseif ($clearRoomId <= 0) {
        $error = '请选择要清空的房间';
    } else {
        $chk = $pdo->prepare('SELECT id, name FROM rooms WHERE id = ? LIMIT 1');
        $chk->execute([$clearRoomId]);
        $targetRoom = $chk->fetch();
        if (!$targetRoom) {
            $error = '所选房间不存在';
        } else {
            require_once __DIR__ . '/includes/upload_helpers.php';
            chat_delete_attachments_for_room($pdo, $clearRoomId);
            $now = time();
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM room_presence WHERE room_id = ?')->execute([$clearRoomId]);
                $pdo->prepare('DELETE FROM messages WHERE room_id = ?')->execute([$clearRoomId]);
                $sys = $pdo->prepare(
                    'INSERT INTO messages (room_id, user_id, guest_key, display_name, content, is_system, created_at)
                     VALUES (?, NULL, NULL, ?, ?, 1, ?)'
                );
                $sys->execute([$clearRoomId, '系统', '管理员已清空本房间聊天记录。', $now]);
                $pdo->commit();
                $success = '已清空「' . (string) $targetRoom['name'] . '」的全部聊天记录。';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = '清空失败，请稍后重试';
            }
        }
    }
    if ($success !== '') {
        $_SESSION['admin_room_messages_flash_success'] = $success;
    }
    if ($error !== '') {
        $_SESSION['admin_room_messages_flash_error'] = $error;
    }
    header('Location: admin_room_messages.php');
    exit;
}

$list = $pdo->query(
    'SELECT id, name FROM rooms ORDER BY id ASC'
)->fetchAll() ?: [];
$roomCount = count($list);
if ($defaultClearRoomId <= 0 && $roomCount > 0) {
    $defaultClearRoomId = (int) $list[0]['id'];
}

admin_layout_start('管理后台 · 消息清理', 'room_ops', [
    'headline' => '消息清理',
    'subtitle' => '删除指定房间内的全部聊天消息与关联上传文件；房间本身保留。操作不可恢复。',
]);
?>

<?php if ($success !== ''): ?>
    <div class="admin-alert admin-alert--success" role="status"><?php echo h($success); ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="admin-alert admin-alert--error" role="alert"><?php echo h($error); ?></div>
<?php endif; ?>

<?php if ($roomCount === 0): ?>
    <p class="admin-empty">暂无房间，请先在「群聊房间」页面创建房间。</p>
<?php else: ?>
    <section class="admin-panel admin-panel--danger-zone">
        <h2 class="admin-panel-title">清空聊天记录</h2>
        <p class="admin-panel-hint">请选择房间并勾选确认。会移除该房间内所有消息与附件，并插入一条系统提示。</p>
        <form method="post" class="admin-clear-messages-form">
            <div class="admin-field-line admin-field-line--block">
                <label for="clear_room_id" class="admin-field-label">目标房间</label>
                <select id="clear_room_id" name="clear_room_id" class="admin-field-control admin-select" required>
                    <?php foreach ($list as $r): ?>
                        <option value="<?php echo (int) $r['id']; ?>" <?php echo ((int) $r['id'] === $defaultClearRoomId) ? 'selected' : ''; ?>><?php echo h((string) $r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="admin-confirm-line">
                <input type="checkbox" name="confirm_clear" value="1" required>
                <span>我确认要清空该房间的全部记录（含图片与附件），且知悉不可恢复</span>
            </label>
            <div class="admin-toolbar">
                <button type="submit" class="btn-danger admin-toolbar-primary">执行清空</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php
admin_layout_end();
