import { ref, computed, onMounted } from 'vue'
import { showSuccessToast, showErrorToast } from '@/utils/swal'
import { storageLogoUrl } from '@/utils/storage'
import sopService from '@/services/sop.service'
import { refreshIdentitas } from '@/composables/useIdentitasLembaga'
import { useUiStore } from '@/stores/uiStore'
import { useSettingsStore } from '@/stores/settingsStore.js'

export function useSop() {
  const activeSection = ref('wellcome')
  const isSaving = ref(false)
  const isLoading = ref(false)
  const uiStore = useUiStore()
  const settingsStore = useSettingsStore()

  const menuList = [
    { key: 'wellcome', label: 'Selamat Datang', icon: 'home' },
    { key: 'lembaga', label: 'Profil Lembaga', icon: 'building' },
    { key: 'pasangBaru', label: 'Pasang Baru', icon: 'user-plus' },
    { key: 'sistemTagihan', label: 'Sistem Tagihan', icon: 'file-invoice-dollar' },
    { key: 'pemakaianAir', label: 'Pemakaian', icon: 'tachometer-alt' },
    { key: 'calk', label: 'Pengaturan CALK', icon: 'book-open' },
    { key: 'logo', label: 'Logo & Branding', icon: 'image' },
    { key: 'whatsapp', label: 'Whatsapp API', icon: ['fab', 'whatsapp'] },
    { key: 'signature', label: 'Tanda Tangan', icon: 'signature' },
  ]

  const activeLabel = computed(() => {
    const m = menuList.find((x) => x.key === activeSection.value)
    return m ? m.label : ''
  })

  const lembagaForm = ref({
    nama: '',
    alamat: '',
    email: '',
    telepon: '',
    domain: '',
    peraturan_desa: '',
    sk_kemenkumham: '',
  })

  const pasangBaruForm = ref({
    statusPembayaran: false,
  })

  /*
   * Pengaturan pencatatan meteran.
   *
   * `autoOpenMeterCamera` menentukan apakah kamera scan langsung terbuka
   * begitu popup "Input Pemakaian" muncul.
   *
   * Bawaannya `false` (tidak dibuka otomatis), dan itu bukan sekadar
   * "-- yang wajib diisi cuma meter akhir". Popup ini dipakai teknisi yang
   * sering sudah mencatat angka meteran secara manual; memaksa kamera
   * menyala tiap popup hanya menambah waktu, bukan data.
   *
   * Bedanya dengan setting lain di halaman ini: `false` di sini berarti
   * "kamera TIDAK dibuka otomatis", bukan "fiturnya dimatikan". Tombol scan
   * tetap bisa dipakai kapan saja.
   */
  const pemakaianAirForm = ref({
    autoOpenMeterCamera: false,
  })

  /*
   * Pengaturan tagihan.
   *
   * DUA angka di sini, dan masing-masing punya satuan yang BERBEDA:
   *
   *   - `toleransiTunggakan`  : TANGGAL (1-31) untuk semua aturan berbasis
   *                             tanggal -- jatuh tempo tagihan bulanan,
   *                             generate piutang tiap bulan, ambang piutang,
   *                             dan deteksi advance payment.
   *   - `batasBulanTunggakan` : JUMLAH BULAN tunggakan yang boleh ditoleransi
   *                             sebelum sambungan pelanggan diputus.
   *
   * Sebelumnya ada TIGA input: `batasTagihan` (tanggal jatuh tempo) dan
   * `toleransiTunggakan` (tanggal generate piutang) berdampingan. Keduanya
   * TANGGAL dalam bulan dengan satuan yang sama, jadi hanya bedanya nama --
   * dan dua input untuk satu angka membuat admin mengira ada dua aturan
   * yang harus diselaraskan sendiri, padahal di database keduanya bisa
   * berisi angka berbeda (27 vs 30 di lokasi demo).
   *
   * Sekarang keduanya digabung jadi satu input "Toleransi Menunggak".
   *
   * `batasBulanTunggakan` disimpan di kolom `settings.billing_unpaid_limit`,
   * yang memang sudah tersedia di database (nullable, tanpa default).
   */
  const sistemTagihanForm = ref({
    toleransiTunggakan: 10,
    batasBulanTunggakan: 3,
  })

  // ---- CALK ----
  // Form konfigurasi CALK (persentase bagian, laba ditahan, dan Point A kustom).
  // Struktur payload mengikuti sidbm (SopController::_calk.blade.php).
  const calkForm = ref({
    peraturan_desa: '',
    bantuan_rumah_tangga: 0,
    pengembangan_kapasitas: 0,
    pelatihan_masyarakat: 0,
    peningkatan_modal: 0,
    penambahan_investasi: 0,
    pendirian_unit_usaha: 0,
    point_a: '',
  })

  const logoForm = ref({
    file: null,
    preview: '',
    previewName: '',
  })

  const whatsappForm = ref({
    templateTagihan: '',
    templatePembayaran: '',
  })

  const wellcomeForm = ref({})

  const loadSettings = async () => {
    try {
      isLoading.value = true
      const res = await sopService.getAll()
      const data = res?.data?.data ?? res?.data ?? res
      if (!data) return

      if (data.lembaga) lembagaForm.value = { ...lembagaForm.value, ...data.lembaga }
      if (data.pasangBaru) pasangBaruForm.value = { ...pasangBaruForm.value, ...data.pasangBaru }

      /*
       * Pengaturan tagihan.
       *
       * Dipetakan eksplisit ke angka, bukan di-spread apa adanya: backend
       * mengirim nilai yang sudah di-cast jadi integer, tapi `null` bisa
       * muncul kalau baris `settings` belum ada sama sekali, dan `null` di
       * dalam `<input type="number">` tampil sebagai string kosong yang
       * tidak bisa di-submit sebagai angka.
       *
       * `toleransiTunggakan` adalah SATU angka tanggal. Backend mengirimnya
       * juga di field `batasTagihan` (nilainya sama) supaya konsumen lama
       * yang masih membaca `batasTagihan` -- `ReportCetakDaftarTagihan.vue`
       * dan `BillingForm.vue` -- tidak perlu diubah. Yang dipakai form ini
       * tetap `toleransiTunggakan`, karena itu nama yang tampil di
       * halaman.
       *
       * `batasBulanTunggakan` punya bawaan 3, sama dengan default backend
       * (`BillingTolerance::unpaidBillLimit()`), jadi kolom kosong di
       * database tidak pernah tampil sebagai angka 0 yang berarti "tidak
       * pernah memutus sambungan".
       */
      if (data.sistemTagihan) {
        sistemTagihanForm.value = {
          toleransiTunggakan: Number(
            data.sistemTagihan.toleransiTunggakan ?? data.sistemTagihan.batasTagihan ?? 10,
          ),
          batasBulanTunggakan: Number(data.sistemTagihan.batasBulanTunggakan ?? 3),
        }
      }

      if (data.whatsapp) whatsappForm.value = { ...whatsappForm.value, ...data.whatsapp }

      /*
       * Pengaturan pemakaian.
       *
       * Backend mengirim `null` kalau kolomnya belum pernah diisi (lihat
       * `SopController::index()`). `null` dipetakan ke `false` di sini --
       * artinya "tidak dibuka otomatis" -- karena itu satu-satunya bawaan
       * yang aman: membiarkan `null` lolos ke checkbox akan membuat form
       * tampil dalam keadaan yang tidak pernah disetujui admin.
       */
      if (data.pemakaianAir) {
        pemakaianAirForm.value = {
          autoOpenMeterCamera: Boolean(data.pemakaianAir.autoOpenMeterCamera),
        }
      }

      // CALK config (persentase bagian + laba ditahan + point_a)
      if (data.calk) {
        const ck = data.calk
        calkForm.value = {
          peraturan_desa: ck.peraturan_desa ?? '',
          bantuan_rumah_tangga: Number(ck.D?.['1']?.d?.['1'] ?? 0),
          pengembangan_kapasitas: Number(ck.D?.['1']?.d?.['2'] ?? 0),
          pelatihan_masyarakat: Number(ck.D?.['1']?.d?.['3'] ?? 0),
          peningkatan_modal: Number(ck.D?.['2']?.a ?? 0),
          penambahan_investasi: Number(ck.D?.['2']?.b ?? 0),
          pendirian_unit_usaha: Number(ck.D?.['2']?.c ?? 0),
          point_a: ck.point_a ?? '',
        }
      }

      if (data.logo) {
        logoForm.value.previews = {
          mainLogo: data.logo.mainLogo_url || data.logo.mainLogo || '',
          dashboardLogo: data.logo.dashboardLogo_url || data.logo.dashboardLogo || '',
          favicon: data.logo.favicon_url || data.logo.favicon || '',
        }
      }

      if (data.whatsapp) {
        whatsappForm.value = {
          templateTagihan: data.whatsapp.templateTagihan ?? '',
          templatePembayaran: data.whatsapp.templatePembayaran ?? '',
        }
      }

      if (data.logo) {
        const fileName = data.logo.logo || ''
        logoForm.value.preview = fileName ? storageLogoUrl(fileName) : ''
        logoForm.value.previewName = fileName
      }
    } catch (error) {
      showErrorToast(error)
    } finally {
      isLoading.value = false
    }
  }

  const saveLembaga = async () => {
    try {
      isSaving.value = true
      await sopService.saveLembaga({ ...lembagaForm.value })
      uiStore.bumpSettings()
      /*
       * Segarkan cache identitas.
       *
       * Satu refresh cukup untuk dua hal: nomor telepon yang dipakai
       * halaman pelanggan (Lapor Gangguan, dst) dan data kop surat/cetak.
       * Keduanya membaca cache yang sama -- memanggil `refreshKontak()`
       * dan `refreshIdentitas()` dua-duanya hanya menembak endpoint yang
       * sama dua kali.
       */
      refreshIdentitas()
      showSuccessToast('Profil Lembaga berhasil disimpan')
    } catch (error) {
      showErrorToast(error)
    } finally {
      isSaving.value = false
    }
  }

  const savePasangBaru = async () => {
    try {
      isSaving.value = true
      await sopService.savePasangBaru({ ...pasangBaruForm.value })
      showSuccessToast('Pengaturan Pasang Baru berhasil disimpan')
    } catch (error) {
      showErrorToast(error)
    } finally {
      isSaving.value = false
    }
  }

  /**
   * Simpan pengaturan sistem tagihan.
   *
   * Tanggal divalidasi DI SINI, sebelum request dikirim, karena
   * angka yang tidak valid tidak akan merusak data -- tapi ia akan
   * MATIkan generate tanpa pesan: `BillingTolerance::generateDay()`
   * mengembalikan 0 untuk angka di luar 1-31, yang dibaca sebagai "SOP
   * belum diisi". Jadi gejalanya "sudah saya set tanggalnya tapi kok
   * generate tidak jalan?" -- jauh lebih sulit ditelusuri daripada
   * pesan error yang muncul di detik yang sama.
   *
   * Backend juga memvalidasi rentangnya, jadi ini hanya lapisan pertama
   * yang memberi pesan dalam bahasa yang sama dengan sisa antarmuka.
   */
  const saveSistemTagihan = async () => {
    const toleransi = Number(sistemTagihanForm.value.toleransiTunggakan)

    if (!Number.isFinite(toleransi) || toleransi < 1 || toleransi > 31) {
      showErrorToast({
        message: 'Tanggal toleransi menunggak harus antara 1 dan 31.',
      })

      return
    }

    /*
     * Batas tunggakan (JUMLAH BULAN) punya rentang sendiri: 0-24.
     *
     * 0 itu sah -- artinya sambungan tidak boleh diputus otomatis sama
     * sekali -- jadi penolakannya hanya untuk angka negatif atau di atas
     * 24 bulan.
     */
    const batasBulan = Number(sistemTagihanForm.value.batasBulanTunggakan)

    if (!Number.isFinite(batasBulan) || batasBulan < 0 || batasBulan > 24) {
      showErrorToast({
        message: 'Batas tagihan bulanan harus antara 0 dan 24 bulan.',
      })

      return
    }

    try {
      isSaving.value = true
      await sopService.saveSistemTagihan({
        // Satu angka tanggal untuk semua aturan. `batasTagihan` ikut
        // dikirim dengan nilai yang sama supaya endpoint tetap kompatibel
        // dengan klien lama yang masih mengirim kedua field itu.
        toleransiTunggakan: toleransi,
        batasTagihan: toleransi,
        batasBulanTunggakan: batasBulan,
      })
      showSuccessToast('Sistem Tagihan berhasil disimpan')
    } catch (error) {
      showErrorToast(error)
    } finally {
      isSaving.value = false
    }
  }

  /**
   * Simpan pengaturan pencatatan meteran.
   *
   * Setelah disimpan, store `settings` ikut diperbarui supaya popup input
   * meter yang SEDANG terbuka ikut memakai nilai terbaru -- tanpa perlu
   * memuat ulang halaman.
   */
  const savePemakaianAir = async () => {
    try {
      isSaving.value = true
      await sopService.savePemakaianAir({
        autoOpenMeterCamera: Boolean(pemakaianAirForm.value.autoOpenMeterCamera),
      })
      settingsStore.setAutoOpenMeterCamera(pemakaianAirForm.value.autoOpenMeterCamera)
      showSuccessToast('Pengaturan Pemakaian berhasil disimpan')
    } catch (error) {
      showErrorToast(error)
    } finally {
      isSaving.value = false
    }
  }

  const saveCalk = async () => {
    try {
      isSaving.value = true
      // Simpan konfigurasi CALK (persentase + laba ditahan).
      await sopService.saveCalk({
        peraturan_desa: calkForm.value.peraturan_desa,
        bantuan_rumah_tangga: Number(calkForm.value.bantuan_rumah_tangga) || 0,
        pengembangan_kapasitas: Number(calkForm.value.pengembangan_kapasitas) || 0,
        pelatihan_masyarakat: Number(calkForm.value.pelatihan_masyarakat) || 0,
        peningkatan_modal: Number(calkForm.value.peningkatan_modal) || 0,
        penambahan_investasi: Number(calkForm.value.penambahan_investasi) || 0,
        pendirian_unit_usaha: Number(calkForm.value.pendirian_unit_usaha) || 0,
      })
      // Simpan Point A (Gambaran Umum) kustom secara paralel.
      await sopService.saveCustomCalk({ point_a: calkForm.value.point_a ?? '' })
      showSuccessToast('Pengaturan CALK berhasil disimpan')
    } catch (error) {
      showErrorToast(error)
    } finally {
      isSaving.value = false
    }
  }

  const saveLogo = async () => {
    if (!logoForm.value.file) {
      showErrorToast({ message: 'Pilih file logo terlebih dahulu' })
      return
    }
    try {
      isSaving.value = true
      const res = await sopService.saveLogo(logoForm.value.file)
      const data = res?.data?.data ?? res?.data ?? res
      if (data?.logo) {
        logoForm.value.preview = storageLogoUrl(data.logo)
        logoForm.value.previewName = data.logo
      }
      logoForm.value.file = null
      // Logo baru harus langsung dipakai di kop surat yang sedang tampil.
      refreshIdentitas()
      showSuccessToast('Logo berhasil disimpan')
    } catch (error) {
      showErrorToast(error)
    } finally {
      isSaving.value = false
    }
  }

  const saveWhatsapp = async () => {
    try {
      isSaving.value = true
      await sopService.saveWhatsapp({ ...whatsappForm.value })
      showSuccessToast('Template WhatsApp berhasil disimpan')
    } catch (error) {
      showErrorToast(error)
    } finally {
      isSaving.value = false
    }
  }

  const saveSettings = () => {
    switch (activeSection.value) {
      case 'lembaga':
        return saveLembaga()
      case 'pasangBaru':
        return savePasangBaru()
      case 'sistemTagihan':
        return saveSistemTagihan()
      case 'pemakaianAir':
        return savePemakaianAir()
      case 'calk':
        return saveCalk()
      case 'logo':
        return saveLogo()
      case 'whatsapp':
        return saveWhatsapp()
      default:
        return Promise.resolve()
    }
  }

  onMounted(() => {
    loadSettings()
  })

  return {
    activeSection,
    activeLabel,
    menuList,
    isLoading,
    isSaving,
    lembagaForm,
    pasangBaruForm,
    sistemTagihanForm,
    pemakaianAirForm,
    calkForm,
    logoForm,
    whatsappForm,
    wellcomeForm,
    loadSettings,
    saveSettings,
    saveLembaga,
    savePasangBaru,
    saveSistemTagihan,
    savePemakaianAir,
    saveCalk,
    saveLogo,
    saveWhatsapp,
  }
}
