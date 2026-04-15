<?php
declare(strict_types=1);

/**
 * @copyright 千寻百念工作室
 */

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$roomId = (int) ($_GET['room_id'] ?? 0);
$lastId = (int) ($_GET['last_id'] ?? 0);

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
touch_room_presence($pdo, $roomId, $sk, $displayLabel);

if ($lastId === 0) {
    $st = $pdo->prepare(
        'SELECT m.id, m.user_id, m.guest_key, m.display_name, m.content, m.content_kind, m.attachment_path, m.attachment_name,
                m.reply_to_message_id, m.is_recalled, m.recalled_at, m.recalled_by_user_id, m.is_system, m.created_at,
                su.nickname AS sender_nickname, su.username AS sender_username,
                rm.display_name AS reply_display_name, rm.user_id AS reply_user_id, rm.content AS reply_content, rm.content_kind AS reply_content_kind, rm.is_recalled AS reply_is_recalled, rm.is_system AS reply_is_system,
                ru.nickname AS reply_sender_nickname, ru.username AS reply_sender_username,
                COALESCE(rb.is_admin, 0) AS recalled_by_is_admin,
                rb.nickname AS recalled_by_nickname,
                rb.username AS recalled_by_username
         FROM messages m
         LEFT JOIN users su ON su.id = m.user_id
         LEFT JOIN messages rm ON rm.id = m.reply_to_message_id
         LEFT JOIN users ru ON ru.id = rm.user_id
         LEFT JOIN users rb ON rb.id = m.recalled_by_user_id
         WHERE m.room_id = ? ORDER BY m.id DESC LIMIT 150'
    );
    $st->execute([$roomId]);
    $rows = array_reverse($st->fetchAll() ?: []);
} else {
    $st = $pdo->prepare(
        'SELECT m.id, m.user_id, m.guest_key, m.display_name, m.content, m.content_kind, m.attachment_path, m.attachment_name,
                m.reply_to_message_id, m.is_recalled, m.recalled_at, m.recalled_by_user_id, m.is_system, m.created_at,
                su.nickname AS sender_nickname, su.username AS sender_username,
                rm.display_name AS reply_display_name, rm.user_id AS reply_user_id, rm.content AS reply_content, rm.content_kind AS reply_content_kind, rm.is_recalled AS reply_is_recalled, rm.is_system AS reply_is_system,
                ru.nickname AS reply_sender_nickname, ru.username AS reply_sender_username,
                COALESCE(rb.is_admin, 0) AS recalled_by_is_admin,
                rb.nickname AS recalled_by_nickname,
                rb.username AS recalled_by_username
         FROM messages m
         LEFT JOIN users su ON su.id = m.user_id
         LEFT JOIN messages rm ON rm.id = m.reply_to_message_id
         LEFT JOIN users ru ON ru.id = rm.user_id
         LEFT JOIN users rb ON rb.id = m.recalled_by_user_id
         WHERE m.room_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT 200'
    );
    $st->execute([$roomId, $lastId]);
    $rows = $st->fetchAll();
}

$uid = $actor['type'] === 'user' ? (int) $actor['user_id'] : null;
$gk = $actor['type'] === 'guest' ? (string) $actor['guest_token'] : null;
$isAdmin = $actor['type'] === 'user' && current_user_is_admin();

$presenceMetaBySessionKey = [];
if ($isAdmin && $rows !== []) {
    $sessionKeys = [];
    foreach ($rows as $r0) {
        if ((int) ($r0['is_system'] ?? 0) === 1) {
            continue;
        }
        $ownerId0 = $r0['user_id'] !== null ? (int) $r0['user_id'] : null;
        $guestKey0 = $r0['guest_key'] !== null ? trim((string) $r0['guest_key']) : '';
        if ($ownerId0 !== null && $ownerId0 > 0) {
            $sessionKeys[] = 'u:' . $ownerId0;
        } elseif ($guestKey0 !== '') {
            $sessionKeys[] = 'g:' . $guestKey0;
        }
    }
    $sessionKeys = array_values(array_unique($sessionKeys));
    if ($sessionKeys !== []) {
        $placeholders = implode(',', array_fill(0, count($sessionKeys), '?'));
        $params = array_merge([$roomId], $sessionKeys);
        $pst = $pdo->prepare(
            'SELECT session_key, ip, geo_text
             FROM room_presence
             WHERE room_id = ? AND session_key IN (' . $placeholders . ')'
        );
        $pst->execute($params);
        $pRows = $pst->fetchAll() ?: [];
        foreach ($pRows as $pr) {
            $sk = (string) ($pr['session_key'] ?? '');
            if ($sk === '') continue;
            $ip = isset($pr['ip']) ? trim((string) $pr['ip']) : '';
            $geo = isset($pr['geo_text']) ? trim((string) $pr['geo_text']) : '';
            $presenceMetaBySessionKey[$sk] = [
                'ip' => $ip !== '' ? $ip : null,
                'geo_text' => $geo !== '' ? $geo : null,
            ];
        }
    }
}

