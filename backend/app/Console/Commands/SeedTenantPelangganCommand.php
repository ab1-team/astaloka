<?php

namespace App\Console\Commands;

use App\Models\BillPayment;
use App\Models\Central\Tenant;
use App\Models\Customer;
use App\Models\InstallationPackage;
use App\Models\InstallationTicket;
use App\Models\InstallationTicketHistory;
use App\Models\MeterReading;
use App\Models\MonthlyBill;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\SurveyResult;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Village;
use App\Services\AmountRecalculator;
use App\Services\BillingService;
use App\Services\SuspendTransactionsTrigger;
use App\Services\TenantManager;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Isi database LOKASI dengan 10 pelanggan beserta seluruh datanya.
 *
 * Kenapa command, bukan seeder
 * ---------------------------
 * `db:seed` berjalan tanpa konteks lokasi: `database.default` masih menunjuk
 * database PUSAT, jadi seeder biasa akan menulis tabel lokasi ke database
 * pusat. `GuardCentralDatabaseWrites` memang menutup jalur itu untuk
 * MIGRASI, tapi `db:seed` tidak pernah ikut dicek sebagai command migrasi.
 *
 * Command ini me-resolve lokasi sendiri lewat
 * {@see TenantManager::runFor()}, persis seperti `MakeTenantDemoUsers` dan
 * `ReseedMasterData`: `database.default` diarahkan ke database lokasi selama
 * seluruh pekerjaan berjalan, lalu dikembalikan.
 *
 * Data yang dibuat
 * ----------------
 * Sepuluh tiket instalasi dengan status yang berbeda-beda (draft sampai
 * terminated), plus seluruh data turunannya yang memang ADA di aplikasi:
 *
 *   - master  : paket instalasi + blok tarif + empat desa contoh
 *   - akun    : satu user `pelanggan` per pelanggan
 *   - tiket   : `installation_tickets` (kolom `user_id` = TEKNISIS/Cater)
 *   - survey  : `survey_results`
 *   - bayar   : `payments` (jurnal "Pasang Baru" dibuat sendiri oleh
 *                `PaymentObserver`, jadi tidak digandakan di sini)
 *   - aktif   : `customers` + kode pelanggan turunan `villages.code`
 *   - pakai   : `meter_readings`
 *   - tagihan : `monthly_bills`, dihitung dengan
 *                `BillingService::generateForCustomer()` supaya
 *                `total_amount` PERSIS sama dengan hasil aplikasi
 *   - bayar   : `bill_payments` + jurnal pembayarannya
 *   - riwayat : `installation_ticket_histories` (change_type `initial`)
 *
 * Kenapa tidak ada kolom `catatan`
 * -------------------------------
 * Kolom itu memang tidak pernah ada di skema `customers`, dan tidak ada
 * bagian aplikasi lain yang memakainya. Snapshot yang memang dibutuhkan --
 * nama paket, tarif, abodemen, denda -- sudah punya tempatnya di
 * `installation_ticket_histories`.
 *
 * Pembukuan
 * ---------
 * Tagihan yang SUDAH LUNAS dicatat langsung ke kas dan akun pendapatan:
 * debet `1.1.01.01` (Tunai) atau `1.1.01.03` (Transfer BRI), kredit
 * `4.1.01.02` (abodemen), `4.1.01.03` (pemakaian), `4.1.01.04` (denda).
 * Tanggal bayarnya masih di dalam masa tenggang, jadi piutang belum pernah
 * dibentuk untuk tagihan itu -- persis alur `MonthlyBillController::pay()`
 * saat belum ada jurnal piutang.
 *
 * Tagihan yang MENUNGGAK dicatat sebagai piutang: debet `1.1.03.01`
 * (Piutang Usaha), kredit akun pendapatan yang sama. Barisnya memakai
 * `reverence_type = 'overdue_bill'`, sama seperti yang ditulis
 * `billing:generate-overdue-transactions` -- sehingga perintah itu, kalau
 * nanti dijalankan, TIDAK membuat jurnal ganda untuk tagihan yang sama.
 *
 * Trigger `amount` dimatikan sementara selama penulisan massal (lihat
 * `SuspendTransactionsTrigger`), lalu tabel `amount` dihitung ulang satu kali
 * di akhir dengan `AmountRecalculator` -- sama seperti yang dilakukan
 * `billing:generate-overdue-transactions`.
 */
class SeedTenantPelangganCommand extends Command
{
    protected $signature = 'tenancy:seed-pelanggan
        {tenant? : Slug lokasi. Kosongkan untuk semua lokasi aktif.}
        {--force : Hapus data pelanggan versi perintah ini dulu, lalu buat ulang}';

    protected $description = 'Buat 10 data pelanggan (paket, desa, tagihan, dan jurnal) di database lokasi';

    /** Kas tunai -- penerimaan pembayaran tagihan di tangan. */
    private const KAS_TUNAI = '1.1.01.01';

    /** Kas di Bank BRI -- penerimaan pembayaran tagihan lewat transfer. */
    private const KAS_TRANSFER = '1.1.01.03';

    /** Piutang Usaha -- sisi debet jurnal piutang tagihan menunggak. */
    private const PIUTANG_USAHA = '1.1.03.01';

    /** Pendapatan abodemen. */
    private const PENDAPATAN_ABODEMEN = '4.1.01.02';

    /** Pendapatan pemakaian air. */
    private const PENDAPATAN_PEMAKAIAN = '4.1.01.03';

    /** Pendapatan denda. */
    private const PENDAPATAN_DENDA = '4.1.01.04';

    /** Baris jurnal yang menunggu ditulis. */
    private array $jurnalRows = [];

    /** Id transaksi berikutnya. */
    private int $nextJurnalId = 0;

    /** Id user yang dipakai sebagai pencatat jurnal. */
    private int $jurnalUserId = 0;

