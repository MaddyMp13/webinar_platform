<?php
declare(strict_types=1);
// Private CLI bridge. Uses the existing PHP PDO settings; no database secrets in Python or command arguments.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/playback.php';
require_once dirname(__DIR__).'/api/chat.php';
require_once dirname(__DIR__).'/api/transcripts.php';
require_once dirname(__DIR__).'/api/transcription.php';
try {
    $raw=stream_get_contents(STDIN,2000001); if (strlen($raw)>2000000) throw new ApiError(413,'Worker result is too large.');
    $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR); if (!is_array($data)) throw new ApiError(422,'Invalid worker request.');
    $config=require dirname(__DIR__).'/config/config.php'; $db=database($config);
    echo json_encode(['ok'=>true,'result'=>transcription_worker($db,$config,$data)],JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (ApiError $e) { echo json_encode(['ok'=>false,'status'=>$e->status,'message'=>$e->getMessage()]); }
catch (Throwable $e) { fwrite(STDERR,get_class($e).' '.$e->getCode()."\n"); echo json_encode(['ok'=>false,'status'=>500,'message'=>'The transcription backend is unavailable. Check MySQL and the migrations.']); exit(1); }
