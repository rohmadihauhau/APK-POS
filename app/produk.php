<?php

require 'config.php';

need(['admin', 'kanan_admin']);

$isKananAdmin = (($_SESSION['u']['role'] ?? '') === 'kanan_admin');

$msg = '';
$ok  = '';

/* ==========================================================
   DATA FORM PRODUK
   ========================================================== */

$e = [
    'id'          => '',
    'kode'        => '',
    'nama'        => '',
    'harga_modal' => 0,
    'harga'       => 0,
    'stok'        => 0,
    'satuan'      => 'kg'
];

/* ==========================================================
   HAPUS PRODUK
   ========================================================== */

if (isset($_GET['del'])) {

    if ($isKananAdmin) {
        http_response_code(403);
        exit('Akses ditolak: role kanan admin tidak memiliki izin menghapus produk.');
    }

    if (hash_equals(security_csrf_token(), (string)($_GET['t'] ?? ''))) {

        $id = (int)$_GET['del'];

    try {

        /*
         * Produk yang sudah pernah dipakai transaksi
         * tidak boleh dihapus.
         */

        $cek = $pdo->prepare(
            'SELECT COUNT(*) FROM detail WHERE produk_id=?'
        );

        $cek->execute([$id]);

        if ((int)$cek->fetchColumn() > 0) {

            $msg =
                'Produk sudah memiliki transaksi dan tidak bisa dihapus. ' .
                'Gunakan Edit untuk mengubah nama/harga.';

        } else {

            $pdo->prepare(
                'DELETE FROM produk WHERE id=?'
            )->execute([$id]);

            header(
                'Location: produk.php'
            );

            exit;
        }

    } catch (PDOException $x) {

        $msg =
            'Produk tidak dapat dihapus karena masih digunakan oleh data lain.';
    }
}
}