    public function handle(TenantManager $manager): int
    {
        $slug = $this->argument('tenant');
        $force = (bool) $this->option('force');

        $tenants = $slug
            ? Tenant::where('slug', $slug)->get()
            : Tenant::where('is_active', true)->orderBy('id')->get();

        if ($tenants->isEmpty()) {
            $this->error($slug
                ? "Lokasi `{$slug}` tidak terdaftar."
                : 'Tidak ada lokasi aktif.');

            return self::FAILURE;
        }

        $gagal = 0;

        foreach ($tenants as $tenant) {
            $this->newLine();
            $this->line("<fg=cyan>──</> {$tenant->slug} <fg=gray>({$tenant->database})</>");

            if (! $tenant->databaseExists()) {
                $this->error('   Database tidak ada. Lokasi ini tidak bisa disiapkan.');
                $gagal++;

                continue;
            }

            try {
                $this->seedTenant($manager, $tenant, $force);
            } catch (Throwable $e) {
                $this->error('   GAGAL: '.$e->getMessage());
                report($e);
                $gagal++;

                continue;
            }
        }

        $this->newLine();

        if ($gagal > 0) {
            $this->error("{$gagal} lokasi gagal.");

            return self::FAILURE;
        }

        $this->info(count($tenants).' lokasi selesai diisi data pelanggan.');

        return self::SUCCESS;
    }

    /**
     * Isi satu lokasi.
     *
     * Urutannya penting:
     *   1. trigger `amount` dimatikan (DDL -- harus DI LUAR transaksi),
     *   2. seluruh data ditulis dalam satu transaksi,
     *   3. trigger hidup kembali,
     *   4. tabel `amount` dihitung ulang satu kali.
     */
    private function seedTenant(TenantManager $manager, Tenant $tenant, bool $force): void
    {
        $manager->runFor($tenant, function () use ($force) {
            $this->jurnalRows = [];
            $this->nextJurnalId = 0;

            $this->cekDataLama($force);

            $suspend = new SuspendTransactionsTrigger;
            $suspend->disable();

            try {
                DB::transaction(function () {
                    $this->tulisSemuaData();
                });
            } finally {
                // WAJIB: kalau proses mati di tengah, trigger yang tertinggal
                // mati akan membuat tabel `amount` berhenti ter-update untuk
                // seluruh aplikasi.
                $suspend->restore();
            }

            $this->hitungUlangAmount();

            $this->cetakRingkasan();
        });
    }

    /* ================================================================
     | PEMERIKSAAN & PEMBERSIHAN
     |============================================================= */

    /**
     * Tolak jalan kalau data pelanggan versi perintah ini sudah ada.
     *
     * Menjalankan dua kali tanpa `--force` akan menghasilkan NIK kembar dan
     * kode pelanggan dengan urutan berbeda -- data yang membingungkan tanpa
     * error yang jelas. Jadi lebih baik berhenti di awal.
     */
    private function cekDataLama(bool $force): void
    {
        $ada = InstallationTicket::whereIn('nik', $this->daftarNik())->count();

        if ($ada === 0) {
            return;
        }

        if (! $force) {
            throw new \RuntimeException(
                "Sudah ada {$ada} pelanggan hasil perintah ini. "
                .'Jalankan ulang dengan --force untuk menggantinya.'
            );
        }

        $this->line("   <fg=yellow>mengganti</> {$ada} pelanggan lama...");
        $this->hapusDataLama();
    }

    /**
     * Hapus data pelanggan yang dibuat oleh perintah ini.
     *
     * Jurnal dihapus DULUAN dengan `forceDelete()`: kalau hanya soft-delete,
     * `GenerateOverdueTransactions` -- yang jalan setiap login -- akan tetap
     * melihat jurnal lama sebagai "sudah ada" lewat global scope
     * SoftDeletes, dan `amount` yang dihitung ulang akan tetap memotong
     * saldo yang sudah tidak ada.
     */
    private function hapusDataLama(): void
    {
        $ticketIds = InstallationTicket::whereIn('nik', $this->daftarNik())->pluck('id')->all();

        if ($ticketIds === []) {
            return;
        }

        $paymentIds = Payment::whereIn('ticket_id', $ticketIds)->pluck('id')->all();
        $customerIds = Customer::whereIn('ticket_id', $ticketIds)->pluck('id')->all();
        $billIds = MonthlyBill::whereIn('customer_id', $customerIds)->pluck('id')->all();
        $billPaymentIds = BillPayment::whereIn('bill_id', $billIds)->pluck('id')->all();

        if ($paymentIds !== []) {
            Transaction::withTrashed()
                ->where('reverence_type', 'payment')
                ->whereIn('reverence_id', $paymentIds)
                ->forceDelete();
        }

        if ($billPaymentIds !== []) {
            Transaction::withTrashed()
                ->where('reverence_type', 'bill_payment')
                ->whereIn('reverence_id', $billPaymentIds)
                ->forceDelete();
        }

        if ($billIds !== []) {
            Transaction::withTrashed()
                ->where('reverence_type', 'overdue_bill')
                ->whereIn('reverence_id', $billIds)
                ->forceDelete();
        }

        // `installation_ticket_histories` tidak punya foreign key ke tiket,
        // jadi cascade TIDAK membersihkannya.
        InstallationTicketHistory::whereIn('installation_ticket_id', $ticketIds)->delete();

        // Tiket -> cascade ke payments, survey_results, customers ->
        // cascade ke meter_readings, monthly_bills -> cascade ke bill_payments.
        InstallationTicket::whereIn('id', $ticketIds)->delete();

        // Akun pelanggan tidak ikut terhapus oleh cascade, jadi dihapus
        // eksplisit -- dan hanya SETELAH `customers` hilang (FK-nya tidak
        // punya ON DELETE CASCADE).
        User::whereIn('email', $this->daftarEmailPelanggan())->delete();
    }

    /* ================================================================
     | PENULISAN DATA
     |============================================================= */

