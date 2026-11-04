<template>
  <div class="mg">
    <!-- ===== Langkah 0: pilih lokasi ===== -->
    <!--
      Lokasi dipilih DI ATAS segalanya, termasuk sebelum jenis data.

      Alasannya bukan soal tampilan: satu lokasi = satu database, dan
      seluruh isi template (sheet "Referensi" berisi nama paket, nama desa,
      jabatan) dibaca dari database lokasi itu. Template "Pelanggan" untuk
      Lokasi A tidak bisa dipakai di Lokasi B -- paket yang ada di sana
      tidak ada di sini -- jadi jenis data saja tidak cukup untuk memulai.

      Lokasi yang dipilih juga tidak pernah disimpan di URL atau storage.
      Ini keputusan yang disengaja: kalau lokasi tersimpan, membuka halaman
      ini lagi akan langsung menampilkan template milik lokasi lama di
      kolom unduh, dan tidak ada yang ingat bahwa ini bukan lokasi yang
      sedang dia pikirkan. Memilih ulang butuh satu klik, dan klik itulah
      yang membuat pilihan itu disadari.
    -->
    <div class="mg-pick">
      <label class="mg-field">
        <span class="mg-field__label">Masuk ke lokasi</span>

        <!--
          Pakai `BaseSelect`, bukan `<select>` native.

          Alasannya sama seperti di filter halaman central lain (lihat
          catatan panjangnya di `BaseSelect.vue`): daftar lokasi punya
          label panjang yang akan terpotong di layar sempit, tidak ada
          cara mengetik sebagian nama untuk mencari, dan tampilan native
          berbeda di tiap perangkat. Panel pusat dibaca dari perangkat
          yang varied, jadi pilihan tidak boleh bergantung perangkat.

          Label opsi dibuat gabungan di {@see opsiLokasi} karena PrimeVue
          hanya bisa mencari berdasarkan `optionLabel` -- jadi nama, slug,
          dan nama lembaga harus semuanya ikut di dalam satu teks.

          `memuatLokasi` (ref), BUKAN `muatLokasi` (fungsi pemanggilnya).
          Dua nama itu hanya berbeda satu huruf dan hampir sama persis,
          tapi karena `muatLokasi` adalah fungsi yang selalu bernilai
          benar, spinner-nya menyala permanen baik request masih jalan
          maupun sudah selesai. Select-nya terlihat "loading terus"
          padahal daftar lokasinya sudah terisi dan bisa diklik.

          Yang membuat ini bertahan lama adalah ref `memuatLokasi`
          dipakai di baris lain (syarat pesan "tidak ada lokasi")
          tanpa error, jadi tidak ada
          yang memberi tahu kalau bind spinner salah -- halaman tetap
          jalan, semua langkah berikutnya berfungsi, dan yang macet hanya
          satu elemen kecil di layar.
        -->
        <BaseSelect
          v-model="lokasi"
          :options="opsiLokasi"
          option-label="label"
          option-value="value"
          placeholder="Pilih lokasi tujuan..."
          :loading="memuatLokasi"
          :disabled="sibukCommit || sibukUnggah"
          filter-placeholder="Ketik nama lokasi..."
        />
      </label>

      <p class="mg-pick__note">
        <font-awesome-icon icon="circle-info" />
        <span>
          Data yang diimpor <strong>langsung ditulis ke database lokasi ini</strong>. Data tidak ada
          di panel pusat, dan tidak bisa dibatalkan dari sini setelah ditulis.
        </span>
      </p>

      <!--
        Kegagalan dimuat, dengan tombol mencoba lagi.

        Dipisah dari "tidak ada lokasi yang cocok" di bawah karena itu
        dua masalah dengan tindakan yang berbeda: yang satu tombolnya
        mencobanya lagi, yang satu tidak ada yang bisa dicoba karena
        memang tidak ada lokasi yang layak. Kalau keduanya memakai
        tampilan yang sama, orang akan menekan "Coba lagi" berulang
        kali untuk masalah yang tidak akan pernah berubah.
      -->
      <p v-if="lokasiError" class="mg-pick__empty mg-pick__empty--salah">
        <font-awesome-icon icon="circle-exclamation" />
        <span>
          {{ lokasiError }}
          <button type="button" class="mg-link" @click="muatLokasi">Coba lagi</button>
        </span>
      </p>

      <!--
        Tidak ada lokasi yang bisa ditulis = halaman ini tidak bisa dipakai.

        Ditampilkan sebagai pesan, bukan dibiarkan jadi dropdown kosong:
        daftar kosong tanpa penjelasan membuat orang mengira daftar lokasinya
        memang cuma segitu, lalu menebak-nebak dan lap bug. Yang terjadi
        sebenarnya adalah lokasi tidak aktif atau database-nya sudah dihapus
        dari server -- keduanya sudah disaring di {@see muatLokasi}.
      -->
      <p v-else-if="!memuatLokasi && opsiLokasi.length === 0" class="mg-pick__empty">
        <font-awesome-icon icon="circle-exclamation" />
        <span>
          Tidak ada lokasi yang bisa jadi tujuan migrasi. Hanya lokasi
          <strong>aktif</strong> yang databasenya masih ada di server yang bisa ditulis.
        </span>
      </p>
    </div>

    <!-- ===== Daftar jenis data ===== -->
    <div v-if="loadingIndex" class="mg-grid" aria-hidden="true">
      <div v-for="n in 5" :key="`sk-${n}`" class="mg-card mg-card--skeleton">
        <span class="mg-sk mg-sk--icon" />
        <span class="mg-sk mg-sk--line mg-sk--w60" />
        <span class="mg-sk mg-sk--line mg-sk--w85" />
      </div>
    </div>

    <div v-else-if="indexError" class="mg-panel">
      <font-awesome-icon icon="circle-exclamation" class="mg-panel__icon" />
      <p class="mg-panel__title">Daftar jenis data tidak bisa dimuat</p>
      <p class="mg-panel__text">{{ indexError }}</p>
      <button type="button" class="mg-btn mg-btn--primary" @click="muatIndex">Coba lagi</button>
    </div>

    <div v-else class="mg-grid">
      <!--
        Seluruh kartu bisa diklik untuk memilih jenis yang sedang dimigrasi,
        BUKAN hanya tombol di dalamnya.

        Yang diklik di halaman beranda adalah kartunya, bukan tombol kecil di
        sudutnya. Membatasi klik ke tombol memaksa orang membidik target
        kecil -- dan sekali klik keliru di tempat lain, panel di bawahnya
        hilang, bersama hasil validasi yang sudah jadi.

        Tombol di dalam kartu tetap punya `stop`, supaya mengunduh template
        tidak ikut mengganti jenis yang dipilih.
      -->
      <article
        v-for="jenis in jenisList"
        :key="jenis.type"
        class="mg-card"
        :class="{ 'mg-card--active': aktifType === jenis.type }"
        role="button"
        tabindex="0"
        :aria-pressed="aktifType === jenis.type"
        @click="aktifType = jenis.type"
        @keydown.enter.prevent="aktifType = jenis.type"
        @keydown.space.prevent="aktifType = jenis.type"
      >
        <header class="mg-card__head">
          <span class="mg-card__icon">
            <font-awesome-icon :icon="jenis.icon" />
          </span>

          <div class="mg-card__titles">
            <h3 class="mg-card__title">{{ jenis.label }}</h3>
            <p class="mg-card__sub">{{ jenis.tabel.join(', ') }}</p>
          </div>
        </header>

        <p class="mg-card__desc">{{ jenis.deskripsi }}</p>

        <ol class="mg-steps">
          <li><span class="mg-steps__n">1</span> Unduh template</li>
          <li><span class="mg-steps__n">2</span> Isi di spreadsheet</li>
          <li><span class="mg-steps__n">3</span> Unggah &amp; periksa</li>
        </ol>

        <p class="mg-card__cols">
          {{ jenis.kolom.length }} kolom
          <template v-if="jenis.punyaReferensi"
            >&mdash; sheet Referensi diambil dari lokasi</template
          >
        </p>

        <div class="mg-card__actions">
          <!--
            Tombol unduh DISABLED sebelum lokasi dipilih, bukan disembunyikan.

            disembunyikan membuat orang mencari tombol yang tidak ada; dinonaktifkan
            menjelaskan APA yang kurang. `disabled` Plus `title` supaya kursor
            berhenti di situ dan alasannya terbaca.
          -->
          <button
            type="button"
            class="mg-btn mg-btn--ghost"
            :disabled="!lokasi || mengunduh === jenis.type"
            :title="lokasi ? '' : 'Pilih lokasi dulu'"
            @click.stop="unduhTemplate(jenis.type, 'xlsx')"
          >
            <font-awesome-icon icon="file-export" />
            {{ mengunduh === jenis.type ? 'Menyusun...' : 'Unduh .xlsx' }}
          </button>

          <button
            type="button"
            class="mg-btn mg-btn--ghost"
            :disabled="!lokasi || mengunduh === jenis.type"
            :title="lokasi ? '' : 'Pilih lokasi dulu'"
            @click.stop="unduhTemplate(jenis.type, 'csv')"
          >
            .csv
          </button>
        </div>
      </article>
    </div>

    <!-- ===== Panel kerja ===== -->
    <section v-if="lokasi && aktifType" class="mg-work">
      <header class="mg-work__head">
        <div>
          <h2 class="mg-work__title">
            <font-awesome-icon :icon="jenisAktif?.icon" />
            {{ jenisAktif?.label }}
          </h2>
          <p class="mg-work__sub">
            tujuan: <strong>{{ namaLokasiTerpilih }}</strong>
            <span class="mg-work__sep">/</span>
            slug <code>{{ lokasi }}</code>
          </p>
        </div>

        <span class="mg-work__step">
          <ol class="mg-wizard" aria-label="Tahap migrasi">
            <li
              v-for="s in tahapWizard"
              :key="s.n"
              class="mg-wizard__item"
              :class="`mg-wizard__item--${s.state}`"
              :aria-current="s.state === 'kini' ? 'step' : undefined"
            >
              <span class="mg-wizard__n">{{ s.n }}</span>
              <span class="mg-wizard__label">{{ s.label }}</span>
            </li>
          </ol>
        </span>
      </header>

      <!--
        ===== Daftar kolom template =====

        Berada di dalam panel kerja, bukan di kartu jenis data, karena
        isinya ikut berubah sesuai lokasi -- dan hanya setelah lokasi
        dipilih nilainya benar.

        Ini memendekkan lintasan "unduh template -> buka Excel -> cari
        sheet Petunjuk". Petunjuk memang ada di dalam template, tapi
        sheet itu hanya dibaca kalau orang ingat untuk membukanya,
        sedangkan penolakan validasi selalu dibaca. Karena itu kolom
        yang BENAR-BENAR ditolak backend ditampilkan di tempat yang
        sama dengan penolakannya.

        Tiap baris menyebut tiga hal: nama kolom, apakah wajib, dan
        contoh isinya. Contoh itu bukan hiasan: "Nama Paket" dan
        "Desa" harus PERSIS sama dengan yang ada di lokasi, dan daftar
        lengkapnya ada di sheet Referensi yang harus dibuka terpisah.
        Satu contoh yang benar sudah menutup sebagian besar kasus.
      -->
      <details v-if="lokasi && jenisAktif" class="mg-ref">
        <summary class="mg-ref__sum">
          <font-awesome-icon icon="table-list" />
          <span>
            {{ jenisAktif.label }} punya
            <strong>{{ jenisAktif.kolom.length }} kolom</strong> &mdash;
            <strong>{{ wajibCount }}</strong> wajib diisi
          </span>
          <font-awesome-icon icon="chevron-down" class="mg-ref__caret" />
        </summary>

        <div class="mg-ref__body">
          <table class="mg-ref__table">
            <thead>
              <tr>
                <th scope="col" class="mg-ref__c-nama">Kolom</th>
                <th scope="col" class="mg-ref__c-contoh">Contoh isi</th>
                <th scope="col" class="mg-ref__c-opsi">Pilihan yang diterima</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="kol in jenisAktif.kolom" :key="kol.key">
                <td class="mg-ref__c-nama">
                  {{ kol.label }}
                  <span v-if="kol.required" class="mg-ref__wajib" title="Wajib diisi">wajib</span>
                  <span v-else class="mg-ref__opsional">opsional</span>
                </td>
                <td class="mg-ref__c-contoh">
                  <code v-if="kol.example">{{ kol.example }}</code>
                  <span v-else class="mg-ref__kosong">&mdash;</span>
                </td>
                <td class="mg-ref__c-opsi">
                  <!--
                    Kolom BERPILIH ditampilkan utuh, bukan dipangkas.

                    Kalau dipangkas, orang tidak akan bisa melihat seluruh
                    daftar tanpa membuka sheet Referensi -- dan itulah
                    langkah yang ingin kita hindari. Daftar di sini
                    pendek (jabatan, status), jadi tidak pernah jadi
                    masalah panjang.
                  -->
                  <span v-if="kol.options?.length" class="mg-ref__opts">
                    <span v-for="o in kol.options" :key="o" class="mg-chip mg-chip--netral">
                      {{ o }}
                    </span>
                  </span>
                  <span v-else class="mg-ref__kosong">&mdash;</span>
                </td>
              </tr>
            </tbody>
          </table>

          <!--
            Catatan sheet Referensi hanya untuk jenis yang memang
            memilikinya. `punyaReferensi` datang dari backend, jadi ini
            ikut benar kalau nanti ada jenis baru yang tidak punya data
            acuan -- panelnya menyesuaikan tanpa perlu diubah di sini.
          -->
          <p v-if="punyaSheetReferensi" class="mg-ref__catatan">
            <font-awesome-icon icon="circle-info" />
            <span>
              Kolom yang isinya harus sama dengan data di lokasi punya daftar lengkap di sheet
              <strong>Referensi</strong> pada template yang diunduh. Nilai di luar daftar itu akan
              ditolak.
            </span>
          </p>

          <p class="mg-ref__catatan mg-ref__catatan--redup">
            <font-awesome-icon icon="circle-info" />
            <span>
              Nama dan urutan kolom harus sama persis seperti di template. Kalau kolom dikosongkan,
              biarkan kosong -- jangan dihapus dari file.
            </span>
          </p>
        </div>
      </details>

      <!-- ===== 2. Unggah ===== -->
      <div v-if="tahap === 'unggah'" class="mg-drop-wrap">
        <!--
          Area jatuh-lepas yang juga bisa diklik.

          Dua cara masuk sekaligus, karena tidak semua orang nyaman
          dengan drag-and-drop -- terutama yang pakai ponsel, tempat
          seret-lepas tidak ada sama sekali. Area diklik untuk
          membuka pemilih file, dan tombol di dalamnya memanggil hal
          yang sama supaya yang mengarahkan kursor ke sana punya target
          yang kelihatan.
        -->
        <div
          class="mg-drop"
          :class="{ 'mg-drop--over': serang, 'mg-drop--busy': sibukUnggah }"
          role="button"
          tabindex="0"
          @click="bukaPemilih"
          @keydown.enter.prevent="bukaPemilih"
          @keydown.space.prevent="bukaPemilih"
          @dragover.prevent="serang = true"
          @dragleave.prevent="serang = false"
          @drop.prevent="tangkapDrop"
        >
          <input
            ref="inputBerkas"
            type="file"
            class="mg-drop__input"
            :accept="diterima"
            @change="tangkapPilih"
          />

          <font-awesome-icon icon="cloud-arrow-up" class="mg-drop__icon" />

          <p class="mg-drop__title">
            {{ serang ? 'Lepaskan di sini' : 'Tarik file ke sini, atau klik untuk memilih' }}
          </p>

          <p class="mg-drop__hint">{{ batasTeks }} &middot; format {{ ekstensiTeks }}</p>

          <!--
            Nama berkas yang SEDANG DIPILIH, dengan ukurannya.

            Sebenarnya tidak wajib: begitu berkas dipilih, layar langsung
            pindah ke tabel hasil, jadi nama berkas tidak pernah tampil
            lama. Tapi pada berkas besar layar itu beberapa detik, dan
            tanpa nama di sana orang mengira berkasnya belum terbaca --
            lalu membatalkannya dan mengunggah ulang.
          -->
          <p v-if="namaBerkas" class="mg-drop__file">
            <font-awesome-icon icon="file-lines" />
            <span class="mg-drop__filename">{{ namaBerkas }}</span>
            <span class="mg-drop__size">{{ ukuranBerkas }}</span>
          </p>

          <!--
            Progress UNGGAH, yang persentasenya nyata.

            Yang diukur di sini adalah transfer BERKAS ke server -- angka
            ini datang dari browser dan benar-benar bergerak. Beda dengan
            progress COMMIT, yang tidak ada persentasenya karena
            backend tidak bisa melapor di tengah request.

            Ditampilkan hanya kalau > 0: angka 0% yang berkedip selama
            200 milidetik sebelum request benar-benar jalan hanya
            berkedip-kedip, dan kedipan tanpa makna lebih buruk
            daripada tidak menampilkan apa pun.
          -->
          <div v-if="sibukUnggah && persenUnggah > 0" class="mg-up">
            <div class="mg-up__bar">
              <span class="mg-up__fill" :style="{ width: persenUnggah + '%' }" />
            </div>
            <p class="mg-up__text">Mengunggah... {{ persenUnggah }}%</p>
          </div>

          <button type="button" class="mg-btn mg-btn--primary" :disabled="sibukUnggah">
            Pilih File
          </button>
        </div>
      </div>

      <!-- ===== 3. Periksa ===== -->
      <div v-else-if="tahap === 'periksa'" class="mg-result">
        <div class="mg-stats" :class="`mg-stats--${tone}`">
          <div class="mg-stat">
            <span class="mg-stat__n">{{ hasil.siap }}</span>
            <span class="mg-stat__l">siap ditulis</span>
          </div>
          <div class="mg-stat">
            <span class="mg-stat__n">{{ hasil.baru }}</span>
            <span class="mg-stat__l">data baru</span>
          </div>
          <div class="mg-stat">
            <span class="mg-stat__n">{{ hasil.diperbarui }}</span>
            <span class="mg-stat__l">diperbarui</span>
          </div>
          <div class="mg-stat" :class="{ 'mg-stat--bad': hasil.gagal > 0 }">
            <span class="mg-stat__n">{{ hasil.gagal }}</span>
            <span class="mg-stat__l">ditolak</span>
          </div>
        </div>

        <p class="mg-result__msg">
          <font-awesome-icon :icon="hasil.siap > 0 ? 'circle-check' : 'triangle-exclamation'" />
          {{ pesanValidasi }}
        </p>

        <p v-if="hasil.gagal > 0" class="mg-result__note">
          Baris yang ditolak TIDAK ikut ditulis. Perbaiki di file asal -- daftar barisnya bisa
          diunduh di bawah -- lalu unggah ulang file itu. File yang sama bisa diunggah berulang
          kali; yang sudah ada di lokasi akan diperbarui, bukan diduplikasi.
        </p>

        <!--
          PREVIEW: isi file apa adanya, dengan sel yang salah ditandai.

          Kolom tabel diambil dari `hasil.kolom` (backend), dan nilainya
          dari `baris.ringkas`. Keduanya sengaja berpasangan di backend:
          `ringkas` berkunci kolom TEMPLATE (`desa`), bukan kolom
          database (`village_name`) yang dikembalikan `mapRow()` -- jadi
          baris yang lolos dan baris yang ditolak bisa berdiri di kolom
          yang sama, dan sel yang salah bisa ditandai.

          Tabel TIDAK disembunyikan di layar sempit. Yang dipotong hanya
          jumlah BARIS-nya (lihat catatan `disembunyikan` di bawahnya),
          dan itu selalu disebutkan. Kolomnya justru yang paling sering
          dibutuhkan justru saat tabel sedang digulir ke kanan -- kalau
          disembunyikan, yang hilang justru jawabannya.
        -->
        <div class="mg-table-wrap">
          <table class="mg-table">
            <thead>
              <tr>
                <th scope="col" class="mg-table__num">Baris</th>
                <th scope="col">Status</th>
                <th scope="col">Keterangan</th>
                <th v-for="kol in kolomPreview" :key="kol.key" scope="col" class="mg-table__col">
                  {{ kol.label
                  }}<span v-if="kol.required" class="mg-table__req" title="Wajib diisi"> *</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="baris in hasil.baris"
                :key="baris.nomor"
                :class="{ 'mg-table__row--bad': baris.status !== 'siap' }"
              >
                <td class="mg-table__num">{{ baris.nomor }}</td>
                <td>
                  <span class="mg-badge" :class="badgeBaris(baris)">
                    {{ labelBaris(baris) }}
                  </span>
                </td>
                <td class="mg-table__msg">
                  <!--
                    Baris yang ditolak SELALU menampilkan pesan aslinya.

                    Placeholder "baru / akan diperbarui" hanya untuk baris
                    yang lolos, karena tidak ada pesan untuk baris itu --
                    dan menampilkan teks yang sama di kolom yang sama
                    membuat orang mencari penjelasan tambahan yang memang
                    tidak ada.
                  -->
                  <span v-if="baris.status === 'siap'" class="mg-table__muted">
                    {{
                      baris.aksi === 'baru'
                        ? 'Belum ada di lokasi ini'
                        : 'Sudah ada, akan diperbarui'
                    }}
                  </span>
                  <template v-else>
                    <span v-if="baris.kolom?.length" class="mg-table__cols">
                      <span v-for="(nama, i) in baris.kolom" :key="nama + i" class="mg-chip">
                        {{ nama }}
                      </span>
                    </span>
                    <span>{{ baris.pesan }}</span>
                  </template>
                </td>

                <!--
                  Sel yang salah diberi tanda TEBAL dan latar merah muda.

                  Bukan hanya teksnya: menandai kolom di header saja tidak
                  cukup, karena yang dicari orang adalah "sel mana di
                  baris ini". Warna baris sudah menandai barisnya, jadi sel
                  yang menonjol di dalam baris itu yang menunjuk kolomnya.
                -->
                <td
                  v-for="kol in kolomPreview"
                  :key="kol.key"
                  :class="{ 'mg-cell--salah': salahDi(baris, kol) }"
                >
                  {{ baris.ringkas?.[kol.key] ?? '-' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!--
          Baris yang TIDAK ikut dikirim ke tabel.

          Pipeline membatasi preview ke 50 baris pertama + 25 terakhir +
          semua baris yang ditolak (lihat `MigrationPipeline::
          potongUntukTampilan()`), jadi untuk file 5.000 baris tabelnya
          benar-benar tidak memuat semuanya. Bilangnya dikirim lewat
          `disembunyikan`.

          Disampaikan, bukan disembunyikan: preview yang dipotong tanpa
          pemberitahuan terlihat sama dengan preview lengkap -- orang
          menyimpulkan file-nya cuma punya baris yang terlihat, lalu
          commit tanpa pernah tahu ada baris lain yang tidak pernah
          dipratinjau. Yang disembunyikan di sini justru baris yang
          LOLOS, dan itu disebut eksplisit karena memang tidak ada yang
          salah padanya; yang bermasalah semuanya tetap terlihat.
        -->
        <p v-if="hasil.disembunyikan > 0" class="mg-result__note mg-result__note--info">
          <font-awesome-icon icon="circle-info" />
          <span>
            Menampilkan {{ hasil.baris.length }} dari {{ hasil.total }} baris.
            <strong>{{ hasil.disembunyikan }} baris disembunyikan</strong> karena berada di tengah
            file yang panjang -- semuanya lolos validasi. Untuk melihat semuanya, pecah file jadi
            beberapa bagian.
          </span>
        </p>

        <div class="mg-actions">
          <button type="button" class="mg-btn mg-btn--ghost" @click="tahap = 'unggah'">
            <font-awesome-icon icon="rotate" />
            Ganti File
          </button>

          <button
            v-if="hasil.gagal > 0"
            type="button"
            class="mg-btn mg-btn--ghost"
            @click="unduhLogGagal"
          >
            <font-awesome-icon icon="file-export" />
            Unduh {{ hasil.gagal }} Baris Ditolak
          </button>

          <!--
            Commit DISABLED kalau tidak ada baris yang siap, dan teksnya
            menjelaskan kenapa.

            Tombol aktif yang pasti gagal adalah jebakan: superadmin menekan
            "Tulis ke Lokasi", melihat spinner, lalu mendapat "0 baris
            tersimpan". Menonaktifkannya lebih cepat, dan teks di bawahnya
            membuat jalan keluarnya jelas -- perbaiki baris yang
            ditandai, bukan mengulang tanpa mengubah apa pun.
          -->
          <button
            type="button"
            class="mg-btn mg-btn--save mg-btn--lg"
            :disabled="hasil.siap === 0 || sibukCommit"
            :title="hasil.siap === 0 ? 'Tidak ada baris yang siap ditulis' : ''"
            @click="jalankanCommit"
          >
            <font-awesome-icon
              :icon="sibukCommit ? 'spinner' : 'check-double'"
              :spin="sibukCommit"
            />
            {{ sibukCommit ? 'Menulis ke Lokasi...' : `Tulis ${hasil.siap} Baris ke Lokasi` }}
          </button>
        </div>

        <p v-if="sibukCommit" class="mg-wait">
          <span class="mg-wait__dot" /><span class="mg-wait__dot" /><span class="mg-wait__dot" />
          {{ teksMenunggu }}
        </p>
      </div>

      <!-- ===== 4. Selesai ===== -->
      <div v-else class="mg-result">
        <div class="mg-stats" :class="`mg-stats--${tone}`">
          <div class="mg-stat">
            <span class="mg-stat__n">{{ hasilCommit.berhasil }}</span>
            <span class="mg-stat__l">tersimpan</span>
          </div>
          <div class="mg-stat" :class="{ 'mg-stat--bad': hasilCommit.gagal > 0 }">
            <span class="mg-stat__n">{{ hasilCommit.gagal }}</span>
            <span class="mg-stat__l">gagal ditulis</span>
          </div>
        </div>

        <p class="mg-result__msg">
          <font-awesome-icon icon="circle-check" />
          {{ pesanCommit }}
        </p>

        <div v-if="hasilCommit.detail?.length" class="mg-table-wrap">
          <table class="mg-table">
            <thead>
              <tr>
                <th scope="col" class="mg-table__num">Baris</th>
                <th scope="col">Kolom</th>
                <th scope="col">Alasan</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="satu in hasilCommit.detail" :key="satu.nomor" class="mg-table__row--bad">
                <td class="mg-table__num">{{ satu.nomor }}</td>
                <td>
                  <span v-if="satu.kolom?.length" class="mg-table__cols">
                    <span v-for="(nama, i) in satu.kolom" :key="nama + i" class="mg-chip">
                      {{ nama }}
                    </span>
                  </span>
                  <span v-else class="mg-table__muted">&mdash;</span>
                </td>
                <td>{{ satu.pesan }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!--
          Sama seperti di tahap periksa: backend membatasi `detail` jadi
          500 baris pertama. Untuk file besar sisanya tidak terlihat, dan
          tidak terlihat berarti tidak bisa diperbaiki -- padahal
          "Unduh Baris Gagal" justru mengirim seluruhnya, jadi angka
          yang di layar dan isi unduhan harus cocok.
        -->
        <p v-if="hasilCommit.disembunyikan > 0" class="mg-result__note mg-result__note--info">
          <font-awesome-icon icon="circle-info" />
          <span>
            Menampilkan {{ hasilCommit.detail.length }} dari {{ hasilCommit.gagal }} baris gagal.
            Unduh daftar lengkapnya lewat tombol di bawah.
          </span>
        </p>

        <div class="mg-actions">
          <button
            v-if="hasilCommit.gagal > 0"
            type="button"
            class="mg-btn mg-btn--ghost"
            @click="unduhLogGagal"
          >
            <font-awesome-icon icon="file-export" />
            Unduh {{ hasilCommit.gagal }} Baris Gagal
          </button>

          <button type="button" class="mg-btn mg-btn--ghost" @click="mulaiLagi">
            <font-awesome-icon icon="rotate" />
            Migrasi File Lain
          </button>

          <button type="button" class="mg-btn mg-btn--primary" @click="tahap = 'unggah'">
            <font-awesome-icon icon="upload" />
            Perbaiki &amp; Unggah Ulang
          </button>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import BaseSelect from '@/presentations/components/ui/BaseSelect.vue'
import { locationService, centralMigrationService } from '@/services/central.service'
import { showErrorToast, showSuccessToast } from '@/utils/swal'

/* =================================================================
 | DAFTAR JENIS DATA + BATAS FILE
 |=============================================================*/

const jenisList = ref([])
const batas = ref({ kilobyte: 0, ekstensi: [] })
const indexError = ref('')
const loadingIndex = ref(true)

/*
 * Batas dan daftar ekstensi TIDAK ditulis ulang di sini.
 *
 * Nilainya datang dari `GET /migrasi-data`, yang memanggil fungsi yang
 * sama dengan yang dipakai backend saat memvalidasi. Menulis ulang daftar
 * ini berarti dua sumber kebenaran: frontend akan menerima file yang
 * backend tolak, dan pesan penolakannya ("format harus salah satu dari...")
 * akan bertentangan dengan yang tertulis di halaman.
 */
const batasTeks = computed(() =>
  batas.value.kilobyte ? `Maksimal ${Math.round(batas.value.kilobyte / 1024)} MB` : '',
)

const ekstensiTeks = computed(() => (batas.value.ekstensi || []).map((e) => `.${e}`).join(', '))

const diterima = computed(() => (batas.value.ekstensi || []).map((e) => `.${e}`).join(','))

async function muatIndex() {
  loadingIndex.value = true
  indexError.value = ''

  try {
    const res = await centralMigrationService.getIndex()

    jenisList.value = res.data?.data?.jenis ?? []
    batas.value = res.data?.data?.batas ?? { kilobyte: 0, ekstensi: [] }
  } catch (error) {
    indexError.value = error?.response?.data?.message || error.message
  } finally {
    loadingIndex.value = false
  }
}

/* =================================================================
 | LOKASI
 |=============================================================*/

const lokasiList = ref([])
const lokasi = ref('')
const memuatLokasi = ref(true)
const lokasiError = ref('')

/*
 * Opsi untuk `BaseSelect`.
 *
 * Labelnya gabungan, dan itu bukan gaya: PrimeVue hanya bisa melakukan
 * pencarian terhadap `optionLabel`, jadi nama, slug, dan nama lembaga
 * harus semuanya ada di dalam satu teks. Kalau slug hanya ada di
 * tooltip, orang yang ingat slug tapi lupa nama tidak akan pernah
 * menemukannya -- dan slug justru yang menentukan file masuk ke
 * database mana.
 *
 * `value` memakai slug (bukan id) karena endpoint migrasi menerima slug,
 * dan seluruh halaman ini berjalan dengan slug di URL.
 */
const opsiLokasi = computed(() =>
  lokasiList.value.map((row) => ({
    value: row.slug,
    label: `${row.label} (${row.slug})`,
  })),
)

/*
 * Nama lokasi yang dipilih, untuk ditampilkan di panel kerja.
 *
 * `label` -- bukan `nama` -- karena `label()` milik `Tenant` sudah
 * compose dari nama dan nama lembaga, dan itulah yang dipakai backend
 * di respons migrasi (`lokasi_nama`). Kalau frontend memakai sumber
 * yang berbeda, layar bisa menampilkan "Tirto Mulo" sementara pesan
 * dari server menyebut "Tirto Mulo - PAMSIDES Tirto Mulo".
 */
const namaLokasiTerpilih = computed(
  () => lokasiList.value.find((row) => row.slug === lokasi.value)?.label || lokasi.value,
)

async function muatLokasi() {
  /*
   * `memuatLokasi` dikembalikan ke true di AWAL, bukan hanya di akhir.
   *
   * Tanpa itu, tombol "Coba lagi" menjalankan request kedua tanpa
   * indikator apa pun: select diam, spinner tidak menyala, dan kalau
   * request-nya butuh beberapa detik tombolnya terlihat tidak
   * terpakai. Yang membedakannya dari "macet" hanya keadaan yang
   * memang tidak ada di layar.
   */
  memuatLokasi.value = true
  lokasiError.value = ''

  try {
    const res = await locationService.getAll()
    const data = res.data?.data ?? []

    /*
     * Hanya lokasi yang statusnya bisa dipakai.
     *
     * Lokasi nonaktif tidak punya database yang bisa ditulis -- dan
     * kegagalannya baru muncul di langkah TEMPLATE, setelah orang
     * menunggu unduhan. Disaring di sini supaya yang muncul di daftar
     * memang bisa jadi tujuan.
     *
     * `database_ada` ikut diperiksa karena lokasinya bisa saja masih
     * aktif tapi databasenya sudah dihapus dari server. Kalau lolos
     * ke daftar, kegagalannya baru muncul saat template diunduh --
     * dengan pesan error database yang tidak menjelaskan apa pun.
     */
    lokasiList.value = (Array.isArray(data) ? data : []).filter(
      (row) => row.is_active !== false && row.database_ada !== false,
    )
  } catch (error) {
    /*
     * Toast saja TIDAK cukup di sini, dan itu bukan soal ttl.
     *
     * Kegagalan di sini berbeda dari kegagalan lain di halaman ini:
     * muatIndex() punya panel error dengan tombol "Coba lagi" di
     * tempatnya, jadi orang tahu harus apa. Daftar lokasi tidak punya
     * tempat itu -- dropdown-nya cuma jadi kosong, dan dropdown kosong
     * terlihat PERSIS seperti dropdown yang belum selesai dimuat.
     *
     * Itu sebabnya pesan ini ditulis di bawah select, bukan hanya di
     * toast: orang melihat select yang tidak berubah lalu menyimpulkan
     * "loading-nya macet", padahal request-nya sudah selesai dengan 401
     * karena sesi habis. Dua keadaan dengan tampilan sama, dan yang
     * salah tebaknya jauh lebih sulit.
     */
    lokasiError.value =
      error?.response?.data?.message || error?.message || 'Daftar lokasi tidak bisa dimuat.'

    showErrorToast(error)
  } finally {
    memuatLokasi.value = false
  }
}

/* =================================================================
 | UNDUH TEMPLATE
 |=============================================================*/

const mengunduh = ref('')

async function unduhTemplate(type, format) {
  mengunduh.value = type

  try {
    await centralMigrationService.downloadTemplate(lokasi.value, type, format)

    // Template baru diunduh -> hasil validasi lama tidak berlaku lagi.
    if (aktifType.value === type) {
      tahap.value = 'unggah'
      hasil.value = kosong
      hasilCommit.value = kosongCommit
    }
  } catch (error) {
    showErrorToast(error)
  } finally {
    mengunduh.value = ''
  }
}

/* =================================================================
 | LANGKAH 2: UNGGAH
 |=============================================================*/

const inputBerkas = ref(null)
const serang = ref(false)
const sibukUnggah = ref(false)
const persenUnggah = ref(0)

/*
 * Nama + ukuran berkas yang sedang diproses.
 *
 * Disimpan sebagai teks, bukan sebagai objek `File`, karena yang
 * dibutuhkan hanya ditampilkan selama unggahan berjalan. Menahan
 * objek `File` berarti seluruh isinya ditahan di memori sampai commit
 * selesai -- sia-sia, karena berkasnya sudah naik ke server dan
 * validate() tidak butuh berkasnya lagi.
 */
const namaBerkas = ref('')
const ukuranBerkas = ref('')

function tandaiBerkas(berkas) {
  namaBerkas.value = berkas.name || 'berkas tanpa nama'

  const kb = berkas.size / 1024

  ukuranBerkas.value = kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`
}

const bukaPemilih = () => {
  if (!sibukUnggah.value) inputBerkas.value?.click()
}

function tangkapPilih(event) {
  const berkas = event.target.files?.[0]

  if (berkas) periksa(berkas)
}

function tangkapDrop(event) {
  serang.value = false

  const berkas = event.dataTransfer?.files?.[0]

  if (berkas) periksa(berkas)
}

/*
 * Cek ukuran DI SINI, sebelum mengunggah.
 *
 * Server juga memeriksanya, tapi setelah file selesai naik lewat jaringan --
 * untuk file 50 MB itu satu menit bandwidth terbuang untuk jawaban yang
 * sudah diketahui sekarang.
 *
 * Ini hanya menghemat waktu, bukan menggantikan cek server: kalau file
 * tetap lolos dari sini, penolakan tetap datang dari server.
 *
 * Ekstensi diperiksa di sini juga, dengan alasan yang sama: `accept`
 * hanya menyaring APA YANG MUNCUL di dialog -- tidak berlaku untuk
 * tarik-lepas, dan beberapa peramban tetap menerimanya. Tanpa cek
 * ini, orang baru tahu formatnya salah setelah berkasnya naik.
 *
 * Ekstensi dicek dari nama berkas karena itulah satu-satunya yang
 * tersedia di sisi peramban; server memeriksa ulang lewat
 * `extensions:`, jadi cek di sini hanya menghemat waktu -- bukan
 * menggantikan yang di server.
 */
function periksa(berkas) {
  if (batas.value.kilobyte && berkas.size > batas.value.kilobyte * 1024) {
    showErrorToast(
      `File ${Math.round(berkas.size / 1024 / 1024)} MB melebihi batas ${batasTeks.value}.`,
    )

    bersihkanPilihan()

    return
  }

  const ekstensi = (berkas.name.split('.').pop() || '').toLowerCase()
  const boleh = batas.value.ekstensi || []

  if (boleh.length && !boleh.includes(ekstensi)) {
    showErrorToast(
      `Format .${ekstensi || 'tanpa ekstensi'} tidak diterima. Pakai: ${ekstensiTeks.value}.`,
    )

    bersihkanPilihan()

    return
  }

  tandaiBerkas(berkas)

  kirimValidasi(berkas)
}

/*
 * Kosongkan input file.
 *
 * WAJIB setelah setiap percobaan, termasuk yang GAGAL.
 *
 * Kalau tidak, memilih ulang berkas yang sama tidak memicu `change` --
 * karena nilainya belum berubah -- sehingga tidak ada yang terjadi saat
 * tombol diklik. Untuk file yang ditolak, itu jebakan yang mudah
 *tersesat: orang memperbaiki file, memilihnya lagi, lalu menekan
 * "Unggah" untuk ketiga kalinya dan tidak ada yang terjadi karena input
 * masih menyimpan nama berkas lama.
 */
function bersihkanPilihan() {
  if (inputBerkas.value) inputBerkas.value.value = ''
}

async function kirimValidasi(berkas) {
  sibukUnggah.value = true
  persenUnggah.value = 0

  try {
    const res = await centralMigrationService.validate(
      lokasi.value,
      aktifType.value,
      berkas,
      (p) => {
        persenUnggah.value = p
      },
    )

    hasil.value = { ...kosong, ...res.data?.data }
    tahap.value = 'periksa'
  } catch (error) {
    /*
     * Pesan 422 dari backend SUDAH ditulis ulang dalam Bahasa Indonesia
     * yang menyebut kolom atau baris yang salah ("Kolom NIK berisi
     * ..."). Menimpanya dengan pesan bawaan axios yang berbunyi
     * "Request failed with status code 422" membuang informasi yang
     * justru paling dibutuhkan.
     */
    const pesan = error?.response?.data?.message

    // Nama berkas dibersihkan juga di jalur gagal: orang akan memperbaiki
    // file-nya lalu memilih ulang, dan seperti dijelaskan di
    // `bersihkanPilihan()`, memilih berkas yang sama dua kali tidak
    // memicu `change` kalau input tidak dikosongkan.
    namaBerkas.value = ''
    ukuranBerkas.value = ''

    if (pesan) {
      showErrorToast(pesan)
    } else {
      showErrorToast(error)
    }
  } finally {
    sibukUnggah.value = false
    persenUnggah.value = 0

    bersihkanPilihan()
  }
}

/* =================================================================
 | LANGKAH 3: COMMIT
 |=============================================================*/

const sibukCommit = ref(false)
const detikTunggu = ref(0)

let penghitungDetik = null

/*
 * Teks yang BERUBAH saat commit berjalan.
 *
 * Yang bisa dijanjikan dengan jujur: tidak ada persentase. Backend
 * memproses seluruh file dalam satu request, dan respons yang sudah
 * ter-buffer tidak bisa di-flush ke peramban -- jadi angka yang bergerak
 * di layar tidak akan mencerminkan apa pun.
 *
 * Yang bisa:girikan spinner dengan teks yang berubah. Untuk 5.000 baris
 * prosesnya memang beberapa menit, dan teks "memeriksa baris ke 2.400"
 * memberitahu orang bahwa ini progressing -- bukan asal jalan.
 *
 * Angka di sini sengaja dibulatkan ke detik terdekat. Detik yang
 * angka yangfalse terlihat seperti data yang diukur, padahal tidak ada
 * yang mengukurnya -- dan begitu salah, orang mulai meragukan
 * angka-angka lain di layar ini.
 */
const teksMenunggu = computed(() => {
  if (detikTunggu.value < 8) return 'Menyusun data untuk ditulis ke lokasi...'

  if (detikTunggu.value < 25) {
    return 'Menulis ke database lokasi. File besar butuh waktu -- jangan tutup halaman ini.'
  }

  return `Masih menulis (${detikTunggu.value} detik). Jangan tutup halaman ini.`
})

async function jalankanCommit() {
  sibukCommit.value = true
  detikTunggu.value = 0

  penghitungDetik = setInterval(() => {
    detikTunggu.value += 1
  }, 1000)

  try {
    const res = await centralMigrationService.commit(
      lokasi.value,
      aktifType.value,
      hasil.value.hash,
    )

    hasilCommit.value = { ...kosongCommit, ...res.data?.data }
    tahap.value = 'selesai'

    showSuccessToast(res.data?.message || 'Migrasi selesai.')
  } catch (error) {
    showErrorToast(error)
  } finally {
    clearInterval(penghitungDetik)
    sibukCommit.value = false
  }
}

/* =================================================================
 | LOG BARIS GAGAL
 |=============================================================*/

/*
 * Baris yang gagal diambil dari tahap yang sedang ditampilkan, bukan
 * dari satu variabel terpisah.
 *
 * Setelah commit ada dua sumber: `hasil.gagal` (ditolak saat validasi,
 * tidak pernah ditulis) dan `hasilCommit.detail` (gagal saat ditulis).
 * Keduanya memanggil endpoint yang sama dengan bentuk yang sama, jadi
 * memilihnya di sini membuat satu tombol ini benar untuk dua tahap
 * tanpa tombolnya jadi dua.
 *
 * Penyaring memakai `status !== 'siap'`, bukan `!b.siap`: `siap` tidak
 * pernah ada di payload, jadi bentuk lama selalu benar untuk semua baris
 * -- file CSV "baris gagal" berisi SELURUH isi file, termasuk baris yang
 * justru lolos. Laporan seperti itu dikirim ke admin lokasi yang
 * harus memperbaikinya, dan menyatakan semua baris gagal padahal
 * sebenarnya tidak ada masalah sama sekali.
 */
const barisGagal = computed(() => {
  if (tahap.value === 'selesai') return hasilCommit.value.detail ?? []

  return (hasil.value.baris ?? []).filter((b) => b.status !== 'siap')
})

async function unduhLogGagal() {
  try {
    await centralMigrationService.downloadLogGagal(lokasi.value, aktifType.value, barisGagal.value)
  } catch (error) {
    showErrorToast(error)
  }
}

/* =================================================================
 | PREVIEW
 |=============================================================*/

/*
 * Kolom tabel preview.
 *
 * diambil dari `hasil.kolom` (backend), BUKAN dari `jenisAktif.kolom`
 * di `GET /migrasi-data`.
 *
 * Alasannya bukan hemat request. `ringkas` yang dikirim backend berkunci
 * pada kolom template milik JENIS YANG BARU DI-UNGGAH, dan daftar di
 * index bisa saja milik jenis lain kalau daftar berubah di tengah sesi
 * (lokasi ditambahkan, admin reload, atau genre baru belum ada).
 * Kalau header tabel memakai daftar yang berbeda, setiap nilai jatuh ke
 * kolom yang salah -- dan preview salah lebih berbahaya daripada preview
 * kosong, karena orang mempercayainya.
 *
 * Fallback ke `jenisAktif.kolom` supaya tabel tetap punya header kalau
 * backend versi lama tidak mengirim `kolom`.
 */
const kolomPreview = computed(() => {
  const dariRespons = hasil.value.kolom

  return (
    Array.isArray(dariRespons) && dariRespons.length ? dariRespons : (jenisAktif.value?.kolom ?? [])
  ).map((k) => ({
    key: k.key,
    label: k.label,
    required: !!k.required,
  }))
})

/*
 * Jumlah kolom wajib untuk jenis data yang sedang dipilih.
 *
 * Dihitung, bukan disimpan di state: daftar kolom datang sekali dari
 * `GET /migrasi-data` dan tidak pernah berubah selama sesi, jadi
 * menyimpannya berarti satu sumber kebenaran yang bisa basi kalau
 * jenisnya berganti tanpa daftar ikut dimuat ulang.
 */
const wajibCount = computed(() => (jenisAktif.value?.kolom ?? []).filter((k) => k.required).length)

/*
 * Apakah template jenis ini punya sheet "Referensi".
 *
 * Mengondition catatan sheet Referensi pada nilai ini, bukan
 * menampilkannya untuk semua jenis. Sheet Referensi hanya dibuat
 * kalau blueprint punya data acuan (desa, paket, jabatan), dan
 * blueprint tanpa data acuan TIDAK punya sheet itu -- jadi catatan
 * "lihat sheet Referensi" yang muncul untuk template yang tidak
 * memilikinya hanya mengarahkan orang mencari sesuatu yang tidak
 * ada, dan membuat catatan yang benar untuk jenis lain ikut
 * diragukan.
 */
const punyaSheetReferensi = computed(() => !!jenisAktif.value?.punyaReferensi)

/*
 * Sel yang salah di baris ini.
 *
 * `baris.kolom` berisi LABEL kolom template ("Nama Paket"), sedangkan
 * header kolom memakai `key` ("paket"). Penandanya harus cocok dalam
 * BENTUK YANG SAMA, jadi kedua sisi dinormalisasi dengan cara yang
 * identik -- sama seperti `MigrationPipeline::normalisasi()` di backend:
 * huruf kecil, tanpa spasi, tanda baca, dan garis bawah.
 *
 * Tanpa normalisasi, tidak ada satu pun sel yang akan pernah ditandai:
 * "Nama Paket" !== "paket" -- dan penandaan yang diam-diam gagal
 * bekerja lebih buruk tidak ada, karena orang akan mencari kolom yang
 * salah dan menyalahkan isi file yang sebenarnya sudah benar.
 */
function salahDi(baris, kolom) {
  if (baris.status === 'siap' || !baris.kolom?.length) return false

  const target = normalisasi(kolom.label)

  return baris.kolom.some((nama) => normalisasi(nama) === target)
}

/** Sama seperti normalisasi backend: huruf kecil, tanpa tanda baca. */
function normalisasi(teks) {
  return String(teks ?? '')
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '')
}

/*
 * Status satu baris.
 *
 * DIBEDAKAN EMPAT, bukan dua.
 *
 * Versi sebelumnya memakai `baris.siap` -- yang tidak pernah ada di
 * respons backend (yang kirim `status`). Akibatnya `!baris.siap` selalu
 * benar: SETIAP baris punya latar merah dan badge "Ditolak", termasuk
 * baris yang justru lolos validasi. Preview-nya menampilkan kebalikan
 * dari kenyataan.
 *
 * Badge per status, bukan satu untuk semua "siap": "Baru" dan
 * "Diperbarui" adalah dua keputusan yang berbeda. Kalau file diunggah
 * dua kali, yang kedua tidak menambah apa pun tapi memperbarui -- dan
 * dari layar yang hanya menulis "Siap", orang tidak bisa membedakan
 * impor pertama dari impor ulang.
 */
function badgeBaris(baris) {
  if (baris.status === 'gagal') return 'mg-badge--bad'
  if (baris.aksi === 'diperbarui') return 'mg-badge--info'

  return 'mg-badge--ok'
}

function labelBaris(baris) {
  if (baris.status === 'gagal') return 'Ditolak'

  return baris.aksi === 'diperbarui' ? 'Diperbarui' : 'Baru'
}

/* =================================================================
 | INDIKATOR TAHAP
 |=============================================================*/

/*
 * Tahap 1 (unduh template) SELALU selesai begitu jenis data dipilih --
 * tidak ada keadaan "sedang" atau "belum" untuknya, karena tidak ada
 * tombol yang harus ditekan. Menandainya selesai begitu outset adalah
 * jujur: yang di layar memang sudah ada file yang bisa diunduh.
 *
 * Yang ditampilkan sebagai tahap adalah 2, 3, 4 -- karena itulah yang
 * punya tombol. Angka 1 tidak dihapus dari urutan, supaya langkahnya
 * tetap terbaca.
 */
const tahapWizard = computed(() => {
  const sekarang = tahap.value === 'unggah' ? 2 : tahap.value === 'periksa' ? 3 : 4

  return [
    { n: 1, label: 'Unduh template' },
    { n: 2, label: 'Unggah file' },
    { n: 3, label: 'Periksa' },
    { n: 4, label: 'Selesai' },
  ].map((s) => ({
    ...s,
    state: s.n < sekarang ? 'selesai' : s.n === sekarang ? 'kini' : 'nanti',
  }))
})

const kosong = {
  hash: '',
  total: 0,
  siap: 0,
  gagal: 0,
  baru: 0,
  diperbarui: 0,
  baris: [],
  kolom: [],
  disembunyikan: 0,
}
const kosongCommit = { total: 0, berhasil: 0, gagal: 0, detail: [], disembunyikan: 0 }

const tahap = ref('unggah')
const hasil = ref({ ...kosong })
const hasilCommit = ref({ ...kosongCommit })

const aktifType = ref('')
const jenisAktif = computed(() => jenisList.value.find((j) => j.type === aktifType.value))

const tone = computed(() =>
  hasil.value.siap === 0 || hasilCommit.value.berhasil === 0 ? 'bad' : 'ok',
)

const pesanValidasi = computed(() => {
  if (hasil.value.siap === 0) {
    return `Tidak ada baris yang bisa ditulis dari ${hasil.value.total} baris di file.`
  }

  return hasil.value.gagal === 0
    ? `Semua ${hasil.value.siap} baris siap ditulis ke ${hasil.value.lokasi_nama}.`
    : `${hasil.value.siap} dari ${hasil.value.total} baris siap ditulis. Sisanya ditolak.`
})

const pesanCommit = computed(() => {
  if (hasilCommit.value.gagal === 0) {
    return `${hasilCommit.value.berhasil} baris tersimpan di ${hasilCommit.value.lokasi_nama}.`
  }

  return (
    `${hasilCommit.value.berhasil} baris tersimpan, ` +
    `${hasilCommit.value.gagal} gagal ditulis. Baris yang gagal TIDAK ada di lokasi.`
  )
})

function mulaiLagi() {
  tahap.value = 'unggah'
  hasil.value = { ...kosong }
  hasilCommit.value = { ...kosongCommit }
  persenUnggah.value = 0
  namaBerkas.value = ''
  ukuranBerkas.value = ''
  bersihkanPilihan()
}

/*
 * Ganti jenis atau ganti lokasi = awal yang baru.
 *
 * `hash` dari validasi sengaja TIDAK dipindah ke tahap berikutnya.
 * File kerja yang sudah ada itu milik lokasi DAN jenis yang tadi
 * dipilih; membawanya ke pilihan yang lain berarti commit dijalankan
 * atas file yang berbeda dari yang dipratinjau di layar.
 */
watch([lokasi, aktifType], () => {
  tahap.value = 'unggah'
  hasil.value = { ...kosong }
  hasilCommit.value = { ...kosongCommit }
  persenUnggah.value = 0
  namaBerkas.value = ''
  ukuranBerkas.value = ''
  serang.value = false
})

onMounted(async () => {
  await Promise.all([muatIndex(), muatLokasi()])
})
</script>

<style scoped>
/*
 * Lebar maksimum & jarak tepi diambil dari `--central-*` di `CentralLayout.vue`,
 * sama seperti halaman central lain.
 *
 * WARNA diambil dari `--ct-*` (assets/css/central-tokens.css), bukan hex
 * yang ditulis di sini.
 *
 * Versi sebelumnya menulis `#2563eb` untuk biru utama di delapan tempat,
 * dan warna itu TIDAK sama dengan `--ct-sky` / `--ct-sky-fill`. Jadi
 * tombol "Tulis ke Lokasi", badge "Siap", progress bar, dan border kartu
 * aktif semuanya biru yang sedikit berbeda satu sama lain -- dan tidak
 * ikut berubah kalau palet panel pusat berubah.
 *
 * Yang ditulis di sini sekarang hanya peran: biru untuk aksi, hijau
 * untuk berhasil, amber untuk peringatan, merah untuk kegagalan.
 *
 * ===== Permukaan: tiga tingkat, dipilah-pisah =====
 *
 * Halaman ini punya tiga ukuran kotak yang berdiri sendiri, dan ketiganya
 * dulu memakai SATU token abu yang sama (`--ct-surface-2`) -- padahal
 * fallback yang ditulis di sini berbeda-beda: `#fff`, `#f8fafc`, `#f1f5f9`.
 *
 * Fallback yang berbeda-beda itu sebenarnya sudah benar: maksudnya "kartu
 * ini putih, panel kecil ini abu muda, isian kecil ini abu tua". Yang hilang
 * adalah token untuk setiap tingkat itu. `--ct-surface-2` satu-satunya
 * berarti abu, jadi dipakai untuk ketiganya -- dan yang menentukan warna
 * sebenarnya bukan fallback, melainkan token. Hasilnya kartu utamanya ikut
 * abu, bukan putih seperti kartu di seluruh halaman panel pusat lain.
 *
 * Sekarang tiap tingkat punya tokennya sendiri:
 *
 *   --ct-surface     kartu utama (`.mg-pick`, `.mg-card`, `.mg-work`)
 *   --ct-surface-3   panel besar di dalam kartu (`.mg-ref`, `.mg-stat`,
 *                    header tabel)
 *   --ct-surface-2   isian kecil (pil langkah, chip, track progress bar)
 *
 * Urutannya dibalik dari yang biasa: `-2` lebih tua dari `-3` karena `-2`
 * sudah dipakai dashboard dan kedua pop-up untuk isian kecil, dan angka itu
 * tidak boleh diubah tanpa menyentuh tiga file lain.
 */
.mg {
  width: 100%;
  max-width: var(--central-content-max, 1200px);
  margin-inline: auto;
}

/* ===== Pemilihan lokasi ===== */

.mg-pick {
  margin-bottom: 1.5rem;
  padding: 1.25rem;
  border: 1px solid var(--ct-line, #e5e7eb);
  border-radius: var(--ct-radius, 16px);
  background: var(--ct-surface, #fff);
  /* Elevasi penuh, sama dengan kartu utama halaman panel pusat yang lain
     (struktur akun, tagihan, daftar lokasi). Tanpa bayangan, kotak ini
     menempel rata ke latarAbuAbu dan terbaca sebagai bagian halaman,
     bukan sebagai satu panel. */
  box-shadow: var(--central-shadow-card);
}

.mg-field {
  display: block;
}

.mg-field__label {
  display: block;
  margin-bottom: 0.4rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--ct-text-dim, #64748b);
}

.mg-pick__note {
  display: flex;
  gap: 0.5rem;
  margin: 0.9rem 0 0;
  font-size: 0.82rem;
  line-height: 1.5;
  color: var(--ct-text-dim, #64748b);
}

.mg-pick__note strong {
  color: var(--ct-amber, #b45309);
}

/*
 * "Tidak ada lokasi yang bisa dipakai" -- kondisi kosong, bukan error.
 * Amber, bukan merah: tidak ada lokasi yang bisa ditulis memang kondisi
 * yang mungkin terjadi (semua lokasi nonaktif), dan warna merah akan
 * membuat orang berpikir ada yang error di server.
 */
.mg-pick__empty {
  display: flex;
  gap: 0.5rem;
  align-items: flex-start;
  margin: 0.9rem 0 0;
  padding: 0.65rem 0.8rem;
  border-radius: var(--ct-radius-sm, 10px);
  background: var(--ct-amber-tint, #fffbeb);
  font-size: 0.82rem;
  line-height: 1.5;
  color: var(--ct-amber, #b45309);
}

.mg-pick__empty strong {
  font-weight: 700;
}

/*
 * "Daftar lokasi gagal dimuat" -- MERAH, bukan amber seperti pesan
 * "tidak ada lokasi yang cocok" di atasnya.
 *
 * Amber dipakai di sana karena itu bukan kegagalan: tidak ada lokasi
 * yang aktif adalah kondisi yang sah, hanya tidak ada yang bisa
 * dipakai. Yang di sini adalah request yang sudah selesai dengan
 * kesalahan, dan warnanya harus berbeda supaya orang tahu ini bukan
 * "memang tidak ada lokasinya" -- kalau sama, pesan "Coba lagi" di
 * sebelahnya terlihat seperti opsi biasa yang bisa diabaikan.
 */
.mg-pick__empty--salah {
  background: var(--ct-red-tint, #fef2f2);
  color: var(--ct-red, #b91c1c);
}

/*
 * "Coba lagi" sebagai link di dalam pesan, bukan tombol tersendiri.
 *
 * Alasannya letak: aksi ini milik pesan itu, bukan langkah berikutnya.
 * Tombol penuh di sebelahnya akan terlihat seperti pilihan lain --
 * "coba lagi" dan "pilih file" jadi dua hal yang setara, padahal yang
 * kedua belum mungkin dipakai sebelum yang pertama berhasil.
 */
.mg-link {
  padding: 0;
  border: 0;
  background: none;
  font: inherit;
  font-weight: 700;
  text-decoration: underline;
  cursor: pointer;
  color: inherit;
}

.mg-link:hover {
  opacity: 0.75;
}

/* ===== Kartu jenis data ===== */

.mg-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 1rem;
}

.mg-card {
  display: flex;
  flex-direction: column;
  padding: 1.1rem;
  border: 1px solid var(--ct-line, #e5e7eb);
  border-radius: var(--ct-radius, 16px);
  background: var(--ct-surface, #fff);
  /* Elevasi kartu utama, sama dengan kartu akun di halaman superadmin. */
  box-shadow: var(--central-shadow-card);
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease,
    transform 0.15s ease;
}

/*
 * Kartu jenis data bisa diklik seluruhnya, jadi elevate saat kursor di atasnya.
 *
 * Angkanya dari pasangan token (`--central-shadow-card-hover`), bukan
 * ditulis sendiri, supaya kartu ini terangkat dengan jarak yang sama dengan
 * kartu di halaman panel pusat lain.
 */
.mg-card:hover {
  border-color: var(--ct-line-strong, #cbd5e1);
  box-shadow: var(--central-shadow-card-hover);
  transform: translateY(-1px);
}

/*
 * Kartu aktif.
 *
 * Cincin luar pakai `color-mix`, bukan hex rgba yang ditulis manual.
 * Alasannya bukan tren: warna birunya sekarang datang dari
 * `--ct-sky`, yang itself-nya merujuk ke `--color-primary-dark`. Kalau
 * cincinnya ditulis sebagai `rgb(37 99 235 / 12%)` yang tetap biru
 * `#2563eb`, palet aplikasi berubah tapi cincinnya tidak -- jadi kartu
 * aktif advertise warna yang tidak lagi dipakai di mana pun.
 *
 * Cincinnya memakai `outline`, BUKAN `box-shadow`, dan itu disengaja.
 * `box-shadow` yang sudah dipakai untuk elevasi: kalau cincin ditumpangkan
 * di sana, `box-shadow` kedua menimpa yang pertama -- dan kartu yang sedang
 * aktif kehilangan bayangannya begitu kursor mendekatinya. `outline` berdiri
 * sendiri, jadi cincin dan elevasi bisa hidup berdampingan.
 *
 * `:hover` ikut ditulis di sini, bukan cuma di `.mg-card:hover`:
 * `.mg-card:hover` lebih spesifik daripada `.mg-card--active`, jadi tanpa
 * baris tambahan ini kartu yang SEDANG AKTIF akan kehilangan garis biru
 * begitu kursor lewat di atasnya -- dan garis itu justru penanda bahwa
 * jenis data ini yang sedang dikerjakan.
 */
.mg-card--active,
.mg-card--active:hover {
  border-color: var(--ct-sky, #0284c7);
  outline: 3px solid color-mix(in srgb, var(--ct-sky, #0284c7) 14%, transparent);
  outline-offset: -1px;
}

.mg-card__head {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
}

.mg-card__icon {
  display: grid;
  place-items: center;
  flex: 0 0 2.25rem;
  height: 2.25rem;
  border-radius: var(--ct-radius-sm, 10px);
  background: var(--ct-sky-tint, #e0f2fe);
  color: var(--ct-sky, #0284c7);
}

.mg-card__titles {
  min-width: 0;
}

.mg-card__title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
}

.mg-card__sub {
  margin: 0.1rem 0 0;
  font-size: 0.72rem;
  color: var(--ct-text-dim, #64748b);
}

/*
 * Deskripsi kartu.
 *
 * `--ct-text-dim`, bukan abu-abu gelap (#4b5563) yang sebelumnya ditulis.
 * Alasannya bukan kontras, tapi konsistensi: deskripsi ini paragraf
 * yang dibaca, bukan label pendek -- dan panel pusat sudah memakai
 * `--ct-text-dim` untuk teks penjelasan di halaman lain, sehingga
 * kartu jenis data terbaca sebagai bagian dari halaman yang sama.
 */
.mg-card__desc {
  margin: 0.75rem 0 0;
  font-size: 0.82rem;
  line-height: 1.55;
  color: var(--ct-text-dim, #64748b);
}

.mg-steps {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin: 0.85rem 0 0;
  padding: 0;
  list-style: none;
  font-size: 0.72rem;
  color: var(--ct-text-dim, #64748b);
}

.mg-steps li {
  display: inline-flex;
  gap: 0.3rem;
  align-items: center;
  padding: 0.15rem 0.45rem;
  border-radius: 999px;
  background: var(--ct-surface-2, #f1f5f9);
}

.mg-steps__n {
  font-weight: 700;
  color: var(--ct-sky, #0284c7);
}

.mg-card__cols {
  margin: 0.75rem 0 0;
  font-size: 0.72rem;
  color: var(--ct-text-dim, #64748b);
}

.mg-card__actions {
  display: flex;
  gap: 0.5rem;
  margin-top: auto;
  padding-top: 0.9rem;
}

/* ===== Kerangka placeholder ===== */

.mg-card--skeleton {
  gap: 0.7rem;
}

/*
 * Placeholder saat daftar jenis dimuat.
 *
 * Warnanya dari token, dan ini bukan sekadar konsistensi: skeleton
 * adalah satu-satunya yang terlihat di layar selama request
 * berjalan. Kalau warnanya tidak ikut tema, halaman berkedip
 * putih saat berpindah tema -- dan itu yang dibaca sebagai
 * "halaman rusak".
 */
.mg-sk {
  display: block;
  border-radius: var(--ct-radius-sm, 10px);
  background: linear-gradient(
    90deg,
    var(--ct-surface-2, #f1f5f9) 25%,
    var(--ct-line, #e5e7eb) 37%,
    var(--ct-surface-2, #f1f5f9) 63%
  );
  background-size: 400% 100%;
  animation: mg-shimmer 1.4s ease infinite;
}

.mg-sk--icon {
  width: 2.25rem;
  height: 2.25rem;
}

.mg-sk--line {
  height: 0.7rem;
}

.mg-sk--w60 {
  width: 60%;
}

.mg-sk--w85 {
  width: 85%;
}

@keyframes mg-shimmer {
  from {
    background-position: 100% 50%;
  }
  to {
    background-position: 0 50%;
  }
}

/* ===== Panel ===== */

.mg-panel {
  display: grid;
  justify-items: center;
  gap: 0.5rem;
  padding: 3rem 1.5rem;
  border: 1px dashed var(--ct-line-strong, #d1d5db);
  border-radius: var(--ct-radius, 16px);
  text-align: center;
  /* Elevasi kecil: ini kondisi kosong, bukan panel utama halaman. */
  box-shadow: var(--central-shadow-sm);
}

.mg-panel__icon {
  font-size: 1.8rem;
  color: var(--ct-red-fill, #ef4444);
}

.mg-panel__icon--muted {
  color: var(--ct-text-faint, #94a3b8);
}

.mg-panel__title {
  margin: 0;
  font-weight: 600;
}

.mg-panel__text {
  margin: 0;
  max-width: 46ch;
  font-size: 0.85rem;
  color: var(--ct-text-dim, #64748b);
}

/* ===== Area kerja ===== */

.mg-work {
  margin-top: 1.5rem;
  padding: 1.25rem;
  border: 1px solid var(--ct-line, #e5e7eb);
  border-radius: var(--ct-radius, 16px);
  background: var(--ct-surface, #fff);
  /* Elevasi penuh, sama dengan kartu utama halaman panel pusat yang lain. */
  box-shadow: var(--central-shadow-card);
}

.mg-work__head {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  justify-content: space-between;
  align-items: flex-start;
  padding-bottom: 0.9rem;
  border-bottom: 1px solid var(--ct-line, #e5e7eb);
}

.mg-work__title {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
}

.mg-work__sub {
  margin: 0.25rem 0 0;
  font-size: 0.8rem;
  color: var(--ct-text-dim, #64748b);
}

.mg-work__sep {
  margin-inline: 0.35rem;
  color: var(--ct-line, #e2e8f0);
}

.mg-work__step {
  display: block;
}

/*
 * Indikator tahap, menggantikan pil "Langkah 3 dari 4".
 *
 * Pil itu benar secara angka tapi tidak-answer pertanyaan yang muncul
 * begitu orang berhenti di tengah alur: "masih ada berapa langkah
 * lagi, dan saya sudah lewat yang mana". Daftar empat tahap menjawab
 * keduanya tanpa harus menghitung, dan yang sudah selesai versus yang
 * belum terlihat bedanya tanpa membaca teksnya.
 *
 * Nomor memakai `list-style: none` + `counter`, bukan empat elemen
 * terpisah: supaya penomoran otomatis ikut saat tahap ditambah, dan
 * tidak ada langkah yang bisa tampil nomor lama karena lupa diubah.
 */
.mg-wizard {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
  counter-reset: mg-wizard;
}

.mg-wizard__item {
  display: flex;
  gap: 0.35rem;
  align-items: center;
  padding: 0.2rem 0.6rem 0.2rem 0.25rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 600;
  color: var(--ct-text-faint, #94a3b8);
}

.mg-wizard__n {
  display: grid;
  place-items: center;
  width: 1.25rem;
  height: 1.25rem;
  border-radius: 999px;
  background: var(--ct-surface-2, #f1f5f9);
  font-size: 0.68rem;
  font-variant-numeric: tabular-nums;
}

.mg-wizard__item--selesai {
  color: var(--ct-text-dim, #64748b);
}

.mg-wizard__item--selesai .mg-wizard__n {
  background: var(--ct-green-tint, #dcfce7);
  color: var(--ct-green, #15803d);
}

/* Tahap yang sedang dikerjakan satu-satunya yang penuh -- kalau tidak,
 * yang "kini" dan "nanti" akan sama-sama pil abu-abu. */
.mg-wizard__item--kini {
  background: var(--ct-sky-tint, #f0f9ff);
  color: var(--ct-sky, #0369a1);
}

.mg-wizard__item--kini .mg-wizard__n {
  background: var(--ct-sky, #0284c7);
  color: #fff;
}

/* =================================================================
 | Daftar kolom template (`<details>`)
 |=================================================================*/

/*
 * `<details>`, bukan kartu yang selalu terbuka.
 *
 * Kolom template itu informasi rujukan, bukan tugas yang sedang
 * dikerjakan -- kebanyakan orang sudah tahu kolomnya dari file yang
 * diunduh, dan yang kembali ke sini setelah gagal upload justru
 * butuh melihat _preview_, bukan tabel definisi. Jadi yang dibuka
 * adalah ringkasannya ("11 kolom, 6 wajib"), dan daftar penuhnya
 * tinggal satu klik.
 *
 * Penanda panah pakai `::marker`, bukan ikon terpisah: native
 * `<details>` sudah punya penanda, dan menambahkan ikon kedua
 * menghasilkan dua tanda panah yang hanya salah satu yang berfungsi.
 */
.mg-ref {
  margin-top: 0.9rem;
  border: 1px solid var(--ct-line, #e5e7eb);
  border-radius: var(--ct-radius-sm, 10px);
  background: var(--ct-surface-3, #f8fafc);
  /* Permukaan di dalam kartu: elevasi kecil (`--central-shadow-sm`), bukan
     elevasi kartu utama. Kalau ikut setinggi kartu di luar, daftar kolom
     yang panjang ini terlihat seperti panel sepadat panel utama --
     dan yang Referensi ini sebenarnya hanya catatan tambahan. */
  box-shadow: var(--central-shadow-sm);
}

.mg-ref__sum {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  padding: 0.6rem 0.85rem;
  font-size: 0.82rem;
  cursor: pointer;
  color: var(--ct-text-dim, #64748b);
}

.mg-ref__sum:hover {
  color: var(--ct-text, #0f172a);
}

.mg-ref__sum::marker {
  color: var(--ct-text-faint, #94a3b8);
}

.mg-ref__caret {
  margin-left: auto;
  font-size: 0.7rem;
  color: var(--ct-text-faint, #94a3b8);
  transition: transform 0.15s;
}

.mg-ref[open] .mg-ref__caret {
  transform: rotate(180deg);
}

.mg-ref__body {
  padding: 0 0.85rem 0.85rem;
}

.mg-ref__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}

.mg-ref__table th,
.mg-ref__table td {
  padding: 0.4rem 0.6rem;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid var(--ct-line, #eef2f7);
}

.mg-ref__table thead th {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--ct-text-faint, #94a3b8);
}

/*
 * Lebar kolom-basis dikunci, bukan `width: 1%`.
 *
 * Nama kolom dalam template pendek ("Desa"), tapi contoh isinya
 * panjang ("Sukamaju / Dusun 1"). Tanpa lebar dasar, tabel memakai
 * lebar konten penuh dan kolom contoh melar sampai kolom pilihan --
 * yang justru kolom yang paling penting untuk dibaca -- hanya sisa
 * ruang. `width: 1%` + `white-space: nowrap` memaksa kolom konten
 * menyempit ke ukuran aslinya dan memberi ruang ke kolom nama.
 */
.mg-ref__c-nama {
  width: 1%;
  white-space: nowrap;
  font-weight: 600;
}

.mg-ref__c-contoh {
  width: 1%;
  white-space: nowrap;
}

.mg-ref__c-opsi {
  width: 100%;
}

.mg-ref__wajib,
.mg-ref__opsional {
  display: inline-block;
  margin-left: 0.35rem;
  padding: 0.02rem 0.35rem;
  border-radius: 4px;
  font-size: 0.66rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.mg-ref__wajib {
  background: var(--ct-amber-tint, #fffbeb);
  color: var(--ct-amber, #b45309);
}

.mg-ref__opsional {
  background: var(--ct-surface-2, #f1f5f9);
  color: var(--ct-text-faint, #94a3b8);
}

.mg-ref__table code {
  font-size: 0.78rem;
  color: var(--ct-text, #0f172a);
}

.mg-ref__kosong {
  color: var(--ct-text-faint, #94a3b8);
}

.mg-ref__opts {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
}

/* Pilihan di sini bukan kesalahan -- sekalian nilai netral, bukan
   merah seperti chip kolom yang salah di preview. Yang merah di
   preview berarti "koreksi baris ini"; yang netral di sini berarti
   "pilih salah satu dari ini". Warna yang sama untuk dua maksud
   berbeda itu yang membuat panel referensi terasa menegur. */
.mg-chip--netral {
  background: var(--ct-surface-2, #f1f5f9);
  color: var(--ct-text-dim, #64748b);
}

.mg-ref__catatan {
  display: flex;
  gap: 0.4rem;
  align-items: flex-start;
  margin: 0.75rem 0 0;
  padding: 0.55rem 0.7rem;
  border-radius: var(--ct-radius-sm, 10px);
  background: var(--ct-sky-tint, #f0f9ff);
  font-size: 0.78rem;
  line-height: 1.5;
  color: var(--ct-text-dim, #64748b);
}

.mg-ref__catatan--redup {
  background: var(--ct-surface-2, #f1f5f9);
  color: var(--ct-text-faint, #94a3b8);
}

/* ===== Area unggah ===== */

.mg-drop-wrap {
  padding: 1.25rem 0 0.25rem;
}

.mg-drop {
  display: grid;
  justify-items: center;
  gap: 0.5rem;
  padding: 2.5rem 1.5rem;
  border: 2px dashed var(--ct-line, #cbd5e1);
  border-radius: var(--ct-radius, 16px);
  text-align: center;
  cursor: pointer;
  /* Elevasi kecil: area ini hidup di dalam panel kerja, dan setinggi kartu
     utama akan membuatnya terlihat seperti panel terpisah -- padahal
     isinya masih satu langkah dari alur yang sama. */
  box-shadow: var(--central-shadow-sm);
  transition:
    border-color 0.15s,
    background 0.15s,
    box-shadow 0.15s;
}

.mg-drop:hover,
.mg-drop:focus-visible {
  border-color: var(--ct-sky, #0284c7);
  background: var(--ct-sky-tint, #f0f9ff);
  box-shadow: var(--central-shadow-sm-hover);
}

.mg-drop--over {
  border-color: var(--ct-sky, #0284c7);
  background: var(--ct-sky-tint, #f0f9ff);
}

.mg-drop--busy {
  opacity: 0.6;
  pointer-events: none;
}

.mg-drop__input {
  display: none;
}

.mg-drop__icon {
  font-size: 2rem;
  color: var(--ct-sky, #0284c7);
}

.mg-drop__title {
  margin: 0;
  font-weight: 600;
}

.mg-drop__hint {
  margin: 0 0 0.4rem;
  font-size: 0.78rem;
  color: var(--ct-text-dim, #64748b);
}

/*
 * Nama berkas yang sedang diproses.
 *
 * `max-width` + ellipsis karena nama berkas dari Windows bisa sangat
 * panjang (`Data Pelanggan Andhra Pradesh (Revisi 3) FINAL v2.xlsx`),
 * dan tanpa batas itu satu baris panjang akan mendorong ikon dan
 * tombol keluar dari area unggah. Dipotong dari TENGAH, bukan dari
 * kiri: ujung nama berkas biasanya yang membedakan dua file
 * ("revisi 2" vs "revisi 3"), jadi bagian itu yang harus selalu
 * terlihat. `direction: rtl` itu yang membuat pemotongan terjadi dari
 * ujung kanan, bukan dari awal.
 */
.mg-drop__file {
  display: flex;
  gap: 0.4rem;
  align-items: center;
  max-width: 100%;
  margin: 0;
  padding: 0.3rem 0.7rem;
  border-radius: 999px;
  background: var(--ct-surface-2, #f1f5f9);
  font-size: 0.78rem;
  color: var(--ct-text, #0f172a);
}

.mg-drop__filename {
  max-width: 22rem;
  overflow: hidden;
  white-space: nowrap;
  direction: rtl;
  text-overflow: ellipsis;
  font-weight: 600;
}

.mg-drop__size {
  color: var(--ct-text-dim, #64748b);
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

/* ===== Progress unggah ===== */

.mg-up {
  width: min(320px, 100%);
  margin-bottom: 0.4rem;
}

.mg-up__bar {
  height: 0.4rem;
  border-radius: 999px;
  background: var(--ct-surface-2, #f1f5f9);
  overflow: hidden;
}

.mg-up__fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: var(--ct-sky, #0284c7);
  transition: width 0.15s linear;
}

.mg-up__text {
  margin: 0.3rem 0 0;
  font-size: 0.75rem;
  color: var(--ct-text-dim, #64748b);
}

/* ===== Hasil ===== */

.mg-result {
  padding-top: 1.1rem;
}

.mg-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
  gap: 0.6rem;
  margin-bottom: 1rem;
}

.mg-stat {
  padding: 0.7rem 0.85rem;
  border: 1px solid var(--ct-line, #e5e7eb);
  border-radius: var(--ct-radius-sm, 10px);
  background: var(--ct-surface-3, #f8fafc);
  /* Kotak angka: elevasi kecil, sama seperti kartu tagihan HP. */
  box-shadow: var(--central-shadow-sm);
}

.mg-stat--bad {
  border-color: var(--ct-red, #dc2626);
  background: var(--ct-red-tint, #fef2f2);
}

.mg-stat__n {
  display: block;
  font-size: 1.35rem;
  font-weight: 700;
  line-height: 1.1;
}

.mg-stat__l {
  display: block;
  margin-top: 0.15rem;
  font-size: 0.72rem;
  color: var(--ct-text-dim, #64748b);
}

.mg-result__msg {
  display: flex;
  gap: 0.5rem;
  align-items: flex-start;
  margin: 0 0 0.6rem;
  font-size: 0.88rem;
  line-height: 1.55;
}

.mg-result__note {
  margin: 0 0 0.9rem;
  padding: 0.65rem 0.8rem;
  border-left: 3px solid var(--ct-amber, #f59e0b);
  border-radius: 0 var(--ct-radius-sm, 10px) var(--ct-radius-sm, 10px) 0;
  background: var(--ct-amber-tint, #fffbeb);
  font-size: 0.8rem;
  line-height: 1.55;
  color: var(--ct-amber, #78350f);
}

.mg-result__note--info {
  border-left-color: var(--ct-sky, #0284c7);
  background: var(--ct-sky-tint, #f0f9ff);
  color: var(--ct-text, #0f172a);
}

/*
 * Tabel preview.
 *
 * `min-width` itu wajib, bukan sisa. Tabel punya kolom data yang
 * jumlahnya ikut jenis migrasi -- Tiket Instalasi punya 15 kolom --
 * dan tanpa `min-width` browser akan memampatkan kolom selebar mungkin
 * sampai teksnya membungkus dua kata per baris. Preview yang sempit tapi
 * terbaca membuat orang salah baca nilai, dan preview yang salah dibaca
 * lebih berbahaya daripada preview yang perlu digulir.
 *
 * Kolom yang banyak itu juga alasan `Baris`, `Status`, dan `Keterangan`
 * dikunci di kiri (`position: sticky`): tanpa itu, saat menggulir ke
 * kolom terakhir untuk membaca nilai, tidak ada yang memberi tahu ini
 * baris nomor berapa dan berhasil atau gagal -- dan dua hal itulah
 * yang paling sering dicari.
 */
.mg-table-wrap {
  max-height: 26rem;
  overflow: auto;
  border: 1px solid var(--ct-line, #e5e7eb);
  border-radius: var(--ct-radius-sm, 10px);
  /* Permukaan di dalam panel: elevasi kecil, bukan elevasi kartu utama. */
  box-shadow: var(--central-shadow-sm);
}

.mg-table {
  width: 100%;
  min-width: 62rem;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 0.8rem;
}

.mg-table th,
.mg-table td {
  padding: 0.5rem 0.7rem;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid var(--ct-line, #eef2f7);
}

.mg-table thead th {
  position: sticky;
  top: 0;
  z-index: 2;
  background: var(--ct-surface-3, #f8fafc);
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--ct-text-dim, #64748b);
  white-space: nowrap;
}

.mg-table__num {
  position: sticky;
  left: 0;
  z-index: 1;
  width: 4.5rem;
  min-width: 4.5rem;
  background: var(--ct-surface, #fff);
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

/* Kiri tiga kolom ikut terkunci, jadi z-index-nya harus di atas
   `thead` yang juga sticky -- kalau tidak, header kunci akan lewat
   di belakang header kolom data saat digulir ke bawah. */
.mg-table thead th:nth-child(1),
.mg-table thead th:nth-child(2),
.mg-table thead th:nth-child(3) {
  z-index: 3;
}

.mg-table__msg {
  width: 20rem;
  min-width: 14rem;
}

.mg-table__col {
  width: 11rem;
  min-width: 9rem;
}

/*
 * Tanda kolom wajib.
 *
 * Hanya di header, dan kecil -- bukan kunci. Kolom yang wajib diisi
 * sudah dinyatakan backend lewat `required` dan akan ditolak dengan
 * pesan yang menyebut namanya, jadi tanda di sini hanya pengingat,
 * bukan penjelasan kalau gagal.
 */
.mg-table__req {
  color: var(--ct-red, #dc2626);
}

.mg-table__row--bad {
  background: var(--ct-red-tint, #fef2f2);
}

/*
 * Sel "Baris" yang terkunci ikut merah kalau barisnya ditolak.
 *
 * Latar merah menempel pada `<tr>`, sedangkan sel yang terkunci punya latar
 * sendiri -- jadi tanpa aturan ini, kolom "Baris" tetap putih di baris yang
 * seluruhnya merah. Hasilnya kolom terkunci terlihat seperti kotak putih yang
 * ditempel di baris merah, dan penanda "yang ditolak" hilang tepat di bagian
 * yang paling sering dibaca: nomor baris yang salah.
 */
.mg-table__row--bad .mg-table__num {
  background: var(--ct-red-tint, #fef2f2);
}

/* Sel yang salah, di dalam baris yang ditolak. */
.mg-cell--salah {
  font-weight: 700;
  color: var(--ct-red, #b91c1c);
  background: var(--ct-red-tint, #fee2e2);
  box-shadow: inset 2px 0 0 var(--ct-red, #dc2626);
}

.mg-table__muted {
  color: var(--ct-text-faint, #94a3b8);
}

/*
 * Chip nama kolom yang salah.
 *
 * Diulang di dalam teks pesan karena pesan dari backend tidak selalu
 * menyebut kolomnya -- `MigrationRowException::kosong()` sudah
 * menyertakan kolomnya, tapi pesan yang lebih umum (mis. "Gagal
 * diproses: ...") tidak punya kolom sama sekali. Chipnya memisahkan
 * "kolom mana" dari "apa masalahnya", yang kalau digabung jadi satu
 * kalimat jadi sulit dipindai.
 */
.mg-table__cols {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  margin-right: 0.4rem;
}

.mg-chip {
  padding: 0.05rem 0.4rem;
  border-radius: 4px;
  background: var(--ct-red, #b91c1c);
  font-size: 0.7rem;
  font-weight: 600;
  white-space: nowrap;
  color: #fff;
}

.mg-badge {
  display: inline-block;
  padding: 0.1rem 0.5rem;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 600;
  white-space: nowrap;
}

.mg-badge--ok {
  background: var(--ct-green-tint, #dcfce7);
  color: var(--ct-green, #15803d);
}

/*
 * "Diperbarui" punya warna sendiri, tidak berbagi dengan "Baru".
 *
 * Keduanya "berhasil", jadi secara teknis warna hijau sudah cukup --
 * tapi perbedaan di sini bukan soal prettiness: mengunggah
 * file yang sama dua kali menghasilkan SEMUA baris "Diperbarui" dan
 * nol baris baru, dan itu hasil yang berbeda secara total dari impor
 * pertama. Kalau keduanya hijau, layar tidak memberi tahu bahwa
 * impor kedua ini sebenarnya tidak menambah apa pun.
 */
.mg-badge--info {
  background: var(--ct-sky-tint, #e0f2fe);
  color: var(--ct-sky, #0369a1);
}

.mg-badge--bad {
  background: var(--ct-red-tint, #fee2e2);
  color: var(--ct-red, #b91c1c);
}

/* ===== Tombol ===== */

.mg-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  justify-content: flex-end;
  margin-top: 1rem;
}

.mg-btn {
  display: inline-flex;
  gap: 0.4rem;
  align-items: center;
  padding: 0.5rem 0.85rem;
  font: inherit;
  font-size: 0.82rem;
  font-weight: 600;
  border: 1px solid transparent;
  border-radius: var(--ct-radius-sm, 10px);
  cursor: pointer;
  /* Bayangan dasar ada di sini supaya tidak ada varian yang "lupa". Tanpa
     ini tombol berdiri datar di atas kartu putih, dan tepinya menyatu
     dengan permukaannya -- warna birunya jadi satu-satunya penanda. */
  box-shadow: var(--central-shadow-btn);
  transition:
    background 0.15s,
    box-shadow 0.15s,
    opacity 0.15s;
}

.mg-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
  /* Bayangan tombol mati dihapus: tombol yang tidak bisa dipakai tidak
     perlu terlihat menjulang dari permukaan. */
  box-shadow: none;
}

/*
 * Tombol utama.
 *
 * `color-mix` untuk state hover, bukan hex gelap yang ditulis manual
 * (`#1d4ed8`). Warna tombolnya ikut `--ct-sky`, yang merujuk ke
 * `--color-primary-dark` milik tema aplikasi -- kalau tema berubah ke
 * warna gelap, tombol ini mengikuti; kalau hex-nya ditulis manual, ia
 * tetap biru terang di tengah aplikasi yang seluruhnya gelap.
 *
 * Bayangannya ikut warna tombol (biru), bukan abu tua: abu di atas biru
 * terbaca sebagai noda, bukan cahaya dari tombol yang sama.
 */
.mg-btn--primary {
  background: var(--ct-sky, #0284c7);
  color: #fff;
  box-shadow: var(--central-shadow-btn-primary);
}

.mg-btn--primary:hover:not(:disabled) {
  background: color-mix(in srgb, var(--ct-sky, #0284c7) 86%, #000);
  box-shadow: var(--central-shadow-btn-primary-hover);
}

/*
 * Tombol "Simpan" -- abu, sama dengan aplikasi lokasi.
 *
 * Dipakai untuk "Tulis ke Lokasi": itu aksi yang benar-benar menyimpan
 * data, jadi warnanya harus sama dengan tombol simpan di halaman lain.
 * `mg-btn--primary` (biru) tetap dipakai untuk langkah yang belum
 * menyimpan apa pun -- "Pilih File", "Coba lagi", "Perbaiki & Unggah
 * Ulang". Lihat `.btn--save` di `central-form.css`.
 */
.mg-btn--save {
  background: var(--central-btn-save);
  color: #fff;
  box-shadow: var(--central-shadow-btn-save);
}

.mg-btn--save:hover:not(:disabled) {
  background: var(--central-btn-save-hover);
  box-shadow: var(--central-shadow-btn-save-hover);
}

.mg-btn--ghost {
  border-color: var(--ct-line-strong, #d1d5db);
  background: var(--ct-surface, #fff);
  color: inherit;
}

.mg-btn--ghost:hover:not(:disabled) {
  background: var(--ct-slate-tint, #f1f5f9);
  box-shadow: var(--central-shadow-btn-hover);
}

.mg-btn--lg {
  padding: 0.6rem 1.1rem;
}

/* ===== Menunggu ===== */

.mg-wait {
  display: flex;
  gap: 0.35rem;
  align-items: center;
  justify-content: flex-end;
  margin: 0.6rem 0 0;
  font-size: 0.78rem;
  color: var(--ct-text-dim, #64748b);
}

.mg-wait__dot {
  width: 0.35rem;
  height: 0.35rem;
  border-radius: 50%;
  background: var(--ct-sky-fill, #0ea5e9);
  animation: mg-pulse 1.2s ease-in-out infinite;
}

.mg-wait__dot:nth-child(2) {
  animation-delay: 0.15s;
}

.mg-wait__dot:nth-child(3) {
  animation-delay: 0.3s;
}

@keyframes mg-pulse {
  0%,
  100% {
    opacity: 0.25;
    transform: scale(0.8);
  }
  50% {
    opacity: 1;
    transform: scale(1);
  }
}

/* Hormati preferensi sistem yang mengurangi gerakan. */
@media (prefers-reduced-motion: reduce) {
  .mg-sk,
  .mg-wait__dot {
    animation: none;
  }
}
</style>
