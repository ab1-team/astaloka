<?php

use App\Services\ReceivableGenerator;
use App\Services\TenantManager;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Generate piutang tunggakan untuk SEMUA lokasi.
 *
 * Kenapa bukan `Schedule::command(...)` biasa
 * -----------------------------------------
 *
 * `Schedule::command('billing:generate-overdue-transactions')` menjalankan
 * perintah itu satu kali, pada koneksi database yang sedang jadi
 * `database.default` saat proses scheduler hidup.
 *
 * Di pamsides-v2 (satu lokasi) itu benar. Di ASTALOKA tidak: setiap lokasi
 * punya database sendiri, dan proses scheduler tidak punya "lokasi aktif" --
 * yang ada hanyalah nilai `.env`. Akibatnya command hanya menyentuh satu
 * lokasi, dan lokasi mana itu bergantung pada konfigurasi mesin, bukan pada
 * daftar pelanggan. Semua lokasi lain tidak pernah menjalankan generate
 * piutang: tagihan tetap `unpaid`, akun Piutang Usaha tidak pernah ditambah,
 * dan tidak ada error maupun log yang menandakannya.
 *
 * Kenapa TIDAK memanggil command secara langsung
 * ----------------------------------------------
 *
 * Versi lama memanggil `Artisan::call('billing:generate-overdue-transactions')`
 * di dalam loop lokasi. Itu LEWAT dari gate tanggal generate: gate-nya ada
 * di `ReceivableGenerator::shouldRun()`, yang membandingkan `now()->day`
 * dengan `toleransi_tunggakan`.
 *
 * Efeknya: scheduler berjalan setiap malam untuk SEMUA lokasi, termasuk
 * lokasi yang tanggal generate-nya belum tercapai. Gate tanggal generate
 * -- satu-satunya aturan yang menentukan KAPAN piutang tunggakan ought
 * dijurnalkan -- jadi tidak berlaku sama sekali di jalur scheduler.
 *
 * Yang benar: scheduler memanggil `ReceivableGenerator::runIfDue()` --
 * satu-satunya tempat yang memegang gate, busy-guard, dan penanganan
 * kegagalan. Command-nya sendiri tetap idempotent (pasangan
 * `reverence_id|account_kredit`), jadi generate boleh berulang tanpa
 * menghasilkan jurnal dobel.
 *
 * GATE `===` DAN ARTINYA BAGI SCHEDULER
 * -------------------------------------
 *
 * Gate tanggal generate adalah `===`: `toleransi_tunggakan` = 28 berarti
 * generate HANYA pada tanggal 28. Scheduler berjalan SETIAP malam, jadi
 * hanya malam tanggal 28 yang benar-benar menjalankan generate -- malam
 * lainnya dilewati oleh gate, persis seperti login di tanggal lain.
 *
 * Ini penting justru karena menutup celah yang gate `===` membuka:
 * kalau TIDAK ADA staff yang login tepat pada tanggal 28, generate bulan
 * itu tetap jalan -- lewat scheduler, pada tanggal 28 juga.
 *
 * Yang TIDAK tertutup: kalau scheduler sendiri mati tepat pada malam
 * tanggal generate (server dimatikan, cron belum jalan), bulan itu
 * terlewat -- dan tidak ada staff yang login tanggal itu juga. Dua
 * sumber penutup harus sama-sama hidup: login di tanggal yang tepat, dan
 * scheduler yang jalan. Tidak ada generate yang berjalan diam-diam di
 * tanggal lain -- itu memang bagian dari permintaan: hanya tanggal yang
 * di-set.
 *
 * Jadwal 00:01 tetap dipertahankan.
 */
