<?php
declare(strict_types=1);

function transcription_worker_status(PDO $db,array $config): array
{
    $row=$db->query('SELECT package_version,state,last_seen_at,(last_seen_at>UTC_TIMESTAMP(6)-INTERVAL 60 SECOND) AS online FROM transcription_worker_state WHERE id=1')->fetch();
    $online=$row && (bool)$row['online'];
    return ['online'=>$online,'model'=>$config['whisper_model'],'device'=>'cpu','computeType'=>'int8',
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
    $model=$config['whisper_model'];
    if (!in_array($model,['tiny','base','small','medium','large-v3','turbo'],true)) throw new ApiError(503,'Configure a supported faster-whisper model in PHP settings.');
    $q=$db->prepare('SELECT id,webinar_id FROM transcription_jobs WHERE id=?'); $q->execute([$data['requestId']]); $existing=$q->fetch();
    if ($existing) {
        if ((int)$existing['webinar_id']!==$id) throw new ApiError(409,'This request ID belongs to another webinar.');
        json_response(200,['jobId'=>$existing['id'],'message'=>'This job has already been queued.']);
    }
    $source=transcription_source($db,$config,$id); transcript_lock($db,$id);
    $chunks=transcript_chunks($db,$id);
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
