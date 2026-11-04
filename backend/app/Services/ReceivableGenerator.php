<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Menentukan KAPAN jurnal piutang tunggakan harus dibuat, lalu menjalankannya.
 *
 * Masalah yang diselesaikan
 * -------------------------
 *
 * Class ini memindahkan pemicu generate dari frontend ke backend.
 *
 * Sebelumnya generate dipicu `useOverdueGenNotification.js` -- yaitu saat
 * dashboard DIBUKA -- dan hanya jalan bila dua syarat terpenuhi:
 *
 *     DashboardController:451   $configured     = $scheduledDay >= 1 && <= 28
 *     DashboardController:452   $isScheduledDay = $todayDay === $scheduledDay
 *
 * Dua syarat itu membuat generate bergantung pada orang, bukan pada tanggal:
 *
 *   - Kalau tidak ada staff yang membuka dashboard pada tanggal itu, tagihan
 *     menunggak TIDAK PERNAH dijurnalkan. Tidak ada error, tidak ada log,
 *     tidak ada tanda di mana pun. Tagihan tetap `unpaid` di `monthly_bills`
 *     tapi tidak pernah membebani akun Piutang Usaha `1.1.03.01`, sehingga
 *     Neraca dan pelaporan under-report sementara `monthly_bills` tetap
 *     menunjukkan angka yang berbeda -- dua sumber kebenaran yang tidak
 *     sinkron.
 *
 *   - Gate `===` juga membuat tanggal 29, 30, dan 31 tidak pernah bisa
 *     menjadi tanggal generate.
 *
 * KONSEP: generate HANYA pada tanggal yang di-set, tapi boleh berulang
 * -----------------------------------------------------------
 *
 * Permintaan operasionalnya: "generate hanya 1 hari di tgl yang di-set,
 * bukan hari ini -- tgl itu, tapi hanya tgl itu". Jadi gate-nya `===`:
 * tanggal 28 -> generate HANYA pada tanggal 28.
 *
 * Yang TETAP boleh berulang adalah JUMLAH generate di hari itu: kalau
 * lima orang login pada tanggal 28, generate jalan lima kali -- dan
 * tidak menghasilkan jurnal dobel, karena command-nya idempotent
 * (pasangan `reverence_id|account_kredit`).
 *
 * Karena itu tiga lapis penghalang yang tadinya ada DILETAKHKAN:
 *
 *   1. Gate `===` -> TETAP. Ini yang diminta: hanya tanggal yang di-set.
 *
 *   2. Cache "sudah pernah dijalankan bulan ini" (TTL 35 hari)
 *      -> DIHAPUS. Ini penghalang paling ketat: begitu generate jalan sekali
 *      pada tanggal 6, seluruh bulan berikutnya di lokasi itu tidak pernah
 *      bisa generate lagi -- termasuk kasus tagihan baru yang masuk lewat
 *      salesperson, atau tagihan yang tadinya salah dan sudah diperbaiki.
 *
 *   3. Mutex "sudah dijalankan hari ini" (TTL 1 jam)
 *      -> diturunkan menjadi BUSY-GUARD SAJA. Gunanya hanya mencegah dua
 *      proses agregasi `amount` berjalan bersamaan (lock wait timeout),
 *      BUKAN mencegah generate.
 *
 * Yang PEGANG rem dup-nya tetap command itu sendiri, yang sudah
 * idempotent: setiap tagihan punya paling banyak satu jurnal piutang per
 * akun kredit (dicek lewat pasangan `reverence_id|account_kredit`).
 * Menjalankan berkali-kali pada hari yang sama tidak menghasilkan jurnal
 * dobel.
 *
 * Efek samping yang diinginkan: kalau ada pelanggan yang lupa dicatat
 * meternya, atau tagihannya baru saja diperbaiki admin, generate ulang
 * di tanggal yang sama akan terlihat dan menagihnya tanpa perlu
 * intervensi manual.
 *
 * Kapan TIDAK berjalan
 * --------------------
 *
 * 1. Tanggal hari ini BUKAN tanggal generate. Contoh: `toleransi 6`,
 *    hari ini tanggal 3 -> belum jalan. Hari ini tanggal 7 -> sudah
 *    lewat, TIDAK jalan (gate `===`).
 *
 * 2. SOP belum diisi (nilai di luar rentang 1-31).
 *
 * Catatan penting soal tanggal yang terlewat
 * --------------------------------------------
 *
 * Gate `===` berarti bulan tanpa staff yang login tepat pada tanggal
 * generate TIDAK punya jurnal piutang sama sekali. Ini konsekuensi yang
 * disengaja dari permintaan di atas, dan penutupannya adalah scheduler
 * harian di `routes/console.php` -- ia memanggil `runIfDue()` untuk
 * semua lokasi setiap malam, jadi tanggal yang terlewat tetap tertangani
 * selama scheduler hidup.
 *
 * Kegagalan
 * ---------
 *
 * Generate TIDAK BOLEH menggagalkan login. Password sudah benar, akun
 * sudah aktif, dan tagihan langganan sudah lunas -- sisa pekerjaan
 * akuntansi internal tidak boleh menjadi alasan orang tidak bisa masuk.
 * Semua kegagalan ditangkap dan dicatat; login tetap sukses.
 */
