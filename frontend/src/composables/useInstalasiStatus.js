import { computed, ref, watch } from 'vue'

import { useRoute, useRouter } from 'vue-router'

import { useInstalasiStore } from '@/stores/instalasiStore'
import { INSTALASI_MENU_LIST } from '@/types/instalasiStatus'
import ticketService from '@/services/ticket.service'
import { filterRowsBySearch } from '@/utils/instalasiStatus'
import { showErrorToast, showSuccessToast } from '@/utils/swal'

const ALLOWED_FILTERS = INSTALASI_MENU_LIST.map((m) => m.key)

/**
 * Path halaman cetak status instalasi.
 *
 * Sengaja DIPISAH dari `/app/instalasi/status` (path aslinya): halaman
 * cetak memakai kerangka preview sendiri -- tanpa sidebar, dengan toolbar
 * zoom dan thumbnail -- persis seperti halaman cetak modul lain
 * (`/app/pelaporan/preview`, `/usages/cetak_*`).
 */
export const CETAK_STATUS_PATH = '/app/instalasi/status/cetak'

/**
 * Ambil pesan error dari balasan blob.
 *
 * Request ekspor memakai `responseType: 'blob'`, jadi ketika backend
 * membalas JSON (mis. 422 kategori tidak dikenal, atau 500), body-nya
 * sampai di browser sebagai Blob -- bukan objek biasa. Tanpa dibaca ulang
 * sebagai teks, `error.response.data.message` selalu `undefined` dan user
 * hanya melihat pesan generik.
 */
const pesanDariBlob = async (data) => {
  if (!data) return ''
  if (typeof data === 'string') return data

  try {
    if (typeof data.text === 'function') {
      const teks = await data.text()
      const json = JSON.parse(teks)
      return json?.message || json?.error || teks
    }
  } catch {
    // Body blob bukan JSON (mis. halaman error HTML dari proxy).
    return 'Gagal mengekspor data. Silakan coba lagi.'
  }

  return ''
}

