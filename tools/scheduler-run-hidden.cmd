@echo off
REM ===========================================================================
REM ASTALOKA -- jalankan Laravel scheduler tanpa membuka jendela.
REM
REM Dipakai oleh Windows Task Scheduler ("ASTALOKA Scheduler"), lewat
REM `tools\scheduler-run-hidden.vbs`.
REM
REM Kenapa file ini ada
REM ==================
REM
REM Task Scheduler yang menjalankan `php.exe` (aplikasi konsol) selalu membuka
REM jendela konsol baru setiap kali task berjalan. Kalau task-nya dijadwalkan
REM tiap menit, itu satu jendela baru per menit -- dan karena Windows Terminal
REM jadi default terminal di Win11, jendela itu muncul sebagai TAB BARU di
REM Windows Terminal, lalu hilang sendiri begitu prosesnya selesai.
REM
REM Gejalanya persis seperti pada bug report: terminal "misterius" membuka tab
REM baru sendiri, beberapa saat kemudian tabnya hilang. Penyebabnya bukan
REM aplikasi -- ini perilaku Task Scheduler plus `php.exe` yang butuh console.
REM
REM Perintah di sini dijalankan lewat `cmd.exe /c`, jadi Task Scheduler tidak
REM perlu membuka jendela: JENDELA cmd-nya disembunyikan oleh VBS (window
REM style 0), sementara console-nya tetap ada untuk proses turunan.
REM
REM Kenapa output ditulis ke file, bukan ke layar
REM =============================================
REM
REM Karena jendela disembunyikan, output ke STDOUT tidak terlihat siapa pun --
REM termasuk saat task gagal. Semua stdout/stderr ditulis ke file supaya
REM kegagalan tetap bisa dibaca.
REM
REM Output `schedule:run` sendiri praktis kosong: flag `--whisper` menahan
REM pesan "No scheduled commands are ready to run." yang tanpa itu muncul
REM SETIAP menit. Isi file baru bermakna kalau ada error -- dan itu justru
REM saat paling perlu dibaca.
REM ===========================================================================

setlocal

REM --- Lokasi (diturunkan dari lokasi file ini, bukan ditulis mati) --------
REM %~dp0 = ...\astaloka\tools\  ->  dua tingkat di atas = ...\astaloka
set "TOOLS_DIR=%~dp0"
for %%I in ("%TOOLS_DIR%..") do set "BASE_DIR=%%~fI"

set "BACKEND_DIR=%BASE_DIR%\backend"
set "PHP_EXE=C:\laragon\bin\php\php-8.4.19-nts-Win32-vs17-x64\php.exe"

set "LOG_DIR=%BACKEND_DIR%\storage\logs"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%" >nul 2>&1

REM Nama file output unik per run, supaya dua run yang tumpang tindih tidak
REM berebut satu file yang sama.
set "STAMP=%RANDOM%%RANDOM%"
set "OUT_FILE=%LOG_DIR%\scheduler-out-%STAMP%.txt"

REM --- Jalankan scheduler -------------------------------------------------
REM `--whisper` menahan pesan "No scheduled commands are ready to run."
REM yang tanpa itu muncul SETIAP menit.
REM
REM `--no-ansi` TIDAK dipakai: Artisan masih bisa mencetak kode escape
REM warna dari tabel/box yang ia gambar sendiri, dan kode itu jadi sampah
REM di file log.
REM
REM `chcp 65001` menyetel code page UTF-8 untuk pesan-pesan cmd sendiri.
REM Catatan: ini BUKAN yang membuat isi file log jadi UTF-8 -- PHP menulis
REM byte UTF-8 langsung ke stream yang dialihkan, tanpa melewati code page
REM konsol. Bytes-nya sudah UTF-8 sejak awal; kalau file itu terlihat
REM kacau, itu karena dibaca dengan pembaca yang salah encoding, bukan
REM karena cmd salah set code page.
chcp 65001 >nul 2>&1
cd /d "%BACKEND_DIR%"

"%PHP_EXE%" "%BACKEND_DIR%\artisan" schedule:run --whisper > "%OUT_FILE%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"

REM --- Bersihkan file output ----------------------------------------------
REM Hapus file kosong: output schedule:run yang normal itu kosong, dan
REM tanpa penghapusan file-nya akan menumpuk tiap menit.
if not exist "%OUT_FILE%" goto :selesai

for %%A in ("%OUT_FILE%") do set "OUT_SIZE=%%~zA"

if "%OUT_SIZE%"=="0" (
    del /q "%OUT_FILE%" >nul 2>&1
    goto :selesai
)

REM --- Gabungkan ke log utama ---------------------------------------------
REM File log ditulis dari sini, BUKAN dari VBS, supaya tidak ada dua
REM penulis ke satu file (VBS membacanya sebelum cmd selesai = terkunci).
REM
REM Yang ditulis HANYA kalau ada masalah:
REM   - exit code bukan 0, atau
REM   - output-nya menandakan ada error.
REM
REM Run yang sukses dan tidak menghasilkan output TIDAK ditulis sama sekali.
REM Alasannya: `schedule:run` yang sukses tetap mencetak baris "Running
REM [...] DONE" untuk setiap job yang jalan, dan job `migrasi:bersihkan`
REM jalan tiap 10 menit -- jadi log akan penuh baris sukses yang tidak
REM pernah dibaca siapa pun, dan baris error yang SESUNGGUHNYA jadi
REM sulit ditemukan di antaranya.
REM
REM Deteksi error pakai kata "error"/"exception" (case-insensitive) --
REM output Artisan selalu memakai kata-kata itu saat ada yang gagal.
set "LOG_FILE=%LOG_DIR%\scheduler-run.log"

set "IS_ERROR=0"
if not "%EXIT_CODE%"=="0" set "IS_ERROR=1"

findstr /i /c:"error" /c:"exception" /c:"failed" "%OUT_FILE%" >nul 2>&1
if not errorlevel 1 set "IS_ERROR=1"

if "%IS_ERROR%"=="0" (
    del /q "%OUT_FILE%" >nul 2>&1
    goto :selesai
)

>> "%LOG_FILE%" echo --- %DATE% %TIME% exit_code=%EXIT_CODE% ---
type "%OUT_FILE%" >> "%LOG_FILE%"
del /q "%OUT_FILE%" >nul 2>&1

:selesai
exit /b %EXIT_CODE%
