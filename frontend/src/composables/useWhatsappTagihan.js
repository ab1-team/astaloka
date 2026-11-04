import { ref } from 'vue'
import { whatsappService } from '@/services/whatsapp.service.js'
import { showErrorToast, showSuccessToast, showWarningToast } from '@/utils/swal'

/**
 * Mengirim pesan WhatsApp pengingat tagihan.
 *
 * PESANNYA DIAMBIL DARI TEMPLATE SOP
 * ----------------------------------
 * Template ada di `settings.pesan_tagihan`, diisi admin lewat
 * SOP -> Whatsapp API. Tag yang didukung: `{customer}`, `{desa}`,
 * `{jumlah_tagihan}`, `{tagihan}`, `{periode}`, `{jatuh_tempo}`.
 *
 * Compose ini yang menggantinya dengan data tagihan yang sebenarnya, jadi
 * admin cukup menyetel template sekali di SOP -- tidak ada lagi teks pesan
 * yang ditulis mati di halaman Daftar Tagihan.
 *
 * DUA CARA PAKAI
 * --------------
 *  1. Satu per satu  -- halaman "Daftar Tagihan". Tombol kirim ada di
 *     setiap baris, jadi admin tidak perlu buka modal rincian dulu.
 *  2. Banyak sekaligus -- popup "TAGIHAN" di dashboard. Admin mencentang
 *     beberapa baris, lalu seluruh pesan disusun sekaligus.
 *
 * Keduanya memakai service yang sama, jadi isi pesannya dijamin sama.
 *
 * CATATAN SOAL POPUP
 * ------------------
 * `window.open` lebih dari satu kali dalam satu klik akan diblokir browser.
 * Karena itu untuk kirim banyak, jendela PERTAMA dibuka langsung saat
 * klik (masih dalam gestur pengguna), lalu berikutnya dibuka satu per satu
 * dengan jeda. Tanpa jeda itu, browser memblokir hampir semuanya dan
 * admin tidak tahu mana yang gagal terkirim.
 */
export function useWhatsappTagihan() {
  /** Id tagihan yang sedang diproses. */
  const sendingId = ref(null)

  /** Id-Id tagihan yang sedang diproses (mode banyak). */
  const sendingIds = ref([])

  /** Hasil susunan pesan terakhir (mode banyak). */
  const lastResult = ref(null)

  /**
   * Kirim satu tagihan.
   *
   * @param {number} billId
   * @param {Object} [opts]
   * @param {string} [opts.tipe] 'tagihan' | 'pembayaran'
   * @returns {Promise<boolean>} true kalau tautan wa.me berhasil dibuka
   */
  const sendOne = async (billId, { tipe = 'tagihan' } = {}) => {
    if (!billId || sendingId.value !== null) return false

    sendingId.value = billId

    try {
      const res = await whatsappService.getMessages([billId], tipe)
      const pesan = res?.data?.messages?.[0]

      if (!pesan) {
        showErrorToast('Pesan WhatsApp tidak bisa disusun.')
        return false
      }

      if (!pesan.wa_url) {
        showWarningToast(pesan.error || 'Nomor WhatsApp pelanggan tidak tersedia.')
        return false
      }

      window.open(pesan.wa_url, '_blank')

      return true
    } catch (error) {
      showErrorToast(error)
      return false
    } finally {
      sendingId.value = null
    }
  }

  /**
   * Kirim beberapa tagihan sekaligus.
   *
   * @param {Array<number>} billIds
   * @param {Object} [opts]
   * @param {string} [opts.tipe] 'tagihan' | 'pembayaran'
   * @returns {Promise<Object|null>} ringkasan hasil
   */
  const sendBulk = async (billIds = [], { tipe = 'tagihan' } = {}) => {
    const ids = [...new Set((billIds || []).map(Number).filter(Boolean))]

    if (ids.length === 0) {
      showWarningToast('Pilih minimal satu tagihan terlebih dahulu.')
      return null
    }

    if (sendingIds.value.length > 0) return null

    sendingIds.value = ids

    try {
      const res = await whatsappService.getMessages(ids, tipe)
      const pesan = res?.data?.messages || []
      const siapKirim = pesan.filter((p) => p.wa_url)

      lastResult.value = res?.data ?? null

      if (siapKirim.length === 0) {
        showWarningToast('Tidak ada tagihan yang punya nomor WhatsApp.')
        return res?.data ?? null
      }

      /*
       * Jendela pertama dibuka LANGSUNG, di dalam handler klik.
       *
       * Ini satu-satunya momen di mana browser masih anggap ini sebagai
       * aksi dari pengguna; setelah ada `await`, browser menganggap setiap
       * `window.open` berikutnya sebagai popup yang diblokir.
       */
      const [pertama, ...sisa] = siapKirim
      window.open(pertama.wa_url, '_blank')

      // Sisanya dibuka berurutan dengan jeda pendek.
      for (const item of sisa) {
        await new Promise((resolve) => setTimeout(resolve, 350))
        window.open(item.wa_url, '_blank')
      }

      const gagal = pesan.length - siapKirim.length
      if (gagal > 0) {
        showWarningToast(
          `${siapKirim.length} pesan dibuka, ${gagal} tagihan dilewati karena nomor WhatsApp tidak tersedia.`,
        )
      } else {
        showSuccessToast(`${siapKirim.length} pesan WhatsApp siap dikirim.`)
      }

      return res?.data ?? null
    } catch (error) {
      showErrorToast(error)
      return null
    } finally {
      sendingIds.value = []
    }
  }

  return {
    sendingId,
    sendingIds,
    lastResult,
    isSending: (id) => sendingId.value === id,
    isSendingAny: () => sendingId.value !== null || sendingIds.value.length > 0,
    sendOne,
    sendBulk,
  }
}

export default useWhatsappTagihan
