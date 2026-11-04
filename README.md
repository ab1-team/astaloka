# ASTALOKA Project Setup Guide

Selamat datang di repositori **ASTALOKA**. Ikuti langkah-langkah di bawah ini untuk melakukan setup awal setelah melakukan clone repositori ini.

## 📖 Dokumentasi Proyek

- [🗺️ Peta Proyek Lengkap](docs/roadmap.md) — status tiap modul, arsitektur multi-tenant, dan **spesifikasi perhitungan** (tarif progresif, pengakuan piutang, jurnal pembayaran, tutup buku) sampai ke level rumus
- [🗄️ Struktur Database](docs/database.md)
- [🔌 Referensi API Backend](docs/api_backend.md)
- [🔀 Perbandingan dengan Aplikasi Lama](docs/perbedaan-apk-lama-baru.md)
- [🚀 Panduan Deploy ke Produksi](docs/deploy.md) — GitHub Actions secrets yang wajib diisi, gejala kegagalan, dan cara memperbaikinya

## 🛠 Prasyarat (Prerequisites)

Pastikan Anda sudah menginstal software berikut di komputer Anda:

- **Node.js**: Versi lts/iron (v22+)
- **pnpm**: Versi 10+ (`npm install -g pnpm`)
- **PHP**: Versi 8.3+
- **Composer**: Versi 2+
- **Web Server**: Laragon (Direkomendasikan) atau Apache/Nginx

---

## 🚀 Setup Awal

### 1. Clone Repositori

Clone repositori dari branch `develop`:

```bash
git clone -b develop git@github.com:ab1-team/ASTALOKA.git
cd ASTALOKA
```

### 2. Alur Kerja Git (Git Workflow)

Untuk perbaikan, update, atau penambahan fitur apapun, **wajib** membuat branch baru dari `develop` dengan format:
`feature/[nama-fitur]` atau `fix/[nama-bug]`.

Contoh:

```bash
git checkout develop
git pull origin develop
git checkout -b feature/update-dokumentasi
```

Setelah selesai, lakukan push dan buat **Pull Request** ke branch `develop`.

### 3. Pesan Commit (Conventional Commits)

