<?php
declare(strict_types=1);

function conversation_messages(PDO $db,string $id): array
{
    $q=$db->prepare('SELECT id,role,message,model_name,input_tokens,output_tokens,created_at FROM chat_messages WHERE conversation_id=? ORDER BY id'); $q->execute([$id]); $messages=$q->fetchAll();
    foreach ($messages as &$message) {
        $q=$db->prepare('SELECT transcript_chunk_id,source_label,start_seconds,end_seconds,quote_text AS quote FROM chat_message_sources WHERE message_id=? ORDER BY id'); $q->execute([$message['id']]); $message['sources']=$q->fetchAll();
    }
    return $messages;
}
function owned_conversation(PDO $db,string $id,int $registrationId,int $webinarId): void
{
    $q=$db->prepare('SELECT id FROM chat_conversations WHERE id=? AND registration_id=? AND webinar_id=?'); $q->execute([$id,$registrationId,$webinarId]);
    if (!$q->fetchColumn()) throw new ApiError(404,'Conversation not found for this webinar and registration.');
}
function chat_api(PDO $db,array $config,?array $visitor,int $webinarId,string $method): never
{
    $reg=find_registration($db,$visitor,$webinarId);
    if (!$reg) throw new ApiError(401,'Register for this webinar to open its transcript assistant.');
    $chunks=transcript_chunks($db,$webinarId); $conversationId=null;
    $q=$db->prepare('SELECT c.id,c.created_at,c.updated_at,(SELECT LEFT(message,75) FROM chat_messages WHERE conversation_id=c.id AND role=\'user\' ORDER BY id LIMIT 1) AS title FROM chat_conversations c WHERE registration_id=? AND webinar_id=? ORDER BY updated_at DESC LIMIT 50');
    $q->execute([$reg['id'],$webinarId]); $conversations=$reg['activityConsent'] ? $q->fetchAll() : [];
    if ($method==='GET') {
        $requested=$_GET['conversation_id'] ?? null;
        if ($requested!==null) {
            if (!is_string($requested) || !valid_uuid($requested)) throw new ApiError(422,'Invalid conversation ID.');
            owned_conversation($db,$requested,$reg['id'],$webinarId); $conversationId=$requested;
        } else $conversationId=$conversations[0]['id'] ?? null;
        $status=assistant_status($config);
        json_response(200,['transcriptReady'=>(bool)$chunks,'transcriptVersion'=>transcript_hash($chunks),'mode'=>$status['mode'],'assistant'=>$status,
            'activityConsent'=>$reg['activityConsent'],'conversationId'=>$conversationId,'conversations'=>$conversations,
            'messages'=>$conversationId && $reg['activityConsent'] ? conversation_messages($db,$conversationId) : []]);
    }
    if ($method!=='POST') throw new ApiError(405,'Method not allowed.');
    require_write_access($visitor); activity_registration($db,$visitor,$webinarId);
    $data=read_json(); $question=is_string($data['question'] ?? null) ? trim($data['question']) : '';
    if (mb_strlen($question)<3 || mb_strlen($question)>1500 || strlen($question)>6000 || !valid_uuid($data['requestId'] ?? null)) throw new ApiError(422,'Ask a question between 3 and 1,500 characters.');
    // Serialize a registration's requests, including retries, before invoking the model.
    $q=$db->prepare('SELECT GET_LOCK(?,2)'); $q->execute(['webinar-chat:'.$reg['id']]);
    if ((int)$q->fetchColumn()!==1) throw new ApiError(409,'A question is still being answered. Wait for it to finish before retrying.');
    $q=$db->prepare('SELECT c.id,c.registration_id,c.webinar_id,m.message FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id WHERE m.request_uuid=?');
    $q->execute([$data['requestId']]); $duplicate=$q->fetch();
    if ($duplicate) {
        if ((int)$duplicate['registration_id']!==$reg['id'] || (int)$duplicate['webinar_id']!==$webinarId || $duplicate['message']!==$question) throw new ApiError(409,'This request ID has already been used for another question.');
        json_response(200,['conversationId'=>$duplicate['id'],'messages'=>conversation_messages($db,$duplicate['id'])]);
    }
    rate_limit($db,'chat',15);
    if (!$chunks) throw new ApiError(409,'The transcript has not been added for this webinar.');
    if (isset($data['conversationId']) && $data['conversationId']!==null) {
        if (!valid_uuid($data['conversationId'])) throw new ApiError(422,'Invalid conversation ID.');
        $conversationId=$data['conversationId']; owned_conversation($db,$conversationId,$reg['id'],$webinarId);
    } elseif (($data['newConversation'] ?? false)!==true) $conversationId=$conversations[0]['id'] ?? null;
    $history=$conversationId ? conversation_messages($db,$conversationId) : [];
    if (count($history)>=100) throw new ApiError(422,'This conversation has reached 50 questions. Start a new conversation.');
    transcript_lock($db,$webinarId); $chunks=transcript_chunks($db,$webinarId);
    if (!$chunks) throw new ApiError(409,'This webinar’s transcript is unavailable.');
    $previous=''; foreach ($history as $m) if ($m['role']==='user') $previous=$m['message'];
    $selected=ranked_chunks($chunks,$question,$previous);
    if ($selected && ($config['chat_provider'] ?? '')==='ollama') rate_limit($db,'ollama-generation',20);
    $answer=grounded_answer($config,$question,$selected,$history);
    $db->beginTransaction();
    try {
        // Consent and registration may have changed while local inference was running.
        $q=$db->prepare('SELECT activity_consent FROM lead_webinars WHERE id=? FOR UPDATE'); $q->execute([$reg['id']]);
        if ((int)$q->fetchColumn()!==1) throw new ApiError(403,'Activity consent was removed. This answer has not been saved.');
        if (!$conversationId) { $conversationId=uuid_v4(); $q=$db->prepare('INSERT INTO chat_conversations(id,webinar_id,registration_id) VALUES(?,?,?)'); $q->execute([$conversationId,$webinarId,$reg['id']]); }
        $q=$db->prepare('INSERT INTO chat_messages(conversation_id,role,message,request_uuid) VALUES(?,\'user\',?,?)'); $q->execute([$conversationId,$question,$data['requestId']]);
        $q=$db->prepare('INSERT INTO chat_messages(conversation_id,role,message,model_name,input_tokens,output_tokens) VALUES(?,\'assistant\',?,?,?,?)');
        $q->execute([$conversationId,$answer['answer'],$answer['model'],$answer['inputTokens'],$answer['outputTokens']]); $messageId=$db->lastInsertId();
        $q=$db->prepare('INSERT INTO chat_message_sources(message_id,transcript_chunk_id,source_label,start_seconds,end_seconds,quote_text) VALUES(?,?,?,?,?,?)');
        foreach ($answer['sources'] as $chunk) $q->execute([$messageId,$chunk['id'],mb_substr($chunk['source_reference'] ?: 'Transcript section '.($chunk['chunk_index']+1),0,255),$chunk['start_seconds'],$chunk['end_seconds'],$chunk['quote'] ?? null]);
        $q=$db->prepare('UPDATE chat_conversations SET updated_at=UTC_TIMESTAMP(6) WHERE id=?'); $q->execute([$conversationId]);
        $q=$db->prepare('UPDATE lead_webinars SET updated_at=UTC_TIMESTAMP(6) WHERE id=?'); $q->execute([$reg['id']]); $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    json_response(201,['conversationId'=>$conversationId,'messages'=>conversation_messages($db,$conversationId)]);
}
function uuid_v4(): string
{
    $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&15)|64); $bytes[8]=chr((ord($bytes[8])&63)|128);
    $hex=bin2hex($bytes); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
}
