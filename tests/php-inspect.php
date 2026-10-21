<?php
declare(strict_types=1);
// Read-only test helper, CLI only. Never exposed through Apache.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/api/db.php';
$config = require dirname(__DIR__) . '/config/config.php';
$db = database($config);
$email = $argv[1] ?? '';
$query = $db->prepare('SELECT id FROM leads WHERE email=?');
$query->execute([$email]);
$lead = $query->fetch();
$query = $db->prepare('SELECT r.*, (SELECT COUNT(*) FROM consent_events c WHERE c.registration_id=r.id) AS consent_count FROM lead_webinars r WHERE lead_id=? ORDER BY webinar_id');
$query->execute([$lead['id'] ?? 0]);
$tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo json_encode(['leadCount'=>$lead ? 1 : 0, 'registrations'=>$query->fetchAll(), 'tables'=>$tables], JSON_THROW_ON_ERROR);