/* ==========================================================
   SIMPAN / UPDATE PRODUK
   ========================================================== */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    ($_POST['aksi'] ?? '') === 'produk'
) {

    if ($isKananAdmin && !empty($_POST['id'])) {
        http_response_code(403);
        exit('Akses ditolak: role kanan admin tidak memiliki izin mengubah atau mengedit produk.');
    }

    $e = [

        'id' =>
            (int)($_POST['id'] ?? 0),

        'kode' =>
            trim(
                (string)($_POST['kode'] ?? '')
            ),

        'nama' =>
            trim(
                (string)($_POST['nama'] ?? '')
            ),

        'harga_modal' =>
            (float)(
                $_POST['harga_modal'] ?? 0
            ),

        'harga' =>
            (float)(
                $_POST['harga'] ?? 0
            ),

        'stok' =>
            (float)(
                $_POST['stok'] ?? 0
            ),

        'satuan' =>
            strtolower(
                trim(
                    (string)(
                        $_POST['satuan'] ?? 'kg'
                    )
                )
            )
    ];

    try {

        /*
         * Hilangkan karakter #.
         */

        $e['kode'] =
            str_replace(
                '#',
                '',
                $e['kode']
            );

        /*
         * Kode harus angka.
         */

        if (
            !preg_match(
                '/^\d{1,30}$/',
                $e['kode']
            )
        ) {

            throw new Exception(
                'Kode produk harus berupa angka maksimal 30 digit.'
            );
        }

        if ($e['nama'] === '') {

            throw new Exception(
                'Nama produk wajib diisi.'
            );
        }

        if ($e['harga_modal'] < 0) {

            throw new Exception(
                'Harga modal tidak boleh minus.'
            );
        }

        if ($e['harga'] < 0) {

            throw new Exception(
                'Harga jual tidak boleh minus.'
            );
        }

        if ($e['harga_modal'] > 999999999999) {

            throw new Exception(
                'Harga modal terlalu besar.'
            );
        }

        if ($e['harga'] > 999999999999) {

            throw new Exception(
                'Harga jual terlalu besar.'
            );
        }

        /*
         * Stok hanya digunakan ketika produk baru.
         */

        if ($e['stok'] < 0) {

            throw new Exception(
                'Stok tidak boleh minus.'
            );
        }

        if ($e['stok'] > 999999999) {

            throw new Exception(
                'Stok terlalu besar.'
            );
        }

        $satuanValid = [

            'kg',
            'gram',
            'buah',
            'pcs',
            'sir',
            'pak',
            'liter',
            'lusin',
            'ikat'

        ];

        if (
            !in_array(
                $e['satuan'],
                $satuanValid,
                true
            )
        ) {

            throw new Exception(
                'Satuan tidak valid.'
            );
        }

        /*
         * Pembulatan.
         */

        $e['harga_modal'] =
            round(
                $e['harga_modal'],
                2
            );

        $e['harga'] =
            round(
                $e['harga'],
                2
            );

        $e['stok'] =
            round(
                $e['stok'],
                3
            );

        /* ==================================================
           PRODUK BARU
           ================================================== */

        if (!$e['id']) {

            $pdo->beginTransaction();

            /*
             * Produk dibuat dengan stok 0.
             */

            $q = $pdo->prepare(
                '
                INSERT INTO produk
                (
                    kode,
                    nama,
                    harga,
                    harga_modal,
                    stok,
                    satuan
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
                '
            );

            $q->execute([

                $e['kode'],
                $e['nama'],
                $e['harga'],
                $e['harga_modal'],
                0,
                $e['satuan']

            ]);

            $produkId =
                (int)$pdo->lastInsertId();

            /*
             * Stok awal dicatat sebagai stok masuk.
             */

            if ($e['stok'] > 0) {

                stok_masuk(

                    $pdo,
                    $produkId,
                    $e['stok'],
                    'Stok awal produk',
                    $_SESSION['u']['id'] ?? null

                );
            }

            $pdo->commit();

        }

        /* ==================================================
           EDIT PRODUK
           ================================================== */

        else {

            /*
             * Stok TIDAK diubah dari halaman produk.
             */

            $q = $pdo->prepare(
                '
                UPDATE produk
                SET
                    kode=?,
                    nama=?,
                    harga=?,
                    harga_modal=?,
                    satuan=?
                WHERE id=?
                '
            );

            $q->execute([

                $e['kode'],
                $e['nama'],
                $e['harga'],
                $e['harga_modal'],
                $e['satuan'],
                $e['id']

            ]);
        }

        header(
            'Location: produk.php'
        );

        exit;

    } catch (PDOException $x) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $msg =
            $x->getCode() == 23000

            ? 'Kode produk sudah dipakai produk lain.'

            : 'Gagal menyimpan produk.';

    } catch (Exception $x) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $msg = ($x instanceof PDOException) ? 'Terjadi kesalahan database.' : $x->getMessage();
    }
}

/* ==========================================================
   EDIT PRODUK
   ========================================================== */

if (isset($_GET['edit'])) {

    if ($isKananAdmin) {
        header('Location: produk.php');
        exit;
    }

    $s = $pdo->prepare(
        '
        SELECT
            id,
            kode,
            nama,
            harga_modal,
            harga,
            stok,
            satuan
        FROM produk
        WHERE id=?
        '
    );

    $s->execute([
        (int)$_GET['edit']
    ]);

    $row =
        $s->fetch(PDO::FETCH_ASSOC);

    if ($row) {

        $e = [

            'id' =>
                (int)($row['id'] ?? 0),

            'kode' =>
                (string)($row['kode'] ?? ''),

            'nama' =>
                (string)($row['nama'] ?? ''),

            'harga_modal' =>
                (float)($row['harga_modal'] ?? 0),

            'harga' =>
                (float)($row['harga'] ?? 0),

            'stok' =>
                (float)($row['stok'] ?? 0),

            'satuan' =>
                (string)($row['satuan'] ?? 'kg')

        ];
    }
}

/* ==========================================================
   DATA PRODUK
   ========================================================== */

