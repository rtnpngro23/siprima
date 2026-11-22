const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {spawn, spawnSync} = require('node:child_process');
const root = path.resolve(__dirname,'..');
const php = process.env.PHP_BINARY || 'php';
const mysqld = process.env.MYSQLD_BINARY;
if (!mysqld) throw new Error('Set MYSQLD_BINARY to test against an isolated MySQL instance.');
const tmp = fs.mkdtempSync(path.join(os.tmpdir(),'siprima-security-'));
const children=[];
let databasePort;
function run(binary,args,env) { const r=spawnSync(binary,args,{cwd:root,env,encoding:'utf8',maxBuffer:8*1024*1024});if(r.status!==0)throw new Error(r.stderr+'\n'+r.stdout);if(binary===php)process.stdout.write(r.stdout); }
function worker(args,env){return new Promise((resolve,reject)=>{const p=spawn(php,['tests/security_concurrency_worker.php',...args],{cwd:root,env});let out='',err='';p.stdout.on('data',d=>out+=d);p.stderr.on('data',d=>err+=d);p.on('error',reject);p.on('exit',code=>code===0?resolve(out):reject(Error(err)));});}
async function port() { const s=net.createServer();await new Promise(r=>s.listen(0,'127.0.0.1',r));const p=s.address().port;await new Promise(r=>s.close(r));return p; }
async function waitPort(p) { for(let i=0;i<150;i++){if(await new Promise(r=>{const s=net.connect(p,'127.0.0.1');s.once('connect',()=>{s.destroy();r(true)});s.once('error',()=>r(false))}))return;await new Promise(r=>setTimeout(r,200));}throw new Error('Server did not start'); }
(async()=>{
 const dbPort=await port(),httpPort=await port();
 databasePort=dbPort;
 const env={...process.env,SIPRIMA_ISOLATED_TEST:'1',TEST_DB_PORT:String(dbPort),DB_HOST:'127.0.0.1',DB_PORT:String(dbPort),DB_USER:'root',DB_PASS:'',DB_NAME:'siprima_security_test',APP_PUBLIC_URL:'https://example.invalid',APP_SESSION_SECRET:'isolated-security-test-secret-123456789012345',SIPRIMA_LOG_DIR:path.join(tmp,'logs'),LOGIN_THROTTLE_DIR:path.join(tmp,'throttle')};
 const data=path.join(tmp,'mysql');
 run(mysqld,['--no-defaults','--initialize-insecure','--basedir='+path.dirname(path.dirname(mysqld)),'--datadir='+data],env);
 const mysql=spawn(mysqld,['--no-defaults','--datadir='+data,'--port='+dbPort,'--bind-address=127.0.0.1','--mysqlx=0'],{env,stdio:'ignore'});children.push(mysql);await waitPort(dbPort);
 run(php,['tests/security_integration_test.php','setup'],env);
 run(php,['sql/security_20260913.php','--apply'],env);
 run(php,['sql/security_20260913.php','--apply'],env);
 run(php,['tests/security_integration_test.php'],env);
 fs.mkdirSync(path.join(tmp,'sessions'));
 const http=spawn(php,['-d','session.save_path='+path.join(tmp,'sessions'),'-S','127.0.0.1:'+httpPort,'tests/security_http_fixture.php'],{cwd:root,env,stdio:'ignore'});children.push(http);await waitPort(httpPort);
 const base='http://127.0.0.1:'+httpPort;
 const seenSessionIds=new Set();
 function client(){const cookies=new Map();return async(url,data)=>{const r=await fetch(base+url,{method:data?'POST':'GET',redirect:'manual',headers:{Cookie:[...cookies].map(([k,v])=>k+'='+v).join('; '),...(data?{'Content-Type':'application/x-www-form-urlencoded'}:{})},body:data?new URLSearchParams(data):undefined});for(const c of r.headers.getSetCookie()){const pair=c.split(';')[0],i=pair.indexOf('=');cookies.set(pair.slice(0,i),pair.slice(i+1));if(pair.startsWith('SIPRIMA_')&&pair.slice(i+1).length>20)seenSessionIds.add(pair.slice(i+1));}return r;};}
 const admin=client();const csrf=async c=>(await(await c('/csrf')).json()).token;
 const loginClient=client();
 assert.equal((await loginClient('/proses_login.php',{username:'0000000000000012',password:'Initial8!Secret'})).status,403);
 const login=await loginClient('/proses_login.php',{username:'0000000000000012',password:'Initial8!Secret',csrf_token:await csrf(loginClient)});
 assert.equal(login.status,302);assert.equal(login.headers.get('location'),'user/dashboard_user.php');
 assert.equal((await loginClient('/protected')).status,200);
 console.log('PASS actual login binds a valid session and rejects missing CSRF');
 for(const endpoint of ['/petugas_desa_kk.php','/petugas_desa_edit_kk.php']) {
   assert.equal((await admin(endpoint,{x:'1'})).status,403);
   assert.equal((await admin(endpoint,{csrf_token:'wrong'})).status,403);
   assert.equal((await (client())(endpoint,{csrf_token:await csrf(admin)})).status,403);
   assert.equal((await admin(endpoint,{csrf_token:await csrf(admin)})).status,200);
 }
 console.log('PASS CSRF rejected without valid token on both KK endpoints');
 for(const [id,kind] of [[7,'active'],[8,'role'],[9,'assignment']]) {
   const c=client();assert.equal((await c('/signin',{id,csrf_token:await csrf(c)})).status,200);
   assert.equal((await c('/protected')).status,200);
   await admin('/mutate',{id,kind,csrf_token:await csrf(admin)});
   assert.equal((await c('/protected')).status,302);
 }
 console.log('PASS active, role, and assignment changes revoke live sessions');
 for(const mode of ['old','absolute']) {const c=client();await c('/signin',{id:10,[mode]:'1',csrf_token:await csrf(c)});assert.equal((await c('/protected')).status,302);}
 console.log('PASS idle and absolute session expiry');
 const temp=await(await admin('/issue',{id:11,csrf_token:await csrf(admin)})).json();
 const c=client();await c('/signin',{id:11,csrf_token:await csrf(c)});
 assert.equal((await c('/protected')).status,303);
 const tempPage=await c('/change_temporary_password.php');
 if(tempPage.status!==200) console.error('TEMP_PAGE',tempPage.status,tempPage.headers.get('location'),await tempPage.text(),fs.readFileSync(path.join(tmp,'logs','application.log'),'utf8'));
 assert.equal(tempPage.status,200);
 const changed=await c('/change_temporary_password.php',{csrf_token:await csrf(c),current_password:temp.password,new_password:'Private9!New',confirm_password:'Private9!New'});
 assert.ok((await changed.text()).includes('Password berhasil diubah'));
 assert.equal((await c('/protected')).status,401);
 console.log('PASS temporary password restricts access, changes once, and logs out');
 const throttle=[];for(let i=0;i<7;i++)throttle.push(await(await (client())('/throttle')).json());
 assert.equal(throttle.filter(s=>!s.is_locked).length,5);
 console.log('PASS fresh cookies do not bypass account throttling');
 const concurrent=await Promise.all(Array.from({length:12},(_,i)=>worker(['throttle','192.0.2.'+(i+1)],env)));
 assert.equal(concurrent.map(JSON.parse).filter(r=>r.allowed).length,5);
 concurrent.map(JSON.parse).forEach((r,i)=>assert.equal(r.ip,'192.0.2.'+(i+1)));
 console.log('PASS concurrent attempts across 12 IPs limited atomically; spoofed proxy header ignored');
 const token=await worker(['prepare'],env);
 const consumers=await Promise.all([worker(['consume'],{...env,TEST_RESET_TOKEN:token}),worker(['consume'],{...env,TEST_RESET_TOKEN:token})]);
 assert.deepEqual(consumers.sort(),['accepted','rejected']);
 assert.equal((await loginClient('/protected')).status,302);
 console.log('PASS concurrent token consumption succeeds once and revokes actual login session');
 const resetClient=client(),httpToken=await worker(['prepare','2'],env);
 const resetPage=await resetClient('/reset_password.php');assert.equal(resetPage.status,200);
 assert.ok(resetPage.headers.get('cache-control').includes('no-store'));
 const input={value:''};require('node:vm').runInNewContext(fs.readFileSync(path.join(root,'public/assets/js/security_password.js'),'utf8'),{document:{getElementById:()=>input},location:{hash:'#token='+httpToken},URLSearchParams});
 assert.equal(input.value,httpToken);
 const resetResponse=await resetClient('/reset_password.php',{csrf_token:await csrf(resetClient),reset_token:input.value,new_password:'Webreset9!Secret',confirm_password:'Webreset9!Secret'});
 assert.ok((await resetResponse.text()).includes('Password berhasil diubah'));
 const replay=await resetClient('/reset_password.php',{csrf_token:await csrf(resetClient),reset_token:input.value,new_password:'Another9!Secret',confirm_password:'Another9!Secret'});
 assert.ok((await replay.text()).includes('tidak valid atau kedaluwarsa'));
 console.log('PASS public reset page: fragment handling, CSRF submit, no-store, and replay denial');
 const logs=fs.readFileSync(path.join(tmp,'logs','application.log'),'utf8');
 for(const secret of [temp.password,'Private9!New','Initial8!Secret','Webreset9!Secret','0000000000000012',token,httpToken,...seenSessionIds])assert.ok(!logs.includes(secret),'Sensitive test value leaked to log');
 console.log('PASS security logs contain no test passwords, reset tokens, full NIK, or session IDs');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{
 if(databasePort) spawnSync(path.join(path.dirname(mysqld),'mysqladmin.exe'),['--no-defaults','--host=127.0.0.1','--port='+databasePort,'--user=root','shutdown'],{timeout:30000,stdio:'ignore'});
 for(const child of children.reverse()) {if(child.exitCode===null&&child.pid){const closed=new Promise(r=>child.once('exit',r));child.kill();await closed;}}
 await new Promise(r=>setTimeout(r,2000));
 assert.equal(path.dirname(tmp),path.resolve(os.tmpdir()));assert.ok(path.basename(tmp).startsWith('siprima-security-'));
 fs.rmSync(tmp,{recursive:true,force:true,maxRetries:10,retryDelay:250});
});
