[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$environmentPath = Join-Path $projectRoot '.env'
$examplePath = Join-Path $projectRoot '.env.example'
$prerequisiteErrors = 0

function Write-Setup([string] $Message) {
    Write-Host "[SETUP] $Message" -ForegroundColor Cyan
}

Write-Host "`nCampusLoop first-time setup`n" -ForegroundColor Cyan

if (-not (Test-Path -LiteralPath $examplePath -PathType Leaf)) {
    Write-Host '[ERROR] .env.example is missing. Restore the project configuration before continuing.' -ForegroundColor Red
    exit 1
}
if (Test-Path -LiteralPath $environmentPath -PathType Leaf) {
    Write-Host '[WARNING] An existing .env file was found. It was not changed or overwritten.' -ForegroundColor Yellow
    Write-Setup 'Review the existing file yourself; no database or stored records were changed.'
} else {
    Copy-Item -LiteralPath $examplePath -Destination $environmentPath
    $randomBytes = New-Object byte[] 32
    $randomGenerator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $randomGenerator.GetBytes($randomBytes)
    } finally {
        $randomGenerator.Dispose()
    }
    $secret = -join ($randomBytes | ForEach-Object { $_.ToString('x2') })
    $environmentLines = @(Get-Content -LiteralPath $environmentPath)
    for ($index = 0; $index -lt $environmentLines.Count; $index++) {
        if ($environmentLines[$index] -match '^APP_SECRET=') {
            $environmentLines[$index] = 'APP_SECRET=' + $secret
            break
        }
    }

    $securePassword = Read-Host 'Enter the password for the MySQL application user (leave blank to edit .env yourself)' -AsSecureString
    $passwordPointer = [IntPtr]::Zero
    try {
        $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
        $password = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
        if (-not [string]::IsNullOrEmpty($password)) {
            for ($index = 0; $index -lt $environmentLines.Count; $index++) {
                if ($environmentLines[$index] -match '^DB_PASSWORD=') {
                    $environmentLines[$index] = 'DB_PASSWORD=' + $password
                    break
                }
            }
        }
    } finally {
        if ($passwordPointer -ne [IntPtr]::Zero) {
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
        }
        if ($securePassword) {
            $securePassword.Dispose()
        }
    }
    $contents = ($environmentLines -join "`r`n") + "`r`n"
    [System.IO.File]::WriteAllText($environmentPath, $contents, (New-Object System.Text.UTF8Encoding($false)))
    Write-Setup 'Created .env without changing any existing configuration. The application secret and any entered password were not displayed.'
}

$php = Get-Command php -ErrorAction SilentlyContinue
if (-not $php) {
    $prerequisiteErrors++
    Write-Host '[ERROR] PHP is not on PATH. Install PHP 8.1+ and reopen PowerShell.' -ForegroundColor Red
} else {
    $versionOutput = & $php.Source -r 'echo PHP_VERSION;' 2>&1
    if ($LASTEXITCODE -eq 0 -and $versionOutput -match '^\d+\.\d+') {
        if ([version]$versionOutput -ge [version]'8.1') {
            Write-Setup "PHP $versionOutput is available."
        } else {
            $prerequisiteErrors++
            Write-Host "[ERROR] PHP $versionOutput is too old; CampusLoop requires PHP 8.1+." -ForegroundColor Red
        }
        $modules = @(& $php.Source -m 2>&1 | ForEach-Object { ([string]$_).Trim().ToLowerInvariant() })
        foreach ($extension in @('pdo_mysql', 'mbstring', 'json', 'session', 'fileinfo')) {
            if ($modules -contains $extension) {
                Write-Setup "PHP extension '$extension' is enabled."
            } else {
                $prerequisiteErrors++
                Write-Host "[ERROR] Required PHP extension '$extension' is missing. Enable it in the loaded php.ini." -ForegroundColor Red
            }
        }
        $iniOutput = & $php.Source --ini 2>&1
        $iniLine = $iniOutput | Where-Object { $_ -match 'Loaded Configuration File' } | Select-Object -First 1
        if ($iniLine -match ':\s*(.+?)\s*$') {
            $iniPath = $Matches[1].Trim().Trim('"')
            Write-Setup "PHP configuration file: $iniPath"
        }
    } else {
        $prerequisiteErrors++
        Write-Host '[ERROR] PHP is on PATH but failed its version check.' -ForegroundColor Red
    }
}

$mysqlServices = @(Get-Service -ErrorAction SilentlyContinue | Where-Object {
    $_.Name -match 'mysql|maria' -or $_.DisplayName -match 'mysql|maria'
})
if ($mysqlServices.Count -eq 0) {
    Write-Host '[WARNING] No MySQL/MariaDB service was detected. Install MySQL and start its Windows service.' -ForegroundColor Yellow
} else {
    foreach ($service in $mysqlServices) {
        $color = if ($service.Status -eq 'Running') { 'Green' } else { 'Yellow' }
        $label = if ($service.Status -eq 'Running') { 'SETUP' } else { 'WARNING' }
        Write-Host "[$label] Database service '$($service.DisplayName)': $($service.Status)." -ForegroundColor $color
    }
}

Write-Host @'

Next steps (safe for existing databases):
  1. Start the MySQL service from Windows Services if it is stopped.
  2. In an elevated/administrator MySQL client, import the initial schema only
     into a new, empty database:
    cmd /c "mysql -u root -p < database\schema.sql"
  3. Import the built-in roles and categories into the new database:
    cmd /c "mysql -u root -p second_story < database\seed.sql"
     The seed adds built-in roles and categories only; it creates no demo user or listing.
  4. Create the least-privilege app account and grant access. Use the same
     password you entered above (or set DB_PASSWORD in .env):
       CREATE USER 'second_story_app'@'127.0.0.1' IDENTIFIED BY 'your-unique-password';
       GRANT SELECT, INSERT, UPDATE, DELETE ON second_story.* TO 'second_story_app'@'127.0.0.1';
  5. Confirm DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD in .env.
  6. Run .\check-campusloop.ps1, then .\run-campusloop.ps1.

Never re-import the initial schema or seed over a database containing data.
This script does not create, drop, migrate, or delete database records.
'@

if (-not (Test-Path -LiteralPath $environmentPath -PathType Leaf)) {
    Write-Host '[ERROR] Local configuration could not be prepared.' -ForegroundColor Red
    exit 1
}
if ($prerequisiteErrors -gt 0) {
    Write-Host "`nLocal configuration was prepared, but resolve the PHP prerequisites above before launching the app." -ForegroundColor Yellow
    exit 1
}
Write-Host "`nSetup preparation complete. Follow the manual database steps above before launching the app." -ForegroundColor Green
