<?php

require 'config.php';

/* Role kanan_admin tidak boleh mengakses halaman kasir */
if (!empty($_SESSION['u']) && (($_SESSION['u']['role'] ?? '') === 'kanan_admin')) {
    header('Location: produk.php');
    exit;
}

/* ============================================================
   KELUAR
   ============================================================ */

if (isset($_GET['keluar'])) {

    unset(
        $_SESSION['k'],
        $_SESSION['u']
    );

    header('Location: index.php');
    exit;

}

/* ============================================================
   FLASH
   ============================================================ */

$err  = '';
$info = '';

$fl = $_SESSION['flash'] ?? null;

unset($_SESSION['flash']);

if ($fl) {

    $info =
        'Closing berhasil. Laporan PDF sudah dibuat otomatis.';

    if (($fl['minus'] ?? 0) > 0) {

        $err =
            'Terdapat selisih KURANG ' .
            rp($fl['minus']) .
            ' dan sudah tercatat di laporan.';

    }

    if (($fl['lebih'] ?? 0) > 0) {

        $info =
            'Closing berhasil. Selisih LEBIH ' .
            rp($fl['lebih']) .
            '. Laporan PDF sudah dibuat otomatis.';

    }

    if (($fl['sesuai'] ?? false)) {

        $info =
            'Closing berhasil. Kas sesuai. Laporan PDF sudah dibuat otomatis.';

    }

}

/* ============================================================
   LOGIN KASIR
   ============================================================ */

