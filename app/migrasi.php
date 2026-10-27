<?php
/* Migrasi struktur database. Dipanggil dari config.php (memakai $pdo dan $has). */

/* ============================================================
   MIGRASI LAMA
   ============================================================ */

if (empty($_SESSION['mig6'])) {

    try {

        /* ====================================================
           KODE PRODUK
           ==================================================== */

        if (!$has('produk', 'kode')) {

            $pdo->exec("
                ALTER TABLE produk
                ADD kode VARCHAR(30) NULL AFTER id
            ");

            foreach (
                $pdo
                    ->query('SELECT id FROM produk')
                    ->fetchAll()
                as $r
            ) {

                $pdo
                    ->prepare(
                        'UPDATE produk SET kode=? WHERE id=?'
                    )
                    ->execute([
                        (string)(1000 + $r['id']),
                        $r['id']
                    ]);
            }

            try {

                $pdo->exec("
                    ALTER TABLE produk
                    ADD UNIQUE KEY uk_produk_kode (kode)
                ");

            } catch (Throwable $e) {
                /* Index mungkin sudah ada */
            }
        }

        /* ====================================================
           PANJANG KODE PRODUK
           ==================================================== */

        $col = $pdo
            ->query(
                "SHOW COLUMNS FROM produk LIKE 'kode'"
            )
            ->fetch();

        if (
            $col &&
            stripos($col['Type'], 'varchar(10)') !== false
        ) {

            $pdo->exec("
                ALTER TABLE produk
                MODIFY kode VARCHAR(30) NULL
            ");
        }

        /* ====================================================
           STOK DECIMAL
           ==================================================== */

        $stokCol = $pdo
            ->query(
                "SHOW COLUMNS FROM produk LIKE 'stok'"
            )
            ->fetch();

        if ($stokCol) {

            $stokType = strtolower(
                $stokCol['Type'] ?? ''
            );

            if (
                !preg_match(
                    '/^decimal\(15,3\)/i',
                    $stokType
                )
            ) {

                $pdo->exec("
                    ALTER TABLE produk
                    MODIFY stok DECIMAL(15,3)
                    NOT NULL DEFAULT 0
                ");
            }
        }

        /* ====================================================
           DETAIL.QTY DECIMAL
           ==================================================== */

        $qtyCol = $pdo
            ->query(
                "SHOW COLUMNS FROM detail LIKE 'qty'"
            )
            ->fetch();

        if ($qtyCol) {

            $qtyType = strtolower(
                $qtyCol['Type'] ?? ''
            );

            if (
                !preg_match(
                    '/^decimal\(15,3\)/i',
                    $qtyType
                )
            ) {

                $pdo->exec("
                    ALTER TABLE detail
                    MODIFY qty DECIMAL(15,3)
                    NOT NULL DEFAULT 0
                ");
            }
        }

        /* ====================================================
           METODE TRANSAKSI
           ==================================================== */

        if (!$has('transaksi', 'metode')) {

            $pdo->exec("
                ALTER TABLE transaksi
                ADD metode VARCHAR(10)
                NOT NULL DEFAULT 'tunai'
            ");
        }

        /* ====================================================
           SHIFT ID
           ==================================================== */

        if (!$has('transaksi', 'shift_id')) {

            $pdo->exec("
                ALTER TABLE transaksi
                ADD shift_id INT NULL
            ");
        }

        /* ====================================================
           TABEL SHIFT
           ==================================================== */

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS shift (

                id INT AUTO_INCREMENT PRIMARY KEY,

                user_id INT,

                buka TIMESTAMP
                    DEFAULT CURRENT_TIMESTAMP,

                tutup DATETIME NULL,

                status VARCHAR(10)
                    DEFAULT 'buka',

                jml INT DEFAULT 0,

                total_tunai DECIMAL(15,2)
                    DEFAULT 0,

                total_qris DECIMAL(15,2)
                    DEFAULT 0,

                input_pendapatan DECIMAL(15,2)
                    NULL,

                selisih DECIMAL(15,2)
                    NULL,

                modal DECIMAL(15,2)
                    NULL
            )
        ");

        /* ====================================================
           KOLOM SHIFT
           ==================================================== */

        if (!$has('shift', 'input_pendapatan')) {

            $pdo->exec("
                ALTER TABLE shift
                ADD input_pendapatan DECIMAL(15,2) NULL
            ");
        }

        if (!$has('shift', 'selisih')) {

            $pdo->exec("
                ALTER TABLE shift
                ADD selisih DECIMAL(15,2) NULL
            ");
        }

        if (!$has('shift', 'modal')) {

            $pdo->exec("
                ALTER TABLE shift
                ADD modal DECIMAL(15,2) NULL
            ");
        }

        /* ====================================================
           TABEL HISTORI STOK
           ==================================================== */

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS stok_log (

                id INT AUTO_INCREMENT PRIMARY KEY,

                produk_id INT NOT NULL,

                jenis ENUM('masuk','keluar')
                    NOT NULL,

                qty DECIMAL(15,3)
                    NOT NULL DEFAULT 0,

                stok_sebelum DECIMAL(15,3)
                    NULL,

                stok_sesudah DECIMAL(15,3)
                    NULL,

                keterangan VARCHAR(255)
                    NULL,

                transaksi_id INT
                    NULL,

                user_id INT
                    NULL,

                created_at TIMESTAMP
                    DEFAULT CURRENT_TIMESTAMP,

                INDEX idx_stok_produk
                    (produk_id),

                INDEX idx_stok_jenis
                    (jenis),

                INDEX idx_stok_transaksi
                    (transaksi_id),

                INDEX idx_stok_user
                    (user_id),

                INDEX idx_stok_tanggal
                    (created_at)
            )
        ");

        $_SESSION['mig6'] = 1;

    } catch (Throwable $e) {

        /*
         * Jangan hentikan aplikasi
         * kalau migrasi lama sebagian
         * sudah berhasil.
         */
    }
}

/* ============================================================
   MIGRASI BARU - MIG7
   ============================================================ */

if (empty($_SESSION['mig7'])) {

    try {

        /* ====================================================
           HARGA MODAL PRODUK
           ==================================================== */

        if (!$has('produk', 'harga_modal')) {

            $pdo->exec("
                ALTER TABLE produk
                ADD harga_modal DECIMAL(15,2)
                NOT NULL DEFAULT 0
                AFTER harga
            ");
        }

        /* ====================================================
           HARGA MODAL DETAIL TRANSAKSI
           ==================================================== */

        if (!$has('detail', 'harga_modal')) {

            $pdo->exec("
                ALTER TABLE detail
                ADD harga_modal DECIMAL(15,2)
                NOT NULL DEFAULT 0
                AFTER harga
            ");
        }

        /* ====================================================
           PASTIKAN STOK PRODUK DECIMAL
           ==================================================== */

        $stokCol = $pdo
            ->query(
                "SHOW COLUMNS FROM produk LIKE 'stok'"
            )
            ->fetch();

        if ($stokCol) {

            $stokType = strtolower(
                $stokCol['Type'] ?? ''
            );

            if (
                !preg_match(
                    '/^decimal\(15,3\)/i',
                    $stokType
                )
            ) {

                $pdo->exec("
                    ALTER TABLE produk
                    MODIFY stok DECIMAL(15,3)
                    NOT NULL DEFAULT 0
                ");
            }
        }

        /* ====================================================
           PASTIKAN DETAIL.QTY DECIMAL
           ==================================================== */

        $qtyCol = $pdo
            ->query(
                "SHOW COLUMNS FROM detail LIKE 'qty'"
            )
            ->fetch();

        if ($qtyCol) {

            $qtyType = strtolower(
                $qtyCol['Type'] ?? ''
            );

            if (
                !preg_match(
                    '/^decimal\(15,3\)/i',
                    $qtyType
                )
            ) {

                $pdo->exec("
                    ALTER TABLE detail
                    MODIFY qty DECIMAL(15,3)
                    NOT NULL DEFAULT 0
                ");
            }
        }

        /* ====================================================
           PASTIKAN STOK_LOG.STOK_SEBELUM
           ==================================================== */

        if (!$has('stok_log', 'stok_sebelum')) {

            $pdo->exec("
                ALTER TABLE stok_log
                ADD stok_sebelum DECIMAL(15,3)
                NOT NULL DEFAULT 0
                AFTER qty
            ");
        }

        /* ====================================================
           PASTIKAN STOK_LOG.STOK_SESUDAH
           ==================================================== */

        if (!$has('stok_log', 'stok_sesudah')) {

            $pdo->exec("
                ALTER TABLE stok_log
                ADD stok_sesudah DECIMAL(15,3)
                NOT NULL DEFAULT 0
                AFTER stok_sebelum
            ");
        }

        $_SESSION['mig7'] = 1;

    } catch (Throwable $e) {

        /*
         * Jangan matikan aplikasi jika
         * migration mengalami error.
         */
    }
}

/* ============================================================
   MIGRASI DISKON
   Menambah kolom diskon di tabel transaksi (aman dijalankan
   berulang kali; hanya menambah kolom bila belum ada).
   ============================================================ */

if (empty($_SESSION['mig_diskon'])) {

    try {

        if (!$has('transaksi', 'subtotal')) {

            $pdo->exec("
                ALTER TABLE transaksi
                ADD subtotal DECIMAL(15,2)
                NOT NULL DEFAULT 0
            ");

            /* Transaksi lama: subtotal = total (belum ada diskon) */
            $pdo->exec("
                UPDATE transaksi
                SET subtotal = total
                WHERE subtotal = 0
            ");
        }

        if (!$has('transaksi', 'diskon_persen')) {

            $pdo->exec("
                ALTER TABLE transaksi
                ADD diskon_persen DECIMAL(5,2)
                NOT NULL DEFAULT 0
            ");
        }

        if (!$has('transaksi', 'diskon_nominal')) {

            $pdo->exec("
                ALTER TABLE transaksi
                ADD diskon_nominal DECIMAL(15,2)
                NOT NULL DEFAULT 0
            ");
        }

        $_SESSION['mig_diskon'] = 1;

    } catch (Throwable $e) {

        /* Jangan matikan aplikasi bila migrasi gagal */
    }
}

/* ============================================================
   MIGRASI PIN 6 DIGIT + LOG LOGIN (FOTO PERCOBAAN GAGAL)
   ============================================================ */

if (empty($_SESSION['mig_pin'])) {

    try {

        if (!$has('users', 'pin_hash')) {

            $pdo->exec("
                ALTER TABLE users
                ADD pin_hash VARCHAR(255) NULL
            ");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS login_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                username VARCHAR(60) NULL,
                jenis VARCHAR(20) NOT NULL,
                ip VARCHAR(45) NULL,
                agent VARCHAR(255) NULL,
                foto MEDIUMBLOB NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_login_user (user_id),
                INDEX idx_login_jenis_waktu (jenis, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $_SESSION['mig_pin'] = 1;

    } catch (Throwable $e) {

        /* Jangan matikan aplikasi bila migrasi gagal */
    }
}


/* ============================================================
   TABEL BIAYA OPERASIONAL (listrik, gaji, perawatan, dll)
   ============================================================ */

if (empty($_SESSION['mig_biaya'])) {

    try {

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS biaya (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tanggal DATE NOT NULL,
                kategori VARCHAR(30) NOT NULL,
                keterangan VARCHAR(150) NULL,
                jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
                user_id INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_biaya_tanggal (tanggal),
                INDEX idx_biaya_kategori (kategori)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $_SESSION['mig_biaya'] = 1;

    } catch (Throwable $e) {

        /* Jangan matikan aplikasi bila migrasi gagal */
    }
}


/* ============================================================
   ROLE KANAN ADMIN
   ============================================================ */

if (empty($_SESSION['mig_kanan_admin'])) {

    try {

        $pdo->exec("
            ALTER TABLE users
            MODIFY role ENUM('admin','karyawan','kanan_admin') DEFAULT 'karyawan'
        ");

        $_SESSION['mig_kanan_admin'] = 1;

    } catch (Throwable $e) {

        /* Jangan matikan aplikasi bila migrasi gagal */
    }
}