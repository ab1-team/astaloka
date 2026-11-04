import api from '@/utils/centralAxios'
import {
  CENTRAL_TOKEN_KEY,
  CENTRAL_EXPIRES_KEY,
  CENTRAL_USER_KEY,
  clearCentralSession,
} from '@/utils/centralAxios'

/**
 * Central Service - API untuk Panel Pusat ASTA BRATA.
 *
 * Semua endpoint di sini menuju ke database PUSAT (registry lokasi, user
 * superadmin, tagihan langganan), bukan ke database lokasi mana pun. Jangan
 * menambahkan endpoint operasional di sini.
 *
 * Auth: superadmin. Sesi disimpan di `central_token`, terpisah dari
 * `auth_token` milik lokasi.
 */
export const centralService = {
  /**
   * Ringkasan angka untuk dashboard pusat.
   *
   * `options.silent` dipakai oleh polling latar (`useAutoRefresh`): request
   * sistem tidak boleh menaikkan spinner global, karena bukan ada yang
   * menunggu request itu dan spinner akan berkedip sendiri.
   */
  async getOverview(options = {}) {
    return await api.get('/central/overview', { silentLoading: options.silent })
  },

  /**
   * Agregat untuk dashboard: ringkasan, komposisi kondisi lokasi, komposisi
   * status tagihan, tunggakan per lokasi, jumlah tagihan terlambat per
   * lokasi, dan ringkasan tagihan PER LOKASI (`rincianLokasi`) untuk
   * pop-up detail.
   *
   * Dipakai dashboard alih-alih menjumlahkan sendiri dari `/locations` dan
   * `/invoices`. Alasannya bukan hemat request: `/invoices` dipaginasi 50
   * baris per halaman, jadi menjumlahkannya dari sana hanya menghitung 50
   * tagihan terbaru. Lokasi yang tagihannya jatuh di luar 50 baris itu akan
   * terbaca lunas, dan tidak ada error yang mengatakannya -- grafiknya hanya
   * menampilkan angka yang lebih kecil, tanpa penjelasan.
   *
   * `rincianLokasi` PER LOKASI punya bentuk:
   * ```
   * {
   *   belumLunas: { jumlah, nominal },
   *   terlambat:  { jumlah, nominal },  // subset dari belumLunas
   *   lunas:      { jumlah, nominal },
   *   total:      { jumlah, nominal },  // lunas + belumLunas
   *   terbaru:    [ ...maks 6 tagihan terbaru... ],
   *   usaha, sektor, alamat, email, telepon, domain, database,
   *   database_ada, is_active, tgl_berakhir, slug, label
   * }
   * ```
   * Dibatasi 6 baris `terbaru` yang perlu diingat di sisi pemanggil:
   * daftar itu SAMPL, sedangkan semua angka di dalamnya bukan. Jangan
   * menjumlahkan `terbaru` untuk mendapat total -- total sudah ada di `total`.
   *
   * Tidak ada parameter filter. Endpoint ini menjawab "keadaan sekarang",
   * bukan "riwayat"; penyaringan riwayat ada di halaman tagihan yang
   * sudah bisa difilter sendiri.
   *
   * `options.silent` dengan alasan yang sama seperti `getOverview`.
   */
  async getDashboard(options = {}) {
    return await api.get('/central/dashboard', {
      silentLoading: options.silent,
    })
  },
}

/**
 * CoA Service - Struktur akun level 1-3 milik pusat.
 *
 * Level 1-3 adalah KERANGKA chart of accounts yang sama untuk semua lokasi,
 * jadi disimpan sekali di database pusat. Level 4 (`accounts`) tetap milik
 * masing-masing lokasi -- tiap lokasi bebas menambah akunnya sendiri, dan itu
 * dikelola di aplikasi lokasi, bukan di sini.
 *
 * Create dan delete ADA, tapi tidak tanpa konsekuensi: penghapusan di sini
 * berjenjang ke seluruh turunan di pusat DAN ke akun level 4 di setiap
 * lokasi yang menunjuk baris yang dihapus. Akun level 4 yang sudah punya
 * data keuangan (amount/ebudgeting/transactions) menolak dihapus, dan
 * backend menjawab 409 dengan daftar akun yang menahannya -- supaya yang
 * hilang adalah struktur yang tidak dipakai, bukan pembukuan yang sudah
 * terjadi.
 *
 * `id` dan `parent_id` tetap tidak bisa disunting: keduanya adalah acuan
 * `accounts.parent_id` di setiap database lokasi dan kolom itu tidak punya
 * foreign key yang menegakkannya.
 *
 * Auth: superadmin.
 */
