<?php
declare(strict_types=1);

/**
 * 管理后台布局
 *
 * @copyright 千寻百念工作室
 */

require_once __DIR__ . '/admin_spa.php';

/**
 * @param list<array{0: string, 1: string|null}> $breadcrumbs
 */
function admin_render_page_head_block(string $headline, ?string $subtitle, array $breadcrumbs): void
{
    ?>
                <header class="admin-page-head">
                    <?php if ($breadcrumbs !== []): ?>
                        <nav class="admin-breadcrumb" aria-label="面包屑">
                            <?php foreach ($breadcrumbs as $i => $crumb): ?>
                                <?php if ($i > 0): ?>
                                    <span class="admin-breadcrumb-sep" aria-hidden="true">/</span>
                                <?php endif; ?>
                                <?php if ($crumb[1] !== null && $crumb[1] !== ''): ?>
                                    <a href="<?php echo h($crumb[1]); ?>"><?php echo h($crumb[0]); ?></a>
                                <?php else: ?>
                                    <span class="admin-breadcrumb-current"><?php echo h($crumb[0]); ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>
                    <h1 class="admin-page-title"><?php echo h($headline); ?></h1>
                    <?php if ($subtitle !== null && $subtitle !== ''): ?>
                        <p class="admin-page-subtitle"><?php echo h((string) $subtitle); ?></p>
                    <?php endif; ?>
                </header>
    <?php
}

/**
 * 后台统一布局：侧栏（品牌 + 菜单 + 账号）+ 主内容；窄屏为抽屉 + 顶栏。
 *
 * @param string $htmlTitle 浏览器标题
 * @param 'dashboard'|'users'|'room_ops' $navActive
 * @param array{
 *   headline?: string,
 *   subtitle?: string|null,
 *   breadcrumbs?: list<array{0: string, 1: string|null}>
 * } $ctx
 */
