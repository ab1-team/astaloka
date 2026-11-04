/**
 * HELPER KAMERA: cara menyalakan kamera + cara menjelaskan kegagalannya.
 *
 * Bug yang dikunci test ini sudah terjadi di repo ini
 * ---------------------------------------------------
 *
 * 1. `facingMode` yang ditulis sebagai STRING
 *
 *    Media Capture Spec menyatakan `facingMode` dengan nilai string WAJIB
 *    diperlakukan seperti `{ exact: true }`. Jadi
 *
 *        getUserMedia({ video: { facingMode: 'environment' } })
 *
 *    melempar `OverconstrainedError` di laptop yang kameranya cuma webcam
 *    depan -- PC tidak punya kamera belakang. Kamera yang justru TERSEDIA
 *    jadi dilewati, dan user melihat "Kamera Gagal" padahal webcam-nya
 *    menyala baik-baik saja.
 *
 *    Di HP constraint `environment` memang ada, jadi tidak kelihatan di
 *    sini. Tapi hampir semua developer menguji dari laptop, dan banyak
 *    teknisi juga pakai laptop.
 *
 * 2. `catch` yang membuang nama error
 *
 *    Semua pemanggilan kamera dulu dibungkus `catch` tanpa binding:
 *
 *        } catch {
 *          MySwal.fire({ title: 'Kamera Gagal', text: 'Pastikan browser ...' })
 *        }
 *
 *    Lima penyebab yang BERBEDA produce pesan yang SAMA: izin ditolak,
 *    tidak ada kamera, kamera dipakai aplikasi lain, halaman bukan secure
 *    context, dan constraint tidak cocok. User tidak bisa menebak mana
 *    yang terjadi -- padahal cara memperbaikinya beda-beda.
 *
 * Yang diuji di sini
 * ------------------
 *
 *   1. `facingMode` dikirim sebagai `ideal`, bukan string.
 *   2. Kalau device tidak cocok dengan mode yang diminta, helper
 *      DITOLAK fallback ke kamera tanpa constraint.
 *   3. Setiap penyebab kegagalan punya pesan yang MENYEBUTKAN cara
 *      memperbaikinya.
 *   4. Di luar secure context, pesannya menyebut HTTPS -- bukan izin.
 */

import { strict as assert } from 'node:assert'
import { afterEach, beforeEach, describe, it } from 'node:test'

import {
  bukaKamera,
  CameraError,
  KAMERA_ERROR,
  kodeKameraError,
  jelaskanErrorKamera,
  matikanKamera,
} from '../src/utils/cameraStream.js'

/**
 * Permintaan yang tercatat dari stub `getUserMedia`.
 */
let permintaan

/**
 * Error yang harus dilempar stub berikutnya.
 */
let errorBerikutnya

/**
 * Stream tiruan.
 */
function streamTiruan() {
  return {
    getTracks: () => [{ stop() {} }],
  }
}

/**
 * Pasang stub `navigator.mediaDevices`.
 */
function pasangKamera() {
  permintaan = []
  errorBerikutnya = null

  Object.defineProperty(globalThis.navigator, 'mediaDevices', {
    value: {
      async getUserMedia(constraint) {
        permintaan.push(constraint)

        if (errorBerikutnya) {
          const error = errorBerikutnya
          errorBerikutnya = null
          throw error
        }

        return streamTiruan()
      },
    },
    configurable: true,
    writable: true,
  })
}

/** Buang stub supaya test berikutnya tidak mewarisi. */
function lepasKamera() {
  if (globalThis.navigator?.mediaDevices) {
    delete globalThis.navigator.mediaDevices
  }
}

/** Error dengan `name` tertentu, seperti yangThrown browser. */
function errorBrowser(nama, pesan = 'gagal') {
  const error = new Error(pesan)
  error.name = nama
  return error
}

beforeEach(() => {
  pasangKamera()
})

afterEach(() => {
  lepasKamera()
})

