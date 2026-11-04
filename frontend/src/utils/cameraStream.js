/**
 * Membuka kamera lewat `getUserMedia`, dengan pesan error yang bisa
 * ditindaklanjuti user.
 *
 * Kenapa helper ini ada
 * ====================
 *
 * Semua pemanggilan `getUserMedia` di aplikasi ini dulu dibungkus `catch`
 * yang TIDAK membawa nama errornya:
 *
 *     } catch {
 *       MySwal.fire({ title: 'Kamera Gagal', text: 'Pastikan browser memiliki izin ...' })
 *     }
 *
 * Akibatnya lima penyebab yang BERBEDA produce pesan yang SAMA:
 *
 *   1. Izin kamera ditolak oleh user atau sudah diblokir browser.
 *   2. Tidak ada kamera sama sekali di perangkat.
 *   3. Kamera sedang dipakai aplikasi lain (video call, aplikasi kamera).
 *   4. Halaman dibuka lewat HTTP di LAN, bukan HTTPS.
 *   5. Constraint kamera yang diminta tidak bisa dipenuhi perangkat ini.
 *
 * User hanya tahu "kamera gagal", padahal cara memperbaikinya beda-beda --
 * dan nomor 1 sampai 3 sama sekali tidak bisa diperbaiki dengan cara yang
 * sama seperti nomor 4. Tanpa nama errornya, satu-satunya yang bisa dilakukan
 * user adalah mencoba-coba sendiri.
 *
 * Dua masalah nyata yang helper ini perbaiki
 * -----------------------------------------
 *
 * 1. `facingMode` yang ditulis sebagai string
 *
 *    Media Capture Spec menyatakan bahwa `facingMode` dengan nilai string
 *    WAJIB diperlakukan seperti `{ exact: true }`. Jadi
 *
 *        getUserMedia({ video: { facingMode: 'environment' } })
 *
 *    melempar `OverconstrainedError` di laptop yang kameranya cuma webcam
 *    depan -- PC tidak punya kamera belakang, dan tidak ada satu pun
 *    perangkat yang memenuhi constraint itu. Kamera yang justru tersedia
 *    jadi dilewati, dan user mendapat "Kamera Gagal" padahal webcam-nya
 *    menyala dengan baik.
 *
 *    Di HP constraint `environment` memang ada, jadi masalahnya tidak
 *    muncul -- tapi hampir semua developer menguji dari laptop, dan banyak
 *    teknisi juga pakai laptop.
 *
 *    Solusinya: pakai `ideal`. `ideal` cuma preferensi, jadi browser bebas
 *    memakai kamera lain yang tersedia kalau preferensinya tidak bisa
 *    dipenuhi. Ditambah fallback ke `video: true` kalau ternyata device-nya
 *    ada tapi tidak cocok.
 *
 * 2. Konteks aman (secure context)
 *
 *    `navigator.mediaDevices` HANYA ada di secure context: HTTPS atau
 *    `localhost`. Di LAN HTTP -- misalnya `http://192.168.1.10:5173` --
 *    objeknya `undefined`, jadi `navigator.mediaDevices.getUserMedia(...)`
 *    melempar `TypeError`, yang pesannya sama sekali tidak menyebut HTTP.
 *    Padahal itu penyebab paling sering di lingkungan lapangan: teknisi
 *    membuka aplikasinya lewat IP lokal.
 */

/** Kode kesalahan kamera. */
export const KAMERA_ERROR = {
  /** Halaman bukan secure context -- butuh HTTPS atau localhost. */
  PERLU_HTTPS: 'perlu_https',
  /** Izin kamera ditolak atau sudah diblokir browser. */
  IZIN_DITOLAK: 'izin_ditolak',
  /** Tidak ada perangkat kamera di perangkat ini. */
  TIDAK_ADA_KAMERA: 'tidak_ada_kamera',
  /** Kamera tidak bisa dibuka karena sedang dipakai aplikasi lain. */
  KAMERA_TERPAKAI: 'kamera_terpakai',
  /** Tidak ada kamera yang cocok dengan mode yang diminta. */
  TIDAK_KOMPATIBEL: 'tidak_kompatibel',
  /** Penyebab lain yang tidak dikenali. */
  TIDAK_DIKETAHUI: 'tidak_diketahui',
}

/**
 * Kesalahan kamera yang sudah dinormalisasi.
 *
 * `code` dipakai untuk memilih pesan; `penyebab` menyimpan nama error
 * asli dari browser supaya masih bisa ditelusuri di log.
 */
export class CameraError extends Error {
  constructor(code, pesan, penyebab = null) {
    super(pesan)
    this.name = 'CameraError'
    this.code = code
    this.penyebab = penyebab
  }
}

/**
 * Terjemahkan error dari `getUserMedia` jadi kode yang bisa acted upon.
 *
 * @param {any} error - error yang dilempar browser.
 * @returns {string} salah satu dari `KAMERA_ERROR`.
 */
