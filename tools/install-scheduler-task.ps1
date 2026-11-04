<#
.SYNOPSIS
    Daftarkan (atau perbaiki) Windows Task Scheduler untuk ASTALOKA.

.DESCRIPTION
    Membuat task "ASTALOKA Scheduler" yang menjalankan Laravel scheduler
    (`php artisan schedule:run`) setiap menit -- TANPA membuka jendela
    konsol.

    Kenapa tidak `php.exe` langsung?
    -------------------------------
    Task Scheduler yang menjalankan aplikasi konsol selalu membuka jendela
    konsol baru setiap kali task berjalan. Kalau task-nya tiap menit, itu
    satu jendela per menit -- dan karena Windows Terminal jadi default
    terminal di Win11, jendela itu muncul sebagai TAB BARU, lalu hilang
    sendiri beberapa detik kemudian.

    Skrip ini memakai `tools\scheduler-run-hidden.vbs` sebagai perantara:
    `WScript.Shell.Run` dengan window style `0` menjalankan perintah yang
    sama dengan console yang tetap ada (supaya proses turunan Symfony
    Process bisa jalan) tapi tidak pernah ditampilkan.

.PARAMETER Remove
    Hapus task-nya, lalu keluar.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\install-scheduler-task.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\install-scheduler-task.ps1 -Remove
#>

[CmdletBinding()]
param(
    [switch]$Remove
)

$ErrorActionPreference = 'Stop'

$TaskName   = 'ASTALOKA Scheduler'
$ToolsDir   = Split-Path -Parent $MyInvocation.MyCommand.Path
$RootDir    = Split-Path -Parent $ToolsDir
$BackendDir = Join-Path $RootDir 'backend'
$VbsScript  = Join-Path $ToolsDir 'scheduler-run-hidden.vbs'
$Wscript    = Join-Path $env:SystemRoot 'System32\wscript.exe'

# --- Mode hapus ----------------------------------------------------------
if ($Remove) {
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Host "Task '$TaskName' dihapus." -ForegroundColor Yellow
    } else {
        Write-Host "Task '$TaskName' tidak ada." -ForegroundColor Yellow
    }
    return
}

# --- Prasyarat -----------------------------------------------------------
if (-not (Test-Path $VbsScript)) {
    throw "Tidak ditemukan: $VbsScript"
}
if (-not (Test-Path $Wscript)) {
    throw "Tidak ditemukan: $Wscript"
}

$existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "Task '$TaskName' sudah ada -- akan diperbarui." -ForegroundColor Cyan
}

# --- Action, trigger, principal, settings --------------------------------
$action = New-ScheduledTaskAction `
    -Execute $Wscript `
    -Argument ('"' + $VbsScript + '"') `
    -WorkingDirectory $BackendDir

# Setiap menit, selama 10 tahun ( Intentional: `schedule:run` yang memeriksa
# apakah ada yang perlu dijalankan, dan memeriksa setiap menit itu jauh lebih
# murah daripada membiarkan satu jadwal terlewat).
$trigger = New-ScheduledTaskTrigger `
    -Once `
    -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 1) `
    -RepetitionDuration (New-TimeSpan -Days 3650)

# `Interactive` berarti task berjalan di sesi user yang sedang login --
# persis seperti task bawaan Laravel, dan tanpa perlu password tersimpan.
$principal = New-ScheduledTaskPrincipal `
    -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) `
    -LogonType Interactive `
    -RunLevel Limited

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 5) `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable

# `Hidden` TIDAK mencegah jendela muncul -- itu hanya menyembunyikan entri
# task di Task Scheduler UI. Yang mencegah jendela adalah VBS-nya.
$settings.Hidden = $true

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'ASTALOKA Laravel scheduler (tanpa jendela konsol)' `
    -Force | Out-Null

Write-Host "Task '$TaskName' terdaftar." -ForegroundColor Green
Write-Host '  Aksi   : ' $Wscript ' "' $VbsScript '"'
Write-Host '  Folder :' $BackendDir
Write-Host '  Jadwal : setiap menit'
Write-Host ''
Write-Host 'Cek hasil:' -ForegroundColor Cyan
Write-Host '  schtasks /query /tn "ASTALOKA Scheduler" /v /fo LIST'
Write-Host '  type backend\storage\logs\scheduler-status.txt'
