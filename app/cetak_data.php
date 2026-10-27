<?php
require_once 'config.php';

need('admin');

$tanggal_awal  = $_GET['tanggal_awal'] ?? date('Y-m-01');
$tanggal_akhir = $_GET['tanggal_akhir'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_awal)) {
    $tanggal_awal = date('Y-m-01');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_akhir)) {
    $tanggal_akhir = date('Y-m-d');
}

/*
|--------------------------------------------------------------------------
| DATA YANG DITARIK (detail / stok / header)
| Kosong = cetak semua bagian.
|--------------------------------------------------------------------------
*/
$tampil = $_GET['tampil'] ?? '';

if (!in_array($tampil, ['detail', 'stok', 'header'], true)) {
    $tampil = '';
}

/* SUMMARY */
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM transaksi
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$total_transaksi = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total), 0)
    FROM transaksi
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$total_penjualan = (float) $stmt->fetchColumn();

/* TOTAL DISKON */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(diskon_nominal), 0)
    FROM transaksi
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$total_diskon = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(detail.qty * detail.harga_modal), 0) AS total_modal,
        COALESCE(
                        SUM(detail.subtotal - (detail.qty * detail.harga_modal) - (CASE WHEN transaksi.subtotal > 0 THEN transaksi.diskon_nominal * detail.subtotal / transaksi.subtotal ELSE 0 END)),
            0
        ) AS total_profit
    FROM detail
    INNER JOIN transaksi
        ON transaksi.id = detail.transaksi_id
    WHERE DATE(transaksi.created_at) BETWEEN ? AND ?
");
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

$total_modal = (float) ($summary['total_modal'] ?? 0);
$total_profit = (float) ($summary['total_profit'] ?? 0);

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(qty), 0)
    FROM stok_log
    WHERE jenis = 'masuk'
      AND DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$total_stok_masuk = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(qty), 0)
    FROM stok_log
    WHERE jenis = 'keluar'
      AND DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$total_stok_keluar = (float) $stmt->fetchColumn();

