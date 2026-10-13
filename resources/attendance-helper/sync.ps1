# Nepsus attendance helper: reads punches from the Hikvision attendance device on the
# office network and sends them to the website. Windows runs this every 5 minutes.
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$dir = $PSScriptRoot
$log = Join-Path $dir 'sync.log'

function Write-Log([string] $text) {
    Add-Content -Path $log -Value ("{0}  {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $text)
    if ((Get-Item $log).Length -gt 200KB) { Get-Content $log -Tail 500 | Set-Content $log }
}

try {
    $cfg = Get-Content (Join-Path $dir 'config.json') -Raw | ConvertFrom-Json
    $site = $cfg.site.TrimEnd('/')
    $headers = @{ Authorization = 'Bearer ' + $cfg.token; Accept = 'application/json' }

    # Windows runs this every minute. Read the device every 5 minutes, or sooner
    # when someone pressed "Sync now" on the website.
    $state = Join-Path $dir 'last-sync.txt'
    $due = (-not (Test-Path $state)) -or (((Get-Date) - (Get-Item $state).LastWriteTime).TotalMinutes -ge 4.5)
    if (-not $due) {
        $pending = Invoke-RestMethod -Uri ($site + '/api/attendance/pending') -Headers $headers -TimeoutSec 20
        if (-not $pending.sync) { exit 0 }
    }

    # The device password is stored encrypted for this computer only.
    Add-Type -AssemblyName System.Security
    $bytes = [Security.Cryptography.ProtectedData]::Unprotect([Convert]::FromBase64String($cfg.devicePassword), $null, 'LocalMachine')
    $password = ConvertTo-SecureString ([Text.Encoding]::UTF8.GetString($bytes)) -AsPlainText -Force
    $cred = New-Object System.Management.Automation.PSCredential($cfg.deviceUser, $password)

    # Read the last few days. One extra day either side, in case the device clock's time zone is set wrong.
    $start = (Get-Date).Date.AddDays(-[int] $cfg.days)
    $end = (Get-Date).AddDays(1)
    $searchId = [guid]::NewGuid().ToString('N').Substring(0, 16)
    $punches = New-Object System.Collections.ArrayList
    $position = 0

    do {
        $body = @{ AcsEventCond = @{
            searchID = $searchId; searchResultPosition = $position; maxResults = 30; major = 0; minor = 0
            startTime = $start.ToString('yyyy-MM-ddTHH:mm:ss') + '+05:45'
            endTime = $end.ToString('yyyy-MM-ddTHH:mm:ss') + '+05:45'
        } } | ConvertTo-Json -Depth 4

        $page = (Invoke-RestMethod -Uri ("http://{0}/ISAPI/AccessControl/AcsEvent?format=json" -f $cfg.deviceIp) -Method Post -Body $body -ContentType 'application/json' -Credential $cred -TimeoutSec 30).AcsEvent
        $events = if ($page.InfoList) { @($page.InfoList) } else { @() }

        foreach ($e in $events) {
            if ($e.employeeNoString -and $e.time) {
                # Keep the clock time shown on the device; ignore its time zone label.
                [void] $punches.Add(@{ user = [string] $e.employeeNoString; time = $e.time.Substring(0, 19).Replace('T', ' ') })
            }
        }
        $position += $events.Count
    } while ($page.responseStatusStrg -eq 'MORE' -and $events.Count -gt 0 -and $position -lt 20000)

    $payload = @{ computer = $env:COMPUTERNAME; punches = @($punches) } | ConvertTo-Json -Depth 4 -Compress
    $result = Invoke-RestMethod -Uri ($site + '/api/attendance/punches') -Method Post `
        -Body ([Text.Encoding]::UTF8.GetBytes($payload)) -ContentType 'application/json; charset=utf-8' `
        -Headers $headers -TimeoutSec 60

    Set-Content -Path $state -Value (Get-Date -Format 'o')
    Write-Log ("OK  {0} punches sent, {1} days marked present" -f $punches.Count, $result.marked)
    exit 0
}
catch {
    Write-Log ("ERROR  " + $_.Exception.Message)
    exit 1
}
