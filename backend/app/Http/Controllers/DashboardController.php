<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\InstallationTicket;
use App\Models\MonthlyBill;
use App\Models\Transaction;
use App\Services\ArsipPiutangService;
use App\Services\BillingTolerance;
use App\Services\ReceivableGenerator;
use App\Services\TenantCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    /**
     * TTL cache untuk statistik dashboard.
     * - Stat umum: 60 detik (data berubah saat bayar / tiket baru)
     * - Finance chart & available_years: 5 menit (lebih stabil)
     */
    private const STATS_CACHE_TTL = 60;   // detik

    private const FINANCE_CACHE_TTL = 300;  // detik (5 menit)

    private const YEARS_CACHE_TTL = 3600; // 1 jam

    public function __construct(private TenantCache $cache) {}

    public function statistics(Request $request)
    {
        $now = now();
        $year = (int) $request->query('year', $now->year);
        $month = (int) $request->query('month', $now->month);
        $todayYmd = $now->toDateString();

        // ─── CACHE 1: stat-global (independent of year/month) ───
        //
        // Kunci lewat `TenantCache`: tanpa prefiks lokasi, lokasi B membaca
        // statistik lokasi A dari cache yang sama.
        $statsGlobal = $this->cache->remember(
            'dashboard:stats:global:'.$todayYmd,
            self::STATS_CACHE_TTL,
            function () {
                return [
                    'total_customers' => Customer::count(),
                    'tickets_by_status' => InstallationTicket::selectRaw('status, count(*) as total')
                        ->groupBy('status')
                        ->pluck('total', 'status')
                        ->all(),
                    'bills_unpaid' => MonthlyBill::where('status', 'unpaid')->count(),
                    'pemakaian_count' => InstallationTicket::where('status', 'completed')->count(),
                    /*
                     * Jumlah piutang tunggakan yang tercatat.
                     *
                     * Dihitung lewat `ArsipPiutangService` supaya angka di kotak
                     * dashboard sama persis dengan isi tabel Arsip Piutang.
                     *
                     * Sebelumnya dipakai `penalty_amount > 0` -- proxy yang
                     * menyesatkan: tagihan menunggak tanpa denda ikut hilang,
                     * padahal piutangnya sudah membebani akun 1.1.03.01.
                     */
                    'tunggakan_total' => app(ArsipPiutangService::class)->countFiltered(null, null),
                    'latest_tickets' => InstallationTicket::with('package:id,name')
                        ->orderBy('created_at', 'desc')
                        ->limit(5)
                        ->get(['id', 'applicant_name', 'status', 'package_id', 'created_at']),
                    'overdue_bills' => MonthlyBill::with([
                        'customer:id,user_id,customer_code',
                        'customer.user:id,name',
                    ])
                        ->where('status', 'unpaid')
                        ->where('due_date', '<=', now()->toDateString())
                        ->orderBy('due_date')
                        ->limit(5)
                        ->get([
                            'id', 'customer_id', 'billing_period_year', 'billing_period_month',
                            'total_amount', 'penalty_amount', 'due_date', 'status',
                        ]),
                ];
            }
        );

        // ─── CACHE 2: finance data (per year+month) ───
        $financeData = $this->cache->remember(
            "dashboard:finance:{$year}:{$month}",
            self::FINANCE_CACHE_TTL,
            function () use ($year, $month) {
                // Pakai range tanggal supaya index tgl_transaksi optimal
                $start = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
                $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

                // Aggregate pendapatan + beban dalam 1 query (bukan 2)
                $monthAgg = Transaction::selectRaw(
                    "COALESCE(SUM(CASE WHEN account_kredit LIKE '4.%' THEN saldo ELSE 0 END), 0) AS pendapatan,"
                    ." COALESCE(SUM(CASE WHEN account_debet  LIKE '5.%' THEN saldo ELSE 0 END), 0) AS beban"
                )
                    ->whereBetween('tgl_transaksi', [$start, $end])
                    ->first();

                $pendapatan = (float) ($monthAgg->pendapatan ?? 0);
                $beban = (float) ($monthAgg->beban ?? 0);

                // Chart per bulan dalam 1 tahun
                $yearStart = Carbon::create($year, 1, 1)->startOfYear()->toDateString();
                $yearEnd = Carbon::create($year, 12, 31)->endOfYear()->toDateString();

                $rowsPendapatan = Transaction::selectRaw(
                    'MONTH(tgl_transaksi) AS m,'
                    .' COALESCE(SUM(saldo), 0) AS total'
                )
                    ->whereBetween('tgl_transaksi', [$yearStart, $yearEnd])
                    ->where('account_kredit', 'like', '4.%')
                    ->groupBy(DB::raw('MONTH(tgl_transaksi)'))
                    ->pluck('total', 'm');

                $rowsBeban = Transaction::selectRaw(
                    'MONTH(tgl_transaksi) AS m,'
                    .' COALESCE(SUM(saldo), 0) AS total'
                )
                    ->whereBetween('tgl_transaksi', [$yearStart, $yearEnd])
                    ->where('account_debet', 'like', '5.%')
                    ->groupBy(DB::raw('MONTH(tgl_transaksi)'))
                    ->pluck('total', 'm');

                $chart = [];
                for ($m = 1; $m <= 12; $m++) {
                    $p = (float) ($rowsPendapatan[$m] ?? 0);
                    $b = (float) ($rowsBeban[$m] ?? 0);
                    $chart[] = [
                        'year' => $year,
                        'month' => $m,
                        'pendapatan' => $p,
                        'beban' => $b,
                        'surplus' => $p - $b,
                    ];
                }

                return [
                    'finance' => [
                        'pendapatan' => $pendapatan,
                        'beban' => $beban,
                        'surplus' => $pendapatan - $beban,
                        'year' => $year,
                        'month' => $month,
                    ],
                    'finance_chart' => $chart,
                ];
            }
        );

        // ─── CACHE 3: available_years (jarang berubah) ───
        $availableYears = $this->cache->remember(
            'dashboard:available_years',
            self::YEARS_CACHE_TTL,
            function () {
                return Transaction::selectRaw('DISTINCT YEAR(tgl_transaksi) as y')
                    ->whereNotNull('tgl_transaksi')
                    ->orderBy('y')
                    ->pluck('y')
                    ->map(fn ($y) => (int) $y)
                    ->values()
                    ->all();
            }
        );

        // Revenue bulan ini (ringan, index ada)
        $revenueThisMonth = MonthlyBill::where('status', 'paid')
            ->where('billing_period_year', $year)
            ->where('billing_period_month', $month)
            ->sum('total_amount');

        // Kompatibilitas dengan frontend lama: bills_this_month.unpaid
        $billsThisMonth = ['unpaid' => $statsGlobal['bills_unpaid']];

        return response()->json([
            'success' => true,
            'data' => array_merge($statsGlobal, [
                'revenue_this_month' => $revenueThisMonth,
                'bills_this_month' => $billsThisMonth,
                'finance' => $financeData['finance'],
                'finance_chart' => $financeData['finance_chart'],
                'available_years' => $availableYears,
            ]),
        ]);
    }

    /**
     * Endpoint RINGAN khusus untuk popup 4 kotak dashboard.
     * Filter & paginasi dilakukan di SERVER sehingga frontend tidak perlu
     * menarik semua data dan filter client-side (yang menyebabkan loop
     * getAllBills() sebelumnya menarik ratusan halaman).
     *
     * Query params:
     *   - type     : instalasi | tunggakan | tagihan | pemakaian (wajib)
     *   - search   : string pencarian (opsional)
     *   - page     : halaman (default 1)
     *   - per_page : rows per page (default 10, max 100)
     *
     * Response shape seragam: { success, data: [...], meta: { ... } }
     */
    public function popupData(Request $request)
    {
        $type = (string) $request->query('type', '');
        // Batas max 100 WAJIB sinkron dengan opsi "Tampilkan" di DataTable
        // (10/25/50/100). Kalau batas server lebih kecil dari pilihan UI,
        // frontend menghitung offset halaman dari `per_page` pilihan user
        // sementara server memakai nilai yang dipangkas -- sehingga halaman 2
        // menampilkan baris yang salah, dan label "Showing X to Y" jadi bohong.
        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
        $page = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));

        switch ($type) {
            case 'instalasi':
                return $this->popupInstalasi($perPage, $page, $search);
            case 'tunggakan':
                return $this->popupTunggakan($perPage, $page, $search);
            case 'tagihan':
                return $this->popupTagihan($perPage, $page, $search);
            case 'pemakaian':
                return $this->popupPemakaian($request, $perPage, $page, $search);
            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Tipe popup tidak dikenali. Gunakan: instalasi | tunggakan | tagihan | pemakaian.',
                ], 422);
        }
    }

    /**
     * Arsip Instalasi: tiket dengan status draft / pending / surveyed / unpaid.
     * Hanya SELECT field minimum + filter server-side.
     */
    private function popupInstalasi(int $perPage, int $page, string $search)
    {
        $query = InstallationTicket::query()
            ->whereIn('status', ['draft', 'pending', 'surveyed', 'unpaid'])
            ->orderBy('created_at', 'desc');

        if ($search !== '') {
            $query->where(function ($w) use ($search) {
                $w->where('applicant_name', 'like', $search.'%')
                    ->orWhere('nik', 'like', $search.'%');
            });
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($page, $perPage)
            ->get(['id', 'applicant_name', 'address', 'status', 'created_at']);

        $items = $rows->map(fn ($t) => [
            'id' => $t->id,
            'nomorInduk' => 'INS-'.str_pad((string) $t->id, 5, '0', STR_PAD_LEFT),
            'customer' => $t->applicant_name ?: '-',
            'alamat' => $t->address ?: '-',
            'tanggalOrder' => $t->created_at ? $t->created_at->toDateString() : '-',
            'status' => $t->status,
        ])->values();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'last_page' => (int) max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Arsip Piutang: piutang pelanggan yang belum tertagih.
     *
     * DITERUSKAN ke `ArsipPiutangService`, bukan dihitung sendiri.
     *
     * Alasannya: popup dashboard dan halaman Arsip Piutang (/app/arsip/
     * tunggakan) menampilkan HAL YANG SAMA, dan versi lama menghitungnya
     * sendiri dengan `penalty_amount > 0` -- proxy yang sudah ditolak di
     * `MonthlyBillController::pay` ("jurnal overdue_bill adalah satu-satunya
     * fakta yang bisa dipercaya"). Kalau popup dan arsip memakai definisi
     * berbeda, angka di kotak dashboard tidak akan sama dengan isi tabelnya,
     * dan tidak ada yang bisa menjelaskan bedanya.
     */
    private function popupTunggakan(int $perPage, int $page, string $search)
    {
        $arsip = app(ArsipPiutangService::class);

        $total = $arsip->countFiltered(null, null, $search);
        $items = $arsip->items(null, null, $search, $page, $perPage);

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'last_page' => (int) max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Arsip Tagihan: SEMUA tagihan unpaid (tanpa filter denda).
     */
    private function popupTagihan(int $perPage, int $page, string $search)
    {
        $query = MonthlyBill::query()
            ->with([
                'customer:id,user_id,ticket_id,customer_code',
                'customer.user:id,name',
                'customer.ticket:id,applicant_name,address',
            ])
            ->where('status', 'unpaid')
            ->orderBy('due_date', 'asc');

        if ($search !== '') {
            $query->where(function ($w) use ($search) {
                $w->whereHas('customer.user', fn ($u) => $u->where('name', 'like', $search.'%'))
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_code', 'like', $search.'%'))
                    ->orWhereHas('customer.ticket', fn ($t) => $t->where('applicant_name', 'like', $search.'%'));
            });
        }

        $total = (clone $query)->count();
        $rows = $query->forPage($page, $perPage)->get();

        $items = $rows->map(fn ($b) => [
            'id' => $b->id,
            'nomorInduk' => $b->customer?->customer_code ?: '-',
            'customer' => $b->customer?->ticket?->applicant_name
                ?? $b->customer?->user?->name ?? '-',
            'alamat' => $b->customer?->ticket?->address ?: '-',
            'periode' => $b->billing_period_month,
            'tahun' => $b->billing_period_year,
            'periodeLabel' => $b->billing_period_month
                ? sprintf('%02d/%d', $b->billing_period_month, $b->billing_period_year)
                : '-',
            'total' => (float) $b->total_amount,
            'denda' => (float) $b->penalty_amount,
            'jatuhTempo' => $b->due_date?->toDateString(),
            'status' => $b->status,
        ])->values();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'last_page' => (int) max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Arsip Pemakaian: pemakaian air bulan ini.
     * Anti-N+1: ambil semua customer + tagihan sekaligus.
     */
    private function popupPemakaian(Request $request, int $perPage, int $page, string $search)
    {
        $now = now();
        $month = (int) $request->query('month', $now->month);
        $year = (int) $request->query('year', $now->year);

        $customers = Customer::with(['user:id,name', 'ticket:id,applicant_name,address'])
            ->whereHas('ticket', fn ($q) => $q->whereIn('status', [
                'surveyed', 'unpaid', 'processing', 'completed', 'suspended',
            ]))
            ->get(['id', 'user_id', 'ticket_id', 'customer_code']);

        $bills = MonthlyBill::whereIn('customer_id', $customers->pluck('id'))
            ->where('billing_period_year', $year)
            ->where('billing_period_month', $month)
            ->get()
            ->keyBy('customer_id');

        $items = $customers->map(function ($c) use ($bills, $month, $year) {
            $bill = $bills->get($c->id);

            return [
                'id' => $c->id,
                'nomorInduk' => $c->customer_code ?: '-',
                'customer' => $c->user?->name ?? $c->ticket?->applicant_name ?? '-',
                'alamat' => $c->ticket?->address ?: '-',
                'periodeLabel' => $bill
                    ? sprintf('%02d/%d', $bill->billing_period_month, $bill->billing_period_year)
                    : sprintf('%02d/%d', $month, $year),
                'meter_awal' => $bill?->meter_reading_start,
                'meter_akhir' => $bill?->meter_reading_end,
                'pemakaian' => $bill?->usage_m3,
                'pemakaian_charge' => $bill?->usage_charge,
                'abodemen' => $bill?->abodemen,
                'denda' => $bill?->penalty_amount ?? 0,
                'total' => $bill?->total_amount ?? 0,
                'status' => $bill?->status ?? 'pending',
            ];
        })->values();

        if ($search !== '') {
            $q = mb_strtolower($search);
            $items = $items->filter(fn ($i) => str_contains(mb_strtolower($i['customer']), $q)
                || str_contains(mb_strtolower($i['nomorInduk']), $q))->values();
        }

        $total = $items->count();
        $rows = $items->forPage($page, $perPage)->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page' => (int) max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Pra-cek apakah hari ini adalah tanggal generate piutang.
     *
     * Endpoint ringan yang dipanggil frontend SEBELUM popup loading dibuka,
     * supaya modal tidak berkedip di hari biasa. Sengaja TIDAK bergantung
     * pada cache "sudah pernah jalan": frontend memakai `will_run` hanya
     * untuk memutuskan membuka popup, dan itu harus tetap true di setiap
     * login pada tanggal tersebut. Deduplikasi ada di level command.
     */
    public function checkAutoGenerateOverdue(Request $request)
    {
        $today = now();
        $todayYmd = $today->toDateString();
        $todayDay = (int) $today->format('d');

        /*
         * Tanggal generate dibaca lewat `BillingTolerance`, bukan langsung
         * dari `Setting`.
         *
         * `generateDay()` mengubah nilai di luar rentang 1-31 menjadi 0 --
         * artinya "SOP belum diisi, tidak ada yang dijadwalkan". Tanpa itu,
         * nilai 40 akan lolos sebagai tanggal generate yang sah.
         *
         * `effectiveGenerateDay()` menarik angka yang diset ke HARI TERAKHIR
         * bulan kalau angka itu tidak ada di bulan berjalan. Jadi tanggal 29
         * di Oktober = 29, di Februari = 28.
         *
         * Sumbernya tetap SATU: `BillingTolerance` adalah tempat semua
         * arti `toleransi_tunggakan` dikunci.
         */
        $tolerance = app(BillingTolerance::class);
        $scheduledDay = $tolerance->generateDay();
        $effectiveDay = $tolerance->effectiveGenerateDay($today);

        $configured = $scheduledDay >= 1;

        // Gate `===`: HANYA pada tanggal yang di-set.
        //
        // `toleransi_tunggakan` = 28 -> generate jalan pada tanggal 28,
        // dan TIDAK pada tanggal 29, 30, maupun 31.
        //
        // Ini yang diminta: "generate hanya 1 hari di tgl yang di-set,
        // bukan hari ini -- tgl itu, tapi hanya tgl itu".
        //
        // Konsekuensinya yang disengaja: kalau tidak ada staff yang login
        // tepat pada tanggal itu, generate bulan itu TIDAK PERNAH jalan
        // -- tanpa error, tanpa log. Penutupnya adalah scheduler harian
        // di `routes/console.php`, yang memanggil `runIfDue()` untuk semua
        // lokasi setiap malam.
        $isScheduledDay = $configured && $todayDay === $effectiveDay;

        return response()->json([
            'success' => true,
            'configured' => $configured,
            'is_scheduled' => $isScheduledDay,
            // Dipertahankan supaya frontend lama yang membaca field ini
            // tidak rusak, tapi nilainya sudah tidak dipakai sebagai penentu.
            'already_ran' => false,
            'will_run' => $isScheduledDay,
            'scheduled_day' => $scheduledDay,
            'effective_day' => $effectiveDay,
            'today_day' => $todayDay,
            'date' => $todayYmd,
        ]);
    }

    /**
     * Auto-generate piutang/abodemen/denda untuk tagihan menunggak.
     *
     * Dipanggil dari frontend setiap kali halaman dashboard di-mount, dan
     * juga otomatis dari `POST /api/login` lewat `ReceivableGenerator`.
     * - Hanya jalan bila hari ini PERSIS sama dengan tanggal generate
     *   (`toleransi_tunggakan`, range 1-31). Gate `===`.
     * - Menjalankan command `billing:generate-overdue-transactions`
     *   dengan `--tanggal=today` dan `--force=false`.
     * - Hasil dikembalikan ke frontend untuk ditampilkan di pop up.
     *
     * Boleh dijalankan BERULANG. Dulu ada flag `auto_gen_overdue_{YYYY-MM}`
     * (TTL 35 hari) yang mengunci generate untuk seluruh bulan setelah
     * jalannya yang pertama -- sehingga pelanggan yang lupa somehow, tagihan
     * yang baru masuk belakangan, atau tagihan yang tadinya salah lalu
     * diperbaiki, tidak pernah bisa tertangani tanpa intervensi manual.
     *
     * Flag itu dihapus. Yang mencegah jurnal dobel adalah command itu
     * sendiri yang sudah idempotent: tagihan yang sudah punya jurnal
     * piutang untuk akun kredit tertentu akan dilewati.
     */
    public function autoGenerateOverdue(Request $request)
    {
        // Route-nya memang dibuka untuk admin & teknisi (teknisi butuh
        // endpoint ini untuk popup yang sama), TAPI aksi-nya khusus admin.
        // Generate piutang menulis ke tabel `transactions` secara global
        // dan ringkasannya disimpan di cache ber-key tanggal — bukan per
        // user. Kalau teknisi boleh menjalankannya, teknisi pertama yang
        // login pada tanggal tersebut akan "memiliki" eksekusi hari itu.
        if (auth()->user()?->role !== 'admin') {
            abort(403, 'Hanya admin yang dapat menjalankan generate piutang.');
        }

        $userId = auth()->id();
        $today = now();
        $todayYmd = $today->toDateString();

        // Sama seperti di `checkAutoGenerateOverdue()`: satu sumber angka.
        $tolerance = app(BillingTolerance::class);
        $scheduledDay = $tolerance->generateDay();
        $effectiveDay = $tolerance->effectiveGenerateDay($today);

        // 1) Kalau SOP belum di-set / 0 -> tidak ada generate otomatis.
        if ($scheduledDay < 1) {
            return response()->json([
                'success' => true,
                'ran' => false,
                'reason' => 'Toleransi menunggak belum dikonfigurasi.',
                'scheduled_day' => $scheduledDay,
                'effective_day' => $effectiveDay,
                'today_day' => (int) $today->format('d'),
                'date' => $todayYmd,
            ]);
        }

        // 2) Gate `===`: HANYA pada tanggal yang di-set.
        //
        //    `toleransi_tunggakan` = 20 -> generate jalan pada tanggal 20,
        //    dan TIDAK pada tanggal 19, 21, atau 22.
        //
        //    Ini yang diminta: "generate hanya 1 hari di tgl yang di-set,
        //    bukan hari ini -- tgl itu, tapi hanya tgl itu".
        //
        //    Tanggal 29/30/31 ditarik ke hari terakhir bulan: 29 di
        //    Oktober, 28 di Februari.
        //
        //    Konsekuensi yang disengaja: bulan tanpa staff yang login tepat
        //    pada tanggal itu TIDAK punya jurnal piutang. Penutupnya
        //    adalah scheduler harian di `routes/console.php`.
        $todayDay = (int) $today->format('d');
        if ($todayDay !== $effectiveDay) {
            return response()->json([
                'success' => true,
                'ran' => false,
                'reason' => 'Hari ini bukan tanggal generate piutang bulan ini.',
                'scheduled_day' => $scheduledDay,
                'effective_day' => $effectiveDay,
                'today_day' => $todayDay,
                'date' => $todayYmd,
            ]);
        }

        /*
         * 3) Generate TIDAK lagi dikunci per bulan.
         *
         *    Versi lama memakai flag `auto_gen_overdue_{YYYY-MM}` (TTL 35
         *    hari) plus gate tanggal. Efeknya: begitu generate jalan sekali
         *    pada tanggal 6, SELURUH bulan berikutnya di lokasi itu tidak
         *    pernah bisa generate lagi.
         *
         *    Itu persis skenario yang harus dicegah: ada pelanggan yang
         *    lupa somehow, ada tagihan yang baru masuk belakangan, atau ada
         *    tagihan yang tadinya salah lalu diperbaiki admin. Semua itu
         *    butuh generate ULANG, bukan generate yang terkunci.
         *
         *    Yang PEGANG duplikatnya adalah command itu sendiri, yang sudah
         *    idempotent: setiap tagihan punya paling banyak satu jurnal
         *    piutang per akun kredit (dicek lewat pasangan
         *    `reverence_id|account_kredit`). Menjalankan berkali-kali pada
         *    hari yang sama tidak menghasilkan jurnal dobel.
         *
         *    Yang tersisa di sini hanya BUSY-GUARD: mencegah dua proses
         *    agregasi tabel `amount` berjalan bersamaan (lock wait timeout),
         *    BUKAN mencegah generate.
         */
        $lockKey = app(ReceivableGenerator::class)->lockKey($today);

        if (! Cache::add($lockKey, 1, now()->addMinutes(10))) {
            return response()->json([
                'success' => true,
                'ran' => false,
                'busy' => true,
                'reason' => 'Generate sedang berjalan di proses lain. Coba lagi sebentar lagi.',
                'scheduled_day' => $scheduledDay,
                'today_day' => $todayDay,
                'date' => $todayYmd,
            ]);
        }

        // 4) Eksekusi command (sama seperti endpoint /piutang/generate).
        $start = microtime(true);
        $opts = ['--tanggal' => $todayYmd];

        try {
            $exit = Artisan::call('billing:generate-overdue-transactions', $opts);
            $output = Artisan::output();
        } catch (\Throwable $e) {
            // Lepas kunci supaya percobaan berikutnya tidak terkunci hanya
            // karena satu kegagalan.
            Cache::forget($lockKey);

            Log::error('autoGenerateOverdue gagal: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'ran' => false,
                'message' => 'Gagal menjalankan generate: '.$e->getMessage(),
                'scheduled_day' => $scheduledDay,
                'today_day' => $todayDay,
                'date' => $todayYmd,
            ], 500);
        } finally {
            // Lepas kunci juga setelah sukses: generate berikutnya
            // (login berikutnya, atau klik ulang tombol) harus BOLEH jalan.
            Cache::forget($lockKey);
        }

        $duration = (int) ((microtime(true) - $start) * 1000);

        // Reset flag dismiss notifikasi supaya pop up baru bisa muncul.
        $this->cache->flagForget('overdue_gen_dismissed_'.$userId);

        // Statistik dashboard sudah basi: generate ini menambah piutang,
        // sehingga angka tunggakan dan pendapatan berubah.
        $this->cache->bump();

        // Susun payload untuk pop up frontend.
        //
        // Ringkasan diambil dari command yang baru saja jalan, BUKAN dari
        // query ulang "jurnal bertanggal hari ini".
        //
        // Query lama selalu mengembalikan 0 pada run kedua: semua tagihan
        // sudah punya jurnal, jadi TIDAK ada jurnal baru bertanggal hari
        // ini. Padahal command-nya memproses ribuan tagihan. Popup pun
        // menampilkan "diproses 0" -- angka yang tidak pernah terjadi.
        //
        // `ReceivableGenerator::runSummary()` membaca ringkasan yang
        // ditulis command dari loop pemrosesannya, jadi angkanya selalu
        // sesuai dengan apa yang benar-benar terjadi.
        $ringkasan = app(ReceivableGenerator::class)->runSummary($today);

        $processed = (int) ($ringkasan['jurnal_abodemen'] ?? 0);
        $processedUsage = (int) ($ringkasan['jurnal_pemakaian'] ?? 0);
        $processedDenda = (int) ($ringkasan['jurnal_denda'] ?? 0);

        $totalUnpaid = (int) ($ringkasan['total_unpaid']
            ?? MonthlyBill::where('status', 'unpaid')->count());
        $totalOverdue = (int) ($ringkasan['total_overdue']
            ?? MonthlyBill::where('status', 'unpaid')->where('due_date', '<', $todayYmd)->count());

        $payload = [
            'success' => true,
            'ran' => true,
            'date' => $todayYmd,
            'scheduled_day' => $scheduledDay,
            'today_day' => $todayDay,
            'duration_ms' => $duration,
            'exit_code' => $exit,
            'output' => trim($output),
            'summary' => [
                'tagihan_dengan_abodemen_tungakan' => $processed,
                'tagihan_dengan_pemakaian_tungakan' => $processedUsage,
                'tagihan_dengan_denda_tungakan' => $processedDenda,
                'total_unpaid' => $totalUnpaid,
                'total_overdue' => $totalOverdue,
            ],
        ];

        return response()->json($payload);
    }
}
