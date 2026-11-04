<?php

namespace App\Services;

use App\Models\MonthlyBill;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Arsip Piutang = daftar piutang pelanggan yang belum tertagih.
 *
 * SUMBER KEBENARAN: JURNAL, BUKAN TAGIHAN
 * ----------------------------------------
 *
 * Versi lama endpoint ini memakai `penalty_amount > 0` sebagai proxy
 * "sudah dibukukan sebagai piutang". Proxy itu sudah ditolak sendiri di
 * `MonthlyBillController::pay`:
 *
 *   "Jurnal overdue_bill adalah satu-satunya fakta yang bisa dipercaya:
 *    kalau baris ini ada, tagihan ini sudah membebani akun 1.1.03.01 dan
 *    pembayarannya wajib menutup piutang, bukan menambah pendapatan."
 *
 * Dua sebab proxy itu salah:
 *
 *   1. Tagihan menunggak yang dendanya 0 (cuma abodemen) disembunyikan,
 *      padahal piutangnya sudah tercatat di akun 1.1.03.01.
 *   2. Tagihan yang dendanya > 0 tapi belum pernah dijurnal ikut muncul,
 *      padahal belum membebani piutang sama sekali.
 *
 * Jadi di sini yang menentukan "piutang tercatat" adalah keberadaan jurnal
 * `reverence_type = 'overdue_bill'` dengan debit ke akun Piutang Usaha.
 *
 * ALUR BULANAN YANG DIPATUHI
 * --------------------------
 *
 *   - Tagihan periode M (mis. Mei) dibuat saat meteran Mei dicatat, dan
 *     jatuh temponya ada di bulan Mei.
 *   - Jurnal piutang tagihan itu dibuat pada TANGGAL GENERATE -- yaitu
 *     generate pertama yang ambangnya sudah melewati jatuh tempo tagihan itu.
 *     Karena generate hanya jalan satu hari dalam bulan, tagihan periode Mei
 *     umumnya baru menjadi piutang pada generate bulan Juni.
 *   - Sejak tanggal generate itu, piutang tagihan Mei terlihat sampai
 *     generate berikutnya datang (atau sampai tagihannya dibayar).
 *
 * Karena itu filter bulan di arsip memakai **bulan pencatatan piutang**
 * (tanggal jurnal), bukan bulan periode tagihan. Pada bulan Juni, arsip
 * menampilkan piutang bertagihan Mei yang tercatat pada generate Juni.
 * Kolom "Periode Tagihan" tetap menampilkan periode aslinya (Mei) supaya
 * tidak menyesatkan, dan kolom "Tercatat" menampilkan tanggal generate
 * yang dipakai.
 *
 * Tagihan menunggak yang BELUM punya jurnal tidak masuk bulan mana pun --
 * ia belum masuk periode piutang manapun. Baris seperti itu tetap
 * ditampilkan dengan status "Menunggu Generate", karena diam-diam
 * disembunyikan akan membuat orang mengira tidak ada tunggakan.
 */
class ArsipPiutangService
{
    /**
     * Akun Piutang Usaha. Hanya debit ke akun ini yang berarti "piutang
     * pelanggan yang harus ditagih".
     */
    public const PIUTANG_USAHA = '1.1.03.01';

    /**
     * Relasi yang dimuat untuk setiap baris.
     *
     * Dipisah dari `baseQuery()` supaya query agregat TIDAK ikut memuatnya:
     * eager loading pada query agregat tidak ada gunanya (barisnya satu
     * angka, bukan daftar tagihan) dan menambah query sia-sia.
     */
    private const RELASI = [
        'customer:id,user_id,ticket_id,customer_code',
        'customer.user:id,name',
        'customer.ticket:id,applicant_name,address',
    ];