    /**
     * Tulis seluruh data: master, akun, tiket, dan semua turunannya.
     */
    private function tulisSemuaData(): void
    {
        $paket = $this->tulisMasterPaket();
        $desa = $this->tulisMasterDesa();

        $admin = User::where('role', 'admin')->orderBy('id')->first();
        $teknisi = User::where('role', 'teknisi')->orderBy('id')->first();

        if (! $admin || ! $teknisi) {
            throw new \RuntimeException(
                'Akun admin/teknisi belum ada di lokasi ini. '
                .'Jalankan `tenancy:make-demo-users` lebih dulu.'
            );
        }

        $this->jurnalUserId = (int) $admin->id;

        $billing = app(BillingService::class);
        $batasTagihan = (int) (Setting::first()?->batas_tagihan ?? 27);

        foreach ($this->rencanaPelanggan() as $i => $rencana) {
            $village = $desa[$rencana['village_code']];
            $paketPelanggan = $paket[$rencana['package']];

            $pengguna = $this->tulisAkunPelanggan($rencana);

            $tanggalOrder = Carbon::parse($rencana['order_date']);

            $ticket = InstallationTicket::create([
                'package_id' => $paketPelanggan->id,
                // `installation_tickets.user_id` = TEKNISIS (Cater), bukan
                // pelanggan. Akun pelanggan ada di `customers.user_id`.
                'user_id' => $teknisi->id,
                'applicant_name' => $rencana['nama'],
                'nik' => $rencana['nik'],
                'rt' => $rencana['rt'],
                'rw' => $rencana['rw'],
                'phone' => $rencana['phone'],
                'gender' => $rencana['gender'],
                'birth_place' => $rencana['birth_place'],
                'birth_date' => $rencana['birth_date'],
                'address' => $rencana['address'],
                'lat' => $rencana['lat'],
                'lng' => $rencana['lng'],
                'status' => $rencana['status'],
                'village_id' => $village->id,
                'created_by' => $admin->id,
                'order_date' => $tanggalOrder->toDateString(),
            ]);

            if ($rencana['survey']) {
                SurveyResult::create([
                    'ticket_id' => $ticket->id,
                    'surveyor_id' => $admin->id,
                    'distance_to_pipe_m' => $rencana['survey_distance'],
                    'material_notes' => $rencana['survey_notes'],
                    'photo_url' => null,
                    'surveyed_at' => $tanggalOrder->copy()->addDays(3)->setTime(10, 0),
                ]);
            }

            if ($rencana['payment']) {
                /*
                 * Jurnal "Pasang Baru" (debet 1.1.01.01, kredit 4.1.01.01)
                 * dibuat sendiri oleh `PaymentObserver` begitu payment
                 * berstatus `confirmed`. Tidak digandakan di sini.
                 */
                Payment::create([
                    'ticket_id' => $ticket->id,
                    'amount' => $rencana['payment']['amount'],
                    'type' => 'installation_fee',
                    'status' => $rencana['payment']['status'],
                    'confirmed_by' => $rencana['payment']['status'] === 'confirmed' ? $admin->id : null,
                    'paid_at' => $rencana['payment']['paid_at'],
                ]);
            }

            $customer = null;

            if ($rencana['activated_at']) {
                $customer = Customer::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $pengguna->id,
                    // Kode pelanggan mengikuti format aplikasi: turunan dari
                    // `villages.code`.
                    'customer_code' => Village::generateCustomerCodeForVillageId($village->id),
                    'initial_meter_reading' => $rencana['meter_awal'],
                    'meter_photo_url' => null,
                    'activated_at' => Carbon::parse($rencana['activated_at'])->setTime(9, 0),
                ]);
            }

            $tagihan = [];

            if ($customer && $rencana['tagihan']) {
                $tagihan = $this->tulisTagihan(
                    $customer,
                    $paketPelanggan,
                    $rencana,
                    $teknisi->id,
                    $admin->id,
                    $billing,
                    $batasTagihan
                );
            }

            $this->tulisRiwayatPaket($ticket, $customer, $paketPelanggan, $rencana, $admin->id, $tagihan);

