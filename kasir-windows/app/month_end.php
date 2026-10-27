<?php
require 'config.php';
need('admin');

/* ============================================================
   MONTH END
   - Backup SELURUH database ke file .sql (disimpan di folder backup/)
   - Mengosongkan data transaksi, tapi TIDAK PERNAH menyentuh
     tabel users dan produk (stok produk tetap apa adanya).
   - Semua penghapusan dalam satu transaksi: gagal di tengah = batal total.
   ============================================================ */

const ME_KEEP  = ['users', 'produk'];
const ME_CLEAR = ['detail', 'transaksi', 'shift', 'stok_log', 'login_log', 'security_audit_log', 'biaya'];

function me_dir()
{
    $d = __DIR__ . '/backup';
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    $h = $d . '/.htaccess';
    if (is_dir($d) && !is_file($h)) {
        @file_put_contents($h, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    return $d;
}

/* Unduh file backup (khusus admin; nama file divalidasi ketat) */
if (isset($_GET['unduh'])) {
    $f = basename((string)$_GET['unduh']);
    $p = me_dir() . '/' . $f;
    if (!preg_match('/^kasir_buah_backup_[0-9A-Za-z_-]+\.sql$/', $f) || !is_file($p)) {
        http_response_code(404);
        exit('File backup tidak ditemukan.');
    }
    header_remove('Content-Security-Policy');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $f . '"');
    header('Content-Length: ' . filesize($p));
    header('Cache-Control: no-store');
    readfile($p);
    exit;
}

function me_hash($pdo)
{
    $h = [];
    foreach (ME_KEEP as $t) {
        $h[$t] = md5(json_encode($pdo->query('SELECT * FROM `' . $t . '` ORDER BY 1')->fetchAll(PDO::FETCH_NUM)));
    }
    return $h;
}

function me_dump($pdo, $fh, $tabel, $maks)
{
    $nama = [];
    $biner = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `' . $tabel . '`')->fetchAll() as $c) {
        $nama[] = '`' . $c['Field'] . '`';
        $biner[] = (bool)preg_match('/blob|binary/i', $c['Type']);
    }

    $create = $pdo->query('SHOW CREATE TABLE `' . $tabel . '`')->fetch(PDO::FETCH_NUM);
    fwrite($fh, "\n-- Tabel `" . $tabel . "`\nDROP TABLE IF EXISTS `" . $tabel . "`;\n" . $create[1] . ";\n\n");

    $where = $maks === null ? '' : ' WHERE id <= ' . (int)$maks;
    $ukuran = $tabel === 'login_log' ? 20 : 200;
    $n = 0;
    $off = 0;

    while (true) {
        $rows = $pdo->query('SELECT * FROM `' . $tabel . '`' . $where . ' ORDER BY 1 LIMIT ' . $ukuran . ' OFFSET ' . $off)->fetchAll(PDO::FETCH_NUM);
        if (!$rows) {
            break;
        }
        $vals = [];
        foreach ($rows as $r) {
            $v = [];
            foreach ($r as $i => $x) {
                if ($x === null) {
                    $v[] = 'NULL';
                } elseif ($biner[$i]) {
                    $v[] = ($x === '') ? "''" : '0x' . bin2hex($x);
                } else {
                    $v[] = $pdo->quote((string)$x);
                }
            }
            $vals[] = '(' . implode(',', $v) . ')';
        }
        fwrite($fh, 'INSERT INTO `' . $tabel . '` (' . implode(',', $nama) . ") VALUES\n" . implode(",\n", $vals) . ";\n");
        $n += count($rows);
        $off += $ukuran;
    }
    return $n;
}

