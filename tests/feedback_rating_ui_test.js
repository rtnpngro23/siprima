const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const source = fs.readFileSync('public/user/permohonan_feedback_modal.php','utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/<\?=json_encode\(\$feedbackCsrf\)\?>/,'"test-token"');
const scores = {feedback_rating:'5', feedback_service_rating:'5'};
const elements = {};
const requests = [];
function el(id) {
    return elements[id] ||= {value:'',hidden:false,required:false,textContent:'',style:{},listeners:{},
        addEventListener(type,fn){this.listeners[type]=fn;},
        dispatchEvent(event){this.listeners[event.type]?.(event);},
        setAttribute(){},focus(){this.focused=true;},
        classList:{add(){},remove(){},contains(){return true;}}
    };
}
const radios = [el('appRadio'),el('serviceRadio')];
const form = el('feedbackForm');
form.querySelector = selector => ({value:scores[selector.match(/name="([^"]+)"/)[1]]});
form.querySelectorAll = () => radios;
form.reset = () => {scores.feedback_rating='5';scores.feedback_service_rating='5';el('feedbackType').value='tanggapan';el('feedbackMessage').value='';};
form.reset();
const document = {getElementById:el,body:{style:{}},addEventListener(){},querySelector(){return null;}};
const window = {location:{href:'http://siprima_gorontalokab_riset.test:8080/public_html/public/user/status_permohonan.php'}};
const context = {document,window,URL,URLSearchParams,Event:class {constructor(type){this.type=type;}},setTimeout,fetch:async(url,opts)=>{
    assert.equal(url,'http://siprima_gorontalokab_riset.test:8080/public_html/public/user/permohonan_feedback.php');
    const payload=Object.fromEntries(opts.body);requests.push(payload);
    return {ok:true,json:async()=>({success:true,should_prompt:true,saved:true,show_skm:false})};
}};
vm.runInNewContext(script,context);
const tick = () => new Promise(resolve=>setImmediate(resolve));
async function open(){window.requestPermohonanFeedback('tb_kartukeluarga',1,'download');await tick();}
async function submit(){form.listeners.submit({preventDefault(){}});await tick();}
(async()=>{
    await open();
    assert.equal(scores.feedback_rating,'5');assert.equal(scores.feedback_service_rating,'5');
    assert.equal(el('feedbackMessage').required,false);
    await submit();
    assert.equal(requests.at(-1).rating,'5');assert.equal(requests.at(-1).service_rating,'5');
    await open();scores.feedback_service_rating='3';radios[1].listeners.change();
    assert.equal(el('feedbackMessage').required,true);
    let before=requests.length;await submit();assert.equal(requests.length,before);
    assert.match(el('feedbackError').textContent,/3 bintang/);
    el('feedbackMessage').value='Mohon informasi lebih jelas';await submit();
    assert.equal(requests.at(-1).service_rating,'3');
    await open();scores.feedback_rating='1';radios[0].listeners.change();
    assert.equal(el('feedbackMessage').required,true);
    scores.feedback_rating='4';radios[0].listeners.change();
    assert.equal(el('feedbackMessage').required,false);
    el('feedbackType').value='aduan';el('feedbackType').listeners.change();
    assert.equal(el('feedbackRatingField').hidden,true);assert.equal(el('feedbackMessage').required,true);
    before=requests.length;await submit();assert.equal(requests.length,before);
    el('feedbackMessage').value='Dokumen perlu diperiksa';await submit();
    assert.equal(requests.at(-1).jenis_feedback,'aduan');
    await open();el('feedbackSkip').listeners.click();await tick();
    assert.equal(requests.at(-1).action,'skip');assert.equal(requests.at(-1).rating,undefined);
    console.log('PASS: both defaults, low ratings, comments, complaint, reset, and skip');
})().catch(error=>{console.error(error);process.exitCode=1;});