$list = $pdo->query(
    '
    SELECT
        p.*,

        COALESCE(
            (
                SELECT SUM(sl.qty)
                FROM stok_log sl
                WHERE
                    sl.produk_id=p.id
                    AND sl.jenis="masuk"
            ),
            0
        ) AS total_masuk,

        COALESCE(
            (
                SELECT SUM(sl.qty)
                FROM stok_log sl
                WHERE
                    sl.produk_id=p.id
                    AND sl.jenis="keluar"
            ),
            0
        ) AS total_keluar

    FROM produk p

    ORDER BY
        p.nama
    '
)->fetchAll(PDO::FETCH_ASSOC);

/* ==========================================================
   RINGKASAN
   ========================================================== */

$totalStok = 0;

$totalNilaiModal = 0;

$totalPotensiUntung = 0;

foreach ($list as $p) {

    $stok =
        (float)($p['stok'] ?? 0);

    $hargaModal =
        (float)($p['harga_modal'] ?? 0);

    $hargaJual =
        (float)($p['harga'] ?? 0);

    $profitUnit =
        $hargaJual - $hargaModal;

    $totalStok +=
        $stok;

    $totalNilaiModal +=
        $hargaModal * $stok;

    $totalPotensiUntung +=
        $profitUnit * $stok;
}

include 'header.php';

?>

<!-- ======================================================
     FORM PRODUK
====================================================== -->

<div class="panel">

    <h3>

        <?= (!empty($e['id']) && !$isKananAdmin)
            ? 'Edit'
            : 'Tambah' ?>

        Produk

    </h3>

    <?php if ($msg): ?>

        <div
            class="alert"
            style="margin:10px 0"
        >

            <?= h($msg) ?>

        </div>

    <?php endif; ?>

    <form
        method="post"
        class="frm"
        id="frmProduk"
    >

        <input
            type="hidden"
            name="aksi"
            value="produk"
        >

        <input
            type="hidden"
            name="id"
            value="<?= h($e['id'] ?? '') ?>"
        >

        <!-- ==================================================
             KODE PRODUK
        ================================================== -->

        <div>

            <small>
                Kode Produk / PLU
            </small>

            <div
                style="
                    display:flex;
                    gap:8px;
                    align-items:end
                "
            >

                <input
                    name="kode"
                    id="kode"
                    value="<?= h($e['kode'] ?? '') ?>"
                    placeholder="Contoh: 079631"
                    pattern="\d{1,30}"
                    maxlength="30"
                    inputmode="numeric"
                    required
                >

                <button
                    type="button"
                    class="btn ghost"
                    onclick="buatKode()"
                    style="white-space:nowrap"
                >
                    Kode Acak
                </button>

            </div>

        </div>

        <!-- ==================================================
             NAMA / DESKRIPSI
        ================================================== -->

        <div>

            <small>
                Nama / Deskripsi Produk
            </small>

            <input
                name="nama"
                value="<?= h($e['nama'] ?? '') ?>"
                placeholder="Contoh: Apel Fuji"
                required
            >

        </div>

        <!-- ==================================================
             HARGA MODAL
        ================================================== -->

        <div>

            <small>
                Harga Modal
            </small>

            <input
                name="harga_modal"
                type="number"
                min="0"
                step="0.01"
                value="<?= h($e['harga_modal'] ?? 0) ?>"
                placeholder="Contoh: 10000"
                required
            >

        </div>

        <!-- ==================================================
             HARGA JUAL
        ================================================== -->

        <div>

            <small>
                Harga Jual
            </small>

            <input
                name="harga"
                type="number"
                min="0"
                step="0.01"
                value="<?= h($e['harga'] ?? 0) ?>"
                placeholder="Contoh: 15000"
                required
            >

        </div>

        <!-- ==================================================
             STOK AWAL
        ================================================== -->

        <div>

            <small>
                Stok Awal
            </small>

            <input
                name="stok"
                type="number"
                min="0"
                step="0.001"
                value="<?= h($e['stok'] ?? 0) ?>"
                placeholder="Contoh: 10.000"
                required

                <?= !empty($e['id'])
                    ? 'readonly'
                    : '' ?>
            >

        </div>

        <!-- ==================================================
             SATUAN
        ================================================== -->

        <div>

            <small>
                Satuan
            </small>

            <select
                name="satuan"
                required
            >

                <?php

                $satuanList = [

                    'kg'    => 'Kilogram (kg)',
                    'gram'  => 'Gram',
                    'buah'  => 'Buah',
                    'pcs'   => 'Pcs',
                    'sir'   => 'Sir',
                    'pak'   => 'Pak',
                    'liter' => 'Liter',
                    'lusin' => 'Lusin',
                    'ikat'  => 'Ikat'

                ];

                foreach (
                    $satuanList
                    as $key => $label
                ):

                ?>

                    <option
                        value="<?= h($key) ?>"

                        <?= (
                            ($e['satuan'] ?? 'kg')
                            === $key
                        )
                            ? 'selected'
                            : '' ?>
                    >

                        <?= h($label) ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <!-- ==================================================
             TOMBOL
        ================================================== -->

        <div>

            <small>
                Aksi
            </small>

            <div
                style="
                    display:flex;
                    gap:8px
                "
            >

                <button
                    class="btn"
                    type="submit"
                >

                    <?= (!empty($e['id']) && !$isKananAdmin)
                        ? 'Update Produk'
                        : 'Simpan Produk' ?>

                </button>

                <?php if (!empty($e['id']) && !$isKananAdmin): ?>

                    <a
                        href="produk.php"
                        class="btn ghost"
                        style="text-decoration:none"
                    >
                        Batal
                    </a>

                <?php endif; ?>

            </div>

        </div>

    </form>

    <div
        style="
            margin-top:10px;
            color:#6b7d73;
            font-size:.78rem
        "
    >

        Untuk barang timbangan gunakan kode PLU,
        misalnya <b>079631</b>,
        satuan <b>kg</b>.

        <br>

        Stok mendukung 3 angka desimal.

        <br>

        Harga modal digunakan untuk menghitung
        keuntungan produk.

        <?php if (!empty($e['id'])): ?>

            <br><br>

            <b>Catatan:</b>

            stok tidak diubah dari halaman produk.

            Gunakan menu

            <b>📦 Stok Masuk / Keluar</b>

            untuk perubahan stok.

        <?php endif; ?>

    </div>

