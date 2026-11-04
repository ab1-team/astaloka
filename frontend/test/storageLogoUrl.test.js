import { strict as assert } from 'node:assert'
import { describe, it } from 'node:test'

/*
 * Harness diimpor paling awal supaya loader SFC-nya aktif.
 *
 * `src/utils/storage.js` mengimpor `@/utils/apiBase` -- alias yang hanya
 * dipahami bundler. Tanpa loader Node, impor itu gagal dengan
 * `Cannot find package '@/utils'`, dan gejalanya menunjuk ke paket yang
 * tidak ada, bukan ke alias yang belum dipetakan.
 *
 * Impor modul Storage sendiri harus DINAMIS, bukan statis. ESM me-link
 * seluruh import statis SEBELUM body modul apa pun dieksekusi -- jadi
 * `register()` yang dipasang di dalam `vueHarness.mjs` baru aktif setelah
 * `storage.js` sudah gagal resolve. Impor dinamis menunggu loader siap.
 */
await import('./vueHarness.mjs')

const { storageLogoUrl, storageUrl } = await import('../src/utils/storage.js')

/**
 * URL logo harus menunjuk ke tempat file benar-benar dilayani.
 *
 * Yang diuji di sini adalah bug yang sudah MENYEBABKAN logo tidak pernah
 * muncul: `settings.logo` berisi NAMA FILE saja (mis. `logo-abc.jpg`), dan
 * file-nya dilayani lewat symlink `public/storage`, jadi URL yang benar
 * adalah `/storage/sop/logo/<nama-file>`.
 *
 * Beberapa komponen pernah membentuknya sendiri sebagai
 * `${storageUrl('')}/sop/logo/${logo}` -- TANPA segmen `storage`. Hasilnya
 * `https://host/sop/logo/file.jpg` yang 404, dan gejalanya "logo kosong
 * tanpa pesan error" karena `<img>` yang gagal dimuat tidak melempar.
 *
 * Jalankan: `node --test frontend/test/`
 */
describe('storageLogoUrl', () => {
  it('nama file saja jadi path storage/sop/logo', () => {
    const url = storageLogoUrl('logo-abc.jpg')

    assert.ok(url.includes('/storage/sop/logo/logo-abc.jpg'), `url: ${url}`)
  })

  it('path storage yang sudah utuh tidak jadi dobel prefix', () => {
    const base = storageUrl('')

    assert.equal(storageLogoUrl('storage/sop/logo/a.jpg'), `${base}/storage/sop/logo/a.jpg`)
    assert.equal(storageLogoUrl('/storage/sop/logo/a.jpg'), `${base}/storage/sop/logo/a.jpg`)
  })

  it('path yang sudah memuat folder logo tidak dikali dua', () => {
    const base = storageUrl('')

    assert.equal(storageLogoUrl('/sop/logo/a.jpg'), `${base}/sop/logo/a.jpg`)
  })

  it('URL lengkap dan data URI dikembalikan apa adanya', () => {
    assert.equal(
      storageLogoUrl('https://cdn.example.com/logo.png'),
      'https://cdn.example.com/logo.png',
    )
    assert.equal(
      storageLogoUrl('http://cdn.example.com/logo.png'),
      'http://cdn.example.com/logo.png',
    )
    assert.equal(storageLogoUrl('data:image/png;base64,AAA'), 'data:image/png;base64,AAA')
  })

  it('nilai kosong menghasilkan string kosong, bukan origin', () => {
    // String kosong dipakai untuk menentukan "tidak ada logo", dan
    // `<img src="">` akan memuat dokumen halaman itu sendiri.
    assert.equal(storageLogoUrl(''), '')
    assert.equal(storageLogoUrl(null), '')
    assert.equal(storageLogoUrl(undefined), '')
    assert.equal(storageLogoUrl('   '), '')
  })
})
