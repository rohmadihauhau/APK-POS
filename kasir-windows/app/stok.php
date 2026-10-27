<?php
require 'config.php';
need(['admin', 'kanan_admin']);

$msg = '';
$ok  = '';

/* ==========================================================
   PROSES STOK MASUK / KELUAR
   ========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'stok') {

    $produkId = (int)($_POST['produk_id'] ?? 0);
    $jenis    = strtolower(trim((string)($_POST['jenis'] ?? '')));
    $qty      = (float)($_POST['qty'] ?? 0);
    $ket      = trim((string)($_POST['keterangan'] ?? ''));

    try {
        if (!$produkId) throw new Exception('Produk belum dipilih.');
        if (!in_array($jenis, ['masuk', 'keluar'], true)) throw new Exception('Jenis stok tidak valid.');
        if ($qty <= 0) throw new Exception('Jumlah stok harus lebih dari 0.');
        if ($qty > 999999999) throw new Exception('Jumlah stok terlalu besar.');

        $qty    = round($qty, 3);              // maksimal 3 angka desimal
        $userId = $_SESSION['u']['id'] ?? null;

        $pdo->beginTransaction();              // stok & riwayat disimpan bersamaan (atomik)
        if ($jenis === 'masuk') {
            stok_masuk($pdo, $produkId, $qty, $ket !== '' ? $ket : 'Stok masuk', $userId);
        } else {
            stok_keluar($pdo, $produkId, $qty, $ket !== '' ? $ket : 'Stok keluar', $userId);
        }
        $pdo->commit();

        header('Location: stok.php?ok=1');
        exit;

    } catch (Exception $x) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = ($x instanceof PDOException) ? 'Terjadi kesalahan database.' : $x->getMessage();
    }
}

if (isset($_GET['ok'])) {
    $ok = 'Stok berhasil diperbarui dan sudah tercatat di database.';
}

/* ==========================================================
   DATA PRODUK
   ========================================================== */
$list = $pdo->query('SELECT id, kode, nama, harga, stok, satuan FROM produk ORDER BY nama')
            ->fetchAll(PDO::FETCH_ASSOC);

$totalStok = 0;
foreach ($list as $p) $totalStok += (float)($p['stok'] ?? 0);

/* ==========================================================
   FILTER (dipakai untuk TAMPILKAN dan EXPORT PDF)
   ========================================================== */
$hariIni      = date('Y-m-d');
$filterAktif  = isset($_GET['dari']) || isset($_GET['sampai']) || isset($_GET['jenis']) || isset($_GET['produk_id']);

