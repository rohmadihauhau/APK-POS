<?php
require 'config.php';

/* ============================================================
   PENGATURAN KEAMANAN LOGIN
   ============================================================ */
const PIN_ROLE      = ['admin', 'kanan_admin'];  // role yang wajib PIN 6 digit setelah password benar
const PIN_MAX_SALAH = 5;          // PIN salah maksimal dalam 15 menit
const PIN_BERLAKU   = 300;        // detik: batas waktu mengisi PIN setelah password benar

/* Catat percobaan gagal. Foto dikirim kemudian oleh browser (kamera depan). */
function catat_login($pdo, $uid, $username, $jenis)
{
    $pdo->prepare('INSERT INTO login_log(user_id,username,jenis,ip,agent) VALUES(?,?,?,?,?)')
        ->execute([
            $uid,
            substr((string)$username, 0, 60),
            $jenis,
            substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    $id = (int)$pdo->lastInsertId();

    /* Token sekali pakai agar hanya halaman ini yang boleh mengunggah foto */
    $tok = bin2hex(random_bytes(16));
    $_SESSION['foto_token'] = array_slice(($_SESSION['foto_token'] ?? []) + [$id => $tok], -10, null, true);
    $_SESSION['foto_token'][$id] = $tok;

    /* Simpan maksimal 500 log terakhir */
    try {
        $pdo->exec('DELETE FROM login_log WHERE id <= (SELECT id FROM (SELECT id FROM login_log ORDER BY id DESC LIMIT 1 OFFSET 500) x)');
    } catch (Throwable $e) {}

    return [$id, $tok];
}

function login_selesai($u)
{
    session_regenerate_id(true);
    unset($_SESSION['pin_uid'], $_SESSION['pin_waktu']);
    unset($u['pin_hash']);
    $_SESSION['u'] = $u;
    if (($u['role'] ?? '') === 'kanan_admin') {
        header('Location: produk.php');
        exit;
    }
    header('Location: laporan.php');
    exit;
}

$err = '';
$foto_id = 0;
$foto_tok = '';

/* Batalkan proses PIN, kembali ke login */
if (isset($_GET['batal'])) {
    unset($_SESSION['pin_uid'], $_SESSION['pin_waktu']);
    header('Location: login.php');
    exit;
}

/* Apakah ada admin yang password-nya sudah benar dan tinggal PIN? */
$pending = null;
if (!empty($_SESSION['pin_uid'])) {
    if (time() - (int)($_SESSION['pin_waktu'] ?? 0) > PIN_BERLAKU) {
        unset($_SESSION['pin_uid'], $_SESSION['pin_waktu']);
        $err = 'Waktu memasukkan PIN habis. Silakan login ulang.';
    } else {
        $s = $pdo->prepare('SELECT * FROM users WHERE id=?');
        $s->execute([(int)$_SESSION['pin_uid']]);
        $pending = $s->fetch();
        if (!$pending) {
            unset($_SESSION['pin_uid'], $_SESSION['pin_waktu']);
        }
    }
}

$mode = $pending ? (empty($pending['pin_hash']) ? 'buatpin' : 'pin') : 'login';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $aksi = $_POST['aksi'] ?? 'login';

    /* ---------- 1. USERNAME + PASSWORD ---------- */
    if ($aksi === 'login' && $mode === 'login') {

        /* Batas percobaan: maks 10 password salah per IP dalam 15 menit */
        $ipx = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
        $cx = $pdo->prepare("SELECT COUNT(*) FROM login_log WHERE jenis='password_salah' AND ip=? AND created_at > (NOW() - INTERVAL 15 MINUTE)");
        $cx->execute([$ipx]);
        $diblok = (int)$cx->fetchColumn() >= 10;

        $un = trim($_POST['username'] ?? '');
        $s = $pdo->prepare('SELECT * FROM users WHERE username=?');
        $s->execute([$un]);
        $u = $s->fetch();

        if (!$diblok && $u && password_verify($_POST['password'] ?? '', $u['password'])) {

            if (in_array($u['role'], PIN_ROLE, true)) {
                /* Password benar -> lanjut minta PIN 6 digit */
                session_regenerate_id(true);
                $_SESSION['pin_uid'] = (int)$u['id'];
                $_SESSION['pin_waktu'] = time();
                header('Location: login.php');
                exit;
            }

            if ($u['role'] == 'admin' || $u['role'] == 'kanan_admin') {
                login_selesai($u);
            }

            $err = kasir_masuk($pdo, $u);
            if (!$err) {
                header('Location: index.php');
                exit;
            }

        } else {
            $err = $diblok
                ? 'Terlalu banyak percobaan gagal. Coba lagi dalam 15 menit.'
                : 'Username atau password salah';

            if (!$diblok) {
                if ($u && in_array($u['role'], ['admin', 'kanan_admin'], true)) {
                    /* Admin / Kanan Admin salah password -> catat + foto kamera depan */
                    [$foto_id, $foto_tok] = catat_login($pdo, (int)$u['id'], $u['username'], 'password_salah');
                } else {
                    $pdo->prepare('INSERT INTO login_log(user_id,username,jenis,ip,agent) VALUES(?,?,?,?,?)')
                        ->execute([$u ? (int)$u['id'] : null, substr($un, 0, 60), 'password_salah', $ipx, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
                }
            }
        }

    /* ---------- 2. PIN 6 DIGIT ---------- */
    } elseif ($aksi === 'pin' && $mode === 'pin') {

        $c = $pdo->prepare("SELECT COUNT(*) FROM login_log WHERE user_id=? AND jenis='pin_salah' AND created_at > (NOW() - INTERVAL 15 MINUTE)");
        $c->execute([(int)$pending['id']]);
        $salah = (int)$c->fetchColumn();

        $pin = (string)($_POST['pin'] ?? '');

        if ($salah >= PIN_MAX_SALAH) {

            unset($_SESSION['pin_uid'], $_SESSION['pin_waktu']);
            $mode = 'login';
            $err = 'Terlalu banyak PIN salah. Coba lagi dalam 15 menit.';

        } elseif (preg_match('/^\d{6}$/', $pin) && password_verify($pin, $pending['pin_hash'])) {

            login_selesai($pending);

        } else {

            [$foto_id, $foto_tok] = catat_login($pdo, (int)$pending['id'], $pending['username'], 'pin_salah');
            $sisa = PIN_MAX_SALAH - ($salah + 1);

            if ($sisa <= 0) {
                unset($_SESSION['pin_uid'], $_SESSION['pin_waktu']);
                $mode = 'login';
                $err = 'PIN salah 5 kali. Akun dikunci 15 menit.';
            } else {
                $err = 'PIN salah. Sisa percobaan: ' . $sisa;
            }
        }

    /* ---------- 3. BUAT PIN (pertama kali) ---------- */
    } elseif ($aksi === 'buatpin' && $mode === 'buatpin') {

        $p1 = (string)($_POST['pin'] ?? '');
        $p2 = (string)($_POST['pin2'] ?? '');

        if (!preg_match('/^\d{6}$/', $p1)) {
            $err = 'PIN harus 6 digit angka.';
        } elseif ($p1 !== $p2) {
            $err = 'Konfirmasi PIN tidak sama.';
        } elseif (in_array($p1, ['000000', '111111', '123456', '654321'], true)) {
            $err = 'PIN terlalu mudah ditebak, pilih yang lain.';
        } else {
            $pdo->prepare('UPDATE users SET pin_hash=? WHERE id=?')
                ->execute([password_hash($p1, PASSWORD_DEFAULT), (int)$pending['id']]);
            $pending['pin_hash'] = 'x';
            $s = $pdo->prepare('SELECT * FROM users WHERE id=?');
            $s->execute([(int)$pending['id']]);
            login_selesai($s->fetch());
        }
    }
}
?>
<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#16794c"><link rel="manifest" href="manifest.json"><title>Login - <?= h(NAMA_TOKO) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
<link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__."/assets/style.css") ?>">
<link rel="stylesheet" href="assets/tablet.css?v=<?= @filemtime(__DIR__.'/assets/tablet.css') ?>">
<link rel="stylesheet" href="assets/login.css?v=<?= (int)@filemtime(__DIR__ . '/assets/login.css') ?>">
</head>
<body class="login-bg"><form method="post" class="login-card" autocomplete="off">
<div class="logo"><?= $mode === 'login' ? '🍉' : '🔐' ?></div><h1><?= h(NAMA_TOKO) ?></h1>
<?php if ($mode === 'login'): ?><p>Aplikasi Kasir Toko Buah</p>
<?php elseif ($mode === 'pin'): ?><p>Masukkan PIN 6 digit untuk melanjutkan</p>
<?php else: ?><p>Buat PIN 6 digit untuk akun <?= (($pending['role'] ?? '') === 'kanan_admin') ? 'Kanan Admin' : 'Admin' ?> Anda</p><?php endif; ?>

<?php if($err): ?><div class="alert"><?= h($err) ?></div><div class="catat">Emang agak lain kawan .</div><?php endif; ?>

<?php if ($mode === 'login'): ?>

<input type="hidden" name="aksi" value="login">
<label>Username</label><input name="username" required autofocus placeholder="ID user" autocomplete="username">
<label>Password</label><input type="password" name="password" required placeholder="••••••••" autocomplete="current-password">
<button class="btn">Masuk</button>
<a href="index.php" class="btn ghost" style="display:block;text-align:center;text-decoration:none;margin-top:10px;box-sizing:border-box">🧾 Ke Halaman Kasir</a>

<?php elseif ($mode === 'pin'): ?>

<input type="hidden" name="aksi" value="pin">
<div class="who">👤 <?= h($pending['nama']) ?></div>
<label>PIN 6 digit</label>
<input class="pin-in" id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus placeholder="••••••" autocomplete="one-time-code">
<button class="btn">Masuk</button>
<a href="login.php?batal=1" class="btn ghost" style="display:block;text-align:center;text-decoration:none;margin-top:10px;box-sizing:border-box">← Batal</a>

<?php else: ?>

<input type="hidden" name="aksi" value="buatpin">
<div class="who">👤 <?= h($pending['nama']) ?> &middot; belum punya PIN</div>
<label>PIN baru (6 digit angka)</label>
<input class="pin-in" id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus placeholder="••••••" autocomplete="new-password">
<label>Ulangi PIN</label>
<input class="pin-in" id="pin2" name="pin2" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="••••••" autocomplete="new-password">
<button class="btn">Simpan PIN &amp; Masuk</button>
<a href="login.php?batal=1" class="btn ghost" style="display:block;text-align:center;text-decoration:none;margin-top:10px;box-sizing:border-box">← Batal</a>

<?php endif; ?>
</form>

<script>var LOGIN_MODE=<?= json_encode($mode) ?>,FOTO=<?= json_encode($foto_id ? ['id'=>$foto_id,'token'=>$foto_tok] : null) ?>;</script>
<script src="assets/login.js?v=<?= (int)@filemtime(__DIR__ . '/assets/login.js') ?>"></script>
</body></html>
