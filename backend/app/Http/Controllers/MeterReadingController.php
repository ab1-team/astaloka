<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesMeterReadings;
use App\Http\Controllers\Concerns\GeneratesSafeUploadNames;
use App\Models\Customer;
use App\Models\MeterReading;
use App\Models\MonthlyBill;
use App\Models\Setting;
use App\Services\BillingService;
use App\Services\BillingTolerance;
use App\Services\UnpaidMonths;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MeterReadingController extends Controller
{
    use AuthorizesMeterReadings;
    use GeneratesSafeUploadNames;

    /**
     * Ambil data meteran yang SUDAH di-input berdasarkan filter Bulan dan Tahun
     */
    public function completed(Request $request)
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2000',
        ], [
            'month.required' => 'Filter bulan wajib diisi.',
            'month.between' => 'Bulan harus bernilai antara 1 sampai 12.',
            'year.required' => 'Filter tahun wajib diisi.',
        ]);

        $query = MeterReading::with([
            'customer.user',
            'customer.ticket',
        ])
            ->where('reading_month', $request->month)
            ->where('reading_year', $request->year);

        // Least-privilege: teknisi melihat catatan meter yang ia catat sendiri
        // ATAU catatan meter pelanggan di wilayahnya (tiket yang ia tangani).
        // Sama seperti filter di `index()` — supaya daftar "sudah dicatat"
        // konsisten dengan daftar "menunggu dicatat".
        if (! $this->isPrivileged($request)) {
            $userId = $request->user()?->id;

            $query->where(function ($q) use ($userId) {
                $q->where('recorded_by', $userId)
                    ->orWhereHas('customer.ticket', function ($t) use ($userId) {
                        $t->where('user_id', $userId);
                    });
            });
        }

        $readings = $query->latest()->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar pencatatan meter.',
            'data' => $readings,
        ]);
    }

    public function index(Request $request)
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2000',
        ]);

        $bulan = $request->month;
        $tahun = $request->year;

        $prevMonth = $bulan === 1 ? 12 : $bulan - 1;
        $prevYear = $bulan === 1 ? $tahun - 1 : $tahun;

        $customers = Customer::with([
            'user',
            'ticket.village',
            'meterReadings' => function ($query) use ($prevMonth, $prevYear) {
                $query->where('reading_month', $prevMonth)
                    ->where('reading_year', $prevYear);
            },
            'monthlyBills' => function ($query) use ($prevMonth, $prevYear) {
                $query->where('billing_period_month', $prevMonth)
                    ->where('billing_period_year', $prevYear);
            },
        ])
            // Hanya pelanggan dengan tiket aktif yang bisa ditagih. Tanpa
            // filter ini pelanggan terminated/blokir ikut muncul di daftar
            // pencatatan meter. (MonthlyBillController::usage() sudah pakai
            // daftar status yang sama -- disamakan di sini.)
            ->whereHas('ticket', function ($query) {
                $query->whereIn('status', ['surveyed', 'unpaid', 'processing', 'completed', 'suspended']);
            })
            ->whereDoesntHave('meterReadings', function ($query) use ($bulan, $tahun) {
                $query->where('reading_month', $bulan)
                    ->where('reading_year', $tahun);
            });

        // Least-privilege: teknisi hanya ditagih pelanggan di WILAYAHNYA —
        // pelanggan yang tiketnya ia tangani (`installation_tickets.user_id`).
        // Sama seperti `MonthlyBillController` (index/unpaidSummary/show),
        // supaya satu pelanggan tidak terlihat milik teknisi A di halaman
        // pencatatan tapi milik teknisi B di halaman tagihan.
        //
        // Fallback: pelanggan yang pernah ia catat meter-nya (data legacy
        // tanpa `ticket.user_id`) tetap boleh, supaya pencatatan bulan
        // berikutnya tidak terkunci.
        //
        // CATATAN: hasil `whereHas()` HARUS di-assign. Tanpa assignment,
        // Eloquent mengembalikan builder BARU dan constrain-nya dibuang --
        // scoping jadi tidak berlaku sama sekali.
        if (! $this->isPrivileged($request)) {
            $userId = $request->user()?->id;
            $customers->where(function ($query) use ($userId) {
                $query->whereHas('ticket', function ($t) use ($userId) {
                    $t->where('user_id', $userId);
                })->orWhereHas('meterReadings', function ($q) use ($userId) {
                    $q->where('recorded_by', $userId);
                });
            });
        }

        $customers = $customers->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar pelanggan yang belum dicatat meter periode terpilih',
            'total_customers' => $customers->count(),
            'data' => $customers,
        ]);
    }

    /**
     * Simpan data pencatatan meter baru + generate tagihan + cek tunggakan
     */
    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'meter_value' => 'required|integer|min:0|max:99999999',
            'photo' => 'nullable|image|max:2048',
            'reading_month' => 'required|integer|between:1,12',
            'reading_year' => 'required|integer|min:2000',
        ]);

        $bulan = (int) $request->reading_month;
        $tahun = (int) $request->reading_year;

        // Pastikan pelanggan benar-benar punya tiket & paket. BillingService
        // memakai $customer->ticket->package tanpa cek null; tanpa guard di
        // sini permintaan untuk pelanggan tanpa tiket berakhir 500.
        $customer = Customer::with(['ticket.package.waterTariffBlocks'])->findOrFail($request->customer_id);

        if (! $customer->ticket || ! $customer->ticket->package) {
            return response()->json([
                'success' => false,
                'message' => 'Pelanggan ini belum memiliki paket aktif sehingga tidak bisa ditagih.',
            ], 422);
        }

        // Least-privilege: teknisi boleh mencatat untuk pelanggan di
        // WILAYAHNYA — yaitu pelanggan yang tiketnya dia tangani
        // (`installation_tickets.user_id`) — sama seperti admin yang
        // bebas untuk semua pelanggan. store() ikut membuat MonthlyBill dan
        // bisa menyuspend tiket, jadi tanpa cek ini teknisi bisa menagih &
        // mengubah status pelanggan milik teknisi lain.
        //
        // Fallback: kalau tiket tidak punya user_id (data legacy), teknisi
        // tetap boleh untuk pelanggan yang pernah ia catat meter-nya.
        if (! $this->isPrivileged($request)) {
            $isOwnArea = $this->isWithinTechnicianArea($customer, $request);

            $isOwnCustomer = MeterReading::where('customer_id', $customer->id)
                ->where('recorded_by', $request->user()?->id)
                ->exists();

            if (! $isOwnArea && ! $isOwnCustomer) {
                abort(403, 'Akses ditolak. Pelanggan ini bukan dalam wilayah pencatatan Anda.');
            }
        }

        $exists = MeterReading::where('customer_id', $request->customer_id)
            ->where('reading_month', $bulan)
            ->where('reading_year', $tahun)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Meter pelanggan untuk periode bulan dan tahun ini sudah dicatat.',
            ], 400);
        }

        $last = MeterReading::where('customer_id', $request->customer_id)
            ->orderByDesc('reading_year')
            ->orderByDesc('reading_month')
            ->first();

        if ($last && $request->meter_value < $last->meter_value) {
            return response()->json([
                'success' => false,
                'message' => 'Angka meter tidak boleh lebih kecil dari bulan sebelumnya (Catatan terakhir: '.$last->meter_value.' m┬│)',
            ], 400);
        }

        // Foto meteran hanya BUKTI pendukung, bukan data wajib. Teknologi
        // lapangan sering tidak bisa memotret meteran (lampu mati, meter di
        // bawah tanah, pelanggan tidak ada di tempat), jadi kolom
        // `photo_url` nullable dipakai: pencatatan TIDAK boleh ditolak hanya
        // karena foto tidak ada.
        $fileName = $request->hasFile('photo')
            ? $this->storeUploadedImage($request->file('photo'), 'meter-readings')
            : null;

        $settings = Setting::first();
        $batasTagihan = (int) ($settings?->batas_tagihan ?? 27);

        // Waktu pencatatan meter = hari `batas_tagihan` di dalam bulan
        // PEMAKAIAN, bukan bulan tagihannya.
        //
        // `recorded_at` dipakai frontend sebagai "TANGGAL AKHIR" pemakaian
        // (lihat `usePemakaianAir.js`: `tanggalAkhir = reading_recorded_at
        // || due_date`), jadi nilainya harus menunjuk ke batas akhir periode
        // pemakaian -- bukan ke tanggal jatuh temponya yang sudah digeser
        // ke bulan berikutnya.
        //
        // Meter dibaca di sekitar hari `batas_tagihan` bulan berjalan, lalu
        // tagihannya dibayar pada bulan berikutnya.
        $recordedAt = Carbon::create($tahun, $bulan, 1)
            ->setDay(min($batasTagihan, Carbon::create($tahun, $bulan, 1)->daysInMonth))
            ->setTimeFrom(now());

        $result = DB::transaction(function () use ($request, $customer, $bulan, $tahun, $fileName, $recordedAt, $batasTagihan) {
            $reading = MeterReading::create([
                'customer_id' => $customer->id,
                'recorded_by' => Auth::id(),
                'reading_month' => $bulan,
                'reading_year' => $tahun,
                'meter_value' => $request->meter_value,
                'photo_url' => $fileName,
                'recorded_at' => $recordedAt,
            ]);

            $bill = app(BillingService::class)
                ->generateForCustomer($customer, $tahun, $bulan, $batasTagihan);

            return ['reading' => $reading, 'bill' => $bill, 'customer' => $customer];
        });

        $suspended = false;

        /*
         * Batas tunggakan sebelum sambungan diputus.
         *
         * Satuannya JUMLAH BULAN -- bukan tanggal dalam bulan dan bukan
         * jumlah tagihan. Angka ini dibaca dari kolom
         * `settings.billing_unpaid_limit`, yang terpisah dari
         * `toleransi_tunggakan` justru karena satuannya berbeda.
         *
         * Yang dibandingkan adalah JUMLAH BULAN menunggak, bukan jumlah
         * baris tagihan unpaid. Alasannya: yang ditanyakan admin adalah
         * "berapa bulan pelanggan belum bayar". Kalau yang dihitung jumlah
         * tagihan, 4 tagihan dalam 2 bulan (mis. ada tagihan susulan)
         * akan dihitung sama dengan 4 tagihan berjarak 4 bulan.
         *
         * Perhitungan bulan menunggak ada di `UnpaidMonths` -- satu tempat
         * supaya angka ini tidak dihitung berbeda di tempat lain.
         */
        $toleransi = app(BillingTolerance::class)->unpaidBillLimit();

        if ($toleransi > 0) {
            $unpaidMonths = app(UnpaidMonths::class)
                ->forCustomer($result['customer']->id);

            if ($unpaidMonths > $toleransi && $result['customer']->ticket) {
                $result['customer']->ticket->update(['status' => 'suspended']);
                $suspended = true;
            }
        }

        $result['reading']->load(['customer.user', 'customer.ticket']);

        return response()->json([
            'success' => true,
            'message' => $suspended
                ? 'Pencatatan meter & tagihan tersimpan. Pelanggan disuspend karena tunggakan melebihi toleransi.'
                : 'Pencatatan meter & tagihan berhasil disimpan.',
            'data' => [
                'reading' => $result['reading'],
                'bill' => $result['bill'],
                'suspended' => $suspended,
            ],
        ]);
    }

    public function show(Request $request, string $id)
    {
        $reading = MeterReading::with(['customer.user', 'customer.ticket'])->find($id);

        if (! $reading) {
            return response()->json([
                'success' => false,
                'message' => 'Pencatatan meter tidak ditemukan',
            ], 404);
        }

        $this->ensureMeterReadingOwner($request, $reading);

        return response()->json([
            'success' => true,
            'message' => 'Detail pencatatan meter ditemukan',
            'data' => $reading,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $reading = MeterReading::find($id);

        if (! $reading) {
            return response()->json([
                'success' => false,
                'message' => 'Pencatatan meter tidak ditemukan',
            ], 404);
        }

        $this->ensureMeterReadingOwner($request, $reading);

        $request->validate([
            'meter_value' => 'required|integer|min:0|max:99999999',
            'photo' => 'nullable|image|max:2048',
        ]);

        $previous = MeterReading::where('customer_id', $reading->customer_id)
            ->where(function ($query) use ($reading) {
                $query->where('reading_year', '<', $reading->reading_year)
                    ->orWhere(function ($q) use ($reading) {
                        $q->where('reading_year', $reading->reading_year)
                            ->where('reading_month', '<', $reading->reading_month);
                    });
            })
            ->orderByDesc('reading_year')
            ->orderByDesc('reading_month')
            ->first();

        if ($previous && $request->meter_value < $previous->meter_value) {
            return response()->json([
                'success' => false,
                'message' => 'Meter tidak boleh lebih kecil dari bulan sebelumnya ('.$previous->meter_value.' m┬│)',
            ], 400);
        }

        // Rekam tagihan yang sudah dibayar tidak boleh diubah nilainya:
        // bukti fisiknya sudah jadi bagian audit.
        $paidBill = MonthlyBill::where('customer_id', $reading->customer_id)
            ->where('billing_period_month', $reading->reading_month)
            ->where('billing_period_year', $reading->reading_year)
            ->whereIn('status', ['paid', 'processing'])
            ->exists();

        if ($paidBill && (int) $request->meter_value !== (int) $reading->meter_value) {
            return response()->json([
                'success' => false,
                'message' => 'Meter periode ini sudah ditagih dan dibayar sehingga tidak bisa diubah.',
            ], 409);
        }

        $reading->meter_value = $request->meter_value;

        if ($request->hasFile('photo')) {
            if ($reading->photo_url) {
                Storage::disk('public')->delete('meter-readings/'.$reading->photo_url);
            }
            $reading->photo_url = $this->storeUploadedImage($request->file('photo'), 'meter-readings');
        }

        $reading->save();

        return response()->json([
            'success' => true,
            'message' => 'Pencatatan meter berhasil diperbarui',
            'data' => $reading,
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $reading = MeterReading::find($id);

        if (! $reading) {
            return response()->json([
                'success' => false,
                'message' => 'Pencatatan meter tidak ditemukan',
            ], 404);
        }

        $this->ensureMeterReadingOwner($request, $reading);

        // monthly_bills tidak punya FK ke meter_readings, jadi hapus catatan
        // tetap berhasil walau periodenya sudah dibayar. Itu menghilangkan
        // bukti audit -- tolak di sini.
        $hasSettledBill = MonthlyBill::where('customer_id', $reading->customer_id)
            ->where('billing_period_month', $reading->reading_month)
            ->where('billing_period_year', $reading->reading_year)
            ->whereIn('status', ['paid', 'processing'])
            ->exists();

        if ($hasSettledBill) {
            return response()->json([
                'success' => false,
                'message' => 'Catatan meter ini sudah ditagih dan dibayar sehingga tidak bisa dihapus.',
            ], 409);
        }

        return $this->safeDelete(
            function () use ($reading) {
                if ($reading->photo_url) {
                    Storage::disk('public')->delete('meter-readings/'.$reading->photo_url);
                }
                $reading->delete();
            },
            'METER_READING_IN_USE',
            'Pencatatan meter',
        );
    }
}
