<?php
declare(strict_types=1);

function stream_video(PDO $db, array $config, ?array $session, int $webinarId): never
{
    if (!find_registration($db,$session,$webinarId)) throw new ApiError(401, 'Register for this webinar before watching.');
    $query = $db->prepare("SELECT storage_path,mime_type FROM webinar_assets WHERE webinar_id=? AND asset_type='video' AND is_active=1");
    $query->execute([$webinarId]);
    $asset = $query->fetch();
    $root = realpath($config['video_directory']);
    $file = $asset && $root ? realpath($root . DIRECTORY_SEPARATOR . $asset['storage_path']) : false;
    if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR) || !is_file($file))
        throw new ApiError(503, 'The video has not been configured yet.');
    $size = filesize($file);
    $start = 0; $end = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    $invalid = false;
    if ($range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $matches) || ($matches[1] === '' && $matches[2] === '')) $invalid = true;
        else {
            $first = $matches[1] === '' ? null : filter_var($matches[1], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
            $last = $matches[2] === '' ? null : filter_var($matches[2], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
            if ($first === false || $last === false) $invalid = true;
            elseif ($first === null) $start = max(0, $size - $last);
            else { $start = $first; if ($last !== null) $end = min($last, $size - 1); }
            if ($start >= $size || $start > $end) $invalid = true;
        }
    }
    if ($invalid) {
        header("Content-Range: bytes */$size");
        json_response(416, ['message'=>'Invalid video range.']);
    }
    $handle = fopen($file, 'rb');
    if (!$handle) throw new ApiError(503, 'The video is temporarily unavailable.');
    while (ob_get_level() > 0) ob_end_clean();
    ini_set('zlib.output_compression', '0');
    set_time_limit(0);
    http_response_code($range === '' ? 200 : 206);
    header('Content-Type: video/mp4');
    header("Content-Security-Policy: default-src 'none'; media-src 'self'; frame-ancestors 'none'");
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . ($end - $start + 1));
    if ($range !== '') header("Content-Range: bytes $start-$end/$size");
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        fseek($handle, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($handle) && !connection_aborted()) {
            $chunk = fread($handle, min(65536, $remaining));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
    }
    fclose($handle);
    exit;
}