Gunakan standar [Conventional Commits](https://www.conventionalcommits.org/) dalam setiap pesan commit. Format umum:
`<type>[optional scope]: <description>`

Contoh:

- `feat(auth): tambah integrasi login google`
- `fix(ui): perbaiki padding sidebar yang tidak rata`
- `docs: update panduan setup awal`
- `chore: update dependensi husky`

### 4. Setup Root (Husky & Git Hooks)

Di direktori utama (root), jalankan pnpm install untuk mengaktifkan Husky.

```bash
pnpm install
```

_Langkah ini akan secara otomatis menjalankan script `prepare` yang menginisialisasi Git Hooks._

### 3. Setup Backend (Laravel)

Pindah ke direktori `backend` dan lakukan instalasi:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

_Sesuaikan konfigurasi database di `.env` (DB_DATABASE, DB_USERNAME, DB_PASSWORD)._

**Buat database** sesuai `DB_DATABASE` di MySQL, lalu jalankan:

```bash
# 1. Skema PUSAT (tenants, user superadmin, invoice, audit)
php artisan tenancy:migrate-central --seed

# 2. Skema LOKASI untuk database yang aktif di DB_DATABASE
php artisan migrate --force --path=database/migrations_tenant
```

Kedua perintah wajib menyebut jalannya, dan itu disengaja. Skema pusat dan
skema lokasi dipisah ke dua folder yang tidak berbagi file
(`database/migrations_central/` dan `database/migrations_tenant/`), jadi
folder default Migrator `database/migrations/` sengaja dibiarkan kosong --
`php artisan migrate` tanpa `--path` tidak lagi menjalankan apa pun, bukan
lupa. Jalur foldernya dibaca dari `migrations_path` dan
`central_migrations_path` di `backend/config/tenancy.php`.

> [!WARNING]
> **`DB_DATABASE` harus menunjuk ke database LOKASI, bukan ke database pusat.**
> `DB_DATABASE` dibaca juga oleh koneksi `mysql` — koneksi default yang dipakai
> seluruh query aplikasi, dan yang dituju perintah `migrate --path=...` di
> atas. Kalau `DB_DATABASE` menunjuk ke `astaloka` (nama polos = database
> pusat), seluruh query aplikasi dan seluruh skema lokasi ikut mendarat di
> database pusat: tabel `cache`/`sessions` pusat berisi data operasional
> lokasi, dan `migrations` pusat mencatat file skema lokasi sebagai milik
> pusat — provisioning lokasi berikutnya lalu melompatinya.
>
> Pola nama database lokasi selalu `astaloka_<nama-lokasi>`
> (`TENANCY_DATABASE_PREFIX` di `backend/config/tenancy.php`), sedangkan
> database pusat selalu `astaloka` — nama polos, tanpa underscore di
> belakang, supaya tidak pernah tertukar.

### 4. Setup Frontend (Vue.js)

Pindah ke direktori `frontend` dan lakukan instalasi:

```bash
cd ../frontend
pnpm install
cp .env.example .env
```

**Penting:** Edit `frontend/.env` dan sesuaikan `VITE_BACKEND_URL` dengan
vhost Laragon Anda:

```env
VITE_BACKEND_URL=http://astaloka.test
```

Nilai ini **hanya** jadi target proxy Vite. Base URL axios sengaja
`VITE_API_BASE_URL=` (kosong) — kalau diisi, SPA yang dibuka lewat
`demo.astaloka.test:5173` akan fetching ke host pusat dan identitas
lokasi ikut hilang.

---

## 🏷️ Mengakses Lokasi (Tenant)

Setiap lokasi punya subdomain sendiri, sama seperti produksi:

| URL                              | Dilayani                |
|----------------------------------|-------------------------|
| `http://astaloka.test:5173`      | Panel pusat             |
| `http://<slug>.astaloka.test:5173` | Aplikasi lokasi       |

Contoh: `http://demo.astaloka.test:5173`

**Subdomain harus terdaftar di hosts file Windows.** Laragon di mesin ini
tidak memakai dnsmasq, jadi setiap subdomain dipetakan manual. Untuk
menambah lokasi baru, jalankan (klik kanan → *Run as administrator*):

```bat
tools\tambah-host-tenant.bat lokasi2 lokasi3
```

Lalu ensure `tenants.domain` terisi (`php artisan tenancy:make <slug>
--domain=<slug>.astaloka.test`). Tanpa itu, subdomain tetap membuka
halaman pusat karena `TenantResolver` tidak punya yang dicocokkan.

> **Jangan isi `TENANCY_FRONTEND_URL` dengan `http://localhost:5173`
> saat development.** Nilai itu membuang subdomain yang dipakai petugas,
> sehingga backend tidak bisa mencocokkan lokasi dan `/api/branding`
> membalas branding bawaan. Pakai `TENANCY_FRONTEND_URL_TEMPLATE=http://{host}:5173`
> (sudah terisi di `.env.example`) — nilainya kosongkan di produksi.

---

## 💻 Menjalankan Aplikasi

### Backend

Pastikan server database Anda menyala (mysql), lalu jalankan:

```bash
php artisan serve
```

### Frontend

Jalankan dev server Vite:

```bash
pnpm run dev
```

### 🐳 Docker

Untuk menjalankan lewat Docker (build frontend sudah termasuk di dalam image):

```bash
docker compose up --build
```

Aplikasi tersedia di `http://localhost:8000`.

> **Penting:** di mode development (tanpa Docker), jalankan **dua** Terminal --
> `php artisan serve` untuk backend dan `pnpm run dev` untuk Vite. Backend saja
> tidak cukup: `/login`, `/central/login`, dan halaman SPA lainnya dilayani
> oleh Vite, bukan oleh Laravel. Tanpa dev server Vite, semua path itu
> berakhir 404 karena tidak ada route Laravel untuk path tersebut.

### 🧪 Test Backend

```bash
cd backend
php artisan test
```

> [!WARNING]
> **Jangan menjalankan `php artisan test` di dua proses sekaligus.** Semua test
> berbagi satu database (`astaloka_testing`), dan `RefreshDatabase` menjalankan
> `migrate:fresh` -- jadi dua proses yang jalan bersamaan akan saling
> menghapus tabel dan menghasilkan puluhan kegagalan yang sebenarnya bukan
> bug. Gejalanya: `Table 'astaloka_testing.X' doesn't exist` atau
> `Table 'X' already exists`.
>
> Kalau `astaloka_testing` sedang dipakai proses lain di mesin yang sama,
> jalankan suite-nya dengan nama database sendiri lewat variabel lingkungan:
>
> ```bash
> $env:DB_DATABASE='astaloka_testing_iso'
> $env:TENANCY_CENTRAL_DATABASE='astaloka_testing_iso_central'
> php artisan test
> ```
>
> Nama database wajib mengandung `testing` -- pengaman di
> `tests/TestCase.php` menolak test yang menunjuk database operasional.

> [!NOTE]
> **Database uji boleh dihapus bebas, dan dibuat ulang sendiri.** Jadi
> `astaloka_testing` (lokasi) dan `astaloka_testing_central` (pusat) tidak
> perlu dibuat manual -- `php artisan test` akan membuatnya kalau belum ada,
> lengkap dengan skemanya. Aman dihapus di tengah-tengah pun: suite berikutnya
> membangunnya ulang dari nol.
>
> Yang **tidak** otomatis bersih adalah database yang dibuat test yang
> mem-provisioning lokasi. Nama-nama itu memakai salah satu prefix di bawah:
>
> | Prefix database | Test pembuatnya |
> |---|---|
> | `astaloka_provtest_` | `CentralTenantProvisionTest` |
> | `astaloka_statustest_` | `CentralLocationStatusTest` |
> | `astaloka_domtest_` | `CentralDomainTest` |
> | `astaloka_brandtest_` | `BrandingTest` |
> | `astaloka_usatest_` | `CentralSuperadminTest` |
> | `astaloka_ringkasan_` | `CentralInvoiceRingkasanTest` |
> | `astaloka_invlock_` | `InvoiceLockTest` |
> | `astaloka_landingtest_` | `LandingPenggunaTest` |
> | `hakakses_` | `CentralMenuAccessTest` |
> | `shortcut_` | `ShortcutLoginTest` |
> | `migtest_` | `MigrateTenantsTest` |
>
> Tiga baris terakhir **tidak** memakai awalan `astaloka_` -- test-nya membuat
> database lokasi palsu dengan nama polos. Pola `LIKE 'astaloka\_%'` sama
> sekali tidak akan menyentuhnya, padahal tiga prefix itu justru yang paling
> sering tertinggal.
>
> Test itu membersihkannya di `tearDown()` -- tapi kalau proses test-nya mati
> di tengah jalan (Ctrl+C, timeout, kill), `tearDown()` tidak pernah jalan dan
> database-nya tertinggal selamanya. Kalau `SHOW DATABASES` mulai penuh nama
> aneh yang tidak pernah dijelaskan, itu sisa-sisa itu.
>
> Cara aman membersihkannya: **sebutkan prefix-nya satu per satu**. Pola
> `LIKE 'astaloka\_%'` terlalu luas -- database LOKASI yang asli juga berawalan
> `astaloka_`, dan ikut terhapus berarti data operasional hilang.
>
> Sudah ada skripnya di repo. **Jalankan `--dry-run` dulu** -- perintah ini
> menghapus SELURUH isi database yang cocok, dan isinya tidak ada salinannya
> di tempat lain:
>
> ```bash
> php tools/bersihkan-db-uji.php --dry-run   # tampilkan saja, tidak menghapus
> php tools/bersihkan-db-uji.php             # baru benar-benar menghapus
> ```
>
> Skrip itu membaca daftar database LOKASI dari registry pusat (tabel
> `tenants`) dan `backend/config/database.php`, dan **tidak akan** menyentuh
> salah satunya -- jadi aman dijalankan selagi database uji sedang dipakai
> suite yang lain. Yang dihapus hanya yang cocok dengan pola di atas.

> [!WARNING]
> **Hanya SATU `php artisan test` boleh jalan pada satu mesin.** Semua test
> berbagi database `astaloka_testing`, dan `RefreshDatabase` menjalankan
> `migrate:fresh` -> `db:wipe` -> `dropAllTables()` di awal suite. Dua proses
> yang jalan bersamaan akan saling menghapus tabel, dan gejalanya berupa
> puluhan kegagalan yang **bukan** bug:
>
> ```
> SQLSTATE[42S01]: Base table or view already exists: 1050
> Table 'installation_tickets' already exists
> ```
>
> Gejalanya selalu "table already exists" atau "table doesn't exist" -- dua
>-dua dari database yang sama, dan keduanya bukan bug kode. Kalau itu muncul,
> periksa dulu berapa proses test yang sedang jalan sebelum menelusuri kode.

### 🔐 Akun Default (Hasil Seeder)

| Role      | Email                       | Password |
|-----------|-----------------------------|----------|
| Admin     | admin@ASTALOKA.test         | password |
| Surveyor  | surveyor@ASTALOKA.test      | password |
| Teknisi   | teknisi@ASTALOKA.test       | password |
| Pelanggan | pelanggan@ASTALOKA.test     | password |

> Password default bisa berbeda — cek `database/seeders/UserSeeder.php` untuk memastikan.

### 🏛️ Akun Panel Pusat (Superadmin)

Akun ini hidup di **database pusat** (`astaloka`), bukan di database lokasi, dan
dipakai untuk masuk di domain pusat (`http://astaloka.test` — bukan
subdomain lokasi):

| Role      | Email                 | Password |
|-----------|-----------------------|----------|
| Superadmin | pusat@astabrata.co.id | password |

Dibuat lewat:

```bash
php artisan tenancy:make-superadmin --name="Admin Pusat" \
    --email=pusat@astabrata.co.id --password=password
```

> Password `password` di atas hanya untuk development lokal. Ganti sebelum
> dipakai di mana pun yang bisa dijangkau orang lain — panel pusat mengatur
> tagihan langganan seluruh lokasi.

### 🏢 Akun Demo per Lokasi Tenant

Setiap lokasi yang **baru didaftarkan** — lewat panel pusat (`POST /api/central/locations`)
atau `php artisan tenancy:make <slug>` — otomatis mendapat satu akun contoh untuk
setiap role, di database lokasi itu sendiri:

| Role      | Email                | Password |
|-----------|----------------------|----------|
| Admin     | admin@gmail.com      | password |
| Pelanggan | pelanggan@gmail.com  | password |
| Surveyor  | surveyor@gmail.com   | password |
| Teknisi   | teknisi@gmail.com    | password |

Jadi setiap tenant baru bisa langsung dicoba keempat role-nya tanpa perlu
menambah akun manual.

Lokasi yang sudah ada sebelumnya (dibuat sebelum fitur ini, atau dengan
`--skip-migrate`) bisa ditambahkan belakangan:

```bash
php artisan tenancy:make-demo-users            # semua lokasi aktif
php artisan tenancy:make-demo-users <slug>     # satu lokasi
php artisan tenancy:make-demo-users <slug> --list
```

> [!WARNING]
> **Matikan di produksi:** `TENANCY_DEMO_USERS=false` di `.env`.
> Password `password` yang aktif di setiap lokasi produksi bukan sandaran yang
> layak. Sakelar ini hanya berlaku untuk lokasi yang dibuat **sesudah**
> dimatikan — akun yang sudah terlanjur ada tidak ikut terhapus, jadi hapus
> manual lewat `php artisan tenancy:make-user` / hapus barisnya dari
> `users` di database lokasi tersebut.

> [!NOTE]
> Akun demo **tidak pernah ikut** `tenancy:reseed-master`. Perintah itu
> dijalankan ulang untuk semua lokasi sebagai pemeliharaan rutin, dan kalau
> akun demo ikut di dalamnya, setiap pemanggilan akan mengembalikan password
> setiap akun demo ke `password` — membatalkan password yang sudah diganti
> pemilik lokasi. Sebaliknya, menjalankan `tenancy:make-demo-users` berulang
> aman: akun yang sudah ada tidak pernah disentuh.

Email dan passwordnya diatur di `config/tenancy.php` → `demo_users`
(`TENANCY_DEMO_USERS_PASSWORD` untuk mengganti passwordnya).

---

## 🤝 Standar Koding & Git Hooks

Proyek ini menggunakan **Husky** dan **lint-staged** untuk menjaga kualitas kode. Setiap kali Anda melakukan `git commit`, sistem akan otomatis menjalankan:

- **Frontend**: `oxlint`, `eslint`, dan `prettier` pada file `.js`, `.vue`, dan `.json`.
- **Backend**: `php artisan pint` untuk merapikan kode PHP.

### Jika Commit Gagal (Lint Error):

Jika terdapat error saat commit, perbaiki kode sesuai saran linter, lalu `git add` kembali file tersebut dan ulangi commit.

> [!IMPORTANT]
> Jangan gunakan flag `--no-verify` saat commit kecuali benar-benar diperlukan, agar kualitas kode tim tetap terjaga.