export const coaService = {
  /** Seluruh struktur level 1-3 sekaligus, lengkap dengan bentuk pohonnya. */
  async getAll() {
    return await api.get('/central/coa')
  },

  /** Satu baris untuk diedit. `level` harus 1, 2, atau 3. */
  async getById(level, id) {
    return await api.get(`/central/coa/level/${level}/${id}`)
  },

  /**
   * Tambah satu baris level 1-3.
   *
   * `parentId` wajib untuk level 2 dan 3 -- backend menolak dengan 422 kalau
   * induknya tidak ada, karena akun tanpa induk yang nyata tidak akan pernah
   * muncul di pohon.
   *
   * `jenisMutasi` boleh dikosongkan: kalau tidak diisi, backend mewarisi nilai
   * induk, bukan menebak dari nama akun.
   */
  async create(level, payload) {
    const body = {
      kode_akun: payload.kode_akun,
      nama_akun: payload.nama_akun,
      jenis_mutasi: payload.jenis_mutasi || null,
    }

    if (level > 1) body.parent_id = payload.parent_id
    if (level === 3 && payload.posisi !== null && payload.posisi !== undefined) {
      body.posisi = payload.posisi
    }

    return await api.post(`/central/coa/level/${level}`, body)
  },

  /**
   * Apa yang akan hilang kalau satu baris dihapus, dan apakah itu boleh.
   *
   * Dipanggil sebelum `remove()` untuk mengisi dialog konfirmasi. Dibiarkan
   * terpisah -- bukan digabung ke respons `remove()` -- supaya jumlah yang
   * ditampilkan orang dihitung dari pemindaian yang sama dengan yang
   * dipakai backend untuk benar-benar menghapus. Kalau dialognya menghitung
   * sendiri, angka yang tampil bisa berbeda dari yang benar-benar hilang.
   *
   * `blocked: true` berarti ada akun level 4 yang sudah dipakai; `blockers`
   * berisi akun mana, di lokasi mana, dan kenapa tertahan.
   */
  async getImpact(level, id) {
    return await api.get(`/central/coa/level/${level}/${id}/impact`)
  },

  /**
   * Hapus satu baris beserta seluruh turunannya.
   *
   * `confirm` WAJIB berisi kode akun yang dihapus, persis seperti tertulis
   * di dialog. Backend membandingkannya sendiri, jadi mengosongkan atau
   * mengisi kode yang salah akan ditolak dengan 422 tanpa menghapus apa pun.
   *
   * Melempar pada 409 kalau ada akun turunan yang sudah punya data
   * keuangan. Pesannya sudah menyebut akun mana, di lokasi mana, dan
   * kenapa tertahan -- tampilkan pesan itu apa adanya, jangan diubah
   * jadi kalimat generik.
   */
  async remove(level, id, confirm) {
    return await api.delete(`/central/coa/level/${level}/${id}`, {
      data: { confirm },
    })
  },

  /**
   * Simpan perubahan teks satu baris.
   *
   * Backend membalas `changed: false` kalau nilai yang dikirim sudah sama
   * dengan yang tersimpan. Ini bukan error, tapi layar harus membedakan
   * "tersimpan" dari "tidak ada yang berubah" -- kalau tidak, superadmin
   * mengira datanya sudah ditulis padahal tidak ada satu baris pun yang
   * tersentuh, dan tidak ada jejak audit untuk dicari.
   */
  async update(level, id, payload) {
    return await api.patch(`/central/coa/level/${level}/${id}`, payload)
  },
}

/**
 * Location Service - Registry lokasi (tenant) beserta masanya.
 *
 * Auth: superadmin. Endpoint di bawah `auth:sanctum` + `superadmin`.
 */