$messages = [];
foreach ($rows as $r) {
    $isMine = false;
    if ($uid !== null && $r['user_id'] !== null && (int) $r['user_id'] === $uid) {
        $isMine = true;
    }
    if ($gk !== null && $r['guest_key'] !== null && hash_equals((string) $r['guest_key'], $gk)) {
        $isMine = true;
    }
    $kind = isset($r['content_kind']) ? (string) $r['content_kind'] : 'text';
    $attPath = isset($r['attachment_path']) ? (string) $r['attachment_path'] : '';
    $attName = isset($r['attachment_name']) ? (string) $r['attachment_name'] : '';

    $isRecalled = (int) ($r['is_recalled'] ?? 0) === 1 || $kind === 'recalled';
    $ownerId = $r['user_id'] !== null ? (int) $r['user_id'] : null;
    $canRecall = false;
    if (!$isRecalled && (int) ($r['is_system'] ?? 0) !== 1 && $uid !== null) {
        if ($isAdmin) {
            // 管理员：允许撤回所有非系统消息（包含访客消息）
            $canRecall = true;
        } elseif ($ownerId !== null && $ownerId === $uid) {
            // 自己的消息始终展示撤回入口（最终是否允许以后端接口判定）
            $canRecall = true;
        }
    }

    if ($isRecalled) {
        $kind = 'recalled';
        $r['content'] = '消息已撤回';
        $attPath = '';
        $attName = '';
    }

    $displayName = (string) ($r['display_name'] ?? '');
    if ($ownerId !== null) {
        if (!empty($room['anonymous_mode'])) {
            $displayName = anon_display_name($ownerId, $roomId, $config['anon_salt']);
        } else {
            $nick = trim((string) ($r['sender_nickname'] ?? ''));
            $uname = trim((string) ($r['sender_username'] ?? ''));
            $displayName = $nick !== '' ? $nick : ($uname !== '' ? $uname : $displayName);
        }
    }

    $senderIp = null;
    $senderGeoText = null;
    if ($isAdmin && (int) ($r['is_system'] ?? 0) !== 1) {
        $sk1 = null;
        $guestKey1 = $r['guest_key'] !== null ? trim((string) $r['guest_key']) : '';
        if ($ownerId !== null && $ownerId > 0) {
            $sk1 = 'u:' . $ownerId;
        } elseif ($guestKey1 !== '') {
            $sk1 = 'g:' . $guestKey1;
        }
        if ($sk1 !== null && isset($presenceMetaBySessionKey[$sk1])) {
            $senderIp = $presenceMetaBySessionKey[$sk1]['ip'] ?? null;
            $senderGeoText = $presenceMetaBySessionKey[$sk1]['geo_text'] ?? null;
        }
    }

    $recalledByName = null;
    if (isset($r['recalled_by_user_id']) && $r['recalled_by_user_id'] !== null) {
        $rid = (int) $r['recalled_by_user_id'];
        if ((int) ($r['recalled_by_is_admin'] ?? 0) === 1) {
            $recalledByName = '管理员';
        } elseif (!empty($room['anonymous_mode'])) {
            $recalledByName = anon_display_name($rid, $roomId, $config['anon_salt']);
        } else {
            $rawName = trim((string) ($r['recalled_by_nickname'] ?? ''));
            if ($rawName === '') {
                $rawName = trim((string) ($r['recalled_by_username'] ?? ''));
            }
            $recalledByName = $rawName !== '' ? $rawName : ('用户' . $rid);
        }
    }

    $reply = null;
    if (!empty($r['reply_to_message_id'])) {
        $replyKind = (string) ($r['reply_content_kind'] ?? 'text');
        $replyContent = (string) ($r['reply_content'] ?? '');
        $replyOwnerId = isset($r['reply_user_id']) && $r['reply_user_id'] !== null ? (int) $r['reply_user_id'] : null;
        $replyDisplayName = trim((string) ($r['reply_display_name'] ?? ''));
        if ($replyOwnerId !== null) {
            if (!empty($room['anonymous_mode'])) {
                $replyDisplayName = anon_display_name($replyOwnerId, $roomId, $config['anon_salt']);
            } else {
                $replyNick = trim((string) ($r['reply_sender_nickname'] ?? ''));
                $replyUname = trim((string) ($r['reply_sender_username'] ?? ''));
                $replyDisplayName = $replyNick !== '' ? $replyNick : ($replyUname !== '' ? $replyUname : $replyDisplayName);
            }
        }
        if ((int) ($r['reply_is_system'] ?? 0) === 1) {
            $replyDisplayName = '系统';
        }
        if ((int) ($r['reply_is_recalled'] ?? 0) === 1 || $replyKind === 'recalled') {
            $replyContent = '消息已撤回';
        } elseif ($replyKind === 'image') {
            $replyContent = '[图片]' . ($replyContent !== '' ? (' ' . html_entity_decode(strip_tags($replyContent), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) : '');
        } elseif ($replyKind === 'file') {
            $replyContent = '[文件]' . ($replyContent !== '' ? (' ' . html_entity_decode(strip_tags($replyContent), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) : '');
        } else {
            $replyContent = html_entity_decode(strip_tags($replyContent), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        if ($replyDisplayName === '') {
            $replyDisplayName = '用户';
        }
        if (trim($replyContent) === '') {
            $replyContent = '原消息';
        }
        $reply = [
            'id' => (int) $r['reply_to_message_id'],
            'username' => $replyDisplayName,
            'content' => $replyContent,
        ];
    }

    $messages[] = [
        'id' => (int) $r['id'],
        'username' => $displayName,
        'content' => $r['content'],
        'content_kind' => $kind,
        'attachment_url' => $attPath !== '' ? $attPath : null,
        'attachment_name' => $attName !== '' ? $attName : null,
        'timestamp' => (int) $r['created_at'],
        'is_mine' => $isMine,
        'is_system' => (bool) (int) $r['is_system'],
        'can_recall' => $canRecall,
        'recalled_by_user_id' => isset($r['recalled_by_user_id']) && $r['recalled_by_user_id'] !== null
            ? (int) $r['recalled_by_user_id']
            : null,
        'recalled_by_is_admin' => (int) ($r['recalled_by_is_admin'] ?? 0) === 1,
        'recalled_by_name' => $recalledByName,
        'reply' => $reply,
        'sender_ip' => $isAdmin ? $senderIp : null,
        'sender_geo_text' => $isAdmin ? $senderGeoText : null,
    ];
}

echo json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE);