</div>

<!-- ======================================================
     RINGKASAN STOK
====================================================== -->

<div class="stats">

    <div class="stat">

        <small>
            Total Stok Saat Ini
        </small>

        <b>

            <?= number_format(
                $totalStok,
                3,
                ',',
                '.'
            ) ?>

        </b>

    </div>

    <div class="stat">

        <small>
            Nilai Modal Stok
        </small>

        <b>

            <?= rp($totalNilaiModal) ?>

        </b>

    </div>

    <div class="stat">

        <small>
            Potensi Untung Stok
        </small>

        <b>

            <?= rp($totalPotensiUntung) ?>

        </b>

    </div>

    <div class="stat">

        <small>
            Jumlah Produk
        </small>

        <b>

            <?= count($list) ?>

        </b>

    </div>

</div>

<!-- ======================================================
     DAFTAR PRODUK
====================================================== -->

<div class="panel">

    <h3>
        📋 Daftar Produk
    </h3>

    <div class="tw">

        <table>

            <tr>

                <th>Kode / PLU</th>

                <th>Nama</th>

                <th>Harga Modal</th>

                <th>Harga Jual</th>

                <th>Untung / Unit</th>

                <th>Stok Masuk</th>

                <th>Stok Keluar</th>

                <th>Stok Saat Ini</th>

                <th>Nilai Modal</th>

                <th>Potensi Untung</th>

                <th>Status</th>

                <?php if (!$isKananAdmin): ?>
                    <th>Aksi</th>
                <?php endif; ?>

            </tr>

            <?php foreach ($list as $p): ?>

                <?php

                $stok =
                    (float)($p['stok'] ?? 0);

                $hargaModal =
                    (float)($p['harga_modal'] ?? 0);

                $hargaJual =
                    (float)($p['harga'] ?? 0);

                $profitUnit =
                    $hargaJual - $hargaModal;

                $nilaiModal =
                    $hargaModal * $stok;

                $potensiUntung =
                    $profitUnit * $stok;

                $tipis =
                    $stok <= 10;

                ?>

                <tr>

                    <!-- KODE -->

                    <td>

                        <code>
                            <?= h($p['kode']) ?>
                        </code>

                    </td>

                    <!-- NAMA -->

                    <td>

                        <b>
                            <?= h($p['nama']) ?>
                        </b>

                    </td>

                    <!-- HARGA MODAL -->

                    <td>

                        <?= rp($hargaModal) ?>

                        /

                        <?= h($p['satuan']) ?>

                    </td>

                    <!-- HARGA JUAL -->

                    <td>

                        <?= rp($hargaJual) ?>

                        /

                        <?= h($p['satuan']) ?>

                    </td>

                    <!-- UNTUNG -->

                    <td>

                        <?php if ($profitUnit < 0): ?>

                            <span class="tag low">

                                <?= rp($profitUnit) ?>

                            </span>

                        <?php else: ?>

                            <span class="tag">

                                <?= rp($profitUnit) ?>

                            </span>

                        <?php endif; ?>

                    </td>

                    <!-- MASUK -->

                    <td>

                        <span class="tag">

                            +

                            <?= number_format(
                                (float)(
                                    $p['total_masuk']
                                    ?? 0
                                ),
                                3,
                                ',',
                                '.'
                            ) ?>

                        </span>

                    </td>

                    <!-- KELUAR -->

                    <td>

                        <span class="tag">

                            -

                            <?= number_format(
                                (float)(
                                    $p['total_keluar']
                                    ?? 0
                                ),
                                3,
                                ',',
                                '.'
                            ) ?>

                        </span>

                    </td>

                    <!-- STOK -->

                    <td>

                        <span
                            class="tag <?= $tipis
                                ? 'low'
                                : '' ?>"
                            style="
                                font-weight:700;
                                <?= $tipis
                                    ? 'color:#d93a3a;background:#ffe9e9;'
                                    : '' ?>
                            "
                        >

                            <?= number_format(
                                $stok,
                                3,
                                ',',
                                '.'
                            ) ?>

                            <?= h(
                                $p['satuan']
                            ) ?>

                        </span>

                    </td>

                    <!-- NILAI MODAL -->

                    <td>

                        <?= rp($nilaiModal) ?>

                    </td>

                    <!-- POTENSI UNTUNG -->

                    <td>

                        <?php if ($potensiUntung < 0): ?>

                            <span class="tag low">

                                <?= rp($potensiUntung) ?>

                            </span>

                        <?php else: ?>

                            <span class="tag">

                                <?= rp($potensiUntung) ?>

                            </span>

                        <?php endif; ?>

                    </td>

                    <!-- STATUS -->

                    <td>

                        <?php if ($stok <= 0): ?>

                            <span class="tag low">
                                HABIS
                            </span>

                        <?php elseif ($tipis): ?>

                            <span class="tag low">
                                ⚠ MENIPIS
                            </span>

                        <?php else: ?>

                            <span class="tag">
                                AMAN
                            </span>

                        <?php endif; ?>

                    </td>

                    <!-- AKSI -->

                    <?php if (!$isKananAdmin): ?>
                    <td>

                        <a
                            href="?edit=<?= (int)$p['id'] ?>"
                        >
                            Edit
                        </a>

                        ·

                        <a
                            href="?del=<?= (int)$p['id'] ?>&amp;t=<?= security_csrf_token() ?>"
                            onclick="return confirm('Hapus produk ini?')"
                            class="red"
                        >
                            Hapus
                        </a>

                    </td>
                    <?php endif; ?>

                </tr>

            <?php endforeach; ?>

            <?php if (!$list): ?>

                <tr>

                    <td
                        colspan="<?= $isKananAdmin ? 11 : 12 ?>"
                        class="empty"
                    >

                        Belum ada produk.

                    </td>

                </tr>

            <?php endif; ?>

        </table>

    </div>

</div>

<script src="assets/produk.js?v=<?= (int)@filemtime(__DIR__ . '/assets/produk.js') ?>"></script>

<?php include 'footer.php'; ?>
