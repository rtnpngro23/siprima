<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../app/config/document_features.php';
$count=0;
function check($condition, string $label): void { global $count; if (!$condition) throw new RuntimeException($label); $count++; }
function invalid(array $settings, string $label): void {
    try { document_features_validate($settings); } catch (InvalidArgumentException $e) { check(true,$label); return; }
    throw new RuntimeException($label);
}
$defaults=document_features_defaults();
$catalog=document_features_catalog();

check(array_keys($defaults)===array_keys($catalog),'Catalog covers exactly the default keys');
check(count($defaults)===4,'Four features are managed');
foreach ($defaults as $key=>$value) check(is_bool($value),'Default is boolean: '.$key);
foreach ($catalog as $key=>$meta) {
    foreach (['constant','label','scope','description','caution'] as $field) {
        check(isset($meta[$field]) && is_string($meta[$field]) && trim($meta[$field])!=='', 'Catalog field filled: '.$key.'.'.$field);
    }
    check(preg_match('/^[A-Z][A-Z0-9_]*$/D',$meta['constant'])===1,'Constant name is uppercase: '.$key);
    check(defined($meta['constant']),'Constant is defined: '.$meta['constant']);
    check(is_bool(constant($meta['constant'])),'Constant is boolean: '.$meta['constant']);
}

// Konstanta yang sudah dipakai halaman pemohon dan app/Api tidak boleh hilang atau berganti nama.
foreach (['DOKUMEN_HASIL_ENABLED','FORMULIR_F101_AUTO_ENABLED','FORMULIR_F201_AUTO_ENABLED','FORMULIR_F201_KEMATIAN_AUTO_ENABLED'] as $constant) {
    check(in_array($constant, array_column($catalog,'constant'), true),'Existing constant still managed: '.$constant);
}

document_features_validate($defaults);
check(true,'Defaults pass validation');
document_features_validate(array_map(function ($value) { return !$value; }, $defaults));
check(true,'Inverted defaults pass validation');

$missing=$defaults; unset($missing['dokumen_hasil']);
invalid($missing,'Missing key rejected');
invalid(array_merge($defaults,['dokumen_hasil'=>'true']),'String value rejected');
invalid(array_merge($defaults,['dokumen_hasil'=>1]),'Integer value rejected');
invalid(array_merge($defaults,['dokumen_hasil'=>null]),'Null value rejected');
invalid(array_merge($defaults,['fitur_lain'=>true]),'Unknown key rejected');
invalid([],'Empty settings rejected');

// Nilai awal migrasi harus sama persis dengan cadangan, agar migrasi tidak mengubah perilaku.
$sql=file_get_contents(__DIR__.'/../sql/20260923_document_features.sql');
check($sql!==false,'Migration file is readable');
check(preg_match('/INSERT IGNORE INTO tb_document_features \(id, payload\) VALUES\s*\(1, \'([^\']+)\'\)/',$sql,$m)===1,'Migration seeds the settings row');
$seed=json_decode($m[1],true,512,JSON_THROW_ON_ERROR);
document_features_validate($seed);
check($seed===$defaults,'Migration seed matches the PHP fallback');
check(str_contains($sql,'tb_document_features_audit'),'Migration creates the audit table');

if (!in_array('--integration',$argv,true)) { echo "PASS $count document feature checks\n"; exit; }

