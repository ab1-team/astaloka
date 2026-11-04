# Panduan Deploy ke Produksi

Dokumen ini menjelaskan cara menjalankan deploy otomatis ke server produksi.

Deploy dijalankan otomatis oleh workflow `Deploy` (`.github/workflows/deploy.yml`) setiap
ada push ke branch `main`.

## Status saat ini

| Item | Status |
|---|---|
| Empat GitHub Actions secret | **Terisi** (`DEPLOY_SSH_KEY`, `SSH_HOST`, `SSH_PORT`, `SSH_USER`) |
| Koneksi TCP ke server (`103.177.95.92:22`) | **Terbuka** |
| Public key deploy terdaftar di server | **Belum** -- ini satu-satunya sisa |
| Host `astaloka.siupk.com` di Nginx Proxy Manager + Cloudflare | **Belum** diarahkan |
| Build frontend + artifact upload | **Hijau** |
| Migrasi, restart container, smoke test | Belum dijalankan -- tertahan public key |

Deploy terakhir berhenti di step `Test SSH connection` dengan
`Permission denied (publickey)`. artinya runner **sudah** sampai ke server,
hanya public key-nya yang belum dikenali.

**Langkah berikutnya (di server, bukan di repo):**

1. Daftarkan public key di `~/.ssh/authorized_keys` milik user `enpii`
   (public key-nya ada di bagian "Public key yang harus terdaftar di server"
   di bawah).
2. Jalankan ulang workflow: **Actions → Deploy → Run workflow**.
3. Kalau smoke test masih 404, arahkan host `astaloka.siupk.com` di Nginx
   Proxy Manager dan Cloudflare (lihat bagian "Prasyarat di sisi server").

---

## Prasyarat: GitHub Actions Secrets

Workflow deploy butuh **empat secret**. Tanpa semuanya, deploy berhenti di step
`Setup SSH key` dan tidak pernah menyentuh server.

| Secret | Isi | Contoh |
|---|---|---|
| `DEPLOY_SSH_KEY` | Private key SSH (format PEM/OpenSSH, **termasuk** baris `BEGIN` dan `END`) | `-----BEGIN OPENSSH PRIVATE KEY-----` ... |
| `SSH_HOST` | Alamat server **SSH** (bukan alamat web!) | `103.177.95.92` |
| `SSH_PORT` | Port SSH | `22` |
| `SSH_USER` | User SSH yang punya akses ke folder proyek | `enpii` |

> **`SSH_HOST` itu alamat SSH, bukan alamat web aplikasi.**
> `astaloka.siupk.com` berada di belakang Cloudflare -- Cloudflare tidak
> menyediakan SSH, dan workflow runner tidak akan pernah sampai ke server
> kalau `SSH_HOST` diisi dengan alamat itu. Isi dengan IP server yang
> menjalankan Docker (`103.177.95.92`), bukan dengan domain frontend.

> **`SSH_PORT` juga bukan port web.** Port `80`/`443` itu HTTP/HTTPS.
> Port default SSH adalah `22`; kalau server Pakai port lain, isi yang
> benar -- jangan sampai `443`, karena itu port HTTPS dan handshake SSH
> di sana akan gagal.

### Public key yang harus terdaftar di server

`DEPLOY_SSH_KEY` (private) harus berpasangan dengan public key yang
terdaftar di `~/.ssh/authorized_keys` milik `SSH_USER` di server.

Public key untuk pasangan key yang sudah disiapkan untuk repo ini:

```
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGF+k9CgEHvFNpgJvOpHDDi4yWsLqjeJ6cumdjBjM+li github-actions-deploy@astaloka
```

Fingerprint-nya:

```
SHA256:gnIazfpE9PV/e+wdabF9t3CfmmttXiGw7DXY02tgcA0
```

Kalau public key itu belum terdaftar di server, tambahkan:

```bash
echo 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGF+k9CgEHvFNpgJvOpHDDi4yWsLqjeJ6cumdjBjM+li github-actions-deploy@astaloka' >> ~/.ssh/authorized_keys
chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys
```

Untuk memastikan key-nya sudah terdaftar, di server:

```bash
ssh-keygen -lf ~/.ssh/authorized_keys
```

