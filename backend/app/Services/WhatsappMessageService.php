<?php

namespace App\Services;

use App\Models\MonthlyBill;
use App\Models\Setting;

/**
 * Menyusun pesan WhatsApp dari template yang diset admin di halaman SOP.
 *
 * TEMPLATE DISIMPAN DI `settings.pesan_tagihan` / `settings.pesan_pembayaran`
 * -----------------------------------------------------------------------
 * Dua kolom itu diisi admin lewat SOP -> Whatsapp API (`SopController::updateWhatsapp`).
 *
 * SEBELUMNYA template itu HANYA DISIMPAN, TIDAK PERNAH DIPAKAI
 * ----------------------------------------------------------
 * Halaman "Daftar Tagihan" mengirim pesan WA dengan teks yang ditulis
 * langsung di frontend (`detaiDaftarTagihan.vue`), jadi placeholder-nya
 * diabaikan dan admin yang sudah menyetel template di SOP tetap melihat
 * pesan lama. Service ini adalah tempat satu-satunya yang membaca template
 * dan mengganti placeholder-nya -- jadi tempat yang sama untuk 1 tagihan
 * maupun kirim banyak sekaligus.
 *
 * PLACEHOLDER YANG DIDUKUNG
 * --------------------------
 *   {customer}       nama pelanggan (user.name, atau applicant_name)
 *   {desa}           nama desa pelanggan
 *   {jumlah_tagihan} nominal tagihan, format "Rp. 12.500"
 *   {tagihan}        sama dengan {jumlah_tagihan} (dipakai template pembayaran)
 *   {periode}        "Agustus 2026"
 *   {jatuh_tempo}    "10 September 2026"
 *
 * Placeholder yang tidak dikenal dibiarkan apa adanya -- jadi salah ketik
 * di SOP terlihat di pesan, bukan hilang diam-diam.
 */
class WhatsappMessageService
{
    public const TIPE_TAGIHAN = 'tagihan';

    public const TIPE_PEMBAYARAN = 'pembayaran';

    /** Bawaan kalau kolom template belum pernah diisi. */
    public const DEFAULT_TAGIHAN =
        'Yth. {customer}, tagihan Anda bulan ini sebesar {jumlah_tagihan}. '.
        'Mohon segera melakukan pembayaran sebelum jatuh tempo. Terima kasih.';

    public const DEFAULT_PEMBAYARAN =
        'Yth. {customer}, pembayaran tagihan sebesar {tagihan} telah kami terima. Terima kasih.';

    /**
     * Ambil template mentah dari settings.
     */
    public function template(string $tipe = self::TIPE_TAGIHAN): string
    {
        $s = Setting::first();

        $raw = $tipe === self::TIPE_PEMBAYARAN
            ? ($s?->pesan_pembayaran ?? '')
            : ($s?->pesan_tagihan ?? '');

        $raw = trim((string) $raw);

        if ($raw === '') {
            $raw = $tipe === self::TIPE_PEMBAYARAN
                ? self::DEFAULT_PEMBAYARAN
                : self::DEFAULT_TAGIHAN;
        }

        return $raw;
    }

    /**
     * Ganti placeholder pada template dengan data tagihan.
     */
    public function render(string $template, MonthlyBill $bill): string
    {
        $data = $this->dataTagihan($bill);

        $teks = $template;
        foreach ($data as $key => $value) {
            $teks = str_replace('{'.$key.'}', (string) $value, $teks);
        }

        return $this->rapikanTeks($teks);
    }

    /**
     * Susun satu pesan lengkap untuk satu tagihan.
     *
     * @return array{bill_id:int, customer:string, desa:string, phone:string, phone_normalized:string, message:string, wa_url:string, status:string}
     */
    public function forBill(MonthlyBill $bill, string $tipe = self::TIPE_TAGIHAN): array
    {
        $data = $this->dataTagihan($bill);
        $message = $this->render($this->template($tipe), $bill);
        $phone = $data['phone'];
        $normalized = $this->normalizeNomor($phone);

        return [
            'bill_id' => (int) $bill->id,
            'customer' => $data['customer'],
            'desa' => $data['desa'],
            'phone' => $phone,
            'phone_normalized' => $normalized,
            'message' => $message,
            'wa_url' => $normalized === '' ? '' : $this->waUrl($normalized, $message),
            'status' => (string) $bill->status,
        ];
    }

    /**
     * Nilai untuk setiap placeholder.
     *
     * @return array<string, string>
     */
    private function dataTagihan(MonthlyBill $bill): array
    {
        $customer = $bill->customer;
        $ticket = $customer?->ticket;
        $user = $customer?->user;

        $nama = $user?->name ?: ($ticket?->applicant_name ?: 'Pelanggan');
        $desa = $ticket?->village?->village_name
            ?: ($ticket?->village?->hamlet_name ?: '-');

        $nominal = 'Rp. '.number_format((float) ($bill->total_amount ?? 0), 0, ',', '.');

        $bulan = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];

        $periode = trim(($bulan[(int) $bill->billing_period_month] ?? '-').' '.(int) $bill->billing_period_year);

        return [
            'customer' => $nama,
            'desa' => $desa,
            'jumlah_tagihan' => $nominal,
            'tagihan' => $nominal,
            'periode' => $periode,
            'jatuh_tempo' => $bill->due_date ? $bill->due_date->format('d F Y') : '-',
            'phone' => (string) ($ticket?->phone ?? ''),
        ];
    }

    /**
     * Nomor Indonesia -> format internasional tanpa "+".
     *
     *   0812-3456-7890 -> 6281234567890
     *   +62 812-3456…  -> 6281234567890
     */
    public function normalizeNomor(?string $phone): string
    {
        $d = preg_replace('/\D/', '', (string) $phone) ?? '';

        if ($d === '') {
            return '';
        }

        if (str_starts_with($d, '62')) {
            return $d;
        }

        if (str_starts_with($d, '0')) {
            return '62'.substr($d, 1);
        }

        if (str_starts_with($d, '8')) {
            return '62'.$d;
        }

        return $d;
    }

    /**
     * Tautan wa.me yang isi pesannya sudah ter-encode.
     */
    public function waUrl(string $nomorNormalized, string $message): string
    {
        return 'https://wa.me/'.$nomorNormalized.'?text='.rawurlencode($message);
    }

    /**
     * Rapikan hasil penggantian placeholder.
     *
     * Baris kosong berlebih dirapatkan, dan spasi di ujung tiap baris
     * dibuang -- template yang diketik admin sering punya baris kosong di
     * antara paragraf yang tidak disengaja.
     */
    private function rapikanTeks(string $teks): string
    {
        $baris = array_map('rtrim', explode("\n", $teks));

        // buang baris kosong yang berturut-turut lebih dari satu
        $hasil = [];
        $sebelumnyaKosong = false;
        foreach ($baris as $barisSatu) {
            $kosong = trim($barisSatu) === '';
            if ($kosong && $sebelumnyaKosong) {
                continue;
            }
            $sebelumnyaKosong = $kosong;
            $hasil[] = $barisSatu;
        }

        return trim(implode("\n", $hasil));
    }
}
