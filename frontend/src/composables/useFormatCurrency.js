/**
 * Memformat angka menjadi mata uang Rupiah Indonesia
 * @param {number} value - Angka yang diformat
 * @param {Object} options - Opsi pemformatan
 * @returns {string} String mata uang yang diformat
 */
export function useFormatCurrency(value, options = {}) {
  const {
    currency = 'IDR',
    locale = 'id-ID',
    minimumFractionDigits = 0,
    maximumFractionDigits = 0,
    showSymbol = true,
  } = options

  if (value === null || value === undefined || isNaN(value)) {
    return showSymbol ? 'Rp 0' : '0'
  }

  const formatter = new Intl.NumberFormat(locale, {
    style: showSymbol ? 'currency' : 'decimal',
    currency,
    minimumFractionDigits,
    maximumFractionDigits,
  })

  return formatter.format(value)
}

/**
 * Formatter Rupiah sederhana (alternatif)
 *
 * Nilai dipaksa jadi number DULU sebelum diformat.
 *
 * Alasannya: `String.prototype.toLocaleString` MENERIMA argumen apa pun dan
 * tetap mengembalikan string itu apa adanya -- jadi `("15000").toLocaleString('id-ID')`
 * menghasilkan "15000", bukan "15.000". Nilai dari API memang bisa berupa
 * string angka, dan hasilnya jadi "Rp 15000" (tanpa pemisah ribuan) --
 * format yang berbeda dari "Rp 15.000" untuk angka yang sama.
 *
 * Karena itu: `Number()` dulu, cek hasilnya finite, baru format.
 *
 * @param {number|string} value - Angka yang diformat
 * @returns {string} String Rupiah yang diformat
 */
export function formatRupiah(value) {
  const angka = Number(value)

  if (!Number.isFinite(angka)) {
    return 'Rp 0'
  }

  return `Rp ${angka.toLocaleString('id-ID')}`
}

/**
 * Mengubah string mata uang kembali menjadi angka
 * @param {string} currencyString - String mata uang
 * @returns {number} Angka hasil parsing
 */
export function parseCurrency(currencyString) {
  if (!currencyString || typeof currencyString !== 'string') {
    return 0
  }

  // Hapus simbol mata uang dan pemformatan
  const cleanString = currencyString
    .replace(/[Rp\s$€£¥]/g, '')
    .replace(/\./g, '')
    .replace(/,/g, '.')

  const parsed = parseFloat(cleanString)
  return isNaN(parsed) ? 0 : parsed
}