export function kodeKameraError(error) {
  // `CameraError` sudah membawa kodenya -- jangan ditafsirkan ulang.
  if (error?.code && typeof error.code === 'string') {
    return error.code
  }

  const nama = error?.name ?? ''

  switch (nama) {
    case 'NotAllowedError':
    case 'PermissionDeniedError':
    case 'SecurityError':
      return KAMERA_ERROR.IZIN_DITOLAK
    case 'NotFoundError':
    case 'DevicesNotFoundError':
      return KAMERA_ERROR.TIDAK_ADA_KAMERA
    case 'NotReadableError':
    case 'TrackStartError':
      return KAMERA_ERROR.KAMERA_TERPAKAI
    case 'OverconstrainedError':
      return KAMERA_ERROR.TIDAK_KOMPATIBEL
    default:
      break
  }

  /*
   * `navigator.mediaDevices` yang hilang bukan error kamera -- itu masalah
   * alamat halaman. `TypeError` di sini hampir selalu berarti pemanggilnya
   * mencoba memanggil `getUserMedia` di objek yang tidak ada.
   *
   * Dicek dari `error` itu sendiri, bukan dari `navigator` sekarang:
   * `navigator.mediaDevices` bisa saja sudah terpasang saat error ini
   * ditafsirkan -- yang hilang waktu error itu dibuat adalah objeknya.
   */
  if (nama === 'TypeError') {
    return KAMERA_ERROR.PERLU_HTTPS
  }

  /*
   * Error tanpa `name` yang terjadi di luar secure context.
   *
   * `bukaKamera()` sudah mengecek keberadaan `navigator.mediaDevices`
   * SEBELUM memanggil `getUserMedia`, jadi di jalur itu error-nya adalah
   * `CameraError` yang membawa kodenya sendiri. Tapi `jelaskanErrorKamera()`
   * juga boleh dipanggil dengan error mentah dari tempat lain, jadi
   * `navigator.mediaDevices` yang hilang tetap perlu dikenali.
   */
  if (!navigator?.mediaDevices?.getUserMedia) {
    return KAMERA_ERROR.PERLU_HTTPS
  }

  return KAMERA_ERROR.TIDAK_DIKETAHUI
}

/**
 * Dialog yang ditampilkan ke user untuk sebuah kegagalan kamera.
 *
 * Setiap pesan menyebut CARA memperbaikinya, karena "Kamera Gagal" tanpa
 * petunjuk hanya mengulang informasi yang sudah diketahui user.
 *
 * @param {any} error - error dari `getUserMedia`.
 * @returns {{ title: string, text: string, code: string }}
 */
export function jelaskanErrorKamera(error) {
  const code = kodeKameraError(error)

  switch (code) {
    case KAMERA_ERROR.PERLU_HTTPS:
      return {
        code,
        title: 'Kamera Perlu HTTPS',
        text:
          'Kamera hanya bisa diakses lewat alamat HTTPS atau localhost. ' +
          'Alamat yang dipakai sekarang bukan secure context -- buka aplikasi ' +
          'lewat HTTPS, atau lewat localhost kalau sedang di komputer sendiri.',
      }

    case KAMERA_ERROR.IZIN_DITOLAK:
      return {
        code,
        title: 'Izin Kamera Ditolak',
        text:
          'Izin kamera belum diberikan atau sudah diblokir browser. ' +
          'Buka pengaturan situs di browser (ikon kunci di bilah alamat) → ' +
          'Izin → Kamera → Blokir/Allow, lalu muat ulang halaman ini.',
      }

    case KAMERA_ERROR.TIDAK_ADA_KAMERA:
      return {
        code,
        title: 'Kamera Tidak Ditemukan',
        text:
          'Tidak ada kamera yang terdeteksi di perangkat ini. ' +
          'Bisa tetap mengetik angka meteran secara manual, atau memilih foto ' +
          'dari galeri lewat tombol File.',
      }

    case KAMERA_ERROR.KAMERA_TERPAKAI:
      return {
        code,
        title: 'Kamera Sedang Dipakai',
        text:
          'Kamera tidak bisa dibuka karena sedang dipakai aplikasi lain ' +
          '(mis. aplikasi video call). Tutup aplikasi itu, lalu coba lagi.',
      }

    case KAMERA_ERROR.TIDAK_KOMPATIBEL:
      return {
        code,
        title: 'Kamera Tidak Kompatibel',
        text:
          'Kamera ini tidak mendukung mode yang diminta. ' +
          'Coba kamera lainnya, atau ketik angka meteran secara manual.',
      }

    default:
      return {
        code,
        title: 'Kamera Gagal',
        text:
          'Kamera tidak bisa dinyalakan. ' +
          'Coba muat ulang halaman, atau pakai tombol File untuk memilih foto.',
      }
  }
}

