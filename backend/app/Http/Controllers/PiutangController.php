<?php

namespace App\Http\Controllers;

use App\Services\ArsipPiutangService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;

class PiutangController extends Controller
{
    public function __construct(private ArsipPiutangService $arsip) {}

    public function generate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tanggal' => 'nullable|date_format:Y-m-d',
            'force' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $start = microtime(true);
        $tanggal = $request->input('tanggal', date('Y-m-d'));
        $force = $request->boolean('force');

        $opts = ['--tanggal' => $tanggal];
        if ($force) {
            $opts['--force'] = true;
        }

        $exit = Artisan::call('billing:generate-overdue-transactions', $opts);
        $output = Artisan::output();

        $duration = (int) ((microtime(true) - $start) * 1000);

        return response()->json([
            'success' => $exit === 0,
            'tanggal' => $tanggal,
            'force' => $force,
            'duration_ms' => $duration,
            'output' => trim($output),
        ]);
    }

    /**
     * Arsip Piutang: daftar piutang pelanggan yang belum tertagih.
     *
     * Query params:
     *   - month     : bulan pencatatan piutang (1-12). Kosong = semua bulan.
     *   - year      : tahun pencatatan piutang. Kosong = semua tahun.
     *   - bulan     : alias dari `month` (dipakai form Indonesian).
     *   - tahun     : alias dari `year`.
     *   - search    : kata kunci (mengandung, bukan diawali).
     *   - page      : halaman (default 1).
     *   - per_page  : baris per halaman (default 10, max 100).
     *
     * BULAN DI SINI BULAN PENCATATAN PIUTANG, bukan bulan periode tagihan.
     * Lihat `ArsipPiutangService` untuk penjelasan alurnya.
     *
     * Response:
     *   {
     *     success: true,
     *     data: [ ...baris... ],
     *     meta: { current_page, last_page, per_page, total, ...ringkasan }
     *   }
     */
    public function arsip(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 10), 100));
        $page = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));

        $bulan = $this->bulanDari($request);
        $tahun = $this->tahunDari($request);

        $total = $this->arsip->countFiltered($bulan, $tahun, $search);
        $items = $this->arsip->items($bulan, $tahun, $search, $page, $perPage);
        $ringkasan = $this->arsip->ringkasan($bulan, $tahun, $search);

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => array_merge([
                'current_page' => $page,
                'last_page' => (int) max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
                'bulan' => $bulan,
                'tahun' => $tahun,
            ], $ringkasan),
        ]);
    }

    /**
     * Bulan yang difilter, atau null kalau semua bulan.
     *
     * Menerima dua nama param (`month` dan `bulan`) supaya form yang
     * memakai bahasa Indonesia dan yang memakai nama API bisa memakai
     * endpoint yang sama.
     */
    private function bulanDari(Request $request): ?int
    {
        $raw = $request->query('month', $request->query('bulan'));

        if ($raw === null || $raw === '') {
            return null;
        }

        $bulan = (int) $raw;

        return ($bulan >= 1 && $bulan <= 12) ? $bulan : null;
    }

    /**
     * Tahun yang difilter, atau null kalau semua tahun.
     */
    private function tahunDari(Request $request): ?int
    {
        $raw = $request->query('year', $request->query('tahun'));

        if ($raw === null || $raw === '') {
            return null;
        }

        $tahun = (int) $raw;

        return ($tahun >= 2000 && $tahun <= 2100) ? $tahun : null;
    }
}
