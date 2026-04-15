<?php
declare(strict_types=1);

/**
 * 保存房间聊天上传文件（图片或附件）。
 * 类型由 finfo 检测 MIME，text/plain 时可根据原文件后缀细分（如 .md、.csv）。
 *
 * @param array<string, mixed> $file $_FILES['file'] 单项
 * @return array{kind: string, relative_path: string, original_name: string}|null
 */
function chat_save_room_upload(int $roomId, array $file): ?array
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    $maxImage = 8 * 1024 * 1024;
    $maxFile = 32 * 1024 * 1024;
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return null;
    }

    $tmp = (string) $file['tmp_name'];
    if (!is_readable($tmp)) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    if ($mime === false) {
        return null;
    }

    $imageMimes = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/gif' => '.gif',
        'image/webp' => '.webp',
        'image/bmp' => '.bmp',
        'image/x-ms-bmp' => '.bmp',
        'image/tiff' => '.tiff',
        'image/tif' => '.tif',
        'image/avif' => '.avif',
    ];

    $fileMimes = [
        'application/pdf' => '.pdf',
        'application/zip' => '.zip',
        'application/x-zip-compressed' => '.zip',
        'text/plain' => '.txt',
        'application/msword' => '.doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
        'application/vnd.ms-excel' => '.xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
        'application/vnd.ms-powerpoint' => '.ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => '.pptx',
        'application/x-7z-compressed' => '.7z',
        'application/x-rar-compressed' => '.rar',
        'application/vnd.rar' => '.rar',
        'text/csv' => '.csv',
        'text/comma-separated-values' => '.csv',
        'text/markdown' => '.md',
        'text/x-markdown' => '.md',
        'application/rtf' => '.rtf',
        'text/rtf' => '.rtf',
        'text/html' => '.html',
        'application/xhtml+xml' => '.xhtml',
        'application/json' => '.json',
        'text/json' => '.json',
        'text/xml' => '.xml',
        'application/xml' => '.xml',
        'application/vnd.oasis.opendocument.text' => '.odt',
        'application/vnd.oasis.opendocument.spreadsheet' => '.ods',
        'application/vnd.oasis.opendocument.presentation' => '.odp',
        'application/epub+zip' => '.epub',
        'audio/mpeg' => '.mp3',
        'audio/mp3' => '.mp3',
        'audio/mp4' => '.m4a',
        'audio/x-m4a' => '.m4a',
        'audio/wav' => '.wav',
        'audio/x-wav' => '.wav',
        'audio/ogg' => '.ogg',
        'audio/webm' => '.weba',
        'audio/flac' => '.flac',
        'audio/aac' => '.aac',
        'video/mp4' => '.mp4',
        'video/webm' => '.webm',
        'video/quicktime' => '.mov',
        'video/x-msvideo' => '.avi',
        'video/x-matroska' => '.mkv',
        'application/x-tar' => '.tar',
        'application/gzip' => '.gz',
        'application/x-gzip' => '.gz',
        'application/x-bzip2' => '.bz2',
        'application/x-xz' => '.xz',
        'image/heic' => '.heic',
        'image/heif' => '.heif',
    ];

    /** text/plain 时按客户端文件名后缀允许的扩展（小写，无点） */
    $plainExtMap = [
        'md' => '.md',
        'markdown' => '.md',
        'csv' => '.csv',
        'tsv' => '.tsv',
        'json' => '.json',
        'xml' => '.xml',
        'log' => '.log',
        'ini' => '.ini',
        'yml' => '.yml',
        'yaml' => '.yaml',
        'toml' => '.toml',
        'cfg' => '.cfg',
        'txt' => '.txt',
    ];

    $kind = '';
    $ext = '';
    $origForExt = (string) ($file['name'] ?? '');
    $rawExt = strtolower(pathinfo($origForExt, PATHINFO_EXTENSION));

    if (isset($imageMimes[$mime])) {
        if ($size > $maxImage) {
            return null;
        }
        $kind = 'image';
        $ext = $imageMimes[$mime];
    } elseif ($mime === 'text/plain' && $rawExt !== '' && preg_match('/^[a-z0-9]{1,12}$/', $rawExt) && isset($plainExtMap[$rawExt])) {
        if ($size > $maxFile) {
            return null;
        }
        $kind = 'file';
        $ext = $plainExtMap[$rawExt];
    } elseif (isset($fileMimes[$mime])) {
        if ($size > $maxFile) {
            return null;
        }
        $kind = 'file';
        $ext = $fileMimes[$mime];
    } else {
        return null;
    }

    $base = dirname(__DIR__) . '/uploads/room_' . $roomId;
    if (!is_dir($base) && !mkdir($base, 0755, true) && !is_dir($base)) {
        return null;
    }

    $name = bin2hex(random_bytes(16)) . $ext;
    $dest = $base . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        return null;
    }

    $relative = 'uploads/room_' . $roomId . '/' . $name;
    $orig = (string) ($file['name'] ?? 'file');
    $orig = preg_replace('/[\x00-\x1f\x7f]/', '', $orig);
    if (strlen($orig) > 200) {
        $orig = substr($orig, 0, 200);
    }
    if ($orig === '') {
        $orig = 'file' . $ext;
    }

    return [
        'kind' => $kind,
        'relative_path' => $relative,
        'original_name' => $orig,
    ];
}

/**
 * 删除某房间消息关联的上传文件（应在 DELETE FROM messages 之前调用）。
 */
function chat_delete_attachments_for_room(PDO $pdo, int $roomId): void
{
    $st = $pdo->prepare(
        'SELECT attachment_path FROM messages WHERE room_id = ? AND attachment_path IS NOT NULL AND TRIM(attachment_path) != \'\''
    );
    $st->execute([$roomId]);
    $projectRoot = realpath(dirname(__DIR__));
    if ($projectRoot === false) {
        return;
    }
    $expectedPrefix = 'uploads/room_' . $roomId . '/';
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $relRaw) {
        $rel = str_replace('\\', '/', (string) $relRaw);
        $rel = ltrim($rel, '/');
        if (strpos($rel, '..') !== false || strpos($rel, $expectedPrefix) !== 0) {
            continue;
        }
        $full = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

/**
 * 删除单条消息关联的上传文件（撤回时用）。
 */
function chat_delete_attachment_for_message(PDO $pdo, int $messageId, int $roomId): void
{
    $st = $pdo->prepare(
        'SELECT attachment_path FROM messages WHERE id = ? AND room_id = ? LIMIT 1'
    );
    $st->execute([$messageId, $roomId]);
    $relRaw = $st->fetchColumn();
    if (!is_string($relRaw) || trim($relRaw) === '') {
        return;
    }
    $projectRoot = realpath(dirname(__DIR__));
    if ($projectRoot === false) {
        return;
    }
    $expectedPrefix = 'uploads/room_' . $roomId . '/';
    $rel = str_replace('\\', '/', (string) $relRaw);
    $rel = ltrim($rel, '/');
    if (strpos($rel, '..') !== false || strpos($rel, $expectedPrefix) !== 0) {
        return;
    }
    $full = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (is_file($full)) {
        @unlink($full);
    }
}
