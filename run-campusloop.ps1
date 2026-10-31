[CmdletBinding()]
param(
    [ValidateRange(1, 65535)]
    [int]$Port = 8000,

    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$environmentPath = Join-Path $projectRoot '.env'
$routerPath = Join-Path $projectRoot 'public\router.php'
$statePath = Join-Path $projectRoot "storage\campusloop-server-$Port.json"
$server = $null
$stateWritten = $false

function Import-CampusLoopEnvironment([string] $Path) {
    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -match '^\s*#' -or $line -notmatch '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            continue
        }
        $name = $Matches[1]
        $value = $Matches[2]
        if ($value.Length -ge 2 -and $value.StartsWith('"') -and $value.EndsWith('"')) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        [Environment]::SetEnvironmentVariable($name, $value, 'Process')
    }
}

function Test-LocalDatabaseService {
    $hostName = [string]$env:DB_HOST
    if ($hostName -notin @('127.0.0.1', 'localhost', '::1')) {
        Write-Host "[WARNING] DB_HOST is not local ('$hostName'); the local MySQL Windows service check is skipped."
        return
    }
    $services = @(Get-Service -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -match 'mysql|maria' -or $_.DisplayName -match 'mysql|maria'
    })
    if ($services.Count -gt 0) {
        $running = @($services | Where-Object { $_.Status -eq 'Running' })
        if ($running.Count -eq 0) {
            throw 'A MySQL/MariaDB service is installed but stopped. Start it in Windows Services.'
        }
        Write-Host "Database service running: $($running[0].DisplayName)" -ForegroundColor Green
        return
    }
    $listener = @(Get-NetTCPConnection -State Listen -LocalPort ([int]$env:DB_PORT) -ErrorAction SilentlyContinue)
    if ($listener.Count -eq 0) {
        throw 'No running MySQL/MariaDB service or listener was found. Start MySQL and try again.'
    }
    Write-Host '[WARNING] A database listener is available, but its Windows service could not be identified.' -ForegroundColor Yellow
}

function Test-RecordedServer([object] $State) {
    $processId = [int]$State.process_id
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $processId" -ErrorAction SilentlyContinue
    if (-not $process -or $process.Name -notmatch '^php(?:-cgi)?\.exe$') {
        return $false
    }
    if ([string]$process.ExecutablePath -ne [string]$State.executable_path) {
        return $false
    }
    $actualStart = (Get-Process -Id $processId -ErrorAction SilentlyContinue).StartTime.ToUniversalTime().Ticks
    if ([long]$actualStart -ne [long]$State.process_start_ticks) {
        return $false
    }
    $commandLine = [string]$process.CommandLine
    return $commandLine -match [regex]::Escape("-S 127.0.0.1:$Port") `
        -and $commandLine -match [regex]::Escape('-t public') `
        -and $commandLine -match [regex]::Escape('public\router.php')
}

