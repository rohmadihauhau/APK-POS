<?php
require_once 'config.php';

/*
|--------------------------------------------------------------------------
| CEK AKSES
|--------------------------------------------------------------------------
| Sistem login aplikasi menggunakan $_SESSION['u'] dan fungsi need().
*/
need('admin');

$u = $_SESSION['u'];

/*
|--------------------------------------------------------------------------
| FILTER TANGGAL
|--------------------------------------------------------------------------
*/
$tanggal_awal  = $_GET['tanggal_awal'] ?? date('Y-m-01');
$tanggal_akhir = $_GET['tanggal_akhir'] ?? date('Y-m-d');

/*
|--------------------------------------------------------------------------
| VALIDASI TANGGAL
|--------------------------------------------------------------------------
*/
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_awal)) {
    $tanggal_awal = date('Y-m-01');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_akhir)) {
    $tanggal_akhir = date('Y-m-d');
}

/*
|--------------------------------------------------------------------------
| DATA YANG DITARIK (detail / stok / header)
| Kosong = hanya ringkasan di atas yang tampil.
|--------------------------------------------------------------------------
*/
$tampil = $_GET['tampil'] ?? '';

if (!in_array($tampil, ['detail', 'stok', 'header'], true)) {
    $tampil = '';
}

/*
|--------------------------------------------------------------------------
| TOTAL TRANSAKSI
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT COUNT(*) AS total_transaksi
    FROM transaksi
    WHERE DATE(created_at) BETWEEN ? AND ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$total_transaksi = (int) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| TOTAL PENJUALAN
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT COALESCE(SUM(total), 0) AS total_penjualan
    FROM transaksi
    WHERE DATE(created_at) BETWEEN ? AND ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$total_penjualan = (float) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| TOTAL DISKON
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(diskon_nominal), 0)
    FROM transaksi
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$total_diskon = (float) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| TOTAL MODAL DAN PROFIT
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        COALESCE(SUM(detail.qty * detail.harga_modal), 0) AS total_modal,
        COALESCE(
                        SUM(
                detail.subtotal -
                (detail.qty * detail.harga_modal) -
                (CASE WHEN transaksi.subtotal > 0 THEN transaksi.diskon_nominal * detail.subtotal / transaksi.subtotal ELSE 0 END)
            ),
            0
        ) AS total_profit
    FROM detail
    INNER JOIN transaksi
        ON transaksi.id = detail.transaksi_id
    WHERE DATE(transaksi.created_at) BETWEEN ? AND ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

$total_modal = (float) ($summary['total_modal'] ?? 0);
$total_profit = (float) ($summary['total_profit'] ?? 0);

/*
|--------------------------------------------------------------------------
| STOK MASUK
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT COALESCE(SUM(qty), 0)
    FROM stok_log
    WHERE jenis = 'masuk'
      AND DATE(created_at) BETWEEN ? AND ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$total_stok_masuk = (float) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| STOK KELUAR
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT COALESCE(SUM(qty), 0)
    FROM stok_log
    WHERE jenis = 'keluar'
      AND DATE(created_at) BETWEEN ? AND ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$total_stok_keluar = (float) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| DATA PENJUALAN DETAIL
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        transaksi.id AS transaksi_id,
        transaksi.kode,
        transaksi.created_at,
        transaksi.total,
        transaksi.bayar,
        transaksi.kembali,
        transaksi.metode,

        users.nama AS kasir,

        detail.id AS detail_id,
        detail.produk_id,
        detail.nama AS nama_produk,
        detail.harga,
        detail.harga_modal,
        detail.qty,
                detail.subtotal,
        transaksi.diskon_persen,
        (CASE WHEN transaksi.subtotal > 0 THEN transaksi.diskon_nominal * detail.subtotal / transaksi.subtotal ELSE 0 END) AS diskon,
        produk.kode AS kode_produk,
        produk.satuan,
        (
            detail.subtotal -
            (detail.qty * detail.harga_modal) -
            (CASE WHEN transaksi.subtotal > 0 THEN transaksi.diskon_nominal * detail.subtotal / transaksi.subtotal ELSE 0 END)
        ) AS profit

    FROM detail

    INNER JOIN transaksi
        ON transaksi.id = detail.transaksi_id

    LEFT JOIN users
        ON users.id = transaksi.user_id

    LEFT JOIN produk
        ON produk.id = detail.produk_id

    WHERE DATE(transaksi.created_at) BETWEEN ? AND ?

    ORDER BY transaksi.created_at DESC, detail.id DESC
";

$data_penjualan = [];

if ($tampil === 'detail') {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tanggal_awal, $tanggal_akhir]);
    $data_penjualan = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| DATA STOK
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        stok_log.id,
        stok_log.produk_id,
        stok_log.jenis,
        stok_log.qty,
        stok_log.keterangan,
        stok_log.transaksi_id,
        stok_log.created_at,
        stok_log.stok_sebelum,
        stok_log.stok_sesudah,

        produk.kode AS kode_produk,
        produk.nama AS nama_produk,
        produk.satuan,

        users.nama AS nama_user

    FROM stok_log

    LEFT JOIN produk
        ON produk.id = stok_log.produk_id

    LEFT JOIN users
        ON users.id = stok_log.user_id

    WHERE DATE(stok_log.created_at) BETWEEN ? AND ?

    ORDER BY stok_log.created_at DESC, stok_log.id DESC
";

$data_stok = [];

if ($tampil === 'stok') {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tanggal_awal, $tanggal_akhir]);
    $data_stok = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| HEADER PENJUALAN
| Satu baris per transaksi, HANYA dari shift kasir yang sudah closing.
| Total dan profit langsung dihitung dari transaksi (setelah diskon).
|--------------------------------------------------------------------------
*/
$data_header = [];
$header_belum_closing = 0;

