import { ref, watch, onMounted, isRef, unref } from 'vue'
import dashboardService from '@/services/dashboard.service'

/**
 * Fetch data untuk popup arsip dashboard admin (4 kotak statistik).
 *
 * Semua popup ini sebelumnya menyalin blok fetch/watch yang sama persis,
 * dan salinan itu membawa dua bug yang muncul justru karena disalin:
 *
 * 1. Ganti `per_page` TIDAK mengembalikan `currentPage` ke 1.
 *
 *    `DataTable` mengirim `update:perPage` DAN `update:currentPage`, tapi
 *    `currentPage` tidak ikut berubah. Jadi kalau user sedang di halaman
 *    terakhir (mis. halaman 19 dari 19 dengan per_page=10, total 190) lalu
 *    mengganti ke per_page=50, server menerima `page=19&per_page=50` --
 *    offset 900, sedangkan total hanya 190. Server membalas data kosong,
 *    tapi `DataTable` menghitung label dari state lokal:
 *    `Showing 901 to 190 of 190 entries`. Angka 901 lebih besar dari 190,
 *    dan tidak ada baris yang tampil.
 *
 * 2. Balasan request lama bisa menimpa balasan request baru.
 *
 *    Dua request berjalan bersamaan (mis. user ganti per_page lalu langsung
 *    klik halaman). Kalau request `per_page=10` selesai LEBIH LAMBAT dari
 *    request `per_page=50`, state akhirnyalah yang salah: label
 *    "Showing 51 to 100" padahal barinya 10 (dari per_page=10). Tidak ada
 *    yang salah di masing-masing request -- hanya urutannya.
 *
 * Solusinya di sini:
 *   - `per_page` berubah -> `currentPage` setback ke 1 (bug 1).
 *   - tiap request membawa nomor urut; hanya balasan dengan nomor urut
 *     terbaru yang boleh mengubah state (bug 2).
 *
 * PARAMETER YANG REAKTIF
 * -----------------------
 *
 * `params` boleh berupa `ref`/`reactive` (atau objek biasa). Kalau begitu,
 * perubahannya memicu fetch ulang sendiri -- dan nomor halaman dikembalikan
 * ke 1, karena hasil di halaman berbeda tidak comparable dengan hasil di
 * halaman lama.
 *
 * Yang TIDAK boleh: `params` yang berubah diam-diam tanpa fetch ulang.
 * Itu yang membuat filter bulan di halaman Arsip Piutang lama terlihat
 * "nyangkut": bulan yang diganti tidak pernah benar-benar sampai ke server.
 *
 * @param {string} type          Tipe popup: instalasi | pemakaian | tunggakan | tagihan
 * @param {Object} [opts]
 * @param {Object|import('vue').Ref} [opts.params] Parameter tambahan yang
 *                                dikirim ke server (mis. `{ month, year }`).
 * @param {Function} [opts.petakan] Pemeta baris server -> baris tabel.
 * @param {boolean} [opts.otomatis] Langsung fetch saat komponen mounted.
 */
