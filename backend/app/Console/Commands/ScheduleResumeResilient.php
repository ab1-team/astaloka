<?php

namespace App\Console\Commands;

use Illuminate\Console\Events\ScheduleResumed;
use Illuminate\Console\Scheduling\ScheduleResumeCommand;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * `schedule:resume` yang menghapus flag pause dari store yang BENAR.
 *
 * MASALAH YANG DIPERBAIKI
 * =======================
 *
 * Sama seperti {@see SchedulePauseResilient}: perintah bawaan menghapus
 * flag pause dari cache default (`database`), sementara scheduler membacanya
 * dari `config('cache.schedule_store')` (`file`).
 *
 * Kalau flag pause dihapus dari store yang salah, scheduler yang membaca
 * store scheduler TETAP melihat flag itu -- jadi scheduler tetap berhenti,
 * padahal perintahnya sudah bilang "resumed".
 *
 * SOLUSINYA
 * =========
 *
 * Hapus flag dari store yang sama dengan yang dibaca scheduler.
 *
 * Kalau penghapusan GAGAL, perintah ini mengembalikan exit code bukan-0
 * dan menjelaskan apa yang terjadi -- jangan diam-diam succeed, karena
 * "resume" yang sebenarnya tidak terjadi akan membuat orang mengira
 * scheduler sudah berjalan padahal tidak.
 *
 * @see ScheduleRunResilient
 * @see SchedulePauseResilient
 */
class ScheduleResumeResilient extends ScheduleResumeCommand
{
    /**
     * Nama perintah harus tetap sama dengan yang bawaan.
     *
     * Atribut `AsCommand` milik class induk TIDAK diwarisi oleh subclass
     * (atribut PHP tidak diwarisi), jadi tanpa baris ini perintahnya tidak
     * punya nama sama sekali.
     */
    protected $signature = 'schedule:resume';

    /**
     * Parameter `$cache` sengaja tidak dipakai.
     *
     * Tanda tangannya harus sama persis dengan method induk: PHP menolak
     * method override yang menghapus parameter wajib. Tapi cache yang
     * di-resolve ke sini adalah store DEFAULT -- justru yang tidak boleh
     * dipakai untuk flag scheduler.
     */
    public function handle(Repository $cache, Dispatcher $dispatcher)
    {
        try {
            app('cache')->store(config('cache.schedule_store'))
                ->forget('illuminate:schedule:paused');
        } catch (Throwable $e) {
            $this->components->error('Gagal menghapus flag pause: '.$e->getMessage());

            report($e);

            return self::FAILURE;
        }

        $dispatcher->dispatch(new ScheduleResumed);

        $this->components->info('Scheduled task processing has resumed.');

        return self::SUCCESS;
    }
}