    /**
     * Query dasar: tagihan yang belum lunas + agregasi jurnal piutangnya.
     *
     * Jurnal dijoin sebagai subquery TERPISAH, bukan relasi Eloquent:
     * `monthly_bills.transactions()` adalah `morphMany` dengan tipe
     * `monthly_bill`, sedangkan jurnal piutang bertipe `overdue_bill` --
     * sehingga relasi itu tidak pernah menemukan jurnal piutang sama sekali.
     *
     * Jurnal yang sudah di-soft-delete TIDAK ikut dihitung. Soft delete
     * berarti barisnya tidak lagi menyumbang saldo ke akun piutang
     * (`GenerateOverdueTransactions::recomputeAmount()` juga memfilter
     * `deleted_at IS NULL`), jadi menghitungnya di sini akan menampilkan
     * nominal piutang yang tidak ada di pembukuan.
     */
    public function baseQuery(): Builder
    {
        /*
         * Subquery-nya memakai `DB::table`, bukan model `Transaction`.
         *
         * Dua alasannya:
         *
         * 1. `DB::table` mengabaikan global scope, jadi filter soft-delete
         *    harus ditulis eksplisit -- dan ditulisnya di sini, satu
         *    tempat, sehingga tidak bisa terlewat.
         * 2. Subquery di dalam JOIN tidak boleh punya binding yang berurutan
         *    salah; `DB::table` menghasilkan SQL polos yang paling mudah
         *    dibaca saat query ini error.
         */
        $jurnal = DB::table('transactions')
            ->selectRaw(
                'reverence_id,'
                .' COALESCE(SUM(saldo), 0) AS piutang_tercatat,'
                .' MIN(tgl_transaksi) AS tanggal_pencatatan'
            )
            ->where('reverence_type', 'overdue_bill')
            ->where('account_debet', self::PIUTANG_USAHA)
            // Jurnal yang sudah di-soft-delete TIDAK menyumbang saldo ke
            // akun piutang -- `GenerateOverdueTransactions::recomputeAmount()`
            // juga memfilter `deleted_at IS NULL`. Menghitungnya di sini
            // akan menampilkan nominal piutang yang tidak ada di pembukuan.
            ->whereNull('deleted_at')
            ->groupBy('reverence_id');

        return MonthlyBill::query()
            ->leftJoinSub($jurnal, 'jt', 'jt.reverence_id', '=', 'monthly_bills.id')
            ->select([
                'monthly_bills.*',
                'jt.piutang_tercatat',
                'jt.tanggal_pencatatan',
            ]);
    }

