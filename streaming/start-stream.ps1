# ---------------------------------------------------------------------------
# start-stream.ps1
#
# One-command launcher for the GH PIK2 live CCTV feed (go2rtc edition).
#   - Downloads go2rtc (win64) into this folder if it's missing.
#   - Starts it using the existing go2rtc.yaml (which holds the RTSP sources).
#   - go2rtc pulls rtsp://.../live2.sdp and restreams it as low-latency WebRTC.
#   - The Vue app connects to ws://localhost:1984/api/ws?src=cam1..4
#
# Kenapa go2rtc? Latency WebRTC < 1 detik, jadi live tidak lagi delay seperti
# saat memakai HLS/MediaMTX.
#
# Usage (from the streaming/ folder):
#   powershell -ExecutionPolicy Bypass -File .\start-stream.ps1
# ---------------------------------------------------------------------------

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$here    = Split-Path -Parent $MyInvocation.MyCommand.Path
$exePath = Join-Path $here 'go2rtc.exe'
$ymlPath = Join-Path $here 'go2rtc.yaml'
$logPath = Join-Path $here 'go2rtc.log'
$status  = Join-Path $here '_status.txt'

function Set-Status([string]$msg) {
    "$([DateTime]::Now.ToString('s')) $msg" | Out-File -FilePath $status -Append -Encoding utf8
}
"" | Out-File -FilePath $status -Encoding utf8   # reset status log

function Download-Go2rtc {
    Set-Status 'download=start'
    Write-Host 'go2rtc not found - downloading the latest win64 build...' -ForegroundColor Cyan

    # Ask GitHub for the latest release and pick the win64 asset.
    $api = 'https://api.github.com/repos/AlexxIT/go2rtc/releases/latest'
    $rel = Invoke-RestMethod -Uri $api -Headers @{ 'User-Agent' = 'gh-pik2' }

    # go2rtc ships a bare go2rtc_win64.zip (containing go2rtc.exe).
    $asset = $rel.assets | Where-Object { $_.name -like '*win64.zip' } | Select-Object -First 1
    if (-not $asset) {
        # Fallback: some releases publish the raw .exe directly.
        $asset = $rel.assets | Where-Object { $_.name -like '*win64.exe' } | Select-Object -First 1
    }
    if (-not $asset) { throw 'Could not find a win64 asset in the latest go2rtc release.' }

    $dl = Join-Path $here $asset.name
    Write-Host ("Downloading {0} ({1:N1} MB)..." -f $asset.name, ($asset.size / 1MB)) -ForegroundColor Cyan
    Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $dl -Headers @{ 'User-Agent' = 'gh-pik2' }

    if ($asset.name -like '*.zip') {
        Write-Host 'Extracting go2rtc.exe...' -ForegroundColor Cyan
        $tmp = Join-Path $here '_g2r_tmp'
        if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
        Expand-Archive -Path $dl -DestinationPath $tmp -Force
        $found = Get-ChildItem -Path $tmp -Recurse -Filter 'go2rtc*.exe' | Select-Object -First 1
        if (-not $found) { throw 'go2rtc.exe not found inside the downloaded zip.' }
        Copy-Item $found.FullName $exePath -Force
        Remove-Item $tmp -Recurse -Force
        Remove-Item $dl -Force
    } else {
        Copy-Item $dl $exePath -Force
        Remove-Item $dl -Force
    }

    Set-Status 'download=done'
    Write-Host 'go2rtc ready.' -ForegroundColor Green
}

try {
    if (-not (Test-Path $exePath)) { Download-Go2rtc } else { Set-Status 'download=skip_already_present' }
} catch {
    Set-Status "download=FAILED $($_.Exception.Message)"
    throw
}

Write-Host ''
Write-Host '===================================================================' -ForegroundColor Green
Write-Host ' Starting go2rtc. Keep this window open while you use the app.'         -ForegroundColor Green
Write-Host ' WebRTC feed : ws://localhost:1984/api/ws?src=cam1..4  (what Vue plays)' -ForegroundColor Green
Write-Host ' Web UI      : http://localhost:1984/  (test players + stream status)'  -ForegroundColor Green
Write-Host ' Stop        : press Ctrl+C'                                            -ForegroundColor Green
Write-Host '===================================================================' -ForegroundColor Green
Write-Host ''

Set-Status 'go2rtc=starting'
# Run go2rtc with our config; mirror its logs to go2rtc.log for easy inspection.
& $exePath -config $ymlPath *>&1 | Tee-Object -FilePath $logPath
