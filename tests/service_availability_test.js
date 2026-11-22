const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
function element(props = {}) {
  return Object.assign({dataset:{}, attrs:{}, disabled:false, hidden:false, textContent:'',
    children:[], events:{}, append(...items){this.children.push(...items);}, replaceChildren(){this.children=[];}, addEventListener(k,fn){this.events[k]=fn;}, style:{}, setAttribute(k,v){this.attrs[k]=v;}, removeAttribute(k){delete this.attrs[k];},
    scrollIntoView(){this.scrolled=true;}}, props);
}
async function run() {
  const events = {};
  const button = element();
  const unrelatedDisabled = element({disabled:true});
  const edit = element({href:'http://localhost/user/edit_kk.php?id=1'});
  const status = element({href:'http://localhost/user/status_permohonan.php'});
  const banner = element();
  let interval;
  let next = {is_open:false,message:'Pengajuan ditutup; isian tetap tersimpan.'};
  const context = {
    URL, JSON, location:{origin:'http://localhost',pathname:'/user/edit_kk.php',href:'http://localhost/user/edit_kk.php?id=1'},
    document:{
      hidden:false,
      getElementById(id){return id === 'service-availability-state' ? {textContent:JSON.stringify({is_open:true,message:''})} : null;},
      createElement(tag){return tag==='section' ? banner : element();},
      querySelector(){return {prepend(){}};},
      querySelectorAll(selector){return selector==='a[href]' ? [edit,status] : [button,unrelatedDisabled];},
      addEventListener(type,handler){events[type]=handler;}
    },
    setInterval(handler,ms){assert.equal(ms,30000); interval=handler;},
    fetch:async()=>({ok:true,json:async()=>next})
  };
  context.window = {};
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../public/assets/js/service-notice.js'),'utf8'),context);
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../public/assets/js/service-availability.js'),'utf8'),context);
  assert.equal(button.disabled,false);
  assert.equal(banner.hidden,true);
  await interval();
  assert.equal(button.disabled,true);
  assert.equal(banner.children[1].textContent,next.message);
  assert.equal(edit.attrs['aria-disabled'],'true');
  assert.equal(status.attrs['aria-disabled'],undefined);
  let prevented=0;
  const event={preventDefault(){prevented++;},stopImmediatePropagation(){},target:{closest(){return edit;}}};
  events.click(event);
  events.submit(event);
  assert.equal(prevented,2);
  assert.equal(banner.scrolled,true);
  next={is_open:true,message:''};
  await interval();
  assert.equal(button.disabled,false);
  assert.equal(unrelatedDisabled.disabled,true);
  assert.equal(edit.attrs['aria-disabled'],undefined);
  assert.equal(banner.hidden,true);
  const viewRoot = element();
  const view = context.window.SiprimaServiceNotice.create(viewRoot);
  const noticeState = {is_open:true,message:'Full text',title:'Announcement',summary:'13:00–14:00',resume:'Resume at 14:00',
    detail:'<img src=x onerror=alert(1)>',countdown:'3 minutes'};
  view.update(noticeState);
  assert.equal(viewRoot.children[0].textContent,'Announcement');
  assert.equal(viewRoot.children[3].children[1].textContent,noticeState.detail);
  assert.equal(viewRoot.children[4].textContent,'3 minutes');
  assert.equal(viewRoot.dataset.tone,'warning');
  viewRoot.children[3].open = true;
  view.update({...noticeState,countdown:'2 minutes'});
  assert.equal(viewRoot.children[3].open,true);
  view.update({...noticeState,is_open:false,countdown:''});
  assert.equal(viewRoot.dataset.tone,'closed');
  assert.equal(viewRoot.children[4].hidden,true);

  const saved = new Map();
  function popup(version, type='closure', blockedStorage=false) {
    const root = element(), dialog = element({open:false}), closeButton = element();
    let opens=0;
    dialog.showModal=()=>{dialog.open=true; opens++;};
    dialog.close=()=>{dialog.open=false; dialog.events.close();};
    dialog.querySelectorAll=()=>[closeButton];
    const data={...noticeState,notice:{id:'event1',version,type}};
    const storage={getItem(key){if(blockedStorage)throw Error('blocked');return saved.get(key);},
      setItem(key,value){if(blockedStorage)throw Error('blocked');saved.set(key,value);}};
    const c={window:{localStorage:storage,sessionStorage:storage},document:{
      body:{style:{}},activeElement:{focus(){}},
      getElementById(id){return {'service-home-notice-state':{textContent:JSON.stringify(data)},
        'service-home-notice':root,'service-announcement-dialog':dialog}[id];},
      createElement(){return element();}
    }};
    vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../public/assets/js/service-notice.js'),'utf8'),c);
    return {root,dialog,closeButton,opens:()=>opens};
  }
  let p=popup('v1'); assert.equal(p.opens(),1);
  p.closeButton.events.click(); assert.equal(p.dialog.open,false);
  p=popup('v1'); assert.equal(p.opens(),0);
  p.root.children.at(-1).events.click(); assert.equal(p.opens(),1);
  p=popup('v2'); assert.equal(p.opens(),1);
  p=popup('v3','notice'); assert.equal(p.opens(),0);
  p=popup('v4','closure',true); assert.equal(p.opens(),1);
  p.closeButton.events.click(); assert.equal(p.dialog.open,false);
  console.log('Announcement checks passed: compact details, warning order, preserved expansion, popup dismissal/versioning/reopen, and unavailable storage.');
  console.log('Client status checks passed: refresh, disable/re-enable, safe links, and submit interception.');
}
run().catch(error=>{console.error(error);process.exitCode=1;});