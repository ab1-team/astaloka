<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * `schedule:run` yang tidak crash ketika database sedang tidak terjangkau.
 *
 * LATAR BELAKANG
 * ==============
 *
 * `schedule:run` dipanggil setiap menit oleh Task Scheduler. Sebelum
 * menjalankan job apa pun, framework melakukan TIGA hal yang butuh cache:
 *
 *   1. Membaca flag pause:
 *
 *          $this->cache->get('illuminate:schedule:paused', false)
 *
 *   2. Mengambil mutex `withoutOverlapping()` untuk setiap job.
 *
 *   3. Mengecek flag interrupt.
 *
 * Cache di aplikasi ini memakai store `database`, dan store itu menunjuk ke
 * database PUSAT. Jadi:
 *
 *   - kalau MySQL sedang mati, atau belum selesai start, atau sedang
 *     di-restart, query cache itu melempar
 *     `SQLSTATE[HY000] [2002] No connection could be made`;
 *   - `schedule:run` CRASH sebelum job apa pun sempat dicek.
 *
 * Akibatnya yang terjadi di lapangan:
 *
 *   - job yang SEHARUSNYA jalan tidak jalan. Yang paling penting:
 *     generate piutang tunggakan pada tanggal generate-nya. Gate tanggal
 *     generate bersifat PERSIS (`===`), jadi satu malam yang terlewat
 *     berarti bulan itu TIDAK PERNAH punya jurnal piutang -- tanpa error
 *     yang dilihat anybody, karena errornya milik scheduler.
 *   - satu baris ERROR ditulis ke `storage/logs/laravel.log` SETIAP MENIT.
 *     Log jadi membanjir, dan error yang SEBENARNYA (mis. bug di generate
 *     piutang) jadi sulit ditemukan di antara ribuan error koneksi yang
 *     gejalanya sama.
 *
 * BUKAN SOLUSI: MENGGANTI CACHE APLIKASI
 * =======================================
 *
 * Cache aplikasi (`CACHE_STORE=database`) sengaja memakai koneksi PUSAT,
 * supaya tabel `cache` jadi keyspace bersama. Mengubahnya ke `file` akan
 * memecah konvensi yang sudah dibangun: `TenantCache` menghitung
 * awalan kunci per lokasi, dan penyimpangan store akan membuat satu lokasi
 * membaca cache milik lokasi lain.
 *
 * Yang diubah di sini HANYA cache yang dipakai scheduler -- cache internal
 * framework untuk mutex dan flag, bukan cache aplikasi. Dan store itu
 * dikunci lewat `config('cache.schedule_store')` (default `file`), yang
 * TIDAK butuh database.
 *
 * SOLUSINYA
 * =========
 *
 * Command ini overriding `schedule:run` bawaan. Perbedaannya satu:
 * pembacaan cache yang GAGAL tidak membuat scheduler mati.
 *
 *   - Flag pause: kalau cache tidak bisa dibaca, scheduler dianggap TIDAK
 *     paused dan tetap jalan. Itu pilihan yang benar di sini: lebih baik
 *     menjalankan job yang excessive daripada membiarkan generate piutang
 *     terlewat. Flag pause sendiri masih bisa dipakai -- hanya saja ia
 *     tidak terbaca pada menit ketika database mati, dan itu memang
 *     kondisi yang tidak bisa diharapkan berjalan rapi.
 *
 *   - Mutex `withoutOverlapping()`: kalau lock tidak bisa diambil karena
 *     cache mati, job DIJALANKAN tanpa lock. Alasannya: mutex yang gagal
 *     diambil karena masalah infrastruktur BUKAN tanda "job ini sedang
 *     jalan di proses lain" -- jobnya memang tidak jalan di mana pun.
 *     Kalau job ini justru DILEWATI karena mutex gagal, maka `migrasi:
 *     bersihkan` -- yang tugasnya menghapus file kerja migrasi -- tidak
 *     pernah jalan, dan file yang memuat NIK serta nama lengkap orang
 *     bisa bertahan lama.
 *
 *   - Job yang butuh database dan database-nya mati akan gagal dengan
 *     error-nya sendiri, dan itu TERLIHAT: pesan errornya tetap ditulis
 *     ke log, dan exit code-nya bukan 0. Yang dicegah di sini adalah
 *     "crash SEBELUM job dicek", bukan "menyembunyikan kegagalan job".
 *
 * KENAPA OVERRIDE `schedule:pause` DAN `schedule:resume` JUGA
 * ========================================================
 *
 * Perintah bawaan itu menulis flag pause ke cache DEFAULT:
 *
 *     $cache->forever('illuminate:schedule:paused', true);
 *
 * `Cache` yang di-resolve dari container adalah store default -- yaitu
 * `database` di aplikasi ini. Tapi command INI membaca flag dari store
 * `file`. Dua store berbeda, dua kunci berbeda: flag yang ditulis
 * `schedule:pause` TIDAK PERNAH dibaca scheduler.
 *
 * Gejalanya: `schedule:pause` mencetak "Scheduled task processing has been
 * paused." -- lalu scheduler tetap menjalankan job terus-terusan, tanpa
 * error apa pun. Perintah itu berbohong.
 *
 * Karena itu kedua perintah itu ikut di-override supaya menulis dan
 * membaca di store yang SAMA. Lihat {@see SchedulePauseResilient} dan
 * {@see ScheduleResumeResilient}.
 *
 * Kenapa overriding, bukan mengubah framework
 * ==========================================
 *
 * Mengubah file di `vendor/` tidak bisa dilakukan: `composer install` akan
 * menimpanya kembali, dan perbedaannya hilang tanpa jejak. Overriding lewat
 * `ArtisanStarting` membuat perbedaannya menetap di repo ini dan terlihat
 * saat review kode.
 *
 * @see SchedulePauseResilient
 * @see ScheduleResumeResilient
 * @see App\Providers\AppServiceProvider::registerSchedulerCommandOverride()
 */
