<template>
  <section class="branding">
    <header class="panel__head branding__head">
      <div>
        <h3>Tampilan Login</h3>
        <p class="panel__sub">
          Teks dan warna yang dilihat orang sebelum masuk ke aplikasi lokasi ini.
        </p>
      </div>

      <button
        type="button"
        class="btn btn--primary btn--sm"
        :disabled="menyimpan"
        @click="bukaEditor"
      >
        <font-awesome-icon :icon="sudahAdaBranding ? 'pen' : 'plus'" />
        {{ sudahAdaBranding ? 'Ubah' : 'Atur Tampilan' }}
      </button>
    </header>

    <!--
      Ringkasan selalu tampil, apa pun yang happens di form.

      Dua keadaan harus bisa dibedakan dari layar, tanpa membuka apa pun:
      "sudah diisi" dan "kosong, pakai bawaan". Kalau keadaan kedua
      ditampilkan sebagai teks kosong, orang mengira branding-nya hilang --
      padahal halamannya berjalan normal dengan bawaan. Kalau dipakai
      teks bawaannya, orang mengira isian itu disengaja dan tidak akan
      pernah menyentuhnya.

      Yang ditampilkan di sini: teks yang benar-benar tersimpan, dengan
      "pakai bawaan" ditulis eksplisit kalau kolomnya kosong.
    -->
    <div class="branding__isi">
      <div class="branding__teks">
        <div class="branding__baris">
          <span class="branding__label">Judul</span>
          <p class="branding__nilai">
            {{ branding.judul || `Bawaan: ${defaultTeks.judul}` }}
          </p>
        </div>

        <div class="branding__baris">
          <span class="branding__label">Sub Judul</span>
          <p class="branding__nilai">
            {{ branding.sub_judul || `Bawaan: ${defaultTeks.sub_judul}` }}
          </p>
        </div>

        <div class="branding__baris">
          <span class="branding__label">Deskripsi</span>
          <p class="branding__nilai">
            {{ branding.deskripsi || `Bawaan: ${defaultTeks.deskripsi}` }}
          </p>
        </div>

        <div class="branding__baris">
          <span class="branding__label">Warna Utama</span>
          <p class="branding__nilai branding__nilai--warna">
            <span class="branding__swatch" :style="{ background: warna }" />
            <code>{{ warna }}</code>
            <span v-if="pakaiWarnaBawaan" class="branding__bawaan">warna bawaan</span>
          </p>
        </div>
      </div>

      <!--
        Pratinjau memakai warna ASLI yang sedang dipilih, bukan warna
        panel pusat.

        Panel pusat sendiri tidak pernah memakai warna lokasi: warna itu
        identitas aplikasi lokasi, dan warna panel pusat sendiri harus tetap
        sama supaya admin tahu sedang berada di panel pusat dan bukan di
        aplikasi tenant. Kalau pratinjau memakai warna panel, yang terlihat
        di sini bukan apa yang akan dilihat pengguna.
      -->
      <div class="branding__pratinjau" :style="gayaPratinjau">
        <p class="branding__pratinjau-judul">{{ pratinjau.judul }}</p>
        <p class="branding__pratinjau-sub">{{ pratinjau.sub_judul }}</p>
        <p class="branding__pratinjau-desk">{{ pratinjau.deskripsi }}</p>
        <span class="branding__pratinjau-tombol" :style="gayaTombolPratinjau">
          Masuk Sekarang
        </span>
      </div>
    </div>

    <!-- ===== Editor ===== -->
    <Teleport to="body">
      <div v-if="terbuka" class="branding__overlay" @click="tutup">
        <div class="branding__dialog branding__dialog--lebar" @click.stop>
          <header class="branding__dialog-head">
            <div>
              <h3>Tampilan Halaman Login</h3>
              <p>
                Kosongkan teks untuk kembali ke bawaan. Warna berlaku untuk seluruh aplikasi lokasi
                ini.
              </p>
            </div>
            <button type="button" class="branding__close" aria-label="Tutup" @click="tutup">
              <font-awesome-icon icon="xmark" />
            </button>
          </header>

          <div class="branding__dialog-body">
            <!--
              Pratinjau aplikasi, bukan cuma halaman login.

              Yang paling sering dikeluhkan setelah warna lokasi disimpan adalah
              "menu aktifnya masih biru" dan "gradasinya kuning-biru". Dua
              hal itu hanya terlihat di dalam aplikasi lokasi, jadi kalau
              pratinjau di sini cuma meniru halaman login, dua masalah itu
              baru ketahuan setelah disimpan -- padahal sudah ada tempat yang
              tepat untuk melihatnya duluan.

              Jadi sidebar dan menu aktif ikut ditampilkan, dengan warna yang
              dihitung dari helper yang sama dengan `applyTheme.js`.
            -->
            <div class="branding__pratinjau-aplikasi">
              <div class="branding__mock-sidebar" :style="gayaSidebarPratinjau">
                <p class="branding__mock-sidebar-judul">MENU</p>
                <span class="branding__mock-menu">Beranda</span>
                <span class="branding__mock-menu branding__mock-menu--aktif">Pelanggan</span>
                <span class="branding__mock-menu">Tagihan</span>
              </div>

              <div class="branding__pratinjau branding__pratinjau--besar" :style="gayaPratinjau">
                <p class="branding__pratinjau-judul">{{ pratinjau.judul || 'Judul' }}</p>
                <p class="branding__pratinjau-sub">{{ pratinjau.sub_judul || 'Sub Judul' }}</p>
                <p v-if="pratinjau.deskripsi" class="branding__pratinjau-desk">
                  {{ pratinjau.deskripsi }}
                </p>
                <span class="branding__pratinjau-tombol" :style="gayaTombolPratinjau">
                  Masuk Sekarang
                </span>
              </div>
            </div>

            <div class="branding__form">
              <BaseInput
                v-model="form.judul"
                label="Judul"
                :maxlength="80"
                :placeholder="defaultTeks.judul"
                :error="errors.judul"
                hint="Baris pertama headline. Kosongkan untuk memakai bawaan."
              />

              <BaseInput
                v-model="form.sub_judul"
                label="Sub Judul"
                :maxlength="80"
                :placeholder="defaultTeks.sub_judul"
                :error="errors.sub_judul"
                hint="Baris kedua headline, yang diberi warna lokasi."
              />

              <BaseInput
                v-model="form.deskripsi"
                label="Deskripsi"
                type="textarea"
                :rows="3"
                :maxlength="255"
                :placeholder="defaultTeks.deskripsi"
                :error="errors.deskripsi"
                hint="Paragraf pendek di bawah headline. Kosongkan untuk memakai bawaan."
              />

              <!--
                Warna bebas DAN preset, bukan salah satu.

                Preset berwarna: warna yang dipakai orang lain sudah terbukti
                tidak merusak keterbacaan. Tapi daftar tertutup yang
                memaksa memotong pilihan -- ada warna sah yang tidak ada
                di preset (mis. warna khas PDAM tertentu), dan
                membiarkannya berarti "warna lokasi" sebenarnya tidak
                bisa dipakai.

                Input `type="color"` bawaan browser disimpan, tapi SETENGAH
                disembunyikan: yang kelihatan cuma kotak warnanya (16px),
                sisanya `opacity: 0` dan diposisikan di atas kotak teks.

                Kenapa tidak disembunyikan sepenuhnya: browser modernize
                `display: none` pada `input[type=color]` -- dialog
                pilihannya TIDAK akan terbuka lagi, jadi warnanya jadi tidak
                bisa diganti sama sekali. `opacity: 0` tidak punya
                perilaku itu, tombolnya tetap bisa diklik. Karena controls
                bawaan browser sulit di-style, yang diklik justru lapisan
                bening di atasnya (lihat `.branding__warna-native-wrap`).
              -->
              <div class="branding__field">
                <label class="branding__field-label" for="branding-warna">Warna Utama</label>

                <div class="branding__warna-pilih">
                  <input
                    id="branding-warna"
                    v-model="form.warna"
                    class="branding__warna-input"
                    type="text"
                    maxlength="7"
                    spellcheck="false"
                    placeholder="#0ea5e9"
                  />

                  <div class="branding__warna-swatch-wrap">
                    <span
                      class="branding__warna-swatch"
                      :style="{ '--branding-warna-aktif': warnaPratinjau }"
                    />
                    <input
                      v-model="form.warna"
                      class="branding__warna-native"
                      type="color"
                      tabindex="-1"
                      :aria-label="`Pilih warna: ${form.warna}`"
                    />
                  </div>
                </div>

                <p v-if="errors.warna" class="branding__error">{{ errors.warna }}</p>

                <div v-if="pilihanWarna.length" class="branding__preset">
                  <button
                    v-for="w in pilihanWarna"
                    :key="w.hex"
                    type="button"
                    class="branding__preset-item"
                    :class="{ 'is-aktif': samakanWarna(w.hex, form.warna) }"
                    :title="w.label"
                    @click="form.warna = w.hex"
                  >
                    <span class="branding__preset-swatch" :style="{ background: w.hex }" />
                    <span class="branding__preset-label">{{ w.label }}</span>
                  </button>
                </div>

                <p v-if="pilihanWarna.length === 0" class="branding__hint">
                  Daftar warna bawaan belum termuat. Warna tetap bisa diketik sendiri di atas.
                </p>
              </div>

              <p class="branding__catatan">
                <font-awesome-icon icon="circle-info" />
                Warna ini jadi warna tombol, sidebar, dan tabel di seluruh aplikasi lokasi ini.
                Warna hijau (sukses) dan merah (bahaya) tidak ikut berubah.
              </p>
            </div>
          </div>

          <footer class="branding__dialog-foot">
            <button type="button" class="btn btn--ghost" :disabled="menyimpan" @click="tutup">
              Batal
            </button>
            <button
              type="button"
              class="btn btn--save"
              :disabled="menyimpan || !warnaValid"
              @click="simpan"
            >
              <font-awesome-icon v-if="menyimpan" icon="spinner" spin />
              Simpan
            </button>
          </footer>
        </div>
      </div>
    </Teleport>
  </section>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import BaseInput from '@/presentations/components/ui/BaseInput.vue'
