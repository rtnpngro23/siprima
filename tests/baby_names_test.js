'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const base=path.join(__dirname,'..'),code=fs.readFileSync(path.join(base,'public/assets/js/baby-names.js'),'utf8');
const context={URL,AbortSignal,fetch:async()=>({ok:true,json:async()=>data})};vm.runInNewContext(code,context);
const B=context.BabyNames,data=JSON.parse(fs.readFileSync(path.join(base,'public/assets/data/baby-names.json'),'utf8'));
assert.equal(B.validate(data).names.length,200);
assert.equal(new Set(data.names.map(n=>n.id)).size,200);
for(const gender of ['L','P'])assert.equal(data.names.filter(n=>n.g===gender).length,100);
let tests=2;
const selections=data.regions.map(region=>({region}));
for(let i=0;i<data.regions.length;i++)for(let j=i+1;j<data.regions.length;j++)selections.push({region:'campuran',mix:[data.regions[i],data.regions[j]]});
for(const gender of ['L','P'])for(const selection of selections)for(const count of [2,3,4])for(const theme of ['all',...Object.keys(data.themes)]){
  const {region,mix}=selection,options={gender,...selection,count,theme},pool=B.pool(data,options);
  const possible=theme==='all'||pool.some(n=>n.themes.includes(theme));
  let seed=9;const rng=()=>{seed=(Math.imul(seed,1664525)+1013904223)>>>0;return seed/4294967296;};
  const rows=B.generate(data,options,rng);
  assert.equal(rows.length,possible?5:0);
  assert.equal(new Set(rows.map(row=>row.map(n=>n.id).join(':'))).size,rows.length);
  for(const row of rows){assert.equal(row.length,count);assert.equal(new Set(row.map(n=>n.id)).size,count);assert(row.every(n=>n.g===gender));assert(region==='campuran'?new Set(row.map(n=>n.region)).size===2 && row.every(n=>mix.includes(n.region)):row.every(n=>n.region===region));assert(theme==='all'||row.some(n=>n.themes.includes(theme)));
    const next=B.replaceWord(data,row,0,options,rng);if(next){assert.notEqual(next[0].id,row[0].id);assert(next.every(n=>pool.some(p=>p.id===n.id)));if(mix)assert(mix.every(r=>next.some(n=>n.region===r)));assert.deepEqual(next.slice(1).map(n=>n.id),row.slice(1).map(n=>n.id));assert(theme==='all'||next.some(n=>n.themes.includes(theme)));}
  }tests++;
}
const options={gender:'L',region:'Arab',count:2,theme:'all'};
assert.equal(B.generate(data,{...options,region:'Gorontalo'}).length,0);
for(const mix of [[],['Arab'],['Arab','Arab'],['Arab','Gorontalo'],['Arab','Eropa','Sanskerta'],null,'Arab'])assert.equal(B.generate(data,{...options,region:'campuran',mix}).length,0);
assert(B.generate(data,{...options,region:'campuran'}).every(row=>row.every(n=>['Arab','Eropa'].includes(n.region))));
for(const region of data.regions)for(const gender of ['L','P'])assert.equal(data.names.filter(n=>n.region===region && n.g===gender).length,25);
assert.equal(B.resolveSaved(data,[['bagus','arya'],['cinta','gita']]).length,2);
assert.equal(B.generate(data,{...options,count:100}).length,0);
assert.equal(B.generate(data,{...options,gender:'bad'}).length,0);
assert.equal(B.generate({...data,names:data.names.slice(0,1)},options).length,0);
assert.equal(B.generate(data,options,()=>0).length,1); // bounded even when random never varies
const first=B.generate(data,options),next=B.generate(data,options,Math.random,first);
assert(next.every(row=>!first.some(old=>old.map(n=>n.id).join(':')===row.map(n=>n.id).join(':'))));
assert.equal(B.sourceUrl('javascript:alert(1)'),null);assert.equal(B.sourceUrl('https://dmnes.org.evil.test/name/A'),null);
for(const patch of [{id:data.names[1].id},{status:'pending_source_review'},{source:{title:'x',url:'javascript:alert(1)'}},{n:'<script>'},{themes:['invented']}]){const bad=JSON.parse(JSON.stringify(data));Object.assign(bad.names[0],patch);assert.throws(()=>B.validate(bad));tests++;}
assert.equal(B.resolveSaved(data,[['ahmad','amin'],['missing','amin'],['amin','amin'],['ahmad','amin']]).length,1);
assert.equal(B.resolveSaved(data,{bad:true}).length,0);
// Both views must load the shared catalog and the removed lists must not return.
const generator=fs.readFileSync(path.join(base,'public/user/generate_nama_bayi.php'),'utf8')+fs.readFileSync(path.join(base,'app/views/baby_name_generator_content.php'),'utf8'),dictionary=fs.readFileSync(path.join(base,'public/kamus-nama-bayi.html'),'utf8');
assert(generator.includes('baby-names.json'));assert(dictionary.includes('baby-names.json'));assert(!generator.includes('birthDay'));assert(!generator.includes('const names ='));assert(!dictionary.includes('{n:"'));
for(const match of dictionary.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g))if(match[1].trim())new vm.Script(match[1]);
(async()=>{assert.equal((await B.load('/catalog')).names.length,200);context.fetch=async()=>({ok:false});await assert.rejects(B.load('/catalog'));context.fetch=async()=>({ok:true,json:async()=>({version:99})});await assert.rejects(B.load('/catalog'));console.log('Baby names: '+tests+' filter/data scenarios and boundary, shared-view, loading checks passed');})().catch(e=>{console.error(e);process.exitCode=1;});