<template>
  <div class="central-login">
    <!-- Lapisan dekoratif: mesh gradien + sinar diagonal. Murni visual,
         disembunyikan dari screen reader lewat aria-hidden. -->
    <div class="central-login__backdrop" aria-hidden="true">
      <div class="central-login__mesh"></div>
      <div class="central-login__beam"></div>
    </div>

    <!-- Satu objek, dua kolom. Foto lama memakai kartu kaca yang melayang
         di atas latar; sekarang panel kiri gelap dan panel kanan putih
         menyatu jadi satu cangkang, sehingga tidak ada "kartu di dalam
         kartu" yang saling berebut perhatian. -->
    <div class="central-login__shell">
      <!-- ===== Kolom kiri: panggung identitas ===== -->
      <section class="central-login__stage" aria-label="Tentang Panel Pusat">
        <!-- Cincin orbit: dekorasi murni, tidak membawa informasi. -->
        <div class="central-login__orbit" aria-hidden="true">
          <span class="central-login__orbit-ring"></span>
          <span class="central-login__orbit-ring"></span>
          <span class="central-login__orbit-ring"></span>
          <span class="central-login__orbit-core"></span>
        </div>

        <header class="central-login__brand">
          <span class="central-login__mark">
            <font-awesome-icon icon="gem" />
          </span>
          <span class="central-login__brand-text">
            <span class="central-login__brand-name">ASTALOKA</span>
            <span class="central-login__brand-role">Panel Pusat</span>
          </span>

          <!--
            Kembali ke halaman depan duduk DI KOTAK GELAP, sejajar dengan brand.

            Dua percobaan sebelumnya gagal karena posisinya, bukan karena
            kalimatnya. Di bawah tombol "Masuk" jaraknya cuma beberapa piksel,
            jadi terbaca sebagai tombol kedua dari form. Setelah dipindah ke
            atas form, teksnya jadi terlalu dekat dengan judul "Masuk ke Panel
            Pusat" -- padahal yang dilakukan link itu adalah MEMBALIK,
            sebaliknya sekali dari masuk.

            Di sebelah brand masalahnya hilang: yang dipakai sebagai penanda
            adalah kotak gelap itu sendiri, bukan jarak ke form. Tubuh
            halaman -- panel putih berisi kredensial -- ada di sebelah kanan,
            jadi memutar perhatian ke kiri sudah jelas berarti keluar dari
            halaman ini. Link ini satu-satunya elemen di baris itu yang bisa
            diklik, jadi tidak pernah disalahartikan sebagai bagian dari logo.

            `margin-left: auto` yang mengalahkannya ke kanan, bukan `gap`:
            `.brand` sudah punya `gap` sendiri, jadi menambah elemen ketiga
            ke sana tanpa margin akan membagi jarak sama rata di antara tiga
            anak -- brand terdorong menjauh dari tepi kiri, dan posisinya
            berubah tergantung panjang teks "Panel Pusat".
          -->
          <!--
            `aria-label` ada karena di bawah 62rem teksnya disembunyikan CSS
            dan yang tersisa cuma ikon panah. Tanpa label itu, screen reader
            hanya membaca "tombol" tanpa tahu mau ke mana. `title` dipakai
            juga sebagai tooltip untuk user HP yang tidak punya hover.

            Keduanya memakai teks yang sama persis dengan yang tampil di
            desktop, jadi tidak ada dua versi kalimat yang bisa berbeda makna.
          -->
          <a
            :href="landingUrl"
            class="central-login__switch"
            title="Kembali ke halaman depan"
            aria-label="Kembali ke halaman depan"
          >
            <font-awesome-icon icon="arrow-left" />
            <span>Kembali ke halaman depan</span>
          </a>
        </header>

        <div class="central-login__stage-body">
          <p class="central-login__eyebrow">
            <span class="central-login__eyebrow-dot"></span>
            <span>Akses Terbatas</span>
          </p>

          <h1 class="central-login__headline">Kendali <em>Seluruh</em> Lokasi dari Satu Tempat.</h1>

          <p class="central-login__lead">
            Dari sini Anda menambah lokasi, menerbitkan tagihan langganan, dan mengelola kerangka
            akun bersama — tanpa menyentuh sistem operasional lokasi mana pun.
          </p>

          <!--
            Daftar kemampuan pakai `<ol>`, bukan kartu ikon seperti versi
            lama. Urutannya dibaca sebagai alur kerja (tambah lokasi ->
            tagih -> awasi), dan penandanya berupa rel garis yang menyambung
            tiap nomor -- jadi jelas mana yang urutan dan mana yang sekadar
            daftar.
          -->
          <ol class="central-login__steps">
            <li class="central-login__step">
              <span class="central-login__step-no">01</span>
              <span class="central-login__step-body">
                <strong>Manajemen Lokasi</strong>
                <small>Onboarding, konfigurasi, dan status tiap tenant.</small>
              </span>
            </li>
            <li class="central-login__step">
              <span class="central-login__step-no">02</span>
              <span class="central-login__step-body">
                <strong>Tagihan Langganan</strong>
                <small>Terbitkan tagihan dan pantau pembayarannya.</small>
              </span>
            </li>
            <li class="central-login__step">
              <span class="central-login__step-no">03</span>
              <span class="central-login__step-body">
                <strong>Struktur Akun</strong>
                <small>Kerangka akun yang berlaku seragam di semua lokasi.</small>
              </span>
            </li>
          </ol>
        </div>

        <footer class="central-login__stage-foot">
          <p class="central-login__stage-owner">Panel Pusat ASTALOKA</p>
          <p class="central-login__stage-note">Seluruh aktivitas tercatat pada log audit.</p>
        </footer>
      </section>

      <!-- ===== Kolom kanan: form login ===== -->
      <section class="central-login__panel">
        <header class="central-login__panel-head">
          <span class="central-login__panel-chip">
            <font-awesome-icon icon="user-shield" />
            <span>Superadmin</span>
          </span>
          <h2>Masuk ke Panel Pusat</h2>
          <p class="central-login__panel-sub">Gunakan akun superadmin ASTALOKA Anda.</p>
        </header>

        <div class="central-login__alert" role="note">
          <font-awesome-icon icon="circle-info" />
          <p>
            Halaman ini khusus <strong>superadmin pusat</strong>. Akun pengguna lokasi tidak dapat
            masuk di sini.
          </p>
        </div>

        <form class="central-login__form" novalidate @submit.prevent="handleLogin">
          <div class="central-login__field">
            <label for="central-email">Alamat Email</label>
            <div class="central-login__control">
              <font-awesome-icon icon="user" class="central-login__control-icon" />
              <input
                id="central-email"
                v-model.trim="form.email"
                type="email"
                inputmode="email"
                autocomplete="username"
                placeholder="superadmin@astaloka.co.id"
                :class="{ 'is-invalid': errors.email }"
                :aria-invalid="Boolean(errors.email)"
                :aria-describedby="errors.email ? 'central-email-error' : undefined"
                @input="errors.email = ''"
              />
            </div>
            <p v-if="errors.email" id="central-email-error" class="central-login__error">
              <font-awesome-icon icon="triangle-exclamation" />
              {{ errors.email }}
            </p>
          </div>

          <div class="central-login__field">
            <label for="central-password">Kata Sandi</label>
            <div class="central-login__control">
              <font-awesome-icon icon="key" class="central-login__control-icon" />
              <input
                id="central-password"
                v-model="form.password"
                :type="showPassword ? 'text' : 'password'"
                autocomplete="current-password"
                placeholder="••••••••"
                :class="{ 'is-invalid': errors.password }"
                :aria-invalid="Boolean(errors.password)"
                :aria-describedby="errors.password ? 'central-password-error' : undefined"
                @input="errors.password = ''"
              />
              <button
                type="button"
                class="central-login__toggle"
                :aria-label="showPassword ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi'"
                :aria-pressed="showPassword"
                @click="showPassword = !showPassword"
              >
                <font-awesome-icon :icon="showPassword ? 'eye-slash' : 'eye'" />
              </button>
            </div>
            <p v-if="errors.password" id="central-password-error" class="central-login__error">
              <font-awesome-icon icon="triangle-exclamation" />
              {{ errors.password }}
            </p>
          </div>

          <label class="central-login__remember">
            <input v-model="form.remember" type="checkbox" />
            <span class="central-login__remember-box" aria-hidden="true">
              <font-awesome-icon icon="check" />
            </span>
            <span class="central-login__remember-text">
              <strong>Ingat saya</strong>
              <small>Jangan minta kata sandi lagi di perangkat ini selama 30 hari.</small>
            </span>
          </label>

          <button type="submit" class="central-login__submit" :disabled="loading">
            <span v-if="loading" class="central-login__spinner" aria-hidden="true"></span>
            <span>{{ loading ? 'Memverifikasi akses...' : 'Masuk ke Panel Pusat' }}</span>
            <font-awesome-icon v-if="!loading" icon="arrow-right" />
          </button>

          <!--
            Angkanya cocok dengan `AppServiceProvider::configureRateLimiters()`:
            limiter `login` mengizinkan 5 percobaan per menit untuk kombinasi
            email + IP, jadi yang dibatasi adalah email DAN perangkat ini --
            bukan "percobaan gagal berturut-turut" seperti teks sebelumnya.
            Sumber ketidaktepatan itu, orang biasa mempercayainya lalu mengira
            perangkatnya aman padahal yang terkunci hanya email.
          -->
          <p class="central-login__hint">
            <font-awesome-icon icon="shield-halved" />
            Email dan kata sandi dicoba maksimal 5 kali per menit untuk kombinasi email dan
            perangkat ini.
          </p>
        </form>

        <!-- Footer sekarang hanya berisi penutup. Jumlah isinya satu baris,
             tapi `margin-top: auto` tetap dipakai supaya baris ini menempel ke
             dasar panel dan kolom kanan tetap setinggi kolom kirinya -- tanpa
             itu, panel putih jadi lebih pendek dan bentuk dua kolomnya pecah. -->
        <footer class="central-login__footer">
          <p class="central-login__legal">&copy; {{ currentYear }} ASTALOKA.</p>
        </footer>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { MySwal } from '@/utils/swal'
