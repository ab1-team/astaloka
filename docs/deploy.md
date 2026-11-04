# Panduan Deploy ke Produksi

Dokumen ini menjelaskan cara menjalankan deploy otomatis ke server produksi.

Deploy dijalankan otomatis oleh workflow `Deploy` (`.github/workflows/deploy.yml`) setiap
ada push ke branch `main`.

---

## Prasyarat: GitHub Actions Secrets

Workflow deploy butuh **empat secret**. Tanpa semuanya, deploy berhenti di step
`Setup SSH key` dan tidak pernah menyentuh server.

| Secret | Isi | Contoh |
|---|---|---|
| `DEPLOY_SSH_KEY` | Private key SSH (format PEM/OpenSSH, **termasuk** baris `BEGIN` dan `END`) | `-----BEGIN OPENSSH PRIVATE KEY-----` ... |
| `SSH_HOST` | Alamat server (hostname atau IP) | `astaloka.siupk.com` |
| `SSH_PORT` | Port SSH | `22` |
| `SSH_USER` | User SSH yang punya akses ke folder proyek | `enpii` |

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

Di mesin lokal:

```bash
ssh-keygen -t ed25519 -C "deploy-astaloka" -f ~/.ssh/id_ed25519
```

Yang di-*push* ke GitHub hanya **public key**-nya:

```bash
cat ~/.ssh/id_ed25519.pub
```

Public key itu harus terdaftar di server, di `~/.ssh/authorized_keys` milik user
`SSH_USER`. Kalau tidak, deploy gagal dengan `Permission denied (publickey)`.

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

## Kalau ada yang gagal

Step deploy berhenti di step pertama yang gagal, jadi sisanya tidak dijalankan.
Yang sudah berhasil **tidak** dibatalkan -- misalnya bundle sudah ter-rsync tapi
migrasi gagal, jalankan ulang workflow setelah perbaikannya.

Untuk menjalankan ulang tanpa commit baru: **Actions → Deploy → Run workflow**.
