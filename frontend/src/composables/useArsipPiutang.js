import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import piutangService from '@/services/piutang.service'

/**
 * Data Arsip Piutang: piutang pelanggan yang belum tertagih.
 *
 * DIPISAH dari `usePopupData` karena arsip butuh tiga hal yang popup tidak
 * punya:
 *
 *   1. FILTER PERIODE yang reaktif. Filter di popup selalu "semua", jadi
 *      `usePopupData` yang lama hanya perlu parameter statis.
 *   2. RINGKASAN (jumlah tagihan, total piutang, tanggal generate) yang
 *      dipakai kartu ringkasan di halaman Arsip.
 *   3. PESAN ERROR yang tampil, bukan ditelan.
 *
 * BULAN DI SINI = BULAN PENCATATAN PIUTANG
 * ----------------------------------------
 *
 * Tagihan periode M (mis. Mei) baru masuk akun Piutang Usaha saat
 * generate dijalankan -- dan generate hanya jalan pada tanggal yang diset
 * di SOP, satu hari dalam bulan. Tagihan Mei umumnya baru menjadi piutang
 * pada generate bulan Juni.
 *
 * Jadi filter bulan memakai bulan saat jurnal piutang dibuat, bukan bulan
 * periode tagihan. Kolom "Periode Tagihan" di tabel tetap menampilkan
 * periode aslinya (Mei), jadi orang tetap tahu tagihan bulan mana yang
 * sedang ditagih.
 *
 * Tagihan menunggak yang BELUM punya jurnal tidak masuk bulan mana pun --
 * ia belum masuk periode piutang manapun. Baris seperti itu tetap
 * ditampilkan dengan status "Menunggu Generate", karena disembunyikan
 * akan membuat orang mengira tidak ada tunggakan.
 *
 * @param {Object} [opts]
 * @param {import('vue').Ref<number|null>} [opts.bulan] Bulan awal yang difilter
 * @param {import('vue').Ref<number|null>} [opts.tahun] Tahun awal yang difilter
 */
export function useArsipPiutang({ bulan = null, tahun = null } = {}) {
  const searchQuery = ref('')
  const currentPage = ref(1)
  const perPage = ref(10)
  const loading = ref(false)
  const itemsList = ref([])
  const serverTotal = ref(0)
  const totalPages = ref(1)
  const errorMessage = ref(null)
  const ringkasan = ref({
    periode: null,
    keseluruhan: null,
    generate_terakhir: null,
    generate_berikutnya: null,
  })

  /*
   * Nomor urut request.
   *
   * Balasan request lama bisa tiba SETELAH balasan request baru. Kalau
   * tidak dijaga, state akhirnya salah: label "Showing 11 to 20" padahal
   * barisnya 10 dari request sebelumnya.
   */
  let requestId = 0
  let latestRequestId = 0

  const fetchData = async () => {
    const myId = ++requestId
    latestRequestId = myId

    try {
      loading.value = true
      errorMessage.value = null

      const response = await piutangService.getArsip({
        page: currentPage.value,
        per_page: perPage.value,
        search: searchQuery.value || undefined,
        month: bulan?.value ?? undefined,
        year: tahun?.value ?? undefined,
      })

      // Balasan usang: request yang lebih baru sudah dimulai. Abaikan.
      if (myId !== latestRequestId) return

      if (response?.success) {
        itemsList.value = Array.isArray(response.data) ? response.data : []
        serverTotal.value = response.meta?.total ?? itemsList.value.length
        totalPages.value = Math.max(1, response.meta?.last_page ?? 1)
        ringkasan.value = {
          periode: response.meta?.periode ?? null,
          keseluruhan: response.meta?.keseluruhan ?? null,
          generate_terakhir: response.meta?.generate_terakhir ?? null,
          generate_berikutnya: response.meta?.generate_berikutnya ?? null,
        }
      } else {
        itemsList.value = []
        serverTotal.value = 0
        totalPages.value = 1
        errorMessage.value = response?.message || 'Gagal memuat data arsip piutang.'
      }
    } catch (err) {
      if (myId !== latestRequestId) return

      itemsList.value = []
      serverTotal.value = 0
      totalPages.value = 1
      errorMessage.value =
        err?.response?.data?.message || err?.message || 'Gagal memuat data arsip piutang.'
    } finally {
      // Hanya request terbaru boleh menurunkan spinner.
      if (myId === latestRequestId) {
        loading.value = false
      }
    }
  }

  /**
   * Kembalikan ke halaman 1 lalu muat ulang.
   *
   * Dipakai setiap kali filter berubah: hasil di halaman 3 untuk filter
   * lama tidak berlaku untuk filter baru.
   */
  const backToFirstPage = () => {
    if (currentPage.value === 1) {
      fetchData()
    } else {
      currentPage.value = 1
    }
  }

  /*
   * `per_page` DAN `currentPage` dipantau BERSAMA, dan perbedaannya
   * dijaga di dalam watcher: saat `per_page` berubah, `currentPage`
   * disetel ke 1 DI DALAM watcher itu sendiri, sebelum fetch dijalankan.
   *
   * Kalau disetel dari luar (`watch(perPage, () => currentPage.value = 1)`),
   * Vue menjadwalkan watcher `currentPage` sebagai pekerjaan terpisah --
   * sehingga request dengan `page` lama tetap terkirim.
   */
  watch([currentPage, perPage], (newVal, oldVal) => {
    const perPageChanged = oldVal && newVal[1] !== oldVal[1]

    if (perPageChanged && currentPage.value !== 1) {
      currentPage.value = 1
      return
    }

    fetchData()
  })

  /*
   * Search di-debounce 300ms supaya mengetik tidak menembak request di
   * setiap ketukan.
   */
  let searchTimer = null
  watch(searchQuery, () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(backToFirstPage, 300)
  })

  // Filter periode: karena sudah reaktif (ref), berubahnya memicu fetch.
  if (bulan) {
    watch(bulan, backToFirstPage)
  }
  if (tahun) {
    watch(tahun, backToFirstPage)
  }

  onMounted(fetchData)

  onBeforeUnmount(() => {
    clearTimeout(searchTimer)
  })

  const periodeTerpilih = computed(() => ({
    bulan: bulan?.value ?? null,
    tahun: tahun?.value ?? null,
  }))

  return {
    searchQuery,
    currentPage,
    perPage,
    loading,
    itemsList,
    serverTotal,
    totalPages,
    errorMessage,
    ringkasan,
    periodeTerpilih,
    fetchData,
  }
}
