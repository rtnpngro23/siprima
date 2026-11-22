<?php
require_once '../error_handler.php';
// get_user.php

// Mulai session HANYA jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Debug session
error_log('=== GET_USER.PHP STARTED ===');
error_log('Session status: ' . session_status());
error_log('User lookup request: ' . siprima_request_id());

// Cek apakah user adalah admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    error_log('ACCESS DENIED - User role: ' . ($_SESSION['role'] ?? 'NOT SET'));
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}

require_once __DIR__ . '/../../app/config/koneksi.php';

header('Content-Type: application/json');

// Cek apakah parameter ID ada
if (!isset($_GET['id']) || empty($_GET['id'])) {
    error_log('No ID parameter provided');
    echo json_encode(['success' => false, 'message' => 'ID pengguna tidak valid']);
    exit;
}

$user_id = intval($_GET['id']);
error_log('Processing user ID: ' . $user_id);

try {
    // Cek koneksi database
    if (!$koneksi) {
        throw new Exception("Koneksi database gagal");
    }
    
    // Ambil data user berdasarkan ID
    $query = "
        SELECT 
            u.id,
            u.nama,
            u.nik,
            u.no_hp,
            u.email,
            u.role,
            u.is_active,
            u.alamat,
            u.created_at,
            u.id_kua,
            u.id_puskes,
            u.id_rs,
            u.id_kecamatan,
            u.id_desa,
            u.id_petugas,
            ku.nama_kua
        FROM tb_users u 
        LEFT JOIN tb_kua ku ON u.id_kua = ku.id_kua
        WHERE u.id = ?
    ";
    
    $stmt = $koneksi->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Prepare statement gagal: " . $koneksi->error);
    }
    
    $stmt->bind_param('i', $user_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Execute gagal: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Pengguna tidak ditemukan']);
        exit;
    }
    
    $user = $result->fetch_assoc();
    
    // Jika user adalah operator, ambil data kecamatan dan KUA tugas
    $kecamatan_tugas = [];
    $kua_tugas = [];
    $puskes_tugas = [];
    $rs_tugas = [];
    
    if ($user['role'] === 'operator') {
        // Ambil data kecamatan yang ditugaskan
        $stmt_kec = $koneksi->prepare("
            SELECT kecamatan_id 
            FROM tb_operator_kecamatan 
            WHERE operator_id = ?
        ");
        
        if ($stmt_kec) {
            $stmt_kec->bind_param('i', $user_id);
            if ($stmt_kec->execute()) {
                $result_kec = $stmt_kec->get_result();
                while ($row = $result_kec->fetch_assoc()) {
                    $kecamatan_tugas[] = $row['kecamatan_id'];
                }
            }
            $stmt_kec->close();
        }
        
        // Ambil data KUA yang ditugaskan
        $stmt_kua = $koneksi->prepare("
            SELECT DISTINCT kua_id 
            FROM tb_operator_kecamatan 
            WHERE operator_id = ?
        ");
        
        if ($stmt_kua) {
            $stmt_kua->bind_param('i', $user_id);
            if ($stmt_kua->execute()) {
                $result_kua = $stmt_kua->get_result();
                while ($row = $result_kua->fetch_assoc()) {
                    if (!empty($row['kua_id'])) {
                        $kua_tugas[] = $row['kua_id'];
                    }
                }
            }
            $stmt_kua->close();
        }

        $stmt_puskes = $koneksi->prepare("
            SELECT DISTINCT puskes_id 
            FROM tb_operator_kecamatan 
            WHERE operator_id = ?
        ");

        if ($stmt_puskes) {
            $stmt_puskes->bind_param('i', $user_id);
            if ($stmt_puskes->execute()) {
                $result_puskes = $stmt_puskes->get_result();
                while ($row = $result_puskes->fetch_assoc()) {
                    if (!empty($row['puskes_id'])) {
                        $puskes_tugas[] = $row['puskes_id'];
                    }
                }
            }
            $stmt_puskes->close();
        }

        $stmt_rs = $koneksi->prepare("
            SELECT DISTINCT rs_id 
            FROM tb_operator_kecamatan 
            WHERE operator_id = ?
        ");

        if ($stmt_rs) {
            $stmt_rs->bind_param('i', $user_id);
            if ($stmt_rs->execute()) {
                $result_rs = $stmt_rs->get_result();
                while ($row = $result_rs->fetch_assoc()) {
                    if (!empty($row['rs_id'])) {
                        $rs_tugas[] = $row['rs_id'];
                    }
                }
            }
            $stmt_rs->close();
        }
    }
    
    echo json_encode([
        'success' => true,
        'user' => $user,
        'kecamatan_tugas' => $kecamatan_tugas,
        'kua_tugas' => $kua_tugas,
        'puskes_tugas' => $puskes_tugas,
        'rs_tugas' => $rs_tugas
    ]);
    
} catch (Exception $e) {
    error_log('Error in get_user.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server']);
}
?>