Sidik jari `SHA256:gnIazfpE9PV/e+wdabF9t3CfmmttXiGw7DXY02tgcA0` harus muncul
di daftar itu.

### Cara mengisi

1. Buka **Settings → Secrets and variables → Actions → New repository secret**
2. Isi satu per satu dengan nama persis seperti di tabel (huruf besar, tanpa spasi).
3. Ulangi untuk keempat secret.

Atau lewat CLI, kalau `gh` sudah login:

```bash
gh secret set DEPLOY_SSH_KEY < ~/.ssh/id_ed25519
gh secret set SSH_HOST
gh secret set SSH_PORT
gh secret set SSH_USER
```

> `gh secret set DEPLOY_SSH_KEY < file` membaca private key dari file, jadi
> private key tidak pernah muncul di riwayat shell.

### Membikin key SSH kalau belum punya

Kalau kamu lebih suka memakai key sendiri daripada key yang sudah disiapkan,
buat di mesin lokal:

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy@astaloka" -f ~/.ssh/id_ed25519
```

Yang di-*push* ke GitHub hanya **public key**-nya:

```bash
cat ~/.ssh/id_ed25519.pub
```

Public key itu harus terdaftar di server, di `~/.ssh/authorized_keys` milik user
`SSH_USER`. Kalau tidak, deploy gagal dengan `Permission denied (publickey)`.

> **Private key jangan pernah masuk repo.** Kalau private key pernah
> ter-commit, anggap sudah bocor -- hapus dari riwayat git **dan** ganti
> key-nya di server, karena private key yang ada di riwayat git tetap bisa
> dipakai siapa pun yang punya salinannya.

---

## Gejala &diagnosis

Workflow sudah menulis diagnosis yang bisa ditindaklanjuti ke log runner
**dan** ke `$GITHUB_STEP_SUMMARY` (tab Summary di halaman run). Baca dua tempat
itu duluan sebelum menebak.

### `secret Actions berikut belum diisi di repository ini: ...`

Empat secret di atas belum diisi, atau ada yang namanya salah eja.

Isi ulang satu per satu, lalu jalankan ulang workflow (`workflow_dispatch`) --
tidak perlu commit baru.

### `DEPLOY_SSH_KEY bukan private key yang sah`

Isi secret-nya **public key** (`ssh-ed25519 ...`) atau private key yang terpotong.

Yang dibutuhkan private key utuh, termasuk baris `BEGIN` dan `END`. Periksa
dengan:

```bash
ssh-keygen -y -f ~/.ssh/id_ed25519
```

Kalau perintah itu gagal, file-nya rusak atau bukan private key.

### `koneksi SSH ditolak` / `Permission denied (publickey)`

Host dan port benar, tapi public key-nya tidak terdaftar di server.

Diagnosis lengkap (termasuk sidik jari public key yang harus ditambahkan)
tertulis di log. Di server:

```bash
echo '<public key>' >> ~/.ssh/authorized_keys
chmod 700 ~/.ssh
chmod 600 ~/.ssh/authorized_keys
```

### `gagal menghubungi HOST:PORT via ssh-keyscan`

Port salah, atau firewall menolak koneksi dari runner GitHub.

Cek manual:

```bash
ssh -p PORT -v SSH_USER@HOST
```

Kalau FIREWALL dari runner jadi penyebab, openssh-nya harus menerima koneksi
dari IP runner GitHub -- keduanya di luar jangkauan repo ini.

---

## Yang dilakukan workflow

1. **Build frontend** (`pnpm build`) + dua penjaga: bundle tidak boleh mengunci
   host backend, dan tidak boleh mengandung `/api/api`.
2. **Unggah artefak** `frontend/dist`.
3. **Rsync** aset ke `<server>:/home/enpii/astaloka/backend/public/`.
   File yang tidak boleh terhapus (`index.php`, `.htaccess`, `favicon.ico`,
   `robots.txt`, `storage`, `assets/branding/`) dikecualikan.
4. **Pull source** di server (`git pull --ff-only origin main`), lalu
   `composer install`.
5. **Migrasi**: `tenancy:migrate-central` (pusat) lalu
   `tenancy:migrate-tenants` (semua lokasi terdaftar).
   `php artisan migrate` biasa **tidak** dipakai -- folder migrasi lokasi
   sengaja dipisah ke `database/migrations_tenant/`.
6. **Clear cache** (`config`, `route`, `view`) **setelah** migrasi.
7. **Restart container** dengan `docker compose up -d --build app web`.
   Bukan `restart` -- `restart` memakai image LAMA, jadi bundle baru tertinggal.
8. **Smoke test** ke `https://astaloka.siupk.com` dan `/api/health`.

