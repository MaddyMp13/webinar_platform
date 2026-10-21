<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/transcripts.php';
require_once dirname(__DIR__).'/api/transcription.php';

function check_remote(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
function rejects_remote(callable $fn,string $label): void { try { $fn(); } catch (ApiError) { check_remote(true,$label); return; } throw new RuntimeException($label); }

$config=['transcription_api_url'=>'https://transcribe.arkentechsolutions.com','transcription_api_allowed_hosts'=>['transcribe.arkentechsolutions.com'],'transcription_api_key_header'=>'X-API-Key','transcription_api_key'=>'test'];
[$base,$header,$key]=transcription_remote_endpoint($config);
check_remote($base==='https://transcribe.arkentechsolutions.com' && $header==='X-API-Key' && $key==='test','validate allowlisted HTTPS endpoint');
rejects_remote(fn()=>transcription_remote_endpoint([...$config,'transcription_api_url'=>'http://transcribe.arkentechsolutions.com']),'reject insecure endpoint');
rejects_remote(fn()=>transcription_remote_endpoint([...$config,'transcription_api_key'=>'']),'require server-side API key');

$timed=transcription_remote_result(['status'=>'completed','result'=>['language'=>'en','language_probability'=>0.97,'segments'=>[
    ['start'=>0.2,'end'=>2.4,'text'=>'Verified backups protect recovery plans.'],
    ['start'=>2.5,'end'=>5.0,'text'=>'Test restoration every quarter.'],
]]]);
check_remote($timed['format']==='vtt' && $timed['language']==='en' && $timed['segments']===2 && $timed['chunks'][0]['start']===0.2,'parse timestamped VPS result');
$plain=transcription_remote_result(['status'=>'completed','text'=>'This is a sufficiently long transcript returned without timestamps.']);
check_remote($plain['format']==='txt' && $plain['chunks'][0]['start']===null,'fall back to plain transcript text');
rejects_remote(fn()=>transcription_remote_result(['status'=>'completed','segments'=>[['start'=>4,'end'=>2,'text'=>'Invalid timestamps']]]),'reject invalid VPS timestamps');
echo "Remote faster-whisper adapter checks passed.\n";
