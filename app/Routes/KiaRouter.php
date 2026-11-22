<?php
declare(strict_types=1);

namespace Siprima\Routes;

use Siprima\Controllers\KiaController;
use Siprima\Models\KiaModel;
use mysqli;

final class KiaRouter
{
    public const ROUTES = [
        'create'                 => ['POST'],
        'detail'                 => ['GET'],
        'list'                   => ['GET'],
        'resubmit'               => ['POST'],
        'update_status'          => ['POST'],
        'upload'                 => ['POST'],
        'upload_dokumen'         => ['POST'],
        'koreksi_anak'           => ['POST'],
        'koreksi_mapping'        => ['POST'],
        'lapor_kesalahan'        => ['POST'],
        'list_laporan_kesalahan' => ['GET'],
        'selesaikan_laporan'     => ['POST'],
        'balas_laporan_kesalahan' => ['POST'],
        'submit_survei'          => ['POST'],
        'survei_progress'        => ['GET'],
        'survei_admin_summary'   => ['GET'],
        'export_survei_excel'    => ['GET'],
        'upload_pdf'             => ['POST'],
        'download_pdf'           => ['GET'],
        'view_dokumen'           => ['GET'],
        'ajukan_cetak_kia'       => ['POST'],
        'update_status_cetak'    => ['POST'],
        'list_cetak_kia'         => ['GET'],
        'pengaturan_cetak_kia'   => ['GET', 'POST'],
    ];

    public function __construct(private KiaController $controller) {}

    public static function createDefault(mysqli $db): self
    {
        $model = new KiaModel($db);
        $controller = new KiaController($model, $db);
        return new self($controller);
    }

    public function dispatch(string $method, string $action, array $input, array $files, array $actor): array
    {
        $action = trim($action);
        if ($action === '') {
            $action = ($method === 'POST') ? 'create' : 'list';
        }

        if (!isset(self::ROUTES[$action])) {
            return [
                'status' => 404,
                'data' => [
                    'success' => false,
                    'error' => "Aksi tidak ditemukan."
                ]
            ];
        }

        $allowedMethods = self::ROUTES[$action];
        if (!in_array($method, $allowedMethods, true)) {
            if (!headers_sent()) {
                header('Allow: ' . implode(', ', $allowedMethods));
            }
            return [
                'status' => 405,
                'data' => [
                    'success' => false,
                    'error' => "Metode request tidak diizinkan."
                ]
            ];
        }

        return $this->controller->handleRequest($method, $action, $input, $files, $actor);
    }
}
