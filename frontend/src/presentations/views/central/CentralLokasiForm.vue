<template>
  <div class="form-page">
    <!--
      ===== Baris atas lengket: Kembali di kiri, Simpan di kanan =====

      Ini menggantikan baris "Kembali" terpisah di atas kartu DAN tombol
      "Batal" di baris bawah form. Alasannya hanya satu: keduanya hal yang
      sama -- kembali tanpa menyimpan -- dan letaknya berlawanan, jadi satu
      di atas dan satu di bawah. Yang di bawah adalah tempat orang mencari
      tombol submit, bukan tempat mencari jalan keluar.

      Karena baris ini lengket di bawah topnav, tombol Simpan selalu dapat
      dijangkau tanpa menggulir sampai ujung form yang panjang ini.
    -->
    <div class="form-topbar">
      <button type="button" class="form-topbar__back" @click="goBack">
        <font-awesome-icon icon="arrow-left" />
        Kembali
      </button>

      <div class="form-topbar__title">
        <h2>Daftarkan Tenant Baru</h2>
      </div>

      <div class="form-topbar__actions">
        <button type="submit" form="lokasi-form" class="btn btn--save" :disabled="saving">
          <font-awesome-icon v-if="saving" icon="spinner" spin />
          <font-awesome-icon v-else icon="check" />
          {{ saving ? 'Menyiapkan...' : 'Daftarkan' }}
        </button>
      </div>
    </div>

    <form id="lokasi-form" class="form-card" @submit.prevent="handleSubmit">
      <!--
        ===== Seksi Langganan TIDAK ADA di halaman ini =====

        Halaman ini membuat tenant, dan langganan bukan bagian dari
        pendaftaran. Satu-satunya sisa langganan yang pernah bisa diisi
        di sini -- batas jumlah user -- sudah dihapus seluruhnya, kolomnya
        termasuk (`2026_10_16_000002_drop_max_users_from_tenants_table`).
        Jadi yang tersisa tinggal tanggal berakhir, dan sebagian besar
        tenant tidak punya batas.

        Yang tersisa kemudian adalah satu field yang isinya hampir selalu
        sudah diketahui saat bikin tenant, tapi hampir tidak pernah diubah
        sesudahnya. Meminta orang mengisinya sekarang hanya menambah satu
        keputusan tanpa dasar di layar yang sudah panjang -- dan keputusan
        yang bisa diambil belakangan, di halaman Ubah, kalau memang perlu.

        Field yang dihapus beserta alasannya:
        - Maksimum user -- sudah dihapus total, bukan cuma disembunyikan.
        - Tanggal berakhir -- butuh kalender, padahal mayoritas tenant
          tidak punya batas.
      -->
      <div class="form-body">
        <!--
          ===== Identitas =====

          Seksi pertama menyeberangi dua kolom, dan ini bukan karena isinya
          paling penting -- tapi karena jumlahnya. Lima field di grid tiga
          kolom menyisakan dua sel kosong di baris paling bawah, dan baris
          paling bawah yang kosong justru yang paling kelihatan.

          Yang membuat baris itu tidak kosong: field terakhir (domain
          alternatif) diberi `span 2`, jadi lima field menutup tiga kolom
          pas -- tiga di baris pertama, dua di baris kedua.
        -->
        <section class="form-section form-section--wide">
          <header class="form-section__head">
            <span class="form-section__icon"><font-awesome-icon icon="location-dot" /></span>
            <h2>Identitas</h2>
          </header>

          <div class="form-fields">
            <BaseInput
              v-model="form.nama"
              label="Nama Tenant *"
              placeholder="Contoh: Tirto Mulo"
              prefix-icon="location-dot"
              :error="errors.nama"
              no-margin
            />

            <BaseInput
              v-model="form.nama_lembaga"
              label="Nama Lembaga / BUMD"
              placeholder="Contoh: Perum Tirto Mulo"
              prefix-icon="building"
              :error="errors.nama_lembaga"
              no-margin
            />

            <!--
              Slug diisi manual, bukan dibuat otomatis dari nama tenant.

              Dua tenant dengan nama berbeda sering berakhir dengan slug
              yang sama ("Tirto Mulo" dan "Tirto Mulo Kota"), dan slug yang
              bentrok ditolak backend. Kalau slug diam-diam berubah sendiri,
              orang tidak pernah tahu nama database dan subdomain yang
              sebenarnya dibuat -- dan keduanya tidak bisa diubah lagi
              setelah lokasi terdaftar.
            -->
            <BaseInput
              v-model="form.slug"
              label="Slug (URL) *"
              placeholder="tirto-mulo"
              prefix-icon="link"
              :error="errors.slug"
              hint="Huruf kecil, angka, dan tanda hubung."
              no-margin
            />

            <BaseInput
              v-model="form.domain"
              label="Domain Utama"
              placeholder="tirtomulo.astaloka.co.id"
              prefix-icon="globe"
              :error="errors.domain"
              no-margin
            />

            <BaseInput
              v-model="form.domain_alternatif"
              label="Domain Alternatif"
              placeholder="tirto-mulo.astaloka.test"
              prefix-icon="globe"
              :error="errors.domain_alternatif"
              class="form-field--span2"
              no-margin
            />
          </div>

          <div v-if="databasePreview" class="slug-preview">
            <font-awesome-icon icon="circle-info" />
            <div>
              <p class="slug-preview__label">Database:</p>
              <code class="slug-preview__value">{{ databasePreview }}</code>
            </div>
          </div>
        </section>

        <!-- ===== Profil usaha ===== -->
        <section class="form-section">
          <header class="form-section__head">
            <span class="form-section__icon form-section__icon--green">
              <font-awesome-icon icon="briefcase" />
            </span>
            <h2>Profil Usaha</h2>
          </header>

          <!--
            Sektor lewat `BaseSelect`, bukan `<select>` native.

            Daftar diambil dari backend (lihat catatan panjangnya di
            <script>), jadi yang tampil dan yang divalidasi tidak mungkin
            berbeda. Select-nya juga bisa diketik, dan daftar ini akan
            bertambah bersama tahun-tahun ke depan -- menggulir empat baris
            untuk memilih satu akan cepat terasa melelahkan.
          -->
          <div class="form-fields">
            <div class="form-field">
              <label class="form-label" for="form-sektor">Sektor</label>
              <BaseSelect
                id="form-sektor"
                v-model="form.sektor"
                :options="sektorOptions"
                option-label="label"
                option-value="value"
                placeholder="Pilih sektor..."
                :loading="loadingSektor"
                filter-placeholder="Cari sektor..."
                @change="errors.sektor = ''"
                no-margin
              />
              <p v-if="errors.sektor" class="form-error">{{ errors.sektor }}</p>
            </div>

            <BaseInput
              v-model="form.bidang_usaha"
              label="Bidang Usaha"
              placeholder="Contoh: PDAM Tirto Mulo"
              prefix-icon="building"
              :error="errors.bidang_usaha"
              no-margin
            />

            <BaseInput
              v-model="form.deskripsi_usaha"
              label="Deskripsi Usaha"
              type="textarea"
              :rows="2"
              placeholder="Contoh: melayani 12 kecamatan dengan tarif berjenjang."
              :error="errors.deskripsi_usaha"
              class="form-field--full"
              no-margin
            />
          </div>
        </section>

        <!-- ===== Kontak ===== -->
        <section class="form-section">
          <header class="form-section__head">
            <span class="form-section__icon form-section__icon--slate">
              <font-awesome-icon icon="address-book" />
            </span>
            <h2>Kontak</h2>
          </header>

          <div class="form-fields">
            <BaseInput
              v-model="form.alamat"
              label="Alamat"
              type="textarea"
              :rows="2"
              placeholder="Alamat lengkap kantor lokasi"
              :error="errors.alamat"
              class="form-field--full"
              no-margin
            />

            <BaseInput
              v-model="form.email"
              label="Email"
              type="email"
              placeholder="kontak@lokasi.id"
              prefix-icon="envelope"
              :error="errors.email"
              no-margin
            />

            <BaseInput
              v-model="form.telepon"
              label="Telepon"
              placeholder="0812xxxxxxx"
              prefix-icon="phone"
              :error="errors.telepon"
              no-margin
            />
          </div>
        </section>

        <!--
          ===== Admin Tenant =====

          Pasangan seksi Penyiapan Database di baris paling bawah. Keduanya
          sengaja dikecilkan jadi dua kolom supaya jumlah barisnya sama --
          kartu yang lebih tinggi satu baris berarti ruang kosong tepat di
          tempat yang paling menarik mata, yaitu baris paling bawah.
        -->
        <section class="form-section">
          <header class="form-section__head">
            <span class="form-section__icon form-section__icon--violet">
              <font-awesome-icon icon="user-shield" />
            </span>
            <h2>Admin Tenant</h2>
          </header>

          <div class="form-fields">
            <BaseInput
              v-model="form.admin_name"
              label="Nama Admin"
              placeholder="Nama lengkap admin"
              prefix-icon="user"
              :error="errors.admin_name"
              no-margin
            />

            <BaseInput
              v-model="form.admin_email"
              label="Email Admin"
              type="email"
              placeholder="admin@lokasi.id"
              prefix-icon="envelope"
              :error="errors.admin_email"
              no-margin
            />

            <BaseInput
              v-model="form.admin_password"
              label="Kata Sandi Admin"
              type="password"
              placeholder="Minimal 8 karakter"
              prefix-icon="lock"
              :error="errors.admin_password"
              hint="Boleh dikosongkan."
              class="form-field--full"
              no-margin
            />
          </div>
        </section>

        <!-- ===== Penyiapan database ===== -->
        <section class="form-section form-section--accent">
          <header class="form-section__head">
            <span class="form-section__icon form-section__icon--green">
              <font-awesome-icon icon="database" />
            </span>
            <h2>Penyiapan Database</h2>
          </header>

          <!--
            Strip informasi, bukan daftar langkah dan bukan toggle.

            Daftar per langkah pernah ada di sini dan isinya berulang:
            "seluruh migration" dan "master data" sebenarnya satu hal yang
            sama, yaitu database baru yang siap pakai. Yang benar-benar
            perlu diketahui sebelum menekan tombol hanya dua: database apa
            yang akan dibuat, dan berapa lama prosesnya.

            Opsi "siapkan nanti" juga pernah ada, dan itu jebakan: lokasi
            terdaftar di panel, terlihat aktif, tapi setiap request ke
            lokasi itu gagal dengan error koneksi -- dan tidak ada halaman
            yang menunjukkan hal itu. Backend menolak `provision: false`
            dengan 422, jadi kontrol yang tersisa hanya menambah satu
            langkah yang selalu berakhir sama.
          -->
          <div class="db-strip">
            <div class="db-strip__name">
              <span>Database</span>
              <code>{{ databasePreview || 'astaloka_' + (form.slug || '…') }}</code>
            </div>

            <ul class="db-strip__items">
              <li><font-awesome-icon icon="check" />Migration</li>
              <li><font-awesome-icon icon="check" />Master data</li>
              <li><font-awesome-icon icon="check" />Identitas laporan</li>
            </ul>
          </div>

          <p class="form-note form-note--warn">
            <font-awesome-icon icon="clock" />
            <span>
              Proses berjalan sinkron di server dan memakan waktu sekitar
              <strong>1-2 menit</strong>. Jangan tutup halaman selama proses berjalan.
            </span>
          </p>
        </section>
      </div>
    </form>
  </div>
</template>

<script setup>
// BaseInput bukan komponen global -- harus diimpor eksplisit seperti di
// halaman app lokasi. Tanpa ini semua field form tidak dirender.
import BaseInput from '@/presentations/components/ui/BaseInput.vue'
import BaseSelect from '@/presentations/components/ui/BaseSelect.vue'
import { useLokasiForm } from '@/composables/useLokasiForm'
import '@/assets/css/central-form.css'

/*
 * Logika form TIDAK ditulis di sini. Daftar sektor, bentuk payload, pemetaan
 * balasan 422, dan pratinjau nama database semuanya tinggal di
 * `useLokasiForm` karena halaman Ubah membutuhkannya juga -- lihat catatan
 * panjang di file itu.
 */
const {
  form,
  errors,
  saving,
  sektorOptions,
  loadingSektor,
  databasePreview,
  goBack,
  handleSubmit,
} = useLokasiForm('create')
</script>
