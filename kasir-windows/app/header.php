<?php
require_once 'config.php';
need(['admin', 'kanan_admin']);

$u = $_SESSION['u'];
$cur = basename($_SERVER['PHP_SELF']);

function nv($f, $i, $t) {
    global $cur;

    return '<a href="' . $f . '" class="' . ($cur == $f ? 'on' : '') . '">
                <span>' . $i . '</span>' . $t . '
            </a>';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">

<meta name="viewport"
      content="width=device-width,initial-scale=1,viewport-fit=cover">

<meta name="theme-color" content="#16794c">

<link rel="manifest" href="manifest.json">

<title><?= h(NAMA_TOKO) ?> - Kasir</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
      rel="stylesheet" media="print" onload="this.media='all'">

<link rel="stylesheet"
      href="assets/style.css?v=<?= filemtime(__DIR__ . "/assets/style.css") ?>">

<link rel="stylesheet" href="assets/tablet.css?v=<?= @filemtime(__DIR__.'/assets/tablet.css') ?>">
</head>

<body>

<aside class="side">

    <div class="brand">
        <i>🍉</i>
        <?= h(NAMA_TOKO) ?>
    </div>

    <?php if ($u['role'] === 'admin'): ?>
        <?= nv('laporan.php', '📊', 'Laporan') ?>
        <?= nv('data.php', '📋', 'Data') ?>
    <?php endif; ?>

    <?= nv('produk.php', '📦', 'Produk') ?>

    <?= nv('stok.php', '📥', 'Stok Masuk / Keluar') ?>

    <?php if ($u['role'] === 'admin'): ?>
        <?= nv('users.php', '👥', 'User') ?>
        <?= nv('biaya.php', '💸', 'Biaya') ?>
        <?= nv('month_end.php', '🗓️', 'Month End') ?>
        <?= nv('login_log.php', '🛡️', 'Log Login') ?>
    <?php endif; ?>

    <div class="me">

        <div class="av">
            <?= strtoupper(substr($u['nama'], 0, 1)) ?>
        </div>

        <div>
            <b><?= h($u['nama']) ?></b>
            <small><?= $u['role'] === 'kanan_admin' ? 'Kanan Admin' : ucfirst($u['role']) ?></small>
        </div>

    </div>

    <a href="logout.php" class="lo">
        <span>🚪</span>
        Keluar
    </a>

</aside>

<main>