/* DETAIL PENJUALAN */
$stmt = $pdo->prepare("
    SELECT
        transaksi.kode,
        transaksi.created_at,
        transaksi.metode,
        users.nama AS kasir,
        detail.produk_id,
        detail.nama AS nama_detail,
        detail.harga,
        detail.harga_modal,
        detail.qty,
                detail.subtotal,
        transaksi.diskon_persen,
        (CASE WHEN transaksi.subtotal > 0 THEN transaksi.diskon_nominal * detail.subtotal / transaksi.subtotal ELSE 0 END) AS diskon,
        produk.kode AS kode_produk,
        produk.nama AS nama_produk,
        produk.satuan,
        (
                        detail.subtotal - (detail.qty * detail.harga_modal) - (CASE WHEN transaksi.subtotal > 0 THEN transaksi.diskon_nominal * detail.subtotal / transaksi.subtotal ELSE 0 END)
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
");
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$data_penjualan = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* DETAIL STOK */
$stmt = $pdo->prepare("
    SELECT
        stok_log.id,
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
");
$stmt->execute([$tanggal_awal, $tanggal_akhir]);
$data_stok = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| HEADER PENJUALAN
| Satu baris per transaksi, HANYA dari shift kasir yang sudah closing.
| Total dan profit langsung dihitung dari transaksi (setelah diskon).
|--------------------------------------------------------------------------
*/
$data_header = [];
$header_belum_closing = 0;

if ($tampil === '' || $tampil === 'header') {

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

function rupiah_cetak($angka)
{
    return 'Rp ' . number_format((float) $angka, 0, ',', '.');
}

function qty_cetak($angka)
{
    return rtrim(
        rtrim(number_format((float) $angka, 3, ',', '.'), '0'),
        ','
    );
}

function e_cetak($value)
{
    return htmlspecialchars((string) ($value ?? '-'), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e_cetak(NAMA_TOKO) ?> - Cetak Data</title>

<link rel="stylesheet" href="assets/cetak.css?v=<?= (int)@filemtime(__DIR__ . '/assets/cetak.css') ?>">
</head>

<body>

<div class="toolbar">
    <button class="btn" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
</div>

<div class="report">

    <div class="title">
        <h1><?= e_cetak(NAMA_TOKO) ?> — DATA PENJUALAN & STOK</h1>
        <p>
            Periode:
            <strong><?= e_cetak($tanggal_awal) ?></strong>
            s/d
            <strong><?= e_cetak($tanggal_akhir) ?></strong>
        </p>
    </div>

    <div class="summary">
        <div class="card">
            <div class="label">TOTAL TRANSAKSI</div>
            <div class="value"><?= number_format($total_transaksi, 0, ',', '.') ?></div>
        </div>

        <div class="card">
                        <div class="label">TOTAL PENJUALAN (NETO)</div>
            <div class="value"><?= rupiah_cetak($total_penjualan) ?></div>
        </div>

        <div class="card">
            <div class="label">TOTAL DISKON</div>
            <div class="value"><?= rupiah_cetak($total_diskon) ?></div>
        </div>

        <div class="card">
            <div class="label">TOTAL MODAL</div>
            <div class="value"><?= rupiah_cetak($total_modal) ?></div>
        </div>

        <div class="card">
            <div class="label">TOTAL PROFIT</div>
            <div class="value"><?= rupiah_cetak($total_profit) ?></div>
        </div>

        <div class="card">
            <div class="label">STOK MASUK / KELUAR</div>
            <div class="value">
                <?= qty_cetak($total_stok_masuk) ?> /
                <?= qty_cetak($total_stok_keluar) ?>
            </div>
        </div>
    </div>

    <?php if ($tampil === '' || $tampil === 'detail'): ?>
    <div class="section">
        <h2>DETAIL PENJUALAN</h2>

        <?php if (empty($data_penjualan)): ?>
            <div class="empty">Tidak ada data penjualan pada periode ini.</div>
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
                        <th>Qty</th>
                        <th>Harga</th>
                        <th>Modal</th>
                                                <th>Subtotal</th>
                        <th>Diskon</th>

                        <th>Profit</th>
                        <th>Metode</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($data_penjualan as $no => $row): ?>
                    <tr>
                        <td class="center"><?= $no + 1 ?></td>
                        <td class="nowrap">
                            <?= e_cetak(date('d-m-Y H:i', strtotime($row['created_at']))) ?>
                        </td>
                        <td class="nowrap"><strong><?= e_cetak($row['kode']) ?></strong></td>
                        <td><?= e_cetak($row['kasir']) ?></td>
                        <td class="nowrap"><?= e_cetak($row['kode_produk']) ?></td>
                        <td><?= e_cetak($row['nama_produk'] ?: $row['nama_detail']) ?></td>
                        <td class="center"><?= e_cetak($row['satuan']) ?></td>
                        <td class="num"><?= qty_cetak($row['qty']) ?></td>
                        <td class="num"><?= rupiah_cetak($row['harga']) ?></td>
                        <td class="num"><?= rupiah_cetak($row['harga_modal']) ?></td>
                                                <td class="num"><?= rupiah_cetak($row['subtotal']) ?></td>
                        <td class="num"><?= ((float) $row['diskon'] > 0.004)
                            ? rtrim(rtrim(number_format((float) $row['diskon_persen'], 2, ',', '.'), '0'), ',') . '% (-' . rupiah_cetak($row['diskon']) . ')'
                            : '-' ?></td>
                        <td class="num"><?= rupiah_cetak($row['profit']) ?></td>
                        <td class="center"><?= e_cetak(strtoupper($row['metode'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php endif; ?>

<?php if ($tampil === '' || $tampil === 'stok'): ?>
    <div class="section">
        <h2>RIWAYAT STOK</h2>

        <?php if (empty($data_stok)): ?>
            <div class="empty">Tidak ada riwayat stok pada periode ini.</div>
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
                        <th>Qty</th>
                        <th>Stok Sebelum</th>
                        <th>Stok Sesudah</th>
                        <th>Keterangan</th>
                        <th>User</th>
                        <th>Transaksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($data_stok as $no => $row): ?>
                    <tr>
                        <td class="center"><?= $no + 1 ?></td>
                        <td class="nowrap">
                            <?= e_cetak(date('d-m-Y H:i', strtotime($row['created_at']))) ?>
                        </td>
                        <td class="nowrap"><?= e_cetak($row['kode_produk']) ?></td>
                        <td><?= e_cetak($row['nama_produk']) ?></td>
                        <td class="center"><?= e_cetak($row['satuan']) ?></td>
                        <td class="center"><strong><?= e_cetak(strtoupper($row['jenis'])) ?></strong></td>
                        <td class="num"><?= qty_cetak($row['qty']) ?></td>
                        <td class="num"><?= qty_cetak($row['stok_sebelum']) ?></td>
                        <td class="num"><?= qty_cetak($row['stok_sesudah']) ?></td>
                        <td><?= e_cetak($row['keterangan']) ?></td>
                        <td><?= e_cetak($row['nama_user']) ?></td>
                        <td class="center"><?= e_cetak($row['transaksi_id']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($tampil === '' || $tampil === 'header'): ?>
    <div class="section">
        <h2>HEADER PENJUALAN (KASIR SUDAH CLOSING)</h2>

        <?php if ($header_belum_closing > 0): ?>
            <div class="empty" style="text-align:left;padding:4px 0">
                <?= (int) $header_belum_closing ?> transaksi pada periode ini belum closing, tidak dihitung.
            </div>
        <?php endif; ?>

        <?php if (empty($data_header)): ?>
            <div class="empty">Tidak ada transaksi dari shift yang sudah closing pada periode ini.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Tanggal</th>
                        <th>Kode Transaksi</th>
                        <th>Kasir</th>
                        <th>Shift</th>
                        <th>Subtotal</th>
                        <th>Diskon</th>
                        <th>Total</th>
                        <th>Modal</th>
                        <th>Profit</th>
                        <th>Metode</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($data_header as $no => $h): ?>
                    <tr>
                        <td class="center"><?= $no + 1 ?></td>
                        <td class="nowrap"><?= e_cetak(date('d-m-Y H:i', strtotime($h['created_at']))) ?></td>
                        <td class="nowrap"><strong><?= e_cetak($h['kode']) ?></strong></td>
                        <td><?= e_cetak($h['kasir']) ?></td>
                        <td class="center">#<?= (int) $h['shift_id'] ?></td>
                        <td class="num"><?= rupiah_cetak($h['subtotal_kotor']) ?></td>
                        <td class="num"><?= ((float) $h['diskon_nominal'] > 0)
                            ? rtrim(rtrim(number_format((float) $h['diskon_persen'], 2, ',', '.'), '0'), ',') . '% (-' . rupiah_cetak($h['diskon_nominal']) . ')'
                            : '-' ?></td>
                        <td class="num"><strong><?= rupiah_cetak($h['total']) ?></strong></td>
                        <td class="num"><?= rupiah_cetak($h['modal']) ?></td>
                        <td class="num"><?= rupiah_cetak($h['profit']) ?></td>
                        <td class="center"><?= e_cetak(strtoupper($h['metode'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight:bold">
                        <td colspan="5" class="num">TOTAL (<?= count($data_header) ?> transaksi)</td>
                        <td class="num"><?= rupiah_cetak($hd_subtotal) ?></td>
                        <td class="num">-<?= rupiah_cetak($hd_diskon) ?></td>
                        <td class="num"><?= rupiah_cetak($hd_total) ?></td>
                        <td class="num"><?= rupiah_cetak($hd_modal) ?></td>
                        <td class="num"><?= rupiah_cetak($hd_profit) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

</div>

</body>
</html>
