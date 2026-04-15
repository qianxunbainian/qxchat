<?php
declare(strict_types=1);

/**
 * 房间列表
 *
 * @copyright 千寻百念工作室
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php?from=rooms');
    exit;
}

$pdo = db();
enforce_and_refresh_logged_in_user($pdo);
$error = (string) ($_SESSION['rooms_flash_error'] ?? '');
$success = (string) ($_SESSION['rooms_flash_success'] ?? '');
unset($_SESSION['rooms_flash_error'], $_SESSION['rooms_flash_success']);
$uid = (int) $_SESSION['user_id'];
$isAdmin = current_user_is_admin();
$isApproved = current_user_is_approved();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isAdmin) {
        $updateUserPermId = (int) ($_POST['update_user_perm_id'] ?? 0);
        $renameRoomId = (int) ($_POST['rename_room_id'] ?? 0);
        $deleteRoomId = (int) ($_POST['delete_room_id'] ?? 0);
        $updateRoomPasswordId = (int) ($_POST['update_room_password_id'] ?? 0);
        if ($updateUserPermId > 0) {
            $permIsAdmin = isset($_POST['perm_is_admin']) ? 1 : 0;
            $permIsFrozen = isset($_POST['perm_is_frozen']) ? 1 : 0;
            $permIsMuted = isset($_POST['perm_is_muted']) ? 1 : 0;
            $permCanUpload = isset($_POST['perm_can_upload_file']) ? 1 : 0;
            $permIsApproved = isset($_POST['perm_is_approved']) ? 1 : 0;
            $permNewPassword = (string) ($_POST['perm_new_password'] ?? '');
            $permNewPasswordConfirm = (string) ($_POST['perm_new_password_confirm'] ?? '');
            $myId = (int) $_SESSION['user_id'];
            $st = $pdo->prepare('SELECT id, username, is_admin FROM users WHERE id = ? LIMIT 1');
            $st->execute([$updateUserPermId]);
            $targetUser = $st->fetch();
            if (!$targetUser) {
                $error = '目标用户不存在';
            } elseif ($updateUserPermId === $myId && $permIsFrozen === 1) {
                $error = '不能冻结当前登录账号';
            } else {
                if ($permNewPassword !== '' || $permNewPasswordConfirm !== '') {
                    if ($permNewPassword !== $permNewPasswordConfirm) {
                        $error = '两次输入的新密码不一致';
                    } elseif (strlen($permNewPassword) < 6) {
                        $error = '新密码至少6位';
                    }
                }
                if ((int) ($targetUser['is_admin'] ?? 0) === 1 && $permIsAdmin === 0) {
                    $cntSt = $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1');
                    $adminCount = (int) $cntSt->fetchColumn();
                    if ($adminCount <= 1) {
                        $error = '至少需要保留一名管理员';
                    }
                }
                if ($error === '') {
                    if ($permNewPassword !== '') {
                        $up = $pdo->prepare(
                            'UPDATE users
                             SET is_admin = ?, is_frozen = ?, is_muted = ?, can_upload_file = ?, is_approved = ?, password_hash = ?
                             WHERE id = ?'
                        );
                        $up->execute([
                            $permIsAdmin,
                            $permIsFrozen,
                            $permIsMuted,
                            $permCanUpload,
                            $permIsApproved,
                            password_hash($permNewPassword, PASSWORD_DEFAULT),
                            $updateUserPermId,
                        ]);
                    } else {
                        $up = $pdo->prepare(
                            'UPDATE users
                             SET is_admin = ?, is_frozen = ?, is_muted = ?, can_upload_file = ?, is_approved = ?
                             WHERE id = ?'
                        );
                        $up->execute([$permIsAdmin, $permIsFrozen, $permIsMuted, $permCanUpload, $permIsApproved, $updateUserPermId]);
                    }
                    if ($updateUserPermId === $myId) {
                        enforce_and_refresh_logged_in_user($pdo);
                    }
                    $success = '已更新用户「' . (string) ($targetUser['username'] ?? '') . '」的权限';
                }
            }
        } elseif ($updateRoomPasswordId > 0) {
            $roomPass = (string) ($_POST['room_password'] ?? '');
            $roomPass2 = (string) ($_POST['room_password_confirm'] ?? '');
            $clearPassword = isset($_POST['clear_password']) ? 1 : 0;
            $chk = $pdo->prepare('SELECT id, name FROM rooms WHERE id = ? LIMIT 1');
            $chk->execute([$updateRoomPasswordId]);
            $targetRoom = $chk->fetch();
            if (!$targetRoom) {
                $error = '目标房间不存在';
            } elseif ($clearPassword === 1) {
                $up = $pdo->prepare('UPDATE rooms SET room_password_hash = NULL WHERE id = ?');
                $up->execute([$updateRoomPasswordId]);
                unlock_room_password($updateRoomPasswordId);
                $success = '已清空房间「' . (string) $targetRoom['name'] . '」的密码';
            } else {
                if ($roomPass === '' || $roomPass2 === '') {
                    $error = '请输入并确认房间密码';
                } elseif ($roomPass !== $roomPass2) {
                    $error = '房间密码两次输入不一致';
                } elseif (strlen($roomPass) < 4) {
                    $error = '房间密码至少4位';
                } else {
                    $up = $pdo->prepare('UPDATE rooms SET room_password_hash = ? WHERE id = ?');
                    $up->execute([password_hash($roomPass, PASSWORD_DEFAULT), $updateRoomPasswordId]);
                    unlock_room_password($updateRoomPasswordId);
                    $success = '已更新房间「' . (string) $targetRoom['name'] . '」的密码';
                }
            }
        } elseif ($renameRoomId > 0) {
            $newRoomName = trim((string) ($_POST['new_room_name'] ?? ''));
            if ($newRoomName === '' || strlen($newRoomName) > 100) {
                $error = '房间名称不能为空且不超过100字';
            } else {
                $chk = $pdo->prepare('SELECT id, name FROM rooms WHERE id = ? LIMIT 1');
                $chk->execute([$renameRoomId]);
                $targetRoom = $chk->fetch();
                if (!$targetRoom) {
                    $error = '目标房间不存在';
                } else {
                    $up = $pdo->prepare('UPDATE rooms SET name = ? WHERE id = ?');
                    $up->execute([$newRoomName, $renameRoomId]);
                    $success = '房间名已更新为「' . $newRoomName . '」';
                }
            }
        } elseif ($deleteRoomId > 0) {
            $chk = $pdo->prepare('SELECT id, name FROM rooms WHERE id = ? LIMIT 1');
            $chk->execute([$deleteRoomId]);
            $targetRoom = $chk->fetch();
            if (!$targetRoom) {
                $error = '目标房间不存在';
            } else {
                require_once __DIR__ . '/includes/upload_helpers.php';
                chat_delete_attachments_for_room($pdo, $deleteRoomId);
                $del = $pdo->prepare('DELETE FROM rooms WHERE id = ?');
                $del->execute([$deleteRoomId]);
                $success = '已删除房间「' . (string) $targetRoom['name'] . '」';
            }
        } else {
            $name = trim((string) ($_POST['name'] ?? ''));
            $anonymous = isset($_POST['anonymous_mode']) ? 1 : 0;
            $guestAllowed = isset($_POST['guest_allowed']) ? 1 : 0;
            $roomPass = (string) ($_POST['room_password'] ?? '');
            $roomPass2 = (string) ($_POST['room_password_confirm'] ?? '');

            $roomHash = null;
            if ($roomPass !== '' || $roomPass2 !== '') {
                if ($roomPass !== $roomPass2) {
                    $error = '房间密码两次输入不一致';
                } elseif (strlen($roomPass) < 4) {
                    $error = '房间密码至少4位（或全部留空表示不设密码）';
                } else {
                    $roomHash = password_hash($roomPass, PASSWORD_DEFAULT);
                }
            }

            if ($error === '' && ($name === '' || strlen($name) > 100)) {
                $error = '房间名称不能为空且不超过100字';
            }

            if ($error === '') {
                $slug = unique_room_slug($pdo, $name);
                $now = time();
                $pdo->beginTransaction();
                try {
                    $ins = $pdo->prepare(
                        'INSERT INTO rooms (name, slug, anonymous_mode, guest_allowed, room_password_hash, created_by, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $ins->execute([$name, $slug, $anonymous, $guestAllowed, $roomHash, $uid, $now]);
                    $roomId = (int) $pdo->lastInsertId();
                    $welcome = '房间「' . $name . '」已创建。';
                    $m = $pdo->prepare(
                        'INSERT INTO messages (room_id, user_id, guest_key, display_name, content, is_system, created_at)
                         VALUES (?, NULL, NULL, ?, ?, 1, ?)'
                    );
                    $m->execute([$roomId, '系统', $welcome, $now]);
                    $pdo->commit();
                    if ($roomHash !== null) {
                        unlock_room_password($roomId);
                    }
                    header('Location: room.php?id=' . $roomId);
                    exit;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $error = '创建失败，请稍后重试';
                }
            }
        }
    } else {
        $error = '只有管理员可以创建房间';
    }
    if ($success !== '' || $error !== '') {
        if ($success !== '') {
            $_SESSION['rooms_flash_success'] = $success;
        }
        if ($error !== '') {
            $_SESSION['rooms_flash_error'] = $error;
        }
        header('Location: rooms.php');
        exit;
    }
}

$list = $pdo->query(
    'SELECT id, name, slug, anonymous_mode, guest_allowed, room_password_hash, created_at FROM rooms ORDER BY id ASC'
)->fetchAll();
$manageUsers = [];
if ($isAdmin) {
    $manageUsers = $pdo->query(
        'SELECT id, username, nickname, is_admin, is_frozen, is_muted, can_upload_file, is_approved, created_at
         FROM users ORDER BY id ASC'
    )->fetchAll() ?: [];
}

$username = (string) $_SESSION['username'];
$avatarLetter = function_exists('mb_substr')
    ? mb_strtoupper(mb_substr($username, 0, 1, 'UTF-8'), 'UTF-8')
    : strtoupper(substr($username, 0, 1));
$roomCount = count($list);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <?php studio_copyright_html_comment(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>群聊房间</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-rooms rooms-v3">
    <div class="rooms-shell">
        <header class="rooms-topbar glass-effect">
            <div class="rooms-topbar-brand">
                <h1 class="rooms-title">群聊房间</h1>
                <p class="rooms-lead">选择房间进入聊天<?php echo $isAdmin ? '，或创建新房间' : '。'; ?></p>
            </div>
            <div class="rooms-topbar-user">
                <div class="rooms-user-chip">
                    <span class="rooms-user-avatar" aria-hidden="true"><?php echo h($avatarLetter); ?></span>
                    <span class="rooms-user-name"><?php echo h($username); ?></span>
                </div>
                <a href="logout.php" class="rooms-logout">退出</a>
                <?php if ($isAdmin): ?>
                    <button type="button" class="rooms-nav-link rooms-create-trigger" id="open-create-room-modal">创建房间</button>
                    <button type="button" class="rooms-nav-link rooms-user-manage-trigger" id="open-user-manage-modal">用户管理</button>
                <?php endif; ?>
            </div>
        </header>

        <?php if (!$isApproved): ?>
            <div class="rooms-alert error-message">当前账号尚未通过管理员审核，可登录但暂不能进入房间，请等待审核通过。</div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="rooms-alert success-message"><?php echo h($success); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="rooms-alert error-message"><?php echo h($error); ?></div>
        <?php endif; ?>

        <div class="rooms-v3-meta">
            <span class="rooms-v3-pill">共 <?php echo (int) $roomCount; ?> 个房间</span>
            <span class="rooms-v3-pill"><?php echo $isAdmin ? '管理员模式' : '普通成员'; ?></span>
        </div>

        <div class="rooms-v3-grid">
            <section class="rooms-v3-list-panel">
                <div class="rooms-main-head">
                    <h2 class="rooms-section-title">进入房间</h2>
                    <span class="rooms-stat">选择一个房间开始聊天</span>
                </div>

                <?php if ($roomCount === 0): ?>
                    <p class="rooms-empty"><?php echo $isAdmin
                        ? '暂无房间，请使用顶部「创建房间」添加第一个房间。'
                        : '暂无房间，请联系管理员创建。'; ?></p>
                <?php else: ?>
                    <ul class="rooms-card-list" role="list">
                        <?php foreach ($list as $r): ?>
                            <li class="room-card">
                                <a class="room-card-link" href="room.php?id=<?php echo (int) $r['id']; ?>" <?php echo !$isApproved ? 'onclick="window.alert(\'当前账号审核中，暂不能进入房间。\'); return false;"' : ''; ?>>
                                    <span class="room-card-icon" aria-hidden="true">
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                    </span>
                                    <div class="room-card-text">
                                        <span class="room-card-name"><?php echo h($r['name']); ?></span>
                                        <span class="room-card-badges">
                                            <?php if ((int) $r['anonymous_mode'] === 1): ?>
                                                <span class="badge badge-anon">匿名</span>
                                            <?php endif; ?>
                                            <?php if ((int) $r['guest_allowed'] === 1): ?>
                                                <span class="badge badge-guest">访客</span>
                                            <?php endif; ?>
                                            <?php if (!empty($r['room_password_hash'])): ?>
                                                <span class="badge badge-lock" title="需要房间密码">密码</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <span class="room-card-chevron" aria-hidden="true">›</span>
                                </a>
                                <?php if ((int) $r['guest_allowed'] === 1): ?>
                                    <div class="room-card-footer">
                                        <a class="room-card-guest-link" href="guest_enter.php?room=<?php echo (int) $r['id']; ?>">访客入口</a>
                                    </div>
                                <?php endif; ?>
                                <?php if ($isAdmin): ?>
                                    <div class="room-card-footer room-card-footer--admin">
                                        <form method="post" onsubmit="const n=window.prompt('请输入新的房间名称', '<?php echo h((string) $r['name']); ?>'); if(n===null){return false;} this.new_room_name.value=n; return true;">
                                            <input type="hidden" name="rename_room_id" value="<?php echo (int) $r['id']; ?>">
                                            <input type="hidden" name="new_room_name" value="">
                                            <button type="submit" class="room-card-edit-btn">修改名称</button>
                                        </form>
                                        <form method="post" onsubmit="const p=window.prompt('为房间「<?php echo h((string) $r['name']); ?>」设置新密码（至少4位，留空表示清空密码）', ''); if(p===null){return false;} if(p===''){ if(!window.confirm('确认清空该房间密码？')){return false;} this.clear_password.value='1'; this.room_password.value=''; this.room_password_confirm.value=''; return true; } if(p.length<4){ window.alert('房间密码至少4位'); return false; } const p2=window.prompt('请再次输入新密码', ''); if(p2===null){return false;} if(p!==p2){ window.alert('两次输入不一致'); return false; } this.clear_password.value='0'; this.room_password.value=p; this.room_password_confirm.value=p2; return true;">
                                            <input type="hidden" name="update_room_password_id" value="<?php echo (int) $r['id']; ?>">
                                            <input type="hidden" name="room_password" value="">
                                            <input type="hidden" name="room_password_confirm" value="">
                                            <input type="hidden" name="clear_password" value="0">
                                            <button type="submit" class="room-card-edit-btn">房间密码</button>
                                        </form>
                                        <form method="post" onsubmit="return window.confirm('确认删除该房间？删除后不可恢复。');">
                                            <input type="hidden" name="delete_room_id" value="<?php echo (int) $r['id']; ?>">
                                            <button type="submit" class="room-card-delete-btn">删除房间</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
        <?php if ($isAdmin): ?>
            <div class="online-modal rooms-user-manage-modal" id="user-manage-modal" hidden>
                <button type="button" class="online-modal-backdrop" id="user-manage-modal-backdrop" aria-label="关闭用户管理弹窗"></button>
                <div class="online-modal-panel rooms-user-manage-panel">
                    <div class="online-modal-head">
                        <h3>用户管理</h3>
                        <button type="button" class="online-modal-close" id="close-user-manage-modal" aria-label="关闭">×</button>
                    </div>
                    <div class="rooms-user-search-wrap">
                        <input type="text" id="rooms-user-search" class="rooms-user-search-input" placeholder="搜索用户名或昵称">
                    </div>
                    <div class="rooms-user-manage-list" id="rooms-user-manage-list">
                        <?php foreach ($manageUsers as $u): ?>
                            <button
                                type="button"
                                class="rooms-user-manage-item"
                                data-user-id="<?php echo (int) $u['id']; ?>"
                                data-username="<?php echo h((string) $u['username']); ?>"
                                data-is-admin="<?php echo (int) $u['is_admin']; ?>"
                                data-is-frozen="<?php echo (int) $u['is_frozen']; ?>"
                                data-is-muted="<?php echo (int) $u['is_muted']; ?>"
                                data-can-upload-file="<?php echo (int) $u['can_upload_file']; ?>"
                                data-is-approved="<?php echo (int) $u['is_approved']; ?>"
                            >
                                <span class="rooms-user-manage-name"><?php echo h((string) $u['username']); ?></span>
                                <span class="rooms-user-manage-meta">
                                    #<?php echo (int) $u['id']; ?> · <?php echo h((string) $u['nickname']); ?>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="online-modal rooms-user-perm-modal" id="user-perm-modal" hidden>
                <button type="button" class="online-modal-backdrop" id="user-perm-modal-backdrop" aria-label="关闭权限配置弹窗"></button>
                <div class="online-modal-panel perm-modal-panel">
                    <div class="online-modal-head">
                        <h3>权限配置</h3>
                        <button type="button" class="online-modal-close" id="close-user-perm-modal" aria-label="关闭">×</button>
                    </div>
                    <form method="post" class="perm-form rooms-user-manage-form" id="rooms-user-manage-form">
                        <input type="hidden" name="update_user_perm_id" id="rooms-perm-user-id" value="">
                        <p class="perm-user-name" id="rooms-perm-user-name">请选择一个用户进行权限编辑</p>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_is_admin" id="rooms-perm-is-admin" value="1">
                            管理员（可创建房间和管理用户）
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_is_frozen" id="rooms-perm-is-frozen" value="1">
                            冻结账号（不可登录和使用）
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_is_muted" id="rooms-perm-is-muted" value="1">
                            禁言（不能发送消息）
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_can_upload_file" id="rooms-perm-can-upload" value="1">
                            允许发送图片/文件
                        </label>
                        <label class="perm-item">
                            <input type="checkbox" name="perm_is_approved" id="rooms-perm-is-approved" value="1">
                            审核通过（可进入房间）
                        </label>
                        <div class="form-group">
                            <label for="rooms-perm-new-password">新密码（可选）</label>
                            <input type="password" name="perm_new_password" id="rooms-perm-new-password" minlength="6" maxlength="64" autocomplete="new-password" placeholder="留空表示不修改密码">
                        </div>
                        <div class="form-group">
                            <label for="rooms-perm-new-password-confirm">确认新密码</label>
                            <input type="password" name="perm_new_password_confirm" id="rooms-perm-new-password-confirm" minlength="6" maxlength="64" autocomplete="new-password" placeholder="再次输入新密码">
                        </div>
                        <div class="perm-actions">
                            <button type="submit" class="btn-primary" id="rooms-perm-save" disabled>保存权限</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="online-modal rooms-create-modal" id="create-room-modal" hidden>
                <button type="button" class="online-modal-backdrop" id="create-room-modal-backdrop" aria-label="关闭创建房间弹窗"></button>
                <div class="online-modal-panel">
                    <div class="online-modal-head">
                        <h3>创建房间</h3>
                        <button type="button" class="online-modal-close" id="close-create-room-modal" aria-label="关闭">×</button>
                    </div>
                    <p class="rooms-create-hint">创建一个新房间并设置聊天模式和访问权限。</p>
                    <form method="post" class="create-room-form">
                        <div class="form-group">
                            <label for="create_room_name">房间名称</label>
                            <input type="text" id="create_room_name" name="name" maxlength="100" required placeholder="例如：项目讨论组">
                        </div>
                        <div class="form-group inline-checks">
                            <label><input type="checkbox" name="anonymous_mode" value="1"> 匿名模式</label>
                            <label><input type="checkbox" name="guest_allowed" value="1"> 允许访客</label>
                        </div>
                        <div class="form-group">
                            <label for="create_room_password">房间密码（可选）</label>
                            <input type="password" id="create_room_password" name="room_password" autocomplete="new-password" minlength="4" placeholder="至少4位，留空表示不设密码">
                        </div>
                        <div class="form-group">
                            <label for="create_room_password_confirm">确认密码</label>
                            <input type="password" id="create_room_password_confirm" name="room_password_confirm" autocomplete="new-password" minlength="4" placeholder="再次输入密码">
                        </div>
                        <button type="submit" class="btn-primary btn-create-room">创建并进入</button>
                    </form>
                </div>
            </div>
            <script>
                (function () {
                    const createModal = document.getElementById('create-room-modal');
                    const createOpenBtn = document.getElementById('open-create-room-modal');
                    const createCloseBtn = document.getElementById('close-create-room-modal');
                    const createBackdrop = document.getElementById('create-room-modal-backdrop');
                    const createNameInput = document.getElementById('create_room_name');
                    const userModal = document.getElementById('user-manage-modal');
                    const userOpenBtn = document.getElementById('open-user-manage-modal');
                    const userCloseBtn = document.getElementById('close-user-manage-modal');
                    const userBackdrop = document.getElementById('user-manage-modal-backdrop');
                    const userPermModal = document.getElementById('user-perm-modal');
                    const userPermCloseBtn = document.getElementById('close-user-perm-modal');
                    const userPermBackdrop = document.getElementById('user-perm-modal-backdrop');
                    const userSearchInput = document.getElementById('rooms-user-search');
                    const permForm = document.getElementById('rooms-user-manage-form');
                    const permUserId = document.getElementById('rooms-perm-user-id');
                    const permUserName = document.getElementById('rooms-perm-user-name');
                    const permIsAdmin = document.getElementById('rooms-perm-is-admin');
                    const permIsFrozen = document.getElementById('rooms-perm-is-frozen');
                    const permIsMuted = document.getElementById('rooms-perm-is-muted');
                    const permCanUpload = document.getElementById('rooms-perm-can-upload');
                    const permIsApproved = document.getElementById('rooms-perm-is-approved');
                    const permNewPassword = document.getElementById('rooms-perm-new-password');
                    const permNewPasswordConfirm = document.getElementById('rooms-perm-new-password-confirm');
                    const permSave = document.getElementById('rooms-perm-save');
                    const userItems = Array.from(document.querySelectorAll('.rooms-user-manage-item'));

                    const hideModal = function (modal) {
                        if (modal) {
                            modal.hidden = true;
                        }
                    };
                    const showModal = function (modal) {
                        if (modal) {
                            modal.hidden = false;
                        }
                    };
                    if (createOpenBtn && createModal && createCloseBtn && createBackdrop) {
                        createOpenBtn.addEventListener('click', function () {
                            showModal(createModal);
                            window.setTimeout(function () {
                                if (createNameInput) {
                                    createNameInput.focus();
                                }
                            }, 0);
                        });
                        createCloseBtn.addEventListener('click', function () { hideModal(createModal); });
                        createBackdrop.addEventListener('click', function () { hideModal(createModal); });
                    }

                    if (userOpenBtn && userModal && userCloseBtn && userBackdrop) {
                        userOpenBtn.addEventListener('click', function () {
                            showModal(userModal);
                            window.setTimeout(function () {
                                if (userSearchInput) {
                                    userSearchInput.focus();
                                }
                            }, 0);
                        });
                        userCloseBtn.addEventListener('click', function () { hideModal(userModal); });
                        userBackdrop.addEventListener('click', function () { hideModal(userModal); });
                    }
                    if (userPermModal && userPermCloseBtn && userPermBackdrop) {
                        userPermCloseBtn.addEventListener('click', function () { hideModal(userPermModal); });
                        userPermBackdrop.addEventListener('click', function () { hideModal(userPermModal); });
                    }

                    const setActiveUserItem = function (activeItem) {
                        userItems.forEach(function (item) {
                            item.classList.toggle('is-active', item === activeItem);
                        });
                    };
                    userItems.forEach(function (item) {
                        item.addEventListener('click', function () {
                            const id = Number(item.dataset.userId || 0);
                            if (!id) {
                                return;
                            }
                            if (permUserId) permUserId.value = String(id);
                            if (permUserName) permUserName.textContent = '正在编辑：' + (item.dataset.username || '');
                            if (permIsAdmin) permIsAdmin.checked = Number(item.dataset.isAdmin || 0) === 1;
                            if (permIsFrozen) permIsFrozen.checked = Number(item.dataset.isFrozen || 0) === 1;
                            if (permIsMuted) permIsMuted.checked = Number(item.dataset.isMuted || 0) === 1;
                            if (permCanUpload) permCanUpload.checked = Number(item.dataset.canUploadFile || 0) === 1;
                            if (permIsApproved) permIsApproved.checked = Number(item.dataset.isApproved || 0) === 1;
                            if (permNewPassword) permNewPassword.value = '';
                            if (permNewPasswordConfirm) permNewPasswordConfirm.value = '';
                            if (permSave) permSave.disabled = false;
                            setActiveUserItem(item);
                            showModal(userPermModal);
                        });
                    });
                    if (userSearchInput) {
                        userSearchInput.addEventListener('input', function () {
                            const keyword = userSearchInput.value.trim().toLowerCase();
                            userItems.forEach(function (item) {
                                const text = item.textContent ? item.textContent.toLowerCase() : '';
                                item.hidden = keyword !== '' && text.indexOf(keyword) === -1;
                            });
                        });
                    }
                    if (permForm) {
                        permForm.addEventListener('submit', function (event) {
                            if (!permUserId || !permUserId.value) {
                                event.preventDefault();
                                window.alert('请先选择一个用户');
                            }
                        });
                    }
                    document.addEventListener('keydown', function (event) {
                        if (event.key !== 'Escape') {
                            return;
                        }
                        if (createModal && !createModal.hidden) hideModal(createModal);
                        if (userPermModal && !userPermModal.hidden) hideModal(userPermModal);
                        if (userModal && !userModal.hidden) hideModal(userModal);
                    });
                })();
            </script>
        <?php endif; ?>
        <?php studio_render_copyright(); ?>
    </div>
</body>
</html>