class ReceivableGenerator
{
    public function __construct(private TenantCache $cache) {}

    /**
     * Apakah jurnal piutang hari ini masih perlu dibuat.
     *
     * Dipakai endpoint `dashboard/auto-generate-overdue/check` supaya
     * pop up hanya muncul saat memang ada pekerjaan yang perlu dikerjakan.
     *
     * GATE `===`: generate HANYA pada tanggal yang sudah diset di SOP.
     *
     * Permintaan operasionalnya eksplisit: "generate hanya 1 hari di tgl
     * yang di-set, bukan hari ini -- tgl itu, tapi hanya tgl itu". Jadi
     * kalau `toleransi_tunggakan` = 28, generate hanya boleh jalan pada
     * tanggal 28 setiap bulan -- bukan tanggal 28, 29, 30, dan 31.
     *
     * Bedanya dengan gate `>=` (yang dipakai sebelumnya):
     *
     *   - `>=` : tanggal 28 -> jalan pada 28, 29, 30, 31.
     *   - `===` : tanggal 28 -> jalan HANYA pada 28.
     *
     * Konsekuensi yang disengaja dan harus disadari: kalau TIDAK ADA
     * staff yang login tepat pada tanggal itu, generate bulan itu TIDAK
     * PERNAH jalan. Tagihan tetap `unpaid` di `monthly_bills` dan akun
     * Piutang Usaha `1.1.03.01` tidak pernah bertambah -- Neraca
     * under-report sementara daftar tagihan tidak, jadi dua sumber
     * kebenaran jadi tidak sinkron.
     *
     * Ini persis trade-off yang diminta. Yang bisa-cloud menutup celah
     * itu adalah scheduler harian (lihat `routes/console.php`): ia
     * memanggil `runIfDue()` untuk semua lokasi setiap malam, sehingga
     * tanggal yang terlewat tetap tertangani TANPA harus ada yang login.
     *
     * Tapi scheduler hanya menutup celah kalau JALAN. Kalau scheduler
     * mati dan tidak ada yang login tepat pada tanggalnya, bulan itu
     * memang terlewat -- dan itu harus terlihat, bukan tersembunyi.
     */
    public function shouldRun(?Carbon $now = null): bool
    {
        $now ??= now();
        $scheduledDay = $this->effectiveScheduledDay($now);

        // SOP belum diisi -- tidak ada tanggal generate yang bisa dipilih.
        if ($scheduledDay < 1) {
            return false;
        }

        return $now->day === $scheduledDay;
    }

    /**
     * Tanggal generate yang benar-benar ADA di bulan ini.
     *
     * `scheduledDay()` mengembalikan angka yang diset admin (1-31). Angka itu
     * belum tentu ada di bulan berjalan -- tanggal 31 tidak ada di
     * Februari, tanggal 30 tidak ada di Februari, tanggal 29 tidak ada di
     * Februari tahun biasa.
     *
     * Yang dipakai untuk membandingkan dengan "hari ini" adalah tanggal
     * yang BENAR-BENAR jatuh pada bulan itu: kalau angka yang diset
     * melewati hari terakhir bulan, ia ditarik ke hari terakhir bulan.
     * Jadi diset 29 -> 29 di Oktober, 28 di Februari.
     *
     * Tanpa ini, admin yang menyetel tanggal 29 akan melihat generate
     * TIDAK PERNAH jalan di Februari -- dan di bulan yang lain pun tidak,
     * karena `generateDay()` mengembalikan 0 untuk angka di luar 1-31.
     */
    public function effectiveScheduledDay(?Carbon $now = null): int
    {
        $now ??= now();

        return app(BillingTolerance::class)->effectiveGenerateDay($now);
    }

    /**
     * Tanggal generate bulan ini.
     *
     * Dipakai untuk mutex: dua proses pada hari yang sama harus melihat
     * kunci yang sama, dan tanggal inilah yang menentukan kunci itu.
     */
    public function scheduledDate(?Carbon $now = null): string
    {
        $now ??= now();

        return $now->copy()->day(max(1, $this->effectiveScheduledDay($now)))->toDateString();
    }

