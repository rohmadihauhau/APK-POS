# Launcher Kasir Toko Buah (Windows 64-bit)
# Menyalakan MariaDB + PHP secara tersembunyi, membuka jendela aplikasi, lalu mematikan semuanya saat jendela ditutup.
param([string]$Restore = '')

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName PresentationFramework

$Root     = Split-Path -Parent $MyInvocation.MyCommand.Path
$App      = Join-Path $Root 'app'
$Php      = Join-Path $Root 'php\php.exe'
$MariaBin = Join-Path $Root 'mariadb\bin'
$Data     = Join-Path $env:LOCALAPPDATA 'KasirBuahData'
$DbDir    = Join-Path $Data 'mysql'
$PassFile = Join-Path $Data 'dbpass.txt'
$LogFile  = Join-Path $Data 'launcher.log'
$PhpPort  = 8765
$DbPort   = 3307
$Url      = "http://127.0.0.1:$PhpPort/login.php"

New-Item -ItemType Directory -Force -Path $Data, (Join-Path $Data 'sessions'), (Join-Path $Data 'browser') | Out-Null

function Log($m) { Add-Content -Path $LogFile -Value ("{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $m) }
function Info($m)  { [void][System.Windows.MessageBox]::Show($m, 'Kasir Toko Buah', 'OK', 'Information') }
function Fatal($m) { Log "FATAL: $m"; [void][System.Windows.MessageBox]::Show("$m`n`nDetail: $LogFile", 'Kasir Toko Buah', 'OK', 'Error'); exit 1 }

function Port-Open([int]$port) {
    $c = New-Object System.Net.Sockets.TcpClient
    try { $iar = $c.BeginConnect('127.0.0.1', $port, $null, $null); $ok = $iar.AsyncWaitHandle.WaitOne(300); if ($ok -and $c.Connected) { return $true } return $false }
    catch { return $false } finally { $c.Close() }
}
function Wait-Port([int]$port, [int]$sec) {
    for ($i = 0; $i -lt ($sec * 2); $i++) { if (Port-Open $port) { return $true }; Start-Sleep -Milliseconds 500 }
    return $false
}
function Pick($dir, [string[]]$names) {
    foreach ($n in $names) { $p = Join-Path $dir $n; if (Test-Path $p) { return $p } }
    return $null
}

# ---------- Satu instance saja ----------
$created = $false
$mutex = New-Object System.Threading.Mutex($true, 'KasirBuahRunning', [ref]$created)

# Password database (dibuat acak sekali, disimpan di profil pengguna)
if (-not (Test-Path $PassFile)) {
    $chars = (48..57) + (65..90) + (97..122)
    $pw = -join (1..24 | ForEach-Object { [char]($chars | Get-Random) })
    Set-Content -Path $PassFile -Value $pw -NoNewline
}
$DbPass = (Get-Content $PassFile -Raw).Trim()

$MysqlExe  = Pick $MariaBin @('mariadb.exe', 'mysql.exe')
$AdminExe  = Pick $MariaBin @('mariadb-admin.exe', 'mysqladmin.exe')
$InstallDb = Pick $MariaBin @('mariadb-install-db.exe', 'mysql_install_db.exe')
$MariaD    = Pick $MariaBin @('mariadbd.exe', 'mysqld.exe')
if (-not ($MysqlExe -and $AdminExe -and $InstallDb -and $MariaD -and (Test-Path $Php))) {
    Fatal 'File program tidak lengkap. Install ulang aplikasi.'
}

$script:dbProc = $null
$script:phpProc = $null

function Init-Db {
    if (Test-Path (Join-Path $DbDir 'mysql')) { return }
    Log 'Inisialisasi database pertama kali...'
    $p = Start-Process -FilePath $InstallDb -ArgumentList @("--datadir=`"$DbDir`"", "--password=$DbPass") -WindowStyle Hidden -Wait -PassThru
    if ($p.ExitCode -ne 0 -or -not (Test-Path (Join-Path $DbDir 'mysql'))) { Fatal 'Gagal menyiapkan database.' }
}

function Start-Db {
    if (Port-Open $DbPort) { Log 'Database sudah berjalan.'; return }
    Init-Db
    $dbArgs = @(
        '--no-defaults',
        "--basedir=`"$(Join-Path $Root 'mariadb')`"",
        "--datadir=`"$DbDir`"",
        "--port=$DbPort",
        '--bind-address=127.0.0.1',
        '--skip-name-resolve',
        '--character-set-server=utf8mb4',
        '--collation-server=utf8mb4_general_ci',
        '--sql-mode=NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION',
        '--innodb-buffer-pool-size=64M',
        '--max-connections=30',
        "--log-error=`"$(Join-Path $Data 'mariadb-error.log')`""
    )
    $script:dbProc = Start-Process -FilePath $MariaD -ArgumentList $dbArgs -WindowStyle Hidden -PassThru
    if (-not (Wait-Port $DbPort 60)) { Fatal 'Database tidak mau menyala. Pastikan port 3307 tidak dipakai program lain.' }
    Log 'Database siap.'
}

function Stop-Db {
    if (-not (Port-Open $DbPort)) { return }
    $env:MYSQL_PWD = $DbPass
    try {
        Start-Process -FilePath $AdminExe -ArgumentList @('--protocol=tcp', '-h127.0.0.1', "-P$DbPort", '-uroot', 'shutdown') -WindowStyle Hidden -Wait | Out-Null
    } catch { Log "Shutdown error: $_" }
    for ($i = 0; $i -lt 60 -and (Port-Open $DbPort); $i++) { Start-Sleep -Milliseconds 500 }
    if ($script:dbProc -and -not $script:dbProc.HasExited) { Stop-Process -Id $script:dbProc.Id -Force -ErrorAction SilentlyContinue }
}

# ---------- Mode pemulihan data: Restore-Data.bat file.sql ----------
if ($Restore -ne '') {
    if (-not $created -or (Port-Open $PhpPort)) { Info 'Tutup dulu aplikasi Kasir, lalu ulangi pemulihan data.'; exit 1 }
    if (-not (Test-Path $Restore)) { Fatal "File tidak ditemukan: $Restore" }
    $ans = [System.Windows.MessageBox]::Show("Semua data kasir yang sekarang akan DIGANTI dengan isi file:`n$Restore`n`nLanjutkan?", 'Pulihkan data', 'YesNo', 'Warning')
    if ($ans -ne 'Yes') { exit 0 }
    try {
        Start-Db
        $env:MYSQL_PWD = $DbPass
        $common = @('--protocol=tcp', '-h127.0.0.1', "-P$DbPort", '-uroot', '--default-character-set=utf8mb4')
        $p = Start-Process -FilePath $MysqlExe -ArgumentList ($common + @('-e', '"DROP DATABASE IF EXISTS kasir_buah; CREATE DATABASE kasir_buah CHARACTER SET utf8mb4;"')) -WindowStyle Hidden -Wait -PassThru
        if ($p.ExitCode -ne 0) { Fatal 'Gagal menyiapkan database untuk pemulihan.' }
        $p = Start-Process -FilePath $MysqlExe -ArgumentList ($common + @('kasir_buah')) -RedirectStandardInput $Restore -RedirectStandardError (Join-Path $Data 'restore-error.log') -WindowStyle Hidden -Wait -PassThru
        if ($p.ExitCode -ne 0) { Fatal 'Pemulihan gagal. File .sql mungkin bukan dump database kasir_buah.' }
        Info 'Data berhasil dipulihkan. Buka aplikasi Kasir seperti biasa.'
    } finally { Stop-Db }
    exit 0
}

# ---------- Mode normal ----------
function Find-Browser {
    $c = @(
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe",
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
        "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
        "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
        "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
    )
    foreach ($p in $c) { if (Test-Path $p) { return $p } }
    return $null
}

$Browser = Find-Browser
if (-not $Browser) { Fatal 'Microsoft Edge atau Google Chrome tidak ditemukan.' }
$ProfileDir = Join-Path $Data 'browser'
$BrowserArgs = @("--app=$Url", "--user-data-dir=`"$ProfileDir`"", '--no-first-run', '--no-default-browser-check', '--start-maximized')

try {
    Start-Db

    if (-not (Port-Open $PhpPort)) {
        $env:KASIR_DB_PASS = $DbPass
        $env:KASIR_DB_PORT = "$DbPort"
        $phpArgs = @(
            '-n',
            '-d', "extension_dir=`"$(Join-Path $Root 'php\ext')`"",
            '-d', 'extension=mbstring', '-d', 'extension=openssl', '-d', 'extension=pdo_mysql', '-d', 'extension=fileinfo',
            '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=`"$(Join-Path $Data 'php-error.log')`"",
            '-d', "session.save_path=`"$(Join-Path $Data 'sessions')`"",
            '-d', 'memory_limit=256M', '-d', 'post_max_size=16M', '-d', 'upload_max_filesize=16M',
            '-S', "127.0.0.1:$PhpPort",
            '-t', "`"$App`"",
            "`"$(Join-Path $Root 'router.php')`""
        )
        $script:phpProc = Start-Process -FilePath $Php -ArgumentList $phpArgs -WorkingDirectory $App -WindowStyle Hidden -PassThru
        if (-not (Wait-Port $PhpPort 30)) { Fatal "Web server tidak mau menyala. Pastikan port $PhpPort tidak dipakai program lain." }
        Log 'PHP siap.'
    }

    # Pertama kali: buat tabel + akun awal lewat install.php (hanya bisa dari localhost)
    $flag = Join-Path $Data 'installed.flag'
    if (-not (Test-Path $flag)) {
        try {
            $r = Invoke-WebRequest -Uri "http://127.0.0.1:$PhpPort/install.php" -UseBasicParsing -TimeoutSec 60
            if ($r.Content -match "siap dipakai") {
                Set-Content $flag (Get-Date -Format o)
                if ($r.Content -match 'admin123') {
                    Info "Aplikasi siap dipakai.`n`nAkun awal:`nAdmin: admin / admin123`nKaryawan: karyawan / karyawan123`n`nSegera ganti password lewat menu User."
                }
            } else { Fatal 'Pembuatan tabel database gagal.' }
        } catch { Fatal "Pembuatan tabel database gagal: $_" }
    }

    if (-not $created) {
        # Instance lain sudah jalan: cukup buka jendela baru, jangan matikan server.
        Start-Process -FilePath $Browser -ArgumentList $BrowserArgs | Out-Null
        exit 0
    }

    Start-Process -FilePath $Browser -ArgumentList $BrowserArgs | Out-Null
    Start-Sleep -Seconds 3

    # Tunggu sampai semua proses browser profil ini tertutup
    while ($true) {
        $alive = Get-CimInstance Win32_Process -Filter "Name='msedge.exe' OR Name='chrome.exe'" -ErrorAction SilentlyContinue |
                 Where-Object { $_.CommandLine -and $_.CommandLine.Contains($ProfileDir) }
        if (-not $alive) { break }
        Start-Sleep -Seconds 2
    }
}
catch { Log "ERROR: $_"; Fatal "Terjadi kesalahan: $_" }
finally {
    if ($created) {
        Log 'Menutup aplikasi...'
        if ($script:phpProc -and -not $script:phpProc.HasExited) { Stop-Process -Id $script:phpProc.Id -Force -ErrorAction SilentlyContinue }
        Stop-Db
        try { $mutex.ReleaseMutex() } catch {}
    }
}
