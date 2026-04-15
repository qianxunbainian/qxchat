<?php
declare(strict_types=1);

/**
 * @copyright 千寻百念工作室
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/upload_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => '请求方法不正确'], JSON_UNESCAPED_UNICODE);
    exit;
}

$roomId = (int) ($_POST['room_id'] ?? 0);
$raw = trim((string) ($_POST['message'] ?? ''));
$replyToMessageId = (int) ($_POST['reply_to_message_id'] ?? 0);

$actor = chat_actor_for_room($roomId);
if ($roomId <= 0 || $actor === null) {
    echo json_encode(['success' => false, 'error' => '未登录或无权访问'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hasFile = isset($_FILES['file'])
    && is_array($_FILES['file'])
    && (int) ($_FILES['file']['error'] ?? 0) === UPLOAD_ERR_OK;

if (!$hasFile && $raw === '') {
    echo json_encode(['success' => false, 'error' => '消息不能为空'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strlen($raw) > 8000) {
    echo json_encode(['success' => false, 'error' => '消息过长'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$room = fetch_room($pdo, $roomId);
if (!$room || !can_access_room($room, $actor)) {
    echo json_encode(['success' => false, 'error' => '无权访问'], JSON_UNESCAPED_UNICODE);
    exit;
}

$frozenJson = api_json_if_user_frozen($pdo, $actor);
if ($frozenJson !== null) {
    echo json_encode($frozenJson, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($actor['type'] === 'user') {
    $flags = chat_user_account_flags($pdo, $actor);
    if ($flags && $flags['is_muted'] === 1) {
        echo json_encode(['success' => false, 'error' => '您已被禁言，无法发送消息', 'account_muted' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($hasFile && $flags && $flags['can_upload_file'] !== 1) {
        echo json_encode(['success' => false, 'error' => '无发送文件权限'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

assert_room_unlocked_json($room, $roomId);

if ($replyToMessageId > 0) {
    $replySt = $pdo->prepare(
        'SELECT id FROM messages WHERE id = ? AND room_id = ? LIMIT 1'
    );
    $replySt->execute([$replyToMessageId, $roomId]);
    if (!$replySt->fetch()) {
        echo json_encode(['success' => false, 'error' => '回复目标消息不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

global $config;
$displayName = display_label_for_actor($pdo, $room, $actor, $config['anon_salt']);

$userId = null;
$guestKey = null;
if ($actor['type'] === 'user') {
    $userId = (int) $actor['user_id'];
} else {
    $guestKey = (string) $actor['guest_token'];
}

$now = time();
$sk = presence_session_key($actor);
touch_room_presence($pdo, $roomId, $sk, $displayName);

if ($hasFile) {
    $upload = chat_save_room_upload($roomId, $_FILES['file']);
    if ($upload === null) {
        echo json_encode(['success' => false, 'error' => '文件上传失败、过大或不支持的类型'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $caption = $raw !== '' ? htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
    $kind = $upload['kind'];
    $path = $upload['relative_path'];
    $origName = $upload['original_name'];

    $ins = $pdo->prepare(
        'INSERT INTO messages (room_id, user_id, guest_key, display_name, content, content_kind, attachment_path, attachment_name, reply_to_message_id, is_system, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
    );
    $ins->execute([$roomId, $userId, $guestKey, $displayName, $caption, $kind, $path, $origName, $replyToMessageId > 0 ? $replyToMessageId : null, $now]);
} else {
    $content = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $ins = $pdo->prepare(
        'INSERT INTO messages (room_id, user_id, guest_key, display_name, content, content_kind, attachment_path, attachment_name, reply_to_message_id, is_system, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, ?, 0, ?)'
    );
    $ins->execute([$roomId, $userId, $guestKey, $displayName, $content, 'text', $replyToMessageId > 0 ? $replyToMessageId : null, $now]);
}

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