import { locationService } from '@/services/central.service'
import { MySwal, showSuccessToast, showErrorToast } from '@/utils/swal'
import {
  normalisasiHex,
  warnaTeksDiAtas,
  skalaWarna,
  gradienBrand,
  tripletRgb,
  WARNA_BAWAAN,
} from '@/utils/themeColor'
import { bawaanBranding } from '@/composables/useBranding'

const props = defineProps({
  /**
   * `lokasi.branding` apa adanya, dari `LocationController::present()`.
   *
   * Bentuknya `Branding::raw()`: teks boleh `null` (artinya kosong = pakai
   * bawaan), warna sudah final. Dipakai baca-saja, bukan `v-model`, karena
   * panel ini menyimpan lewat `locationService.update()` lalu memuat ulang
   * detail -- bukan punya salinan sendiri yang harus dicocokkan.
   */
  lokasi: { type: Object, required: true },

  /**
   * Nilai bawaan milik tenant ini: `default_judul`, `default_sub_judul`,
   * `default_deskripsi`, `default_warna`.
   *
   * Sengaja tidak `required` dan setiap fieldnya boleh kosong: kalau
   * backend-nya versi lama, panel ini harus tetap bisa dibuka dengan teks
   * bawaan dari frontend, bukan gagal total karena satu prop tidak ada.
   */
  bawaan: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['saved'])

const terbuka = ref(false)
const menyimpan = ref(false)
const errors = ref({})
const pilihanWarna = ref([])

const branding = computed(() => props.lokasi?.branding ?? {})

/**
 * Teks bawaan untuk placeholder.
 *
 * Sumbernya `bawaan` dari server, dengan teks yang sama dari
 * `useBranding.bawaanBranding()` sebagai jaring pengaman.
 *
 * Dua lapis ini tidak berlebihan kalau ditanyakan begini: endpoint publik
 * sudah mengirim nilai final, jadi panel ini sebenarnya bisa memakai
 * `judulLogin()` dan selesai di situ. Tapi `useBranding` di sini berisi
 * branding LOKASI INI, sementara panel ini adalah panel pusat yang kebetulan
 * dibuka lewat subdomain yang sama -- kalau panel pusat diakses dari domain
 * utama sementara form-nya diisi untuk lokasi lain, teksnya akan salah tanpa
 * satu pun error. Bawaan harus datang dari tenant-nya.
 *
 * Lapis kedua hanya aktif kalau server tidak mengirim `default_*` (versi
 * backend lama, atau tenant yang barisnya belum pernah di-`present()`).
 */
const defaultTeks = computed(() => ({
  ...bawaanBranding(),
  ...props.bawaan,
  deskripsi: props.bawaan?.deskripsi || bawaanBranding().deskripsi,
}))

/**
 * Warna bawaan, hanya untuk ditampilkan dan jadi titik kembali.
 *
 * Urutannya penting: `bawaan.warna` dulu, baru branding yang tersimpan.
 * Kalau urutannya dibalik, warna yang sedang dipakai tenant ikut jadi
 * titik kembali -- jadi tombol "pakai warna bawaan" tidak pernah benar
 * dan "Kosongkan untuk bawaan" jadi tidak bisa dilakukan sama sekali.
 * `WARNA_BAWAAN` di paling akhir hanya aktif kalau keduanya kosong, yaitu
 * tenant yang belum pernah punya kolom warna sama sekali.
 */
const warnaBawaan = computed(
  () => normalisasiHex(props.bawaan?.warna) ?? normalisasiHex(branding.value.warna) ?? WARNA_BAWAAN,
)

/** Kalau ada isi branding tersimpan, tombolnya berbunyi "Ubah", bukan "Atur". */
const sudahAdaBranding = computed(
  () =>
    Boolean(branding.value.judul) ||
    Boolean(branding.value.sub_judul) ||
    Boolean(branding.value.deskripsi) ||
    !pakaiWarnaBawaan.value,
)

const pakaiWarnaBawaan = computed(() => branding.value.warna === warnaBawaan.value)

const form = reactive({
  judul: '',
  sub_judul: '',
  deskripsi: '',
  warna: WARNA_BAWAAN,
})

/*
 * Pratinjau memakai nilai form, bukan branding yang tersimpan.
 *
 * Yang membuat panel ini berguna adalah melihat perubahan SEBELUM
 * disimpan. Kalau pratinjau hanya menampilkan data tersimpan, orang baru
 * tahu hasilnya setelah menekan Simpan dan membuka tab login.
 *
 * Teks kosong TIDAK ditampilkan sebagai teks kosong. Pratinjau memakai
 * `|| bawaan`, sama seperti halaman login sebenarnya: kalau isiannya
 * dikosongkan, yang terlihat setelah disimpan adalah bawaan -- jadi itu
 * yang harus dievaluasi sekarang, bukan string "Kosong" yang tidak akan
 * pernah muncul di mana pun.
 *
 * Perhatikan: ini hanya berlaku di pratinjau, bukan di form. Isian form
 * yang dikosongkan tetap kosong -- kalau pratinjau ikut mengisi formnya,
 * orang akan menyimpan nilai itu tanpa sengaja. Pratinjau membaca, form
 * menulis; keduanya tidak boleh berbagi sumber nilai.
 */
const warnaPratinjau = computed(() => normalisasiHex(form.warna) ?? warnaBawaan.value)

const pratinjau = computed(() => ({
  judul: form.judul.trim() || defaultTeks.value.judul,
  sub_judul: form.sub_judul.trim() || defaultTeks.value.sub_judul,
  deskripsi: form.deskripsi.trim() || defaultTeks.value.deskripsi,
}))

/*
 * Gaya pratinjau dihitung, bukan hex yang ditulis langsung ke CSS.
 *
 * `color-mix()` dipakai untuk latar pratinjau: warna utama yang pekat
 * di atas kartu putih akan membuat teks di dalamnya sulit dibaca, dan
 * admin akan menyimpulkan panelnya yang salah, bukan warnanya. Dicampur
 * dengan putih supaya teksnya selalu terbaca -- dengan intensitas kecil
 * supaya warna yang dipilih tetap kelihatan.
 *
 * Warna teks di atas warna solid (tombol) dihitung dari kontras, bukan
 * ditebak: lihat catatan panjangnya di `themeColor.js`.
 */
const gayaPratinjau = computed(() => ({
  borderColor: warnaPratinjau.value,
  background: `color-mix(in srgb, ${warnaPratinjau.value} 7%, #ffffff)`,
}))

/*
 * Pratinjau memakai GRADIEN yang sama dengan aplikasi, bukan warna polos.
 *
 * Waktu pilih kuning, gradasinya harus langsung kelihatan seperti apa --
 * bukan baru ketahuan setelah disimpan lalu aplikasi dibuka.
 *
 * Helper-nya sama dengan yang dipakai `utils/applyTheme.js`, bukan ditulis
 * ulang di sini. Kalau gradasinya ditulis terpisah di dua tempat, keduanya
 * pasti menjauh dari satu sama lain: begitu ada satu yang diubah, yang lain
 * diam, dan pratinjau mulai menjanjikan sesuatu yang tidak lagi muncul di
 * aplikasi. Pratinjau yang menyimpang lebih buruk daripada tidak ada
 * pratinjau sama sekali, karena orang menyimpan berdasarkan apa yang
 * dijanjikan pratinjau.
 *
 * Tombol di sini memakai pasangan 400 -> 600, sama dengan
 * `.base-button--info-gradient` dan `.modal-top-accent`.
 */
const gayaTombolPratinjau = computed(() => {
  const skala = skalaWarna(warnaPratinjau.value)

  return {
    background: gradienBrand(skala['400'], skala['600']),
    color: warnaTeksDiAtas(warnaPratinjau.value),
  }
})

/*
 * Pratinjau sidebar dan menu aktif.
 *
 * Supaya dua hal yang paling sering dikeluhkan ("menu aktifnya masih biru" dan
 * "gradasinya kuning-biru") terlihat di panel pusat, bukan baru ketahuan
 * setelah menyimpan dan membuka aplikasi.
 *
 * Menu aktif memakai gradasi yang sama persis dengan
 * `.sidebar-nav-item.active` di `main.css`, dan penandunya memakai
 * `--color-primary` yang sama dengan `::after` di sana. Jadi yang tampil
 * di sini bukan interpretasi -- persis yang akan muncul di aplikasi.
 *
 * `tripletRgb` dipakai untuk bagian transparannya, bukan `rgba(hex, 0.2)`:
 * menyisipkan heksa ke dalam `rgba()` menghasilkan nilai yang tidak valid
 * dan tidak menghasilkan error, gradasinya hilang begitu saja.
 */
const gayaSidebarPratinjau = computed(() => {
  const skala = skalaWarna(warnaPratinjau.value)
  const rgb = tripletRgb(warnaPratinjau.value)

  return {
    '--mock-brand': gradienBrand(skala['400'], skala['600']),
    '--mock-menu-aktif': `linear-gradient(
      to right,
      rgb(${rgb} / 0.9) 0%,
      rgb(${rgb} / 0.2) 60%,
      rgb(${rgb} / 0.05) 100%
    )`,
    '--mock-penanda': skala['500'],
  }
})

const warnaValid = computed(() => normalisasiHex(form.warna) !== null)

/*
 * Bandingkan dua warna tanpa memedulikan huruf besar-kecil.
 *
 * `input type="color"` selalu mengembalikan huruf kecil, sedangkan orang
 * yang mengetik hex boleh menulis huruf besar. Tanpa normalisasi, warna
 * yang sama tidak akan pernah terlihat sebagai terpilih.
 */
function samakanWarna(a, b) {
  const x = normalisasiHex(a)
  const y = normalisasiHex(b)

  return x !== null && x === y
}

/*
 * Daftar preset diambil dari backend, bukan ditulis di sini.
 *
 * Warna ini menentukan identitas seluruh aplikasi lokasi, dan backend
 * menolaknya kalau tidak ada di `Branding::WARNA_TERSEDIA`. Daftar yang
 * ditulis ulang di frontend pasti akan menyimpang pada satu warna --
 * dan karena yang ditolak adalah warnanya (bukan field-nya), errornya
 * muncul sebagai 422 yang tidak menyebut apa pun.
 *
 * Gagal memuat TIDAK memblokir panel. Warna tetap bisa diketik, dan itu
 * jalur yang selalu ada. Daftar preset yang kosong hanya mengurangi
 * kenyamanan, bukan kemampuan.
 */
async function muatPilihanWarna() {
  if (pilihanWarna.value.length) return

  try {
    const res = await locationService.getWarnaBranding()
    pilihanWarna.value = res.data?.data ?? []
  } catch {
    pilihanWarna.value = []
  }
}

const bukaEditor = async () => {
  errors.value = {}
  form.judul = branding.value.judul ?? ''
  form.sub_judul = branding.value.sub_judul ?? ''
  form.deskripsi = branding.value.deskripsi ?? ''
  form.warna = branding.value.warna || WARNA_BAWAAN
  terbuka.value = true

  muatPilihanWarna()
}

const tutup = () => {
  if (menyimpan.value) return
  terbuka.value = false
}

/*
 * Simpan branding.
 *
 * Kirim keempat field apa adanya, termasuk yang dikosongkan. Ini yang
 * membuat "Kosongkan untuk kembali ke bawaan" benar-benar bekerja: kalau
 * field yang dikosongkan tidak dikirim, `update()` adalah pembaruan
 * sebagian dan isian lamanya akan tetap ada -- jadi orang mengira teks
 * sudah dihapus, sementara halamannya masih menampilkan teks itu.
 *
 * Warna tidak pernah dikirim kosong: form mewajibkan hex yang sah, dan
 * "pakai warna bawaan" dicapai dengan MENGIRIM nilai bawaannya, bukan
 * dengan mengosongkan kolom (backend menolak string kosong untuk rule
 * regex, dan warna kosong tidak punya bentuk yang bisa digambar).
 */
const simpan = async () => {
  errors.value = {}

  const warna = normalisasiHex(form.warna)

  if (!warna) {
    errors.value.warna = 'Warna harus berupa hex, misalnya #0ea5e9.'
    return
  }

  const kosongkan = (nilai) => (typeof nilai === 'string' ? nilai.trim() : '')

  menyimpan.value = true

  try {
    const res = await locationService.update(props.lokasi.id, {
      judul_login: kosongkan(form.judul) || null,
      sub_judul_login: kosongkan(form.sub_judul) || null,
      deskripsi_login: kosongkan(form.deskripsi) || null,
      warna_utama: warna,
    })

    showSuccessToast(res.data?.message || 'Tampilan login lokasi diperbarui.')
    terbuka.value = false

    // Muat ulang detail, bukan cuma mengubah state lokal: panel di luar
    // editor ini (ringkasan di atas) membaca dari `lokasi.branding`, dan
    // nilainya hanya benar setelah datang dari server.
    emit('saved')
  } catch (error) {
    if (error.response?.status === 422) {
      const fieldErrors = error.response.data?.errors || {}

      if (Object.keys(fieldErrors).length > 0) {
        errors.value = Object.fromEntries(
          Object.entries(fieldErrors).map(([key, msgs]) => [key, msgs[0]]),
        )

        MySwal.fire({
          icon: 'error',
          title: 'Tampilan Login Belum Lengkap',
          text: error.response.data?.message || 'Periksa kembali isian yang ditandai.',
          confirmButtonColor: '#0284c7',
        })

        return
      }
    }

    showErrorToast(error)
  } finally {
    menyimpan.value = false
  }
}
</script>

<style scoped>
/*
 * `.btn--sm` ditulis ULANG di sini, bukan dipakai dari file pemanggil.
 *
 * Aturan `.btn--sm` yang ada di `CentralLokasiDetail.vue` berada di
 * `<style scoped>` file itu -- dan aturan scoped hanya berlaku untuk elemen
 * yang punya atribut `data-v-<id>` milik komponen itu. Tombol di panel ini
 * dirender oleh komponen INI, jadi atributnya berbeda dan selector itu
 * tidak akan pernah mengenainya.
 *
 * Gejalanya bukan tombol yang rusak: tombol tetap bisa diklik, hanya
 * setinggi tombol biasa (2.5rem) dan seukuran penuh, bukan ukuran `sm` yang
 * dipakai tombol sekelasnya di halaman. Tanpa catatan ini, siapa pun yang
 * Tearuh tombol besar di sini akan menyangka stylenya hilang, bukan ingat
 * bahwa aturan scoped tidak menjangkau lintas komponen.
 */
.btn--sm {
  padding: 0.45rem 0.85rem;
  font-size: 0.76rem;
}

/*
 * Gaya `.branding__*` ada di sini, BUKAN di `CentralLokasiDetail.vue`.

 * Dua alasannya. Pertama, panel ini dipakai di satu halaman sekarang, tapi
 * teks dan warnanya milik lokasi -- bukan milik halaman detail tenant --
 * jadi ikut ke sana akan berarti setiap halaman panel pusat yang someday
 * menampilkan branding harus menyalin ulang seluruh aturan ini.

 * Kedua, dan yang menentukan: aturan scoped hanya berlaku untuk elemen yang
 * dirender komponen INI. Kalau `.branding__isi` (grid isi panel) ditulis
 * di file pemanggil, isinya tidak akan pernah mendapat aturan itu --
 * persis masalah `.modal-box` yang sudah pernah terjadi di file yang sama,
 * dan sekarang dijaga di `central-form.css`.
 */

.branding {
  background: var(--color-card-bg, #fff);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-xl, 1rem);
  box-shadow: var(--central-shadow-card);
  overflow: hidden;
}

.branding__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  flex-wrap: wrap;
  padding: 0.9rem 1.15rem;
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
}

.branding__head h3 {
  font-size: 0.88rem;
  font-weight: 800;
  color: var(--color-text-main, #1e293b);
  margin: 0;
}

.branding__head .panel__sub {
  font-size: 0.73rem;
  color: var(--color-gray-400, #94a3b8);
  margin: 0.15rem 0 0;
}

.branding__isi {
  display: grid;
  /* Teks di kiri, pratinjau di kanan.
   *
    * Dua kolom eksplisit, bukan `auto-fit minmax`: pada lebar panel yang
    * biasa, `auto-fit` bisa berhenti di satu kolom pada lebar 900px dan
    * membuat pratinjau menumpuk di bawah teks -- padahal di layar 1024px
    * masih ada ruang untuk keduanya berdampingan.
    *
    * Pratinjau punya lebar minimum yang tidak boleh dikecilkan (lihat
    * `.branding__pratinjau`): kalau boleh menyusut, headline-nya ikut
    * menyusut dan orang menilai tampilan yang tidak akan pernah muncul.
    */
  grid-template-columns: minmax(0, 1fr) 20rem;
  gap: 1.25rem;
  padding: 1.15rem;
  align-items: start;
}

.branding__teks {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  min-width: 0;
}

.branding__baris {
  display: grid;
  grid-template-columns: 6.5rem minmax(0, 1fr);
  gap: 0.75rem;
  align-items: baseline;
}

.branding__label {
  font-size: 0.66rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--color-gray-400, #94a3b8);
}

.branding__nilai {
  font-size: 0.82rem;
  color: var(--color-text-main, #1e293b);
  line-height: 1.5;
  margin: 0;
  overflow-wrap: anywhere;
}

.branding__nilai--warna {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.branding__nilai--warna code {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.76rem;
}

.branding__swatch {
  width: 1.1rem;
  height: 1.1rem;
  border-radius: 5px;
  border: 1px solid var(--color-border, #e2e8f0);
  flex: none;
}

.branding__bawaan {
  font-size: 0.68rem;
  font-weight: 700;
  color: var(--color-gray-400, #94a3b8);
}

/* ===== Pratinjau ===== */

/*
 * Pratinjau aplikasi: sidebar tiruan di sebelah pratinjau login.
 *
 * Sidebar ikut ditampilkan, bukan cuma halaman login, karena warna di
 * sidebar dan di menu aktif adalah tempat yang paling sering dikeluhkan
 * ("masih biru") -- dan keduanya tidak terlihat di halaman login sama
 * sekali. Kalau tidak ditampilkan di sini, masalahnya baru ketahuan
 * setelah disimpan.
 *
 * Yang di sini benar-benar meniru aturan `main.css`: gradasi brand untuk
 * panel sidebar, gradasi lokasi untuk menu aktif, dan penanda di sisi kanan.
 * Bukan sidebar kosong yang dipaksakan sebagai hiasan -- kalau tidak sesuai
 * dengan aturannya, orang menyimpulkan panel-panel ini benar padahal tidak.
 */
.branding__pratinjau-aplikasi {
  display: flex;
  gap: 0.75rem;
  align-items: stretch;
  margin-bottom: 1.25rem;
}

.branding__mock-sidebar {
  width: 9.5rem;
  flex-shrink: 0;
  background: var(--mock-brand);
  border-radius: var(--border-radius-lg, 0.75rem);
  padding: 0.75rem 0.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.branding__mock-sidebar-judul {
  font-size: 0.55rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.7);
  margin: 0 0 0.35rem 0.5rem;
}

.branding__mock-menu {
  font-size: 0.72rem;
  font-weight: 600;
  color: rgba(255, 255, 255, 0.85);
  padding: 0.35rem 0.5rem;
  border-radius: 0 1rem 1rem 0;
  position: relative;
}

/*
 * Menu aktif: gradasi location plus penanda di kanan.
 *
 * `position: relative` + `::after` dipakai bukan `border-right` langsung,
 * supaya penandanya bisa bertepi atas dan bawah seperti di sidebar asli --
 * kalau memakai border biasa, hasilnya kotak dengan sudut tajam yang
 * terlihat berbeda dari sidebar aslinya.
 */
.branding__mock-menu--aktif {
  background: var(--mock-menu-aktif);
  color: #1e293b;
  font-weight: 800;
}

.branding__mock-menu--aktif::after {
  content: '';
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  width: 4px;
  border-right: 3px solid var(--mock-penanda);
  border-radius: 0 1rem 1rem 0;
}

.branding__pratinjau {
  border: 1px solid;
  border-radius: var(--border-radius-lg, 0.75rem);
  padding: 1rem;
  min-width: 0;
}

.branding__pratinjau--besar {
  padding: 1.4rem;
}

.branding__pratinjau-judul {
  font-size: 1.35rem;
  font-weight: 900;
  line-height: 1.15;
  letter-spacing: -0.02em;
  color: #0f172a;
  margin: 0;
}

.branding__pratinjau--besar .branding__pratinjau-judul {
  font-size: 1.75rem;
}

.branding__pratinjau-sub {
  font-size: 1.35rem;
  font-weight: 900;
  line-height: 1.15;
  letter-spacing: -0.02em;
  margin: 0;
  /* `background-clip: text` + `text-fill-color: transparent` adalah cara
     yang dipakai `.headline h1.blue` di `login.css` supaya warna gradien
     bisa lewat. Di sini tidak perlu gradien -- warna tunggal lewat
     `-webkit-text-fill-color` saja sudah sama hasilnya dan tidak
     bergantung pada dukungan `background-clip`. */
  -webkit-text-fill-color: currentColor;
}

.branding__pratinjau-desk {
  font-size: 0.74rem;
  line-height: 1.5;
  color: #475569;
  margin: 0.55rem 0 0.8rem;
}

.branding__pratinjau-tombol {
  display: inline-block;
  padding: 0.45rem 1.1rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 700;
}

/* ===== Dialog ===== */

.branding__overlay {
  position: fixed;
  inset: 0;
  z-index: 5000;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  background: rgb(15 23 42 / 0.55);
  backdrop-filter: blur(3px);
  overflow-y: auto;
}

.branding__dialog {
  width: 100%;
  max-width: 34rem;
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 3rem);
  background: var(--color-card-bg, #fff);
  border-radius: var(--border-radius-xl, 1rem);
  box-shadow: 0 25px 50px -12px rgb(15 23 42 / 0.35);
  overflow: hidden;
}

/*
 * Lebar, bukan 46rem.
 *
 * 46rem ternyata cukup untuk dua kolom, tapi isinya bertambah -- mock sidebar,
 * form, pratinjau warna -- sehingga kolom pratinjau menyisakan sekitar 6rem
 * untuk teks. Efeknya di layar: judul "internet maju jaya" pecah jadi tiga
 * baris, deskripsi jadi delapan baris satu kata, dan tombol "Masuk Sekarang"
 * jadi dua baris. Semuanya terasa sempit, bukan salah ketik atau salah data.
 *
 * Lebarnya sekarang longgar supaya kolom pratinjau punya ruang yang layak.
 * Dialog ini dibuka dari panel tengah yang sudah sempit, jadi ruang ekstra hanya
 * berarti ke layar yang memang punya ruang.
 */
.branding__dialog--lebar {
  max-width: 68rem;
}

.branding__dialog-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.1rem 1.25rem;
  border-bottom: 1px solid var(--color-gray-100, #f1f5f9);
}

.branding__dialog-head h3 {
  font-size: 0.98rem;
  font-weight: 800;
  color: var(--color-text-main, #1e293b);
  margin: 0;
}

.branding__dialog-head p {
  font-size: 0.74rem;
  color: var(--color-gray-500, #6b7280);
  line-height: 1.5;
  margin: 0.25rem 0 0;
  max-width: 34rem;
}

.branding__close {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  height: 2rem;
  flex: none;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--color-gray-400, #94a3b8);
  font-size: 0.9rem;
  cursor: pointer;
  transition: all 0.18s ease;
}

.branding__close:hover {
  background: var(--color-gray-100, #f1f5f9);
  color: var(--color-text-main, #1e293b);
}

/*
 * Isi dialog: pratinjau di ATAS, form di bawah. Bukan dua kolom.
 *
 * Dua kolom terlihat rapi di mockup, tapi isinya tidak evenly: mock
 * sidebar + pratinjau login butuh ruang horizontal yang jauh lebih banyak
 * daripada tiga input teks. Dipaksa dua kolom, pratinjau mendapat sisa yang
 * paling sedikit -- judul jadi tiga baris, deskripsi jadi delapan baris satu
 * kata, tombol jadi dua baris.
 *
 * Pratinjau jadi baris penuh di atas: lebarnya dipakai untuk apa yang perlu
 * lebar (mock sidebar dan headline), dan form di bawahnya punya lebar penuh
 * untuk input yang memang butuh lebar penuh (deskripsi).
 */
.branding__dialog-body {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 1.25rem;
  padding: 1.25rem;
  overflow-y: auto;
  min-height: 0;
}

.branding__dialog-body .branding__pratinjau {
  align-self: start;
}

.branding__form {
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
  min-width: 0;
}

.branding__field {
  display: flex;
  flex-direction: column;
}

.branding__field-label {
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--color-text-main, #1e293b);
  margin-bottom: 0.35rem;
}

.branding__warna-pilih {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.branding__warna-input {
  flex: 1;
  min-width: 0;
  padding: 0.6rem 0.8rem;
  border: 1.5px solid var(--color-border, #e2e8f0);
  border-radius: 0.6rem;
  background: #fff;
  color: var(--color-text-main, #1e293b);
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-size: 0.85rem;
}

.branding__warna-input:focus {
  outline: none;
  border-color: var(--color-primary, #0ea5e9);
}

/*
 * Kotak warna + tombol pilih warna bawaan browser.
 *
 * Dua lapis karena `input[type=color]` bawaan browser tidak bisa di-style
 * isinya: yang bisa diubah hanya ukuran, border, dan font. Hasilnya kalau
 * dipaksakan jadi "swatch" yang rapi selalu berakhir gruesome, dan yang
 * muncul di layar adalah setengah tombol abu dengan strip warna setebal 4px
 * -- yang tidak pernah terlihat seperti pilihan warna.
 *
 * Jadi yang digambar sendiri adalah kotaknya (`.branding__warna-swatch`)
 * dan input aslinya diletakkan BENING tepat di atasnya. Input asli tetap
 * ada karena hanya dia yang bisa membuka dialog pilih warna.
 *
 * `opacity: 0`, bukan `display: none` atau `visibility: hidden`: keduanya
 * membuat browser berhenti membuka dialog pilihnya, dan warna jadi tidak
 * bisa diganti sama sekali. `opacity: 0` tidak punya perilaku itu.
 * `tabindex="-1"` mengeluarkan input ini dari urutan tab, karena fokusnya
 * sudah ditangani oleh kotak warna yang ada di bawahnya.
 */
.branding__warna-swatch-wrap {
  position: relative;
  width: 2.6rem;
  height: 2.6rem;
  flex: none;
}

.branding__warna-swatch {
  position: absolute;
  inset: 0;
  border: 1.5px solid var(--color-border, #e2e8f0);
  border-radius: 0.6rem;
  /* Warna yang sedang dipilih. `v-bind()` menulis variabel CSS dari nilai
   * script, jadi kotak ini selalu mengikuti isi kolom hex tanpa aturan
   * `.is-aktif` atau watch tambahan. */
  background: var(--branding-warna-aktif);
  pointer-events: none;
}

/*
 * Input asli: menutupi kotak warna sepenuhnya, jadi klik di mana saja
 * pada kotak itu membuka dialog pilih warna.
 */
.branding__warna-native {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  opacity: 0;
  cursor: pointer;
}

.branding__preset {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-top: 0.6rem;
}

.branding__preset-item {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.3rem 0.6rem 0.3rem 0.35rem;
  border: 1.5px solid var(--color-border, #e2e8f0);
  border-radius: 9999px;
  background: #fff;
  color: var(--color-text-main, #1e293b);
  font-family: inherit;
  font-size: 0.72rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
}

.branding__preset-item:hover {
  border-color: var(--color-gray-300, #cbd5e1);
}

.branding__preset-item.is-aktif {
  border-color: var(--color-primary, #0ea5e9);
  background: color-mix(in srgb, var(--color-primary, #0ea5e9) 10%, #fff);
}

.branding__preset-swatch {
  width: 1rem;
  height: 1rem;
  border-radius: 50%;
  border: 1px solid rgb(0 0 0 / 0.08);
  flex: none;
}

.branding__hint,
.branding__error {
  font-size: 0.72rem;
  line-height: 1.5;
  margin: 0.45rem 0 0;
}

.branding__hint {
  color: var(--color-gray-500, #6b7280);
}

.branding__error {
  color: var(--color-danger, #ef4444);
}

.branding__catatan {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  padding: 0.7rem 0.8rem;
  background: var(--color-gray-50, #f8fafc);
  border: 1px solid var(--color-border, #e2e8f0);
  border-radius: var(--border-radius-md, 0.5rem);
  font-size: 0.72rem;
  color: var(--color-gray-500, #6b7280);
  line-height: 1.5;
  margin: 0;
}

.branding__catatan svg {
  margin-top: 0.15rem;
  flex: none;
}

.branding__dialog-foot {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.9rem 1.25rem;
  border-top: 1px solid var(--color-gray-100, #f1f5f9);
}

/* ===== RESPONSIF ===== */

@media (max-width: 62rem) {
  .branding__isi,
  .branding__dialog-body {
    grid-template-columns: minmax(0, 1fr);
  }

  .branding__dialog-body .branding__pratinjau {
    position: static;
  }
}

@media (max-width: 40rem) {
  .branding__baris {
    grid-template-columns: minmax(0, 1fr);
    gap: 0.15rem;
  }

  .branding__dialog-foot .btn {
    flex: 1;
    justify-content: center;
  }
}
</style>
