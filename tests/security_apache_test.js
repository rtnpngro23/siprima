const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const os=require('node:os');
const net=require('node:net');
const {spawn,spawnSync}=require('node:child_process');
const httpd=process.env.HTTPD_BINARY;
if(!httpd)throw Error('Set HTTPD_BINARY to an Apache 2.4 binary.');
const root=path.resolve(__dirname,'..'),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'siprima-apache-'));
let server;
const quote=p=>'"'+p.replaceAll('\\','/')+'"';
(async()=>{
 const socket=net.createServer();await new Promise(r=>socket.listen(0,'127.0.0.1',r));const port=socket.address().port;await new Promise(r=>socket.close(r));
 const site=path.join(tmp,'site');
 for(const dir of ['public/uploads','public/user/uploads','app','logs','storage','sql'])fs.mkdirSync(path.join(site,dir),{recursive:true});
 fs.copyFileSync(path.join(root,'.htaccess'),path.join(site,'.htaccess'));
 fs.copyFileSync(path.join(root,'public/.htaccess'),path.join(site,'public/.htaccess'));
 for(const file of ['app/fixture.txt','logs/fixture.txt','storage/fixture.txt','sql/fixture.txt','public/error_log.txt','public/error_log.txt.1','public/uploads/private.pdf','public/user/uploads/private.pdf'])fs.writeFileSync(path.join(site,file),'SYNTHETIC_PRIVATE_FIXTURE');
 fs.writeFileSync(path.join(site,'public/index.html'),'Public fixture');
 const base=path.dirname(path.dirname(httpd));
 const config=[`ServerRoot ${quote(base)}`,`Listen 127.0.0.1:${port}`,`ServerName localhost:${port}`,`PidFile ${quote(path.join(tmp,'httpd.pid'))}`,`ErrorLog ${quote(path.join(tmp,'error.log'))}`,'LogLevel warn',
 ...['authz_core','rewrite','headers','dir'].map(m=>`LoadModule ${m}_module ${quote(path.join(base,'modules','mod_'+m+'.so'))}`),
 `DocumentRoot ${quote(site)}`,`<Directory ${quote(site)}>`, 'Require all granted','AllowOverride All','Options -Indexes','</Directory>'].join('\n');
 const conf=path.join(tmp,'httpd.conf');fs.writeFileSync(conf,config);
 const syntax=spawnSync(httpd,['-t','-f',conf],{encoding:'utf8'});assert.equal(syntax.status,0,syntax.stderr);
 server=spawn(httpd,['-X','-f',conf],{stdio:'ignore'});
 const url='http://127.0.0.1:'+port;
 for(let i=0;i<100;i++){try{await fetch(url,{redirect:'manual'});break;}catch{if(i===99)throw Error('Apache did not start');await new Promise(r=>setTimeout(r,100));}}
 for(const file of ['app/fixture.txt','logs/fixture.txt','storage/fixture.txt','sql/fixture.txt','public/error_log.txt','public/error_log.txt.1','public/uploads/private.pdf','public/user/uploads/private.pdf']){
   const r=await fetch(url+'/'+file,{redirect:'manual'});assert.equal(r.status,403,file);assert.ok(!(await r.text()).includes('SYNTHETIC_PRIVATE_FIXTURE'));
 }
 const response=await fetch(url+'/public/index.html',{redirect:'manual'});
 assert.equal(response.status,301);
 const csp=response.headers.get('content-security-policy');
 assert.ok(csp.includes('https://cdn.tailwindcss.com'));
 assert.ok(!/script-src[^;]*\shttps:;/.test(csp));
 assert.ok(response.headers.get('content-security-policy-report-only').includes("script-src 'self'"));
 assert.equal(response.headers.get('x-content-type-options'),'nosniff');
 console.log('PASS Apache: 8 private/log paths blocked; HTTPS redirect, script allowlist, Report-Only and nosniff headers verified');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{
 if(server?.pid&&server.exitCode===null){const exited=new Promise(r=>server.once('exit',r));if(process.platform==='win32')spawnSync('taskkill',['/PID',String(server.pid),'/T','/F'],{stdio:'ignore'});else server.kill();await exited;}
 await new Promise(r=>setTimeout(r,500));
 assert.equal(path.dirname(tmp),path.resolve(os.tmpdir()));assert.ok(path.basename(tmp).startsWith('siprima-apache-'));
 fs.rmSync(tmp,{recursive:true,force:true,maxRetries:10,retryDelay:200});
});
