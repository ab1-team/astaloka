import { defineStore } from 'pinia'
import { ref } from 'vue'
import sopService from '@/services/sop.service.js'

/**
 * Settings Store - Mengelola pengaturan SOP/setting global yang
 * dibutuhkan lintas komponen (mis. BillingForm), sehingga tidak
 * setiap komponen harus memanggil API sendiri.
 *
 * DUA kolom SOP, DUA arti:
 *
 * - toleransiTunggakan: TANGGAL (1-31) akhir bayar / jatuh tempo.
 *   Jatuh tempo dihitung pada BULAN PENCATATAN meter: kalau pencatatan
 *   lewat tanggal ini, jatuh tempo bergeser ke bulan berikutnya.
 * - batasTagihan: JUMLAH BULAN tunggakan yang boleh menumpuk sebelum
 *   sambungan pelanggan diputus (0 = tidak pernah putus otomatis).
 *
 * Tidak ada kolom ketiga. Nilai bawaan di sini hanya dipakai kalau
 * backend belum sempat dimuat; setelah `loadSettings()` berhasil,
 * nilainya selalu mengikuti SOP.
 */
export const useSettingsStore = defineStore('settings', () => {
  // State - Sistem Tagihan
  const toleransiTunggakan = ref(10)
  const batasTagihan = ref(3)

  /*
   * Kamera scan pada popup "Input Pemakaian".
   *
   * Default `false`: kalau request gagal, popup tetap bisa diisi manual.
   */
  const autoOpenMeterCamera = ref(false)

  // State - lembaga (siapa tahu nanti butuh di komponen lain)
  const lembagaName = ref('')

  // Loading flag
  const isLoading = ref(false)
  const isLoaded = ref(false)

  /*
   * Penanda terpisah untuk pengaturan pemakaian air.
   *
   * `isLoaded` menandai `getAll()` yang `role:admin` -- jadi kalau
   * pengaturan pemakaian air ikut memakai penanda yang sama, teknisi yang
   * pernah memanggil `loadSettings()` (dan gagal dengan 403) tidak akan
   * pernah mencoba memanggil `loadPemakaianAir()`.
   */
  const isPemakaianAirLoaded = ref(false)

  /**
   * Ambil semua setting dari backend (/settings/sop) dan simpan ke store.
   * Aman dipanggil berulang - hanya fetch kalau belum pernah di-load,
   * kecuali `force = true`.
   */
  const loadSettings = async (force = false) => {
    if (isLoaded.value && !force) return
    try {
      isLoading.value = true
      const res = await sopService.getAll()
      const data = res?.data?.data ?? res?.data ?? res
      if (!data) return

      if (data.sistemTagihan) {
        toleransiTunggakan.value = Number(data.sistemTagihan.toleransiTunggakan ?? 10)
        batasTagihan.value = Number(data.sistemTagihan.batasTagihan ?? 3)
      }
      if (data.lembaga?.nama) {
        lembagaName.value = data.lembaga.nama
      }
      if (data.pemakaianAir) {
        autoOpenMeterCamera.value = Boolean(data.pemakaianAir.autoOpenMeterCamera)
      }

      isLoaded.value = true
    } catch (err) {
      // Jangan lempar error agar komponen lain tidak crash.
      // Biarkan nilai default (toleransiTunggakan=10, batasTagihan=3).
      console.error('[settingsStore] loadSettings error:', err)
    } finally {
      isLoading.value = false
    }
  }

  /**
   * Ambil pengaturan pencatatan meteran saja.
   *
   * Endpoint-nya (`/settings/pemakaian-air`) terbuka untuk semua role yang
   * sudah login, sementara `getAll()` (`/settings/sop`) itu `role:admin`.
   *
   * Itu penting karena popup input meter dipakai TEKNISI juga. Kalau popup
   * ini bergantung pada `getAll()`, teknisi selalu kena 403 dan pengaturannya
   * tidak pernah masuk -- gejalanya bukan "403", melainkan kamera yang
   * diam-diam tidak pernah dibuka.
   */
  const loadPemakaianAir = async (force = false) => {
    if (isPemakaianAirLoaded.value && !force) return
    try {
      const res = await sopService.getPemakaianAir()
      const data = res?.data?.data ?? res?.data ?? res
      if (data) {
        autoOpenMeterCamera.value = Boolean(data.autoOpenMeterCamera)
      }
      isPemakaianAirLoaded.value = true
    } catch (err) {
      // Nilai bawaan (`false`) dipakai: kamera tidak dibuka otomatis.
      // Itu perilaku yang paling aman -- popup tetap bisa diisi manual.
      console.error('[settingsStore] loadPemakaianAir error:', err)
    }
  }

  /**
   * Update tanggal toleransi secara lokal (mis. setelah save dari halaman
   * SOP) tanpa harus refetch semua data.
   */
  const setToleransiTunggakan = (val) => {
    toleransiTunggakan.value = Number(val ?? 10)
  }

  /**
   * Update batas tagihan (JUMLAH BULAN) secara lokal.
   */
  const setBatasTagihan = (val) => {
    batasTagihan.value = Number(val ?? 3)
  }

  /**
   * Alias lama. Tidak menambah kolom; hanya memanggil setter yang sama.
   */
  const setBatasBulanTunggakan = (val) => {
    setBatasTagihan(val)
  }

  /**
   * Update flag kamera otomatis secara lokal.
   *
   * Dipanggil setelah admin menyimpan pengaturan dari halaman SOP, supaya
   * popup input meter yang sudah terbuka ikut memakai nilai terbaru tanpa
   * perlu memuat ulang halaman.
   */
  const setAutoOpenMeterCamera = (val) => {
    autoOpenMeterCamera.value = Boolean(val)
  }

  const reset = () => {
    toleransiTunggakan.value = 10
    batasTagihan.value = 3
    autoOpenMeterCamera.value = false
    lembagaName.value = ''
    isLoaded.value = false
    isPemakaianAirLoaded.value = false
  }

  return {
    // state
    toleransiTunggakan,
    batasTagihan,
    autoOpenMeterCamera,
    lembagaName,
    isLoading,
    isLoaded,
    // actions
    loadSettings,
    loadPemakaianAir,
    setToleransiTunggakan,
    setBatasTagihan,
    setBatasBulanTunggakan,
    setAutoOpenMeterCamera,
    reset,
  }
})
