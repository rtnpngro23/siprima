'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const base=path.join(__dirname,'..'),core=fs.readFileSync(path.join(base,'public/assets/js/baby-names.js'),'utf8'),code=fs.readFileSync(path.join(base,'public/user/js/baby-name-handoff.js'),'utf8');
const data=JSON.parse(fs.readFileSync(path.join(base,'public/assets/data/baby-names.json'),'utf8'));
async function run(patch={},value='',method='GET',fail=false,race=false,page='/user/akta_kelahiran.php'){
  let removed=false,requests=0,note=null;
  const item={ids:['ahmad','amin'],account:'7',path:page,at:Date.now(),...patch};
  const field={value,dispatchEvent(){},insertAdjacentElement(_,el){note=el;}};
  const ctx={URL,URLSearchParams,AbortSignal,Event:class{},Date,location:{pathname:page,search:'?inspirasi_nama=1'},sessionStorage:{getItem:()=>JSON.stringify(item),removeItem(){removed=true;}},document:{currentScript:{dataset:{account:'7',catalog:'/data',method}},querySelector:()=>field,createElement:()=>({style:{},setAttribute(){}})},fetch:async()=>{requests++;if(fail)throw Error('network');if(race)field.value='Nama yang diketik';return{ok:true,json:async()=>data};}};
  vm.runInNewContext(core,ctx);vm.runInNewContext(code,ctx);await new Promise(r=>setImmediate(r));return{field,removed,requests,note};
}
(async()=>{
  let r=await run();assert.equal(r.field.value,'Ahmad Amin');assert.equal(r.removed,true);
  for(const page of ['/user/akta_kelahiran.php','/user/petugas_desa_akta_kelahiran.php','/user_ahlak/ahlak_akta_kelahiran.php','/user_rs/rs_akta_kelahiran.php']){
    r=await run({ids:['bagus','arya']},'','GET',false,false,page);assert.equal(r.field.value,'Bagus Arya');assert.equal(r.removed,true);
    r=await run({ids:['cinta','gita']},'','GET',false,false,page);assert.equal(r.field.value,'Cinta Gita');assert.equal(r.removed,true);
    r=await run({path:'/other/akta_kelahiran.php'},'','GET',false,false,page);assert.equal(r.requests,0);assert.equal(r.field.value,'');
    r=await run({},'Nama yang sudah diisi','GET',false,false,page);assert.equal(r.requests,0);assert.equal(r.field.value,'Nama yang sudah diisi');
    r=await run({},'','POST',false,false,page);assert.equal(r.requests,0);
  }
  for(const patch of [{account:'8'},{path:'/user/other.php'},{at:Date.now()-1900000},{at:Date.now()+100000}]){r=await run(patch);assert.equal(r.requests,0);assert.equal(r.field.value,'');}
  r=await run({ids:['unknown','amin']});assert.equal(r.field.value,'');assert.equal(r.removed,false);
  r=await run({},'Nama keluarga');assert.equal(r.field.value,'Nama keluarga');assert.equal(r.requests,0);
  r=await run({},'','POST');assert.equal(r.requests,0);
  r=await run({},'','GET',false,true);assert.equal(r.field.value,'Nama yang diketik');assert.equal(r.removed,false);
  r=await run({},'','GET',true);assert.equal(r.field.value,'');assert(r.note.textContent.includes('belum bisa'));assert.equal(r.removed,false);
  console.log('Baby name handoff: account, target, expiry, invalid IDs, existing input, failed POST, typing race and network failure passed');
})().catch(e=>{console.error(e);process.exitCode=1;});