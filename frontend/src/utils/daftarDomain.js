/**
 * Daftar domain milik satu lokasi, untuk ditampilkan di panel pusat.
 *
 * Ada di satu tempat karena dipakai dua halaman -- daftar lokasi dan detail
 * lokasi -- dan dua versi yang sedikit berbeda akan menghasilkan tampilan
 * yang benar di satu halaman dan salah di halaman lain, tanpa pesan error
 * apa pun.
 *
 * MASALAH YANG DIATASI
 * ====================
 *
 * Satu lokasi punya dua sumber domain:
 *
 *   1. `domains` -- daftar dari tabel `tenant_domains`. Ini sumber yang
 *      lengkap: bisa memuat banyak domain, wildcard, dan status aktif.
 *   2. `domain` / `domain_alternatif` -- dua kolom legacy di tabel `tenants`.
 *      Cuma dua slot, tapi keduanya MASIH dipakai resolver sebagai cadangan.
 *
 * Keduanya bisa berbeda isinya, dan selisihnya tidak pernah muncul sebagai
 * error:
 *
 *   - Lokasi yang kehilangan domain utamanya dari `tenant_domains` -- karena
 *     bug lama yang menghapus baris utama setiap kali slot alternatif diisi --
 *     masih punya `domain` di kolom lamanya, dan host itu MASIH dilayani
 *     resolver. Kalau daftar dari tabel baru dipakai sendiri, host itu
 *     tidak terlihat di panel: admin melihat satu domain, padahal ada dua
 *     yang hidup.
 *
 *   - Sebaliknya, kolom legacy bisa menunjuk host yang sudah tidak
 *     dilayani -- admin sudah mengganti domain lewat halaman Domain, dan
 *     kolom lamanya belum ikut berubah. Host yang sudah ada di
 *     `tenant_domains` menang, karena itu yang benar-benar dilayani.
 *
 * Jadi aturannya: daftar dari `tenant_domains` jadi basisnya, dan kolom
 * legacy SELALU ditambahkan kalau host-nya belum ada di sana.
 *
 * Kenapa "selalu", bukan "kalau daftarnya kosong":
 *
 *   - Kolom legacy tidak pernah dihapus, bahkan ketika domainnya dihapus
 *     lewat halaman Domain. `TenantResolver` masih membacanya sebagai
 *     cadangan, jadi host itu MASIH dilayani. Kalau panel menyembunyikannya,
 *     admin tidak akan tahu kenapa domain lamanya masih jalan padahal tidak
 *     ada di daftar.
 *   - Lokasi yang kehilangan domain utamanya dari `tenant_domains` -- bug
 *     lama yang menghapus baris utama setiap kali slot alternatif diisi --
 *     tetap punya `domain` di kolom lamanya. Kalau daftar dari tabel baru
 *     dipakai sendiri, host itu tidak terlihat di panel: admin melihat satu
 *     domain, padahal ada dua yang hidup.
 *
 * Sebaliknya, host yang sudah ada di `tenant_domains` menang -- karena itu
 * yang benar-benar dilayani, dan di situ perannya (utama/cadangan, wildcard,
 * aktif/nonaktif) sudah diketahui.
 *
 * Fallback penuh ke kolom legacy dipakai kalau `domains` TIDAK ADA di
 * respons -- yang terjadi kalau endpoint dijalankan pada versi lama.
 */

/**
 * Host yang layak ditampilkan.
 *
 * String kosong, `null`, dan `undefined` dibuang -- kolom legacy yang kosong
 * tidak boleh muncul sebagai baris domain kosong di panel.
 */
function hostValid(host) {
  return typeof host === 'string' && host.trim() !== ''
}

/**
 * Daftar domain untuk satu lokasi.
 *
 * @param {object} lokasi Baris lokasi dari backend
 * @returns {Array<{key: string|number, host: string, is_primary: boolean, is_wildcard: boolean, is_active: boolean}>}
 */
export function daftarDomainLokasi(lokasi) {
  const dari = Array.isArray(lokasi?.domains) ? lokasi.domains : null

  const dasar = dari
    ? dari
        .filter((d) => d && hostValid(d.host))
        .map((d, i) => ({
          key: d.id ?? `d${i}`,
          host: d.host,
          is_primary: Boolean(d.is_primary),
          is_wildcard: Boolean(d.is_wildcard),
          is_active: d.is_active !== false,
        }))
    : []

  /*
   * Host dari kolom legacy yang belum ada di daftar baru.
   *
   * `domain` dianggap utama, `domain_alternatif` cadangan -- sama dengan
   * urutannya di form lokasi.
   */
  const sudahAda = new Set(dasar.map((d) => String(d.host)))

  const tambahan = [
    lokasi?.domain ? { host: lokasi.domain, is_primary: true } : null,
    lokasi?.domain_alternatif ? { host: lokasi.domain_alternatif, is_primary: false } : null,
  ]
    .filter((d) => d && hostValid(d.host) && !sudahAda.has(String(d.host)))
    .map((d, i) => ({
      key: `legacy-${i}`,
      host: d.host,
      is_primary: Boolean(d.is_primary),
      is_wildcard: false,
      is_active: true,
    }))

  /*
   * Fallback PENUH: `domains` tidak ada di respons sama sekali.
   *
   * Berbeda dari daftar yang kosong -- di situ tidak ada yang bisa dipulihkan
   * dari kolom legacy, jadi isinya memang tidak ada.
   */
  if (!dari) {
    return tambahan
  }

  return [...dasar, ...tambahan]
}
/**
 * Daftar domain milik satu lokasi, sudah diurutkan.
 *
 * Domain utama selalu di atas: itu satu-satunya yang dipakai
 * `settings.domain` di database lokasi dan satu-satunya yang muncul di kop
 * laporan. Setelah itu, yang aktif mendahului yang nonaktif, lalu diurutkan
 * menurut host supaya daftar tidak berkedip-kedip urutan.
 */
export function daftarDomainTerurut(lokasi) {
  return [...daftarDomainLokasi(lokasi)].sort((a, b) => {
    if (a.is_primary !== b.is_primary) return a.is_primary ? -1 : 1
    if (a.is_active !== b.is_active) return a.is_active ? -1 : 1
    return String(a.host).localeCompare(String(b.host))
  })
}

export default daftarDomainLokasi
