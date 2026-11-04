# Dokumentasi API Backend ASTALOKA

## Base URL
```
http://localhost:8000/api
```

## Autentikasi
Semua endpoint (kecuali login) menggunakan **Bearer Token** di header:
```
Authorization: Bearer {token}
```

---

## Auth

| Method | Endpoint | Role | Keterangan |
|---|---|---|---|
| POST | `/login` | Public | Login |
| POST | `/logout` | Semua | Logout |
| POST | `/refresh` | Semua | Refresh token |
| GET | `/me` | Semua | Data user login |

**Request Login:**
```json
{
    "email": "admin@ASTALOKA.test",
    "password": "password"
}
```
//sesuai dengan data awal di seeder, bisa login dengan email
**Response Login:**
```json
{
    "success": true,
    "data": {
        "user": {...},
        "token": "1|xxxxxxxxxxxxxxxx"
    }
}
```

---

## Paket Instalasi

| Method | Endpoint | Role | Keterangan |
|---|---|---|---|
| GET | `/installation-packages` | Admin | Daftar semua paket |
| POST | `/installation-packages` | Admin | Tambah paket baru |
| GET | `/installation-packages/{id}` | Admin | Detail paket |
| PUT | `/installation-packages/{id}` | Admin | Update paket |
| DELETE | `/installation-packages/{id}` | Admin | Hapus paket |

**Request POST/PUT:**
```json
{
    "name": "Paket Reguler",
    "installation_fee": 1500000,
    "monthly_abodemen": 15000,
    "late_penalty": 10000
}
```

---

## Blok Tarif

| Method | Endpoint | Role | Keterangan |
|---|---|---|---|
| GET | `/installation-packages/{id}/water-tariff-blocks` | Admin | Daftar blok tarif |
| POST | `/installation-packages/{id}/water-tariff-blocks` | Admin | Tambah blok tarif |
| GET | `/installation-packages/{id}/water-tariff-blocks/{blockId}` | Admin | Detail blok tarif |
| PUT | `/installation-packages/{id}/water-tariff-blocks/{blockId}` | Admin | Update blok tarif |
| DELETE | `/installation-packages/{id}/water-tariff-blocks/{blockId}` | Admin | Hapus blok tarif |

**Request POST/PUT:**
```json
{
    "usage_min_m3": 0,
    "usage_max_m3": 10,
    "price_per_m3": 1500
}
```

> ⚠️ `usage_max_m3` boleh `null` untuk blok terakhir (tidak terbatas).
> Validasi: tidak boleh overlap antar blok dalam satu paket.

---

## Tiket Instalasi

| Method | Endpoint | Role | Keterangan |
|---|---|---|---|
| GET | `/installation-tickets` | Admin | Daftar tiket |
| GET | `/installation-tickets/register-dropdown` | Admin | Data ringan di-groupBy NIK untuk dropdown Register Instalasi (tanpa eager-load berat) |
| POST | `/installation-tickets` | Admin | Buat tiket baru |
| GET | `/installation-tickets/{id}` | Admin | Detail tiket |
| PATCH | `/installation-tickets/{id}/transition` | Admin | Ubah status tiket |
| POST | `/installation-tickets/{id}/survey` | Surveyor | Input hasil survey + foto |
| POST | `/installation-tickets/{id}/payment` | Admin | Konfirmasi pembayaran instalasi |
| POST | `/installation-tickets/{id}/installation-result` | Teknisi | Input hasil instalasi + foto meter |
| POST | `/installation-tickets/{id}/activate` | Admin | Aktivasi pelanggan |

**Query Parameters GET `/installation-tickets`:**
```
?status=pending|surveyed|unpaid|processing|completed|draft
?search=nama_atau_nik
?grouped=1                # return semua tiket di-groupBy NIK (semua status, tanpa paginasi)
```

**Query Parameters GET `/installation-tickets/register-dropdown`:**
```
?search=nama_atau_nik     # opsional, filter by applicant_name atau nik
```
Response sudah di-groupBy NIK di backend, tanpa eager-load relasi berat (`payments`, `customer.meterReadings`, `customer.monthlyBills`, `tariffBlocks`). Direkomendasikan untuk halaman Register Instalasi agar loading cepat.

**Alur Status Tiket:**
```
pending → surveyed → unpaid → processing → completed
```

**Request POST `/installation-tickets`:**
```json
{
    "package_id": 1,
    "applicant_name": "Budi Santoso",
    "nik": "3300000000000001",
    "address": "Jl. Magelang No. 10, Yogyakarta",
    "lat": -7.797068,
    "lng": 110.370529
}
```

