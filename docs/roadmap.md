# Roadmap ASTALOKA

Dokumen ini adalah **peta lengkap** proyek ASTALOKA: apa yang sudah ada, bagaimana
aturannya bekerja sampai ke perhitungannya, dan apa yang masih perlu dikerjakan.

> **Dokumen ini menggantikan roadmap lama.** Versi sebelumnya masih menggambarkan
> aplikasi single-tenant tanpa panel pusat, tanpa akuntansi, tanpa multi-database —
> padahal kode yang ada sekarang sudah jauh melampaui itu. Roadmap lama hanya
> relevan sebagai catatan urutan pengerjaan awal.
>
> Status per bab: ✅ selesai · 🟡 berjalan / belum tuntas · ⬜ belum ada

---

## Daftar Isi

1. [Ringkasan Produk](#1-ringkasan-produk)
2. [Arsitektur](#2-arsitektur)
3. [Multi-Tenancy & Panel Pusat](#3-multi-tenancy--panel-pusat)
4. [Sistem Role, Jabatan & Hak Akses](#4-sistem-role-jabatan--hak-akses)
5. [Master Data](#5-master-data)
6. [Alur Instalasi (Tiket)](#6-alur-instalasi-tiket)
7. [Pencatatan Meter](#7-pencatatan-meter)
8. [⭐ Engine Tagihan Bulanan](#8--engine-tagihan-bulanan)
9. [⭐ Pengakuan Piutang Tunggakan](#9--pengakuan-piutang-tunggakan)
10. [⭐ Jurnal Pembayaran](#10--jurnal-pembayaran)
11. [⭐ Akuntansi: Amount, Tutup Buku, Alokasi Laba](#11--akuntansi-amount-tutup-buku-alokasi-laba)
12. [Pelaporan](#12-pelaporan)
13. [Arsip](#13-arsip)
14. [Pengaturan (SOP)](#14-pengaturan-sop)
15. [Frontend](#15-frontend)
16. [Infrastruktur, Testing & CI](#16-infrastruktur-testing--ci)
17. [Risiko & Utang Teknis Tertuang](#17-risiko--utang-teknis-tertuang)
18. [Peta File Referensi](#18-peta-file-referensi)

---

## 1. Ringkasan Produk

ASTALOKA adalah **platform pengelolaan_air bersih untuk BUMDes**. Satu aplikasi
melayani banyak BUMDes sekaligus; setiap BUMDes ("lokasi") punya basis data,
merek, akun kas, dan aturan tagihannya sendiri.

Tiga karakteristik yang membedakan produk ini dari aplikasi single-tenant biasa:

| Karakteristik | Arti |
|---|---|
| **Multi-tenant berbasis database** | Satu database MySQL per lokasi (`astaloka_<slug>`), bukan sekadar filter `tenant_id` |
| **Panel pusat (ASTA BRATA)** | Pusat menjual langganan ke lokasi; lokasi yang tagihannya belum lunas **dikunci** |
| **Akuntansi terintegrasi** | Tagihan air otomatis menjadi jurnal piutang, kas, dan pendapatan |

### 1.1 Modul yang sudah ada

| Modul | Status | Catatan |
|---|---|---|
| Autentikasi (Sanctum, token 8 jam) | ✅ | 4 role lokasi + 1 role pusat |
| Multi-tenancy & provisioning lokasi | ✅ |buat DB, migrasi, seed, demo user, branding, domain |
| Panel pusat | ✅ | Dashboard, lokasi, domain, CoA, superadmin, tagihan langganan, migrasi data |
| Master paket & blok tarif | ✅ | Validasi overlap |
| Alur instalasi 8 status | ✅ | State machine + riwayat perubahan paket |
| Survey lapangan (surveyor) | ✅ | Foto + jarak + material |
| Hasil instalasi (teknisi) | ✅ | Meter awal + foto meter |
| Pencatatan meter bulanan | ✅ | Validasi monoton + OCR opsional + suspend otomatis |
| Engine tagihan bulanan | ✅ | Tarif FLAT + abodemen + denda beku |
| Pengakuan piutang tunggakan | ✅ | Jurnal per komponen, idempoten |
| Jurnal pembayaran | ✅ | 3 baris per tagihan, reclass ADVANCE |
| Jurnal Umum, E-Budgeting, Inventaris | ✅ | |
| Tutup Buku (bulan 13/00) | ✅ | |
| Alokasi Laba | ✅ | Default 50/50 |
| Komisi SPS | ✅ | 10% |
| Pelaporan (18 jenis laporan + 10 sub-laporan) | ✅ | Preview + ekspor PDF/Excel |
| Arsip (4 halaman) | ✅ | |
| Portal pelanggan | ✅ | Tagihan, riwayat, lapor gangguan |
| Pengaturan SOP | ✅ | Identitas, sistem tagihan, CALK, logo, WA, tanda tangan |
| Impor data Excel | ✅ | 5 jenis data, validasi + commit |
| Import data legacy | ✅ | 20 command `legacy:*` + 11 command `import:*` |
| Pengerasan hak akses per jabatan | ✅ | Dikonfigurasi dari panel pusat |
| **Manajemen pengguna (UI)** | ⬜ | API ada (`apiResource users`), **halaman UI belum ada** |
| **Pembayaran tagihan dari portal pelanggan** | ⬜ | Pelanggan bisa lihat, tidak bisa bayar |
| **Notifikasi WhatsApp otomatis** | ⬜ | Ada pengaturan pesan, tidak ada pengirim |
| **Reversal / koreksi jurnal** | ⬜ | Tidak ada jejak audit untuk koreksi |
| **Wilayah 4 tingkat (Kecamatan/Desa)** | 🟡 | Desa ada, Kecamatan hardcoded di dropdown |
| **Test frontend untuk service/composable** | 🟡 | 26 file test, mostly util |

### 1.2 Stack teknologi

**Backend** — Laravel 11/12, PHP 8.3+, MySQL 8, Sanctum, Pest/PHPUnit
**Frontend** — Vue 3.5 (`<script setup>`), Vue Router 5, Pinia 3, PrimeVue 4 (Aura), Tailwind CSS 4, Vite 7
**Tooling** — Pint, ESLint, oxlint, Prettier, Husky + lint-staged

---

## 2. Arsitektur

```
┌──────────────────────────────────────────────────────────────────┐
│                        BROWSER                                    │
│   Panel Pusat (astaloka.test)      Aplikasi Lokasi (demo.astaloka.test) │
└───────────────┬──────────────────────────────┬──────────────────┘
                │                              │
        ┌───────▼────────┐            ┌────────▼─────────┐
        │  Vue SPA       │            │   Vue SPA        │
        │  /central/*    │            │   /app/*         │
        └───────┬────────┘            └────────┬─────────┘
                │                              │
        ┌───────▼──────────────────────────────▼─────────┐
        │              Laravel API (backend)               │
        │  ┌────────────────────────────────────────────┐  │
        │  │ ResolveTenant (prepend, SEBELUM auth)      │  │
        │  │   host → tenant, atau header X-Tenant-Slug │  │
        │  ├────────────────────────────────────────────┤  │
        │  │ auth:sanctum  →  CheckRole                 │  │
        │  │ EnforceInvoiceStatus → EnforceMenuAccess   │  │
        │  │              (append, SETELAH auth)         │  │
        │  └────────────────────────────────────────────┘  │
        └────────┬───────────────────────────┬────────────┘
                 │                           │
        ┌────────▼─────────┐       ┌─────────▼──────────────┐
        │  Database PUSAT   │       │  Database LOKASI        │
        │  `astaloka`       │       │  `astaloka_<slug>`      │
        │  · tenants        │       │  · users, tickets,      │
        │  · tenant_invoices│       │    customers, bills,     │
        │  · akun_level_1/2/3│     │    transactions, amount │
        │  · tenant_domains │       │  · accounts (level 4)   │
        │  · central_accounts│      │  · settings (SOP)       │
        │  · audit_logs     │       │                         │
        └───────────────────┘       └────────────────────────┘
```

### 2.1 Prinsip arsitektur yang harus dijaga

| Prinsip | Kenapa | File yang menjaganya |
|---|---|---|
| **Pusat & lokasi pisah total** | Skema lokasi tidak boleh bocor ke pusat | `database/migrations/` sengaja **kosong** |
| **Isolasi lewat `database.default`** | ~800 file model tidak menyebut nama koneksi | `TenantManager::activate()` |
| **Rumus tagihan hanya di satu tempat** | 3 implementasi berbeda = tagihan berbeda antar lokasi | `BillingService`, `BillingDueDate` |
| **Menu dari server, bukan hardcoded** | Backend memblokir endpoint, frontend menyembunyikan menu | `config/menu_access.php` |
| **Semua mutasi besar dalam `DB::transaction()`** | Parseparsial = saldo tidak sinkron | `MonthlyBillController::pay()` |

> [!IMPORTANT]
> **Isolasi tenansi bukan lewat `tenant_id`.** `TenantManager` menukar
> `Config::set('database.default', 'tenant')`. Kalau nanti ada model baru, **jangan**
> menambah kolom `tenant_id` — itu akan membuat model yang bisa bocor ke lokasi lain.

---

## 3. Multi-Tenancy & Panel Pusat

### 3.1 Konsep database

```
astaloka              → database PUSAT (nama polos, tanpa underscore di belakang)
astaloka_lokasi       → database LOKASI untuk slug "lokasi"
astaloka_pamsimas     → database LOKASI untuk slug "pamsimas"
```

Aturan penamaan ada di **satu tempat saja**: `TenantManager::databaseNameFor()`.

```php
// backend/app/Services/TenantManager.php:239-266
public static function databaseNameFor(string $slug): string
{
    $prefix = (string) config('tenancy.database_prefix', 'astaloka_');
    $batas = (int) config('tenancy.max_database_name_length', 64);

    $slugUntukDb = preg_replace('/[^a-z0-9_]/', '_', strtolower($slug)) ?? '';

    if ($slugUntukDb === '' || trim($slugUntukDb, '_') === '') {
        throw new RuntimeException("Slug `{$slug}` tidak menghasilkan nama database yang valid.");
    }

    return rtrim(substr($prefix.$slugUntukDb, 0, $batas), '_');
}
```

Konsekuensi yang **wajib diketahui**:

- Panjang nama database **dipotong 64 karakter** → slug panjang bisa berbentrok.
- `rtrim(..., '_')` dijalankan **setelah** `substr` → nama bisa berakhiran `_` yang dihapus.
- **Hyphen dan underscore dianggap slug yang sama**: `tirto-mulo` dan `tirto_mulo`
  menghasilkan nama database **identik** (`astaloka_tirto_mulo`), tapi lolos unique
  index `tenants.slug`. Karena itu `LocationController` juga mengecek nama database
  yang sudah ada di MySQL tapi belum terdaftar.
- Nama database harus cocok `/^[A-Za-z0-9_]+$/` — `CREATE DATABASE` tidak bisa
  diparameterisasi, dan satu backtick cukup untuk mengubah statement.

### 3.2 Resolusi tenant

`TenantResolver` mencoba **dua sumber, berurutan dari yang paling kuat**:

| Urutan | Sumber | Contoh | Kapan dipakai |
|---|---|---|---|
| 1 | **Host** | `demo.astaloka.test` | Produksi (reverse proxy) |
| 2 | **Slug** | Header `X-Tenant-Slug: demo` | Development lokal (`localhost:5173`) |

Pencocokan host mencoba tiga sumber berurutan:

1. `tenant_domains.host` persis, dengan `is_active = true` di **kedua** tabel
   (`tenant_domains` dan `tenants`).
2. Baris **wildcard** (`is_wildcard = true`) —suffix `.astaloka.test`.
3. Kolom legacy `tenants.domain` / `tenants.domain_alternatif`.

Aturan wildcard (`TenantDomain::matches()`):

- Harus menjulurkan **tepat satu label**: `demo.astaloka.test` ✅, `a.b.astaloka.test` ❌
- **Tidak boleh cocok dengan apex-nya sendiri**: `astaloka.test` ❌ — kalau iya, wildcard
  diam-diam mengambil alih domain pusat.

> [!WARNING]
> **Slug hanya dipercaya di luar produksi.** `TenantResolver::slugAllowed()` mengembalikan
> `false` di environment `production`, apa pun nilai `TENANCY_LOOKUP`. Kalau tidak,
> siapa saja bisa menambah header `X-Tenant-Slug` dan menebak slug lokasi lain.
>
> Pola slug dibatasi `/^[a-z0-9][a-z0-9-]{0,63}$/` sebelum masuk query.

### 3.3 Provisioning lokasi

`POST /api/central/locations` menjalankan **urutan wajib** ini:

| # | Langkah | Kode |
|---|---|---|
| 1 | Validasi slug (regex, unique, bukan reserved) | `LocationController::store()` |
| 2 | Hitung nama database | `TenantManager::databaseNameFor()` |
| 3 | Cek nama sudah dipakai lokasi lain | `Tenant::where('database', ...)` |
| 4 | Cek nama sudah ada di MySQL tapi tidak terdaftar | `TenantManager::databaseExists()` |
| 5 | **Buat database** (`utf8mb4/utf8mb4_unicode_ci`) | `TenantManager::createDatabase()` |
| 6 | Simpan baris `tenants` | `Tenant::create()` |
| 7 | **Migrasi skema lokasi** | `migrate --path=database/migrations_tenant --force` |
| 8 | **Seed master** (CoA level 4, jabatan, jenis transaksi, arus kas) | `db:seed --class=...` per seeder |
| 9 | Sinkronkan domain (`tenant_domains` + kolom legacy) | `syncLegacyDomain()` |
| 10 | Buat admin lokasi (opsional) | `createLocationAdmin()` |
| 11 | Buat 4 akun demo | `TenantManager::seedDemoUsers()` |
| 12 | Tulis identitas lokasi ke `settings` | `applyTenantIdentity()` |
| 13 | Audit log | `AuditLogger::record('tenant.create')` |

**Provisioning tidak bisa dimatikan.** `provision: false` ditolak dengan 422:

> *"Penyiapan database lokasi tidak bisa dimatikan. Lokasi tanpa skema tidak bisa dipakai."*

**Rollback**: kalau langkah mana pun gagal, baris `tenants` dihapus **dan** database
di-drop._drop dilakukan **di luar** blok `if ($tenant)` — karena `createDatabase()`
sudah selesai, jadi database benar-benar sudah berdiri; membiarkannya akan
meninggalkan **database yatim** (tidak terlihat di panel, tidak tercatat di registry,
~100 MB per lokasi gagal).

### 3.4 Seed master per lokasi

```php
// backend/config/tenancy.php
'provision' => [
    'central_seeders' => [
        AkunLevel1Seeder::class,   // → database PUSAT
        AkunLevel2Seeder::class,   // → database PUSAT
        AkunLevel3Seeder::class,   // → database PUSAT
        CentralAccountSeeder::class,
    ],
    'seeders' => [                // → database LOKASI
        JabatanSeeder::class,
        AccountsTableSeeder::class,
        JenisTransactionsSeeder::class,
        MasterArusKasSeeder::class,
    ],
],
```

**Urutan itu wajib.** `AkunLevel1` → `2` → `3` karena masing-masing mengisi `id`
untuk `parent_id` berikutnya. `AccountsTableSeeder` mengisi `accounts.id` yang
dipakai `MasterArusKasSeeder` (debit/kredit menyimpan `kode_akun` level 3).

> [!IMPORTANT]
> **Daftar seeder tidak boleh memuat data contoh.** `db:seed` tanpa `--class`
> memakai `DatabaseSeeder` single-tenant (pelanggan contoh, meter reading, struk) —
> itu akan mengisi database produksi dengan data fiktif. Karena itu provisioning
> memanggil seeder **satu per satu** via `--class`, bukan `db:seed` polos.
>
> **`DemoUserSeeder` sengaja TIDAK ada di `provision.seeders`.** Perintah
> `tenancy:reseed-master` dijalankan ulang untuk semua lokasi sebagai pemeliharaan
> rutin; kalau akun demo ikut di sana, setiap pemanggilan mengembalikan password
> semua akun demo ke `password` — membatalkan password yang sudah diganti pemilik lokasi.

### 3.5 Identitas lokasi

`applyTenantIdentity()` menulis baris `settings` id = 1 milik lokasi itu sendiri:

| Kolom | Nilai default saat provisioning |
|---|---|
| `key` | `sop` |
| `nama`, `nama_lembaga` | Nama lokasi |
| `alamat`, `email`, `telepon`, `domain` | Dari form lokasi |
| `status_pembayaran` | `true` |
| `batas_tagihan` | `10` |
| `toleransi_tunggakan` | `10` |
| `pesan_tagihan` | Template dengan `{customer}`, `{jumlah_tagihan}` |
| `pesan_pembayaran` | Template dengan `{customer}`, `{tagihan}` |

Kalau baris sudah ada, **hanya kolom identitas** yang di-update. Nilai tagihan
(`batas_tagihan`, `toleransi_tunggakan`, `billing_unpaid_limit`) **tidak** ditimpa —
jadi provisioning ulang tidak mengubah perilaku tagihan yang sudah diset admin.

> [!WARNING]
> `toleransi_tunggakan` **tidak boleh diisi dari `invoice_grace_months`**.
> Satuannya berbeda: `invoice_grace_months` = BULAN, `toleransi_tunggakan` = TANGGAL
> dalam bulan 1–31. Gejala yang pernah terjadi: tanggal generate diset 28, tapi
> generate tidak pernah berjalan karena nilainya tertimpa jadi 1.
>
> `batas_tagihan` dan `toleransi_tunggakan` kini **satu angka** yang ditulis
> dua kali (lihat [§9.4](#94-satu-kolom-tanggal-untuk-semua-aturan-berbasis-tanggal)).
> Writer baru (`TenantManager`, `SettingsSeeder`) selalu mengisi keduanya dengan
> nilai yang sama.

### 3.6 Tagihan langganan & penguncian lokasi

Pusat menjual langganan ke lokasi lewat `tenant_invoices` + `tenant_invoice_items`.
**Tidak ada model `Plan`/`Subscription`** — langganan dimodelkan sebagai invoice.

`InvoiceGate` adalah **satu-satunya** sumber keputusan, dipakai di tiga tempat:

1. `POST /api/login` — menolak login sebelum token terbit
2. `EnforceInvoiceStatus` middleware — memblokir request setelah login
3. `MyInvoiceController` — ringkasan untuk popup

```php
// backend/app/Services/InvoiceGate.php:55-67
$unpaid = $tenant->unpaidInvoices()->get();

if (! (bool) config('tenancy.invoice_block_on_unpaid', true)) {
    $unpaid = $unpaid->filter(fn (TenantInvoice $i) => $i->isOverdue())->values();
}

return $unpaid->sortByDesc(fn (TenantInvoice $i) => $i->deadline()->getTimestamp())->values();
```

**Blokir di level respons (402), bukan mencabut token.** Docblock di
`EnforceInvoiceStatus` menjelaskan alasannya:

> *Mencabut token itu salah. Begitu token dihapus, `auth:sanctum` pada request
> berikutnya membalas 401 — termasuk untuk `tagihan-saya` dan `logout` yang
> sengaja dikecualikan. Akibatnya user terkunci total tanpa jalan keluar. Itu
> pengucilan, bukan penagihan.*

Respons 402 membawa payload: `terlambat`, `tagihan_terkunci`, `total_tagihan`,
dan array `invoices` (nomor, jumlah, `tgl_invoice`, `deadline`, `sisa_hari`, `terlambat`).
Kolom `periode` **sengaja tidak ikut** — user lokasi cukup tahu nomor, nominal, dan
kapan harus bayar.

Ada **dua pesan berbeda** — terlambat vs belum terlambat. Menampilkan kalimat sama
untuk keduanya membuat lokasi yang masih dalam tempo merasa pasti ditolak.

Path yang dikecualikan dari blokir (`tenancy.invoice_exempt_paths`):

```
api/tagihan-saya, api/tagihan-saya/*, api/login, api/logout, api/refresh,
api/me, api/me/*, api/health, api/branding, api/login-user, api/login-user/*
```

> `api/logout` **wajib** ada di daftar ini. Kalau logout ikut diblokir, satu-satunya
> cara keluar hilang, dan user yang tagihannya terlambat bisa mencoba pounded
> memakai akunnya sendiri — persis yang harus dicegah.

### 3.7 Domain & wildcard

`tenant_domains` menyimpan domain per lokasi. Tiap lokasi bisa punya banyak domain;
satu ditandai **utama** (primary), sisanya alternatif. Panel pusat manages:
`GET|POST /api/central/domains`, `PATCH|DELETE /api/central/domains/{domain}`,
plus `GET|POST /api/central/locations/{location}/domains`.

Kolom legacy `tenants.domain` / `tenants.domain_alternatif` **masih ditulis**,
tapi resolver membacanya sebagai fallback terakhir. Nilainya disimpan dalam
**bentuk bersih** (tanpa `https://`) karena `TenantResolver::fromHost()` membandingkan
host polos — input mentah `https://demo.astaloka.test` tidak akan pernah cocok.

### 3.8 Configuration reference — `config/tenancy.php`

| Key | Default | Env |
|---|---|---|
| `central_connection` | `central` | `TENANCY_CENTRAL_CONNECTION` |
| `central_database` | `astaloka` | `TENANCY_CENTRAL_DATABASE` |
| `migrations_path` | `database/migrations_tenant` | — |
| `central_migrations_path` | `database/migrations_central` | — |
| `database_prefix` | `astaloka_` | `TENANCY_DATABASE_PREFIX` |
| `max_database_name_length` | `64` | — |
| `reserved_slugs` | `central, pusat, mysql, information_schema, performance_schema, sys, test, laravel` | — |
| `lookup` | `host` | `TENANCY_LOOKUP` |
| `slug_header` | `X-Tenant-Slug` | — |
| `central_hosts` | `[]` | `TENANCY_CENTRAL_HOSTS` |
| `frontend_url` | `null` | `TENANCY_FRONTEND_URL` |
| `frontend_url_template` | `null` | `TENANCY_FRONTEND_URL_TEMPLATE` |
| `invoice_grace_months` | `1` | `TENANCY_INVOICE_GRACE_MONTHS` |
| `invoice_block_on_unpaid` | `true` | `TENANCY_INVOICE_BLOCK_ON_UNPAID` |
| `session_key` | `tenant_slug` | — |
| `demo_users.enabled` | `true` | `TENANCY_DEMO_USERS` |
| `demo_users.password` | `password` | `TENANCY_DEMO_USERS_PASSWORD` |

> [!IMPORTANT]
> **Path migrasi harus relatif dan ber-pemisah `/`.**
> `database_path()` mengembalikan backslash di Windows; `glob()` Migrator tidak akan
> cocok, dan perintah keluar **status sukses** sambil mencetak "Nothing to migrate".
> Gejalanya: database lokasi tetap kosong, lalu seeder gagal dengan
> `Table 'astaloka_x.settings' doesn't exist`.

> `session_key` = `tenant_slug` **tidak dibaca PHP backend** — hanya dipakai sebagai
> key `localStorage` di frontend (`frontend/src/utils/tenantSlug.js`).

---

## 4. Sistem Role, Jabatan & Hak Akses

Ada **dua lapis** kontrol akses yang saling menumpuk.

### 4.1 Lapis 1 — Role (kasar, berbasis route)

| Role | Cakupan |
|---|---|
| `admin` | Hampir semua endpoint lokasi |
| `surveyor` | Daftar tiket, input survey |
| `teknisi` | Tiket ≥ `surveyed`, meter reading, dashboard |
| `pelanggan` | Portal pelanggan: tagihan sendiri, riwayat, lapor gangguan |

Ditegakkan middleware `role` → `CheckRole` (alias Laravel):

```php
// backend/app/Http/Middleware/CheckRole.php:13-25
if (! $user || ! in_array(trim($user->role), array_map('trim', $roles))) {
    return response()->json([
        'success' => false,
        'message' => 'Akses ditolak. Role Anda: ' . ($user->role ?? 'Guest')
            . ', Butuh salah satu dari: ' . implode(', ', $roles)
    ], 403);
}
```

### 4.2 Lapis 2 — Jabatan per lokasi (halus, berbasis menu)

Tiap user lokasi punya `jabatan_id` → tabel `jabatan`. Tiap jabatan punya
`jabatan_menu_access` yang berisi daftar `menu_key` yang boleh diakses.

**Aturanribut:**

- `roles` di `config/menu_access.php` adalah **BATAS ATAS**. Jabatan hanya boleh
  **menyusut** akses milik role-nya, tidak pernah memberi akses ke menu role lain.
- Jabatan yang **belum pernah dikonfigurasi** tetap mendapat semua menu miliknya.
  Jadi mengaktifkan fitur ini **tidak mengunci siapa pun secara diam-diam**.
- Role `admin` **tidak** diberi pengecualian. Docblock `EnforceMenuAccess` menjelaskan:
  *"bendahara memakai role `admin` justru supaya bisa memakai Jurnal Umum, jadi
  membebaskan admin berarti membebaskan bendaharanya juga."*
- Menu `dashboard` selalu di `always` — itulah yang mencegah terkunci total.

### 4.3 Katalog menu

`backend/config/menu_access.php` adalah **satu-satunya sumber kebenaran** tentang
menu aplikasi lokasi. `SidebarView.vue` menariknya lewat `GET /api/menu-access/catalog`.

**30 entri menu** dalam 10 grup:

| Grup | Key | Role |
|---|---|---|
| Pengaturan | `settings.sop` | admin |
| Pengaturan | `settings.coa` | admin |
| Pengaturan | `settings.package` | admin |
| Basis Data | `master.pelanggan` | admin |
| Basis Data | `master.desa` | admin |
| Basis Data | `master.instalasi` | admin |
| Master Instalasi | `instalasi.register` | admin |
| Master Instalasi | `instalasi.status` | admin |
| Master Instalasi | `instalasi.pemakaian` | teknisi |
| Survey | `survey.daftar` | surveyor |
| Survey | `survey.create` | surveyor |
| Pusat Teknis | `teknisi.hasil-instalasi` | teknisi |
| Pusat Teknis | `teknisi.daftar-tagihan` | teknisi |
| Layanan Pelanggan | `pelanggan.tagihan` | pelanggan |
| Layanan Pelanggan | `pelanggan.riwayat` | pelanggan |
| Layanan Pelanggan | `pelanggan.lapor-gangguan` | pelanggan |
| Pencatatan Tagihan | `tagihan.input` | admin |
| Pencatatan Tagihan | `tagihan.daftar` | admin |
| Transaksi | `transaksi.jurnal-umum` | admin |
| Transaksi | `transaksi.tagihan-bulanan` | admin |
| Transaksi | `transaksi.tagihan-instalasi` | admin |
| Transaksi | `transaksi.ebudgeting` | admin |
| Transaksi | `transaksi.tutup-buku` | admin |
| Transaksi | `transaksi.alokasi-laba` | admin |
| Transaksi | `transaksi.komisi-sps` | admin |
| Pelaporan | `pelaporan` | admin |
| Arsip | `arsip.tagihan` | admin |
| Arsip | `arsip.piutang` | admin |
| Arsip | `arsip.pemakaian` | admin |
| Arsip | `arsip.instalasi` | admin |

### 4.4 Match prefix

`EnforceMenuAccess` mencocokkan **path endpoint** (bukan path halaman — path
halaman tidak pernah sampai ke server; yang sampai adalah `GET /api/transactions`).

```php
// backend/app/Http/Middleware/EnforceMenuAccess.php:225-248
if ($path === $prefix) {
    return true;
}

if (str_starts_with($path, $prefix.'/')) {
    return true;
}

// Wildcard satu segmen, mis. prefix `installation-tickets/X/survey`
// cocok dengan path `installation-tickets/12/survey`.
if (str_contains($prefix, '*')) {
    return fnmatch($prefix, $path, FNM_PATHNAME);
}
```

> [!IMPORTANT]
> `FNM_PATHNAME` itu **wajib**, bukan pilihan. Tanpa flag itu, `*` di `fnmatch`
> juga menyeberangi `/`, jadi satu `*` di tengah ikut mencocokkan jalur BERAPA pun.
>
> Prefix yang tidak dimiliki menu mana pun → **biarkan lewat** (fail-open untuk
> path yang tidak terdaftar, bukan untuk endpoint terdaftar).

### 4.5 Responsive frontend

Guard frontend ada di `frontend/src/router/index.js` lewat tabel `ROLE_GUARDS`
(prefix → role yang boleh), bukan `meta` route. Route baru yang butuh pembatasan
role **harus** ditambahkan ke tabel itu.

Tambahan: `menuAccessStore.allowsPath()` mencocokkan **path penuh** terhadap katalog.
Path yang tidak dikenal lolos (fail-open) — supaya halaman baru tidak terkunci
karena lupa mendaftarkannya.

---

## 5. Master Data

### 5.1 Paket & blok tarif

```
installation_packages (1) ──< (N) water_tariff_blocks
```

**`installation_packages`**

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint | PK |
| `name` | varchar(255) | Nama paket |
| `installation_fee` | decimal(12,2) | Biaya pasang sambungan baru |
| `monthly_abodemen` | decimal(12,2) | Biaya tetap bulanan |
| `late_penalty` | decimal(12,2) | Nilai denda keterlambatan |

**`water_tariff_blocks`**

| Kolom | Tipe | Keterangan |
|---|---|---|
| `package_id` | bigint | FK → `installation_packages`, cascade delete |
| `usage_min_m3` | int | Batas bawah pemakaian (m³) |
| `usage_max_m3` | int | Batas atas (m³), `null` = tak terbatas |
| `price_per_m3` | decimal(12,2) | Harga per m³ |

**Validasi overlap** (`WaterTariffBlockController::checkOverlap()`): dua blok
overlap kalau rentang berpotongan, atau kalau blok tak terbatas (`usage_max_m3` null)
dan min blok baru ≥ min blok tak terbatas itu.

**Perhatikan:** `usage_max_m3` harus **lebih besar** dari `usage_min_m3`
(bukan ≥). Ini yang membuat penulisan `0-10` dan `10-20` lolos validasi tapi
dua bloknya sama-sama cocok untuk pemakaian 10 m³ — lihat
[§8.4](#84-rumus-tarif-flat). Rumus FLAT mengambil **blok pertama** yang cocok,
sehingga `10 m³` dihitung dengan harga blok `0-10`, bukan `10-20`.

### 5.2 Desa & Droplet

`villages` menyimpan desa + dusun, dengan `code` untuk generate `customer_code`.
Wilayah **kecamatan** diambil dari API eksternal `emsifa.com` saat formulir desa
dibuat — **bukan** dari tabel sendiri. Lihat [§17](#17-risiko--utang-teknis-tertuang).

### 5.3 CoA — terbelah dua database

| Level | Tabel | Database |
|---|---|---|
| 1 | `akun_level_1` | **PUSAT** |
| 2 | `akun_level_2` | **PUSAT** |
| 3 | `akun_level_3` | **PUSAT** |
| 4 | `accounts` | **LOKASI** per tenant |

Konsekuensinya:

- **Tidak ada FK constraint** yang mengikat `parent_id` — hanya dijaga seeder + logika Eloquent.
- Penghapusan berjenjang di-handle `CoaCascadeService` (level 1→2→3→accounts, lintas
  database), dengan proteksi 409 kalau sudah ada data keuangan.
- `AkunLevel1/2/3` mengunci `$connection = 'central'` — tidak bisa di-redirect.

**Kode akun yang dipakai kode aplikasi:**

| Kode | Nama | Jenis |
|---|---|---|
| `1.1.01.01` | Kas Tunai | debet |
| `1.1.01.03` | Kas di Bank BRI | debet |
| `1.1.03.01` | Piutang Usaha | debet |
| `2.1.01.01` | Utang Dividen Pemdes | kredit |
| `2.1.01.02` | Utang Dividen Masy Penyerta Modal | kredit |
| `2.1.02.02` | Utang FEE Kolektor | kredit |
| `3.1.01.01` | Modal Pemdes | kredit |
| `3.2.01.01` | Laba Ditahan s/d Tahun lalu | kredit |
| `3.2.02.01` | Laba/Rugi Tahun Berjalan | kredit |
| `4.1.01.02` | Pendapatan Abodemen | kredit |
| `4.1.01.03` | Tagihan Pemakaian | kredit |
| `4.1.01.04` | Denda | kredit |

### 5.4=settings (SOP lokasi)

Satu baris per lokasi (`id = 1`, `key = 'sop'`). Kolom yang relevan tagihan:

| Kolom | Tipe | Default DB | Fungsi |
|---|---|---|---|
| `status_pembayaran` | bool | `false` | Wajib lunas sebelum instalasi diproses |
| `batas_tagihan` | tinyint | `10` | Tanggal jatuh tempo (1–31) di bulan tagihan — **sumber tunggal** semua aturan berbasis tanggal |
| `toleransi_tunggakan` | tinyint | `0` | Salinan dari `batas_tagihan` — lihat [§9.4](#94-satu-kolom-tanggal-untuk-semua-aturan-berbasis-tanggal) |
| `billing_unpaid_limit` | smallint | — | Batas tunggakan (JUMLAH BULAN) sebelum sambungan di-`suspended` |
| `auto_open_meter_camera` | bool | `null` | Kamera scan meter terbuka otomatis |
| `calk` | json | — | Persentase CALK, editable dari UI |
| `calk_point_a` | longtext | — | Narasi CALK |

---

## 6. Alur Instalasi (Tiket)

### 6.1 Status tiket

8 status: `draft`, `pending`, `surveyed`, `unpaid`, `processing`, `completed`,
`suspended`, `terminated`.

**State machine** (`backend/app/StateMachines/TicketStateMachine.php`):

```php
private static array $transitions = [
    'draft'      => ['pending', 'terminated'],
    'pending'    => ['surveyed', 'unpaid', 'processing', 'terminated'],
    'surveyed'   => ['unpaid', 'processing', 'pending', 'terminated'],
    'unpaid'     => ['processing', 'pending', 'terminated'],
    'processing' => ['completed', 'terminated'],
    'completed'  => ['suspended', 'terminated'],
    'suspended'  => ['completed', 'terminated'],
    'terminated' => [],
];
```

Alur normal: `draft → pending → surveyed → unpaid → processing → completed`.
Cabut tersedia dari semua status Except `terminated`. `suspended → completed`
adalah jalur **pemulihan** — dipicu otomatis saat semua tagihan pelanggan lunas.

### 6.2 Alur end-to-end

```
[draft] ──Daftar──> [pending] ──Survey──> [surveyed] ──Bayar──> [unpaid]
                                                              │
                                                       Instalasi
                                                              ▼
   [completed] <──Aktivasi── [processing] <──Meter + Foto──────┘
       │
       ├──> [suspended]  (tagihan menunggak > batas)
       └──> [terminated] (cabut)
```

| Endpoint | Method | Role | Fungsi |
|---|---|---|---|
| `/installation-tickets` | POST | admin | Buat tiket (draft/pending) |
| `/installation-tickets/{id}/survey` | POST | admin, surveyor | Hasil survey + foto |
| `/installation-tickets/{id}/payment` | POST | admin | Konfirmasi pembayaran pasang |
| `/installation-tickets/{id}/installation-result` | POST | admin, teknisi | Meter awal + foto meter |
| `/installation-tickets/{id}/activate` | POST | admin | Aktivasi → buat `customers` |
| `/installation-tickets/{id}/transition` | PATCH | admin | Ubah status (state machine) |
| `/installation-tickets/{id}/advance-stage` | POST | admin | Majukan tahap otomatis |
| `/installation-tickets/{id}/change-package` | POST | admin | Ganti paket + riwayat |
| `/installation-tickets/{id}/history` | GET | admin | Riwayat perubahan |

### 6.3 Aktivasi → pelanggan

Saat diaktifkan, sistem membuat baris `customers` dengan `customer_code` yang
dihasilkan `Village::generateNextCustomerCode()` (format `<kode_desa>.<urutan>`,
mis. `1.04.0996`), `initial_meter_reading`, `meter_photo_url`, dan `activated_at`.

### 6.4 Riwayat perubahan paket

`installation_ticket_histories` mencatat perubahan paket dengan **snapshot** nilai
paket lama & baru. Konsekuensinya: tagihan yang sudah terbit **menyimpan snapshot
abodemen & usage_charge** — jadi kalau paket pelanggan diganti bulan depan,
tagihan bulan lalu tidak ikut berubah.

### 6.5 Pelanggan

| Kolom | Tipe | Keterangan |
|---|---|---|
| `ticket_id` | bigint | FK → `installation_tickets` |
| `user_id` | bigint | FK → `users` (akun pelanggan) |
| `customer_code` | varchar(50) | Unique, auto-generated |
| `initial_meter_reading` | decimal(10,2) | Angka meter awal saat aktivasi |
| `meter_photo_url` | varchar(500) | Foto meteran terpasang |
| `activated_at` | timestamp | Waktu aktivasi resmi |

---

## 7. Pencatatan Meter

### 7.1 Endpoint

| Endpoint | Method | Role | Fungsi |
|---|---|---|---|
| `/meter-readings/pending` | GET | admin, teknisi | Pelanggan belum dicatat bulan ini |
| `/meter-readings` | POST | admin, teknisi | Catat meter + foto |
| `/meter-readings/{id}` | PUT | admin, teknisi | Koreksi meter |
| `/meter-readings/{id}` | DELETE | admin, teknisi | Hapus pencatatan |
| `/meter-readings/completed` | GET | admin, teknisi | Yang sudah dicatat |

### 7.2 Validasi

```php
// backend/app/Http/Controllers/MeterReadingController.php:145-151
'customer_id'   => 'required|exists:customers,id',
'meter_value'   => 'required|integer|min:0|max:99999999',
'photo'         => 'nullable|image|max:2048',
'reading_month' => 'required|integer|between:1,12',
'reading_year'  => 'required|integer|min:2000',
```

| Aturan | Detail |
|---|---|
| **Foto opsional** | `nullable` — teknisi sering tidak bisa memfoto (gelap, meter di bawah tanah, pelanggan tidak di tempat) |
| **Meter monoton** | Angka baru tidak boleh < catatan terakhir |
| **Periode unik** | Satu pelanggan satu bulan — dilindungi unique index `(customer_id, reading_year, reading_month)` |
| **Tagihan sudah lunas** | Meter yang periodenya sudah `paid` tidak boleh diubah (409) |

Pembanding monoton mengambil **catatan terakhir berdasarkan periode**, bukan
strikt N-1:

```php
// backend/app/Http/Controllers/MeterReadingController.php:201-211
$last = MeterReading::where('customer_id', $request->customer_id)
    ->orderByDesc('reading_year')->orderByDesc('reading_month')->first();

if ($last && $request->meter_value < $last->meter_value) {
    return response()->json([
        'success' => false,
        'message' => 'Angka meter tidak boleh lebih kecil dari bulan sebelumnya (Catatan terakhir: '.$last->meter_value.' m³)',
    ], 400);
}
```

### 7.3 Suspend otomatis

Setelah pencatatan, sistem menghitung **berapa bulan** pelanggan menunggak:

```php
// backend/app/Http/Controllers/MeterReadingController.php:277-287
$toleransi = app(BillingTolerance::class)->unpaidBillLimit();   // = settings.billing_unpaid_limit

if ($toleransi > 0) {
    $unpaidMonths = app(UnpaidMonths::class)
        ->forCustomer($result['customer']->id);

    if ($unpaidMonths > $toleransi && $result['customer']->ticket) {
        $result['customer']->ticket->update(['status' => 'suspended']);
        $suspended = true;
    }
}
```

**Rumus: `suspended` kalau `unpaidMonths > billing_unpaid_limit`** (strictly greater),
dan hanya kalau `billing_unpaid_limit > 0`.

> `billing_unpaid_limit` satuannya **JUMLAH BULAN**, bukan tanggal dalam bulan
> dan bukan jumlah tagihan. Nilai kosong (`NULL`) dibaca sebagai 3 — bawaan yang
> sama dengan `SettingsSeeder`. Migration tenant
> `2026_10_31_000001_fill_billing_unpaid_limit_default` mengisi `NULL` → 3.

#### Cara menghitung bulan menunggak (`UnpaidMonths`)

Tagihan dianggap menunggak kalau **status `unpaid` DAN sudah lewat jatuh tempo**
(`due_date < hari ini`). Tagihan yang jatuh temponya belum sampai **tidak**
dihitung — kalau ikut dihitung, setiap pelanggan dianggap menunggak begitu
tagihan bulan berikutnya dibuat, persis sebelum tagihan itu harus dibayar.

Bulan menunggak diambil dari **tagihan unpaid dengan periode paling lama**
(periode paling awal), lalu:

```php
// backend/app/Services/UnpaidMonths.php:103
$selisih = (($now->year - $year) * 12) + ($now->month - $month);

return max(0, $selisih);
```

|_now_ | Tagihan tertua unpaid & lewat tempo | Bulan menunggak |
|---|---|---|
| Oktober 2026 | Juni 2026 | `(2026−2026)*12 + (10−6)` = **4** |
| Oktober 2026 | Oktober 2026 | `0 + 0` = **0** (bulannya belum selesai) |
| Oktober 2026 | September 2026 | **1** |

> Selisih dihitung dari **periode tagihan** (bulan pemakaian), bukan dari
> `due_date`. `due_date` bisa bergeser (jatuh tempo ada di bulan tagihan),
> jadi memakainya bisa meleset satu bulan.

> Catatan: `BillingTolerance::shouldSuspend()` mengimplementasikan rumus yang sama,
> tapi controller **inline**-kan logikanya, tidak memanggil helper itu. Duplikasi ini
> perlu dibereskan (lihat §17).

**Pemulihan otomatis**: saat `pay()` menemukan tiket `suspended` dan tagihan
unpaid tersisa = 0, tiket dikembalikan ke `completed`.

### 7.4 `recorded_at`

```php
// backend/app/Http/Controllers/MeterReadingController.php:222-238
$recordedAt = Carbon::create($tahun, $bulan, 1)
    ->setDay(min($batasTagihan, Carbon::create($tahun, $bulan, 1)->daysInMonth))
    ->setTimeFrom(now());
```

`recorded_at` = hari `batas_tagihan` **di dalam bulan pemakaian** (bukan bulan
tagihan) — frontend memakainya sebagai "tanggal akhir pemakaian".

---

## 8. ⭐ Engine Tagihan Bulanan

Ini inti sistem. Seluruh section ini adalah **specifikasi perhitungan** yang harus
mengacu ke kode.

### 8.1 Ringkasan alur

```
meter_readings (bulan N)  +  meter_readings (bulan N-1)
        │
        ▼
   usage_m3 = end − start
        │
        ▼
   usage_charge = tarif FLAT dari water_tariff_blocks
        │
        +  abodemen   (dari paket)
        +  denda      (dari tagihan N-1)
        ▼
   total_amount
        │
        ▼
   due_date = bulan N+1, hari batas_tagihan
```

### 8.2 Pembuatan tagihan — 3 jalur

| Jalur | Dipanggil dari | Periode | Kode |
|---|---|---|---|
| **Per pelanggan** | `POST /meter-readings` | Bulan pencatatan | `BillingService::generateForCustomer()` |
| **Massal (bulanan)** | `POST /monthly-bills/generate` | Bulan berjalan saja | `MonthlyBillService::generate()` |
| **Massal (param)** | `POST /bills/generate` | `year` + `month` | `BillingController::generate()` |
| **Hitung ulang** | `PUT /monthly-bills/usage` | Periode yang diubah | `BillRecalculationService::recalculateForCustomer()` |

> [!WARNING]
> `MonthlyBillService::generate()` **hanya** bisa membuat tagihan untuk **bulan
> berjalan**. Fungsi `year`/`month` ada di `BillingController::generate()`, bukan di
> `MonthlyBillService`. Jangan sampai ada yang mengira keduanya sama.

### 8.3 Satu implementasi tarif, dipakai semua jalur

Ada **tiga** jalur yang menghitung tagihan. Semuanya membaca blok tarif dari
paket yang sama, jadi **selisih sekecil apa pun** akan terlihat oleh klien sebagai
"tagihan berbeda antar lokasi".

| File | Method | Peran |
|---|---|---|
| `app/Services/BillingService.php:219` | `calculateUsageCharge()` | **sumber tunggal rumus** ✅ |
| `app/Services/MonthlyBillService.php:171` | `calculateTariff()` | delegasi ke `BillingService` ✅ |
| `app/Services/BillRecalculationService.php:57` | `recalculateForCustomer()` | delegasi ke `BillingService` ✅ |

> Ketiganya sekarang memanggil method yang **sama**. Dulu `MonthlyBillService`
> menyalin logikanya sendiri — dan dua salinan itu bisa saja berbeda satu rupiah
> tanpa ada yang sadar. Sekarang kalau rumusnya berubah, cukup satu tempat.

### 8.4 Rumus tarif FLAT

Rumus tagihan = **pemakaian × harga paket per m³** (FLAT) — **sama dengan
aplikasi LAMA**. Blok tarif dipakai untuk **memilih harga per m³**, bukan untuk
membagi pemakaian menjadi beberapa bagian dengan harga berbeda.

```php
// backend/app/Services/BillingService.php:219-257
public function calculateUsageCharge($package, float $usageM3): float
{
    if ($usageM3 <= 0) {
        return 0;
    }

    $blocks = WaterTariffBlock::where('package_id', $package->id)
        ->orderBy('usage_min_m3')
        ->get();

    if ($blocks->isEmpty()) {
        return 0;
    }

    $price = null;

    foreach ($blocks as $block) {
        $max = $block->usage_max_m3 === null
            ? null
            : (float) $block->usage_max_m3;

        if ($max === null || $usageM3 <= $max) {
            $price = (float) $block->price_per_m3;

            break;
        }
    }

    // Pemakaian melewati semua batas atas dan tidak ada blok tak
    // terbatas: pakai harga blok terakhir.
    if ($price === null) {
        $price = (float) $blocks->last()->price_per_m3;
    }

    return round($usageM3 * $price);
}
```

#### Batas atas blok bersifat INKLUSIF

Blok terpilih = **blok pertama** yang `usage_max_m3`-nya (inklusif) sudah
`>=` pemakaian. Jadi:

| Pemakaian | Blok (`0-10` @ 1.500, `11-20` @ 2.000, `21-null` @ 2.500) | Nominal |
|---|---|---|
| 0 m³ | — (nol, tidak ditagih) | 0 |
| 9 m³ | 0-10 @ 1.500 | 13.500 |
| **10 m³** | **0-10** @ 1.500 (batas atas inklusif) | 15.000 |
| 11 m³ | 11-20 @ 2.000 | 22.000 |
| 21 m³ | 21-null @ 2.500 | 52.500 |
| 35 m³ (tidak ada blok tak terbatas) | harga blok terakhir | 87.500 |

Perhatikan `10 m³` masih memakai harga blok **pertama**. Ini persis konvensi
aplikasi LAMA: blok `1-10 m³` berarti 1..10 (10 nilai), dan blok berikutnya
mulai di `11`.

#### Bukti: data produksi, bukan asumsi

Data produksi aplikasi LAMA (`pamsides_dump.usages`) dipakai sebagai acuan.
Populasinya: **baris yang dihitung APLIKASI** (`user_id` NOT NULL — bukan
hasil import Excel), pemakaiannya > 0, dan paketnya bertarif → **25.569 baris**.

| Rumus | Baris cocok | Persentase |
|---|---|---|
| **FLAT + blok PERTAMA** (implementasi sekarang) | **25.540 / 25.569** | **99,89%** |
| FLAT + blok TERAKHIR (semantik source legacy) | 25.377 / 25.569 | 99,25% |
| Progresif (dibagi per blok) | 11.832 / 25.569 | 46,27% |

Jadi rumus progresif **tidak pernah dipakai** di produksi.

Baris **import Excel** sengaja tidak dipakai sebagai bukti: di sana `jumlah`
adalah turunan dari nominal yang sudah jadi di file Excel
(`jumlah = round(nominal / harga, 2)` — `siap_mapan/AuthController.php:815`),
bukan hasil hitung aplikasi.

> **Angka-angka ini bisa diulang.** Perintah
> `php artisan legacy:verify-tariff --database=pamsides_dump` menghitung ulang
> tabel di atas dari DB legacy dan mencetak residualnya. Kalau angkanya
> berubah, **dokumentasi ini yang harus diperbarui** — bukan sebaliknya.
> Source: `backend/app/Console/Commands/VerifyLegacyTariffCommand.php`.

#### Kenapa "blok pertama", bukan "blok terakhir"?

Source legacy (`siap_mapan/app/Http/Controllers/UsageController.php:287`)
menulis `$result[$i] = $index` di dalam loop, jadi angka yang tercakup lebih dari
satu blok berakhir menunjuk blok **TERAKHIR**. Blok legacy memang tumpang tindih
(`0-10`, `10-20`, `20-100`), jadi aturan itu beda dari yang tertulis di paket.

Diuji terhadap data yang benar-benar tersimpan di produksi (tabel di atas),
aturan **blok pertama** justru lebih cocok: 99,89% vs 99,25%.

29 baris sisanya **tidak cocok dengan kedua aturan** — jadi bukan bukti aturan
yang berbeda. Isinya anomali data:

| Kategori | Jumlah |
|---|---|
| `nominal` = 0 (pemakaian tercatat, tidak ada tagihan) | 8 |
| Harga 10.000/m³ yang bukan harga paket mana pun | 4 |
| Harga paket lain dari blok terpilih | 3 |
| `nominal` duplikat / hasil import | 14 |

Sebar di **22 tanggal berbeda** dan **10 bulan berbeda** (terpadat 7 baris dalam
satu timestamp), jadi **tidak terkluster**.

> Konsekuensi praktis: untuk blok bertumpuk `0-10` dan `10-20`, pemakaian
> **10 m³** memakai harga blok `0-10`, bukan `10-20`.

#### Pembulatan

`round()` ke **rupiah penuh**, sekali di akhir.

Aplikasi LAMA tidak membulatkan di source-nya
(`siap_mapan/app/Http/Controllers/UsageController.php:315` menyimpan
`harga × pemakaian` apa adanya), tapi semua harga paket legacy kelipatan 100 dan
pemakaian yang diinput aplikasi selalu bulat — jadi untuk pemakaian bulat
hasilnya **identik** dengan legacy.

Diuji terhadap data produksi: dari 25.569 baris yang cocok FLAT+blok PERTAMA,
**25.540 baris** menghasilkan `harga × pemakaian` yang sudah bulat dan **nol
baris** pecahan — jadi `round()` tidak mengubah satu rupiah pun.

Pembulatan ke **RATUSAN** TIDAK dipakai: tidak ada satu pun aturan di aplikasi
LAMA yang melakukannya. Helper `Keuangan::pembulatan()` ada di source tapi tidak
dipanggil dari mana pun (kode mati), dan tabel `businesses` tidak punya kolom
`pembulatan` sama sekali — jadi tidak ada pengaturan yang bisa mengaturnya.

> Pola "nominal selalu kelipatan 100" di produksi adalah **artefak import
> Excel**, bukan aturan hitung: `AuthController.php:815-832` menyimpan `nominal`
> (uang dari Excel) lalu menurunkan `jumlah = round(nominal / harga, 2)`.
> Diuji terhadap seluruh baris yang `jumlah`-nya pecahan (276 baris: 275 dari
> import Excel + 1 dari aplikasi), `jumlah == round(nominal / harga_paket, 2)`
> cocok **276/276 (100%)** — dengan `harga_paket` mengikuti alur legacy:
> `harga[0]`, atau `harga[1]` kalau `nominal / harga[0] > 10`.

#### Blok tarif tidak pernah tumpang tindih di aplikasi ini

Blok tarif yang tumpang tindih hanya ada di **data legacy**. Di aplikasi ini
`WaterTariffBlockController::checkOverlap()` menolak blok yang berpotongan,
dan `ImportLegacyPackagesCommand::parseJarak()` menerjemahkan rentang legacy
`0-10` / `10-20` / `20-100` apa adanya — jadi kalau blok warisan diimpor apa
adanya, ia bisa tumpang tindih.

Konsekuensinya: **untuk semua data yang sah, "blok PERTAMA" dan "blok
TERAKHIR" menghasilkan nominal yang sama.** Perbedaannya cuma muncul kalau
blok tumpang tindih sudah terlanjur ada di database — persis kasus yang
dibuktikan data produksi di atas.

### 8.5 Rumus lengkap tagihan

```php
// backend/app/Services/BillingService.php:32-47
$startMeter = $previousReading
    ? $previousReading->meter_value
    : $customer->initial_meter_reading;

$endMeter = $currentReading->meter_value;
$usageM3 = max(0, $endMeter - $startMeter);

$usageCharge = $this->calculateUsageCharge($customer->ticket->package, $usageM3);

$abodemen = round($customer->ticket->package->monthly_abodemen);
$penaltyAmount = $this->calculatePenalty($customer, $year, $month);

$totalAmount = round($usageCharge + $abodemen + $penaltyAmount);
```

| Komponen | Sumber | Pembulatan |
|---|---|---|
| `meter_reading_start` | `meter_readings[N-1].meter_value`, fallback `customers.initial_meter_reading` | — |
| `meter_reading_end` | `meter_readings[N].meter_value` | — |
| `usage_m3` | `max(0, end − start)` | — |
| `usage_charge` | Tarif FLAT (`pemakaian × harga per m³`) | `round()` ke rupiah penuh |
| `abodemen` | `installation_packages.monthly_abodemen` | `round()` |
| `penalty_amount` | `installation_packages.late_penalty` (bila ada) | `round()` |
| `total_amount` | jumlah ketiganya | `round()` **sekali** |

> `total_amount` adalah angka **otoritatif**. Komponen hanya dipakai sebagai
> fallback untuk baris tagihan hasil import legacy yang `total_amount`-nya kosong
> (lihat `MonthlyBillController::pay()`).

### 8.6 Denda

```php
// backend/app/Services/BillingService.php:259-275
public function calculatePenalty(Customer $customer, int $year, int $month): float
{
    $prevMonth = $month === 1 ? 12 : $month - 1;
    $prevMonthYear = $month === 1 ? $year - 1 : $year;

    $oldBill = MonthlyBill::where('customer_id', $customer->id)
        ->where('billing_period_year', $prevMonthYear)
        ->where('billing_period_month', $prevMonth)
        ->where('status', 'unpaid')
        ->first();

    if (! $oldBill) {
        return 0;
    }

    return round($customer->ticket->package->late_penalty);
}
```

| Aturan | Nilai |
|---|---|
| Sumber | Tagihan **N-1** dengan status `unpaid` |
| Nilai | `late_penalty` paket pelanggan **saat ini** |
| Kumulatif? | **Tidak** — tiap keterlambatan dihitung terpisah |
| Kapan dihitung? | **Sekali**, saat tagihan dibuat → **dibekukan** di `penalty_amount` |

Contoh: Februari telat → denda muncul di tagihan Maret. Maret juga telat → denda
Maret muncul di tagihan April. Dua denda terpisah, tidak digabung.

> **Denda dibekukan.** `BillRecalculationService` sengaja **tidak** menghitung ulang
> `penalty_amount` kalau tagihannya sudah ada:
> *"Menghitung ulang akan mengubah tagihan yang sudah dipublikasikan ke pelanggan."*
> Yang dihitung ulang hanya `usage_m3`, `usage_charge`, dan `total_amount`.

### 8.7 Jatuh tempo — bulan berikutnya

```php
// backend/app/Services/BillingDueDate.php:95-105
public static function inBillingMonth(int $year, int $month, int $day = self::DEFAULT_BATAS_TAGIHAN): Carbon
{
    // Bulan TAGIHAN = bulan pemakaian + 1. Ini inti aturan bisnisnya.
    $billing = Carbon::create($year, $month, 1)->addMonthNoOverflow();

    // Tanggal yang diset dijepit ke hari terakhir bulan tagihan.
    $effectiveDay = min(max($day, 1), $billing->daysInMonth);

    return $billing->setDay($effectiveDay)->startOfDay();
}
```

**Aturan bisnis: pemakaian dibayar pada bulan BERIKUTNYA.**

| Periode pemakaian | `batas_tagihan` | Jatuh tempo |
|---|---|---|
| Juni 2026 | 27 | **27 Juli 2026** |
| Juni 2026 | 31 | 31 Juli 2026 |
| Februari 2026 | 31 | 28 Februari 2026 (2026 bukan tahun kabisat) |
| Desember 2026 | 27 | 27 Januari 2027 |

> Ini **sama** dengan aplikasi lama (`siap_mapan`), yang memakai
> `date('Y-m', strtotime('+1 month', $tglPakai)) . '-' . toleransi`.
> Versi ASTALOKA sebelumnya salah (jatuh tempo di bulan yang sama) dan sudah
> dikoreksi oleh migrasi `2026_10_30_000001_shift_bill_due_date_to_next_month`.

**Default `batas_tagihan`**: `27` (`BillingDueDate::DEFAULT_BATAS_TAGIHAN`).
Tapi database memakai default `10`, dan provisioning lokasi baru juga memakai `10`.

### 8.8 Tagihan Rp 0 — dua kasus berbeda

```php
// backend/app/Services/BillingService.php:76-88
$isNihilLegitimate = $usageM3 <= 0 && $totalAmount <= 0;

if ($usageM3 > 0 && $totalAmount <= 0) {
    Log::warning('Tagihan Rp 0 padahal pelanggan memakai layanan — konfigurasi paket kemungkinan bermasalah', [...]);
}

$status = $isNihilLegitimate ? 'paid' : 'unpaid';
```

| Kasus | Kondisi | Status | Alasan |
|---|---|---|---|
| **Nihil sah** | `usage_m3 = 0` dan `total = 0` | `paid` | Pelanggan tidak pakai air → tidak ada yang ditagih |
| **Nihil rusak** | `usage_m3 > 0` tapi `total = 0` | `unpaid` + log warning | Paket belum punya blok tarif, atau semua `price_per_m3` = 0 |

Kasus kedua **sengaja dibiarkan `unpaid`** supaya tagihannya Rp 0 tetap terlihat di
daftar tagihan dan bisa dibetulkan admin. Kalau dibiarkan `paid`, pelanggan yang
benar-benar memakai air dapat air gratis dan paket rusak ikut tersembunyi selamanya.

### 8.9 Tabel `monthly_bills`

| Kolom | Tipe | Keterangan |
|---|---|---|
| `customer_id` | bigint | FK → `customers`, cascade delete |
| `billing_period_year` | int | Tahun **pemakaian** |
| `billing_period_month` | int | Bulan **pemakaian** (1–12) |
| `meter_reading_start` | decimal(10,2) | Meter awal periode |
| `meter_reading_end` | decimal(10,2) | Meter akhir periode |
| `usage_m3` | decimal(10,2) | Total pemakaian |
| `usage_charge` | decimal(12,2) | Biaya pemakaian (hasil tarif FLAT) |
| `abodemen` | decimal(12,2) | Abodemen (snapshot dari paket) |
| `penalty_amount` | decimal(12,2) | Denda (default 0, **dibekukan**) |
| `total_amount` | decimal(12,2) | Total tagihan |
| `status` | enum('unpaid','paid') | Status pembayaran |
| `due_date` | date | Jatuh tempo |
| `deleted_at` | timestamp | Soft delete |

Index: `idx_bills_customer_period (customer_id, billing_period_year, billing_period_month)`,
plus index pada `status`.

> ⚠️ **Tidak ada unique constraint** di `(customer_id, year, month)` — hanya index.
> Yang punya unique adalah `meter_readings (customer_id, reading_year, reading_month)`.

### 8.10 Hitung ulang tagihan

`PUT /monthly-bills/usage` dipakai admin/technisi untuk memperbaiki angka meter.

**Alur:** update meter → `recalculateForCustomer()` → `syncOverdueJournals()` →
**rantai ke periode berikutnya**.

```php
// backend/app/Http/Controllers/MonthlyBillController.php:1491-1503
[$nextYear, $nextMonth] = $this->billRecalculationService->nextPeriod($year, $month);
$nextBill = MonthlyBill::where('customer_id', $customer->id)
    ->where('billing_period_year', $nextYear)
    ->where('billing_period_month', $nextMonth)
    ->first();

if ($nextBill && ! $this->billRecalculationService->isBillPaid($nextBill)) {
    $this->billRecalculationService->recalculateForCustomer($customer, $nextYear, $nextMonth);
}
```

**Rantai ini penting**: memperbaiki meter Juni mengubah tagihan Juni **dan** Juli
(karena meter awal Juli = meter akhir Juni). Kalau tidak dirantai, tagihan Juli
menjadi tidak sinkron.

#### Data demo ikut rumus yang sama

Database demo (`astaloka_demo`, `astaloka_bumdesmart`) sudah dihitung ulang ke
rumus FLAT, termasuk sisi pembukuannya:

| Yang ikut dihitung ulang | Kenapa |
|---|---|
| `monthly_bills.usage_charge` + `total_amount` | komponen tagihan |
| `bill_payments.amount_paid` | pembayaran yang tercatat = total tagihan |
| `transactions` (`bill_payment` **dan** `overdue_bill`) | jurnal harus sama dengan komponen tagihan |
| `installation_ticket_histories.total_billed_on_old_package` | snapshot yang ditampilkan `PackageDetailModal.vue` |
| `amount` | rekap per bulan (`AmountRecalculator::compute()`, **bukan** definisi YTD yang dipakai trigger MySQL) |

Hasil verifikasi kedua DB: `usage_charge` = pemakaian × harga paket untuk
**seluruh** tagihan, `total_amount` = pemakaian + abodemen + denda,
`bill_payments.amount_paid` = `total_amount` (selisih 0), seluruh jurnal sama
dengan komponennya, `amount` cocok dengan rekap per bulan, dan total debit =
total kredit (7.679.500 = 7.679.500).

`tenancy:seed-pelanggan --force` juga menghasilkan tagihan FLAT yang sama
persis — perintah itu memanggil `BillingService::generateForCustomer()`, bukan
menyalin rumusnya, jadi tidak bisa menyimpang dari `calculateUsageCharge()`.

### 8.11 Status tagihan — sumber kebenaran

Status dihitung ulang saat halaman tagihan dibuka, dari **tiga sumber**:

```php
// backend/app/Http/Controllers/MonthlyBillController.php:392-433
$txPaid   = $paidAmountByBillId[$bill->id] ?? 0;              // SUM(saldo) jurnal bill_payment
$bpPaid   = $amountPaidFallbackByBillId[$bill->id] ?? 0;      // SUM(bill_payments.amount_paid)
$billStatusPaid = $bill && strtolower((string) $bill->status) === 'paid';

$paidAmount = $txPaid > 0 ? $txPaid : ($billStatusPaid && $bpPaid > 0 ? $bpPaid : 0);

$isNihilBill = $bill && $baseTotal <= 0 && $billStatusPaid;

$statusLabel = ($paidAmount > 0 || $isNihilBill)
    ? 'PAID'
    : ($bill || $reading ? 'UNPAID' : 'PENDING');
```

| Label | Kondisi |
|---|---|
| `PAID` | Ada jurnal `bill_payment` dengan SUM(saldo) > 0, **atau** fallback `bill_payments` (hanya jika status tagihan = paid), **atau** tagihan nihil sah |
| `UNPAID` | Ada tagihan atau pencatatan, tapi belum ada pembayaran |
| `PENDING` | Belum ada tagihan maupun pencatatan |

> **Denda tidak ditambah ulang saat tampil** — sudah termasuk di `total_amount`.

### 8.12 Guard generator tagihan massal

`MonthlyBillService::generate()` menolak dengan dua guard:

| Guard | Pesan |
|---|---|
| Sudah ada tagihan periode ini | *"Tagihan bulan ini sudah pernah digenerate"* |
| Meter belum lengkap | *"Masih ada pelanggan yang belum dicatat meternya"* |

Guard kedua membandingkan `COUNT(meter_readings periode) < COUNT(customers)`.

> Catatan: `BillingController::generate()` memakai pemeriksaan berbeda —
> `Customer::whereDoesntHave('meterReadings' ...)`. Dua jalur, dua implementasi
> guard yang sama. Sebaiknya diseragamkan.

---

## 9. ⭐ Pengakuan Piutang Tunggakan

### 9.1 Kapan jurnal piutang dibuat

Tagihan yang menunggak harus **diakui sebagai piutang** di akun `1.1.03.01`,
supaya Neraca dan pelaporan tidak under-report.

**Dua pemicu, keduanya harus hidup:**

| Pemicu | Kapan | Kode |
|---|---|---|
| **Scheduler** | Setiap malam 00:01, untuk semua lokasi aktif | `routes/console.php` → `ReceivableGenerator::runIfDue()` |
| **Login staff** | Saat admin/teknisi login **tepat pada tanggal generate** | `AuthController` → `ReceivableGenerator::runIfDue()` |

### 9.2 Gate `===` — hanya tanggal yang di-set

```php
// backend/app/Services/ReceivableGenerator.php:140-151
public function shouldRun(?Carbon $now = null): bool
{
    $now ??= now();
    $scheduledDay = $this->effectiveScheduledDay($now);

    if ($scheduledDay < 1) {
        return false;
    }

    return $now->day === $scheduledDay;
}
```

**Perbandingan ketat `===`.** Kalau tanggal generate = 28:

| Gate | Perilaku |
|---|---|
| `>=` (lama) | Jalan pada tanggal 28, 29, 30, 31 |
| `===` (sekarang) | Jalan **HANYA** pada tanggal 28 |

**Konsekuensi yang disengaja:** kalau tidak ada staff yang login tepat pada tanggal
itu, generate bulan itu **tidak pernah** jalan. Tagihan tetap `unpaid` di
`monthly_bills` tapi tidak pernah membebani akun Piutang Usaha — **dua sumber
kebenaran yang tidak sinkron**. Penutupnya adalah scheduler harian: ia memanggil
`runIfDue()` untuk semua lokasi setiap malam.

> Kalau scheduler mati **dan** tidak ada yang login tepat pada tanggalnya, bulan itu
> memang terlewat — dan itu harus terlihat, bukan tersembunyi.

### 9.3 Tanggal generate yang benar-benar ada

```php
// backend/app/Services/BillingTolerance.php:156-166
public function effectiveGenerateDay(?Carbon $now = null): int
{
    $now ??= now();
    $day = $this->generateDay();

    if ($day === 0) {
        return 0;
    }

    return min($day, $now->daysInMonth);
}
```

| Diset | Oktober | Februari |
|---|---|---|
| 28 | 28 | 28 |
| 29 | 29 | 28 |
| 31 | 31 | 28 (atau 29 di tahun kabisat) |

> Docblock `BillingTolerance` menjelaskan kenapa batasnya 1–31, bukan 1–28:
> dengan batas 28, admin yang menyetel tanggal 29 akan mendapat `generateDay()` = 0,
> yang dibaca sebagai "SOP belum diisi" — jadi generate **TIDAK PERNAH** jalan.
> Tidak ada error, tidak ada popup, tidak ada log. Persis gejala
> *"sudah saya set tanggalnya tapi kok generate tidak jalan?"*.

### 9.4 Satu kolom tanggal untuk semua aturan berbasis tanggal

**Dulu: satu kolom dibaca sebagai empat hal berbeda.** `settings.toleransi_tunggakan`
dipakai sebagai: (1) tanggal generate piutang, (2) ambang piutang mundur dari hari
run, (3) ambang deteksi advance payment, dan (4) batas tagihan unpaid sebelum
suspend.

**Sekarang: makna (1)–(3) digabung jadi SATU angka, dan (4) pindah ke kolom
sendiri.**

| # | Makna | Satuan | Accessor | Sumber | Pemakai |
|---|---|---|---|---|---|
| 1 | Tanggal generate piutang dalam bulan | hari 1–31 | `generateDay()` / `effectiveGenerateDay()` | `settings.batas_tagihan` | `ReceivableGenerator` |
| 2 | Ambang piutang mundur dari hari run | **jumlah hari** | `graceDays()` | `settings.batas_tagihan` | `GenerateOverdueTransactions` |
| 3 | Ambang deteksi advance payment | tanggal di bulan tagihan | `advanceDayOfMonth()` | `settings.batas_tagihan` | `MonthlyBillController::pay()` |
| 4 | Batas tagihan unpaid sebelum suspend | **jumlah bulan** | `unpaidBillLimit()` | `settings.billing_unpaid_limit` | `MeterReadingController::store()` |

Ketiganya satuan dan maknanya sama: "hari ke berapa dalam bulan". Dua input untuk
satu satuan yang sama bikin admin mengira ada dua aturan terpisah, padahal
tujuannya satu. Halaman SOP sekarang hanya punya SATU input tanggal.

```php
// backend/app/Services/BillingTolerance.php
public function tanggal(): int
{
    $batas = (int) ($this->settings()->batas_tagihan ?? 0);

    if ($batas >= 1 && $batas <= 31) {
        return $batas;
    }

    $toleransi = (int) ($this->settings()->toleransi_tunggakan ?? 0);

    return ($toleransi >= 1 && $toleransi <= 31) ? $toleransi : 0;
}
```

**Mengapa sumbernya `batas_tagihan`, bukan `toleransi_tunggakan`?**

`monthly_bills.due_date` yang sudah tersimpan di database dihitung dari
`batas_tagihan`. Kalau sumbernya `toleransi_tunggakan` (= 30 di lokasi demo),
jatuh tempo tagihan BARU bergeser ke tanggal 30 sementara tagihan LAMA tetap
tanggal 27 — satu bulan tagihan yang sama punya dua jatuh tempo berbeda. Dengan
`batas_tagihan` (= 27) sebagai sumber, tagihan lama dan baru sama saja.

`settings.toleransi_tunggakan` **tidak dihapus**: kolomnya tetap ada dan tetap
diisi dengan angka yang sama (salinan). Dipakai import legacy dan dilacak lewat
banyak query lama. Migration tenant
`2026_10_31_000002_sync_toleransi_tunggakan_with_batas_tagihan` menyamakan
keduanya secara idempoten, tanpa pernah menyentuh `batas_tagihan`.

> [!IMPORTANT]
> **Fallback ke `toleransi_tunggakan` hanya satu arah.** Kalau `batas_tagihan`
> tidak berisi tanggal yang sah (0, atau di luar 1–31), `toleransi_tunggakan`
> dipakai sebagai cadangan — asalkan itu juga tanggal yang sah. Baris dengan
> `batas_tagihan` = 0 adalah baris lama yang belum pernah mengisi SOP; dulu
> baris seperti itu tetap punya generate piutang yang jalan pada tanggalnya, jadi
> membaca `toleransi_tunggakan` sebagai cadangan mempertahankan perilaku lama
> persis. Arah sebaliknya (nilai 0 di `toleransi_tunggakan` berarti "generate
> tidak dijadwalkan") tidak ada — jadi `toleransi_tunggakan` = 0 tidak pernah
> berarti apa-apa setelah digabung.
>
> Perilaku "di luar 1–31 = SOP belum diisi" dijaga dengan benar:
> `generateDay()` mengembalikan 0 untuk nilai di luar 1–31, dan
> `ReceivableGenerator::scheduledDay()` membacanya lewat accessor itu — bukan
> langsung dari `Setting`.

**Bug yang ikut hilang.** Dulu ambang advance (makna 3) dibaca dari
`toleransi_tunggakan` (30) sementara `due_date` dihitung dari `batas_tagihan`
(27). Pembayaran pada tanggal 28, 29, dan 30 dianggap `ADVANCE` padahal
tagihannya belum jatuh tempo. Sekarang ambang advance = tanggal jatuh tempo.

**Kelima makna** yang mirip tapi **harus dipisahkan**: masa tenggang tagihan
langganan ASTALOKA. Satuannya **BULAN**, bukan hari, dan berlaku untuk tagihan
pusat → lokasi:

```php
// backend/app/Services/BillingTolerance.php
public function invoiceGraceMonths(): int
{
    return (int) config('tenancy.invoice_grace_months', 1);
}
```

### 9.5 Threshold jurnal piutang

```php
// backend/app/Console/Commands/GenerateOverdueTransactions.php:26-48
$today = $this->option('tanggal') ?: now()->toDateString();
$toleransi = app(BillingTolerance::class)->graceDays();
$thresholdDate = date('Y-m-d', strtotime("$today -$toleransi days"));

$overdueBills = MonthlyBill::where('status', 'unpaid')
    ->where('due_date', '<', $thresholdDate)
    ->with(['customer.user'])->get();
```

**Rumus: `due_date < (hari generate − graceDays() hari)`** (strict), dan
`graceDays()` = tanggal SOP (`batas_tagihan`) yang sama.

Contoh `toleransi = 3`, hari generate 28:

```
threshold = 28 Juli − 3 hari = 25 Juli
Tagihan dengan due_date < 25 Juli → dijurnalkan
Tagihan dengan due_date = 25 Juli → BELUM (strict <)
```

### 9.6 Jurnal per komponen

**Satu tagihan bisa menghasilkan 3 baris jurnal** — satu per komponen yang non-nol:

| Komponen | Sumber | Debit | Kredit | `saldo` |
|---|---|---|---|---|
| Abodemen | `bill.abodemen` | `1.1.03.01` | `4.1.01.02` | `abodemen` |
| Pemakaian | `bill.usage_charge` | `1.1.03.01` | `4.1.01.03` | `usage_charge` |
| Denda | `bill.penalty_amount` | `1.1.03.01` | `4.1.01.04` | `penalty_amount` |

Semua baris berbagi:

```php
'reverence_type' => 'overdue_bill',
'reverence_id'   => $bill->id,          // = monthly_bills.id
'tgl_transaksi'  => $today,              // tanggal GENERATE, bukan periode tagihan
'relasi'         => $customer_code,
'id_user'        => $systemUser->id,
'transaction_group' => null,
```

Keterangan: `"Piutang {Abodemen|Pemakaian|Denda} bulan {Periode} an. {Nama} ({Kode})"`.

### 9.7 Idempotensi

Kunci dedup adalah **pasangan `reverence_id|account_kredit`**:

```php
// backend/app/Console/Commands/GenerateOverdueTransactions.php:134-143
$alreadyHave = $force ? collect()
    : Transaction::where('reverence_type', 'overdue_bill')
        ->select('reverence_id', 'account_kredit')->distinct()->get()
        ->map(fn ($t) => $t->reverence_id.'|'.$t->account_kredit)->flip();
```

Konsekuensinya:

- Tagihan yang sudah punya jurnal abodemen **tetap** mendapat jurnal pemakaian.
- Baris **soft-deleted** tetap dihitung "sudah ada" (global scope) — disengaja,
  supaya jurnal tidak pernah dibuat dua kali.
- `--force` soft-delete semua jurnal `overdue_bill` lama untuk tagihan itu, lalu
  buat ulang.

**Karena itu generate boleh berulang di hari yang sama.** Kalau lima orang login
pada tanggal generate, generate jalan lima kali — dan tidak menghasilkan jurnal dobel.

### 9.8 Bus-guard, bukan blocker

```php
// backend/app/Services/ReceivableGenerator.php:301-310
if (! Cache::add($lockKey, 1, now()->addMinutes(10))) {
    return [
        'ran' => false,
        'reason' => 'Generate sedang berjalan di proses lain. Coba lagi sebentar lagi.',
        'busy' => true,
        ...
    ];
}
```

Kuncinya berbasis **tanggal hari ini** (`overdue_gen_lock_{YYYY-MM-DD}`), bukan
tanggal generate — karena yang perlu dijaga adalah "jangan agregasi `amount` dua
kali bersamaan", dan itu soal waktu.

**TTL 10 menit**, dan kuncinya **dilepas** di akhir (sukses maupun gagal), supaya
generate berikutnya (login berikutnya) **boleh** jalan lagi.

> Ini berbeda dari versi lama yang memakai cache "sudah pernah dijalankan bulan ini"
> (TTL 35 hari) — penghalang paling ketat, yang membuat generate tidak pernah bisa
> dijalankan ulang dalam sebulan.

### 9.9 Ringkasan run

Command menulis ringkasan ke cache dengan **TTL 26 jam**:

```php
// backend/app/Console/Commands/GenerateOverdueTransactions.php:526-562
$cache->flagPut('overdue_gen_run_summary', [
    'date'           => $today,
    'threshold_date' => $thresholdDate,
    'processed'      => $processed,
    'skipped'        => $skipped,
    'jurnal_abodemen' => $jurnalAbodemen,
    'jurnal_pemakaian' => $jurnalPemakaian,
    'jurnal_denda'   => $jurnalDenda,
    'total_unpaid'   => $totalUnpaid,
    'total_overdue'  => $totalOverdue,
    'timestamp'      => now()->toISOString(),
], now()->addHours(26));
```

`processed` hanya menghitung tagihan yang **benar-benar** menghasilkan ≥1 jurnal baru.

**Ringkasan basi dicek lewat `date`.** TTL 26 jam lebih lama dari satu hari, jadi
ringkasan kemarin masih hidup saat generate hari ini jalan. `runSummary()`
membandingkan `$summary['date'] !== now()->toDateString()` → `null`.

Command **selalu** menulis ulang ringkasan saat jalan — termasuk saat tidak
menemukan tagihan menunggak (angka nolnya memang nol). Satu-satunya cara ringkasan
basi sampai ke user adalah ketika command-nya sendiri tidak sempat menulis.

### 9.10 Command

```bash
# Generate untuk hari ini
php artisan billing:generate-overdue-transactions

# Tanggal acuan lain
php artisan billing:generate-overdue-transactions --tanggal=2026-07-28

# Hapus jurnal lama untuk tagihan yang sama, lalu buat ulang
php artisan billing:generate-overdue-transactions --force

# Ukuran chunk bulk insert (default 500)
php artisan billing:generate-overdue-transactions --chunk=1000
```

---

## 10. ⭐ Jurnal Pembayaran

### 10.1 Alur `POST /monthly-bills/{id}/pay`

```
Validasi
  ├─ tagihan sudah paid?            → 400
  ├─ status_pembayaran (opsional)   → cek setting
  └─ selisih nominal > Rp 1         → 422
      ↓
Tentukan advance/normal
      ↓
DB::transaction()
  ├─ lockForUpdate() → cek status lagi
  ├─ reclass jurnal piutang (kalau advance)
  ├─ update status = 'paid'
  ├─ create bill_payments
  ├─ create 3 journals (satu per komponen non-nol)
  └─ auto-restore tiket suspended
      ↓
Response
```

### 10.2 Validasi

```php
// backend/app/Http/Controllers/MonthlyBillController.php:528-564
'payment_method' => 'nullable|string|in:cash,transfer',
'amount_paid'     => ['required', 'numeric', 'gte:0'],
'paid_at_date'    => ['nullable', 'date', 'before_or_equal:today'],
```

| Aturan | Detail |
|---|---|
| Idempotensi | Tagihan `paid` → **400** *"Tagihan sudah dibayar"* |
| Toleransi selisih | `abs(amount_paid − total) > 1.0` → **422** |
| Metode bayar | `cash` (default) atau `transfer` |
| Tanggal bayar | `paid_at_date` ≤ hari ini; default `now()` |

> ⚠️ **Komentar di kode menyebut `lte:total_amount`, tapi aturan aslinya tidak
> punya batas atas.** Guard over/under-pay diimplementasikan manual di baris
> 630–648 dengan toleransi ±1 rupiah. Selisih **tepat 1 rupiah** lolos.

### 10.3 Advance vs Normal

```php
// backend/app/Http/Controllers/MonthlyBillController.php:707-746
$toleransiTgl = app(BillingTolerance::class)->advanceDayOfMonth();
$isAdvancePayment = false;
$thresholdDate = null;

if ($toleransiTgl >= 1) {
    $thresholdYear  = (int) ($bill->billing_period_year ?? 0);
    $thresholdMonth = (int) ($bill->billing_period_month ?? 0);

    if ($thresholdYear < 1 || $thresholdMonth < 1) {
        // Fallback legacy: threshold = due_date itu sendiri
        $dueRef = Carbon::parse($bill->due_date);
        $thresholdDate = $dueRef->startOfDay();
        $isAdvancePayment = $paidAtDate->lt($thresholdDate);
    } else {
        $thresholdDate = BillingDueDate::inBillingMonth($thresholdYear, $thresholdMonth, $toleransiTgl);
        $isAdvancePayment = $paidAtDate->lt($thresholdDate);
    }
}
```

**Rumus:**

```
threshold = BillingDueDate::inBillingMonth(billing_period_year, billing_period_month, batas_tagihan)
isAdvance = paid_at_date < threshold
```

> Angka ambang advance sekarang **sama** dengan tanggal jatuh tempo
> (`batas_tagihan`). Dulu keduanya bisa berbeda — ambang advance dibaca dari
> `toleransi_tunggakan` sementara `due_date` dihitung dari `batas_tagihan` —
> sehingga pembayaran tanggal 28-30 salah flagged `ADVANCE` padahal tagihannya
> belum jatuh tempo. Lihat [§9.4](#94-satu-kolom-tanggal-untuk-semua-aturan-berbasis-tanggal).

Contoh `toleransi = 10`, tagihan pemakaian Agustus 2026:

```
threshold = 10 September 2026
Bayar 9 September 2026  → ADVANCE
Bayar 10 September 2026 → NORMAL (tidak < threshold)
Bayar 15 September 2026 → NORMAL
```

> Threshold dihitung di **bulan tagihan** (bulan pemakaian + 1), sama seperti
> jatuh tempo. Frontend `BillingForm.vue` menghitung ulang hal yang sama untuk
> pratinjau — kalau angkanya berbeda, user melihat status advance yang salah.

### 10.4 Rekening kas

```php
// backend/app/Http/Controllers/MonthlyBillController.php:654-659
$paymentMethod = $request->input('payment_method', 'cash');
$accountDebetKas = $paymentMethod === 'transfer' ? '1.1.01.03' : '1.1.01.01';
```

| Metode | Rekening debit |
|---|---|
| `cash` (default) | `1.1.01.01` — Kas Tunai |
| `transfer` | `1.1.01.03` — Kas di Bank BRI |

### 10.5 Routing kredit — per komponen

```php
// backend/app/Http/Controllers/MonthlyBillController.php:755-786
$revenueAbodemen  = $piutangRows->has('4.1.01.02') ? '1.1.03.01' : '4.1.01.02';
$revenuePemakaian = $piutangRows->has('4.1.01.03') ? '1.1.03.01' : '4.1.01.03';
$revenueDenda     = $piutangRows->has('4.1.01.04') ? '1.1.03.01' : '4.1.01.04';
```

**Kuncinya: kredit bergantung pada apakah komponen itu sudah pernah dijurnalkan sebagai piutang.**

| Komponen | Ada jurnal piutang? | Kredit |
|---|---|---|
| Abodemen | Ya | `1.1.03.01` (Piutang Usaha) — **menutup** piutang |
| Abodemen | Tidak | `4.1.01.02` (Pendapatan Abodemen) |
| Pemakaian | Ya | `1.1.03.01` |
| Pemakaian | Tidak | `4.1.01.03` (Tagihan Pemakaian) |
| Denda | Ya | `1.1.03.01` |
| Denda | Tidak | `4.1.01.04` (Denda) |

### 10.6 Tiga baris jurnal

Semua di dalam satu `DB::transaction()`:

| Komponen | Kondisi | Debit | Kredit | `saldo` |
|---|---|---|---|---|
| Abodemen | `abodemen > 0` | kas | `$revenueAbodemen` | `abodemen` |
| Pemakaian | `usage_charge > 0` | kas | `$revenuePemakaian` | `usage_charge` |
| Denda | `denda > 0` | kas | `$revenueDenda` | `denda` |

Semua baris berbagi:

```php
'tgl_transaksi'        => $payment->paid_at,
'reverence_type'       => 'bill_payment',
'reverence_id'         => $payment->id,   // = bill_payments.id
'transaction_group'    => null,
'relasi'               => $customer_code,
'id_user'              => Auth::id(),
```

Keterangan: `"Tagihan {Abodemen|Pemakaian|Denda} bulan {Periode} an. {Nama} ({Kode}) - {Tunai|Transfer BRI}"`.

### 10.7 Reclass ADVANCE

Kalau tagihan dibayar **sebelum** ambang advance **dan** sudah ada jurnal piutang,
jurnal piutang itu **direklasifikasi** — bukan dihapus:

```php
// backend/app/Http/Controllers/MonthlyBillController.php:820-864
$trxIdsRevert = Transaction::where('reverence_type', 'overdue_bill')
    ->where('reverence_id', $bill->id)
    ->where('account_debet', '1.1.03.01')
    ->pluck('id');

foreach ($trxIdsRevert as $revertId) {
    DB::table('transactions')->where('id', $revertId)->update([
        'account_debet'  => $accountDebetKas,
        'tgl_transaksi' => $paidAtDate->toDateString(),
        'updated_at'    => now(),
    ]);
}
```

**`account_kredit` sengaja dibiarkan** di kode pendapatan aslinya — itu jejak audit:
"Akun ini pernah Generates sebagai piutang, lalu dibayar."

> Update lewat `DB::table()` supaya observer `Transaction::updated` tidak menjalankan
> agregasi `amount` penuh sekali lagi. Trigger MySQL tetap yang dipakai untuk
> sinkronisasi `amount`.

### 10.8 Guard konkurensi

```php
// backend/app/Http/Controllers/MonthlyBillController.php:814-817
$bill = MonthlyBill::lockForUpdate()->findOrFail($bill->id);

if ($bill->status === 'paid') {
    throw new \RuntimeException('TAGIHAN_SUDAH_DIBAYAR');
}
```

`lockForUpdate()` = `SELECT ... FOR UPDATE`. Pemeriksaan di luar transaksi
(baris 568) hanya **fast path**; yang menentukan ada di dalam lock.

Penanganan error:

| Kondisi | HTTP | Pesan |
|---|---|---|
| `TAGIHAN_SUDAH_DIBAYAR` | 400 | *"Tagihan sudah dibayar"* |
| SQLSTATE `40001`, `40P01`, `HY000` | 503 | *"Server sedang sibuk, pembayaran gagal diproses. Silakan coba lagi."* |

### 10.9 Auto-restore tiket suspended

```php
// backend/app/Http/Controllers/MonthlyBillController.php:957-968
if ($customer && $customer->ticket && $customer->ticket->status === 'suspended') {
    $stillUnpaid = MonthlyBill::where('customer_id', $customer->id)
        ->where('status', 'unpaid')->count();

    if ($stillUnpaid === 0) {
        $customer->ticket->update(['status' => 'completed']);
        $restoredTicket = true;
    }
}
```

### 10.10 Rollback pembayaran

`DELETE /monthly-bills/{id}` — hanya untuk tagihan `paid`. Menghapus jurnal
`bill_payment`, menghapus `bill_payments`, set status kembali `unpaid`, semua dalam
satu transaksi.

Tagihan dengan `total_amount <= 0` **ditolak** (400) — tagihan nihil tidak punya
baris pembayaran.

### 10.11 Perbedaan dengan `BillPaymentService`

`BillPaymentService::recordPayment()` (dipakai `KomisiSPSController`) **berbeda**:

| Aspek | `MonthlyBillController::pay()` | `BillPaymentService` |
|---|---|---|
| Jumlah jurnal | **3** (satu per komponen) | **1** (satu untuk seluruh tagihan) |
| Kredit | `1.1.03.01` atau kode pendapatan | **`2.1.02.02`** (Utang FEE Kolektor) |
| Set status tagihan | Ya (`paid`) | Tidak — pemanggil yang men-set |
| `transaction_group` | `null` | `2` |
| `amount_paid` | `total_amount` | `total_amount` |

---

## 11. ⭐ Akuntansi: Amount, Tutup Buku, Alokasi Laba

### 11.1 Tabel `amount`

Tabel ini menyimpan **mutasi per bulan** per akun — bukan snapshot kumulatif.
Strukturnya:

```php
// backend/app/Services/AmountRecalculator.php:46-47
$bulan = str_pad($month, 2, '0', STR_PAD_LEFT);
$key   = $accountId . $tahun . $bulan;
```

`id = account_id . tahun . bulan` (mis. `594202607`).

| Kolom | Isi |
|---|---|
| `debit` | `SUM(saldo)` dari transaksi di mana akun itu `account_debet` |
| `kredit` | `SUM(saldo)` dari transaksi di mana akun itu `account_kredit` |

**Tiga sumber komputasi — DAN BERBEDA SEMANTIKA:**

| Sumber | Periode | Dipakai oleh |
|---|---|---|
| **Trigger MySQL** | **Jan 1 → akhir bulan** (YTD) | `create_amount_debit`, `update_amount_debit`, `delete_amount_debit` |
| `TransactionObserver` | **Jan 1 → akhir bulan** (YTD) | Update/delete transaksi |
| `AmountRecalculator::compute()` | **Per bulan** (bukan YTD) | `legacy:rebuild-amount`, `tenancy:seed-pelanggan` |

> ⚠️ **Ini adalah penyimpangan yang belum diselesaikan.** `GenerateOverdueTransactions`
> sengaja mengikuti semantik trigger (YTD) — dengan komentar eksplisit di
> `app/Console/Commands/GenerateOverdueTransactions.php:429-443`.
> Tapi `AmountRecalculator` memakai per-bulan. Kalau keduanya menulis baris yang
> sama dengan angka berbeda, tabel `amount` jadi tidak konsisten.
>
> **Status: ⬜ perlu diselesaikan.** Lihat §17.

### 11.2 Trigger MySQL

Tiga trigger dibuat di migrasi `2026_06_23_034006_create_amount_table.php`, dan
diekstrak verbatim ke `app/Support/TransactionTriggers.php`:

| Trigger | Kapan |
|---|---|
| `create_amount_debit` | INSERT ke `transactions` |
| `update_amount_debit` | UPDATE `transactions` |
| `delete_amount_debit` | DELETE `transactions` (soft delete) |

Konsekuensi: tabel `amount` **tidak bisa dipindah ke database pusat**, dan
`legacy:rebuild-amount` dibuat untuk memperbaikinya.

### 11.3 Tutup Buku

**Penanda tahun tertutup:** baris `amount` dengan `tahun = Y` dan `bulan = '13'`.

```php
// backend/app/Http/Controllers/TutupBukuController.php:27-32
$closing = DB::table('amount')->where('tahun', $year)->where('bulan', '13')->first();
$isClosed = $closing ? true : false;
```

#### Dua baris yang ditulis

| Baris | Isi |
|---|---|
| `bulan = '13'` tahun Y | Saldo akhir tahun untuk **semua** akun aktif |
| `bulan = '00'` tahun Y+1 | Carry-over — **hanya akun neraca** (1.x, 2.x, 3.x) |

```php
// backend/app/Http/Controllers/TutupBukuController.php:331-341
$first = substr($a->kode_akun, 0, 1);

if ($first === '4' || $first === '5') {
    continue;   // pendapatan & beban TIDAK dibawa ke tahun berikutnya
}
```

> Pendapatan (4.x) dan beban (5.x) **harus** ditutup ke laba ditahan lewat
> `AlokasiLabaController` — kalau tidak, neraca tahun berikutnya tidak seimbang.

#### Saldo penutup — kumulatif sejak awal pembukuan

```php
// backend/app/Http/Controllers/TutupBukuController.php:217-258
$end = "{$tahun}-12-31";
// SUM(saldo) WHERE account_debet = kode AND deleted_at IS NULL AND tgl_transaksi <= $end
// SUM(saldo) WHERE account_kredit = kode AND deleted_at IS NULL AND tgl_transaksi <= $end
$saldo = self::isKredit($a->jenis_mutasi) ? ($kredit - $debit) : ($debit - $kredit);
```

**Bukan** sekadar pergerakan tahun itu — tapi kumulatif dari awal pembukuan sampai
31 Desember tahun tersebut. Tanda mengikuti `jenis_mutasi` akun (kredit → `kredit − debit`).

#### Baris Laba/Rugi Tahun Berjalan

```php
// backend/app/Http/Controllers/TutupBukuController.php:260-268
if ($first === '4') $labaRugi += $saldo;       // pendapatan
elseif ($first === '5') $labaRugi -= $saldo;  // beban
```

Kalau `|labaRugi| >= 0.01`, ditulis baris untuk akun `akun_laba_rugi_berjalan`
(default `3.2.02.01`).

#### Config

Disimpan di `settings` key `tutup_buku_config` (JSON):

| Key | Default |
|---|---|
| `akun_laba_ditahan` | `3.2.01.01` |
| `akun_laba_rugi_berjalan` | `3.2.02.01` |

#### Guard

| Kondisi | Hasil |
|---|---|
| Tahun di masa depan | 422 |
| Tahun sudah ditutup | 422 *"Buku untuk tahun X sudah ditutup."* |
| Override saldo per akun | `overrides[kode] = saldo` (opsional) |

#### Override

`POST /tutup-buku/close` menerima array `overrides` berisi `{kode, saldo}` yang
**menggantikan** saldo hasil hitungan. Ini yang dipakai untuk koreksi pembukuan.

### 11.4 Alokasi Laba

**Prasyarat: tahun harus sudah ditutup** (baris `bulan = '13'` ada).

#### Default 50/50

```php
// backend/app/Http/Controllers/AlokasiLabaController.php:377-421
$default = [
    'dibagikan' => [
        ['kode_akun' => '2.1.01.01', 'nama_akun' => 'Utang Dividen Pemdes',
         'kategori' => 'Dividen Pemdes (50%)', 'persen' => 50],
        ['kode_akun' => '2.1.01.02', 'nama_akun' => 'Utang Dividen Masy Penyerta Modal',
         'kategori' => 'Dividen Masy Penyerta Modal (50%)', 'persen' => 50],
    ],
    'ditahan' => [
        ['kode_akun' => '3.1.01.01', 'nama_akun' => 'Modal Pemdes',
         'kategori' => 'Pemupukan Modal (0%)', 'persen' => 0],
    ],
];
```

> Komentarnya: *"dibagi rata karena tidak ada ketentuan RUPS yang tercatat dalam sistem"*.
> Persentase bisa diubah lewat `settings` key `alokasi_laba_config`, dan
> `saveConfig()` memvalidasi totalnya **tepat 100**.

#### Hitung laba/rugi

```php
// backend/app/Http/Controllers/AlokasiLabaController.php:336-357
$pendapatan = amount (tahun, bulan='13') JOIN accounts WHERE kode_akun LIKE '4.%'
              → SUM(kredit - debit)
$biaya      = amount (tahun, bulan='13') JOIN accounts WHERE kode_akun LIKE '5.%'
              → SUM(debit - kredit)
return (float)$pendapatan - (float)$biaya;
```

Dibaca dari **baris `bulan = '13'`**, bukan dari `transactions`.

#### Jurnal alokasi

```php
// backend/app/Http/Controllers/AlokasiLabaController.php:151-187
[
    'tgl_transaksi'        => $tahun . '-12-31',
    'account_debet'        => '3.2.01.01',          // hardcoded Laba Ditahan
    'account_kredit'       => $akun->kode_akun,     // 2.1.01.01 / 2.1.01.02
    'transaction_group'    => $group,
    'reverence_type'       => 'alokasi_laba',
    'reverence_id'         => $tahun,
    'keterangan_transaksi' => "Alokasi Laba {$tahun} - {$akun->nama_akun}",
    'saldo'                => $nominal,
    'urutan'               => $urutan++,
    'id_user'              => $userId,
]
```

Akun `3.2.01.01` sendiri **dilewati** — laba ditahan yang tersisa tidak perlu
dikurangi; jurnalnya sudah ada sebagai sample debit.

#### Guard

| Kondisi | Hasil |
|---|---|
| Tahun belum ditutup | 422 *"Buku tahun X belum ditutup."* |
| `|total_laba − Σnominal| >= 0.01` | 422 |
| Akun `3.2.01.01` tidak ada | 422 |
| Sudah pernah disimpan | 422 *"Alokasi laba untuk tahun X sudah pernah disimpan."* |

Idempotensi dicek lewat `transactions` dengan `reverence_type = 'alokasi_laba'` dan
`reverence_id = $tahun`.

> **Catatan:** endpoint `calculate()` mengembalikan `persen => 0` dan `nominal => 0`
> untuk semua baris — persentase di config bersifat **konfigurasi saja**. Split
> sebenarnya dihitung di frontend (`alokasiLaba.vue`) dan harus input manual
> supaya jumlahnya tepat sama dengan laba.

### 11.5 Komisi SPS

```php
// backend/app/Http/Controllers/KomisiSPSController.php:24
private const KOMISI_RATE = 0.1;
```

Komisi **dihitung per tagihan**, lalu dijumlahkan:

```php
// backend/app/Http/Controllers/KomisiSPSController.php:220-223
$komisiPerBill = $bills->mapWithKeys(fn ($bill) => [
    $bill->id => round((float) $bill->total_amount * self::KOMISI_RATE, 2),
]);
$nominalKomisi = round($komisiPerBill->sum(), 2);
```

Komentar di kode menjelaskan kenapa tidak pakai total × 10% langsung:
*"Jurnal di bawah membulatkan 10% tiap tagihan secara terpisah, jadi kalau
menghitung dari total, selisihnya bisa berbeda."*

---

## 12. Pelaporan

### 12.1 Arsitektur

```
Backend:  Controller → JSON
Frontend: PelaporanPreview.vue → chunking → preview → print → PDF (jsPDF + html2canvas)
```

Tidak ada wkhtmltopdf, tidak ada server-side rendering.

### 12.1.1 Daftar jenis laporan

Sumber: tabel `jenis_laporans` (18 baris, diisi migration tenant
`2026_06_08_072611_create_jenis_laporans_table.php`). Kolom `file` adalah
kunci yang dicari `PelaporanController::$handlerMap`.

| # | Nama | `file` |
|---|---|---|
| 1 | Cover | `cover` |
| 2 | Surat Pengantar | `surat_pengantar` |
| 3 | Jurnal Transaksi | `jurnal_transaksi` |
| 4 | Buku Besar | `buku_besar` |
| 5 | Neraca Saldo | `neraca_saldo` |
| 6 | Neraca | `neraca` |
| 7 | Laba / Rugi | `laba_rugi` |
| 8 | Laporan Arus Kas | `arus_kas` |
| 9 | Laporan Perubahan Modal | `LPM` |
| 10 | Catatan Atas Laporan Keuangan | `calkk` |
| 11 | Daftar Pelanggan | `daftar_pelanggan` |
| 12 | Daftar Tagihan Pelanggan | `tagihan_pelanggan` |
| 13 | Daftar Piutang Pelanggan | `piutang_pelanggan` |
| 14 | Daftar Aset Tetap dan Investasi | `ati` |
| 15 | Daftar Aset Tak Berwujud | `atb` |
| 16 | E-Budgeting | `e_budgeting` |
| 17 | Awal Tahun (tutup buku) | `tutup_buku` |
| 18 | Daftar Utang Komisi SPS | `piutang_komisi` |

Sub-laporan (tabel `sub_laporans`, 10 baris) menambah variasi isi di dalam
laporan: Daftar Pelanggan Aktif/Diblokir/Dicabut/Pemasangan Baru, Daftar
Tagihan Detail/Per Desa/Per Kecamatan, Daftar Piutang Detail/Per Desa/Per
Kecamatan.

> `handlerMap` juga menerima alias `calk` → `calkk` dan `tutup_buku` →
> `awal_tahun`, dan `LPM` (huruf besar) — bukan `lpm`. `PelaporanPreview.vue`
> punya **24 komponen view**, termasuk 5 varian tutup buku
> (`tutup_buku_alokasi_laba`, `tutup_buku_neraca`, `tutup_buku_laba_rugi`,
> `tutup_buku_jurnal`, `tutup_buku_calk`).

### 12.2 Halaman cetak

Ada **6 route cetak** yang berada di luar `MainView` (top-level), supaya bisa
diakses tanpa sidebar:

| Route | Isi |
|---|---|
| `/usages/cetak_input` | Form input meter |
| `/usages/cetak_form` | Form pencatatan |
| `/usages/cetak_daftar_tagihan` | Daftar tagihan |
| `/usages/cetak_struk` | Struk pembayaran |
| `/usages/cetak_bukti_transaksi` | Bukti transaksi |
| `/app/pelaporan/preview` | Preview laporan |

### 12.3 Chunking per laporan

| view_target | Strategi |
|---|---|
| `cover`, `surat_pengantar` | 1 halaman |
| `daftar_pelanggan/tagihan/piutang` | Chunk per **grup desa+dusun** (19/25 baris) |
| `neraca_saldo` | Chunk 25 baris, `summary` hanya di halaman terakhir |
| `buku_besar` | Chunk 20, **`startRunningSaldo` dibawa antar halaman** |
| `jurnal_transaksi` | Chunk 20 item (~40 baris tabel) |
| `laba_rugi` / `tutup_buku_laba_rugi` | Flatten grup → `flatRows`, chunk 45 |
| `e_budgeting`, `piutang_komisi` | Chunk 20 |
| `ati` / `atb` | **1 halaman per kategori aset** |
| `calk` | **Probe offscreen** — ukur tinggi asli, hitung chunk per halaman |

Konstanta `PER_PAGE_ROWS = 50` dan `FIRST_PAGE_ROWS = 48` ada di
`frontend/src/utils/reportConfig.js`. **Nilai ini diukur** dengan
`tools/ukur-cetak-form.mjs` (headless-Chrome) — **jangan diubah tanpa menjalankan
ulang tool-nya**.

### 12.4 Format halaman

| Format | Ukuran |
|---|---|
| A4 | 210 × 297 mm |
| F4 | 215 × 330 mm |

`PX_PER_MM = 96/25.4`. Margin PDF 15 mm. Zoom preview 0.5–3.

### 12.5 Tanda tangan (TTD)

`SignatureService` — template + gambar per laporan.

| Aspek | Aturan |
|---|---|
| Placement | **Hanya halaman terakhir** |
| Sanitasi | Whitelist tag, buang `on*`, batas 50 KB |
| Template | Disimpan di `signature_settings` |

### 12.6 Ekspor

| Format | Endpoint |
|---|---|
| CSV | `GET /reports/billing/export-csv`, `GET /reports/installation/export-csv` |
| PDF | `GET /reports/billing/export-pdf`, `GET /reports/installation/export-pdf` |
| Excel | `POST /pelaporan/excel` |

---

## 13. Arsip

4 halaman arsip, **semua** milik role admin:

| Halaman | Route | Sumber data |
|---|---|---|
| Arsip Tagihan | `/app/arsip/tagihan` | `monthly_bills` |
| **Arsip Piutang** | `/app/arsip/piutang` | **Jurnal piutang** (`transactions` dengan `reverence_type = 'overdue_bill'`) |
| Arsip Pemakaian | `/app/arsip/pemakaian` | `meter_readings` |
| Arsip Instalasi | `/app/arsip/instalasi` | `installation_tickets` |

> **Perubahan penting:** Arsip Piutang sekarang memakai **jurnal piutang** sebagai
> sumber kebenaran, bukan `monthly_bills`. Alasannya: jurnal piutang yang sudah
> dibuat adalah pengakuan resmi — sedangkan `monthly_bills` yang `unpaid` belum
> tentu sudah diakui.
>
> Route lama `/app/arsip/tunggakan` **tetap ada** sebagai redirect ke
> `/app/arsip/piutang` (query + hash diteruskan).

Periode filter: bulan + tahun, dengan request-id ordering guard supaya respons
out-of-order tidak menimpa data yang lebih baru.

---

## 14. Pengaturan (SOP)

Halaman **Personalisasi (SOP)** — 8 tab:

| Tab | Endpoint | Isi |
|---|---|---|
| Welcome | — | Pengantar |
| Identitas Lembaga | `GET/POST /settings/sop/lembaga` | Nama, alamat, email, telepon, peraturan desa, SK Kemenkumham |
| Pasang Baru | `POST /settings/sop/pasang-baru` | setting pasang baru |
| Sistem Tagihan | `POST /settings/sop/sistem-tagihan` | `toleransiTunggakan` (satu angka tanggal), `batasBulanTunggakan` (jumlah bulan), `status_pembayaran` |
| Pemakaian Air | `GET/POST /settings/pemakaian-air` | `auto_open_meter_camera` |
| CALK | `GET/POST /settings/sop/calk` | Persentase CALK (JSON, editable) |
| Logo | `POST /settings/sop/logo` | Upload logo |
| Whatsapp | `POST /settings/sop/whatsapp` | Template pesan |
| Tanda Tangan | `GET/PUT /settings/signatures` | Template & gambar TTD |

> **`GET /settings/pemakaian-air` sengaja terpisah** dari `GET /settings/sop` yang
> `role:admin`. Popup input meter dipakai teknisi juga; kalau hanya ada di
> `settings/sop`, teknisi selalu kena 403 tanpa melihat gejalanya.

### 14.1 Placeholder pesan

| Placeholder | Sumber |
|---|---|
| `{customer}` | Nama pelanggan |
| `{jumlah_tagihan}` | Nominal tagihan |
| `{tagihan}` | Nominal tagihan (pesan pembayaran) |

### 14.2 CoA

Halaman CoA terpisah dari SOP, memakai data yang sama dengan panel pusat
(level 1–3 di pusat, level 4 di lokasi).

---

## 15. Frontend

### 15.1 Struktur

```
frontend/src/
├── router/        index.js (guard + ROLE_GUARDS), dashboardRoutes.js
├── stores/        6 Pinia store
├── services/      21 modul API
├── composables/   24 composable
├── utils/         17 util (axios, format, camera, sanitize, theme, tenant)
├── types/         3 modul tipe/konstanta
└── presentations/
    ├── layouts/   app/ (Main, Sidebar, TopNavigation, Footer), central/
    ├── components/ ui/ (design system), topnav/, billing/, central/, transaksi/, settings/
    └── views/     app/ (admin, pelanggan, surveyor, teknisi), auth/, central/
```

### 15.2 Store Pinia

| Store | ID | Isi |
|---|---|---|
| `uiStore` | `ui` | Loading (ref-count), role, data user, toast, modal |
| `settingsStore` | `settings` | `batasTagihan`, `toleransiTunggakan`, `autoOpenMeterCamera`, `lembagaName` |
| `notificationStore` | `notification` | Ringkasan tagihan unpaid (lonceng navbar) |
| `menuAccessStore` | `menuAccess` | Katalog menu, `allowsKey()`, `allowsPath()` |
| `instalasiStore` | `instalasi` | Data instalasi per 5 kategori status |
| `billingStore` | `billing` | Periode tagihan, pembayaran, auto-retry |

> `uiStore.userRole` default-nya `''` — **bukan** `'admin'`. Ini deliberate:
> default `'admin'` akan membuat halaman yang salah akses tampil dengan hak admin
> selama `localStorage` belum terisi.

### 15.3 Tiga axios instance

| Instance | File | Timeout | Token | Header tenant |
|---|---|---|---|---|
| Lokasi | `utils/axios.js` | 60 s | `auth_token` | ✅ `X-Tenant-Slug` |
| Pusat | `utils/centralAxios.js` | 180 s | `central_token` | ❌ |
| Branding | `services/branding.service.js` | 10 s | — | Manual |

**Interceptor lokasi:**

| Respons | Aksi |
|---|---|
| 401 | Bersihkan sesi → redirect `/login?logout=expired` |
| 402 | `showInvoiceLockModal()` — modal tanpa tombol tutup |

### 15.4 Format uang — 5 implementasi

`frontend/src/utils/format.js` adalah versi **kanonik** (dibuat khusus untuk
menghentikan duplikasi). Tapi masih ada implementasi lain:

| File | Selisih |
|---|---|
| `utils/format.js` | `Intl.NumberFormat('id-ID', {style:'currency'})` ✅ |
| `composables/useFormatCurrency.js` | `"Rp " + toLocaleString('id-ID')` — **beda format** |
| `composables/useCurrencyFormat.js` | Versi lama dengan `convertTextToAmount` |
| `composables/usePdfReport.js` | `formatCurrency` lokal |
| `composables/useInvoiceLock.js` | `rupiah()` lokal |
| `composables/useInstalasiActions.js` | `formatRupiah` **tanpa prefix `Rp`** |

> ⚠️ `useInstalasiActions.js` menampilkan angka **tanpa "Rp"** — kalau halaman itu
> pernah menampilkan nominal tagihan, ini terlihat seperti bug.

### 15.5 Nama bulan Indonesia — 4 duplikasi

| File |
|---|
| `composables/useDateFormat.js` (kanonik) |
| `stores/billingStore.js` (`monthNames` inline) |
| `composables/usePemakaianAir.js` (`bulanOptions`) |
| `composables/usePdfReport.js` (`bulanNama`) |

### 15.6 Guard router

```js
// frontend/src/router/index.js — ROLE_GUARDS
surveyor:      ['/app/survey/create', '/app/surveyor']              → ['surveyor', 'admin']
teknisi:       ['/app/teknisi', '/app/instalasi/teknisiPemakaianAir'] → ['teknisi', 'admin']
pelanggan:     ['/app/pelanggan']                                    → ['pelanggan']
admin:         ['/app/data-pelanggan', '/app/data-desa', '/app/dataInstalasi',
                '/app/arsip', '/app/settings', '/app/kelas-biaya',
                '/app/transaksi', '/app/pelaporan',
                '/app/instalasi/register', '/app/instalasi/status',
                '/app/instalasi/daftar-tagihan']                      → ['admin']
admin-teknisi: ['/app/instalasi/pemakaian']                           → ['admin', 'teknisi']
```

**Match prefix TERPANJANG menang.** Role tidak cocok → redirect ke dashboard
sendiri (bukan ke login).

**Fail-closed untuk role tidak dikenal:** paksa logout, redirect `/login`.

**Fail-open untuk katalog menu belum loaded** — supaya halaman baru tidak
terkunci karena katalog belum ter-fetch.

### 15.7 Target login per role

```js
// frontend/src/router/dashboardRoutes.js
admin: '/app', surveyor: '/app/surveyor',
teknisi: '/app/teknisi', pelanggan: '/app'
```

### 15.8 Path pemakaian tanpa kata "air"

URL halaman pemakaian **tidak lagi** memuat kata "air" — ASTALOKA dipakai lintas
jenis usaha, dan "air" mengunci satu jenis usaha di address bar.

| Route | Halaman |
|---|---|
| `/app/instalasi/pemakaian` | Daftar pemakaian |
| `/app/instalasi/pemakaian/input` | Input pemakaian (admin) |

Route lama **tetap ada** sebagai redirect, dengan query + hash diteruskan:

| Route lama | Redirect ke |
|---|---|
| `/app/instalasi/pemakaian-air` | `/app/instalasi/pemakaian` |
| `/app/instalasi/pemakaian-air/input` | `/app/instalasi/pemakaian/input` |

```js
// frontend/src/router/index.js
{
  path: 'instalasi/pemakaian-air/input',
  redirect: (to) => ({
    path: '/app/instalasi/pemakaian/input',
    query: to.query,
    hash: to.hash,
  }),
},
```

Query **wajib** diteruskan: `?bulan=Oktober&tahun=2026` adalah bagian dari
intentions user (periode apa yang mau dibuka). Redirect yang membuang query
mendaratkan user di halaman yang benar dengan filter yang salah — lebih
membingungkan daripada 404.

> Route teknisi `/app/instalasi/teknisiPemakaianAir` **tidak diubah** — itu
> path lama yang sudah jadi rujukan, dan hanya teknisi yang memakainya.
> Yang berubah hanya **label**-nya ("Input Pemakaian Air Teknisi").

Diuji `frontend/test/pathPemakaianTanpaAir.test.js` (10 test).

### 15.9 Branding

`useBranding.js` (module-level cache) menarik `GET /api/branding` — endpoint
publis yang mengembalikan warna, judul login, sub-judul, deskripsi.

Tema diterapkan ke CSS variable (`--color-primary*`, `--brand-gradient*`,
`--p-primary-*` PrimeVue) oleh `utils/applyTheme.js`.

> **`applyTheme()` harus dijalankan setelah `app.use(PrimeVue)` dan setelah
> `mount`.** Urutan lain → token PrimeVue tidak ter-override dan UI terlihat
> salah warna.

---

## 16. Infrastruktur, Testing & CI

### 16.1 Database uji

| Database | Isi |
|---|---|
| `astaloka_testing` | Skema lokasi |
| `astaloka_testing_central` | Skema pusat |

**`tests/TestCase.php` menolak test yang menunjuk database operasional** — nama
database wajib mengandung `testing`.

> [!WARNING]
> **Hanya SATU `php artisan test` boleh jalan pada satu mesin.** Semua test
> berbagi `astaloka_testing`, dan `RefreshDatabase` menjalankan `migrate:fresh` →
> `db:wipe` → `dropAllTables()`. Dua proses bersamaan saling menghapus tabel.
> Gejalanya: `Table 'installation_tickets' already exists` atau `doesn't exist` —
> **bukan bug**.

### 16.2 Test

| Lokasi | Jumlah | Isi |
|---|---|---|
| Backend Feature | 52 file | Auth, Billing, Tenancy, Hak Akses, Piutang, Tutup Buku, dsb. |
| Backend Unit | 9 file | `BillingServiceTest`, `BillingDueDateTest`, `BillingToleranceTest`, dll. |
| Frontend | 26 file | `format.test.js`, `cameraStream.test.js`, `sanitizeHtml.test.js`, dll. |

**Jumlah test aktual** (hasil run lokal, 2 November 2026):

| Suite | Test | Assertion |
|---|---|---|
| Backend (`php artisan test`) | **776 passed** | **3450** |
| Frontend (`pnpm run test`) | **271 passed** | — |

> Angka **776 / 3450** adalah hasil run di working tree saat dokumen ini ditulis.
> Angka **594 / 2922** yang masih tertulis di komentar `.github/workflows/ci.yml`
> berasal dari run lama dan **sudah usang** — komentar itu perlu diperbarui
> (lihat §17, P3-23).

### 16.3 CI

`.github/workflows/ci.yml` — 2 job:

| Job | Isi |
|---|---|
| `frontend` | `pnpm install --frozen-lockfile` → `pnpm run build` → `oxlint` + `eslint` (tanpa `--fix`) → `pnpm run test` |
| `backend` | MySQL 8.0 service → `composer install` → `php artisan test` (**blocking**, `continue-on-error: false`) |

> `VITE_API_BASE_URL` dikosongkan di CI — sama seperti di `deploy.yml`. Base URL
> yang mengunci satu host backend membuat seluruh request dari subdomain lokasi ikut
> ke host pusat, dan di produksi `TenantResolver::slugAllowed()` mengembalikan false.

### 16.4 Scheduler di Windows

`.github/workflows/ci.yml` tidak menjalankan scheduler. Di produksi Windows,
scheduler dijalankan lewat **Windows Task Scheduler**:

```
tools\scheduler-run-hidden.vbs
tools\scheduler-run-hidden.cmd
tools\install-scheduler-task.ps1
```

`routes/console.php` mendaftarkan:

| Jadwal | Nama | Perintah |
|---|---|---|
| `dailyAt('00:01')` | `piutang:generate-overdue-transactions` | `ReceivableGenerator::runIfDue()` untuk semua lokasi aktif |
| `everyTenMinutes()` | `migrasi:bersihkan` | Bersihkan log migrasi |

**Dua hal yang wajib diketahui:**

1. `schedule:run` mengembalikan exit code **0** bahkan kalau job gagal. Periksa
   `storage/logs/laravel.log`, **bukan** exit code-nya.
2. `schedule:pause` / `schedule:resume` di-override untuk menulis ke
   `config('cache.schedule_store')` — perintah bawaan Laravel **diam-diam tidak
   melakukan apa pun** kalau tidak di-override.

Tiga command tahan-gagal tersedia: `schedule:run:resilient`,
`schedule:pause:resilient`, `schedule:resume:resilient`.

### 16.5 Docker

```bash
docker compose up --build     # → http://localhost:8000
```

Image frontend sudah built di dalam image (bukan runtime `npm install`).

### 16.6 Tool operasional

| Tool | Fungsi |
|---|---|
| `tools/bersihkan-db-uji.php` | Hapus database uji — **wajib `--dry-run` dulu** |
| `tools/tambah-host-tenant.bat` | Tambah subdomain ke hosts file |
| `tools/ukur-cetak-form.mjs` | Ukur tinggi baris laporan (headless Chrome) |
| `tools/cek-halaman.mjs` | Cek halaman |
| `tools/test-backend.sh` | Jalankan test backend |
| `tools/check-roadmap-anchors.py` | Cek semua anchor internal `docs/roadmap.md` (meniru github-slugger) |
| `tools/check-roadmap-tables.py` | Cek struktur Markdown: fence berpasangan + kolom tabel konsisten |
| `tools/install-scheduler-task.ps1` | Pasang Windows Task Scheduler untuk `schedule:run` |

---

## 17. Risiko & Utang Teknis Tertuang

Prioritas: **P1** = risiko data/uang, **P2** = risiko operasional, **P3** = debt.

### P1 — Risiko data & keuangan

| # | Masalah | Dampak | Lokasi |
|---|---|---|---|
| 1 | **3 implementasi tarif** | Tagihan berbeda antar lokasi | ✅ selesai — `MonthlyBillService::calculateTariff()` kini delegasi ke `BillingService::calculateUsageCharge()` |
| 2 | **`amount`: trigger (YTD) vs `AmountRecalculator` (per-bulan)** | Saldo bisa write 2× dengan angka berbeda | `AmountRecalculator.php` |
| 3 | **Tidak ada reversal / koreksi jurnal** | Koreksi tidak berjejak audit | `transactions` |
| 4 | **Tidak ada unique constraint** di `monthly_bills (customer_id, year, month)` | Tagihan dobel mungkin terjadi | `monthly_bills` |
| 5 | **`toleransi_tunggakan` = 4 makna dalam 1 kolom** | Salah set → generate tidak jalan / suspend salah | ✅ selesai — makna 1–3 digabung jadi satu angka tanggal (`BillingTolerance::tanggal()`), makna 4 pindah ke `billing_unpaid_limit` |
| 6 | **Dua implementasi `computeDueDate`** | Salah satu sudah delegasi ke `BillingDueDate` ✅ | — |

### P2 — Risiko operasional

| # | Masalah | Dampak | Lokasi |
|---|---|---|---|
| 7 | **Gate `===` + scheduler mati** | Bulan tanpa jurnal piutang → Neraca under-report | `ReceivableGenerator` |
| 8 | **4 duplikasi format uang** | Angka tampil berbeda antar halaman | `frontend/src/utils/format.js` vs composables |
| 9 | **4 duplikasi nama bulan** | Risiko salah label | `useDateFormat.js` dll. |
| 10 | **Tanpa UI manajemen pengguna** | User hanya bisa dibuat via API/panel pusat | `apiResource users` |
| 11 | **`billing_unpaid_limit` mati** | Kolom membingungkan, tidak dibaca kode mana pun | ✅ selesai — kini dibaca `BillingTolerance::unpaidBillLimit()` dan diisi lewat SOP (`SopController`, field `batasBulanTunggakan`) |
| 12 | **`shouldSuspend()` tidak dipakai** | Helper menduplikasi logika controller | `MeterReadingController::store()` — controller memanggil `UnpaidMonths` langsung, bukan `BillingTolerance::shouldSuspend()` |
| 13 | **Desa pakai API eksternal EMSIFA** | Tidak bisa offline; ketergantungan pihak ketiga | `DesaCreate.vue` |
| 14 | **Kecamatan hardcoded di dropdown** | Tidak bisa dikelola dari UI | — |
| 15 | **2 migrasi duplikat** `installation_packages` | `2026_03_26_190302` & `2026_03_26_190403` | `migrations_tenant/` |
| 16 | **2 migrasi duplikat** `add_id_to_settings_table` | `2026_05_18_015437` & `2026_05_18_020803` | `migrations_tenant/` |

### P3 — Debt & technical debt

| # | Masalah | Lokasi |
|---|---|---|
| 17 | `struktur.sql` nyasar di root backend | `backend/struktur.sql` |
| 18 | `database/migrations/` sengaja kosong — setiap migrate wajib `--path` | `config/tenancy.php` |
| 19 | Menu katalog hanya 30 entri; `always` kosong | `config/menu_access.php` |
| 20 | 51 command Artisan, banyak untuk sekali pakai | `app/Console/Commands/` |
| 21 | Tidak ada pagination di `monthly_bills` | `billing.service.js` |
| 22 | Skema CoA level 1–3 di pusat, level 4 di lokasi — FK lintas database tidak mungkin | `AkunLevel1/2/3` vs `Account` |
| 23 | Komentar CI menyebut "594 test / 2922 assertion" — angka lama (aktual 776 / 3450) | `.github/workflows/ci.yml:58` |

### 17.1 Rencana perbaikan (urutan eksekusi)

**P1-1 — Satukan tarif.** ✅ **SELESAI.**

`BillingService::calculateUsageCharge()` sekarang jadi **satu-satunya** sumber
rumus tarif (FLAT: `pemakaian × harga paket per m³`). `MonthlyBillService::calculateTariff()`
dan `BillRecalculationService::recalculateForCustomer()` memanggilnya — tidak
menyalin logikanya lagi. Unit test: `backend/tests/Unit/BillingServiceTest.php`
(14 test, mencakup batas blok inklusif, harga blok terakhir, dan pembulatan).

**P1-2 — Seragamkan semantik `amount`.**
Pilih satu definisi (rekomendasi: **YTD sampai akhir bulan**, mengikuti trigger MySQL
karena itu yang sudah berjalan di produksi dan yang dipakai pelaporan), lalu ubah
`AmountRecalculator::compute()` agar sama, atau buat `AmountRecalculator` khusus
YTD dan sisakan versi per-bulan untuk kebutuhan lain dengan nama berbeda.

**P1-3 — Unique constraint `monthly_bills`.**
Tambahkan unique index `(customer_id, billing_period_year, billing_period_month)`
setelah data duplikat dibersihkan. Hati-hati: `monthly_bills` punya soft delete,
jadi unik harus menyertakan `deleted_at` (MySQL tidak mendukung unique partial index
seperti PostgreSQL — perlu workaround).

**P1-4 — Pisahkan makna `toleransi_tunggakan`.** ✅ **SELESAI (bentuk berbeda
dari usulan awal).**

Usulan awal: pecah jadi 4 kolom terpisah. Yang dikerjakan: makna 1–3
(tanggal generate, ambang piutang, ambang advance) digabung jadi **satu** kolom
tanggal, karena ketiganya satuan dan maknanya sama — "hari ke berapa dalam
bulan" — dan tiga input terpisah justru membingungkan admin. Sumbernya
`settings.batas_tagihan`, dengan `settings.toleransi_tunggakan` tetap ada
sebagai salinan (dipakai import legacy). Makna 4 (batas tagihan menunggak
sebelum suspend) dipisah ke `settings.billing_unpaid_limit` dengan satuan
**JUMLAH BULAN**.

Perilaku lama dipertahankan: `billing_unpaid_limit` kosong dibaca sebagai 3,
dan baris dengan `batas_tagihan` = 0 masih punya jadwal generate lewat
fallback ke `toleransi_tunggakan`. Lihat [§9.4](#94-satu-kolom-tanggal-untuk-semua-aturan-berbasis-tanggal).

**P2-1 — Jaga scheduler hidup.**
Tambahkan monitoring: heartbeat di cache, dan alarm kalau heartbeat >
2 hari. Dokumentasikan setup Windows Task Scheduler di README.

**P2-2 — Rapikan format uang.**
Hapus semua `formatRupiah` di luar `utils/format.js`, dan pastikan `useInstalasiActions.js`
menggunakan fungsi kanonik.

**P2-3 — Bangun UI manajemen pengguna.**
Halaman CRUD user + jabatan, dengan `jabatan_id` bisa dipilih. Ini prasyarat agar
fitur hak akses per jabatan benar-benar berguna untuk pasar non-teknis.

**P2-4 — Bersihkan `settings`.**
Tentukan: hapus `billing_unpaid_limit` lewat migrasi (destructive tapi jelas),
atau aktifkan lagi dengan accessor tersendiri. ✅ **Sudah diaktifkan** —
`billing_unpaid_limit` kini dibaca `BillingTolerance::unpaidBillLimit()` dan
diisi lewat halaman SOP ("Batas Tagihan Bulanan", satuan bulan).

---

## 18. Peta File Referensi

### 18.1 Backend — inti tagihan

| Area | File |
|---|---|
| Tagihan per pelanggan | `backend/app/Services/BillingService.php` |
| Tagihan massal | `backend/app/Services/MonthlyBillService.php` |
| Hitung ulang tagihan | `backend/app/Services/BillRecalculationService.php` |
| Jatuh tempo (sumber tunggal) | `backend/app/Services/BillingDueDate.php` |
| Makna tanggal (jatuh tempo / generate / ambang) | `backend/app/Services/BillingTolerance.php` |
| Bulan menunggak (untuk suspend) | `backend/app/Services/UnpaidMonths.php` |
| Pengakuan piutang | `backend/app/Services/ReceivableGenerator.php` |
| Command piutang | `backend/app/Console/Commands/GenerateOverdueTransactions.php` |
| Controller tagihan | `backend/app/Http/Controllers/MonthlyBillController.php` |
| Controller meter | `backend/app/Http/Controllers/MeterReadingController.php` |
| Pembayaran SPS | `backend/app/Services/BillPaymentService.php` |
| Tutup buku | `backend/app/Http/Controllers/TutupBukuController.php` |
| Alokasi laba | `backend/app/Http/Controllers/AlokasiLabaController.php` |
| Amount | `backend/app/Services/AmountRecalculator.php`, `backend/app/Support/TransactionTriggers.php` |
| Verifikasi tarif vs data legacy | `backend/app/Console/Commands/VerifyLegacyTariffCommand.php` |

### 18.2 Backend — multi-tenancy

| Area | File |
|---|---|
| Manager & provisioning | `backend/app/Services/TenantManager.php` |
| Resolusi tenant | `backend/app/Services/TenantResolver.php` |
| Config | `backend/config/tenancy.php` |
| Middleware | `backend/app/Http/Middleware/ResolveTenant.php`, `EnsureSuperadmin.php`, `EnforceInvoiceStatus.php`, `EnforceMenuAccess.php`, `CheckRole.php` |
| Gate invoice | `backend/app/Services/InvoiceGate.php` |
| Panel pusat | `backend/app/Http/Controllers/Central/` |
| Model pusat | `backend/app/Models/Central/` |

### 18.3 Backend — master & config

| Area | File |
|---|---|
| Katalog menu | `backend/config/menu_access.php` |
| CoA | `backend/app/Models/AkunLevel1.php` (central), `AkunLevel2`, `AkunLevel3`, `backend/app/Models/Account.php` (tenant) |
| Cascade CoA | `backend/app/Services/CoaCascadeService.php` |
| State machine tiket | `backend/app/StateMachines/TicketStateMachine.php` |
| Audit | `backend/app/Services/AuditLogger.php` |
| Signature | `backend/app/Services/SignatureService.php` |
| Impor Excel | `backend/app/Services/Migration/` |

### 18.4 Frontend

| Area | File |
|---|---|
| Router + guard | `frontend/src/router/index.js` |
| Dashboard route | `frontend/src/router/dashboardRoutes.js` |
| Sidebar | `frontend/src/presentations/layouts/app/SidebarView.vue` |
| Login | `frontend/src/presentations/views/auth/LoginView.vue` |
| Form bayar | `frontend/src/presentations/components/billing/BillingForm.vue` |
| Input meter + OCR | `frontend/src/presentations/views/app/admin/tagihan/partials/inputMeterModal.vue` |
| Preview laporan | `frontend/src/presentations/views/app/admin/pelaporan/PelaporanPreview.vue` |
| Format uang | `frontend/src/utils/format.js` |
| Config laporan | `frontend/src/utils/reportConfig.js` |
| Kamera | `frontend/src/utils/cameraStream.js` |
| Axios lokasi | `frontend/src/utils/axios.js` |
| Axios pusat | `frontend/src/utils/centralAxios.js` |
| Tenant slug | `frontend/src/utils/tenantSlug.js` |
| Template pesan | `frontend/src/presentations/views/app/admin/sop/partials/WhatsappSop.vue` |

### 18.5 Dokumentasi lain

| Dokumen | Isi |
|---|---|
| `README.md` | Setup, git workflow, akun demo, test |
| `docs/database.md` | Skema database |
| `docs/api_backend.md` | Referensi endpoint |
| `docs/perbedaan-apk-lama-baru.md` | Perbandingan dengan aplikasi lama `siap_mapan` |
| `backend/API_DOCUMENTATION.md` | Dokumentasi API backend (lama) |
| `BACKEND_NOTES.md` | Catatan integrasi frontend ↔ backend |
