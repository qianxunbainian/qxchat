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
if ($roomId <= 0) {
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

if (!current_user_is_admin()) {
    echo json_encode(['success' => false, 'error' => '仅管理员可操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

chat_delete_attachments_for_room($pdo, $roomId);
$now = time();
$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM room_presence WHERE room_id = ?')->execute([$roomId]);
    $pdo->prepare('DELETE FROM messages WHERE room_id = ?')->execute([$roomId]);
    $sys = $pdo->prepare(
        'INSERT INTO messages (room_id, user_id, guest_key, display_name, content, is_system, created_at)
         VALUES (?, NULL, NULL, ?, ?, 1, ?)'
    );
    $sys->execute([$roomId, '系统', '管理员已清空本房间聊天记录。', $now]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => '清理失败，请稍后重试'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);

