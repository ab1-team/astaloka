<?php

namespace Tests\Unit;

use App\Models\InstallationPackage;
use App\Models\WaterTariffBlock;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BillingService;
    }

    private function createPackageWithBlocks(): InstallationPackage
    {
        $package = InstallationPackage::create([
            'name' => 'Paket Test',
            'installation_fee' => 1_500_000,
            'monthly_abodemen' => 15_000,
            'late_penalty' => 10_000,
        ]);

        WaterTariffBlock::insert([
            ['package_id' => $package->id, 'usage_min_m3' => 0,  'usage_max_m3' => 10,   'price_per_m3' => 1_500, 'created_at' => now(), 'updated_at' => now()],
            ['package_id' => $package->id, 'usage_min_m3' => 10, 'usage_max_m3' => 20,   'price_per_m3' => 2_000, 'created_at' => now(), 'updated_at' => now()],
            ['package_id' => $package->id, 'usage_min_m3' => 20, 'usage_max_m3' => null, 'price_per_m3' => 2_500, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $package;
    }

    /**
     * Rumus tagihan = pemakaian × harga paket per m³ (FLAT).
     *
     * Blok tarif dipakai untuk MEMILIH harga per m³, bukan untuk membagi
     * pemakaian jadi beberapa bagian dengan harga berbeda. Ini yang dipakai
     * aplikasi LAMA -- dibuktikan dari data produksi `pamsides_dump.usages`:
     * dari 25.569 baris pemakaian yang dihitung APLIKASI (bukan import
     * Excel) dan paketnya bertarif, rumus flat cocok 25.540 baris (99,89%),
     * rumus progresif hanya 11.832 baris (46,27%).
     *
     * Baris import Excel TIDAK dipakai sebagai bukti: di sana `jumlah`
     * adalah turunan dari nominal yang sudah jadi di file Excel
     * (`jumlah = round(nominal / harga, 2)`), bukan hasil hitung aplikasi.
     */
    #[Test]
    public function pemakaian_di_bawah_batas_blok_pertama(): void
    {
        $package = $this->createPackageWithBlocks();
        $result = $this->service->calculateChargeForTesting($package, 5);

        $this->assertEquals(7_500, $result);
    }

    #[Test]
    public function pemakaian_di_blok_kedua(): void
    {
        // 15 m3 -> masuk blok 10-20 @ 2.000 -> 15 x 2.000 = 30.000
        $package = $this->createPackageWithBlocks();
        $result = $this->service->calculateChargeForTesting($package, 15);

        $this->assertEquals(30_000, $result);
    }

    #[Test]
    public function pemakaian_di_blok_ketiga(): void
    {
        // 25 m3 -> blok tak terbatas @ 2.500 -> 25 x 2.500 = 62.500
        $package = $this->createPackageWithBlocks();
        $result = $this->service->calculateChargeForTesting($package, 25);

        $this->assertEquals(62_500, $result);
    }

    #[Test]
    public function pemakaian_tepat_di_batas_blok(): void
    {
        // Batas atas blok INKLUSIF: 10 m3 masih di blok 0-10 @ 1.500
        $package = $this->createPackageWithBlocks();
        $result = $this->service->calculateChargeForTesting($package, 10);

        $this->assertEquals(15_000, $result);
    }

    #[Test]
    public function pemakaian_desimal(): void
    {
        // 10,5 m3 -> melewati batas atas blok pertama (10), jadi masuk blok
        // kedua @ 2.000 -> 10,5 x 2.000 = 21.000
        $package = $this->createPackageWithBlocks();
        $result = $this->service->calculateChargeForTesting($package, 10.5);

        $this->assertEquals(21_000, $result);
    }

    #[Test]
    public function pemakaian_nol_tidak_dibayar(): void
    {
        $package = $this->createPackageWithBlocks();
        $result = $this->service->calculateChargeForTesting($package, 0);

        $this->assertEquals(0, $result);
    }

    /**
     * Paket dengan penulisan rentang INKLUSIF -- format yang dipakai data
     * produksi (paket "Warga": `1-10 m3` @ 3.500 lalu `11-100000 m3` @ 4.000).
     *
     * Di aplikasi LAMA, 19 m3 tercatat 76.000 (= 19 x 4.000), bukan 71.000.
     * Rumus progresif (10 x 3.500 + 9 x 4.000) tidak pernah dipakai di
     * produksi -- 0 baris yang cocok.
     */
    private function createPackageWithInclusiveBlocks(): InstallationPackage
    {
        $package = InstallationPackage::create([
            'name' => 'Paket Rentang Inklusif',
            'installation_fee' => 1_500_000,
            'monthly_abodemen' => 10_000,
            'late_penalty' => 10_000,
        ]);

        WaterTariffBlock::insert([
            ['package_id' => $package->id, 'usage_min_m3' => 1,  'usage_max_m3' => 10,      'price_per_m3' => 3_500, 'created_at' => now(), 'updated_at' => now()],
            ['package_id' => $package->id, 'usage_min_m3' => 11, 'usage_max_m3' => 100_000, 'price_per_m3' => 4_000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        return $package;
    }

    #[Test]
    public function rentang_inklusif_tepat_di_batas_blok_pertama(): void
    {
        // 10 m3 -> blok 1-10 @ 3.500 -> 10 x 3.500 = 35.000
        $package = $this->createPackageWithInclusiveBlocks();
        $result = $this->service->calculateChargeForTesting($package, 10);

        $this->assertEquals(35_000, $result);
    }

    #[Test]
    public function rentang_inklusif_satu_m3_di_bawah_batas_blok_pertama(): void
    {
        // 9 m3 -> masih blok pertama -> 9 x 3.500 = 31.500
        $package = $this->createPackageWithInclusiveBlocks();
        $result = $this->service->calculateChargeForTesting($package, 9);

        $this->assertEquals(31_500, $result);
    }

    #[Test]
    public function rentang_inklusif_satu_m3_di_atas_batas_blok_pertama(): void
    {
        // 11 m3 -> blok 11-100000 @ 4.000 -> 11 x 4.000 = 44.000
        $package = $this->createPackageWithInclusiveBlocks();
        $result = $this->service->calculateChargeForTesting($package, 11);

        $this->assertEquals(44_000, $result);
    }

    #[Test]
    public function rentang_inklusif_pemakaian_19_m3(): void
    {
        // Kasus nyata Arjo Suwito (agustus 2026, produksi): 19 m3 ->
        // 76.000 = 19 x 4.000
        $package = $this->createPackageWithInclusiveBlocks();
        $result = $this->service->calculateChargeForTesting($package, 19);

        $this->assertEquals(76_000, $result);
    }

    #[Test]
    public function rentang_inklusif_pemakaian_20_m3(): void
    {
        // 20 m3 -> 20 x 4.000 = 80.000
        $package = $this->createPackageWithInclusiveBlocks();
        $result = $this->service->calculateChargeForTesting($package, 20);

        $this->assertEquals(80_000, $result);
    }

    #[Test]
    public function tagihan_selalu_naik_bertambah_saat_pemakaian_naik(): void
    {
        // Penjaga: pemakaian naik TIDAK BOLEH bikin tagihan turun. Ini yang
        // dikeluhkan admin (18 m3 -> 92.000, lalu 19 m3 -> 91.500).
        $package = $this->createPackageWithInclusiveBlocks();

        $sebelum = $this->service->calculateChargeForTesting($package, 18);
        $sesudah = $this->service->calculateChargeForTesting($package, 19);

        $this->assertGreaterThan($sebelum, $sesudah);
    }

    /**
     * Pemakaian desimal menghasilkan nominal RUPIAH PENUH.
     *
     * Aplikasi LAMA tidak membulatkan di source-nya
     * (`siap_mapan/app/Http/Controllers/UsageController.php:315` menyimpan
     * `harga × pemakaian` apa adanya), TAPI semua harga paket legacy
     * kelipatan 100 dan pemakaian yang diinput aplikasi selalu bulat --
     * jadi untuk pemakaian bulat hasilnya identik dengan legacy.
     *
     * Untuk pemakaian desimal, pembulatan ke rupiah penuh dipakai supaya
     * tagihannya bisa dibayar tepat (kolom `usage_charge` DECIMAL(12,2),
     * pembayaran dalam rupiah penuh).
     *
     * Yang TIDAK dipakai: pembulatan ke RATUSAN. Tidak ada aturan di
     * aplikasi LAMA yang melakukannya -- `Keuangan::pembulatan()` ada di
     * source tapi tidak dipanggil dari mana pun, dan tabel `businesses`
     * tidak punya kolom `pembulatan` sama sekali.
     *
     * Angka di bawah adalah hasil `round(pemakaian × harga)` -- bukan
     * pembulatan ke ratusan. 9,71 x 3.500 = 33.985 persis, jadi nominalnya
     * 33.985, bukan 34.000.
     */
    #[Test]
    public function pemakaian_desimal_dibulatkan_ke_rupiah_penuh(): void
    {
        $package = $this->createPackageWithInclusiveBlocks();

        // 9,71 m3 -> 9,71 x 3.500 = 33.985 -> 33.985 (sudah bulat)
        $this->assertEquals(33_985, $this->service->calculateChargeForTesting($package, 9.71));

        // 6,14 m3 -> 6,14 x 3.500 = 21.490 -> 21.490 (sudah bulat)
        $this->assertEquals(21_490, $this->service->calculateChargeForTesting($package, 6.14));

        // 0,14 m3 -> 0,14 x 3.500 = 490 -> 490 (sudah bulat)
        $this->assertEquals(490, $this->service->calculateChargeForTesting($package, 0.14));
    }

    /**
     * Pemakaian desimal yang hasilnya pecahan rupiah dibulatkan ke rupiah
     * penuh -- bukan ke ratusan dan tidak dibiarkan pecahan.
     *
     * 0,1443 m3 x 3.500 = 505,05 -> 505.
     * 0,1443 m3 x 4.000 = 577,20 -> 577.
     *
     * Yang penting: hasilnya SELALU bulat, apa pun bentuk pakainya.
     */
    #[Test]
    public function pemakaian_desimal_selalu_menghasilkan_nominal_bulat(): void
    {
        $package = $this->createPackageWithInclusiveBlocks();

        foreach ([0.14, 0.57, 6.14, 9.71, 10.5, 11.02, 11.07, 19.5, 33.33, 0.1443, 7.7777] as $usage) {
            $result = $this->service->calculateChargeForTesting($package, $usage);

            $this->assertSame(
                $result,
                round($result),
                sprintf('Pemakaian %s m3 harus menghasilkan nominal bulat.', $usage)
            );
        }
    }

    /**
     * Pemakaian yang melewati SEMUA batas atas blok (tidak ada blok tak
     * terbatas) memakai harga blok terakhir.
     */
    #[Test]
    public function pemakaian_melewati_semua_batas_atas_memakai_harga_blok_terakhir(): void
    {
        $package = InstallationPackage::create([
            'name' => 'Paket Tanpa Blok Tak Terbatas',
            'installation_fee' => 1_500_000,
            'monthly_abodemen' => 10_000,
            'late_penalty' => 10_000,
        ]);

        WaterTariffBlock::insert([
            ['package_id' => $package->id, 'usage_min_m3' => 0,  'usage_max_m3' => 10,   'price_per_m3' => 1_500, 'created_at' => now(), 'updated_at' => now()],
            ['package_id' => $package->id, 'usage_min_m3' => 11, 'usage_max_m3' => 20,   'price_per_m3' => 2_000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 35 m3 -> melewati 20 -> harga blok terakhir 2.000 -> 70.000
        $result = $this->service->calculateChargeForTesting($package, 35);

        $this->assertEquals(70_000, $result);
    }

    /**
     * Blok yang TUMPANG TINDIH: yang dipakai adalah blok PERTAMA.
     *
     * Ini bukan pilihan gaya — ini yang terbukti dari data produksi.
     *
     * Source legacy (`siap_mapan/app/Http/Controllers/UsageController.php:287`)
     * menulis `$result[$i] = $index` di dalam loop, jadi angka yang tercakup
     * lebih dari satu blok menunjuk blok TERAKHIR. Tapi blok legacy memang
     * tumpang tindih (`0-10`, `10-20`, `20-100`), dan data yang tersimpan di
     * produksi justru lebih cocok dengan aturan "blok PERTAMA":
     *
     *   - FLAT + blok PERTAMA : 25.540 / 25.569 = 99,89%
     *   - FLAT + blok TERAKHIR : 25.377 / 25.569 = 99,25%
     *
     * 29 baris sisanya TIDAK cocok dengan kedua aturan (jadi bukan bukti
     * aturan yang berbeda) -- isinya anomali data: `nominal` 0, harga
     * 10.000/m³ yang bukan harga paket mana pun, duplikat import, dan
     * harga paket lain. Sebar di 22 tanggal / 10 bulan berbeda.
     *
     * Yang salah satu diedit di sini persis seperti di produksi: blok
     * `0-10` @ 1.500 dan `10-20` @ 2.000 (titik 10 terdua keduanya).
     */
    #[Test]
    public function blok_tumpang_tindih_memakai_blok_pertama(): void
    {
        $package = InstallationPackage::create([
            'name' => 'Paket Blok Tumpang Tindih',
            'installation_fee' => 1_500_000,
            'monthly_abodemen' => 15_000,
            'late_penalty' => 10_000,
        ]);

        WaterTariffBlock::insert([
            ['package_id' => $package->id, 'usage_min_m3' => 0,  'usage_max_m3' => 10, 'price_per_m3' => 1_500, 'created_at' => now(), 'updated_at' => now()],
            ['package_id' => $package->id, 'usage_min_m3' => 10, 'usage_max_m3' => 20, 'price_per_m3' => 2_000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 10 m3 -> blok PERTAMA (0-10 @ 1.500) -> 10 x 1.500 = 15.000
        // Kalau blok terakhir yang dipakai, hasilnya 10 x 2.000 = 20.000.
        $this->assertEquals(15_000, $this->service->calculateChargeForTesting($package, 10));

        // 9 m3 -> blok pertama juga -> 9 x 1.500 = 13.500
        $this->assertEquals(13_500, $this->service->calculateChargeForTesting($package, 9));

        // 11 m3 -> baru blok kedua -> 11 x 2.000 = 22.000
        $this->assertEquals(22_000, $this->service->calculateChargeForTesting($package, 11));
    }
}
