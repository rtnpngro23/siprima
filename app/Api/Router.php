<?php
declare(strict_types=1);
namespace Siprima\Api;

require_once __DIR__.'/Core.php';
require_once __DIR__.'/Auth.php';
require_once __DIR__.'/Catalog.php';
require_once __DIR__.'/Uploads.php';
require_once __DIR__.'/Accounts.php';
require_once __DIR__.'/Applications.php';
require_once __DIR__.'/Interactions.php';

final class Router
{
    public const ROUTES = [
        '/auth/login'=>['POST'], '/auth/refresh'=>['POST'], '/auth/logout'=>['POST'],
        '/auth/register'=>['POST'], '/auth/register-village-officer'=>['POST'],
        '/auth/password/forgot'=>['POST'], '/auth/password/reset'=>['POST'], '/auth/password/change'=>['POST'],
        '/me'=>['GET','PATCH'], '/services'=>['GET'], '/services/{service_code}'=>['GET'],
        '/service-availability'=>['GET'], '/references/{type}'=>['GET'],
        '/applications'=>['GET','POST'], '/applications/{application_ref}'=>['GET','PATCH'],
        '/applications/{application_ref}/resubmit'=>['POST'], '/applications/{application_ref}/status'=>['PATCH'],
        '/uploads'=>['POST'], '/applications/{application_ref}/history'=>['GET'],
        '/applications/{application_ref}/comments'=>['GET','POST'], '/applications/{application_ref}/documents'=>['GET'],
        '/documents/{document_ref}/download'=>['GET'], '/applications/{application_ref}/receipt'=>['POST'],
        '/applications/{application_ref}/feedback'=>['POST'], '/notifications'=>['GET'], '/notifications/{notification_ref}'=>['PATCH'],
    ];

    public function __construct(private \mysqli $db) {}

