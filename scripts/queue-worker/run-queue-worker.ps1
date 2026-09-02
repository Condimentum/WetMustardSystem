<#
.SYNOPSIS
    Runs the DBMTS Laravel queue worker in a restart loop.

.DESCRIPTION
    `php artisan queue:work` is designed to be restarted periodically (memory
    growth, --max-time, code deploys). This wrapper keeps it running
    indefinitely and logs restarts, so it can be pointed at by a Windows
    Scheduled Task / service instead of relying on a developer leaving a
    terminal open. Not meant to be run directly by hand for normal use -
    see register-queue-worker-task.ps1.
#>

$ErrorActionPreference = 'Continue'

$appRoot = 'C:\xampp\htdocs\WetMustardSystem'
$php = 'C:\xampp\php\php.exe'
$logFile = Join-Path $appRoot 'storage\logs\queue-worker.log'

function Write-Log([string]$message) {
    $line = "[{0}] {1}" -f (Get-Date -Format 's'), $message
    Add-Content -Path $logFile -Value $line
}

Set-Location $appRoot
Write-Log 'Queue worker supervisor starting.'

while ($true) {
    Write-Log 'Starting php artisan queue:work ...'

    & $php artisan queue:work database --queue=default --tries=3 --backoff=10 --max-time=3600 --sleep=3 2>> $logFile

    Write-Log ("queue:work exited with code {0}; restarting in 5s." -f $LASTEXITCODE)
    Start-Sleep -Seconds 5
}
