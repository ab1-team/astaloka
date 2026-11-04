<?php

namespace App\Console\Commands;

use Illuminate\Console\Events\SchedulePaused;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulePauseCommand;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * `schedule:pause` yang menulis flag pause ke store yang BENAR.
 *
 * MASALAH YANG DIPERBAIKI
 * =======================
 *
 * Perintah bawaan Laravel menulis flag pause ke cache default:
 *
 *     $cache->forever('illuminate:schedule:paused', true);
 *
 * `Cache` yang di-resolve dari container adalah store default aplikasi --
 * di sini `database`. Tapi scheduler membaca flag itu dari
 * `config('cache.schedule_store')`, yang di sini `file`.
 *
 * Dua store berbeda, dua kunci berbeda. Flag yang ditulis perintah ini
 * TIDAK PERNAH dibaca scheduler.
 *
 * Gejalanya sangat menyesatkan: `schedule:pause` mencetak "Scheduled task
 * processing has been paused." dengan exit code 0 -- lalu scheduler tetap
 * menjalankan job setiap menit, tanpa error apa pun. Perintah itu
 * berbohong, dan tidak ada jejak yang menunjukkan kenapa.
 *
 * SOLUSINYA
 * =========
 *
 * Tulis flag ke store yang sama dengan yang dibaca scheduler. Store itu
 * diambil dari `config('cache.schedule_store')` -- bukan dari cache default.
 *
 * Kalau penulisan GAGAL (mis. store-nya tidak bisa ditulis), perintah ini
 * mengembalikan exit code bukan-0 dan menjelaskan apa yang terjadi.
 * Sengaja tidak diam-diam succeed: perintah yang gagal menulis harus
 * bilang gagal, karena itu satu-satunya kesempatan pengguna tahu
 * scheduler-nya tidak ikut berhenti.
 *
 * @see ScheduleRunResilient
 * @see ScheduleResumeResilient
 */
class SchedulePauseResilient extends SchedulePauseCommand
{
    /**
     * Nama perintah harus tetap sama dengan yang bawaan, supaya tidak ada
     * dua perintah dengan nama berbeda yang melakukan hal yang sama.
     *
     * Atribut `AsCommand` milik class induk TIDAK diwarisi oleh subclass
     * (atribut PHP tidak diwarisi), jadi tanpa baris ini perintahnya tidak
     * punya nama sama sekali.
     */
    protected $signature = 'schedule:pause';

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
        if (! Schedule::$pausable) {
            $this->components->error('Schedule pausing is currently disabled.');

            return self::FAILURE;
        }

        try {
            app('cache')->store(config('cache.schedule_store'))
                ->forever('illuminate:schedule:paused', true);
        } catch (Throwable $e) {
            $this->components->error('Gagal menyimpan flag pause: '.$e->getMessage());

            report($e);

            return self::FAILURE;
        }

        $dispatcher->dispatch(new SchedulePaused);

        $this->components->info('Scheduled task processing has been paused.');

        return self::SUCCESS;
    }
}
