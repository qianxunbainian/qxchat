<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => '请求方法不正确'], JSON_UNESCAPED_UNICODE);
    exit;
}

$roomId = (int) ($_POST['room_id'] ?? 0);
$nickname = trim((string) ($_POST['nickname'] ?? ''));

if ($roomId <= 0) {
    echo json_encode(['success' => false, 'error' => '参数不正确'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($nickname === '' || strlen($nickname) < 2 || strlen($nickname) > 20) {
    echo json_encode(['success' => false, 'error' => '昵称长度需为2-20个字符'], JSON_UNESCAPED_UNICODE);
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

$uid = (int) $actor['user_id'];
$up = $pdo->prepare('UPDATE users SET nickname = ? WHERE id = ?');
$up->execute([$nickname, $uid]);
$_SESSION['nickname'] = $nickname;

echo json_encode(['success' => true, 'nickname' => $nickname], JSON_UNESCAPED_UNICODE);