    /**
     * Query dengan filter bulan pencatatan + pencarian.
     *
     * `$bulan`/`$tahun` null berarti SEMUA bulan / SEMUA tahun.
     *
     * Keduanya berdiri sendiri: bulan tanpa tahun berarti "bulan itu di
     * tahun mana pun", tahun tanpa bulan berarti "seluruh tahun itu".
     * Form di halaman Arsip mengizinkan keduanya dipilih sebagian --
     * "Semua Bulan" + tahun 2026 -- jadi keduanya harus difilter
     * terpisah. Kalau keduanya digabung jadi satu syarat, memilih
     * "Semua Bulan" sebenarnya berarti "abaikan tahun juga", dan tabelnya
     * menampilkan periode yang tidak sedang dipilih.
     */
    public function filteredQuery(?int $bulan, ?int $tahun, string $search = ''): Builder
    {
        $query = $this->baseQuery()
            ->where('monthly_bills.status', 'unpaid')
            // "Menunggak" = sudah lewat jatuh tempo. Tagihan yang jatuh tempo
            // hari ini belum tunggak; baru besok.
            ->where('monthly_bills.due_date', '<', now()->toDateString());

        if ($bulan !== null && $tahun !== null) {
            $start = Carbon::create($tahun, $bulan, 1)->startOfMonth()->toDateString();
            $end = Carbon::create($tahun, $bulan, 1)->endOfMonth()->toDateString();

            // Dua kelompok masuk filter bulan ini:
            //   - yang sudah punya jurnal di bulan ini (piutang tercatat), dan
            //   - yang belum punya jurnal sama sekali (menunggu generate) --
            //     ini belum masuk periode piutang mana pun, jadi tidak boleh
            //     ikut hilang bersama filter.
            $query->where(function ($w) use ($start, $end) {
                $w->whereBetween('jt.tanggal_pencatatan', [$start, $end])
                    ->orWhereNull('jt.reverence_id');
            });
        } elseif ($bulan !== null) {
            // Bulan saja, tahun bebas.
            $query->where(function ($w) use ($bulan) {
                $w->whereRaw('MONTH(jt.tanggal_pencatatan) = ?', [$bulan])
                    ->orWhereNull('jt.reverence_id');
            });
        } elseif ($tahun !== null) {
            // Tahun saja, bulan bebas.
            $query->where(function ($w) use ($tahun) {
                $w->whereRaw('YEAR(jt.tanggal_pencatatan) = ?', [$tahun])
                    ->orWhereNull('jt.reverence_id');
            });
        }

        if ($search !== '') {
            // Pencarian MENGANDUNG kata kunci, bukan hanya diawali.
            //
            // `like 'keyword%'` hanya menemukan baris yang dimulai dengan
            // keyword itu -- "arif" tidak menemukan "muhammad arif", padahal
            // itulah yang dicari orang.
            $term = '%'.mb_strtolower($search).'%';

            $query->where(function ($w) use ($term) {
                $w->whereHas('customer.user', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$term]))
                    ->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(customer_code) LIKE ?', [$term]))
                    ->orWhereHas('customer.ticket', fn ($t) => $t->whereRaw('LOWER(applicant_name) LIKE ?', [$term]));
            });
        }

        return $query;
    }

    /**
     * Satu halaman baris arsip.
     *
     * @return array<int, array<string, mixed>>
     */
    public function items(?int $bulan, ?int $tahun, string $search, int $page, int $perPage): array
    {
        $rows = $this->filteredQuery($bulan, $tahun, $search)
            ->with(self::RELASI)
            ->orderBy('monthly_bills.due_date', 'asc')
            ->orderBy('monthly_bills.id', 'asc')
            ->forPage($page, $perPage)
            ->get();

        return $rows->map(fn (MonthlyBill $b) => $this->toRow($b))->values()->all();
    }

    /**
     * Jumlah baris yang cocok dengan filter (untuk paginasi).
     */
    public function countFiltered(?int $bulan, ?int $tahun, string $search = ''): int
    {
        return (int) $this->filteredQuery($bulan, $tahun, $search)->count();
    }

    /**
     * Ringkasan untuk kartu ringkasan di halaman Arsip Piutang.
     *
     * Mengembalikan dua kelompok angka:
     *   - `periode`: angka untuk periode yang sedang difilter.
     *   - `keseluruhan`: angka tunggakan yang SEDANG BERJALAN tanpa filter
     *     bulan, jadi admin tetap tahu total piutang yang harus ditagih
     *     walau sedang memilih satu bulan tertentu.
     */
    public function ringkasan(?int $bulan, ?int $tahun, string $search = ''): array
    {
        $periode = $this->agregat($this->filteredQuery($bulan, $tahun, $search));
        $keseluruhan = $this->agregat(
            $this->baseQuery()
                ->where('monthly_bills.status', 'unpaid')
                ->where('monthly_bills.due_date', '<', now()->toDateString())
        );

        return [
            'periode' => $periode,
            'keseluruhan' => $keseluruhan,
            'generate_terakhir' => $this->generateTerakhir(),
            'generate_berikutnya' => $this->generateBerikutnya(),
        ];
    }

    /**
     * Satu query agregat untuk sekumpulan baris.
     *
     * `select([])` DIWajib ada di sini.
     *
     * Query asal sudah punya select `monthly_bills.*, jt.piutang_tercatat,
     * jt.tanggal_pencatatan`, dan `selectRaw()` itu MENAMBAH, bukan
     * menggantikan. Kalau select lama dibiarkan ikut, SQL-nya jadi
     * `SELECT monthly_bills.*, COUNT(*) ...` tanpa GROUP BY -- dan MySQL
     * dengan `ONLY_FULL_GROUP_BY` (default sejak 5.7) menolak query itu
     * dengan "Expression #1 of SELECT list is not in GROUP BY clause".
     *
     * Mengosongkan select dulu membuat SQL-nya murni agregat, dan
     * `cloneWithout`+`count()` di `countFiltered()` sudah melakukan hal
     * yang sama tanpa select sama sekali.
     */
    private function agregat(Builder $query): array
    {
        $row = (clone $query)
            ->select([])
            ->selectRaw(
                'COUNT(*) AS jml_tagihan,'
                .' COUNT(DISTINCT monthly_bills.customer_id) AS jml_pelanggan,'
                .' COALESCE(SUM(monthly_bills.total_amount), 0) AS total_tagihan,'
                .' COALESCE(SUM(monthly_bills.penalty_amount), 0) AS total_denda,'
                .' COALESCE(SUM(jt.piutang_tercatat), 0) AS total_piutang,'
                .' COALESCE(SUM(CASE WHEN jt.reverence_id IS NULL THEN 1 ELSE 0 END), 0) AS jml_menunggu,'
                .' COALESCE(SUM(CASE WHEN jt.reverence_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS jml_tercatat'
            )
            ->first();

        return [
            'jml_tagihan' => (int) ($row->jml_tagihan ?? 0),
            'jml_pelanggan' => (int) ($row->jml_pelanggan ?? 0),
            'total_tagihan' => (float) ($row->total_tagihan ?? 0),
            'total_denda' => (float) ($row->total_denda ?? 0),
            'total_piutang' => (float) ($row->total_piutang ?? 0),
            'jml_menunggu' => (int) ($row->jml_menunggu ?? 0),
            'jml_tercatat' => (int) ($row->jml_tercatat ?? 0),
        ];
    }

    /**
     * Ubah satu tagihan menjadi baris tabel.
     *
     * @return array<string, mixed>
     */
    private function toRow(MonthlyBill $b): array
    {
        $total = (float) $b->total_amount;
        $denda = (float) $b->penalty_amount;

        $tanggalPencatatan = $b->tanggal_pencatatan
            ? Carbon::parse($b->tanggal_pencatatan)->toDateString()
            : null;

        $tercatat = $tanggalPencatatan !== null;

        return [
            'id' => $b->id,
            'nomorInduk' => $b->customer?->customer_code ?: '-',
            'customer' => $b->customer?->ticket?->applicant_name
                ?? $b->customer?->user?->name ?? '-',
            'alamat' => $b->customer?->ticket?->address ?: '-',
            // Periode TAGIHAN (bukan periode pencatatan piutang).
            'periodeLabel' => $b->periodLabel() ?: '-',
            'periodeBulan' => (int) $b->billing_period_month,
            'periodeTahun' => (int) $b->billing_period_year,
            'jatuhTempo' => $b->due_date?->toDateString(),
            /*
             * Berapa hari sudah lewat jatuh tempo.
             *
             * Arah pemanggilannya PENTING: Carbon 3 membuat `diffInDays`
             * BERSIGN -- hasilnya negatif kalau tanggal kedua ada di masa
             * lalu. `now()->diffInDays($jatuhTempo)` untuk jatuh tempo
             * minggu lalu menghasilkan -7, bukan 7.
             *
             * Kalau ini dibalik, `max(0, ...)` mengubah semua nilai menjadi
             * nol dan kolom "Telat N hari" selalu kosong -- bukan karena
             * tidak ada tunggakan, tapi karena tandanya terbalik.
             *
             * Jadi yang memanggil adalah TANGGAL JATUH TEMPO: `jatuhTempo-
             * diffInDays(now)` bernilai positif untuk tanggal yang sudah
             * lewat.
             */
            'hariMenunggak' => $b->due_date
                ? max(0, (int) $b->due_date->copy()->startOfDay()->diffInDays(now()->startOfDay()))
                : 0,
            'tagihan' => max(0, $total - $denda),
            'denda' => $denda,
            'total' => $total,
            'piutangTercatat' => (float) ($b->piutang_tercatat ?? 0),
            'tanggalPencatatan' => $tanggalPencatatan,
            'status' => $tercatat ? 'Tercatat' : 'Menunggu Generate',
            'statusKey' => $tercatat ? 'tercatat' : 'menunggu',
        ];
    }

    /**
     * Tanggal generate piutang yang terakhir berjalan.
     *
     * Diambil dari jurnal piutang terakhir -- bukan dari tanggal hari ini,
     * karena generate HANYA jalan pada tanggal yang diset di SOP.
     */
    public function generateTerakhir(): ?string
    {
        $tgl = Transaction::where('reverence_type', 'overdue_bill')
            ->max('tgl_transaksi');

        return $tgl ? Carbon::parse($tgl)->toDateString() : null;
    }

    /**
     * Tanggal generate piutang yang akan datang.
     *
     * NULL kalau tanggal generate belum diatur di SOP (nilai di luar
     * rentang 1-31) -- dalam keadaan itu generate tidak pernah jalan, dan
     * menampilkan tanggal menipu akan lebih buruk daripada tidak
     * menampilkan apa pun.
     */
    public function generateBerikutnya(): ?string
    {
        /*
         * Angka yang dipakai adalah `generateDay()` -- angka MENTAH yang
         * diset admin -- bukan `effectiveGenerateDay()`.
         *
         * Alasannya: `effectiveGenerateDay()` menarik angka ke hari terakhir
         * BULAN SEKARANG (31 -> 28 di Februari). Untuk "generate berikutnya
         * di bulan depan" itu salah: kalau hari ini 28 Februari dan
         * generate-nya tanggal 31, `effectiveGenerateDay()` mengembalikan 28
         * (28 Februari), dan tanggal yang ditampilkan menjadi "28 Maret" --
         * padahal generate berikutnya jatuh pada 31 Maret.
         *
         * Jadi penjepitan ke hari terakhir bulan tujuan dilakukan di sini,
         * per bulan yang dihitung.
         */
        $day = app(BillingTolerance::class)->generateDay();

        if ($day < 1) {
            return null;
        }

        $now = now();

        if ($now->day < $day) {
            /*
             * Masih bulan ini. Tanggal 31 di bulan dengan 30 hari
             * (November) dijepit ke 30, dan 31 di Februari ke 28/29.
             */
            $hari = min($day, $now->daysInMonth);

            return $now->copy()->day($hari)->toDateString();
        }

        /*
         * Sudah lewat -- generate berikutnya ada di bulan depan.
         *
         * Tanggalnya dijepit ke HARI TERAKHIR bulan tujuan: tanggal 31 di
         * bulan ini berarti tanggal 28 (atau 29) di Februari. Tanpa
         * penjepitan ini, `day(31)` pada tanggal Februari akan meluber ke
         * Maret -- dan tanggal generate yang ditampilkan jadi bukan tanggal
         * generate yang sebenarnya.
         */
        $bulanDepan = $now->copy()->addMonthNoOverflow()->startOfMonth();
        $hari = min($day, $bulanDepan->daysInMonth);

        return $bulanDepan->day($hari)->toDateString();
    }
}