describe('helper kamera', () => {
  it('facingMode dikirim sebagai ideal, bukan string', async () => {
    await bukaKamera({ facingMode: 'environment' })

    assert.equal(permintaan.length, 1)
    assert.ok(permintaan[0].video, 'Constraint video harus ada.')

    /*
     * String diperlakukan `{ exact: true }` oleh browser, dan itu yang
     * membuat webcam depan di laptop tidak pernah terpakai.
     */
    assert.ok(
      typeof permintaan[0].video.facingMode === 'object',
      'facingMode harus object { ideal }, bukan string -- string berarti exact.',
    )
    assert.equal(permintaan[0].video.facingMode.ideal, 'environment')
  })

  it('device yang tidak cocok dicoba lagi tanpa constraint facingMode', async () => {
    errorBerikutnya = errorBrowser('OverconstrainedError')

    const stream = await bukaKamera({ facingMode: 'environment' })

    assert.ok(stream, 'Helper harus mengembalikan stream dari percobaan kedua.')
    assert.equal(
      permintaan.length,
      2,
      'Harus ada dua percobaan: yang pertama pakai facingMode, yang kedua tanpa constraint.',
    )
    assert.ok(
      !('facingMode' in permintaan[1].video),
      'Percobaan kedua harus benar-benar tanpa constraint facingMode.',
    )
  })

  it('error selain OverconstrainedError tidak dicoba lagi', async () => {
    errorBerikutnya = errorBrowser('NotAllowedError')

    await assert.rejects(() => bukaKamera({ facingMode: 'environment' }))

    assert.equal(
      permintaan.length,
      1,
      'Izin ditolak tidak boleh dicoba lagi -- percobaan ulang hanya memunculkan permintaan izin lagi.',
    )
  })

  it('izin ditolak dijelaskan dengan cara memperbaikannya', () => {
    const pesan = jelaskanErrorKamera(errorBrowser('NotAllowedError'))

    assert.equal(pesan.code, KAMERA_ERROR.IZIN_DITOLAK)
    assert.match(pesan.text, /izin/i)
    assert.match(
      pesan.text,
      /pengaturan situs|muat ulang/i,
      'Pesan harus menyebut cara memperbaiki, bukan hanya menyebut gejalanya.',
    )
  })

  it('tidak adanya kamera dijelaskan sebagai hal yang bisa dicatat manual', () => {
    const pesan = jelaskanErrorKamera(errorBrowser('NotFoundError'))

    assert.equal(pesan.code, KAMERA_ERROR.TIDAK_ADA_KAMERA)
    assert.match(pesan.text, /manual/i)
  })

  it('kamera yang sedang dipakai aplikasi lain dijelaskan sebagai konflik', () => {
    const pesan = jelaskanErrorKamera(errorBrowser('NotReadableError'))

    assert.equal(pesan.code, KAMERA_ERROR.KAMERA_TERPAKAI)
    assert.match(pesan.text, /dipakai|video call/i)
  })

  it('halaman di luar secure context dijelaskan lewat HTTPS', () => {
    /*
     * `navigator.mediaDevices` yang tidak ada itu TIDAK soal izin kamera --
     * itu soal alamat halaman. Menunjuk user ke pengaturan izin browser
     * untuk masalah ini sama sekali tidak membantu.
     */
    const pesan = jelaskanErrorKamera(
      new TypeError("Cannot read properties of undefined (reading 'getUserMedia')"),
    )

    assert.equal(pesan.code, KAMERA_ERROR.PERLU_HTTPS)
    assert.match(pesan.text, /HTTPS/)
  })

  it('getUserMedia yang tidak ada sama sekali menghasilkan CameraError', async () => {
    lepasKamera()

    await assert.rejects(
      () => bukaKamera(),
      (error) => {
        assert.ok(error instanceof CameraError)
        return true
      },
    )
  })

  it('kode error dari CameraError tidak ditafsirkan ulang', () => {
    const asli = new CameraError(KAMERA_ERROR.KAMERA_TERPAKAI, 'kamera dipakai')

    assert.equal(kodeKameraError(asli), KAMERA_ERROR.KAMERA_TERPAKAI)
  })

  it('matikanKamera menghentikan semua track dan melepas dari video', () => {
    let berhenti = 0
    const stream = {
      getTracks: () => [
        {
          stop() {
            berhenti += 1
          },
        },
      ],
    }
    const video = { srcObject: stream }

    matikanKamera(stream, video)

    assert.equal(berhenti, 1, 'Semua track harus dihentikan.')
    assert.equal(video.srcObject, null, 'Video harus dilepas dari stream.')
  })

  it('matikanKamera aman dipanggil tanpa stream', () => {
    assert.doesNotThrow(() => matikanKamera(null, null))
  })
})
