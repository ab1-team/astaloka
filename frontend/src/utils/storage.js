/**
 * URL absolut untuk file statis di backend (logo, lampiran, foto unduhan).
 *
 * Kenapa file ini ada
 * ==================
 *
 * Halaman laporan (kop surat, bukti cetak) tidak boleh memakai URL relatif
 * seperti `src="/storage/..."`, karena dokumen itu sering dibuka lewat
 * `window.print()` atau disimpan sebagai PDF. Keduanya membuka dokumen di
 * luar SPA, dan URL relatif ikut hilang begitu tidak ada lagi halaman yang
 * menyediakannya -- hasilnya logo kosong tanpa pesan error.
 *
 * Kenapa BUKAN `import.meta.env.VITE_BACKEND_URL`
 * ===============================================
 *
 * Nilai itu hanya jadi target proxy Vite, dan sengaja TIDAK boleh dipakai
 * di sini. Kalau dipakai, laporan yang dibuka dari
 * `demo.astaloka.test:5173` akan menarik logo dari `astaloka.test` -- host
 * pusat, tanpa subdomain. Untuk panel pusat itu memang benar, tapi untuk
 * aplikasi lokasi berarti logo diambil dari lokasi yang salah.
 *
 * Yang dipakai di sini adalah origin sendiri. Dia selalu benar di dua mode:
 *
 *   - produksi  : SPA dan backend satu host, jadi origin = backend
 *   - dev       : subdomain lokasi ikut terbawa, dan proxy meneruskannya
 *
 * Dan `VITE_API_BASE_URL` tetap dipakai kalau diisi, karena itu override
 * eksplisit -- kalau memang backend ada di mesin lain, memaksa semua
 * request kesana memang itu yang diinginkan.
 *
 * Perhitungannya memakai `utils/apiBase.js`, supaya bentuk basis URL sama
 * dengan yang dipakai axios. Sebelumnya file ini menambahkan `/api` sendiri
 * sementara `utils/axios.js` tidak -- jadi satu nilai `VITE_API_BASE_URL`
 * menghasilkan dua basis URL berbeda, dan `.../api/api` muncul di produksi.
 */

import { apiBase, apiOrigin } from '@/utils/apiBase'

/**
 * Basis API absolut.
 *
 * Falls back ke origin sendiri kalau tidak ada konfigurasi, bukan ke
 * `localhost` atau `127.0.0.1`. Fallback ke localhost terlihat benar
 * selama satu mesin, dan langsung rusak begitu SPA dibuka lewat nama host
 * lain -- persis kondisi panel pusat dan subdomain lokasi.
 */
export const API_BASE = apiBase()

/**
 * Origin backend tanpa suffix `/api`.
 *
 * Dipakai laporan untuk membentuk URL file. Backend dan proxy memang bisa
 * berbeda port dari SPA, jadi port ikut dibawa -- kecuali saat portnya port
 * default HTTP, yang tidak perlu ditulis ulang.
 */
export const API_ORIGIN = apiOrigin()

/**
 * URL absolut untuk sebuah file di backend.
 *
 * `path` boleh sudah berupa URL lengkap -- lalu dikembalikan apa adanya,
 * supaya `storageUrl('https://cdn.example.com/logo.png')` tidak dirusak
 * oleh prefix origin.
 *
 * Path relatif dibersihkan dari `/` di depan supaya tidak jadi `//`, yang
 * browser baca sebagai protocol-relative URL dan diarahkan ke host lain.
 */
export const storageUrl = (path) => {
  const clean = String(path ?? '').trim()

  if (!clean) return API_ORIGIN
  if (/^(https?:)?\/\//i.test(clean) || /^data:/i.test(clean)) return clean

  return `${API_ORIGIN}/${clean.replace(/^\/+/, '')}`
}

/**
 * URL absolut untuk logo lembaga.
 *
 * Nama helper ini sengaja BUKAN `logoUrl` — sudah ada computed bernama
 * `logoUrl` di beberapa komponen (BaseReportLayout, ReportCover, dst), dan
 * mengimpor fungsi dengan nama yang sama di file yang sama akan bentrok.
 *
 * Kenapa perlu helper khusus
 * ========================
 *
 * Nilai `settings.logo` disimpan sebagai NAMA FILE saja (mis.
 * `logo-abc.jpg`), dan file-nya disimpan di `storage/app/public/sop/logo/`.
 * File publik seperti ini disajikan lewat symlink `public/storage`, jadi
 * URL yang benar adalah `/storage/sop/logo/<nama-file>`.
 *
 * Sebelumnya beberapa komponen membentuk URL-nya sendiri dengan
 * `${storageUrl('')}/sop/logo/${logo}` — TANPA segmen `storage`. Itu
 * menghasilkan `https://host/sop/logo/file.jpg` yang 404, sehingga logo
 * tidak pernah muncul di kop laporan. Helper ini dipakai di semua tempat
 * supaya path-nya hanya ada di satu titik dan tidak salah lagi.
 *
 * Path yang sudah lengkap (http/https/data) atau yang sudah memuat
 * `/storage/` dikembalikan apa adanya supaya tidak jadi dobel prefix.
 */
export const storageLogoUrl = (logo) => {
  const clean = String(logo ?? '').trim()

  if (!clean) return ''
  if (/^(https?:)?\/\//i.test(clean) || /^data:/i.test(clean)) return clean

  // Sudah berupa path storage yang utuh (mis. "storage/sop/logo/a.jpg").
  if (/^\/?storage\//i.test(clean)) {
    return storageUrl(clean)
  }

  // Sudah memuat folder logo (mis. "/sop/logo/a.jpg").
  if (clean.includes('/sop/logo/')) {
    return storageUrl(clean)
  }

  // Bentuk paling umum: hanya nama file.
  return storageUrl(`storage/sop/logo/${clean.replace(/^\/+/, '')}`)
}

export default storageUrl