export function useInstalasiStatus() {
  const store = useInstalasiStore()
  const route = useRoute()
  const router = useRouter()

  // const fetchData = async () => {
  //   try {
  //     isLoading.value = true
  //     const response = await ticketService.getTickets({ per_page: 150 })
  //     if (response?.success && response?.data?.data) {
  //       const freshMap = {
  //         permohonan: [],
  //         pasang_baru: [],
  //         aktif: [],
  //         blokir: [],
  //         cabut: [],
  //       }

  //       response.data.data.forEach((ticket) => {
  //         const status = ticket.status
  //         let category = null
  //         let mappedStatusLabel = ''

  //         if (status === 'pending') {
  //           category = 'permohonan'
  //           mappedStatusLabel = 'Permohonan'
  //         } else if (['surveyed', 'unpaid', 'processing'].includes(status)) {
  //           category = 'pasang_baru'
  //           mappedStatusLabel = 'Pasang Baru'
  //         } else if (status === 'completed') {
  //           category = 'aktif'
  //           mappedStatusLabel = 'Aktif'
  //         } else if (status === 'suspended') {
  //           category = 'blokir'
  //           mappedStatusLabel = 'Blokir'
  //         } else if (status === 'terminated') {
  //           category = 'cabut'
  //           mappedStatusLabel = 'Cabut'
  //         }

  //         if (!category) return

  //         freshMap[category].push({
  //           id:
  //             ticket.customer?.[0]?.customer_code ||
  //             `#INS-${ticket.id.toString().padStart(4, '0')}`,
  //           ticketId: ticket.id,
  //           name: ticket.applicant_name || '-',
  //           nik: ticket.nik || '-',
  //           phone: ticket.phone || '-',
  //           initials: ticket.applicant_name
  //             ? ticket.applicant_name
  //                 .split(' ')
  //                 .map((n) => n[0])
  //                 .join('')
  //                 .toUpperCase()
  //                 .substring(0, 2)
  //             : '?',
  //           color: ['#3B82F6', '#8B5CF6', '#10B981', '#F59E0B', '#EF4444'][ticket.id % 5],
  //           type: ticket.package?.name || '-',
  //           packageId: ticket.package_id || null,
  //           address: ticket.address || '-',
  //           village: ticket.village?.name || '-',
  //           villageId: ticket.village_id || null,
  //           lat: ticket.lat || null,
  //           lng: ticket.lng || null,
  //           orderDate: ticket.order_date || '-',
  //           category: mappedStatusLabel,
  //           status: INSTALASI_RAW_STATUS_LABELS[status] || status,
  //           rawStatus: status,
  //           createdAt: ticket.created_at || '-',
  //           updatedAt: ticket.updated_at || '-',
  //           rawData: ticket,
  //           surveyInfo: ticket.survey?.[0]
  //             ? {
  //                 id: ticket.survey[0].id,
  //                 distance_to_pipe_m: ticket.survey[0].distance_to_pipe_m,
  //                 material_notes: ticket.survey[0].material_notes,
  //                 photo_url: ticket.survey[0].photo_url,
  //                 surveyed_at: ticket.survey[0].surveyed_at,
  //                 surveyor_name: ticket.survey[0].surveyor?.name || '-',
  //                 surveyor_id: ticket.survey[0].surveyor_id,
  //                 ticket: ticket,
  //               }
  //             : null,
  //         })
  //       })
  //       dataMap.value = freshMap
  //     }
  //   } catch (error) {
  //     console.error('Failed to fetch installation statuses:', error)
  //   } finally {
  //     isLoading.value = false
  //   }
  // }

  const activeLabel = computed(() => {
    return store.menuList.find((m) => m.key === store.activeStatus)?.label || ''
  })

  const currentData = computed(() => store.dataMap[store.activeStatus] || [])

  /*
   * Baris yang tampil setelah pencarian.
   *
   * Memakai `filterRowsBySearch` dari `utils/instalasiStatus.js` -- helper
   * yang sama dengan yang dipakai backend saat ekspor Excel. Kalau
   * aturannya berbeda, hasil ekspor akan berisi baris yang tidak terlihat
   * di layar (atau sebaliknya), dan user mengira salah satu dari keduanya.
   */
  const filteredData = computed(() => filterRowsBySearch(currentData.value, store.searchQuery))

  const totalPages = computed(() =>
    Math.max(1, Math.ceil(filteredData.value.length / store.perPage)),
  )

  const visiblePages = computed(() => {
    const pages = []
    for (let i = 1; i <= totalPages.value; i++) pages.push(i)
    return pages
  })

  const paginatedData = computed(() => {
    const start = (store.currentPage - 1) * store.perPage
    return filteredData.value.slice(start, start + store.perPage)
  })

  const prevPage = () => {
    if (store.currentPage > 1) store.currentPage--
  }
  const nextPage = () => {
    if (store.currentPage < totalPages.value) store.currentPage++
  }
  const goToPage = (page) => {
    store.currentPage = page
  }

  watch([() => store.activeStatus, () => store.searchQuery], () => {
    store.currentPage = 1
  })

  watch(
    () => store.activeStatus,
    (val) => {
      if (route.path === '/app/instalasi/status' && route.query.filter !== val) {
        router.replace({ path: '/app/instalasi/status', query: { filter: val } })
      }
    },
  )

  watch(
    () => route.query.filter,
    (val) => {
      if (ALLOWED_FILTERS.includes(val) && store.activeStatus !== val) {
        store.activeStatus = val
      }
    },
  )

  /*
   * Status request ekspor -- dipakai tombol "Export Excel" untuk
   * menampilkan spinner di tombolnya.
   */
  const isExporting = ref(false)

  /**
   * Nama file hasil ekspor.
   *
   * Dipakai kalau server tidak mengirim header `Content-Disposition`
   * (mis. lewat proxy yang membuang header). Formatnya sama dengan yang
   * dibuat backend, dan selalu ASCII supaya aman di semua sistem file.
   */
  const namaFileEkspor = () => {
    const label = activeLabel.value || 'Semua'
    const slug = label
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')

    return `Status_Instalasi_${slug || 'semua'}_${new Date().toISOString().slice(0, 10)}.xlsx`
  }

  /**
   * Export data status instalasi ke file Excel.
   *
   * Filenya dibuat DI BACKEND (`GET /installation-tickets/export-status`)
   * memakai PhpSpreadsheet, jadi hasilnya file `.xlsx` sungguhan dengan
   * kop surat, border, dan pengaturan cetak -- bukan CSV yang disamarkan
   * dengan ekstensi `.xlsx`.
   *
   * Parameter yang dikirim PERSIS sama dengan yang sedang aktif di
   * layar: kategori tab dan kata kunci pencarian. Backend memetakan
   * kategori ke status mentah dan menyaring dengan tiga field yang sama
   * (nama, kode instalasi, alamat), jadi "yang terlihat di layar" =
   * "yang keluar di file".
   */
  const exportData = async () => {
    if (isExporting.value) return

    isExporting.value = true

    try {
      const params = { kategori: store.activeStatus }
      const pencarian = (store.searchQuery || '').trim()

      if (pencarian !== '') {
        params.search = pencarian
      }

      const blob = await ticketService.exportStatusExcel(params)

      if (!blob) {
        throw new Error('File ekspor kosong.')
      }

      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')

      link.href = url
      link.download = namaFileEkspor()
      document.body.appendChild(link)
      link.click()
      link.remove()

      // Object URL disimpan di memori browser sampai di-revoke. Kalau
      // tidak, tiap ekspor meninggalkan salinan file yang tidak pernah
      // dibebaskan -- dan mengunduh berkali-kali bisa membuat tab berat.
      setTimeout(() => URL.revokeObjectURL(url), 60_000)

      showSuccessToast('File Excel berhasil diunduh.')
    } catch (error) {
      const pesan = await pesanDariBlob(error?.response?.data)

      showErrorToast({
        message: pesan || error?.message || 'Gagal mengekspor data status instalasi.',
      })
    } finally {
      isExporting.value = false
    }
  }

  /**
   * Buka halaman cetak status instalasi di tab baru.
   *
   * Halaman cetaknya menerima `filter` (kategori tab) dan `q` (kata kunci
   * pencarian) lewat query string, lalu memuat datanya sendiri. Jadi
   * cetakan selalu berisi SELURUH baris yang lolos filter -- bukan hanya
   * halaman tabel yang sedang terlihat.
   */
  const printData = () => {
    const url = router.resolve({
      path: CETAK_STATUS_PATH,
      query: {
        filter: store.activeStatus,
        q: store.searchQuery || '',
      },
    }).href

    const newTab = window.open(url, '_blank')

    if (!newTab) {
      showErrorToast({
        message: 'Popup diblokir browser. Izinkan popup untuk membuka halaman cetak.',
      })
      return
    }

    newTab.focus?.()
  }

  return {
    activeStatus: computed({
      get: () => store.activeStatus,
      set: (v) => {
        store.activeStatus = v
      },
    }),
    activeLabel,
    currentPage: computed({
      get: () => store.currentPage,
      set: (v) => {
        store.currentPage = v
      },
    }),
    perPage: computed({
      get: () => store.perPage,
      set: (v) => {
        store.perPage = v
      },
    }),
    searchQuery: computed({
      get: () => store.searchQuery,
      set: (v) => {
        store.searchQuery = v
      },
    }),
    menuList: store.menuList,
    dataMap: computed(() => store.dataMap),
    filteredData,
    totalPages,
    visiblePages,
    paginatedData,
    statusStyle: store.statusStyle,
    prevPage,
    nextPage,
    goToPage,
    exportData,
    printData,
    isExporting,
    fetchData: store.fetchData,
    fetchError: computed(() => store.fetchError),
    isLoading: computed(() => store.isLoading),
    getCategoryByStatus: store.getCategoryByStatus,
  }
}