    /**
     * Alasan kenapa generate TIDAK jalan hari ini.
     *
     * Gate-nya `===`, jadi ada TIGA kemungkinan yang berbeda dan messaging
     * yang menyatukan ketiganya jadi "belum tanggal generate" akan
     * menyesatkan:
     *
     *   - SOP belum diisi (nilai di luar rentang 1-31).
     *   - Tanggal hari ini masih SEBELUM tanggal generate.
     *   - Tanggal hari ini sudah MELAWATI tanggal generate.
     *
     * Kasus ketiga yang paling penting: user menyetel tanggal 28, lalu
     * login tanggal 29 dan tidak melihat popup. Tanpa pesan yang jelas,
     * dia mengira sistemnya rusak -- padahal memang tidak ada generate
     * hari itu, persis seperti yang diminta.
     */
    private function alasanBelumJalankan(Carbon $now): string
    {
        $scheduledDay = $this->scheduledDay();

        if ($scheduledDay < 1) {
            return 'Tanggal generate piutang belum diatur di SOP (harus 1-31).';
        }

        $effectiveDay = $this->effectiveScheduledDay($now);
        $todayDay = (int) $now->format('d');

        if ($todayDay < $effectiveDay) {
            return 'Belum tanggal generate piutang bulan ini (tanggal '.$effectiveDay.').';
        }

        return 'Tanggal generate piutang bulan ini sudah lewat (tanggal '.$effectiveDay.').';
    }

    /**
     * Kunci BUSY-GUARD generate piutang.
     *
     * SATU-SATUNYA tempat kunci ini dibuat. Sebelumnya ada dua kunci
     * berbeda untuk hal yang sama:
     *
     *   - `ReceivableGenerator`  : `overdue_gen_lock_{tanggal generate}`
     *   - `DashboardController`   : `overdue_gen_lock_{tanggal hari ini}`
     *
     * Kalau tanggal generate BEDA dari hari ini -- yang pasti terjadi
     * setiap hari selain tanggal generate -- kedua kunci itu tidak pernah
     * sama. Akibatnya dua proses yang seharusnya saling menjaga bisa
     * berjalan bersamaan, persis hal yang busy-guard ini-exist untuk
     * mencegahnya.
     *
     * Dan kalau tanggal generate justru SAMA dengan hari ini, keduanya
     * kebetulan sama -- jadi gejalanya baru muncul pada kasus yang paling
     * jarang diuji: tanggal generate yang sudah terlewat.
     *
     * Kuncinya berbasis TANGGAL HARI INI, bukan tanggal generate, karena
     * yang perlu dijaga adalah "jangan agregasi `amount` dua kali
     * bersamaan", dan itu soal waktu, bukan soal tanggal generate.
     */
    public function lockKey(?Carbon $now = null): string
    {
        $now ??= now();

        return $this->cache->flagKey('overdue_gen_lock_'.$now->toDateString());
    }

    /**
     * Jalankan generate piutang bila memang sudah waktunya.
     *
     * `scheduled_day` ikut dikembalikan supaya frontend bisa menampilkan
     * tanggal generate di popup tanpa harus memanggil endpoint lain --
     * bentuknya sama dengan yang dikirim `DashboardController`.
     *
     * @return array{ran: bool, reason: string, summary: array<string,mixed>|null, scheduled_day: int, today_day: int}
     */
    public function runIfDue(?Carbon $now = null): array
    {
        $now ??= now();

        if (! $this->shouldRun($now)) {
            return [
                'ran' => false,
                'reason' => $this->alasanBelumJalankan($now),
                'summary' => null,
                'scheduled_day' => $this->scheduledDay(),
                'today_day' => (int) $now->format('d'),
            ];
        }

        $lockKey = $this->lockKey($now);

        /*
         * BUSY-GUARD, bukan blocker.
         *
         * Fungsinya hanya mencegah dua proses agregasi tabel `amount` di atas
         * tabel `transactions` berjalan bersamaan. Dua proses itu bukan
         * merusak data (command-nya idempotent), tapi boros mahal dan bisa
         * memicu lock wait timeout -- terutama di lokasi besar dengan
         * puluhan ribu tagihan.
         *
         * PENTING: kegagalan acquiring lock TIDAK berarti "generate sudah
         * pernah jalan, jadi jangan jalan lagi". Itu semantics yang
         * lama -- dan justru alasan generate tidak pernah bisa dijalankan
         * ulang dalam sebulan.
         *
         * TTL-nya 10 menit, bukan 1 jam: kalau proses pertama mati di
         * tengah jalan (timeout, OOM, koneksi putus), percobaan berikutnya
         * tidak perlu menunggu satu jam penuh.
         *
         * Dan kalau proses yang sedang jalan BENAR-BENAR selesai, kuncinya
         * dilepas di akhir supaya generate berikutnya (login berikutnya)
         * langsung boleh jalan.
         */
        if (! Cache::add($lockKey, 1, now()->addMinutes(10))) {
            return [
                'ran' => false,
                'reason' => 'Generate sedang berjalan di proses lain. Coba lagi sebentar lagi.',
                'summary' => null,
                'busy' => true,
                'scheduled_day' => $this->scheduledDay(),
                'today_day' => (int) $now->format('d'),
            ];
        }

        try {
            $summary = $this->execute($now);
        } catch (\Throwable $e) {
            // Lepas kunci supaya percobaan berikutnya tidak terkunci hanya
            // karena satu kegagalan.
            Cache::forget($lockKey);

            Log::error('Generate piutang tunggakan gagal: '.$e->getMessage(), [
                'tenant' => $this->cache->scope(),
                'exception' => $e,
            ]);

            return [
                'ran' => false,
                'reason' => 'Generate gagal: '.$e->getMessage(),
                'summary' => null,
                'scheduled_day' => $this->scheduledDay(),
                'today_day' => (int) $now->format('d'),
            ];
        } finally {
            // Lepas kunci juga setelah sukses: generate berikutnya
            // (misalnya login berikutnya) harus BOLEH jalan lagi.
            Cache::forget($lockKey);
        }

        return [
            'ran' => true,
            'reason' => 'Generate piutang tunggakan selesai.',
            'summary' => $summary,
            'scheduled_day' => $this->scheduledDay(),
            'today_day' => (int) $now->format('d'),
        ];
    }