if ($tampil === 'header') {

    $sql = "
        SELECT
            transaksi.id,
            transaksi.kode,
            transaksi.created_at,
            transaksi.metode,
            transaksi.diskon_persen,
            transaksi.diskon_nominal,
            transaksi.total,
            (CASE WHEN transaksi.subtotal > 0
                  THEN transaksi.subtotal
                  ELSE transaksi.total END) AS subtotal_kotor,
            transaksi.shift_id,
            users.nama AS kasir,
            shift.buka AS shift_buka,
            shift.tutup AS shift_tutup,
            COALESCE(SUM(detail.qty * detail.harga_modal), 0) AS modal,
            COALESCE(SUM(detail.subtotal - (detail.qty * detail.harga_modal)), 0)
                - transaksi.diskon_nominal AS profit
        FROM transaksi
        INNER JOIN shift
            ON shift.id = transaksi.shift_id
           AND shift.status = 'tutup'
        LEFT JOIN users
            ON users.id = transaksi.user_id
        LEFT JOIN detail
            ON detail.transaksi_id = transaksi.id
        WHERE DATE(transaksi.created_at) BETWEEN ? AND ?
        GROUP BY
            transaksi.id, transaksi.kode, transaksi.created_at,
            transaksi.metode, transaksi.diskon_persen,
            transaksi.diskon_nominal, transaksi.total, transaksi.subtotal,
            transaksi.shift_id, users.nama, shift.buka, shift.tutup
        ORDER BY transaksi.created_at DESC, transaksi.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tanggal_awal, $tanggal_akhir]);
    $data_header = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Transaksi pada periode yang shift-nya BELUM closing (tidak dihitung) */
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM transaksi
        LEFT JOIN shift ON shift.id = transaksi.shift_id
        WHERE DATE(transaksi.created_at) BETWEEN ? AND ?
          AND (shift.id IS NULL OR shift.status <> 'tutup')
    ");
    $stmt->execute([$tanggal_awal, $tanggal_akhir]);
    $header_belum_closing = (int) $stmt->fetchColumn();
}

$hd_subtotal = $hd_diskon = $hd_total = $hd_modal = $hd_profit = 0;

foreach ($data_header as $h) {
    $hd_subtotal += (float) $h['subtotal_kotor'];
    $hd_diskon   += (float) $h['diskon_nominal'];
    $hd_total    += (float) $h['total'];
    $hd_modal    += (float) $h['modal'];
    $hd_profit   += (float) $h['profit'];
}

/*
|--------------------------------------------------------------------------
| FORMAT RUPIAH
|--------------------------------------------------------------------------
*/
function rupiah($angka)
{
    return 'Rp ' . number_format(
        (float) $angka,
        0,
        ',',
        '.'
    );
}

/*
|--------------------------------------------------------------------------
| FORMAT QTY
|--------------------------------------------------------------------------
*/
function format_qty($angka)
{
    return rtrim(
        rtrim(
            number_format(
                (float) $angka,
                3,
                ',',
                '.'
            ),
            '0'
        ),
        ','
    );
}
?>

