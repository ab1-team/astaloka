<?php

namespace App\Listeners;

use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Jembatan event console Symfony -> event console Laravel.
 *
 * Kenapa jembatan ini perlu
 * =========================
 *
 * `Illuminate\Console\Events\CommandStarting` -- event yang dipakai
 * `GuardDestructiveCommands` dan `GuardCentralDatabaseWrites` -- TIDAK
 * dikirim oleh Laravel secara langsung. Ia dikirim oleh
 * `Illuminate\Foundation\Console\Kernel::rerouteSymfonyCommandEvents()`,
 * yang memasang listener Symfony `console.command` lalu menerjemahkannya
 * menjadi event Laravel.
 *
 * Dan method itu SENGAJA dilewati saat `runningUnitTests()` bernilai true:
 *
 *     $this->app->booted(function () {
 *         if (! $this->app->runningUnitTests()) {
 *             $this->rerouteSymfonyCommandEvents();
 *         }
 *     });
 *
 * Alasan framework-nya wajar: event console dianggap tidak relevan di
 * dalam test. Tapi di repo ini event itu justru SATU-SATUNYA alat yang
 * membuat penjaga database bekerja -- dan penjaga itu yang menjaga
 * database produksi dari test yang salah sasaran.
 *
 * Akibatnya, di DALAM `php artisan test`:
 *
 *   - `GuardDestructiveCommands` terdaftar, tapi TIDAK PERNAH dipanggil.
 *   - `GuardCentralDatabaseWrites` terdaftar, tapi TIDAK PERNAH dipanggil.
 *
 * Bukan "kurang ketat" -- benar-benar mati. Dan itu yang membuat
 * `RefreshDatabase` (yang menjalankan `migrate:fresh` -> `db:wipe` ->
 * `dropAllTables()`) bisa menjatuhkan SELURUH isi database pusat dalam satu
 * statement, tanpa satu pun penjaga yang menyadarinya.
 *
 * Yang dilakukan jembatan ini
 * ==========================
 *
 * Jembatan ini memasang dispatcher Symfony sendiri pada aplikasi Artisan,
 * dan menerjemahkan `console.command` / `console.terminate` menjadi
 * `CommandStarting` / `CommandFinished` -- persis seperti yang dilakukan
 * Kernel, tapi tanpa syarat `runningUnitTests()`.
 *
 * Kenapa aman di luar test
 * ========================
 *
 * Di luar test, `Kernel::getArtisan()` menimpa dispatcher yang dipasang
 * jembatan ini dengan dispatcher milik Kernel sendiri:
 *
 *     if ($this->symfonyDispatcher instanceof EventDispatcher) {
 *         $this->artisan->setDispatcher($this->symfonyDispatcher);
 *     }
 *
 * Jadi event TIDAK terkirim dua kali -- hanya dispatcher terakhir yang
 * menang, dan itu milik Kernel. Jembatan ini murni jaring pengaman untuk
 * konteks test.
 */
class BridgeConsoleCommandEvents
{
    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    public function handle(ArtisanStarting $event): void
    {
        $artisan = $event->artisan;

        if (! $artisan instanceof SymfonyApplication) {
            return;
        }

        $dispatcher = new EventDispatcher;

        $dispatcher->addListener(ConsoleEvents::COMMAND, function (ConsoleCommandEvent $e): void {
            $this->events->dispatch(new CommandStarting(
                $e->getCommand()?->getName() ?? '',
                $e->getInput(),
                $e->getOutput()
            ));
        });

        $dispatcher->addListener(ConsoleEvents::TERMINATE, function (ConsoleTerminateEvent $e): void {
            $this->events->dispatch(new CommandFinished(
                $e->getCommand()?->getName() ?? '',
                $e->getInput(),
                $e->getOutput(),
                $e->getExitCode()
            ));
        });

        $artisan->setDispatcher($dispatcher);
    }
}
