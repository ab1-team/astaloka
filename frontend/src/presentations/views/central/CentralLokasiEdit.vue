<template>
  <div class="form-page">
    <!--
      ===== Baris atas lengket: Kembali di kiri, Simpan di kanan =====

      Yang di kiri bukan "Batal": kalau diambil dari form ubah, "Batal"
      berarti "buang perubahan", dan halaman ini justru tempat perubahan
      dibaca ulang. Jadi tombolnya "Kembali" dan tujuannya ditulis di
      `goBack()` -- bukan `router.back()`.
    -->
    <div class="form-topbar">
      <button type="button" class="form-topbar__back" :disabled="loading" @click="goBack">
        <font-awesome-icon icon="arrow-left" />
        Kembali
      </button>

      <div class="form-topbar__title">
        <h2>{{ form.nama || 'Ubah Tenant' }}</h2>
        <p>{{ form.slug }}</p>
      </div>

      <span class="status-pill" :class="form.is_active ? 'status-pill--on' : 'status-pill--off'">
        {{ form.is_active ? 'Aktif' : 'Nonaktif' }}
      </span>

      <div class="form-topbar__actions">
        <button
          type="submit"
          form="lokasi-form"
          class="btn btn--save"
          :disabled="saving || loading"
        >
          <font-awesome-icon v-if="saving" icon="spinner" spin />
          <font-awesome-icon v-else icon="check" />
          {{ saving ? 'Menyimpan...' : 'Simpan' }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="form-card form-card--state">
      <font-awesome-icon icon="spinner" spin />
      <span>Memuat data tenant...</span>
    </div>

    <form v-else id="lokasi-form" class="form-card" @submit.prevent="handleSubmit">
      <div class="form-body">
        <!--
            ===== Identitas =====

            Yang membedakan halaman ini dari halaman tambah: lima field ini
            BUKAN lima field yang bisa diubah. Yang di bawah slug adalah nama
            database yang sudah jadi, dan yang di bawahnya lagi adalah
            seluruh data lokasi.

            Menunjukkannya sebagai isian "tidak bisa diubah" hanya
            menimbulkan salah baca: field yang memang tidak bisa diubah, tapi
            tampil seperti isian yang hampir bisa diubah -- itu cara paling
            umum orang mengira bisa diedit, mengetik, lalu menyimpan dan
            mendapat 422 yang tidak menjelaskan apa pun. Karena itu slug
            tidak muncul sebagai field sama sekali, dan yang ditampilkan
            cuma nama database-nya, sebagai baris info di seksi ini.
          -->
        <section class="form-section form-section--wide">
          <header class="form-section__head">
            <span class="form-section__icon form-section__icon--slate">
              <font-awesome-icon icon="location-dot" />
            </span>
            <h2>Identitas</h2>
          </header>

          <div class="form-fields">
            <BaseInput v-model="form.nama" label="Nama Tenant *" :error="errors.nama" no-margin />

            <BaseInput
              v-model="form.nama_lembaga"
              label="Nama Lembaga / BUMD"
              :error="errors.nama_lembaga"
              no-margin
            />

            <div class="form-field">
              <span class="form-label">Database</span>
              <div class="slug-preview">
                <font-awesome-icon icon="database" />
                <code class="slug-preview__value">{{ form.database || '—' }}</code>
              </div>
            </div>

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
        </section>

        <!-- ===== Profil usaha ===== -->
        <section class="form-section">
          <header class="form-section__head">
            <span class="form-section__icon form-section__icon--green">
              <font-awesome-icon icon="briefcase" />
            </span>
            <h2>Profil Usaha</h2>
          </header>

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
            ===== Langganan & status =====

            Seksi terakhir, jadi menyeberangi dua kolom: tiga field di grid
            dua kolom menyisikan satu sel kosong di baris paling bawah, dan
            baris paling bawah yang kosong justru yang paling kelihatan.
          -->
        <section class="form-section form-section--wide">
          <header class="form-section__head">
            <span class="form-section__icon form-section__icon--amber">
              <font-awesome-icon icon="calendar-days" />
            </span>
            <h2>Langganan</h2>
          </header>

          <div class="form-fields">
            <!--
              Tanpa ikon, dan tanpa `showClear`.

              Tanpa ikon: ikon di dalam input tanggal tidak menambah informasi
              apa pun -- label di atasnya sudah menyebut "tanggal", dan kalender
              yang muncul saat diklik juga sudah jelas. Yang tersisa dari ikon
              itu cuma ruang kosong.

              Tanpa `showClear`: nilai kosong berarti "tanpa batas", jadi
              membatalkannya bukan satu klik -- tapi ketik kosong saja, dan
              kolomnya memang bisa diketik. Kalau nanti tombol hapus
              ditambahkan, tombol itu harus memanggil `update:modelValue`
              dengan `null` secara eksplisit: `DatePicker` emit
              `clear-click`, bukan `date-select`, jadi `handleChange` yang
              biasa tidak ikut terpanggil dan form akan menyimpan tanggal lama
              padahal layarnya sudah kosong.
            -->
            <AppDatePicker
              v-model="form.tgl_berakhir"
              label="Tanggal Berakhir"
              placeholder="Tanpa batas waktu"
              :error="errors.tgl_berakhir"
              no-margin
            />

            <!--
              ===== Maksimum user: TIDAK ADA DI SINI =====

              Field ini pernah ada di sini, dan dihapus barusan -- bersama
              kolomnya di database (`2026_10_16_000002_drop_max_users_from_tenants_table`).

              Tidak ada tenant yang pernah punya kuota, dan `NULL` sudah berarti
              "tanpa batas" sejak awal, jadi field ini selalu menampilkan
              "Tanpa batas" dan tidak pernah membatasi apa pun.

              Yang tersisa darinya cuma satu field yang isinya sudah pasti
              sebelum dibuka, dan satu nilai di halaman Detail dan di pop-up
              dashboard yang selalu tertulis "Tanpa batas".
            -->

            <!--
              Sakelar, bukan kotak centang.

              Yang diubah bukan isinya -- menonaktifkan lokasi tetap berarti
              `is_active = false` -- tapi tempatnya: dulu berdiri sendiri di
              bawah baris input, terpisah dari seksi Langganan, jadi
              terbaca sebagai pengaturan umum halaman. Sekarang menyatu di
              seksi yang sama, dan labelnya bisa diklik (elemen <label>),
              jadi tidak harus mengenai kotak kecilnya.

              `form-switch--match` menyamakan tinggi kotak ini dengan tinggi
              field tanggal di sebelahnya, dan menggesernya turun sejauh tinggi
              baris label supaya keduanya berimpit.

              Tanpa itu keduanya meleset arah, bukan sekadar beda tinggi:
              field tanggal punya baris label di atasnya sementara sakelar
              tidak punya apa pun, jadi kotak sakelar terlihat melayang
              turun dan tepi bawahnya tidak sejajar. Lihat catatan panjangnya
              di `central-form.css`.

              Sakelar ini juga `span 2`, jadi tiga kolom terisi penuh oleh
              tanggal (1) + sakelar (2) = 3, tanpa sel kosong.
            -->
            <label class="form-field form-field--span2 form-switch form-switch--match">
              <ToggleSwitch v-model="form.is_active" />
              <span class="form-switch__text">
                <span class="form-switch__title">Lokasi Aktif</span>
                <span class="form-switch__desc">
                  Lokasi nonaktif tidak bisa diakses, datanya tetap utuh.
                </span>
              </span>
            </label>
          </div>
        </section>
      </div>
    </form>
  </div>
</template>

<script setup>
// BaseInput hanya terdaftar sebagai komponen global di app LOKASI lewat
// import eksplisit per-file. Tanpa baris ini Vue memperlakukan <BaseInput>
// sebagai elemen HTML yang tidak dikenal: seluruh section form kosong,
// bukan sekadar gagal dimuat -- form tetap bisa dikirim dengan form kosong.
import BaseInput from '@/presentations/components/ui/BaseInput.vue'
import BaseSelect from '@/presentations/components/ui/BaseSelect.vue'
import AppDatePicker from '@/presentations/components/AppDatePicker.vue'
import ToggleSwitch from 'primevue/toggleswitch'
import { useLokasiForm } from '@/composables/useLokasiForm'
import '@/assets/css/central-form.css'

/*
 * Logika form TIDAK ditulis di sini. Daftar sektor, pemuatan tenant, bentuk
 * payload, dan pemetaan balasan 422 semuanya tinggal di `useLokasiForm`
 * karena halaman Tambah membutuhkannya juga -- lihat catatan panjang di file
 * itu.
 *
 * `loading` sekarang milik composable, bukan alias ke `uiStore.loading`.
 * Yang tadinya store global: ia ikut naik saat polling latar berjalan, jadi
 * tombol "Simpan" ikut nonaktif dan seluruh form berganti ke tampilan
 * "Memuat data tenant..." untuk request yang bukan miliknya. `loading` di sini
 * hanya benar selama pemuatan tenant itu sendiri.
 */
const { form, errors, saving, loading, sektorOptions, loadingSektor, goBack, handleSubmit } =
  useLokasiForm('edit')
</script>
