<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/api/db.php';
$db=database(require dirname(__DIR__).'/config/config.php');
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$email=$input['email'] ?? ''; if(!preg_match('/^qa-chatbot-\d+@example\.test$/D',$email))throw new RuntimeException('QA email required');
$code='qa-chat-'.substr(hash('sha256',$email),0,20);
$mode=$input['mode'] ?? 'set-source'; $name=$input['file'] ?? '';
if($mode==='set-source' && !preg_match('/^qa-transcription-\d+\.mp4$/D',$name))throw new RuntimeException('QA media name required');
if($mode==='escape')$name='../config/config.php';
if(!in_array($mode,['set-source','escape'],true))throw new RuntimeException('Unknown fixture mode');
$q=$db->prepare('UPDATE webinar_assets a JOIN webinars w ON w.id=a.webinar_id SET a.storage_path=? WHERE a.webinar_id=? AND w.campaign_code IN (?,?)');$q->execute([$name,(int)$input['id'],$code.'-0',$code.'-1']);
if(!$q->rowCount())throw new RuntimeException('No matching QA asset');
echo json_encode(['updated'=>true]);
