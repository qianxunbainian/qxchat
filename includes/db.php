<?php
declare(strict_types=1);

/**
 * 数据库连接与迁移
 *
 * @copyright 千寻百念工作室
 */

/**
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $db = $config['db'];
    $pdo = new PDO($db['dsn'], $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    apply_mysql_migrations($pdo);
    return $pdo;
}

function apply_mysql_migrations(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $schema = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!$schema) {
            return;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([(string) $schema, 'rooms', 'room_password_hash']);
        if ((int) $st->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE rooms ADD COLUMN room_password_hash VARCHAR(255) NULL DEFAULT NULL');
        }
        $st->execute([(string) $schema, 'rooms', 'broadcast_text']);
        if ((int) $st->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE rooms ADD COLUMN broadcast_text VARCHAR(200) NULL DEFAULT NULL AFTER slug');
        }
        $st->execute([(string) $schema, 'users', 'is_admin']);
        if ((int) $st->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash'
            );
            $pdo->exec(
                'UPDATE users u
                 INNER JOIN (SELECT MIN(id) AS id FROM users) first ON u.id = first.id
                 SET u.is_admin = 1
                 WHERE (SELECT COUNT(*) FROM users WHERE is_admin = 1) = 0'
            );
        }
        foreach (
            [
                ['nickname', 'ALTER TABLE users ADD COLUMN nickname VARCHAR(50) NOT NULL DEFAULT "" AFTER username'],
                ['is_frozen', 'ALTER TABLE users ADD COLUMN is_frozen TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin'],
                ['is_muted', 'ALTER TABLE users ADD COLUMN is_muted TINYINT(1) NOT NULL DEFAULT 0 AFTER is_frozen'],
                ['can_upload_file', 'ALTER TABLE users ADD COLUMN can_upload_file TINYINT(1) NOT NULL DEFAULT 1 AFTER is_muted'],
                ['is_approved', 'ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0 AFTER can_upload_file'],
            ] as $colSpec
        ) {
            $st->execute([(string) $schema, 'users', $colSpec[0]]);
            if ((int) $st->fetchColumn() === 0) {
                $pdo->exec($colSpec[1]);
                if ($colSpec[0] === 'nickname') {
                    $pdo->exec('UPDATE users SET nickname = username WHERE nickname = "" OR nickname IS NULL');
                }
                if ($colSpec[0] === 'is_approved') {
                    $pdo->exec('UPDATE users SET is_approved = 1 WHERE is_admin = 1');
                }
            }
        }

        foreach (
            [
                ['reply_to_message_id', 'ALTER TABLE messages ADD COLUMN reply_to_message_id INT UNSIGNED NULL DEFAULT NULL AFTER attachment_name'],
                ['is_recalled', 'ALTER TABLE messages ADD COLUMN is_recalled TINYINT(1) NOT NULL DEFAULT 0 AFTER attachment_name'],
                ['recalled_at', 'ALTER TABLE messages ADD COLUMN recalled_at INT UNSIGNED NULL DEFAULT NULL AFTER is_recalled'],
                ['recalled_by_user_id', 'ALTER TABLE messages ADD COLUMN recalled_by_user_id INT UNSIGNED NULL DEFAULT NULL AFTER recalled_at'],
            ] as $colSpec
        ) {
            $st->execute([(string) $schema, 'messages', $colSpec[0]]);
            if ((int) $st->fetchColumn() === 0) {
                $pdo->exec($colSpec[1]);
            }
        }

        foreach (
            [
                ['ip', 'ALTER TABLE room_presence ADD COLUMN ip VARCHAR(45) NULL DEFAULT NULL AFTER display_label'],
                ['geo_text', 'ALTER TABLE room_presence ADD COLUMN geo_text VARCHAR(120) NULL DEFAULT NULL AFTER ip'],
                ['geo_updated_at', 'ALTER TABLE room_presence ADD COLUMN geo_updated_at INT UNSIGNED NULL DEFAULT NULL AFTER geo_text'],
            ] as $colSpec
        ) {
            $st->execute([(string) $schema, 'room_presence', $colSpec[0]]);
            if ((int) $st->fetchColumn() === 0) {
                $pdo->exec($colSpec[1]);
            }
        }
    } catch (Throwable $e) {
        // 非 MySQL 或无权限时忽略
    }
}
