<#
.SYNOPSIS
    One-time setup: registers the WinMan MO sync as a periodic Windows
    Scheduled Task so the MO Search fallback cache stays fresh.

.DESCRIPTION
    Must be run ONCE from an elevated ("Run as Administrator") PowerShell
    prompt, on the WEB/APP SERVER (the machine with PHP + this htdocs folder -
    e.g. condi-web), NOT on the SQL Server box (condi-sql1). SQL Server is
    just a database engine reached over the network via .env (DB_HOST /
    WINMAN_DB_HOST) - it has no PHP and does not need any scheduled task.

    Creates a Scheduled Task that:
      - runs `php artisan winman:sync-manufacturing-orders` every 3 minutes,
        indefinitely, starting now
      - will NOT start a new run if the previous one is still in progress
        (Task Scheduler's own "IgnoreNew" policy) - this is on top of the
        code-level Cache::lock() protection already inside the job itself,
        so overlapping/duplicate runs are guarded against twice over
      - runs as SYSTEM so it works even with nobody logged in

    Safe to re-run - it replaces any existing task with the same name.
#>

$taskName = 'DBMTS WinMan MO Sync'
$appRoot = 'C:\xampp\htdocs\WetMustardSystem'
$php = 'C:\xampp\php\php.exe'

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error 'Please re-run this script from an elevated ("Run as Administrator") PowerShell prompt.'
    exit 1
}

$action = New-ScheduledTaskAction -Execute $php `
    -Argument 'artisan winman:sync-manufacturing-orders' `
    -WorkingDirectory $appRoot

# Omitting -RepetitionDuration leaves Duration empty, which Task Scheduler
# treats as "repeat indefinitely". Passing [TimeSpan]::MaxValue instead is
# rejected as out of range (P99999999DT23H59M59S).
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes 3)

$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 2)

try {
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger `
        -Principal $principal -Settings $settings -Force -ErrorAction Stop | Out-Null

    Start-ScheduledTask -TaskName $taskName -ErrorAction Stop
} catch {
    Write-Error "Failed to register/start scheduled task '$taskName': $($_.Exception.Message)"
    exit 1
}

Write-Host "Registered and started scheduled task '$taskName' (runs every 3 minutes)."
Write-Host "Check status any time with: Get-ScheduledTask -TaskName '$taskName' | Get-ScheduledTaskInfo"
Write-Host 'Verify it is syncing with: php artisan winman:sync-manufacturing-orders'
