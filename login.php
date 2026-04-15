<?php
declare(strict_types=1);

/**
 * 用户登录
 *
 * @copyright 千寻百念工作室
 */

require_once __DIR__ . '/includes/bootstrap.php';

function login_clear_session_state(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

if (!empty($_SESSION['user_id'])) {
    // 防止会话中的 user_id 失效导致 login.php 与 rooms.php 互相重定向
    $pdo = db();
    enforce_and_refresh_logged_in_user($pdo);
    if (!empty($_SESSION['user_id'])) {
        // 若从 rooms 回跳到登录页仍携带会话，优先清理旧会话以打断重定向循环
        if (isset($_GET['from']) && (string) $_GET['from'] === 'rooms') {
            login_clear_session_state();
        } else {
            header('Location: rooms.php');
            exit;
        }
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = '用户名和密码不能为空';
    } else {
        $pdo = db();
        $st = $pdo->prepare(
            'SELECT id, username, nickname, password_hash, is_admin, is_frozen, is_muted, can_upload_file, is_approved
             FROM users WHERE username = ? LIMIT 1'
        );
        $st->execute([$username]);
        $row = $st->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            if ((int) ($row['is_frozen'] ?? 0) === 1) {
                $error = '账号已被冻结，无法登录';
            } else {
                $_SESSION['user_id'] = (int) $row['id'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['nickname'] = (string) ($row['nickname'] ?? '');
                $_SESSION['is_admin'] = (int) ($row['is_admin'] ?? 0) === 1 ? 1 : 0;
                $_SESSION['is_muted'] = (int) ($row['is_muted'] ?? 0) === 1 ? 1 : 0;
                $_SESSION['can_upload_file'] = (int) ($row['can_upload_file'] ?? 1) === 1 ? 1 : 0;
                $_SESSION['is_approved'] = (int) ($row['is_approved'] ?? 0) === 1 ? 1 : 0;
                unset($_SESSION['guest_room']);
                header('Location: rooms.php');
                exit;
            }
        }
        $error = '用户名或密码错误';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <?php studio_copyright_html_comment(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 实时多人聊天室</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-login">
    <div class="login-shell">
        <div class="login-hero">
            <p class="login-kicker">PHP ChatRoom</p>
            <h1 class="login-title">登录聊天室</h1>
            <p class="login-subtitle">连接房间、继续对话，消息与文件实时同步。</p>
        </div>
        <div class="auth-container">
        <div class="auth-box login-auth-box">
            <?php if (isset($_GET['frozen'])): ?>
                <div class="error-message">账号已被冻结，无法继续使用。</div>
            <?php endif; ?>
            <?php if (isset($_GET['register_disabled'])): ?>
                <div class="error-message">当前站点已关闭自助注册，请联系管理员分配账号。</div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="error-message"><?php echo h($error); ?></div>
            <?php endif; ?>
            <form method="post" action="">
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" required autocomplete="username" placeholder="请输入用户名">
                </div>
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="请输入密码">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-primary login-submit-btn">登录</button>
                </div>
                <div class="auth-links">
                    <p>还没有账号？<a href="register.php">立即注册</a></p>
                </div>
            </form>
            <?php studio_render_copyright(); ?>
        </div>
        </div>
    </div>
</body>
</html>
