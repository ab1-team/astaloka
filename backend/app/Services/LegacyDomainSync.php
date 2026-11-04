<?php

namespace App\Services;

use App\Models\Central\Tenant;
use App\Models\Central\TenantDomain;

/**
 * Menjaga agar dua slot legacy (`domain`, `domain_alternatif`) dan tabel
 * `tenant_domains` berisi hal yang sama.
 *
 * KENAPA LAYANAN INI ADA, BUKAN SEBAGIAN DARI CONTROLLER
 * =======================================================
 *
 * Dua slot legacy dan tabel `tenant_domains` menyimpan hal yang sama: host
 * yang dimiliki sebuah lokasi. Keduanya harus bergerak bersama, dan bentuk
 * geraknya harus PERSIS sama di semua tempat yang menyentuhnya -- panel pusat
 * (form lokasi), halaman Domain, dan perbaikan data lama.
 *
 * Menyalin aturan ini ke beberapa tempat menghasilkan bug yang tidak pernah
 * muncul sebagai error, hanya sebagai "domain saya kok cuma kelihatan satu".
 *
 * ATURAN SLOTNYA
 * ==============
 *
 * Tiap slot memegang SATU domain persis (bukan wildcard):
 *
 *   - `domain`            -> baris dengan `is_primary = true`
 *   - `domain_alternatif` -> baris dengan `is_primary = false`
 *
 * Baris wildcard TIDAK pernah disentuh dari sini. Wildcard dibuat lewat
 * halaman Domain, dan form lama tidak punya field untuk itu -- jadi ikut
 * ditulis di sini akan membuat wildcard terhapus setiap kali admin menyimpan
 * form.
 *
 * Kolom legacy dipakai sebagai CATATAN slot: nilai kolom `domain` adalah host
 * yang terakhir mengisi slot utama. Dari situ diketahui baris mana yang harus
 * dilepas ketika slot itu diisi ulang dengan host lain.
 *
 * BUG YANG MENJADIKAN LAYANAN INI PENTING
 * ========================================
 *
 * Versi sebelumnya melepas baris "lama" sebuah slot dengan cara menghapus
 * SELURUH baris domain persis milik lokasi itu -- untuk slot cadangan. Urutan
 * slotnya `domain` dulu, lalu `domain_alternatif`, jadi baris utama yang
 * barusan dibuat langsung terhapus oleh langkah slot cadangan.
 *
 * Akibatnya satu lokasi yang mengisi kedua field hanya punya SATU baris, dan
 * baris itu pun bukan `is_primary`. Di `tenant_domains` itu terlihat seperti
 * lokasi itu memang hanya punya satu domain -- padahal form-nya jelas berisi
 * dua. Form yang sama tidak bisa menyimpan dua domain, dan tidak ada error
 * yang menyinggung penyebabnya.
 *
 * Di sini pelepasan baris lama hanya menyentuh baris yang benar-benar dipegang
 * slot itu, dan hanya kalau host baris itu tidak sedang dibutuhkan slot lain
 * pada permintaan yang sama. Menukar dua slot (domain utama dan alternatif
 * ditukar) karena itu tidak menghapus baris yang sedang dibutuhkan -- dan
 * tidak membuat baris baru untuk host yang sudah ada.
 */
final class LegacyDomainSync
{
    /**
     * Kolom legacy mana yang perannya domain utama.
     *
     * `domain` = utama, `domain_alternatif` = cadangan.
     *
     * @var array<string, bool> [nama kolom => apakah ini slot utama]
     */
    private const SLOTS = [
        'domain' => true,
        'domain_alternatif' => false,
    ];

