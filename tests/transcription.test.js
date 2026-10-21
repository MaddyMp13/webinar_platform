'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict');
const {randomUUID,randomBytes}=require('node:crypto'),{execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),vm=require('node:vm');
const {JSDOM}=require('../.qa/node_modules/jsdom');
const base=new URL(process.env.WEBINAR_TEST_URL || 'http://localhost/Task/'),php='C:/xampp/php/php.exe';
const python=path.join(os.homedir(),'.webinar-transcriber','venv','Scripts','python.exe');

test('local speech -> background queue -> timestamped database transcript -> chatbot', {timeout:300000},async t=>{
 const stamp=Date.now(),email=`qa-chatbot-${stamp}@example.test`,password=randomBytes(20).toString('hex');
 const fixture=(file,mode,extra={})=>JSON.parse(execFileSync(php,[path.join(__dirname,file),...(mode?[mode]:[])],{input:JSON.stringify({email,password,...extra}),encoding:'utf8'}));
 const {ids:[id,otherId]}=fixture('chatbot-fixtures.php','create');
 const name=`qa-transcription-${stamp}.mp4`,video=path.join('C:/xampp/htdocs/Task/videos',name),wav=path.join(__dirname,'..','.qa',`qa-transcription-${stamp}.wav`);const windows=[];
 t.after(()=>{for(const win of windows)win.close();fixture('chatbot-fixtures.php','cleanup');for(const file of [video,wav])if(fs.existsSync(file))fs.unlinkSync(file);});
 execFileSync('powershell',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(__dirname,'create-transcription-audio.ps1'),'-Destination',wav]);
 execFileSync(python,[path.join(__dirname,'create-transcription-video.py'),wav,video]);
 fixture('transcription-assets.php',null,{id,file:name});fixture('transcription-assets.php',null,{id:otherId,mode:'escape'});
 let cookie='',csrf='',adminCookie='',adminCsrf='';
 const url=(resource,parameters={})=>{const u=new URL('api/index.php',base);u.searchParams.set('resource',resource);for(const [k,v] of Object.entries(parameters))u.searchParams.set(k,v);return u;};
 async function request(resource,data,options={}){
  const r=await fetch(url(resource,options.params),{method:data?'POST':'GET',headers:{Cookie:options.admin?adminCookie:cookie,Origin:base.origin,'X-CSRF-Token':options.admin?adminCsrf:csrf,...(data?{'Content-Type':'application/json'}:{}),...options.headers},...(data?{body:JSON.stringify(data)}:{})});
  const c=r.headers.get('set-cookie');if(c){if(c.startsWith('webinar_admin_session='))adminCookie=c.split(';')[0];else cookie=c.split(';')[0];}return r;
 }
 async function json(resource,data,options={}){const r=await request(resource,data,options),body=await r.json();assert.ok(r.ok,`${resource} ${r.status}: ${body.message || ''}`);if(body.csrfToken){if(options.admin)adminCsrf=body.csrfToken;else csrf=body.csrfToken;}return body;}
 assert.equal((await request('transcription')).status,401);
 await json('admin_status');adminCsrf=(await json('admin_login',{email,password})).csrfToken;
 const management=await json('transcript_manage',null,{admin:true});assert.equal(management.transcription.online,true,'start the local transcription worker first');
 const start={action:'start',webinarId:id,requestId:randomUUID(),authorized:true,replaceExisting:false,language:'en'};
 assert.equal((await request('transcription',{...start,authorized:false},{admin:true})).status,422);
 assert.equal((await request('transcription',start,{admin:true,headers:{'X-CSRF-Token':'wrong'}})).status,403);
 assert.equal((await request('transcription',{...start,webinarId:otherId,requestId:randomUUID()},{admin:true})).status,422,'reject video paths outside private directory');
 const queued=await json('transcription',start,{admin:true});assert.equal((await json('transcription',start,{admin:true})).jobId,queued.jobId);
 let job;
 for(let i=0;i<150;i++){
  const status=await json('transcription',null,{admin:true,params:{webinar_id:id}});job=status.jobs.find(j=>j.id===queued.jobId);assert.ok(job);assert.equal('lease_token' in job,false);assert.equal('source_path' in job,false);
  if(['completed','failed','cancelled'].includes(job.status))break;await new Promise(r=>setTimeout(r,1000));
 }
 assert.equal(job.status,'completed',job.message);assert.equal(job.language_detected,'en');assert.ok(Number(job.duration_seconds)>5);assert.ok(Number(job.segment_count)>0);
 assert.equal((await request('transcription',{action:'cancel',jobId:job.id},{admin:true})).status,409,'completed transcription cannot be cancelled');
 await json('registration',null,{params:{webinar_id:id}});
 await json('registration',{firstName:'QA',lastName:'Speech',email,phone:'+91 98765 43210',company:'Temporary speech fixture',country:'IN',jobTitle:'Tester',privacyConsent:true,marketingOptIn:false,activityConsent:true},{params:{webinar_id:id}});
 const transcript=await json('transcript',null,{params:{webinar_id:id}});assert.match(transcript.chunks.map(c=>c.chunk_text).join(' '),/verified backups/i);assert.ok(transcript.chunks.every(c=>c.start_seconds!==null));assert.equal(transcript.transcription.engine,'faster-whisper');
 const answer=await json('chat',{question:'What does a recovery plan use?',requestId:randomUUID()},{params:{webinar_id:id}});assert.match(answer.messages.at(-1).message,/verified backups/i);assert.ok(answer.messages.at(-1).sources.length);
 const retry={...start,requestId:randomUUID()};assert.equal((await request('transcription',retry,{admin:true})).status,422,'protect an existing transcript from unconfirmed replacement');
 const second=await json('transcription',{...retry,replaceExisting:true},{admin:true});await json('transcription',{action:'cancel',jobId:second.jobId},{admin:true});
 const retained=await json('transcript',null,{params:{webinar_id:id}});assert.equal(retained.version,transcript.version,'cancelling keeps the previous transcript');
 await t.test('transcript manager shows local worker, source consent and completed jobs',async()=>{
   const dom=new JSDOM(fs.readFileSync(path.join(__dirname,'..','transcripts.html'),'utf8'),{url:new URL('transcripts.html',base).href,runScripts:'outside-only'});windows.push(dom.window);
   const win=dom.window,doc=win.document;Object.defineProperty(win.crypto,'randomUUID',{value:randomUUID});win.fetch=(endpoint,options={})=>fetch(endpoint,{...options,headers:{...(options.headers || {}),Cookie:adminCookie,Origin:base.origin}});
   for(const file of ['backend.js','transcripts.js'])vm.runInContext(fs.readFileSync(path.join(__dirname,'..',file),'utf8'),dom.getInternalVMContext());
   async function waitFor(fn){for(let i=0;i<500;i++){if(fn())return;await new Promise(r=>setTimeout(r,10));}throw new Error('transcription manager DOM timed out');}
   await waitFor(()=>doc.querySelector('#transcribe-webinar')?.options.length>0);const select=doc.querySelector('#transcribe-webinar');select.value=String(id);select.dispatchEvent(new win.Event('change'));await waitFor(()=>doc.querySelector('.state-completed'));
   assert.match(doc.querySelector('#whisper-status').textContent,/running/);assert.equal(doc.querySelector('#transcribe-replace').required,true);assert.equal(doc.querySelector('#transcribe-authorized').checked,false);
   await waitFor(()=>doc.querySelector('#transcription-jobs').getAttribute('aria-busy')==='false');
   const editSelect=doc.querySelector('#import-webinar');editSelect.value=String(id);editSelect.dispatchEvent(new win.Event('change'));doc.querySelector('#load-current-transcript').click();await waitFor(()=>doc.querySelector('#import-text').value.includes('verified backups')&&!doc.querySelector('#load-current-transcript').disabled);assert.equal(doc.querySelector('#import-format').value,'vtt');
 });
 const conflictJob=await json('transcription',{...start,requestId:randomUUID(),replaceExisting:true},{admin:true});
 const corrected={webinarId:id,fileName:'Temporary reviewed QA.txt',format:'txt',authorized:true,expectedVersion:transcript.version,text:'An administrator corrected this transcript during speech recognition. Recovery plans use a separately verified archive.'};
 await json('transcript_manage',corrected,{admin:true});
 let conflict;
 for(let i=0;i<120;i++){conflict=(await json('transcription',null,{admin:true,params:{webinar_id:id}})).jobs.find(j=>j.id===conflictJob.jobId);if(['failed','completed'].includes(conflict.status))break;await new Promise(r=>setTimeout(r,1000));}
 assert.equal(conflict.status,'failed');assert.match(conflict.message,/edited/);assert.match((await json('transcript',null,{params:{webinar_id:id}})).chunks[0].chunk_text,/administrator corrected/);
 assert.equal((await request('transcript_manage',corrected,{admin:true})).status,409,'reject saving a stale editing version');
});
