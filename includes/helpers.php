<?php
declare(strict_types=1);

/** 版权署名：千寻百念工作室 */
const STUDIO_COPYRIGHT = '千寻百念工作室';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 输出页脚版权（HTML，已转义） */
function studio_render_copyright(): void
{
    echo '<footer class="site-studio-copy" role="contentinfo"><span>© ' . h(STUDIO_COPYRIGHT) . '</span></footer>';
}

/** 源码中 HTML 注释版版权 */
function studio_copyright_html_comment(): void
{
    echo '<!-- 版权：' . h(STUDIO_COPYRIGHT) . ' -->' . "\n";
}

/**
 * 获取客户端 IP（在存在反代时尽量取 XFF 第一个；可能被伪造，仅用于展示参考）
 */
function client_ip(): string
{
    $xff = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim((string) $_SERVER['HTTP_X_FORWARDED_FOR']) : '';
    if ($xff !== '') {
        $parts = array_map('trim', explode(',', $xff));
        if ($parts !== [] && $parts[0] !== '') {
            return $parts[0];
        }
    }
    $real = isset($_SERVER['HTTP_X_REAL_IP']) ? trim((string) $_SERVER['HTTP_X_REAL_IP']) : '';
    if ($real !== '') {
        return $real;
    }
    return isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
}

function ip_is_private_or_reserved(string $ip): bool
{
    if ($ip === '') return true;
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    return filter_var($ip, FILTER_VALIDATE_IP, $flags) === false;
}

/**
 * 基于公网 IP 的粗略定位（不保证可用；失败返回 null）
 *
 * 返回示例：["continent"=>"亚洲","country"=>"中国","region"=>"江苏","city"=>"南京","isp"=>"..."]
 *
 * @return array{continent?:string,country?:string,region?:string,city?:string,isp?:string}|null
 */
function geo_lookup_ip(string $ip): ?array
{
    if (ip_is_private_or_reserved($ip)) {
        return null;
    }
    // ypsou 定位接口（按用户指定）
    // 示例：{"code":200,"message":"查询成功","data":{"ip":"...","region":"北美洲|美国|Virginia|Ashburn||亚马逊|..."}}
    $url = 'https://ip.ypsou.com/api/query?ip=' . rawurlencode($ip);
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 1.8,
            'header' => "User-Agent: PHPChatRoom\r\n",
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }
    $j = json_decode($raw, true);
    if (!is_array($j) || (int) ($j['code'] ?? 0) !== 200) {
        return null;
    }
    $data = $j['data'] ?? null;
    if (!is_array($data)) {
        return null;
    }
    $regionRaw = isset($data['region']) ? trim((string) $data['region']) : '';
    if ($regionRaw === '') {
        return null;
    }
    $parts = array_map('trim', explode('|', $regionRaw));
    // region 字段结构：大洲|国家|省/州|城市||运营商|...
    $continent = $parts[0] ?? '';
    $country = $parts[1] ?? '';
    $region = $parts[2] ?? '';
    $city = $parts[3] ?? '';
    $isp = $parts[5] ?? '';
    return [
        'continent' => $continent,
        'country' => $country,
        'region' => $region,
        'city' => $city,
        'isp' => $isp,
    ];
}

function current_user_is_admin(): bool
{
    return !empty($_SESSION['user_id']) && (int) ($_SESSION['is_admin'] ?? 0) === 1;
}

function current_user_is_approved(): bool
{
    return !empty($_SESSION['user_id']) && (int) ($_SESSION['is_approved'] ?? 0) === 1;
}

/**
 * 从数据库刷新当前登录用户的会话字段（用户名、管理员、禁言、发文件等），并处理账号冻结。
 */
function enforce_and_refresh_logged_in_user(PDO $pdo): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $uid = (int) $_SESSION['user_id'];
    $st = $pdo->prepare(
        'SELECT id, username, nickname, is_admin, is_frozen, is_muted, can_upload_file, is_approved FROM users WHERE id = ? LIMIT 1'
    );
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row || (int) ($row['is_frozen'] ?? 0) === 1) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        header('Location: login.php?frozen=1');
        exit;
    }
    $_SESSION['username'] = (string) $row['username'];
    $_SESSION['nickname'] = (string) ($row['nickname'] ?? '');
    $_SESSION['is_admin'] = (int) ($row['is_admin'] ?? 0) === 1 ? 1 : 0;
    $_SESSION['is_muted'] = (int) ($row['is_muted'] ?? 0) === 1 ? 1 : 0;
    $_SESSION['can_upload_file'] = (int) ($row['can_upload_file'] ?? 1) === 1 ? 1 : 0;
    $_SESSION['is_approved'] = (int) ($row['is_approved'] ?? 0) === 1 ? 1 : 0;
}

/**
 * 登录用户在库内的限制标记；访客返回 null。
 *
 * @return array{is_frozen:int,is_muted:int,can_upload_file:int}|null
 */
function chat_user_account_flags(PDO $pdo, array $actor): ?array
{
    if ($actor['type'] !== 'user') {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT is_frozen, is_muted, can_upload_file FROM users WHERE id = ? LIMIT 1'
    );
    $st->execute([(int) $actor['user_id']]);
    $row = $st->fetch();
    if (!$row) {
        return ['is_frozen' => 1, 'is_muted' => 1, 'can_upload_file' => 0];
    }

    return [
        'is_frozen' => (int) ($row['is_frozen'] ?? 0),
        'is_muted' => (int) ($row['is_muted'] ?? 0),
        'can_upload_file' => (int) ($row['can_upload_file'] ?? 1),
    ];
}

