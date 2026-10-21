'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict');
const http=require('node:http'),path=require('node:path'),{execFile}=require('node:child_process');
test('PHP Ollama adapter validates structured answers and handles provider failures',async t=>{
 let mode='valid',received;
 const server=http.createServer(async(req,res)=>{
   res.setHeader('Content-Type','application/json');
   if(req.url==='/api/tags'){res.end(JSON.stringify({models:[{name:'qa-model:local'}]}));return;}
   let body='';for await(const part of req)body+=part;received=JSON.parse(body);
   if(mode==='error'){res.statusCode=500;res.end('{"error":"temporary failure"}');return;}
   let answer={answer:'Recovery plans use verified backups.',insufficient:false,evidence:[{chunkId:11,quote:'use verified backups'}]};
   if(mode==='wrong-source')answer.evidence[0].chunkId=999;
   if(mode==='wrong-quote')answer.evidence[0].quote='This sentence was invented.';
   if(mode==='insufficient')answer={answer:'Unsupported invented text',insufficient:true,evidence:[]};
   res.end(JSON.stringify({done:true,done_reason:mode==='truncated'?'length':'stop',prompt_eval_count:100,eval_count:80,message:{content:mode==='malformed'?'not json':JSON.stringify(answer)}}));
 });
 await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));t.after(()=>server.close());
 async function call(next){mode=next;return new Promise((resolve,reject)=>{const child=execFile(process.env.PHP_BIN || 'C:/xampp/php/php.exe',[path.join(__dirname,'ollama-contract.php')],{encoding:'utf8'},(error,stdout)=>{if(error)reject(error);else {try{resolve(JSON.parse(stdout));}catch(e){reject(e);}}});child.stdin.end(JSON.stringify({port:server.address().port}));});}
 const ok=await call('valid');assert.equal(ok.status,200);assert.equal(ok.result.model,'qa-model:local');assert.equal(ok.result.sources[0].id,11);
 assert.equal(received.stream,false);assert.equal(received.options.temperature,0);assert.equal(received.format.type,'object');assert.ok(received.options.num_predict<=1200);
 for(const mode of ['wrong-source','wrong-quote','malformed','truncated'])assert.equal((await call(mode)).status,502,mode);
 assert.equal((await call('error')).status,503);
 const refused=await call('insufficient');assert.equal(refused.status,200);assert.match(refused.result.answer,/couldn’t find enough information/);assert.deepEqual(refused.result.sources,[]);
});
