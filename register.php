<?php
declare(strict_types=1);

/**
 * 用户注册
 *
 * @copyright 千寻百念工作室
 */

require_once __DIR__ . '/includes/bootstrap.php';

/**
 * UTF-8 字符串长度（无 mbstring 时按字节近似，可能偏严）
 */
function register_utf8_len(string $s): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($s, 'UTF-8');
    }
    return strlen($s);
}

const REGISTER_USERNAME_MIN_LEN = 2;
const REGISTER_USERNAME_MAX_LEN = 11;

if (!empty($_SESSION['user_id'])) {
    header('Location: rooms.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $nickname = trim((string) ($_POST['nickname'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($username === '' || $nickname === '' || $password === '' || $confirmPassword === '') {
        $error = '所有字段都必须填写';
    } elseif ($password !== $confirmPassword) {
        $error = '两次输入的密码不一致';
    } else {
        $uLen = register_utf8_len($username);
        if ($uLen < REGISTER_USERNAME_MIN_LEN) {
            $error = '用户名过短';
        } elseif ($uLen > REGISTER_USERNAME_MAX_LEN) {
            $error = '用户名不能超过' . REGISTER_USERNAME_MAX_LEN . '个字符';
        } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
            $error = '用户名仅支持英文字母与数字';
        } elseif (register_utf8_len($nickname) < 2 || register_utf8_len($nickname) > 20) {
            $error = '昵称长度必须在2-20个字符之间';
        } elseif (strlen($password) < 6) {
            $error = '密码长度必须至少为6个字符';
        } else {
            $pdo = db();
            $st = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $st->execute([$username]);
            if ($st->fetch()) {
                $error = '该用户名已被使用';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $now = time();
                $ins = $pdo->prepare(
                    'INSERT INTO users (username, nickname, password_hash, is_admin, is_frozen, is_muted, can_upload_file, is_approved, created_at)
                     VALUES (?, ?, ?, 0, 0, 0, 1, 1, ?)'
                );
                $ins->execute([$username, $nickname, $hash, $now]);
                $userId = (int) $pdo->lastInsertId();
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                $_SESSION['nickname'] = $nickname;
                $_SESSION['is_admin'] = 0;
                $_SESSION['is_muted'] = 0;
                $_SESSION['can_upload_file'] = 1;
                $_SESSION['is_approved'] = 1;
                unset($_SESSION['guest_room']);
                header('Location: rooms.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <?php studio_copyright_html_comment(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>注册 - 实时多人聊天室</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-login">
    <div class="login-shell">
        <div class="login-hero">
            <p class="login-kicker">PHP ChatRoom</p>
            <h1 class="login-title">创建新账号</h1>
            <p class="login-subtitle">注册后自动登录并进入房间，马上开始实时聊天。</p>
        </div>
        <div class="auth-container">
            <div class="auth-box login-auth-box">
                <?php if ($error !== ''): ?>
                    <div class="error-message"><?php echo h($error); ?></div>
                <?php endif; ?>
                <form method="post" action="">
                    <div class="form-group">
                        <label for="username">用户名</label>
                        <input type="text" id="username" name="username" required maxlength="11" autocomplete="username" inputmode="latin" placeholder="英文字母或数字，最多11位">
                    </div>
                    <div class="form-group">
                        <label for="nickname">昵称（必填）</label>
                        <input type="text" id="nickname" name="nickname" required maxlength="20" placeholder="2-20个字符">
                    </div>
                    <div class="form-group">
                        <label for="password">密码</label>
                        <input type="password" id="password" name="password" required autocomplete="new-password" placeholder="至少6位密码">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">确认密码</label>
                        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" placeholder="再次输入密码">
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn-primary login-submit-btn">注册</button>
                    </div>
                    <div class="auth-links">
                        <p>已有账号？<a href="login.php">立即登录</a></p>
                    </div>
                </form>
                <?php studio_render_copyright(); ?>
            </div>
        </div>
    </div>
</body>
</html>