/**
 * Apakah error ini layak dicoba lagi TANPA constraint `facingMode`.
 *
 * Hanya `OverconstrainedError` yang layak: itu artinya ada device, tapi tidak
 * ada yang cocok dengan mode yang diminta -- persis kasus laptop yang hanya
 * punya webcam depan.
 *
 * Error lain TIDAK dicoba lagi. Percobaan ulang pada `NotAllowedError` akan
 * memunculkan permintaan izin lagi (membingungkan), dan pada
 * `NotFoundError` jelas-jelas tidak ada device yang bisa ditemukan.
 */
function layakDicobaLagi(error) {
  return error?.name === 'OverconstrainedError'
}

/**
 * Nyalakan kamera.
 *
 * Urutan usaha:
 *
 *   1. Constraint `facingMode` sebagai `ideal` -- preferensi, bukan syarat.
 *   2. Tanpa constraint apa pun -- untuk perangkat yang tidak punya kamera
 *      belakang sama sekali.
 *
 * @param {object} opsi
 * @param {'environment'|'user'} [opsi.facingMode] - preferensi arah kamera.
 * @param {object} [opsi.tambahan] - constraint lain, mis. `{ width: { ideal: 1280 } }`.
 * @returns {Promise<MediaStream>}
 * @throws {CameraError} kalau kamera tidak bisa dinyalakan.
 */
export async function bukaKamera({ facingMode = 'environment', tambahan = {} } = {}) {
  if (!navigator?.mediaDevices?.getUserMedia) {
    /*
     * `isSecureContext` yang `false` berarti pasti bukan secure context.
     * Tapi `undefined` juga berarti tidak aman -- JSDOM dan browser lama
     * tidak punya properti ini sama sekali, dan di sana `mediaDevices`
     * memang tidak ada.
     *
     * Jadi "pasti aman" yang dicek, bukan "pasti tidak aman": kalau
     * `isSecureContext` bernilai `true`, berarti halaman memang aman dan
     * `mediaDevices` yang hilang berarti browser-nya memang tidak mendukung
     * kamera.
     */
    const pastiAman = typeof window !== 'undefined' && window.isSecureContext === true

    throw new CameraError(
      pastiAman ? KAMERA_ERROR.TIDAK_DIKETAHUI : KAMERA_ERROR.PERLU_HTTPS,
      pastiAman
        ? 'Browser ini tidak menyediakan navigator.mediaDevices.getUserMedia.'
        : 'navigator.mediaDevices tidak tersedia: halaman dibuka di luar secure context.',
    )
  }

  /*
   * Percobaan kedua TANPA constraint `facingMode`, tapi tetap berbentuk
   * object -- bukan `video: true`.
   *
   * Bentuk `video: true` memang valid untuk `getUserMedia`, tapi
   * handwritten constraint (`{ facingMode: { ideal } }`) adalah
   * `ConstrainDOMString`, dan `true` TIDAK termasuk nilainya. Jadi kalau
   * percobaan pertama gagal, `video: true` cukup jelas untuk membuat
   * browser memilih kamera tanpa preferensi arah.
   */
  const usaha = [
    { video: { facingMode: { ideal: facingMode }, ...tambahan } },
    { video: { ...tambahan } },
  ]

  let errorTerakhir = null

  for (const constraint of usaha) {
    try {
      return await navigator.mediaDevices.getUserMedia(constraint)
    } catch (error) {
      errorTerakhir = error

      if (!layakDicobaLagi(error)) {
        throw new CameraError(kodeKameraError(error), error?.message ?? 'Kamera gagal.', error)
      }
    }
  }

  throw new CameraError(
    kodeKameraError(errorTerakhir),
    errorTerakhir?.message ?? 'Kamera tidak bisa dinyalakan.',
    errorTerakhir,
  )
}

/**
 * Nyalakan kamera dan pasang stream-nya ke elemen video.
 *
 * Stream yang gagal dinyalakan TIDAK dipasang -- elemen videonya dibiarkan
 * kosong, dan errornya dilempar supaya pemanggil bisa memberi tahu user.
 *
 * @param {object} opsi
 * @param {'environment'|'user'} [opsi.facingMode]
 * @param {HTMLVideoElement|null} [opsi.video] - elemen video tujuan.
 * @param {object} [opsi.tambahan] - constraint lain, mis. `{ width: { ideal: 1280 } }`.
 * @returns {Promise<MediaStream>}
 */
export async function bukaKameraPadaVideo({
  facingMode = 'environment',
  video = null,
  tambahan = {},
} = {}) {
  const stream = await bukaKamera({ facingMode, tambahan })

  if (video) {
    video.srcObject = stream
  }

  return stream
}

/**
 * Matikan stream kamera dan lepaskan dari elemen video.
 *
 * @param {MediaStream|null} stream
 * @param {HTMLVideoElement|null} [video]
 */
export function matikanKamera(stream, video = null) {
  if (stream) {
    stream.getTracks().forEach((track) => track.stop())
  }

  if (video) {
    video.srcObject = null
  }
}