            $this->line(sprintf(
                '   <fg=green>%-2d</> %-24s %-11s %s',
                $i + 1,
                $rencana['nama'],
                $rencana['status'],
                $customer ? $customer->customer_code : '-'
            ));
        }

        // Jurnal tagihan ditulis setelah semua ticket selesai, supaya id
        // jurnalnya tidak bentrok dengan jurnal "Pasang Baru" yang dibuat
        // `PaymentObserver` di tengah loop.
        $this->tulisJurnalTagihan();
    }

    /**
     * Master: paket instalasi + blok tarif.
     *
     * Paket yang sudah ada TIDAK diubah nominalnya, dan blok tarif yang
     * sudah ada tidak dihapus -- supaya menjalankan ulang perintah ini tidak
     * mengembalikan perubahan yang memang sengaja dibuat admin lokasi.
     */
    private function tulisMasterPaket(): array
    {
        $definisi = [
            'reguler' => [
                'name' => 'Paket Reguler',
                'installation_fee' => 1_500_000,
                'monthly_abodemen' => 15_000,
                'late_penalty' => 10_000,
                'blocks' => [
                    ['usage_min_m3' => 0, 'usage_max_m3' => 10, 'price_per_m3' => 1_500],
                    ['usage_min_m3' => 11, 'usage_max_m3' => 20, 'price_per_m3' => 2_000],
                    ['usage_min_m3' => 21, 'usage_max_m3' => null, 'price_per_m3' => 2_500],
                ],
            ],
            'sosial' => [
                'name' => 'Paket Sosial',
                'installation_fee' => 800_000,
                'monthly_abodemen' => 10_000,
                'late_penalty' => 5_000,
                'blocks' => [
                    ['usage_min_m3' => 0, 'usage_max_m3' => 10, 'price_per_m3' => 1_000],
                    ['usage_min_m3' => 11, 'usage_max_m3' => null, 'price_per_m3' => 1_500],
                ],
            ],
        ];

        $hasil = [];

        foreach ($definisi as $key => $data) {
            $blocks = $data['blocks'];
            unset($data['blocks']);

            $paket = InstallationPackage::firstOrCreate(['name' => $data['name']], $data);

            if ($paket->waterTariffBlocks()->count() === 0) {
                $paket->waterTariffBlocks()->createMany($blocks);
            }

            $hasil[$key] = $paket;
        }

        return $hasil;
    }

    /**
     * Master: empat desa contoh.
     *
     * `villages.code` WAJIB punya minimal dua segment (kecamatan.desa),
     * karena `Village::generateNextCustomerCode()` membentuk kode pelanggan
     * dari segment-segment itu -- dan melempar exception kalau kurang.
     */
    private function tulisMasterDesa(): array
    {
        $daftar = [
            [
                'code' => '005.0001.0001', 'village_name' => 'Sukamaju', 'hamlet_name' => 'Dusun Krajan',
                'address' => 'Jl. Raya Sukamaju No. 12, Kec. Sukamaju', 'phone' => '081211000001',
            ],
            [
                'code' => '005.0002.0001', 'village_name' => 'Cikaramat', 'hamlet_name' => 'Dusun Ngabean',
                'address' => 'Jl. Ciwaruga No. 45, Kec. Cikaramat', 'phone' => '081211000002',
            ],
            [
                'code' => '005.0003.0002', 'village_name' => 'Bojongsari', 'hamlet_name' => 'Dusun Cikeumeuh',
                'address' => 'Jl. Bojong No. 8, Kec. Bojongsari', 'phone' => '081211000003',
            ],
            [
                'code' => '005.0004.0001', 'village_name' => 'Cibeureum', 'hamlet_name' => 'Dusun Waru',
                'address' => 'Jl. Cibeureum No. 3, Kec. Cibeureum', 'phone' => '081211000004',
            ],
        ];

        $hasil = [];

        foreach ($daftar as $desa) {
            $hasil[$desa['code']] = Village::updateOrCreate(['code' => $desa['code']], $desa);
        }

        return $hasil;
    }

    /**
     * Akun `pelanggan` untuk satu pelanggan.
     */
    private function tulisAkunPelanggan(array $rencana): User
    {
        return User::updateOrCreate(
            ['email' => $this->emailPelanggan($rencana)],
            [
                'name' => $rencana['nama'],
                'role' => 'pelanggan',
                'jabatan_id' => null,
                'password' => Hash::make('password'),
            ]
        );
    }

    /**
     * Email akun pelanggan.
     *
     * Formatnya sama dengan yang dipakai `InstallationTicketController`:
     * `slug-nama_NIK@ASTALOKA.local` -- supaya akun yang dibuat perintah ini
     * tidak bentrok dengan akun yang dibuat aplikasinya sendiri.
     */
    private function emailPelanggan(array $rencana): string
    {
        return Str::slug($rencana['nama']).'_'.$rencana['nik'].'@ASTALOKA.local';
    }

    /**
     * Meter reading + tagihan bulanan untuk satu pelanggan aktif.
     *
     * Tagihan dibuat lewat `BillingService::generateForCustomer()` -- bukan
     * dirangkai manual -- supaya `total_amount`, `usage_charge`, `abodemen`,
     * `penalty_amount`, dan `due_date` PERSIS sama dengan hasil aplikasi.
     *
     * @return array<int, array{bill:MonthlyBill,payment:?BillPayment}>
     */
    private function tulisTagihan(
        Customer $customer,
        InstallationPackage $paket,
        array $rencana,
        int $teknisiId,
        int $adminId,
        BillingService $billing,
        int $batasTagihan
    ): array {
        $hasil = [];
        $meter = (float) $rencana['meter_awal'];

        foreach ($rencana['tagihan'] as $baris) {
            [$tahun, $bulan] = array_map('intval', explode('-', $baris['bulan']));

            $meter += (float) $baris['pemakaian'];

            MeterReading::create([
                'customer_id' => $customer->id,
                'recorded_by' => $teknisiId,
                'reading_year' => $tahun,
                'reading_month' => $bulan,
                'meter_value' => $meter,
                'photo_url' => null,
                'recorded_at' => Carbon::create($tahun, $bulan, 1)
                    ->setDay(min($batasTagihan, Carbon::create($tahun, $bulan, 1)->daysInMonth))
                    ->setTime(9, 0),
            ]);

            $bill = $billing->generateForCustomer($customer, $tahun, $bulan, $batasTagihan);

            $payment = null;

            if ($baris['status'] === 'paid') {
                $bill->update(['status' => 'paid']);

                $payment = BillPayment::create([
                    'bill_id' => $bill->id,
                    'amount_paid' => $bill->total_amount,
                    'confirmed_by' => $adminId,
                    // Dibayar H+1 dari jatuh tempo: masih di dalam masa
                    // tenggang, jadi piutang belum pernah dibentuk untuk
                    // tagihan ini dan pembayaran bisa langsung masuk ke akun
                    // pendapatan.
                    'paid_at' => $bill->due_date->copy()->addDay()->setTime(10, 0),
                ]);
            }

            $hasil[] = ['bill' => $bill, 'payment' => $payment];
        }

        return $hasil;
    }

    /**
     * Riwayat paket (`installation_ticket_histories`, change_type `initial`).
     *
     * Baris ini yang membuat popup "Detail Paket & Tagihan" punya garis
     * waktu -- `GET /installation-tickets/{id}/history` membacanya, dan
     * `PackageDetailModal.vue` merender timeline-nya.
     *
     * `effective_until` sengaja NULL: itu arti "paket ini masih aktif".
     *	Nanti, saat admin mengganti paket, `changePackage` akan menutup baris
     * ini dan membuat baris berikutnya -- persis alur aslinya.
     */
    private function tulisRiwayatPaket(
        InstallationTicket $ticket,
        ?Customer $customer,
        InstallationPackage $paket,
        array $rencana,
        int $adminId,
        array $tagihan
    ): void {
        $totalTagihan = 0.0;

        foreach ($tagihan as $baris) {
            $totalTagihan += (float) $baris['bill']->total_amount;
        }

        $totalBayar = (float) Payment::where('ticket_id', $ticket->id)
            ->where('status', 'confirmed')
            ->sum('amount');

        foreach ($tagihan as $baris) {
            if ($baris['payment']) {
                $totalBayar += (float) $baris['payment']->amount_paid;
            }
        }

        InstallationTicketHistory::create([
            'installation_ticket_id' => $ticket->id,
            'customer_id' => $customer?->id,
            'package_id' => $paket->id,
            'new_package_id' => $paket->id,
            'package_name' => $paket->name,
            'installation_fee' => $paket->installation_fee,
            'monthly_abodemen' => $paket->monthly_abodemen,
            'late_penalty' => $paket->late_penalty,
            'effective_from' => $customer?->activated_at
                ?? Carbon::parse($rencana['order_date'])->setTime(9, 0),
            'effective_until' => null,
            'total_paid_on_old_package' => $totalBayar,
            'total_billed_on_old_package' => $totalTagihan,
            'remaining_on_old_package' => max(0, $totalTagihan - $totalBayar),
            'change_type' => 'initial',
            'reason' => 'Paket awal saat instalasi dicatat.',
            'changed_by' => $adminId,
        ]);
    }

    /* ================================================================
     | JURNAL TAGIHAN
     |============================================================= */

    /**
     * Tulis jurnal untuk setiap tagihan: pelunasan (kas -> pendapatan) atau
     * tunggakan (piutang -> pendapatan).
     *
     * Tagihan yang menunggak memakai `reverence_type = 'overdue_bill'` --
     * sama seperti `billing:generate-overdue-transactions` -- supaya perintah
     * itu tidak membuat jurnal ganda untuk tagihan yang sama.
     */
    private function tulisJurnalTagihan(): void
    {
        $bills = MonthlyBill::with(['customer.user', 'customer.ticket.package', 'billPayments'])
            ->orderBy('id')
            ->get();

        foreach ($bills as $bill) {
            $customer = $bill->customer;

            if (! $customer) {
                continue;
            }

            $nama = trim((string) ($customer->user?->name ?: $customer->ticket?->applicant_name));
            $kode = (string) $customer->customer_code;

            $abodemen = (float) ($bill->abodemen ?? 0);
            $pemakaian = (float) ($bill->usage_charge ?? 0);
            $denda = (float) ($bill->penalty_amount ?? 0);

            $payment = $bill->billPayments->sortBy('id')->first();

            if ($payment) {
                // ── LUNAS: kas masuk, pendapatan diakui ──
                $kas = $this->akunKas($bill->customer);
                $tanggal = $payment->paid_at->toDateString();
                $suffix = $payment->paid_at->toDateString() === $bill->due_date->toDateString()
                    ? '' : ' - '.$this->caraBayar($bill->customer);

                $this->queueJurnal([
                    'tgl_transaksi' => $tanggal,
                    'account_debet' => $kas,
                    'account_kredit' => self::PENDAPATAN_ABODEMEN,
                    'reverence_type' => 'bill_payment',
                    'reverence_id' => $payment->id,
                    'keterangan_transaksi' => 'Tagihan Abodemen '.$bill->periodLabel().' an. '.$nama.' ('.$kode.')'.$suffix,
                    'relasi' => $kode,
                    'saldo' => $abodemen,
                ], $abodemen > 0);

                $this->queueJurnal([
                    'tgl_transaksi' => $tanggal,
                    'account_debet' => $kas,
                    'account_kredit' => self::PENDAPATAN_PEMAKAIAN,
                    'reverence_type' => 'bill_payment',
                    'reverence_id' => $payment->id,
                    'keterangan_transaksi' => 'Tagihan Pemakaian '.$bill->periodLabel().' an. '.$nama.' ('.$kode.')'.$suffix,
                    'relasi' => $kode,
                    'saldo' => $pemakaian,
                ], $pemakaian > 0);

                $this->queueJurnal([
                    'tgl_transaksi' => $tanggal,
                    'account_debet' => $kas,
                    'account_kredit' => self::PENDAPATAN_DENDA,
                    'reverence_type' => 'bill_payment',
                    'reverence_id' => $payment->id,
                    'keterangan_transaksi' => 'Tagihan Denda '.$bill->periodLabel().' an. '.$nama.' ('.$kode.')'.$suffix,
                    'relasi' => $kode,
                    'saldo' => $denda,
                ], $denda > 0);

                continue;
            }

            // ── MENUNGGAK: piutang usaha terbentuk, pendapatan diakui ──
            $tanggal = $bill->due_date->toDateString();
            $suffix = ' '.$this->caraBayar($bill->customer);

            $this->queueJurnal([
                'tgl_transaksi' => $tanggal,
                'account_debet' => self::PIUTANG_USAHA,
                'account_kredit' => self::PENDAPATAN_ABODEMEN,
                'reverence_type' => 'overdue_bill',
                'reverence_id' => $bill->id,
                'keterangan_transaksi' => 'Piutang Abodemen '.$bill->periodLabel().' an. '.$nama.' ('.$kode.')',
                'relasi' => $kode,
                'saldo' => $abodemen,
            ], $abodemen > 0);

            $this->queueJurnal([
                'tgl_transaksi' => $tanggal,
                'account_debet' => self::PIUTANG_USAHA,
                'account_kredit' => self::PENDAPATAN_PEMAKAIAN,
                'reverence_type' => 'overdue_bill',
                'reverence_id' => $bill->id,
                'keterangan_transaksi' => 'Piutang Pemakaian '.$bill->periodLabel().' an. '.$nama.' ('.$kode.')',
                'relasi' => $kode,
                'saldo' => $pemakaian,
            ], $pemakaian > 0);

            $this->queueJurnal([
                'tgl_transaksi' => $tanggal,
                'account_debet' => self::PIUTANG_USAHA,
                'account_kredit' => self::PENDAPATAN_DENDA,
                'reverence_type' => 'overdue_bill',
                'reverence_id' => $bill->id,
                'keterangan_transaksi' => 'Piutang Denda '.$bill->periodLabel().' an. '.$nama.' ('.$kode.')',
                'relasi' => $kode,
                'saldo' => $denda,
            ], $denda > 0);
        }

        $this->flushJurnal();
    }

    /**
     * Akun kas tujuan penerimaan pembayaran.
     *
     * Dipilih per pelanggan supaya kedua akun kas (Tunai dan Transfer BRI)
     * sama-sama terpakai -- persis seperti pilihan "Tunai / Transfer" di
     * form pembayaran tagihan.
     */
    private function akunKas(Customer $customer): string
    {
        return $this->caraBayar($customer) === 'Transfer BRI' ? self::KAS_TRANSFER : self::KAS_TUNAI;
    }

    /**
     * Cara bayar pelanggan. Deterministik, berbasis kode pelanggan.
     */
    private function caraBayar(Customer $customer): string
    {
        return ((int) $customer->id) % 2 === 0 ? 'Transfer BRI' : 'Tunai';
    }

    /**
     * Tambahkan satu baris jurnal ke antrean.
     *
     * Baris dengan nominal nol DILEWATI: jurnal tanpa nominal hanya menambah
     * baris tanpa mengubah saldo, dan laporan buku besar jadi lebih sulit
     * dibaca tanpa manfaat apa pun.
     */
    private function queueJurnal(array $baris, bool $boleh = true): void
    {
        if (! $boleh || (float) $baris['saldo'] <= 0) {
            return;
        }

        $id = $this->nextJurnalId++;

        $this->jurnalRows[] = array_merge([
            'id' => $id,
            'transaction_group' => null,
            'penerima_komisi_id' => null,
            'urutan' => $id,
            'id_user' => $this->jurnalUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $baris);
    }

    /**
     * Tulis antrean jurnal ke tabel `transactions`.
     *
     * Id ditulis eksplisit supaya kolom `urutan` terisi dalam satu INSERT --
     * menghindari `->update(['urutan' => $id])` per baris yang memicu
     * agregasi `amount` berulang.
     */
    private function flushJurnal(): void
    {
        if ($this->jurnalRows === []) {
            return;
        }

        // `max('id)` WAJIB lewat query builder polos: `Transaction` memakai
        // global scope SoftDeletes, jadi `Transaction::max('id')` tidak
        // melihat baris yang sudah di-soft-delete -- padahal primary key-nya
        // tetap terpakai.
        $this->nextJurnalId = ((int) DB::table('transactions')->max('id')) + 1;

        /*
         * Perulangan WAJIB memakai indeks.
         *
         * `foreach ($this->jurnalRows as $baris)` memberi SALINAN baris itu,
         * jadi penulisan `$baris['id'] = ...` hanya mengubah salinannya --
         * array aslinya tetap menyimpan id placeholder dari
         * `queueJurnal()`, yaitu 0, 1, 2, ... INSERT-nya lalu menabrak
         * primary key yang sudah dipakai jurnal "Pasang Baru" yang dibuat
         * `PaymentObserver`.
         *
         * Ditulis lewat indeks supaya yang berubah benar-benar baris yang
         * akan di-INSERT.
         */
        foreach ($this->jurnalRows as $i => $baris) {
            $id = $this->nextJurnalId++;

            $this->jurnalRows[$i]['id'] = $id;
            $this->jurnalRows[$i]['urutan'] = $id;
        }

        DB::table('transactions')->insert($this->jurnalRows);

        $this->jurnalRows = [];
    }

    /**
     * Hitung ulang tabel `amount` dari `transactions`.
     *
     * Menggantikan trigger `create_amount_debit` yang melakukan hal yang sama
     * untuk setiap baris. Dipanggil SATU KALI di akhir -- bukan per jurnal.
     */
    private function hitungUlangAmount(): void
    {
        $computed = array_values(AmountRecalculator::compute());

        if ($computed !== []) {
            DB::table('amount')->upsert(
                array_map(fn (array $r) => [
                    'id' => $r['id'],
                    'account_id' => $r['account_id'],
                    'tahun' => $r['tahun'],
                    'bulan' => $r['bulan'],
                    'debit' => $r['debit'],
                    'kredit' => $r['kredit'],
                ], $computed),
                ['id'],
                ['account_id', 'tahun', 'bulan', 'debit', 'kredit']
            );
        }

        // Baris `amount` yang tidak lagi punya transaksi (mis. sisa dari
        // data lama) dibuang -- kecuali baris tutup buku (bulan 00/13).
        $idsValid = array_map(fn (array $r) => (string) $r['id'], $computed);

        $basi = DB::table('amount')
            ->get(['id', 'bulan'])
            ->filter(fn ($r) => ! in_array((string) $r->id, $idsValid, true)
                && ! in_array((string) $r->bulan, ['00', '13'], true))
            ->pluck('id')
            ->all();

        if ($basi !== []) {
            DB::table('amount')->whereIn('id', $basi)->delete();
        }
    }

    /* ================================================================
     | RENCANA DATA
     |============================================================= */

    /**
     * NIK seluruh pelanggan yang dibuat perintah ini.
     *
     * @return array<int, string>
     */
    private function daftarNik(): array
    {
        return array_column($this->rencanaPelanggan(), 'nik');
    }

    /**
     * Email seluruh akun pelanggan yang dibuat perintah ini.
     *
     * @return array<int, string>
     */
    private function daftarEmailPelanggan(): array
    {
        return array_map(
            fn (array $p) => $this->emailPelanggan($p),
            $this->rencanaPelanggan()
        );
    }

    /**
     * Kesepuluh pelanggan yang akan dibuat.
     *
     * Semuanya deterministik -- tidak ada `rand()` -- supaya menjalankan ulang
     * perintah ini menghasilkan data yang sama persis, dan supaya `--force`
     * bisa yakin tidak ada baris yang tertinggal.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rencanaPelanggan(): array
    {
        return [
            [
                'nama' => 'Ahmad Fauzi Ramadhan',
                'nik' => '3273011205850001',
                'gender' => 'male',
                'birth_place' => 'Bandung',
                'birth_date' => '1985-05-12',
                'phone' => '081312450001',
                'rt' => '02', 'rw' => '05',
                'address' => 'Kp. Sukamaju No. 12 RT 02 RW 05',
                'village_code' => '005.0001.0001',
                'lat' => -6.9175000, 'lng' => 107.6150000,
                'package' => 'reguler',
                'status' => 'draft',
                'order_date' => '2026-10-05',
                'survey' => false,
                'survey_distance' => 0,
                'survey_notes' => '',
                'payment' => null,
                'activated_at' => null,
                'meter_awal' => 0,
                'tagihan' => null,
            ],
            [
                'nama' => 'Siti Nurhaliza Putri',
                'nik' => '3273014503890002',
                'gender' => 'female',
                'birth_place' => 'Sumedang',
                'birth_date' => '1989-03-08',
                'phone' => '081312450002',
                'rt' => '03', 'rw' => '02',
                'address' => 'Kp. Ngabean No. 45 RT 03 RW 02',
                'village_code' => '005.0002.0001',
                'lat' => -6.9042000, 'lng' => 107.6013000,
                'package' => 'sosial',
                'status' => 'pending',
                'order_date' => '2026-09-18',
                'survey' => false,
                'survey_distance' => 0,
                'survey_notes' => '',
                'payment' => null,
                'activated_at' => null,
                'meter_awal' => 0,
                'tagihan' => null,
            ],
            [
                'nama' => 'Bambang Suryanto',
                'nik' => '3273010807920003',
                'gender' => 'male',
                'birth_place' => 'Ciamis',
                'birth_date' => '1992-07-24',
                'phone' => '081312450003',
                'rt' => '01', 'rw' => '04',
                'address' => 'Kp. Cikeumeuh No. 8 RT 01 RW 04',
                'village_code' => '005.0003.0002',
                'lat' => -6.8891000, 'lng' => 107.6487000,
                'package' => 'reguler',
                'status' => 'surveyed',
                'order_date' => '2026-08-22',
                'survey' => true,
                'survey_distance' => 12,
                'survey_notes' => 'Jalur pipa utama tersedia, sambungan ke rumah pelanggan sepanjang 12 meter.',
                'payment' => null,
                'activated_at' => null,
                'meter_awal' => 0,
                'tagihan' => null,
            ],
            [
                'nama' => 'Dewi Anggraini',
                'nik' => '3273015210890004',
                'gender' => 'female',
                'birth_place' => 'Tasikmalaya',
                'birth_date' => '1989-10-08',
                'phone' => '081312450004',
                'rt' => '04', 'rw' => '01',
                'address' => 'Kp. Waru No. 21 RT 04 RW 01',
                'village_code' => '005.0004.0001',
                'lat' => -6.8735000, 'lng' => 107.5924000,
                'package' => 'reguler',
                'status' => 'unpaid',
                'order_date' => '2026-07-14',
                'survey' => true,
                'survey_distance' => 18,
                'survey_notes' => 'Akses penggalian perlu izin warga sekitar karena jalan lingkungan sempit.',
                'payment' => [
                    'amount' => 750_000,
                    'status' => 'pending',
                    'paid_at' => null,
                ],
                'activated_at' => null,
                'meter_awal' => 0,
                'tagihan' => null,
            ],
            [
                'nama' => 'Rizky Ramadhan',
                'nik' => '3273012304940005',
                'gender' => 'male',
                'birth_place' => 'Subang',
                'birth_date' => '1994-04-23',
                'phone' => '081312450005',
                'rt' => '02', 'rw' => '05',
                'address' => 'Kp. Sukamaju No. 34 RT 02 RW 05',
                'village_code' => '005.0001.0001',
                'lat' => -6.9181000, 'lng' => 107.6162000,
                'package' => 'reguler',
                'status' => 'processing',
                'order_date' => '2026-07-28',
                'survey' => true,
                'survey_distance' => 9,
                'survey_notes' => 'Material standar, akses mudah ke titik sambungan.',
                'payment' => [
                    'amount' => 1_500_000,
                    'status' => 'confirmed',
                    'paid_at' => '2026-08-02 10:00:00',
                ],
                'activated_at' => null,
                'meter_awal' => 0,
                'tagihan' => null,
            ],
            [
                'nama' => 'Yuli Iswanto',
                'nik' => '3273010101800006',
                'gender' => 'male',
                'birth_place' => 'Bandung',
                'birth_date' => '1980-01-11',
                'phone' => '081312450006',
                'rt' => '01', 'rw' => '03',
                'address' => 'Kp. Ngabean No. 7 RT 01 RW 03',
                'village_code' => '005.0002.0001',
                'lat' => -6.9048000, 'lng' => 107.6008000,
                'package' => 'reguler',
                'status' => 'completed',
                'order_date' => '2026-05-25',
                'survey' => true,
                'survey_distance' => 15,
                'survey_notes' => 'Pipa galvanis 3/4 inch tersedia di jalur utama, meteran dipasang di depan pagar.',
                'payment' => [
                    'amount' => 1_500_000,
                    'status' => 'confirmed',
                    'paid_at' => '2026-05-30 09:30:00',
                ],
                'activated_at' => '2026-06-05 09:00:00',
                'meter_awal' => 12,
                'tagihan' => [
                    ['bulan' => '2026-07', 'status' => 'paid', 'pemakaian' => 10],
                    ['bulan' => '2026-08', 'status' => 'paid', 'pemakaian' => 14],
                    ['bulan' => '2026-09', 'status' => 'paid', 'pemakaian' => 9],
                ],
            ],
            [
                'nama' => 'Maria Ulfah',
                'nik' => '3273014412850007',
                'gender' => 'female',
                'birth_place' => 'Majalengka',
                'birth_date' => '1985-12-14',
                'phone' => '081312450007',
                'rt' => '03', 'rw' => '04',
                'address' => 'Kp. Cikeumeuh No. 19 RT 03 RW 04',
                'village_code' => '005.0003.0002',
                'lat' => -6.8897000, 'lng' => 107.6495000,
                'package' => 'sosial',
                'status' => 'completed',
                'order_date' => '2026-06-02',
                'survey' => true,
                'survey_distance' => 7,
                'survey_notes' => 'Sambungan pendek, tidak perlu penggalian besar.',
                'payment' => [
                    'amount' => 800_000,
                    'status' => 'confirmed',
                    'paid_at' => '2026-06-05 09:15:00',
                ],
                'activated_at' => '2026-06-15 09:00:00',
                'meter_awal' => 5,
                'tagihan' => [
                    ['bulan' => '2026-07', 'status' => 'paid', 'pemakaian' => 8],
                    ['bulan' => '2026-08', 'status' => 'paid', 'pemakaian' => 12],
                    ['bulan' => '2026-09', 'status' => 'paid', 'pemakaian' => 16],
                ],
            ],
            [
                'nama' => 'Hendra Gunawan',
                'nik' => '3273011709770008',
                'gender' => 'male',
                'birth_place' => 'Sumedang',
                'birth_date' => '1977-09-17',
                'phone' => '081312450008',
                'rt' => '01', 'rw' => '02',
                'address' => 'Kp. Waru No. 4 RT 01 RW 02',
                'village_code' => '005.0004.0001',
                'lat' => -6.8741000, 'lng' => 107.5931000,
                'package' => 'reguler',
                'status' => 'completed',
                'order_date' => '2026-06-20',
                'survey' => true,
                'survey_distance' => 22,
                'survey_notes' => 'Tanah keras di sisi jalan, perlu pengerasan trench lebih dalam.',
                'payment' => [
                    'amount' => 1_500_000,
                    'status' => 'confirmed',
                    'paid_at' => '2026-06-24 10:45:00',
                ],
                'activated_at' => '2026-07-05 09:00:00',
                'meter_awal' => 0,
                'tagihan' => [
                    ['bulan' => '2026-08', 'status' => 'paid', 'pemakaian' => 11],
                    ['bulan' => '2026-09', 'status' => 'paid', 'pemakaian' => 12],
                    ['bulan' => '2026-10', 'status' => 'unpaid', 'pemakaian' => 7],
                ],
            ],
            [
                'nama' => 'Nurul Hidayah',
                'nik' => '3273016003830009',
                'gender' => 'female',
                'birth_place' => 'Bandung',
                'birth_date' => '1983-03-20',
                'phone' => '081312450009',
                'rt' => '05', 'rw' => '06',
                'address' => 'Kp. Sukamaju No. 55 RT 05 RW 06',
                'village_code' => '005.0001.0001',
                'lat' => -6.9163000, 'lng' => 107.6134000,
                'package' => 'reguler',
                'status' => 'suspended',
                'order_date' => '2026-05-18',
                'survey' => true,
                'survey_distance' => 10,
                'survey_notes' => 'Instalasi standar, meteran diletakkan di pagar depan.',
                'payment' => [
                    'amount' => 1_500_000,
                    'status' => 'confirmed',
                    'paid_at' => '2026-05-22 11:00:00',
                ],
                'activated_at' => '2026-06-02 09:00:00',
                'meter_awal' => 3,
                'tagihan' => [
                    ['bulan' => '2026-07', 'status' => 'paid', 'pemakaian' => 9],
                    ['bulan' => '2026-08', 'status' => 'unpaid', 'pemakaian' => 10],
                    ['bulan' => '2026-09', 'status' => 'unpaid', 'pemakaian' => 13],
                    ['bulan' => '2026-10', 'status' => 'unpaid', 'pemakaian' => 8],
                ],
            ],
            [
                'nama' => 'Joko Purnomo',
                'nik' => '3273010905910010',
                'gender' => 'male',
                'birth_place' => 'Karawang',
                'birth_date' => '1991-05-09',
                'phone' => '081312450010',
                'rt' => '04', 'rw' => '05',
                'address' => 'Kp. Ngabean No. 62 RT 04 RW 05',
                'village_code' => '005.0002.0001',
                'lat' => -6.9036000, 'lng' => 107.6024000,
                'package' => 'reguler',
                'status' => 'terminated',
                'order_date' => '2026-03-19',
                'survey' => true,
                'survey_distance' => 26,
                'survey_notes' => 'Pelanggan membatalkan pengajuan karena biaya pemasangan tidak sesuai anggaran.',
                'payment' => [
                    'amount' => 450_000,
                    'status' => 'confirmed',
                    'paid_at' => '2026-03-25 13:20:00',
                ],
                'activated_at' => null,
                'meter_awal' => 0,
                'tagihan' => null,
            ],
        ];
    }

    /* ================================================================
     | RINGKASAN
     |============================================================= */

    /**
     * Cetak angka akhir supaya bisa dibandingkan dengan halaman aplikasi.
     */
    private function cetakRingkasan(): void
    {
        $this->line('');
        $this->line('   <fg=gray>Ringkasan:</>');
        $this->line('     tiket instalasi   : '.InstallationTicket::count());
        $this->line('     akun pelanggan    : '.User::where('role', 'pelanggan')->count());
        $this->line('     pelanggan aktif   : '.Customer::count());
        $this->line('     survey            : '.SurveyResult::count());
        $this->line('     pembayaran install: '.Payment::count());
        $this->line('     meter reading     : '.MeterReading::count());
        $this->line('     tagihan bulanan   : '.MonthlyBill::count()
            .' ('.MonthlyBill::where('status', 'unpaid')->count().' belum bayar)');
        $this->line('     pembayaran tagihan: '.BillPayment::count());
        $this->line('     riwayat paket     : '.InstallationTicketHistory::count());
        $this->line('     jurnal transaksi  : '.Transaction::count());
        $this->line('     baris amount      : '.DB::table('amount')->count());
    }
}
