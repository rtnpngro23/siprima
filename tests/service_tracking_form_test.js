const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/js/service-tracking.js'), 'utf8');
function harness(status = 'diajukan') {
    const handlers = {};
    const node = () => ({style: {}, dataset: {}, value: '', textContent: '', children: [], addEventListener() {},
        appendChild(child) { this.children.push(child); }, before() {}, after() {},
        setAttribute(name, value) { this[name] = value; }, removeAttribute(name) { delete this[name]; }});
    const form = Object.assign(node(), {action: {value: 'update_status'}, getAttribute: () => null, reportValidity: () => true,
        matches: () => true, addEventListener() {}});
    const select = Object.assign(node(), {name: 'status', value: status, options: ['diajukan', 'diproses', 'selesai', 'ditolak'].map(value => ({value})),
        closest: () => form, addEventListener() {}});
    const note = Object.assign(node(), {name: 'alasan', value: 'Catatan lama', placeholder: 'Tuliskan alasan'});
    const button = node(); const upload = Object.assign(node(), {name:'dokumen_hasil[]',value:'hasil.pdf'});
    form.elements = {status: select, alasan: note, expected_status: Object.assign(node(),{value:status}), history_version: Object.assign(node(),{value:4})};
    form.appendChild = child => { if (child.name) form.elements[child.name] = child; };
    const section = node();
    form.querySelector = selector => {
        if (selector.startsWith('textarea')) return note;
        if (selector.startsWith('button')) return button;
        if (selector.includes('dokumen_hasil[]')) return upload;
        if (selector === '[data-dokumen-hasil-upload]') return section;
        if (selector === '[data-status-note-help]') return null;
        const match = selector.match(/input\[name="([^"]+)"\]/);
        return match ? form.elements[match[1]] || null : null;
    };
    const message = node(); const history = node(); const badge = node();
    const doc = {addEventListener(name, callback) { (handlers[name] ||= []).push(callback); }, createElement: node,
        querySelector: () => form, querySelectorAll: selector => selector === '[data-service-current-status]' ? [badge] : [],
        getElementById: id => ({'service-tracking-state': {textContent: JSON.stringify({status,version:4,draft:''})},
            'service-tracking-message': message, 'service-tracking-history': history}[id])};
    const requests = [];
    const context = {document: doc, window: {location: {href: '/detail?id=1'}, dispatchEvent() {}},
        CustomEvent: function(){}, FormData: function(form) { this.status = form.elements.status.value; this.note = note.value; },
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({url,options,resolve,reject}))};
    vm.runInNewContext(source, context);
    handlers.DOMContentLoaded.forEach(callback => callback());
    const submit = () => handlers.submit[0]({target:form,preventDefault(){},stopImmediatePropagation(){}});
    return {form,select,note,button,upload,message,history,badge,requests,submit,context};
}
(async () => {
    let h = harness();
    assert.equal(h.note.value, ''); assert.equal(h.note.placeholder, undefined); assert.equal(h.note.required, false);
    assert.equal(h.select.value, 'diproses'); assert.equal(h.button.textContent, 'Mulai Proses');
    h.note.value = 'Sedang diperiksa';
    const pending = h.submit(); await h.submit();
    assert.equal(h.requests.length, 1); assert.equal(h.button.disabled, true);
    assert.equal(h.requests[0].url, '/detail?id=1');
    assert.equal(h.requests[0].options.body.note, 'Sedang diperiksa');
    h.requests[0].resolve({ok:true,json:async()=>({success:true,status:'diproses',history_version:5,history_html:'Riwayat terbaru',message:'Tersimpan'})});
    await pending;
    assert.equal(h.note.value, ''); assert.equal(h.history.innerHTML, 'Riwayat terbaru'); assert.equal(h.badge.textContent, 'DIPROSES');
    assert.equal(h.context.window.location.href, '/detail?id=1'); assert.equal(h.button.disabled, false);
    assert.equal(h.button.textContent, 'Simpan Pembaruan Progres'); assert.equal(h.note.required, true);
    h.note.value = 'Menunggu persetujuan Dukcapil asal';
    const progressPending = h.submit();
    h.requests[1].resolve({ok:true,json:async()=>({success:true,status:'diproses',history_version:6,history_html:'Pembaruan progres',status_note:h.note.value,message:'Progres disimpan'})});
    await progressPending;
    assert.equal(h.note.value,''); assert.equal(h.form.elements.expected_status.value,'diproses');
    assert.equal(h.form.elements.history_version.value,6); assert.equal(h.history.innerHTML,'Pembaruan progres');
    h = harness('diproses'); h.note.value = 'Jangan hilang';
    assert.equal(h.note.required, true); assert.equal(h.button.textContent, 'Simpan Pembaruan Progres');
    let work = h.submit(); h.requests[0].reject(new Error('Jaringan terputus')); await work;
    assert.equal(h.note.value,'Jangan hilang'); assert.equal(h.upload.value,'hasil.pdf'); assert.equal(h.form.elements.history_version.value,4);
    h = harness('diproses'); h.select.value='selesai'; h.note.value='Catatan selesai';
    work = h.submit(); h.requests[0].resolve({ok:true,json:async()=>({success:true,partial:true,status:'selesai',history_version:6,message:'Status tersimpan, unggahan gagal'})}); await work;
    assert.equal(h.note.value,'Catatan selesai'); assert.equal(h.upload.value,'hasil.pdf'); assert.equal(h.form.elements.expected_status.value,'selesai');
    h = harness('diproses'); h.note.value='Awal'; work=h.submit(); h.note.value='Catatan baru saat menunggu';
    h.requests[0].resolve({ok:true,json:async()=>({success:true,status:'selesai',history_version:6,files_uploaded:true,message:'Tersimpan'})}); await work;
    assert.equal(h.note.value,'Catatan baru saat menunggu'); assert.equal(h.upload.value,'');
    h = harness('diproses'); h.note.value='Catatan konflik'; work=h.submit();
    h.requests[0].resolve({ok:false,json:async()=>({success:false,message:'Status telah berubah'})}); await work;
    assert.equal(h.note.value,'Catatan konflik'); assert.equal(h.message.textContent,'Status telah berubah');
    console.log('Form behavior checks passed: blank notes, no navigation, duplicate click, failed request, partial upload, in-flight edits, conflict.');
})().catch(error => { console.error(error); process.exitCode=1; });
