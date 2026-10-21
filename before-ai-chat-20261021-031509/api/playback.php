<?php

declare(strict_types=1);

function activity_registration(PDO $db, ?array $visitor, int $webinarId): array
{
    $registration = find_registration($db, $visitor, $webinarId);
    if (!$registration) throw new ApiError(401, 'Register for this webinar first.');
    if (!$registration['activityConsent']) throw new ApiError(403, 'Enable activity consent in your registration to save video or chat activity.');
    return $registration;
}
function valid_uuid(mixed $value): bool
{
    return is_string($value) && (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $value);
}
function bounded_number(mixed $value, float $max = 172800): float
{
    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || $value > $max)
        throw new ApiError(422, 'Invalid playback measurement.');
    return (float) $value;
}
function playback_api(PDO $db, ?array $visitor, int $webinarId, string $resource, string $method): never
{
    if ($method !== 'POST') throw new ApiError(405, 'Method not allowed.');
    require_write_access($visitor);
    rate_limit($db, 'playback', 360);
    $registration = activity_registration($db, $visitor, $webinarId);
    $data = read_json();
    $id = $data['sessionId'] ?? null;
    if (!valid_uuid($id)) throw new ApiError(422, 'Invalid viewing session ID.');
    if ($resource === 'playback_session') {
        $duration = bounded_number($data['durationSeconds'] ?? 0);
        $query = $db->prepare('SELECT id FROM webinar_assets WHERE webinar_id=? AND asset_type=\'video\' AND is_active=1');
        $query->execute([$webinarId]);
        $assetId = $query->fetchColumn();
        if (!$assetId) throw new ApiError(503, 'No video is configured for this webinar.');
        $query = $db->prepare('INSERT IGNORE INTO playback_sessions(id,registration_id,asset_id,duration_seconds) VALUES(?,?,?,?)');
        $query->execute([$id, $registration['id'], $assetId, $duration ?: null]);
        $query = $db->prepare('SELECT registration_id FROM playback_sessions WHERE id=?');
        $query->execute([$id]);
        if ((int) $query->fetchColumn() !== $registration['id']) throw new ApiError(403, 'This viewing session belongs to another registration.');
        json_response(200, ['sessionId' => $id]);
    }
    $events = $data['events'] ?? null;
    if (!is_array($events) || count($events) < 1 || count($events) > 30) throw new ApiError(422, 'Send between 1 and 30 playback events.');
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM playback_sessions WHERE id=? AND registration_id=? FOR UPDATE');
        $query->execute([$id, $registration['id']]);
        $playback = $query->fetch();
        if (!$playback) throw new ApiError(404, 'Viewing session not found.');
        $accepted = 0;
        foreach ($events as $event) {
            if (!is_array($event) || !valid_uuid($event['id'] ?? null)) throw new ApiError(422, 'Invalid playback event.');
            $type = $event['type'] ?? '';
            if (!in_array($type, ['started', 'playing', 'paused', 'resumed', 'seeked', 'progress', 'stopped', 'completed', 'session_ended'], true)) throw new ApiError(422, 'Unknown playback event type.');
            $position = bounded_number($event['positionSeconds'] ?? 0);
            $duration = bounded_number($event['durationSeconds'] ?? 0);
            $claimedWatch = bounded_number($event['watchedDeltaSeconds'] ?? 0, 30);
            try {
                $occurred = new DateTimeImmutable(is_string($event['occurredAt'] ?? null) ? $event['occurredAt'] : 'invalid');
            } catch (Throwable) {
                throw new ApiError(422, 'Invalid playback timestamp.');
            }
            $unix = (float) $occurred->format('U.u');
            if ($unix > microtime(true) + 60 || $unix < microtime(true) - 86400) throw new ApiError(422, 'Playback timestamp is outside the supported retry window.');
            $previousUnix = $playback['last_event_at'] ? (float) (new DateTimeImmutable($playback['last_event_at'], new DateTimeZone('UTC')))->format('U.u') : $unix;
            $watch = min($claimedWatch, max(0, $unix - $previousUnix));
            $stamp = $occurred->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $previous = isset($event['previousPositionSeconds']) ? bounded_number($event['previousPositionSeconds']) : null;
            $newPosition = isset($event['newPositionSeconds']) ? bounded_number($event['newPositionSeconds']) : null;
            $duration = $duration ?: (float) ($playback['duration_seconds'] ?? 0);
            if ($duration > 0) $position = min($position, $duration);
            $progress = $duration > 0 ? min(100, $position / $duration * 100) : 0;
            $query = $db->prepare('INSERT IGNORE INTO playback_events(event_uuid,playback_session_id,event_type,position_seconds,previous_position_seconds,new_position_seconds,progress_percent,occurred_at,watched_delta_seconds,metadata) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $query->execute([$event['id'], $id, $type, $position, $previous, $newPosition, $progress, $stamp, $watch, json_encode(['playbackRate' => $event['playbackRate'] ?? 1])]);
            if (!$query->rowCount()) continue; // Idempotency: retried events never add watch time twice.
            $accepted++;
            $playback['watched_seconds'] = (float) $playback['watched_seconds'] + $watch;
            $playback['duration_seconds'] = $duration ?: null;
            $playback['completion_percent'] = max((float) $playback['completion_percent'], $progress);
            if ($unix >= $previousUnix) {
                $playback['last_event_at'] = $stamp;
                $playback['last_position_seconds'] = $position;
                if ($type === 'completed') $playback['status'] = 'completed';
                elseif ($playback['status'] !== 'completed') {
                    $playback['status'] = match ($type) {
                        'started', 'playing', 'resumed' => 'playing',
                        'paused' => 'paused',
                        'stopped', 'session_ended' => 'ended',
                        default => $playback['status']
                    };
                }
                if (in_array($type, ['completed', 'stopped', 'session_ended'], true)) $playback['ended_at'] = $stamp;
            }
        }
        $query = $db->prepare('UPDATE playback_sessions SET last_position_seconds=?,watched_seconds=?,completion_percent=?,status=?,duration_seconds=?,last_event_at=?,ended_at=? WHERE id=?');
        $query->execute([$playback['last_position_seconds'], $playback['watched_seconds'], $playback['completion_percent'], $playback['status'], $playback['duration_seconds'], $playback['last_event_at'], $playback['ended_at'], $id]);
        if ($accepted > 0) {
            $query = $db->prepare('UPDATE lead_webinars SET updated_at=UTC_TIMESTAMP(6) WHERE id=?');
            $query->execute([$registration['id']]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    json_response(200, ['accepted' => $accepted]);
}