try {
    Write-Host "`nStarting CampusLoop local development server`n" -ForegroundColor Cyan
    if (-not (Test-Path -LiteralPath $routerPath -PathType Leaf)) {
        throw "The PHP router was not found at '$routerPath'."
    }
    if (-not (Test-Path -LiteralPath $environmentPath -PathType Leaf)) {
        throw 'Local .env configuration is missing. Run .\setup-campusloop.ps1 first.'
    }

    $php = Get-Command php -ErrorAction SilentlyContinue
    if (-not $php) {
        throw 'PHP was not found on PATH. Install PHP 8.1+ and reopen PowerShell.'
    }
    $versionOutput = & $php.Source -r 'echo PHP_VERSION;' 2>&1
    if ($LASTEXITCODE -ne 0 -or $versionOutput -notmatch '^\d+\.\d+') {
        throw 'PHP could not report its version.'
    }
    if ([version]$versionOutput -lt [version]'8.1') {
        throw "PHP $versionOutput is too old. CampusLoop requires PHP 8.1 or later."
    }
    Write-Host "PHP $versionOutput found at $($php.Source)." -ForegroundColor Green

    $modules = @(& $php.Source -m 2>&1 | ForEach-Object { ([string]$_).Trim().ToLowerInvariant() })
    $missingExtensions = @(@('pdo_mysql', 'mbstring', 'json', 'session', 'fileinfo') | Where-Object {
        $modules -notcontains $_
    })
    if ($missingExtensions.Count -gt 0) {
        throw "Enable required PHP extensions in php.ini: $($missingExtensions -join ', ')."
    }

    Import-CampusLoopEnvironment $environmentPath
    $databaseKeys = @('DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD')
    $missingSettings = @($databaseKeys | Where-Object {
        [string]::IsNullOrWhiteSpace([string][Environment]::GetEnvironmentVariable($_, 'Process'))
    })
    if ($missingSettings.Count -gt 0 -or $env:DB_PASSWORD -like 'replace-with-*') {
        throw 'Database configuration is incomplete. Check DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD in .env.'
    }
    $databasePort = 0
    if (-not [int]::TryParse($env:DB_PORT, [ref]$databasePort) -or $databasePort -lt 1 -or $databasePort -gt 65535) {
        throw 'DB_PORT in .env must be a number from 1 to 65535.'
    }
    Test-LocalDatabaseService

    $databaseConfigPath = Join-Path $projectRoot 'config\database.php'
    $databaseProbe = 'require $argv[1]; database_connection(); echo 1;'
    $probeOutput = & $php.Source -r $databaseProbe $databaseConfigPath 2>&1
    if ($LASTEXITCODE -ne 0) {
        $safeDetails = ($probeOutput | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ }) -join ' '
        throw "Could not connect to the configured database. Check MySQL, schema setup and .env values. $safeDetails"
    }
    Write-Host 'Database connection verified (credentials were not displayed).' -ForegroundColor Green

    if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'storage\logs') -PathType Container)) {
        throw 'The storage\logs directory is missing.'
    }
    $occupied = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
    if ($occupied.Count -gt 0) {
        $ownerIds = ($occupied | Select-Object -ExpandProperty OwningProcess -Unique) -join ', '
        throw "Port $Port is already in use (process ID(s): $ownerIds). Stop that service or choose another port."
    }

    if (Test-Path -LiteralPath $statePath -PathType Leaf) {
        $previousState = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
        if (Test-RecordedServer $previousState) {
            throw "CampusLoop is already running on port $Port (process ID $($previousState.process_id)). Use .\stop-campusloop.ps1."
        }
        Remove-Item -LiteralPath $statePath -Force
    }

    $stdoutLog = Join-Path $projectRoot "storage\logs\campusloop-$Port.out.log"
    $stderrLog = Join-Path $projectRoot "storage\logs\campusloop-$Port.err.log"
    $arguments = "-S 127.0.0.1:$Port -t public public\router.php"
    $server = Start-Process -FilePath $php.Source -ArgumentList $arguments `
        -WorkingDirectory $projectRoot -PassThru `
        -RedirectStandardOutput $stdoutLog -RedirectStandardError $stderrLog

    $ready = $false
    for ($attempt = 0; $attempt -lt 15; $attempt++) {
        Start-Sleep -Milliseconds 500
        $server.Refresh()
        if ($server.HasExited) {
            break
        }
        $request = [System.Net.HttpWebRequest]::Create("http://127.0.0.1:$Port/")
        $request.Proxy = $null
        $request.Timeout = 750
        try {
            $response = $request.GetResponse()
            try {
                $ready = [int]$response.StatusCode -eq 200
            } finally {
                $response.Close()
            }
        } catch [System.Net.WebException] {
            $ready = $false
        }
        if ($ready) {
            break
        }
    }
    if (-not $ready) {
        $details = @()
        foreach ($log in @($stderrLog, $stdoutLog)) {
            if (Test-Path -LiteralPath $log -PathType Leaf) {
                $details += Get-Content -LiteralPath $log -Tail 12
            }
        }
        $logDetails = if ($details.Count -gt 0) { ($details -join ' ').Trim() } else { 'No PHP output was captured.' }
        throw "The PHP server did not start listening on 127.0.0.1:$Port. $logDetails"
    }

    $state = [ordered]@{
        process_id = $server.Id
        process_start_ticks = (Get-Process -Id $server.Id).StartTime.ToUniversalTime().Ticks
        executable_path = $php.Source
        port = $Port
        project_root = $projectRoot
        launcher_process_id = $PID
    }
    $state | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding UTF8
    $stateWritten = $true
    $url = "http://127.0.0.1:$Port/"
    Write-Host "`nCampusLoop is running at $url" -ForegroundColor Green
    Write-Host 'Document root: public\  |  Router: public\router.php' -ForegroundColor Green
    Write-Host "Server output: $stdoutLog"
    Write-Host "To stop safely from another PowerShell window: .\stop-campusloop.ps1 -Port $Port"
    Write-Host 'Press Ctrl+C here to stop this server.' -ForegroundColor Cyan

    if (-not $NoBrowser) {
        try {
            Start-Process $url | Out-Null
        } catch {
            Write-Host "[WARNING] The server is running, but the browser could not be opened automatically: $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }
    while ($true) {
        $server.Refresh()
        if ($server.HasExited) {
            Write-Host "`nThe PHP server exited with code $($server.ExitCode)." -ForegroundColor Yellow
            if (Test-Path -LiteralPath $stderrLog -PathType Leaf) {
                Get-Content -LiteralPath $stderrLog -Tail 20
            }
            break
        }
        Start-Sleep -Seconds 1
    }
} catch {
    Write-Host "`n[ERROR] $($_.Exception.Message)" -ForegroundColor Red
    Write-Host 'Run .\check-campusloop.ps1 for a full diagnostic report.' -ForegroundColor Yellow
    exit 1
} finally {
    if ($server) {
        $server.Refresh()
        if (-not $server.HasExited) {
            Stop-Process -Id $server.Id -ErrorAction SilentlyContinue
        }
    }
    if ($stateWritten -and (Test-Path -LiteralPath $statePath -PathType Leaf)) {
        Remove-Item -LiteralPath $statePath -Force -ErrorAction SilentlyContinue
    }
}