function me_jalankan($pdo, $periode)
{
    ignore_user_abort(true);
    @set_time_limit(0);

    $semua = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (ME_KEEP as $t) {
        if (!in_array($t, $semua, true)) {
            throw new Exception('Tabel ' . $t . ' tidak ditemukan.');
        }
    }
    $hapus = [];
    foreach (ME_CLEAR as $t) {
        if (in_array($t, $semua, true)) {
            $hapus[] = $t;
        }
    }

    $dir = me_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new Exception('Folder backup tidak bisa ditulis: ' . $dir);
    }

    $file = 'kasir_buah_backup_' . $periode . '_' . date('Ymd-His') . '_' . bin2hex(random_bytes(6)) . '.sql';
    $path = $dir . '/' . $file;
    $fh = @fopen($path, 'wb');
    if (!$fh) {
        throw new Exception('File backup tidak bisa dibuat.');
    }

    $pdo->beginTransaction();

    try {
        /* Kunci produk & users selama proses (tidak ada yang boleh berubah) */
        $pdo->query('SELECT id FROM users FOR UPDATE')->fetchAll();
        $pdo->query('SELECT id FROM produk FOR UPDATE')->fetchAll();
        $hashAwal = me_hash($pdo);

        /* Batas id & jumlah baris (foto kondisi saat ini) */
        $maks = [];
        $cnt = [];
        foreach ($semua as $t) {
            if (in_array($t, $hapus, true)) {
                $maks[$t] = (int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM `' . $t . '`')->fetchColumn();
                $cnt[$t] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $t . '` WHERE id <= ' . $maks[$t])->fetchColumn();
            } else {
                $maks[$t] = null;
                $cnt[$t] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
            }
        }

        $tr = $pdo->query('SELECT COUNT(*) n, COALESCE(SUM(total),0) omzet FROM transaksi WHERE id <= ' . (int)$maks['transaksi'])->fetch();
        $lb = $pdo->query('SELECT COALESCE(SUM(d.qty*d.harga_modal),0) hpp, COALESCE(SUM(CASE WHEN d.harga_modal=0 THEN 1 ELSE 0 END),0) nol FROM detail d JOIN transaksi t ON t.id=d.transaksi_id WHERE t.id <= ' . (int)$maks['transaksi'])->fetch();
        $dsk = (float)$pdo->query('SELECT COALESCE(SUM(diskon_nominal),0) FROM transaksi WHERE id <= ' . (int)$maks['transaksi'])->fetchColumn();
        $laba = (float)$tr['omzet'] - (float)$lb['hpp'];
        $biaya = 0.0;
        $biayaRinci = [];
        if (isset($maks['biaya'])) {
            foreach ($pdo->query('SELECT kategori, COALESCE(SUM(jumlah),0) j FROM biaya WHERE id <= ' . (int)$maks['biaya'] . ' GROUP BY kategori ORDER BY j DESC')->fetchAll() as $r) {
                $biayaRinci[$r['kategori']] = (float)$r['j'];
                $biaya += (float)$r['j'];
            }
        }
        $bersih = $laba - $biaya;
        $st = $pdo->query('SELECT COUNT(*) n, COALESCE(SUM(stok*harga_modal),0) modal, COALESCE(SUM(stok*harga),0) jual FROM produk')->fetch();
        $oleh = preg_replace('/[\r\n]+/', ' ', (string)($_SESSION['u']['nama'] ?? ''));

        fwrite($fh, "-- ============================================================\n");
        fwrite($fh, "-- BACKUP MONTH END KASIR BUAH\n");
        fwrite($fh, "-- Periode  : " . $periode . "\n");
        fwrite($fh, "-- Dibuat   : " . date('Y-m-d H:i:s') . " oleh " . $oleh . "\n");
        fwrite($fh, "-- Transaksi: " . (int)$tr['n'] . " | Omzet: Rp " . number_format((float)$tr['omzet'], 0, ',', '.') . "\n");
        fwrite($fh, "-- Diskon: Rp " . number_format($dsk, 0, ',', '.') . " | HPP (modal terjual): Rp " . number_format((float)$lb['hpp'], 0, ',', '.') . " | Laba kotor: Rp " . number_format($laba, 0, ',', '.') . "\n");
        fwrite($fh, "-- Biaya operasional: Rp " . number_format($biaya, 0, ',', '.') . " | LABA BERSIH: Rp " . number_format($bersih, 0, ',', '.') . "\n");
        foreach ($biayaRinci as $kat => $jml) {
            fwrite($fh, "--   Biaya " . preg_replace('/[\r\n]+/', ' ', (string)$kat) . ": Rp " . number_format($jml, 0, ',', '.') . "\n");
        }
        fwrite($fh, "-- Stok akhir: " . (int)$st['n'] . " produk | Nilai modal: Rp " . number_format((float)$st['modal'], 0, ',', '.') . " | Nilai jual: Rp " . number_format((float)$st['jual'], 0, ',', '.') . "\n");
        foreach ($semua as $t) {
            fwrite($fh, "-- " . $t . ": " . $cnt[$t] . " baris\n");
        }
        fwrite($fh, "-- Pulihkan: impor file ini di phpMyAdmin pada database kasir_buah\n");
        fwrite($fh, "-- ============================================================\n\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");

        foreach ($semua as $t) {
            $n = me_dump($pdo, $fh, $t, $maks[$t]);
            if ($n !== $cnt[$t]) {
                throw new Exception('Jumlah baris backup tabel ' . $t . ' tidak cocok. Dibatalkan, tidak ada data yang dihapus.');
            }
        }

        fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n-- SELESAI month end backup\n");
        fclose($fh);
        $fh = null;

        /* Pastikan file benar-benar utuh sebelum menghapus apa pun */
        clearstatcache();
        $ukuran = (int)filesize($path);
        $ekor = $ukuran > 0 ? (string)file_get_contents($path, false, null, max(0, $ukuran - 200)) : '';
        if ($ukuran < 200 || strpos($ekor, '-- SELESAI month end backup') === false) {
            throw new Exception('File backup tidak lengkap. Dibatalkan, tidak ada data yang dihapus.');
        }

        /* Hapus data transaksi (hanya sampai id yang sudah dibackup) */
        $dihapus = [];
        foreach ($hapus as $t) {
            $k = (int)$pdo->exec('DELETE FROM `' . $t . '` WHERE id <= ' . (int)$maks[$t]);
            if ($k !== $cnt[$t]) {
                throw new Exception('Jumlah baris terhapus tabel ' . $t . ' tidak sama dengan backup. Dibatalkan.');
            }
            $dihapus[$t] = $k;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_resource($fh)) {
            fclose($fh);
        }
        @unlink($path);
        throw $e;
    }

    /* Bukti: users & produk identik sebelum dan sesudah */
    try {
        $sama = ($hashAwal === me_hash($pdo));
    } catch (Throwable $e) {
        $sama = false;
    }

    return [
        'file'    => $file,
        'ukuran'  => $ukuran,
        'dihapus' => $dihapus,
        'tr'      => (int)$tr['n'],
        'omzet'   => (float)$tr['omzet'],
        'diskon'  => $dsk,
        'hpp'     => (float)$lb['hpp'],
        'laba'    => $laba,
        'biaya'   => $biaya,
        'rinci'   => $biayaRinci,
        'bersih'  => $bersih,
        'nol'     => (int)$lb['nol'],
        'produk'  => (int)$st['n'],
        'modal'   => (float)$st['modal'],
        'jual'    => (float)$st['jual'],
        'sama'    => $sama,
    ];
}

