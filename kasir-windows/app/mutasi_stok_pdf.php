<?php
/* Export riwayat mutasi stok ke PDF (A4 landscape)
   Parameter: dari, sampai, jenis (semua|masuk|keluar), produk_id (0 = semua) */
require_once 'config.php';
require_once 'pdf.php';
need(['admin', 'kanan_admin']);

$dari   = $_GET['dari']   ?? date('Y-m-01');
$sampai = $_GET['sampai'] ?? date('Y-m-d');
$jenis  = $_GET['jenis']  ?? 'semua';
$pid    = (int)($_GET['produk_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari))   $dari   = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) $sampai = date('Y-m-d');
if ($sampai < $dari) { $t = $dari; $dari = $sampai; $sampai = $t; }
if (!in_array($jenis, ['semua', 'masuk', 'keluar'], true)) $jenis = 'semua';

/* ---------- ambil data sesuai filter ---------- */
$where = ['sl.created_at >= ?', 'sl.created_at < DATE_ADD(?, INTERVAL 1 DAY)'];
$par   = [$dari, $sampai];
if ($jenis !== 'semua') { $where[] = 'sl.jenis = ?';     $par[] = $jenis; }
if ($pid > 0)           { $where[] = 'sl.produk_id = ?'; $par[] = $pid; }

$st = $pdo->prepare(
    'SELECT sl.*, p.kode, p.nama, p.satuan, p.stok AS stok_now, u.nama AS user_nama
     FROM stok_log sl
     JOIN produk p ON p.id = sl.produk_id
     LEFT JOIN users u ON u.id = sl.user_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY sl.created_at ASC, sl.id ASC'
);
$st->execute($par);
$rows = $st->fetchAll();

$namaProduk = 'Semua Produk';
if ($pid > 0) {
    $q = $pdo->prepare('SELECT kode, nama FROM produk WHERE id = ?');
    $q->execute([$pid]);
    $p = $q->fetch();
    $namaProduk = $p ? $p['kode'] . ' - ' . $p['nama'] : 'ID ' . $pid . ' (tidak ditemukan)';
}

/* ---------- total & ringkasan per produk ---------- */
$tm = 0; $tk = 0; $per = [];
foreach ($rows as $r) {
    $qty = (float)$r['qty'];
    $k   = $r['produk_id'];
    if (!isset($per[$k])) $per[$k] = ['kode'=>$r['kode'], 'nama'=>$r['nama'], 'satuan'=>$r['satuan'], 'm'=>0, 'k'=>0, 'now'=>$r['stok_now']];
    if ($r['jenis'] === 'masuk') { $tm += $qty; $per[$k]['m'] += $qty; }
    else                         { $tk += $qty; $per[$k]['k'] += $qty; }
}

/* ---------- susun halaman ---------- */
$tgl = function ($d) { return date('d-m-Y', strtotime($d)); };
$cut = function ($s, $n) { return substr(preg_replace('/\s+/', ' ', (string)$s), 0, $n); };
$lbl = ['semua' => 'SEMUA', 'masuk' => 'STOK MASUK', 'keluar' => 'STOK KELUAR'];
$eq  = str_repeat('=', 152);
$ln  = str_repeat('-', 152);

$CAP = 52; $hdr = []; $pages = []; $cur = [];
$add = function ($l) use (&$cur, &$pages, &$hdr, $CAP) {
    if (count($cur) >= $CAP) { $pages[] = $cur; $cur = $hdr; }   // halaman baru + ulang judul kolom
    $cur[] = $l;
};

foreach ([
    NAMA_TOKO . ' - LAPORAN MUTASI STOK', $eq,
    'Periode         : ' . $tgl($dari) . ' s/d ' . $tgl($sampai),
    'Jenis           : ' . $lbl[$jenis],
    'Produk          : ' . $namaProduk,
    'Dicetak         : ' . date('d-m-Y H:i') . ' oleh ' . ($_SESSION['u']['nama'] ?? '-'),
    $ln, 'RINGKASAN',
    'Jumlah mutasi   : ' . count($rows),
    'Total masuk     : ' . pdf_format_qty($tm),
    'Total keluar    : ' . pdf_format_qty($tk),
    $ln,
] as $l) $add($l);

if ($pid == 0 && $per) {
    $f = '%-12s %-28s %14s %14s %14s';
    $add('RINGKASAN PER PRODUK');
    $add(sprintf($f, 'KODE', 'PRODUK', 'MASUK', 'KELUAR', 'STOK SEKARANG'));
    $add($ln);
    foreach ($per as $x) {
        $add(sprintf($f, $cut($x['kode'], 12), $cut($x['nama'], 28),
            $cut(pdf_format_qty($x['m']) . ' ' . $x['satuan'], 14),
            $cut(pdf_format_qty($x['k']) . ' ' . $x['satuan'], 14),
            $cut(pdf_format_qty($x['now']) . ' ' . $x['satuan'], 14)));
    }
    $add($ln);
}

$fmt = '%-16s %-12s %-24s %-6s %14s %12s %12s %-34s %-14s';
$add('DETAIL MUTASI STOK');
$hd = [sprintf($fmt, 'TANGGAL', 'KODE', 'PRODUK', 'JENIS', 'QTY', 'SEBELUM', 'SESUDAH', 'KETERANGAN', 'USER'), $ln];
foreach ($hd as $l) $add($l);
$hdr = $hd;

if (!$rows) $add('(tidak ada data mutasi stok pada filter ini)');
foreach ($rows as $r) {
    $add(sprintf($fmt,
        date('d-m-Y H:i', strtotime($r['created_at'])),
        $cut($r['kode'], 12), $cut($r['nama'], 24),
        $r['jenis'] === 'masuk' ? 'MASUK' : 'KELUAR',
        $cut(pdf_format_qty($r['qty']) . ' ' . $r['satuan'], 14),
        $cut(pdf_format_qty($r['stok_sebelum']), 12),
        $cut(pdf_format_qty($r['stok_sesudah']), 12),
        $cut($r['keterangan'], 34), $cut($r['user_nama'] ?? '-', 14)));
}
$pages[] = $cur;

/* ---------- kirim PDF ---------- */
$pdf = pdf_halaman($pages, ['landscape' => true, 'size' => 8, 'lead' => 10, 'footer' => true]);
while (ob_get_level()) ob_end_clean();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="mutasi-stok-' . $dari . '_' . $sampai . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
