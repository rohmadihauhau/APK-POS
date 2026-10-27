<?php

require_once __DIR__ . '/settings.php';

date_default_timezone_set('Asia/Pontianak');

require_once __DIR__ . '/security.php';
security_session_start();   // cookie HttpOnly + SameSite, header keamanan
security_check_origin();    // tolak POST dari situs lain

ini_set('display_errors', (defined('DEBUG') && DEBUG) ? '1' : '0');

/* ============================================================
   DATABASE
   ============================================================ */

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : '3306') . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
        ]
    );
} catch (PDOException $e) {
    error_log('DB: ' . $e->getMessage());
    http_response_code(500);
    exit('Database tidak bisa dihubungi. Pastikan MariaDB menyala dan settings.php benar.');
}

/* Samakan jam MySQL dengan jam PHP (WIB) */
$pdo->exec("SET time_zone = '+07:00'");

/* ============================================================
   HELPER CEK KOLOM
   ============================================================ */

$has = function ($t, $c) use ($pdo) {

    $t = str_replace('`', '', $t);
    $c = str_replace('`', '', $c);

    return $pdo
        ->query(
            "SHOW COLUMNS FROM `$t` LIKE " .
            $pdo->quote($c)
        )
        ->fetch();
};

/* Migrasi struktur database (dijalankan sekali per sesi) */
require __DIR__ . '/migrasi.php';

/* ============================================================
   PILIHAN DISKON (persen)
   Ubah daftar ini bila ingin pilihan diskon yang lain.
   ============================================================ */

function diskon_pilihan()
{
    return [2, 4, 6, 8, 10, 12, 14, 16, 18, 20];
}

function diskon_valid($persen)
{
    $persen = (float)$persen;

    if ($persen == 0) {
        return 0;
    }

    return in_array((int)$persen, diskon_pilihan(), true)
        && $persen == (int)$persen
        ? (int)$persen
        : 0;
}

/* ============================================================
   HELPER RUPIAH
   ============================================================ */

function rp($n)
{
    return 'Rp ' . number_format(
        (float)$n,
        0,
        ',',
        '.'
    );
}

/* ============================================================
   HELPER HTML
   ============================================================ */