import { centralAuthService } from '@/services/central.service'
import { petakanFieldError, PESAN_TERLAMBAT } from '@/composables/useLoginError'
import { isCentralSessionValid, clearCentralSession } from '@/utils/centralAxios'
import { apiOrigin } from '@/utils/apiBase'
import '@/assets/css/central-login.css'

const router = useRouter()

const form = reactive({ email: '', password: '', remember: false })
const errors = reactive({ email: '', password: '' })
const loading = ref(false)
const showPassword = ref(false)

const currentYear = new Date().getFullYear()

/*
| Landing page bukan bagian dari SPA.
|
| `landing.blade.php` dilayani Laravel dari host backend (`/` di routes/web.php),
| sedangkan halaman ini dilayani Vite. Jadi `href="/"` di sini -- kalau SPA yang
| melayani -- hanya akan memicu route Vue `path: '/'` yang isinya
| `redirect: '/login'`, jadi user yang ingin balik ke halaman depan justru
| mendarat di halaman login yang baru saja ia tinggalkan. Itu membingungkan
| dan kelihatan seperti tombol rusak.
|
| `VITE_BACKEND_URL` SENGAJA TIDAK dipakai, meski di sini kelihatan aman
| karena panel pusat memang berada di host pusat. Alasannya: nilai itu
| menunjuk ke backend, dan bisa berbeda dari tempat panel ini dibuka --
| development memakai `localhost:5173`, produksi memakai host yang sama.
| Membaca `VITE_API_BASE_URL` saja membiarkan satu override eksplisit,
| sedangkan bawaan selalu mengikuti tempat panel benar-benar dibuka.
*/
const landingUrl = (() => {
  if (import.meta.env.VITE_API_BASE_URL) {
    // Root backend, tanpa suffix `/api` -- yang kita butuhkan halaman depan,
    // bukan basis API. `apiOrigin()` sudah membuang satu suffix `/api`,
    // jadi nilai yang sudah berbentuk `.../api` tidak jadi `.../api/api`.
    return `${apiOrigin()}/`
  }

  // Tanpa konfigurasi, andalkan origin sendiri. Di produksi ini root backend;
  // di development ini Vite, dan `/` di sana diteruskan ke host backend oleh
  // proxy -- hasilnya sama, tanpa perlu URL yang ditulis tangan.
  return '/'
})()

