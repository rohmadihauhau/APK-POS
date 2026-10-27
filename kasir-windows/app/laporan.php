<?php
require 'config.php';
need('admin');
if(isset($_GET['tutup'])){ tutup_shift($pdo,(int)$_GET['tutup']); header('Location: laporan.php'); exit; }
include 'header.php';

$os=$pdo->query("SELECT s.*,u.nama FROM shift s JOIN users u ON u.id=s.user_id WHERE s.status='buka'")->fetch();
$tgl=$_GET['tgl'] ?? date('Y-m-d');
$s=$pdo->prepare('SELECT t.*,u.nama kasir FROM transaksi t LEFT JOIN users u ON u.id=t.user_id WHERE DATE(t.created_at)=? ORDER BY t.id DESC');
$s->execute([$tgl]);
$rows=$s->fetchAll();
$sum=array_sum(array_column($rows,'total')); $qr=0; $tn=0;
foreach($rows as $r) if($r['metode']=='qris') $qr+=$r['total']; else $tn+=$r['total'];
$dsk=array_sum(array_column($rows,'diskon_nominal')); $bruto=array_sum(array_column($rows,'subtotal'));
$cl=$pdo->query("SELECT s.*,u.nama FROM shift s JOIN users u ON u.id=s.user_id WHERE s.status='tutup' ORDER BY s.id DESC LIMIT 30")->fetchAll();
?>
<?php if($os): ?><div class="alert okmsg" style="margin-bottom:14px">🟢 Shift kasir terbuka: <b><?= h($os['nama']) ?></b> sejak <?= h($os['buka']) ?> · <a href="?tutup=<?= (int)$os['id'] ?>" onclick="return confirm('Tutup paksa shift ini?')" style="color:#d93a3a">Tutup paksa</a></div><?php endif; ?>
<div class="stats">
<div class="stat"><small>Tunai</small><b><?= rp($tn) ?></b></div>
<div class="stat"><small>QRIS</small><b><?= rp($qr) ?></b></div>
<div class="stat"><small>Diskon</small><b><?= rp($dsk) ?></b></div>
<div class="stat"><small>Pendapatan</small><b><?= rp($sum) ?></b></div>
<div class="stat"><small>Transaksi</small><b><?= count($rows) ?></b></div>
<div class="stat"><small>Rata-rata</small><b><?= rp($rows ? $sum/count($rows) : 0) ?></b></div>
</div>
<div class="panel"><form class="frm"><input type="date" name="tgl" value="<?= h($tgl) ?>"><button class="btn">Tampilkan</button></form>
<div class="tw"><table><tr><th>Kode</th><th>Waktu</th><th>Kasir</th><th>Metode</th><th>Subtotal</th><th>Diskon</th><th>Total</th><th>Kembali</th></tr>
<?php foreach($rows as $r): ?><tr><td><?= h($r['kode']) ?></td><td><?= date('H:i',strtotime($r['created_at'])) ?></td><td><?= h($r['kasir']) ?></td><td><span class="tag <?= $r['metode']=='qris'?'adm':'' ?>"><?= strtoupper(h($r['metode'])) ?></span></td><td><?= rp($r['subtotal']>0 ? $r['subtotal'] : $r['total']) ?></td><td><?= (float)$r['diskon_nominal']>0 ? '<span class="tag low">'.(float)$r['diskon_persen'].'% · -'.rp($r['diskon_nominal']).'</span>' : '-' ?></td><td><b><?= rp($r['total']) ?></b></td><td><?= rp($r['kembali']) ?></td></tr><?php endforeach; if(!$rows) echo '<tr><td colspan="8" class="empty">Belum ada transaksi</td></tr>'; ?></table></div></div>
<div class="panel"><h3>Riwayat Closing Kasir</h3><div class="tw"><table>
<tr><th>Tutup</th><th>Kasir</th><th>Modal</th><th>Transaksi</th><th>Tunai</th><th>QRIS</th><th>Kas Seharusnya</th><th>Uang Aktual</th><th>Selisih</th><th>Status</th><th>PDF</th></tr>
<?php foreach($cl as $c):
$modal=$c['modal']===null ? 0 : (float)$c['modal'];
$tunai=(float)$c['total_tunai'];
$qris=(float)$c['total_qris'];
$seharusnya=$modal+$tunai;
$sel=$c['selisih'];
?>
<tr>
<td><?= h($c['tutup']) ?></td><td><?= h($c['nama']) ?></td><td><?= rp($modal) ?></td><td><?= (int)$c['jml'] ?></td><td><?= rp($tunai) ?></td><td><?= rp($qris) ?></td><td><b><?= rp($seharusnya) ?></b></td>
<td><?= $c['input_pendapatan']===null ? '-' : rp($c['input_pendapatan']) ?></td>
<td><?php if($sel===null) echo '-'; elseif((float)$sel>0) echo '<span class="tag">+'.rp($sel).'</span>'; elseif((float)$sel<0) echo '<span class="tag low">-'.rp(abs($sel)).'</span>'; else echo '<span class="tag">Rp 0</span>'; ?></td>
<td><?php if($c['input_pendapatan']===null): ?><span class="tag">TUTUP ADMIN</span><?php elseif((float)$sel>0): ?><span class="tag">LEBIH</span><?php elseif((float)$sel<0): ?><span class="tag low">KURANG</span><?php else: ?><span class="tag">SESUAI</span><?php endif; ?></td>
<td><a href="closingpdf.php?id=<?= (int)$c['id'] ?>" target="_blank">📄 Lihat PDF</a></td>
</tr>
<?php endforeach; if(!$cl) echo '<tr><td colspan="11" class="empty">Belum ada closing</td></tr>'; ?></table></div></div>
<?php include 'footer.php'; ?>
