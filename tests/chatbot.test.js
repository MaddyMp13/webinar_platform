'use strict';
// Real Apache/MySQL/Ollama integration, plus DOM checks with no browser rendering.
const {test}=require('node:test');
const assert=require('node:assert/strict');
const {randomUUID,randomBytes}=require('node:crypto');
const {execFileSync}=require('node:child_process');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {JSDOM}=require('../.qa/node_modules/jsdom');
const root=path.join(__dirname,'..'),base=new URL(process.env.WEBINAR_TEST_URL || 'http://localhost/Task/');
const php=process.env.PHP_BIN || 'C:/xampp/php/php.exe';
test('authorized import, local AI, sources, history, isolation, summaries and UI', {timeout:600000},async t=>{
  const email=`qa-chatbot-${Date.now()}@example.test`,password=randomBytes(20).toString('hex');
  const fixture=mode=>JSON.parse(execFileSync(php,[path.join(__dirname,'chatbot-fixtures.php'),mode],{input:JSON.stringify({email,password}),encoding:'utf8'}));
  let visitorCookie='',csrf='',adminCookie='',adminCsrf='';const windows=[];
  const url=(resource,id,extra={})=>{const value=new URL('api/index.php',base);value.searchParams.set('resource',resource);if(id)value.searchParams.set('webinar_id',id);for(const [k,v] of Object.entries(extra))value.searchParams.set(k,v);return value;};
  async function request(resource,id,data,options={}){
    const response=await fetch(url(resource,id,options.extra),{method:options.method || (data?'POST':'GET'),headers:{Cookie:options.admin?adminCookie:visitorCookie,Origin:base.origin,'X-CSRF-Token':options.admin?adminCsrf:csrf,...(data?{'Content-Type':'application/json'}:{}),...options.headers},...(data?{body:JSON.stringify(data)}:{})});
    const cookie=response.headers.get('set-cookie');if(cookie){const token=cookie.split(';')[0];if(token.startsWith('webinar_admin_session='))adminCookie=token;else visitorCookie=token;}
    return response;
  }
  async function json(resource,id,data,options={}){const res=await request(resource,id,data,options),body=await res.json();assert.ok(res.ok,`${resource} ${res.status}: ${body.message || ''}`);if(body.csrfToken){if(options.admin)adminCsrf=body.csrfToken;else csrf=body.csrfToken;}return body;}
  const {ids:[id,otherId]}=fixture('create');
  t.after(()=>{for(const win of windows)win.close();fixture('cleanup');});
  await json('admin_status');const auth=await json('admin_login',null,{email,password});adminCsrf=auth.csrfToken;
  assert.equal((await request('transcript_manage')).status,401);
  const management=await json('transcript_manage',null,null,{admin:true});
  assert.equal(management.assistant.available,true,management.assistant.message);assert.equal(management.assistant.mode,'ollama');
  const upload={webinarId:id,fileName:'Temporary authorized QA.vtt',format:'vtt',authorized:true,text:'WEBVTT\n\n00:00.000 --> 00:08.000\nA recovery plan uses verified backups.\n\n00:10.000 --> 00:20.000\nTest the restoration procedure before deployment. The main topic is data recovery.'};
  assert.equal((await request('transcript_manage',null,{...upload,authorized:false},{admin:true})).status,422);
  assert.equal((await request('transcript_manage',null,upload,{admin:true,headers:{'X-CSRF-Token':'wrong'}})).status,403);
  const imported=await json('transcript_manage',null,upload,{admin:true});assert.equal(imported.chunkCount,1);assert.equal(imported.timestamped,true);
  assert.equal((await request('transcript',id)).status,401);
  await json('registration',id);
  const lead={firstName:'QA',lastName:'Chatbot',email,phone:'+91 98765 43210',company:'Temporary QA',country:'IN',jobTitle:'Tester',privacyConsent:true,marketingOptIn:false,activityConsent:false};
  await json('registration',id,lead);
  const transcript=await json('transcript',id);assert.equal(transcript.chunks[0].start_seconds,'0.000');
  assert.equal((await request('chat',id,{question:'What uses verified backups?',requestId:randomUUID()})).status,403);
  assert.equal((await json('transcript',id,{search:'verified',offset:0})).total,1);
  assert.equal((await json('transcript',id,{search:'nonexistent zeppelin',offset:0})).total,0);
  await json('registration',id,{...lead,activityConsent:true});
  const status=await json('chat',id);assert.equal(status.transcriptReady,true);assert.equal(status.mode,'ollama');
  const question={question:'What does a recovery plan use?',requestId:randomUUID(),newConversation:true};
  const first=await json('chat',id,question);assert.equal(first.messages.length,2);assert.match(first.messages[1].message,/verified backups/i);assert.equal(first.messages[1].model_name,'qwen2.5:1.5b');assert.ok(first.messages[1].sources[0].quote);
  assert.equal((await json('chat',id,question)).messages.length,2);
  assert.equal((await request('chat',id,{...question,question:'A different recovery question?'})).status,409);
  assert.equal((await json('chat',id)).conversationId,first.conversationId);
  await json('registration',otherId,{...lead,activityConsent:true});
  assert.equal((await request('chat',otherId,null,{extra:{conversation_id:first.conversationId}})).status,404);
  assert.equal((await request('chat',otherId,{question:'What does a recovery plan use?',requestId:question.requestId})).status,409);
  assert.equal((await json('transcript',otherId)).total,0);
  const absent=await json('chat',id,{question:'Quantum penguin zeppelins?',requestId:randomUUID(),conversationId:first.conversationId});assert.match(absent.messages.at(-1).message,/couldn’t find enough information/);assert.equal(absent.messages.at(-1).sources.length,0);
  const newChat=await json('chat',id,{question:'Unrelated cobalt galaxies?',requestId:randomUUID(),newConversation:true});assert.notEqual(newChat.conversationId,first.conversationId);assert.equal(newChat.messages.length,2);
  let summary=await json('insights',id);assert.equal(summary.totalParts,1);
  const generated=await json('insights',id,{part:0,version:summary.version});assert.ok(generated.result.items.length);assert.ok(generated.result.items.every(item=>item.sources.length));
  summary=await json('insights',id);assert.ok(summary.parts[0]);
  const cached=await json('insights',id,{part:0,version:summary.version});assert.equal(cached.result.generatedAt,generated.result.generatedAt);
  await t.test('chat DOM reads history, transcript search, source seeking and saved summary',async()=>{
    const dom=new JSDOM('<video id="webinar-video"></video><div id="chat-host"></div>',{url:new URL('webinar.html',base).href,runScripts:'outside-only'});windows.push(dom.window);
    const win=dom.window,doc=win.document;Object.defineProperty(doc.querySelector('video'),'duration',{value:60});Object.defineProperty(win.crypto,'randomUUID',{value:randomUUID});
    win.fetch=async(endpoint,options={})=>fetch(endpoint,{...options,headers:{...(options.headers || {}),Origin:base.origin,Cookie:visitorCookie}});
    for(const file of ['backend.js','chat.js'])vm.runInContext(fs.readFileSync(path.join(root,file),'utf8'),dom.getInternalVMContext());
    await vm.runInContext(`WebinarAPI.request('registration',{webinar_id:${id}}).then(()=>WebinarChat.mount(document.querySelector('#chat-host'),{id:${id}},{activityConsent:true}))`,dom.getInternalVMContext());
    async function waitFor(fn){for(let i=0;i<500;i++){if(fn())return;await new Promise(r=>setTimeout(r,10));}throw new Error('DOM timed out');}
    assert.equal(doc.querySelectorAll('#chat-conversation option').length,3);
    const select=doc.querySelector('#chat-conversation');select.value=first.conversationId;select.dispatchEvent(new win.Event('change'));await waitFor(()=>doc.querySelector('.source-link'));
    doc.querySelector('[data-seek]').click();assert.equal(doc.querySelector('video').currentTime,0);
    doc.querySelector('#tab-transcript').click();await waitFor(()=>doc.querySelector('.transcript-passage'));
    doc.querySelector('#transcript-query').value='no such phrase';doc.querySelector('#transcript-search').dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true}));await waitFor(()=>doc.querySelector('#transcript-count').textContent.includes('No matching'));
    doc.querySelector('#tab-summary').click();await waitFor(()=>doc.querySelector('.summary-point'));assert.match(doc.querySelector('#summary-status').textContent,/complete/);
    doc.querySelector('#tab-ask').click();doc.querySelector('#chat-new').click();assert.equal(doc.querySelector('#chat-conversation').value,'');assert.ok(doc.querySelector('.chat-welcome'));
    doc.querySelector('#chat-question').value='Unrelated ultraviolet galaxies?';doc.querySelector('#chat-form').dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true}));
    await waitFor(()=>doc.querySelector('.from-assistant') && !doc.querySelector('#chat-question').disabled);assert.equal(doc.querySelector('#chat-question').value,'');assert.match(doc.querySelector('.from-assistant').textContent,/couldn’t find enough information/);
  });
  await json('transcript_manage',null,{...upload,text:upload.text.replace('verified backups','encrypted archives')},{admin:true});
  const archived=await json('chat',id,null,{extra:{conversation_id:first.conversationId}});assert.equal(archived.messages[1].sources[0].transcript_chunk_id,null);assert.ok(archived.messages[1].sources[0].quote);
  assert.equal(Object.keys((await json('insights',id)).parts).length,0);
  const dashboard=await json('dashboard',null,{view:'chat',filters:{search:email,webinarId:id}},{admin:true});assert.equal(dashboard.summary.conversations,3);assert.equal(dashboard.summary.questions,4);
  const exported=await request('dashboard_export',null,{view:'chat',filters:{search:email,webinarId:id}},{admin:true});assert.equal(exported.status,200);const csv=await exported.text();assert.match(csv,/verified backups/);assert.match(csv,/sources/);assert.match(csv,/qwen2.5:1.5b/);
  await json('registration',id,{...lead,activityConsent:false});assert.equal((await json('chat',id)).messages.length,0);
});
