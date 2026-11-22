const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawn } = require('node:child_process');
const net = require('node:net');

(async () => {
  const root = path.resolve(__dirname, '..');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'siprima-login-'));
  let server;
  try {
    for (const sub of ['public','app/config','app/security','sessions']) fs.mkdirSync(path.join(dir,sub),{recursive:true});
    for (const file of ['login.php', 'login_add.php', 'login_kua.php', 'login_ahlak.php', 'login_rs.php'])
      fs.copyFileSync(path.join(root, 'public', file), path.join(dir, 'public', file));
    fs.copyFileSync(path.join(root, 'app/config/session_cache.php'), path.join(dir, 'app/config/session_cache.php'));
    // Isolate services and database; exercise real session configuration and login templates.
    fs.writeFileSync(path.join(dir, 'public/error_handler.php'), "<?php require_once __DIR__.'/../app/config/session_cache.php'; function csrf_input(){return '';}");
    fs.writeFileSync(path.join(dir, 'app/config/service_hours.php'), "<?php function service_hours_status(){return ['is_open'=>true];} function service_hours_event_notice(){return null;}");
    fs.writeFileSync(path.join(dir, 'app/security/login_security.php'), "<?php function login_throttle_status($id=null){return ['requires_captcha'=>false,'is_locked'=>false];}");
    fs.writeFileSync(path.join(dir, 'public/failure.php'), [
      "<?php require_once 'error_handler.php';",
      "if (session_status() === PHP_SESSION_NONE) session_start();",
      "$_SESSION['login_error']='NIK atau password yang Anda masukkan salah. Silakan periksa kembali.';",
      "$_SESSION['error_title']='Login Gagal';",
      "$_SESSION['error_type']='credentials_invalid';",
      "$_SESSION['login_username']='1234567890123456';",
      "$target = basename($_GET['target'] ?? 'login.php');",
      "session_write_close(); header('Location: '.$target);"
    ].join('\n'));
    const socket = net.createServer();
    await new Promise(resolve => socket.listen(0, '127.0.0.1', resolve));
    const port = socket.address().port;
    await new Promise(resolve => socket.close(resolve));
    server = spawn(process.env.PHP_BINARY || 'php', ['-d', 'session.save_path='+path.join(dir,'sessions'), '-S', '127.0.0.1:'+port, '-t', path.join(dir,'public')], {
      cwd: path.join(dir,'public'),
      env: { ...process.env, APP_SESSION_SECRET: 'isolated-login-regression-secret-1234567890' },
      stdio: 'ignore'
    });
    let spawnError;
    server.on('error', error => { spawnError = error; });
    const base = 'http://127.0.0.1:'+port;
    for (let i=0; i<50; i++) {
      if (spawnError) throw spawnError;
      try { await fetch(base+'/login.php'); break; } catch {
        if (i===49) throw new Error('Test server did not start');
        await new Promise(resolve=>setTimeout(resolve,100));
      }
    }
    for (const page of ['login.php','login_add.php','login_kua.php','login_ahlak.php','login_rs.php']) {
      const cookies = new Map();
      const request = async url => {
        const response = await fetch(base+url, {redirect:'manual',headers:{Cookie:[...cookies].map(([k,v])=>k+'='+v).join('; ')}});
        for (const cookie of response.headers.getSetCookie()) {
          const pair=cookie.split(';')[0]; const separator=pair.indexOf('=');
          cookies.set(pair.slice(0,separator),pair.slice(separator+1));
        }
        return response;
      };
      await request('/'+page);
      const failed = await request('/failure.php?target='+page);
      assert.equal(failed.status,302);
      const response = await request('/'+failed.headers.get('location'));
      const html = await response.text();
      assert.ok(html.includes('NIK atau password yang Anda masukkan salah.'), page+': error must survive redirect');
      assert.ok(html.includes('value="1234567890123456"'), page+': NIK retained');
      assert.ok(!/<input\b[^>]*type="password"[^>]*value=/i.test(html), page+': password stays empty');
      assert.ok(!(await (await request('/'+page)).text()).includes('NIK atau password yang Anda masukkan salah.'),page+': flash consumed once');
      assert.equal([...cookies.keys()].filter(k=>k.startsWith('SIPRIMA_')).length,1);
      assert.ok(!cookies.has('PHPSESSID'),page+': no separate default session');
      console.log('PASS '+page);
    }
  } finally {
    if (server && server.exitCode === null && server.pid) {
      const exited = new Promise(resolve => server.once('exit',resolve));
      server.kill();
      await exited;
    }
    // Only remove the uniquely created test directory inside the OS temp directory.
    assert.equal(path.dirname(dir),path.resolve(os.tmpdir()));
    assert.ok(path.basename(dir).startsWith('siprima-login-'));
    fs.rmSync(dir,{recursive:true,force:true});
  }
})().catch(error=>{console.error(error.message);process.exitCode=1;});