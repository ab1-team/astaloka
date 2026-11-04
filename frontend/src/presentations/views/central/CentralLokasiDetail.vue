<template>
  <div class="detail-page">
    <!--
         ===== Baris kendali =====

         Satu baris, dua sisi: kembali di kiri, aksi di kanan.

         "Kembali" sekarang `<button>`, bukan `<router-link>`. Bukan karena
         tautan tidak bisa dipakai di sini -- bisa -- tapi karena halaman ini
         punya tempat untuk tombol, dan tautan yang terlihat seperti teks
         bukan tempat untuk tombol. Yang diperbeda bukan mekanismenya:
         `<router-link>` tetap bisa dipakai, tapi ia menandai tujuan, bukan
         tindakan. "Kembali ke Daftar Tenant" berdiri di baris tombol bersama
         dua aksi lain, jadi harus terbaca sebagai bagian dari kelompok itu.

         Yang benar-benar berubah: ketiganya sekarang satu kelompok visual.
         Sebelumnya "Kembali" berdiri sendiri di paling atas halaman dan dua
         aksi lain jauh di kanan header kartu -- jadi mata harus melompat
         antara dua ujung halaman untuk tahu apa saja yang bisa dilakukan di
         sini.
    -->
    <header class="detail-bar">
      <button type="button" class="detail-bar__back" @click="goBack">
        <font-awesome-icon icon="arrow-left" />
        <span>Kembali ke Daftar Tenant</span>
      </button>

      <div v-if="lokasi" class="detail-actions">
        <router-link
          :to="{ name: 'central-lokasi-edit', params: { id: lokasi.id } }"
          class="btn btn--primary"
        >
          <font-awesome-icon icon="pen" />
          Ubah
        </router-link>

        <button type="button" class="btn btn--danger" @click="handleToggleActive">
          <font-awesome-icon :icon="lokasi.is_active ? 'ban' : 'rotate-left'" />
          {{ lokasi.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
        </button>

        <!--
          Hapus permanen.

          Letakkan di baris kendali ini, bukan di dalam salah satu panel
          di bawahnya: ini satu-satunya aksi di halaman ini yang menghapus
          database beserta seluruh datanya, dan ia harus terlihat jelas di
          tempat yang sama dengan aksi-aksi lain terhadap lokasi ini --
          bukan tersembunyi di dalam panel tagihan atau di bawah lipatan.

          Ikon `trash-can` (bukan `trash`) karena `trash` di panel ini sudah
          berarti "hapus tagihan" dan "hapus domain". Orang yang sudah
          terbiasa menekan `trash` harus melihat bentuk yang berbeda sebelum
          menekan tombol yang menghapus semuanya.
        -->
        <button
          type="button"
          class="btn btn--danger"
          :disabled="deleting"
          @click="handleForceDelete"
        >
          <font-awesome-icon :icon="deleting ? 'spinner' : 'trash-can'" :spin="deleting" />
          {{ deleting ? 'Menghapus...' : 'Hapus Permanen' }}
        </button>
      </div>
    </header>

    <div v-if="loading" class="panel panel--state">
      <font-awesome-icon icon="spinner" spin />
      <span>Memuat detail tenant...</span>
    </div>

    <!--
         ===== badan: identitas di kiri, isi di kanan =====

         Rilisan (rail) kiri sengaja `sticky`. Identitas tenant adalah
         konteks untuk semua yang ada di sebelah kanannya -- nama, status,
         database, tagihan -- dan konteks itu tetap dibutuhkan saat admin
         menggulir ke riwayat tagihan paling bawah. Di tata letak lama,
         kartu identitas berada di atas dan ikut ter-scroll: begitu mata
         turun ke tagihan, tidak ada lagi yang pemberi tahu baris ini
         milik tenant yang mana.
    -->
    <div v-else-if="lokasi" class="detail-body">
      <!--
        Kolom kiri = KARTU IDENTITAS + TAGIHAN, dan keduanya dalam satu
        kelompok yang menempel.

        Semula hanya kartu identitas yang ada di kolom ini, jadi kolom
        kiri berhenti di baris "Tagihan" sementara kolom kanan masih
        berjalan melewati lima panel -- dan seluruh ruang di bawahnya
        terisi abu. Yang kosong bukan satu panel yang belum selesai, tapi
        satu kolom yang isinya habis jauh lebih cepat daripada kolom di
        sebelahnya.

        Dua kartu ini sekarang satu unit yang menempel (`position:
        sticky` di `.detail-side`, bukan di `.rail`). Menempel per kartu
        justru merusak yang baru diperbaiki: kartu tagihan akan menggulir
        ke atas dan menghilang di belakang kartu identitas yang sedang
        menempel, tepat saat orang sedang membacanya.

        Isi tagihan yang tampil bukan tabel, melainkan daftar ringkas.
        Daftar lengkapnya tetap di halaman Tagihan yang sudah punya filter,
        paginasi, dan angka tunggakan seluruh panel -- daftar yang
        dibuat ulang di sini tanpa paginasi hanya jadi tempat kedua yang
        harus sama-sama benar, dan tempat kedua biasanya tidak ikut
        diperbarui.
      -->
      <div class="detail-side">
        <aside class="rail">
          <div class="rail__head">
            <span class="rail__mark">
              <font-awesome-icon icon="location-dot" />
            </span>
            <h2 class="rail__name">{{ lokasi.label }}</h2>
            <p class="rail__slug">{{ lokasi.slug }}</p>

            <span
              class="status-pill rail__status"
              :class="lokasi.is_active ? 'status-pill--on' : 'status-pill--off'"
            >
              {{ lokasi.is_active ? 'Aktif' : 'Nonaktif' }}
            </span>
          </div>

          <!--
          Baris "Tagihan" yang pernah ada di sini dihapus, bukan dipindah ke
          bawah.

          Kartu tagihan sekarang duduk tepat di bawah rail ini, di kolom yang
          sama, jadi dua-duanya akan terlihat bersamaan. Angka "0 belum lunas
          / Lunas" yang ditulis di sini akan muncul dua kali dalam jarak satu
          kartu -- dan dua angka yang sama di satu layar bukan pengulangan
          yang membantu: begitu keduanya tidak sinkron, orang tidak tahu
          yang mana yang benar.
        -->
          <dl class="rail__facts">
            <div class="rail__fact">
              <dt>Sektor</dt>
              <dd>{{ lokasi.sektor || '—' }}</dd>
            </div>

            <div class="rail__fact">
              <dt>Berlaku Sampai</dt>
              <dd>{{ lokasi.tgl_berakhir || 'Tanpa batas' }}</dd>
            </div>
          </dl>
        </aside>

        <!-- ===== Tagihan =====
          Kartu kedua di kolom kiri, bukan panel kelima di kolom kanan.

          Yang ditampilkan tiga hal dalam urutan yang dipakai saat membaca:
          oweannya dulu (berapa yang harus dibayar), baru tagihan terakhirnya
          (kapan), baru aksinya. Ringkasan tanpa daftar membuat "menunggak
          sejak kapan" harus dijawab dengan pindah halaman; daftar tanpa
          ringkasan membuat orang menjumlahkan nominalnya sendiri sebelum
          tahu apakah itu masalah.

          Daftar ini DIBATAS dan disengaja.

          Yang dimuat adalah `invoiceTerbaru` -- lima tagihan terakhir lokasi
          ini, tanpa paginasi dan tanpa filter. Daftar lengkap tetap milik
          halaman Tagihan, yang punya paginasi server dan angka tunggakan
          seluruh panel. Daftar tanpa paginasi di sini hanya akan menjadi
          tempat kedua untuk data yang sama, dan tempat kedua biasanya tidak
          ikut diperbarui -- bukan karena lupa, tapi karena tidak ada yang
          menyeret orang ke sana.

          Karena itu daftar di sini tidak mencoba menghitung tunggakan dari
          dirinya sendiri. Baris "N belum lunas" dibaca dari angka server
          (`ringkasan`), bukan dari `invoiceTerbaru` yang isinya sudah
          dipotong lima -- kalau dihitung dari sana, angkanya benar selama
          tagihan lokasi ini masih di bawah lima dan diam-diam salah setelah
          itu, tanpa error yang mengatakannya. Ini persis sumber desync yang
          sudah pernah dihapus di halaman Tagihan.
        -->
        <section class="tagihan-card-panel">
          <header class="tagihan-card-panel__head">
            <h3>Tagihan</h3>
            <span v-if="!invoiceLoading" class="tagihan-card-panel__count">
              {{ tagihanRingkas.jumlah }} tagihan
            </span>
          </header>

          <!--
            Satu blok untuk status, bukan dua angka terpisah.

            "2 belum lunas" dan "Rp 3.000.000" adalah satu fakta dengan dua
            bagian. Ditulis sebagai dua sel terpisah, keduanya bisa tampil
            dengan gaya berbeda dan mata harus menyusunnya jadi satu
            kalimat. Dalam satu blok, "boleh dibaca tanpa berpikir".
          -->
          <div class="tagihan-sum" :class="tagihanTunggakan ? 'is-bad' : 'is-ok'">
            <p class="tagihan-sum__label">
              <font-awesome-icon
                :icon="tagihanTunggakan ? 'triangle-exclamation' : 'circle-check'"
              />
              {{
                tagihanRingkas.jumlah === 0
                  ? 'Belum ada tagihan'
                  : tagihanTunggakan
                    ? 'Perlu ditagih'
                    : 'Semua tagihan lunas'
              }}
            </p>

            <!--
              `tagihanTunggakan` sengaja tidak pernah tampil sebagai
              "0 belum lunas".

              Angka nol dengan satuan rupiah ("Rp 0 belum lunas") dibaca
              sebagai berita, bukan sebagai ketiadaan. Kalau tidak ada
              tunggakan, kartu ini berhenti di kalimat "Semua tagihan
              lunas" -- dan tidak menampilkan total yang sudah dibayar.

              Total terbayar sengaja TIDAK dihitung di sini. Sumbernya
              hanya lima tagihan terakhir, jadi jumlahnya benar selama
              tagihan lunas lokasi ini banyak dan diam-diam salah
              setelahnya. Backend hanya mengirim `belumLunas` dan
              `totalTunggakan`; menambah kolom "sudah dibayar" berarti
              mengubah backend hanya untuk mengisi satu baris yang
              bonusnya tidak sebanding -- dan angkanya akan terlihat
              di tempat yang tidak punya tempat memverifikasinya.
            -->
            <template v-if="tagihanTunggakan">
              <p class="tagihan-sum__value">{{ tagihanRingkas.belumLunas }} belum lunas</p>
              <p class="tagihan-sum__amount">{{ formatRupiah(tagihanRingkas.totalTunggakan) }}</p>
            </template>
          </div>

          <p v-if="invoiceLoading" class="tagihan-card-panel__empty">
            <font-awesome-icon icon="spinner" spin />
            Memuat tagihan...
          </p>

          <!--
            Daftar terakhir, bukan tabel.

            Tiga baris per tagihan -- nomor dan tanggal, nominal, status --
            tanpa kepala kolom sama sekali. Tabel di kolom selebar 19rem
            memaksa nama kolom jadi dua baris dan nominal jadi terpotong,
            sedangkan tiap baris daftar punya lebar penuh untuk isinya.
          -->
          <ul v-else-if="invoiceTerbaru.length" class="tagihan-mini">
            <li v-for="inv in invoiceTerbaru" :key="inv.id">
              <!--
                Baris ini tautan ke daftar lengkap, bukan ke dialog detail.

                Ada `CentralTagihanPopup` untuk membuka satu tagihan dari
                halaman Tagihan, dan godaan untuk memakainya di sini besar.
                Tapi popup itu milik halaman Tagihan: ia menerima tagihan
                dari daftar yang difilter dan difokusasi di sana. Dari
                halaman ini, tagihannya tidak sedang dibuka -- yang sedang
                dibuka adalah satu tenant -- dan mengunci layar dengan popup
                yang isinya bisa dilihat dari satu klik di sebelah kiri
                tidak menambah informasi, hanya menambah lapisan.

                Jadi barisnya mengarah ke tempat yang punya isi: daftar
                tagihan lengkap milik lokasi ini.
              -->
              <router-link
                :to="{ name: 'central-tagihan', query: { tenant_id: lokasi.id } }"
                class="tagihan-mini__row"
              >
                <span class="tagihan-mini__ident">
                  <span class="tagihan-mini__nomor">{{ inv.nomor }}</span>
                  <span class="tagihan-mini__tanggal">{{ inv.tgl_invoice || '—' }}</span>
                </span>

                <span class="tagihan-mini__amount">{{ formatRupiah(inv.jumlah) }}</span>

                <span
                  class="tagihan-mini__status"
                  :class="
                    inv.status === 'PAID' ? 'is-lunas' : inv.terlambat ? 'is-lambat' : 'is-belum'
                  "
                >
                  {{
                    inv.status === 'PAID' ? 'Lunas' : inv.terlambat ? 'Terlambat' : 'Belum Lunas'
                  }}
                </span>
              </router-link>
            </li>
          </ul>

          <p v-else class="tagihan-card-panel__empty">
            <font-awesome-icon icon="receipt" />
            Belum ada tagihan untuk lokasi ini.
          </p>

          <!--
            Dua tombol selebar kartu, aksi di atas dan yang di bawah.

            Urutannya mengikuti urutannya membaca: lihat dulu kondisinya,
            baru putuskan. "Riwayat Tagihan" di atas karena itu tombol yang
            lebih sering ditekan -- sebagian besar tagihan sudah terbit,
            jadi yang paling sering dibutuhkan adalah MEMBACA, bukan
            menerbitkan.

            Lebar penuh, bukan `btn--sm` berdampingan. Di kolom 19rem dua
            tombol "Terbitkan Tagihan" dan "Riwayat Tagihan" dalam satu baris
            harus perkecil teksnya supaya muat -- dan teks yang diperkecil
            supaya muat adalah teks yang berhenti terbaca.
          -->
          <div class="tagihan-card-panel__aksi">
            <router-link
              :to="{ name: 'central-tagihan', query: { tenant_id: lokasi.id } }"
              class="btn btn--ghost btn--block"
            >
              <font-awesome-icon icon="receipt" />
              Riwayat Tagihan
            </router-link>

            <button type="button" class="btn btn--save btn--block" @click="showInvoiceModal = true">
              <font-awesome-icon icon="plus" />
              Terbitkan Tagihan
            </button>
          </div>
        </section>
      </div>

      <div class="detail-col">
        <!-- ===== Informasi ===== -->
        <section class="panel">
          <header class="panel__head">
            <h3>Informasi Tenant</h3>
          </header>

          <!--
            Grid sel, bukan baris `dt`/`dd`.

            Empat field ini sama-sama penting dan tidak ada yang dibaca
            berurutan -- tidak ada "utama" dan "pelengkap" di antaranya.
            Baris dua kolom memaksa mata bergerak bolak-balik ke kanan
            empat kali; grid sel membacanya seperti kartu identitas, ke
            bawah lalu ke kanan.

            Tiga kolom eksplisit, bukan `auto-fit`. Empat field di
            `auto-fit minmax(13rem)` berakhir jadi 3 + 1, dan field
            keempat -- Telepon -- menggantung sendirian dengan dua sel
            kosong selebar kolom di sebelahnya. Sel kosong itulah yang
            terlihat di tangkapan layar: bukan karena ada yang belum
            diisi, tapi karena pembagian kolom tidak pernah tahu
            jumlah field yang benar-benar ada.

            Dua kolom menutup empat field tanpa sisa, tapi di layar
            lebar tiap sel jadi selebar setengah halaman dan alamat yang
            panjang membungkus jadi lima baris. Empat kolom menutupnya
            rapat, tapi membuat email terpotong di tablet.

            Jawabannya bukan menambah field kelima untuk mengisi tempat
            kosong itu. Jawabannya: Telepon diberi `span 2` di baris
            kedua, jadi dia mengisi lebar baris itu sendiri. Dua sel
            kosong yang tersisa di sebelahnya tidak terlihat, karena
            baris itu memang tidak punya apa-apa lagi.
          -->
          <div class="info-grid">
            <div class="info-cell">
              <p class="info-cell__label">Nama Lembaga</p>
              <p class="info-cell__value">{{ lokasi.nama_lembaga || '—' }}</p>
            </div>

            <div class="info-cell">
              <p class="info-cell__label">Alamat</p>
              <p class="info-cell__value">{{ lokasi.alamat || '—' }}</p>
            </div>

            <div class="info-cell">
              <p class="info-cell__label">Email</p>
              <p class="info-cell__value">{{ lokasi.email || '—' }}</p>
            </div>

            <div class="info-cell info-cell--telepon">
              <p class="info-cell__label">Telepon</p>
              <p class="info-cell__value">{{ lokasi.telepon || '—' }}</p>
            </div>
          </div>
        </section>

        <!-- ===== Domain ===== -->
        <!--
            Panel sendiri, bukan satu sel di grid informasi.

            Domain punya bentuk yang berbeda dari field lain: bisa banyak,
            setiap barisnya punya tag, dan sebagian bisa diklik. Ditempelkan
            ke grid sel, daftar yang panjang akan mengubah tinggi panel
           Informasi jadi tidak terkendali -- sementara panelnya sendiri
            tidak punya apa-apa yang perlu menyesuaikan.
        -->
        <section class="panel">
          <header class="panel__head">
            <h3>Domain</h3>
            <p class="panel__sub">{{ daftarDomain.length }} domain</p>
          </header>

          <ul v-if="daftarDomain.length" class="domain-list">
            <li v-for="d in daftarDomain" :key="d.id" class="domain-item">
              <font-awesome-icon icon="globe" class="domain-item__icon" />

              <!--
                Wildcard dan domain nonaktif sengaja bukan tautan.
                `https://*.astaloka.test` memang tidak bisa dibuka, dan
                tautan yang selalu gagal mengajari admin bahwa ada domain
                rusak di panel -- padahal yang rusak hanya tautannya.

                `tenantUrl()` juga memilih http/https sesuai jenis host-nya,
                jadi domain development (`.test`) tidak lagi menghasilkan
                tautan `https://` yang gagal sebelum halaman ter-load.
              -->
              <a
                v-if="!d.is_wildcard && d.is_active && tenantUrl(d.host)"
                :href="tenantUrl(d.host)"
                target="_blank"
                rel="noopener noreferrer"
                class="domain-item__host"
              >
                {{ d.host }}
                <font-awesome-icon icon="arrow-up-right-from-square" />
              </a>
              <span v-else class="domain-item__host">{{ d.host }}</span>

              <span class="domain-item__tags">
                <span v-if="d.is_primary" class="domain-tag domain-tag--primary">Utama</span>
                <span v-if="d.is_wildcard" class="domain-tag">Wildcard</span>
                <span v-if="!d.is_active" class="domain-tag domain-tag--off">Nonaktif</span>
              </span>
            </li>
          </ul>

          <p v-else class="panel__empty">Belum ada domain terdaftar untuk tenant ini.</p>
        </section>

        <!--
          ===== Tampilan login =====

          Branding diletakkan DI ANTAR Domain dan Infrastruktur, bukan di
          paling bawah.

          Urutannya mengikuti pertanyaan sederhana: apa yang dilihat orang
          dari lokasi ini, lalu bagaimana lokasi itu dijalankan. Nama,
          domain, dan tampilan adalah identitas yang dilihat dari luar;
          database dan tagihan adalah urusan internal. Field teknis yang
          tidak ada hubungannya dengan wajah lokasi dibaca orang yang
          memasang sistem, bukan orang yang membuka aplikasi lokasi untuk
          bekerja.
        -->
        <CentralBrandingEditor
          :lokasi="lokasi"
          :bawaan="{
            judul: lokasi.branding?.default_judul,
            sub_judul: lokasi.branding?.default_sub_judul,
            deskripsi: lokasi.branding?.default_deskripsi,
            warna: lokasi.branding?.default_warna,
          }"
          @saved="loadLocation"
        />

        <!-- ===== Infrastruktur ===== -->
        <section class="panel">
          <header class="panel__head">
            <h3>Infrastruktur</h3>
          </header>

          <div class="infra">
            <div class="infra-row">
              <span class="infra-label">
                <font-awesome-icon icon="database" />
                Database
              </span>
              <code class="infra-value">{{ lokasi.database }}</code>
            </div>

            <div class="infra-row">
              <span class="infra-label">
                <font-awesome-icon
                  :icon="lokasi.database_ada ? 'circle-check' : 'circle-exclamation'"
                />
                Status Database
              </span>
              <span
                class="infra-badge"
                :class="lokasi.database_ada ? 'infra-badge--ok' : 'infra-badge--warn'"
              >
                {{ lokasi.database_ada ? 'Tersedia' : 'Tidak ditemukan' }}
              </span>
            </div>
          </div>

          <p v-if="!lokasi.database_ada" class="infra-warning">
            <font-awesome-icon icon="triangle-exclamation" />
            <span>
              Database tidak ditemukan di server. Lokasi terdaftar di pusat tetapi tidak bisa
              dilayani. Periksa apakah database sudah benar-benar dibuat.
            </span>
          </p>

          <div class="tenant-hint">
            <p class="tenant-hint__label">Identitas tenant saat development</p>
            <p class="tenant-hint__value">
              <code>{{ lokasi.slug }}</code>
            </p>
            <p class="tenant-hint__desc">
              Dikirim sebagai header <code>X-Tenant-Slug</code> atau query
              <code>?tenant=</code> saat <code>TENANCY_LOOKUP=slug</code>.
            </p>
          </div>
        </section>

        <!--
          Panel Tagihan TIDAK lagi ada di kolom ini.

          Panel yang isinya cuma judul, satu kalimat ringkasan, dan dua
          tombol tidak salah bentuknya; letaknya yang salah, dan itu yang
          terus terlihat sebagai ruang kosong -- lima panel di sebelah kanan,
          satu panel berisi kepala di sebelah kiri.

          Tagihan sekarang punya kartu sendiri di kolom kiri, tepat di bawah
          kartu identitas. Alasannya urutan baca, bukan tata letak: ringkasan
          tagihan biasanya dibaca BERSAMA identitas tenant ("tenant ini
          aktif, tapi menunggak" adalah satu kalimat), sementara Infrastruktur
          dan Domain dibaca satu per satu saat butuh.

          Dua tombol aksi ikut pindah ke sana, supaya tidak ada tombol yang
          terpisah dari isinya.
        -->
      </div>
    </div>

    <!-- ===== Modal terbitkan tagihan ===== -->
    <Teleport to="body">
      <div v-if="showInvoiceModal" class="modal-overlay" @click="showInvoiceModal = false">
        <div class="modal-box modal-box--lebar" @click.stop>
          <div class="modal-box__header">
            <div>
              <h3>Terbitkan Tagihan</h3>
              <p>Tagihan langganan untuk {{ lokasi?.label }}.</p>
            </div>
            <button
              type="button"
              class="modal-box__close"
              aria-label="Tutup"
              @click="showInvoiceModal = false"
            >
              <font-awesome-icon icon="times" />
            </button>
          </div>

          <div class="modal-box__body">
            <div class="form-grid">
              <!--
                `MaksMoneyInput`, bukan `type="number"`, dan tanpa prefix
                "Rp.".

                Input number biasa menampilkan apa adanya yang diketik, jadi
                nominal panjang tampil sebagai "1500000" -- bentuk yang harus
                dibaca di layar sebelum bisa dipastikan benar. Input ini
                menampilkan "1.500.000,00" dengan pemisah ribuan, dan yang
                keluar dari `v-model` tetap angka polos `1500000`, jadi yang
                tersimpan ke database tidak ikut berubah bentuk.

                Prefix "Rp." dimatikan karena sudah ada di dalam label
                ("Jumlah (Rp)") dan akan mengulanginya di kiri input
                ("Rp. 1.500.000,00"). Nominal yang sama ditulis dua kali
                dalam jarak satu baris bukan pengulangan yang membantu --
                hanya membuat kolom melebar dan angka terdorong ke kanan.
                Rumahnya sudah ada di judul modal dan di label.
              -->
              <MaksMoneyInput
                v-model="invoiceForm.jumlah"
                label="Jumlah *"
                placeholder="0,00"
                hide-prefix
                :error="invoiceErrors.jumlah"
              />
              <!--
                Kalender, bukan `<input type="date">`.

                Tanggal terbit hampir selalu sudah diketahui orangnya --
                "tanggal 15", "akhir bulan depan". `type="date"` bawaan
                browser memaksa urutan year/month/day yang tidak sama di
                setiap peramban, jadi hasil ketikanya tidak bisa berulang
                di tempat lain dan tanggal yang sama jadi salah ketik di
                peramban berikutnya.

                Komponen ini sudah dipakai di form Ubah Tenant di panel
                yang sama, jadi bentuk, radius, dan tinggi inputnya sama --
                mengetik tanggal di form tenant dan mengetik tanggal tagihan
                adalah tindakan yang sama, dan harus terasa sama.

                Nilai yang keluar dari `v-model` tetap string `YYYY-MM-DD`,
                persis bentuk yang dipakai `blankInvoiceForm()` dan yang
                dibaca backend, jadi tidak ada konversi tambahan di sini.

                HAPUS: "Jatuh Tempo". Tidak ada isian tanggal jatuh tempo
                di form penerbitan. Form ini hanya menentukan tanggal
                tagihan diterbitkan; kapan uangnya benar-benar dibayar
                adalah fakta yang baru diketahui saat pelunasan, bukan
                saat tagihan dibuat -- jadi tanggal itu diinput di dialog
                pelunasan (lihat `markPaid()` di `CentralTagihanIndex.vue`),
                bukan di sini.

                Batas pembayaran tetap ada, tapi dihitung dari tanggal
                terbit ditambah masa toleransi global
                (`tenancy.invoice_grace_months`), bukan dari tanggal yang
                dipilih admin. Satu tagihan punya satu batas, dan itu
                sudah cukup untuk satu aplikasi per lokasi.
              -->
              <AppDatePicker
                v-model="invoiceForm.tgl_invoice"
                label="Tanggal Terbit *"
                placeholder="Pilih tanggal"
                :error="invoiceErrors.tgl_invoice"
              />
            </div>

            <!--
              Status lunas, sebagai saklar bukan select.

              Select dengan dua opsi ("Belum Lunas" / "Lunas") memaksa
              orang membaca dan memutuskan, padahal hampir semua tagihan
              terbit dalam keadaan belum lunas -- jadi select itu akan
              dipilih "Belum Lunas" hampir setiap kali tanpa pernah dibaca.
              Saklar punya keadaan bawaan yang benar, dan yang harus
              dilakukan hanya mengubahnya ketika jawabannya memang "sudah".

              Dipakai checkbox, bukan toggle bergaya, karena labelnya adalah
              kalimat yang menjelaskan akibatnya -- dan kalimat itu tidak muat
              di dalam tombol tanpa jadi tidak terbaca.
            -->
            <div class="form-block">
              <label class="sudah-lunas">
                <input v-model="sudahLunas" type="checkbox" />
                <span>
                  <strong>Tagihan ini sudah dibayar</strong>
                  <em>
                    Centang kalau uangnya sudah masuk. Kalau tidak, tagihan terbit sebagai "Belum
                    Lunas" dan bisa dilunasi nanti dari halaman Tagihan.
                  </em>
                </span>
              </label>
            </div>

            <!--
              Isian pembayaran HANYA muncul kalau saklar di atas menyala.

              Disembunyikan, bukan disabled, supaya form yang belum
              tercentang tidak terlihat punya dua isian kosong yang harus diisi.
              Kalau disabled, yang terlihat adalah "ada yang belum diisi" --
              padahal memang tidak ada yang perlu diisi.

              Dua isiannya berpasangan dalam SATU baris: tanggal di
              kiri, pilihan kas atau rekening di kanan. Keduanya
              sama lebar karena keduanya mengisi satu kolom grid --
              dan tidak ada `form-span` di sini, karena melintasi
              kolom justru yang membuatnya turun ke baris kedua dan
              menyisakan setengah baris kosong di sebelahnya.
            -->
            <div v-if="sudahLunas" class="form-grid">
              <AppDatePicker
                v-model="tglLunas"
                label="Tanggal Bayar"
                placeholder="Hari ini"
                :max-date="todayStr"
                no-margin
              />

              <!--
                Kas atau rekening tujuan, dalam SATU dropdown.

                Sama persis dengan yang dipakai dialog pelunasan di
                halaman Tagihan: daftarnya langsung "Kas Tunai",
                "Kas di Bank BRI", dan seterusnya. Dua isian
                (pilih cara bayar, lalu pilih rekeningnya) untuk satu
                keputusan hanya menambah satu klik, dan bentuknya di
                sini harus sama dengan di sana supaya catatan
                pembayaran tidak berubah tergantung dari halaman mana
                orang masuk.

                Nomor rekening TIDAK ikut dalam nama di dropdown --
                kolomnya 15rem supaya bisa berpasangan dengan tanggal,
                dan label yang membawa nomor akan terpotong tepat di
                bagian yang dicari orang. Nomornya muncul di baris
                `rekening-detail` tepat di bawah, yang melintasi
                seluruh lebar form.

                `show-clear` dimatikan: kolom ini wajib diisi, jadi
                tombol "Hapus" di kanan tidak punya apa-apa untuk
                dihapus saat field masih kosong -- PrimeVue tetap
                memunculkan tanda silang di sana, dan itu terlihat
                seperti tombol kedua untuk menutup pilihan yang
                memang belum pernah dibuat.
              -->
              <div class="form-field">
                <label class="form-label" for="inv-rekening">Kas / Rekening Tujuan</label>
                <BaseSelect
                  id="inv-rekening"
                  v-model="rekeningBayar"
                  :options="pilihanRekening"
                  option-label="label"
                  option-value="value"
                  :placeholder="
                    rekeningLoading
                      ? 'Memuat kas dan rekening...'
                      : pilihanRekening.length
                        ? 'Pilih kas atau rekening bank'
                        : 'Belum ada kas atau rekening'
                  "
                  :loading="rekeningLoading"
                  :disabled="!pilihanRekening.length"
                  :error="invoiceErrors.central_account_id"
                  :show-clear="false"
                />
              </div>
            </div>

            <!--
              Nomor rekening, di luar grid dan selebar form.

              Kalau ikut duduk di dalam `.form-grid`, dia masuk ke kolom
              kedua dan ikut terpotong -- persis hal yang dipindahkan ke
              sini supaya tidak terjadi.
            -->
            <p v-if="sudahLunas && rekeningTerpilih?.no_rekening" class="rekening-detail">
              <font-awesome-icon icon="building-columns" />
              <span>
                {{ rekeningTerpilih.no_rekening }}
                <template v-if="rekeningTerpilih.atas_nama">
                  &mdash; atas nama {{ rekeningTerpilih.atas_nama }}
                </template>
              </span>
            </p>

            <p
              v-else-if="sudahLunas && !rekeningLoading && !pilihanRekening.length"
              class="form-hint is-bad"
            >
              Belum ada kas atau rekening bank terdaftar di panel pusat. Tambahkan di tabel
              <code>central_accounts</code> dulu, atau biarkan tagihan ini belum lunas.
            </p>

            <BaseInput
              v-model="invoiceForm.keterangan"
              label="Keterangan"
              type="textarea"
              :rows="2"
              placeholder="Keterangan tagihan (opsional)"
              :error="invoiceErrors.keterangan"
            />

            <p class="modal-box__note">
              <font-awesome-icon icon="circle-info" />
              Nomor tagihan dibuat otomatis oleh sistem. Tagihan yang lewat jatuh tempo akan
              memblokir akses pengguna lokasi sampai dilunasi.
            </p>
          </div>

          <div class="modal-box__footer">
            <button type="button" class="btn btn--ghost" @click="showInvoiceModal = false">
              Batal
            </button>
            <button
              type="submit"
              class="btn btn--save"
              :disabled="invoiceSaving"
              @click="submitInvoice"
            >
              <font-awesome-icon v-if="invoiceSaving" icon="spinner" spin />
              {{ submitLabel }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
// BaseInput hanya terdaftar sebagai komponen global di app LOKASI lewat
// import eksplisit per-file. Tanpa baris ini form ubah ikut kosong.
import BaseInput from '@/presentations/components/ui/BaseInput.vue'
// MaksMoneyInput tidak didaftarkan global di main.js (hanya PrimeVue-nya),
// jadi tetap perlu import eksplisit seperti BaseInput di atas.
import MaksMoneyInput from '@/presentations/components/MaksMoneyInput.vue'
// AppDatePicker dipakai di form Ubah Tenant di panel yang sama, jadi tanggal
// di form tenant dan tanggal di form tagihan tampil dan berperilaku sama.
import AppDatePicker from '@/presentations/components/AppDatePicker.vue'
// BaseSelect dipakai untuk satu dropdown kas atau rekening tujuan, supaya
// konsisten dengan filter di halaman Tagihan. PrimeVue Select-nya
// tidak didaftarkan global di main.js, jadi import eksplisit.
import BaseSelect from '@/presentations/components/ui/BaseSelect.vue'
import CentralBrandingEditor from '@/presentations/components/central/CentralBrandingEditor.vue'
import { MySwal, showSuccessToast, showErrorToast } from '@/utils/swal'
import { locationService, invoiceService, rekeningService } from '@/services/central.service'
import { formatRupiah } from '@/utils/format'
import { tenantUrl } from '@/utils/tenantUrl'
import '@/assets/css/central-form.css'

const route = useRoute()
const router = useRouter()
/*
 * `loading` milik HALAMAN INI, bukan alias ke `uiStore.loading`.
 *
 * Yang tadinya store global: begitu ada request apa pun yang sedang berjalan,
 * seluruh badan halaman ini diganti spinner "Memuat detail tenant..." --
 * termasuk request milik komponen lain di dalamnya.
 *
 * Yang paling merusak bukan tampilannya, tapi akibatnya: spinner itu mengganti
 * `<section class="branding">` dari DOM, jadi `onUnmounted` komponen editor
 * ikut jalan dan state `terbuka`-nya hilang. Hasilnya tombol "Atur Tampilan"
 * membuka dialog, dialognya request daftar preset warna, request itu menaikkan
 * spinner global, spinner_it membongkar dialognya sendiri -- dan yang terlihat
 * hanyalah halaman yang berganti sendiri.
 *
 * Editor branding adalah satu-satunya bagian halaman ini yang membuka UI lalu
 * menembak request bersamaan, jadi hanya dia yang terpengaruh. Halaman lain
 * memakai store global tanpa masalah karena request-nya selalu cocok dengan
 * apa yang sedang dip ditampilkan.
 *
 * Catatan yang sama sudah ada di `CentralLokasiEdit.vue`; di situ alasannya
 * polling latar yang mengedipkan spinner tiap 30 detik, di sini lifecycle
 * komponen yang dibongkar.
 */
const memuatDetail = ref(true)
const loading = computed(() => memuatDetail.value)
const invoiceLoading = ref(false)

const lokasi = ref(null)

/*
 * Sedang menghapus lokasi secara permanen.
 *
 * Dipakai untuk men-disable tombol "Hapus Permanen" selama request
 * berjalan, dan untuk menggantinya dengan spinner. Tanpa ini, menekan
 * tombol dua kali akan mengirim dua request DELETE -- dan request kedua
 * akan tiba setelah baris registry-nya sudah hilang, jadi ia akan
 * gagal dengan 404 yang tidak menjelaskan apa pun.
 */
const deleting = ref(false)

/*
 * Data tagihan milik kartu di kolom kiri.
 *
 * Empat ref terpisah, bukan satu objek, dan bukan satu array yang
 * dijumlahkan nanti.
 *
 * Alasannya tidak soal kerapian: `invoiceTerbaru` dipotong lima baris dari
 * server, sedangkan tiga angka di sebelahnya mencakup SELURUH tagihan
 * lokasi ini. Kalau keempatnya diturunkan dari satu array, kartu akan
 * menulis "5 tagihan" untuk lokasi yang punya dua puluh -- dan karena
 * kelimanya memang benar sampai hitungan lima, kesalahannya tidak pernah
 * menampakkan diri sebagai error.
 *
 * `invoiceTotal` (dari `data.total`) dan `belumLunas`/`totalTunggakan`
 * (dari `ringkasan`) sengaja tidak bisa saling menggantikan: hanya
 * `ringkasan` yang dihitung server dengan filter yang benar, dan hanya
 * `total` yang tahu berapa banyak baris yang sebenarnya ada.
 */
const invoiceTerbaru = ref([])
const invoiceTotal = ref(0)
const belumLunas = ref(0)
const totalTunggakan = ref(0)
const lunas = ref(0)

/*
 * Kembali memakai `push`, bukan `router.back()`.
 *
 * `back()` mengembalikan ke mana pun orang datang, dan itu bisa jadi
 * dashboard atau daftar tagihan -- bukan daftar tenant. Halaman ini
 * selalu soal satu tenant, dan tombol "Kembali ke Daftar Tenant"
 * menjanjikan tujuan yang spesifik. Janji tombol harus benar, jadi
 * tujuannya ditulis, bukan asal mengikuti riwayat.
 */
const goBack = () => router.push('/central/lokasi')

/*
 * Daftar domain milik tenant ini.
 *
 * Sumbernya `lokasi.domains` (tabel `tenant_domains`), BUKAN kolom `domain` /
 * `domain_alternatif`. Kolom itu cuma dua slot dan tidak bisa memuat wildcard
 * atau domain tambahan.
 *
 * Fallback ke kolom legacy hanya dipakai kalau backend tidak mengirim daftar --
 * yang terjadi kalau endpoint dijalankan pada versi lama. Tanpa fallback itu,
 * tenant yang punya domain tapi tidak punya baris di `tenant_domains` akan
 * tampil "tidak punya domain", padahal resolver tetap melayaninya. Tampilan
 * yang lebih jarang diperbarui tapi benar lebih mudah dipercaya daripada
 * tampilan yang sering diperbarui tapi salah.
 *
 * Diurutkan ulang di sini bukan bergantung pada urutan backend: domain utama
 * selalu di atas, karena itu satu-satunya yang dipakai `settings.domain` di
 * database lokasi dan satu-satunya yang muncul di kop laporan.
 */
const daftarDomain = computed(() => {
  const dari = Array.isArray(lokasi.value?.domains) ? lokasi.value.domains : []

  if (dari.length) {
    return [...dari].sort((a, b) => {
      if (a.is_primary !== b.is_primary) return a.is_primary ? -1 : 1
      if (a.is_active !== b.is_active) return a.is_active ? -1 : 1
      return String(a.host).localeCompare(String(b.host))
    })
  }

  // Fallback: susun dari kolom legacy.
  const legacy = [
    lokasi.value?.domain ? { host: lokasi.value.domain, is_primary: true } : null,
    lokasi.value?.domain_alternatif
      ? { host: lokasi.value.domain_alternatif, is_primary: false }
      : null,
  ].filter(Boolean)

  return legacy.map((d, i) => ({ id: `legacy-${i}`, ...d, is_wildcard: false, is_active: true }))
})

/*
 * Satu bentuk untuk template, bukan lima nama yang disebut di seluruh kartu.
 *
 *
 * `tagihanRingkas` hanya menaungi penamaan, bukan menghitung apa pun --
 * semua angkanya sudah diisi `loadInvoices()` dari respons server. Yang
 * dijaga di sini cuma satu hal: template tidak pernah membaca
 * `invoiceTerbaru` untuk menyebut angka yang bukan lima.
 */
const tagihanRingkas = computed(() => ({
  jumlah: invoiceTotal.value,
  belumLunas: belumLunas.value,
  totalTunggakan: totalTunggakan.value,
  lunas: lunas.value,
}))

/*
 * "Perlu ditagih" atau tidak.
 *
 * Satu boolean, bukan "!belumLunas" yang ditulis di template. Dipakai
 * sebagai kelas CSS sekaligus sebagai syarat blok nominal -- kalau dua
 * tempat itu menuliskan syaratnya masing-masing, kartu bisa saja punya
 * gaya merah tanpa nominalnya, atau sebaliknya.
 */
const tagihanTunggakan = computed(() => belumLunas.value > 0)

/* ===== Data ===== */
const loadLocation = async () => {
  memuatDetail.value = true

  try {
    const res = await locationService.getById(route.params.id)
    lokasi.value = res.data?.data || null
  } catch (error) {
    showErrorToast(error)
    router.push('/central/lokasi')
  } finally {
    /*
     * `finally`, bukan di akhir `try`: kalau request gagal, halaman ini langsung
     * pindah ke daftar tenant -- tapi kalau spinner global masih menyala saat
     * komponen dilepas, `loading` yang tersisa ikut memengaruhi halaman
     * berikutnya yang kebetulan juga memakainya.
     */
    memuatDetail.value = false
  }
}

/*
 * Ambil data tagihan untuk kartu di kolom kiri.
 *
 * `per_page: BATAS_TAGIHAN_TERBARU` dan bukan angka bawaan server (50).
 * Kartu ini hanya menampilkan lima tagihan terakhir, jadi menarik 50 baris
 * untuk lalu memotongnya di peramban berarti 45 baris tidak pernah
 * dilihat -- dan backend sudah menerima `per_page` sampai 200, jadi angka
 * kecil di sini benar-benar reduzir yang diambil dari server, bukan hanya
 * menyembunyikan di layar.
 *
 * Yang dipakai dari respons:
 *
 *   - `data.data`          -> `invoiceTerbaru`, lima baris yang ditampilkan
 *   - `ringkasan`          -> angka "N belum lunas" dan total tunggakan
 *   - `data.total`         -> jumlah SELURUH tagihan lokasi ini
 *
 * Ketiganya wajib. Ambil hanya `ringkasan` tanpa `total`, kartu akan
 * menulis "5 tagihan" padahal lokasi ini punya tujuh -- angka yang benar
 * sampai lima dan tidak pernah bisa menjelaskan dirinya sendiri.
 */
const BATAS_TAGIHAN_TERBARU = 5

const loadInvoices = async () => {
  invoiceLoading.value = true

  try {
    const res = await invoiceService.getAll({
      tenant_id: route.params.id,
      per_page: BATAS_TAGIHAN_TERBARU,
    })

    const body = res.data?.data || {}

    invoiceTerbaru.value = Array.isArray(body.data) ? body.data : []

    invoiceTotal.value = Number(body.total ?? 0)

    /*
     * Ringkasan BELUM LUNAS dibaca dari server, bukan dijumlahkan dari
     * `invoiceTerbaru`.
     *
     * Kelima baris itu sudah dipotong, jadi menjumlahkannya di sini
     * menghasilkan angka yang benar selama tagihan lokasi ini masih di
     * bawah lima dan diam-diam salah setelah itu -- tanpa error yang
     * mengatakannya. Ini persis sumber desync yang sudah pernah dihapus
     * di halaman Tagihan, hanya saja di tempat lain.
     *
     * `lunas` juga dibaca dari server, bukan dihitung sebagai
     * `jumlah - belumLunas`.
     *
     *Hitungan itu terlihat benar karena hanya ada dua status (`PAID` dan
     * `UNPAID`) -- tapi rapuh: begitu ada status ketiga, `jumlah -
     * belumLunas` menghitung tagihan yang bukan lunas sebagai lunas.
     * Backend sudah mengirim angkanya sendiri, jadi tidak ada alasan
     * menurunkannya di sini.
     *
     * `totalLunas` (nominal) sengaja TIDAK ikut ditampilkan -- lihat
     * catatan di template kartu tagihan.
     */
    const ringkasan = res.data?.ringkasan || {}

    belumLunas.value = Number(ringkasan.belumLunas || 0)
    totalTunggakan.value = Number(ringkasan.totalTunggakan || 0)
    lunas.value = Number(ringkasan.lunas ?? Math.max(0, invoiceTotal.value - belumLunas.value))
  } catch (error) {
    showErrorToast(error)
  } finally {
    invoiceLoading.value = false
  }
}

/**
 * Aktifkan / nonaktifkan lokasi.
 *
 * Dua arah, dua endpoint BERBEDA: `deactivate()` memakai DELETE (efeknya
 * soft -- data dan database tidak dihapus), `activate()` memakai PATCH
 * endpoint khusus. Keduanya sengaja tidak digabung: DELETE yang bisa
 * mengembalikan lokasi akan membuat satu request berarti dua hal yang
 * bertentangan.
 */
const handleToggleActive = async () => {
  if (lokasi.value.is_active) {
    const result = await MySwal.fire({
      title: 'Nonaktifkan Tenant?',
      html: `Tenant <strong>${lokasi.value.label}</strong> tidak lagi bisa diakses.<br>
        Database dan datanya <strong>tidak dihapus</strong>.`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#ef4444',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Ya, nonaktifkan',
      cancelButtonText: 'Batal',
    })

    if (!result.isConfirmed) return
  }

  try {
    const res = lokasi.value.is_active
      ? await locationService.deactivate(lokasi.value.id)
      : await locationService.activate(lokasi.value.id)

    showSuccessToast(res.data?.message || 'Status tenant diperbarui.')
    await loadLocation()
  } catch (error) {
    showErrorToast(error)
  }
}

/**
 * Hapus tenant PERMANEN, beserta database dan seluruh datanya.
 *
 * Berbeda dari "Nonaktifkan" di sebelahnya, yang hanya mematikan tenant
 * tanpa menyentuh database. Yang di sini benar-benar menghapus, dan tidak
 * ada cara memulihkannya -- jadi konfirmasinya dua tahap, dan tahap kedua
 * mewajibkan slug tenant diketik ulang.
 *
 * Setelah berhasil, halaman ini tidak bisa menampilkan apa pun lagi:
 * tenant yang dihapus tidak ada lagi di server. Karena itu halaman
 * langsung dikembalikan ke daftar, bukan sekadar dimuat ulang.
 */
const handleForceDelete = async () => {
  const konfirmasi = await MySwal.fire({
    title: 'Hapus Tenant Permanen?',
    html: `Tenant <strong>${lokasi.value.label}</strong> akan dihapus <strong>permanen</strong>.<br><br>
      Database <code>${lokasi.value.database}</code> beserta <strong>seluruh data di dalamnya</strong>
      akan ikut dihapus, dan <strong>tidak ada cara memulihkannya</strong>.<br><br>
      Kalau hanya ingin menonaktifkan sementara, gunakan tombol "Nonaktifkan" -- datanya tetap utuh.`,
    icon: 'error',
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Lanjut',
    cancelButtonText: 'Batal',
  })

  if (!konfirmasi.isConfirmed) return

  const ketikSlug = await MySwal.fire({
    title: 'Ketik slug untuk konfirmasi',
    html: `Ketik <code>${lokasi.value.slug}</code> untuk menghapus tenant ini secara permanen.`,
    icon: 'warning',
    input: 'text',
    inputAttributes: {
      autocapitalize: 'none',
      autocorrect: 'off',
      spellcheck: 'false',
    },
    inputPlaceholder: lokasi.value.slug,
    inputValidator: (value) => {
      if (!value || value.trim() !== lokasi.value.slug) {
        return `Slug harus persis sama: ${lokasi.value.slug}`
      }

      return null
    },
    showCancelButton: true,
    confirmButtonColor: '#dc2626',
    cancelButtonColor: '#6b7280',
    confirmButtonText: 'Hapus permanen',
    cancelButtonText: 'Batal',
  })

  if (!ketikSlug.isConfirmed) return

  deleting.value = true

  try {
    const res = await locationService.forceDelete(lokasi.value.id, lokasi.value.slug)

    await MySwal.fire({
      icon: 'success',
      title: 'Tenant Dihapus Permanen',
      html: res.data?.message || 'Tenant beserta database dan seluruh datanya sudah dihapus.',
      confirmButtonColor: 'var(--color-primary, #3b82f6)',
    })

    router.push({ name: 'central-lokasi' })
  } catch (error) {
    showErrorToast(error)
  } finally {
    deleting.value = false
  }
}

/* ===== Tagihan ===== */
const showInvoiceModal = ref(false)
const invoiceSaving = ref(false)
const invoiceErrors = ref({})

const today = () => new Date().toISOString().slice(0, 10)

/*
 * Form tagihan: hanya nominal, tanggal terbit, dan keterangan.
 *
 * Dua hal yang dulu ada di sini sengaja tidak ada lagi, dan keduanya
 * karena alasan yang sama -- form penerbitan bukan tempat memasukkan
 * angka yang belum terjadi:
 *
 *   - Rincian per unit ("Qty", "Satuan", "Harga satuan"). Panel ini
 *     menagih langganan: satu aplikasi untuk satu lokasi, jadi satu
 *     tagihan cukup dengan satu nominal. Baris "1 x Lisensi Tahunan"
 *     hanya menambah tempat mengetik angka yang nilainya sudah pasti.
 *   - Tanggal jatuh tempo. Kapan uangnya dibayar adalah fakta yang baru
 *     diketahui saat pelunasan. Mencatatkannya di form penerbitan berarti
 *     admin menebak tanggal pembayaran di hari yang belum tahu kapan
 *     dibayar -- dan tanggal itu lalu tercatat sebagai data.
 *
 * Yang tetap ada adalah batas pembayaran, tapi itu bukan pilihan admin:
 * `TenantInvoice::deadline()` menghitungnya dari tanggal terbit
 * ditambah masa toleransi global, jadi `tgl_jatuh_tempo` dibiarkan null
 * dan tidak pernah dikirim dari sini.
 *
 * Backend tetap menerima `items` dan `tgl_jatuh_tempo` (keduanya opsional)
 * dan invoice yang sudah punya nilai tetap utuh -- yang dihapus hanya
 * tempat mengisinya.
 */

const blankInvoiceForm = () => ({
  jumlah: '',
  tgl_invoice: today(),
  keterangan: '',
  // Lewati status untuk form ini: sebagian besar tagihan diterbitkan
  // belum masuk, jadi "belum lunas" adalah keadaan bawaan yang benar.
  // Yang ingin dicatat lebih dulu daripada pembayaran adalah jawaban
  // "sudah", dan itu punya bentuknya sendiri di bawah (`sudahLunas`).
})

const invoiceForm = reactive(blankInvoiceForm())

/*
 * Status tagihan yang sedang diterbitkan.
 *
 * `false` (belum lunas) adalah bawaan, bukan karena jarang terjadi,
 * tapi karena itu keadaan tagihan sejak terbit: uang belum masuk
 * sampai ada yang mengatakannya. Jadi form tidak pernah menebak --
 * kalau tidak ditebak, default-nya sudah benar dan tidak ada yang
 * perlu dikoreksi kemudian.
 *
 * `true` ada karena ada tagihan yang lunas sebelum sempat masuk daftar:
 * lokasi transfer di hari yang sama, atau tagihan untuk periode yang
 * sudah lewat dan baru dicatat sekarang. Lewati "Terbitkan" lalu
 * "Tandai Lunas" di halaman tagihan berarti tiga klik dan satu
 * perpindahan halaman untuk sesuatu yang sudah diketahui.
 */
const sudahLunas = ref(false)

/*
 * Tanggal pelunasan.
 *
 * Hanya ada kalau `sudahLunas` menyala. Kalau tidak perlu diubah,
 * "dibayar hari ini" hampir selalu benar saat invoice baru dibuat --
 * orang yang baru mencatat tagihan sudah lunas biasanya sedang
 * menyimpan bukti transfer pada hari itu juga.
 *
 * Kosong berarti backend memakai `now()` (`InvoiceController::markPaid`),
 * jadi tetap ada tanggalnya di database.
 */
const tglLunas = ref('')

const todayStr = today()

/*
 * Kas atau rekening tujuan, termasuk kas tunai.
 *
 * Tidak ada lagi ref `metodeBayar`. Dulu form ini punya dua isian --
 * pilih cara bayar, lalu pilih rekeningnya -- dan yang kedua hanya
 * muncul setelah yang pertama dijawab. Sekarang satu dropdown
 * langsung menampilkan semua kas dan rekening yang aktif, dan
 * backend menurunkan cara pembayarannya sendiri dari kategori
 * rekening yang dipilih (`InvoiceController::markPaid`). Satu
 * isian, dan tidak ada keadaan yang bisa terisi tidak sesuai.
 */
const rekeningBayar = ref(null)

/*
 * Daftar kas dan rekening tujuan untuk form penerbitan.
 *
 * Dimuat saat modal dibuka, bukan saat halaman dimuat: daftar
 * rekening bisa berubah di tab lain, dan form yang menampilkan
 * rekening yang baru saja dinonaktifkan akan menerima request
 * yang ditolak backend. Memuatnya lebih lambat dari yang lain
 * tidak masalah -- form ini sudah menunggu orang menekan tombol.
 */
const rekening = ref([])
const rekeningLoading = ref(false)

/*
 * Opsi dropdown: nama rekening saja, TANPA nomor rekening.
 *
 * Sama persis dengan yang dilakukan dialog pelunasan di
 * `CentralBayarDialog.vue`, dan karena alasan yang sama: `r.label`
 * dari server menempelkan nomor dan atas nama, yang benar untuk tabel
 * rekening tapi terlalu panjang untuk kolom 15rem di dropdown ini.
 * Nomor rekening muncul di baris `rekening-detail` tepat di bawah
 * grid, yang melintasi seluruh lebar form.
 */
const pilihanRekening = computed(() =>
  rekening.value.map((r) => ({ ...r, value: r.id, label: r.nama_akun })),
)

const rekeningTerpilih = computed(
  () => rekening.value.find((r) => r.id === rekeningBayar.value) || null,
)

/*
 * Status tombol utama mengikuti status tagihan.
 *
 * Tidak ada tombol "Simpan" terpisah: satu tombol yang bunyinya ikut
 * berubah lebih jujur daripada tombol yang selalu berarti "terbitkan
 * saja" sementara efek sampingnya ditentukan status yang dipilih di atas.
 */
const submitLabel = computed(() => (sudahLunas.value ? 'Terbitkan & Lunas' : 'Terbitkan'))

/*
 * Kembalikan seluruh isian pembayaran ke keadaan semula.
 *
 * Dipisah dari `blankInvoiceForm()` karena yang dikembalikan di sini
 * BUKAN bagian dari `invoiceForm` -- tidak ada `jumlah` atau
 * `keterangan` di dalamnya, dan menggabungkannya ke satu objek akan
 * membuat orang mengira form tagihan dan form pembayaran menyimpan hal
 * yang sama.
 *
 * Dipanggil setelah tagihan berhasil diterbitkan, supaya modal berikutnya
 * tidak membuka dengan sisa pembayaran tagihan yang lalu -- termasuk
 * tanggal bayar lama, yang paling mudah terlewat karena tidak terlihat
 * sampai saklarnya dinyalakan lagi.
 */
const resetFormPembayaran = () => {
  sudahLunas.value = false
  tglLunas.value = ''
  rekeningBayar.value = null
  invoiceErrors.value = {}
}

/*
 * Muat daftar rekening tujuan saat modal dibuka.
 *
 * Dipicu dari saklar `sudahLunas`, bukan dari `watch` terpisah atas
 * `showInvoiceModal`: daftar rekening hanya dibutuhkan kalau form
 * menampilkan kolom rekening, jadi memuatnya sebelum itu berarti satu
 * request yang hasilnya tidak pernah dilihat.
 */
const loadRekening = async () => {
  rekeningLoading.value = true

  try {
    // Tanpa `all: true` -- server hanya mengembalikan yang aktif, dan form
    // tidak boleh menawarkan rekening yang sudah dinonaktifkan.
    const res = await rekeningService.getAll()
    rekening.value = res.data?.data || []
  } catch {
    // Kegagalan memuat daftar rekening tidak menggagalkan penerbitan:
    // tagihan tanpa pembayaran tetap bisa diterbitkan, dan yang gagal
    // hanya kolom rekening yang tidak muncul. Toast di sini akan
    // membuat orang mengira seluruh form rusak.
    rekening.value = []
  } finally {
    rekeningLoading.value = false
  }
}

watch(sudahLunas, (nyala) => {
  if (nyala && !rekening.value.length) {
    loadRekening()
  }
})

/*
 * Terbitkan tagihan, dan catat pembayarannya dalam satu kali menekan
 * tombol.
 *
 * Dua request, bukan satu: `POST /invoices` lalu
 * `POST /invoices/{id}/paid`. Bukan karena tidak ada endpoint yang bisa
 * keduanya, tapi karena bentuk datanya memang berbeda: yang dibuat
 * adalah tagihannya, yang tercatat adalah kejadian uangnya masuk. "Sudah
 * dibayar" bukan sifat tagihan saat terbit, itu hasil dari kejadian
 * yang terpisah.
 *
 * Menggabungkannya jadi satu request akan terlihat lebih rapi, tapi
 * menyimpan keputusan di backend yang tidak bisa dibatalkan. Kalau
 * pembayaran gagal setelah tagihan tersimpan, satu-satunya jalan keluar
 * adalah mengoreksi tagihan yang sudah terbit -- dan orang tidak akan
 * mengetahuinya perlu dilakukan. Dengan dua request, kegagalan di
 * langkah kedua terlihat sebagai "tagihan sudah terbit tapi belum
 * tercatat pembayarannya", dan itulah keadaan yang sebenarnya.
 */
const submitInvoice = async () => {
  invoiceErrors.value = {}

  if (!invoiceForm.jumlah || Number(invoiceForm.jumlah) <= 0) {
    invoiceErrors.value.jumlah = 'Jumlah wajib diisi dan lebih dari nol.'
    return
  }

  if (!invoiceForm.tgl_invoice) {
    invoiceErrors.value.tgl_invoice = 'Tanggal terbit wajib diisi.'
    return
  }

  /*
   * Kas atau rekening WAJIB sebelum tagihan diterbitkan, bukan sesudah
   * gagal.
   *
   * Kalau dicek setelah `create()`, tagihan yang sudah terbit akan gagal
   * berubah status dan form akan menampilkan error di isian yang baru
   * muncul -- satu klik lagi berarti satu tagihan terlanjur terbit belum
   * lunas hanya karena kas atau rekeningnya belum dipilih. Memeriksa di
   * sini membuat kegagalan terjadi sebelum ada yang perlu diperbaiki.
   *
   * Syaratnya satu, bukan satu per cara bayar: sekarang kas tunai juga
   * punya baris rekeningnya sendiri, jadi setiap pembayaran masuk ke
   * tepat satu tempat.
   */
  if (sudahLunas.value && !rekeningBayar.value) {
    invoiceErrors.value.central_account_id =
      'Pilih kas atau rekening bank yang menerima pembayaran.'
    return
  }

  invoiceSaving.value = true

  try {
    const res = await invoiceService.create({
      tenant_id: Number(route.params.id),
      jumlah: Number(invoiceForm.jumlah),
      tgl_invoice: invoiceForm.tgl_invoice,
      keterangan: invoiceForm.keterangan.trim() || null,
      // `items` tidak dikirim. Backend menjadikannya opsional,
      // jadi tagihan tanpa rincian tetap diterima dan tabel item tidak diisi.
    })

    const baru = res.data?.data

    /*
     * Lunasi hanya kalau saklarnya menyala.
     *
     * Kalau tidak, tagihan ini tertinggal di daftar sebagai "Belum Lunas"
     * dan dilunasi nanti dari halaman Tagihan -- dan di sanalah dialog
     * pembayaran dipakai. Yang membuat form ini punya isian pembayaran
     * yang bentuknya sama dengan dialog itu: supaya catatan pembayaran
     * tidak bergantung dari halaman mana orang masuk.
     */
    if (sudahLunas.value && baru?.id) {
      /*
       * Hanya rekening tujuan yang dikirim -- bukan `metode`.
       * Backend menurunkannya dari kategori rekening yang dipilih,
       * jadi tidak ada lagi yang bisa terisi tidak sesuai: "tunai"
       * yang menunjuk rekening bank, atau sebaliknya.
       */
      await invoiceService.markPaid(baru.id, {
        tgl_lunas: tglLunas.value || null,
        central_account_id: rekeningBayar.value,
        catatan: invoiceForm.keterangan.trim() || null,
      })
    }

    showSuccessToast(
      sudahLunas.value
        ? 'Tagihan diterbitkan dan pembayaran dicatat.'
        : 'Tagihan berhasil diterbitkan.',
    )

    Object.assign(invoiceForm, blankInvoiceForm())

    resetFormPembayaran()
    showInvoiceModal.value = false

    await loadInvoices()
  } catch (error) {
    if (error.response?.status === 422) {
      const fieldErrors = error.response.data?.errors || {}

      if (Object.keys(fieldErrors).length > 0) {
        invoiceErrors.value = Object.fromEntries(
          Object.entries(fieldErrors).map(([key, msgs]) => [key, msgs[0]]),
        )
        return
      }
    }

    // 409 = nomor tagihan bentrok. Ini kondisi sementara, bukan kegagalan
    // permanen, jadi cukup dilaporkan tanpa menutup modal -- admin bisa
    // menekan Terbitkan lagi.
    if (error.response?.status === 409) {
      showErrorToast(error)
      return
    }

    showErrorToast(error)
  } finally {
    invoiceSaving.value = false
  }
}

/*
 * Cetak, tandai lunas, dan batalkan lunas TIDAK ada di halaman ini lagi.
 *
 * Ketiganya pindah ke halaman Tagihan bersama tabelnya. Fungsi-fungsi ini
 * sengaja dihapus, bukan dibiarkan tidak terpakai: versi lama menandai
 * lunas dari daftar yang tidak dipaginasi per lokasi, jadi hasilnya bisa
 * berbeda dari yang dilihat di halaman tagihan -- dan dua tempat untuk
 * mengubah status yang sama akan selalu menghasilkan dua tempat untuk
 * menampilkan status yang berbeda.
 */

onMounted(() => {
  loadLocation()
  loadInvoices()
})
</script>

<style scoped>
/*
 | Warna diambil dari token `main.css`, sama seperti `CentralLokasiIndex.vue`.
 | Halaman ini dan daftar tenant dibaca bergantian, dan keduanya harus terasa
 | seperti satu panel.
 */
/*
| `max-width`/`margin` yang dulu ada di sini dihapus -- 74rem = 1184px, dan
| itu satu-satunya lebar di panel pusat yang dibatasi. Form tenant punya angka
| yang sama, sementara dashboard, daftar tenant, tagihan, user pusat, dan
| struktur akun melebar penuh. Hasilnya di layar lebar: halaman detail berdiri
| di tengah dengan ruang kosong besar di kiri-kanan, dan form "menjorok ke
| dalam" setiap kali dibuka dari daftar tenant.
|
| Sekarang keduanya ikut `--central-content-max` yang sama dengan semua
| halaman lain (lihat `CentralLayout.vue`), jadi berpindah menu tidak lagi
| menggeser seluruh isi ke dalam atau keluar.
|
| `min-width: 0` wajib di flex column yang berisi `.detail-body`: tanpa itu,
| grid di dalamnya ikut memuai sebesar min-content-nya -- satu nama lokasi
| panjang atau satu nominal rupiah lebar akan mendorong kolom utama keluar.
*/
.detail-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  width: 100%;
  max-width: var(--central-content-max, 1200px);
  margin-inline: auto;
  min-width: 0;
}

/* ===== Baris kendali ===== */
.detail-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
  padding: 0.65rem 0.75rem;
  background: var(--color-card-bg, #fff);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-lg, 0.75rem);
  /* Baris kendali ini melayang (sticky) di atas isi yang digulir, jadi
     butuh bayangan -- tanpa itu kartu yang lewat underneath-nya terlihat
     menempel saat halaman digulir. Nilai dari CentralLayout.vue, sama
     seperti kartu utama halaman lain. */
  box-shadow: var(--central-shadow-card);
}

/*
 | "Kembali" kehilangan bordernya sendiri dan pakai warna teks biasa.
 |
 | Alasannya bukan sekadar biar lebih ringan: di baris ini ada tiga tombol,
 | dan hanya dua yang melakukan sesuatu terhadap tenant ini. "Kembali" cuma
 | menggeser tampilan, dan membungkusnya dengan kotak yang sama seperti
 | tombol lain membuat orang membacanya sebagai aksi ketiga yang setara
 | dengan "Ubah" dan "Nonaktifkan" -- dua aksi yang tidak bisa dikembalikan.
 */
.detail-bar__back {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 0.75rem;
  border: 1px solid transparent;
  border-radius: 9px;
  background: transparent;
  color: var(--color-gray-500, #6b7280);
  font-size: 0.8rem;
  font-weight: 700;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.18s ease;
}

.detail-bar__back:hover {
  background: var(--color-gray-100, #f1f5f9);
  border-color: var(--color-border, #e2e8f0);
  color: var(--color-text-main, #1e293b);
}

.detail-actions {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

/* ===== Badan ===== */
.detail-body {
  display: grid;
  grid-template-columns: 19rem minmax(0, 1fr);
  gap: 1rem;
  align-items: start;
}

.detail-col {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
}

@media (max-width: 1024px) {
  .detail-body {
    grid-template-columns: 1fr;
  }
}

/* ===== Kolom kiri =====
 *
 | `sticky` dipindah dari `.rail` ke pembungkus kolomnya, `.detail-side`.
 |
 | Rail yang menempel sendiri: begitu kartu tagihan ditambahkan di
 | bawahnya, kartu itu ikut bergulir ke atas dan menghilang di belakang
 | kartu identitas yang sedang menempel -- persis di saat orang sedang
 | membacanya. Yang perlu tetap terlihat saat halaman digulir ke panel
 | yang lebih panjang di kanan adalah seluruh kolom kiri, bukan kartu
 | identitas saja.
 |
 | Offset-nya sama seperti rail yang lama: tinggi topnav (56px) + jarak
 | 1rem. Tanpa jarak, kolom ini menempel tepat di bawah topnav dan saat
 | halaman digulir terlihat menempel pada elemen yang kebetulan ada di
 | atasnya.
 */
.detail-side {
  position: sticky;
  top: calc(56px + 1rem);
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
}

@media (max-width: 1024px) {
  /* Di bawah 1024px kolom ini jadi baris penuh, dan "menempel" tidak
     berarti apa-apa -- tidak ada yang bisa digulir di sebelahnya. */
  .detail-side {
    position: static;
  }
}

/* ===== Rail identitas ===== */
.rail {
  background: var(--color-card-bg, #fff);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-xl, 1rem);
  /* Elevasi penuh, sama dengan kartu utama halaman lain. Nilai dari
     CentralLayout.vue. */
  box-shadow: var(--central-shadow-card);
  overflow: hidden;
}

.rail__head {
  padding: 1.15rem 1.15rem 1.1rem;
  background: linear-gradient(
    150deg,
    var(--color-primary-dark, #0284c7),
    var(--color-primary, #0ea5e9)
  );
  color: #fff;
}

.rail__mark {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.6rem;
  height: 2.6rem;
  margin-bottom: 0.75rem;
  border-radius: 10px;
  background: rgb(255 255 255 / 0.18);
  font-size: 1.05rem;
}

.rail__name {
  font-size: 1.05rem;
  font-weight: 800;
  line-height: 1.25;
  margin: 0;
  overflow-wrap: anywhere;
}

.rail__slug {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.72rem;
  color: rgb(255 255 255 / 0.75);
  margin: 0.2rem 0 0.7rem;
  overflow-wrap: anywhere;
}

.rail__status {
  background: rgb(255 255 255 / 0.2);
  color: #fff;
}

.rail__facts {
  margin: 0;
  padding: 0.35rem 1.15rem 0.7rem;
}

.rail__fact {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.55rem 0;
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
}

.rail__fact:last-child {
  border-bottom: none;
}

.rail__fact dt {
  font-size: 0.73rem;
  font-weight: 600;
  color: var(--color-gray-500, #6b7280);
  flex-shrink: 0;
}

.rail__fact dd {
  margin: 0;
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--color-text-main, #1e293b);
  text-align: right;
  min-width: 0;
  overflow-wrap: anywhere;
}

/* `.rail__clear` dan `.rail__debt*` yang pernah ada di sini dihapus
   bersama baris "Tagihan" dari daftar fakta. Baris itu pindah ke kartu
   tagihan di bawah dan sekarang punya warnanya sendiri; menyisakan
   aturannya di sini hanya aturan yang tidak terpakai. */

/* ===== Kartu tagihan =====
 *
 * Kartu di bawah rail, bukan panel di kolom kanan.
 *
 * Semua nilai di sini mengikuti lebar kolomnya (19rem), bukan lebar
 * halaman. Itu yang membedakan kartu ini dari panel-panel di kanan: di
 * 19rem tidak ada ruang untuk tabel lima kolom, tapi ada ruang untuk
 * tiga baris per tagihan -- jadi bentuknya kartu, bukan tabel yang
 * dipotong.
 */
.tagihan-card-panel {
  background: var(--color-card-bg, #fff);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-xl, 1rem);
  /* Elevasi penuh, sama seperti kartu identitas di atasnya. Dua kartu
     di satu kolom dengan bayangan berbeda akan terlihat seperti satu
     kartu yang salah tempat. */
  box-shadow: var(--central-shadow-card);
  overflow: hidden;
}

.tagihan-card-panel__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.85rem 1.05rem;
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
}

.tagihan-card-panel__head h3 {
  font-size: 0.88rem;
  font-weight: 800;
  color: var(--color-text-main, #1e293b);
  margin: 0;
}

/* Jumlah tagihan sebagai pelengkap di kanan judul, bukan baris sendiri.
 *
 * "7 tagihan" adalah keterangan, bukan berita -- sedangkan "2 belum lunas"
 * yang ada di bawahnya itu berita. Yang penting naik ke atas berita. */
.tagihan-card-panel__count {
  font-size: 0.7rem;
  font-weight: 700;
  color: var(--color-gray-400, #94a3b8);
  white-space: nowrap;
}

/* ===== Ringkasan =====
 *
 * Satu blok berwarna dengan dua baris isinya, bukan dua sel.
 *
 * Warnanya mengikuti isinya dan bukan warna tombol: kartu ini menampilkan
 * keadaan, dan tidak ada yang bisa dilakukan padanya dari sini -- jadi
 * merah berarti "perlu diketahui", bukan "klik untuk memperbaiki".
 */
.tagihan-sum {
  padding: 0.9rem 1.05rem;
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
}

.tagihan-sum.is-ok {
  background: #f0fdf4;
}

.tagihan-sum.is-bad {
  background: #fef2f2;
}

.tagihan-sum__label {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.7rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.tagihan-sum.is-ok .tagihan-sum__label {
  color: #047857;
}

.tagihan-sum.is-bad .tagihan-sum__label {
  color: #b91c1c;
}

.tagihan-sum__label svg {
  font-size: 0.72rem;
}

.tagihan-sum__value {
  margin: 0.4rem 0 0;
  font-size: 1.15rem;
  font-weight: 800;
  color: var(--color-text-main, #1e293b);
  line-height: 1.2;
}

/* Nominal adalah angka yang dicari, jadi ia yang terbesar -- bukan jumlah
   tagihan ("2 belum lunas"), yang cuma memberi konteks. */
.tagihan-sum__amount {
  margin: 0.15rem 0 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.95rem;
  font-weight: 700;
  color: #b91c1c;
}

.tagihan-sum.is-ok .tagihan-sum__amount {
  color: #047857;
}

/* ===== Daftar terakhir ===== */
.tagihan-mini {
  list-style: none;
  margin: 0;
  padding: 0.4rem 0.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

/* Baris bisa diklik ke daftar lengkap lokasi ini, jadi `cursor: pointer`
   dan hover-nya wajib -- tanpa itu, hover jadi tanda bahwa ada
   tautan yang tidak terlihat seperti tautan. */
.tagihan-mini__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.15rem 0.5rem;
  padding: 0.45rem 0.55rem;
  border-radius: var(--border-radius-md, 0.5rem);
  text-decoration: none;
  transition: background 0.16s ease;
}

.tagihan-mini__row:hover {
  background: var(--color-gray-100, #f1f5f9);
}

.tagihan-mini__row:focus-visible {
  outline: 2px solid var(--color-primary, #0ea5e9);
  outline-offset: -2px;
}

.tagihan-mini__ident {
  display: flex;
  align-items: baseline;
  gap: 0.4rem;
  min-width: 0;
}

.tagihan-mini__nomor {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--color-text-main, #1e293b);
  white-space: nowrap;
}

/* Tanggal tidak punya satuan rupiah dan tidak jadi pencarian, jadi
   diturunkan satu tingkat dari nomor -- cukup untuk bekannt, tidak
   cukup untuk mengalahkan. */
.tagihan-mini__tanggal {
  font-size: 0.68rem;
  font-weight: 600;
  color: var(--color-gray-400, #94a3b8);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.tagihan-mini__amount {
  grid-column: 1;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--color-text-main, #1e293b);
  white-space: nowrap;
}

/* Status di kanan baris bawah, rata kanan dengan nominal di atasnya --
   jadi kolom Status dan kolom Jumlah punya tepi kanan yang sama. */
.tagihan-mini__status {
  grid-column: 2;
  grid-row: 2;
  justify-self: end;
  font-size: 0.62rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  white-space: nowrap;
}

.tagihan-mini__status.is-lunas {
  color: #047857;
}

.tagihan-mini__status.is-belum {
  color: #b45309;
}

.tagihan-mini__status.is-lambat {
  color: #b91c1c;
}

/* ===== Keadaan kosong ===== */
.tagihan-card-panel__empty {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  margin: 0;
  padding: 1.4rem 1.05rem;
  font-size: 0.76rem;
  font-weight: 600;
  color: var(--color-gray-400, #94a3b8);
  text-align: center;
}

/* ===== Aksi =====
 *
 * Dua tombol vertikal, bukan `btn--sm` berdampingan.
 *
 * "Riwayat Tagihan" dan "Terbitkan Tagihan" di kolom 19rem: ditumpuk
 * keduanya punya lebar penuh dan ukuran font normal; berdampingan keduanya
 * harus jadi seuprit supaya muat, dan teks yang diperkecil supaya
 * muat adalah teks yang berhenti terbaca.
 */
.tagihan-card-panel__aksi {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.7rem 1.05rem 1rem;
  border-top: 1px solid var(--color-gray-100, #f1f5f9);
}

.btn--block {
  width: 100%;
  justify-content: center;
}

/* ===== Panel ===== */
.panel {
  background: var(--color-card-bg, #fff);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-xl, 1rem);
  /* Elevasi penuh. Tanpa ini, kartu identitas dan kartu domain di sebelah
     kanan berdiri datar di atas latar abu muda, sementara rail yang di
     sebelah kiri mengambang -- jadi kolom yang justru paling penting
     terlihat paling tempel. */
  box-shadow: var(--central-shadow-card);
  overflow: hidden;
}

.panel--state {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
  padding: 3rem 1.5rem;
  color: var(--color-gray-400, #94a3b8);
  font-size: 0.85rem;
  font-weight: 600;
}

.panel--state svg {
  color: var(--color-primary, #0ea5e9);
  font-size: 1.3rem;
}

.panel__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  padding: 0.9rem 1.15rem;
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
}

.panel__head h3 {
  font-size: 0.88rem;
  font-weight: 800;
  color: var(--color-text-main, #1e293b);
  margin: 0;
}

.panel__sub {
  font-size: 0.73rem;
  color: var(--color-gray-400, #94a3b8);
  margin: 0.15rem 0 0;
}

.panel__state {
  padding: 2.5rem 1.5rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.5rem;
  color: var(--color-gray-400, #94a3b8);
  font-size: 0.82rem;
  font-weight: 600;
}

.panel__state p {
  margin: 0;
}

.state-icon {
  font-size: 1.7rem;
  color: var(--color-gray-300, #cbd5e1);
}

.panel__empty {
  padding: 1.5rem 1.15rem;
  margin: 0;
  font-size: 0.8rem;
  color: var(--color-gray-400, #94a3b8);
}

/* ===== Grid informasi =====
 *
    * Sel-sel dipisah oleh garis 1px yang dibuat dari `gap: 1px` di atas
    * background abu, bukan `border` per sel. Alasannya: `border` per sel
    * menggandakan garis di tengah dan tepi, jadi garis yang sama antar sel
    * sel terlihat lebih tebal di sela-sela. `gap: 1px` plus latar abu
    * menghasilkan satu garis yang sama tebalnya di seluruh grid, dan kotak
    * terakhir otomatis sama tinggi dengan yang di sebelahnya -- dua hal yang
    * tidak dijamin kalau tinggi setiap sel ditentukan sendiri-sendiri.
 */
.info-grid {
  display: grid;
  /* Tiga kolom eksplisit, bukan `auto-fit minmax(13rem)`.
     --
     * `auto-fit` menghitung jumlah kolom dari lebar yang tersedia, jadi
     * jumlah kolom terakhirnya yang menentukan -- dan field terakhir yang
     * ganjil selalu berakhir sendirian di baris paling bawah dengan dua
     * sel kosong selebar kolom di sebelahnya. Itulah blok abu kosong di
     * panel Informasi: bukan isian yang belum diisi, tapi pembagian kolom
     * yang tidak pernah tahu ada berapa field.
     *
     * Tiga kolom eksplisit membuat setiap field tahu sejak awal kolom mana
     * pun dia tempati. Field keempat -- Telepon -- lalu diberi `span 2`
     * di bawah supaya dia mengisi lebar baris kedua sendiri, dan sisa
     * kosongnya tidak terlihat karena baris itu memang sudah habis. */
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1px;
  background: var(--color-gray-100, #f1f5f9);
}

.info-cell {
  background: var(--color-card-bg, #fff);
  padding: 0.85rem 1.15rem;
}

/* Kalau panel ini nanti dapat field kelima, hapus aturan ini dulu sebelum
   menambah kolom -- dua tempat yang harus diubah untuk satu perubahan
   grid membuat kesalahan yang sama terulang dua kali. */
.info-cell--telepon {
  grid-column: span 2;
}

@media (max-width: 68rem) {
  .info-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .info-cell--telepon {
    /* Di dua kolom, `span 2` masih aman: baris kedua memang hanya punya
       Telepon, dan sel kosongnya tidak cukup lebar untuk terlihat seperti
       panel yang belum selesai diisi. */
    grid-column: span 2;
  }
}

@media (max-width: 40rem) {
  .info-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .info-cell--telepon {
    grid-column: auto;
  }
}

.info-cell__label {
  font-size: 0.66rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: var(--color-gray-400, #94a3b8);
  margin: 0;
}

.info-cell__value {
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--color-text-main, #1e293b);
  margin: 0.3rem 0 0;
  overflow-wrap: anywhere;
}

/* ===== Domain ===== */
.domain-list {
  list-style: none;
  margin: 0;
  padding: 0.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.domain-item {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  flex-wrap: wrap;
  padding: 0.55rem 0.65rem;
  border-radius: var(--border-radius-md, 0.5rem);
  background: var(--color-gray-100, #f1f5f9);
}

.domain-item:hover {
  background: var(--color-gray-50, #f8fafc);
}

.domain-item__icon {
  font-size: 0.75rem;
  color: var(--color-gray-400, #94a3b8);
  flex-shrink: 0;
}

.domain-item__host {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--color-text-main, #1e293b);
  text-decoration: none;
  overflow-wrap: anywhere;
}

a.domain-item__host {
  color: var(--color-primary-dark, #0284c7);
}

a.domain-item__host:hover {
  text-decoration: underline;
}

.domain-item__tags {
  display: inline-flex;
  gap: 0.3rem;
  flex-wrap: wrap;
  margin-left: auto;
}

.domain-tag {
  display: inline-flex;
  align-items: center;
  padding: 0.08rem 0.45rem;
  border-radius: var(--radius-full, 9999px);
  background: var(--color-gray-100, #f1f5f9);
  color: var(--color-gray-500, #6b7280);
  font-size: 0.62rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  white-space: nowrap;
}

.domain-tag--primary {
  background: var(--color-primary-light, #e0f2fe);
  color: var(--color-primary-dark, #0284c7);
}

.domain-tag--off {
  background: var(--color-red-50, #fee2e2);
  color: var(--color-red-800, #991b1b);
}

/* ===== Infrastruktur ===== */
.infra {
  padding: 0.85rem 1.15rem;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.infra-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
}

.infra-label {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.76rem;
  font-weight: 700;
  color: var(--color-gray-500, #6b7280);
}

.infra-label svg {
  font-size: 0.78rem;
  color: var(--color-gray-400, #94a3b8);
}

.infra-value {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--color-text-main, #1e293b);
  background: var(--color-gray-100, #f1f5f9);
  padding: 0.2rem 0.5rem;
  border-radius: 6px;
  overflow-wrap: anywhere;
}

.infra-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.72rem;
  font-weight: 700;
  white-space: nowrap;
}

.infra-badge--ok {
  color: var(--color-success, #10b981);
}

.infra-badge--warn {
  color: var(--color-warning, #f59e0b);
}

.infra-warning {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  margin: 0 1.15rem 0.85rem;
  padding: 0.7rem 0.85rem;
  background: var(--color-yellow-50, #fef3c7);
  border: 1px solid color-mix(in srgb, var(--color-warning, #f59e0b) 30%, transparent);
  border-radius: var(--border-radius-sm);
  font-size: 0.75rem;
  line-height: 1.5;
  color: var(--color-yellow-800, #92400e);
}

.infra-warning svg {
  flex-shrink: 0;
  margin-top: 0.1rem;
}

.tenant-hint {
  margin: 0 1.15rem 1.15rem;
  padding: 0.8rem 0.9rem;
  background: var(--color-gray-50, #f8fafc);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-md, 0.5rem);
}

.tenant-hint__label {
  font-size: 0.66rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--color-gray-400, #94a3b8);
  margin: 0 0 0.35rem;
}

.tenant-hint__value {
  margin: 0 0 0.45rem;
}

.tenant-hint__desc {
  font-size: 0.73rem;
  color: var(--color-gray-500, #6b7280);
  line-height: 1.55;
  margin: 0;
}

.tenant-hint code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.72rem;
  background: var(--color-gray-100, #f1f5f9);
  padding: 0.05rem 0.3rem;
  border-radius: 4px;
  color: var(--color-primary-dark, #0284c7);
  overflow-wrap: anywhere;
}

/* Fokus keyboard tidak boleh hilang di tombol mana pun di halaman ini. */
.detail-bar__back:focus-visible,
.btn:focus-visible {
  outline: 2px solid var(--color-primary, #0ea5e9);
  outline-offset: 2px;
}

/* Styling `.modal-overlay` / `.modal-box` TIDAK ada di file ini.
 *
 * Semula ada, dan itu sebabnya dialog pembayaran di `CentralBayarDialog.vue`
 * tampil tanpa bentuk: file ini memakai `<style scoped>`, jadi setiap
 * aturannya hanya berlaku untuk elemen yang dirender komponen INI -- padahal
 * dialog itu memakai kelas yang sama persis dari komponen yang berbeda.
 * Aturan scoped Vue hanya cocok dengan elemen yang punya atribut
 * `data-v-<id>` milik komponen itu, dan elemen di `CentralBayarDialog.vue`
 * punya milik dialog itu.
 *
 * Konsekuensnya bukan satu dialog yang sedikit jelek: `.modal-box` tanpa
 * `display: flex`, `max-height`, dan `overflow: hidden` berubah jadi blok
 * biasa yang ikut mengikuti aturan halaman, sehingga kepala, isi, dan kaki
 * kehilangan batas visualnya dan seluruh form menutupi layar.
 *
 * Sekarang semuanya tinggal di `central-form.css`, yang diimpor oleh kedua
 * file. Jangan dikembalikan ke sini.
 */

/* ===== RESPONSIF ===== */
@media (max-width: 46rem) {
  .detail-bar {
    /* Tombol-tombolnya jadi lebar penuh. Di bawah ~46rem, "Kembali ke Daftar
       Tenant" + "Ubah" + "Nonaktifkan" dalam satu baris hanya menyisakan
       ruang untuk ikon, dan ketiganya kehilangan teksnya -- persis
       kebalikan dari yang membuatnya lebih mudah dibaca. */
    flex-direction: column;
    align-items: stretch;
  }

  .detail-bar__back,
  .detail-actions,
  .detail-actions .btn {
    justify-content: center;
  }

  .detail-actions .btn {
    flex: 1;
  }

  .panel__head {
    align-items: flex-start;
    flex-direction: column;
    gap: 0.7rem;
  }

  /*
   * Tidak ada aturan khusus untuk modal di layar sempit.
   *
   * `.form-grid` di dalam modal memakai `auto-fit minmax(15rem, 1fr)`,
   * jadi isian otomatis turun ke satu kolom di 390px tanpa bantuan CSS
   * tambahan. Baris ini hanya mencatat itu -- supaya tidak ada yang
   * menambahkan aturan grid sendiri nanti dan mengira grid-nya sendiri
   * yang mengurus kolom.
   */
}
</style>
