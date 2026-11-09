# Dokumentasi API PAM Sides

## Daftar Isi
1. [Rute API](#1-rute-api)
2. [Checklist Tabel yang Belum Diimplementasi](#2-checklist-tabel-yang-belum-diimplementasi)

---

## 1. Rute API

### RUTE PUBLIK (Tanpa Autentikasi)

| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| POST | `/api/login` | AuthController | `{ success, data: { user, token } }` |
| GET | `/api/health` | Closure | `{"status":"OK"}` |

---

### TERAUTENTIKASI (Semua Role)

| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| POST | `/api/logout` | AuthController | `{ success, data: { message } }` |
| POST | `/api/refresh` | AuthController | `{ success, data: { token } }` |
| GET | `/api/me` | AuthController | Profil pengguna + data pelanggan |
| PUT | `/api/me` | AuthController | Profil yang diperbarui |
| PUT | `/api/me/password` | AuthController | Konfirmasi perubahan kata sandi |
| POST | `/api/me/avatar` | AuthController | URL avatar |
| GET | `/api/settings/kecamatan` | SettingController | Daftar kecamatan |
| GET | `/api/settings/desa` | SettingController | Daftar desa |
| GET | `/api/settings/payment-mode` | SettingController | Pengaturan mode pembayaran |

---

### RUTE BERSAMA (Admin + Surveyor)

| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/installation-tickets` | InstallationTicketController | Tiket (paginasi) |
| GET | `/api/installation-tickets/{id}` | InstallationTicketController | Detail satu tiket |

---

### RUTE BERSAMA (Admin + Surveyor) - Pengiriman Survei

| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| POST | `/api/installation-tickets/{id}/survey` | SurveyResultController | Hasil survei + pembaruan status |

---

### RUTE BERSAMA (Admin + Teknisi)

| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/test-teknisi` | Closure | `{message: "Kamu teknisi!"}` |
| GET | `/api/meter-readings/completed` | MeterReadingController | Daftar pembacaan selesai |
| GET | `/api/dashboard/statistics` | DashboardController | Statistik dashboard |
| GET | `/api/meter-readings/pending` | MeterReadingController | Pelanggan pending (belum dicatat) |
| POST | `/api/meter-readings` | MeterReadingController | Buat pembacaan + generate tagihan otomatis. Field `photo` OPSIONAL (bukti pendukung, boleh null) |
| GET | `/api/meter-readings/{id}` | MeterReadingController | Detail satu pembacaan |
| PUT | `/api/meter-readings/{id}` | MeterReadingController | Perbarui pembacaan |
| DELETE | `/api/meter-readings/{id}` | MeterReadingController | Hapus pembacaan |
| GET | `/api/customers` | CustomerController | Daftar pelanggan |
| GET | `/api/customers/search` | CustomerController | Cari pelanggan |
| GET | `/api/customers/{id}` | CustomerController | Detail pelanggan |
| GET | `/api/monthly-bills` | MonthlyBillController | Daftar tagihan |
| GET | `/api/monthly-bills/usage` | MonthlyBillController | Laporan penggunaan |
| POST | `/api/installation-tickets/{id}/installation-result` | InstallationResultController | Catat hasil pemasangan |

---

### RUTE KHUSUS ADMIN

| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/test-admin` | Closure | `{message: "Kamu admin!"}` |

#### Pengguna
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/users` | UserController | Daftar pengguna |
| POST | `/api/users` | UserController | Buat pengguna |
| GET | `/api/users/{id}` | UserController | Detail pengguna |
| PUT | `/api/users/{id}` | UserController | Perbarui pengguna |
| DELETE | `/api/users/{id}` | UserController | Hapus pengguna |

#### Desa
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/villages` | VillageController | Daftar desa |
| POST | `/api/villages` | VillageController | Buat desa |
| GET | `/api/villages/{id}` | VillageController | Detail desa |
| PUT | `/api/villages/{id}` | VillageController | Perbarui desa |
| DELETE | `/api/villages/{id}` | VillageController | Hapus desa |

#### Pelanggan (CRUD Admin)
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/customers` | CustomerController | Daftar pelanggan |
| POST | `/api/customers` | CustomerController | Buat pelanggan |
| GET | `/api/customers/{id}` | CustomerController | Detail pelanggan |
| PUT | `/api/customers/{id}` | CustomerController | Perbarui pelanggan |
| DELETE | `/api/customers/{id}` | CustomerController | Hapus pelanggan |

#### Paket Pemasangan
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/installation-packages` | InstallationPackageController | Daftar paket |
| POST | `/api/installation-packages` | InstallationPackageController | Buat paket |
| GET | `/api/installation-packages/{id}` | InstallationPackageController | Detail paket |
| PUT | `/api/installation-packages/{id}` | InstallationPackageController | Perbarui paket |
| DELETE | `/api/installation-packages/{id}` | InstallationPackageController | Hapus paket |

#### Blok Tarif Air (nested di bawah paket)
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/installation-packages/{id}/water-tariff-blocks` | WaterTariffBlockController | Blok tarif |
| POST | `/api/installation-packages/{id}/water-tariff-blocks` | WaterTariffBlockController | Buat blok tarif |
| GET | `/api/installation-packages/{id}/water-tariff-blocks/{bid}` | WaterTariffBlockController | Detail blok tarif |
| PUT | `/api/installation-packages/{id}/water-tariff-blocks/{bid}` | WaterTariffBlockController | Perbarui blok tarif |
| DELETE | `/api/installation-packages/{id}/water-tariff-blocks/{bid}` | WaterTariffBlockController | Hapus blok tarif |

#### Tiket Pemasangan
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| POST | `/api/installation-tickets` | InstallationTicketController | Buat tiket (draft) |
| PUT | `/api/installation-tickets/{id}/register` | InstallationTicketController | Daftarkan pemasangan |
| PATCH | `/api/installation-tickets/{id}/transition` | InstallationTicketController | Transisi status |
| GET | `/api/installation-tickets-unpaid` | InstallationTicketController | Tiket belum bayar |
| POST | `/api/installation-tickets/{id}/payment` | PaymentController | Catat pembayaran |
| POST | `/api/installation-tickets/{id}/advance-stage` | InstallationTicketController | Majukan tahap |
| POST | `/api/installation-tickets/{id}/activate` | ActivationController | Aktifkan pelanggan |
| GET | `/api/reports/installations` | InstallationTicketController | Laporan pemasangan |

#### Hasil Survei
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/survey-results` | SurveyResultController | Daftar survei |
| GET | `/api/survey-results/{id}` | SurveyResultController | Detail survei |
| PUT | `/api/survey-results/{id}` | SurveyResultController | Perbarui survei |
| POST | `/api/survey-results/{id}` | SurveyResultController | Perbarui survei (alternatif) |
| DELETE | `/api/survey-results/{id}` | SurveyResultController | Hapus survei |

#### Laporan Gangguan
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/trouble-reports` | TroubleReportController | Daftar laporan |
| GET | `/api/trouble-reports/{id}` | TroubleReportController | Detail laporan |
| PATCH | `/api/trouble-reports/{id}/status` | TroubleReportController | Perbarui status |

#### Penagihan
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/bills/recap` | BillingController | Rekap tagihan |
| POST | `/api/bills/generate` | BillingController | Generate tagihan |
| GET | `/api/bills/{id}` | BillingController | Detail tagihan |

#### Tagihan Bulanan
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/monthly-bills` | MonthlyBillController | Tagihan |
| GET | `/api/monthly-bills/{id}` | MonthlyBillController | Detail tagihan (admin + teknisi) |
| GET | `/api/monthly-bills/usage` | MonthlyBillController | Penggunaan |
| PUT | `/api/monthly-bills/usage` | MonthlyBillController | Ubah meter akhir + hitung ulang tagihan (admin + teknisi) |
| POST | `/api/monthly-bills/{id}/pay` | MonthlyBillController | Bayar tagihan |
| DELETE | `/api/monthly-bills/{id}` | MonthlyBillController | Rollback tagihan yang sudah dibayar |
| POST | `/api/monthly-bills/generate` | MonthlyBillController | Generate tagihan |
| GET | `/api/reports/bills` | MonthlyBillController | Laporan tagihan |

#### Laporan (Admin)
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/reports/billing` | ReportController | Laporan penagihan |
| GET | `/api/reports/installation` | ReportController | Laporan pemasangan |
| GET | `/api/reports/billing/export-csv` | ReportController | CSV penagihan |
| GET | `/api/reports/billing/export-pdf` | ReportController | PDF penagihan |
| GET | `/api/reports/installation/export-csv` | ReportController | CSV pemasangan |
| GET | `/api/reports/installation/export-pdf` | ReportController | PDF pemasangan |

#### Transaksi (Akuntansi)
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/transactions` | TransactionController | Daftar transaksi (paginasi) |
| POST | `/api/transactions` | TransactionController | Buat transaksi |
| GET | `/api/transactions/{id}` | TransactionController | Detail transaksi |
| PUT | `/api/transactions/{id}` | TransactionController | Perbarui transaksi |
| DELETE | `/api/transactions/{id}` | TransactionController | Hapus transaksi |
| POST | `/api/generate-amount` | GenerateAmountController | Generate jumlah akun |
| POST | `/api/piutang/generate` | PiutangController | Trigger manual generate jurnal piutang untuk tagihan menunggak |

#### Amount
| Method | Endpoint | Controller | Response |
|--------|----------|------------|----------|
| GET | `/api/amount` | AmountController | Debit/kredit by bulan, tahun, account_id |

#### Jenis Transactions
| Method | Endpoint | Controller | Response |
|--------|----------|------------|----------|
| GET | `/api/jenis-transactions` | JenisTransactionController | List semua jenis transaksi |
| POST | `/api/jenis-transactions` | JenisTransactionController | Buat jenis transaksi |
| GET | `/api/jenis-transactions/{id}` | JenisTransactionController | Detail jenis transaksi |
| PUT | `/api/jenis-transactions/{id}` | JenisTransactionController | Update jenis transaksi |
| DELETE | `/api/jenis-transactions/{id}` | JenisTransactionController | Hapus jenis transaksi |

#### Inventaris (CRUD)
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/inventaris` | InventoryController | List inventaris (filter: `kategori`, `jenis`, `status`, `q`, `tgl_dari`, `tgl_sampai`, `per_page`) |
| GET | `/api/inventaris/{id}` | InventoryController | Detail inventaris |
| POST | `/api/inventaris` | InventoryController | Buat inventaris |
| PUT | `/api/inventaris/{id}` | InventoryController | Perbarui inventaris |
| DELETE | `/api/inventaris/{id}` | InventoryController | Hapus inventaris |

**Body `POST /api/inventaris`:**
```json
{
  "nama_barang": "Laptop Asus ROG",
  "tgl_beli": "2026-07-01",
  "unit": 1,
  "harsat": 10000000,
  "umur_ekonomis": 48,
  "jenis": "1",
  "kategori": "4",
  "status": "Baik",
  "tgl_validasi": null
}
```

#### Jurnal Umum - Form Dispatch & Inventaris
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/transaksi/jurnal-umum/form` | JurnalUmumController | Dispatch form (4 skenario: inventaris pembelian, hapus inventaris, auto-susut, jurnal biasa) |
| POST | `/api/transaksi/inventaris` | JurnalUmumController | Pembelian inventaris (insert `transactions` + `inventories`) |
| POST | `/api/transaksi/inventaris/{inventory}/hapus` | JurnalUmumController | Hapus/jual/revaluasi/rusak/hilang inventaris |

**Query `GET /api/transaksi/jurnal-umum/form`:**
- `tgl_transaksi` (required, date)
- `jenis_transaksi` (required, integer)
- `sumber_dana` (required, kode akun)
- `disimpan_ke` (required, kode akun)

**Response skenario (berdasarkan kombinasi sumber_dana + disimpan_ke + jenis_transaksi):**
- Pembelian (disimpan_ke di `1.2.01.*` atau `1.2.03.*`):
  ```json
  {
    "success": true,
    "data": {
      "form_type": "inventaris",
      "fields": ["nama_barang", "jumlah", "harga_satuan", "umur_ekonomis", "relasi"]
    }
  }
  ```
- Hapus/Jual/Revaluasi (sumber_dana `1.2.01.*`/`1.2.02.*` + disimpan_ke `5.3.02.01` + jenis_transaksi=2):
  ```json
  {
    "success": true,
    "data": {
      "form_type": "hapus_inventaris",
      "fields": ["inventory_id", "alasan", "unit", "harsat", "harga_jual"],
      "inventaris_list": [
        {"id": 12, "nama_barang": "Laptop", "unit": 3, "harsat": 10000000, "nilai_buku": 8500000}
      ]
    }
  }
  ```
- Auto-susut (disimpan_ke di `5.1.07.08/09/10`):
  ```json
  {
    "success": true,
    "data": {
      "form_type": "nominal",
      "fields": ["keterangan", "nominal", "relasi"],
      "prefill": {"nominal": 250000}
    }
  }
  ```
- Default:
  ```json
  {
    "success": true,
    "data": {
      "form_type": "nominal",
      "fields": ["keterangan", "nominal", "relasi"]
    }
  }
  ```

**Body `POST /api/transaksi/inventaris` (pembelian):**
```json
{
  "tgl_transaksi": "2026-07-01",
  "jenis_transaksi": 1,
  "sumber_dana": "1.1.01.01",
  "disimpan_ke": "1.2.01.04",
  "nama_barang": "Laptop Asus",
  "jumlah": 3,
  "harga_satuan": 10000000,
  "umur_ekonomis": 48,
  "relasi": "Toko ABC"
}
```
Response:
```json
{
  "success": true,
  "message": "Pembelian inventaris berhasil disimpan.",
  "data": {"transaksi_id": 1245, "inventory_id": 28, "total": 30000000}
}
```

**Body `POST /api/transaksi/inventaris/{id}/hapus`:**
```json
{
  "tgl_transaksi": "2026-07-15",
  "sumber_dana": "1.2.01.04",
  "disimpan_ke": "5.3.02.01",
  "alasan": "dijual",
  "unit": 1,
  "harsat": 1000000,
  "harga_jual": 800000
}
```
`alasan` ∈ `['hapus', 'dijual', 'revaluasi', 'rusak', 'hilang']`. Response:
```json
{
  "success": true,
  "message": "Penjualan 1 unit Laptop Asus",
  "data": {"transaksi_ids": [1246, 1247], "inventory_id": 28, "sisa_unit": 2}
}
```

#### Pelaporan
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/pelaporan` | PelaporanController | Daftar jenis laporan |
| GET | `/api/pelaporan/sub-laporan/{file}` | PelaporanController | Daftar sub laporan |
| POST | `/api/pelaporan/preview` | PelaporanController | Preview PDF (stub) |
| POST | `/api/pelaporan/excel` | PelaporanController | Ekspor Excel (stub) |
| POST | `/api/pelaporan/simpan-saldo` | PelaporanController | Rekalibrasi amount 1-12 (delegasi ke `GenerateAmountController::generate`) |

**Body `POST /api/pelaporan/simpan-saldo`:**
```json
{
  "tahun": 2026,
  "bulan": "05"
}
```
- `bulan` opsional: kosong → rekalk semua bulan 1-12 untuk tahun tsb; `01`-`12` → rekalk bulan tertentu.

Response:
```json
{
  "success": true,
  "message": "Saldo periode berhasil direkalibrasi.",
  "data": {"tahun": 2026, "bulan": ["05"], "jumlah_rekord": 5}
}
```

#### Generate Piutang (Manual Trigger)

| Method | Endpoint | Auth | Controller | Respons |
|--------|----------|------|------------|---------|
| POST | `/api/piutang/generate` | admin | PiutangController | `{ success, tanggal, force, duration_ms, output }` |

**Body (semua field opsional):**
```json
{
  "tanggal": "2026-07-25",
  "force": false
}
```
- `tanggal` opsional, default `hari ini` (`Y-m-d`). Dipakai sebagai `tgl_transaksi` jurnal & acuan `due_date`.
- `force` opsional, default `false`. Jika `true`, hapus jurnal tunggakan lama untuk bill yang sama (`reverence_type='overdue_bill'`, `reverence_id=`) sebelum insert baru. Default idempotent: skip bill yang sudah punya jurnal.

**Logika:**
- Loop `MonthlyBill` dengan `status='unpaid'` dan `due_date <= tanggal`. Ambang pengakuan piutang = **tanggal generate itu sendiri** (`BillingTolerance::piutangBerlakuPada()`), inklusif: tagihan yang jatuh tempo tepat pada tanggal generate ikut dibukukan. Dulu dipakai `tanggal - graceDays()` hari, tetapi `graceDays()` membaca angka tanggal SOP (1–31) sebagai jumlah hari sehingga ambang selalu jatuh di akhir bulan sebelumnya dan tagihan periode berjalan terlambat satu bulan; accessor itu sudah dihapus. Aturan ini sama dengan APK lama (`$usage->tgl_akhir <= $date`).
- Definisi "menunggak" untuk suspend/Arsip tetap `due_date < hari ini`, jadi tagihan yang jatuh tempo hari ini sudah dicatat sebagai piutang tetapi belum masuk daftar menunggak.
- Untuk tiap bill:
  - Insert 1 baris jurnal ke `transactions` (`account_debet='1.1.03.01'` Piutang, `account_kredit='4.1.01.02'` Abodemen, `saldo = bill.abodemen`) jika `bill.abodemen > 0`.
  - Insert 1 baris jurnal (`account_kredit='4.1.01.03'` Pemakaian, `saldo = bill.usage_charge`) jika `bill.usage_charge > 0`.
  - `reverence_type='overdue_bill'`, `reverence_id=bill.id`, `id_user=admin pertama`, `keterangan_transaksi` prefix `Tunggakan Abodemen -` / `Tunggakan Pemakaian -`.
- Trigger DB MySQL auto-update `amount` per insert (lihat `database/migrations_tenant/2026_06_23_034006_create_amount_table.php`), jadi **tidak ada recalc manual** di endpoint ini.
- Notifikasi dashboard disimpan ke `Cache::put('overdue_gen_notification', ..., 7 hari)`.

**Response sukses (200):**
```json
{
  "success": true,
  "tanggal": "2026-07-25",
  "force": false,
  "duration_ms": 432,
  "output": "Berhasil memproses 12 tagihan menunggak (skip 3)."
}
```

**Response sukses tanpa tagihan menunggak:**
```json
{
  "success": true,
  "tanggal": "2026-07-25",
  "force": false,
  "duration_ms": 12,
  "output": "Tidak ada tagihan menunggak."
}
```

**Response gagal validasi (422):**
```json
{
  "success": false,
  "errors": { "tanggal": ["The tanggal does not match the format Y-m-d."] }
}
```

**Response gagal proses (200, success=false):** Terjadi jika `User::where('role','admin')->first()` null atau exception di loop. Output berisi pesan error.

**Scheduler otomatis:** `routes/console.php:11` → `Schedule::command('billing:generate-overdue-transactions')->dailyAt('00:01')`. Pastikan cron `* * * * * php artisan schedule:run` aktif di server.

**Command Artisan setara:**
```bash
php artisan billing:generate-overdue-transactions
php artisan billing:generate-overdue-transactions --tanggal=2026-07-25
php artisan billing:generate-overdue-transactions --force
```

**Catatan migrasi/config:** tidak ada migration baru untuk endpoint ini. Toleransi dibaca lewat `App\Services\BillingTolerance` dari `settings.batas_tagihan` (dengan fallback ke `settings.toleransi_tunggakan` kalau `batas_tagihan` belum diisi), dan kedua kolom itu disamakan nilainya oleh migration tenant `2026_10_31_000002_sync_toleransi_tunggakan_with_batas_tagihan`.

#### Pengaturan/SOP
| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/settings/sop` | SopController | Pengaturan SOP |
| POST | `/api/settings/sop/lembaga` | SopController | Perbarui lembaga |
| POST | `/api/settings/sop/pasang-baru` | SopController | Perbarui pasang baru |
| POST | `/api/settings/sop/sistem-tagihan` | SopController | Perbarui sistem tagihan |
| POST | `/api/settings/sop/pemakaian-air` | SopController | Perbarui pengaturan pemakaian |
| POST | `/api/settings/sop/logo` | SopController | Perbarui logo |
| POST | `/api/settings/sop/whatsapp` | SopController | Perbarui whatsapp |

**Sistem Tagihan (`toleransiTunggakan`, `batasTagihan`)**

Dua angka, dua satuan, dua kolom:

| Field API | Kolom `settings` | Satuan | Arti |
|---|---|---|---|
| `toleransiTunggakan` | `toleransi_tunggakan` | TANGGAL (1-31) | Tanggal akhir bayar / jatuh tempo (TGL AKHIR BAYAR). Jatuh tempo dihitung pada bulan pencatatan meter: pencatatan s.d. tanggal ini = bulan itu; lewat tanggal ini = bulan berikutnya. |
| `batasTagihan` | `batas_tagihan` | JUMLAH BULAN (0-24) | Batas tunggakan sebelum sambungan pelanggan di-`suspended`. `0` = tidak pernah memutus otomatis. |

> `batasBulanTunggakan` masih diterima/dikirim sebagai alias lama untuk
> `batasTagihan`. Tidak ada lagi kolom `billing_unpaid_limit`; migration
> tenant `2026_11_09_000001_align_billing_cycle_settings` memindahkan
> nilainya ke `batas_tagihan`, lalu menghapus kolomnya.
>
> Jatuh tempo memakai bulan PENCATATAN meter, bukan bulan pemakaian.
> Contoh toleransi 26: pencatatan 2 Nov 2026 -> 26 Nov 2026; pencatatan
> 29 Jan 2026 -> 26 Feb 2026. `meter_readings.recorded_at` menyimpan waktu
> input sebenarnya, bukan tanggal akhir periode.
>
> Kalau `toleransi_tunggakan` belum diisi, generate/edit tagihan ditolak;
> tidak ada fallback angka 27 yang tersembunyi. Default lokasi baru:
> `toleransi_tunggakan = 10`, `batas_tagihan = 3`.

**Response `data.sistemTagihan` dari GET `/api/settings/sop`:**
```json
{
    "success": true,
    "data": {
        "sistemTagihan": {
            "batasTagihan": 3,
            "toleransiTunggakan": 27,
            "batasBulanTunggakan": 3
        }
    }
}
```

> `batasTagihan` berisi JUMLAH BULAN, bukan tanggal. `batasBulanTunggakan`
> adalah alias lama dengan nilai yang sama, dipertahankan supaya consumer
> lama tetap jalan.

**Request POST `/api/settings/sop/sistem-tagihan`:**
```json
{
    "toleransiTunggakan": 27,
    "batasTagihan": 3
}
```

> `toleransiTunggakan` WAJIB diisi, rentang 1-31. `batasTagihan` WAJIB
> diisi, rentang 0-24. `batasBulanTunggakan` masih diterima sebagai alias
> lama kalau `batasTagihan` tidak dikirim.
>
> Keduanya **tidak** harus sama: `toleransiTunggakan` adalah tanggal,
> sedangkan `batasTagihan` adalah jumlah bulan.
>
> Yang dibandingkan untuk auto-suspend adalah **jumlah bulan** sejak
> tagihan paling lama unpaid yang jatuh temponya sudah lewat
> (`App\Services\UnpaidMonths`), **bukan jumlah tagihan**. Pemutusannya
> tetap di `MeterReadingController::store` (saat cater/teknisi mencatat
> pemakaian) — tidak ada pemicu baru.

**Pengaturan pemakaian (`autoOpenMeterCamera`)**

Aturan ini mengatur apakah kamera scan langsung terbuka begitu popup "Input
Pemakaian" muncul. Yang WAJIB diisi di popup itu tetap hanya angka meter
akhir -- foto meteran dan hasil scan OCR sama-sama opsional, dan tombol scan
tetap bisa dipakai kapan saja. Setting ini hanya mengatur KAPAN kamera dibuka
otomatis, bukan apakah fitur scan boleh dipakai.

| Method | Endpoint | Role | Keterangan |
|---|---|---|---|
| GET | `/api/settings/pemakaian-air` | Semua role login | Baca pengaturan pemakaian |
| POST | `/api/settings/sop/pemakaian-air` | Admin | Simpan pengaturan pemakaian |

**Response GET `/api/settings/pemakaian-air`:**
```json
{
    "success": true,
    "data": {
        "autoOpenMeterCamera": false
    }
}
```

**Request POST `/api/settings/sop/pemakaian-air`:**
```json
{
    "autoOpenMeterCamera": true
}
```

> `GET /api/settings/pemakaian-air` sengaja TERPISA dari `GET /api/settings/sop`.
> Endpoint `settings/sop` itu `role:admin`, sementara popup input meter dibaca
> TEKNISI juga. Kalau flag ini hanya hidup di sana, teknisi selalu kena 403 --
> dan gejalanya bukan "403", melainkan kamera yang diam-diam tidak pernah
> terbuka.
>
> Kolomnya nullable: `NULL` = belum pernah diisi (bawaan: kamera TIDAK dibuka
> otomatis), `false` = admin sengaja mematikan. Keduanya sengaja tidak
> digabung supaya "belum pernah disentuh" dan "sengaja dimatikan" masih bisa
> dibedakan.


---

### RUTE PELANGGAN

| Method | Endpoint | Controller | Respons |
|--------|----------|------------|---------|
| GET | `/api/test-pelanggan` | Closure | `{message: "Kamu pelanggan!"}` |
| GET | `/api/pelanggan/dashboard` | PelangganPortalController | Data dashboard |
| GET | `/api/pelanggan/bill-detail/{id?}` | PelangganPortalController | Detail tagihan |
| GET | `/api/pelanggan/bill-history` | PelangganPortalController | Riwayat tagihan |
| GET | `/api/pelanggan/profile` | PelangganPortalController | Profil pelanggan |
| POST | `/api/pelanggan/trouble-report` | TroubleReportController | Kirim laporan gangguan |

---

## 2. Checklist Tabel yang Belum Diimplementasi

### TABEL DENGAN MODEL TAPI TANPA CONTROLLER

- [x] **`accounts`** - Bagan akun (kode_akun, nama_akun, hierarki level)
  - Model: `Account.php` (relasi ke AkunLevel1/2/3)
  - Digunakan oleh: `TransactionController`, `PelaporanController`, `GenerateAmountController`
  - Belum ada controller CRUD

- [ ] **`amount`** - Saldo akun terhitung per periode (tahun, bulan, debit, kredit)
  - Tidak ada file model
  - Digunakan oleh: `GenerateAmountController` (akses langsung via DB::table)
  - DB trigger mengisi otomatis dari `transactions`

### TABEL TANPA MODEL DAN TANPA CONTROLLER

- [x] **`akun_level_1`** - Akun level 1 (lev1, kode_akun, nama_akun, jenis_mutasi)
  - Model: `AkunLevel1.php` (relasi: hasMany AkunLevel2, accounts)
  - Controller: - (belum ada)

- [x] **`akun_level_2`** - Akun level 2 (parent_id, lev1-4, kode_akun, nama_akun, jenis_mutasi)
  - Model: `AkunLevel2.php` (relasi: belongsTo AkunLevel1, hasMany AkunLevel3, accounts)
  - Controller: - (belum ada)

- [x] **`akun_level_3`** - Akun level 3 (parent_id, lev1-4, kode_akun, nama_akun, posisi, jenis_mutasi)
  - Model: `AkunLevel3.php` (relasi: belongsTo AkunLevel2, hasMany accounts)
  - Controller: - (belum ada)

- [x] **`ebudgeting`** - Entri e-budgeting (account_id, tahun, bulan, jumlah)
  - Model: `Ebudgeting.php` (relasi: belongsTo Account)
  - Controller: `EbudgetingController` (CRUD lengkap + bulkStore)
  - Migration ada: `2026_06_18_020404_create_ebudgeting_table.php`

- [x] **`jenis_transactions`** - Master jenis transaksi (nama_jt)
  - Model: `JenisTransaction.php`
  - Controller: `JenisTransactionController` (CRUD lengkap + search)
  - Migration ada: `2026_06_18_021046_create_jenis_transactions_table.php`

- [x] **`master_arus_kas`** - Master arus kas (nama_akun, debit, kredit, parent_id)
  - Model: `MasterArusKas.php` (relasi: hasMany children, belongsTo parent)
  - Controller: - (belum ada)
  - Migration ada: `2026_06_18_021550_create_master_arus_kas_table.php`

- [x] **`inventories`** - Inventaris barang (nama_barang, tgl_beli, unit, harsat, umur_ekonomis, jenis, kategori, status, tgl_validasi)
  - Model: `Inventory.php` (HasFactory)
  - Controller: `InventoryController` (CRUD lengkap)
  - Service: `InventoryService` (hitungBulan, hitungItemSatuan, hitungPenyusutan)
  - Integrasi jurnal umum: `JurnalUmumController` (form dispatch + 4 skenario: pembelian, hapus, jual, revaluasi, rusak, hilang, auto-susut)
  - Seeder: `InventorySampleSeeder`

### YANG HILANG/TIDAK LENGKAP

- [ ] **`PelaporanController::preview()`** - Mengembalikan view template PDF yang tidak ada
  - `resources/views/pelaporan/pdf_template.blade.php` tidak ditemukan

- [ ] **View Pelaporan** - Direktori `resources/views/pelaporan/` tidak ada
  - Method: `preview()`, `exportExcel()` masih stub
  - Method private: `cover`, `surat_pengantar`, `jurnal_transaksi`, `neraca_saldo`, `neraca`, `laba_rugi`, `e_budgeting`, `tutup_buku_*`, `buku_besar`, `calkk`, `LPM`, `arus_kas`, `ati`, `atb`, `piutang_komisi` - semua implementasi ada di controller (return JSON view_target, FE yang render)
  - `simpanSaldo()` SUDAH diimplementasi (delegasi rekalk amount 1-12)

### TABEL FRAMEWORK LARAVEL (dikelola otomatis)

- [ ] `cache` - Cache Laravel (otomatis)
- [ ] `cache_locks` - Cache locks Laravel (otomatis)
- [ ] `failed_jobs` - Failed jobs Laravel (otomatis)
- [ ] `job_batches` - Job batches Laravel (otomatis)
- [ ] `jobs` - Queue jobs Laravel (otomatis)
- [ ] `migrations` - Tabel migrations Laravel (otomatis)
- [ ] `password_reset_tokens` - Reset kata sandi (otomatis)
- [ ] `personal_access_tokens` - Token Sanctum (otomatis, via model)
- [ ] `sessions` - Sesi Laravel (otomatis)

### TABEL YANG SUDAH DIIMPLEMENTASI PENUH (model + controller + routes)

- [x] `users` - UserController + model User
- [x] `villages` - VillageController + model Village
- [x] `customers` - CustomerController + model Customer
- [x] `installation_packages` - InstallationPackageController + model InstallationPackage
- [x] `water_tariff_blocks` - WaterTariffBlockController + model WaterTariffBlock
- [x] `installation_tickets` - InstallationTicketController + model InstallationTicket
- [x] `payments` - PaymentController + model Payment
- [x] `survey_results` - SurveyResultController + model SurveyResult
- [x] `trouble_reports` - TroubleReportController + model TroubleReport
- [x] `meter_readings` - MeterReadingController + model MeterReading
- [x] `monthly_bills` - MonthlyBillController + model MonthlyBill
- [x] `bill_payments` - model BillPayment (digunakan oleh MonthlyBillController)
- [x] `settings` - SettingController + model Setting
- [x] `transactions` - TransactionController + model Transaction
- [x] `monthly_bills` (generate piutang) - PiutangController (manual trigger endpoint) + GenerateOverdueTransactions (Artisan command + scheduler harian)
- [x] `jenis_transactions` - JenisTransactionController + model JenisTransaction
- [x] `jenis_laporans` - model JenisLaporan (digunakan oleh PelaporanController)
- [x] `sub_laporans` - model SubLaporan (digunakan oleh PelaporanController)
- [x] `akun_level_1` - model AkunLevel1 (relasi: hasMany AkunLevel2, accounts)
- [x] `akun_level_2` - model AkunLevel2 (relasi: belongsTo AkunLevel1, hasMany AkunLevel3, accounts)
- [x] `akun_level_3` - model AkunLevel3 (relasi: belongsTo AkunLevel2, hasMany accounts)
- [x] `ebudgeting` - EbudgetingController + model Ebudgeting
- [x] `master_arus_kas` - model MasterArusKas (relasi: hasMany children, belongsTo parent)
- [x] `inventories` - InventoryController + JurnalUmumController + model Inventory + InventoryService

---

## Ringkasan

| Kategori | Jumlah |
|----------|--------|
| Total tabel di DB | 34 |
| Tabel dengan model | 27 |
| Tabel dengan controller | 22 |
| Tabel dengan rute API | 22 |
| **Tabel tanpa model** | **1** (`amount` — diakses via DB::table) |
| **Tabel tanpa controller/rute** | **5** (`accounts` read-only via `AccountController`, `akun_level_1/2/3`, `master_arus_kas`) |
| **Masalah kritis** | **1** (view `resources/views/pelaporan/pdf_template.blade.php` hilang — `preview()` masih stub) |
| Generate piutang | Scheduler harian `00:01` + endpoint manual `POST /api/piutang/generate` (admin). Command `billing:generate-overdue-transactions` support `--tanggal` dan `--force`. |

---

## 3. Changelog

### v1.5 (2026-11-09) — Dua Kolom SOP: Tanggal Jatuh Tempo dan Batas Bulan

**Baru:**
- `App\Services\BillingCycleSettings` — satu sumber aturan siklus tagihan:
  `dueDay()` + `dueDate()` membaca `settings.toleransi_tunggakan`, sedangkan
  `arrearsLimit()` membaca `settings.batas_tagihan`.
- Migration tenant `2026_11_09_000001_align_billing_cycle_settings` —
  memindahkan nilai `billing_unpaid_limit` ke `batas_tagihan`, menormalkan
  `meter_readings.recorded_at`, menghitung ulang `due_date` tagihan belum
  lunas, lalu menghapus kolom `billing_unpaid_limit`.

**Diubah:**
- Jatuh tempo dihitung dari **bulan pencatatan meter**: pencatatan s.d.
  tanggal `toleransi_tunggakan` jatuh tempo di bulan itu; lewat tanggal itu
  bergeser ke bulan berikutnya.
- `toleransiTunggakan` = TANGGAL (1-31) dan `batasTagihan` = JUMLAH BULAN
  (0-24). Keduanya tidak lagi harus sama; `batasBulanTunggakan` hanya alias.
- `meter_readings.recorded_at` diisi waktu input sebenarnya, bukan tanggal
  akhir periode.
- Tidak ada fallback angka 27 di backend/frontend. Kalau SOP belum diisi,
  generate/edit tagihan ditolak atau menampilkan `-`; kalau `batasTagihan`
  = 0, auto-suspend dimatikan.
- Frontend menampilkan `monthly_bills.due_date` apa adanya (tidak dikurangi
  satu hari).

**Perbaikan:**
- Tagihan lama dan baru memakai arti kolom yang sama, sehingga tidak ada
  lagi dua jatuh tempo berbeda untuk bulan tagihan yang sama.
- Previews/payment memakai `due_date` tersimpan; report dan halaman cetak
  tidak lagi menanam angka 26/27 di kode.

**Test regresi:**
- `BillingDueDateTest`, `BillingToleranceTest`, `UpdatePemakaianAirTest`,
  dan test billing lain memakai SOP dua kolom.

### v1.4 (2026-11-04) — Perbaiki Pengakuan Piutang Bulan Berjalan

**Diubah:**
- `billing:generate-overdue-transactions` / `POST /api/piutang/generate` — ambang pengakuan piutang sekarang **tanggal generate itu sendiri**, dengan perbandingan **inklusif** (`due_date <= tanggal`). Sebelumnya `due_date < (tanggal - graceDays())`.
- `App\Services\BillingTolerance` — `graceDays()` **dihapus** (unitnya keliru: angka tanggal 1-31 dibaca sebagai jumlah hari). Penggantinya `piutangBerlakuPada(?Carbon $tanggalGenerate = null): string`, yang mengembalikan tanggal acuan dalam format `Y-m-d`.
- Ringkasan run (`total_overdue` pada cache `overdue_gen_summary` dan fallback `DashboardController`) memakai `due_date <= hari ini`, konsisten dengan himpunan yang diproses.

**Perbaikan:**
- Tagihan periode M (jatuh tempo bulan M+1) kini dicatat pada generate bulan M+1, bukan M+2. Generate tidak lagi "kosong" untuk tagihan yang jatuh tempo bulan berjalan.
- Paritas dengan APK lama: sana piutang diakui saat `$usage->tgl_akhir <= $date`.

**Catatan:**
- Tidak ada perubahan skema dan tidak ada migration baru.
- Jurnal lama tidak diubah/dihapus (tidak ada hard delete).
- Definisi "menunggak" untuk suspend (`UnpaidMonths`) dan Arsip Piutang tetap `due_date < hari ini` — sengaja berbeda satu hari dari ambang pengakuan piutang.
- Test regresi: `backend/tests/Feature/AkurasiPiutangPerBulanTest.php`.

### v1.3 (2026-10-31) — Gabung Dua Field Tanggal SOP (Toleransi Menunggak)

**Baru:**
- Migration tenant `2026_10_31_000002_sync_toleransi_tunggakan_with_batas_tagihan` — menyamakan `settings.toleransi_tunggakan` dengan `settings.batas_tagihan` (idempoten). `batas_tagihan` sendiri tidak pernah disentuh. Baris dengan `batas_tagihan = 0` ("belum diisi") dibiarkan apa adanya.

**Diubah:**
- `POST /api/settings/sop/sistem-tagihan` — field tanggal jadi SATU: `toleransiTunggakan` (wajib, 1-31). `batasTagihan` diterima sebagai alias legacy opsional, dan kalau dikirim nilainya harus sama dengan `toleransiTunggakan` (selain itu ditolak `422`). `batasBulanTunggakan` tetap wajib (0-24). Penyimpanan menulis kedua kolom `settings` dengan angka yang sama.
- `GET /api/settings/sop` — `data.sistemTagihan` mengirim `batasTagihan` dan `toleransiTunggakan` dengan nilai yang sama, supaya consumer lama tidak perlu diubah.
- `App\Services\BillingTolerance` — `legacy()` diganti jadi `tanggal()`. Sekarang membaca `settings.batas_tagihan` (sumber tunggal), dengan fallback ke `settings.toleransi_tunggakan` kalau `batas_tagihan` belum berisi tanggal yang sah (0 / di luar 1-31), supaya baris lama tidak kehilangan jadwal generate. `generateDay()`, `graceDays()`, dan `advanceDayOfMonth()` semuanya memakai angka itu. (`graceDays()` kemudian dihapus di v1.4 — lihat di atas.)
- `App\Services\TenantManager` dan `SettingsSeeder` — baris baru ditulis dengan kedua kolom tanggal bernilai sama (10).

**Perbaikan:**
- Bug advance payment: ambang advance dibaca dari `toleransi_tunggakan` (30) sementara `due_date` dihitung dari `batas_tagihan` (27). Pembayaran pada tanggal 28-30 salah flagged `ADVANCE` padahal tagihannya belum jatuh tempo. Sekarang ambang advance = tanggal jatuh tempo.

**Catatan migrasi:**
- Tidak ada perubahan skema. Migration di atas hanya menyamakan nilai data.
- `settings.toleransi_tunggakan` TIDAK dihapus — kolom ini masih dibaca import legacy dan dilacak lewat banyak query lama.

### v1.2 (2026-07-25) — Generate Piutang API

**Baru:**
- `POST /api/piutang/generate` — Trigger manual generate jurnal piutang untuk tagihan menunggak (admin only). Delegasi ke `Artisan::call('billing:generate-overdue-transactions')`.
- Controller baru: `app/Http/Controllers/PiutangController.php`

**Diubah:**
- `php artisan billing:generate-overdue-transactions` — Tambah opsi `--tanggal=Y-m-d` (default hari ini) dan `--force` (hapus jurnal lama sebelum insert). Hormati `settings.toleransi_tunggakan` untuk threshold `due_date`. Return `FAILURE` bukan silent exit saat `User admin` tidak ditemukan.
- Idempotency: default skip bill yang sudah punya jurnal `overdue_bill`; pakai `--force` / `force=true` untuk replace.

**Catatan migrasi:**
- Tidak ada migration baru
- Trigger DB `amount` (existing) sudah handle update saldo otomatis per insert jurnal — endpoint ini tidak panggil recalc manual

### v1.1 (2026-07-24) — Fitur Tutup Buku, Simpan Saldo, Inventaris Jurnal Umum

**Baru:**
- `GET /api/transaksi/jurnal-umum/form` — Form dispatch polymorphic (4 skenario: inventaris/hapus/auto-susut/nominal)
- `POST /api/transaksi/inventaris` — Pembelian inventaris (insert `transactions` + `inventories`)
- `POST /api/transaksi/inventaris/{id}/hapus` — Hapus/jual/revaluasi/rusak/hilang
- `GET /api/inventaris` — List inventaris (filter: kategori, jenis, status, q, tgl_dari, tgl_sampai, per_page)
- `GET /api/inventaris/{id}` — Detail inventaris
- `POST /api/inventaris` — Buat inventaris
- `PUT /api/inventaris/{id}` — Perbarui inventaris
- `DELETE /api/inventaris/{id}` — Hapus inventaris

**Diubah:**
- `POST /api/pelaporan/simpan-saldo` — **Tidak lagi stub**. Sekarang delegasi rekalk `amount` bulan 1-12 untuk tahun tertentu (single bulan atau semua bulan). Body: `{tahun, bulan?}`. Response: `{success, message, data: {tahun, bulan, jumlah_rekord}}`

**Catatan migrasi:**
- Tidak ada migration baru
- `Inventory` model tambah `HasFactory` trait
- `phpunit.xml` set `DB_DATABASE=ASTALOKA` (sebelumnya `ASTALOKA_test`) — karena user DB `ASTALOKA` tidak punya hak GRANT ke DB baru. Test sekarang pakai DB dev yang sama dengan `migrate:fresh` per test class.