export const locationService = {
  /**
   * Daftar lokasi.
   *
   * `options.silent` untuk polling latar -- lihat catatan di `centralService`.
   *
   * Default TIDAK dibalik jadi "diam", dan itu disengaja: pemanggil
   * yang tidak mengirim apa-apa tetap mendapat spinner global. Daftar
   * lokasi adalah hal pertama yang dimuat halaman, jadi spinner memberi
   * tahu sedang terjadi sesuatu; pemanggil yang memang tidak ingin itu
   * menulis `{ silent: true }` secara sadar.
   *
   * Kalau dibalik diam-diam tanpa ada yang menyebutkannya, tidak akan
   * ada request di halaman yang menaikkan `activeRequests`, jadi
   * `uiStore.loading` tidak pernah menjadi true dan spinner global di
   * panel pusat praktis mati -- bentuk kegagalan yang jauh lebih sulit
   * dilihat daripada spinner yang nyala terus.
   */
  async getAll(options = {}) {
    return await api.get('/central/locations', { silentLoading: options.silent })
  },

  /**
   * Daftar sektor yang boleh dipilih untuk sebuah tenant.
   *
   * Diambil dari backend, bukan ditulis ulang di sini. Daftar ini
   * divalidasi backend dengan rule `in:` -- kalau frontend punya daftarnya
   * sendiri, yang berbeda sedikit saja akan membuat form menawarkan pilihan
   * yang selalu ditolak, dan errornya muncul sebagai "422 dari mana saja".
   *
   * Tidak di-cache di modul: dipanggil sekali saat form dibuka. Daftar ini
   * empat baris dan jarang berubah, jadi cache di memori hanya menambah satu
   * state yang harus dihapus kalau someday daftarnya berubah.
   */
  async getSektor() {
    return await api.get('/central/sektor')
  },

  /**
   * Warna branding yang boleh dipilih untuk sebuah lokasi.
   *
   * Alasan dan bentuk responsnya sama persis dengan `getSektor()`: daftar
   * ini milik FORM, dan form harus menawarkan persis apa yang backend terima.
   * Warna yang lolos lewat payload tanpa pernah ada di daftar ini akan jadi
   * warna seluruh aplikasi lokasi, jadi daftar di frontend yang "cuma untuk
   * tampilan" justru menjadi tempat kesalahan berasal.
   *
   * Respons: `[{ hex, label }]`, urutannya sudah sesuai urutan tampil.
   *
   * `silentLoading` wajib di sini. Request ini dikirim dari
   * dalam dialog yang SEDANG terbuka, jadi spinner global yang menyala akan
   * membuat halaman tempat dialog itu berada ikut berganti tampilan -- dan
   * dialog ikut hilang bersamanya. Daftar preset juga tidak mengubah apa pun
   * yang sedang dilihat orang, jadi tidak ada yang perlu diberi tahu.
   */
  async getWarnaBranding() {
    return await api.get('/central/warna', { silentLoading: true })
  },

  async getById(id) {
    return await api.get(`/central/locations/${id}`)
  },

  async create(payload) {
    return await api.post('/central/locations', payload)
  },

  async update(id, payload) {
    return await api.put(`/central/locations/${id}`, payload)
  },

  /**
   * Nonaktifkan lokasi (soft delete). Data di database lokasi TIDAK dihapus.
   *
   * Hanya bisa membuat lokasi NONAKTIF. Untuk mengaktifkan kembali ada
   * `activate()` -- bukan endpoint yang sama, dan itu disengaja: DELETE
   * yang bisa mengembalikan lokasi akan membuat satu request berarti dua
   * hal yang bertentangan.
   */
  async deactivate(id) {
    return await api.delete(`/central/locations/${id}`)
  },

  /**
   * Aktifkan kembali lokasi yang sudah dinonaktifkan.
   *
   * Backend menolak dengan 422 kalau database lokasinya hilang atau
   * domainnya belum ada -- jadi "aktif" yang tidak bisa dilayani tidak
   * akan pernah terjadi. Pesan errornya langsung ditampilkan.
   */
  async activate(id) {
    return await api.patch(`/central/locations/${id}/activate`)
  },

  /**
   * Hapus lokasi PERMANEN, beserta database dan seluruh datanya.
   *
   * Tidak bisa dibatalkan. Backend mewajibkan body berisi `slug` lokasi
   * yang tepat sebagai konfirmasi -- jadi slug diketik ulang supaya salah
   * klik tidak langsung menghapus semua data lokasi.
   *
   * `slug` yang dikirim hanya yang diketik user; `confirm` di pemanggil
   * sudah memanggil dialog konfirmasi, jadi method ini tidak buka dialog
   * apa pun.
   */
  async forceDelete(id, slug) {
    return await api.delete(`/central/locations/${id}/force-delete`, {
      data: { slug },
    })
  },
}

/*
 * Tidak ada `domainService` di file ini lagi.
 *
 * Akses domain lewat panel masih ada, tapi lewat `locationService.update()`
 * -- kolom `domain` dan `domain_alternatif` di form edit tenant disinkronkan
 * backend ke tabel `tenant_domains`. Jadi form yang sudah ada tetap mengelola
 * domain tanpa perlu service terpisah, dan tidak ada tempat lain yang
 * menarik daftar domain lintas lokasi.
 */

/**
 * Central Menu Access Service - Hak akses menu per jabatan, lintas lokasi.
 *
 * BERADA DI SINI BUKAN DI `menuAccess.service.js`, dan itu bukan kebetulan
 * susunan file. `menuAccess.service.js` memakai `@/utils/axios` -- token
 * LOKASI (`auth_token`) dan header `X-Tenant-Slug`, jadi selalu mengarah ke
 * satu subdomain. Endpoint di sini memakai `@/utils/centralAxios` -- token
 * PUSAT (`central_token`), tanpa header lokasi. Menyalin yang satu ke yang
 * lain berarti mengelola seluruh jaringan lewat sesi yang hanya berlaku di
 * satu lokasi; itu persis masalah yang dipindahkan untuk diselesaikan.
 *
 * Auth: superadmin. Data akhirnya ditulis ke database LOKASI (tabel
 * `jabatan_menu_access`), jadi backend menyeberang ke sana per permintaan --
 * tapi pemanggilnya selalu superadmin di database pusat.
 *
 * `{location}` boleh berupa `slug` ATAU `id`; backend menerima keduanya.
 * Yang dikirim halaman ini `id`, karena baris tabel panel pusat sudah
 * memegangnya dan tidak perlu menebak slug lagi.
 */
