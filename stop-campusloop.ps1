[CmdletBinding()]
param(
    [ValidateRange(1, 65535)]
    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$statePath = Join-Path $projectRoot "storage\campusloop-server-$Port.json"

if (-not (Test-Path -LiteralPath $statePath -PathType Leaf)) {
    Write-Host "[INFO] No CampusLoop server is recorded on port $Port." -ForegroundColor Yellow
    exit 0
}

try {
    $state = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
    $expectedRoot = [System.IO.Path]::GetFullPath($projectRoot).TrimEnd('\')
    $recordedRoot = [System.IO.Path]::GetFullPath([string]$state.project_root).TrimEnd('\')
    $sameProject = [string]::Equals($expectedRoot, $recordedRoot, [StringComparison]::OrdinalIgnoreCase)
    $samePort = [int]$state.port -eq $Port
    if (-not $sameProject -or -not $samePort) {
        throw 'The server state file does not belong to this project and port. No process was stopped.'
    }

    $processId = [int]$state.process_id
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $processId" -ErrorAction SilentlyContinue
    if (-not $process) {
        Remove-Item -LiteralPath $statePath -Force
        Write-Host "[INFO] The recorded process has already exited; removed its stale state file." -ForegroundColor Yellow
        exit 0
    }
    $isPhpExecutable = $process.Name -match '^php(?:-cgi)?\.exe$'
    $isExpectedExecutable = [string]$process.ExecutablePath -eq [string]$state.executable_path
    if (-not $isPhpExecutable -or -not $isExpectedExecutable) {
        throw 'The recorded process is not the expected PHP executable. No process was stopped.'
    }

    $running = Get-Process -Id $processId -ErrorAction SilentlyContinue
    $sameProcessStart = $running -and $running.StartTime.ToUniversalTime().Ticks -eq [long]$state.process_start_ticks
    if (-not $sameProcessStart) {
        throw 'The recorded process ID has been reused or changed. No process was stopped.'
    }
    $commandLine = [string]$process.CommandLine
    $correctServerCommand = $commandLine -match [regex]::Escape("-S 127.0.0.1:$Port")
    $correctDocumentRoot = $commandLine -match [regex]::Escape('-t public')
    $correctRouter = $commandLine -match [regex]::Escape('public\router.php')
    if (-not $correctServerCommand -or -not $correctDocumentRoot -or -not $correctRouter) {
        throw 'The process command line does not match the CampusLoop router. No process was stopped.'
    }

    Stop-Process -Id $processId -ErrorAction Stop
    $running.Refresh()
    if (-not $running.WaitForExit(5000)) {
        throw "PHP process $processId did not stop within five seconds. No other processes were targeted."
    }
    Remove-Item -LiteralPath $statePath -Force
    Write-Host "CampusLoop server on port $Port stopped (process ID $processId)." -ForegroundColor Green
} catch {
    Write-Host "[ERROR] $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