class ScheduleRunResilient extends ScheduleRunCommand
{
    /**
     * Cache store yang dipakai untuk membaca flag pause.
     *
     * Mengambil `cache.schedule_store` (default `file`) supaya scheduler
     * tidak bergantung pada database sama sekali.
     */
    protected function scheduleCache(): Repository
    {
        return app('cache')->store(config('cache.schedule_store'));
    }

    /**
     * Baca flag pause tanpa pernah melempar exception.
     *
     * Kalau cache tidak bisa dibaca (database mati, cache store bermasalah),
     * hasilnya `false` -- scheduler berjalan. Lihat penjelasan di docblock
     * class.
     */
    protected function isPaused()
    {
        if (! Schedule::$pausable) {
            return false;
        }

        try {
            return (bool) $this->scheduleCache()->get('illuminate:schedule:paused', false);
        } catch (Throwable $e) {
            // Cache tidak terjangkau. Scheduler TIDAK boleh mati hanya
            // karena itu -- lihat docblock class.
            report($e);

            return false;
        }
    }

    /**
     * Jalankan scheduler.
     *
     * Ganti cache yang di-resolve DIAMATERI dengan store scheduler, jadi
     * pembacaan flag pause dan flag interrupt sama-sama tidak menyentuh
     * database.
     *
     * Catatan: `parent::handle()` mengembalikan `void`, jadi exit code-nya
     * selalu 0. Kegagalan job yang benar-benar butuh database TETAP
     * terlihat -- errornya ditulis ke log oleh exception handler, dan
     * `Last Result` di Task Scheduler tetap 0 karena `schedule:run` sendiri
     * tidak gagal. Yang dicegah di sini adalah crash scheduler, bukan
     * kegagalan job.
     */
    public function handle(
        Schedule $schedule,
        Dispatcher $dispatcher,
        Repository $cache,
        ExceptionHandler $handler,
    ) {
        $this->cache = $this->scheduleCache();

        parent::handle($schedule, $dispatcher, $cache, $handler);
    }
}
