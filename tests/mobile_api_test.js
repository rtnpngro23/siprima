/* Runs real HTTP requests against copied code and a disposable, synthetic MySQL instance.
 * Requires PHP_BINARY and MYSQLD_BINARY if not on PATH. Never imports resident records. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {spawn, spawnSync} = require('node:child_process');
const contract = require('./mobile_api_contract_test.js');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || 'php';
// Uploads re-encode through GD. Set PHP_TEST_GD=1 when the CLI php.ini does not load it.
const phpArgs = process.env.PHP_TEST_GD === '1' ? ['-d', 'extension=gd'] : [];
const mysqld = process.env.MYSQLD_BINARY;
if (!mysqld) throw Error('Set MYSQLD_BINARY to an installed mysqld executable.');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'siprima-mobile-'));
const app = path.join(tmp, 'app');
const children = [];
let env;
function run(binary, args, input='') {
  const r = spawnSync(binary, binary===php?[...phpArgs,...args]:args, {cwd:root, env, input, encoding:'utf8', maxBuffer:8*1024*1024});
  if(r.status!==0) throw Error(r.stderr+'\n'+r.stdout);
  return r.stdout;
}
function fixture(command, input={}) { return JSON.parse(run(php, ['tests/mobile_api_fixture.php', command], JSON.stringify(input))); }
function copy(file) { const to=path.join(app,file);fs.mkdirSync(path.dirname(to),{recursive:true});fs.copyFileSync(path.join(root,file),to); }
async function port() { const s=net.createServer();await new Promise(r=>s.listen(0,'127.0.0.1',r));const p=s.address().port;await new Promise(r=>s.close(r));return p; }
async function ready(p) {
  for(let i=0;i<150;i++) { if(await new Promise(r=>{const s=net.connect(p,'127.0.0.1');s.once('connect',()=>{s.destroy();r(true)});s.once('error',()=>r(false));}))return;await new Promise(r=>setTimeout(r,200)); }
  throw Error('Server did not start');
}
function worker(command,input) {
  return new Promise((resolve,reject)=>{const c=spawn(php,[...phpArgs,'tests/mobile_api_fixture.php',command],{cwd:root,env});let out='',err='';c.stdout.on('data',d=>out+=d);c.stderr.on('data',d=>err+=d);c.on('error',reject);c.on('exit',code=>code===0?resolve(JSON.parse(out)):reject(Error(err+out)));c.stdin.end(JSON.stringify(input));});
}
(async()=>{
  const dbPort=await port(), httpPort=await port();
  env={...process.env,SIPRIMA_ISOLATED_TEST:'1',MOBILE_API_TEST_ROOT:app,TEST_DB_PORT:String(dbPort),DB_HOST:'127.0.0.1',DB_PORT:String(dbPort),DB_USER:'root',DB_PASS:'',DB_NAME:'siprima_mobile_test',
    APP_ENV:'local',APP_PUBLIC_URL:'https://example.invalid',MOBILE_API_ENABLED:'true',MOBILE_API_ALLOW_HTTP:'true',APP_DEBUG:'false',SMTP_HOST:'',SMTP_USERNAME:'',LOGIN_THROTTLE_DIR:path.join(tmp,'throttle')};
  for(const dir of ['app/Api','app/security','public/functions']) for(const file of fs.readdirSync(path.join(root,dir))) if(file.endsWith('.php'))copy(dir+'/'+file);
  for(const file of ['app/config/env_loader.php','app/config/service_hours.php','app/config/document_features.php','public/api/v1/index.php','public/user/upload_compat.php','public/user/functions/feedback_validation.php','sql/mobile_api_migrate.php','sql/20260913_mobile_api.sql','tools/mobile_dev_router.php']) copy(file);
  const data=path.join(tmp,'mysql');
  run(mysqld,['--no-defaults','--initialize-insecure','--basedir='+path.dirname(path.dirname(mysqld)),'--datadir='+data]);
  const mysql=spawn(mysqld,['--no-defaults','--datadir='+data,'--port='+dbPort,'--bind-address=127.0.0.1','--mysqlx=0'],{env,stdio:'ignore',windowsHide:true});children.push(mysql);await ready(dbPort);
  fixture('setup');
  for(let i=0;i<2;i++) assert.match(run(php,[path.join(app,'sql/mobile_api_migrate.php'),'--apply']),/PASS mobile API migration/);
  console.log('PASS DDL-only synthetic schema and repeatable mobile migration');
  const log=fs.openSync(path.join(tmp,'php.log'),'a');
  const http=spawn(php,[...phpArgs,'-d','upload_max_filesize=5M','-d','post_max_size=6M','-d','log_errors=1','-d','error_log='+path.join(tmp,'php-errors.log'),'-S','127.0.0.1:'+httpPort,'-t',path.join(app,'public'),path.join(app,'tools/mobile_dev_router.php')],{cwd:root,env,stdio:['ignore',log,log],windowsHide:true});children.push(http);await ready(httpPort);
  const base='http://127.0.0.1:'+httpPort;
  async function request(url,{method='GET',body,token,headers={}}={}) {
    const multipart=body instanceof FormData;
    const res=await fetch(base+'/api/v1'+url,{method,redirect:'manual',headers:{...(body&&!multipart?{'Content-Type':'application/json'}:{}),...(token?{Authorization:'Bearer '+token}:{}),...headers},body:body?(multipart?body:JSON.stringify(body)):undefined});
    const text=await res.text();let json;try{json=JSON.parse(text)}catch{}
    return {status:res.status,json,text,headers:res.headers};
  }
  async function ok(url,opts={},status=200) {const r=await request(url,opts);assert.equal(r.status,status,r.text);assert.ok(r.json?.data!==undefined,r.text);contract.validateResponse(url,opts.method||'GET',status,r.json);return r.json.data;}
  async function denied(url,opts,status) {const r=await request(url,opts);assert.equal(r.status,status,r.text);assert.ok(r.json?.error?.code,r.text);return r.json.error;}
  async function login(id) {
    fixture('clear-rate');
    return ok('/auth/login',{method:'POST',body:{username:String(id).padStart(16,'0'),password:'Fixture9!Secret',device_name:'Synthetic Android'}});
  }
  assert.equal((await request('/me',{headers:{Cookie:'PHPSESSID=fake'}})).status,401);
  await denied('/auth/login',{method:'GET'},405);
  await denied('/unknown',{},404);
  const citizen=await login(1),other=await login(2),add=await login(3),otherAdd=await login(4);
  for(const id of [5,6,7,8]) {fixture('clear-rate');await denied('/auth/login',{method:'POST',body:{username:String(id).padStart(16,'0'),password:'Fixture9!Secret',device_name:'Android'}},401);}
  assert.equal((await ok('/me',{token:citizen.access_token})).persona,'citizen');
  await denied('/me',{method:'PATCH',token:citizen.access_token,body:{role:'admin'}},422);
  await denied('/me',{method:'PATCH',token:add.access_token,body:{id_desa:2}},422);
  await ok('/me',{method:'PATCH',token:citizen.access_token,body:{alamat:'Updated synthetic address'}});
  assert.equal((await request('/me',{token:citizen.access_token})).headers.get('set-cookie'),null);
  console.log('PASS bearer-only login, role/institution restrictions, protected profile and no cookie authentication');
  const temp=await login(9);
  assert.equal((await ok('/me',{token:temp.access_token})).must_change_password,true);
  await denied('/services',{token:temp.access_token},403);
  await ok('/auth/password/change',{method:'POST',token:temp.access_token,body:{current_password:'Fixture9!Secret',new_password:'Replacement9!Secret',confirm_password:'Replacement9!Secret'}});
  await denied('/me',{token:temp.access_token},401);
  console.log('PASS temporary password restriction, change and session revocation');
  const rotating=await login(10);
  const rotated=await ok('/auth/refresh',{method:'POST',body:{refresh_token:rotating.refresh_token}});
  await denied('/me',{token:rotating.access_token},401);
  await denied('/auth/refresh',{method:'POST',body:{refresh_token:rotating.refresh_token}},401);
  await denied('/me',{token:rotated.access_token},401);
  const exp=await login(10);fixture('expire-session',{id:exp.session_id,type:'idle'});await denied('/auth/refresh',{method:'POST',body:{refresh_token:exp.refresh_token}},401);
  const changed=await login(11);fixture('identity',{id:11,field:'id_desa',value:2});await denied('/me',{token:changed.access_token},401);
  const logout=await login(12);await ok('/auth/logout',{method:'POST',token:logout.access_token,body:{}});await denied('/auth/refresh',{method:'POST',body:{refresh_token:logout.refresh_token}},401);
  fixture('assert-private',{tokens:[citizen.access_token,citizen.refresh_token,rotated.refresh_token]});
  console.log('PASS refresh rotation/replay revokes family, idle expiry, identity change, logout and hashed storage');
  const concurrentSession=await login(10);
  const refreshes=await Promise.all([1,2].map(()=>worker('dispatch',{method:'POST',path:'/auth/refresh',body:{refresh_token:concurrentSession.refresh_token}})));
  assert.deepEqual(refreshes.map(r=>r.status).sort(),[200,401]);
  await denied('/me',{token:refreshes.find(r=>r.status===200).data.access_token},401);
  const absolute=await login(10);fixture('expire-session',{id:absolute.session_id,type:'absolute'});await denied('/auth/refresh',{method:'POST',body:{refresh_token:absolute.refresh_token}},401);
  const accessExpired=await login(10);fixture('expire-session',{id:accessExpired.session_id,type:'access'});await denied('/me',{token:accessExpired.access_token},401);await ok('/auth/refresh',{method:'POST',body:{refresh_token:accessExpired.refresh_token}});
  console.log('PASS simultaneous refresh replay, absolute deadline, and refresh after access expiry');
  const png=Buffer.from(fixture('image').base64,'base64');
  async function upload(code,type,token,bytes=png,name='fixture.png') {
    const f=new FormData();f.set('service_code',code);f.set('doc_type',type);f.set('file',new Blob([bytes],{type:'image/png'}),name);
    return request('/uploads',{method:'POST',body:f,token});
  }
  async function uploads(code,token) {
    const meta=await ok('/services/'+code,{token});const refs=[];
    for(const type of [...meta.required_documents,...meta.required_document_groups.map(g=>g[0])]) {const r=await upload(code,type,token);assert.equal(r.status,201,r.text);refs.push(r.json.data.upload_ref);}
    return refs;
  }
  function fields(meta) {
    const f={};for(const [name,schema] of Object.entries(meta.field_schema)) {
      if(!schema.required)continue;
      f[name]=schema.type==='integer'?1:schema.type==='array'?[{nama:'Synthetic Member',nik:'0000000000000099',hubungan:'anak'}]:schema.enum?schema.enum[0]:schema.format==='date'?'2020-01-01':name==='waktu'?'10:00':name.startsWith('nik_')||name==='no_kk'?'0000000000000099':'Synthetic';
    }return f;
  }
  assert.equal((await upload('kk','formulir_f101',citizen.access_token,Buffer.from('<?php echo 1;'),'file.png')).status,422);
  assert.equal((await upload('kk','formulir_f101',citizen.access_token,png,'file.php')).status,422);
  // Trailing zeroes after IEND keep the PNG decodable while hitting exactly the 5 MiB ceiling,
  // so this is the size a phone camera photo lands at and used to be rejected outright.
  const largePng=Buffer.concat([png,Buffer.alloc(5*1024*1024-png.length)]);
  const compressed=await upload('kk','formulir_f101',citizen.access_token,largePng);
  assert.equal(compressed.status,201,compressed.text);
  contract.validateResponse('/uploads','POST',201,compressed.json);
  assert.equal(compressed.json.data.original_file_size,5*1024*1024);
  assert.ok(compressed.json.data.file_size>0&&compressed.json.data.file_size<500000);
  const stored=path.join(app,'public/user/uploads/mobile',compressed.json.data.upload_ref+'.png');
  assert.equal(fs.statSync(stored).size,compressed.json.data.file_size);
  assert.equal((await upload('kk','formulir_f101',citizen.access_token,Buffer.concat([largePng,Buffer.from([0])]))).status,422);
  assert.equal((await upload('kk','formulir_f101',citizen.access_token,Buffer.alloc(6*1024*1024))).status,413);
  console.log('PASS 5 MiB upload, compressed persisted size, response contract and multipart limits');
  const refs=await uploads('kk',citizen.access_token);const kkFields=fields(await ok('/services/kk',{token:citizen.access_token}));
  const payload={service_code:'kk',fields:kkFields,uploads:refs};const createHeaders={'Idempotency-Key':'test-create-000000001'};
  await denied('/applications',{method:'POST',token:other.access_token,body:payload,headers:{'Idempotency-Key':'test-stolen-000000001'}},422);
  assert.equal(fixture('state').applications,0);
  const created=await ok('/applications',{method:'POST',token:citizen.access_token,body:payload,headers:createHeaders},201);const ref=created.application_ref;
  const retry=await ok('/applications',{method:'POST',token:citizen.access_token,body:payload,headers:createHeaders},201);assert.equal(retry.application_ref,ref);assert.equal(fixture('state').applications,1);
  await denied('/applications',{method:'POST',token:citizen.access_token,body:{...payload,fields:{...kkFields,dusun:'Changed'}},headers:createHeaders},409);
  await denied('/applications/'+ref,{token:other.access_token},404);await denied('/applications/'+ref,{token:add.access_token},404);
  const detail=await ok('/applications/'+ref,{token:citizen.access_token});assert.equal(detail.attachments.length,3);assert.ok(!JSON.stringify(detail).includes('uploads/mobile/'));
  const attachment=detail.attachments[0].document_ref;
  assert.equal((await request('/documents/'+attachment+'/download',{token:citizen.access_token})).status,200);
  await denied('/documents/'+attachment+'/download',{token:other.access_token},404);
  assert.equal((await fetch(base+'/user/uploads/mobile/'+refs[0]+'.png')).status,404);
  fixture('web-change',{id:Number(ref.split('-')[1])});await denied('/applications/'+ref,{method:'PATCH',token:citizen.access_token,headers:{'If-Match':detail.revision},body:{fields:{dusun:'New'}}},409);
  const fresh=await ok('/applications/'+ref,{token:citizen.access_token});await ok('/applications/'+ref,{method:'PATCH',token:citizen.access_token,headers:{'If-Match':fresh.revision},body:{fields:{dusun:'New'}}});
  console.log('PASS valid upload, invalid files, cross-owner denial, idempotent creation, private download and website-aware revisions');
  const parallelPayload={...payload,uploads:await uploads('kk',citizen.access_token)};
  const beforeParallel=fixture('state').applications;
  const parallel=await Promise.all(Array.from({length:6},()=>worker('dispatch',{method:'POST',path:'/applications',body:parallelPayload,headers:{authorization:'Bearer '+citizen.access_token,'idempotency-key':'parallel-create-000001'}})));
  parallel.forEach(r=>assert.equal(r.status,201,JSON.stringify(r)));
  assert.equal(new Set(parallel.map(r=>r.data.application_ref)).size,1);assert.equal(fixture('state').applications,beforeParallel+1);
  console.log('PASS six simultaneous submissions commit exactly one application');
  const rollbackPayload={...payload,uploads:await uploads('kk',citizen.access_token)};const beforeFault=fixture('state');
  fixture('fault',{kind:'history',enabled:true});
  const faultOptions={method:'POST',token:citizen.access_token,body:rollbackPayload,headers:{'Idempotency-Key':'rollback-create-000001'}};
  await denied('/applications',faultOptions,503);
  const failedState=fixture('state');assert.equal(failedState.applications,beforeFault.applications);assert.equal(failedState.temporary_uploads,beforeFault.temporary_uploads);
  fixture('fault',{kind:'history',enabled:false});await ok('/applications',faultOptions,201);
  console.log('PASS injected database failure rolls back application/upload claims; same-key retry succeeds');
  fixture('closed',{closed:true});assert.equal((await ok('/service-availability')).is_open,false);
  assert.equal((await ok('/applications/'+ref,{token:citizen.access_token})).capabilities.can_edit,false);
  await denied('/applications/'+ref,{method:'PATCH',token:citizen.access_token,headers:{'If-Match':fresh.revision},body:{fields:{dusun:'Closed'}}},403);
  assert.equal((await request('/documents/'+attachment+'/download',{token:citizen.access_token})).status,200);
  fixture('closed',{closed:false});fixture('status',{ref,status:'ditolak'});
  const rejected=await ok('/applications/'+ref,{token:citizen.access_token});const resubmitOpts={method:'POST',token:citizen.access_token,body:{},headers:{'If-Match':rejected.revision,'Idempotency-Key':'test-resubmit-0000001'}};
  await ok('/applications/'+ref+'/resubmit',resubmitOpts);await ok('/applications/'+ref+'/resubmit',resubmitOpts);
  const history=await ok('/applications/'+ref+'/history',{token:citizen.access_token});assert.equal(history.items.filter(x=>x.event_type==='resubmitted').length,2);
  await denied('/applications?per_page=101',{token:citizen.access_token},422);
  console.log('PASS WITA closure gates writes but preserves reads/downloads, resubmit retry and bounded pagination');
  fixture('settings-missing');await denied('/uploads',{method:'POST',token:citizen.access_token,body:{}},415);assert.equal((await ok('/service-availability')).closed_reason,'unavailable');
  assert.equal((await upload('kk','formulir_f101',citizen.access_token)).status,503);await ok('/me',{token:citizen.access_token});fixture('closed',{closed:false});
  for(const [account,codes] of [[citizen,['kelahiran','kematian','pindah','pindah_online','peristiwa']],[add,['kk','kelahiran','kematian','pindah','pindah_online']]]) {
    fixture('clear-rate');
    for(const code of codes) {
      const meta=await ok('/services/'+code,{token:account.access_token});const p={service_code:code,fields:fields(meta),uploads:await uploads(code,account.access_token)};
      const made=await ok('/applications',{method:'POST',token:account.access_token,body:p,headers:{'Idempotency-Key':'all-services-'+account.user.id+'-'+code+'-00001'}},201);
      await ok('/applications/'+made.application_ref,{token:account.access_token});
      if(code==='peristiwa') {
        await denied('/applications/'+made.application_ref,{token:otherAdd.access_token},404);
        const regional=await ok('/applications/'+made.application_ref,{token:add.access_token});assert.equal(regional.capabilities.can_process_event,true);
        await ok('/applications/'+made.application_ref+'/status',{method:'PATCH',token:add.access_token,headers:{'If-Match':regional.revision},body:{status:'diproses',reason:''}});
      }
    }
  }
  console.log('PASS all six citizen services and five ADD services, plus ADD regional event processing');
  fixture('clear-rate');
  const c=await ok('/applications/'+ref+'/comments',{method:'POST',token:citizen.access_token,body:{message:'Synthetic clarification'}},201);
  await denied('/applications/'+ref+'/comments',{method:'POST',token:other.access_token,body:{message:'No access'}},404);
  await denied('/applications/'+ref+'/comments',{method:'POST',token:citizen.access_token,body:{message:'Bad parent',parent_id:999999}},422);
  assert.equal((await ok('/applications/'+ref+'/comments',{token:citizen.access_token})).items[0].id,c.comment_id);
  fixture('future-status',{ref});await denied('/applications/'+ref+'/receipt',{method:'POST',token:citizen.access_token,body:{confirmation:'sudah_masuk',note:''}},422);
  fixture('status',{ref,status:'selesai'});
  await ok('/applications/'+ref+'/receipt',{method:'POST',token:citizen.access_token,body:{confirmation:'sudah_masuk',note:''}});
  await denied('/applications/'+ref+'/feedback',{method:'POST',token:citizen.access_token,body:{jenis_feedback:'tanggapan',rating:2,service_rating:5,pesan:''}},422);
  await ok('/applications/'+ref+'/feedback',{method:'POST',token:citizen.access_token,body:{jenis_feedback:'tanggapan',rating:4,service_rating:5,pesan:'Synthetic feedback'}});
  const emailDoc=fixture('email-document',{ref});
  const receipt=await ok('/applications/'+ref+'/receipt',{method:'POST',token:citizen.access_token,body:{confirmation:'belum_masuk',note:'Synthetic'}});assert.equal(receipt.changed,false);
  const docs=await ok('/applications/'+ref+'/documents',{token:citizen.access_token});assert.equal(docs.items[0].document_ref,emailDoc);assert.equal(docs.operator_results_enabled,false);
  assert.equal((await request('/documents/'+emailDoc+'/download',{token:citizen.access_token})).status,200);await denied('/documents/'+emailDoc+'/download',{token:other.access_token},404);
  const n=fixture('notification',{id:1});await ok('/notifications/notification-'+n,{method:'PATCH',token:citizen.access_token,body:{read:true}});await denied('/notifications/notification-'+n,{method:'PATCH',token:other.access_token,body:{read:true}},404);
  const pending=fixture('state').outbox_pending;assert.ok(pending>0);
  fixture('fault',{kind:'notifications',enabled:true});assert.equal(fixture('outbox-attempt').ok,false);assert.equal(fixture('state').outbox_pending,pending);
  fixture('fault',{kind:'notifications',enabled:false});assert.equal(fixture('outbox'),pending);const notified=fixture('state').notifications;assert.equal(fixture('outbox'),0);assert.equal(fixture('state').notifications,notified);
  console.log('PASS comments, dual ratings, receipt delay/status preservation, email PDF, notification ownership and durable outbox');
  fixture('clear-rate');
  const expired=await upload('kk','formulir_f101',citizen.access_token);fixture('expire-upload',{id:expired.json.data.upload_ref});fixture('orphan');assert.ok(fixture('cleanup')>=2);
  await ok('/auth/register',{method:'POST',body:{nama:'Synthetic Registered',nik:'0000000000000300',email:'registered@example.invalid',alamat:'Synthetic',no_hp:'081234567890',password:'Registered9!Secret',confirm_password:'Registered9!Secret'}},201);
  await denied('/auth/register',{method:'POST',body:{nama:'Synthetic Registered',nik:'0000000000000300',email:'registered@example.invalid',alamat:'Synthetic',no_hp:'081234567890',password:'Registered9!Secret',confirm_password:'Registered9!Secret'}},409);
  const form=new FormData();for(const [k,v] of Object.entries({nama:'Synthetic Officer',nik:'0000000000000301',email:'officer@example.invalid',alamat:'Synthetic',no_hp:'081234567890',id_kecamatan:'1',id_desa:'1',jabatan:'lainnya',keterangan_jabatan:'Petugas',agree_terms:'on'}))form.set(k,v);
  form.set('surat_tugas',new Blob(['%PDF-1.4\n% fixture only\n%%EOF'],{type:'application/pdf'}),'assignment.pdf');
  assert.equal((await ok('/auth/register-village-officer',{method:'POST',body:form},201)).status,'pending');
  const token=fixture('reset-token',{id:2});await ok('/auth/password/reset',{method:'POST',body:{token,new_password:'Reset9!Secret',confirm_password:'Reset9!Secret'}});await denied('/me',{token:other.access_token},401);
  await denied('/auth/password/reset',{method:'POST',body:{token,new_password:'OtherReset9!Secret',confirm_password:'OtherReset9!Secret'}},422);
  const before=fixture('state');const forgot=await ok('/auth/password/forgot',{method:'POST',body:{email:'missing@example.invalid'}},202);assert.ok(!JSON.stringify(forgot).includes('token'));
  console.log('PASS temporary cleanup, citizen registration, ADD verification registration, single-use password reset and generic forgot response');
  const logs=fs.existsSync(path.join(tmp,'php-errors.log'))?fs.readFileSync(path.join(tmp,'php-errors.log'),'utf8'):'';
  for(const secret of ['Fixture9!Secret',citizen.access_token,citizen.refresh_token,token,'0000000000000300'])assert.ok(!logs.includes(secret),'Sensitive data leaked to logs');
  console.log('PASS no synthetic secrets in logs');
  console.log('PASS mobile API integration suite');
})().catch(e=>{console.error(e);console.error('Isolated diagnostics retained at '+tmp);process.exitCode=1;}).finally(async()=>{
  for(const c of children.reverse()) {if(c.exitCode===null)c.kill();await new Promise(r=>c.exitCode!==null?r():c.once('exit',r));}
});
