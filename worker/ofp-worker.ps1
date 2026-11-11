# Ofnoa Print Upscaler - local worker (Windows)
# Runs Real-ESRGAN on THIS computer for free and sends the result back to the site.
#
# How to run:  right-click > "Run with PowerShell"
#   or:        powershell -ExecutionPolicy Bypass -File .\ofp-worker.ps1
# Options:     -Once  (process at most one job and exit)
#
# First run downloads realesrgan-ncnn-vulkan (about 45 MB) next to this script.
# Needs a GPU with Vulkan support (any NVIDIA / AMD / Intel graphics from the last ~8 years).

param(
    [switch]$Once,
    [int]$PollSeconds = 15
)

$Api   = '__OFP_API__'
$Key   = '__OFP_KEY__'
$Model = 'realesrgan-x4plus'
$Zip   = 'https://github.com/xinntao/Real-ESRGAN/releases/download/v0.2.5.0/realesrgan-ncnn-vulkan-20220424-windows.zip'

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ProgressPreference = 'SilentlyContinue'

$Here    = if ($PSScriptRoot) { $PSScriptRoot } else { (Get-Location).Path }
$ToolDir = Join-Path $Here 'realesrgan'
$Work    = Join-Path $Here 'ofp-work'
$Headers = @{ 'X-OFP-Worker-Key' = $Key }

function Log($msg) { Write-Host ("[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $msg) }

function Get-Exe {
    $exe = Get-ChildItem -Path $ToolDir -Filter 'realesrgan-ncnn-vulkan.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($exe) { return $exe.FullName }
    Log 'Downloading Real-ESRGAN (one time)...'
    New-Item -ItemType Directory -Force -Path $ToolDir | Out-Null
    $zipPath = Join-Path $ToolDir 'realesrgan.zip'
    Invoke-WebRequest -Uri $Zip -OutFile $zipPath -UseBasicParsing
    Expand-Archive -Path $zipPath -DestinationPath $ToolDir -Force
    Remove-Item $zipPath -Force
    $exe = Get-ChildItem -Path $ToolDir -Filter 'realesrgan-ncnn-vulkan.exe' -Recurse | Select-Object -First 1
    if (-not $exe) { throw 'realesrgan-ncnn-vulkan.exe not found after download' }
    return $exe.FullName
}

function Send-Fail($id, $msg) {
    try {
        Invoke-RestMethod -Method Post -Uri "$Api/fail/$id" -Headers $Headers -ContentType 'application/json' `
            -Body (@{ message = "$msg" } | ConvertTo-Json) | Out-Null
    } catch { Log "Could not report failure: $($_.Exception.Message)" }
}

function Invoke-Job($exe, $next) {
    $id  = [int]$next.job
    $in  = Join-Path $Work "in-$id.png"
    $out = Join-Path $Work "out-$id.png"
    Log "Job #$id - downloading source"
    Invoke-WebRequest -Uri "$Api/source/$id" -Headers $Headers -OutFile $in -UseBasicParsing
    Log "Job #$id - upscaling x4 on this PC"
    $modelsDir = Join-Path (Split-Path $exe) 'models'
    $p = Start-Process -FilePath $exe -ArgumentList @('-i', "`"$in`"", '-o', "`"$out`"", '-n', $Model, '-s', '4', '-f', 'png', '-m', "`"$modelsDir`"") `
        -NoNewWindow -Wait -PassThru
    if ($p.ExitCode -ne 0 -or -not (Test-Path $out) -or (Get-Item $out).Length -lt 100) {
        throw "realesrgan exit code $($p.ExitCode) - check that the graphics driver supports Vulkan"
    }
    Log ("Job #{0} - uploading result ({1:N1} MB)" -f $id, ((Get-Item $out).Length / 1MB))
    Invoke-RestMethod -Method Post -Uri "$Api/result/$id" -Headers $Headers -ContentType 'image/png' -InFile $out -TimeoutSec 600 | Out-Null
    Log "Job #$id - done"
    Remove-Item $in, $out -Force -ErrorAction SilentlyContinue
}

New-Item -ItemType Directory -Force -Path $Work | Out-Null
$exe = Get-Exe
Log "Worker ready. Site: $Api"
Log 'Waiting for jobs... (Ctrl+C to stop)'

while ($true) {
    $next = $null
    try {
        $next = Invoke-RestMethod -Method Get -Uri ("$Api/next?t=" + [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()) -Headers $Headers -TimeoutSec 30
    } catch {
        Log "Site not reachable: $($_.Exception.Message)"
    }
    if ($next -and [int]$next.job -gt 0) {
        try { Invoke-Job $exe $next }
        catch {
            Log "Job #$($next.job) failed: $($_.Exception.Message)"
            Send-Fail $next.job $_.Exception.Message
        }
        if ($Once) { break }
        continue
    }
    if ($Once) { Log 'No jobs waiting.'; break }
    Start-Sleep -Seconds $PollSeconds
}
