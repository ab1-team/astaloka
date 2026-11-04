import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Role yang tampilannya mengikuti desain pamsides-v2, bukan branding lokasi.
 *
 * Kenapa daftarnya pendek: hanya dua role yang memang punya desain sendiri.
 * Admin, pelanggan, dan panel pusat memakai warna lokasi, dan warna itu
 * harus tetap begitu --see `utils/applyTheme.js`.
 *
 * Daftar ini dibaca `assets/css/role-palette.css` lewat atribut
 * `html[data-role]`. Atributnya dipasang di `<html>`, bukan di `<body>`,
 * karena beberapa modal dirender dengan `<Teleport to="body">` dan
 * class di dalam aplikasi tidak ikut ke sana.
 */
const ROLE_PALET_PAMSIDES = ['teknisi', 'surveyor']

/**
 * Tulis atribut `data-role` di elemen `<html>`.
 *
 * Dipanggil setiap kali role berubah. Role kosong (setelah logout) MENGHAPUS
 * atributnya -- bukan menulis string kosong -- supaya halaman login kembali
 * ke branding lokasi, dan selector `html[data-role='']` tidak pernah cocok.
 *
 * @param {string} role
 */
function terapkanAtributRole(role) {
  if (typeof document === 'undefined') return

  const root = document.documentElement

  if (ROLE_PALET_PAMSIDES.includes(role)) {
    root.dataset.role = role
  } else {
    delete root.dataset.role
  }
}

/**
 * UI Store - Mengelola state UI global seperti loading dan notifikasi
 */
export const useUiStore = defineStore('ui', () => {
  // State
  const loading = ref(false)
  const activeRequests = ref(0)
  // Default TIDAK boleh 'admin'. Nilai kosong lebih aman: role yang belum
  // dikenal akan ditolak router guard (fail closed). Dengan default 'admin',
  // storage yang kosong atau terhapus sempat menampilkan menu & dashboard
  // admin sebelum guard yang menyusun ulang redirect.
  const userRole = ref(localStorage.getItem('user_role') || '')
  const userData = ref(JSON.parse(localStorage.getItem('user_data')) || null)
  const lembagaName = ref('')
  const settingsVersion = ref(0)

  /*
  | Atribut `data-role` dipasang SEKALI di sini, bukan hanya di
  | `setUserRole()`.
  |
  | Alasannya: `setUserRole()` hanya jalan kalau ada yang memanggilnya --
  | yaitu saat login dan saat logout. TapiMUAT ULANG halaman (F5) tidak
  | memanggil keduanya. Tanpa pemasangan di sini, palet pamsides-v2 untuk
  | teknisi/surveyor baru berlaku SETELAH user login ulang -- padahal sesi
  | yang sudah ada harus langsung tampil benar.
  |
  | Nilainya dibaca dari `localStorage`, jadi sama persis dengan role yang
  | dipakai router guard.
  */
  terapkanAtributRole(userRole.value)

  // Modal State
  const activeModalCount = ref(0)

  // Toast State
  const toastMessage = ref(null)

  const openModal = () => activeModalCount.value++
  const closeModal = () => (activeModalCount.value = Math.max(0, activeModalCount.value - 1))
  const hasActiveModal = () => activeModalCount.value > 0

  // Actions
  const setLoading = (status) => {
    if (status) {
      activeRequests.value++
      loading.value = true
    } else {
      activeRequests.value = Math.max(0, activeRequests.value - 1)
      if (activeRequests.value === 0) {
        loading.value = false
      }
    }
  }

  const showToast = (severity, summary, detail, life = 3000) => {
    toastMessage.value = { severity, summary, detail, life, timestamp: Date.now() }
  }

  const success = (detail, summary = 'Berhasil') => showToast('success', summary, detail)
  const error = (detail, summary = 'Kesalahan') => showToast('error', summary, detail)
  const info = (detail, summary = 'Informasi') => showToast('info', summary, detail)
  const warn = (detail, summary = 'Peringatan') => showToast('warn', summary, detail)

  const setUserRole = (role) => {
    userRole.value = role
    localStorage.setItem('user_role', role)
    // Atribut `data-role` ikut diperbarui, supaya palet per-role langsung
    // berlaku tanpa perlu reload.
    terapkanAtributRole(role)
  }

  const setUserData = (data) => {
    userData.value = data
    localStorage.setItem('user_data', JSON.stringify(data))
  }

  const setLembagaName = (name) => {
    lembagaName.value = name || ''
  }

  const bumpSettings = () => {
    settingsVersion.value++
  }

  return {
    loading,
    toastMessage,
    setLoading,
    showToast,
    success,
    error,
    info,
    warn,
    userRole,
    setUserRole,
    userData,
    setUserData,
    lembagaName,
    setLembagaName,
    settingsVersion,
    bumpSettings,
    openModal,
    closeModal,
    hasActiveModal,
    activeModalCount,
  }
})
