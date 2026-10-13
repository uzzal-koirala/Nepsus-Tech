# Installs the Nepsus attendance helper on this PC: asks for the device password once,
# tests it, then has Windows send punches to the website every 5 minutes.
$ErrorActionPreference = 'Stop'

# Needs administrator rights to add the Windows task; ask for them if missing.
$admin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) {
    Start-Process powershell.exe -Verb RunAs -ArgumentList ('-NoProfile -ExecutionPolicy Bypass -File "{0}"' -f $PSCommandPath)
    exit
}

$target = Join-Path $env:ProgramData 'NepsusAttendance'
$taskName = 'Nepsus Attendance Sync'

Write-Host ''
Write-Host '  Nepsus attendance helper' -ForegroundColor Cyan
Write-Host '  ------------------------'
$cfg = Get-Content (Join-Path $PSScriptRoot 'config.json') -Raw | ConvertFrom-Json
Write-Host ('  Website : ' + $cfg.site)
Write-Host ('  Device  : ' + $cfg.deviceIp + '  (user ' + $cfg.deviceUser + ')')
Write-Host ''

$ip = Read-Host ('  Device IP address [press Enter for ' + $cfg.deviceIp + ']')
if ($ip) { $cfg.deviceIp = $ip.Trim() }
$secure = Read-Host '  Device admin password' -AsSecureString
$plain = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))

Add-Type -AssemblyName System.Security
$cfg.devicePassword = [Convert]::ToBase64String([Security.Cryptography.ProtectedData]::Protect([Text.Encoding]::UTF8.GetBytes($plain), $null, 'LocalMachine'))

New-Item -ItemType Directory -Force -Path $target | Out-Null
Copy-Item (Join-Path $PSScriptRoot 'sync.ps1') $target -Force
$cfg | ConvertTo-Json | Set-Content (Join-Path $target 'config.json') -Encoding UTF8

Write-Host ''
Write-Host '  Testing: reading the device and sending to the website...'
Remove-Item (Join-Path $target 'last-sync.txt') -ErrorAction SilentlyContinue  # force a full read now
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $target 'sync.ps1')
$ok = $LASTEXITCODE -eq 0
$last = Get-Content (Join-Path $target 'sync.log') -Tail 1
if ($ok) { Write-Host ('  ' + $last) -ForegroundColor Green } else { Write-Host ('  ' + $last) -ForegroundColor Red }

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}"' -f (Join-Path $target 'sync.ps1'))
$everyMinute = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$atStart = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 4) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $everyMinute, $atStart -Principal $principal -Settings $settings -Force | Out-Null

Write-Host ''
if ($ok) {
    Write-Host '  Done. Punches reach the website every 5 minutes, or within a minute after Sync now.' -ForegroundColor Green
} else {
    Write-Host '  Installed, but the test failed (see the message above). Fix it and run Install.bat again.' -ForegroundColor Yellow
}
Write-Host ('  Log file: ' + (Join-Path $target 'sync.log'))
Write-Host ''
Read-Host '  Press Enter to close'
