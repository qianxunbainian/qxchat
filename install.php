<?php
declare(strict_types=1);

/**
 * 一键安装（不引入 bootstrap，避免未安装时重定向循环）
 *
 * @copyright 千寻百念工作室
 */
date_default_timezone_set('Asia/Shanghai');

require_once __DIR__ . '/includes/install_state.php';
require_once __DIR__ . '/includes/install_schema.php';
require_once __DIR__ . '/includes/helpers.php';

function install_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (is_app_installed()) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>已安装</title>'
        . '<link rel="stylesheet" href="css/style.css"></head><body class="page-rooms">'
        . '<div class="rooms-page"><div class="rooms-panel glass-effect">'
        . '<h1>已完成安装</h1><p class="rooms-sub">请删除服务器上的 <code>install.php</code> 以防他人重置。</p>'
        . '<p><a class="room-link" href="login.php">前往登录</a></p>'
        . '<footer class="site-studio-copy" role="contentinfo"><span>© ' . h(STUDIO_COPYRIGHT) . '</span></footer>'
        . '</div></div></body></html>';
    exit;
}

$error = '';
$defaults = [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'phpchatroom',
    'db_user' => 'root',
    'db_pass' => '',
    'admin_user' => 'admin',
    'anon_salt' => bin2hex(random_bytes(16)),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim((string) ($_POST['db_host'] ?? ''));
    $dbPort = (int) ($_POST['db_port'] ?? 3306);
    $dbName = trim((string) ($_POST['db_name'] ?? ''));
    $dbUser = trim((string) ($_POST['db_user'] ?? ''));
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $adminUser = trim((string) ($_POST['admin_user'] ?? ''));
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $adminPass2 = (string) ($_POST['admin_pass2'] ?? '');
    $anonSalt = trim((string) ($_POST['anon_salt'] ?? ''));

    if ($dbHost === '' || !preg_match('/^[a-zA-Z0-9.\-_]+$/', $dbHost)) {
        $error = '数据库主机格式不正确';
    } elseif ($dbPort < 1 || $dbPort > 65535) {
        $error = '端口无效';
    } elseif ($dbName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
        $error = '库名仅允许字母数字下划线';
    } elseif ($dbUser === '') {
        $error = '数据库用户名不能为空';
    } elseif (strlen($adminUser) < 3 || strlen($adminUser) > 20) {
        $error = '管理员用户名长度 3–20';
    } elseif (strlen($adminPass) < 6) {
        $error = '管理员密码至少 6 位';
    } elseif ($adminPass !== $adminPass2) {
        $error = '两次输入的管理员密码不一致';
    } elseif ($anonSalt === '' || strlen($anonSalt) < 8) {
        $error = '匿名盐值过短（至少 8 字符）';
    } elseif (!extension_loaded('pdo_mysql')) {
        $error = 'PHP 未启用 pdo_mysql 扩展';
    } else {
        $dsnServer = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort);
        $dsnDb = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);

        try {
            $pdoServer = new PDO($dsnServer, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $quotedDb = '`' . str_replace('`', '``', $dbName) . '`';
            $pdoServer->exec(
                'CREATE DATABASE IF NOT EXISTS ' . $quotedDb
                . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );

            $pdo = new PDO($dsnDb, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            foreach (install_schema_statements() as $sql) {
                $sql = trim($sql);
                if ($sql !== '') {
                    $pdo->exec($sql);
                }
            }

            // 旧库升级：列可能已由 install_schema 包含，此处幂等
            try {
                $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
                $chk = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
                );
                $chk->execute([(string) $schema, 'rooms', 'room_password_hash']);
                if ((int) $chk->fetchColumn() === 0) {
                    $pdo->exec('ALTER TABLE rooms ADD COLUMN room_password_hash VARCHAR(255) NULL DEFAULT NULL');
                }
                $chk->execute([(string) $schema, 'users', 'is_admin']);
                if ((int) $chk->fetchColumn() === 0) {
                    $pdo->exec(
                        'ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash'
                    );
                }
            } catch (Throwable $e) {
                // ignore
            }

            $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($userCount > 0) {
                throw new RuntimeException('所选数据库中已有用户数据，请更换空库或清空 users 表后再安装。');
            }

            $pdo->beginTransaction();
            try {
                $roomCount = (int) $pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
                if ($roomCount === 0) {
                    $t = time();
                    $pdo->prepare(
                        'INSERT INTO rooms (id, name, slug, anonymous_mode, guest_allowed, room_password_hash, created_by, created_at)
                         VALUES (?, ?, ?, ?, ?, NULL, NULL, ?), (?, ?, ?, ?, ?, NULL, NULL, ?)'
                    )->execute([
                        1, '大厅', 'lobby', 0, 0, $t,
                        2, '匿名树洞', 'anonymous-pit', 1, 1, $t,
                    ]);
                    $pdo->prepare(
                        'INSERT INTO messages (room_id, user_id, guest_key, display_name, content, is_system, created_at)
                         VALUES (?, NULL, NULL, ?, ?, 1, ?), (?, NULL, NULL, ?, ?, 1, ?)'
                    )->execute([
                        1, '系统', '欢迎来到大厅！请文明交流。', $t,
                        2, '系统', '本房间为匿名模式：昵称对其他人不可追溯；访客也可进入（若开启）。', $t,
                    ]);
                }

                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $ins = $pdo->prepare(
                    'INSERT INTO users (username, nickname, password_hash, is_admin, is_approved, created_at) VALUES (?, ?, ?, 1, 1, ?)'
                );
                $ins->execute([$adminUser, $adminUser, $hash, time()]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $dataDir = __DIR__ . '/data';
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }

            $localCfg = [
                'db' => [
                    'dsn' => $dsnDb,
                    'user' => $dbUser,
                    'pass' => $dbPass,
                ],
                'anon_salt' => $anonSalt,
            ];
            $localPath = __DIR__ . '/includes/config.local.php';
            $exported = "<?php\nreturn " . var_export($localCfg, true) . ";\n";
            if (file_put_contents($localPath, $exported) === false) {
                throw new RuntimeException('无法写入 includes/config.local.php，请检查目录权限');
            }

            $lockBody = json_encode(['installed_at' => time()], JSON_UNESCAPED_UNICODE);
            if (file_put_contents($dataDir . '/install.lock', (string) $lockBody) === false) {
                throw new RuntimeException('无法写入 data/install.lock');
            }

            header('Location: login.php');
            exit;
        } catch (Throwable $e) {
            $error = '安装失败：' . $e->getMessage();
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <?php studio_copyright_html_comment(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>一键安装</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="page-rooms">
    <div class="rooms-page">
        <div class="rooms-panel glass-effect">
            <h1>一键安装</h1>
            <p class="rooms-sub">将创建数据库表、默认房间，并注册首个管理员账号。安装完成后请删除本文件。</p>

            <?php if (!extension_loaded('pdo_mysql')): ?>
                <div class="error-message">当前 PHP 未启用 <strong>pdo_mysql</strong>，请先开启扩展。</div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="error-message"><?php echo install_h($error); ?></div>
            <?php endif; ?>

            <form method="post" class="create-room-form" style="margin-top:16px;">
                <h2 class="rooms-section" style="margin-top:0;">数据库</h2>
                <div class="form-group">
                    <label for="db_host">主机</label>
                    <input type="text" id="db_host" name="db_host" value="<?php echo install_h($defaults['db_host']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="db_port">端口</label>
                    <input type="number" id="db_port" name="db_port" value="<?php echo install_h((string) $defaults['db_port']); ?>" min="1" max="65535" required>
                </div>
                <div class="form-group">
                    <label for="db_name">数据库名</label>
                    <input type="text" id="db_name" name="db_name" value="<?php echo install_h($defaults['db_name']); ?>" required pattern="[a-zA-Z0-9_]+">
                </div>
                <div class="form-group">
                    <label for="db_user">用户名</label>
                    <input type="text" id="db_user" name="db_user" value="<?php echo install_h($defaults['db_user']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="db_pass">密码</label>
                    <input type="password" id="db_pass" name="db_pass" value="" autocomplete="new-password">
                </div>

                <h2 class="rooms-section">管理员（首个账号）</h2>
                <div class="form-group">
                    <label for="admin_user">用户名</label>
                    <input type="text" id="admin_user" name="admin_user" value="<?php echo install_h($defaults['admin_user']); ?>" required minlength="3" maxlength="20">
                </div>
                <div class="form-group">
                    <label for="admin_pass">密码</label>
                    <input type="password" id="admin_pass" name="admin_pass" required minlength="6" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="admin_pass2">确认密码</label>
                    <input type="password" id="admin_pass2" name="admin_pass2" required minlength="6" autocomplete="new-password">
                </div>

                <h2 class="rooms-section">安全</h2>
                <div class="form-group">
                    <label for="anon_salt">匿名盐（用于匿名代号，勿泄露）</label>
                    <input type="text" id="anon_salt" name="anon_salt" value="<?php echo install_h($defaults['anon_salt']); ?>" required minlength="8">
                </div>

                <button type="submit" class="btn-primary" <?php echo extension_loaded('pdo_mysql') ? '' : 'disabled'; ?>>开始安装</button>
            </form>
            <?php studio_render_copyright(); ?>
        </div>
    </div>
</body>
</html>
