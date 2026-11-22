<?php
require_once '../error_handler.php';
// save_user.php

// Mulai session HANYA jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Debug session
error_log('=== SAVE_USER.PHP STARTED ===');
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
error_log('User management request: ' . siprima_request_id());

// Cek aksi
$action = $_POST['action'] ?? '';
$user_id = isset($_POST['id']) ? intval($_POST['id']) : 0;

error_log('Action: ' . $action . ', User ID: ' . $user_id);

if ($action === 'add_user') {
    addUser();
} elseif ($action === 'update_user') {
    updateUser($user_id);
} else {
    echo json_encode(['success' => false, 'message' => 'Aksi tidak valid']);
    exit;
}

function isDesaInKecamatan($kecamatanId, $desaId) {
    global $koneksi;

    if ($kecamatanId <= 0 || $desaId <= 0) {
        return false;
    }

    $stmt = $koneksi->prepare("SELECT id FROM tb_desa WHERE id = ? AND kecamatan_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $desaId, $kecamatanId);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function addUser() {
    global $koneksi;
    
    // Validasi input
    $nama = trim($_POST['nama'] ?? '');
    $nik = trim($_POST['nik'] ?? '');
    $no_hp = trim($_POST['no_hp'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? 'user');
    $is_active = intval($_POST['is_active'] ?? 1);
    $alamat = trim($_POST['alamat'] ?? '');
    $id_kua = !empty($_POST['id_kua']) ? intval($_POST['id_kua']) : null;
    $id_puskes = !empty($_POST['id_puskes']) ? intval($_POST['id_puskes']) : null;
    $id_rs = !empty($_POST['id_rs']) ? intval($_POST['id_rs']) : null; // TAMBAH RUMAH SAKIT
    $id_kecamatan = !empty($_POST['id_kecamatan']) ? intval($_POST['id_kecamatan']) : null;
    $id_desa = !empty($_POST['id_desa']) ? intval($_POST['id_desa']) : null; // TAMBAH DESA
    $id_petugas = !empty($_POST['id_petugas']) ? intval($_POST['id_petugas']) : null; // TAMBAH PETUGAS
    $password = $_POST['password'] ?? '';
    
    // Log data
    error_log('User creation request: ' . siprima_request_id());
    
    // Validasi required fields
    if (empty($nama) || empty($nik) || empty($no_hp)) {
        echo json_encode(['success' => false, 'message' => 'Nama, NIK, dan No HP wajib diisi']);
        return;
    }
    
    // Validasi NIK (16 digit)
    if (!preg_match('/^\d{16}$/', $nik)) {
        echo json_encode(['success' => false, 'message' => 'NIK harus 16 digit angka']);
        return;
    }
    
    // Validasi No HP (10-13 digit)
    if (!preg_match('/^\d{10,13}$/', $no_hp)) {
        echo json_encode(['success' => false, 'message' => 'No HP harus 10-13 digit angka']);
        return;
    }
    
    // Validasi password untuk user baru
    if (($passwordError = siprima_password_error($password)) !== null) {
        echo json_encode(['success' => false, 'message' => $passwordError]);
        return;
    }
    
    if ($role === 'petugas_desa') {
        if (!$id_kecamatan) {
            echo json_encode(['success' => false, 'message' => 'Petugas desa harus memiliki kecamatan']);
            return;
        }
        if (!$id_desa) {
            echo json_encode(['success' => false, 'message' => 'Petugas desa harus memiliki desa']);
            return;
        }
        if (!isDesaInKecamatan($id_kecamatan, $id_desa)) {
            echo json_encode(['success' => false, 'message' => 'Desa tidak sesuai dengan kecamatan yang dipilih']);
            return;
        }
    } else {
        $id_kecamatan = null;
        $id_desa = null;
    }
    
    // Validasi untuk user role: hanya boleh memiliki satu jenis (KUA, Puskesmas, atau Rumah Sakit)
    if ($role === 'user') {
        $assigned_count = 0;
        if ($id_kua) $assigned_count++;
        if ($id_puskes) $assigned_count++;
        if ($id_rs) $assigned_count++;
        
        if ($assigned_count > 1) {
            echo json_encode(['success' => false, 'message' => 'User hanya boleh memiliki satu jenis instansi (KUA, Puskesmas, atau Rumah Sakit)']);
            return;
        }
    }
    
    // Validasi untuk operator: id_kua, id_puskes, id_rs, id_kecamatan, id_desa harus null
    if ($role === 'operator' && ($id_kua || $id_puskes || $id_rs || $id_kecamatan || $id_desa)) {
        echo json_encode(['success' => false, 'message' => 'Operator tidak boleh memiliki KUA, Puskesmas, Rumah Sakit, Kecamatan, atau Desa langsung']);
        return;
    }
    
    // Cek apakah NIK sudah terdaftar
    $check_nik = $koneksi->prepare("SELECT id FROM tb_users WHERE nik = ?");
    if (!$check_nik) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $koneksi->error]);
        return;
    }
    
    $check_nik->bind_param('s', $nik);
    $check_nik->execute();
    $check_nik->store_result();
    
    if ($check_nik->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'NIK sudah terdaftar']);
        return;
    }
    
    // Cek apakah email sudah terdaftar (jika ada email)
    if (!empty($email)) {
        $check_email = $koneksi->prepare("SELECT id FROM tb_users WHERE email = ?");
        if ($check_email) {
            $check_email->bind_param('s', $email);
            $check_email->execute();
            $check_email->store_result();
            
            if ($check_email->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Email sudah terdaftar']);
                return;
            }
        }
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Mulai transaksi
    $koneksi->begin_transaction();
    
    try {
        // Insert user ke tabel tb_users
        $stmt = $koneksi->prepare("
            INSERT INTO tb_users (nama, nik, no_hp, email, role, is_active, alamat, 
                                  id_kua, id_puskes, id_rs, id_kecamatan, id_desa, id_petugas, password, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare statement failed: " . $koneksi->error);
        }
        
        $stmt->bind_param('sssssisssiiiiss', $nama, $nik, $no_hp, $email, $role, $is_active, $alamat, 
                         $id_kua, $id_puskes, $id_rs, $id_kecamatan, $id_desa, $id_petugas, $hashed_password);
        
        if (!$stmt->execute()) {
            throw new Exception("Gagal menyimpan user: " . $stmt->error);
        }
        
        $new_user_id = $stmt->insert_id;
        error_log('New user created with ID: ' . $new_user_id);
        
        // Jika role adalah operator, simpan tugas kecamatan, KUA, puskesmas, dan rumah sakit
        if ($role === 'operator') {
            // Simpan kecamatan tugas (jika ada yang dipilih)
            if (isset($_POST['kecamatan_tugas']) && is_array($_POST['kecamatan_tugas']) && !empty($_POST['kecamatan_tugas'])) {
                $stmt_kec = $koneksi->prepare("
                    INSERT INTO tb_operator_kecamatan (operator_id, kecamatan_id, kua_id, puskes_id, rs_id)
                    VALUES (?, ?, NULL, NULL, NULL)
                ");
                
                if (!$stmt_kec) {
                    throw new Exception("Prepare kecamatan failed: " . $koneksi->error);
                }
                
                foreach ($_POST['kecamatan_tugas'] as $kec_id) {
                    $kec_id_int = intval($kec_id);
                    if ($kec_id_int > 0) {
                        $stmt_kec->bind_param('ii', $new_user_id, $kec_id_int);
                        if (!$stmt_kec->execute()) {
                            throw new Exception("Gagal menyimpan kecamatan tugas: " . $stmt_kec->error);
                        }
                    }
                }
                $stmt_kec->close();
                error_log('Saved ' . count($_POST['kecamatan_tugas']) . ' kecamatan tugas');
            }
            
            // Simpan KUA tugas (jika ada yang dipilih)
            if (isset($_POST['kua_tugas']) && is_array($_POST['kua_tugas']) && !empty($_POST['kua_tugas'])) {
                $stmt_kua = $koneksi->prepare("
                    INSERT INTO tb_operator_kecamatan (operator_id, kecamatan_id, kua_id, puskes_id, rs_id)
                    VALUES (?, NULL, ?, NULL, NULL)
                ");
                
                if (!$stmt_kua) {
                    throw new Exception("Prepare KUA failed: " . $koneksi->error);
                }
                
                foreach ($_POST['kua_tugas'] as $kua_id) {
                    $kua_id_int = intval($kua_id);
                    if ($kua_id_int > 0) {
                        $stmt_kua->bind_param('ii', $new_user_id, $kua_id_int);
                        if (!$stmt_kua->execute()) {
                            throw new Exception("Gagal menyimpan KUA tugas: " . $stmt_kua->error);
                        }
                    }
                }
                $stmt_kua->close();
                error_log('Saved ' . count($_POST['kua_tugas']) . ' KUA tugas');
            }
            
            // Simpan puskesmas tugas (jika ada yang dipilih)
            if (isset($_POST['puskes_tugas']) && is_array($_POST['puskes_tugas']) && !empty($_POST['puskes_tugas'])) {
                $stmt_puskes = $koneksi->prepare("
                    INSERT INTO tb_operator_kecamatan (operator_id, kecamatan_id, kua_id, puskes_id, rs_id)
                    VALUES (?, NULL, NULL, ?, NULL)
                ");
                
                if (!$stmt_puskes) {
                    throw new Exception("Prepare puskesmas failed: " . $koneksi->error);
                }
                
                foreach ($_POST['puskes_tugas'] as $puskes_id) {
                    $puskes_id_int = intval($puskes_id);
                    if ($puskes_id_int > 0) {
                        $stmt_puskes->bind_param('ii', $new_user_id, $puskes_id_int);
                        if (!$stmt_puskes->execute()) {
                            throw new Exception("Gagal menyimpan puskesmas tugas: " . $stmt_puskes->error);
                        }
                    }
                }
                $stmt_puskes->close();
                error_log('Saved ' . count($_POST['puskes_tugas']) . ' puskesmas tugas');
            }
            
            // Simpan rumah sakit tugas (jika ada yang dipilih)
            if (isset($_POST['rs_tugas']) && is_array($_POST['rs_tugas']) && !empty($_POST['rs_tugas'])) {
                $stmt_rs = $koneksi->prepare("
                    INSERT INTO tb_operator_kecamatan (operator_id, kecamatan_id, kua_id, puskes_id, rs_id)
                    VALUES (?, NULL, NULL, NULL, ?)
                ");
                
                if (!$stmt_rs) {
                    throw new Exception("Prepare rumah sakit failed: " . $koneksi->error);
                }
                
                foreach ($_POST['rs_tugas'] as $rs_id) {
                    $rs_id_int = intval($rs_id);
                    if ($rs_id_int > 0) {
                        $stmt_rs->bind_param('ii', $new_user_id, $rs_id_int);
                        if (!$stmt_rs->execute()) {
                            throw new Exception("Gagal menyimpan rumah sakit tugas: " . $stmt_rs->error);
                        }
                    }
                }
                $stmt_rs->close();
                error_log('Saved ' . count($_POST['rs_tugas']) . ' rumah sakit tugas');
            }
        }
        
        $koneksi->commit();
        error_log('Transaction committed successfully');
        echo json_encode(['success' => true, 'message' => 'User berhasil ditambahkan']);
        
    } catch (Exception $e) {
        $koneksi->rollback();
        error_log('Error in addUser: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
    }
}

function updateUser($user_id) {
    global $koneksi;
    
    // Validasi input
    $nama = trim($_POST['nama'] ?? '');
    $no_hp = trim($_POST['no_hp'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? 'user');
    $is_active = intval($_POST['is_active'] ?? 1);
    $alamat = trim($_POST['alamat'] ?? '');
    $id_kua = !empty($_POST['id_kua']) ? intval($_POST['id_kua']) : null;
    $id_puskes = !empty($_POST['id_puskes']) ? intval($_POST['id_puskes']) : null;
    $id_rs = !empty($_POST['id_rs']) ? intval($_POST['id_rs']) : null; // TAMBAH RUMAH SAKIT
    $id_kecamatan = !empty($_POST['id_kecamatan']) ? intval($_POST['id_kecamatan']) : null;
    $id_desa = !empty($_POST['id_desa']) ? intval($_POST['id_desa']) : null; // TAMBAH DESA
    $id_petugas = !empty($_POST['id_petugas']) ? intval($_POST['id_petugas']) : null; // TAMBAH PETUGAS
    $password = $_POST['password'] ?? '';
    
    // Log data
    error_log('Update User - ID: ' . $user_id . ', Role: ' . $role . 
              ', id_kua: ' . $id_kua . ', id_puskes: ' . $id_puskes . 
              ', id_rs: ' . $id_rs . ', id_kecamatan: ' . $id_kecamatan . ', id_desa: ' . $id_desa . 
              ', id_petugas: ' . $id_petugas);
    
    // Validasi required fields
    if (empty($nama) || empty($no_hp)) {
        echo json_encode(['success' => false, 'message' => 'Nama dan No HP wajib diisi']);
        return;
    }
    
    // Validasi No HP
    if (!preg_match('/^\d{10,13}$/', $no_hp)) {
        echo json_encode(['success' => false, 'message' => 'No HP harus 10-13 digit angka']);
        return;
    }
    
    // Validasi password (jika diisi)
    if ($password !== '' && ($passwordError = siprima_password_error($password)) !== null) {
        echo json_encode(['success' => false, 'message' => $passwordError]);
        return;
    }
    
    if ($role === 'petugas_desa') {
        if (!$id_kecamatan) {
            echo json_encode(['success' => false, 'message' => 'Petugas desa harus memiliki kecamatan']);
            return;
        }
        if (!$id_desa) {
            echo json_encode(['success' => false, 'message' => 'Petugas desa harus memiliki desa']);
            return;
        }
        if (!isDesaInKecamatan($id_kecamatan, $id_desa)) {
            echo json_encode(['success' => false, 'message' => 'Desa tidak sesuai dengan kecamatan yang dipilih']);
            return;
        }
    } else {
        $id_kecamatan = null;
        $id_desa = null;
    }
    
    // Validasi untuk user role: hanya boleh memiliki satu jenis (KUA, Puskesmas, atau Rumah Sakit)
    if ($role === 'user') {
        $assigned_count = 0;
        if ($id_kua) $assigned_count++;
        if ($id_puskes) $assigned_count++;
        if ($id_rs) $assigned_count++;
        
        if ($assigned_count > 1) {
            echo json_encode(['success' => false, 'message' => 'User hanya boleh memiliki satu jenis instansi (KUA, Puskesmas, atau Rumah Sakit)']);
            return;
        }
    }
    
    // Validasi untuk operator: id_kua, id_puskes, id_rs, id_kecamatan, id_desa harus null
    if ($role === 'operator' && ($id_kua || $id_puskes || $id_rs || $id_kecamatan || $id_desa)) {
        // Untuk update, kita ubah menjadi null jika sebelumnya ada
        error_log('Operator has direct assignments, will set to NULL');
        $id_kua = null;
        $id_puskes = null;
        $id_rs = null;
        $id_kecamatan = null;
        $id_desa = null;
        $id_petugas = null;
    }
    
    // Mulai transaksi
    $koneksi->begin_transaction();
    
    try {
        // Update user data
        if (!empty($password)) {
            // Jika password diubah
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $koneksi->prepare("
                UPDATE tb_users 
                SET nama = ?, no_hp = ?, email = ?, role = ?, is_active = ?, 
                    alamat = ?, id_kua = ?, id_puskes = ?, id_rs = ?, id_kecamatan = ?,
                    id_desa = ?, id_petugas = ?, password = ?, must_change_password = 0, temporary_password_expires_at = NULL, updated_at = NOW()
                WHERE id = ?
            ");
            if (!$stmt) {
                throw new Exception("Prepare update with password failed: " . $koneksi->error);
            }
            $stmt->bind_param('ssssisiiiiiisi', $nama, $no_hp, $email, $role, $is_active, 
                              $alamat, $id_kua, $id_puskes, $id_rs, $id_kecamatan, $id_desa, $id_petugas, 
                              $hashed_password, $user_id);
        } else {
            // Jika password tidak diubah
            $stmt = $koneksi->prepare("
                UPDATE tb_users 
                SET nama = ?, no_hp = ?, email = ?, role = ?, is_active = ?, 
                    alamat = ?, id_kua = ?, id_puskes = ?, id_rs = ?, id_kecamatan = ?,
                    id_desa = ?, id_petugas = ?, updated_at = NOW()
                WHERE id = ?
            ");
            if (!$stmt) {
                throw new Exception("Prepare update failed: " . $koneksi->error);
            }
            $stmt->bind_param('ssssisiiiiiii', $nama, $no_hp, $email, $role, $is_active, 
                              $alamat, $id_kua, $id_puskes, $id_rs, $id_kecamatan, $id_desa, $id_petugas, 
                              $user_id);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Gagal mengupdate user: " . $stmt->error);
        }
        
        error_log('User updated successfully');
        
        // Hapus tugas lama untuk operator
        $delete_tugas = $koneksi->prepare("DELETE FROM tb_operator_kecamatan WHERE operator_id = ?");
        if ($delete_tugas) {
            $delete_tugas->bind_param('i', $user_id);
            $delete_tugas->execute();
            $delete_tugas->close();
            error_log('Deleted old tugas');
        }
        
        // Jika role adalah operator, simpan tugas kecamatan, KUA, puskesmas, dan rumah sakit baru (jika ada)
        if ($role === 'operator') {
            // Simpan kecamatan tugas (jika ada yang dipilih)
            if (isset($_POST['kecamatan_tugas']) && is_array($_POST['kecamatan_tugas']) && !empty($_POST['kecamatan_tugas'])) {
                $stmt_kec = $koneksi->prepare("
                    INSERT INTO tb_operator_kecamatan (operator_id, kecamatan_id, kua_id, puskes_id, rs_id)
                    VALUES (?, ?, NULL, NULL, NULL)
                ");
                
                if (!$stmt_kec) {
                    throw new Exception("Prepare kecamatan failed: " . $koneksi->error);
                }
                
                foreach ($_POST['kecamatan_tugas'] as $kec_id) {
                    $kec_id_int = intval($kec_id);
                    if ($kec_id_int > 0) {
                        $stmt_kec->bind_param('ii', $user_id, $kec_id_int);
                        if (!$stmt_kec->execute()) {
                            throw new Exception("Gagal menyimpan kecamatan tugas: " . $stmt_kec->error);
                        }
                    }
                }
                $stmt_kec->close();
                error_log('Saved ' . count($_POST['kecamatan_tugas']) . ' kecamatan tugas');
            }
            
            // Simpan KUA tugas (jika ada yang dipilih)
            if (isset($_POST['kua_tugas']) && is_array($_POST['kua_tugas']) && !empty($_POST['kua_tugas'])) {
                $stmt_kua = $koneksi->prepare("
                    INSERT INTO tb_operator_kecamatan (operator_id, kecamatan_id, kua_id, puskes_id, rs_id)
                    VALUES (?, NULL, ?, NULL, NULL)
                ");
                
                if (!$stmt_kua) {
                    throw new Exception("Prepare KUA failed: " . $koneksi->error);
                }
                
                foreach ($_POST['kua_tugas'] as $kua_id) {
                    $kua_id_int = intval($kua_id);
                    if ($kua_id_int > 0) {
                        $stmt_kua->bind_param('ii', $user_id, $kua_id_int);
                        if (!$stmt_kua->execute()) {
                            throw new Exception("Gagal menyimpan KUA tugas: " . $stmt_kua->error);
                        }
                    }
                }
                $stmt_kua->close();
                error_log('Saved ' . count($_POST['kua_tugas']) . ' KUA tugas');
            }
            
            // Simpan puskesmas tugas (jika ada yang dipilih)
            if (isset($_POST['puskes_tugas']) && is_array($_POST['puskes_tugas']) && !empty($_POST['puskes_tugas'])) {
                $stmt_puskes = $koneksi->prepare("
                    INSERT INTO tb_operator_kecamatan (operator_id, kecamatan_id, kua_id, puskes_id, rs_id)
                    VALUES (?, NULL, NULL, ?, NULL)
                ");
                
                if (!$stmt_puskes) {
                    throw new Exception("Prepare puskesmas failed: " . $koneksi->error);
                }
                
                foreach ($_POST['puskes_tugas'] as $puskes_id) {
                    $puskes_id_int = intval($puskes_id);
                    if ($puskes_id_int > 0) {
                        $stmt_puskes->bind_param('ii', $user_id, $puskes_id_int);
                        if (!$stmt_puskes->execute()) {
                            throw new Exception("Gagal menyimpan puskesmas tugas: " . $stmt_puskes->error);
                        }
                    }
                }
                $stmt_puskes->close();
                error_log('Saved ' . count($_POST['puskes_tugas']) . ' puskesmas tugas');
            }
            
            // Simpan rumah sakit tugas (jika ada yang dipilih)
            if (isset($_POST['rs_tugas']) && is_array($_POST['rs_tugas']) && !empty($_POST['rs_tugas'])) {
                $stmt_rs = $koneksi->prepare("
                    INSERT INTO tb_operator_kecamatan (operator_id, kecamatan_id, kua_id, puskes_id, rs_id)
                    VALUES (?, NULL, NULL, NULL, ?)
                ");
                
                if (!$stmt_rs) {
                    throw new Exception("Prepare rumah sakit failed: " . $koneksi->error);
                }
                
                foreach ($_POST['rs_tugas'] as $rs_id) {
                    $rs_id_int = intval($rs_id);
                    if ($rs_id_int > 0) {
                        $stmt_rs->bind_param('ii', $user_id, $rs_id_int);
                        if (!$stmt_rs->execute()) {
                            throw new Exception("Gagal menyimpan rumah sakit tugas: " . $stmt_rs->error);
                        }
                    }
                }
                $stmt_rs->close();
                error_log('Saved ' . count($_POST['rs_tugas']) . ' rumah sakit tugas');
            }
        }
        
        $koneksi->commit();
        error_log('Transaction committed successfully');
        echo json_encode(['success' => true, 'message' => 'User berhasil diperbarui']);
        
    } catch (Exception $e) {
        $koneksi->rollback();
        error_log('Error in updateUser: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
    }
}

error_log('=== SAVE_USER.PHP ENDED ===');
