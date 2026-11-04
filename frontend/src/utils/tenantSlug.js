/**
 * Slug lokasi yang sedang dilayani, untuk dikirim ke backend.
 *
 * Kenapa file ini ada
 * ===================
 *
 * Backend mencari lokasi dari DUA sumber, berurutan (lihat `TenantResolver`):
 * host subdomain lebih dulu, lalu header `X-Tenant-Slug`.
 *
 * Di produksi (`TENANCY_LOOKUP=host`) subdomain sudah menjawabnya, dan
 * header ini diabaikan. Di development lokal (`TENANCY_LOOKUP=slug`) tidak
 * ada subdomain per lokasi -- semuanya dilayani `localhost:5173` -- jadi
 * satu-satunya cara backend tahu lokasi mana yang dimaksud adalah header
 * yang dikirim frontend.
 *
 * Bug yang paling mahal kalau ini tidak ada
 * =========================================
 *
 * Tanpa header, `TenantManager::current()` bernilai `null` di SELURUH
 * request yang tidak lewat subdomain. Akibatnya `GET /api/branding` selalu
 * menjawab branding BAWAAN: teks dan warna hasil editan panel pusat tidak
 * akan pernah muncul, dan gejalanyaylophone "penyimpanan gagal" padahal
 * datanya tersimpan rapi di database.
 *
 * Di produksi hal ini tidak terjadi karena subdomainnya benar. Jadi bug ini
 * hanya terlihat di development -- tapi itu justru tempat orang menguji
 * perubahan tampilan, jadi ia justru menutupi dirinya sendiri.
 *
 * Sumber nilainya
 * ===============
 *
 * `localStorage` lebih dulu, lalu subdomain. Alasannya: panel pusat dibuka
 * lewat `localhost:5173` (tanpa subdomain) tapi bisa saja justru sedang
 * menyunting lokasi tertentu -- slug yang tersimpan waktu login lebih
 * boleh dipercaya daripada subdomain panel pusat yang memang tidak punya.
 *
 * Di produksi `localStorage` biasanya kosong (login lewat subdomain), jadi
 * jalur kedua yang dipakai, dan hasilnya sama dengan yang biasa. Panel pusat
 * yang diakses lewat subdomain `www` sengaja dikecualikan di bawah.
 */

/** Kunci localStorage. Sama dengan `slug_header` di `config/tenancy.php`. */
export const TENANT_SLUG_KEY = 'tenant_slug'

/** Simpan slug lokasi setelah berhasil login. */
export function simpanTenantSlug(slug) {
  const bersih = typeof slug === 'string' ? slug.trim().toLowerCase() : ''

  /*
   * Nilai kosong bukan "tidak ada yang berubah" -- itu perintah untuk
   * melepas lokasi lama. Tanpa ini, login pusat setelah sesi lokasi masih
   * membawa `X-Tenant-Slug` milik lokasi sebelumnya.
   */
  if (!bersih) {
    hapusTenantSlug()
    return
  }

  try {
    localStorage.setItem(TENANT_SLUG_KEY, bersih)
  } catch {
    // localStorage bisa ditolak (mode privat, kuota penuh). Branding tetap
    // jalan lewat subdomain di produksi, jadi kegagalan di sini bukan alasan
    // untuk menggagalkan login.
  }
}

/** Hapus slug saat logout, supaya lokasi berikutnya tidak salah. */
export function hapusTenantSlug() {
  try {
    localStorage.removeItem(TENANT_SLUG_KEY)
  } catch {
    // Sama seperti di atas: tidak ada yang bisa dilakukan, dan tidak fatal.
  }
}

/**
 * Slug untuk dikirim sebagai `X-Tenant-Slug`, atau null kalau tidak ada.
 *
 * Header tidak dikirim kalau null: mengirim string kosong membuat
 * `TenantResolver::fromSlug()` melihat `$slug = ''`, dan itu sama
 * dengan tidak punya identitas -- jadi tidak ada gunanya, hanya menambah
 * satu header sia-sia di setiap request.
 */
export function tenantSlugAktif() {
  try {
    const tersimpan = localStorage.getItem(TENANT_SLUG_KEY)

    if (tersimpan && tersimpan.trim()) return tersimpan.trim().toLowerCase()
  } catch {
    // Lanjut ke subdomain di bawah.
  }

  if (typeof window === 'undefined') return null

  const host = window.location.hostname.toLowerCase()

  /*
   * Subdomain per lokasi: `<slug>.astaloka.test`.
   *
   * Hanya diambil kalau host punya subdomain. `astaloka.test` polos dan
   * `localhost` tidak punya, dan keduanya berarti "panel pusat / dev",
   * bukan sebuah lokasi.
   *
   * Bentuk `<apa saja>.<domain utama>` dicoba, dan kalau hasilnya tidak
   * bentuk `<apa saja>.<domain utama>` dicoba, dan kalau hasilnya tidak
   * ada tenant dengan slug itu, backend akan mengabaikannya (resolver
   * jatuh ke null) -- persis seperti kalau header tidak dikirim.
   * Satu-satunya risiko yang nyata adalah subdomain yang kebetulan bernama
   * seperti slug tenant, dan itu memang lokasi itu sendiri.
   */
  const bagian = host.split('.')

  if (bagian.length < 3) return null

  const kandidat = bagian[0]

  /*
   * `www` bukan slug lokasi. Tanpa pengecualian ini, `www.astaloka.co.id`
   * akan dikirim sebagai slug `www`, dan backend akan wasting satu query
   * untuk mencari tenant yang memang tidak mungkin ada.
   */
  if (kandidat === 'www' || kandidat === 'app' || kandidat === 'api') return null

  return kandidat
}