const toastBase = {
  toast: true,
  position: 'top-end',
  showConfirmButton: false,
  timerProgressBar: true,
  customClass: {
    popup: 'swal-toast-custom',
    title: 'swal-toast-title',
    container: 'swal-toast-container',
  },
}

const handleLogin = async () => {
  // Validasi di sisi klien hanya untuk memberi pesan yang jelas dan cepat.
  // Ini bukan gerbang keamanan: penentuannya ada di
  // `CentralAuthController::login` yang membaca database pusat.
  errors.email = form.email ? '' : 'Alamat email wajib diisi.'
  errors.password = form.password ? '' : 'Kata sandi wajib diisi.'

  if (errors.email || errors.password) {
    document.getElementById(errors.email ? 'central-email' : 'central-password')?.focus()
    return
  }

  loading.value = true

  try {
    const body = await centralAuthService.login(
      {
        email: form.email,
        password: form.password,
      },
      // Pilihan "ingat saya" hanya mengubah masa berlaku penanda lokal di
      // browser, dikirim sebagai opsi terpisah -- bukan field di body
      // request. Backend tidak perlu ikut tahu: yang dia issues adalah
      // token Sanctum tanpa masa berlaku, dan masa berlaku 8 jam itu memang
      // keputusan antarmuka sejak awal.
      { remember: form.remember },
    )

    if (!body?.success) {
      throw new Error(body?.message || 'Login gagal.')
    }

    await MySwal.fire({
      ...toastBase,
      icon: 'success',
      title: 'Login Berhasil',
      text: `Selamat datang, ${body.data.user.name}`,
      timer: 2500,
    })

    router.push('/central')
  } catch (error) {
    /*
     * Kegagalan login di halaman ini SELALU berupa pesan di bawah kolomnya.
     *
     * 422 = kredensial salah, dan backend sudah menunjuk kolomnya: `email`
     * untuk email yang tidak dikenal, `password` untuk kata sandi yang
     * salah. 429 = kena `throttle:login`, yang batasnya sudah tertulis di
     * bawah form -- jadi kalimat di sini cukup mengarahkan ke sana.
     *
     * Yang tersisa hanya kegagalan tanpa kolom (jaringan, timeout, 500),
     * dan itulah satu-satunya hal yang membenarkan toast di halaman ini.
     *
     * `petakanFieldError()` sengaja dipakai, bukan `terapkan()` dari
     * `useLoginError`: halaman ini punya `errors` sendiri yang dipakai juga
     * untuk validasi sebelum request, jadi yang dibutuhkan hanya fungsi
     * pemetaan respnya.
     */
    const perField = petakanFieldError(error)

    if (perField) {
      errors.email = perField.email || ''
      errors.password = perField.password || ''
    } else if (error.response?.status === 429) {
      errors.email = PESAN_TERLAMBAT
    } else {
      MySwal.fire({
        icon: 'error',
        title: 'Tidak Bisa Masuk',
        text: error.response?.data?.message || 'Gagal menghubungi server pusat.',
        confirmButtonColor: '#0ea5e9',
      })
    }

    /*
     * Kata sandi dikosongkan supaya superadmin tidak perlu mengetik ulang dari
     * awal saat mencoba lagi; email dibiarkan supaya tidak melelahkan pada
     * percobaan berulang yang memang gagal.
     *
     * Kursor dialihkan ke kolom yang benar-benar bermasalah. Kalau yang salah
     * adalah email, melodokkan kata sandi -- yang isinya sudah benar --
     * hanya membuat orang mengoreksi kolom yang tidak perlu diperbaiki.
     */
    if (errors.password) {
      form.password = ''
    }

    document.getElementById(errors.email ? 'central-email' : 'central-password')?.focus()
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  const params = new URLSearchParams(window.location.search)

  // Sesi masih hidup -> tidak perlu login ulang.
  if (isCentralSessionValid()) {
    router.replace('/central')
    return
  }

  // Sisa sesi tidak valid (kedaluwarsa atau ditolak server) -> bersihkan
  // sebelum menampilkan form, supaya tidak ada token mati yang ikut terkirim
  // ke setiap request berikutnya.
  clearCentralSession()

  /*
   * Kabar "logout berhasil" DI SINI, bukan di halaman yang memicunya.
   *
   * Alasannya: halaman yang memicunya (panel pusat) sudah dibongkar saat
   * user dipindahkan ke sini. Toast yang dibuat di sana akan ikut hilang
   * bersama komponennya, jadi tidak ada yang pernah sampai terlihat.
   * Halaman login adalah tempat terakhir yang masih hidup, jadi di sinilah
   * kabarnya disampaikan.
   *
   * Bentuknya mengikuti aplikasi lokasi (`views/auth/LoginView.vue`): ikon
   * centang, judul, dan sapaan untuk namanya. Tanpa sapaan itu, orang yang
   * logout lalu login lagi di komputer yang sama tidak punya cara memastikan
   * bahwa sesi yang tersisa memang miliknya -- bukan orang sebelumnya.
   */
  if (params.get('logout')) {
    const nama = params.get('name')

    MySwal.fire({
      ...toastBase,
      icon: 'success',
      title: 'Logout Berhasil',
      text: nama ? `Terima kasih, ${nama}` : 'Terima kasih!',
      timer: 3000,
    })

    /*
     * Parameter `logout` dan `name` DIBUANG dari URL setelah toast muncul.
     *
     * Kalau dibiarkan, memuat ulang halaman (F5) akan memunculkan toast
     * "Logout Berhasil" lagi -- padahal yang logout sudah lama selesai, dan
     * sekarang orang itu mungkin sedang mencoba login. Pesan yang salah
     * waktu lebih membingungkan daripada tidak ada pesan sama sekali.
     */
    window.history.replaceState({}, document.title, window.location.pathname)
    return
  }

  if (params.get('expired')) {
    MySwal.fire({
      ...toastBase,
      icon: 'warning',
      title: 'Sesi Berakhir',
      text: 'Silakan masuk kembali ke panel pusat.',
      timer: 3500,
    })
    window.history.replaceState({}, document.title, window.location.pathname)
  }
})
</script>
