<?php

namespace Tests\Feature;

use App\Models\MonthlyBill;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingDueDate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KAPAN sebuah tagihan harus tercatat sebagai piutang.
 *
 * BUG YANG DIKUNCI DI SINI
 * ------------------------
 *
 * Dulu ambang pengakuan piutang dihitung sebagai:
 *
 *     threshold = tanggal generate - N HARI
 *     (N = `settings.batas_tagihan`, yaitu "hari ke berapa dalam bulan")
 *
 * Dua kesalahan ada di dalamnya sekaligus:
 *
 *   1. SATUANNYA SALAH. Angka 27 berarti "tanggal 27", bukan "27 hari".
 *   2. ANCHORNYA SALAH. Karena tanggal generate juga diambil dari angka
 *      yang SAMA, `tanggal generate - 27 hari` selalu jatuh di AKHIR BULAN
 *      SEBELUMNYA.
 *
 * Akibatnya, tagihan periode M (jatuh tempo di bulan TAGIHAN = M+1) baru
 * dijurnal pada generate bulan M+2 -- satu bulan lebih lambat dari aturan
 * bisnis, dan satu bulan lebih lambat dari aplikasi lama
 * (`$usage->tgl_akhir <= $date`).
 *
 * Gejalanya persis seperti yang dilaporkan user: "piutang bulan Januari
 * sudah tercatat, trus generate di bulan Februari kok nggak kecatet ya,
 * kan beda bulan". Jawabannya: karena generate di bulan G hanya
 * menagih tagihan yang jatuh temponya sudah lewat akhir bulan G-1 --
 * sedangkan tagihan periode G-1 jatuh tempo DI bulan G, jadi ia terlewat
 * satu bulan penuh.
 *
 * ATURAN YANG SEKARANG (dan dikunci di sini)
 * ------------------------------------------
 *
 * Sebuah tagihan menjadi piutang begitu TANGGAL JATUH TEMPO-nya sudah
 * tercapai. Jadi:
 *
 *   - tagihan periode M -> tercatat pada generate bulan M+1
 *     (= tanggal jatuh temponya),
 *   - tagihan yang jatuh tempo BELUM tercapai -> belum dicatat,
 *   - satu pelanggan dengan BULANAN tagihan menunggak -> DUANYA dicatat,
 *     bukan cuma satu.
 *
 * Pertanyaan terakhir itu penting karena dedup generate itu per
 * (tagihan, akun kredit) -- BUKAN per pelanggan. Kalau dedup-nya per
 * pelanggan, generate hanya akan mencatat tagihan menunggak pertama
 * milik orang itu, dan tagihan bulan berikutnya akan menggantung
 * sampai... entah kapan.
 */
class AkurasiPiutangPerBulanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tanggal generate yang dipakai semua test di sini.
     *
     * Dipakai 27 karena itu angka yang dipakai lokasi demo, dan karena
     * tanggal 27 ada di setiap bulan -- jadi timeline-nya bisa dibaca
     * tanpa harus takut tanggal dijepit ke hari terakhir bulan.
     */
    private const HARI_GENERATE = 27;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-15 10:00:00'));
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Akurasi',
            'email' => 'akurasi-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('rahasia123'),
            'role' => 'admin',
        ]);
    }

    private function customerId(User $admin): int
    {
        $packageId = DB::table('installation_packages')->insertGetId([
            'name' => 'Paket', 'installation_fee' => 0, 'monthly_abodemen' => 10_000,
            'late_penalty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ticketId = DB::table('installation_tickets')->insertGetId([
            'package_id' => $packageId, 'applicant_name' => 'Budi', 'nik' => '3321010101900002',
            'order_date' => now()->toDateString(), 'address' => 'Alamat', 'lat' => -7.1, 'lng' => 110.1,
            'status' => 'completed', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $pelanggan = User::create([
            'name' => 'Budi', 'email' => 'budi-'.substr(uniqid('', true), -6).'@pdam.test',
            'password' => Hash::make('rahasia123'), 'role' => 'pelanggan',
        ]);

        return (int) DB::table('customers')->insertGetId([
            'ticket_id' => $ticketId, 'user_id' => $pelanggan->id, 'customer_code' => '7350',
            'initial_meter_reading' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * SOP lokasi: tanggal generate 27.
     */
    private function setSOP(int $hari = self::HARI_GENERATE): void
    {
        Setting::create([
            'key' => 'sop',
            'batas_tagihan' => $hari,
            'toleransi_tunggakan' => $hari,
        ]);
    }

    /**
     * Satu tagihan bulanan untuk satu pelanggan.
     *
     * `due_date` TIDAK dihitung di sini -- pemanggil yang menentukan,
     * supaya test bisa menguji tagihan dengan jatuh tempo yang sengaja
     * dibuat tidak sesuai rumus (`BillingDueDate`).
     */
    private function tagihan(int $customerId, int $year, int $month, string $dueDate): MonthlyBill
    {
        return MonthlyBill::create([
            'customer_id' => $customerId,
            'billing_period_year' => $year,
            'billing_period_month' => $month,
            'meter_reading_start' => 0, 'meter_reading_end' => 10, 'usage_m3' => 10,
            'usage_charge' => 50_000, 'abodemen' => 10_000, 'penalty_amount' => 0,
            'total_amount' => 60_000, 'status' => 'unpaid', 'due_date' => $dueDate,
        ]);
    }

    /**
     * Jumlah jurnal piutang milik satu tagihan.
     */
    private function jumlahJurnal(MonthlyBill $bill): int
    {
        return Transaction::where('reverence_type', 'overdue_bill')
            ->where('reverence_id', $bill->id)
            ->count();
    }

    /**
     * Jalankan generate pada tanggal tertentu.
     *
     * Memakai command langsung (bukan `runIfDue()`) supaya test tidak
     * bergantung pada gate tanggal -- yang diuji di sini adalah ATURAN
     * pengakuannya, bukan penjadwalannya.
     */
    private function generate(string $tanggal): void
    {
        $this->artisan('billing:generate-overdue-transactions', ['--tanggal' => $tanggal])
            ->assertSuccessful();
    }

    /**
     * Tagihan periode M harus tercatat pada generate bulan M+1.
     *
     * Ini inti dari bug yang diperbaiki.
     *
     * Tagihan periode September 2026 jatuh tempo 27 Oktober 2026
     * (bulan tagihan = bulan pemakaian + 1). Jadi pada generate 27 Oktober
     * 2026 tagihan itu WAJIB sudah dibukukan sebagai piutang.
     *
     * Versi lama baru membukukannya pada generate 27 November 2026 --
     * satu bulan terlambat.
     */
    #[Test]
    public function tagihan_periode_september_tercatat_pada_generate_oktober(): void
    {
        $admin = $this->admin();
        $customerId = $this->customerId($admin);
        $this->setSOP();

        $september = $this->tagihan(
            $customerId,
            2026,
            9,
            BillingDueDate::compute(2026, 9, self::HARI_GENERATE)
        );

        $this->assertSame('2026-10-27', $september->due_date->toDateString());

        // Generate di bulan SEBELUM jatuh tempo: belum boleh dicatat.
        $this->generate('2026-09-27');

        $this->assertSame(
            0,
            $this->jumlahJurnal($september),
            'Tagihan yang jatuh temponya belum tercapai tidak boleh dibukukan.'
        );

        // Generate pada TANGGAL JATUH TEMPO: harus dicatat.
        $this->generate('2026-10-27');

        $this->assertSame(
            2,
            $this->jumlahJurnal($september),
            'Tagihan periode September harus tercatat pada generate Oktober (tanggal jatuh temponya).'
        );
    }

    /**
     * Tagihan periode M+1 (yang jatuh tempo bulan M+2) belum boleh ikut
     * tercatat pada generate bulan M+1.
     *
     * Ini yang membuat generate bulan G tidak "membocorkan" tagihan yang
     * belum jatuh tempo -- dan tetap sesuai aturan aplikasi lama.
     */
    #[Test]
    public function tagihan_periode_berikutnya_belum_tercatat_pada_generate_bulan_ini(): void
    {
        $admin = $this->admin();
        $customerId = $this->customerId($admin);
        $this->setSOP();

        $september = $this->tagihan($customerId, 2026, 9, BillingDueDate::compute(2026, 9, self::HARI_GENERATE));
        $oktober = $this->tagihan($customerId, 2026, 10, BillingDueDate::compute(2026, 10, self::HARI_GENERATE));

        $this->generate('2026-10-27');

        $this->assertSame(2, $this->jumlahJurnal($september), 'Tagihan September harus tercatat.');
        $this->assertSame(
            0,
            $this->jumlahJurnal($oktober),
            'Tagihan Oktober (jatuh tempo 27 November) belum boleh tercatat pada generate Oktober.'
        );

        // Generate berikutnya: tagihan Oktober ikut tercatat.
        $this->generate('2026-11-27');

        $this->assertSame(
            2,
            $this->jumlahJurnal($oktober),
            'Tagihan Oktober harus tercatat pada generate November (tanggal jatuh temponya).'
        );
    }

    /**
     * SATU pelanggan dengan DUA bulan menunggak -> DUA set jurnal.
     *
     * Ini menjawab pertanyaan "atau hanya akan generate 1x untuk 1
     * pelanggan?". Jawabannya: TIDAK.
     *
     * Dedup generate adalah per PASANGAN (tagihan, akun kredit), bukan per
     * pelanggan. Kalau dedup-nya per pelanggan, generate hanya akan
     * mencatat tagihan menunggak pertama milik orang itu, dan sisanya
     * akan menggantung -- akun Piutang Usaha jadi kurang dari seharusnya
     * dan pembayaran tagihan kedua tidak bisa menutup piutang.
     */
    #[Test]
    public function satu_pelanggan_dengan_dua_bulan_menunggak_keduanya_tercatat(): void
    {
        $admin = $this->admin();
        $customerId = $this->customerId($admin);
        $this->setSOP();

        $september = $this->tagihan($customerId, 2026, 9, BillingDueDate::compute(2026, 9, self::HARI_GENERATE));
        $agustus = $this->tagihan($customerId, 2026, 8, BillingDueDate::compute(2026, 8, self::HARI_GENERATE));

        $this->generate('2026-10-27');

        $this->assertSame(
            2,
            $this->jumlahJurnal($september),
            'Tagihan September (jatuh tempo 27 Oktober) harus tercatat.'
        );
        $this->assertSame(
            2,
            $this->jumlahJurnal($agustus),
            'Tagihan Agustus (jatuh tempo 27 September) juga harus tercatat -- bukan cuma satu per pelanggan.'
        );

        // Empat baris jurnal: 2 komponen (abodemen + pemakaian) x 2 tagihan.
        $this->assertSame(
            4,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Dua tagihan harus menghasilkan dua set jurnal.'
        );
    }

    /**
     * Generate ulang pada tanggal yang sama tidak boleh menggandakan
     * jurnal.
     *
     * Command dijalankan ulang di setiap login, jadi sifat idempotennya
     * itu syarat -- bukan sekadar nice-to-have.
     */
    #[Test]
    public function generate_ulang_tidak_menggandakan_jurnal(): void
    {
        $admin = $this->admin();
        $customerId = $this->customerId($admin);
        $this->setSOP();

        $september = $this->tagihan($customerId, 2026, 9, BillingDueDate::compute(2026, 9, self::HARI_GENERATE));

        $this->generate('2026-10-27');
        $this->generate('2026-10-27');
        $this->generate('2026-10-27');

        $this->assertSame(
            2,
            $this->jumlahJurnal($september),
            'Generate berulang pada tanggal yang sama tidak boleh menambah jurnal.'
        );
    }

    /**
     * Tagihan yang jatuh tempo SUDAH LEWAT tetap ikut tercatat.
     *
     * Ini penting untuk tagihan yang dibuat terlambat (mis. meter dicatat
     * setelah jadwalnya, atau tagihan yang diperbaiki admin): tagihannya
     * tidak boleh menggantung hanya karena generate pada tanggal jatuh
     * temponya belum sempat jalan.
     */
    #[Test]
    public function tagihan_yang_jatuh_temponya_sudah_lewat_ikut_tercatat(): void
    {
        $admin = $this->admin();
        $customerId = $this->customerId($admin);
        $this->setSOP();

        $agustus = $this->tagihan($customerId, 2026, 8, BillingDueDate::compute(2026, 8, self::HARI_GENERATE));

        // Generate di November, padahal jatuh temponya 27 September.
        $this->generate('2026-11-27');

        $this->assertSame(
            2,
            $this->jumlahJurnal($agustus),
            'Tagihan yang jatuh temponya sudah lewat tetap harus dibukukan sebagai piutang.'
        );
    }

    /**
     * Tanggal jurnal harus TANGGAL GENERATE, bukan tanggal jatuh tempo.
     *
     * Ini perbedaan yang disengaja dari aplikasi lama: sana tanggal
     * jurnalnya = `tgl_akhir`, di sini = tanggal generate. Karena
     * generate jalan pada tanggal yang sama dengan jatuh tempo tagihan
     * (dalam aturan normal), angkanya tetap sama -- tapi kalau
     * generate jalan terlambat, tanggal jurnalnya harus mencerminkan
     * kapan pembukuan itu terjadi.
     */
    #[Test]
    public function tanggal_jurnal_adalah_tanggal_generate(): void
    {
        $admin = $this->admin();
        $customerId = $this->customerId($admin);
        $this->setSOP();

        $agustus = $this->tagihan($customerId, 2026, 8, BillingDueDate::compute(2026, 8, self::HARI_GENERATE));

        $this->generate('2026-11-27');

        $tanggalJurnal = Transaction::where('reverence_type', 'overdue_bill')
            ->where('reverence_id', $agustus->id)
            ->value('tgl_transaksi');

        // `tgl_transaksi` dibaca sebagai DATETIME, jadi bagian tanggalnya
        // yang dibandingkan -- bukan seluruh string.
        $this->assertSame(
            '2026-11-27',
            substr((string) $tanggalJurnal, 0, 10),
            'Tanggal jurnal harus tanggal generate.'
        );
    }

    /**
     * Generate di tanggal yang lebih awal dari jatuh tempo tidak boleh
     * membukukan apa pun -- termasuk lewat jalur manual `--tanggal`.
     */
    #[Test]
    public function generate_manual_dengan_tanggal_lampau_tidak_membukukan_tagihan_nanti(): void
    {
        $admin = $this->admin();
        $customerId = $this->customerId($admin);
        $this->setSOP();

        $oktober = $this->tagihan($customerId, 2026, 10, BillingDueDate::compute(2026, 10, self::HARI_GENERATE));

        // Generate manual untuk tanggal SEBELUM jatuh tempo tagihan.
        $this->generate('2026-10-20');

        $this->assertSame(
            0,
            $this->jumlahJurnal($oktober),
            'Tagihan yang jatuh tempo 27 November tidak boleh tercatat saat generate 20 Oktober.'
        );
    }

    /*
     * ── RANTAI BULANAN ────────────────────────────────────────────────
     *
     * Tiga test di bawah mengunci jawaban untuk pertanyaan:
     *
     *   "Kalau bulan ini tidak bayar, bulan depan generate kok tidak
     *    menambah piutang? Padahal setiap bulan ada generate."
     *
     * Jawabannya ada dua bagian:
     *
     *   1. Generate bulan G MENCATAT tagihan periode G-1 (yang jatuh
     *      tempo di bulan G). Jadi setiap bulan memang bertambah -- TAPI
     *      yang bertambah adalah TAGIHAN BULAN ITU, bukan tagihan lama
     *      yang diputar ulang.
     *   2. Tagihan yang sudah pernah dijurnal TIDAK dijurnal lagi. Kalau
     *      dijurnal ulang setiap bulan, satu tagihan Rp 60.000 akan
     *      menjadi piutang Rp 180.000 setelah tiga bulan -- piutangnya
     *      membengkak tanpa ada tagihan baru.
     *
     * Analoginya: piutang adalah "sisa tagihan yang belum dibayar", bukan
     * "biaya per bulan". Satu tagihan hanya boleh masuk Piutang Usaha
     * sekali.
     */

    /**
     * Tiga bulan berturut-turut tidak membayar -> piutang bertambah
     * setiap bulan, satu set per tagihan.
     *
     * Timeline (tanggal generate 27):
     *
     *   Generate 27 Sep -> tagihan Agustus (jatuh tempo 27 Sep)
     *   Generate 27 Okt -> tagihan September (jatuh tempo 27 Okt)
     *   Generate 27 Nov -> tagihan Oktober (jatuh tempo 27 Nov)
     *
     * Jadi pada akhir November ada TIGA set piutang (6 baris jurnal:
     * abodemen + pemakaian untuk masing-masing tagihan). Bukan satu set
     * yang diulang, dan bukan hanya satu set untuk satu pelanggan.
     */
    #[Test]
    public function tiga_bulan_berturut_turut_piutang_bertambah_setiap_bulan(): void
    {
        $admin = $this->admin();
        $customerId = $this->customerId($admin);
        $this->setSOP();

        $agustus = $this->tagihan($customerId, 2026, 8, BillingDueDate::compute(2026, 8, self::HARI_GENERATE));
        $september = $this->tagihan($customerId, 2026, 9, BillingDueDate::compute(2026, 9, self::HARI_GENERATE));
        $oktober = $this->tagihan($customerId, 2026, 10, BillingDueDate::compute(2026, 10, self::HARI_GENERATE));

        // Generate September: hanya tagihan Agustus yang jatuh tempo.
        $this->generate('2026-09-27');

        $this->assertSame(2, $this->jumlahJurnal($agustus), 'Generate September mencatat tagihan Agustus.');
        $this->assertSame(0, $this->jumlahJurnal($september), 'Tagihan September belum jatuh tempo pada 27 September.');
        $this->assertSame(0, $this->jumlahJurnal($oktober), 'Tagihan Oktober belum jatuh tempo pada 27 September.');

        // Generate Oktober: tagihan September ikut tercatat.
        $this->generate('2026-10-27');

        $this->assertSame(2, $this->jumlahJurnal($agustus), 'Tagihan Agustus tidak dijurnal ulang.');
        $this->assertSame(2, $this->jumlahJurnal($september), 'Generate Oktober mencatat tagihan September.');
        $this->assertSame(0, $this->jumlahJurnal($oktober), 'Tagihan Oktober belum jatuh tempo pada 27 Oktober.');

        // Generate November: tagihan Oktober ikut tercatat.
        $this->generate('2026-11-27');

        $this->assertSame(2, $this->jumlahJurnal($agustus), 'Tagihan Agustus tetap satu set.');
        $this->assertSame(2, $this->jumlahJurnal($september), 'Tagihan September tetap satu set.');
        $this->assertSame(2, $this->jumlahJurnal($oktober), 'Generate November mencatat tagihan Oktober.');

        $this->assertSame(
            6,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Tiga tagihan x dua komponen = enam baris jurnal, bukan satu set yang diulang.'
        );
    }

    /**
     * Tagihan yang sudah PAID tidak pernah menjadi piutang.
     *
     * Kalau pelanggan membayar tagihan September sebelum generate Oktober,
     * generate Oktober harus melewatinya -- dan pada bulan-bulan
     * berikutnya tetap tidak ada piutang untuk tagihan itu. Yang hilang
     * bukan "jurnal yang tertunda", melainkan memang tidak ada piutang:
     * tagihannya sudah lunas.
     */
    #[Test]
    public function tagihan_yang_sudah_dibayar_tidak_membuat_piutang(): void
    {
        $admin = $this->admin();
        $customerId = $this->customerId($admin);
        $this->setSOP();

        $september = $this->tagihan($customerId, 2026, 9, BillingDueDate::compute(2026, 9, self::HARI_GENERATE));

        // Dibayar lunas SEBELUM generate tanggal 27 Oktober.
        $september->update(['status' => 'paid']);

        $this->generate('2026-10-27');

        $this->assertSame(
            0,
            $this->jumlahJurnal($september),
            'Tagihan yang sudah paid tidak boleh dibukukan sebagai piutang.'
        );

        // Bulan berikutnya juga tidak memunculkan piutang susulan.
        $this->generate('2026-11-27');

        $this->assertSame(
            0,
            $this->jumlahJurnal($september),
            'Generate bulan berikutnya tidak boleh menghidupkan kembali tagihan yang sudah lunas.'
        );
        $this->assertSame(
            0,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Tidak ada jurnal piutang yang boleh dibuat untuk tagihan yang sudah dibayar.'
        );
    }

    /**
     * Tagihan yang SAMA tidak dijurnal ulang setiap bulan.
     *
     * Ini sisi lain dari rantai bulanan: kalau tidak ada tagihan baru,
     * generate bulan berikutnya tidak menambah piutang. Tanpa aturan ini,
     * satu tagihan Rp 60.000 yang menunggak tiga bulan akan tercatat
     * sebagai piutang Rp 180.000.
     */
    #[Test]
    public function tagihan_yang_sama_tidak_dijurnal_ulang_bulan_berikutnya(): void
    {
        $admin = $this->admin();
        $customerId = $this->customerId($admin);
        $this->setSOP();

        $september = $this->tagihan($customerId, 2026, 9, BillingDueDate::compute(2026, 9, self::HARI_GENERATE));

        $this->generate('2026-10-27');

        $this->assertSame(2, $this->jumlahJurnal($september), 'Generate Oktober mencatat tagihan September.');

        // Tidak ada tagihan periode baru; generate tetap jalan tiap bulan.
        $this->generate('2026-11-27');
        $this->generate('2026-12-27');
        $this->generate('2027-01-27');

        $this->assertSame(
            2,
            $this->jumlahJurnal($september),
            'Satu tagihan hanya boleh punya satu set jurnal, berapa kali pun generate berjalan.'
        );
        $this->assertSame(
            2,
            Transaction::where('reverence_type', 'overdue_bill')->count(),
            'Piutang tidak boleh bertambah hanya karena generate berjalan lagi.'
        );
    }
}
