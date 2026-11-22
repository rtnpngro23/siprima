<?php
require_once '../error_handler.php';
require_once __DIR__ . '/../../app/middleware/user_or_petugas_desa.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(service_hours_session_restricted($_SESSION) ? service_hours_client_state() : ['is_open'=>true, 'message'=>'']);