    /**
     * Sinkronkan slot domain dari input form.
     *
     * Dipanggil dari `LocationController::update()` SEBELUM `$location->update()`,
     * dan nilai kembalinya diperiksa lebih dulu. Kalau baris domain sudah
     * terlanjur ditulis lalu ternyata inputnya tidak valid, lokasi sudah dalam
     * keadaan setengah jadi: domain baru ada di `tenant_domains`, kolom lamanya
     * masih kosong, dan tidak ada yang melapor.
     *
     * Karena itu SEMUA input divalidasi lebih dulu, dan TIDAK SATU BARIS pun
     * ditulis kalau ada satu saja yang tidak valid. Konsekuensinya: satu
     * field yang salah ketik tidak membatalkan field yang sudah benar -- tapi
     * juga tidak meninggalkan lokasi dalam keadaan setengah jadi.
     *
     * Field yang tidak dikirim TIDAK disentuh. Form lama mengirim keduanya
     * setiap kali, tapi endpoint lain mungkin hanya mengirim salah satu -- dan
     * field yang absen bukan permintaan untuk mengosongkan.
     *
     * @param  array<string, mixed>  $data  Data yang sudah divalidasi
     * @param  bool  $sebagian  Boleh menulis slot yang valid meski slot lain
     *                          tidak valid? Dipakai oleh pendaftaran lokasi,
     *                          di mana satu host aneh tidak boleh
     *                          membatalkan pembuatan lokasi.
     * @return array<string, string> [nama kolom => pesan error]
     */
    public function sync(Tenant $location, array $data, bool $sebagian = false): array
    {
        $errors = [];

        // Host yang diminta tiap slot. `null` berarti slot dikosongkan.
        $diminta = [];

        foreach (self::SLOTS as $kolom => $isPrimary) {
            if (! array_key_exists($kolom, $data)) {
                continue;
            }

            $raw = $data[$kolom];
            $raw = is_string($raw) ? trim($raw) : '';

            // Mengosongkan form berarti "pakai halaman Domain saja". Baris
            // domain persis yang lama dilepas supaya tidak hidup dua kali --
            // dan supaya tidak memblokir index unik ketika host diganti.
            if ($raw === '') {
                $diminta[$kolom] = null;

                continue;
            }

            [$host, $problem] = DomainNormalizer::clean($raw);

            if ($problem !== null) {
                $errors[$kolom] = $problem;

                continue;
            }

            [$boleh, $tabrak] = DomainNormalizer::ensureAvailable($host, false, $location->id);

            if (! $boleh) {
                $errors[$kolom] = $tabrak;

                continue;
            }

            $diminta[$kolom] = $host;
        }

        /*
         * Mode ketat: satu slot yang tidak valid membatalkan semuanya.
         *
         * Dipakai saat MENYIMPAN PERUBAHAN. Menulis satu slot dan membatalkan
         * slot lain berarti lokasi berada di antara dua keadaan: satu domain
         * sudah berganti, yang lain belum -- dan tidak ada yang mengetahuinya.
         */
        if ($errors !== [] && ! $sebagian) {
            return $errors;
        }

        $this->terapkan($location, $diminta);

        return $errors;
    }

    /**
     * Perbaiki lokasi yang kolom legacy-nya belum punya baris di
     * `tenant_domains`.
     *
     * Dipakai oleh perintah `tenancy:repair-domains`, untuk lokasi yang
     * tertinggal rusak oleh versi `sync()` yang lama -- yang menghapus baris
     * utama setiap kali slot alternatif diisi.
     *
     * Bedanya dengan `sync()`:
     *
     *   - Inputnya bukan input user, tapi isi kolom legacy yang sudah ada.
     *     Karena itu hostnya dibersihkan dengan `normalizeLegacy()` (bersih
     *     tanpa menolak), bukan `clean()` (bersih DAN menolak). Kolom legacy
     *     pernah diisi form yang tidak memvalidasi format sama sekali, jadi
     *     isinya bisa saja `localhost:5173` -- dan membatalkan seluruh
     *     perbaikan karena satu host aneh akan membuat lokasi itu tetap rusak.
     *   - Tidak ada validasi ketersediaan. Host yang sudah dimiliki lokasi
     *     lain dilewati, bukan menggagalkan.
     *   - TIDAK PERNAH menghapus baris. Baris yang tidak dipegang slot mana
     *     pun -- misalnya domain tambahan yang ditambahkan lewat halaman
     *     Domain -- dibiarkan utuh. Perbaikan ini menambah yang kurang, bukan
     *     merapikan.
     *
     * @return array{disimpan:int, dilewati:int} Statistik, untuk laporan.
     */
    public function repair(Tenant $location): array
    {
        $disimpan = 0;
        $dilewati = 0;

        foreach (self::SLOTS as $kolom => $isPrimary) {
            $host = DomainNormalizer::normalizeLegacy($location->{$kolom});

            if ($host === null) {
                continue;
            }

            if ($this->cariPersis($location, $host) !== null) {
                $dilewati++;

                continue;
            }

            /*
             * Host yang sudah dimiliki lokasi lain tidak boleh dipakai.
             *
             * Index unik `tenant_domains_host_unique` menolaknya, jadi
             * pemeriksaan dilakukan lebih dulu -- kalau tidak, INSERT-nya
             * gagal dan seluruh perbaikan berhenti di tengah, meninggalkan
             * lokasi berikutnya dalam keadaan setengah diperbaiki.
             */
            [$boleh] = DomainNormalizer::ensureAvailable($host, false, $location->id);

            if (! $boleh) {
                $dilewati++;

                continue;
            }

            TenantDomain::create([
                'tenant_id' => $location->id,
                'host' => $host,
                'is_wildcard' => false,
                'is_primary' => $isPrimary,
                'is_active' => true,
            ]);

            $disimpan++;
        }

        /*
         * Slot utama harus benar-benar menjadi utama.
         *
         * Perbaikan di atas menulis baris utama dengan `is_primary = true`,
         * tapi baris lama yang masih ada di database bisa saja ikut
         * bertanda utama -- dan dua domain utama membuat `settings.domain`
         * di database lokasi menunjuk ke host yang tidak pasti.
         */
        $utama = $this->cariPersis($location, DomainNormalizer::normalizeLegacy($location->domain) ?? '');

        if ($utama !== null && ! $utama->is_primary) {
            $utama->update(['is_primary' => true]);
        }

        if ($utama !== null) {
            $this->clearPrimaryExcept($location->id, $utama->id);
        }

        return ['disimpan' => $disimpan, 'dilewati' => $dilewati];
    }

