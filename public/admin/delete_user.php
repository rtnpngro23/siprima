<?php
require_once '../error_handler.php';
// delete_user.php

// Mulai session HANYA jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Debug session
error_log('=== DELETE_USER.PHP STARTED ===');
error_log('Session role: ' . ($_SESSION['role'] ?? 'NOT SET'));

// Cek apakah user adalah admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    error_log('ACCESS DENIED - Not admin');
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Akses ditolak']);
    exit;
}

require_once __DIR__ . '/../../app/config/koneksi.php';

header('Content-Type: application/json');


// Debug logging
error_log('User deletion request: ' . siprima_request_id());

// Cek apakah parameter ID ada
if (!isset($_POST['id']) || empty($_POST['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID pengguna tidak valid']);
    exit;
}

$user_id = intval($_POST['id']);

// Log attempt
error_log('Attempting to delete user ID: ' . $user_id . ' by admin: ' . $_SESSION['user_id']);

// Cek apakah user mencoba menghapus dirinya sendiri
if ($user_id == $_SESSION['user_id']) {
    echo json_encode(['success' => false, 'message' => 'Tidak dapat menghapus akun sendiri']);
    exit;
}
try {
    // Cek apakah user ada
    $check_user = $koneksi->prepare("SELECT id, nama, role FROM tb_users WHERE id = ?");
    $check_user->bind_param('i', $user_id);
    $check_user->execute();
    $check_user->store_result();
    
    if ($check_user->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'User tidak ditemukan']);
        exit;
    }
    
    // Mulai transaksi
    $koneksi->begin_transaction();
    
    // Hapus tugas operator jika ada
    $delete_tugas = $koneksi->prepare("DELETE FROM tb_operator_kecamatan WHERE operator_id = ?");
    $delete_tugas->bind_param('i', $user_id);
    $delete_tugas->execute();
    $delete_tugas->close();
    error_log('Deleted operator tugas for user ID: ' . $user_id);
    
    // Hapus user
    $delete_user = $koneksi->prepare("DELETE FROM tb_users WHERE id = ?");
    $delete_user->bind_param('i', $user_id);
    
    if (!$delete_user->execute()) {
        throw new Exception("Gagal menghapus user: " . $delete_user->error);
    }
    
    if ($delete_user->affected_rows === 0) {
        throw new Exception("User tidak ditemukan");
    }
    
    $koneksi->commit();
    error_log('Successfully deleted user ID: ' . $user_id);
    echo json_encode(['success' => true, 'message' => 'User berhasil dihapus']);
    
} catch (Exception $e) {
    $koneksi->rollback();
    error_log('Error in delete_user.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
}

error_log('=== DELETE_USER.PHP ENDED ===');