    public function dispatch(string $method,string $path,array $input,array $query,array $files,array $headers): array
    {
        $route=null;$vars=[];
        foreach(self::ROUTES as $pattern=>$methods) {
            $regex=preg_replace('/\{[a-z_]+\}/','([^/]+)',$pattern);
            if (preg_match('#^'.$regex.'$#D',$path,$matches)) {
                $route=$pattern; $vars=array_slice($matches,1);
                if (!in_array($method,$methods,true)) { header('Allow: '.implode(', ',$methods)); fail(405,'METHOD_NOT_ALLOWED','Metode HTTP tidak didukung.'); }
                break;
            }
        }
        if (!$route) fail(404,'NOT_FOUND','Endpoint tidak ditemukan.');
        $auth=new Auth($this->db);$apps=new Applications($this->db);$interactions=new Interactions($this->db);$accounts=new Accounts($this->db);
        $public=in_array($route,['/auth/login','/auth/refresh','/auth/register','/auth/register-village-officer','/auth/password/forgot','/auth/password/reset','/service-availability','/references/{type}'],true);
        if (!$public) {
            $actor=$auth->authenticate($headers['authorization']??'',in_array($route,['/me','/auth/password/change','/auth/logout'],true));$u=$actor['user'];
            if (!empty($u['must_change_password']) && $route==='/me' && $method!=='GET') fail(403,'PASSWORD_CHANGE_REQUIRED','Ganti password sementara terlebih dahulu.');
            rateLimit($this->db,'account',(string)$u['id'],180,60);
        } else {
            rateLimit($this->db,'public',\login_client_ip(),120,60);
            // Mobile users share public IPs through carrier NAT, and a village office shares one
            // line, so a tight per-IP ceiling on login would lock out unrelated citizens.
            // Credential guessing is already bounded per account by login_throttle_begin_attempt(),
            // so login only needs a bulk-abuse ceiling. Registration and reset keep the tight
            // bucket: those have no per-account throttle of their own.
            if ($method==='POST' && $route!=='/auth/refresh') {
                if ($route==='/auth/login') rateLimit($this->db,'public-login',\login_client_ip(),120,900);
                else rateLimit($this->db,'public-write',\login_client_ip(),20,900);
            }
        }
        $key=$headers['idempotency-key']??'';$rev=$headers['if-match']??'';$status=200;
        switch($route) {
            case '/auth/login': $data=$auth->login($input);break;
            case '/auth/refresh': $data=$auth->refresh($input);break;
            case '/auth/logout': only($input,[]);$data=$auth->logout($actor);break;
            case '/auth/register': case '/auth/register-village-officer':
                $data=$accounts->register($input,$files,$route==='/auth/register-village-officer');$status=201;break;
            case '/auth/password/forgot': $data=$auth->forgot($input);$status=202;break;
            case '/auth/password/reset': $data=$auth->reset($input);break;
            case '/auth/password/change': $data=$auth->change($actor,$input);break;
            case '/me':
                $data=$method==='PATCH'?$accounts->update($u,$input):Auth::profile($u);
                $open=empty($u['must_change_password']) && Catalog::availability($this->db)['is_open'];
                $data['capabilities']=['can_create'=>$open,'can_edit_profile'=>empty($u['must_change_password']),'can_process_events'=>$u['role']==='petugas_desa' && empty($u['must_change_password'])];break;
            case '/services':
                $data=['items'=>array_values(array_map([Catalog::class,'metadata'],Catalog::all($u['role'])))];break;
            case '/services/{service_code}': $data=Catalog::metadata(Catalog::get($vars[0],$u['role']));break;
            case '/service-availability': $data=Catalog::availability($this->db);break;
            case '/references/{type}': $data=$this->references($vars[0],$query);break;
            case '/applications':
                $data=$method==='POST'?$apps->create($actor,$input,$key):$apps->list($u,$query);$status=$method==='POST'?201:200;break;
            case '/applications/{application_ref}':
                $data=$method==='PATCH'?$apps->edit($actor,$vars[0],$input,$rev):$apps->detail($u,$vars[0]);header('ETag: "'.$data['revision'].'"');break;
            case '/applications/{application_ref}/resubmit': $data=$apps->resubmit($actor,$vars[0],$input,$key,$rev);break;
            case '/applications/{application_ref}/status': $data=$apps->processEvent($actor,$vars[0],$input,$rev);break;
            case '/uploads': $data=(new Uploads($this->db))->create($u,$input,$files);$status=201;break;
            case '/applications/{application_ref}/history': $data=$apps->historyList($u,$vars[0],$query);break;
            case '/applications/{application_ref}/comments':
                $data=$method==='POST'?$interactions->comment($u,$vars[0],$input):$interactions->comments($u,$vars[0],$query);$status=$method==='POST'?201:200;break;
            case '/applications/{application_ref}/documents': $data=$interactions->documents($u,$vars[0]);break;
            case '/documents/{document_ref}/download': return ['status'=>200,'file'=>$interactions->download($u,$vars[0])];
            case '/applications/{application_ref}/receipt': $data=$interactions->receipt($u,$vars[0],$input);break;
            case '/applications/{application_ref}/feedback': $data=$interactions->feedback($u,$vars[0],$input);break;
            case '/notifications': $data=$interactions->notifications($u,$query);break;
            case '/notifications/{notification_ref}': $data=$interactions->readNotification($u,$vars[0],$input);break;
        }
        return ['status'=>$status,'data'=>$data];
    }

    private function references(string $type,array $q): array
    {
        only($q,['page','per_page','kecamatan_id']);[$p,$size,$offset]=page($q);
        if ($type==='kecamatan') { $table='tb_kecamatan';$cols='id,nama_kecamatan AS name';$where='1=1';$params=[]; }
        elseif ($type==='desa') { $table='tb_desa';$cols='id,nama_desa AS name,kecamatan_id';$where='kecamatan_id=?';$params=[integer($q['kecamatan_id']??null,'kecamatan_id')]; }
        else fail(404,'NOT_FOUND','Jenis referensi tidak ditemukan.');
        return ['items'=>rows($this->db,"SELECT $cols FROM $table WHERE $where ORDER BY id LIMIT $size OFFSET $offset",$params),
            'pagination'=>['page'=>$p,'per_page'=>$size,'total'=>(int)one($this->db,"SELECT COUNT(*) AS n FROM $table WHERE $where",$params)['n']]];
    }
}