<?php include 'header.php'; ?>

<link rel="stylesheet" href="assets/data.css?v=<?= (int)@filemtime(__DIR__ . '/assets/data.css') ?>">

<div class="container">

    <!-- HEADER -->

    <div class="header">

        <h1>
            Data Penjualan & Stok
        </h1>

        <p>
            Laporan periode
            <?= htmlspecialchars($tanggal_awal) ?>
            s/d
            <?= htmlspecialchars($tanggal_akhir) ?>
        </p>

    </div>

    <!-- FILTER -->

    <div class="filter-box">

        <form
            method="GET"
            class="filter-form"
        >

            <div class="form-group">

                <label>
                    Tanggal Awal
                </label>

                <input
                    type="date"
                    name="tanggal_awal"
                    value="<?= htmlspecialchars($tanggal_awal) ?>"
                    required
                >

            </div>

            <div class="form-group">

                <label>
                    Tanggal Akhir
                </label>

                <input
                    type="date"
                    name="tanggal_akhir"
                    value="<?= htmlspecialchars($tanggal_akhir) ?>"
                    required
                >

            </div>

            <button
                type="submit"
                class="btn btn-primary"
            >
                Tampilkan
            </button>

            <button
                type="submit"
                name="tampil"
                value="detail"
                class="btn btn-detail <?= $tampil === 'detail' ? 'aktif' : '' ?>"
            >
                📄 Detail Penjualan
            </button>

            <button
                type="submit"
                name="tampil"
                value="stok"
                class="btn btn-stok <?= $tampil === 'stok' ? 'aktif' : '' ?>"
            >
                📦 Riwayat Stok
            </button>

            <button
                type="submit"
                name="tampil"
                value="header"
                class="btn btn-header <?= $tampil === 'header' ? 'aktif' : '' ?>"
            >
                🧾 Header Penjualan
            </button>

            <button
                type="button"
                class="btn btn-print"
                onclick="cetakData()"
            >
                🖨 Cetak / PDF
            </button>

        </form>

    </div>

    <!-- SUMMARY -->

    <div class="summary-grid">

        <div class="summary-card">

            <div class="summary-title">
                TOTAL TRANSAKSI
            </div>

            <div class="summary-value">
                <?= number_format($total_transaksi, 0, ',', '.') ?>
            </div>

            <div class="summary-sub">
                Transaksi penjualan
            </div>

        </div>

        <div class="summary-card">

            <div class="summary-title">
                TOTAL PENJUALAN
            </div>

            <div class="summary-value">
                <?= rupiah($total_penjualan) ?>
            </div>

                        <div class="summary-sub">
                Omzet (setelah diskon)
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-title">
                TOTAL DISKON
            </div>
            <div class="summary-value">
                <?= rupiah($total_diskon) ?>
            </div>
            <div class="summary-sub">
                Potongan penjualan
            </div>
        </div>

        <div class="summary-card">

            <div class="summary-title">
                TOTAL MODAL
            </div>

            <div class="summary-value">
                <?= rupiah($total_modal) ?>
            </div>

            <div class="summary-sub">
                Harga modal
            </div>

        </div>

        <div class="summary-card">

            <div class="summary-title">
                TOTAL PROFIT
            </div>

            <div class="summary-value profit">
                <?= rupiah($total_profit) ?>
            </div>

            <div class="summary-sub">
                                Laba (setelah diskon)

            </div>

        </div>

        <div class="summary-card">

            <div class="summary-title">
                STOK MASUK / KELUAR
            </div>

            <div class="summary-value">

                <?= format_qty($total_stok_masuk) ?>
                /
                <?= format_qty($total_stok_keluar) ?>

            </div>

            <div class="summary-sub">
                Total quantity
            </div>

        </div>

    </div>

<?php if ($tampil === ''): ?>
    <div class="table-box">
        <div class="empty">
            Pilih <b>Detail Penjualan</b>, <b>Riwayat Stok</b>, atau <b>Header Penjualan</b>
            di atas untuk menarik datanya.
        </div>
    </div>
<?php endif; ?>