---

## Prasyarat di sisi server (di luar repo ini)

Secret GitHub Actions hanya setengah cerita. Dua hal berikut juga harus siap,
dan keduanya **tidak bisa dari repo ini**:

### 1. Public key deploy terdaftar di server

`DEPLOY_SSH_KEY` yang diisi GitHub harus berpasangan dengan public key yang
terdaftar di `~/.ssh/authorized_keys` milik `SSH_USER` di server.

Kalau belum ada key-nya, buat dulu (di mesin lokal):

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy@astaloka" -f ~/.ssh/id_ed25519
```

Lalu daftarkan public key-nya di server:

```bash
echo 'ssh-ed25519 AAAA... github-actions-deploy@astaloka' >> ~/.ssh/authorized_keys
chmod 700 ~/.ssh && chmod 600 ~/.ssh/authorized_keys
```

Bisa juga lewat Nginx Proxy Manager atau panel admin server, kalau memang
begitu cara aksesnya.

### 2. Host `astaloka.siupk.com` harus diarahkan ke aplikasi

Arsitektur yang berjalan saat ini:

```
astaloka.siupk.com  -->  Cloudflare  -->  Nginx Proxy Manager (103.177.95.92)
                                                      |
                                                      +--> container astaloka (port 8000)
```

Dua hal yang harus benar:

- **Di Nginx Proxy Manager**, host `astaloka.siupk.com` harus dibuat dan
  diarahkan ke `103.177.95.92:8000` (port container Laravel), dengan
  SSL memakai sertifikat Let's Encrypt.
- **Di Cloudflare**, record `astaloka` untuk `siupk.com` harus berupa
  `A` record ke `103.177.95.92` -- **bukan** proxy orange-cloud kalau
  sertifikatnya diterbitkan lewat Cloudflare, karena Cloudflare tidak bisa
  meneruskan HTTP ke origin yang tidak punya sertifikat.

Symptom kalau salah satu belum benar:

| Gejala | Artinya |
|---|---|
| `astaloka.siupk.com` balas **404** dari Cloudflare | Record DNS belum diarahkan ke origin, atau host belum dibuat di Nginx Proxy Manager |
| `astaloka.siupk.com` balas **"Default Site"** | Host belum dibuat di Nginx Proxy Manager -- itu halaman default NPM, bukan aplikasi |
| `/api/health` balas **404** | Request tidak sampai ke container Laravel, jadi routing-nya belum benar |

> `pamsides.siupk.com` (aplikasi lama) sudah diarahkan ke
> `103.177.95.92` dengan bypass Cloudflare, dan masih hidup. Itu hostname
> yang berbeda -- jangan dipakai untuk astaloka.

---

## Kalau ada yang gagal

Step deploy berhenti di step pertama yang gagal, jadi sisanya tidak dijalankan.
Yang sudah berhasil **tidak** dibatalkan -- misalnya bundle sudah ter-rsync tapi
migrasi gagal, jalankan ulang workflow setelah perbaikannya.

Untuk menjalankan ulang tanpa commit baru: **Actions → Deploy → Run workflow**.

### Smoke test gagal tapi deploy-nya succeed

`Smoke test production` memeriksa `https://astaloka.siupk.com` dan
`/api/health`. Kalau keduanya 404 padahal semua step sebelumnya sukses,
artinya **deploy-nya sudah benar** -- bundle sudah ter-upload, migrasi sudah
jalan, container sudah restart. Yang belum benar adalah **routing di sisi
server**: host belum dibuat di Nginx Proxy Manager atau DNS belum diarahkan
ke origin.

Cocokkan dengan tabel gejala di bagian "Prasyarat di sisi server" di atas.
Perbaikannya di server, bukan di repo -- jadi tidak perlu commit baru.
Setelah hostnya dibetulkan, jalankan ulang workflow untuk memastikan smoke
test hijau.