$dari         = $_GET['dari']   ?? date('Y-m-01');
$sampai       = $_GET['sampai'] ?? date('Y-m-d');
$jenisFilter  = $_GET['jenis']  ?? 'semua';
$produkFilter = (int)($_GET['produk_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari))   $dari   = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) $sampai = date('Y-m-d');
if ($sampai < $dari) { $tmp = $dari; $dari = $sampai; $sampai = $tmp; }
if (!in_array($jenisFilter, ['semua', 'masuk', 'keluar'], true)) $jenisFilter = 'semua';

/* Tanpa filter  -> tampilkan hari ini saja
   Dengan filter -> tampilkan sesuai tanggal, jenis, dan produk */
$qDari   = $filterAktif ? $dari   : $hariIni;
$qSampai = $filterAktif ? $sampai : $hariIni;

$where = ['sl.created_at >= ?', 'sl.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$par   = [$qDari, $qSampai];

if ($filterAktif && $jenisFilter !== 'semua') { $where[] = 'sl.jenis = ?';     $par[] = $jenisFilter; }
if ($filterAktif && $produkFilter > 0)        { $where[] = 'sl.produk_id = ?'; $par[] = $produkFilter; }

$W = implode(' AND ', $where);

/* Total (dari seluruh data sesuai filter, bukan hanya yang tampil) */
$st = $pdo->prepare("SELECT sl.jenis, COUNT(*) AS n, COALESCE(SUM(sl.qty), 0) AS q
                     FROM stok_log sl WHERE $W GROUP BY sl.jenis");
$st->execute($par);

$totMasuk = 0; $totKeluar = 0; $jumlahMutasi = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $jumlahMutasi += (int)$r['n'];
    if ($r['jenis'] === 'masuk') $totMasuk  = (float)$r['q'];
    else                         $totKeluar = (float)$r['q'];
}

/* Baris riwayat (maksimal 500 terbaru untuk tampilan layar) */
$st = $pdo->prepare("SELECT sl.*, p.kode, p.nama, p.satuan, u.nama AS user_nama
                     FROM stok_log sl
                     JOIN produk p ON p.id = sl.produk_id
                     LEFT JOIN users u ON u.id = sl.user_id
                     WHERE $W
                     ORDER BY sl.created_at DESC, sl.id DESC
                     LIMIT 500");
$st->execute($par);
$mutasi = $st->fetchAll(PDO::FETCH_ASSOC);

/* Teks keterangan filter */
$jenisLabel  = ['semua' => 'Semua', 'masuk' => 'Stok Masuk', 'keluar' => 'Stok Keluar'];
$namaProduk  = 'Semua Produk';
foreach ($list as $p) {
    if ((int)$p['id'] === $produkFilter) $namaProduk = $p['kode'] . ' - ' . $p['nama'];
}
$tgl = function ($d) { return date('d-m-Y', strtotime($d)); };
$periodeTxt = $qDari === $qSampai ? $tgl($qDari) : $tgl($qDari) . ' s/d ' . $tgl($qSampai);
$lblP = $filterAktif ? 'Sesuai Filter' : 'Hari Ini';
$fmt  = function ($n) { return number_format((float)$n, 3, ',', '.'); };

include 'header.php';
?>

<?php if ($msg): ?><div class="alert" style="margin:10px 0"><?= h($msg) ?></div><?php endif; ?>
<?php if ($ok):  ?><div class="alert okmsg" style="margin:10px 0"><?= h($ok) ?></div><?php endif; ?>

<!-- ================= FORM STOK ================= -->
<div class="panel">
    <h3>📦 Stok Masuk / Keluar</h3>

    <div style="margin-bottom:15px;color:#6b7d73;font-size:.82rem">
        Gunakan halaman ini untuk mencatat stok masuk dari supplier atau stok keluar
        karena penjualan, rusak, koreksi, dan sebagainya.<br>
        Stok mendukung hingga <b>3 angka desimal</b>.
    </div>

    <form method="post" class="frm" id="formStok">
        <input type="hidden" name="aksi" value="stok">

        <div>
            <small>Produk</small>
            <select name="produk_id" id="produk_id" required>
                <option value="">Pilih produk</option>
                <?php foreach ($list as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"
                            data-stok="<?= h($p['stok']) ?>"
                            data-satuan="<?= h($p['satuan']) ?>">
                        <?= h($p['kode']) ?> - <?= h($p['nama']) ?>
                        [Stok: <?= $fmt($p['stok']) ?> <?= h($p['satuan']) ?>]
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <small>Jenis</small>
            <select name="jenis" id="jenis" required>
                <option value="masuk">➕ Stok Masuk</option>
                <option value="keluar">➖ Stok Keluar</option>
            </select>
        </div>

        <div>
            <small>Jumlah</small>
            <input name="qty" id="qty" type="number" min="0.001" step="0.001" placeholder="Jumlah stok" required>
        </div>

        <div>
            <small>Keterangan</small>
            <input name="keterangan" placeholder="Contoh: Pembelian supplier">
        </div>

        <button class="btn" type="submit">💾 Simpan Stok</button>
    </form>

    <div id="infoStok" style="margin-top:12px;padding:10px;background:#f5f8f6;border-radius:8px;font-size:.82rem;display:none"></div>
</div>

<!-- ================= RINGKASAN ================= -->
<div class="stats">
    <div class="stat"><small>Total Stok Saat Ini</small><b><?= $fmt($totalStok) ?></b></div>
    <div class="stat"><small>Stok Masuk (<?= h($lblP) ?>)</small><b>+ <?= $fmt($totMasuk) ?></b></div>
    <div class="stat"><small>Stok Keluar (<?= h($lblP) ?>)</small><b>- <?= $fmt($totKeluar) ?></b></div>
    <div class="stat"><small>Jumlah Mutasi (<?= h($lblP) ?>)</small><b><?= (int)$jumlahMutasi ?></b></div>
</div>

<!-- ================= FILTER + EXPORT PDF ================= -->
<div class="panel">
    <h3>🔎 Filter Riwayat &amp; Export PDF</h3>

    <div style="margin-bottom:15px;color:#6b7d73;font-size:.82rem">
        Atur filter lalu klik <b>Tampilkan</b> untuk melihat datanya di bawah.
        Klik <b>Export PDF</b> untuk mencetak data dengan filter yang sama persis seperti isian di bawah.
    </div>

    <form method="get" class="frm" id="formFilter" style="align-items:end">

        <div>
            <small>Dari tanggal</small>
            <input type="date" name="dari" value="<?= h($dari) ?>" required>
        </div>

        <div>
            <small>Sampai tanggal</small>
            <input type="date" name="sampai" value="<?= h($sampai) ?>" required>
        </div>

        <div>
            <small>Jenis</small>
            <select name="jenis">
                <option value="semua"  <?= $jenisFilter === 'semua'  ? 'selected' : '' ?>>Semua</option>
                <option value="masuk"  <?= $jenisFilter === 'masuk'  ? 'selected' : '' ?>>Stok Masuk</option>
                <option value="keluar" <?= $jenisFilter === 'keluar' ? 'selected' : '' ?>>Stok Keluar</option>
            </select>
        </div>

        <div>
            <small>Produk</small>
            <select name="produk_id">
                <option value="0">Semua Produk</option>
                <?php foreach ($list as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $produkFilter === (int)$p['id'] ? 'selected' : '' ?>>
                        <?= h($p['kode']) ?> - <?= h($p['nama']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn" type="submit">🔎 Tampilkan</button>
        <a class="btn ghost" href="stok.php" style="text-decoration:none">↺ Reset</a>
        <button class="btn" type="button" id="btnExportPdf">📄 Export PDF</button>
    </form>
</div>

<!-- ================= RIWAYAT ================= -->
<div class="panel">
    <h3>📝 Riwayat Stok <?= $filterAktif ? '(Sesuai Filter)' : 'Hari Ini' ?></h3>

    <div style="margin-bottom:10px;font-size:.8rem;color:#6b7d73">
        Periode: <b><?= h($periodeTxt) ?></b>
        &nbsp;|&nbsp; Jenis: <b><?= h($jenisLabel[$filterAktif ? $jenisFilter : 'semua']) ?></b>
        &nbsp;|&nbsp; Produk: <b><?= h($filterAktif ? $namaProduk : 'Semua Produk') ?></b>
        <?php if (!$filterAktif): ?>
            <br>Pakai filter di atas untuk melihat tanggal, jenis, atau produk tertentu.
        <?php endif; ?>
        <?php if ($jumlahMutasi > count($mutasi)): ?>
            <br><b>Menampilkan <?= count($mutasi) ?> dari <?= (int)$jumlahMutasi ?> data terbaru.</b>
            Export PDF memuat semua data.
        <?php endif; ?>
    </div>

    <div class="tw">
        <table>
            <tr>
                <th>Tanggal</th><th>Kode</th><th>Produk</th><th>Jenis</th><th>Qty</th>
                <th>Sebelum</th><th>Sesudah</th><th>Keterangan</th><th>User</th>
            </tr>

            <?php foreach ($mutasi as $m): ?>
                <tr>
                    <td><?= date('d-m-Y H:i', strtotime($m['created_at'])) ?></td>
                    <td><code><?= h($m['kode']) ?></code></td>
                    <td><b><?= h($m['nama']) ?></b></td>
                    <td>
                        <?php if (($m['jenis'] ?? '') === 'masuk'): ?>
                            <span class="tag">+ MASUK</span>
                        <?php else: ?>
                            <span class="tag low">- KELUAR</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $fmt($m['qty'] ?? 0) ?> <?= h($m['satuan']) ?></td>
                    <td><?= $fmt($m['stok_sebelum'] ?? 0) ?></td>
                    <td><b><?= $fmt($m['stok_sesudah'] ?? 0) ?></b></td>
                    <td><?= h($m['keterangan'] ?? '') ?></td>
                    <td><?= h($m['user_nama'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$mutasi): ?>
                <tr>
                    <td colspan="9" class="empty">
                        <?= $filterAktif ? 'Tidak ada data sesuai filter.' : 'Belum ada mutasi stok hari ini.' ?>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
    </div>
</div>

<script src="assets/stok.js?v=<?= (int)@filemtime(__DIR__ . '/assets/stok.js') ?>"></script>

<?php include 'footer.php'; ?>