<?php if ($tampil === 'detail'): ?>

    <!-- PENJUALAN -->

    <div class="table-box">

        <div class="table-header">

            <h2>
                Detail Penjualan
            </h2>

        </div>

        <div class="table-wrapper">

            <?php if (empty($data_penjualan)): ?>

                <div class="empty">
                    Tidak ada data penjualan pada periode ini.
                </div>

            <?php else: ?>

                <table>

                    <thead>

                    <tr>

                        <th>No</th>

                        <th>Tanggal</th>

                        <th>Kode Transaksi</th>

                        <th>Kasir</th>

                        <th>Kode Produk</th>

                        <th>Produk</th>

                        <th>Satuan</th>

                        <th class="text-right">
                            Qty
                        </th>

                        <th class="text-right">
                            Harga
                        </th>

                        <th class="text-right">
                            Modal
                        </th>

                                                <th class="text-right">
                            Subtotal
                        </th>
                        <th class="text-right">
                            Diskon
                        </th>

                        <th class="text-right">
                            Profit
                        </th>

                        <th>
                            Metode
                        </th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php
                    $no = 1;

                    foreach ($data_penjualan as $row):

                        $metode = strtolower(
                            $row['metode'] ?? ''
                        );

                        $badge_class = 'badge-other';

                        if ($metode === 'tunai') {
                            $badge_class = 'badge-tunai';
                        } elseif ($metode === 'qris') {
                            $badge_class = 'badge-qris';
                        }
                    ?>

                        <tr>

                            <td>
                                <?= $no++ ?>
                            </td>

                            <td>
                                <?= date(
                                    'd-m-Y H:i',
                                    strtotime($row['created_at'])
                                ) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= htmlspecialchars(
                                        $row['kode'] ?? '-'
                                    ) ?>
                                </strong>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $row['kasir'] ?? '-'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $row['kode_produk'] ?? '-'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $row['nama_produk']
                                    ?? $row['nama']
                                    ?? '-'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $row['satuan'] ?? '-'
                                ) ?>
                            </td>

                            <td class="text-right">
                                <?= format_qty($row['qty']) ?>
                            </td>

                            <td class="text-right">
                                <?= rupiah($row['harga']) ?>
                            </td>

                            <td class="text-right">
                                <?= rupiah($row['harga_modal']) ?>
                            </td>

                                                        <td class="text-right">
                                <?= rupiah($row['subtotal']) ?>
                            </td>
                            <td class="text-right">
                                <?php if ((float) $row['diskon'] > 0.004): ?>
                                    <?= rtrim(rtrim(number_format((float) $row['diskon_persen'], 2, ',', '.'), '0'), ',') ?>%
                                    (-<?= rupiah($row['diskon']) ?>)
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>

                            <td class="text-right profit">

                                <?= rupiah(
                                    $row['profit']
                                ) ?>

                            </td>

                            <td>

                                <span
                                    class="badge <?= $badge_class ?>"
                                >

                                    <?= htmlspecialchars(
                                        strtoupper(
                                            $row['metode']
                                            ?? '-'
                                        )
                                    ) ?>

                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>

    </div>

    <?php endif; ?>

<?php if ($tampil === 'stok'): ?>

    <!-- STOK -->

    <div class="table-box">

        <div class="table-header">

            <h2>
                Riwayat Stok
            </h2>

        </div>

        <div class="table-wrapper">

            <?php if (empty($data_stok)): ?>

                <div class="empty">
                    Tidak ada riwayat stok pada periode ini.
                </div>

            <?php else: ?>

                <table>

                    <thead>

                    <tr>

                        <th>No</th>

                        <th>Tanggal</th>

                        <th>Kode Produk</th>

                        <th>Produk</th>

                        <th>Satuan</th>

                        <th>Jenis</th>

                        <th class="text-right">
                            Qty
                        </th>

                        <th class="text-right">
                            Stok Sebelum
                        </th>

                        <th class="text-right">
                            Stok Sesudah
                        </th>

                        <th>Keterangan</th>

                        <th>User</th>

                        <th>Transaksi</th>

                    </tr>

                    </thead>

                    <tbody>

                    <?php
                    $no = 1;

                    foreach ($data_stok as $row):
                    ?>

                        <tr>

                            <td>
                                <?= $no++ ?>
                            </td>

                            <td>
                                <?= date(
                                    'd-m-Y H:i',
                                    strtotime(
                                        $row['created_at']
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $row['kode_produk']
                                    ?? '-'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $row['nama_produk']
                                    ?? '-'
                                ) ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    $row['satuan']
                                    ?? '-'
                                ) ?>
                            </td>

                            <td>

                                <?php if (
                                    $row['jenis']
                                    === 'masuk'
                                ): ?>

                                    <span class="masuk">
                                        MASUK
                                    </span>

                                <?php else: ?>

                                    <span class="keluar">
                                        KELUAR
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td class="text-right">

                                <?= format_qty(
                                    $row['qty']
                                ) ?>

                            </td>

                            <td class="text-right">

                                <?= format_qty(
                                    $row['stok_sebelum']
                                ) ?>

                            </td>

                            <td class="text-right">

                                <?= format_qty(
                                    $row['stok_sesudah']
                                ) ?>

                            </td>

                            <td>

                                <?= htmlspecialchars(
                                    $row['keterangan']
                                    ?? '-'
                                ) ?>

                            </td>

                            <td>

                                <?= htmlspecialchars(
                                    $row['nama_user']
                                    ?? '-'
                                ) ?>

                            </td>

                            <td>

                                <?= htmlspecialchars(
                                    $row['transaksi_id']
                                    ?? '-'
                                ) ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            <?php endif; ?>

        </div>

    </div>

