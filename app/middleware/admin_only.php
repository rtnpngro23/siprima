<?php
require_once __DIR__ . '/../../public/error_handler.php';
require_once __DIR__ . '/../config/koneksi.php';
siprima_auth_guard($koneksi);
// admin_only.php - HANYA validasi, TIDAK mulai session

error_log('=== ADMIN_ONLY.PHP CHECKING ===');

// Cek session sudah dimulai atau belum
if (session_status() !== PHP_SESSION_ACTIVE) {
    error_log('ERROR: Session not active in admin_only.php');
    echo "<script>alert('Session tidak aktif!'); window.location='../login.php';</script>";
    exit;
}

// Normalisasi key user id agar konsisten antar modul lama/baru
$currentUserId = $_SESSION['id_user'] ?? ($_SESSION['user_id'] ?? null);
if ($currentUserId !== null && $currentUserId !== '') {
    $_SESSION['id_user'] = (int) $currentUserId;
    $_SESSION['user_id'] = (int) $currentUserId;
}

// Debug session data
error_log('Session id_user: ' . ($_SESSION['id_user'] ?? 'NOT SET'));
error_log('Session role: ' . ($_SESSION['role'] ?? 'NOT SET'));

// Wajib ada user id yang valid
if (!isset($_SESSION['id_user']) || (int)$_SESSION['id_user'] <= 0) {
    error_log('ACCESS DENIED - Session id_user invalid');
    echo "<script>alert('Sesi login tidak valid, silakan login ulang'); window.location='../login.php';</script>";
    exit;
}

// Cek apakah user adalah admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    error_log('ACCESS DENIED - Not admin');
    echo "<script>alert('Akses ditolak! Halaman khusus admin'); window.location='index.php';</script>";
    exit;
}

error_log('Admin access GRANTED');
?>