export const centralMenuAccessService = {
  /**
   * Daftar jabatan + menu yang boleh untuk satu lokasi.
   *
   * Bentuk respons sama dengan `menuAccessService.getAll()` di aplikasi
   * lokasi, jadi komponen yang menampilkan kotak centangnya bisa dipakai
   * ulang tanpa penyesuaian.
   *
   * `silentLoading` dipakai: request ini dikirim dari accordion yang
   * SEDANG terbuka di dalam halaman daftar tenant. Spinner global akan
   * membuat halaman tempat accordion itu berada ikut berganti tampilan --
   * dan accordion-nya ikut hilang bersamanya.
   */
  async getAll(locationId, options = {}) {
    return await api.get(`/central/locations/${locationId}/menu-access`, {
      silentLoading: options.silent !== false,
    })
  },

  /**
   * Simpan daftar menu untuk sebuah jabatan di satu lokasi.
   *
   * `menuKeys` adalah daftar LENGKAP menu yang diizinkan, bukan tambahan.
   * Backend menimpanya utuh, jadi pemanggil harus mengirim semua menu yang
   * dicentang -- bukan hanya yang berubah sejak terakhir disimpan.
   */
  async update(locationId, jabatanId, menuKeys) {
    return await api.put(`/central/locations/${locationId}/menu-access/${jabatanId}`, {
      menu_keys: menuKeys,
    })
  },

  /**
   * Kembalikan jabatan ke default: semua menu boleh.
   *
   * Menghapus barisnya di lokasi itu, jadi jabatan kembali ke kondisi "belum
   * pernah dikonfigurasi". Itu beda dari menyimpan semua centang -- lihat
   * `CentralMenuAccessController::reset()` untuk alasannya.
   */
  async reset(locationId, jabatanId) {
    return await api.post(`/central/locations/${locationId}/menu-access/${jabatanId}/reset`)
  },

  /**
   * Buat jabatan baru di satu lokasi.
   *
   * `POST` ke path yang SAMA dengan `getAll()`, bukan ke path terpisah,
   * karena yang dikelola di halaman ini sebenarnya satu hal: daftar jabatan
   * beserta hak aksesnya. Jabatan yang baru dibuat dikembalikan dengan bentuk
   * yang persis sama seperti hasil `getAll()`, jadi bisa langsung masuk ke
   * daftar tanpa memuat ulang seluruh lokasi -- satu permintaan, bukan dua.
   *
   * `silentLoading` karena dipanggil dari dialog yang sedang terbuka di dalam
   * halaman daftar lokasi; spinner global akan menutupi dialog itu.
   */
  async store(locationId, namaJabatan, options = {}) {
    return await api.post(
      `/central/locations/${locationId}/menu-access`,
      { nama_jabatan: namaJabatan },
      { silentLoading: options.silent !== false },
    )
  },
}

/**
 * Superadmin Service - Kelola user pusat ASTA BRATA.
 *
 * User pusat tidak punya lokasi. Dia hidup di database pusat dengan role
 * `superadmin` dan bekerja pada registry lokasi, bukan pada data operasional
 * lokasi mana pun. Karena itu user lokasi TIDAK pernah muncul di sini, walau
 * tabelnya di kedua tempat sama-sama bernama `users`.
 *
 * Yang TIDAK ada di service ini: `delete`. Hapus berarti barisnya hilang,
 * dan jejak audit lama menunjuk id yang sudah tidak ada artinya. Menonaktifkan
 * mencapai tujuan yang sama tanpa akibat itu -- dan karena backend ikut
 * mencabut token, hasilnya secepat menghapus.
 *
 * Auth: superadmin.
 */
export const superadminService = {
  /**
   * Daftar user pusat.
   *
   * `params`: search, status (active | inactive | all). Filter di level server,
   * bukan di memori -- tidak seperti daftar domain. Alasannya jumlah user
   * pusat bisa membesar (satu per admin!), sementara daftar domain biasanya
   * satu atau dua baris per lokasi. Mencari satu akun di antara ratusan baris
   * dengan filter sisi klien berarti mengunduh semuanya dulu.
   */
  async getAll(params = {}) {
    return await api.get('/central/superadmins', { params })
  },

  /**
   * Tambah user pusat.
   *
   * Password wajib diisi di backend. Form harus mengirim `password` dan
   * `password_confirmation` -- kalau hanya satu, backend membalas 422 dengan
   * pesan yang tidak menyebut field mana yang kurang.
   */
  async create(payload) {
    return await api.post('/central/superadmins', payload)
  },

  /**
   * Ubah nama, email, status aktif, atau password.
   *
   * `password` yang tidak dikirim berarti password tidak berubah. Mengirim
   * string kosong TIDAK berarti kosongkan password -- backend membacanya
   * sebagai "jangan sentuh", karena password kosong adalah password yang
   * semua orang tahu.
   *
   * Backend membalas 422 untuk dua keputusan yang tidak bisa diambil dari
   * frontend: menonaktifkan akun sendiri, dan menonaktifkan user pusat aktif
   * terakhir. Keduanya harus ditampilkan apa adanya -- pesan itu menjelaskan
   * kenapa jalan keluar hanya lewat akses database, dan disembunyikan hanya
   * membuat admin mencoba-coba berulang kali.
   */
  async update(id, payload) {
    return await api.patch(`/central/superadmins/${id}`, payload)
  },
}

