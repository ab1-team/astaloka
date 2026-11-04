/**
 * Pemecah baris tabel untuk halaman cetak "Status Instalasi".
 *
 * KENAPA FILE INI ADA
 * ==================
 *
 * Jumlah baris per halaman untuk cetakan TIDAK bisa ditebak.
 *
 * Satu baris tabel bisa jadi satu baris teks atau dua, tergantung
 * panjang nama pelanggan dan alamatnya. Baris dua baris teks hampir
 * dua kali lebih tinggi dari baris satu baris teks. Kalau jumlah
 * baris per halaman ditetapkan dengan angka tetap, salah satu dari dua
 * hal pasti terjadi:
 *
 *   - Halaman meluber ke halaman fisik berikutnya. Isinya tidak hilang,
 *     tapi kop surat halaman lanjutan hilang, dan nomor halaman jadi
 *     tidak sinkron dengan isi.
 *   - Halaman terlalu jarang terisi. Cetakan jadi banyak halaman
 *     kosong yang hanya berisi kop surat.
 *
 * Jadi jumlah baris harus dihitung dari tinggi baris yang SEBENARNYA.
 * `packRows` di bawah adalah bagian matematisnya: dia menerima tinggi
 * tiap baris (sudah diukur dari DOM) dan sisa tinggi yang tersedia
 * per halaman, lalu mengembalikan kelompok baris per halaman.
 *
 * Fungsi ini murni (tanpa DOM), jadi bisa diuji langsung.
 */

/**
 * Kemas baris ke halaman.
 *
 * Cara kerja: greedy. Baris diambil satu per satu selama masih muat
 * di halaman yang sedang dibangun. Kalau baris berikutnya tidak muat,
 * halaman ditutup dan baris itu membuka halaman baru.
 *
 * Tinggi baris dipakai SATU PER SATU, bukan dirata-rata. Baris satu
 * baris teks dan baris dua baris teks bedanya jauh -- memakai
 * rata-rata akan membuat halaman yang berisi baris pendek meluber.
 *
 * @param {number[]} tinggiBaris Tinggi tiap baris, dalam px.
 * @param {number} tersediaPertama Sisa tinggi halaman PERTAMA (px).
 * @param {number} tersediaBerikutnya Sisa tinggi halaman LANJUTAN (px).
 * @returns {number[][]} kelompok indeks baris per halaman.
 */
export const packRows = (tinggiBaris, tersediaPertama, tersediaBerikutnya) => {
  if (!Array.isArray(tinggiBaris) || tinggiBaris.length === 0) return []

  const halaman = []

  let sedang = []
  let terpakai = 0
  let tersedia = tersediaPertama

  tinggiBaris.forEach((tinggi, index) => {
    // Baris pertama selalu masuk halaman, walau halaman sendiri tidak
    // cukup untuk menampungnya. Memotong satu baris jadi dua halaman
    // lebih buruk: barisnya jadi tidak terbaca.
    if (sedang.length > 0 && terpakai + tinggi > tersedia) {
      halaman.push(sedang)

      sedang = []
      terpakai = 0
      tersedia = tersediaBerikutnya
    }

    sedang.push(index)
    terpakai += tinggi
  })

  if (sedang.length > 0) {
    halaman.push(sedang)
  }

  return halaman
}

/**
 * Ubah kelompok indeks baris menjadi potongan data siap pakai.
 *
 * @param {Array} rows Semua baris yang akan dicetak.
 * @param {number[][]} kelompok Hasil dari `packRows`.
 * @returns {{items: Array, startIndex: number}[]}
 */
export const kePotongan = (rows, kelompok) => {
  const data = Array.isArray(rows) ? rows : []

  return kelompok.map((indexes) => {
    const startIndex = indexes[0]

    return {
      items: data.slice(startIndex, startIndex + indexes.length),
      startIndex,
    }
  })
}
