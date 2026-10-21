<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

final class ApiError extends RuntimeException
{
    public function __construct(public int $status, string $message, public array $fields = [])
    { parent::__construct($message); }
}

function json_response(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function app_base(): string
{
    $base = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'])));
    return $base === '/' || $base === '.' ? '' : rtrim($base, '/');
}

function api_url(string $resource, int $webinarId): string
{
    return app_base() . '/api/index.php?resource=' . rawurlencode($resource) . '&webinar_id=' . $webinarId;
}

function read_json(): array
{
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json'))
        throw new ApiError(415, 'Send registration data as JSON.');
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($raw) > 16384) throw new ApiError(413, 'Registration data is too large.');
    try { $value = json_decode($raw, false, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ApiError(400, 'Invalid registration data.'); }
    if (!$value instanceof stdClass) throw new ApiError(400, 'Invalid registration data.');
    return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
}

function visitor_session(PDO $db, array $config, bool $create = false): ?array
{
    $token = $_COOKIE['webinar_php_session'] ?? '';
    if (preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $hash = hash('sha256', $token);
        $query = $db->prepare('SELECT * FROM visitor_sessions WHERE token_hash=? AND expires_at>UTC_TIMESTAMP(6)');
        $query->execute([$hash]);
        if ($row = $query->fetch()) return $row;
    }
    if (!$create) return null;
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $csrf = bin2hex(random_bytes(32));
    $expiry = time() + (int) $config['session_days'] * 86400;
    $query = $db->prepare('INSERT INTO visitor_sessions(token_hash,csrf_token,expires_at) VALUES(?,?,?)');
    $query->execute([$hash, $csrf, gmdate('Y-m-d H:i:s', $expiry)]);
    setcookie('webinar_php_session', $token, [
        'expires' => $expiry, 'path' => app_base() . '/',
        'secure' => (bool) $config['cookie_secure'], 'httponly' => true, 'samesite' => 'Lax',
    ]);
    return ['token_hash' => $hash, 'csrf_token' => $csrf];
}

function require_write_access(?array $session): void
{
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $expectedOrigin = $scheme . '://' . $_SERVER['HTTP_HOST'];
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $expectedOrigin || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site')
        throw new ApiError(403, 'Open this form from the webinar website to continue.');
    if (!$session || !hash_equals($session['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))
        throw new ApiError(403, 'Your session expired. Reload the page and try again.');
}

function rate_limit(PDO $db, string $scope = 'registration', int $limit = 30): void
{
    $bucket = hash('sha256', $scope . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $now = gmdate('Y-m-d H:i:s');
    $cutoff = gmdate('Y-m-d H:i:s', time() - 60);
    $query = $db->prepare('INSERT INTO api_rate_limits(bucket_hash,window_started_at,request_count) VALUES(?,?,1)
      ON DUPLICATE KEY UPDATE request_count=IF(window_started_at<?,1,request_count+1),
      window_started_at=IF(window_started_at<?,VALUES(window_started_at),window_started_at)');
    $query->execute([$bucket, $now, $cutoff, $cutoff]);
    $query = $db->prepare('SELECT request_count FROM api_rate_limits WHERE bucket_hash=?');
    $query->execute([$bucket]);
    if ((int) $query->fetchColumn() > $limit) {
        header('Retry-After: 60');
        throw new ApiError(429, 'Too many attempts. Please wait a minute and try again.');
    }
}

function cleanup_expired_data(PDO $db, array $config): void
{
    $cutoff = gmdate('Y-m-d H:i:s', time() - (int) $config['retention_days'] * 86400);
    $query = $db->prepare('DELETE FROM lead_webinars WHERE updated_at<?');
    $query->execute([$cutoff]);
    $db->exec('DELETE FROM visitor_sessions WHERE expires_at<UTC_TIMESTAMP(6)');
    $db->exec('DELETE FROM admin_sessions WHERE expires_at<UTC_TIMESTAMP(6)');
    $db->exec('DELETE FROM api_rate_limits WHERE window_started_at<UTC_TIMESTAMP(6)-INTERVAL 1 DAY');
    $db->exec('DELETE FROM leads WHERE NOT EXISTS (SELECT 1 FROM lead_webinars WHERE lead_id=leads.id)');
}
