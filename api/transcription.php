<?php
declare(strict_types=1);

function transcription_remote_endpoint(array $config,bool $requireKey=true): array
{
    $base=rtrim((string)($config['transcription_api_url'] ?? ''),'/'); $url=parse_url($base);
    $host=strtolower((string)($url['host'] ?? '')); $allowed=$config['transcription_api_allowed_hosts'] ?? [];
    if (!is_array($allowed)) $allowed=[];
    $allowed=array_map(static fn($item)=>strtolower((string)$item),$allowed);
    if (!$url || ($url['scheme'] ?? '')!=='https' || $host==='' || !in_array($host,$allowed,true)
        || !empty($url['user']) || !empty($url['pass']) || !empty($url['query']) || !empty($url['fragment']) || !empty($url['path']))
        throw new ApiError(503,'Configure the HTTPS transcription API and its allowed host in protected PHP settings.');
    $header=(string)($config['transcription_api_key_header'] ?? 'X-API-Key');
    $key=$config['transcription_api_key'] ?? '';
    if (!preg_match('/^[A-Za-z0-9-]{1,64}$/D',$header) || !is_string($key) || strlen($key)>1000 || preg_match('/[\r\n]/',$key))
        throw new ApiError(503,'Invalid transcription API authentication configuration.');
    if ($requireKey && $key==='') throw new ApiError(503,'Add the transcription API key to protected config/config.local.php.');
    return [$base,$header,$key];
}
function transcription_remote_http(array $config,string $method,string $path,?string $file=null,int $timeout=30,bool $requireKey=true): array
{
    [$base,$keyHeader,$key]=transcription_remote_endpoint($config,$requireKey);
    if (!preg_match('#^/(?:health|api/transcriptions(?:/[a-f0-9-]{36})?)$#D',$path)) throw new LogicException('Invalid transcription API path.');
    $headers=['Accept: application/json']; if ($key!=='') $headers[]=$keyHeader.': '.$key;
    $body=''; $ch=curl_init($base.$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$timeout,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>$headers,CURLOPT_PROXY=>'',
        CURLOPT_WRITEFUNCTION=>static function($ch,$part) use (&$body) {
            if (strlen($body)+strlen($part)>5000000) return 0; $body.=$part; return strlen($part);
        }]);
    if ($method==='POST') {
        if (!$file || !is_file($file)) throw new ApiError(422,'The recording file is unavailable for upload.');
        $mime=function_exists('mime_content_type') ? (mime_content_type($file) ?: 'application/octet-stream') : 'application/octet-stream';
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['file'=>new CURLFile($file,$mime,basename($file))]]);
    } elseif ($method!=='GET') throw new LogicException('Invalid transcription API method.');
    $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($ok===false) throw new ApiError(503,'Could not reach the transcription API. The existing transcript was retained.');
    if (in_array($status,[401,403],true)) throw new ApiError(503,'The transcription API rejected the configured API key.');
    if (!in_array($status,[200,202],true)) throw new ApiError(503,'The transcription API returned HTTP '.$status.'. The existing transcript was retained.');
    try { $result=json_decode($body,true,64,JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new ApiError(502,'The transcription API returned an unreadable response.'); }
    if (!is_array($result)) throw new ApiError(502,'The transcription API returned an invalid response.');
    return $result;
}
function transcription_remote_status_value(array $payload): string
{
    $status=$payload['status'] ?? $payload['state'] ?? '';
    return is_string($status) ? strtolower(trim($status)) : '';
}
function transcription_remote_result(array $payload): array
{
    $data=$payload;
    foreach (['result','transcription','data'] as $key) if (isset($payload[$key]) && is_array($payload[$key])) $data=array_replace($data,$payload[$key]);
    $segments=$data['segments'] ?? null; $format='txt'; $duration=0.0; $count=0;
    if (is_array($segments) && $segments) {
        $cues=[]; $previous=-1.0;
        foreach ($segments as $segment) {
            if (!is_array($segment)) throw new ApiError(502,'The transcription API returned an invalid speech segment.');
            $start=$segment['start'] ?? $segment['start_seconds'] ?? null; $end=$segment['end'] ?? $segment['end_seconds'] ?? null; $text=$segment['text'] ?? null;
            if (!is_numeric($start) || !is_numeric($end) || !is_string($text)) throw new ApiError(502,'The transcription API returned an incomplete speech segment.');
            $start=(float)$start; $end=(float)$end; $text=trim(preg_replace('/\s+/u',' ',$text));
            if (!is_finite($start) || !is_finite($end) || $start<0 || $end<=$start || $start<$previous || $end>86400) throw new ApiError(502,'The transcription API returned invalid timestamps.');
            if ($text==='') continue; $previous=$start; $duration=max($duration,$end); $count++;
            $stamp=static function(float $seconds): string { $ms=(int)round($seconds*1000); return sprintf('%02d:%02d:%02d.%03d',intdiv($ms,3600000),intdiv($ms,60000)%60,intdiv($ms,1000)%60,$ms%1000); };
            $safe=htmlspecialchars(str_replace('-->','→',$text),ENT_NOQUOTES | ENT_SUBSTITUTE,'UTF-8');
            $cues[]=$stamp($start).' --> '.$stamp($end)."\n".$safe;
        }
        if ($cues) { $text="WEBVTT\n\n".implode("\n\n",$cues)."\n"; $format='vtt'; }
    }
    if ($format==='txt') {
        $text=$data['text'] ?? $data['transcript'] ?? (is_string($data['transcription'] ?? null)?$data['transcription']:null) ?? $data['transcription_text'] ?? null;
        if (!is_string($text) || trim($text)==='') throw new ApiError(502,'The completed transcription has no usable text or timestamped segments.');
        $duration=is_numeric($data['duration'] ?? null)?max(0,(float)$data['duration']):0; $count=1;
    }
    $chunks=parse_transcript($text,$format);
    $language=$data['language'] ?? $data['detected_language'] ?? null;
    if (!is_string($language) || !preg_match('/^[a-z]{2,3}$/D',strtolower($language))) $language=null; else $language=strtolower($language);
    $probability=$data['language_probability'] ?? $data['language_confidence'] ?? null;
    $probability=is_numeric($probability)?max(0,min(1,(float)$probability)):null;
    return ['chunks'=>$chunks,'format'=>$format,'duration'=>$duration,'segments'=>max($count,count($chunks)),'language'=>$language,'languageProbability'=>$probability];
}
function transcription_remote_submit(array $config,string $file): string
{
    $result=transcription_remote_http($config,'POST','/api/transcriptions',$file,max(30,min(600,(int)($config['transcription_api_upload_timeout'] ?? 300))));
    $nested=is_array($result['data'] ?? null)?$result['data']:[];
    $id=$result['job_id'] ?? $result['jobId'] ?? $result['id'] ?? $nested['job_id'] ?? $nested['jobId'] ?? $nested['id'] ?? null;
    if (!valid_uuid($id)) throw new ApiError(502,'The transcription API did not return a valid job ID.');
    return $id;
}
function transcription_remote_queue(PDO $db,array $config,int $webinarId,?int $requestedBy,string $requestId,string $language='auto',bool $replaceExisting=false): array
{
    if (($config['transcription_provider'] ?? 'local')!=='remote_api') throw new ApiError(503,'Automatic VPS transcription is not enabled.');
    $q=$db->prepare('SELECT id,status,message FROM transcription_jobs WHERE id=?'); $q->execute([$requestId]);
    if ($existing=$q->fetch()) return ['jobId'=>$existing['id'],'status'=>$existing['status'],'message'=>$existing['message']];
    $source=transcription_source($db,$config,$webinarId); transcript_lock($db,$webinarId); $chunks=transcript_chunks($db,$webinarId);
    if ($chunks && !$replaceExisting) return ['jobId'=>null,'status'=>'completed','message'=>'Transcript is already available.'];
    $q=$db->prepare("SELECT id,status,message FROM transcription_jobs WHERE webinar_id=? AND status IN ('queued','running') ORDER BY created_at LIMIT 1"); $q->execute([$webinarId]);
    if ($active=$q->fetch()) return ['jobId'=>$active['id'],'status'=>$active['status'],'message'=>$active['message']];
    $sourceHash=hash_file('sha256',$source['file']); if (!is_string($sourceHash)) throw new ApiError(503,'Could not read the recording before transcription.');
    $model=(string)($config['transcription_model_label'] ?? 'faster-whisper-vps');
    $db->beginTransaction();
    try {
        $q=$db->prepare("INSERT INTO transcription_jobs(id,webinar_id,asset_id,requested_by,status,phase,language_requested,model_name,source_path,source_size,source_mtime,source_sha256,base_transcript_hash,started_at,message) VALUES(?,?,?,?,?,'uploading',?,?,?,?,?,?,?,UTC_TIMESTAMP(6),'Uploading the recording to the VPS.')");
        $q->execute([$requestId,$webinarId,$source['asset']['id'],$requestedBy,'running',$language,$model,$source['asset']['storage_path'],$source['size'],$source['mtime'],$sourceHash,transcript_hash($chunks)]);
        transcription_webinar_state($db,$webinarId,'pending'); $db->commit();
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    $job=['id'=>$requestId,'webinar_id'=>$webinarId];
    try {
        $remoteId=transcription_remote_submit($config,$source['file']);
        if (!hash_equals($sourceHash,hash_file('sha256',$source['file']))) throw new ApiError(409,'The recording changed during upload. Queue the current recording again.');
        $q=$db->prepare("UPDATE transcription_jobs SET worker_id=?,phase='processing',message='Recording uploaded. Waiting for VPS faster-whisper.',heartbeat_at=UTC_TIMESTAMP(6) WHERE id=? AND status='running'"); $q->execute([$remoteId,$requestId]);
    } catch (ApiError $error) { transcription_fail($db,$job,$error->getMessage()); throw $error; }
    return ['jobId'=>$requestId,'status'=>'running','message'=>'Recording uploaded. Waiting for VPS faster-whisper.'];
}
function transcription_remote_complete(PDO $db,array $config,array $job,array $payload): void
{
    $parsed=transcription_remote_result($payload); transcript_lock($db,(int)$job['webinar_id']);
    $source=transcription_source($db,$config,(int)$job['webinar_id']);
    if ($source['asset']['id']!=$job['asset_id'] || $source['asset']['storage_path']!==$job['source_path'] || $source['size']!=$job['source_size'] || $source['mtime']!=$job['source_mtime']) throw new ApiError(409,'The video changed during transcription. Queue the new recording.');
    if (!is_string($job['source_sha256']) || !hash_equals($job['source_sha256'],hash_file('sha256',$source['file']))) throw new ApiError(409,'The recording content changed during transcription.');
    if (transcript_hash(transcript_chunks($db,(int)$job['webinar_id']))!==$job['base_transcript_hash']) throw new ApiError(409,'The transcript was edited while speech recognition was running. That edit was preserved.');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT status FROM transcription_jobs WHERE id=? FOR UPDATE'); $q->execute([$job['id']]);
        if (!in_array($q->fetchColumn(),['queued','running'],true)) { $db->rollBack(); return; }
        $name=mb_substr('faster-whisper VPS · '.basename($source['file']),0,150);
        replace_transcript($db,(int)$job['webinar_id'],$parsed['chunks'],$name,$parsed['format'],$job['requested_by']===null?null:(int)$job['requested_by']);
        $resultHash=transcript_hash(transcript_chunks($db,(int)$job['webinar_id']));
        $q=$db->prepare("UPDATE transcription_jobs SET status='completed',phase='completed',result_transcript_hash=?,language_detected=?,language_probability=?,duration_seconds=?,processed_seconds=?,segment_count=?,message='Transcript saved automatically from the VPS. Review speech recognition against the recording.',finished_at=UTC_TIMESTAMP(6),heartbeat_at=UTC_TIMESTAMP(6) WHERE id=?");
        $q->execute([$resultHash,$parsed['language'],$parsed['languageProbability'],$parsed['duration'] ?: null,$parsed['duration'],$parsed['segments'],$job['id']]); $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
function transcription_remote_sync(PDO $db,array $config,?int $webinarId=null): void
{
    if (($config['transcription_provider'] ?? 'local')!=='remote_api' || empty($config['transcription_api_key'])) return;
    $sql="SELECT * FROM transcription_jobs WHERE status IN ('queued','running') AND worker_id IS NOT NULL"; $values=[];
    if ($webinarId!==null) { $sql.=' AND webinar_id=?'; $values[]=$webinarId; } $sql.=' ORDER BY created_at LIMIT 5';
    $q=$db->prepare($sql); $q->execute($values);
    foreach ($q->fetchAll() as $job) {
        if (!valid_uuid($job['worker_id'])) continue;
        try {
            $payload=transcription_remote_http($config,'GET','/api/transcriptions/'.$job['worker_id'],null,20);
            $status=transcription_remote_status_value($payload);
            if (in_array($status,['completed','complete','succeeded','success','done','finished'],true)) {
                try { transcription_remote_complete($db,$config,$job,$payload); }
                catch (ApiError $error) { transcription_fail($db,$job,$error->getMessage()); }
            } elseif (in_array($status,['failed','error'],true)) transcription_fail($db,$job,'VPS transcription failed. The previous transcript was retained.');
            elseif (in_array($status,['cancelled','canceled'],true)) transcription_fail($db,$job,'The VPS transcription job was cancelled. The previous transcript was retained.');
            else {
                $progress=$payload['progress'] ?? $payload['percent'] ?? null; $message='The VPS is transcribing this recording.';
                if (is_numeric($progress)) $message.=' Progress: '.max(0,min(100,(int)round((float)$progress))).'%.';
                $u=$db->prepare("UPDATE transcription_jobs SET status='running',phase='transcribing',message=?,heartbeat_at=UTC_TIMESTAMP(6) WHERE id=? AND status IN ('queued','running')"); $u->execute([$message,$job['id']]);
            }
        } catch (ApiError $error) {
            $u=$db->prepare("UPDATE transcription_jobs SET message=? WHERE id=? AND status IN ('queued','running')"); $u->execute([mb_substr($error->getMessage(),0,500),$job['id']]);
        }
    }
}
function transcription_request_api(PDO $db,array $config,?array $visitor,int $webinarId,string $method): never
{
    $registration=find_registration($db,$visitor,$webinarId);
    if (!$registration) throw new ApiError(401,'Register for this webinar to prepare its transcript.');
    if (($config['transcription_provider'] ?? 'local')!=='remote_api') throw new ApiError(503,'Automatic VPS transcription is not enabled.');
    transcription_remote_sync($db,$config,$webinarId); $ready=(bool)transcript_chunks($db,$webinarId);
    $q=$db->prepare("SELECT id,status,phase,message,created_at,finished_at FROM transcription_jobs WHERE webinar_id=? ORDER BY created_at DESC LIMIT 1"); $q->execute([$webinarId]); $job=$q->fetch() ?: null;
    if ($method==='GET') json_response(200,['ready'=>$ready,'job'=>$job]);
    if ($method!=='POST') throw new ApiError(405,'Method not allowed.');
    require_write_access($visitor); rate_limit($db,'viewer-transcription',6);
    if ($ready) json_response(200,['ready'=>true,'job'=>$job,'message'=>'Transcript is available.']);
    $queued=transcription_remote_queue($db,$config,$webinarId,null,transcription_uuid_v4());
    json_response(202,['ready'=>false,'job'=>['id'=>$queued['jobId'],'status'=>$queued['status'],'message'=>$queued['message']],'message'=>$queued['message']]);
}
function transcription_uuid_v4(): string
{
    $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&15)|64); $bytes[8]=chr((ord($bytes[8])&63)|128);
    $hex=bin2hex($bytes); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
}
function transcription_worker_status(PDO $db,array $config): array
{
    if (($config['transcription_provider'] ?? 'local')==='remote_api') {
        $model=(string)($config['transcription_model_label'] ?? 'faster-whisper VPS');
        try {
            $health=transcription_remote_http($config,'GET','/health',null,8,false); $healthy=($health['status'] ?? '')==='ok';
            $keyReady=is_string($config['transcription_api_key'] ?? null) && $config['transcription_api_key']!== '';
            return ['online'=>$healthy && $keyReady,'provider'=>'remote_api','model'=>$model,'device'=>'vps','computeType'=>null,'packageVersion'=>null,
                'state'=>$healthy?($keyReady?'ready':'authentication_required'):'offline',
                'message'=>$healthy?($keyReady?'VPS faster-whisper API is connected.':'VPS API is online, but its API key is not configured in PHP.'):'VPS transcription health check failed.'];
        } catch (ApiError $error) { return ['online'=>false,'provider'=>'remote_api','model'=>$model,'device'=>'vps','computeType'=>null,'packageVersion'=>null,'state'=>'offline','message'=>$error->getMessage()]; }
    }
    $row=$db->query('SELECT package_version,state,last_seen_at,(last_seen_at>UTC_TIMESTAMP(6)-INTERVAL 60 SECOND) AS online FROM transcription_worker_state WHERE id=1')->fetch();
    $online=$row && (bool)$row['online'];
    return ['online'=>$online,'provider'=>'local','model'=>$config['whisper_model'],'device'=>'cpu','computeType'=>'int8',
        'packageVersion'=>$row['package_version'] ?? null,'state'=>$online ? $row['state'] : 'offline',
        'message'=>$online?'Local faster-whisper worker is running.':'Worker offline. Run tools/start-transcription.ps1 from the XAMPP Task folder. Queued jobs will wait for it.'];
}
function transcription_provenance(PDO $db,int $id,string $hash): ?array
{
    $q=$db->prepare("SELECT model_name,language_detected,language_probability,finished_at FROM transcription_jobs WHERE webinar_id=? AND status='completed' AND result_transcript_hash=? ORDER BY finished_at DESC LIMIT 1"); $q->execute([$id,$hash]);
    $row=$q->fetch(); return $row ? ['engine'=>'faster-whisper','model'=>$row['model_name'],'language'=>$row['language_detected'],'notice'=>'Automatically transcribed from this recording. Speech recognition can make mistakes; review important terms against the video.'] : null;
}
function transcription_source(PDO $db,array $config,int $id): array
{
    $q=$db->prepare("SELECT a.* FROM webinar_assets a JOIN webinars w ON w.id=a.webinar_id WHERE a.webinar_id=? AND a.asset_type='video' AND a.is_active=1 AND w.is_active=1"); $q->execute([$id]); $asset=$q->fetch();
    $root=realpath($config['video_directory']); $relative=$asset['storage_path'] ?? '';
    if (!$root || !$asset || preg_match('/^(?:[a-z]:|[\\\\\/]|[a-z][a-z0-9+.-]*:)/i',$relative)) throw new ApiError(422,'Configure a local recording inside the private videos folder first.');
    $file=realpath($root.DIRECTORY_SEPARATOR.$relative);
    if (!$file || strncasecmp($file,$root.DIRECTORY_SEPARATOR,strlen($root)+1)!==0 || !is_file($file) || !is_readable($file)) throw new ApiError(422,'The selected recording is unavailable or outside the private videos folder.');
    clearstatcache(true,$file); $size=filesize($file);
    if (!$size || $size>(int)$config['whisper_max_video_bytes']) throw new ApiError(422,'The recording is empty or exceeds the configured transcription size limit.');
    return ['asset'=>$asset,'root'=>$root,'file'=>$file,'size'=>$size,'mtime'=>filemtime($file)];
}
function transcription_webinar_state(PDO $db,int $id,string $emptyState): void
{
    $q=$db->prepare('UPDATE webinars SET transcript_status=IF(EXISTS(SELECT 1 FROM webinar_transcripts WHERE webinar_id=?),\'ready\',?) WHERE id=?'); $q->execute([$id,$emptyState,$id]);
}
function transcription_api(PDO $db,array $config,string $method): never
{
    $admin=require_admin($db);
    if ($method==='GET') {
        $id=$_GET['webinar_id'] ?? null; $values=[]; $where='';
        if ($id!==null) {
            $id=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if (!$id) throw new ApiError(422,'Invalid webinar ID.');
            $where='WHERE j.webinar_id=?'; $values[]=$id;
        }
        transcription_remote_sync($db,$config,is_int($id)?$id:null);
        $q=$db->prepare("SELECT j.id,j.webinar_id,w.title,j.status,j.phase,j.model_name,j.language_requested,j.language_detected,j.language_probability,j.duration_seconds,j.processed_seconds,j.segment_count,j.message,j.created_at,j.started_at,j.finished_at FROM transcription_jobs j JOIN webinars w ON w.id=j.webinar_id $where ORDER BY j.created_at DESC LIMIT 20"); $q->execute($values);
        json_response(200,['jobs'=>$q->fetchAll(),'worker'=>transcription_worker_status($db,$config)]);
    }
    if ($method!=='POST') throw new ApiError(405,'Method not allowed.');
    require_write_access($admin); rate_limit($db,'transcription',10); $data=read_json();
    if (($data['action'] ?? '')==='cancel') {
        $jobId=$data['jobId'] ?? null; if (!valid_uuid($jobId)) throw new ApiError(422,'Invalid transcription job.');
        $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT * FROM transcription_jobs WHERE id=? FOR UPDATE'); $q->execute([$jobId]); $job=$q->fetch();
            if (!$job) throw new ApiError(404,'Transcription job not found.');
            if (in_array($job['status'],['completed','failed'],true)) throw new ApiError(409,'This job has already finished. Review its status and the current transcript.');
            if (in_array($job['status'],['queued','running'],true)) {
                $q=$db->prepare("UPDATE transcription_jobs SET status='cancelled',phase='cancelled',message='Cancelled by an administrator. The previous transcript was retained.',finished_at=UTC_TIMESTAMP(6) WHERE id=?"); $q->execute([$jobId]);
                transcription_webinar_state($db,(int)$job['webinar_id'],'not_configured');
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        json_response(200,['message'=>'Job cancelled. Existing transcript content is preserved.']);
    }
    if (($data['action'] ?? '')!=='start' || ($data['authorized'] ?? null)!==true) throw new ApiError(422,'Confirm permission to transcribe the selected recording.');
    $id=filter_var($data['webinarId'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$id || !valid_uuid($data['requestId'] ?? null)) throw new ApiError(422,'Select a webinar and send a valid request ID.');
    $language=$data['language'] ?? 'auto';
    if (!is_string($language) || !in_array($language,['auto','en','hi','mr','ta','te','bn','gu','kn','ml','pa','ur','fr','de','es','ja','ko','zh'],true)) throw new ApiError(422,'Choose a supported transcription language.');
    $provider=$config['transcription_provider'] ?? 'local';
    $model=$provider==='remote_api' ? (string)($config['transcription_model_label'] ?? 'faster-whisper-vps') : (string)$config['whisper_model'];
    if ($provider!=='remote_api' && !in_array($model,['tiny','base','small','medium','large-v3','turbo'],true)) throw new ApiError(503,'Configure a supported faster-whisper model in PHP settings.');
    $q=$db->prepare('SELECT id,webinar_id FROM transcription_jobs WHERE id=?'); $q->execute([$data['requestId']]); $existing=$q->fetch();
    if ($existing) {
        if ((int)$existing['webinar_id']!==$id) throw new ApiError(409,'This request ID belongs to another webinar.');
        json_response(200,['jobId'=>$existing['id'],'message'=>'This job has already been queued.']);
    }
    if ($provider==='remote_api') {
        $queued=transcription_remote_queue($db,$config,$id,(int)$admin['admin_id'],$data['requestId'],$language,(bool)($data['replaceExisting'] ?? false));
        json_response(202,['jobId'=>$queued['jobId'],'message'=>$queued['message']]);
    }
    $source=transcription_source($db,$config,$id); transcript_lock($db,$id); $chunks=transcript_chunks($db,$id);
    if ($chunks && ($data['replaceExisting'] ?? null)!==true) throw new ApiError(422,'Confirm replacement of the current transcript when transcription completes.');
    $q=$db->prepare("SELECT id FROM transcription_jobs WHERE webinar_id=? AND status IN ('queued','running') ORDER BY created_at LIMIT 1"); $q->execute([$id]);
    if ($job=$q->fetchColumn()) json_response(202,['jobId'=>$job,'message'=>'A transcription job is already queued for this webinar.']);
    $db->beginTransaction();
    try {
        $q=$db->prepare('INSERT INTO transcription_jobs(id,webinar_id,asset_id,requested_by,language_requested,model_name,source_path,source_size,source_mtime,base_transcript_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$data['requestId'],$id,$source['asset']['id'],$admin['admin_id'],$language,$model,$source['asset']['storage_path'],$source['size'],$source['mtime'],transcript_hash($chunks)]);
        transcription_webinar_state($db,$id,'pending'); $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    json_response(202,['jobId'=>$data['requestId'],'message'=>'Recording queued. The transcript will be saved automatically when local transcription completes.']);
}

/** CLI bridge: only the local Python worker calls these functions. No HTTP route. */
function transcription_worker(PDO $db,array $config,array $data): array
{
    $action=$data['action'] ?? ''; $worker=$data['workerId'] ?? '';
    if (!valid_uuid($worker)) throw new ApiError(422,'Invalid worker ID.');
    if ($action==='claim') {
        $q=$db->prepare('SELECT GET_LOCK(?,1)'); $q->execute(['webinar-transcription-claim']);
        if ((int)$q->fetchColumn()!==1) return ['job'=>null];
        $stale=$db->query("SELECT id,webinar_id FROM transcription_jobs WHERE status='running' AND heartbeat_at<UTC_TIMESTAMP(6)-INTERVAL 3 MINUTE")->fetchAll();
        foreach ($stale as $row) {
            $q=$db->prepare("UPDATE transcription_jobs SET status='failed',phase='interrupted',message='Worker interrupted. Start the worker and retry transcription.',finished_at=UTC_TIMESTAMP(6) WHERE id=? AND status='running'"); $q->execute([$row['id']]);
            transcription_webinar_state($db,(int)$row['webinar_id'],'failed');
        }
        if ((int)$db->query("SELECT COUNT(*) FROM transcription_jobs WHERE status='running'")->fetchColumn()) return ['job'=>null];
        $job=$db->query("SELECT * FROM transcription_jobs WHERE status='queued' ORDER BY created_at,id LIMIT 1")->fetch();
        if (!$job) return ['job'=>null];
        try { $source=transcription_source($db,$config,(int)$job['webinar_id']); }
        catch (ApiError $e) { transcription_fail($db,$job,$e->getMessage()); return ['job'=>null]; }
        if ($source['asset']['id']!=$job['asset_id'] || $source['asset']['storage_path']!==$job['source_path'] || $source['size']!=$job['source_size'] || $source['mtime']!=$job['source_mtime']) { transcription_fail($db,$job,'The video changed after queueing. Queue the current recording again.'); return ['job'=>null]; }
        $lease=bin2hex(random_bytes(32));
        $q=$db->prepare("UPDATE transcription_jobs SET status='running',phase='loading_model',worker_id=?,lease_token=?,started_at=UTC_TIMESTAMP(6),heartbeat_at=UTC_TIMESTAMP(6),message='Loading the local speech model.' WHERE id=? AND status='queued'"); $q->execute([$worker,$lease,$job['id']]);
        if (!$q->rowCount()) return ['job'=>null];
        transcription_webinar_state($db,(int)$job['webinar_id'],'processing');
        return ['job'=>['id'=>$job['id'],'webinarId'=>(int)$job['webinar_id'],'lease'=>$lease,'file'=>$source['file'],'videoRoot'=>$source['root'],
            'sourceSize'=>(int)$job['source_size'],'sourceMtime'=>(int)$job['source_mtime'],'model'=>$job['model_name'],'language'=>$job['language_requested'],
            'cpuThreads'=>max(1,min(8,(int)$config['whisper_cpu_threads'])),'maxDuration'=>(int)$config['whisper_max_duration_seconds']]];
    }
    if ($action==='heartbeat') {
        $q=$db->prepare('INSERT INTO transcription_worker_state(id,worker_id,package_version,state) VALUES(1,?,?,?) ON DUPLICATE KEY UPDATE worker_id=VALUES(worker_id),package_version=VALUES(package_version),state=VALUES(state),last_seen_at=UTC_TIMESTAMP(6)');
        $q->execute([$worker,mb_substr((string)($data['version'] ?? 'unknown'),0,40),($data['jobId'] ?? null)?'transcribing':'idle']);
        if (empty($data['jobId'])) return ['active'=>true];
    }
    if (!valid_uuid($data['jobId'] ?? null) || !is_string($data['lease'] ?? null)) throw new ApiError(422,'Missing job lease.');
    $q=$db->prepare('SELECT * FROM transcription_jobs WHERE id=? AND worker_id=? AND lease_token=?'); $q->execute([$data['jobId'],$worker,$data['lease']]); $job=$q->fetch();
    if (!$job || $job['status']!=='running') return ['active'=>false];
    if ($action==='heartbeat') {
        $progress=max(0,min(86400,(float)($data['processedSeconds'] ?? 0))); $duration=max(0,min(86400,(float)($data['duration'] ?? 0)));
        $q=$db->prepare('UPDATE transcription_jobs SET heartbeat_at=UTC_TIMESTAMP(6),processed_seconds=GREATEST(processed_seconds,?),duration_seconds=IF(?>0,?,duration_seconds),segment_count=?,phase=?,message=? WHERE id=? AND status=\'running\'');
        $phase=in_array($data['phase'] ?? '',['loading_model','transcribing','saving'],true)?$data['phase']:'transcribing';
        $message=['loading_model'=>'Loading the local speech model.','transcribing'=>'Recognizing speech locally.','saving'=>'Saving timestamped transcript.'][$phase];
        $q->execute([$progress,$duration,$duration,max(0,min(50000,(int)($data['segments'] ?? 0))),$phase,$message,$job['id']]);
        return ['active'=>true];
    }
    if ($action==='fail') { transcription_fail($db,$job,mb_substr((string)($data['message'] ?? 'Local transcription failed.'),0,500)); return ['active'=>false]; }
    if ($action!=='complete') throw new ApiError(422,'Unknown worker action.');
    transcript_lock($db,(int)$job['webinar_id']);
    $source=transcription_source($db,$config,(int)$job['webinar_id']);
    if ($source['asset']['id']!=$job['asset_id'] || $source['asset']['storage_path']!==$job['source_path'] || $source['size']!=$job['source_size'] || $source['mtime']!=$job['source_mtime']) throw new ApiError(409,'The video changed during transcription. Queue the new recording.');
    if (transcript_hash(transcript_chunks($db,(int)$job['webinar_id']))!==$job['base_transcript_hash']) throw new ApiError(409,'The transcript was edited while speech recognition was running. That edit was preserved.');
    $hash=$data['sourceSha256'] ?? ''; if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D',$hash) || !hash_equals($hash,hash_file('sha256',$source['file']))) throw new ApiError(409,'The recording content changed during transcription.');
    $vtt=$data['vtt'] ?? null; if (!is_string($vtt) || strlen($vtt)>1500000) throw new ApiError(422,'Generated transcript exceeds the import limit. Split this recording into shorter sessions.');
    $chunks=parse_transcript($vtt,'vtt'); $duration=(float)($data['duration'] ?? 0);
    if (!is_finite($duration) || $duration<=0 || $duration>(int)$config['whisper_max_duration_seconds']) throw new ApiError(422,'Recording duration exceeds the configured transcription limit.');
    $language=$data['language'] ?? ''; if (!is_string($language) || !preg_match('/^[a-z]{2,3}$/D',$language)) throw new ApiError(422,'Invalid detected language.');
    foreach ($chunks as $chunk) if ($chunk['end']>$duration+1) throw new ApiError(422,'Generated timestamps exceed the recording duration.');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT status FROM transcription_jobs WHERE id=? FOR UPDATE'); $q->execute([$job['id']]);
        if ($q->fetchColumn()!=='running') throw new ApiError(409,'This transcription job is no longer active.');
        $name=mb_substr('faster-whisper '.$job['model_name'].' · '.basename($source['file']),0,150);
        replace_transcript($db,(int)$job['webinar_id'],$chunks,$name,'vtt',$job['requested_by']===null?null:(int)$job['requested_by']);
        $resultHash=transcript_hash(transcript_chunks($db,(int)$job['webinar_id']));
        $q=$db->prepare("UPDATE transcription_jobs SET status='completed',phase='completed',source_sha256=?,result_transcript_hash=?,language_detected=?,language_probability=?,duration_seconds=?,processed_seconds=?,segment_count=?,message='Transcript saved automatically. Review speech recognition against the recording.',finished_at=UTC_TIMESTAMP(6),heartbeat_at=UTC_TIMESTAMP(6) WHERE id=?");
        $q->execute([$hash,$resultHash,$language,max(0,min(1,(float)($data['languageProbability'] ?? 0))),$duration,$duration,max(1,(int)($data['segments'] ?? 1)),$job['id']]); $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    return ['active'=>false,'completed'=>true,'chunkCount'=>count($chunks)];
}
function transcription_fail(PDO $db,array $job,string $message): void
{
    $q=$db->prepare("UPDATE transcription_jobs SET status='failed',phase='failed',message=?,finished_at=UTC_TIMESTAMP(6) WHERE id=? AND status IN ('queued','running')"); $q->execute([mb_substr($message,0,500),$job['id']]);
    transcription_webinar_state($db,(int)$job['webinar_id'],'failed');
}
