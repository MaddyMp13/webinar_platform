<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/api/db.php';
$config = require dirname(__DIR__) . '/config/config.php';
$db = database($config);
$sql = str_replace('USE webinar_platform;', '', file_get_contents(dirname(__DIR__) . '/database/chatbot.sql'));
$db->exec($sql);
echo "Chatbot migration applied. Existing registrations and activity preserved.\n";