/**
 * Invoice Service - Tagihan langganan dari pusat ke lokasi.
 *
 * Auth: superadmin.
 */
export const invoiceService = {
  /**
   * `options.silent` untuk polling latar -- lihat catatan di `centralService`.
   */
  async getAll(params = {}, options = {}) {
    return await api.get('/central/invoices', {
      params,
      silentLoading: options.silent,
    })
  },

  async getById(id) {
    return await api.get(`/central/invoices/${id}`)
  },

  async create(payload) {
    return await api.post('/central/invoices', payload)
  },

  async markPaid(id, payload = {}) {
    return await api.post(`/central/invoices/${id}/paid`, payload)
  },

  async markUnpaid(id) {
    return await api.post(`/central/invoices/${id}/unpaid`)
  },
  /**
   * Cetak invoice: buka pratinjau PDF di tab baru.
   *
   * BUKAN `window.open(pdfUrl)` -- endpoint ini berada di balik
   * `auth:sanctum`, jadi butuh header `Authorization`. `window.open` tidak
   * bisa mengirim header, dan hasilnya 401 dengan tab kosong yang
   * membingungkan.
   *
   * Yang dilakukan di sini: ambil PDF sebagai blob dengan axios (header
   * ikut terbawa), lalu buka object URL di tab baru. Dialog cetak bawaan
   * peramban tetap muncul dari sana -- pratinjau PDF bawaan yang
   * memegang kontrol pencetakan, sehingga hasilnya sama dengan
   * membuka file dari disk.
   *
   * Blob URL dicabut 60 detik setelah tab dibuka. Menahannya selamanya
   * menahan PDF-nya di memori selama tab browser hidup, dan setiap
   * pencetakan menambah satu salinan.
   *
   * ===== BUKAN DILEWATI, TAPI DIPERBAIKI =====
   *
   * "Popup diblokir peramban" muncul karena `window.open` dipanggil SETELAH
   * `await api.get(...)` selesai. Browser hanya mengizinkan popup yang
   * dibuka di dalam gestur pengguna -- yaitu di dalam handler klik. Setiap
   * `await` mengubah konteks itu: begitu request selesai, peramban sudah
   * tidak menganggap panggilan ini berasal dari klik.
   *
   * Bentuk lama:
   *
   *     const res = await api.get(...)     // konteks klik hilang di sini
   *     window.open(blobUrl, '_blank')      // sudah tidak di dalam klik
   *
   * Perbaikannya: tab DIBUKA DULUAN, secara sinkron, di dalam gestur klik.
   * Baru setelah PDF-nya siap, tab itu yang diarahkan ke object URL.
   *
   * Popup TIDAK bisa dihilangkan sepenuhnya: kalau pengguna sendiri memilih
   * untuk memblokir popup di pengaturan peramban, halaman ini tidak punya
   * cara mengoverride itu. Yang hilang adalah bagian yang SEBENARNYA
   * tidak perlu izin -- jeda akibat `await` yang tidak ada hubungannya
   * dengan kebijakan popup.
   */
  async openPdf(id) {
    /*
     * Dibuka di sini, SEBELUM `await` pertama.
     *
     * Baris ini masih di dalam gestur klik, jadi peramban mengizinkan
     * popupnya tanpa perlu pengaturan tambahan. Tabnya masih kosong --
     * isinya diisi setelah PDF-nya tiba.
     */
    const tab = window.open('', '_blank')

    if (!tab) {
      /*
       * Diblokir popup. Beri tahu, jangan diam-diam gagal.
       *
       * Dua kemungkinan: pengaturan peramban memblokir popup untuk situs
       * ini, atau tab-nya ditutup sebelum sempat terisi. Keduanya menuntut
       * tindakan yang sama dari user, jadi pesannya dibuat satu.
       */
      throw new Error(
        'Popup diblokir peramban. Izinkan popup untuk situs ini agar invoice bisa dicetak.',
      )
    }

    /*
     * Tanpa ini, tab yang baru terbuka menampilkan halaman kosong --
     * dan kalau requestnya ternyata lambat, orang mengira aplikasinya
     * macet. Ditulis supaya ada penjelasan singkat selama PDF-nya diunduh.
     */
    try {
      tab.document.write(
        '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8">' +
          '<title>Menyiapkan invoice...</title></head>' +
          '<body style="font-family:system-ui,sans-serif;color:#475569;' +
          'display:flex;align-items:center;justify-content:center;height:100vh;' +
          'margin:0">Menyiapkan invoice untuk dicetak...</body></html>',
      )
    } catch {
      // Tab sudah ditutup user. Request tetap jalan; hasilnya dibuang.
    }

    try {
      // `responseType: 'blob'` wajib: tanpa itu axios memparsing PDF
      // sebagai teks dan hasilnya rusak.
      const res = await api.get(`/central/invoices/${id}/pdf`, {
        responseType: 'blob',
      })

      const blobUrl = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }))

      /*
       * Tab-nya sudah ada, jadi ini hanya MEMINDAHKAN ke PDF -- bukan
       * membuka popup baru. Tidak ada gestur yang dibutuhkan di sini.
       */
      tab.location.href = blobUrl

      setTimeout(() => URL.revokeObjectURL(blobUrl), 60000)

      return res
    } catch (error) {
      // Gagal: jangan biarkan tab kosong terbuka menggantung.
      try {
        tab.close()
      } catch {
        // Tab sudah tertutup.
      }

      throw error
    }
  },
}

