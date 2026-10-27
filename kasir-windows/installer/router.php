<?php
// Router untuk PHP built-in server. Meniru aturan .htaccess (Apache tidak dipakai di versi Windows).
$p = '/' . ltrim(str_replace('\\', '/', rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH))), '/');

$blokir = false;
if (strpos($p, '..') !== false)                                                   $blokir = true;
if (preg_match('#/\.#', $p))                                                      $blokir = true; // file/folder tersembunyi
if (preg_match('#^/(database|backup|backups|private|storage|laporan-closing)(/|$)#i', $p)) $blokir = true;
if (preg_match('#^/(config|security|settings|pdf|migrasi)\.php$#i', $p))          $blokir = true;
if (preg_match('#\.(env|ini|log|sql|sqlite|sqlite3|db|bak|backup|old|orig|sh|ps1|bat)$#i', $p)) $blokir = true;

if ($blokir) {
    http_response_code(403);
    exit('403 Forbidden');
}
return false; // sajikan file / skrip seperti biasa