/**
 * @return array<string, mixed>|null 若应中断 API 并返回 JSON，则返回载荷
 */
function api_json_if_user_frozen(PDO $pdo, array $actor): ?array
{
    if ($actor['type'] === 'guest') {
        return null;
    }
    $f = chat_user_account_flags($pdo, $actor);
    if ($f === null || $f['is_frozen'] === 1) {
        return ['success' => false, 'error' => '账号已被冻结', 'account_frozen' => true];
    }

    return null;
}

function anon_display_name(int $userId, int $roomId, string $salt): string
{
    $hex = substr(hash('sha256', $salt . '|' . $roomId . '|' . $userId), 0, 6);
    return '匿名' . $hex;
}

function guest_display_name(string $nickname, string $guestToken): string
{
    $nick = trim($nickname);
    if ($nick === '') {
        $nick = '访客';
    }
    $short = strtoupper(substr(hash('sha256', $guestToken), 0, 4));
    return $nick . '#' . $short;
}

function session_key_for_user(int $userId): string
{
    return 'u:' . $userId;
}

function session_key_for_guest(string $guestToken): string
{
    return 'g:' . $guestToken;
}

/**
 * @return array<string, mixed>|null
 */
function fetch_room(PDO $pdo, int $roomId): ?array
{
    $st = $pdo->prepare(
        'SELECT id, name, slug, broadcast_text, anonymous_mode, guest_allowed, room_password_hash
         FROM rooms WHERE id = ? LIMIT 1'
    );
    $st->execute([$roomId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * @return array{type:string,user_id?:int,username?:string,nickname?:string,guest_token?:string}|null
 */
function chat_actor_for_room(int $roomId): ?array
{
    if (!empty($_SESSION['user_id'])) {
        return [
            'type' => 'user',
            'user_id' => (int) $_SESSION['user_id'],
            'username' => (string) ($_SESSION['username'] ?? ''),
            'nickname' => (string) ($_SESSION['nickname'] ?? ''),
        ];
    }
    $g = $_SESSION['guest_room'] ?? null;
    if (is_array($g)
        && isset($g['room_id'], $g['token'], $g['nickname'])
        && (int) $g['room_id'] === $roomId
    ) {
        return [
            'type' => 'guest',
            'guest_token' => (string) $g['token'],
            'nickname' => (string) $g['nickname'],
        ];
    }
    return null;
}

function can_access_room(array $room, ?array $actor): bool
{
    if ($actor === null) {
        return false;
    }
    if ($actor['type'] === 'user') {
        return true;
    }
    return !empty($room['guest_allowed']);
}

function display_label_for_actor(PDO $pdo, array $room, array $actor, string $anonSalt): string
{
    $rid = (int) $room['id'];
    if ($actor['type'] === 'guest') {
        return guest_display_name($actor['nickname'], $actor['guest_token']);
    }
    $uid = (int) $actor['user_id'];
    if (!empty($room['anonymous_mode'])) {
        return anon_display_name($uid, $rid, $anonSalt);
    }
    $name = trim((string) ($actor['nickname'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($actor['username'] ?? ''));
    }
    return $name !== '' ? $name : ('用户' . $uid);
}

function presence_session_key(array $actor): string
{
    if ($actor['type'] === 'guest') {
        return session_key_for_guest($actor['guest_token']);
    }
    return session_key_for_user((int) $actor['user_id']);
}

function touch_room_presence(PDO $pdo, int $roomId, string $sessionKey, string $displayLabel): void
{
    $now = time();
    $ip = client_ip();
    $sql = 'INSERT INTO room_presence (room_id, session_key, display_label, ip, last_active)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE display_label = VALUES(display_label), ip = VALUES(ip), last_active = VALUES(last_active)';
    $st = $pdo->prepare($sql);
    $st->execute([$roomId, $sessionKey, $displayLabel, $ip !== '' ? $ip : null, $now]);
}

function prune_room_presence(PDO $pdo, int $roomId, int $timeoutSeconds): void
{
    $cut = time() - $timeoutSeconds;
    $st = $pdo->prepare('DELETE FROM room_presence WHERE room_id = ? AND last_active < ?');
    $st->execute([$roomId, $cut]);
}

function sanitize_nickname(string $n): string
{
    $n = preg_replace('/[\x00-\x1F\x7F]/u', '', $n);
    if (function_exists('mb_substr')) {
        return mb_substr(trim($n), 0, 20, 'UTF-8');
    }
    return substr(trim($n), 0, 20);
}

function unique_room_slug(PDO $pdo, string $name): string
{
    $base = preg_replace('/\s+/u', '-', trim($name));
    $base = preg_replace('/[^\p{L}\p{N}\-_]+/u', '', $base);
    if (function_exists('mb_strtolower')) {
        $base = mb_strtolower($base, 'UTF-8');
    } else {
        $base = strtolower($base);
    }
    if ($base === '') {
        $base = 'room';
    }
    if (function_exists('mb_substr')) {
        $base = mb_substr($base, 0, 40, 'UTF-8');
    } else {
        $base = substr($base, 0, 40);
    }
    $candidate = $base;
    for ($i = 0; $i < 20; $i++) {
        $st = $pdo->prepare('SELECT 1 FROM rooms WHERE slug = ? LIMIT 1');
        $st->execute([$candidate]);
        if (!$st->fetch()) {
            return $candidate;
        }
        $candidate = $base . '-' . bin2hex(random_bytes(2));
    }
    return 'room-' . bin2hex(random_bytes(8));
}
