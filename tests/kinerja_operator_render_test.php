<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../app/Laporan/KinerjaOperator.php';
$stats = kinerjaEmpty();
$stamp = (new DateTimeImmutable('today',new DateTimeZone('Asia/Makassar')))->getTimestamp();
$row = ['status'=>'selesai','duration'=>7200,'start'=>$stamp,'end'=>$stamp+7200,'id'=>1,'cycle'=>1,'service'=>'Kartu Keluarga','unit'=>'Dukcapil','reason'=>'','table'=>'tb_kartukeluarga'];
$row += ['applicant_name'=>'Pemohon <img src=x onerror=alert(1)>','subject_name'=>'Subjek & Uji'];
kinerjaAdd($stats,$row);
$row['status']='ditolak'; $row['duration']=null; $row['reason']='<script>alert(1)</script>';
$row += ['applicant_name'=>'Pemohon <img src=x onerror=alert(1)>','subject_name'=>'Subjek & Uji'];
kinerjaAdd($stats,$row);
$fixtureData = ['operators'=>[7=>['name'=>'Operator <img src=x onerror=alert(1)>']+$stats],
    'total'=>$stats,'excluded'=>[],'untracked'=>3,'missing'=>[],'history'=>true,'trend'=>[]];
if (in_array('--empty',$argv,true)) $fixtureData = ['operators'=>[],'total'=>kinerjaEmpty(),'excluded'=>[],'untracked'=>3,'missing'=>[],'history'=>false,'trend'=>[]];
$_GET = in_array('--detail',$argv,true) ? ['operator'=>'7'] : [];
if (in_array('--invalid',$argv,true)) $_GET=['from'=>'2026-02-30'];
$file = __DIR__.'/../public/admin/kinerja_operator.php';
$source = file_get_contents($file);
$source = str_replace("__DIR__",var_export(dirname(realpath($file)),true),$source);
// Render the real template with synthetic data, without authentication or database side effects.
$source = preg_replace('/^require_once .*?(error_handler|admin_only|koneksi)\.php.*;$/m','',$source);
$source = str_replace('kinerjaLoad($koneksi,$start->getTimestamp(),$until,$service,$unit)','$fixtureData',$source);
ob_start();
eval('?>'.$source);
$html = ob_get_clean();
if (!str_contains($html,'Kinerja Operator')) throw new RuntimeException('Page missing');
if (str_contains($html,'<img src=x') || str_contains($html,'<script>alert(1)')) throw new RuntimeException('Unsafe output');
if (in_array('--invalid',$argv,true) && !str_contains($html,'Pilih tanggal yang valid')) throw new RuntimeException('Invalid date not rejected');
if (in_array('--detail',$argv,true) && !str_contains($html,'50,0%')) throw new RuntimeException('Ratio missing');
if (in_array('--empty',$argv,true) && !str_contains($html,'Riwayat pelayanan belum tersedia')) throw new RuntimeException('Missing history state');
if (in_array('--detail',$argv,true) && (!str_contains($html,'Pemohon &lt;img') || !str_contains($html,'Subjek &amp; Uji'))) throw new RuntimeException('Applicant or subject missing or unescaped');
echo "Template render passed: ".implode(' ',array_slice($argv,1))."\n";