    /**
     * Terapkan slot domain yang diminta ke tabel `tenant_domains`.
     *
     * Urutan slot: utama dulu, baru cadangan. Urutannya menentukan siapa
     * yang memegang peran "utama" kalau dua slot meminta host yang sama --
     * dan host yang sama untuk dua slot berarti hanya satu domain, jadi
     * peran utamanya milik slot utama.
     *
     * @param  array<string, string|null>  $diminta  [nama kolom => host|null]
     */
    private function terapkan(Tenant $location, array $diminta): void
    {
        /*
         * Tidak ada slot yang diminta -> tidak ada yang boleh berubah.
         *
         * Endpoint yang hanya mengirim sebagian kolom -- `nama` saja, misalnya
         * -- tidak boleh diam-diam mengubah peran domain milik lokasi itu.
         */
        if ($diminta === []) {
            return;
        }

        /*
         * Baris yang dipegang tiap slot SEBELUM perubahan.
         *
         * Dicari lewat kolom legacy, bukan lewat `is_primary`: kolom legacy
         * adalah catatan "host apa yang mengisi slot ini", dan itulah yang
         * harus dilepas ketika slot diisi ulang dengan host lain.
         *
         * Baris yang tidak ditemukan bukan berarti slot ini kosong -- bisa
         * berarti kolom legacy-nya sudah tertinggal (domain diganti lewat
         * halaman Domain, jadi kolom lamanya tidak pernah ikut berubah).
         * Dalam kasus itu tidak ada yang bisa dilepas dengan yakin, jadi
         * tidak ada yang dilepas. Melebihi domain yang masih dipakai jauh
         * lebih berbahaya daripada meninggalkan satu baris extra.
         */
        $pegangan = [];

        foreach (self::SLOTS as $kolom => $isPrimary) {
            $lama = DomainNormalizer::normalizeLegacy($location->{$kolom});

            $pegangan[$kolom] = $lama === null
                ? null
                : $this->cariPersis($location, $lama);
        }

        foreach (self::SLOTS as $kolom => $isPrimary) {
            // Slot yang tidak dikirim TIDAK disentuh. Field yang absen bukan
            // permintaan untuk mengosongkan.
            if (! array_key_exists($kolom, $diminta)) {
                continue;
            }

            $host = $diminta[$kolom];
            $lama = $pegangan[$kolom];

            $baru = $host === null ? null : $this->cariPersis($location, $host);

            /*
             * Baris lama slot ini dilepas -- tapi HANYA kalau host-nya sedang
             * tidak dibutuhkan slot lain pada permintaan yang sama.
             *
             * Pengecualian itu penting untuk dua hal:
             *
             *   1. Menukar dua slot (utama <-> alternatif) tidak boleh
             *      menghapus baris yang sedang dibutuhkan slot lain; kalau iya,
             *      baris itu harus dibuat ulang -- dan id-nya berubah,
             *      padahal barisnya sendiri tidak salah.
             *   2. Slot yang dikosongkan tidak boleh menghapus baris milik
             *      slot lain hanya karena kebetulan host-nya sama.
             */
            if ($lama !== null && ($baru === null || $lama->id !== $baru->id)) {
                if (! $this->dipakaiSlotLain($lama->host, $kolom, $diminta)) {
                    $lama->delete();
                }
            }

            // Slot dikosongkan: tidak ada baris baru yang dibuat. Baris lama
            // sudah dilepas di atas.
            if ($host === null) {
                continue;
            }

            if ($baru === null) {
                $baru = TenantDomain::create([
                    'tenant_id' => $location->id,
                    'host' => $host,
                    'is_wildcard' => false,
                    'is_primary' => $isPrimary,
                    'is_active' => true,
                ]);
            } elseif (! $baru->is_active) {
                // Host yang sudah pernah dipakai lalu dimatikan lewat halaman
                // Domain, sekarang diisi lagi di form: domainnya dihidupkan
                // kembali, bukan dianggap baru.
                $baru->update(['is_active' => true]);
            }

            /*
             * Peran "utama" hanya boleh diberikan slot utama.
             *
             * Slot cadangan TIDAK pernah menurunkan status utama milik slot
             * lain. Kalau baris yang ditunjuk slot cadangan ternyata sedang
             * jadi utama, baris itu dibiarkan seperti adanya -- slot utama
             * yang diproses di atas sudah mengurus siapa yang menjadi utama.
             */
            if ($isPrimary) {
                if (! $baru->is_primary) {
                    $baru->update(['is_primary' => true]);
                }

                $this->clearPrimaryExcept($location->id, $baru->id);
            }
        }

        $this->jagaAdaUtama($location);
    }