function admin_layout_start(string $htmlTitle, string $navActive, array $ctx = []): void
{
    $headline = isset($ctx['headline']) ? (string) $ctx['headline'] : $htmlTitle;
    $subtitle = array_key_exists('subtitle', $ctx) ? $ctx['subtitle'] : null;
    $breadcrumbs = $ctx['breadcrumbs'] ?? [];
    $username = (string) ($_SESSION['username'] ?? '');
    $usernameH = h($username);
    $letter = $username !== ''
        ? (function_exists('mb_substr')
            ? mb_strtoupper(mb_substr($username, 0, 1, 'UTF-8'), 'UTF-8')
            : strtoupper(substr($username, 0, 1)))
        : '?';

    $navItems = [
        ['key' => 'dashboard', 'label' => '控制台', 'href' => 'admin.php', 'icon' => 'home'],
        ['key' => 'users', 'label' => '用户管理', 'href' => 'admin_users.php', 'icon' => 'users'],
        ['key' => 'room_ops', 'label' => '消息清理', 'href' => 'admin_room_messages.php', 'icon' => 'trash'],
    ];

    $navSvgs = [
        'home' => '<svg class="admin-svg-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>',
        'users' => '<svg class="admin-svg-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'trash' => '<svg class="admin-svg-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>',
        'chat' => '<svg class="admin-svg-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
    ];

    if (admin_is_partial_request()) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<div id="admin-spa-content" class="admin-spa-content" data-page-title="' . h($htmlTitle) . '" data-admin-nav-active="' . h($navActive) . '">';
        admin_render_page_head_block($headline, $subtitle, $breadcrumbs);
        echo '<div class="admin-page-body">';
        return;
    }

    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <?php studio_copyright_html_comment(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($htmlTitle); ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="admin-app">
    <div class="admin-sidebar-backdrop" id="admin-sidebar-backdrop" hidden aria-hidden="true"></div>

    <header class="admin-topbar admin-topbar--mobile">
        <button type="button" class="admin-menu-toggle" id="admin-menu-toggle" aria-label="打开或关闭菜单" aria-expanded="false" aria-controls="admin-sidebar">
            <span class="admin-menu-toggle-bar" aria-hidden="true"></span>
            <span class="admin-menu-toggle-bar" aria-hidden="true"></span>
            <span class="admin-menu-toggle-bar" aria-hidden="true"></span>
        </button>
        <span class="admin-topbar-mobile-title">管理后台</span>
    </header>

    <div class="admin-frame">
        <aside class="admin-sidebar" id="admin-sidebar" aria-label="后台导航">
            <div class="admin-sidebar-brand">
                <span class="admin-sidebar-logo" aria-hidden="true">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2L2 7l10 5 10-5-10-5z" stroke="currentColor" stroke-width="1.75" stroke-linejoin="round"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <div class="admin-sidebar-brand-text">
                    <span class="admin-sidebar-title">管理后台</span>
                    <span class="admin-sidebar-tagline">PHP Chatroom</span>
                </div>
            </div>

            <nav class="admin-nav" role="navigation" id="admin-nav">
                <?php foreach ($navItems as $item): ?>
                    <?php
                    $isActive = $navActive === $item['key'];
                    $cls = 'admin-nav-link' . ($isActive ? ' is-active' : '');
                    ?>
                    <a class="<?php echo $cls; ?>"
                       href="<?php echo h($item['href']); ?>"
                       data-admin-nav="<?php echo h($item['key']); ?>"
                       <?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                        <?php echo $navSvgs[$item['icon']] ?? ''; ?>
                        <span class="admin-nav-label"><?php echo h($item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="admin-sidebar-spacer" aria-hidden="true"></div>

            <div class="admin-sidebar-account">
                <div class="admin-user-chip">
                    <span class="admin-user-avatar" aria-hidden="true"><?php echo h($letter); ?></span>
                    <span class="admin-user-name"><?php echo $usernameH; ?></span>
                </div>
                <div class="admin-sidebar-account-actions">
                    <a href="rooms.php" class="admin-account-link">进入群聊</a>
                    <span class="admin-account-sep" aria-hidden="true">·</span>
                    <a href="logout.php" class="admin-account-link admin-account-link--muted">退出</a>
                </div>
            </div>

            <div class="admin-sidebar-footer">
                <a href="rooms.php" class="admin-sidebar-exit">
                    <?php echo $navSvgs['chat']; ?>
                    <span class="admin-nav-label">返回房间列表</span>
                </a>
            </div>
        </aside>

        <main class="admin-main" id="admin-main">
            <div class="admin-main-inner">
                <div id="admin-spa-content" class="admin-spa-content" data-page-title="<?php echo h($htmlTitle); ?>" data-admin-nav-active="<?php echo h($navActive); ?>">
                    <?php admin_render_page_head_block($headline, $subtitle, $breadcrumbs); ?>
                    <div class="admin-page-body">
    <?php
}

function admin_layout_end(): void
{
    if (admin_is_partial_request()) {
        echo '</div></div>';
        exit;
    }

    ?>
                    </div>
                </div>
            </div>
            <?php studio_render_copyright(); ?>
        </main>
    </div>
    <script src="js/admin_spa.js" defer></script>
    <script>
    (function () {
        var root = document.querySelector('.admin-app');
        var toggle = document.getElementById('admin-menu-toggle');
        var backdrop = document.getElementById('admin-sidebar-backdrop');
        if (!root || !toggle) return;
        function setOpen(open) {
            root.classList.toggle('admin-sidebar-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.body.style.overflow = open ? 'hidden' : '';
            if (backdrop) {
                backdrop.hidden = !open;
                backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
            }
        }
        toggle.addEventListener('click', function () {
            setOpen(!root.classList.contains('admin-sidebar-open'));
        });
        if (backdrop) {
            backdrop.addEventListener('click', function () { setOpen(false); });
        }
        window.addEventListener('resize', function () {
            if (window.matchMedia('(min-width: 900px)').matches) setOpen(false);
        });
    })();
    </script>
</body>
</html>
    <?php
}
