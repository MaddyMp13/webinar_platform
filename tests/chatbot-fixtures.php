<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/api/db.php';
$db=database(require dirname(__DIR__).'/config/config.php');
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$email=$input['email'] ?? '';
if (!preg_match('/^qa-chatbot-\d+@example\.test$/D',$email)) throw new RuntimeException('Use a unique QA email.');
$slug=str_replace(['@','.'],['-','-'],$email);
$code='qa-chat-'.substr(hash('sha256',$email),0,20);
if (($argv[1] ?? '')==='create') {
    $db->beginTransaction();
    $q=$db->prepare('INSERT INTO admin_users(name,email,password_hash) VALUES(?,?,?)'); $q->execute(['QA chatbot admin',$email,password_hash($input['password'],PASSWORD_DEFAULT)]);
    $ids=[];
    for($i=0;$i<2;$i++) {
        $q=$db->prepare("INSERT INTO webinars(campaign_code,slug,title,description,category,is_active) VALUES(?,?,?,?,?,1)");
        $q->execute([$code.'-'.$i,$slug.'-'.$i,'Temporary chatbot QA recording '.$i,'Temporary synthetic test fixture.','QA']); $id=(int)$db->lastInsertId(); $ids[]=$id;
        $q=$db->prepare("INSERT INTO webinar_assets(webinar_id,asset_type,storage_path,label,mime_type,is_demo) SELECT ?,'video',storage_path,'Temporary QA sample',mime_type,1 FROM webinar_assets WHERE webinar_id=6 AND asset_type='video'"); $q->execute([$id]);
    }
    $db->commit(); echo json_encode(['ids'=>$ids]);
} elseif (($argv[1] ?? '')==='cleanup') {
    $q=$db->prepare('DELETE FROM leads WHERE email=?');$q->execute([$email]);
    $q=$db->prepare('DELETE FROM webinars WHERE campaign_code IN (?,?)');$q->execute([$code.'-0',$code.'-1']);
    $q=$db->prepare('DELETE FROM admin_users WHERE email=?');$q->execute([$email]);
    echo json_encode(['cleaned'=>true]);
} else throw new RuntimeException('Unknown fixture action.');