/**
 * Rekening Service - Rekening penampung pembayaran tagihan ASTA BRATA.
 *
 * Berdiri sendiri, bukan bagian dari `coaService`. `coaService` mengelola
 * `akun_level_1..3` yang itu kerangka milik SEMUA lokasi dan tidak boleh
 * ditambah dari panel; yang di sini rekening milik pusat yang dipakai
 * benar-benar untuk menerima uang.
 *
 * Yang dipakai dari sisi panel pusat HANYA `getAll()`, untuk mengisi
 * pilihan kas atau rekening tujuan di form pembayaran. `create`,
 * `update`, dan `getMutasi` tidak lagi punya pemanggil di frontend --
 * tidak ada halaman rekening di panel, daftarnya dikelola langsung di
 * database. Method-nya tetap dibiarkan karena endpointnya masih hidup
 * dan pemanggil berikutnya bisa jadi butuh (mis. penyesuaian rekening
 * tanpa membuka database).
 *
 * TIDAK ada `remove`. Baris yang pernah dipakai pembayaran menunjuk
 * ke sini, jadi menghapusnya membuat riwayat pembayaran menunjuk
 * rekening yang tidak ada. `update(id, { is_active: false })`
 * menyembunyikan rekening dari form tanpa merusak riwayat.
 *
 * Auth: superadmin.
 */
export const rekeningService = {
  /**
   * Daftar rekening beserta saldo masuk dan jumlah pembayaran.
   *
   * Tanpa argumen: hanya yang aktif. Itu yang dipakai form pembayaran --
   * menampilkan rekening nonaktif di sana membuat orang memilih
   * rekening yang sudah tidak dipakai untuk apa pun.
   *
   * `{ all: true }` untuk halaman daftar, yang perlu melihat yang
   * nonaktif supaya bisa mengaktifkannya lagi.
   *
   * `saldo` dihitung server dari `tenant_invoice_payments`, bukan
   * disimpan sebagai kolom. Jadi angka ini selalu benar walaupun ada
   * pembayaran yang dibatalkan dari luar aplikasi.
   */
  async getAll(params = {}) {
    return await api.get('/central/rekening', { params })
  },

  async create(payload) {
    return await api.post('/central/rekening', payload)
  },

  /**
   * Ubah rekening, termasuk `is_active` untuk menonaktifkan.
   *
   * Backend membalas `changed: false` kalau nilainya sudah sama --
   * bukan error, tapi layar harus membedakan "tersimpan" dari
   * "tidak ada yang berubah". Lihat catatan di `coaService.update()`.
   */
  async update(id, payload) {
    return await api.patch(`/central/rekening/${id}`, payload)
  },

  /** Mutasi satu rekening: pembayaran yang masuk lewat rekening ini. */
  async getMutasi(id, params = {}) {
    return await api.get(`/central/rekening/${id}/mutasi`, { params })
  },
}

/**
 * Public Location Service - Endpoint PUBLIK tanpa login.
 *
 * Hanya mengembalikan nama + slug (dipakai halaman depan untuk dropdown
 * lokasi). Jangan dipakai di panel pusat -- datanya tidak lengkap.
 */
export const publicLocationService = {
  async getAll() {
    return await api.get('/central/lokasi')
  },
}

/**
 * Migration Service - Memindahkan data ke database SATU lokasi.
 *
 * TIGA LANGKAH, DAN MENGAPA TIDAK SATU
 * ====================================
 *
 *   1. `template(location, type, format)` -> unduh file kosong + referensi lokasi
 *   2. `validate(location, type, file)`   -> periksa tiap baris, TIDAK menulis
 *   3. `commit(location, type, hash)`     -> tulis yang tadi lolos
 *
 * Langkah 2 dan 3 sengaja dipisah. Kalau penulisan terjadi sambil
 * memvalidasi, satu baris yang gagal di baris 900 membuat pemanggil tidak
 * tahu apakah 899 baris sebelumnya sudah tersimpan -- sehingga "gagal"
 * tidak bisa dibedakan dari "berhasil sebagian", dan yang kedua terlihat
 * berhasil di notifikasi.
 *
 * YANG DIBAWA DI PATH, BUKAN DI BODY
 * ==================================
 *
 * `{location}` dan `{type}` ada di URL setiap endpoint, bukan di body.
 * Alasannya bukan gaya: karena satu lokasi = satu database, "file ini
 * masuk ke mana" harus bisa dibaca dari log akses tanpa melihat isi
 * request. Kalau lokasi ada di body, pertanyaan "tagihan lokasi mana yang
 * berasal dari impor mana?" tidak punya jawaban yang bisa dicari.
 *
 * `hash` dari langkah 2 disesuaikan backend dengan lokasi dan jenis datanya.
 * Kalau tidak cocok, `commit()` menolak -- jadi meng-commit file ke lokasi
 * yang salah tidak mungkin terjadi walau langkah 2-nya untuk lokasi lain.
 *
 * Auth: superadmin.
 */
