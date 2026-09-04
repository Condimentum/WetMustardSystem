<#
.SYNOPSIS
    One-time setup: registers the DBMTS queue worker as a Windows Scheduled
    Task so background emails (notifications + reports) actually get sent.

.DESCRIPTION
    Must be run ONCE from an elevated ("Run as Administrator") PowerShell
    prompt. Creates a Scheduled Task that:
      - starts run-queue-worker.ps1 automatically at system startup
      - restarts it automatically if it stops/crashes
      - runs as SYSTEM so it works even with nobody logged in

    Safe to re-run - it replaces any existing task with the same name.

.NOTES
    This does not require NSSM or any third-party tool; it only uses the
    Windows Task Scheduler cmdlets built into Windows.
#>

$taskName = 'DBMTS Queue Worker'
$scriptPath = Join-Path $PSScriptRoot 'run-queue-worker.ps1'

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error 'Please re-run this script from an elevated ("Run as Administrator") PowerShell prompt.'
    exit 1
}

$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`""

$trigger = New-ScheduledTaskTrigger -AtStartup

$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero)

try {
    Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger `
        -Principal $principal -Settings $settings -Force -ErrorAction Stop | Out-Null

    Start-ScheduledTask -TaskName $taskName -ErrorAction Stop
} catch {
    Write-Error "Failed to register/start scheduled task '$taskName': $($_.Exception.Message)"
    exit 1
}

Write-Host "Registered and started scheduled task '$taskName'."
Write-Host "Logs: $((Join-Path (Split-Path $PSScriptRoot -Parent | Split-Path -Parent) 'storage\logs\queue-worker.log'))"
Write-Host "Check status any time with: Get-ScheduledTask -TaskName '$taskName' | Get-ScheduledTaskInfo"
