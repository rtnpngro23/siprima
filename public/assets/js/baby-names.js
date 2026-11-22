(function (root) {
  'use strict';
  const regions = ['Arab','Eropa','Indonesia/Nusantara','Sanskerta'];
  const themes = ['kebaikan','kasih','kedamaian','bahagia','keberanian','bijaksana','cahaya'];
  function sourceUrl(value) {
    try { const u=new URL(value); return u.protocol==='https:' && ['dmnes.org','en.wiktionary.org','islamicbulletin.org','www.islamic-relief.org.za','repositori.kemendikdasmen.go.id','kbji.kemendikdasmen.go.id','sanskritdictionary.org'].includes(u.hostname) ? u.href : null; } catch (_) { return null; }
  }
  function validate(data) {
    if (!data || data.version!==1 || !Array.isArray(data.names) || !data.names.length || data.names.length>10000) throw new Error('Daftar nama belum bisa dibuka. Silakan coba lagi.');
    const ids=new Set(), names=new Set();
    for (const item of data.names) {
      if (!item || !/^[a-z0-9-]{1,60}$/.test(item.id) || ids.has(item.id) || typeof item.n!=='string' || !/^[\p{L}'’-]{1,50}$/u.test(item.n) || names.has(item.n.toLowerCase()) || !['L','P'].includes(item.g) || !regions.includes(item.region) || typeof item.o!=='string' || !item.o.trim() || typeof item.m!=='string' || !item.m.trim() || item.status!=='source_checked' || !Array.isArray(item.themes) || !item.themes.length || item.themes.some(t=>!themes.includes(t)) || !item.source || typeof item.source.title!=='string' || !sourceUrl(item.source.url)) throw new Error('Ada data nama yang belum siap. Silakan coba lagi nanti.');
      ids.add(item.id); names.add(item.n.toLowerCase());
    }
    return data;
  }
  async function load(url) {
    const response=await fetch(url,{credentials:'same-origin',signal:AbortSignal.timeout(15000)});
    if (!response.ok) throw new Error('Daftar nama belum bisa dibuka. Silakan coba lagi.');
    return validate(await response.json());
  }
  function selectedRegions(options) {
    if(options.region!=='campuran')return regions.includes(options.region)?[options.region]:[];
    const mix=options.mix===undefined?['Arab','Eropa']:options.mix;
    return Array.isArray(mix) && mix.length===2 && mix[0]!==mix[1] && mix.every(r=>regions.includes(r))?mix:[];
  }
  function pool(data,options) {
    if (!['L','P'].includes(options.gender) || !selectedRegions(options).length || ![2,3,4].includes(options.count) || (options.theme!=='all' && !themes.includes(options.theme))) return [];
    return data.names.filter(n=>n.g===options.gender && selectedRegions(options).includes(n.region));
  }
  function matches(words,options) {
    return words.length===options.count && new Set(words.map(w=>w.id)).size===words.length && (options.region!=='campuran' || selectedRegions(options).every(region=>words.some(w=>w.region===region))) && (options.theme==='all' || words.some(w=>w.themes.includes(options.theme)));
  }
  function shuffle(items, random) {
    const a=items.slice();for(let i=a.length-1;i>0;i--){const j=Math.floor(random()*(i+1));[a[i],a[j]]=[a[j],a[i]];}return a;
  }
  function generate(data,options,random=Math.random,previous=[]) {
    const choices=pool(data,options), seen=new Set(), result=[];
    if (choices.length<options.count) return result;
    const old=new Set(previous.map(row=>row.map(n=>n.id).join(':')));
    for(let attempt=0;attempt<800 && result.length<5;attempt++) {
      const words=shuffle(choices,random).slice(0,options.count), key=words.map(n=>n.id).join(':');
      if (seen.has(key) || (attempt<600 && old.has(key)) || !matches(words,options)) continue;
      seen.add(key);result.push(words);
    }
    return result;
  }
  function replaceWord(data,words,index,options,random=Math.random) {
    const choices=shuffle(pool(data,options).filter(n=>!words.some(w=>w.id===n.id)),random);
    for(const name of choices){const next=words.slice();next[index]=name;if(matches(next,options))return next;}return null;
  }
  function resolveSaved(data,stored) {
    if(!Array.isArray(stored))return [];
    const byId=new Map(data.names.map(n=>[n.id,n])),seen=new Set();
    return stored.slice(0,20).filter(ids=>Array.isArray(ids) && ids.length>=2 && ids.length<=4 && ids.every(id=>typeof id==='string' && byId.has(id)) && new Set(ids).size===ids.length).map(ids=>ids.map(id=>byId.get(id))).filter(row=>{const key=row.map(n=>n.id).join(':');if(seen.has(key))return false;seen.add(key);return true;});
  }
  root.BabyNames={load,validate,pool,generate,replaceWord,resolveSaved,sourceUrl};
})(globalThis);