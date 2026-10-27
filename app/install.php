<?php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('Hanya bisa dibuka dari perangkat ini (localhost).'); }
/* ============================================================
   INSTALLER DATABASE - jalankan SEKALI, lalu hapus file ini.
   Alamat: http://localhost:8080/install.php
   (XAMPP: http://localhost/kasir-buah/install.php)
   ============================================================ */
require_once __DIR__ . '/settings.php';

if (isset($_GET['hapus'])) {
    @unlink(__FILE__);
    exit('install.php sudah dihapus. <a href="login.php">Ke halaman login</a>');
}

function halaman($isi) {
    echo "<!DOCTYPE html><html lang='id'><head><meta charset='utf-8'>"
       . "<meta name='viewport' content='width=device-width,initial-scale=1'><title>Install</title>"
       . "<style>body{font-family:system-ui,sans-serif;background:#f2f6f4;margin:0;padding:18px;color:#18271f}"
       . ".c{max-width:560px;margin:auto;background:#fff;border-radius:18px;padding:24px;box-shadow:0 6px 24px #16794c1f}"
       . "a{color:#16794c}code{background:#eef5f0;padding:2px 7px;border-radius:6px}</style></head><body><div class='c'>$isi</div></body></html>";
    exit;
}

$opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

try {
    try { // XAMPP/KSWEB: database dibuat otomatis. Di hosting: buat dulu lewat cPanel.
        $root = new PDO('mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : '3306') . ';charset=utf8mb4', DB_USER, DB_PASS, $opt);
        $root->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4');
    } catch (PDOException $e) { /* abaikan, coba langsung konek ke database */ }

    $db = new PDO('mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : '3306') . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, $opt);
} catch (PDOException $e) {
    halaman('<h3>❌ Gagal konek database</h3><p>' . htmlspecialchars($e->getMessage())
        . '</p><p>Periksa <code>settings.php</code> dan pastikan <b>MySQL sudah ON</b>.</p>');
}

$db->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(80),
    username VARCHAR(40) UNIQUE,
    password VARCHAR(255),
    role ENUM('admin','karyawan') DEFAULT 'karyawan'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS produk (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode VARCHAR(30) UNIQUE,
    nama VARCHAR(80),
    harga DECIMAL(15,2) NOT NULL DEFAULT 0,
    harga_modal DECIMAL(15,2) NOT NULL DEFAULT 0,
    stok DECIMAL(15,3) NOT NULL DEFAULT 0,
    satuan VARCHAR(10) DEFAULT 'kg'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS transaksi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode VARCHAR(30),
    user_id INT,
    total DECIMAL(15,2),
    bayar DECIMAL(15,2),
    kembali DECIMAL(15,2),
    metode VARCHAR(10) NOT NULL DEFAULT 'tunai',
    shift_id INT NULL,
    subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
    diskon_persen DECIMAL(5,2) NOT NULL DEFAULT 0,
    diskon_nominal DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS detail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaksi_id INT,
    produk_id INT,
    nama VARCHAR(80),
    harga DECIMAL(15,2),
    harga_modal DECIMAL(15,2) NOT NULL DEFAULT 0,
    qty DECIMAL(15,3) NOT NULL DEFAULT 0,
    subtotal DECIMAL(15,2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS shift (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    buka TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    tutup DATETIME NULL,
    status VARCHAR(10) DEFAULT 'buka',
    jml INT DEFAULT 0,
    total_tunai DECIMAL(15,2) DEFAULT 0,
    total_qris DECIMAL(15,2) DEFAULT 0,
    input_pendapatan DECIMAL(15,2) NULL,
    selisih DECIMAL(15,2) NULL,
    modal DECIMAL(15,2) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS stok_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produk_id INT NOT NULL,
    jenis ENUM('masuk','keluar') NOT NULL,
    qty DECIMAL(15,3) NOT NULL DEFAULT 0,
    stok_sebelum DECIMAL(15,3) NOT NULL DEFAULT 0,
    stok_sesudah DECIMAL(15,3) NOT NULL DEFAULT 0,
    keterangan VARCHAR(255) NULL,
    transaksi_id INT NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stok_produk (produk_id),
    INDEX idx_stok_jenis (jenis),
    INDEX idx_stok_transaksi (transaksi_id),
    INDEX idx_stok_user (user_id),
    INDEX idx_stok_tanggal (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$baru = false;
if (!$db->query('SELECT COUNT(*) FROM users')->fetchColumn()) {
    $baru = true;
    $u = $db->prepare('INSERT INTO users(nama,username,password,role) VALUES(?,?,?,?)');
    $u->execute(['Administrator',  'admin',    password_hash('admin123',    PASSWORD_DEFAULT), 'admin']);
    $u->execute(['Karyawan Kasir', 'karyawan', password_hash('karyawan123', PASSWORD_DEFAULT), 'karyawan']);

    if (!$db->query('SELECT COUNT(*) FROM produk')->fetchColumn()) {
        $p = $db->prepare('INSERT INTO produk(kode,nama,harga,harga_modal,stok,satuan) VALUES(?,?,?,?,?,?)');
        foreach ([
            ['1001', 'Apel Fuji',          35000, 28000, 50, 'kg'],
            ['1002', 'Jeruk Mandarin',     28000, 22000, 40, 'kg'],
            ['1003', 'Pisang Cavendish',   18000, 13000, 60, 'sisir'],
            ['1004', 'Anggur Merah',       65000, 52000, 25, 'kg'],
            ['1005', 'Semangka',           12000,  8000, 30, 'kg'],
            ['1006', 'Mangga Harum Manis', 30000, 22000, 35, 'kg'],
            ['1007', 'Nanas',              15000, 10000, 20, 'buah'],
            ['1008', 'Stroberi',           45000, 36000, 15, 'pak'],
        ] as $r) $p->execute($r);
    }
}

halaman(
    "<h3>✅ Database '" . htmlspecialchars(DB_NAME) . "' siap dipakai</h3>"
    . ($baru
        ? "<p>Akun awal:<br>Admin: <b>admin / admin123</b><br>Karyawan: <b>karyawan / karyawan123</b></p>"
          . "<p>⚠️ Segera ganti password lewat menu <b>User</b>.</p>"
        : "<p>Data sudah ada, tidak ada yang ditimpa.</p>")
    . "<p><a href='login.php'>➡ Ke halaman login</a></p>"
    . "<p><a href='install.php?hapus=1' onclick=\"return confirm('Hapus install.php?')\">🗑 Hapus install.php (wajib setelah selesai)</a></p>"
);