// Integrasi lapisan penyimpanan pada database terisolasi: migrasi, penguncian revisi, dan audit.
// Tidak menyentuh database aplikasi dan tidak membuat akun atau permohonan.
require_once __DIR__.'/../app/config/env_loader.php';
loadEnv(__DIR__.'/../app/config/.env');
if (!in_array((string)env('DB_HOST','localhost'),['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Integration requires local database.');
$db=document_features_db();
$original=(string)env('DB_NAME');
$testDb='siprima_docfeat_test_'.bin2hex(random_bytes(4));
function dfRevision(): int { global $db; return (int)$db->query('SELECT revision FROM tb_document_features WHERE id=1')->fetch_assoc()['revision']; }
function dfPayload(): array { global $db; return json_decode($db->query('SELECT payload FROM tb_document_features WHERE id=1')->fetch_assoc()['payload'],true,512,JSON_THROW_ON_ERROR); }
// Menyalin cara halaman admin menyimpan: kunci baris, cocokkan revisi, simpan, catat audit, commit.
function dfSave(array $next, int $expectedRevision): bool {
    global $db;
    if (!$db->begin_transaction()) throw new RuntimeException('Cannot start transaction');
    try {
        $locked=$db->query('SELECT payload, revision FROM tb_document_features WHERE id=1 FOR UPDATE')->fetch_assoc();
        if ((int)$locked['revision']!==$expectedRevision) throw new RuntimeException('stale');
        document_features_validate($next);
        $payload=json_encode($next,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $stmt=$db->prepare('UPDATE tb_document_features SET payload=?, revision=revision+1 WHERE id=1');
        $stmt->bind_param('s',$payload); $stmt->execute(); $stmt->close();
        $adminId=123; $at=(new DateTimeImmutable('now',new DateTimeZone('Asia/Makassar')))->format('Y-m-d H:i:s'); $action='features';
        $stmt=$db->prepare('INSERT INTO tb_document_features_audit (admin_id,changed_at,action,before_payload,after_payload) VALUES (?,?,?,?,?)');
        $stmt->bind_param('issss',$adminId,$at,$action,$locked['payload'],$payload); $stmt->execute(); $stmt->close();
        if (!$db->commit()) throw new RuntimeException('commit failed');
        return true;
    } catch (Throwable $e) { $db->rollback(); return false; }
}
try {
    if (!$db->query("CREATE DATABASE $testDb CHARACTER SET utf8mb4")) throw new RuntimeException('Cannot create isolated test database');
    $db->select_db($testDb);
    $sql=file_get_contents(__DIR__.'/../sql/20260923_document_features.sql');
    if (!$db->multi_query($sql)) throw new RuntimeException('Test migration failed');
    do { if ($r=$db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException('Test migration failed');
    check(dfRevision()===1,'Migration seeds revision 1');
    check(dfPayload()===$defaults,'Stored settings match the PHP fallback');

    // Migrasi ulang tidak boleh menimpa pengaturan yang sudah diubah admin.
    $toggled=array_merge($defaults,['dokumen_hasil'=>true]);
    check(dfSave($toggled,1),'First save succeeds');
    check(dfRevision()===2 && dfPayload()===$toggled,'Save bumps revision and stores new values');
    if (!$db->multi_query($sql)) throw new RuntimeException('Re-migration failed');
    do { if ($r=$db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    check(dfPayload()===$toggled,'Re-running migration keeps admin values');

    check(!dfSave($defaults,1),'Stale revision rejected');
    check(dfRevision()===2 && dfPayload()===$toggled,'Rejected save changes nothing');
    check(!dfSave(array_merge($defaults,['fitur_lain'=>true]),2),'Invalid payload rejected');
    check(dfRevision()===2,'Invalid payload leaves revision untouched');
    check((int)$db->query('SELECT COUNT(*) AS n FROM tb_document_features_audit')->fetch_assoc()['n']===1,'Audit records only successful writes');
    $last=$db->query('SELECT action,before_payload,after_payload FROM tb_document_features_audit ORDER BY id DESC LIMIT 1')->fetch_assoc();
    check($last['action']==='features' && json_decode($last['before_payload'],true)===$defaults && json_decode($last['after_payload'],true)===$toggled,'Audit stores before and after');

    // Kegagalan pencatatan audit harus membatalkan penyimpanan pengaturan.
    $db->query('RENAME TABLE tb_document_features_audit TO tb_document_features_audit_hold');
    check(!dfSave($defaults,2),'Audit failure reported as failure');
    check(dfRevision()===2 && dfPayload()===$toggled,'Audit failure rolls back settings atomically');
    $db->query('RENAME TABLE tb_document_features_audit_hold TO tb_document_features_audit');

    echo "PASS $count document feature checks (including storage integration)\n";
} finally {
    // Hanya database acak milik tes ini yang boleh dihapus.
    if (preg_match('/^siprima_docfeat_test_[a-f0-9]{8}$/D',$testDb) && $testDb !== $original) {
        $db->select_db($original);
        $db->query("DROP DATABASE IF EXISTS $testDb");
    }
}
