'use strict';
const assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
const code = fs.readFileSync(__dirname+'/../public/user/js/asprima_handoff.js','utf8');
function run(overrides={}, initial='') {
  let value=JSON.stringify({text:'Mohon informasi',key:'kk:12',account:'7',path:'/user/status_permohonan.php',at:Date.now(),...overrides}), removed=false;
  const field={value:initial,insertAdjacentElement(){},scrollIntoView(){},focus(){}};
  const ctx={window:{},location:{search:'?asprima_comment=1',pathname:'/user/status_permohonan.php'},URLSearchParams,Date,Number,JSON,setTimeout(fn){fn();},sessionStorage:{getItem(){return value;},removeItem(){removed=true;}},document:{currentScript:{dataset:{accountId:'7'}},getElementById(){return field;},createElement(){return {style:{}};}}};
  vm.runInNewContext(code,ctx);ctx.window.asprimaApplyDraft(12,'kk');return {field,removed};
}
assert.equal(run().field.value,'Mohon informasi');assert.equal(run().removed,true);
for(const invalid of [{account:'8'},{key:'kk:13'},{path:'/elsewhere'},{at:Date.now()-700000},{at:Date.now()+60000},{text:42}]) {assert.equal(run(invalid).field.value,'');assert.equal(run(invalid).removed,false);}
assert.equal(run({},'Tulisan pemohon').field.value,'Tulisan pemohon');
console.log('ASPRIMA draft: 15 checks passed');