Schedule::call(function () {
    /** @var TenantManager $tenants */
    $tenants = app(TenantManager::class);

    $hasil = $tenants->runForEachActive(function () {
        $hasil = app(ReceivableGenerator::class)->runIfDue();

        return [
            'ran' => (bool) ($hasil['ran'] ?? false),
            'reason' => (string) ($hasil['reason'] ?? ''),
        ];
    });

    $gagal = array_values(array_filter($hasil, fn ($h) => ! $h['ok']));
    $sukses = count($hasil) - count($gagal);
    $jalan = count(array_filter($hasil, fn ($h) => ($h['ok'] ?? false) && ($h['value']['ran'] ?? false)));

    Log::info('Generate piutang tunggakan terjadwal selesai.', [
        'lokasi_diproses' => count($hasil),
        'lokasi_generate_jalan' => $jalan,
        'berhasil' => $sukses,
        'gagal' => count($gagal),
        'lokasi_gagal' => array_map(fn ($h) => $h['tenant']->slug, $gagal),
    ]);
})->dailyAt('00:01')->name('piutang:generate-overdue-transactions')->withoutOverlapping();

/*
 * Hapus file kerja migrasi yang tidak pernah di-commit.
 *
 * Jadwalnya 10 menit, bukan harian: file kerja dibuat setiap kali
 * superadmin mengunggah file, dan isinya memuat NIK serta nama lengkap
 * orang. Kalau dibiarkan sampai harian, satu file bisa bertahan hampir
 * 24 jam setelah user menutup tab tanpa melanjutkan.
 *
 * `withoutOverlapping()` dipakai karena perintah ini idempoten dan
 * cepat -- berkasnya sudah di luar masa berlaku, jadi isinya tidak akan
 * dibaca lagi. Dua proses yang saling menimpa hanya membuang usaha, bukan
 * merusak apa pun.
 */
Schedule::command('migrasi:bersihkan')
    ->everyTenMinutes()
    ->name('migrasi:bersihkan')
    ->withoutOverlapping();

