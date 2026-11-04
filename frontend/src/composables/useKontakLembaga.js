import { computed } from 'vue'
import { useIdentitasLembaga } from '@/composables/useIdentitasLembaga'

/**
 * Nomor kontak resmi organisasi (WhatsApp / telepon).
 *
 * Satu-satunya sumber kebenaran adalah `settings.telepon`, yaitu kolom yang
 * diisi admin lewat menu "SOP → Profil Lembaga" (`SopController::updateLembaga`).
 *
 * Kenapa TIDAK ada konstanta hardcode di halaman pelanggan:
 *   Halaman "Lapor Gangguan" dulu menulis `0812-3456-7890` langsung di
 *   template. Begitu admin mengganti nomor di Pengaturan, halaman pelanggan
 *   tetap menampilkan yang lama — jadi pelanggan menghubungi nomor yang
 *   salah. Sekarang semua halaman membaca dari composable ini, jadi cukup
 *   ganti sekali di admin dan seluruh sisi pelanggan ikut berubah.
 *
 * Kenapa BACA DARI `useIdentitasLembaga`, bukan request sendiri:
 *   `telepon` ada di respons `GET /api/settings/lembaga-identity` yang
 *   sama. Kalau modul ini punya cache sendiri, satu sesi yang membuka
 *   halaman pelanggan DAN halaman cetak akan menembak endpoint yang
 *   sama dua kali -- untuk data yang isinya identik. `useIdentitasLembaga`
 *   sudah menyimpan satu cache modul untuk seluruh field identitas, jadi
 *   composable ini cukup membacanya.
 *
 *   Fungsi format (`normalizeToInternational`, `formatDisplay`) TETAP ADA
 *   di sini: dipakai bersama oleh modul lain, dan tidak ada hubungannya
 *   dengan pemuatan data.
 */

/**
 * Dipanggil setelah admin menyimpan setting, agar nomor ikut terbarui.
 *
 * Cache identitas di modul lain juga ikut segar, jadi kop surat yang
 * sedang terbuka langsung memakai nomor terbaru.
 */
export const refreshKontak = async () => {
  const { reload } = useIdentitasLembaga()
  await reload()
}

/** Buang semua karakter non-digit. */
const onlyDigits = (v) => String(v || '').replace(/\D/g, '')

/**
 * Ubah nomor Indonesia ke format internasional tanpa "+" (dipakai wa.me).
 *   0812-3456-7890  → 6281234567890
 *   8123456789      → 628123456789
 *   +62 812-3456…   → 6281234567890
 * Nomor yang sudah diawali "62" (kode negara) tidak diubah.
 */
export function normalizeToInternational(value) {
  let d = onlyDigits(value)
  if (!d) return ''
  if (d.startsWith('62')) return d
  if (d.startsWith('0')) return `62${d.slice(1)}`
  if (d.startsWith('8')) return `62${d}`
  return d
}

/**
 * Format tampilan enak dibaca.
 *
 * Aturan panjang digit lokal Indonesia:
 *   11 digit → 4-4-3  (nomor seluler)  0812-3456-7890
 *   10 digit → 4-3-3  (telepon BSN)     0274-889-123
 *    9 digit → 3-3-3  (telepon kota)   021-123-456
 *
 * Regex lama `^(\d{3,4})(\d{3,4})(\d{0,5})$` salah memecah nomor 10 digit
 * jadi 4-4-2 ("0274-8891-23"), sehingga nomor telepon kantor tampil
 * dengan digit yang tersusun keliru.
 */
export function formatDisplay(value) {
  const d = onlyDigits(value)
  if (!d) return ''

  // Kembalikan ke format lokal (leading 0) supaya tampil sebagai nomor
  // Indonesia, bukan "+62…".
  const local = d.startsWith('62') ? `0${d.slice(2)}` : d.startsWith('0') ? d : `0${d}`

  const len = local.length
  const pattern =
    len >= 11
      ? /^(\d{4})(\d{4})(\d{3,})$/
      : len === 10
        ? /^(\d{4})(\d{3})(\d{3})$/
        : len === 9
          ? /^(\d{3})(\d{3})(\d{3})$/
          : null

  const m = pattern ? local.match(pattern) : null
  return m ? `${m[1]}-${m[2]}-${m[3]}` : local
}

/**
 * @returns {{
 *   telepon: import('vue').Ref<string>,
 *   digits: import('vue').ComputedRef<string>,
 *   display: import('vue').ComputedRef<string>,
 *   hasNumber: import('vue').ComputedRef<boolean>,
 *   waLink: import('vue').ComputedRef<string>,
 *   telLink: import('vue').ComputedRef<string>,
 *   reload: () => Promise<void>
 * }}
 */
export function useKontakLembaga() {
  const { telepon, reload } = useIdentitasLembaga()

  /** Digit saja untuk wa.me / tel: (mis. 6281234567890). */
  const digits = computed(() => normalizeToInternational(telepon.value))

  /** Nomor siap tampil (mis. 0812-3456-7890). */
  const display = computed(() => formatDisplay(telepon.value))

  const hasNumber = computed(() => digits.value.length > 0)

  const waLink = computed(() => (digits.value ? `https://wa.me/${digits.value}` : ''))

  const telLink = computed(() => (digits.value ? `tel:+${digits.value}` : ''))

  return {
    telepon,
    digits,
    display,
    hasNumber,
    waLink,
    telLink,
    reload: () => reload(),
  }
}

export default useKontakLembaga
