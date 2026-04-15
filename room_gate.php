<?php
declare(strict_types=1);

/**
 * 房间密码门页
 *
 * @copyright 千寻百念工作室
 */

require_once __DIR__ . '/includes/bootstrap.php';

$roomId = (int) ($_GET['id'] ?? $_POST['room_id'] ?? 0);
$return = (string) ($_GET['return'] ?? $_POST['return'] ?? 'chat');
if ($return !== 'guest' && $return !== 'chat') {
    $return = 'chat';
}

if ($roomId <= 0) {
    header('Location: ' . (!empty($_SESSION['user_id']) ? 'rooms.php' : 'login.php'));
    exit;
}

$pdo = db();
$room = fetch_room($pdo, $roomId);
if (!$room) {
    header('Location: ' . (!empty($_SESSION['user_id']) ? 'rooms.php' : 'login.php'));
    exit;
}

if (!room_requires_password($room)) {
    if ($return === 'guest') {
        header('Location: guest_enter.php?room=' . $roomId);
    } else {
        header('Location: room.php?id=' . $roomId);
    }
    exit;
}

if (room_password_unlocked($roomId)) {
    if ($return === 'guest') {
        header('Location: guest_enter.php?room=' . $roomId);
    } else {
        header('Location: room.php?id=' . $roomId);
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $plain = (string) ($_POST['room_password'] ?? '');
    if ($plain === '') {
        $error = '请输入房间密码';
    } elseif (!verify_room_password($room, $plain)) {
        $error = '房间密码错误';
    } else {
        unlock_room_password($roomId);
        if ($return === 'guest') {
            header('Location: guest_enter.php?room=' . $roomId);
        } else {
            header('Location: room.php?id=' . $roomId);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <?php studio_copyright_html_comment(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>房间密码 · <?php echo h($room['name']); ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-login">
    <div class="login-shell">
        <div class="login-hero">
            <p class="login-kicker">PHP ChatRoom</p>
            <h1 class="login-title">房间密码验证</h1>
            <p class="login-subtitle">房间：<?php echo h($room['name']); ?>，请输入密码后继续访问。</p>
        </div>
        <div class="auth-container">
            <div class="auth-box login-auth-box">
                <?php if ($error !== ''): ?>
                    <div class="error-message" role="alert"><?php echo h($error); ?></div>
                <?php endif; ?>
                <form method="post" action="">
                    <input type="hidden" name="room_id" value="<?php echo $roomId; ?>">
                    <input type="hidden" name="return" value="<?php echo h($return); ?>">
                    <div class="form-group">
                        <label for="room_password">房间密码</label>
                        <input type="password" id="room_password" name="room_password" required autocomplete="off" placeholder="请输入房间密码" autofocus>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-primary login-submit-btn">验证并进入</button>
                    </div>
                    <div class="auth-links">
                        <p>
                            <?php if (!empty($_SESSION['user_id'])): ?>
                                <a href="rooms.php">返回房间列表</a>
                            <?php else: ?>
                                <a href="login.php">返回登录</a>
                            <?php endif; ?>
                        </p>
                    </div>
                </form>
                <?php studio_render_copyright(); ?>
            </div>
        </div>
    </div>
</body>
</html>
