<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/api/bootstrap.php';
require dirname(__DIR__).'/api/assistant.php';
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$config=['chat_provider'=>'ollama','ollama_url'=>'http://127.0.0.1:'.(int)$input['port'],'ollama_model'=>'qa-model:local','ollama_timeout'=>10];
$chunks=[['id'=>11,'chunk_index'=>0,'chunk_text'=>'Recovery plans use verified backups.','source_reference'=>'Temporary QA fixture','start_seconds'=>10,'end_seconds'=>20]];
try { echo json_encode(['status'=>200,'result'=>grounded_answer($config,'What do recovery plans use?',$chunks,[])]); }
catch(ApiError $e) { echo json_encode(['status'=>$e->status,'message'=>$e->getMessage()]); }