**Request PATCH `/installation-tickets/{id}/transition`:**
```json
{
    "status": "surveyed"
}
```

**Request POST `/installation-tickets/{id}/survey` (form-data):**
```
distance_to_pipe_m : 15
material_notes     : Butuh pipa 15 meter
photo              : [file gambar]
```

**Request POST `/installation-tickets/{id}/payment`:**
```json
{
    "amount": 1500000
}
```

**Request POST `/installation-tickets/{id}/installation-result` (form-data):**
```
initial_meter_reading : 0
photo                 : [file gambar]
```

---

## Pencatatan Meter

| Method | Endpoint | Role | Keterangan |
|---|---|---|---|
| GET | `/meter-readings/pending` | Teknisi | Daftar pelanggan belum dicatat bulan ini |
| POST | `/meter-readings` | Teknisi | Input angka meter bulanan + foto (opsional) |

**Response GET `/meter-readings/pending`:**
```json
{
    "message": "Daftar pelanggan yang belum dicatat meter bulan ini",
    "data": [...]
}
```

**Request POST `/meter-readings` (form-data):**
```
customer_id   : 1
meter_value   : 25
reading_month : 3
reading_year  : 2026
photo         : [file gambar]  (OPSIONAL — bukti pendukung, boleh tidak dikirim)
```

**Response POST `/meter-readings`:**
```json
{
    "message": "Meter berhasil dicatat",
    "data": {...}
}
```

> Validasi: angka meter baru tidak boleh lebih kecil dari bulan sebelumnya.
>
> `photo` bersifat opsional — foto meteran hanya bukti pendukung, jadi
> pencatatan tetap berhasil tanpa foto (`meter_readings.photo_url` = null).
> Field `meter_value`, `reading_month`, dan `reading_year` tetap wajib.

### Kamera otomatis saat input meter

Popup "Input Pemakaian" punya kamera scan. Kamera itu **tidak** menyala
otomatis — yang wajib diisi tetap hanya angka meter akhir. Kamera hanya
dibuka kalau teknisi menekan tombol scan (atau tombol kamera foto), atau
kalau admin menyalakannya lewat pengaturan di bawah.

| Method | Endpoint | Role | Keterangan |
|---|---|---|---|
| GET | `/settings/pemakaian-air` | Admin, Teknisi, Surveyor | Baca pengaturan kamera otomatis |
| POST | `/settings/sop/pemakaian-air` | Admin | Simpan pengaturan kamera otomatis |

**Response GET `/settings/pemakaian-air`:**
```json
{
    "success": true,
    "data": {
        "autoOpenMeterCamera": false
    }
}
```

**Request POST `/settings/sop/pemakaian-air`:**
```json
{
    "autoOpenMeterCamera": true
}
```

> `GET /settings/pemakaian-air` sengaja terpisah dari `GET /settings/sop`
> yang `role:admin` — popup input meter dipakai teknisi juga, dan kalau
> hanya ada di `settings/sop` maka teknisi selalu kena 403 tanpa melihat
> gejalanya.
>
> `autoOpenMeterCamera` nullable di database: `null` = belum pernah diisi
> (bawaan: kamera tidak dibuka otomatis), `false` = sengaja dimatikan.

### Kamera gagal — penyebab dan cara memperbaikinya

Popup input meter dan modal kamera memakai helper yang sama
(`frontend/src/utils/cameraStream.js`). Dulunya semua kegagalan kamera
menghasilkan pesan yang SAMA, padahal penyebabnya berbeda-beda dan cara
memperbaikinya juga berbeda. Sekarang pesannya menyebut penyebabnya.

| Gejala di layar | Penyebab | Cara memperbaiki |
|---|---|---|
| "Kamera Perlu HTTPS" | Halaman dibuka lewat HTTP di LAN (mis. `http://192.168.1.10:5173`) | Buka lewat HTTPS, atau lewat `localhost` di komputer sendiri |
| "Izin Kamera Ditolak" | Izin belum diberikan / sudah diblokir browser | Pengaturan situs di browser → Izin → Kamera → Allow, lalu muat ulang |
| "Kamera Tidak Ditemukan" | Tidak ada kamera di perangkat | Ketik angka meteran manual, atau pilih foto dari galeri (tombol File) |
| "Kamera Sedang Dipakai" | Kamera dipakai aplikasi lain (video call) | Tutup aplikasi itu, lalu coba lagi |
| "Kamera Tidak Kompatibel" | Mode kamera yang diminta tidak didukung | Coba kamera lainnya, atau ketik manual |

