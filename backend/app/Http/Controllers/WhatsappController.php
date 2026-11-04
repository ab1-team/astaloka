<?php

namespace App\Http\Controllers;

use App\Models\MonthlyBill;
use App\Services\WhatsappMessageService;
use Illuminate\Http\Request;

/**
 * Menyusun pesan WhatsApp dari template yang diset admin di SOP.
 *
 * ENDPOINT INI TIDAK MENGIRIMKAN PESAN
 * ------------------------------------
 * Yang dia lakukan hanya menyusun teksnya dari template `settings.pesan_tagihan`
 * (atau `pesan_pembayaran`) dan mengembalikan tautan `wa.me` yang pesannya
 * sudah terisi. Admin tinggal menekan tombol kirim di WhatsApp.
 *
 * Kenapa tidak ada gateway di repo ini: SOP -> "Whatsapp API" punya tombol
 * "Scan Whatsapp" yang QR-nya masih mock, dan belum ada service WhatsApp
 * Cloud API yang tersambung. Jadi jalur yang bisa dipakai HARI INI tetap
 * `wa.me` -- tapi isinya sekarang diambil dari template admin, bukan
 * ditulis mati di frontend.
 *
 * TEMPLATE SEBAGAI SUMBER KEBENARAN
 * --------------------------------
 * Composable ini dipakai dua tempat:
 *
 *   - Halaman "Daftar Tagihan"  : satu per satu, tombol kirim langsung di
 *     baris tabel (tanpa harus buka modal rincian).
 *   - Popup "TAGIHAN" di dashboard : beberapa sekaligus, pakai centang.
 *
 * Keduanya memakai service yang sama, jadi isi pesannya dijamin sama.
 */
class WhatsappController extends Controller
{
    public function __construct(
        protected WhatsappMessageService $whatsapp,
    ) {}

    /**
     * GET /whatsapp/template
     * Template yang sedang aktif, untuk pratinjau di halaman SOP.
     */
    public function template()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'templateTagihan' => $this->whatsapp->template(WhatsappMessageService::TIPE_TAGIHAN),
                'templatePembayaran' => $this->whatsapp->template(WhatsappMessageService::TIPE_PEMBAYARAN),
            ],
        ]);
    }

    /**
     * POST /whatsapp/messages
     *
     * Body: { bill_ids: [1,2,3], tipe: "tagihan" | "pembayaran" }
     *
     * Mengembalikan satu pesan per tagihan: teks yang sudah jadi + tautan
     * wa.me-nya. Tagihan tanpa nomor WhatsApp tetap dikembalikan, tapi
     * `wa_url`-nya kosong dan `error`-nya berisi alasannya -- supaya
     * frontend bisa membedakan "gagal total" dari "sebagian gagal".
     */
    public function messages(Request $request)
    {
        $data = $request->validate([
            'bill_ids' => 'required|array|min:1|max:500',
            'bill_ids.*' => 'required|integer|min:1',
            'tipe' => 'nullable|string|in:tagihan,pembayaran',
        ]);

        $tipe = $data['tipe'] ?? WhatsappMessageService::TIPE_TAGIHAN;
        $ids = array_values(array_unique(array_map('intval', $data['bill_ids'])));

        /*
         * Least-privilege: teknisi hanya boleh menyusun pesan untuk tagihan
         * pelanggan yang ia tangani -- sama seperti filter di `index()` dan
         * `show()`. Tanpa ini, teknisi bisa memanggil endpoint ini dengan
         * id tagihan milik teknisi lain secara manual, dan nama + nomor
         * pelanggan itu ikut terbawa keluar.
         */
        $query = MonthlyBill::query()->with([
            'customer.user:id,name',
            'customer.ticket:id,applicant_name,phone,village_id',
            'customer.ticket.village:id,village_name,hamlet_name',
        ])->whereIn('id', $ids);

        if ($request->user()?->role === 'teknisi') {
            $query->whereHas('customer.ticket', function ($t) use ($request) {
                $t->where('user_id', $request->user()->id);
            });
        }

        $bills = $query->get();

        $pesan = [];
        foreach ($bills as $bill) {
            $row = $this->whatsapp->forBill($bill, $tipe);

            if ($row['phone_normalized'] === '') {
                $row['error'] = 'Nomor WhatsApp pelanggan tidak tersedia.';
                $row['wa_url'] = '';
            }

            $pesan[] = $row;
        }

        /*
         * Tagihan yang TIDAK ditemukan ikut dikembalikan sebagai baris
         * bermasalah, supaya frontend bisa menampilkan ringkasan "3 dari 5
         * terkirim" alih-alih diam-diam mengirim 2 tanpa penjelasan.
         */
        $ditemukan = $bills->pluck('id')->map(fn ($id) => (int) $id)->all();
        foreach (array_diff($ids, $ditemukan) as $id) {
            $pesan[] = [
                'bill_id' => (int) $id,
                'customer' => '-',
                'desa' => '-',
                'phone' => '',
                'phone_normalized' => '',
                'message' => '',
                'wa_url' => '',
                'status' => '',
                'error' => 'Tagihan tidak ditemukan atau tidak boleh diakses.',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'template' => $this->whatsapp->template($tipe),
                'tipe' => $tipe,
                'messages' => $pesan,
                'total' => count($pesan),
                'siap_kirim' => count(array_filter($pesan, fn ($p) => $p['wa_url'] !== '')),
            ],
        ]);
    }
}
