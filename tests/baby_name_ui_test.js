'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const base=path.join(__dirname,'..'),data=JSON.parse(fs.readFileSync(path.join(base,'public/assets/data/baby-names.json'),'utf8'));
class Element {
  constructor(tag='div'){this.tag=tag;this.children=[];this.dataset={};this.events={};this.attributes={};this.value='';this._text='';this.style={};}
  set textContent(v){this._text=v;this.children=[];}get textContent(){return this._text+this.children.map(c=>c.textContent).join('');}
  appendChild(c){this.children.push(c);return c;}append(...items){this.children.push(...items);}replaceChildren(...items){this._text='';this.children=items;}
  setAttribute(k,v){this.attributes[k]=v;}addEventListener(k,fn){this.events[k]=fn;}showModal(){this.open=true;}close(){this.open=false;}
}
async function setup(fail=false,module='user'){
  const ids={};for(const id of ['mixedOrigins','mixOriginA','mixOriginB','baby-generator','gender','nameOrigin','nameTheme','wordCount','resultInfo','nameResults','favoritesToggle','catalogInfo','generateButton','retryCatalog','namePreferences','confirmBabyName','confirmedName','handoffNotice','cancelBabyName','useBabyName'])ids[id]=new Element();
  ids['baby-generator'].dataset={account:'7',catalog:'/data',apply:module==='user'?'akta_kelahiran.php':module+'_akta_kelahiran.php'};ids.gender.value='L';ids.nameOrigin.value='Arab';ids.nameTheme.value='all';ids.wordCount.value='2';ids.mixOriginA.value='Arab';ids.mixOriginB.value='Sanskerta';
  const local=new Map(),session=new Map();let target=null,storageBlocked=false;
  const ctx={URL,AbortSignal,Math,location:{href:'https://siprima.test/'+(module==='user'?'user':'user_'+module)+'/generate_nama_bayi.php',origin:'https://siprima.test',assign(url){target=url;}},document:{getElementById:id=>ids[id],createElement:tag=>new Element(tag),querySelector:()=>null},navigator:{clipboard:{writeText:async()=>{}}},fetch:async()=>({ok:!fail,json:async()=>data}),localStorage:{getItem:k=>local.get(k)||null,setItem(k,v){if(storageBlocked)throw Error('blocked');local.set(k,v);}},sessionStorage:{setItem(k,v){if(storageBlocked)throw Error('blocked');session.set(k,v);}}};
  ctx.window=ctx;vm.runInNewContext(fs.readFileSync(path.join(base,'public/assets/js/baby-names.js'),'utf8'),ctx);vm.runInNewContext(fs.readFileSync(path.join(base,'public/user/js/baby-name-generator.js'),'utf8'),ctx);await new Promise(r=>setImmediate(r));
  return {ids,ctx,local,session,getTarget:()=>target,blockStorage:()=>{storageBlocked=true;}};
}
function find(node,text){if(node.tag==='button' && node.textContent===text)return node;for(const c of node.children){const found=find(c,text);if(found)return found;}return null;}
(async()=>{
  let app=await setup();assert.equal(app.ids.nameResults.children.length,5);assert.equal(app.ids.generateButton.disabled,false);assert(app.ids.catalogInfo.textContent.includes('100 nama laki-laki dan 100 nama perempuan'));
  find(app.ids.nameResults,'Simpan favorit').events.click();assert.equal(JSON.parse(app.local.get('siprima:baby-name:favorites:7')).length,1);
  app.ids.favoritesToggle.events.click();assert.equal(app.ids.nameResults.children.length,1);assert(!app.ids.nameResults.textContent.includes('Ganti '));
  find(app.ids.nameResults,'Gunakan nama ini').events.click();assert.equal(app.ids.confirmBabyName.open,true);assert.equal(app.getTarget(),null);
  app.ids.useBabyName.events.click();assert.equal(app.getTarget(),'https://siprima.test/user/akta_kelahiran.php?inspirasi_nama=1');
  const handoff=JSON.parse(app.session.get('siprima:baby-name:selection'));assert.equal(handoff.account,'7');assert.equal(handoff.path,'/user/akta_kelahiran.php');assert.equal(handoff.ids.length,2);assert(!('name' in handoff));
  assert.equal(app.ids.mixedOrigins.hidden,true);
  app.ids.nameOrigin.value='Indonesia/Nusantara';app.ids.namePreferences.events.change();assert.equal(app.ids.nameResults.children.length,5);assert(app.ids.nameResults.children.every(card=>card.children[0].textContent.split(' ').every(word=>data.names.some(n=>n.n===word && n.region==='Indonesia/Nusantara'))));
  app.ids.nameOrigin.value='campuran';app.ids.namePreferences.events.change();assert.equal(app.ids.mixedOrigins.hidden,false);assert.equal(app.ids.nameResults.children.length,5);
  for(const card of app.ids.nameResults.children){const regions=card.children[0].textContent.split(' ').map(word=>data.names.find(n=>n.n===word).region);assert(regions.includes('Arab'));assert(regions.includes('Sanskerta'));}
  app.ids.mixOriginB.value='Arab';app.ids.namePreferences.events.change();assert.equal(app.ids.nameResults.children.length,0);assert(app.ids.resultInfo.textContent.includes('dua asal nama yang berbeda'));
  app.ids.mixOriginA.value='Indonesia/Nusantara';app.ids.mixOriginB.value='Sanskerta';app.ids.namePreferences.events.change();assert.equal(app.ids.nameResults.children.length,5);
  find(app.ids.nameResults,'Gunakan nama ini').events.click();app.ids.useBabyName.events.click();const mixed=JSON.parse(app.session.get('siprima:baby-name:selection'));assert(mixed.ids.every(id=>data.names.some(n=>n.id===id && ['Indonesia/Nusantara','Sanskerta'].includes(n.region))));
  app.ids.nameOrigin.value='unsupported';app.ids.namePreferences.events.change();assert.equal(app.ids.nameResults.children.length,0);assert(app.ids.nameResults.textContent.includes('Belum ada rangkaian'));
  app=await setup();app.blockStorage();find(app.ids.nameResults,'Simpan favorit').events.click();assert(app.ids.resultInfo.textContent.includes('belum bisa disimpan'));assert.equal(app.local.size,0);
  find(app.ids.nameResults,'Gunakan nama ini').events.click();app.ids.useBabyName.events.click();assert.equal(app.getTarget(),null);assert(app.ids.handoffNotice.textContent.includes('belum bisa diteruskan'));
  app=await setup(true);assert.equal(app.ids.retryCatalog.hidden,false);assert.equal(app.ids.generateButton.disabled,true);
  app.ctx.fetch=async()=>({ok:true,json:async()=>data});await app.ids.retryCatalog.events.click();await new Promise(r=>setImmediate(r));assert.equal(app.ids.nameResults.children.length,5);
  for(const module of ['ahlak','rs']){
    app=await setup(false,module);assert.equal(app.ids.nameResults.children.length,5);
    find(app.ids.nameResults,'Gunakan nama ini').events.click();assert.equal(app.getTarget(),null);
    app.ids.useBabyName.events.click();assert.equal(app.getTarget(),'https://siprima.test/user_'+module+'/'+module+'_akta_kelahiran.php?inspirasi_nama=1');
    const item=JSON.parse(app.session.get('siprima:baby-name:selection'));assert.equal(item.path,'/user_'+module+'/'+module+'_akta_kelahiran.php');assert.equal(item.account,'7');
  }
  console.log('Baby name UI: suggestions, favorites, confirmation, handoff, unavailable origin, blocked storage and retry passed (DOM simulation)');
})().catch(e=>{console.error(e);process.exitCode=1;});