<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/api/db.php';
$config=require dirname(__DIR__).'/config/config.php';
$db=database($config);
$db->exec(str_replace('USE webinar_platform;','',file_get_contents(dirname(__DIR__).'/database/transcription.sql')));
echo "Transcription queue migration applied. Existing data preserved.\n";
