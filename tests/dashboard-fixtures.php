<?php
declare(strict_types=1);
// CLI-only, uniquely named temporary QA fixtures; never accessible through Apache.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/api/db.php';
$db=database(require dirname(__DIR__).'/config/config.php');
$input=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
if(!preg_match('/^qa-dashboard-[0-9]+@example\.test$/D',$input['email'] ?? '')) throw new RuntimeException('Use a unique QA fixture email.');
$mode=$argv[1] ?? '';
if($mode==='seed-admin'){
 $query=$db->prepare('INSERT INTO admin_users(name,email,password_hash) VALUES(?,?,?)');$query->execute(['QA admin',$input['email'],password_hash($input['password'],PASSWORD_DEFAULT)]);
 echo json_encode(['adminId'=>$db->lastInsertId()]);
}elseif($mode==='transcript'){
 $index=random_int(10000000,2000000000);
 $query=$db->prepare('INSERT INTO webinar_transcripts(webinar_id,chunk_index,chunk_text,start_seconds,end_seconds,source_reference) VALUES(6,?,?,10,20,\'Temporary QA fixture\')');
 $query->execute([$index,'Quasartest recovery is a temporary QA transcript topic. Recovery plans use verified backups.']);
 echo json_encode(['chunkId'=>(int)$db->lastInsertId()]);
}elseif($mode==='inspect'){
 $query=$db->prepare('SELECT p.* FROM playback_sessions p JOIN lead_webinars r ON r.id=p.registration_id JOIN leads l ON l.id=r.lead_id WHERE l.email=?');$query->execute([$input['email']]);
 echo json_encode(['sessions'=>$query->fetchAll(),'adminCount'=>(int)$db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()]);
}elseif($mode==='cleanup'){
 $query=$db->prepare('DELETE FROM admin_users WHERE email=?');$query->execute([$input['email']]);
 $query=$db->prepare('DELETE FROM leads WHERE email=?');$query->execute([$input['email']]);
 if(isset($input['chunkId'])){$query=$db->prepare('DELETE FROM webinar_transcripts WHERE id=? AND source_reference=\'Temporary QA fixture\'');$query->execute([$input['chunkId']]);}
 echo json_encode(['cleaned'=>true]);
}else throw new RuntimeException('Unknown fixture action.');
