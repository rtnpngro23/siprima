<?php
require_once '../error_handler.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$role = (string)($_SESSION['role'] ?? '');
if (empty($_SESSION['id_user']) || !in_array($role, ['user', 'petugas_desa'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi tidak valid.']);
    exit;
}

echo json_encode(['success' => true, 'csrf_token' => csrf_token()]);