> `getUserMedia` hanya tersedia di **secure context**: HTTPS atau
> `localhost`. Di LAN HTTP objek `navigator.mediaDevices` memang tidak ada
> sama sekali — jadi ini bukan soal izin browser, dan izinnya sudah benar.
>
> Permintaan kamera memakai `facingMode` sebagai `ideal`, bukan string
> persis. String diperlakukan `{ exact: true }` oleh browser, sehingga di
> laptop yang kameranya hanya webcam depan, permintaan "kamera belakang"
> ditolak padahal kamera yang tersedia bisa dipakai.

---

## Tagihan

| Method | Endpoint | Role | Keterangan |
|---|---|---|---|
| GET | `/bills/recap` | Admin | Rekap tagihan |
| POST | `/bills/generate` | Admin | Generate tagihan bulanan (dengan parameter) |
| GET | `/bills/{id}` | Admin | Detail tagihan |
| GET | `/monthly-bills` | Admin, Teknisi | Daftar tagihan (teknisi: hanya pelanggan di wilayahnya) |
| GET | `/monthly-bills/{id}` | Admin, Teknisi | Detail tagihan (teknisi: hanya pelanggan di wilayahnya) |
| GET | `/monthly-bills/usage` | Admin, Teknisi | Rekap pemakaian |
| PUT | `/monthly-bills/usage` | Admin, Teknisi | Ubah meter akhir + hitung ulang tagihan |
| POST | `/monthly-bills/generate` | Admin | Generate tagihan bulanan (otomatis bulan ini) |
| POST | `/monthly-bills/{id}/pay` | Admin | Konfirmasi pembayaran tagihan |
| GET | `/reports/bills` | Admin | Laporan tagihan per periode |

**Query Parameters GET `/bills/recap`:**
```
?year=2026
?month=3
?status=unpaid|paid
```

**Request POST `/bills/generate`:**
```json
{
    "year": 2026,
    "month": 3
}
```

**Query Parameters GET `/monthly-bills`:**
```
?customer_id=1
?status=unpaid|paid
?month=3
?year=2026
```

**Query Parameters GET `/reports/bills` (wajib):**
```
?month=3
?year=2026
```

**Response GET `/reports/bills`:**
```json
{
    "success": true,
    "data": {
        "summary": {
            "total_tagihan": 77500,
            "total_dibayar": 0,
            "total_belum_dibayar": 77500
        },
        "bills": [...]
    }
}
```

**Response POST `/monthly-bills/{id}/pay`:**
```json
{
    "success": true,
    "message": "Pembayaran berhasil dikonfirmasi"
}
```

---

## Dashboard & Laporan

| Method | Endpoint | Role | Keterangan |
|---|---|---|---|
| GET | `/dashboard/statistics` | Admin | Statistik dashboard |
| GET | `/reports/billing` | Admin | Laporan tagihan per periode |
| GET | `/reports/installation` | Admin | Laporan instalasi per periode |
| GET | `/reports/installations` | Admin | Laporan instalasi (alternatif) |
| GET | `/reports/billing/export-csv` | Admin | Export tagihan ke CSV |
| GET | `/reports/billing/export-pdf` | Admin | Export tagihan ke PDF |
| GET | `/reports/installation/export-csv` | Admin | Export instalasi ke CSV |
| GET | `/reports/installation/export-pdf` | Admin | Export instalasi ke PDF |

**Query Parameters `/reports/billing`:**
```
?year=2026
?month=3
?status=unpaid|paid
```

**Query Parameters `/reports/installation`:**
```
?status=pending|surveyed|unpaid|processing|completed
?start_date=2026-01-01
?end_date=2026-03-31
```

---

## Format Response

Semua endpoint menggunakan format response yang sama:

**Berhasil:**
```json
{
    "success": true,
    "data": {...}
}
```

**Berhasil dengan pesan:**
```json
{
    "success": true,
    "message": "Pesan sukses",
    "data": {...}
}
```

**Gagal Validasi (422):**
```json
{
    "message": "...",
    "errors": {
        "field": ["pesan error"]
    }
}
```

**Tidak Terautentikasi (401):**
```json
{
    "message": "Unauthenticated."
}
```

**Akses Ditolak (403):**
```json
{
    "message": "Akses ditolak."
}
```

---

## Role & Akses

| Role | Akses |
|---|---|
| `admin` | Semua endpoint admin |
| `surveyor` | Input hasil survey |
| `teknisi` | Pencatatan meter, input hasil instalasi |
| `pelanggan` | (belum ada endpoint khusus) |

---

## Data Awal (Seeder)

| Email | Password | Role |
|---|---|---|
| `admin@ASTALOKA.test` | `password` | Admin |
| `surveyor@ASTALOKA.test` | `password` | Surveyor |
| `teknisi@ASTALOKA.test` | `password` | Teknisi |
