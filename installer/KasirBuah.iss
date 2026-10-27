; Inno Setup - dipanggil oleh GitHub Actions: ISCC /DAppVersion=1.0.0 installer\KasirBuah.iss
#ifndef AppVersion
  #define AppVersion "1.0.0"
#endif

[Setup]
AppId={{6B1F2E5A-7C34-4D0B-9C8E-2F5A1D3B4E77}
AppName=Kasir Toko Buah
AppVersion={#AppVersion}
AppPublisher=Toko Buah JHU
DefaultDirName={localappdata}\Programs\KasirBuah
DefaultGroupName=Kasir Toko Buah
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
Compression=lzma2/max
SolidCompression=yes
OutputDir=..\dist
OutputBaseFilename=KasirBuah-Setup
SetupIconFile=app.ico
UninstallDisplayIcon={app}\app.ico
AppMutex=KasirBuahRunning
WizardStyle=modern

[Tasks]
Name: "desktopicon"; Description: "Buat ikon di Desktop"; Flags: checkedonce

[Files]
Source: "..\stage\*"; DestDir: "{app}"; Flags: recursesubdirs createallsubdirs ignoreversion

[Dirs]
Name: "{app}\app\laporan-closing"
Name: "{app}\app\backup"

[Icons]
Name: "{autodesktop}\Kasir Toko Buah"; Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\launcher.ps1"""; WorkingDir: "{app}"; IconFilename: "{app}\app.ico"; Tasks: desktopicon
Name: "{userprograms}\Kasir Toko Buah"; Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\launcher.ps1"""; WorkingDir: "{app}"; IconFilename: "{app}\app.ico"

[Run]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\launcher.ps1"""; WorkingDir: "{app}"; Description: "Jalankan Kasir Toko Buah"; Flags: nowait postinstall skipifsilent