<?php endif; ?>

<?php if ($tampil === 'header'): ?>

    <!-- HEADER PENJUALAN (shift sudah closing) -->

    <div class="table-box">

        <div class="table-header">

            <h2>Header Penjualan <small style="font-weight:normal;color:#777;font-size:12px">&mdash; hanya kasir yang sudah closing</small></h2>

            <?php if ($header_belum_closing > 0): ?>
                <div style="margin-top:6px;font-size:12px;color:#b45309">
                    <?= (int) $header_belum_closing ?> transaksi pada periode ini belum closing, tidak dihitung di sini.
                </div>
            <?php endif; ?>

        </div>

        <div class="table-wrapper">

            <?php if (empty($data_header)): ?>

                <div class="empty">
                    Tidak ada transaksi dari shift yang sudah closing pada periode ini.
                </div>

            <?php else: ?>

                <table>

                    <thead>
                    <tr>
                        <th>No</th>
                        <th>Tanggal</th>
                        <th>Kode Transaksi</th>
                        <th>Kasir</th>
                        <th>Shift</th>
                        <th class="text-right">Subtotal</th>
                        <th class="text-right">Diskon</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Modal</th>
                        <th class="text-right">Profit</th>
                        <th>Metode</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($data_header as $i => $h):

                        $m = strtolower($h['metode'] ?? '');
                        $bc = $m === 'tunai' ? 'badge-tunai' : ($m === 'qris' ? 'badge-qris' : 'badge-other');
                    ?>

                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= date('d-m-Y H:i', strtotime($h['created_at'])) ?></td>
                            <td><strong><?= htmlspecialchars($h['kode'] ?? '-') ?></strong></td>
                            <td><?= htmlspecialchars($h['kasir'] ?? '-') ?></td>
                            <td>#<?= (int) $h['shift_id'] ?></td>
                            <td class="text-right"><?= rupiah($h['subtotal_kotor']) ?></td>
                            <td class="text-right">
                                <?php if ((float) $h['diskon_nominal'] > 0): ?>
                                    <?= rtrim(rtrim(number_format((float) $h['diskon_persen'], 2, ',', '.'), '0'), ',') ?>%
                                    (-<?= rupiah($h['diskon_nominal']) ?>)
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="text-right"><strong><?= rupiah($h['total']) ?></strong></td>
                            <td class="text-right"><?= rupiah($h['modal']) ?></td>
                            <td class="text-right profit"><?= rupiah($h['profit']) ?></td>
                            <td><span class="badge <?= $bc ?>"><?= htmlspecialchars(strtoupper($h['metode'] ?? '-')) ?></span></td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                    <tfoot>
                        <tr style="background:#f8f9fc;font-weight:bold">
                            <td colspan="5" class="text-right">TOTAL (<?= count($data_header) ?> transaksi)</td>
                            <td class="text-right"><?= rupiah($hd_subtotal) ?></td>
                            <td class="text-right">-<?= rupiah($hd_diskon) ?></td>
                            <td class="text-right"><?= rupiah($hd_total) ?></td>
                            <td class="text-right"><?= rupiah($hd_modal) ?></td>
                            <td class="text-right profit"><?= rupiah($hd_profit) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>

                </table>

            <?php endif; ?>

        </div>

    </div>

<?php endif; ?>

</div>

<?php include 'footer.php'; ?>

<script>var TAMPIL = <?= json_encode($tampil) ?>;</script>
<script src="assets/data.js?v=<?= (int)@filemtime(__DIR__ . '/assets/data.js') ?>"></script>
