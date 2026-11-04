' =============================================================================
' ASTALOKA -- jalankan Laravel scheduler TANPA membuka jendela.
'
' Dipakai oleh Windows Task Scheduler ("ASTALOKA Scheduler").
'
' KENAPA HARUS ADA SKRIP INI
' ==========================
'
' Task Scheduler yang menjalankan `php.exe` (aplikasi konsol) SELALU membuka
' jendela konsol baru setiap kali task berjalan. Kalau task-nya dijadwalkan
' tiap menit, itu berarti satu jendela baru per menit -- dan karena Windows
' Terminal menjadi default terminal di Win11, jendela itu muncul sebagai TAB
' BARU di Windows Terminal, lalu hilang sendiri begitu prosesnya selesai
' (beberapa detik kemudian).
'
' Gejalanya persis seperti pada bug report: terminal "misterius" membuka tab
' baru sendiri, beberapa saat kemudian tabnya hilang. Penyebabnya bukan
' aplikasi -- ini murni perilaku Task Scheduler plus `php.exe` yang butuh
' console.
'
' Yang dilakukan skrip ini
' ========================
'
' `WScript.Shell.Run` dengan parameter kedua `0` (window style hidden):
'
'   - `0` = sembunyikan jendela. Command Prompt-nya tetap dialokasikan
'     (console-nya ADA, jadi proses turunan tetap bisa jalan), tapi tidak
'     pernah ditampilkan -- jadi tidak ada tab yang muncul di Windows
'     Terminal.
'   - `True` (parameter ketiga) = tunggu sampai selesai, supaya exit code
'     bisa diteruskan ke Task Scheduler dan kegagalannya terlihat di "Last
'     Result".
'
' Kenapa `php.exe`, bukan `php-win.exe`?
' ==================================
'
' `php-win.exe` adalah varian GUI: ia tidak membuat console sama sekali, tapi
' konsekuensinya `proc_open()` dari dalam PHP tidak punya console untuk
' diwariskan. Scheduler Laravel menjalankan command-nya sebagai PROSES TURUNAN
' lewat Symfony Process, dan proses turunan itu butuh `cmd.exe`. Tanpa
' console, pemanggilan itu gagal.
'
' Yang dijalankan skrip ini adalah `scheduler-run-hidden.cmd`, bukan php
' langsung. Alasannya teknis: `WScript.Shell.Run` membungkus command string
' dengan kutip secara otomatis, sehingga operator redirect `>` ikut masuk
' ke dalam kutip -- dan `cmd.exe` memperlakukannya sebagai teks biasa, bukan
' sebagai redirect. Perintahnya lalu gagal diam-diam dengan exit code 1.
' Dengan file `.cmd` yang terpisah, quoting ditangani oleh cmd.exe yang
' sebenarnya, dan hasilnya benar (sudah diuji).
'
' EXIT CODE
' =========
'
' `shell.Run` mengembalikan exit code perintah. Skrip meneruskannya lewat
' `WScript.Quit`, jadi Task Scheduler mencatatnya di "Last Result":
' `0` = sukses, selain itu = gagal dan bisa dibaca di file log.
' =============================================================================

Option Explicit

Dim shell, fso, toolsDir, baseDir, runner, rc, statusFile, f, ts, tsRaw

Set fso = CreateObject("Scripting.FileSystemObject")

' --- Lokasi (diturunkan dari lokasi skrip, bukan ditulis mati) -----------
toolsDir = fso.GetParentFolderName(WScript.ScriptFullName)
baseDir  = fso.GetParentFolderName(toolsDir) & "\backend"

runner = toolsDir & "\scheduler-run-hidden.cmd"

' --- Pastikan folder log ada (fresh install belum membuatnya) ------------
Dim logDir
logDir = baseDir & "\storage\logs"
If Not fso.FolderExists(baseDir & "\storage") Then fso.CreateFolder baseDir & "\storage"
If Not fso.FolderExists(logDir) Then fso.CreateFolder logDir

statusFile = logDir & "\scheduler-status.txt"

Set shell = CreateObject("WScript.Shell")
shell.CurrentDirectory = baseDir

' Jalankan file .cmd tanpa jendela (0) dan TUNGGU (True) supaya exit code
' didapat.
'
' Perintah TIDAK diberi kutip manual di sekitar path: `WScript.Shell.Run`
' sudah menambahkannya sendiri, dan kutip manual kedua hanya menambah
' kutip ganda yang tidak ada artinya di sini.
rc = shell.Run(Chr(34) & runner & Chr(34), 0, True)

' --- Timestamp ------------------------------------------------------------
' `FormatDateTime` dengan pola "nn" TIDAK bisa dipakai di sini: di locale
' mesin ini pemanggilannya melempar "Type mismatch". Jadi timestamp dibangun
' manual dari bagian-bagian `Now` -- tanpa ketergantungan pada locale.
tsRaw = Now
ts = Right("0000" & Year(tsRaw), 4) & "-" & _
      Right("00" & Month(tsRaw), 2) & "-" & _
      Right("00" & Day(tsRaw), 2) & " " & _
      Right("00" & Hour(tsRaw), 2) & ":" & _
      Right("00" & Minute(tsRaw), 2) & ":" & _
      Right("00" & Second(tsRaw), 2)

' --- Status ringkas (ditimpa tiap menit) ----------------------------------
' Task Scheduler sudah mencatat exit code sendiri di "Last Result". File ini
' fungsinya tambahan: supaya timestamp jalan terakhir TERLIHAT tanpa harus
' membuka Task Manager.
Set f = fso.OpenTextFile(statusFile, 2, True)    ' 2 = write (overwrite)
f.WriteLine "last_run=" & ts
f.WriteLine "exit_code=" & rc
f.Close

WScript.Quit rc
