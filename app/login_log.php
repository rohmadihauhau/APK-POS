<?php
require 'config.php';
need('admin');

/* Tampilkan foto (khusus admin) */
if (isset($_GET['foto'])) {
    $s = $pdo->prepare('SELECT foto FROM login_log WHERE id=?');
    $s->execute([(int)$_GET['foto']]);
    $f = $s->fetchColumn();

    if (!$f) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    echo $f;
    exit;
}

/* Hapus semua log */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'hapus_semua') {
    $pdo->exec('DELETE FROM login_log');
    header('Location: login_log.php?ok=1');
    exit;
}

$rows = $pdo->query('SELECT id,user_id,username,jenis,ip,agent,created_at,(foto IS NOT NULL) AS ada_foto FROM login_log ORDER BY id DESC LIMIT 200')->fetchAll();

include 'header.php';
?>
<div class="panel">
<h3>🛡️ Log Percobaan Login Gagal</h3>
<p style="color:var(--mut);font-size:.85rem;margin-bottom:10px">
Berisi password admin yang salah dan PIN yang salah, lengkap dengan foto kamera depan (200 terakhir).
</p>
<?php if (isset($_GET['ok'])): ?><div class="alert okmsg" style="margin:10px 0">✅ Log dihapus.</div><?php endif; ?>

<div class="tw"><table>
<tr><th>Waktu</th><th>Username</th><th>Jenis</th><th>IP</th><th>Perangkat</th><th>Foto</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
<td><?= h(date('d-m-Y H:i:s', strtotime($r['created_at']))) ?></td>
<td><?= h($r['username']) ?></td>
<td><span class="tag low"><?= $r['jenis'] === 'pin_salah' ? 'PIN salah' : 'Password salah' ?></span></td>
<td><?= h($r['ip']) ?></td>
<td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($r['agent']) ?>"><?= h($r['agent']) ?></td>
<td>
<?php if ($r['ada_foto']): ?>
<a href="login_log.php?foto=<?= (int)$r['id'] ?>" target="_blank"><img src="login_log.php?foto=<?= (int)$r['id'] ?>" alt="foto" style="width:84px;height:63px;object-fit:cover;border-radius:8px;display:block"></a>
<?php else: ?><small style="color:var(--mut)">tidak ada foto (kamera ditolak / halaman berpindah sebelum foto terkirim)</small><?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="6" class="empty">Belum ada percobaan login gagal.</td></tr><?php endif; ?>
</table></div>

<?php if ($rows): ?>
<form method="post" onsubmit="return confirm('Hapus semua log dan foto?')" style="margin-top:14px">
<input type="hidden" name="aksi" value="hapus_semua">
<button class="btn" style="background:#d93a3a">Hapus semua log</button>
</form>
<?php endif; ?>
</div>
<?php include 'footer.php'; ?>
