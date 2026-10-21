<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/webinars.php';
require_once __DIR__ . '/registration.php';
require_once __DIR__ . '/video.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/playback.php';
require_once __DIR__ . '/transcripts.php';
require_once __DIR__ . '/assistant.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/dashboard.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

try {
    $config = require dirname(__DIR__) . '/config/config.php';
    $host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if (!in_array($host, $config['allowed_hosts'], true)) throw new ApiError(403, 'This host is not configured.');
    try { $db = database($config); }
    catch (PDOException $error) {
        error_log('Webinar database connection failed: ' . $error->getCode());
        throw new ApiError(503, 'Database unavailable. Import the SQL files and check your PHP database configuration.');
    }
    $resource = $_GET['resource'] ?? 'webinars';
    $method = $_SERVER['REQUEST_METHOD'];
    if (!is_string($resource)) throw new ApiError(400, 'Invalid API resource.');
    if (str_starts_with($resource,'admin_')) admin_auth($db,$config,$resource,$method);
    if ($resource==='transcript_manage') transcript_manage($db,$config,$method);
    if (in_array($resource,['dashboard','dashboard_export','dashboard_detail'],true)) dashboard_api($db,$resource,$method);
    if ($resource === 'webinars' && $method === 'GET') list_webinars($db);
    if ($resource === 'webinar' && $method === 'GET') show_webinar($db);
    if (!in_array($resource, ['registration','video','playback_session','playback_events','chat','transcript','insights'], true)) throw new ApiError(404, 'API route not found.');
    $id = filter_var($_GET['webinar_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$id) throw new ApiError(400, 'Invalid webinar ID.');
    active_webinar($db, $id);
    cleanup_expired_data($db, $config);
    $session = visitor_session($db, $config, $resource === 'registration' && $method === 'GET');
    if (in_array($resource,['playback_session','playback_events'],true)) playback_api($db,$session,$id,$resource,$method);
    if ($resource==='chat') chat_api($db,$config,$session,$id,$method);
    if ($resource==='transcript') transcript_api($db,$session,$id,$method);
    if ($resource==='insights') insights_api($db,$config,$session,$id,$method);
    if ($resource === 'video' && in_array($method, ['GET','HEAD'], true)) stream_video($db,$config,$session,$id);
    if ($resource === 'registration') {
        if ($method === 'GET') json_response(200, ['registration'=>find_registration($db,$session,$id), 'csrfToken'=>$session['csrf_token']]);
        if (in_array($method, ['POST','DELETE'], true)) {
            require_write_access($session);
            rate_limit($db);
            if ($method === 'POST') save_registration($db,$session,$id,read_json());
            remove_registration($db,$session,$id);
        }
    }
    throw new ApiError(405, 'Method not allowed.');
} catch (ApiError $error) {
    json_response($error->status, ['message'=>$error->getMessage()] + ($error->fields ? ['errors'=>$error->fields] : []));
} catch (Throwable $error) {
    error_log('Webinar API error: ' . get_class($error) . ' ' . $error->getCode());
    json_response(500, ['message'=>'The backend could not complete this request. Check the PHP error log and SQL setup.']);
}
