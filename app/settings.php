<?php
// Pengaturan untuk versi Windows (diisi otomatis oleh launcher.ps1 lewat environment variable).
// Jangan isi password di sini; password database dibuat acak saat pertama kali dijalankan.
define('DB_HOST', '127.0.0.1');
define('DB_PORT', getenv('KASIR_DB_PORT') ?: '3307');
define('DB_NAME', 'kasir_buah');
define('DB_USER', 'root');
define('DB_PASS', getenv('KASIR_DB_PASS') ?: '');
define('NAMA_TOKO', 'TOKO BUAH JHU');
define('DEBUG', false);
