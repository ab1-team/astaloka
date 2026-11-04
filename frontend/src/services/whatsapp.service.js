import api from '@/utils/axios'

/**
 * WhatsApp Service - menyusun pesan dari template yang diset admin di SOP.
 *
 * PENTING: service ini TIDAK mengirim pesan. Ia hanya meminta backend
 * merakit teksnya dari `settings.pesan_tagihan` (atau `pesan_pembayaran`)
 * dan mengembalikan tautan `wa.me` yang pesannya sudah terisi.
 *
 * Kenapa begitu: repo ini belum punya gateway WhatsApp yang tersambung
 * (tombol "Scan Whatsapp" di SOP masih QR mock), jadi jalur yang benar-benar
 * bisa dipakai adalah `wa.me`. Yang DIPERBAIKI di sini adalah isi pesannya:
 * sekarang mengikuti template admin, bukan teks yang ditulis mati di frontend.
 *
 * Composables yang memakai ini:
 *   - `useWhatsappTagihan` : dipakai halaman "Daftar Tagihan" (satu per
 *     satu) dan popup TAGIHAN di dashboard (banyak sekaligus).
 */
export const whatsappService = {
  /**
   * Template WhatsApp yang sedang aktif.
   *
   * Admin saja endpoint `settings/sop` yang boleh dibaca; ini versi
   * terpisah yang tidak butuh hak admin.
   */
  async getTemplate() {
    const response = await api.get('/whatsapp/template')
    return response.data
  },

  /**
   * Susun pesan WhatsApp untuk satu tagihan.
   *
   * @param {number} billId
   * @returns {Promise<Object>} { bill_id, customer, message, wa_url, error }
   */
  async getMessage(billId) {
    const response = await api.post('/whatsapp/messages', { bill_ids: [billId] })
    return response.data
  },

  /**
   * Susun pesan WhatsApp untuk beberapa tagihan sekaligus.
   *
   * Dipakai popup TAGIHAN di dashboard, tempat admin mencentang beberapa
   * baris lalu mengirim sekaligus.
   *
   * @param {number[]} billIds
   * @param {string} [tipe] 'tagihan' | 'pembayaran'
   * @returns {Promise<Object>} { template, messages, total, siap_kirim }
   */
  async getMessages(billIds = [], tipe = 'tagihan') {
    const response = await api.post('/whatsapp/messages', {
      bill_ids: billIds,
      tipe,
    })
    return response.data
  },
}

export default whatsappService
