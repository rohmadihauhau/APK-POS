<?php

require 'config.php';

header('Content-Type: application/json');

/* ============================================================
   CEK KASIR
   ============================================================ */

$k = kasir_aktif($pdo);

if (!$k) {

    http_response_code(401);

    echo json_encode([
        'ok'  => 0,
        'msg' => 'Sesi kasir berakhir, silakan login ulang'
    ]);

    exit;
}

/* ============================================================
   CEK MODAL
   ============================================================ */

$m = $pdo->prepare("
    SELECT modal
    FROM shift
    WHERE id=?
");

$m->execute([
    $k['shift']
]);

if ($m->fetchColumn() === null) {

    http_response_code(403);

    echo json_encode([
        'ok'  => 0,
        'msg' => 'Isi uang modal terlebih dahulu'
    ]);

    exit;
}

/* ============================================================
   BACA DATA TRANSAKSI
   ============================================================ */

$d = json_decode(
    file_get_contents('php://input'),
    true
);

try {

    $pdo->beginTransaction();

    $total = 0;
    $rows  = [];

    /* ========================================================
       CEK SEMUA PRODUK DAN STOK
       ======================================================== */

    foreach (($d['items'] ?? []) as $it) {

        $id =
            (int)($it['id'] ?? 0);

        $q =
            (float)($it['qty'] ?? 0);

        if (!$id) {

            throw new Exception(
                'Produk tidak valid'
            );
        }

        /*
         * Maksimal 3 angka desimal.
         */
        $q =
            round(
                $q,
                3
            );

        if ($q <= 0) {

            throw new Exception(
                'Jumlah produk tidak valid'
            );
        }

        /*
         * Lock produk.
         *
         * Harga modal dan harga jual yang dipakai
         * di transaksi diambil dari kondisi produk
         * saat transaksi terjadi.
         */
        $s = $pdo->prepare("
            SELECT *
            FROM produk
            WHERE id=?
            FOR UPDATE
        ");

        $s->execute([
            $id
        ]);

        $p =
            $s->fetch();

        if (!$p) {

            throw new Exception(
                'Produk tidak ditemukan'
            );
        }

        /* ----------------------------------------------------
           CEK STOK
           ---------------------------------------------------- */

        $stok =
            (float)$p['stok'];

        if ($stok < $q) {

            throw new Exception(
                'Stok '.$p['nama'].
                ' tidak cukup. '.
                'Stok tersedia: '.
                number_format(
                    $stok,
                    3,
                    ',',
                    '.'
                ).
                ' '.$p['satuan']
            );
        }

        /* ----------------------------------------------------
           HARGA
           ---------------------------------------------------- */

        $harga =
            round(
                (float)$p['harga'],
                2
            );

        $hargaModal =
            round(
                (float)$p['harga_modal'],
                2
            );

        /* ----------------------------------------------------
           HITUNG SUBTOTAL
           ---------------------------------------------------- */

        $subtotal =
            round(
                $harga * $q,
                2
            );

        /*
         * Simpan stok sebelum dan sesudah.
         */
        $stokSebelum =
            round(
                $stok,
                3
            );

        $stokSesudah =
            round(
                $stok - $q,
                3
            );

        $rows[] = [

            'produk' =>
                $p,

            'qty' =>
                $q,

            'harga' =>
                $harga,

            'harga_modal' =>
                $hargaModal,

            'subtotal' =>
                $subtotal,

            'stok_sebelum' =>
                $stokSebelum,

            'stok_sesudah' =>
                $stokSesudah

        ];

        $total +=
            $subtotal;
    }

    /* ========================================================
       KERANJANG KOSONG
       ======================================================== */

    if (!$rows) {

        throw new Exception(
            'Keranjang kosong'
        );
    }

    /*
     * Subtotal sebelum diskon (dibulatkan rupiah).
     */

    $subtotal =
        round(
            $total,
            0
        );

    /* ========================================================
       DISKON
       Persen divalidasi di server (hanya 0,2,4,...,20).
       ======================================================== */

    $diskonPersen =
        diskon_valid(
            $d['diskon'] ?? 0
        );

    $diskonNominal =
        round(
            $subtotal * $diskonPersen / 100,
            0
        );

    $total =
        round(
            $subtotal - $diskonNominal,
            2
        );

    /* ========================================================
       METODE PEMBAYARAN
       ======================================================== */

    $metode =
        in_array(
            $d['metode'] ?? '',
            ['tunai', 'qris'],
            true
        )
        ? $d['metode']
        : 'tunai';

    /*
     * QRIS dianggap bayar pas.
     */

    if ($metode === 'qris') {

        $bayar =
            $total;

    } else {

        $bayar =
            round(
                (float)($d['bayar'] ?? 0),
                2
            );
    }

    if ($bayar < $total) {

        throw new Exception(
            'Uang bayar kurang'
        );
    }

    $kembali =
        round(
            $bayar - $total,
            2
        );

    /* ========================================================
       KODE TRANSAKSI
       ======================================================== */

    $kode =
        'TRX'.
        date('ymdHis').
        rand(10,99);

    /* ========================================================
       SIMPAN HEADER TRANSAKSI
       ======================================================== */

    $q = $pdo->prepare("
        INSERT INTO transaksi
        (
            kode,
            user_id,
            total,
            bayar,
            kembali,
            metode,
            shift_id,
            subtotal,
            diskon_persen,
            diskon_nominal
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?
        )
    ");

    $q->execute([

        $kode,
        $k['id'],
        $total,
        $bayar,
        $kembali,
        $metode,
        $k['shift'],
        $subtotal,
        $diskonPersen,
        $diskonNominal

    ]);

    $tid =
        $pdo->lastInsertId();

    /* ========================================================
       SIMPAN DETAIL + POTONG STOK
       ======================================================== */

    foreach ($rows as $row) {

        $p =
            $row['produk'];

        $qty =
            $row['qty'];

        $harga =
            $row['harga'];

        $hargaModal =
            $row['harga_modal'];

        $subtotal =
            $row['subtotal'];

        $stokSebelum =
            $row['stok_sebelum'];

        $stokSesudah =
            $row['stok_sesudah'];

        /* ----------------------------------------------------
           DETAIL TRANSAKSI
           ---------------------------------------------------- */

        $q = $pdo->prepare("
            INSERT INTO detail
            (
                transaksi_id,
                produk_id,
                nama,
                harga,
                harga_modal,
                qty,
                subtotal
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");

        $q->execute([

            $tid,
            $p['id'],
            $p['nama'],
            $harga,
            $hargaModal,
            $qty,
            $subtotal

        ]);

        /* ----------------------------------------------------
           POTONG STOK PRODUK
           ---------------------------------------------------- */

        $q = $pdo->prepare("
            UPDATE produk
            SET stok = ?
            WHERE id=?
              AND stok >= ?
        ");

        $q->execute([

            $stokSesudah,
            $p['id'],
            $qty

        ]);

        /*
         * Pastikan benar-benar terpotong.
         */

        if ($q->rowCount() !== 1) {

            throw new Exception(
                'Stok '.$p['nama'].
                ' tidak mencukupi.'
            );
        }

        /* ----------------------------------------------------
           CATAT STOCK KELUAR
           ---------------------------------------------------- */

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
                ?,
                ?
            )
        ");

        $q->execute([

            $p['id'],
            $qty,
            $stokSebelum,
            $stokSesudah,
            'Penjualan POS '.$kode,
            $tid,
            $k['id']

        ]);
    }

    /* ========================================================
       COMMIT
       ======================================================== */

    $pdo->commit();

    /* ========================================================
       RESPONSE
       ======================================================== */

    echo json_encode([

        'ok' =>
            1,

        'kode' =>
            $kode,

        'subtotal' =>
            $subtotal,

        'diskon_persen' =>
            $diskonPersen,

        'diskon_nominal' =>
            $diskonNominal,

        'total' =>
            $total,

        'bayar' =>
            $bayar,

        'kembali' =>
            $kembali

    ]);

} catch (Throwable $e) {

    if ($e instanceof PDOException || $e instanceof Error) {
        error_log('API: ' . $e->getMessage());
    }

    /* ========================================================
       ROLLBACK
       ======================================================== */

    if ($pdo->inTransaction()) {

        $pdo->rollBack();
    }

    http_response_code(400);

    echo json_encode([

        'ok'  => 0,

        'msg' => ($e instanceof PDOException || $e instanceof Error)
            ? 'Terjadi kesalahan server, coba lagi.'
            : $e->getMessage()

    ]);
}