function h($s)
{
    return htmlspecialchars(
        $s ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}

/* ============================================================
   LOGIN
   ============================================================ */

function need($role = null)
{
    global $pdo;

    if (empty($_SESSION['u'])) {
        header('Location: login.php');
        exit;
    }

    /* Ambil role/nama terbaru dari database: user yang dihapus
       atau diturunkan rolenya langsung kehilangan akses. */
    $s = $pdo->prepare('SELECT nama, role FROM users WHERE id=?');
    $s->execute([(int)($_SESSION['u']['id'] ?? 0)]);
    $db = $s->fetch();

    if (!$db) {
        unset($_SESSION['u']);
        header('Location: login.php');
        exit;
    }

    $_SESSION['u']['role'] = $db['role'];
    $_SESSION['u']['nama'] = $db['nama'];

    if ($role) {
        $allowed = is_array($role) ? $role : [$role];
        if (!in_array($db['role'], $allowed, true)) {
            if ($db['role'] === 'kanan_admin') {
                header('Location: produk.php');
                exit;
            }
            header('Location: index.php');
            exit;
        }
    }
}


/* ============================================================
   LOGIN KASIR
   ============================================================ */

function kasir_masuk($pdo, $u)
{
    if (($u['role'] ?? '') !== 'karyawan') {

        return
            'Akun ' . (($u['role'] ?? '') === 'kanan_admin' ? 'kanan admin' : 'admin') . ' tidak bisa dipakai untuk kasir. ' .
            'Gunakan akun karyawan.';
    }

    /* ========================================================
       TUTUP SHIFT LAMA MILIK ADMIN
       ======================================================== */

    foreach (
        $pdo
            ->query("
                SELECT s.id
                FROM shift s
                JOIN users x
                    ON x.id = s.user_id
                WHERE s.status = 'buka'
                  AND x.role = 'admin'
            ")
            ->fetchAll()
        as $o
    ) {

        tutup_shift(
            $pdo,
            $o['id']
        );
    }

    /* ========================================================
       CEK SHIFT AKTIF
       ======================================================== */

    $o = $pdo
        ->query("
            SELECT
                s.id,
                s.user_id,
                u.nama
            FROM shift s
            JOIN users u
                ON u.id = s.user_id
            WHERE s.status = 'buka'
            ORDER BY s.id DESC
            LIMIT 1
        ")
        ->fetch();

    if (
        $o &&
        (int)$o['user_id'] !== (int)$u['id']
    ) {

        return
            'Kasir masih dipakai ' .
            $o['nama'] .
            ' dan belum closing. ' .
            'Closing dulu sebelum login dengan user lain.';
    }

    /* ========================================================
       GUNAKAN SHIFT LAMA ATAU BUAT SHIFT BARU
       ======================================================== */

    if ($o) {

        $sid = $o['id'];

    } else {

        $pdo
            ->prepare("
                INSERT INTO shift
                (
                    user_id,
                    status
                )
                VALUES
                (
                    ?,
                    'buka'
                )
            ")
            ->execute([
                $u['id']
            ]);

        $sid = $pdo->lastInsertId();
    }

    /* ========================================================
       SIMPAN SESSION KASIR
       ======================================================== */

    $_SESSION['k'] = [
        'id'    => $u['id'],
        'nama'  => $u['nama'],
        'role'  => $u['role'],
        'shift' => $sid
    ];

    return null;
}

/* ============================================================
   CEK KASIR AKTIF
   ============================================================ */

function kasir_aktif($pdo)
{
    $k = $_SESSION['k'] ?? null;

    if (!$k) {
        return null;
    }

    if (
        ($k['role'] ?? '') !== 'karyawan'
    ) {

        unset($_SESSION['k']);

        return null;
    }

    $c = $pdo->prepare("
        SELECT status
        FROM shift
        WHERE id = ?
    ");

    $c->execute([
        $k['shift']
    ]);

    if (
        $c->fetchColumn() !== 'buka'
    ) {

        unset($_SESSION['k']);

        return null;
    }

    return $k;
}

/* ============================================================
   RINGKASAN SHIFT
   ============================================================ */

function ringkas($pdo, $sid)
{
    $r = $pdo->prepare("
        SELECT

            COUNT(*) AS n,

            COALESCE(
                SUM(
                    CASE
                        WHEN metode = 'tunai'
                        THEN total
                        ELSE 0
                    END
                ),
                0
            ) AS t,

            COALESCE(
                SUM(
                    CASE
                        WHEN metode = 'qris'
                        THEN total
                        ELSE 0
                    END
                ),
                0
            ) AS q

        FROM transaksi

        WHERE shift_id = ?
    ");

    $r->execute([
        $sid
    ]);

    $hasil = $r->fetch();

    /* ========================================================
       MODAL AWAL
       ======================================================== */

    $m = $pdo->prepare("
        SELECT modal
        FROM shift
        WHERE id = ?
    ");

    $m->execute([
        $sid
    ]);

    $modal = $m->fetchColumn();

    $hasil['modal'] =
        $modal === null
        ? null
        : (float)$modal;

    /* ========================================================
       UANG FISIK SEHARUSNYA
       ======================================================== */

    $hasil['seharusnya'] =
        (
            $hasil['modal'] === null
            ? 0
            : (float)$hasil['modal']
        )
        +
        (float)$hasil['t'];

    return $hasil;
}

/* ============================================================
   TUTUP SHIFT
   ============================================================ */

function tutup_shift(
    $pdo,
    $sid,
    $input = null
) {

    $r = ringkas(
        $pdo,
        $sid
    );

    if ($input === null) {

        $sel = null;

    } else {

        $sel =
            (float)$input
            -
            (float)$r['seharusnya'];
    }

    $q = $pdo->prepare("
        UPDATE shift

        SET

            status = 'tutup',

            tutup = NOW(),

            jml = ?,

            total_tunai = ?,

            total_qris = ?,

            input_pendapatan = ?,

            selisih = ?

        WHERE id = ?
    ");

    $q->execute([
        $r['n'],
        $r['t'],
        $r['q'],
        $input,
        $sel,
        $sid
    ]);

    /* ========================================================
       BUAT PDF CLOSING
       ======================================================== */

    try {

        simpan_pdf(
            $pdo,
            $sid
        );

    } catch (Throwable $e) {

        /*
         * PDF gagal tidak membatalkan closing.
         */
    }

    return $sel;
}

/* ============================================================
   SIMPAN PDF CLOSING
   ============================================================ */

function simpan_pdf($pdo, $sid)
{
    require_once __DIR__ . '/pdf.php';

    $d = __DIR__ . '/laporan-closing';

    if (!is_dir($d)) {

        @mkdir(
            $d,
            0775,
            true
        );

        @file_put_contents(
            $d . '/.htaccess',

            "<IfModule mod_authz_core.c>\n" .
            "Require all denied\n" .
            "</IfModule>\n" .

            "<IfModule !mod_authz_core.c>\n" .
            "Deny from all\n" .
            "</IfModule>\n"
        );
    }

    file_put_contents(
        $d . '/closing-' . $sid . '.pdf',
        pdf_closing(
            $pdo,
            $sid
        )
    );
}

/* ============================================================
   CATAT STOCK MASUK
   ============================================================ */

function stok_masuk(
    $pdo,
    $produk_id,
    $qty,
    $keterangan = '',
    $user_id = null
) {

    $produk_id = (int)$produk_id;

    $qty = round(
        (float)$qty,
        3
    );

    if ($produk_id <= 0) {

        throw new Exception(
            'Produk tidak valid.'
        );
    }

    if ($qty <= 0) {

        throw new Exception(
            'Jumlah stok masuk harus lebih dari 0.'
        );
    }

    /* ========================================================
       LOCK PRODUK
       ======================================================== */

    $q = $pdo->prepare("
        SELECT
            id,
            nama,
            stok,
            satuan
        FROM produk
        WHERE id = ?
        FOR UPDATE
    ");

    $q->execute([
        $produk_id
    ]);

    $p = $q->fetch();

    if (!$p) {

        throw new Exception(
            'Produk tidak ditemukan.'
        );
    }

    /* ========================================================
       STOK SEBELUM
       ======================================================== */

    $stokSebelum = round(
        (float)$p['stok'],
        3
    );

    /* ========================================================
       STOK SESUDAH
       ======================================================== */

    $stokSesudah = round(
        $stokSebelum + $qty,
        3
    );

    /* ========================================================
       UPDATE STOK PRODUK
       ======================================================== */

    $q = $pdo->prepare("
        UPDATE produk
        SET stok = ?
        WHERE id = ?
    ");

    $q->execute([
        $stokSesudah,
        $produk_id
    ]);

    /* ========================================================
       SIMPAN RIWAYAT STOK
       ======================================================== */

    $q = $pdo->prepare("
        INSERT INTO stok_log
        (
            produk_id,
            jenis,
            qty,
            stok_sebelum,
            stok_sesudah,
            keterangan,
            transaksi_id,
            user_id
        )

        VALUES
        (
            ?,
            'masuk',
            ?,
            ?,
            ?,
            ?,
            NULL,
            ?
        )
    ");

    $q->execute([
        $produk_id,
        $qty,
        $stokSebelum,
        $stokSesudah,
        $keterangan,
        $user_id
    ]);

    return true;
}

/* ============================================================
   CATAT STOCK KELUAR MANUAL
   ============================================================ */

function stok_keluar(
    $pdo,
    $produk_id,
    $qty,
    $keterangan = '',
    $user_id = null
) {

    $produk_id = (int)$produk_id;

    $qty = round(
        (float)$qty,
        3
    );

    if ($produk_id <= 0) {

        throw new Exception(
            'Produk tidak valid.'
        );
    }

    if ($qty <= 0) {

        throw new Exception(
            'Jumlah stok keluar harus lebih dari 0.'
        );
    }

    /* ========================================================
       LOCK PRODUK
       ======================================================== */

    $q = $pdo->prepare("
        SELECT
            id,
            nama,
            stok,
            satuan
        FROM produk
        WHERE id = ?
        FOR UPDATE
    ");

    $q->execute([
        $produk_id
    ]);

    $p = $q->fetch();

    if (!$p) {

        throw new Exception(
            'Produk tidak ditemukan.'
        );
    }

    /* ========================================================
       STOK SEBELUM
       ======================================================== */

    $stokSebelum = round(
        (float)$p['stok'],
        3
    );

    /* ========================================================
       CEK STOK
       ======================================================== */

    if ($stokSebelum < $qty) {

        throw new Exception(
            'Stok ' .
            $p['nama'] .
            ' tidak cukup. ' .
            'Stok tersedia: ' .
            number_format(
                $stokSebelum,
                3,
                ',',
                '.'
            ) .
            ' ' .
            $p['satuan']
        );
    }

    /* ========================================================
       STOK SESUDAH
       ======================================================== */

    $stokSesudah = round(
        $stokSebelum - $qty,
        3
    );

    /* ========================================================
       UPDATE STOK
       ======================================================== */

    $q = $pdo->prepare("
        UPDATE produk

        SET stok = ?

        WHERE id = ?
          AND stok >= ?
    ");

    $q->execute([
        $stokSesudah,
        $produk_id,
        $qty
    ]);

    if ($q->rowCount() !== 1) {

        throw new Exception(
            'Stok gagal dikurangi.'
        );
    }

    /* ========================================================
       SIMPAN RIWAYAT
       ======================================================== */

    $q = $pdo->prepare("
        INSERT INTO stok_log
        (
            produk_id,
            jenis,
            qty,
            stok_sebelum,
            stok_sesudah,
            keterangan,
            transaksi_id,
            user_id
        )

        VALUES
        (
            ?,
            'keluar',
            ?,
            ?,
            ?,
            ?,
            NULL,
            ?
        )
    ");

    $q->execute([
        $produk_id,
        $qty,
        $stokSebelum,
        $stokSesudah,
        $keterangan,
        $user_id
    ]);

    return true;
}

/* ============================================================
   AMBIL RINGKASAN STOK PRODUK
   ============================================================ */

function ringkas_stok_produk(
    $pdo,
    $produk_id
) {

    $q = $pdo->prepare("
        SELECT

            COALESCE(
                SUM(
                    CASE
                        WHEN jenis = 'masuk'
                        THEN qty
                        ELSE 0
                    END
                ),
                0
            ) AS masuk,

            COALESCE(
                SUM(
                    CASE
                        WHEN jenis = 'keluar'
                        THEN qty
                        ELSE 0
                    END
                ),
                0
            ) AS keluar

        FROM stok_log

        WHERE produk_id = ?
    ");

    $q->execute([
        $produk_id
    ]);

    $r = $q->fetch();

    return [
        'masuk' =>
            (float)$r['masuk'],

        'keluar' =>
            (float)$r['keluar']
    ];
}

/* ============================================================
   SELESAI
   ============================================================ */
