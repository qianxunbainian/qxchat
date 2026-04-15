<?php
declare(strict_types=1);

/**
 * 房间密码会话与校验
 *
 * @copyright 千寻百念工作室
 */

function room_requires_password(array $room): bool
{
    return isset($room['room_password_hash'])
        && is_string($room['room_password_hash'])
        && $room['room_password_hash'] !== '';
}

function room_password_unlocked(int $roomId): bool
{
    $k = (string) $roomId;
    return !empty($_SESSION['room_unlock'][$k]);
}

function unlock_room_password(int $roomId): void
{
    if (!isset($_SESSION['room_unlock']) || !is_array($_SESSION['room_unlock'])) {
        $_SESSION['room_unlock'] = [];
    }
    $_SESSION['room_unlock'][(string) $roomId] = true;
}

function verify_room_password(array $room, string $plain): bool
{
    if (!room_requires_password($room)) {
        return true;
    }
    return password_verify($plain, (string) $room['room_password_hash']);
}

function assert_room_unlocked_json(array $room, int $roomId): void
{
    if (room_requires_password($room) && !room_password_unlocked($roomId)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => '需要房间密码',
            'need_room_password' => true,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
