# Kasir Toko Buah - installer Windows 64-bit

Repo ini membangun `KasirBuah-Setup.exe` otomatis lewat GitHub Actions.
Hasilnya bundel PHP + MariaDB + aplikasi, jadi PC kasir tidak perlu XAMPP.

## Membuat installer
1. Buat repo **Private** di GitHub, lalu upload seluruh isi folder ini (termasuk folder `.github`).
2. Buka tab **Actions** > **Build installer Windows 64-bit** > **Run workflow**.
3. Tunggu 5-10 menit. Unduh `KasirBuah-Setup` dari bagian **Artifacts** di halaman run tersebut.
4. (Opsional) Untuk versi bernomor: buat tag, misalnya `v1.0.0`. File installer otomatis muncul di **Releases**.

## Memasang di PC kasir
Jalankan `KasirBuah-Setup.exe` (tidak perlu hak admin). Ikon "Kasir Toko Buah" muncul di Desktop.
Saat pertama dibuka, tabel dibuat otomatis dan akun awal ditampilkan (admin / admin123, karyawan / karyawan123). **Segera ganti** lewat menu User.

Aplikasi terbuka di jendela sendiri. Menutup jendela otomatis mematikan database dan web server.

## Lokasi data
- Database dan password acak: `%LOCALAPPDATA%\KasirBuahData`
- Laporan closing PDF: folder `app\laporan-closing` di dalam folder instalasi
- Update aplikasi: pasang installer versi baru di atas yang lama. Data tidak terhapus.

## Memindahkan data dari XAMPP lama
1. Di phpMyAdmin XAMPP: pilih database `kasir_buah` > **Export** > **Go** (file .sql).
2. Di PC kasir, tarik file .sql itu ke `Restore-Data.bat` di folder instalasi. Tutup aplikasi dulu.

## Jika ada masalah
Lihat `%LOCALAPPDATA%\KasirBuahData\launcher.log`, `php-error.log`, dan `mariadb-error.log`.
