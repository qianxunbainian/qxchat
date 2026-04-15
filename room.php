<?php
declare(strict_types=1);

/**
 * 单房间聊天页
 *
 * @copyright 千寻百念工作室
 */

require_once __DIR__ . '/includes/bootstrap.php';

$roomId = (int) ($_GET['id'] ?? 0);
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

if (!empty($_SESSION['user_id'])) {
    enforce_and_refresh_logged_in_user($pdo);
    if (!current_user_is_approved()) {
        header('Location: rooms.php?pending=1');
        exit;
    }
}

$actor = chat_actor_for_room($roomId);
if ($actor === null) {
    if ((int) $room['guest_allowed'] === 1) {
        header('Location: guest_enter.php?room=' . $roomId);
        exit;
    }
    header('Location: login.php');
    exit;
}

if (!can_access_room($room, $actor)) {
    header('Location: login.php');
    exit;
}

if (room_requires_password($room) && !room_password_unlocked($roomId)) {
    $ret = $actor['type'] === 'guest' ? 'guest' : 'chat';
    header('Location: room_gate.php?id=' . $roomId . '&return=' . $ret);
    exit;
}

global $config;
$displayLabel = display_label_for_actor($pdo, $room, $actor, $config['anon_salt']);
$sessionKey = presence_session_key($actor);
touch_room_presence($pdo, $roomId, $sessionKey, $displayLabel);

