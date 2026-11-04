import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import ticketService from '@/services/ticket.service'
import { INSTALASI_STATUS_COLORS, INSTALASI_MENU_LIST } from '@/types/instalasiStatus'
import { getCategoryByStatus, groupTicketsByCategory } from '@/utils/instalasiStatus'

const ALLOWED_FILTERS = ['permohonan', 'pasang_baru', 'aktif', 'blokir', 'cabut']

export const useInstalasiStore = defineStore('instalasi', () => {
  const route = useRoute()
  const initialFilter = (() => {
    const q = route?.query?.filter
    return ALLOWED_FILTERS.includes(q) ? q : 'permohonan'
  })()

  const activeStatus = ref(initialFilter)
  const currentPage = ref(1)
  const perPage = ref(10)
  const searchQuery = ref('')
  const isLoading = ref(false)

  const dataMap = ref({
    permohonan: [],
    pasang_baru: [],
    aktif: [],
    blokir: [],
    cabut: [],
  })

  const menuList = INSTALASI_MENU_LIST
  const statusStyle = INSTALASI_STATUS_COLORS

  const fetchError = ref(null)

  const fetchData = async () => {
    try {
      isLoading.value = true
      fetchError.value = null
      const response = await ticketService.getTickets({ all: 1 })

      if (!response?.success) {
        const msg = response?.message || 'Gagal memuat data tiket.'
        fetchError.value = msg
        throw new Error(msg)
      }

      const tickets = Array.isArray(response?.data) ? response.data : response?.data?.data || []

      if (Array.isArray(tickets)) {
        dataMap.value = groupTicketsByCategory(tickets)
      } else {
        fetchError.value = 'Format data tiket tidak dikenali.'
      }
    } catch (error) {
      fetchError.value = error?.message || 'Gagal memuat data status instalasi.'
    } finally {
      isLoading.value = false
    }
  }

  const getCategoryByStatusForRow = (rawStatus) => getCategoryByStatus(rawStatus)

  return {
    activeStatus,
    currentPage,
    perPage,
    searchQuery,
    isLoading,
    fetchError,
    dataMap,
    menuList,
    statusStyle,
    fetchData,
    getCategoryByStatus: getCategoryByStatusForRow,
  }
})
