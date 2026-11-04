<?php

namespace App\Console\Commands;

use App\Models\MonthlyBill;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BillingTolerance;
use App\Services\SuspendTransactionsTrigger;
use App\Services\TenantCache;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateOverdueTransactions extends Command
{
    protected $signature = 'billing:generate-overdue-transactions
                            {--tanggal= : Tanggal acuan (Y-m-d); default: hari ini}
                            {--force : Hapus jurnal tunggakan lama untuk bill yang sama sebelum insert}
                            {--chunk=500 : Jumlah baris jurnal per statement bulk insert}';

    protected $description = 'Generate jurnal piutang untuk tagihan menunggak (otomatis jam 00:01)';

    public function handle()
    {
        $today = $this->option('tanggal') ?: now()->toDateString();
        $today = date('Y-m-d', strtotime($today));
        $todayYmd = $today;
        $force = (bool) $this->option('force');

        // Ambang tunggakan: berapa HARI (dihitung dari hari generate) sebuah
        // tagihan dianggap menunggak.
        //
        // Nilai ini dibaca sebagai JUMLAH HARI di sini dengan sengaja,
        // sementara `toleransi_tunggakan` dipakai sebagai TANGGAL di dua
        // tempat lain. Perbedaan makna itu sekarang dikunci di
        // `BillingTolerance`, bukan dibaca langsung dari `Setting`.
        //
        // Data aktual perusahaan: tagihan periode Agustus/2026 jatuh tempo
        // 26-27 September. Dengan ambang hari-toleransi dalam bulan pemakaian
        // (tanggal 6), ambangnya jatuh ke 6 AGUSTUS -- sehingga 26 September
        // dianggap belum menunggak dan 3286 tagihan gagal diproses. Versi ini
        // (6 hari sebelum hari generate) memberi ambang 30 September, sesuai.
        $toleransi = app(BillingTolerance::class)->graceDays();
        $thresholdDate = date('Y-m-d', strtotime("$today -$toleransi days"));

        $overdueBills = MonthlyBill::where('status', 'unpaid')
            ->where('due_date', '<', $thresholdDate)
            ->with(['customer.user'])
            ->get();

        if ($overdueBills->isEmpty()) {
            $this->info('Tidak ada tagihan menunggak.');
            // Tetap tulis ringkasan supaya frontend tidak salah menampilkan
            // "diproses 0" padahal prosesnya memang tidak menemukan apa pun.
            $this->storeRunSummary($today, $thresholdDate, 0, 0, 0, 0, 0);

            return self::SUCCESS;
        }

        $systemUser = User::where('role', 'admin')->first();
        if (! $systemUser) {
            $this->error('User admin tidak ditemukan.');
            $this->storeRunSummary($today, $thresholdDate, 0, $overdueBills->count(), 0, 0, 0);

            return self::FAILURE;
        }

        $processed = 0;
        $skipped = 0;
        // Penghitung jurnal yang benar-benar DIBUAT pada run ini. Dipakai
        // untuk ringkasan popup — jangan dikira dari query jurnal bertanggal
        // hari ini, karena pada run ulang tidak ada jurnal baru sama sekali.
        $jurnalAbodemen = 0;
        $jurnalPemakaian = 0;
        $jurnalDenda = 0;

        $chunkSize = max(1, (int) $this->option('chunk'));

        // Akun yang ikut berubah karena generate ini. Tabel `amount` dihitung
        // ulang satu kali di akhir, menggantikan perhitungan berulang yang
        // tadinya dilakukan trigger MySQL untuk setiap baris.
        $affectedAccounts = [];

        // Trigger `amount` dimatikan sementara selama insert dan SELALU
        // dikembalikan lagi sebelum selesai — termasuk saat gagal.
        $suspend = new SuspendTransactionsTrigger;
        $suspend->disable();

        DB::beginTransaction();

        try {
            // Id ditulis eksplisit agar kolom `urutan` terisi dalam satu INSERT.
            //
            // Sebelumnya tiap jurnal melakukan `$trx->update(['urutan' => $trx->id])`
            // sendiri. Update itu memicu trigger `update_amount_debit` yang
            // menjalankan 8x SUM() penuh PER BARIS, padahal `urutan` sama
            // sekali tidak memengaruhi saldo.
            //
            // PENTING: `max('id')` WAJIB dibaca lewat query builder polos,
            // bukan lewat model Eloquent.
            //
            // `Transaction` memakai global scope `SoftDeletes`, jadi
            // `Transaction::max('id')` TIDAK melihat baris yang sudah
            // di-soft-delete. Padahal primary key `id` tetap dipakai baris
            // itu -- soft delete tidak membebaskan id-nya.
            //
            // Akibatnya: begitu ada satu baris soft-deleted dengan id
            // tertinggi ( commonplace: rollback pembayaran meng-soft-delete
            // jurnal `bill_payment`), `max('id')` mengembalikan angka yang
            // LEBIH KECIL dari id yang benar-benar terpakai. Command lalu
            // menghitung `nextId` yang sudah dipakai -> INSERT gagal dengan
            // "Duplicate entry ... for key 'transactions.PRIMARY'" ->
            // seluruh generate piutang hari itu GAGAL tanpa menghasilkan
            // satu baris pun.
            //
            // `DB::table()` mengabaikan global scope, jadi id yang benar
            // (termasuk yang sudah soft-deleted) ikut terhitung.
            $nextId = ((int) DB::table('transactions')->max('id')) + 1;
            $now = now();

            // ── Tagihan yang benar-benar belum punya jurnal ──
            //
            // Dulu dicek per-tagihan dengan `->exists()` di dalam loop: itu N+1
            // query untuk puluhan ribu tagihan. Sekarang satu query untuk
            // semuanya, lalu dicek dari memori.
            //
            // Kuncinya PASANGAN (tagihan, akun kredit), bukan tagihan saja.
            // Kalau hanya per tagihan, tagihan yang jurnal abodemennya sudah
            // ada tapi jurnal pemakaiannya belum akan SELALU dilewati —
            // padahal jurnal pemakaiannya memang belum pernah dibuat.
            // Command ini dijalankan ulang di setiap login, jadi seperti ini
            // prosesnya tidak pernah bisa tuntas.
            $alreadyHave = $force
                ? collect()
                : Transaction::where('reverence_type', 'overdue_bill')
                    ->select('reverence_id', 'account_kredit')
                    ->distinct()
                    ->get()
                    ->map(fn ($t) => $t->reverence_id.'|'.$t->account_kredit)
                    ->flip();

            $hasJournal = fn ($bill, string $kredit) => $force ? false : $alreadyHave->has($bill->id.'|'.$kredit);

            // Karena deduplikasi kini per pasangan akun, "sudah punya
            // jurnal" tidak bisa lagi dihitung dari jumlah tagihan. Hitung
            // per tipe: tagihan yang DILEWATI kalau sudah punya jurnal untuk
            // tipe itu.
            //
            // WAJIB `keyBy('id')`: `Collection::has()` memakai KEY, bukan
            // nilai. Hasil `filter()` punya key integer 0,1,2,... sehingga
            // `has($bill->id)` hampir selalu salah dan TIDAK ADA jurnal yang
            // pernah dibuat — command tetap melaporkan "berhasil memproses"
            // padahal tabel kosong.
            $needsAbodemen = $overdueBills
                ->filter(fn ($bill) => (float) ($bill->abodemen ?? 0) > 0
                    && ! $hasJournal($bill, '4.1.01.02'))
                ->keyBy('id');

            $needsPemakaian = $overdueBills
                ->filter(fn ($bill) => (float) ($bill->usage_charge ?? 0) > 0
                    && ! $hasJournal($bill, '4.1.01.03'))
                ->keyBy('id');

            $needsDenda = $overdueBills
                ->filter(fn ($bill) => (float) ($bill->penalty_amount ?? 0) > 0
                    && ! $hasJournal($bill, '4.1.01.04'))
                ->keyBy('id');

            // Tagihan yang benar-benar akan menghasilkan SATU BARIS JURNAL
            // atau lebih.
            //
            // PENTING: syaratnya harus PERSIS sama dengan kondisi di loop
            // bawah. Kalau $targets lebih longgar dari syarat di loop itu,
            // command akan melaporkan "berhasil memproses N tagihan" padahal
            // tidak satu baris pun ter-insert — popup menampilkan angka yang
            // tidak pernah terjadi.
            //
            // Catatan soft-delete: jurnal yang pernah soft-deleted TETAP
            // terhitung sudah ada di sini, karena model Transaction memakai
            // global scope SoftDeletes. Jadi tagihan itu tidak dijurnal ulang.
            $targets = $overdueBills->filter(
                fn ($bill) => ((float) ($bill->abodemen ?? 0) > 0
                        && ! $hasJournal($bill, '4.1.01.02'))
                    || ((float) ($bill->usage_charge ?? 0) > 0
                        && ! $hasJournal($bill, '4.1.01.03'))
                    || ((float) ($bill->penalty_amount ?? 0) > 0
                        && ! $hasJournal($bill, '4.1.01.04'))
            );

            $skipped = $overdueBills->count() - $targets->count();

            // Mode --force: hapus jurnal lama lebih dulu supaya tidak dobel.
            if ($force) {
                foreach (array_chunk($overdueBills->pluck('id')->all(), 1000) as $idChunk) {
                    Transaction::where('reverence_type', 'overdue_bill')
                        ->whereIn('reverence_id', $idChunk)
                        ->delete();
                }
            }

            $rows = [];

            foreach ($targets as $bill) {
                $relasi = $bill->customer?->customer_code ?? ('Bill #'.$bill->id);
                $customerName = $bill->customer?->user?->name
                    ?? $bill->customer?->ticket?->applicant_name
                    ?? 'Tanpa Nama';
                $abodemen = (float) ($bill->abodemen ?? 0);
                $usageCharge = (float) ($bill->usage_charge ?? 0);
                $denda = (float) ($bill->penalty_amount ?? 0);

                $keteranganSuffix = 'bulan '.$this->periodLabelLong($bill).
                    ' an. '.$customerName.' ('.$relasi.')';

                // Cek PER TIPE, bukan hanya per tagihan: jurnal abodemen yang sudah
                // ada tidak boleh mencegah jurnal pemakaiannya dibuat, dan
                // sebaliknya. Command ini dijalankan ulang di setiap login.
                $adaJurnalBaru = false;

                if ($abodemen > 0 && $needsAbodemen->has($bill->id)) {
                    $rows[] = $this->journalRow(
                        $nextId++,
                        $today,
                        $now,
                        $systemUser->id,
                        $bill,
                        '4.1.01.02',
                        'Piutang Abodemen '.$keteranganSuffix,
                        $relasi,
                        $abodemen
                    );
                    $jurnalAbodemen++;
                    $adaJurnalBaru = true;
                    $affectedAccounts['1.1.03.01'] = true;
                    $affectedAccounts['4.1.01.02'] = true;
                }

                if ($usageCharge > 0 && $needsPemakaian->has($bill->id)) {
                    $rows[] = $this->journalRow(
                        $nextId++,
                        $today,
                        $now,
                        $systemUser->id,
                        $bill,
                        '4.1.01.03',
                        /*
                         * Keterangan ini SEHARUSNYA menyebut pemakaian, bukan
                         * denda.
                         *
                         * `4.1.01.03` adalah akun "Tagihan Pemakaian"
                         * (`AccountsTableSeeder.php:94`), sedangkan "Denda"
                         * adalah `4.1.01.04`. Memakai label "Piutang Denda"
                         * di sini membuat baris buku besar menampilkan
                         * pendapatan denda pada akun pemakaian -- dan
                         * membuat akun Denda yang sebenarnya tidak pernah
                         * dipakai di jalur accrue piutang.
                         *
                         * Perbaikan ini mengubah TEKS SAJA. Kode akun, nilai,
                         * dan seluruh perhitungan tidak tersentuh, sehingga
                         * pembukuan yang sudah terjadi tidak berubah angkanya.
                         */
                        'Piutang Pemakaian '.$keteranganSuffix,
                        $relasi,
                        $usageCharge
                    );
                    $jurnalPemakaian++;
                    $adaJurnalBaru = true;
                    $affectedAccounts['1.1.03.01'] = true;
                    $affectedAccounts['4.1.01.03'] = true;
                }

                /*
                 * Piutang Denda.
                 *
                 * Denda sudah terhitung di `monthly_bills.penalty_amount` sejak
                 * tagihan dibuat, dan tagihan itu sudah menjadi bagian dari
                 * apa yang harus ditagih dari pelanggan. Tanpa dibukukan,
                 * akun `4.1.01.04` (Denda) tetap nol sampai tagihannya
                 * dibayar -- padahal Piutang Usaha sudah membebani seluruh
                 * `total_amount` termasuk dendanya.
                 *
                 * Akibatnya dua laporan tidak cocok: `4.1.01.04` tidak pernah
                 * bergerak, sementara `1.1.03.01` bertambah sebesar total
                 * tagihan. Saat dibayar, dendanya baru diakui dengan tanggal
                 * `paid_at`, yang bisa 2-3 bulan setelah periode tagihan --
                 * pendapatan masuk periode yang salah.
                 *
                 * `MonthlyBillController::pay` memakai pemetaan yang sama
                 * untuk menutup piutang dendanya, sehingga kedua sisi
                 * balance.
                 */
                if ($denda > 0 && $needsDenda->has($bill->id)) {
                    $rows[] = $this->journalRow(
                        $nextId++,
                        $today,
                        $now,
                        $systemUser->id,
                        $bill,
                        '4.1.01.04',
                        'Piutang Denda '.$keteranganSuffix,
                        $relasi,
                        $denda
                    );
                    $jurnalDenda++;
                    $adaJurnalBaru = true;
                    $affectedAccounts['1.1.03.01'] = true;
                    $affectedAccounts['4.1.01.04'] = true;
                }

                // Hanya tagihan yang benar-benar menghasilkan jurnal yang
                // dihitung "diproses". Menghitung semua anggota `$targets`
                // membuat angka popup lebih besar dari kenyataan — dan
                // kalau semua barisnya nol, popup melaporkan ribuan tagihan
                // diproses padahal tabel tidak bertambah sama sekali.
                if ($adaJurnalBaru) {
                    $processed++;
                }
            }

            // Insert per chunk supaya satu statement tidak melebihi
            // max_allowed_packet untuk puluhan ribu baris.
            foreach (array_chunk($rows, $chunkSize) as $chunk) {
                DB::table('transactions')->insert($chunk);
            }

            DB::commit();

            // Trigger harus hidup kembali SEBELUM `amount` dihitung ulang, supaya
            // tabel `amount` tidak pernah tertinggal kalau proses ini mati tepat
            // di tengah jalan.
            $suspend->restore();

            // Gantikan pekerjaan trigger: hitung `amount` satu kali per akun.
            $this->recomputeAmount(array_keys($affectedAccounts), $today);

            $this->info("Berhasil memproses {$processed} tagihan menunggak (skip {$skipped}).");
            $this->storeRunSummary(
                $today,
                $thresholdDate,
                $processed,
                $skipped,
                $jurnalAbodemen,
                $jurnalPemakaian,
                $jurnalDenda
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            // Wajib: kalau trigger tertinggal mati, tabel `amount` berhenti
            // ter-update untuk seluruh aplikasi.
            $suspend->restore();

            $this->error('Gagal: '.$e->getMessage());
            $this->storeRunSummary($today, $thresholdDate, 0, $overdueBills->count(), 0, 0, 0);

            return self::FAILURE;
        }
    }

    /**
     * Satu baris jurnal piutang dengan `id` dan `urutan` terisi sejak awal.
     */
    private function journalRow(
        int $id,
        string $today,
        $now,
        int $userId,
        $bill,
        string $kredit,
        string $keterangan,
        string $relasi,
        float $saldo
    ): array {
        return [
            'id' => $id,
            'tgl_transaksi' => $today,
            'account_debet' => '1.1.03.01',
            'account_kredit' => $kredit,
            'transaction_group' => null,
            'reverence_type' => 'overdue_bill',
            'reverence_id' => $bill->id,
            'keterangan_transaksi' => $keterangan,
            'relasi' => $relasi,
            'saldo' => $saldo,
            'id_user' => $userId,
            'urutan' => $id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Hitung ulang tabel `amount` untuk akun-akun terdampak.
     *
     * Menggantikan trigger `create_amount_debit` yang sebelumnya melakukan hal
     * yang sama untuk setiap baris. Dipanggil SETELAH semua insert selesai,
     * jadi cukup satu kali per akun.
     *
     * PERIODE: 1 Januari sampai AKHIR BULAN yang sama.
     *
     * Ini PERSIS rentang yang dipakai trigger MySQL `create_amount_debit`:
     *
     *     tgl_transaksi BETWEEN CONCAT(YEAR(tgl_transaksi), "-01-01")
     *                      AND LAST_DAY(tgl_transaksi)
     *
     * Jadi nilai yang ditulis command ini sama persis dengan nilai yang
     * akan ditulis trigger kalau jurnal itu di-insert satu per satu. Itu
     * penting: kalau angkanya beda, `amount` akan "berganti bentuk" setiap
     * kali ada insert biasa berikutnya -- laporan melompat-lompat untuk
     * alasan yang tidak ada hubungannya dengan transaksi yang baru masuk.
     *
     * Versi lama command ini memakai `BETWEEN 1 Januari DAN 31 Desember`,
     * yaitu satu baris `amount` untuk bulan generate ditulis dengan JUMLAH
     * SELURUH TAHUN. Kalau generate berjalan tanggal 28 Oktober, baris
     * `amount` bulan Oktober berisi saldo gabungan Oktober..Desember.
     * Laporan Laba Rugi Oktober jadi lebih besar dari seharusnya, sementara
     * November dan Desember kehilangan saldonya -- dua sumber kebenaran
     * berbeda untuk angka yang sama.
     *
     * Efeknya semakin buruk setiap kali generate dijalankan ulang di akhir
     * bulan: `amount` bulan itu ditulis ulang dengan angka yang sama
     * berulang kali, sementara jurnal barunya ikut masuk kehitungannya.
     *
     * CATATAN TENTANG KONSISTENSI SELURUH APLIKASI
     * --------------------------------------------
     *
     * Tabel `amount` di aplikasi ini TIDAK seragam definisinya:
     *
     *   - Trigger MySQL, `TransactionObserver::updateAmountForAccount()`, dan
     *     `PelaporanController::recalcBulan()` memakai YTD-sampai-akhir-bulan
     *     (1 Januari .. akhir bulan).
     *   - `AmountRecalculator::compute()` memakai per bulan (GROUP BY tahun+bulan).
     *
     * Command ini sengaja mengikuti yang PERTAMA, karena itu yang otoritatif
     * di database dan yang dipakai oleh insert jurnal biasa. Perbedaan dengan
     * `AmountRecalculator` adalah ketidakkonsistenan lama di seluruh
     * aplikasi, bukan pilihan command ini -- dan menormalkan satu sisi saja
     * akan mengubah angka pelaporan yang sudah berjalan.
     */
    private function recomputeAmount(array $kodeAkuns, string $tanggal): void
    {
        if (empty($kodeAkuns)) {
            return;
        }

        $tahun = substr($tanggal, 0, 4);
        $bulan = substr($tanggal, 5, 2);
        $startOfYear = $tahun.'-01-01';
        // Batas periode = AKHIR BULAN yang sama, persis seperti trigger.
        $endOfPeriod = Carbon::create((int) $tahun, (int) $bulan, 1)->endOfMonth()->toDateString();

        foreach (array_unique($kodeAkuns) as $kode) {
            $account = DB::table('accounts')->where('kode_akun', $kode)->first();

            if (! $account) {
                continue;
            }

            $row = DB::table('transactions')
                ->selectRaw('COALESCE(SUM(CASE WHEN account_debet = ? THEN saldo ELSE 0 END), 0) AS debit', [$kode])
                ->selectRaw('COALESCE(SUM(CASE WHEN account_kredit = ? THEN saldo ELSE 0 END), 0) AS kredit', [$kode])
                ->whereNull('deleted_at')
                ->whereBetween('tgl_transaksi', [$startOfYear, $endOfPeriod])
                ->where(function ($q) use ($kode) {
                    $q->where('account_debet', $kode)
                        ->orWhere('account_kredit', $kode);
                })
                ->first();

            DB::table('amount')->updateOrInsert(
                ['id' => (string) $account->id.$tahun.$bulan],
                [
                    'account_id' => $account->id,
                    'tahun' => $tahun,
                    'bulan' => $bulan,
                    'debit' => $row->debit ?? 0,
                    'kredit' => $row->kredit ?? 0,
                ]
            );
        }
    }

    /**
     * Label singkat: "Sep 2026"
     */
    private function periodLabel($bill)
    {
        $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Ags', 'Sep', 'Okt', 'Nov', 'Des'];

        return ($months[$bill->billing_period_month] ?? '').' '.$bill->billing_period_year;
    }

    /**
     * Label panjang: "September 2026" (dipakai di keterangan_transaksi).
     */
    private function periodLabelLong($bill)
    {
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        return ($months[(int) $bill->billing_period_month] ?? '').' '.$bill->billing_period_year;
    }

    /**
     * Simpan angka hasil run terakhir agar frontend bisa menampilkan
     * ringkasan yang AKURAT.
     *
     * Kenapa perlu: `DashboardController` sebelumnya menghitung ringkasan
     * dengan query `transactions WHERE tgl_transaksi = today`. Itu salah,
     * karena saat command dijalankan ulang dengan `--force=false`, semua
     * tagihan sudah punya jurnal sehingga TIDAK ada jurnal baru bertanggal
     * hari ini — hasilnya selalu 0 padahal command melaporkan ribuan tagihan
     * diproses. Angka di bawah berasal langsung dari loop yang sama, jadi
     * selalu sesuai dengan apa yang benar-benar terjadi.
     *
     * TTL 26 jam: cukup untuk menutup seluruh jendela "hari generate".
     */
    private function storeRunSummary(
        string $today,
        string $thresholdDate,
        int $processed,
        int $skipped,
        int $jurnalAbodemen,
        int $jurnalPemakaian,
        int $jurnalDenda
    ): void {
        $totalUnpaid = (int) MonthlyBill::where('status', 'unpaid')->count();
        $totalOverdue = (int) MonthlyBill::where('status', 'unpaid')
            ->where('due_date', '<', $today)
            ->count();
        /*
         * Cache dashboard lokasi ini sudah basi karena piutang baru saja
         * ditambah. Tanpa ini, statistik "tunggakan" di dashboard tetap
         * menampilkan angka dari sebelum generate sampai TTL 60 detik habis.
         */
        $cache = app(TenantCache::class);
        $cache->bump();

        // Ringkasan ditulis pada kunci FLAG (tanpa versi), jadi tidak ikut
        // tergeser oleh bump di atas. Pembacanya ada di
        // ReceivableGenerator::execute() dan DashboardController.
        $cache->flagPut('overdue_gen_run_summary', [
            'date' => $today,
            'threshold_date' => $thresholdDate,
            'processed' => $processed,
            'skipped' => $skipped,
            'jurnal_abodemen' => $jurnalAbodemen,
            'jurnal_pemakaian' => $jurnalPemakaian,
            'jurnal_denda' => $jurnalDenda,
            'total_unpaid' => $totalUnpaid,
            'total_overdue' => $totalOverdue,
            'timestamp' => now()->toISOString(),
        ], now()->addHours(26));
    }
}