export const centralMigrationService = {
  /**
   * Daftar jenis data yang bisa dimigrasi, beserta batas ukuran file.
   *
   * Tanpa lokasi: daftar ini sama untuk semua lokasi dan tidak menyentuh
   * database lokasi sama sekali, jadi tidak ada alasan menahannya di balik
   * pemilihan lokasi. `batas.ekstensi` yang dikembalikan di sini adalah
   * sumber kebenaran untuk pesan di sisi klien -- menulis daftar yang sama
   * di dua tempat berarti daftar itu bisa berbeda dari yang benar-benar
   * diterima server, dan file ditolak dengan pesan yang tidak menjelaskan
   * kenapa.
   */
  async getIndex(options = {}) {
    return await api.get('/central/migrasi-data', {
      silentLoading: options.silent !== false,
    })
  },

  /**
   * Langkah 2: baca file, periksa tiap baris, TIDAK menulis apa pun.
   *
   * onProgress dipakai untuk mengukur how far UNDUHAN file berlangsung
   * -- yang itu memang punya persentase karena berasal dari browser, bukan
   * dari server. Yang TIDAK ada di sini adalah progress server: backend
   * memvalidasi seluruh file dalam satu request dan tidak bisa
   * melaporkan-naik di tengah. Lihat catatan di `commit()`.
   */
  async validate(location, type, file, onProgress) {
    const bentuk = new FormData()
    bentuk.append('file', file)

    return await api.post(
      `/central/migrasi-data/${encodeURIComponent(location)}/${encodeURIComponent(type)}/validasi`,
      bentuk,
      {
        /*
         * Content-Type TIDAK disetel manual.
         *
         * Instance axios sudah menyetel `application/json` sebagai
         * default. Untuk `FormData` itu merusak: browser harus menulis
         * sendiri `multipart/form-data; boundary=...`, dan boundary itu
         * hanya dia yang tahu. Kalau dipaksakan, server menerima body
         * yang batasnya tidak terbaca dan jawabannya "file tidak ada"
         * -- padahal file-nya ada.
         */
        headers: { 'Content-Type': undefined },
        timeout: 0,
        silentLoading: true,
        onUploadProgress: (event) => {
          if (onProgress && event.total) {
            onProgress(Math.round((event.loaded / event.total) * 100))
          }
        },
      },
    )
  },

  /**
   * Langkah 3: tulis baris yang tadi lolos validasi.
   *
   * `timeout: 0` -- BATAS TIDAK ADA, dengan alasan yang perlu dibaca
   * sebelum dihapus.
   *
   * Batas bawaan instance pusat adalah 180 detik, yang itu sudah
   * longgar untuk daftar lokasi. Menulis 5.000 baris ke lima database
   * berbeda bisa memakan waktu lebih dari itu, dan memotong request di
   * tengah commit menghasilkan keadaan yang tidak ada yang bisa
   * diperbaiki dari sisi peramban: browser melaporkan gagal, padahal
   * baris pertamanya sudah tersimpan dan tidak ada yang tahu seberapa
   * jauh. Ambil risikonya di sisi server (batas waktu proses), bukan
   * di sisi user.
   *
   * Yang TIDAK ada di sini adalah progress penulisan. Backend memproses
   * seluruh file dalam satu request dan tidak bisa melaporkan-naik di
   * tengah -- respons yang sudah ter-buffer tidak bisa di-flush ke
   * peramban. Persentase yang bergerak padahal tidak ada proses yang
   * bergerak lebih merusak daripada spinner, karena user menunggu
   * sesuatu yang tidak pernah datang.
   */
  async commit(location, type, hash) {
    return await api.post(
      `/central/migrasi-data/${encodeURIComponent(location)}/${encodeURIComponent(type)}/commit`,
      { hash },
      { timeout: 0, silentLoading: true },
    )
  },

  /**
   * Unduh template .xlsx / .csv untuk satu jenis data di satu lokasi.
   *
   * Diunduh lewat XHR (bukan `<a href>`) supaya header `Authorization`
   * ikut terbawa. Token tidak pernah masuk ke query string, dan URL
   * unduhan tetap aman kalau Appearance merujuk riwayat browser.
   *
   * `responseType: 'blob'` wajib: tanpa itu axios memperlakukan .xlsx
   * sebagai teks, dan file yang tersimpan rusak tapi tetap bernama .xlsx
   * -- yang baru ketahuan saat dibuka di Excel.
   */
  async downloadTemplate(location, type, format = 'xlsx') {
    const res = await api.get(
      `/central/migrasi-data/${encodeURIComponent(location)}/${encodeURIComponent(type)}/template`,
      { params: { format }, responseType: 'blob', timeout: 0 },
    )

    const tipe =
      format === 'csv'
        ? 'text/csv;charset=utf-8'
        : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

    this.simpanBerkas(res.data, this.namaFile(res, `template-${type}.${format}`), tipe)

    return true
  },

  /**
   * Unduh daftar baris yang gagal, untuk dikirim ke whoever mengisi file.
   *
   * `detail` adalah array hasil `validate()` atau `commit()` -- bentuknya
   * sudah sama dengan yang diminta backend (`nomor`, `pesan`, `kolom`),
   * jadi pemanggil cukup meneruskannya apa adanya tanpa memetakan ulang.
   */
  async downloadLogGagal(location, type, detail) {
    const res = await api.post(
      `/central/migrasi-data/${encodeURIComponent(location)}/${encodeURIComponent(type)}/log-gagal`,
      { detail },
      { responseType: 'blob' },
    )

    const nama = this.namaFile(res, `gagal-migrasi-${type}.csv`)

    this.simpanBerkas(res.data, nama, 'text/csv;charset=utf-8')

    return nama
  },

  /**
   * Nama file dari header `Content-Disposition`, atau tebakan yang benar.
   *
   * Header itu berbentuk `filename*=UTF-8''nama.xlsx` (RFC 5987), karena
   * nama file dari server memuat nama jenis data yang bisa mengandung
   * spasi. Bentuk `filename="..."` yang lama tetap diperiksa lebih dulu
   * karena tidak semua server mengirimnya.
   */
  namaFile(res, cadangan) {
    const header = res.headers?.['content-disposition'] || ''

    const bentukbaru = /filename\*=UTF-8''([^;]+)/i.exec(header)
    const bentuklama = /filename="?([^";]+)"?/i.exec(header)

    return decodeURIComponent(
      (bentukbaru && bentukbaru[1]) || (bentuklama && bentuklama[1]) || cadangan,
    )
  },

  /**
   * Simpan blob ke disk lewat peramban.
   *
   * Object URL-nya dicabut 10 detik setelah diunduh, bukan selamanya:
   * menahannya berarti setiap unduhan menahan salinan file di memori
   * sampai tab ditutup, dan migrasi bisa mengunduh template untuk lima
   * jenis data dalam satu sesi.
   */
  simpanBerkas(blob, nama, tipe) {
    const url = URL.createObjectURL(new Blob([blob], { type: tipe }))
    const tautan = document.createElement('a')

    tautan.href = url
    tautan.download = nama
    document.body.appendChild(tautan)
    tautan.click()
    tautan.remove()

    setTimeout(() => URL.revokeObjectURL(url), 10000)
  },
}

