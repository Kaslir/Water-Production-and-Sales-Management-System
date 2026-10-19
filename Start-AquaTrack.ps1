param(
    [switch]$CheckOnly,
    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'

function Get-Setting {
    param([string]$Name, [string]$Default)

    $match = Get-Content -LiteralPath $envFile | Where-Object { $_ -match "^$([regex]::Escape($Name))=" } | Select-Object -Last 1
    if (-not $match) { return $Default }
    $value = ($match -split '=', 2)[1].Trim().Trim('"', "'")
    if ($value -eq '') { return $Default }
    return $value
}

function Test-Port {
    param([string]$TargetHost, [int]$Port)

    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $attempt = $client.BeginConnect($TargetHost, $Port, $null, $null)
        if (-not $attempt.AsyncWaitHandle.WaitOne(500)) { return $false }
        $client.EndConnect($attempt)
        return $true
    } catch {
        return $false
    } finally {
        $client.Dispose()
    }
}

function Test-AquaTrack {
    param([string]$Url)

    try {
        $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 4
        return $response.StatusCode -eq 200 -and $response.Content -match 'AquaTrack'
    } catch {
        return $false
    }
}

function Wait-ForPort {
    param([string]$TargetHost, [int]$Port, [int]$Seconds)

    for ($attempt = 0; $attempt -lt ($Seconds * 2); $attempt++) {
        if (Test-Port -TargetHost $TargetHost -Port $Port) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return $false
}

try {
    $projectRoot = $PSScriptRoot
    $xamppRoot = (Resolve-Path -LiteralPath (Join-Path $projectRoot '..\..\..')).Path
    $envFile = Join-Path $projectRoot '.env'
    $php = Join-Path $xamppRoot 'php\php.exe'
    $mysql = Join-Path $xamppRoot 'mysql\bin\mysqld.exe'
    $mysqlConfig = Join-Path $xamppRoot 'mysql\bin\my.ini'
    $url = 'http://127.0.0.1:8000/'

    foreach ($required in @($envFile, $php, (Join-Path $projectRoot 'artisan'), (Join-Path $projectRoot 'vendor\autoload.php'))) {
        if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
            throw "Required file is missing: $required"
        }
    }

    $databaseHost = Get-Setting -Name 'DB_HOST' -Default '127.0.0.1'
    $databasePortText = Get-Setting -Name 'DB_PORT' -Default '3306'
    $databasePort = 0
    if (-not [int]::TryParse($databasePortText, [ref]$databasePort) -or $databasePort -lt 1 -or $databasePort -gt 65535) {
        throw "Invalid DB_PORT in .env: $databasePortText"
    }

    $python = Get-Setting -Name 'PYTHON_BINARY' -Default 'python'
    if (-not (Test-Path -LiteralPath $python -PathType Leaf) -and -not (Get-Command $python -ErrorAction SilentlyContinue)) {
        throw "Python was not found: $python. Install Python or set PYTHON_BINARY in .env."
    }

    $databaseReady = Test-Port -TargetHost $databaseHost -Port $databasePort
    $appPortReady = Test-Port -TargetHost '127.0.0.1' -Port 8000
    if ($CheckOnly) {
        Write-Host "Project: $projectRoot"
        Write-Host "PHP: $php"
        Write-Host "Python: $python"
        Write-Host "Database $databaseHost`:$databasePort listening: $databaseReady"
        Write-Host "Application port 8000 listening: $appPortReady"
        exit 0
    }

    if (-not $databaseReady) {
        if ($databaseHost -notin @('127.0.0.1', 'localhost')) {
            throw "Database $databaseHost`:$databasePort is unavailable. This launcher only starts a local XAMPP MySQL server."
        }
        if (-not (Test-Path -LiteralPath $mysql -PathType Leaf) -or -not (Test-Path -LiteralPath $mysqlConfig -PathType Leaf)) {
            throw 'XAMPP MySQL files were not found. Check your XAMPP installation.'
        }
        Write-Host "Starting XAMPP MySQL on port $databasePort..."
        Start-Process -FilePath $mysql -ArgumentList ('--defaults-file="' + $mysqlConfig + '" --standalone') -WorkingDirectory $xamppRoot -WindowStyle Hidden | Out-Null
        if (-not (Wait-ForPort -TargetHost $databaseHost -Port $databasePort -Seconds 30)) {
            throw "MySQL did not become available on $databaseHost`:$databasePort. Check XAMPP's MySQL log and port settings."
        }
    } else {
        Write-Host 'MySQL is already running.'
    }

    if ($appPortReady) {
        if (-not (Test-AquaTrack -Url $url)) {
            throw 'Port 8000 is occupied, but it is not serving a working AquaTrack page.'
        }
        Write-Host 'AquaTrack is already running.'
    } else {
        $logFolder = Join-Path $projectRoot 'storage\logs'
        if (-not (Test-Path -LiteralPath $logFolder -PathType Container)) {
            throw "Laravel log folder is missing: $logFolder"
        }
        $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
        $outputLog = Join-Path $logFolder "launcher-$stamp-output.log"
        $errorLog = Join-Path $logFolder "launcher-$stamp-error.log"
        Write-Host 'Starting the Laravel server on port 8000...'
        Start-Process -FilePath $php -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', '--port=8000') -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput $outputLog -RedirectStandardError $errorLog | Out-Null

        $ready = $false
        for ($attempt = 0; $attempt -lt 40; $attempt++) {
            if (Test-AquaTrack -Url $url) {
                $ready = $true
                break
            }
            Start-Sleep -Milliseconds 500
        }
        if (-not $ready) {
            throw "AquaTrack did not load. Check $errorLog and storage\logs\laravel.log."
        }
    }

    if (-not $NoBrowser) {
        Start-Process $url | Out-Null
    }
    Write-Host "AquaTrack is ready at $url"
    exit 0
} catch {
    Write-Host "AquaTrack could not start: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
