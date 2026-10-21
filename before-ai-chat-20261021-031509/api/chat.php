<?php
declare(strict_types=1);

function conversation_messages(PDO $db,string $id): array
{
    $query = $db->prepare('SELECT id,role,message,created_at FROM chat_messages WHERE conversation_id=? ORDER BY id');
    $query->execute([$id]); $messages = $query->fetchAll();
    foreach ($messages as &$message) {
        $sources = $db->prepare('SELECT transcript_chunk_id,source_label,start_seconds,end_seconds FROM chat_message_sources WHERE message_id=? ORDER BY id');
        $sources->execute([$message['id']]); $message['sources']=$sources->fetchAll();
    }
    return $messages;
}
function chat_api(PDO $db,?array $visitor,int $webinarId,string $method): never
{
    $registration = find_registration($db,$visitor,$webinarId);
    if (!$registration) throw new ApiError(401,'Register for this webinar to open its transcript assistant.');
    $query = $db->prepare('SELECT COUNT(*) FROM webinar_transcripts WHERE webinar_id=?'); $query->execute([$webinarId]);
    $ready = (int) $query->fetchColumn()>0;
    $query = $db->prepare('SELECT id FROM chat_conversations WHERE registration_id=? AND webinar_id=? ORDER BY created_at DESC LIMIT 1');
    $query->execute([$registration['id'],$webinarId]); $conversationId = $query->fetchColumn();
    if ($method==='GET') json_response(200,[
        'transcriptReady'=>$ready,'mode'=>'transcript_excerpts','activityConsent'=>$registration['activityConsent'],
        'conversationId'=>$conversationId ?: null,'messages'=>$conversationId && $registration['activityConsent'] ? conversation_messages($db,$conversationId) : []
    ]);
    if ($method!=='POST') throw new ApiError(405,'Method not allowed.');
    require_write_access($visitor); activity_registration($db,$visitor,$webinarId); rate_limit($db,'chat',15);
    if (!$ready) throw new ApiError(409,'The transcript has not been added for this webinar.');
    $data = read_json();
    $question = is_string($data['question'] ?? null) ? trim($data['question']) : '';
    if (mb_strlen($question)<3 || mb_strlen($question)>1500 || !valid_uuid($data['requestId'] ?? null)) throw new ApiError(422,'Ask a question between 3 and 1,500 characters.');
    $query = $db->prepare('SELECT c.id,c.registration_id FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id WHERE m.request_uuid=?');
    $query->execute([$data['requestId']]); $duplicate = $query->fetch();
    if ($duplicate) {
        if ((int) $duplicate['registration_id']!==$registration['id']) throw new ApiError(403,'This request belongs to another registration.');
        json_response(200,['conversationId'=>$duplicate['id'],'messages'=>conversation_messages($db,$duplicate['id'])]);
    }
    preg_match_all('/[\p{L}\p{N}]{3,}/u',mb_strtolower($question),$matches);
    $stop = ['what','which','where','when','does','this','that','with','from','about','have','tell','please','could','would','your','there','their','explain','the','and','for','are','how'];
    $keywords = array_slice(array_values(array_diff(array_unique($matches[0]),$stop)),0,8);
    $chunks = [];
    if ($keywords) {
        $predicates = implode(' OR ',array_fill(0,count($keywords),'LOWER(chunk_text) LIKE ?'));
        $query = $db->prepare("SELECT * FROM webinar_transcripts WHERE webinar_id=? AND ($predicates) ORDER BY chunk_index LIMIT 3");
        $query->execute([$webinarId,...array_map(static fn($word)=>'%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$word).'%', $keywords)]);
        $chunks = $query->fetchAll();
    }
    $answer = $chunks ? "Relevant excerpts from this webinar’s transcript:\n\n" . implode("\n\n",array_map(static fn($chunk)=>mb_substr($chunk['chunk_text'],0,1500),$chunks))
        : 'I couldn’t find enough information in this webinar’s transcript to answer that question. Try asking about a specific topic covered in the session.';
    $db->beginTransaction();
    try {
        if (!$conversationId) {
            $conversationId = uuid_v4();
            $query = $db->prepare('INSERT INTO chat_conversations(id,webinar_id,registration_id) VALUES(?,?,?)'); $query->execute([$conversationId,$webinarId,$registration['id']]);
        }
        $query = $db->prepare('SELECT id FROM chat_conversations WHERE id=? FOR UPDATE'); $query->execute([$conversationId]);
        $query = $db->prepare('INSERT INTO chat_messages(conversation_id,role,message,request_uuid) VALUES(?,\'user\',?,?)'); $query->execute([$conversationId,$question,$data['requestId']]);
        $query = $db->prepare('INSERT INTO chat_messages(conversation_id,role,message,model_name) VALUES(?,\'assistant\',?,\'transcript-excerpts\')'); $query->execute([$conversationId,$answer]);
        $messageId = $db->lastInsertId();
        $query = $db->prepare('INSERT INTO chat_message_sources(message_id,transcript_chunk_id,source_label,start_seconds,end_seconds) VALUES(?,?,?,?,?)');
        foreach ($chunks as $chunk) $query->execute([$messageId,$chunk['id'],$chunk['source_reference'] ?: 'Transcript chunk '.($chunk['chunk_index']+1),$chunk['start_seconds'],$chunk['end_seconds']]);
        $query = $db->prepare('UPDATE chat_conversations SET updated_at=UTC_TIMESTAMP(6) WHERE id=?'); $query->execute([$conversationId]);
        $query = $db->prepare('UPDATE lead_webinars SET updated_at=UTC_TIMESTAMP(6) WHERE id=?'); $query->execute([$registration['id']]);
        $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    json_response(201,['conversationId'=>$conversationId,'messages'=>conversation_messages($db,$conversationId)]);
}
function uuid_v4(): string
{
    $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&15)|64); $bytes[8]=chr((ord($bytes[8])&63)|128);
    $hex=bin2hex($bytes); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
}
