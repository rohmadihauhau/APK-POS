<?php
require 'config.php';
need('admin');

/* ============================================================
   BIAYA OPERASIONAL
   Catat listrik, gaji, perawatan, dll supaya laba bersih
   bisa dihitung (laba kotor - total biaya).
   ============================================================ */

const KATEGORI_BIAYA = ['Listrik', 'Gaji Karyawan', 'Perawatan', 'Sewa', 'Buah Busuk / Susut', 'Plastik & Kemasan', 'Transport', 'Lain-lain'];

$msg = '';
$bulan = (string)($_GET['bulan'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
    $bulan = date('Y-m');
}

/* Hapus satu biaya (dilindungi token) */
if (isset($_GET['del']) && hash_equals(security_csrf_token(), (string)($_GET['t'] ?? ''))) {
    $pdo->prepare('DELETE FROM biaya WHERE id=?')->execute([(int)$_GET['del']]);
    header('Location: biaya.php?bulan=' . urlencode($bulan) . '&ok=2');
    exit;
}

/* Tambah biaya */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'tambah') {
    try {
        $tgl = (string)($_POST['tanggal'] ?? '');
        $d = DateTime::createFromFormat('Y-m-d', $tgl);
        if (!$d || $d->format('Y-m-d') !== $tgl) {
            throw new Exception('Tanggal tidak valid.');
        }

        $kat = (string)($_POST['kategori'] ?? '');
        if (!in_array($kat, KATEGORI_BIAYA, true)) {
            throw new Exception('Kategori tidak valid.');
        }

        $jml = round(security_decimal($_POST['jumlah'] ?? 0), 2);
        if ($jml <= 0 || $jml > 999999999999) {
            throw new Exception('Jumlah harus lebih dari 0.');
        }

        $ket = trim((string)($_POST['keterangan'] ?? ''));
        $ket = function_exists('mb_substr') ? mb_substr($ket, 0, 150, 'UTF-8') : substr($ket, 0, 150);

        $pdo->prepare('INSERT INTO biaya (tanggal, kategori, keterangan, jumlah, user_id) VALUES (?,?,?,?,?)')
            ->execute([$tgl, $kat, $ket, $jml, (int)$_SESSION['u']['id']]);

        header('Location: biaya.php?bulan=' . urlencode(substr($tgl, 0, 7)) . '&ok=1');
        exit;
    } catch (PDOException $e) {
        error_log('Biaya: ' . $e->getMessage());
        $msg = 'Gagal menyimpan biaya.';
    } catch (Exception $e) {
        $msg = $e->getMessage();
    }
}

/* Data bulan terpilih */
$awal = $bulan . '-01';
$akhir = date('Y-m-d', strtotime($awal . ' +1 month'));

$s = $pdo->prepare('SELECT * FROM biaya WHERE tanggal >= ? AND tanggal < ? ORDER BY tanggal DESC, id DESC');
$s->execute([$awal, $akhir]);
$rows = $s->fetchAll();

$perKat = [];
$totalBiaya = 0.0;
foreach ($rows as $r) {
    $perKat[$r['kategori']] = ($perKat[$r['kategori']] ?? 0) + (float)$r['jumlah'];
    $totalBiaya += (float)$r['jumlah'];
}
arsort($perKat);

$q = $pdo->prepare('SELECT COUNT(*) n, COALESCE(SUM(total),0) omzet FROM transaksi WHERE created_at >= ? AND created_at < ?');
$q->execute([$awal, $akhir]);
$tr = $q->fetch();

$q = $pdo->prepare('SELECT COALESCE(SUM(d.qty*d.harga_modal),0) FROM detail d JOIN transaksi t ON t.id=d.transaksi_id WHERE t.created_at >= ? AND t.created_at < ?');
$q->execute([$awal, $akhir]);
$hpp = (float)$q->fetchColumn();

$labaKotor = (float)$tr['omzet'] - $hpp;
$labaBersih = $labaKotor - $totalBiaya;

include 'header.php';
?>
<?php if (isset($_GET['ok'])): ?><div class="alert okmsg" style="margin-bottom:14px"><?= $_GET['ok'] === '2' ? '✅ Biaya dihapus.' : '✅ Biaya disimpan.' ?></div><?php endif; ?>

<div class="stats">
<div class="stat"><small>Omzet</small><b><?= rp($tr['omzet']) ?></b></div>
<div class="stat"><small>HPP (modal terjual)</small><b><?= rp($hpp) ?></b></div>
<div class="stat"><small>Laba kotor</small><b><?= rp($labaKotor) ?></b></div>
<div class="stat"><small>Total biaya</small><b><?= rp($totalBiaya) ?></b></div>
<div class="stat"><small>Laba bersih</small><b><?= rp($labaBersih) ?></b></div>
</div>
<p style="color:var(--mut);font-size:.8rem;margin:8px 0 14px">Omzet dan HPP dihitung dari transaksi yang masih ada di aplikasi untuk bulan ini. Setelah Month End, transaksi lama sudah tersimpan di file backup.</p>

<div class="panel">
<h3>💸 Catat Biaya</h3>
<?php if ($msg): ?><div class="alert" style="margin:10px 0"><?= h($msg) ?></div><?php endif; ?>
<form method="post" class="frm" autocomplete="off">
<input type="hidden" name="aksi" value="tambah">
<input type="date" name="tanggal" value="<?= h(date('Y-m-d')) ?>" required>
<select name="kategori" required>
<?php foreach (KATEGORI_BIAYA as $k): ?><option><?= h($k) ?></option><?php endforeach; ?>
</select>
<input name="keterangan" maxlength="150" placeholder="Keterangan (boleh kosong)">
<input type="number" name="jumlah" min="1" step="any" inputmode="decimal" placeholder="Jumlah (Rp)" required>
<button class="btn">Simpan</button>
</form>
<p style="color:var(--mut);font-size:.8rem;margin-top:8px">Untuk <b>Buah Busuk / Susut</b>, isi nilai modal buah yang rusak, lalu kurangi stoknya lewat menu Stok Masuk / Keluar.</p>
</div>

<div class="panel">
<form class="frm" method="get"><input type="month" name="bulan" value="<?= h($bulan) ?>" required><button class="btn">Tampilkan</button></form>

<?php if ($perKat): ?>
<p style="margin:12px 0 6px">Rincian bulan <?= h($bulan) ?>:
<?php foreach ($perKat as $k => $j): ?><span class="tag low"><?= h($k) ?>: <?= rp($j) ?></span> <?php endforeach; ?></p>
<?php endif; ?>

<div class="tw"><table>
<tr><th>Tanggal</th><th>Kategori</th><th>Keterangan</th><th>Jumlah</th><th></th></tr>
<?php foreach ($rows as $r): ?>
<tr>
<td><?= h(date('d-m-Y', strtotime($r['tanggal']))) ?></td>
<td><?= h($r['kategori']) ?></td>
<td><?= h($r['keterangan']) ?></td>
<td><b><?= rp($r['jumlah']) ?></b></td>
<td><a href="?del=<?= (int)$r['id'] ?>&amp;bulan=<?= h($bulan) ?>&amp;t=<?= security_csrf_token() ?>" class="red" onclick="return confirm('Hapus biaya ini?')">Hapus</a></td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="empty">Belum ada biaya bulan ini</td></tr><?php endif; ?>
</table></div>
</div>
<script src="assets/biaya.js?v=<?= (int)@filemtime(__DIR__ . '/assets/biaya.js') ?>"></script>
<?php include 'footer.php'; ?>