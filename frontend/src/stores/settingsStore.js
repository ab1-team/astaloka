import { defineStore } from 'pinia'
import { ref } from 'vue'
import sopService from '@/services/sop.service.js'

/**
 * Settings Store - Mengelola pengaturan SOP/setting global yang
 * dibutuhkan lintas komponen (mis. BillingForm), sehingga tidak
 * setiap komponen harus memanggil API sendiri.
 *
 * Saat ini menyimpan data Sistem Tagihan:
 * - toleransiTunggakan: TANGGAL untuk semua aturan berbasis tanggal
 *   (jatuh tempo tagihan, generate piutang, advance payment) -- default 10.
 *   Ambang pengakuan piutang TIDAK memakai angka ini: piutang diakui saat
 *   tanggal jatuh tempo sudah tercapai.
 * - batasTagihan: SALINAN dari `toleransiTunggakan` dengan nilai yang
 *   sama. Ada karena dua komponen masih membaca nama lama ini:
 *   `ReportCetakDaftarTagihan.vue` (fallback "Tgl Akhir Pembayaran")
 *   dan `BillingForm.vue` (pratinjau advance payment). Keduanya harus
 *   melihat angka yang sama dengan yang dipakai backend.
 * - batasBulanTunggakan: JUMLAH BULAN tunggakan yang boleh ditoleransi
 *   sebelum sambungan pelanggan diputus (default 3)
 *
 * Dan pengaturan pencatatan meteran:
 * - autoOpenMeterCamera: kamera scan langsung terbuka saat popup input
 *   meter dibuka? (default false -- yang wajib diisi cuma meter akhir)
 */
export const useSettingsStore = defineStore('settings', () => {
  // State - Sistem Tagihan
  const batasTagihan = ref(10)
  const toleransiTunggakan = ref(10)
  const batasBulanTunggakan = ref(3)

  /*
   * Kamera scan pada popup "Input Pemakaian".
   *
   * Default `false` dengan alasan yang sama seperti `batasTagihan` di atas:
   * kalau request gagal, komponen harus tetap punya perilaku yang bisa
   * dipakai -- bukan menghilang jadi tidak ada tombol sama sekali.
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
        /*
         * SATU angka tanggal. Backend mengirim `toleransiTunggakan` dan
         * `batasTagihan` dengan nilai yang sama; yang dipakai di sini
         * adalah `toleransiTunggakan`, dengan `batasTagihan` sebagai
         * fallback kalau backend versi lama tidak mengirimkannya.
         */
        const tanggal = Number(
          data.sistemTagihan.toleransiTunggakan ?? data.sistemTagihan.batasTagihan ?? 10,
        )

        toleransiTunggakan.value = tanggal
        batasTagihan.value = tanggal
        batasBulanTunggakan.value = Number(data.sistemTagihan.batasBulanTunggakan ?? 3)
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
      // Biarkan nilai default (batasTagihan=10, toleransiTunggakan=10).
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
   * Update nilai toleransiTunggakan secara lokal (mis. setelah save dari
   * halaman SOP) tanpa harus refetch semua data.
   *
   * `batasTagihan` ikut diperbarui dengan nilai yang sama -- keduanya
   * sekarang cuma salinan dari satu angka tanggal.
   */
  const setToleransiTunggakan = (val) => {
    const angka = Number(val ?? 10)

    toleransiTunggakan.value = angka
    batasTagihan.value = angka
  }

  const setBatasTagihan = (val) => {
    setToleransiTunggakan(val)
  }

  /**
   * Update batas tunggakan (jumlah bulan) secara lokal.
   */
  const setBatasBulanTunggakan = (val) => {
    batasBulanTunggakan.value = Number(val ?? 3)
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
    batasTagihan.value = 10
    toleransiTunggakan.value = 10
    batasBulanTunggakan.value = 3
    autoOpenMeterCamera.value = false
    lembagaName.value = ''
    isLoaded.value = false
    isPemakaianAirLoaded.value = false
  }

  return {
    // state
    batasTagihan,
    toleransiTunggakan,
    batasBulanTunggakan,
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
