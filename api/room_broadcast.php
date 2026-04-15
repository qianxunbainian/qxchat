<?php
declare(strict_types=1);

/**
 * 房间群广播 API
 *
 * @copyright 千寻百念工作室
 */

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$roomId = (int) (($method === 'POST' ? ($_POST['room_id'] ?? 0) : ($_GET['room_id'] ?? 0)));
if ($roomId <= 0) {
    echo json_encode(['success' => false, 'error' => '参数不正确'], JSON_UNESCAPED_UNICODE);
    exit;
}

$actor = chat_actor_for_room($roomId);
if ($actor === null) {
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

if ($method === 'GET') {
    $text = trim((string) ($room['broadcast_text'] ?? ''));
    echo json_encode(['success' => true, 'broadcast_text' => $text], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    echo json_encode(['success' => false, 'error' => '请求方法不正确'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($actor['type'] !== 'user' || !current_user_is_admin()) {
    echo json_encode(['success' => false, 'error' => '仅管理员可操作'], JSON_UNESCAPED_UNICODE);
    exit;
}

$text = trim((string) ($_POST['broadcast_text'] ?? ''));
if (function_exists('mb_strlen')) {
    if (mb_strlen($text, 'UTF-8') > 200) {
        echo json_encode(['success' => false, 'error' => '广播内容最多200个字符'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} elseif (strlen($text) > 600) {
    echo json_encode(['success' => false, 'error' => '广播内容过长'], JSON_UNESCAPED_UNICODE);
    exit;
}

$saveText = $text === '' ? null : $text;
$up = $pdo->prepare('UPDATE rooms SET broadcast_text = ? WHERE id = ?');
$up->execute([$saveText, $roomId]);

echo json_encode(
    ['success' => true, 'broadcast_text' => $text],
    JSON_UNESCAPED_UNICODE
);
