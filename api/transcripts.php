<?php
declare(strict_types=1);

function transcript_timestamp(string $value): float
{
    if (!preg_match('/^(?:(\d{1,2}):)?([0-5]\d):([0-5]\d)[.,](\d{3})$/D', $value, $m))
        throw new ApiError(422, 'Invalid caption timestamp. Use HH:MM:SS.mmm or MM:SS.mmm.');
    return (int) ($m[1] ?: 0) * 3600 + (int) $m[2] * 60 + (int) $m[3] + (int) $m[4] / 1000;
}

/** Parse authorized text/captions locally. */
function parse_transcript(string $text, string $format): array
{
    if (!in_array($format, ['txt','vtt','srt'], true)) throw new ApiError(422, 'Choose TXT, VTT, or SRT captions.');
    if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) throw new ApiError(422, 'Save the transcript as UTF-8 text.');
    $text = trim(str_replace(["\xEF\xBB\xBF", "\r\n", "\r"], ['', "\n", "\n"], $text));
    if (mb_strlen($text) < 20 || mb_strlen($text) > 120000) throw new ApiError(422, 'Use a transcript between 20 and 120,000 characters.');
    $cues = [];
    if ($format === 'txt') {
        foreach (preg_split('/\n\s*\n/u', $text) as $paragraph) $cues[] = ['text'=>trim($paragraph),'start'=>null,'end'=>null];
    } else {
        foreach (preg_split('/\n\s*\n/u', $text) as $block) {
            if (preg_match('/^(NOTE|STYLE|REGION)(\s|$)/', $block)) continue;
            $lines = explode("\n", $block); $at = null;
            foreach ($lines as $i=>$line) if (str_contains($line, '-->')) { $at=$i; break; }
            if ($at === null) {
                if (str_starts_with($block, 'WEBVTT')) continue;
                throw new ApiError(422, 'A caption block has no timestamp. Check the VTT/SRT file.');
            }
            if (!preg_match('/^(\S+)\s+-->\s+(\S+)(?:\s+.*)?$/D', trim($lines[$at]), $times)) throw new ApiError(422, 'Invalid caption time range.');
            $start = transcript_timestamp($times[1]); $end = transcript_timestamp($times[2]);
            if ($end <= $start || $end > 86400) throw new ApiError(422, 'Caption end times must follow start times and stay within 24 hours.');
            $body = html_entity_decode(strip_tags(implode(' ', array_slice($lines,$at+1))), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $body = trim(preg_replace('/\s+/u', ' ', $body));
            if ($body !== '') $cues[] = ['text'=>$body,'start'=>$start,'end'=>$end];
        }
    }
    $chunks = []; $current = null;
    foreach ($cues as $cue) {
        // Bound each retrieval unit without cutting a multibyte character.
        $parts = [];
        $remaining = $cue['text'];
        while (mb_strlen($remaining) > 1000) {
            $piece = mb_substr($remaining,0,1000); $space = mb_strrpos($piece,' ');
            $length = $space !== false && $space > 500 ? $space : 1000;
            $parts[] = trim(mb_substr($remaining,0,$length)); $remaining = ltrim(mb_substr($remaining,$length));
        }
        if ($remaining !== '') $parts[] = $remaining;
        foreach ($parts as $piece) {
            if ($current && mb_strlen($current['text']) + mb_strlen($piece) + 1 > 1000) { $chunks[]=$current; $current=null; }
            if (!$current) $current = ['text'=>$piece,'start'=>$cue['start'],'end'=>$cue['end']];
            else { $current['text'] .= "\n" . $piece; $current['end'] = $cue['end']; }
        }
    }
    if ($current) $chunks[] = $current;
    if (!$chunks) throw new ApiError(422, 'No spoken text was found in this transcript.');
    return $chunks;
}