    /**
     * Jalankan command aslinya lalu kembalikan ringkasannya.
     *
     * Dipisahkan dari {@see runIfDue()} supaya logika penjadwalan (gate,
     * mutex, penanganan kegagalan) tidak tercampur dengan pemanggilan
     * command yang juga dipakai di dua tempat lain.
     *
     * @return array<string,mixed>|null
     */
    public function execute(?Carbon $now = null): ?array
    {
        $now ??= now();
        $tanggal = $now->toDateString();

        $exit = Artisan::call(
            'billing:generate-overdue-transactions',
            ['--tanggal' => $tanggal],
        );

        Log::info('Generate piutang tunggakan dijalankan', [
            'tenant' => $this->cache->scope(),
            'tanggal' => $tanggal,
            'exit_code' => $exit,
        ]);

        return $this->runSummary($now);
    }

    /**
     * Ringkasan run generate yang terakhir.
     *
     * `null` kalau tidak ada ringkasan yang bisa dipercaya.
     *
     * Kenapa harus dicek tanggalnya
     * ---------------------------
     *
     * Ringkasan disimpan di cache dengan TTL 26 jam -- cukup untuk menutup
     * seluruh jendela "hari generate". Tapi TTL 26 jam itu LEBIH LAMA dari
     * satu hari, jadi ringkasan dari run kemarin masih hidup saat generate
     * hari ini dijalankan.
     *
     * Kalau ringkasan basi itu ditampilkan, user melihat angka tagihan
     * yang sudah berumur sehari, dan dianggap generate hari ini memproses
     * ribuan tagihan padahal yang terjadi adalah "tidak ada yang perlu
     * dijurnalkan".
     *
     * Command selalu menulis ulang ringkasan saat ia jalan -- termasuk
     * saat tidak menemukan tagihan menunggak (angka nolnya memang nol) --
     * jadi satu-satunya cara ringkasan basi sampai ke user adalah ketika
     * command-nya sendiri tidak sempat menulis. `date` di dalamnya
     * membedakan kedua kasus itu.
     *
     * @return array<string,mixed>|null
     */
    public function runSummary(?Carbon $now = null): ?array
    {
        $now ??= now();

        $summary = $this->cache->flagGet('overdue_gen_run_summary');

        if (! is_array($summary)) {
            return null;
        }

        if (($summary['date'] ?? null) !== $now->toDateString()) {
            return null;
        }

        return $summary;
    }

    /**
     * Tanggal generate dari SOP lokasi.
     *
     * Satu-satunya tempat nilai `toleransi_tunggakan` dibaca sebagai
     * TANGGAL. Nilai yang sama punya arti lain di tempat lain -- lihat
     * `BillingTolerance` -- sehingga pembacaan ini sengaja tidak disebar
     * ke seluruh aplikasi.
     *
     * Membaca lewat `BillingTolerance::generateDay()` bukan langsung dari
     * `Setting`, karena accessor itu yang mengubah nilai di luar rentang
     * 1-31 menjadi 0 -- artinya "belum dijadwalkan". Tanpa itu, nilai 40
     * akan lolos ke sini dan `shouldRun()` menganggapnya valid.
     */
    public function scheduledDay(): int
    {
        return app(BillingTolerance::class)->generateDay();
    }
}
