[CmdletBinding()]
param()

$ErrorActionPreference = 'Continue'
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$errorCount = 0
$warningCount = 0
$successCount = 0

function Write-Success([string] $Message) {
    $script:successCount++
    Write-Host "[SUCCESS] $Message" -ForegroundColor Green
}

function Write-WarningMessage([string] $Message) {
    $script:warningCount++
    Write-Host "[WARNING] $Message" -ForegroundColor Yellow
}

function Write-ErrorMessage([string] $Message) {
    $script:errorCount++
    Write-Host "[ERROR] $Message" -ForegroundColor Red
}

function Read-EnvironmentFile([string] $Path) {
    $values = @{}
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        return $values
    }

    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -match '^\s*#' -or $line -notmatch '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            continue
        }
        $name = $Matches[1]
        $value = $Matches[2]
        if ($value.Length -ge 2 -and $value.StartsWith('"') -and $value.EndsWith('"')) {
            $value = $value.Substring(1, $value.Length - 2)
        }
        $values[$name] = $value
    }

    return $values
}

Write-Host "`nCampusLoop local setup diagnostics`n" -ForegroundColor Cyan

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    Write-ErrorMessage 'PHP was not found on PATH. Install PHP 8.1+ and reopen PowerShell.'
} else {
    $versionOutput = & $php.Source -r 'echo PHP_VERSION;' 2>&1
    if ($LASTEXITCODE -ne 0 -or $versionOutput -notmatch '^\d+\.\d+') {
        Write-ErrorMessage 'PHP is on PATH but could not report its version.'
    } elseif ([version]$versionOutput -lt [version]'8.1') {
        Write-ErrorMessage "PHP $versionOutput is installed; CampusLoop requires PHP 8.1 or later."
    } else {
        Write-Success "PHP $versionOutput found at $($php.Source)."
        $iniOutput = & $php.Source --ini 2>&1
        $iniLine = $iniOutput | Where-Object { $_ -match 'Loaded Configuration File' } | Select-Object -First 1
        if ($iniLine -match ':\s*(.+?)\s*$' -and $Matches[1] -ne '(none)') {
            $iniPath = $Matches[1].Trim().Trim('"')
            Write-Success "PHP configuration file: $iniPath"
        } else {
            Write-WarningMessage 'PHP has no loaded php.ini. Create one from php.ini-development before enabling extensions.'
        }

        $moduleOutput = & $php.Source -m 2>&1
        $modules = @($moduleOutput | ForEach-Object { ([string]$_).Trim().ToLowerInvariant() })
        foreach ($extension in @('pdo_mysql', 'mbstring', 'json', 'session', 'fileinfo')) {
            if ($modules -contains $extension) {
                Write-Success "PHP extension '$extension' is enabled."
            } else {
                Write-ErrorMessage "Required PHP extension '$extension' is not enabled. Update php.ini and restart PowerShell."
            }
        }
    }
}

$mysqlClient = Get-Command mysql -ErrorAction SilentlyContinue
$mysqlServices = @(Get-Service -ErrorAction SilentlyContinue | Where-Object {
    $_.Name -match 'mysql|maria' -or $_.DisplayName -match 'mysql|maria'
})
$installRoots = @(
    (Join-Path $env:ProgramFiles 'MySQL'),
    (Join-Path $env:ProgramFiles 'MariaDB'),
    (Join-Path ${env:ProgramFiles(x86)} 'MySQL'),
    (Join-Path ${env:ProgramFiles(x86)} 'MariaDB')
)
$databaseInstallations = @($installRoots | Where-Object { $_ -and (Test-Path -LiteralPath $_ -PathType Container) })
if ($mysqlClient) {
    Write-Success "MySQL client found at $($mysqlClient.Source)."
} elseif ($databaseInstallations.Count -gt 0) {
    Write-Success "MySQL/MariaDB installation folder found: $($databaseInstallations[0])"
} else {
    Write-WarningMessage 'MySQL command-line client was not found on PATH. The Windows service may still be installed.'
}
if ($mysqlServices.Count -gt 0) {
    foreach ($service in $mysqlServices) {
        if ($service.Status -eq 'Running') {
            Write-Success "Database service '$($service.DisplayName)' is running."
        } else {
            Write-WarningMessage "Database service '$($service.DisplayName)' is $($service.Status). Start it before running CampusLoop."
        }
    }
} else {
    Write-WarningMessage 'No MySQL/MariaDB Windows service was detected. Install a local database server or verify your remote database settings.'
}

