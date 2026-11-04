import api from '@/utils/axios'

/**
 * SOP Service - Mengelola pengaturan personalisasi SOP
 *
 * Catatan untuk tim Backend:
 * - Setiap section dikirim terpisah agar mudah dibedakan endpoint-nya.
 * - Endpoint default mengikuti pola: /settings/sop/{section}
 * - Untuk upload logo memakai multipart/form-data.
 */
export const sopService = {
  /**
   * Ambil seluruh pengaturan SOP (untuk inisialisasi form)
   *
   * KHUSUS ADMIN — endpoint ini `role:admin`. Jangan panggil dari komponen
   * yang dirender untuk semua role, karena hasilnya 403 dan error-nya
   * ditelan `catch` sehingga gejalanya tidak terlihat. Gunakan
   * `getPublicIdentity()` untuk chrome bersama.
   */
  async getAll() {
    const response = await api.get('/settings/sop')
    return response.data
  },

  /**
   * Nama & logo lembaga — boleh dipanggil role apa pun yang sudah login.
   *
   * Dipakai SidebarView untuk judul, yang juga dirender oleh teknisi,
   * surveyor, dan pelanggan.
   */
  async getPublicIdentity() {
    const response = await api.get('/settings/lembaga-identity')
    return response.data
  },

  /**
   * Profil Lembaga
   * Payload: { nama, alamat, email, telepon, website, deskripsi }
   */
  async saveLembaga(payload) {
    const response = await api.post('/settings/sop/lembaga', payload)
    return response.data
  },

  /**
   * Aturan Pasang Baru
   * Payload: { biayaPasang, statusPembayaran, enableAir, enableSampah }
   */
  async savePasangBaru(payload) {
    const response = await api.post('/settings/sop/pasang-baru', payload)
    return response.data
  },

  /**
   * Sistem Tagihan
   *
   * Payload: { toleransiTunggakan, batasTagihan, batasBulanTunggakan }
   *
   * - `toleransiTunggakan`  : TANGGAL (1-31) untuk semua aturan berbasis
   *                            tanggal -- jatuh tempo tagihan bulanan,
   *                            generate piutang tiap bulan, dan deteksi
   *                            advance payment. (Ambang pengakuan piutang
   *                            TIDAK memakai angka ini: piutang diakui saat
   *                            tanggal jatuh tempo sudah tercapai.)
   * - `batasTagihan`        : alias lama dari `toleransiTunggakan`. Kalau
   *                            dikirim, HARUS sama dengan
   *                            `toleransiTunggakan`, kalau tidak backend
   *                            menolak dengan 422.
   * - `batasBulanTunggakan` : JUMLAH BULAN tunggakan yang boleh ditoleransi
   *                            sebelum sambungan pelanggan diputus (0-24).
   */
  async saveSistemTagihan(payload) {
    const response = await api.post('/settings/sop/sistem-tagihan', payload)
    return response.data
  },

  /**
   * Pengaturan pencatatan meteran (kamera otomatis atau tidak).
   *
   * Endpoint ini BUKAN `role:admin` seperti `getAll()` -- popup input meter
   * membacanya, dan popup itu dipakai teknisi juga. Kalau hanya ada di
   * `settings/sop`, teknisi selalu kena 403 dan camerasnya tidak pernah
   * terbuka untuk mereka.
   */
  async getPemakaianAir() {
    const response = await api.get('/settings/pemakaian-air')
    return response.data
  },

  /**
   * Simpan pengaturan pencatatan meteran.
   * Payload: { autoOpenMeterCamera: boolean }
   */
  async savePemakaianAir(payload) {
    const response = await api.post('/settings/sop/pemakaian-air', payload)
    return response.data
  },

  /**
   * Logo & Branding
   * Mengirim 1 file via multipart/form-data dengan field `logo`.
   * @param {File} file
   */
  async saveLogo(file) {
    const formData = new FormData()
    formData.append('logo', file)

    const response = await api.post('/settings/sop/logo', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    return response.data
  },

  /**
   * Template WhatsApp
   * Payload: { templateTagihan, templatePembayaran }
   */
  async saveWhatsapp(payload) {
    const response = await api.post('/settings/sop/whatsapp', payload)
    return response.data
  },

  /**
   * ============================================================
   * CALK (Catatan Atas Laporan Keuangan)
   * Konsep diadaptasi dari aplikasi sidbm (SopController::calk).
   * ============================================================
   */

  /**
   * Ambil konfigurasi CALK.
   * Response.data: {
   *   peraturan_desa, D.1.d.1/2/3 (%), D.2.a/b/c (nominal), point_a
   * }
   */
  async getCalk() {
    const response = await api.get('/settings/sop/calk')
    return response.data
  },

  /**
   * Simpan konfigurasi CALK.
   * Payload: {
   *   peraturan_desa, bantuan_rumah_tangga, pengembangan_kapasitas,
   *   pelatihan_masyarakat, peningkatan_modal, penambahan_investasi, pendirian_unit_usaha
   * }
   */
  async saveCalk(payload) {
    const response = await api.post('/settings/sop/calk', payload)
    return response.data
  },

  /**
   * Ambil Point A (Gambaran Umum) kustom CALK.
   */
  async getCustomCalk() {
    const response = await api.get('/settings/sop/custom-calk')
    return response.data
  },

  /**
   * Simpan Point A (Gambaran Umum) kustom CALK.
   * Payload: { point_a: string (HTML) }
   */
  async saveCustomCalk(payload) {
    const response = await api.post('/settings/sop/custom-calk', payload)
    return response.data
  },

  /**
   * Ambil catatan "Lain-lain" CALK per tanggal.
   * @param {string} tanggal format YYYY-MM-DD
   */
  async getCalkCatatan(tanggal) {
    const response = await api.get('/settings/sop/calk-catatan', {
      params: { tanggal },
    })
    return response.data
  },

  /**
   * Simpan catatan "Lain-lain" CALK per tanggal.
   * Payload: { tanggal: 'YYYY-MM-DD', catatan: string (HTML) }
   */
  async saveCalkCatatan(payload) {
    const response = await api.post('/settings/sop/calk-catatan', payload)
    return response.data
  },
}

export default sopService
