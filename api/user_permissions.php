<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$roomId = (int) (($method === 'POST' ? $_POST['room_id'] : $_GET['room_id']) ?? 0);
$userId = (int) (($method === 'POST' ? $_POST['user_id'] : $_GET['user_id']) ?? 0);

if ($roomId <= 0 || $userId <= 0) {
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

$me = (int) $actor['user_id'];
$st = $pdo->prepare(
    'SELECT id, username, is_admin, is_frozen, is_muted, can_upload_file
     FROM users WHERE id = ? LIMIT 1'
);
$st->execute([$userId]);
$user = $st->fetch();
if (!$user) {
    echo json_encode(['success' => false, 'error' => '用户不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET') {
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'is_admin' => (int) $user['is_admin'],
            'is_frozen' => (int) $user['is_frozen'],
            'is_muted' => (int) $user['is_muted'],
            'can_upload_file' => (int) $user['can_upload_file'],
            'is_me' => (int) $user['id'] === $me,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    echo json_encode(['success' => false, 'error' => '请求方法不正确'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isAdmin = isset($_POST['is_admin']) ? 1 : 0;
$isFrozen = isset($_POST['is_frozen']) ? 1 : 0;
$isMuted = isset($_POST['is_muted']) ? 1 : 0;
$canUpload = isset($_POST['can_upload_file']) ? 1 : 0;

if ($userId === $me && $isFrozen === 1) {
    echo json_encode(['success' => false, 'error' => '不能冻结当前登录账号'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ((int) $user['is_admin'] === 1 && $isAdmin === 0) {
    $cntSt = $pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1');
    $adminCount = (int) $cntSt->fetchColumn();
    if ($adminCount <= 1) {
        echo json_encode(['success' => false, 'error' => '至少需要保留一名管理员'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$up = $pdo->prepare(
    'UPDATE users
     SET is_admin = ?, is_frozen = ?, is_muted = ?, can_upload_file = ?
     WHERE id = ?'
);
$up->execute([$isAdmin, $isFrozen, $isMuted, $canUpload, $userId]);

echo json_encode([
    'success' => true,
    'user' => [
        'id' => $userId,
        'is_admin' => $isAdmin,
        'is_frozen' => $isFrozen,
        'is_muted' => $isMuted,
        'can_upload_file' => $canUpload,
    ],
], JSON_UNESCAPED_UNICODE);