$requiredFiles = @(
    'README.md',
    '.env.example',
    'check-campusloop.ps1',
    'setup-campusloop.ps1',
    'run-campusloop.ps1',
    'stop-campusloop.ps1',
    'index.html',
    'app.js',
    'styles.css',
    'app',
    'bin',
    'config',
    'database',
    'public\index.php',
    'public\router.php',
    'views',
    'config\app.php',
    'config\database.php',
    'app\Messaging.php',
    'database\schema.sql',
    'database\seed.sql',
    'database\migrations\20261101_add_learner_discovery_metadata.sql',
    'database\migrations\20261101_add_messaging_and_notifications.sql',
    'public\messages.js',
    'public\notifications.js',
    'views\dashboard\messages.php',
    'views\dashboard\notifications.php',
    'storage\uploads',
    'storage\logs'
)
foreach ($relativePath in $requiredFiles) {
    $path = Join-Path $projectRoot $relativePath
    if (Test-Path -LiteralPath $path) {
        Write-Success "Project path exists: $relativePath"
    } else {
        Write-ErrorMessage "Required project path is missing: $relativePath"
    }
}

$environmentPath = Join-Path $projectRoot '.env'
$environment = Read-EnvironmentFile $environmentPath
if (-not (Test-Path -LiteralPath $environmentPath -PathType Leaf)) {
    Write-WarningMessage 'Local .env configuration is missing. Run .\setup-campusloop.ps1 to prepare it.'
} else {
    $databaseKeys = @('DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD')
    $missingKeys = @($databaseKeys | Where-Object {
        -not $environment.ContainsKey($_) -or [string]::IsNullOrWhiteSpace([string]$environment[$_])
    })
    if ($missingKeys.Count -gt 0 -or $environment['DB_PASSWORD'] -like 'replace-with-*') {
        Write-ErrorMessage 'Database settings in .env are incomplete. Check DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD.'
    } else {
        Write-Success 'Database configuration keys are present in .env (values were not displayed).'
    }
    if ($environment.ContainsKey('APP_SECRET') -and $environment['APP_SECRET'].Length -ge 32 -and $environment['APP_SECRET'] -notlike 'replace-with-*') {
        Write-Success 'Application secret is configured without displaying it.'
    } else {
        Write-WarningMessage 'APP_SECRET is missing or shorter than 32 characters. Generate a unique local secret before use.'
    }
}

foreach ($relativeDirectory in @('storage', 'storage\uploads', 'storage\logs')) {
    $directory = Join-Path $projectRoot $relativeDirectory
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) {
        continue
    }
    $probePath = Join-Path $directory ('.campusloop-write-check-' + [guid]::NewGuid().ToString('N') + '.tmp')
    try {
        $stream = [System.IO.File]::Open($probePath, [System.IO.FileMode]::CreateNew, [System.IO.FileAccess]::Write, [System.IO.FileShare]::None)
        $stream.Dispose()
        Remove-Item -LiteralPath $probePath -Force -ErrorAction Stop
        Write-Success "Write access is available: $relativeDirectory"
    } catch {
        if (Test-Path -LiteralPath $probePath -PathType Leaf) {
            Remove-Item -LiteralPath $probePath -Force -ErrorAction SilentlyContinue
        }
        Write-ErrorMessage "PHP may not be able to write to '$relativeDirectory'. Check Windows folder permissions."
    }
}

Write-Host "`nDiagnostic summary: $successCount success, $warningCount warning(s), $errorCount error(s)." -ForegroundColor Cyan
if ($errorCount -gt 0) {
    exit 1
}
exit 0
