import { computed, ref } from 'vue'
import sopService from '@/services/sop.service'
import { storageLogoUrl } from '@/utils/storage'

/**
 * Identitas lembaga untuk kop surat/cetak.
 *
 * Sumber kebenaran: tabel `settings` (backend), dibaca lewat endpoint
 * `GET /api/settings/lembaga-identity`.
 *
 * Kenapa composable, bukan ambil sendiri di tiap laporan
 * =====================================================
 *
 * Kop surat butuh data yang sama di banyak halaman: Cetak Form Input,
 * Cetak Daftar Tagihan, semua laporan Pelaporan, Bukti Cell Jurnal Umum.
 * Kalau tiap halaman memanggil endpoint sendiri, satu sesi opening 5
 * laporan = 5 request untuk data yang isinya sama persis.
 *
 * Karena itu cache-nya DI MODUL (bukan per-instance composable): satu
 * request dipakai bersama seluruh komponen yang hidup di sesi ini.
 * Pola ini sama dengan `useKontakLembaga`.
 *
 * Kenapa endpoint publik, bukan `GET /settings/sop`
 * ===============================================
 *
 * `GET /settings/sop` khusus admin (`role:admin`), dan halaman Cetak Form
 * Input juga dipakai TEKNISI. Kalau composable ini memakai endpoint itu,
 * kop surat untuk teknisi akan kosong dan error-nya tertelan `catch`.
 * Endpoint `lembaga-identity` boleh dipanggil semua role login.
 *
 * Data fallback
 * =============
 *
 * Kalau request gagal, field dikembalikan kosong dan pemanggil bisa
 * menampilkan placeholder. Halaman cetak TIDAK boleh gagal total hanya
 * karena logo/alamat belum diisi admin — form tetap harus bisa dicetak.
 */

/** Data identitas terakhir yang sukses dimuat. */
const identitas = ref({
  nama: '',
  nama_lembaga: '',
  alamat: '',
  email: '',
  telepon: '',
  sk_kemenkumham: '',
  logo: null,
})

const isLoaded = ref(false)
const isLoading = ref(false)
let inflight = null

/**
 * Muat identitas lembaga (dengan cache modul).
 *
 * @param {boolean} force Abaikan cache dan paksa request baru. Dipakai
 *   setelah admin menyimpan Profil Lembaga / Logo.
 */
const load = async (force = false) => {
  if (isLoaded.value && !force) return identitas.value
  if (inflight) return inflight

  inflight = (async () => {
    try {
      isLoading.value = true
      const res = await sopService.getPublicIdentity()
      const data = res?.data?.data ?? res?.data ?? {}

      identitas.value = {
        nama: data.nama ?? '',
        nama_lembaga: data.nama_lembaga ?? '',
        alamat: data.alamat ?? '',
        email: data.email ?? '',
        telepon: data.telepon ?? '',
        sk_kemenkumham: data.sk_kemenkumham ?? '',
        logo: data.logo ?? null,
      }
    } catch {
      // Sengaja diam: kop surat hanya kosmetik. Halaman yang memanggil
      // composable ini punya fallback sendiri, jadi kegagalan request
      // tidak boleh menggagalkan seluruh halaman cetak.
    } finally {
      isLoaded.value = true
      isLoading.value = false
      inflight = null
    }

    return identitas.value
  })()

  return inflight
}

/**
 * Dipanggil setelah admin menyimpan Profil Lembaga atau Logo.
 *
 * Karena `identitas` berupa `ref` modul (shared), semua komponen yang
 * sedang membaca `namaLembaga` / `logoUrl` ikut berubah otomatis —
 * tanpa perlu hard refresh.
 */
export const refreshIdentitas = async () => {
  await load(true)
}

/**
 * Pastikan identitas sudah termuat, lalu kembalikan datanya.
 *
 * Dipakai halaman yang butuh nilai identitas saat membangun payload
 * (mis. `cetakForm.vue` menyusun `lembaga` untuk setiap halaman laporan).
 * `load()` dipakai supaya request paralel dari beberapa komponen tetap
 * hanya jadi satu.
 */
export const ensureIdentitas = async () => {
  await load()
  return identitas.value
}

/**
 * Inisial huruf untuk fallback logo (kalau logo belum diunggah).
 *
 * Diambil dari dua sumber: `nama_lembaga` lebih spesifik untuk kop
 * ("BUMDes Bangun Kencana"), `nama` dipakai sebagai cadangan.
 */
const initialsOf = (value) =>
  String(value ?? '')
    .split(' ')
    .filter((s) => s.length > 0)
    .slice(0, 2)
    .map((s) => s[0])
    .join('')
    .toUpperCase()

/**
 * @returns {{
 *   identitas: import('vue').Ref<Object>,
 *   isLoaded: import('vue').Ref<boolean>,
 *   isLoading: import('vue').Ref<boolean>,
 *   namaLembaga: import('vue').ComputedRef<string>,
 *   namaUsaha: import('vue').ComputedRef<string>,
 *   alamat: import('vue').ComputedRef<string>,
 *   email: import('vue').ComputedRef<string>,
 *   telepon: import('vue').ComputedRef<string>,
 *   skKemenkumham: import('vue').ComputedRef<string>,
 *   logoUrl: import('vue').ComputedRef<string>,
 *   initials: import('vue').ComputedRef<string>,
 *   reload: () => Promise<Object>
 * }}
 */
export function useIdentitasLembaga() {
  if (!isLoaded.value && !inflight) {
    load()
  }

  /**
   * Nama utama untuk kop.
   *
   * `nama_lembaga` lebih dulu karena itu nama badan usaha yang diurus
   * (mis. "BUMDes Bangun Kencana"), sedangkan `nama` biasanya berisi
   * nama aplikasi/tenant (mis. "demo"). Kalau `nama_lembaga` kosong,
   * balik ke `nama` supaya kop tetap punya isi.
   */
  const namaLembaga = computed(() => identitas.value.nama_lembaga || identitas.value.nama || '')

  const namaUsaha = computed(() => identitas.value.nama || '')

  const alamat = computed(() => identitas.value.alamat || '')
  const email = computed(() => identitas.value.email || '')
  const telepon = computed(() => identitas.value.telepon || '')
  const skKemenkumham = computed(() => identitas.value.sk_kemenkumham || '')

  const logoUrl = computed(() => storageLogoUrl(identitas.value.logo))

  const initials = computed(
    () => initialsOf(identitas.value.nama_lembaga || identitas.value.nama) || 'AL',
  )

  return {
    identitas,
    isLoaded,
    isLoading,
    namaLembaga,
    namaUsaha,
    alamat,
    email,
    telepon,
    skKemenkumham,
    logoUrl,
    initials,
    reload: () => load(true),
  }
}

export default useIdentitasLembaga
