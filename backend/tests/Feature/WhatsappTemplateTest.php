<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InstallationPackage;
use App\Models\InstallationTicket;
use App\Models\MonthlyBill;
use App\Models\Setting;
use App\Models\User;
use App\Models\Village;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pesan WhatsApp harus mengikuti template yang diset admin di SOP.
 *
 * SEBELUMNYA template `settings.pesan_tagihan` hanya DISIMPAN, tidak pernah
 * dipakai: halaman "Daftar Tagihan" mengirim teks yang ditulis mati di
 * frontend. Test ini mengunci perilaku yang baru -- isi pesan diambil dari
 * template admin.
 */
class WhatsappTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin Test',
            'email' => 'admin.wa@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);
    }

    private function tekhnisi(): User
    {
        return User::create([
            'name' => 'Teknisi Test',
            'email' => 'teknisi.wa@test.local',
            'password' => Hash::make('password'),
            'role' => 'teknisi',
        ]);
    }

    private function buatTagihan(?User $pemilik, string $nama, string $telepon): MonthlyBill
    {
        $village = Village::create([
            // `code` unik per baris, jadi tiap tagihan dapat desanya sendiri.
            'code' => '999.'.str_pad((string) (Village::count() + 1), 3, '0', STR_PAD_LEFT),
            'village_name' => 'Desa Uji',
            'hamlet_name' => 'Dusun Uji',
        ]);

        $package = InstallationPackage::create([
            'name' => 'Paket Uji',
            'installation_fee' => 1000000,
            'monthly_abodemen' => 50000,
            'late_penalty' => 5000,
        ]);

        $ticket = InstallationTicket::create([
            'package_id' => $package->id,
            'applicant_name' => $nama,
            'nik' => (string) random_int(1000000000000000, 9999999999999999),
            'phone' => $telepon,
            'address' => 'Alamat uji',
            'lat' => 0,
            'lng' => 0,
            'status' => 'completed',
            'village_id' => $village->id,
            'user_id' => $pemilik?->id,
            'created_by' => $pemilik?->id ?? $this->admin()->id,
        ]);

        $user = User::create([
            'name' => $nama,
            'email' => 'pelanggan.'.uniqid().'@test.local',
            'password' => Hash::make('password'),
            'role' => 'pelanggan',
        ]);

        $customer = Customer::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'customer_code' => '999.100.'.uniqid(),
            'initial_meter_reading' => 0,
        ]);

        return MonthlyBill::create([
            'customer_id' => $customer->id,
            'billing_period_year' => 2026,
            'billing_period_month' => 8,
            'meter_reading_start' => 0,
            'meter_reading_end' => 10,
            'usage_m3' => 10,
            'usage_charge' => 125000,
            'abodemen' => 0,
            'penalty_amount' => 0,
            'total_amount' => 125000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(10),
        ]);
    }

    public function test_pesan_mengikuti_template_yang_diset_admin(): void
    {
        $admin = $this->admin();

        $s = Setting::firstOrNew([]);
        $s->key = $s->key ?: 'sop';
        $s->pesan_tagihan = 'Yth. {customer} dari {desa}, tagihan Anda sebesar {jumlah_tagihan}.';
        $s->save();

        $bill = $this->buatTagihan($admin, 'Budi Santoso', '081234567890');

        $res = $this->actingAs($admin)
            ->postJson('/api/whatsapp/messages', ['bill_ids' => [$bill->id]])
            ->assertOk()
            ->assertJsonPath('success', true);

        $pesan = $res->json('data.messages.0');

        $this->assertStringContainsString('Budi Santoso', $pesan['message']);
        $this->assertStringContainsString('Desa Uji', $pesan['message']);
        $this->assertStringContainsString('125.000', $pesan['message']);

        // Nomor 0812... harus jadi format internasional 62812...
        $this->assertSame('6281234567890', $pesan['phone_normalized']);
        $this->assertStringStartsWith('https://wa.me/6281234567890?text=', $pesan['wa_url']);
    }

    public function test_template_kosong_pakai_bawaan(): void
    {
        $admin = $this->admin();

        $s = Setting::firstOrNew([]);
        $s->key = $s->key ?: 'sop';
        $s->pesan_tagihan = null;
        $s->save();

        $bill = $this->buatTagihan($admin, 'Siti Aminah', '081298765432');

        $res = $this->actingAs($admin)
            ->postJson('/api/whatsapp/messages', ['bill_ids' => [$bill->id]])
            ->assertOk();

        $pesan = $res->json('data.messages.0');

        $this->assertStringContainsString('Siti Aminah', $pesan['message']);
        $this->assertStringContainsString('125.000', $pesan['message']);
    }

    public function test_tagihan_tanpa_nomor_wa_tidak_gagal_seluruhnya(): void
    {
        $admin = $this->admin();

        $bill = $this->buatTagihan($admin, 'Tanpa Nomor', '');

        $res = $this->actingAs($admin)
            ->postJson('/api/whatsapp/messages', ['bill_ids' => [$bill->id]])
            ->assertOk();

        $pesan = $res->json('data.messages.0');

        $this->assertSame('', $pesan['wa_url']);
        $this->assertNotEmpty($pesan['error']);
        $this->assertSame(0, $res->json('data.siap_kirim'));
    }

    public function test_teknisi_tidak_bisa_menyusun_pesan_tagihan_milik_teknisi_lain(): void
    {
        $admin = $this->admin();
        $teknisi = $this->tekhnisi();

        // Tagihan milik teknisi lain.
        $bill = $this->buatTagihan($admin, 'Milik Orang Lain', '081234567890');

        $res = $this->actingAs($teknisi)
            ->postJson('/api/whatsapp/messages', ['bill_ids' => [$bill->id]])
            ->assertOk();

        $pesan = $res->json('data.messages.0');

        // Tidak boleh ikut membawa data pelanggan milik teknisi lain.
        $this->assertSame('', $pesan['wa_url']);
        $this->assertNotEmpty($pesan['error']);
        $this->assertStringNotContainsString('Milik Orang Lain', $pesan['message']);
    }

    public function test_banyak_tagihan_disusun_sekaligus(): void
    {
        $admin = $this->admin();

        $s = Setting::firstOrNew([]);
        $s->key = $s->key ?: 'sop';
        $s->pesan_tagihan = 'Yth. {customer}, tagihan {jumlah_tagihan}.';
        $s->save();

        $a = $this->buatTagihan($admin, 'Pelanggan Satu', '081111111111');
        $b = $this->buatTagihan($admin, 'Pelanggan Dua', '082222222222');

        $res = $this->actingAs($admin)
            ->postJson('/api/whatsapp/messages', ['bill_ids' => [$a->id, $b->id]])
            ->assertOk();

        $this->assertSame(2, $res->json('data.total'));
        $this->assertSame(2, $res->json('data.siap_kirim'));
        $this->assertStringContainsString('Pelanggan Satu', $res->json('data.messages.0.message'));
        $this->assertStringContainsString('Pelanggan Dua', $res->json('data.messages.1.message'));
    }
}
