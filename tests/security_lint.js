const {spawnSync} = require('node:child_process');
const path = require('node:path');
const root=path.resolve(__dirname,'..');
const git=args=>{const r=spawnSync('git',args,{cwd:root,encoding:'utf8'});if(r.status!==0)throw Error(r.stderr);return r.stdout.trim().split(/\r?\n/).filter(Boolean);};
const files=[...new Set([...git(['diff','--name-only','--diff-filter=ACM']),...git(['ls-files','--others','--exclude-standard']), 'app/config/koneksi.php', 'app/config/session_cache.php'])];
let php=0,js=0;
for(const file of files){let binary,args;
 if(file.endsWith('.php')){binary=process.env.PHP_BINARY||'php';args=['-l',file];php++;}
 else if(file.endsWith('.js')){binary=process.execPath;args=['--check',file];js++;}else continue;
 const r=spawnSync(binary,args,{cwd:root,encoding:'utf8'});if(r.status!==0)throw Error(file+'\n'+r.stdout+r.stderr);
}
console.log(`PASS syntax: ${php} PHP files, ${js} JavaScript files`);
