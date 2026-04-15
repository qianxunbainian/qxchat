<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/upload_helpers.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => '请求方法不正确'], JSON_UNESCAPED_UNICODE);
    exit;
}

$roomId = (int) ($_POST['room_id'] ?? 0);
$messageId = (int) ($_POST['message_id'] ?? 0);

if ($roomId <= 0 || $messageId <= 0) {
    echo json_encode(['success' => false, 'error' => '参数不正确'], JSON_UNESCAPED_UNICODE);
    exit;
}

$actor = chat_actor_for_room($roomId);
if ($actor === null || $actor['type'] !== 'user') {
    echo json_encode(['success' => false, 'error' => '未登录或无权访问'], JSON_UNESCAPED_UNICODE);
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

assert_room_unlocked_json($room, $roomId);

$uid = (int) $actor['user_id'];
$isAdmin = current_user_is_admin();
$now = time();
$limitSec = 120;

$st = $pdo->prepare(
    'SELECT id, room_id, user_id, guest_key, is_system, is_recalled, attachment_path, attachment_name, created_at
     FROM messages WHERE id = ? AND room_id = ? LIMIT 1'
);
$st->execute([$messageId, $roomId]);
$msg = $st->fetch();
if (!$msg) {
    echo json_encode(['success' => false, 'error' => '消息不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int) ($msg['is_system'] ?? 0) === 1) {
    echo json_encode(['success' => false, 'error' => '系统消息不可撤回'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int) ($msg['is_recalled'] ?? 0) === 1) {
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$ownerId = $msg['user_id'] !== null ? (int) $msg['user_id'] : 0;
$guestKey = $msg['guest_key'] !== null ? trim((string) $msg['guest_key']) : '';
if ($ownerId <= 0 && !$isAdmin) {
    echo json_encode(['success' => false, 'error' => '仅支持撤回注册用户发送的消息'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($ownerId <= 0 && $guestKey === '') {
    echo json_encode(['success' => false, 'error' => '该消息无法撤回'], JSON_UNESCAPED_UNICODE);
    exit;
}

$age = $now - (int) ($msg['created_at'] ?? 0);
if (!$isAdmin) {
    if ($ownerId !== $uid) {
        echo json_encode(['success' => false, 'error' => '无权撤回他人消息'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($age > $limitSec) {
        echo json_encode(['success' => false, 'error' => '仅支持两分钟内撤回'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$pdo->beginTransaction();
try {
    if (!empty($msg['attachment_path'])) {
        chat_delete_attachment_for_message($pdo, $messageId, $roomId);
    }

    $up = $pdo->prepare(
        'UPDATE messages
         SET is_recalled = 1,
             recalled_at = ?,
             recalled_by_user_id = ?,
             content_kind = ?,
             content = ?,
             attachment_path = NULL,
             attachment_name = NULL
         WHERE id = ? AND room_id = ?'
    );
    $up->execute([$now, $uid, 'recalled', '消息已撤回', $messageId, $roomId]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => '撤回失败，请稍后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

