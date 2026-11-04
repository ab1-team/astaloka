import axios from 'axios'

import { tenantSlugAktif } from '@/utils/tenantSlug'
import { apiBase } from '@/utils/apiBase'

/**
 * Ambil branding lokasi yang sedang melayani request.
 *
 * Tanpa autentikasi, dan kesalahannya tidak boleh menggagalkan halaman.
 *
 * Endpoint-nya ada di `PublicBrandingController`. Halaman login memanggilnya
 * sebelum ada sesi -- itu sebabnya endpoint itu tidak memakai
 * `utils/axios.js`. Instance axios di sana menambah spinner global lewat
 * interceptor, dan spinner itu muncul di halaman yang belum selesai dirender
 * sama sekali. Yang dipakai di sini adalah instance biasa, tanpa
 * `withCredentials` dan tanpa interceptor apa pun.
 *
 * `silentLoading` sengaja TIDAK dipakai di sini karena instance ini tidak
 * punya interceptor yang mengenalinya.
 *
 * Konsekuensinya, header `X-Tenant-Slug` juga harus dikirim manual di
 * bawah -- lihat catatan panjangnya di `ambilBranding()`.
 */
export async function ambilBranding() {
  /*
   * `X-Tenant-Slug` WAJIB di sini, dan tidak bisa diwarisi dari
   * `utils/axios.js`.
   *
   * Dua alasan, dan keduanya karena request ini memakai instance axios
   * biasa (lihat catatan file ini): instance itu punya interceptor yang
   * sudah menambahkan header itu, tapi header itu ditambahkan di
   * `centralAxios`/`axios` yang masing-masing punya interceptor sendiri --
   * dan yang di sini sengaja tidak dipakai supaya halaman login tidak
   * menarik spinner dan penghapusan token bersamanya.
   *
   * Akibatnya kalau header ini lupa, backend melihat "tidak ada lokasi
   * aktif" dan menjawab branding BAWAAN -- walaupun teks dan warnanya sudah
   * tersimpan di database pusat. Gejalanya persis seperti "penyimpanan
   * gagal", padahal tidak ada yang gagal menyimpan.
   *
   * Di produksi subdomain sudah menjawabnya dan header ini diabaikan.
   */
  /*
   * Panel pusat tidak boleh mewarisi slug lokasi dari localStorage.
   *
   * `tenantSlugAktif()` membaca `localStorage`, dan nilai itu bertahan
   * sampai logout lokasi. Superadmin yang membuka panel pusat di browser
   * yang sama akan mendapat branding lokasi terakhir kalau header ini tetap
   * dikirim. Branding panel pusat selalu bawaan; `CentralBrandingEditor`
   * memuat data lokasi lewat jalur lain.
   */
  const diPanelPusat =
    typeof window !== 'undefined' && (window.location?.pathname ?? '').startsWith('/central')

  const headers = { Accept: 'application/json' }
  const slug = diPanelPusat ? null : tenantSlugAktif()

  if (slug) {
    headers['X-Tenant-Slug'] = slug
  }

  const res = await axios.get('/branding', {
    baseURL: baseUrlApi(),
    timeout: 10000,
    headers,
  })

  return res.data?.data ?? null
}

/**
 * Base URL API, bentuk yang sama dengan `utils/axios.js`.
 *
 * Instance axios-nya sendiri TIDAK diimpor dari `utils/axios.js`: yang
 * diekspor sana sudah berisi interceptor -- dan mengimpor instance itu ke
 * sini akan menarik seluruh interceptor-nya (spinner, penghapusan token
 * saat 401) ke halaman login. Yang diimpor hanya PERHITUNGAN URL-nya, dari
 * `utils/apiBase.js`, supaya bentuk basis URL sama di semua file.
 *
 * `VITE_BACKEND_URL` SENGAJA TIDAK dipakai, sama seperti di `utils/axios.js`:
 * host di sana adalah host backend, bukan tempat SPA ini berada. Dipakai
 * di sini, halaman login yang dibuka lewat `demo.astaloka.test:5173` akan
 * meminta branding ke host pusat -- dan branding bawaan-lah yang muncul,
 * padahal teks dan warnanya tersimpan rapi di database.
 */
function baseUrlApi() {
  return apiBase()
}
