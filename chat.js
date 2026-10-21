'use strict';

const WebinarChat = (() => {
  const escape = value=>String(value ?? '').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const time = value=>{const n=Math.max(0,Math.floor(Number(value)));return `${Math.floor(n/60)}:${String(n%60).padStart(2,'0')}`;};
  const sources = list=>(list || []).length?`<ul class="chat-sources">${list.map(s=>`<li>${s.start_seconds!==null && s.transcript_chunk_id!==null?`<button class="source-link" type="button" data-seek="${Number(s.start_seconds)}">${time(s.start_seconds)} · ${escape(s.source_label)}</button>`:`<span>${escape(s.source_label)}${s.transcript_chunk_id===null?' · previous transcript version':''}</span>`}${s.quote?`<blockquote>${escape(s.quote)}</blockquote>`:''}</li>`).join('')}</ul>`:'';
  async function mount(host,webinar,registration) {
    host.innerHTML=`<section class="chat-panel" aria-label="Webinar assistant"><div class="video-heading"><h2>Explore this webinar</h2><span class="badge" id="assistant-badge">Local assistant</span></div><p class="body-copy" id="chat-status" role="status">Checking transcript and local model…</p><div class="assistant-tabs" role="tablist" aria-label="Webinar resources"><button type="button" id="tab-ask" role="tab" aria-controls="panel-ask" aria-selected="true" data-tab="ask">Ask the assistant</button><button type="button" id="tab-transcript" role="tab" aria-controls="panel-transcript" aria-selected="false" tabindex="-1" data-tab="transcript">Transcript</button><button type="button" id="tab-summary" role="tab" aria-controls="panel-summary" aria-selected="false" tabindex="-1" data-tab="summary">Summary & takeaways</button></div><section role="tabpanel" id="panel-ask" aria-labelledby="tab-ask"><div class="conversation-tools"><label for="chat-conversation">Saved conversations</label><select id="chat-conversation"><option value="">New conversation</option></select><button class="text-button" id="chat-new" type="button">+ New chat</button></div><div id="chat-messages" class="chat-messages" role="log" aria-live="polite"></div><p class="assistant-hint" id="chat-hint"></p><form id="chat-form" hidden><label for="chat-question">Ask about this webinar</label><textarea id="chat-question" rows="3" minlength="3" maxlength="1500" required placeholder="What does the speaker say about data recovery?"></textarea><div class="assistant-send"><span>Answers use this webinar’s transcript. Check the cited passages.</span><button class="button" type="submit">Ask a question →</button></div></form></section><section role="tabpanel" id="panel-transcript" aria-labelledby="tab-transcript" hidden><form id="transcript-search" class="transcript-search"><label for="transcript-query">Search this transcript</label><div><input id="transcript-query" type="search" maxlength="120" placeholder="Find a topic or phrase"><button class="button" type="submit">Search</button></div></form><p id="transcript-count" class="assistant-hint" role="status"></p><div id="transcript-chunks"></div><button class="text-button" type="button" id="transcript-more" hidden>Load more passages ↓</button></section><section role="tabpanel" id="panel-summary" aria-labelledby="tab-summary" hidden><p class="assistant-hint">Summaries cover every transcript section and include source passages. Only actions explicitly stated by the speaker are included.</p><div class="summary-actions"><button class="button" type="button" id="summary-generate">Generate summary</button><button class="text-button" type="button" id="summary-stop" hidden>Stop after this section</button></div><p id="summary-status" role="status" class="assistant-hint"></p><div id="summary-content"></div></section><p id="chat-feedback" class="field-error" role="alert" hidden></p></section>`;
    const $=selector=>host.querySelector(selector), params={webinar_id:webinar.id};
    let conversationId=null, newConversation=false, busy=false, retry=null, state=null, transcriptLoaded=false, summaryLoaded=false, summary=null, generating=false, stopSummary=false, nextOffset=null, search='', transcriptRequest=0;
    const fail=error=>{$('#chat-feedback').textContent=error.message;$('#chat-feedback').hidden=false;};
    const clear=()=>{$('#chat-feedback').hidden=true;};
    const render=messages=>{
      $('#chat-messages').innerHTML=messages.length?messages.map(m=>`<article class="chat-message ${m.role==='user'?'from-user':'from-assistant'}"><div class="message-author"><strong>${m.role==='user'?'You':'Webinar assistant'}</strong>${m.role!=='user'?`<span>${escape(m.model_name==='transcript-excerpts'?'Transcript excerpts':m.model_name==='insufficient-evidence'?'Insufficient evidence':m.model_name || 'Saved response')}</span>`:''}</div><p>${escape(m.message)}</p>${sources(m.sources)}</article>`).join(''):'<div class="chat-welcome"><span aria-hidden="true">✦</span><h3>Your questions, connected to the source.</h3><p>Ask about an idea, an example, or something the speaker said. Follow the citations back to the recording.</p></div>';
      const log=$('#chat-messages');log.scrollTop=log.scrollHeight;
    };
    const setBusy=value=>{busy=value;$('#chat-form button').disabled=value || !state?.assistant.available;$('#chat-question').disabled=value;$('#chat-conversation').disabled=value;$('#chat-new').disabled=value;$('#chat-form button').textContent=value?'Thinking locally…':'Ask a question →';};
    function listConversations(list) {
      $('#chat-conversation').innerHTML='<option value="">New conversation</option>'+list.map(c=>`<option value="${escape(c.id)}">${escape(c.title || 'Conversation')}</option>`).join('');
      $('#chat-conversation').value=conversationId || '';
    }
    async function loadConversation(id) {
      clear(); setBusy(true);
      try {const result=await WebinarAPI.request('chat',{...params,...(id?{conversation_id:id}:{})});conversationId=result.conversationId;newConversation=false;render(result.messages);listConversations(result.conversations);}
      catch(error){fail(error);}finally{setBusy(false);}
    }
    async function loadTranscript(offset=0) {
      const request=++transcriptRequest,phrase=search;clear();$('#transcript-more').disabled=true;
      try {
        const result=await WebinarAPI.request('transcript',params,'POST',{search:phrase,offset});if(request!==transcriptRequest)return;
        const markup=result.chunks.map(c=>`<article class="transcript-passage">${c.start_seconds!==null?`<button type="button" class="source-link" data-seek="${Number(c.start_seconds)}">${time(c.start_seconds)} – ${time(c.end_seconds)}</button>`:'<span class="passage-label">Text transcript · no timestamp</span>'}<p>${escape(c.chunk_text)}</p></article>`).join('');
        if(!offset)$('#transcript-chunks').innerHTML=markup;else $('#transcript-chunks').insertAdjacentHTML('beforeend',markup);
        $('#transcript-count').textContent=result.total?`${result.total} passage${result.total===1?'':'s'}${phrase?` matching “${phrase}”`:''}.`:(phrase?'No matching passages. Try another phrase.':'No authorized transcript has been added for this webinar.');
        if(result.transcription)$('#transcript-count').textContent+=' '+result.transcription.notice;
        nextOffset=result.nextOffset;$('#transcript-more').hidden=nextOffset===null;transcriptLoaded=true;
      }catch(error){if(request===transcriptRequest)fail(error);}finally{if(request===transcriptRequest)$('#transcript-more').disabled=false;}
    }
    function renderSummary() {
      const saved=Object.keys(summary.parts).length;
      $('#summary-status').textContent=`${saved} of ${summary.totalParts} transcript sections summarized${saved===summary.totalParts?' · complete.':'.'}`;
      $('#summary-content').innerHTML=Object.entries(summary.parts).sort((a,b)=>Number(a[0])-Number(b[0])).map(([index,part])=>`<section class="summary-section"><h3>Section ${Number(index)+1}</h3>${['summary','takeaway','topic','action'].map(kind=>{const items=part.items.filter(item=>item.kind===kind);return items.length?`<h4>${{summary:'Summary',takeaway:'Key takeaways',topic:'Topics & concepts',action:'Action items'}[kind]}</h4>${items.map(item=>`<div class="summary-point"><p>${escape(item.text)}</p>${sources(item.sources)}</div>`).join('')}`:'';}).join('')}</section>`).join('');
      $('#summary-generate').textContent=saved===summary.totalParts?'Summary complete':saved?'Continue summary':'Generate summary';
      $('#summary-generate').disabled=generating || saved===summary.totalParts || !registration.activityConsent || !state?.assistant.available || state?.mode!=='ollama';
    }
    async function loadSummary() {
      if(!state?.transcriptReady){$('#summary-status').textContent='An authorized transcript is needed before a summary can be generated.';$('#summary-generate').disabled=true;return;}
      try {summary=await WebinarAPI.request('insights',params);summaryLoaded=true;renderSummary();}
      catch(error){fail(error);}
    }
    const tabs=[...host.querySelectorAll('[role="tab"]')];
    function showTab(tab) {
      clear();for(const item of tabs){const active=item===tab;item.setAttribute('aria-selected',String(active));item.tabIndex=active?0:-1;$('#panel-'+item.dataset.tab).hidden=!active;}
      if(tab.dataset.tab==='transcript'&&!transcriptLoaded)void loadTranscript();
      if(tab.dataset.tab==='summary'&&!summaryLoaded)void loadSummary();
    }
    for(const [i,tab] of tabs.entries()){
      tab.addEventListener('click',()=>showTab(tab));tab.addEventListener('keydown',event=>{let next;if(event.key==='ArrowRight')next=(i+1)%tabs.length;if(event.key==='ArrowLeft')next=(i+tabs.length-1)%tabs.length;if(event.key==='Home')next=0;if(event.key==='End')next=tabs.length-1;if(next!==undefined){event.preventDefault();tabs[next].focus();showTab(tabs[next]);}});
    }
    host.addEventListener('click',event=>{
      const source=event.target.closest('[data-seek]');if(!source)return;
      const video=document.querySelector('#webinar-video'), position=Number(source.dataset.seek);
      if(!video || !Number.isFinite(position) || position<0)return;
      if(Number.isFinite(video.duration) && position>video.duration){fail(new Error('This timestamp is beyond the current video. Ask the administrator to check that its transcript matches.'));return;}
      try {video.currentTime=position;video.focus();$('#chat-status').textContent=`Video moved to ${time(position)}. Press play to continue.`;}catch{fail(new Error('Wait for the video to load, then select the timestamp again.'));}
    });
    $('#transcript-search').addEventListener('submit',event=>{event.preventDefault();search=$('#transcript-query').value.trim();void loadTranscript();});
    $('#transcript-more').addEventListener('click',()=>{if(nextOffset!==null)void loadTranscript(nextOffset);});
    $('#chat-conversation').addEventListener('change',()=>{
      const id=$('#chat-conversation').value;if(id)void loadConversation(id);else $('#chat-new').click();
    });
    $('#chat-new').addEventListener('click',()=>{if(busy)return;conversationId=null;newConversation=true;retry=null;$('#chat-conversation').value='';$('#chat-question').value='';render([]);clear();$('#chat-question').focus();});
    $('#chat-form').addEventListener('submit',async event=>{
      event.preventDefault();if(busy)return;clear();const question=$('#chat-question').value.trim();if(question.length<3){fail(new Error('Ask a question with at least 3 characters.'));return;}
      if(!retry || retry.question!==question || retry.conversationId!==conversationId)retry={question,conversationId,newConversation,requestId:crypto.randomUUID()};
      setBusy(true);$('#chat-hint').textContent='Reading the relevant transcript passages and generating a local response…';
      try {
        const result=await WebinarAPI.request('chat',params,'POST',retry);conversationId=result.conversationId;newConversation=false;render(result.messages);$('#chat-question').value='';retry=null;
        const refreshed=await WebinarAPI.request('chat',{...params,conversation_id:conversationId});listConversations(refreshed.conversations);
      }catch(error){fail(error);}finally{setBusy(false);$('#chat-hint').textContent='Questions and answers are saved to your registration and visible in the admin dashboard.';}
    });
    $('#summary-stop').addEventListener('click',()=>{stopSummary=true;$('#summary-stop').disabled=true;});
    $('#summary-generate').addEventListener('click',async()=>{
      if(generating || !summary)return;clear();generating=true;stopSummary=false;$('#summary-stop').hidden=false;$('#summary-stop').disabled=false;renderSummary();
      try {for(let part=0;part<summary.totalParts;part++){
        if(summary.parts[part])continue;if(stopSummary || !host.isConnected)break;
        $('#summary-status').textContent=`Generating section ${part+1} of ${summary.totalParts} locally…`;
        const result=await WebinarAPI.request('insights',params,'POST',{part,version:summary.version});summary.parts[result.part]=result.result;renderSummary();
      }}catch(error){fail(error);}finally{generating=false;$('#summary-stop').hidden=true;renderSummary();}
    });
    try {
      state=await WebinarAPI.request('chat',params);if(!host.isConnected)return;
      conversationId=state.conversationId;render(state.messages);listConversations(state.conversations);
      $('#assistant-badge').textContent=state.mode==='ollama'?'Local Ollama':'Transcript excerpts';
      $('#chat-status').textContent=!state.transcriptReady?'An authorized transcript has not been added for this webinar. You can return when the administrator adds it.':state.assistant.message;
      $('#chat-hint').textContent=!registration.activityConsent?'To ask questions and save chat history, enable activity consent under Edit registration. You can still read the transcript and any saved summary.':state.mode==='ollama'?'Your question, recent chat context, and relevant transcript passages are processed by Ollama on this computer. Contact details are not sent to the model.':'AI is disabled. Replies contain matching transcript excerpts.';
      $('#chat-form').hidden=!state.transcriptReady || !registration.activityConsent;setBusy(false);$('#chat-new').disabled=!registration.activityConsent;
      $('#summary-generate').disabled=!state.transcriptReady || !registration.activityConsent || !state.assistant.available || state.mode!=='ollama';
      if($('#tab-summary').getAttribute('aria-selected')==='true')void loadSummary();
    }catch(error){$('#chat-status').textContent=error.message;}
  }
  return {mount};
})();
