<?php

declare(strict_types=1);

function map_webinar(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'campaignId' => $row['campaign_code'],
        'slug' => $row['slug'],
        'title' => $row['title'],
        'description' => $row['description'],
        'category' => $row['category'],
        'image' => $row['image_url'] ?? '',
        'webinarLink' => $row['publisher_url'] ?? '',
        'date' => $row['published_label'] ?? '',
        'videoLabel' => $row['video_label'] ?? 'Session recording',
        'videoIsDemo' => (bool) ($row['video_is_demo'] ?? false),
        'transcriptStatus' => $row['transcript_status'],
        'durationSeconds' => $row['duration_seconds'] === null ? null : (int) $row['duration_seconds'],
    ];
}

function webinar_select(): string
{
    return "SELECT w.*, a.label AS video_label, a.is_demo AS video_is_demo FROM webinars w
      LEFT JOIN webinar_assets a ON a.webinar_id=w.id AND a.asset_type='video' AND a.is_active=1 ";
}

function active_webinar(PDO $db, int $id): array
{
    $query = $db->prepare(webinar_select() . 'WHERE w.id=? AND w.is_active=1');
    $query->execute([$id]);
    $row = $query->fetch();
    if (!$row) throw new ApiError(404, 'Webinar not found.');
    return map_webinar($row);
}

function list_webinars(PDO $db): never
{
    $rows = $db->query(webinar_select() . 'WHERE w.is_active=1 ORDER BY w.id')->fetchAll();
    json_response(200, ['webinars' => array_map('map_webinar', $rows)]);
}

function show_webinar(PDO $db): never
{
    $slug = $_GET['slug'] ?? null;
    if ($slug !== null) {
        if (!is_string($slug) || strlen($slug) > 160) throw new ApiError(400, 'Invalid webinar slug.');
        $query = $db->prepare(webinar_select() . 'WHERE w.slug=? AND w.is_active=1');
        $query->execute([$slug]);
        $row = $query->fetch();
        if (!$row) throw new ApiError(404, 'Webinar not found.');
        $selected = map_webinar($row);
    } else {
        $id = filter_var($_GET['webinar_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) throw new ApiError(400, 'Invalid webinar ID.');
        $selected = active_webinar($db, $id);
    }
    $query = $db->prepare(webinar_select() . 'WHERE w.is_active=1 AND w.id<>? ORDER BY (w.category=?) DESC, w.id LIMIT 3');
    $query->execute([$selected['id'], $selected['category']]);
    json_response(200, ['webinar' => $selected, 'relatedWebinars' => array_map('map_webinar', $query->fetchAll())]);
}