/*
 * JADWAL DI LUAR APLIKASI -- WAJIB ADA
 * ------------------------------------
 *
 * Jadwal di atas hanya jalan kalau ada yang memanggil `schedule:run`.
 * Di produksi, itu biasanya cron. Di Windows, itu Task Scheduler.
 *
 * Kenapa ini penting untuk gate `===`
 *
 * Gate tanggal generate membandingkan "hari ini" dengan tanggal yang di-set,
 * PERSIS. Jadi kalau tidak ada staff yang login tepat pada tanggal itu, dan
 * scheduler juga tidak berjalan, bulan itu TIDAK PERNAH punya jurnal piutang
 * -- tanpa error, tanpa log. Dua sumber penutup harus sama-sama hidup:
 * login di tanggal yang tepat, DAN scheduler yang jalan.
 *
 * Task Scheduler di mesin ini:
 *
 *     Nama  : ASTALOKA Scheduler
 *     Aksi  : C:\Windows\System32\wscript.exe
 *             "C:\laragon\www\astaloka\tools\scheduler-run-hidden.vbs"
 *     Folder: C:\laragon\www\astaloka\backend
 *     Jadwal: setiap menit
 *
 * KENAPA LEWAT VBS, BUKAN LANGSUNG `php.exe`
 * -------------------------------------------
 *
 * Task Scheduler yang menjalankan `php.exe` (aplikasi konsol) selalu membuka
 * jendela konsol baru setiap kali task berjalan. Karena task-nya berjalan
 * tiap menit, itu berarti satu jendela baru per menit -- dan karena Windows
 * Terminal menjadi default terminal di Win11, jendela itu muncul sebagai TAB
 * BARU di Windows Terminal, lalu hilang sendiri begitu prosesnya selesai.
 *
 * Gejalanya persis seperti pada bug report: terminal "misterius" membuka tab
 * baru sendiri, beberapa saat kemudian tabnya hilang. Penyebabnya bukan
 * aplikasi -- ini perilaku Task Scheduler plus `php.exe` yang butuh console.
 *
 * `scheduler-run-hidden.vbs` menjalankan perintah yang sama lewat
 * `WScript.Shell.Run` dengan window style `0` (tersembunyi). Console-nya
 * tetap ada -- jadi proses TURUNAN yang dibuat Symfony Process (yaitu
 * `migrasi:bersihkan`) tetap bisa jalan -- tapi tidak pernah ditampilkan,
 * jadi tidak ada tab yang muncul.
 *
 * `php-win.exe` TIDAK bisa dipakai sebagai gantinya: varian GUI itu tidak
 * membuat console sama sekali, sehingga `proc_open()` di dalam PHP tidak
 * punya console untuk diwariskan dan perintah turunan gagal.
 *
 * Detail teknis lain yang sudah diuji dan HARUS dijaga kalau skripnya
 * diubah:
 *
 *   - `WScript.Shell.Run` membungkus command string dengan kutip sendiri.
 *     Kutip manual kedua membuat `cmd.exe` memperlakukan operator redirect
 *     `>` sebagai teks biasa -- perintahnya gagal diam-diam dengan exit
 *     code 1. Karena itu redirect ditulis di file `.cmd` terpisah
 *     (`scheduler-run-hidden.cmd`), tempat quoting ditangani cmd.exe.
 *   - `FormatDateTime` dengan pola "nn" melempar "Type mismatch" di locale
 *     mesin ini. Timestamp dibangun manual dari `Now`.
 *   - File output per run (`scheduler-out-<random>.txt`), bukan langsung ke
 *     file log: selama `cmd.exe` masih hidup, file yang di-`>>` terkunci,
 *     dan skrip tidak bisa membacanya ("Permission denied").
 *
 * PENTING: jangan pakai task yang sama untuk dua aplikasi. ASTALOKA punya
 * task sendiri (`ASTALOKA Scheduler`) dan tidak boleh berbagi dengan
 * `LaravelScheduler` milik pamsides-v2 -- satu task hanya boleh menunjuk ke
 * satu `artisan`, karena `schedule:run` menjalankan jadwal yang terdaftar di
 * aplikasi yang dipanggil.
 *
 * Cek apakah jalannya:
 *
 *     schtasks /query /tn "ASTALOKA Scheduler"
 *     php artisan schedule:list
 *     type backend\storage\logs\scheduler-status.txt
 *
 * Kalau `Last Result` bukan 0, task-nya gagal jalan -- dan generate piutang
 * tidak akan terjadi di tanggal yang seharusnya. Isi
 * `backend/storage/logs/scheduler-run.log` hanya terisi kalau ada MASALAH:
 * exit code bukan 0, atau output-nya menandakan error. Run yang sukses
 * tidak ditulis sama sekali -- kalau tidak, log akan penuh baris "DONE"
 * yang tidak pernah dibaca, dan error yang sesungguhnya jadi sulit
 * ditemukan di antaranya.
 *
 * CATATAN: `schedule:run` mengembalikan exit code 0 bahkan kalau job-nya
 * gagal. Kegagalan job dilaporkan lewat exception handler (dan ditulis ke
 * `storage/logs/laravel.log`), bukan lewat exit code scheduler. Jadi
 * `Last Result` yang 0 berarti "scheduler-nya hidup", bukan "semua job-nya
 * sukses".
 *
 * FLAG PAUSE
 * ==========
 *
 * `schedule:pause` dan `schedule:resume` menulis dan menghapus flag pause
 * di store `config('cache.schedule_store')` -- store yang sama dengan yang
 * dibaca scheduler. Perintah bawaan Laravel menulis ke cache DEFAULT
 * (`database`), yang berbeda -- dan hasilnya `schedule:pause` menganggur
 * tanpa error: scheduler tetap berjalan meski perintahnya bilang "paused".
 * Karena itu kedua perintah itu di-override.
 *
 * @see \App\Console\Commands\ScheduleRunResilient
 * @see \App\Console\Commands\SchedulePauseResilient
 * @see \App\Console\Commands\ScheduleResumeResilient
 */
