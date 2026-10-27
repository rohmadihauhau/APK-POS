<?php
/* Menerima foto kamera depan dari login.php (hanya dengan token sekali pakai milik sesi ini). */
require 'config.php';
header('Content-Type: application/json; charset=utf-8');

$ok = false;
$id = (int)($_POST['id'] ?? 0);
$tok = (string)($_POST['token'] ?? '');
$foto = (string)($_POST['foto'] ?? '');
$prefix = 'data:image/jpeg;base64,';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $id > 0
    && isset($_SESSION['foto_token'][$id])
    && hash_equals($_SESSION['foto_token'][$id], $tok)
    && strlen($foto) < 600000
    && strpos($foto, $prefix) === 0
) {
    $bin = base64_decode(substr($foto, strlen($prefix)), true);

    if ($bin !== false && strlen($bin) < 400000 && substr($bin, 0, 3) === "\xFF\xD8\xFF") {
        $st = $pdo->prepare('UPDATE login_log SET foto=? WHERE id=? AND foto IS NULL');
        $st->bindValue(1, $bin, PDO::PARAM_LOB);
        $st->bindValue(2, $id, PDO::PARAM_INT);
        $st->execute();

        unset($_SESSION['foto_token'][$id]);
        $ok = true;
    }
}

echo json_encode(['ok' => $ok]);