$isGuest = $actor['type'] === 'guest';
$canUploadFile = $isGuest || (int) ($_SESSION['can_upload_file'] ?? 1) === 1;
$isMuted = !$isGuest && (int) ($_SESSION['is_muted'] ?? 0) === 1;
$isAdmin = !$isGuest && current_user_is_admin();
$myUserId = !$isGuest ? (int) ($_SESSION['user_id'] ?? 0) : 0;
$myNickname = !$isGuest ? trim((string) ($_SESSION['nickname'] ?? $_SESSION['username'] ?? '')) : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <?php studio_copyright_html_comment(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($room['name']); ?> - 聊天室</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body
    class="page-chat"
    data-room-id="<?php echo $roomId; ?>"
    data-my-display="<?php echo h($displayLabel); ?>"
    data-my-user-id="<?php echo $myUserId; ?>"
    data-my-nickname="<?php echo h($myNickname); ?>"
    data-is-guest="<?php echo $isGuest ? '1' : '0'; ?>"
    data-is-admin="<?php echo $isAdmin ? '1' : '0'; ?>"
    data-can-upload="<?php echo $canUploadFile ? '1' : '0'; ?>"
    data-is-muted="<?php echo $isMuted ? '1' : '0'; ?>"
>
    <div class="chat-container">
        <div class="chat-area">
            <div class="chat-header">
                <div class="chat-header-main">
                    <h2><?php echo h($room['name']); ?></h2>
                    <div class="room-tags">
                        <?php if ((int) $room['anonymous_mode'] === 1): ?>
                            <span class="badge badge-anon">匿名</span>
                        <?php endif; ?>
                        <?php if ((int) $room['guest_allowed'] === 1): ?>
                            <span class="badge badge-guest">访客可进</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="header-toolbar" role="toolbar" aria-label="快捷操作">
                    <?php if (!$isGuest): ?>
                        <a href="rooms.php" class="header-icon-btn" title="返回房间列表" aria-label="返回房间列表">
                            <svg class="header-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
                        </a>
                    <?php else: ?>
                        <a href="guest_enter.php?room=<?php echo $roomId; ?>" class="header-icon-btn" title="更换昵称" aria-label="更换昵称">
                            <svg class="header-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </a>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <button type="button" id="open-online-modal" class="header-icon-btn" title="设置" aria-label="设置" aria-haspopup="dialog">
                            <svg class="header-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 8.92 4.6h.08a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.08a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9v.08a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        </button>
                    <?php endif; ?>
                    <?php if (!$isGuest): ?>
                        <div class="my-menu-wrap">
                            <button type="button" id="open-my-menu" class="header-icon-btn" title="我的" aria-label="我的">
                                <svg class="header-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>
                            </button>
                            <div id="my-menu" class="my-menu" hidden>
                                <button type="button" id="my-edit-nickname" class="my-menu-item">修改昵称</button>
                                <a href="logout.php" class="my-menu-item">退出登录</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="login.php" class="header-action-text" title="登录">登录</a>
                    <?php endif; ?>
                </div>
            </div>
            <div id="room-broadcast-wrap" class="room-broadcast-wrap" hidden>
                <div class="room-broadcast-track">
                    <span id="room-broadcast-text" class="room-broadcast-text"></span>
                </div>
            </div>
            <div class="messages" id="messages"></div>
            <div class="message-input">
                <div class="message-input-wrap">
                    <div class="message-input-toolbar">
                        <button type="button" class="input-tool-btn" id="emoji-toggle" title="表情" aria-expanded="false" aria-controls="emoji-panel">😊</button>
                        <label class="input-tool-btn" title="发送图片">
                            <input type="file" id="input-image" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp,image/tiff,image/avif" hidden>
                            <span class="input-tool-icon" aria-hidden="true">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                            </span>
                        </label>
                        <label class="input-tool-btn" title="发送文件（文档、压缩包、音视频等）">
                            <input type="file" id="input-file" accept="audio/*,video/*,.pdf,.zip,.txt,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.7z,.rar,.csv,.md,.markdown,.json,.xml,.rtf,.html,.htm,.xhtml,.odt,.ods,.odp,.epub,.mp3,.m4a,.wav,.ogg,.oga,.aac,.flac,.weba,.mp4,.webm,.mov,.avi,.mkv,.tar,.gz,.bz2,.xz,.tsv,.log,.ini,.yml,.yaml,.toml,.cfg,.heic,.heif" hidden>
                            <span class="input-tool-icon" aria-hidden="true">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                            </span>
                        </label>
                    </div>
                    <?php if ($isMuted): ?>
                        <p class="chat-muted-banner" role="status">您已被禁言，无法发送消息。</p>
                    <?php endif; ?>
                    <div id="emoji-panel" class="emoji-panel" role="listbox" aria-label="表情" hidden></div>
                    <div id="reply-box" class="reply-composer" hidden>
                        <div class="reply-composer-main">
                            <span class="reply-composer-label">回复</span>
                            <span id="reply-text" class="reply-composer-text"></span>
                        </div>
                        <button type="button" id="reply-cancel" class="reply-composer-cancel" aria-label="取消回复">×</button>
                    </div>
                    <form id="message-form">
                        <input type="text" id="message" name="message" placeholder="输入消息..." autocomplete="off" maxlength="2000">
                        <button type="submit">发送</button>
                    </form>
                </div>
            </div>
        </div>
        <?php studio_render_copyright(); ?>
    </div>

    <div id="online-modal" class="online-modal" role="dialog" aria-modal="true" aria-labelledby="online-modal-title" hidden>
        <div class="online-modal-backdrop" id="online-modal-backdrop"></div>
        <div class="online-modal-panel">
            <div class="online-modal-head">
                <h3 id="online-modal-title">在线成员</h3>
                <button type="button" class="online-modal-close" id="close-online-modal" aria-label="关闭">×</button>
            </div>
            <?php if ($isAdmin): ?>
                <div class="online-admin-actions">
                    <button type="button" id="room-broadcast-btn" class="refresh-btn">群广播</button>
                    <button type="button" id="room-password-btn" class="refresh-btn">房间密码</button>
                    <button type="button" id="clear-room-btn" class="refresh-btn">清理记录</button>
                    <button type="button" id="share-room-btn" class="refresh-btn">分享群聊</button>
                </div>
                <div id="share-room-panel" class="share-room-panel" hidden>
                    <div class="share-room-link-row">
                        <input type="text" id="share-room-link" readonly>
                        <button type="button" id="copy-room-link" class="header-icon-btn">复制</button>
                    </div>
                    <img id="share-room-qr" class="share-room-qr" alt="群聊二维码">
                </div>
            <?php endif; ?>
            <button type="button" id="refresh-users" class="refresh-btn">刷新列表</button>
            <ul id="users-list"></ul>
        </div>
    </div>
    <div id="perm-modal" class="online-modal" role="dialog" aria-modal="true" aria-labelledby="perm-modal-title" hidden>
        <div class="online-modal-backdrop" id="perm-modal-backdrop"></div>
        <div class="online-modal-panel perm-modal-panel">
            <div class="online-modal-head">
                <h3 id="perm-modal-title">权限配置</h3>
                <button type="button" class="online-modal-close" id="close-perm-modal" aria-label="关闭">×</button>
            </div>
            <form id="perm-form" class="perm-form">
                <p id="perm-user-name" class="perm-user-name"></p>
                <label class="perm-item"><input type="checkbox" id="perm-is-admin"> 管理员</label>
                <label class="perm-item"><input type="checkbox" id="perm-is-frozen"> 冻结账号</label>
                <label class="perm-item"><input type="checkbox" id="perm-is-muted"> 禁言</label>
                <label class="perm-item"><input type="checkbox" id="perm-can-upload"> 允许发送文件</label>
                <div class="perm-actions">
                    <button type="button" id="perm-cancel" class="header-icon-btn">取消</button>
                    <button type="submit" id="perm-save" class="refresh-btn">保存权限</button>
                </div>
            </form>
        </div>
    </div>
    <div id="room-password-modal" class="online-modal" role="dialog" aria-modal="true" aria-labelledby="room-password-title" hidden>
        <div class="online-modal-backdrop" id="room-password-backdrop"></div>
        <div class="online-modal-panel perm-modal-panel">
            <div class="online-modal-head">
                <h3 id="room-password-title">房间密码设置</h3>
                <button type="button" class="online-modal-close" id="close-room-password-modal" aria-label="关闭">×</button>
            </div>
            <form id="room-password-form" class="perm-form">
                <input type="password" id="room-password-input" maxlength="64" minlength="4" placeholder="新密码（至少4位）" autocomplete="new-password">
                <input type="password" id="room-password-confirm" maxlength="64" minlength="4" placeholder="确认新密码" autocomplete="new-password">
                <label class="perm-item"><input type="checkbox" id="room-password-clear"> 清空房间密码（无需输入新密码）</label>
                <div class="perm-actions">
                    <button type="button" id="room-password-cancel" class="header-icon-btn">取消</button>
                    <button type="submit" id="room-password-save" class="refresh-btn">保存设置</button>
                </div>
            </form>
        </div>
    </div>
    <div id="room-broadcast-modal" class="online-modal" role="dialog" aria-modal="true" aria-labelledby="room-broadcast-title" hidden>
        <div class="online-modal-backdrop" id="room-broadcast-backdrop"></div>
        <div class="online-modal-panel perm-modal-panel">
            <div class="online-modal-head">
                <h3 id="room-broadcast-title">群广播设置</h3>
                <button type="button" class="online-modal-close" id="close-room-broadcast-modal" aria-label="关闭">×</button>
            </div>
            <form id="room-broadcast-form" class="perm-form">
                <textarea id="room-broadcast-input" maxlength="200" rows="4" placeholder="请输入群广播播报内容（留空表示关闭广播）"></textarea>
                <div class="perm-actions">
                    <button type="button" id="room-broadcast-cancel" class="header-icon-btn">取消</button>
                    <button type="submit" id="room-broadcast-save" class="refresh-btn">保存广播</button>
                </div>
            </form>
        </div>
    </div>
    <div id="profile-modal" class="online-modal" role="dialog" aria-modal="true" aria-labelledby="profile-modal-title" hidden>
        <div class="online-modal-backdrop" id="profile-modal-backdrop"></div>
        <div class="online-modal-panel perm-modal-panel">
            <div class="online-modal-head">
                <h3 id="profile-modal-title">修改昵称</h3>
                <button type="button" class="online-modal-close" id="close-profile-modal" aria-label="关闭">×</button>
            </div>
            <form id="profile-form" class="perm-form">
                <label class="perm-item" for="profile-nickname">昵称</label>
                <input type="text" id="profile-nickname" maxlength="20" required>
                <div class="perm-actions">
                    <button type="button" id="profile-cancel" class="header-icon-btn">取消</button>
                    <button type="submit" id="profile-save" class="refresh-btn">保存昵称</button>
                </div>
            </form>
        </div>
    </div>
    <div id="image-preview-modal" class="online-modal image-preview-modal" role="dialog" aria-modal="true" aria-label="图片预览" hidden>
        <div class="online-modal-backdrop" id="image-preview-backdrop"></div>
        <div class="image-preview-wrap">
            <button type="button" id="close-image-preview" class="online-modal-close image-preview-close" aria-label="关闭">×</button>
            <img id="image-preview-img" class="image-preview-img" alt="预览图片">
        </div>
    </div>

    <script src="js/chat.js"></script>
</body>
</html>