/**
 * Central Auth Service - Login/logout superadmin.
 *
 * PASANGAN DENGAN utils/centralAxios.js: token disimpan di `central_token`,
 * bukan `auth_token`. Keduanya hidup di tabel `personal_access_tokens` yang
 * sama-sama ada di database pusat DAN di setiap database lokasi dengan id
 * autoincrement yang sama, jadi bila keduanya berbagi key, sesi di satu sisi
 * bisa menimpa sesi sisi lain.
 */
/**
 * Masa berlaku sesi panel pusat di sisi peramban.
 *
 * Yang dicatat di sini murni keputusan antarmuka: token Sanctum yang
 * diterbitkan
 * server tidak punya masa berlaku, jadi `centralAxios` yang memutuskan kapan
 * memaksa login ulang. Tanpa centang "ingat saya" layar ini akan meminta
 * kata sandi lagi setelah tiap pergantian shift.
 */
const SESSION_SHORT_MS = 8 * 60 * 60 * 1000 // 8 jam
const SESSION_REMEMBER_MS = 30 * 24 * 60 * 60 * 1000 // 30 hari

export const centralAuthService = {
  /**
   * @param credentials {{email: string, password: string}}
   * @param options   {{remember?: boolean}} -- memengaruhi HANYA penanda
   *   kedaluwarsa lokal. Backend tidak menerima dan tidak perlu field baru:
   *   token yang dia terbitkan tetap sama tanpa masa berlaku.
   */
  async login(credentials, options = {}) {
    const response = await api.post('/central/login', credentials)
    const body = response.data

    if (body?.success && body?.data?.token) {
      const ttl = options.remember ? SESSION_REMEMBER_MS : SESSION_SHORT_MS

      localStorage.setItem(CENTRAL_TOKEN_KEY, body.data.token)
      localStorage.setItem(CENTRAL_EXPIRES_KEY, (Date.now() + ttl).toString())
      localStorage.setItem(CENTRAL_USER_KEY, JSON.stringify(body.data.user))
    }

    return body
  },

  async logout() {
    try {
      return await api.post('/central/logout')
    } finally {
      // Sesi lokal dibersihkan apa pun hasil server. Kalau request gagal
      // (token sudah kedaluwarsa, jaringan putus), user TETAP harus keluar
      // dari panel -- menahan dia di sini karena server tidak merespons
      // justru meninggalkan sesi half-login.
      clearCentralSession()
    }
  },
}

export default centralService