/* ---------- proses form ---------- */
$msg = '';
$hasil = null;
$periode = date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'monthend') {
    try {
        $periode = (string)($_POST['periode'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periode)) {
            throw new Exception('Periode tidak valid.');
        }
        if (trim((string)($_POST['konfirmasi'] ?? '')) !== 'MONTH END') {
            throw new Exception('Ketik MONTH END persis seperti contoh untuk melanjutkan.');
        }
        $s = $pdo->prepare('SELECT password FROM users WHERE id=?');
        $s->execute([(int)$_SESSION['u']['id']]);
        if (!password_verify((string)($_POST['password'] ?? ''), (string)$s->fetchColumn())) {
            throw new Exception('Password admin salah.');
        }
        if ((int)$pdo->query("SELECT COUNT(*) FROM shift WHERE status='buka'")->fetchColumn() > 0) {
            throw new Exception('Masih ada shift kasir yang terbuka. Closing dulu sebelum Month End.');
        }
        $_SESSION['me_hasil'] = me_jalankan($pdo, $periode);
        header('Location: month_end.php?selesai=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException) {
            error_log('MonthEnd: ' . $e->getMessage());
            $msg = 'Terjadi kesalahan database. Tidak ada data yang dihapus.';
        } else {
            $msg = $e->getMessage();
        }
    }
}

if (isset($_GET['selesai']) && !empty($_SESSION['me_hasil'])) {
    $hasil = $_SESSION['me_hasil'];
    unset($_SESSION['me_hasil']);
}

/* ---------- data tampilan ---------- */
$semua = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$ringkas = [];
foreach (ME_CLEAR as $t) {
    if (in_array($t, $semua, true)) {
        $ringkas[$t] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn();
    }
}
$tr = $pdo->query('SELECT COUNT(*) n, COALESCE(SUM(total),0) omzet, MIN(created_at) a, MAX(created_at) b FROM transaksi')->fetch();
$lbp = $pdo->query('SELECT COALESCE(SUM(d.qty*d.harga_modal),0) hpp, COALESCE(SUM(CASE WHEN d.harga_modal=0 THEN 1 ELSE 0 END),0) nol FROM detail d JOIN transaksi t ON t.id=d.transaksi_id')->fetch();
$labaP = (float)$tr['omzet'] - (float)$lbp['hpp'];
$biayaP = 0.0;
if (in_array('biaya', $semua, true)) {
    $biayaP = (float)$pdo->query('SELECT COALESCE(SUM(jumlah),0) FROM biaya')->fetchColumn();
}
$bersihP = $labaP - $biayaP;
$produk = $pdo->query('SELECT kode,nama,satuan,stok,harga,harga_modal FROM produk ORDER BY nama')->fetchAll();
$nilaiModal = 0;
$nilaiJual = 0;
foreach ($produk as $p) {
    $nilaiModal += (float)$p['stok'] * (float)$p['harga_modal'];
    $nilaiJual += (float)$p['stok'] * (float)$p['harga'];
}
$terbuka = (int)$pdo->query("SELECT COUNT(*) FROM shift WHERE status='buka'")->fetchColumn();

