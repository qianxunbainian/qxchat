<?php
declare(strict_types=1);

/**
 * 访客进入房间
 *
 * @copyright 千寻百念工作室
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: rooms.php');
    exit;
}

$roomId = (int) ($_GET['room'] ?? 0);
if ($roomId <= 0) {
    header('Location: login.php');
    exit;
}

$pdo = db();
$room = fetch_room($pdo, $roomId);
if (!$room || (int) $room['guest_allowed'] !== 1) {
    header('Location: login.php');
    exit;
}

if (room_requires_password($room) && !room_password_unlocked($roomId)) {
    header('Location: room_gate.php?id=' . $roomId . '&return=guest');
    exit;
}

$error = '';
$prefill = '';
if (isset($_SESSION['guest_room']) && (int) ($_SESSION['guest_room']['room_id'] ?? 0) === $roomId) {
    $prefill = (string) ($_SESSION['guest_room']['nickname'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nick = sanitize_nickname((string) ($_POST['nickname'] ?? ''));
    $len = function_exists('mb_strlen') ? mb_strlen($nick, 'UTF-8') : strlen($nick);
    if ($len < 2) {
        $error = '昵称至少2个字符';
    } else {
        $reuseToken = null;
        if (isset($_SESSION['guest_room']) && (int) ($_SESSION['guest_room']['room_id'] ?? 0) === $roomId) {
            $reuseToken = (string) ($_SESSION['guest_room']['token'] ?? '');
        }
        $token = ($reuseToken !== null && $reuseToken !== '') ? $reuseToken : bin2hex(random_bytes(16));
        $_SESSION['guest_room'] = [
            'room_id' => $roomId,
            'token' => $token,
            'nickname' => $nick,
        ];
        header('Location: room.php?id=' . $roomId);
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
    <title>访客进入 - <?php echo h($room['name']); ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-login">
    <div class="login-shell">
        <div class="login-hero">
            <p class="login-kicker">PHP ChatRoom</p>
            <h1 class="login-title">访客进入房间</h1>
            <p class="login-subtitle">
                房间：<?php echo h($room['name']); ?>
                <?php if ((int) $room['anonymous_mode'] === 1): ?>
                    <span class="badge badge-anon">匿名</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="auth-container">
            <div class="auth-box login-auth-box">
                <?php if ($error !== ''): ?>
                    <div class="error-message"><?php echo h($error); ?></div>
                <?php endif; ?>
                <form method="post" action="">
                    <div class="form-group">
                        <label for="nickname">显示昵称</label>
                        <input type="text" id="nickname" name="nickname" maxlength="20" required placeholder="2-20 字" value="<?php echo h($prefill); ?>">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-primary login-submit-btn">进入聊天</button>
                    </div>
                    <div class="auth-links">
                        <p>有账号？<a href="login.php">登录</a></p>
                    </div>
                </form>
                <?php studio_render_copyright(); ?>
            </div>
        </div>
    </div>
</body>
</html>