export function usePopupData(type, { params = {}, petakan = null, otomatis = true } = {}) {
  const searchQuery = ref('')
  const currentPage = ref(1)
  const perPage = ref(10)
  const loading = ref(false)
  const serverTotal = ref(0)
  const totalPages = ref(1)
  const itemsList = ref([])
  const errorMessage = ref(null)
  const meta = ref({})

  /*
   * Nomor urut request.
   *
   * `requestId` bertambah setiap kali request baru dimulai. Saat balasan
   * datang, bandingkan dengan `latestRequestId`: kalau lebih kecil, balasan
   * itu sudah usang dan HARUS diabaikan -- tidak hanya tidak dipakai untuk
   * `itemsList`, tapi juga tidak boleh menyentuh `loading`, karena
   * `loading = false` dari balasan usang akan mematikan spinner milik
   * request yang masih jalan.
   */
  let requestId = 0
  let latestRequestId = 0

  /**
   * Param yang dikirim ke server.
   *
   * `unref` dipakai supaya `params` bisa berupa `ref` (reaktif) maupun
   * objek biasa. Untuk objek biasa, isinya disalin per request -- kalau
   * tidak, objek yang sama akan ikut berubah di tengah request yang
   * sedang berjalan.
   */
  const paramRequest = () => {
    const nilai = unref(params)

    return nilai && typeof nilai === 'object' ? { ...nilai } : {}
  }

  const fetchData = async () => {
    const myId = ++requestId
    latestRequestId = myId

    try {
      loading.value = true
      errorMessage.value = null

      const response = await dashboardService.getPopupData(type, {
        page: currentPage.value,
        per_page: perPage.value,
        search: searchQuery.value,
        ...paramRequest(),
      })

      // Balasan usang: request yang lebih baru sudah dimulai. Abaikan.
      if (myId !== latestRequestId) return

      if (response?.success) {
        const raw = response.data || []
        itemsList.value = petakan ? raw.map(petakan) : raw
        serverTotal.value = response.meta?.total ?? itemsList.value.length
        totalPages.value = response.meta?.last_page ?? 1
        meta.value = response.meta || {}
      } else {
        itemsList.value = []
        serverTotal.value = 0
        totalPages.value = 1
        meta.value = {}
        errorMessage.value = response?.message || 'Gagal memuat data.'
      }
    } catch (err) {
      // Balasan usang: abaikan juga errornya.
      if (myId !== latestRequestId) return

      itemsList.value = []
      serverTotal.value = 0
      totalPages.value = 1

      /*
       * Pesan error TIDAK lagi ditelan.
       *
       * Sebelumnya `catch` di sini sengaja diamkan dengan alasan "popup
       * cukup menampilkan data yang terakhir sukses". Akibatnya endpoint
       * yang 403 (menu dicabut dari jabatan) atau 500 membuat popup
       * menampilkan "Tidak Ada Tunggakan" -- yang ARTINYA "tidak ada
       * tunggakan", bukan "halaman ini gagal dimuat". Dua hal yang sama
       * sekali, dan yang salah adalah yang kedua.
       *
       * `errorMessage` dibaca oleh pemanggil untuk menampilkan banner
       * kesalahan; tabel tetap tampil (kosong) supaya layout tidak rusak.
       */
      errorMessage.value = err?.response?.data?.message || err?.message || 'Gagal memuat data.'
    } finally {
      // Hanya request terbaru boleh menurunkan spinner.
      if (myId === latestRequestId) {
        loading.value = false
      }
    }
  }

  /*
   * `per_page` DAN `currentPage` dipantau BERSAMA, tapi perbedaannya
   * dijaga di dalam watcher: saat `per_page` berubah, `currentPage`
   * disetel ke 1 DI DALAM watcher itu sendiri, sebelum fetch dijalankan.
   *
   * Kalau disetel dari luar (`watch(perPage, () => currentPage.value = 1)`),
   * Vue akan menjadwalkan watcher `currentPage` sebagai pekerjaan terpisah --
   * sehingga request dengan `page` lama bisa tetap terkirim.
   */
  watch([currentPage, perPage], (newVal, oldVal) => {
    const perPageChanged = oldVal && newVal[1] !== oldVal[1]

    if (perPageChanged && currentPage.value !== 1) {
      currentPage.value = 1
      // `currentPage` yang disetel di sini akan memicu watcher ini lagi.
      // Kembalilah sekarang: watcher kedua itu yang melakukan fetch dengan
      // page=1 yang benar.
      return
    }

    fetchData()
  })

  /*
   * Search di-debounce 300ms supaya mengetik tidak menembak request
   * di setiap ketukan, dan halaman dikembalikan ke 1 (hasil search
   * berbeda dari halaman lama).
   */
  let searchTimer = null
  watch(searchQuery, () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      if (currentPage.value !== 1) {
        currentPage.value = 1
        return
      }
      fetchData()
    }, 300)
  })

  /*
   * Parameter reaktif (mis. filter bulan/tahun).
   *
   * Hanya dipasang kalau `params`-nya memang reaktif. Untuk objek biasa,
   * watcher ini tidak akan pernah memicu -- dan tidak perlu, karena
   * objek biasa tidak berubah sendiri.
   */
  if (isRef(params) || (params && typeof params === 'object' && '__v_isReactive' in params)) {
    watch(
      () => paramRequest(),
      () => {
        // Filter berubah -> hasil di halaman lama tidak berlaku lagi.
        if (currentPage.value !== 1) {
          currentPage.value = 1
          return
        }
        fetchData()
      },
      { deep: true },
    )
  }

  onMounted(() => {
    if (otomatis) fetchData()
  })

  return {
    searchQuery,
    currentPage,
    perPage,
    loading,
    serverTotal,
    totalPages,
    itemsList,
    errorMessage,
    meta,
    fetchData,
  }
}