function transcript_chunks(PDO $db, int $webinarId): array
{
    $q=$db->prepare('SELECT * FROM webinar_transcripts WHERE webinar_id=? ORDER BY chunk_index'); $q->execute([$webinarId]); return $q->fetchAll();
}
function transcript_hash(array $chunks): string { return hash('sha256', json_encode($chunks,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
function transcript_lock(PDO $db,int $id): void
{
    $q=$db->prepare('SELECT GET_LOCK(?,2)'); $q->execute(['webinar-transcript:'.$id]);
    if ((int)$q->fetchColumn()!==1) throw new ApiError(409,'This webinar’s transcript is in use. Please retry shortly.');
}

/** Shared atomic import for manual captions and completed speech-recognition jobs. */
function replace_transcript(PDO $db,int $id,array $chunks,string $name,string $format,?int $adminId): void
{
    if (!$db->inTransaction()) throw new LogicException('Transcript replacement requires a transaction.');
    $q=$db->prepare('DELETE FROM webinar_insights WHERE webinar_id=?'); $q->execute([$id]);
    $q=$db->prepare('DELETE FROM webinar_transcripts WHERE webinar_id=?'); $q->execute([$id]);
    $q=$db->prepare('INSERT INTO webinar_transcripts(webinar_id,chunk_index,chunk_text,start_seconds,end_seconds,source_reference) VALUES(?,?,?,?,?,?)');
    foreach ($chunks as $i=>$chunk) $q->execute([$id,$i,$chunk['text'],$chunk['start'],$chunk['end'],$name.' · section '.($i+1)]);
    $q=$db->prepare('INSERT INTO webinar_transcript_documents(webinar_id,file_name,source_format,content_hash,chunk_count,uploaded_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE file_name=VALUES(file_name),source_format=VALUES(source_format),content_hash=VALUES(content_hash),chunk_count=VALUES(chunk_count),uploaded_by=VALUES(uploaded_by),uploaded_at=UTC_TIMESTAMP(6)');
    $q->execute([$id,$name,$format,transcript_hash($chunks),count($chunks),$adminId]);
    $q=$db->prepare("UPDATE webinars SET transcript_status='ready' WHERE id=?"); $q->execute([$id]);
}

function transcript_manage(PDO $db,array $config,string $method): never
{
    $admin=require_admin($db);
    if ($method==='GET') {
        if (isset($_GET['webinar_id'])) {
            $id=filter_var($_GET['webinar_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$id) throw new ApiError(422,'Invalid webinar ID.');
            active_webinar($db,$id); $chunks=transcript_chunks($db,$id);
            if (!$chunks) throw new ApiError(404,'This webinar has no transcript to edit yet.');
            $timed=count(array_filter($chunks,static fn($c)=>$c['start_seconds']!==null && $c['end_seconds']!==null))===count($chunks);
            $time=static function($seconds): string { $ms=(int)round((float)$seconds*1000); return sprintf('%02d:%02d:%02d.%03d',intdiv($ms,3600000),intdiv($ms,60000)%60,intdiv($ms,1000)%60,$ms%1000); };
            $text=$timed ? "WEBVTT\n\n".implode("\n\n",array_map(static fn($c)=>$time($c['start_seconds']).' --> '.$time($c['end_seconds'])."\n".htmlspecialchars($c['chunk_text'],ENT_NOQUOTES | ENT_SUBSTITUTE,'UTF-8'),$chunks)) : implode("\n\n",array_column($chunks,'chunk_text'));
            json_response(200,['text'=>$text,'format'=>$timed?'vtt':'txt','version'=>transcript_hash($chunks)]);
        }
        $rows=$db->query('SELECT w.id,w.title,w.campaign_code,a.is_demo,d.file_name,d.source_format,d.uploaded_at,COUNT(t.id) AS chunk_count FROM webinars w LEFT JOIN webinar_assets a ON a.webinar_id=w.id AND a.asset_type=\'video\' LEFT JOIN webinar_transcript_documents d ON d.webinar_id=w.id LEFT JOIN webinar_transcripts t ON t.webinar_id=w.id WHERE w.is_active=1 GROUP BY w.id ORDER BY w.id')->fetchAll();
        json_response(200,['webinars'=>$rows,'assistant'=>assistant_status($config),'transcription'=>transcription_worker_status($db,$config),'csrfToken'=>$admin['csrf_token']]);
    }
    if ($method!=='POST') throw new ApiError(405,'Method not allowed.');
    require_write_access($admin); rate_limit($db,'transcript-import',10);
    $data=read_json(800000); $id=filter_var($data['webinarId'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id || ($data['authorized'] ?? null)!==true) throw new ApiError(422,'Select a webinar and confirm permission to use its matching transcript.');
    active_webinar($db,$id);
    $name=is_string($data['fileName'] ?? null) ? mb_substr(basename(str_replace('\\','/',$data['fileName'])),0,150) : 'Transcript';
    $format=is_string($data['format'] ?? null) ? strtolower($data['format']) : '';
    $text=is_string($data['text'] ?? null) ? $data['text'] : '';
    $chunks=parse_transcript($text,$format); transcript_lock($db,$id);
    if (isset($data['expectedVersion']) && (!is_string($data['expectedVersion']) || !hash_equals(transcript_hash(transcript_chunks($db,$id)),$data['expectedVersion']))) throw new ApiError(409,'The transcript changed while you were editing. Load the current transcript before saving.');
    $db->beginTransaction();
    try {
        replace_transcript($db,$id,$chunks,$name,$format,(int)$admin['admin_id']); $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    json_response(201,['message'=>'Transcript imported. Previous answers retain their original source labels; new answers use this version.','chunkCount'=>count($chunks),'timestamped'=>$format!=='txt']);
}

function transcript_api(PDO $db,?array $visitor,int $id,string $method): never
{
    if (!find_registration($db,$visitor,$id)) throw new ApiError(401,'Register for this webinar to read its transcript.');
    $search=''; $offset=max(0,(int)($_GET['offset'] ?? 0));
    if ($method==='POST') {
        require_write_access($visitor); $data=read_json();
        $search=is_string($data['search'] ?? null) ? trim($data['search']) : '';
        if (mb_strlen($search)>120) throw new ApiError(422,'Search with at most 120 characters.');
        $offset=max(0,(int)($data['offset'] ?? 0));
    } elseif ($method!=='GET') throw new ApiError(405,'Method not allowed.');
    $chunks=transcript_chunks($db,$id); $hash=transcript_hash($chunks); $provenance=transcription_provenance($db,$id,$hash);
    if ($search!=='') $chunks=array_values(array_filter($chunks,static fn($c)=>mb_stripos($c['chunk_text'],$search)!==false));
    json_response(200,['chunks'=>array_slice($chunks,$offset,15),'total'=>count($chunks),'offset'=>$offset,'version'=>$hash,'transcription'=>$provenance,'nextOffset'=>$offset+15<count($chunks)?$offset+15:null]);
}

function transcript_download_api(PDO $db,?array $visitor,int $id,string $method): never
{
    if ($method!=='GET' && $method!=='HEAD') throw new ApiError(405,'Method not allowed.');
    if (!find_registration($db,$visitor,$id)) throw new ApiError(401,'Register for this webinar to download its transcript.');
    $chunks=transcript_chunks($db,$id); if (!$chunks) throw new ApiError(404,'This webinar does not have a transcript yet.');
    $q=$db->prepare('SELECT slug FROM webinars WHERE id=?'); $q->execute([$id]); $slug=preg_replace('/[^a-z0-9-]+/','-',strtolower((string)$q->fetchColumn())) ?: 'webinar';
    $timed=count(array_filter($chunks,static fn($c)=>$c['start_seconds']!==null && $c['end_seconds']!==null))===count($chunks);
    if ($timed) {
        $stamp=static function($seconds): string { $ms=(int)round((float)$seconds*1000); return sprintf('%02d:%02d:%02d.%03d',intdiv($ms,3600000),intdiv($ms,60000)%60,intdiv($ms,1000)%60,$ms%1000); };
        $body="WEBVTT\n\n".implode("\n\n",array_map(static fn($c)=>$stamp($c['start_seconds']).' --> '.$stamp($c['end_seconds'])."\n".str_replace('-->','→',$c['chunk_text']),$chunks))."\n"; $extension='vtt'; $type='text/vtt';
    } else { $body=implode("\n\n",array_column($chunks,'chunk_text'))."\n"; $extension='txt'; $type='text/plain'; }
    header('Content-Type: '.$type.'; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$slug.'-transcript.'.$extension.'"'); header('Cache-Control: private, no-store'); header('Content-Length: '.strlen($body));
    if ($method==='GET') echo $body; exit;
}
