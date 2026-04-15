<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$roomId = (int) ($_GET['room_id'] ?? 0);
$actor = chat_actor_for_room($roomId);
if ($roomId <= 0 || $actor === null) {
    echo json_encode(['success' => false, 'error' => '未登录或无权访问'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
$room = fetch_room($pdo, $roomId);
if (!$room || !can_access_room($room, $actor)) {
    echo json_encode(['success' => false, 'error' => '无权访问'], JSON_UNESCAPED_UNICODE);
    exit;
}

assert_room_unlocked_json($room, $roomId);

$frozenJson = api_json_if_user_frozen($pdo, $actor);
if ($frozenJson !== null) {
    echo json_encode($frozenJson, JSON_UNESCAPED_UNICODE);
    exit;
}

global $config;
$displayLabel = display_label_for_actor($pdo, $room, $actor, $config['anon_salt']);
$sk = presence_session_key($actor);

$timeout = 120;
prune_room_presence($pdo, $roomId, $timeout);
touch_room_presence($pdo, $roomId, $sk, $displayLabel);

$cut = time() - $timeout;
$st = $pdo->prepare(
    'SELECT display_label AS username, session_key, last_active, ip, geo_text, geo_updated_at FROM room_presence
     WHERE room_id = ? AND last_active >= ? ORDER BY display_label ASC'
);
$st->execute([$roomId, $cut]);
$rows = $st->fetchAll();

$isAdmin = $actor['type'] === 'user' && current_user_is_admin();
$now = time();
$geoRefreshLimit = 3;

$users = [];
foreach ($rows as $r) {
    $userId = null;
    $sessionKey = (string) ($r['session_key'] ?? '');
    if (substr($sessionKey, 0, 2) === 'u:') {
        $parsedId = (int) substr($sessionKey, 2);
        if ($parsedId > 0) {
            $userId = $parsedId;
        }
    }
    $ip = isset($r['ip']) ? trim((string) $r['ip']) : '';
    $geoText = isset($r['geo_text']) ? trim((string) $r['geo_text']) : '';
    $geoUpdatedAt = isset($r['geo_updated_at']) && $r['geo_updated_at'] !== null ? (int) $r['geo_updated_at'] : 0;

    if ($isAdmin && $geoRefreshLimit > 0) {
        // 仅管理员：粗略定位刷新（最多每 6 小时一次；且每次请求最多刷新 3 个）
        $geoHasHan = $geoText !== '' && @preg_match('/[\p{Han}]/u', $geoText) === 1;
        $shouldRefreshGeo = ($geoText === '' || !$geoHasHan || $geoUpdatedAt <= 0 || ($now - $geoUpdatedAt) > 21600);
        if ($ip !== '' && $shouldRefreshGeo) {
            $g = geo_lookup_ip($ip);
            if (is_array($g)) {
                $parts = [];
                if (!empty($g['continent'])) $parts[] = (string) $g['continent'];
                if (!empty($g['country'])) $parts[] = (string) $g['country'];
                if (!empty($g['region'])) $parts[] = (string) $g['region'];
                if (!empty($g['city'])) $parts[] = (string) $g['city'];
                if (!empty($g['isp'])) $parts[] = (string) $g['isp'];
                $geoText = trim(implode(' ', array_filter($parts)));
                $up = $pdo->prepare('UPDATE room_presence SET geo_text = ?, geo_updated_at = ? WHERE room_id = ? AND session_key = ?');
                $up->execute([$geoText !== '' ? $geoText : null, $now, $roomId, $sessionKey]);
            } else {
                // 即便失败也打时间戳，避免频繁重试打爆外部服务
                $up = $pdo->prepare('UPDATE room_presence SET geo_updated_at = ? WHERE room_id = ? AND session_key = ?');
                $up->execute([$now, $roomId, $sessionKey]);
            }
            $geoRefreshLimit--;
        }
    }
    $users[] = [
        'username' => $r['username'],
        'display_label' => $r['username'],
        'user_id' => $userId,
        'online' => true,
        'last_active' => (int) $r['last_active'],
        'ip' => $isAdmin ? ($ip !== '' ? $ip : null) : null,
        'geo_text' => $isAdmin ? ($geoText !== '' ? $geoText : null) : null,
    ];
}

echo json_encode([
    'users' => $users,
    'timestamp' => time(),
], JSON_UNESCAPED_UNICODE);
