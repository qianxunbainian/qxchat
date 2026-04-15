<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => '请求方法不正确'], JSON_UNESCAPED_UNICODE);
    exit;
}

$roomId = (int) ($_POST['room_id'] ?? 0);
$password = (string) ($_POST['room_password'] ?? '');
$confirm = (string) ($_POST['room_password_confirm'] ?? '');
$clearPassword = isset($_POST['clear_password']);

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

if ($clearPassword) {
    $up = $pdo->prepare('UPDATE rooms SET room_password_hash = NULL WHERE id = ?');
    $up->execute([$roomId]);
    unlock_room_password($roomId);
    echo json_encode(['success' => true, 'has_password' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($password === '' || $confirm === '') {
    echo json_encode(['success' => false, 'error' => '请输入并确认房间密码'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($password !== $confirm) {
    echo json_encode(['success' => false, 'error' => '房间密码两次输入不一致'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (strlen($password) < 4) {
    echo json_encode(['success' => false, 'error' => '房间密码至少4位'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$up = $pdo->prepare('UPDATE rooms SET room_password_hash = ? WHERE id = ?');
$up->execute([$hash, $roomId]);
unlock_room_password($roomId);

echo json_encode(['success' => true, 'has_password' => true], JSON_UNESCAPED_UNICODE);

