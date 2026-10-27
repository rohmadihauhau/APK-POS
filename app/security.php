<?php
/**
 * security.php
 *
 * Lapisan keamanan untuk aplikasi Kasir Buah.
 *
 * PENTING:
 * File ini bukan pengganti HTTPS/server security.
 * APK/WebView bisa dimodifikasi, jadi keputusan akses WAJIB
 * tetap dilakukan di server.
 */

if (defined('KASIR_SECURITY_LOADED')) {
    return;
}

define('KASIR_SECURITY_LOADED', true);

/* ============================================================
   SECURITY HEADERS
   ============================================================ */

function security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    /* camera=(self): kamera dipakai untuk scan barcode di halaman kasir */
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');

    /*
     * CSP dibuat cukup longgar agar aplikasi lama tetap berjalan.
     * Setelah aplikasi stabil, CSP bisa diperketat lagi.
     */
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com data:; "
        . "img-src 'self' data: blob:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
}

/* ============================================================
   SESSION SECURITY
   ============================================================ */

function security_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == '443')
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_start();

    security_headers();
}

/* ============================================================
   SESSION REGENERATE
   Panggil setelah login berhasil.
   ============================================================ */

function security_regenerate_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/* ============================================================
   CSRF
   ============================================================ */

function security_csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function security_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' .
        htmlspecialchars(
            security_csrf_token(),
            ENT_QUOTES,
            'UTF-8'
        ) .
        '">';
}

function security_csrf_verify(?string $token = null): bool
{
    $token = $token ?? ($_POST['_csrf'] ?? '');

    return is_string($token)
        && !empty($_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], $token);
}

function security_require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        return;
    }

    if (!security_csrf_verify()) {
        http_response_code(403);
        exit('Permintaan ditolak: token keamanan tidak valid.');
    }
}

/* ============================================================
   LOGIN / ROLE
   ============================================================ */

function security_user(): ?array
{
    return isset($_SESSION['u']) && is_array($_SESSION['u'])
        ? $_SESSION['u']
        : null;
}

function security_require_login(): array
{
    $u = security_user();

    if (!$u || empty($u['id'])) {
        header('Location: login.php');
        exit;
    }

    return $u;
}

function security_require_role(string $role): array
{
    $u = security_require_login();

    if (($u['role'] ?? '') !== $role) {
        http_response_code(403);
        exit('Akses ditolak.');
    }

    return $u;
}

/*
 * Untuk endpoint yang boleh dipakai admin ATAU karyawan.
 */
function security_require_roles(array $roles): array
{
    $u = security_require_login();

    if (!in_array(($u['role'] ?? ''), $roles, true)) {
        http_response_code(403);
        exit('Akses ditolak.');
    }

    return $u;
}

/* ============================================================
   VALIDASI SESSION TERHADAP DATABASE
   Mencegah session lama tetap dianggap valid setelah user
   dinonaktifkan / role diubah.
   ============================================================ */

function security_validate_user(PDO $pdo): array
{
    $u = security_require_login();

    $stmt = $pdo->prepare("
        SELECT id, nama, username, role
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([(int) $u['id']]);
    $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dbUser) {
        $_SESSION = [];
        security_regenerate_session();

        header('Location: login.php');
        exit;
    }

    /*
     * Sinkronkan role/nama dari database.
     * Jangan percaya role yang hanya berasal dari session.
     */
    $_SESSION['u'] = [
        'id'       => (int) $dbUser['id'],
        'nama'     => $dbUser['nama'],
        'username' => $dbUser['username'],
        'role'     => $dbUser['role'],
    ];

    return $_SESSION['u'];
}

/* ============================================================
   POST ONLY
   ============================================================ */

function security_require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Metode tidak diizinkan.');
    }
}

/* ============================================================
   INPUT INTEGER / DECIMAL
   ============================================================ */

function security_int($value, int $default = 0): int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        return $default;
    }

    return (int) $value;
}

function security_decimal($value, float $default = 0): float
{
    if (!is_scalar($value)) {
        return $default;
    }

    $value = str_replace(',', '.', trim((string) $value));

    if (!is_numeric($value)) {
        return $default;
    }

    return (float) $value;
}

/* ============================================================
   AUDIT LOG
   Membutuhkan tabel security_audit_log.
   Jika tabel belum ada, fungsi tidak boleh mematikan aplikasi.
   ============================================================ */

function security_audit(
    PDO $pdo,
    string $action,
    ?string $target = null,
    ?string $detail = null
): void {
    try {
        $u = $_SESSION['u'] ?? [];

        $stmt = $pdo->prepare("
            INSERT INTO security_audit_log
            (
                user_id,
                username,
                role,
                action,
                target,
                detail,
                ip_address,
                user_agent
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $u['id'] ?? null,
            $u['username'] ?? null,
            $u['role'] ?? null,
            $action,
            $target,
            $detail,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        ]);
    } catch (Throwable $e) {
        /*
         * Audit tidak boleh membuat transaksi kasir gagal.
         */
    }
}

/* ============================================================
   RATE LIMIT SEDERHANA BERBASIS SESSION
   Untuk brute-force login yang serius, gunakan tabel
   security_login_attempts / rate limit berbasis IP+username.
   ============================================================ */

function security_login_throttle(
    string $key,
    int $maxAttempts = 5,
    int $windowSeconds = 300
): bool {
    $now = time();

    if (!isset($_SESSION['_login_limit'])) {
        $_SESSION['_login_limit'] = [];
    }

    $row = $_SESSION['_login_limit'][$key] ?? [
        'count' => 0,
        'start' => $now,
    ];

    if (($now - $row['start']) > $windowSeconds) {
        $row = [
            'count' => 0,
            'start' => $now,
        ];
    }

    if ($row['count'] >= $maxAttempts) {
        return false;
    }

    $row['count']++;
    $_SESSION['_login_limit'][$key] = $row;

    return true;
}

/* ============================================================
   CEK ASAL PERMINTAAN (anti-CSRF untuk semua POST)
   Menolak POST yang berasal dari situs lain. Dipadukan dengan
   cookie SameSite=Lax. Header kosong tetap diizinkan agar WebView
   lama tidak rusak.
   ============================================================ */

function security_check_origin(): void
{
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if (in_array($m, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $src = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';

    if ($src === '') {
        return;
    }

    $host = (string) parse_url($src, PHP_URL_HOST);
    $port = parse_url($src, PHP_URL_PORT);
    $asal = $host . ($port ? ':' . $port : '');

    if (strcasecmp($asal, $_SERVER['HTTP_HOST'] ?? '') !== 0) {
        http_response_code(403);
        exit('Permintaan ditolak (asal tidak cocok).');
    }
}