if (isset($_POST['kid'])) {

    $s = $pdo->prepare("
        SELECT *
        FROM users
        WHERE username=?
    ");

    $s->execute([
        trim($_POST['kid'])
    ]);

    $u = $s->fetch();

    if (
        $u &&
        password_verify(
            $_POST['kpw'],
            $u['password']
        )
    ) {

        $err = kasir_masuk(
            $pdo,
            $u
        );

        if (!$err) {

            header('Location: index.php');
            exit;

        }

    } else {

        $err = 'ID atau password salah';

    }

}

/* ============================================================
   KASIR AKTIF
   ============================================================ */

$k = kasir_aktif($pdo);

/* ============================================================
   RINGKASAN SHIFT - AJAX
   ============================================================ */

if (
    $k &&
    isset($_GET['ringkas'])
) {

    header(
        'Content-Type: application/json'
    );

    $r = ringkas(
        $pdo,
        $k['shift']
    );

    echo json_encode([

        'ok' =>
            1,

        'n' =>
            (int)$r['n'],

        'tunai' =>
            (float)$r['t'],

        'qris' =>
            (float)$r['q'],

        'modal' =>
            $r['modal'] === null
                ? null
                : (float)$r['modal'],

        'seharusnya' =>
            (float)$r['seharusnya']

    ]);

    exit;

}

/* ============================================================
   SIMPAN MODAL
   ============================================================ */

if (
    $k &&
    ($_POST['aksi'] ?? '') === 'modal'
) {

    header(
        'Content-Type: application/json'
    );

    $m =
        $_POST['modal'] ?? '';

    if (!ctype_digit($m)) {

        http_response_code(400);

        echo json_encode([

            'ok'  => 0,
            'msg' => 'Isi uang modal terlebih dahulu'

        ]);

        exit;

    }

    $pdo
        ->prepare("
            UPDATE shift
            SET modal=?
            WHERE id=?
              AND modal IS NULL
        ")
        ->execute([

            (int)$m,
            $k['shift']

        ]);

    echo json_encode([
        'ok' => 1
    ]);

    exit;

}

/* ============================================================
   CLOSING - CEK SELISIH DULU
   ============================================================ */

if (
    $k &&
    ($_POST['aksi'] ?? '') === 'closing'
) {

    header(
        'Content-Type: application/json'
    );

    $pendapatan =
        $_POST['pendapatan'] ?? '';

    if (
        $pendapatan === '' ||
        !is_numeric($pendapatan) ||
        $pendapatan < 0
    ) {

        http_response_code(400);

        echo json_encode([

            'ok'  => 0,
            'msg' => 'Isi total uang kas akhir terlebih dahulu'

        ]);

        exit;

    }

    $pendapatan =
        (int)$pendapatan;

    $r =
        ringkas(
            $pdo,
            $k['shift']
        );

    $seharusnya =
        (float)$r['seharusnya'];

    $selisih =
        $pendapatan -
        $seharusnya;

    $lanjut =
        isset($_POST['lanjut']) &&
        $_POST['lanjut'] === '1';

    if (
        $selisih != 0 &&
        !$lanjut
    ) {

        echo json_encode([

            'ok' =>
                1,

            'confirm' =>
                1,

            'selisih' =>
                $selisih,

            'status' =>
                $selisih > 0
                    ? 'lebih'
                    : 'kurang',

            'modal' =>
                (float)$r['modal'],

            'tunai' =>
                (float)$r['t'],

            'qris' =>
                (float)$r['q'],

            'seharusnya' =>
                $seharusnya,

            'input' =>
                $pendapatan

        ]);

        exit;

    }

    $sel =
        tutup_shift(
            $pdo,
            $k['shift'],
            $pendapatan
        );

    $_SESSION['flash'] = [

        'minus' =>
            $sel < 0
                ? -$sel
                : 0,

        'lebih' =>
            $sel > 0
                ? $sel
                : 0,

        'sesuai' =>
            $sel == 0

    ];

    unset(
        $_SESSION['k']
    );

    echo json_encode([

        'ok' =>
            1,

        'closed' =>
            1,

        'selisih' =>
            $sel

    ]);

    exit;

}

/* ============================================================
   CSS
   ============================================================ */

$css =
    'assets/style.css?v=' .
    filemtime(
        __DIR__ . '/assets/style.css'
    );

/* ============================================================
   LOGIN SCREEN
   ============================================================ */

if (!$k) {

?>

<!DOCTYPE html>

<html lang="id">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<meta
    name="theme-color"
    content="#16794c"
>

<title>
    Login Kasir - <?= h(NAMA_TOKO) ?>
</title>

<link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
    rel="stylesheet" media="print" onload="this.media='all'"
>

<link
    rel="stylesheet"
    href="<?= $css ?>"
>

<link rel="stylesheet" href="assets/tablet.css?v=<?= @filemtime(__DIR__.'/assets/tablet.css') ?>">
</head>

<body class="login-bg">

<form
    method="post"
    class="login-card"
>

<div class="logo">
    🔐
</div>

<h1>
    Login Kasir
</h1>

<p>
    Masukkan ID dan password untuk membuka kasir
</p>

<?php if ($err): ?>

<div class="alert">
    <?= h($err) ?>
</div>

<?php endif; ?>

<?php if ($info): ?>

<div class="alert okmsg">
    <?= h($info) ?>
</div>

<?php endif; ?>

<label>
    ID User
</label>

<input
    name="kid"
    required
    autofocus
    autocomplete="off"
    placeholder="ID user"
>

<label>
    Password
</label>

<input
    type="password"
    name="kpw"
    required
    placeholder="••••••••"
>

<button class="btn">
    Masuk Kasir
</button>

<p style="margin:16px 0 0">

<a
    href="login.php"
    style="color:#7b8b83;font-size:.78rem"
>
    Panel admin
</a>

</p>

</form>

<script src="assets/app.js"></script>
</body>

</html>

<?php

exit;

}

/* ============================================================
   DATA PRODUK
   ============================================================ */

$produk = array_map(

    fn($p) => [

        /*
         * ID tetap integer karena primary key.
         */

        'id' =>
            (int)$p['id'],

        /*
         * KODE WAJIB STRING.
         *
         * Supaya 079631 tidak berubah
         * menjadi 79631.
         */

        'kode' =>
            (string)$p['kode'],

        'nama' =>
            (string)$p['nama'],

        /*
         * Harga uang boleh decimal.
         */

        'harga' =>
            (float)$p['harga'],

        /*
         * Stok menggunakan 3 angka decimal.
         */

        'stok' =>
            round(
                (float)$p['stok'],
                3
            ),

        'satuan' =>
            (string)$p['satuan']

    ],

    $pdo
        ->query("
            SELECT
                id,
                kode,
                nama,
                harga,
                stok,
                satuan
            FROM produk
            ORDER BY nama
        ")
        ->fetchAll()

);

/* ============================================================
   DATA SHIFT
   ============================================================ */

$b = $pdo->prepare("
    SELECT
        buka,
        modal
    FROM shift
    WHERE id=?
");

$b->execute([
    $k['shift']
]);

$sh =
    $b->fetch();

$buka =
    $sh['buka'];

$modal =
    $sh['modal'];

?>

<!DOCTYPE html>

<html lang="id">

<head>

<meta charset="utf-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1,viewport-fit=cover"
>

<meta
    name="theme-color"
    content="#0f3d2a"
>

<meta
    name="manifest"
    content="manifest.json"
>

<title>
    Kasir - <?= h(NAMA_TOKO) ?>
</title>

<link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
    rel="stylesheet" media="print" onload="this.media='all'"
>

<link
    rel="stylesheet"
    href="<?= $css ?>"
>

<link rel="stylesheet" href="assets/kasir.css?v=<?= (int)@filemtime(__DIR__ . '/assets/kasir.css') ?>">

<link rel="stylesheet" href="assets/tablet.css?v=<?= @filemtime(__DIR__.'/assets/tablet.css') ?>">
</head>

<body>

<div class="pos">

<!-- ============================================================
     HEADER
     ============================================================ -->

<header class="tb">

<div class="tl">

<b>
    🍉 <?= h(NAMA_TOKO) ?>
</b>

<span class="chip">
    👤 <?= h($k['nama']) ?>
</span>

<span class="chip">
    Shift #<?= $k['shift'] ?>
    · sejak <?= date('H:i',strtotime($buka)) ?>
</span>

</div>

<div class="tr">

<span id="clk"></span>

<button
    class="btn ghost"
    onclick="closing()"
>
    🔒 Closing
</button>

</div>

</header>

<!-- ============================================================
     BODY
     ============================================================ -->

<div class="bd">

<section class="lf">

<!-- Toggle Mode: Scan / Manual -->
<div class="mode-toggle" id="modeToggle">

<button
    id="btnModeScan"
    class="mode-btn active"
    onclick="setMode('scan')"
>
    📡 Scan
</button>

<button
    id="btnModeManual"
    class="mode-btn"
    onclick="setMode('manual')"
>
    ✏️ Manual
</button>

</div>

<!-- ============= PANEL SCAN ============= -->
<div id="panelScan">

<div class="scanbar">

<span class="dot"></span>

<input
    id="scan"
    autofocus
    autocomplete="off"
    <?= $modal===null ? 'disabled' : '' ?>
    placeholder="Scan barcode atau ketik nama buah, tekan Enter"
>

<button
    class="btn"
    onclick="cam()"
>
    📷 Kamera
</button>

</div>

<div
    class="sug"
    id="sug"
></div>

<div
    class="last"
    id="last"
>

<span>
    Siap scan barcode
</span>

</div>

</div><!-- end #panelScan -->

<!-- ============= PANEL MANUAL ============= -->
<div id="panelManual" style="display:none">

<div class="manual-form">

<div class="mf-row">

<div class="mf-field">
    <label>Kode Produk</label>
    <input
        id="mKode"
        autocomplete="off"
        placeholder="Ketik kode lalu tekan Enter"
        <?= $modal===null ? 'disabled' : '' ?>
    >
    <div class="mf-hint" id="mKodeHint"></div>
</div>

<div class="mf-field">
    <label>Nama Produk</label>
    <input
        id="mNama"
        readonly
        placeholder="Otomatis dari kode"
        tabindex="-1"
    >
</div>

</div>

<div class="mf-row">

<div class="mf-field">
    <label>Harga Satuan</label>
    <input
        id="mHarga"
        readonly
        placeholder="Otomatis"
        tabindex="-1"
    >
</div>

<div class="mf-field mf-small">
    <label>Satuan</label>
    <input
        id="mSatuan"
        readonly
        placeholder="-"
        tabindex="-1"
    >
</div>

<div class="mf-field mf-small">
    <label>Qty</label>
    <input
        id="mQty"
        type="number"
        min="0.001"
        step="any"
        placeholder="1"
        autocomplete="off"
        <?= $modal===null ? 'disabled' : '' ?>
    >
</div>

<div class="mf-field">
    <label>Subtotal</label>
    <input
        id="mSub"
        readonly
        placeholder="Rp 0"
        tabindex="-1"
        class="mf-sub"
    >
</div>

</div>

<div class="mf-actions">

<button
    class="btn mf-btn-add"
    id="mBtnAdd"
    onclick="manualAdd()"
    <?= $modal===null ? 'disabled' : '' ?>
>
    ＋ Tambah ke Keranjang
</button>

<div class="last" id="lastManual" style="flex:1;min-width:0">
    <span>Masukkan kode untuk mulai</span>
</div>

</div>

</div>

</div><!-- end #panelManual -->

<div class="tbl">

<table>

<thead>

<tr>

<th>#</th>
<th>Kode</th>
<th>Nama</th>
<th>Harga</th>
<th>Qty</th>
<th>Subtotal</th>
<th></th>

</tr>

</thead>

<tbody id="rows"></tbody>

</table>

</div>

</section>

<!-- ============================================================
     PEMBAYARAN
     ============================================================ -->

<aside class="cart">

<div class="ch">

<h3>
    Pembayaran
</h3>

<button
    class="lnk"
    onclick="clr()"
>
    Batalkan
</button>

</div>

<div
    class="tot"
    style="margin-top:12px"
>

<small id="cnt">
    0 item
</small>

<div>

<span>
    Total
</span>

<b id="total">
    Rp 0
</b>

</div>

</div>

<div class="mt">

<button
    id="m1"
    class="on"
    onclick="setM('tunai')"
>
    💵 Tunai
</button>

<button
    id="m2"
    onclick="setM('qris')"
>
    📱 QRIS
</button>

</div>

<div id="cash">

<input
    id="bayar"
    inputmode="numeric"
    autocomplete="off"
    placeholder="Rp 0"
>

<div
    class="quick"
    id="quick"
></div>

<div class="kem">

<span>
    Kembalian
</span>

<b id="kembali">
    Rp 0
</b>

</div>

</div>

<!-- ============================================================
     DISKON
     ============================================================ -->

<div class="disc">

<div class="dh">
    <span>🏷️ Diskon</span>
    <b id="dlabel">Tanpa diskon</b>
</div>

<div class="dg" id="dgrid"></div>

<div class="dr hide" id="drsub">
    <span>Subtotal</span>
    <span id="dsub">Rp 0</span>
</div>

<div class="dr hide" id="drdis">
    <span>Potongan diskon</span>
    <b id="dnom">- Rp 0</b>
</div>

</div>

<button
    class="btn big"
    id="pay"
>
    Bayar <kbd>Enter</kbd>
</button>

</aside>

</div>

<div class="ft">
    F2 Scan &middot; Ctrl+M Manual &middot; F1 Transaksi Baru &middot; Enter Bayar
</div>

</div>

<!-- ============================================================
     TOAST
     ============================================================ -->

<div
    class="toast"
    id="toast"
></div>

<!-- ============================================================
     QRIS
     ============================================================ -->

<div
    class="modal"
    id="qris"
>

<div class="box">

<h3>
    Scan QRIS
</h3>

<div
    class="qt"
    id="qtot"
></div>

<div
    id="qimg"
    class="qimg"
></div>

<p class="sm">

Minta pelanggan scan,
lalu cek notifikasi pembayaran masuk.

</p>

<button
    class="btn"
    onclick="send()"
>
    ✔ Pembayaran Diterima
</button>

<button
    class="btn ghost"
    onclick="$('qris').classList.remove('show')"
>
    Batal
</button>

</div>

</div>

<!-- ============================================================
     PEMBAYARAN BERHASIL
     ============================================================ -->

<div
    class="modal"
    id="done"
>

<div class="box">

<svg
    class="ck"
    viewBox="0 0 52 52"
>

<circle
    cx="26"
    cy="26"
    r="24"
/>

<path
    d="M14 27l8 8 16-17"
/>

</svg>

<h2>
    Pembayaran Berhasil
</h2>

<p id="dinfo"></p>

<button
    class="btn"
    id="btnCetakStruk"
    onclick="window.print()"
>
    🖨️ Cetak Struk
</button>

<button
    class="btn ghost"
    id="btnTransaksiBaru"
    onclick="transaksiBaruDenganMode()"
>
    🔄 Transaksi Baru <kbd>F1</kbd>
</button>

</div>

</div>

<!-- ============================================================
     POPUP CLOSING
     ============================================================ -->

<div
    class="modal"
    id="clos"
>

<div class="box">

<h2>
    🔒 Closing Kasir
</h2>

<p
    class="sm"
    style="margin:10px 0"
>

Jumlah transaksi shift ini:

<b id="cn">
    0
</b>

</p>

<div
    id="closingInfo"
    class="sm"
    style="text-align:left;margin:12px 0"
>
</div>

<label
    class="sm"
    style="display:block;text-align:left;margin:14px 0 6px"
>
    Uang Kas Akhir (Rp)
</label>

<input
    id="pend"
    inputmode="numeric"
    autocomplete="off"
    placeholder="Rp 0"
    style="width:100%;font-size:1.4rem;font-weight:700;text-align:center"
>

<p
    class="sm"
    style="margin:12px 0 16px"
>

Masukkan seluruh uang fisik yang ada
di laci saat closing, termasuk modal awal
dan penjualan tunai.

QRIS tidak dihitung sebagai uang fisik.

</p>

<div
    id="selisihConfirm"
    style="display:none"
>

<div
    id="selisihBox"
    class="selisih-box"
>

<h3 id="selisihTitle">
    Ada Selisih
</h3>

<div
    class="nominal"
    id="selisihNominal"
>
    Rp 0
</div>

<div
    class="selisih-info"
    id="selisihDetail"
>
</div>

</div>

<div class="close-actions">

<button
    class="btn ghost"
    onclick="ubahNominal()"
>
    ✏️ Ubah Nominal
</button>

<button
    class="btn"
    onclick="lanjutClosing()"
>
    ✅ Lanjut Closing
</button>

</div>

</div>

<button
    class="btn"
    id="btnClosing"
    onclick="doClose(false)"
    style="width:100%"
>
    Closing Sekarang
</button>

<button
    class="btn ghost"
    onclick="$('clos').classList.remove('show')"
    style="width:100%;margin-top:8px"
>
    Batal
</button>

</div>

</div>

<!-- ============================================================
     MODAL UANG AWAL
     ============================================================ -->

<div
    class="modal"
    id="mdl"
>

<div class="box">

<div style="font-size:2.6rem">
    💰
</div>

<h2>
    Uang Modal Awal
</h2>

<p
    class="sm"
    style="margin:8px 0 16px"
>

Masukkan uang modal di laci kasir
sebelum mulai transaksi.

</p>

<input
    id="mval"
    class="rpin"
    inputmode="numeric"
    autocomplete="off"
    placeholder="Rp 0"
>

<div
    class="quick"
    style="justify-content:center;margin:14px 0"
>

<button onclick="setMv(100000)">
    Rp 100.000
</button>

<button onclick="setMv(200000)">
    Rp 200.000
</button>

<button onclick="setMv(500000)">
    Rp 500.000
</button>

</div>

<button
    class="btn big"
    style="margin-top:6px"
    onclick="saveModal()"
>
    Mulai Kasir
</button>

<p
    class="sm"
    style="margin-top:12px"
>

Kasir:
<?= h($k['nama']) ?>

</p>

</div>

</div>

<!-- ============================================================
     CAMERA
     ============================================================ -->

<div
    class="modal"
    id="camw"
>

<div class="box cambox">

<div class="camv">

<video
    id="vid"
    playsinline
    muted
    autoplay
></video>

<div class="camline"></div>

<div class="camf" id="camf"></div>

</div>

<div class="camst" id="camst">
    Arahkan barcode ke garis tengah
</div>

<p class="sm camtip">
    Dekatkan label sampai barcode memenuhi lebar layar.
    Barcode terbaca otomatis dan langsung masuk keranjang,
    lanjut label berikutnya tanpa menutup kamera.
</p>

<div class="camfoot">

<span class="sm" id="camtot">
    0 item
</span>

<button
    class="btn"
    id="camx"
>
    Selesai
</button>

</div>

</div>

</div>

<div id="rcp"></div>

<script src="assets/ean-scan.js?v=<?= (int)@filemtime(__DIR__ . '/assets/ean-scan.js') ?>"></script>

<script>
const P = <?= json_encode($produk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const Q = <?= json_encode(file_exists('assets/qris.png') ? 'assets/qris.png' : '') ?>;
const NAMA = <?= json_encode(NAMA_TOKO, JSON_UNESCAPED_UNICODE) ?>;
const DISKON_LIST = <?= json_encode(diskon_pilihan()) ?>;
const MODAL_KOSONG = <?= json_encode($modal === null) ?>;
</script>
<script src="assets/kasir.js?v=<?= (int)@filemtime(__DIR__ . '/assets/kasir.js') ?>"></script>

<script src="assets/app.js"></script>
</body>

</html>