$daftar = [];
foreach ((array)glob(me_dir() . '/kasir_buah_backup_*.sql') as $f) {
    $daftar[] = ['n' => basename($f), 's' => filesize($f), 't' => filemtime($f)];
}
usort($daftar, function ($a, $b) {
    return $b['t'] <=> $a['t'];
});

include 'header.php';
?>
<?php if ($hasil): ?>
<div class="panel">
<h3>✅ Month End selesai</h3>
<div class="alert okmsg" style="margin:10px 0">Backup dibuat dan data transaksi sudah dibersihkan. Unduhan file SQL dimulai otomatis.</div>
<div class="stats">
<div class="stat"><small>Transaksi dibackup</small><b><?= (int)$hasil['tr'] ?></b></div>
<div class="stat"><small>Omzet periode</small><b><?= rp($hasil['omzet']) ?></b></div>
<div class="stat"><small>Diskon diberikan</small><b><?= rp($hasil['diskon']) ?></b></div>
<div class="stat"><small>HPP (modal terjual)</small><b><?= rp($hasil['hpp']) ?></b></div>
<div class="stat"><small>Biaya operasional</small><b><?= rp($hasil['biaya']) ?></b></div>
<div class="stat"><small>LABA BERSIH</small><b><?= rp($hasil['bersih']) ?></b></div>
<div class="stat"><small>Laba kotor<?= $hasil['omzet'] > 0 ? ' · ' . number_format($hasil['laba'] / $hasil['omzet'] * 100, 1, ',', '.') . '%' : '' ?></small><b><?= rp($hasil['laba']) ?></b></div>
<div class="stat"><small>Produk (tetap)</small><b><?= (int)$hasil['produk'] ?></b></div>
<div class="stat"><small>Nilai stok (modal)</small><b><?= rp($hasil['modal']) ?></b></div>
<div class="stat"><small>Nilai stok (jual)</small><b><?= rp($hasil['jual']) ?></b></div>
</div>
<?php if ($hasil['rinci']): ?><p style="margin-top:12px">Rincian biaya: <?php foreach ($hasil['rinci'] as $k => $j): ?><span class="tag low"><?= h($k) ?>: <?= rp($j) ?></span> <?php endforeach; ?></p><?php endif; ?>
<?php if ($hasil['nol'] > 0): ?><p style="margin-top:12px;color:#8a6d00;font-size:.85rem">⚠️ <?= (int)$hasil['nol'] ?> baris penjualan tidak punya harga modal (transaksi lama), sehingga laba bisa terlihat lebih besar dari sebenarnya.</p><?php endif; ?>
<p style="margin-top:12px">
<?php if ($hasil['sama']): ?>✅ Data <b>produk</b> dan <b>users</b> dicek ulang: <b>identik</b> dengan sebelum Month End.
<?php else: ?>⚠️ Data produk/users berbeda dari sebelum proses (kemungkinan ada perubahan dari perangkat lain saat proses). Cek manual.<?php endif; ?>
</p>
<p style="margin-top:8px">Baris yang dihapus:
<?php foreach ($hasil['dihapus'] as $t => $k): ?><span class="tag low"><?= h($t) ?>: <?= (int)$k ?></span> <?php endforeach; ?></p>
<p style="margin-top:12px">
<a class="btn" style="text-decoration:none;display:inline-block" href="month_end.php?unduh=<?= h(rawurlencode($hasil['file'])) ?>">⬇ Unduh lagi file backup (<?= number_format($hasil['ukuran'] / 1024, 0, ',', '.') ?> KB)</a>
</p>
<p style="color:var(--mut);font-size:.85rem;margin-top:8px">Salinan juga tersimpan di folder <code>backup/</code> pada server. Simpan file ini di tempat aman: berisi data penjualan dan password terenkripsi pengguna.</p>
</div>
<script>setTimeout(function(){location.href='month_end.php?unduh='+encodeURIComponent(<?= json_encode($hasil['file']) ?>);},700);</script>
<?php else: ?>
<div class="panel">
<h3>🗓️ Month End</h3>
<p style="color:var(--mut);font-size:.88rem;margin-bottom:10px">
Proses akhir bulan: (1) seluruh database di-backup ke file SQL dan diunduh otomatis, (2) data transaksi dikosongkan.
Tabel <b>users</b> dan <b>produk</b> (termasuk stok) <b>tidak disentuh</b>. Backup diperiksa dulu; jika gagal, tidak ada data yang dihapus.
</p>
<?php if ($msg): ?><div class="alert" style="margin:10px 0"><?= h($msg) ?></div><?php endif; ?>
<?php if ($terbuka): ?><div class="alert" style="margin:10px 0">⚠️ Ada shift kasir yang masih terbuka. Closing dulu sebelum menjalankan Month End.</div><?php endif; ?>

