<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/bootstrap.php';
require_once dirname(__DIR__).'/api/transcripts.php';
require_once dirname(__DIR__).'/api/assistant.php';
function check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
function rejects(callable $fn,string $label): void { try { $fn(); } catch (ApiError) { check(true,$label); return; } throw new RuntimeException($label); }
$vtt="WEBVTT\n\n00:00.000 --> 00:08.500\n<v Speaker>Recovery plans use verified backups.</v>\n\n00:10.000 --> 00:19.000\nTest the restoration procedure before production use.";
$parsed=parse_transcript($vtt,'vtt'); check(count($parsed)===1 && $parsed[0]['start']===0.0 && $parsed[0]['end']===19.0,'VTT timestamps and speaker tags');
$srt="1\n01:02:03,456 --> 01:02:08,000\nRecovery plans use verified backups.";
$s=parse_transcript($srt,'srt'); check($s[0]['start']===3723.456,'SRT hours and millisecond commas');
$text=str_repeat('सुरक्षित डेटा पुनर्प्राप्ति की प्रक्रिया। ',120);
$p=parse_transcript($text,'txt'); check(count($p)>1 && mb_check_encoding(implode('',array_column($p,'text')),'UTF-8') && $p[0]['start']===null,'Unicode chunking without fabricated timestamps');
rejects(fn()=>parse_transcript('This is a sufficient body of text.','html'),'reject unsupported format');
rejects(fn()=>parse_transcript("00:04.000 --> 00:02.000\nA sufficiently long caption body.",'vtt'),'reject reversed times');
rejects(fn()=>parse_transcript("00:72.000 --> 00:80.000\nA sufficiently long caption body.",'srt'),'reject malformed minutes');
rejects(fn()=>parse_transcript("\xFF\xFE invalid UTF-8 transcript body.",'txt'),'reject invalid UTF-8');
$chunks=[['id'=>11,'chunk_index'=>0,'chunk_text'=>'Recovery plans use verified backups.','source_reference'=>'Test','start_seconds'=>0,'end_seconds'=>8],['id'=>22,'chunk_index'=>1,'chunk_text'=>'The speaker discusses garden plants.','source_reference'=>'Test','start_seconds'=>9,'end_seconds'=>18]];
$ranked=ranked_chunks($chunks,'What are verified recovery backups?'); check(count($ranked)===1 && $ranked[0]['id']===11,'rank relevant chunks');
check(ranked_chunks($chunks,'Quantum penguin zeppelins?')===[],'no unrelated source retrieval');
check(count(validate_evidence([['chunkId'=>11,'quote'=>'use verified backups']],[$chunks[0]]))===1,'accept exact supporting source quote');
rejects(fn()=>validate_evidence([['chunkId'=>22,'quote'=>'use verified backups']],[$chunks[0]]),'reject source outside retrieval');
rejects(fn()=>validate_evidence([['chunkId'=>11,'quote'=>'unverified automatic policy']],[$chunks[0]]),'reject fabricated source quote');
rejects(fn()=>ollama_http(['ollama_url'=>'http://example.com'],'/api/tags'),'reject non-local provider address');
$parts=insight_parts(array_fill(0,25,$chunks[0])); check(count($parts)===1,'summary grouping covers input');
echo "Chatbot parser, retrieval, source and local-provider checks passed.\n";