    /**
     * Pastikan lokasi punya tepat satu domain utama -- kalau memang punya
     * domain persis yang aktif.
     *
     * Kasus yang diurus di sini: slot utama dikosongkan sementara slot
     * cadangan masih berisi. Baris utama ikut hilang (memang itu yang
     * diminta), tapi baris cadangan harus naik menjadi utama -- kalau tidak,
     * lokasi itu punya domain tapi tidak punya "domain utama", dan
     * `settings.domain` di database lokasinya menunjuk ke host yang sudah
     * tidak jadi milik lokasi ini.
     *
     * Baris yang dipilih adalah yang paling lama dibuat, dan hanya kalau
     * belum ada baris lain yang menjadi utama. Lokasi tanpa domain persis
     * yang aktif -- hanya punya wildcard, misalnya -- dibiarkan tanpa
     * utama: memaksa wildcard jadi utama justru merusak resolver, karena
     * wildcard bukan host yang bisa dilayani.
     */
    private function jagaAdaUtama(Tenant $location): void
    {
        $sudahAdaUtama = TenantDomain::query()
            ->where('tenant_id', $location->id)
            ->where('is_wildcard', false)
            ->where('is_primary', true)
            ->exists();

        if ($sudahAdaUtama) {
            return;
        }

        $kandidat = TenantDomain::query()
            ->where('tenant_id', $location->id)
            ->where('is_wildcard', false)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($kandidat === null) {
            return;
        }

        $kandidat->update(['is_primary' => true]);
    }

    /**
     * Baris domain PERSIS milik lokasi ini dengan host tersebut.
     *
     * Wildcard dikecualikan: form lama tidak punya field untuk wildcard, jadi
     * form tidak boleh memindahkan peran wildcard jadi domain biasa.
     */
    private function cariPersis(Tenant $location, string $host): ?TenantDomain
    {
        if ($host === '') {
            return null;
        }

        return $location->domains()
            ->where('host', $host)
            ->where('is_wildcard', false)
            ->first();
    }

    /**
     * Apakah host ini diminta slot lain pada permintaan yang sama.
     */
    private function dipakaiSlotLain(string $host, string $kolom, array $diminta): bool
    {
        foreach ($diminta as $lain => $h) {
            if ($lain === $kolom || $h === null) {
                continue;
            }

            if ($h === $host) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pastikan hanya satu domain utama per lokasi.
     *
     * Tanpa ini, dua admin yang menekan tombol hampir bersamaan bisa menyimpan
     * dua `is_primary = true`. Efeknya bukan sekadar baris ganda di daftar --
     * `settings.domain` di database lokasi jadi tidak pasti menunjuk ke mana,
     * sehingga kop laporan bisa mencantumkan domain yang tidak dipakai lagi.
     */
    private function clearPrimaryExcept(int $tenantId, ?int $keepId = null): void
    {
        TenantDomain::query()
            ->where('tenant_id', $tenantId)
            ->where('is_primary', true)
            ->when($keepId !== null, fn ($q) => $q->where('id', '!=', $keepId))
            ->update(['is_primary' => false]);
    }
}