<div class="stats">
<div class="stat"><small>Transaksi akan dihapus</small><b><?= (int)$tr['n'] ?></b></div>
<div class="stat"><small>Omzet tercatat</small><b><?= rp($tr['omzet']) ?></b></div>
<div class="stat"><small>HPP (modal terjual)</small><b><?= rp($lbp['hpp']) ?></b></div>
<div class="stat"><small>Laba kotor</small><b><?= rp($labaP) ?></b></div>
<div class="stat"><small>Biaya operasional</small><b><?= rp($biayaP) ?></b></div>
<div class="stat"><small>Laba bersih</small><b><?= rp($bersihP) ?></b></div>
<div class="stat"><small>Produk (aman)</small><b><?= count($produk) ?></b></div>
<div class="stat"><small>Nilai stok (modal)</small><b><?= rp($nilaiModal) ?></b></div>
<div class="stat"><small>Nilai stok (jual)</small><b><?= rp($nilaiJual) ?></b></div>
</div>
<p style="margin:12px 0 6px">Akan dikosongkan:
<?php foreach ($ringkas as $t => $k): ?><span class="tag low"><?= h($t) ?>: <?= (int)$k ?></span> <?php endforeach; ?></p>
<?php if ($tr['a']): ?><p style="color:var(--mut);font-size:.82rem">Rentang transaksi: <?= h($tr['a']) ?> s/d <?= h($tr['b']) ?></p><?php endif; ?>

<form method="post" class="frm" style="margin-top:14px" autocomplete="off" onsubmit="return confirm('Jalankan Month End sekarang? Data transaksi akan dihapus setelah backup dibuat.')">
<input type="hidden" name="aksi" value="monthend">
<input type="month" name="periode" value="<?= h($periode) ?>" pattern="\d{4}-\d{2}" required title="Bulan yang ditutup (untuk nama file backup)">
<input type="password" name="password" placeholder="Password admin" required autocomplete="off">
<input name="konfirmasi" placeholder="Ketik: MONTH END" required autocomplete="off">
<button class="btn" style="background:#d93a3a"<?= $terbuka ? ' disabled' : '' ?>>Jalankan Month End</button>
</form>
</div>

<div class="panel">
<h3>📦 Stok saat ini (tidak berubah)</h3>
<div class="tw"><table>
<tr><th>Kode</th><th>Produk</th><th>Stok</th><th>Harga modal</th><th>Harga jual</th><th>Nilai modal</th></tr>
<?php foreach ($produk as $p): ?>
<tr><td><?= h($p['kode']) ?></td><td><?= h($p['nama']) ?></td><td><?= number_format((float)$p['stok'], 3, ',', '.') ?> <?= h($p['satuan']) ?></td><td><?= rp($p['harga_modal']) ?></td><td><?= rp($p['harga']) ?></td><td><?= rp((float)$p['stok'] * (float)$p['harga_modal']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$produk): ?><tr><td colspan="6" class="empty">Belum ada produk</td></tr><?php endif; ?>
</table></div>
</div>

<div class="panel">
<h3>🗂️ File backup tersimpan</h3>
<div class="tw"><table>
<tr><th>File</th><th>Ukuran</th><th>Dibuat</th><th></th></tr>
<?php foreach ($daftar as $d): ?>
<tr><td><?= h($d['n']) ?></td><td><?= number_format($d['s'] / 1024, 0, ',', '.') ?> KB</td><td><?= h(date('d-m-Y H:i', $d['t'])) ?></td><td><a href="month_end.php?unduh=<?= h(rawurlencode($d['n'])) ?>">⬇ Unduh</a></td></tr>
<?php endforeach; ?>
<?php if (!$daftar): ?><tr><td colspan="4" class="empty">Belum ada backup</td></tr><?php endif; ?>
</table></div>
</div>
<?php endif; ?>
<?php include 'footer.php'; ?>