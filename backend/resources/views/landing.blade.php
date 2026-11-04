<!DOCTYPE html>
<html lang="id" class="no-js">
<head>
    {{--
        Class `no-js` dibuang oleh script di bawah tepat sebelum animasi
        dipasang. Selector `.reveal` punya `opacity: 0`, jadi kalau JS
        gagal dimuat seluruh halaman akan kosong. Guard ini yang
        memastikan konten tetap terlihat apa pun yang terjadi.

        Script-nya inline dan blocking dengan sengaja: kalau placement-nya
        di akhir body, halaman sempat ter-render tak terlihat sebelum
        class berubah -- persis kilatan yang ingin dihindari.
    --}}
    <script>document.documentElement.classList.remove('no-js');</script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="ASTALOKA oleh Asta Brata Teknologi adalah platform pengelolaan usaha, administrasi pelanggan, transaksi, dan laporan keuangan. Untuk berbagai skala usaha: air minum, internet, pembayaran sampah, jasa, dan layanan lainnya.">
    <meta name="theme-color" content="#0369a1">

    <title>ASTALOKA &mdash; Asta Layanan Operasional, Keuangan, dan Administrasi</title>

    {{--
        Favicon.

        Sebelumnya pakai emoji chart (U+1F4C8) di dalam SVG data-URI.
        Itu masalah yang sama seperti ikon di kartu: yang dirender adalah
        glyph dari font emoji sistem operasi, jadi bentuknya beda di tiap
        perangkat, dan di beberapa sistem bisa tampil kotak kosong di tab
        browser -- termasuk di layar yang paling terlihat.

        Sekarang huruf A dalam kotak biru, digambar penuh, jadi tidak
        bergantung font apa pun.

        Tanda kutip di dalam data-URI ditulis sebagai `%22`. Kalau
        ditulis literal, kutip itu menutup atribut HTML lebih dulu
        dan link rusak tanpa error yang kelihatan.

        `viewBox` 0 0 64 64 dengan huruf yang tidak menyentuh tepi: pada
        ikon 16px, bentuk yang menempel tepi akan tergerus penskalaan.
    --}}
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='15' fill='%230369a1'/%3E%3Cpath d='M20 46L32 17l12 29h-6.4l-2.2-5.8H28.6L26.4 46zM30.4 34.8h3.2L32 29.2z' fill='%23fff'/%3E%3C/svg%3E">

<style>
        /* ============================================================
           DESIGN TOKENS

           Bayangan sengaja berlapis tiga lapis (ambient + key + contact).
           Satu bayangan flat selalu terlihat seperti kotak cetak; tiga
           lapis itulah yang bikin kartu terasa terangkat dari halaman.

           Prefix `--sh-` dipakai semua kelas supaya nilainya bisa diubah
           dari satu tempat, bukan disebar di puluhan deklarasi.
           ============================================================ */
        :root {
            /* Palet brand: sky.
               Sumber acuan = aplikasi admin lokasi/tenant
               (frontend/src/assets/css/main.css `--color-primary`),
               bukan teal. Semua nada di halaman ini -- tombol, garis
               bawah nav, kartu, mockup, panel penutup -- diturunkan dari
               skala ini, jadi ganti warna di satu blok ini cukup. */
            --sky-50:  #f0f9ff;
            --sky-100: #e0f2fe;
            --sky-200: #bae6fd;
            --sky-300: #7dd3fc;
            --sky-400: #38bdf8;
            --sky-500: #0ea5e9;
            --sky-600: #0284c7;
            --sky-700: #0369a1;
            --sky-800: #075985;
            --sky-900: #0c4a6e;
            --sky-950: #082f49;

            --ink:      #0f172a;
            --ink-soft: #475569;
            --ink-mute: #94a3b8;
            --line:     #e2e8f0;
            --bg:       #ffffff;
            --bg-soft:  #f8fafc;

            /* Slate + emerald: palet untuk mockup terminal.
               Dipisah dari palet halaman utama supaya kartu gelap tidak
               ikut berubah kalau warna brand nanti diganti. */
            --slate-400: #94a3b8;
            --slate-500: #64748b;
            --slate-900: #0f172a;
            --emerald-300: #6ee7b7;
            --emerald-400: #34d399;
            /* `--emerald-500` ditambahkan khusus untuk titik status
               "versi terbaru" di navbar dan footer.

               300 dan 400 terlalu muda untuk dipakai di sini: di atas
               putih keduanya cuma terbaca sebagai hijau pucat, bukan
               sebagai tanda "aktif". Ini bukan teks, jadi aturan
               kontras teks tidak berlaku -- tapi luminance-nya tetap
               harus cukup tinggi supaya terbaca di layar terang dan
               di panel gelap. */
            --emerald-500: #10b981;
            --amber-400:  #fbbf24;

            --r-sm: 10px;
            --r:    14px;
            --r-lg: 20px;
            --r-xl: 26px;

            /* contact: sangat rapat, menempel permukaan
               key:    arah cahaya, inti elevate
               ambient:luas, sangat tipis, menyebar */
            --sh-xs: 0 1px 2px rgb(15 23 42 / .05);

            --sh-sm: 0 1px 2px rgb(15 23 42 / .04),
                     0 2px 6px -2px rgb(15 23 42 / .06);

            --sh-md: 0 2px 4px -1px rgb(15 23 42 / .04),
                     0 8px 20px -6px rgb(15 23 42 / .10);

            --sh-lg: 0 4px 8px -2px rgb(15 23 42 / .05),
                     0 16px 36px -10px rgb(15 23 42 / .14);

            --sh-xl: 0 8px 16px -4px rgb(15 23 42 / .06),
                     0 28px 60px -16px rgb(15 23 42 / .18);

            --sh-brand: 0 6px 16px -4px rgb(2 132 199 / .32),
                        0 2px 6px -2px rgb(2 132 199 / .20);

            --ease:   cubic-bezier(.16, 1, .3, 1);
            --spring: cubic-bezier(.34, 1.56, .64, 1);
        }

        *, *::before, *::after { box-sizing: border-box; }

        html {
            scroll-behavior: smooth;
            /* Clip horizontal di level `html`, bukan `body`.

             Mockup HP sengaja diberi `margin-right` negatif supaya
             keluar melewati tepi kanan panel hero. `overflow-x: hidden`
             di `body` TIDAK selalu meng-clip-nya -- di beberapa browser
             `overflow` pada `body` dipropagasi ke viewport dan jadi
             `visible`, sehingga halaman bisa bergeser ke samping di
             ponsel. Memotong di `html` bekerja konsisten karena langsung
             membentuk clipping context untuk seluruh turunan.
          */
            overflow-x: hidden;
        }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI",
                         Roboto, "Helvetica Neue", Arial, sans-serif;
            color: var(--ink);
            background: var(--bg);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        a { color: inherit; }

        /*
         * Header `position: sticky` menutupi TARGET anchor saat link nav
         * diklik: `scroll-margin-top` memberi tahu browser bahwa area
         * teratas elemen itu terhalang header setinggi 70px, sehingga
         * browser menggeser halaman lebih jauh ke bawah agar judul
         * section tidak tersembunyi di balik header.
         *
         * Tanpa ini, klik "Fitur" mendarat dengan heading "#fitur" tepat
         * tertutup header -- konten terlihat, tapi tidak terbaca.
         */
        section[id], [id="masuk"] { scroll-margin-top: 88px; }

        /* `max-width` + `width: 100%` + padding: kalimat "box-sizing: border-box"
           di atas membuat padding dihitung di dalam lebar, jadi .wrap tidak
           pernah melebihi viewport. */
        .wrap { width: 100%; max-width: 1160px; margin-inline: auto; padding-inline: 24px; }

        /* ---------- animasi ---------- */
        @keyframes rise {
            from { opacity: 0; transform: translate3d(0, 26px, 0); }
            to   { opacity: 1; transform: none; }
        }
        @keyframes fade {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        @keyframes drift-b {
            from { transform: translate3d(0, 0, 0) scale(1.08); }
            to   { transform: translate3d(-60px, 60px, 0) scale(1); }
        }
        @keyframes pulse {
            0%   { box-shadow: 0 0 0 0 rgb(14 165 233 / .5); }
            70%  { box-shadow: 0 0 0 9px rgb(14 165 233 / 0); }
            100% { box-shadow: 0 0 0 0 rgb(14 165 233 / 0); }
        }

        /* Elemen .reveal muncul saat di-scroll, bukan hanya saat page load.

           PENTING: tanpa JavaScript, `opacity: 0` di sini akan membuat
           seluruh konten halaman hilang permanen. Karena itu `html.no-js`
           (dipasang satu baris inline di <head>) memaksa semua elemen
           terlihat, dan IntersectionObserver hanya meng-upgrade elemen itu
           ke animasi kalau JS memang hidup. Konten tidak pernah hilang
           karena animasi gagal. */
        .reveal { opacity: 0; }
        html.no-js .reveal { opacity: 1; transform: none; }

        /* `--d` (delay) dipasang per-elemen di HTML supaya tiap baris
           muncul berurutan, bukan semuanya sekaligus. */
        .reveal.shown { animation: rise .78s var(--ease) var(--d, 0ms) forwards; }

        /* PENTING: `.hero-art` punya class `reveal` sekaligus transform
           geser dari JS. Keduanya menulis properti `transform`.

           Kalau `rise` yang dipakai, `animation` dengan fill-mode
           `forwards` akan MENYIMPAN frame akhirnya (`transform: none`)
           dan terus menimpanya -- hasilnya mockup terkunci di tengah
           dan tidak bisa digeser sama sekali.

           Karena itu `.hero-art` punya `rise-mockup` sendiri: sama
           sama fade, tapi tanpa menulis `transform` sama sekali.
           Transform dikuasai penuh oleh JS. */
        @keyframes rise-mockup {
            from { opacity: 0; }
            to   { opacity: 1; }
        }
        .hero-art.reveal.shown { animation: rise-mockup .78s var(--ease) var(--d, 0ms) forwards; }

        /* `.mock` (kartu dashboard) punya masalah yang sama dengan
           `.hero-art`, dan untuk alasan yang persis sama.

           `.mock` sekarang punya `transform` sendiri yang disusun dari
           `--mx`/`--my`/`--lift` untuk tilt 3D (lihat blok "dorong 3D").
           Kalau `.reveal.shown` tetap memakai `rise`, animasi itu akan
           menyimpan `transform: none` di frame akhir dengan fill-mode
           `forwards` -- dan karena specificity `.reveal.shown` (0,2,0)
           menang atas `.mock` (0,1,0), transform tilt akan ditimpa
           permanen begitu kartu selesai muncul.

           Gejalanya: kartu terlihat masuk dengan normal, tapi tidak
           bisa dimiringkan sama sekali saat kursor lewat. Tanpa
           pemeriksaan spesifisitas ini, gejalanya jauh lebih buruk --
           mengira tilt-nyarusak, padahal tidak pernah jalan.

           Solusinya sama: pakai `rise-mockup` yang hanya menulis
           `opacity`. Transform sepenuhnya milik CSS `--mx`/`--my`. */
        .mock.reveal.shown { animation: rise-mockup .78s var(--ease) var(--d, 0ms) forwards; }

        /* ---------- header ---------- */
        header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgb(255 255 255 / .78);
            backdrop-filter: saturate(180%) blur(16px);
            -webkit-backdrop-filter: saturate(180%) blur(16px);
            border-bottom: 1px solid transparent;
            transition: border-color .3s var(--ease), box-shadow .3s var(--ease);
        }

        /* Dipasang JS saat scroll: border + bayangan baru muncul setelah
           header benar-benar "cucuk" dari konten, bukan menempel dari atas. */
        header.stuck {
            border-bottom-color: var(--line);
            box-shadow: 0 1px 3px rgb(15 23 42 / .05), 0 8px 24px -12px rgb(15 23 42 / .16);
        }

        /* Nama `.navbar`, bukan `.bar`: `.bar` pernah dipakai untuk batang diagram.
           Nama yang sama di dua konteks berarti aturan CSS diam-diam
           saling menimpa, dan itu sulit dilacak karena tidak ada error. */
        .navbar {
            display: flex; align-items: center; gap: 16px;
            height: 70px;
            position: relative;
        }

        .brand {
            display: flex; align-items: center; gap: 11px;
            font-weight: 700; letter-spacing: -.02em; text-decoration: none;
        }
        .brand .dot {
            width: 36px; height: 36px; border-radius: 11px;
            display: grid; place-items: center;
            background: linear-gradient(135deg, var(--sky-400), var(--sky-700));
            color: #fff; font-size: 18px; font-weight: 800;
            box-shadow: var(--sh-brand);
            transition: transform .5s var(--spring), box-shadow .3s var(--ease);
        }
        .brand:hover .dot { transform: rotate(-8deg) scale(1.08); }
        .brand small {
            display: block; font-size: 11px; font-weight: 500;
            /* `ink-soft`, bukan `ink-mute`. `ink-mute` (#94a3b8) di
               atas putih cuma 2.56:1 -- gagal di teks sekecil 11px.
               Ini baris yang menjelaskan produk dalam dua kata, jadi
               termasuk teks yang harusnya terbaca, bukan dekorasi. */
            color: var(--ink-soft); letter-spacing: 0; line-height: 1.15;
        }

        /* ---------- navbar ----------

           Desktop: deretan link + tombol. Setiap link punya garis bawah
           yang tumbuh dari kiri saat hover, dan link section yang sedang
           dibaca ditandai `.is-active` oleh scroll-spy di JS.

           Mobile (<=900px): `nav` berubah jadi panel dropdown yang
           menempel di bawah header. Tombol hamburger menggantikannya. */
        .nav {
            margin-left: auto;
            display: flex; align-items: center; gap: 4px;
        }

        /* `inline-flex`, bukan `inline` (nilai bawaan `a`).

           WAJIB, bukan sekadar lebih rapi: anak `<span class="navico">`
           adalah `display: grid`. Elemen grid adalah block-level --
           di dalam induk yang sebaris, ia akan memaksa link pecah
           jadi dua baris: ikon di atas, teks di bawahnya. `inline-flex`
           membuat anak-anaknya ikut flex item, jadi ikon dan teks
           tetap satu baris.

           `align-items: center` ditambahkan, sebelumnya tidak ada: tanpa
           itu, ikon 30px dan teks 14.5px akan disejajarkan ke
           garis dasar yang sama, dan kotak ikon yang lebih tinggi
           terlihat menjorok ke bawah.

           Jarak ikon-ke-teks diatur dengan `gap: 7px` di bawah,
           bukan dengan margin di dalam `.navico` -- lihat
           catatan di aturan itu. */
        .nav a.navlink {
            position: relative;
            display: inline-flex;
            align-items: center;
            /* `gap: 7px` jarak ikon-ke-teks.

               Tulis eksplisit di sini, bukan margem di dalam
               `.navico`: yang diatur adalah jarak antar item di
               dalam link, sama seperti `gap: 8px` di `.btn`.

               `.nav` punya `gap: 4px` untuk jarak antar link, dan
               jarak itu tidak diwarisi ke dalam anak. Tanpa baris
               ini jarak ikon dan teks ikut bawaan flex (~0px) --
               terlalu rapat untuk kotak ikon 30px yang berdampingan
               dengan teks 14.5px, dan keduanya terlihat seperti satu
               blok tanpa jeda. */
            gap: 7px;
            text-decoration: none; color: var(--ink-soft);
            font-size: 14.5px; font-weight: 500;
            padding: 9px 13px; border-radius: var(--r-sm);
            transition: color .2s var(--ease), background .2s var(--ease);
        }

        /* Garis bawah penanda: scaleX 0 -> 1 dari kiri, bukan fade,
           jadi tumbuh searah gerakan membaca. */
        .nav a.navlink::after {
            content: ""; position: absolute;
            left: 13px; right: 13px; bottom: 3px; height: 2px;
            border-radius: 2px;
            background: linear-gradient(90deg, var(--sky-400), var(--sky-700));
            transform: scaleX(0); transform-origin: left;
            transition: transform .32s var(--ease);
        }
        .nav a.navlink:hover::after,
        .nav a.navlink.is-active::after { transform: scaleX(1); }

        /* Section yang sedang dibaca: warna brand penuh, garis bawah
           tetap terlihat, latar sedikit lebih kuat. */
        .nav a.navlink.is-active {
            color: var(--sky-800); background: var(--sky-50); font-weight: 600;
        }

        /* ---------- ikon di navbar ----------

           Ikon berdiri di sebelah kiri teks, jadi urutannya mengikuti
           arah baca orang: gambar dulu, baru kata. Kalau ikon diletakkan
           setelah teks, mata membaca kata dulu dan ikon terasa seperti
           Lambang kecil yang ditambahkan belakangan.

           Ikon DIDIBERIKAN kotak sendiri (`display: grid;
           place-items: center`) dan dikunci 30x30, bukan hanya
           diberi `width`/`height`.

           Alasannya celah baseline. `<svg>` inline adalah elemen
           sebaris: ia duduk di baseline baris pertama, dan spasi
           putih di markup ikut menambah tinggi baris. Di navbar
           yang tinggi hanya 70px, pergeseran 1-2px itu cukup untuk
           membuat link terlihat tidak center -- dan di deretan link
           yang semua punya ikon, misalignment yang sama jadi pola,
           bukan lagi detail yang tak terlihat.

           Kotak yang dikunci juga mencegah ikon ikut gepeng kalau
           teksnya panjang: tanpa itu, ikon menjadi satu-satunya
           anak yang bisa menyusut, dan tiga link dengan panjang
           teks berbeda punya ikon pada tinggi berbeda. */
        .navlink .navico {
            width: 30px; height: 30px;
            flex: none;
            display: grid; place-items: center;
            /* Margin kiri negatif: kotak ikon ditekan sedikit ke
               luar supaya jarak ikon-ke-teks terasa sama dengan
               jarak teks-ke-tepi link di sebelah kanan.

               Tanpa itu, sisi kiri link jadi 13px padding + 30px
               kotak, sedangkan sisi kanan 13px saja -- link terlihat
               tidak simetris dan seluruh navbar terasa meleset ke
               kanan. Nilai -4px membalas 4px dari 30px, jadi sisi
               kirinya jadi 9px: cukup untuk terlihat sejajar secara
               optik, tanpa membuat ikon menempel ke padding. */
            margin-left: -4px;
            border-radius: 9px;
            background: var(--sky-50);
            color: var(--sky-600);
            transition: background .25s var(--ease), color .25s var(--ease),
                        transform .3s var(--spring);
        }

        .navlink .navico svg {
            width: 17px; height: 17px;
            display: block;
            fill: none; stroke: currentColor;
            /* 2.4, bukan 1.8: pada 17px, 1.8 hanya menghasilkan ~1.28px
               goresan efektif -- di bawah batas Anti-Aliasing, jadi
               ikon berubah jadi kabut abu. Lihat catatan panjang di
               `.mock__ico svg`. */
            stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round;
        }

        /* Ikon ikut state link-nya, bukan punya warna sendiri.

           Kalau ikon tetap biru saat link diam, barisan navbar
           terbaca sebagai satu blok biru di kiri teks abu --
           dan warna link (abu vs biru) kehilangan fungsinya
           sebagai penanda state.

           Latar kotak juga ikut: saat diam transparan (hanya
           ikon yang terlihat, navbar tetap ringan), jadi hover
           punya sesuatu yang berubah selain warna teks -- kotak
           putih muncul tepat di bawah ikon. */
        .nav a.navlink .navico { color: var(--ink-mute); background: transparent; }
        .nav a.navlink:hover .navico,
        .nav a.navlink.is-active .navico {
            color: var(--sky-700);
            background: #fff;
            transform: translateY(-1px);
        }

        /* Ikon saat hover ikut bergerak sedikit ke atas, supaya tautan
           terasa hidup tanpa teksnya ikut bergeser. Geser hanya ikon,
           teks diam: kalau keduanya bergerak, link terlihat meleset
           dan bukan lagi "dinyentuh". */
        .nav a.navlink:hover { color: var(--sky-700); background: var(--sky-50); }

        /* Garis bawah penanda tetap mulai dari padding link. Kotak ikon
           menambah lebar di sisi kiri, tapi garis harus tetap
           menempel di area teks: kalau ikut bergeser, garis terlihat
           mulai di tengah ikon dan bukan lagi sebagai penanda kata. */

        .nav a.navlink:focus-visible,
        .nav-toggle:focus-visible,
        .to-top:focus-visible {
            outline: 2px solid var(--sky-500); outline-offset: 2px;
        }

        /* ---------- tombol hamburger ----------

           Tiga batang yang morph jadi X. Batangnya punya `transform-origin`
           di tengah supaya berputar pada sumbunya sendiri, bukan di
           ujung -- itu yang bikin garis tengah "naik" saat berubah. */
        .nav-toggle {
            display: none;                 /* desktop: disembunyikan */
            margin-left: auto;
            width: 42px; height: 42px;
            padding: 0; border: 1px solid var(--line); border-radius: 12px;
            background: #fff; cursor: pointer;
            box-shadow: var(--sh-xs);
            transition: box-shadow .25s var(--ease), border-color .25s var(--ease),
                        transform .25s var(--spring);
        }
        .nav-toggle:hover { border-color: var(--sky-200); box-shadow: var(--sh-sm); }
        .nav-toggle:active { transform: scale(.94); }

        .nav-toggle span {
            display: block;
            width: 18px; height: 2px; margin: 0 auto; border-radius: 2px;
            background: var(--ink);
            transition: transform .34s var(--ease), opacity .2s var(--ease),
                        width .34s var(--ease);
        }
        .nav-toggle span + span { margin-top: 4px; }

        /* Batang atas turun, bawah naik, tengah berputar 90 derajat jadi vertikal.
           Lebar batang tengah dikecilkan supaya tidak terlihat lebih tebal dari
           dua batang lain saat jadi vertikal. */
        .nav-toggle[aria-expanded="true"] span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
        .nav-toggle[aria-expanded="true"] span:nth-child(2) { width: 0; opacity: 0; }
        .nav-toggle[aria-expanded="true"] span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

        /* ---------- tombol ---------- */
        .btn {
            position: relative; overflow: hidden;
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            text-decoration: none; font-weight: 600; font-size: 14.5px;
            padding: 12px 22px; border-radius: var(--r);
            border: 1px solid transparent; cursor: pointer;
            transition: transform .18s var(--ease), box-shadow .28s var(--ease),
                        background .22s var(--ease), border-color .22s var(--ease);
        }

        /* Sheen: cahaya melintas saat hover. Pure CSS, jadi tidak ada JS
           yang perlu dibersihkan saat navigasi. */
        .btn::after {
            content: ""; position: absolute; inset: 0;
            background: linear-gradient(120deg, transparent 20%,
                        rgb(255 255 255 / .28) 50%, transparent 80%);
            transform: translateX(-120%);
            transition: transform .7s var(--ease);
            pointer-events: none;
        }
        .btn:hover::after { transform: translateX(120%); }
        .btn:active { transform: translateY(1px) scale(.985); }

        .btn-primary {
            background: linear-gradient(135deg, var(--sky-600), var(--sky-700));
            color: #fff; box-shadow: var(--sh-brand);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px -6px rgb(2 132 199 / .42), 0 3px 8px -2px rgb(2 132 199 / .26);
        }
        .btn-primary:active { transform: translateY(0) scale(.985); }

        .btn-ghost { border-color: var(--line); color: var(--ink); background: #fff; box-shadow: var(--sh-xs); }
        .btn-ghost:hover {
            border-color: var(--sky-300); color: var(--sky-700);
            box-shadow: var(--sh-md); transform: translateY(-2px);
        }

        .btn-lg { padding: 15px 28px; font-size: 16px; }

        /* ---------- tombol "Masuk" di navbar ----------

           Tombol ini satu-satunya pintu masuk ke produk, jadi ia
           harus terbaca sebagai pintu -- bukan sebagai tombol
           biru keempat di barisan link.

           Yang membuatnya begitu ada tiga hal yang sengaja
           dipisah dari `.btn` global: warna yang lebih pekat
           (diturunkan satu langkah ke bawah di skala, bukan satu
           warna brand yang sama), ikon yang bergerak, dan badge
           versi yang menempel di luar tepi kanan.

           Kenapa warnanya justru lebih gelap dari tombol
           primary lain: navbar berada di atas putih, dan tombol
           biru medium di atas putih terlihat benar-benar
           "terang" -- berebut perhatian dengan teks biru tua
           di link aktif. Menurunkan satu langkah membuat
           tombol tetap jadi titik fokus tanpa warna yang
           harus lebih terang dari link di sebelahnya. */
        .nav .btn-login {
            gap: 9px;
            padding: 10px 20px 10px 17px;
            font-size: 14px;
            border-radius: 999px;              /* bentuk pil, beda dari
                                                  tombol kotak di hero dan
                                                  section penutup */
            background: linear-gradient(135deg, var(--sky-700), var(--sky-800));
            color: #fff;
            box-shadow: 0 4px 12px -4px rgb(2 132 199 / .45),
                        inset 0 1px 0 rgb(255 255 255 / .14);
            /* Badge versi keluar dari tepi tombol, jadi `overflow`
               harus `visible` -- padanya `.btn` memakai `hidden`
               supaya sheen tidak keluar dari radius tombol.

               Konsekuensi yang harus ditangani: sheen `.btn::after`
               ikut jadi tidak ter-clip, jadi baris di bawah
               memberikannya radius sendiri. Tanpa itu, cahaya akan
               keluar sebagai persegi setiap kali hover.

               Dan ini bukan masalah lokal: `.btn` dipakai di semua
               tombol halaman ini, jadi radius yang lupa ditulis di
               sini akan hilang bukan cuma di navbar.

               Alternatifnya badge dibuat anak dari wrapper di luar
               tombol, tapi itu menambah elemen DOM dan satu lagi
               selector yang harus dijaga; `overflow: visible`
               plus satu baris radius lebih murah dan lebih mudah
               dilacak. */
            overflow: visible;
        }
        .nav .btn-login::after { border-radius: 999px; }
        .nav .btn-login:hover {
            background: linear-gradient(135deg, var(--sky-600), var(--sky-800));
            transform: translateY(-1px);
            box-shadow: 0 8px 20px -6px rgb(2 132 199 / .55),
                        inset 0 1px 0 rgb(255 255 255 / .18);
        }

        /* Ikon kunci di tombol Masuk.

           Bergeser ke kanan sedikit saat hover, seperti meluncur
           masuk ke dalam kunci.

           Ini satu-satunya tombol di halaman yang ikonnya bergerak,
           dan itu disengaja. Tombol ini tombol tujuan, bukan
           tombol aksi: yang dipinta pengguna adalah "buka pintu",
           dan gerak ke arah kunci cocok dengan makna gerakannya.

           `.btn svg` sudah mengunci ukuran dan mode garis,
           jadi di sini cukup `transition` + state hover. */
        .nav .btn-login .login-ico {
            width: 16px; height: 16px; flex: none;
            display: block;
            transition: transform .4s var(--spring);
        }
        .nav .btn-login:hover .login-ico { transform: translateX(2px); }

        /* ---------- badge versi ----------

           "1.0" di pojok kanan tombol, keluar sedikit dari tepi
           pill.

           Keluar dari tepi, bukan di dalam barisan: kalau
           diletakkan di dalam, ia jadi satu item lagi di antara
           ikon dan teks -- dan tombol yang isinya "Masuk 1.0"
           terbaca seperti produk bernama "1.0", bukan tombol masuk
           dengan penanda versinya.

           Pointer-events none WAJIB, bukan tambahan: badge keluar
           dari tepi tombol, jadi ada area yang secara visual milik
           badge tapi bukan anak dari elemen `<a>`. Tanpa baris ini,
           area itu bisa jadi target klik, dan klik yang jatuh di
           sana tidak akan mendarat di tombol -- bukan cuma meleset,
           tapi hilang. */
        .ver-badge {
            position: absolute;
            top: -7px; right: -3px;
            z-index: 2;
            display: inline-flex; align-items: center; gap: 4px;
            padding: 2px 8px 2px 7px;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--sky-400), var(--sky-500));
            color: var(--sky-950);
            border: 1px solid #fff;
            box-shadow: 0 3px 10px -2px rgb(2 132 199 / .5);
            font-size: 10px; font-weight: 800; line-height: 1.5;
            letter-spacing: .02em;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
            pointer-events: none;
        }

        /* Titik "tersedia": penanda bahwa ini versi yang dipakai,
           bukan sekadar nomor. Tanpa titik, badge hanya angka,
           dan angka saja tidak menyatakan apa pun.

           Cincin tipis di sekeliling titik membuatnya terbaca
           sebagai "live" pada ukuran 5px, yang tanpa cincin hanya
           berupa gumpalan hijau. */
        .ver-badge::before {
            content: "";
            width: 5px; height: 5px; flex: none;
            border-radius: 50%;
            background: var(--emerald-500);
            box-shadow: 0 0 0 2px rgb(16 185 129 / .28);
        }

        /* ---------- animasi badge ----------

           PURE CSS, tanpa JS sama sekali.

           Versi pertama memakai `opacity: 0` di gaya dasar dan
           menunggu class `is-ready` dari JS. Itu pilihan yang salah:
           `opacity: 0` di gaya dasar berarti badge TIDAK TERLIHAT
           kalau skrip gagal dimuat -- dan skrip di halaman ini
           inline, jadi satu kesalahan sintaks di blok mana pun akan
           mematikan seluruhnya. Badge yang hilang jauh lebih buruk
           daripada badge yang muncul sedikit kurang dramatis.

           Sekarang `animation` dengan `backwards`: frame `from`
           hanya dipakai selama animasi berjalan (dan selama
           delay-nya), dan begitu selesai elemen berdiri sendiri di
           gaya dasarnya yang sudah `opacity: 1`. Tidak ada keadaan
           akhir yang bergantung pada JS.

           `transform-origin` di pojok kanan-bawah supaya badge
           terlihat tumbuh DARI tombol. Kalau titik tumpunya di
           tengah, badge terlihat melayang sendirian di atas tombol
           dan hubungan induk-anaknya hilang. */
        .ver-badge {
            opacity: 1;
            transform-origin: 100% 100%;
            animation: ver-grow .62s var(--spring) .42s backwards;
        }

        @keyframes ver-grow {
            from {
                opacity: 0;
                transform: scale(.5) translateY(4px);
            }
            to {
                opacity: 1;
                transform: none;
            }
        }

        /* Gerak denyut kecil saat tombol ditekan.

           Durasi pendek dan hanya sekali, bukan loop: badge kecil
           yang berkedip terus-menerus di navbar akan menarik
           perhatian ke hal yang bukan isi halaman ini -- dan loop
           seperti itu tetap berjalan selama halaman terbuka, di
           setiap halaman, bukan hanya di sini. */
        @keyframes ver-pop {
            0%   { transform: scale(1); }
            45%  { transform: scale(1.09); }
            100% { transform: scale(1); }
        }
        .nav .btn-login:active .ver-badge { animation: ver-pop .42s var(--spring); }

        /* Badge ikut tumbuh saat tombol di-hover.

           Rules ini menimpa `ver-grow`: hover adalah state yang
           benar-benar disebabkan pengguna, jadi boleh membatalkan
           animasi masuk yang sedang jalan. Tanpanya, badge yang
           sedang tumbuh akan berhenti di tengah jalan begitu kursor
           menyentuhnya, dan terlihat macet. */
        .nav .btn-login:hover .ver-badge,
        .nav .btn-login:focus-visible .ver-badge {
            opacity: 1;
            transform: scale(1.08);
            transition: transform .3s var(--spring);
        }

        /* ---------- ikon di dalam tombol ----------

           Symbol ikon digambar dengan `fill: none` + `stroke: currentColor`,
           jadi satu gaya garis bisa dipakai ulang di seluruh halaman. Yang
           tidak bisa adalah lambang-brand: WhatsApp berbentuk siluet
           penuh, dan kalau hanya digores akan terbaca sebagai gelembung
           kosong, bukan sebagai logo.

           Perbedaan itu ditulis di sini sebagai kelas `.i-solid`, bukan
           di dalam `<symbol>`, supaya aturan "semua ikon lain bergaya
           garis" tetap berlaku untuk apa pun yang ditambahkan nanti.

           `flex: none` supaya ikon tidak ikut gepeng kalau teks tombol
           panjang, dan ukuran dikunci supaya tidak mewarisi lebar penuh
           dari elemen svg di dalam flex. */
        .btn svg {
            width: 17px; height: 17px; flex: none;
            fill: none; stroke: currentColor;
            /* 2.4, bukan 1.9. Pada 17px, angka 1.9 hanya menghasilkan
               ~1.35px goresan efektif -- lihat catatan panjang di
               `.mock__ico svg` soal kenapa stroke-width ditulis
               untuk viewBox 24px, bukan untuk ukuran tampil. */
            stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round;
        }
        .btn svg.i-solid { fill: currentColor; stroke: none; }

        /* Tombol di dalam panel gelap.

           Ghost terang (putih dengan border abu) yang dipakai di section
           terang akan hilang di atas dasar biru tua -- teks gelapnya
           jadi tidak terbaca. Di sini diganti versi gelap: isian
           transparan, border putih transparan, teks putih.

           Selector-nya `.hero-panel .btn-ghost`, bukan mengganti
           `.btn-ghost` global, supaya tombol ghost di section terang
           tidak ikut berubah. */
        .hero-panel .btn-ghost {
            background: rgb(255 255 255 / .08);
            border-color: rgb(255 255 255 / .26);
            color: #fff;
            box-shadow: none;
            backdrop-filter: blur(4px);
        }
        .hero-panel .btn-ghost:hover {
            background: rgb(255 255 255 / .16);
            border-color: rgb(255 255 255 / .5);
            color: #fff;
            box-shadow: 0 10px 24px -8px rgb(2 8 20 / .5);
        }

        /* Tombol primary di panel gelap pakai warna lebih terang dari
           versi section terang: biru medium di atas dasar biru tua
           memang inadequate: kontrasnya tidak cukup untuk teks putih. */
        .hero-panel .btn-primary {
            background: linear-gradient(135deg, var(--sky-400), var(--sky-500));
            color: var(--sky-950);
            box-shadow: 0 10px 26px -8px rgb(56 189 248 / .55);
        }
        .hero-panel .btn-primary:hover {
            box-shadow: 0 14px 32px -8px rgb(56 189 248 / .70);
        }

        /* ---------- hero ----------

           Bahasa visual mengikuti pola yang dipakai halaman produk
           modern: satu PANEL gelap besar dengan radius besar, teks
           putih di dalamnya, dan mockup perangkat yang terdorong keluar
           dari panel di sisi kanan.

           Alasannya panel gelap, bukan halaman gelap: landing page ini
           punya lima section panjang. Kalau seluruh halaman gelap,
           turun drastis jumlah kata yang bisa dibaca tanpa mapy --
           dan bagian fitur (yang justru inti ajakan) jadi tidak
           terbaca. Panel gelap hanya di bagian awal: kontras tinggi di
           tempat orang pertama kali memutuskan staying atau leaving,
           lalu langsung kembali terang dan tenang. */
        .hero { padding: 40px 0 20px; position: relative; }

        /* Panel: sudut membulat besar + gradasi diagonal. Radius besar
           itu yang bikin blok sebesar ini terbaca sebagai "kartu
           desain", bukan kotak teks yang kebetulan gelap.

           Gradasinya empat titik, bukan tiga warna tajam: tiga warna
           dengan stop yang jauh (0% / 55% / 100%) bikin ada garis
           perpindahan yang terlihat di tengah panel. Dengan empat
           titik yang lebih dekat, transisinya jadi rata dan tidak
           ada "jahitan" di bagian yang paling jarang dilihat. */
        .hero-panel {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            border-radius: 34px;
            padding: 62px 58px;
            background:
                linear-gradient(145deg,
                    #0c4a6e 0%,
                    var(--sky-900) 28%,
                    var(--sky-950) 62%,
                    #05192b 100%);
            box-shadow: 0 40px 90px -44px rgb(2 132 199 / .70),
                        0 8px 24px -12px rgb(8 47 73 / .55);
        }

        /* Teksur lembut tanpa geometri.

           Semula di sini ada pola grid: dua `linear-gradient` 1px yang
           diulang dengan `background-size: 54px`, diberi mask radial
           supaya memudar di tepi. Tongkosannya kelihatan sekali saja.

           Grid itu memang enak dilihat, tapi tidak mulur: dua garis
           horizontal dan dua garis vertikal yang berulang membuat
           kotak-kotak. Yang dimaksud "tekstur halus" jadi terbaca
           sebagai "kisi" -- dan di panel sebesar ini, kisi yang
           memotong seluruh area konten justru paling mengganggu,
           karena ia menarik mata ke struktur, bukan ke teks.

           Grid 54px juga tidak seragam antar perangkat. Di layar
           Retina atau layar kecil, garis 1px itu bisa bertemu atau
           berjarak 1-2px dan berubah jadi kabut abu. Jadi di satu
           tempat kisi terlihat tegas, di tempat lain jadi noise.

           Diganti dua gumpalan cahaya besar yang sangat lembut.
           Tidak ada tepi, tidak ada pengulangan, jadi tidak mungkin
           membentuk kotak. Sisa kesan "ada sesuatu di sini" cukup
           untuk membuat panel terasa berdepth, tanpa grid.

           `inset: -30%` supaya gradasi ini lebih besar dari panel dan
           tidak pernah menyentuh tepinya -- kalau sampai tepi, tepi
           itu sendiri jadi garis yang bisa dibaca. */
        .hero-panel::before {
            content: ""; position: absolute;
            inset: -30%; z-index: 0;
            background:
                radial-gradient(ellipse 60% 50% at 22% 30%,
                    rgb(56 189 248 / .14), transparent 70%),
                radial-gradient(ellipse 55% 45% at 78% 72%,
                    rgb(14 165 233 / .10), transparent 70%);
            pointer-events: none;
        }
        /* Cahaya biru di sudut kanan, tetap jadi sumber "terang"
           yang arahnya ke mockup. Dipisah dari ::before supaya dua
           lapisan ini tidak saling menimpa saat dianimasikan. */
        .hero-panel::after {
            content: ""; position: absolute; z-index: 0;
            width: 640px; height: 640px;
            top: -240px; right: -160px;
            border-radius: 50%;
            background: radial-gradient(circle, rgb(14 165 233 / .42), transparent 66%);
            filter: blur(70px);
            animation: drift-b 24s ease-in-out infinite alternate;
            pointer-events: none;
        }
        .hero-panel > * { position: relative; z-index: 1; }

        /* Kolom mockup dibuat lebih lebar dari kolom teks supaya HP
           boleh keluar dari panel di kanan tanpa terpotong wrap. */
        .hero-inner { display: grid; gap: 48px; grid-template-columns: 1.06fr .94fr; align-items: center; }

        /* Tanpa `min-width: 0`, kolom grid punya lantai min-content. Di
           layar sempit itu membuat kolom mockup turun ke lebar-isinya
           sendiri (240px) alih-alih ikut menyusut, dan HP keluar dari
           panel. Nolkan agar lebar kolom benar-benar mengikuti ruang. */
        .hero-inner > * { min-width: 0; }

        .eyebrow {
            display: inline-flex; align-items: center; gap: 9px; margin-bottom: 24px;
            font-size: 12.5px; font-weight: 600;
            /* Di dalam panel gelap, warna biru muda di atas tidak
               terbaca. Ganti ke versi gelap: teks terang di atas
               dasar biru transparan. */
            color: var(--sky-200);
            background: rgb(56 189 248 / .12);
            border: 1px solid rgb(125 211 252 / .28);
            padding: 7px 15px 7px 11px; border-radius: 999px;
            backdrop-filter: blur(4px);
        }
        /* Titik berdenyut: penanda "aktif" tanpa perlu kata. */
        .eyebrow::before {
            content: ""; width: 7px; height: 7px; border-radius: 50%;
            background: var(--sky-400); flex: none;
            animation: pulse 2.4s var(--ease) infinite;
        }

        h1 {
            margin: 0 0 22px;
            font-size: clamp(36px, 5.2vw, 55px); line-height: 1.06;
            letter-spacing: -.038em; font-weight: 800;
            color: #fff;
        }
        /* Gradien pada kata kunci, dengan `color` sebagai fallback untuk
           browser lama yang tidak mendukung background-clip: text. */
        h1 em {
            font-style: normal;
            /* Fallback untuk browser lama: biru terang, bukan biru
               gelap -- di atas dasar gelap `--sky-700` hampir hilang. */
            color: var(--sky-300);
            background: linear-gradient(120deg, var(--sky-400), var(--sky-200) 60%, #fff);
            -webkit-background-clip: text; background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .lede {
            margin: 0 0 34px; font-size: 18.5px; line-height: 1.65;
            color: var(--sky-100); opacity: .92; max-width: 56ch;
        }

        .cta { display: flex; flex-wrap: wrap; gap: 13px; }

        .note {
            margin-top: 26px; font-size: 13.5px;
            color: var(--sky-200); opacity: .8;
            display: flex; align-items: flex-start; gap: 9px;
        }

        /* Ikon di depan .note.

           Semula `&#9432;` (diamond with minus sign) -- glyph font,
           masalah yang sama seperti emoji di kartu fitur: bentuknya
           beda antar platform. Sekarang SVG, jadi selalu sama.

           `flex: none` supaya ikon tidak ikut memendek kalau teksnya
           panjang, dan `margin-top` supaya optiknya sejajar dengan
           baris teks pertama, bukan menempel paling atas. */
        .note > svg {
            width: 16px; height: 16px; flex: none; margin-top: 2px;
            fill: none; stroke: currentColor;
            /* 2.4, bukan 1.9: ~1.6px goresan efektif, bukan ~1.27px
               yang tinggal jadi kabut abu. Lihat `.mock__ico svg`. */
            stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round;
        }

        /* ---------- mockup perangkat ----------

           Dua perangkat: satu HP besar di depan, satu kartu laporan
           di belakangnya. Susunannya yang bikin hero terasa "didesain",
           bukan sekadar teks di samping gambar.

           Kunci penghitungnya ada di `.hero-inner > *:last-child`:
           mockup diberi `margin-right` negatif supaya keluar melewati
           tepi kanan panel. Tanpa itu, HP-nya tertahan di dalam kotak
           dan efek "tembus" yang jadi ciri desain ini hilang. */
        .hero-art { position: relative; }

        /* Bayangan besar di bawah perangkat. Tanpa ini perangkat
           terlihat menempel pada panel, bukan melayang di atasnya.

           `radial-gradient` dengan `ellipse` sudah cukup lembut --
           yang membuatnya tetap terlihat "kotak" adalah blur-nya
           kecil (20px) sementara warnanya pekat. Blur diperbesar
           dan alpha diturunkan supaya bayangan benar-benar memudar
           ke gradasi panel, tanpa tepi yang bisa dibaca. */
        .hero-art::after {
            content: ""; position: absolute;
            left: 50%; bottom: -10px; width: 82%; height: 70px;
            margin-left: -41%;
            background: radial-gradient(ellipse, rgb(2 8 20 / .42), transparent 72%);
            filter: blur(32px);
            pointer-events: none;
        }

        /* ---------- mockup digeser sendiri-sendiri ----------

           `.phone` dan `.art-card` punya tiga movement yang semuanya
           menulis properti `transform`:

             1. kemiringan desain      rotate(-4deg) / rotate(7deg)
             2. animasi masuk          translateY(44px) -> 0
             3. geseran oleh pengguna  --dx / --dy

           Ditulis langsung, tiga-tiganya saling menimpa dan hanya
           yang terakhir menang. Karena itu TIGA-TIGANYA dipisah jadi
           custom property lalu digabung sekali di `transform` milik
           masing-masing elemen. JS hanya menulis `--dx`/`--dy`,
           CSS yang menyusun sendiri, dan tidak ada lagi yang
           perlu tahu urutan penulisan.

           `@property` wajib, bukan opsional: tanpapendaftaran itu,
           `--fy` yang dianimasikan oleh `@keyframes` akan melompat
           antar nilai (diskret), bukan interpolate -- ngambangnya
           jadi kedip, bukan melayang.
        ============================================================ */

        /* Jarak geser, ditulis JS. */
        @property --dx { syntax: "<length>"; inherits: false; initial-value: 0px; }
        @property --dy { syntax: "<length>"; inherits: false; initial-value: 0px; }

        /* Ketinggian animasi masuk. 44px di dasar, 0 saat `.is-in`. */
        @property --ty { syntax: "<length>"; inherits: false; initial-value: 0px; }

        /* Ngambangnya: tinggi dan kemiringan tambahan, dianimasikan
           tanpa henti. */
        @property --fy { syntax: "<length>"; inherits: false; initial-value: 0px; }
        @property --fr { syntax: "<angle>";  inherits: false; initial-value: 0deg; }

        /* Ngambang HP: naik-turun 9px, miring 1.2 derajat.

           Durasi 7.5s, bukan bulat, supaya tidak perlu `animation-delay`
           yang berbeda untuk setiap elemen. Nilai yang sama persis
           tapi durasi berbeda sudah cukup membuat keduanya terlihat
           tidak sinkron -- dan itulah yang membuatnya tidak terlihat
           diprogram. */
        @keyframes ngambang-hp {
            0%, 100% { --fy: 0px;   --fr: 0deg;   }
            50%      { --fy: -9px;  --fr: 1.2deg; }
        }

        /* Kartu belakang: arah miring dibalik dan lebih pelan, supaya
           yang di belakang tidak bergerak searah dengan yang depan. */
        @keyframes ngambang-kartu {
            0%, 100% { --fy: 0px;   --fr: 0deg;   }
            50%      { --fy: -7px;  --fr: -1deg;  }
        }

        /* State saat sedang dipegang.

           Transisi `--dx`/`--dy` DIMATIKAN di sini, dan itu disengaja:
           keduanya ditulis JS setiap `pointermove`. Kalau ikut
           bertransisi, mockup tertinggal di belakang kursor dan
           terasa lengket -- bukan mengikuti jari.

           Yang tetap bertransisi: `--ty`, karena `.is-in` tetap perlu
           animasi masuk pada elemen yang sedang digeser. */
        .phone.is-dragging,
        .art-card.is-dragging {
            cursor: grabbing;
            z-index: 3;
            transition: --ty .9s var(--ease) .12s, opacity .7s var(--ease);
        }

        /* Dipasang JS kalau pengguna meminta gerak minimal. Drag dan
           ngambang tetap jalan -- yang hilang hanya "pegas" saat
           dilepas.

           `animation` sengaja tidak disentuh di sini:
           `prefers-reduced-motion` sudah mematikan animasi lewat blok
           media query, dan mematikan dua kali hanya menambah satu
           tempat lagi yang harus dijaga sinkron. */
        .phone.no-spring,
        .art-card.no-spring {
            transition: opacity .7s var(--ease);
        }

        /* ---------- kartu laporan (belakangan) ----------

           Kartu miring di belakang HP. Fungsinya memberi kedalaman:
           dua bidang datar yang bertumpuk menghasilkan ilusi layer
           tanpa perlu 3D asli.

           Sama seperti `.phone`, latar kartunya sekarang gradasi
           dengan alpha rendah, bukan warna solid. Kalau solid,
           kartu ini jadi kotak biru kedua yang berdiri sendiri di
           panel -- dan gradasi panel terputus di tepinya.

           Alpha rendah dipakai karena kartu ini memang lapisan
           BELAKANGAN: sudah cukup jelas dari kemiringan tujuh derajat
           dan posisinya di belakang HP, tidak perlu warna pekat untuk
           terbaca. Justru semakin pekat, semakin "menempel" dan
           semakin jelas terlihat seperti elemen yang bisa diklik. */
        .art-card {
            position: absolute;
            top: 12%; right: -6%;
            width: 78%;
            /* Di bawah HP secara bawaan. Kartu ini lapisan belakang --
               tanpa z-index, urutan dokumen saja yang menentukan, dan
               begitu HP bergeser ke kiri kartu akan menutupinya bukan
               sebaliknya. `is-dragging` menaikkan nilainya di atas 3. */
            z-index: 1;
            padding: 26px 24px;
            border-radius: 24px;
            background: linear-gradient(150deg,
                rgb(3 105 161 / .78),
                rgb(12 74 110 / .70));
            border: 1px solid rgb(125 211 252 / .18);
            color: #fff;
            /* Sama seperti `.phone`: gradasi panel di belakang ikut
               terblur melewati kartu, jadi tidak ada garis potong
               di tepinya. */
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            /* Keadaan awal: naik 26px dari posisi akhir. Tanpa offset
               ini, transisi ke `translateY(0)` tidak bergerak sama
               sekali -- hanya fade, dan efek "melayang" hilang.

               Susunan sama persis dengan `.phone`, hanya byte yang
               berbeda. Ini disengaja: dua elemen yang disusun dengan
               cara berbeda akan terlihat tidak berasal dari satu
               komponen, dan mengoreksi satu tapi tidak yang lain
               jadi pekerjaan yang berulang. */
            --ty: 26px;

            transform: translate3d(var(--dx), var(--dy), 0)
                       rotate(calc(7deg + var(--fr)))
                       translateY(calc(var(--ty) + var(--fy)));

            /* Durasi 9.4s, bukan 7.5s seperti HP. Gerak dengan nilai sama
               tapi periode berbeda menghasilkan pola yang tidak
               berulang -- kalau keduanya 7.5s, ayunan tinggi dan
               rendah jatuh berbarengan dan mata menangkapnya sebagai
               satu gerakan, bukan dua benda melayang. */
            animation: ngambang-kartu 9.4s var(--ease) infinite;
            animation-delay: 1.6s;

            box-shadow: 0 30px 60px -30px rgb(2 8 20 / .55);
            opacity: 0;
            transition: --ty .8s var(--ease), opacity .6s var(--ease);
            cursor: grab;
            touch-action: none;
        }
        .art-card strong { display: block; font-size: 17px; letter-spacing: -.02em; margin-bottom: 6px; }
        .art-card span { font-size: 13px; color: var(--sky-200); line-height: 1.55; display: block; }

        /* Kotak ikon di atas judul.

           SEBELUMNYA slot ini pakai `::before` yang HANYA consisted
           dari kotak semi transparan tanpa isi apa pun -- tidak ada
           `<svg>` di dalamnya. Di layar hasilnya kotak kosong: bentuk
           kotak ikon terbaca, tapi isinya tidak ada, jadi yang terlihat
           cuma kotak abu yang misterius di atas "Laporan siap".

           Sekarang isinya SVG sungguhan, diambil dari sprite yang sama
           dengan ikon lain di halaman. Aturan mainnya: satu sprite,
           satu gaya garis.

           `::before` DIBUANG, bukan diisi. Alasannya `::before` tidak
           bisa memegang `<svg>` -- konten yang dihasilkannya adalah
           teks, dan teks tidak bisa berisi elemen SVG. Kalau tetap
           dipakainya, ikon harus digambar pakai `background-image`
           yang berarti SVG-nya jadi file terpisah -- dan gayanya jadi
           dua tempat: sprite di satu file, ikon kartu ini di file
           lain.

           Kotak yang sekarang adalah elemen HTML biasa
           (`.art-card__ico`), jadi isinya `<svg>` yang menunjuk
           simbol di sprite. */
        .art-card__ico {
            width: 38px; height: 38px; border-radius: 12px;
            background: rgb(255 255 255 / .16);
            display: grid; place-items: center;
            margin-bottom: 14px;
            color: #fff;
        }

        /* Ikon di dalam kotak.

           19px, bukan 17px seperti di tombol. Kotaknya 38px, jadi
           ikon mengisi setengahnya -- ukuran yang sama dengan `.card .ico`
           (23px di kotak 46px). `stroke-width: 2.4` menghasilkan
           ~1.9px goresan efektif, jauh di atas batas Anti-Aliasing
           (lihat catatan panjang di `.mock__ico svg`).

           `display: block` menutup celah baseline: `<svg>` inline
           duduk di baseline dan whitespace di markup menambah tinggi
           kotak -- di 19px cukup untuk membuat ikon terlihat tidak
           center di dalam kotaknya. */
        .art-card__ico svg {
            display: block;
            width: 19px; height: 19px;
            fill: none; stroke: currentColor;
            stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round;
        }

        /* ---------- perangkat HP ----------

           Bingkai gelap dengan "notch" pill di atas. Widinya sengaja
           kecil (280px) supaya masih terbaca sebagai "-- ini HP" dan
           bukan tablet -- tablet terbaca sebagai browser window, yang
           justru ingin kita hindari supaya mockup terasa seperti
           aplikasi yang benar-benar dipakai di lapangan.

           BINGKAI TIDAK ADA WARNA SOLID. Semula `background` bingkai
           hampir hitam pekat, yang membuat bentuk kotak HP terasa
           seperti stiker yang ditempel di atas panel -- gradasi panel
           terputus tepat di sekitarnya dan keseluruhan hero terlihat
           dua bidang yang tidak menyambung.

           Sekarang bingkai dibuat dari gradasi transparan ke gelap,
           dengan tepi putih semi transparan. Efeknya: bentuk HP tetap
           terbaca (radius + notch + layar), tapi tidak lagi menjadi
           "lubang hitam" di tengah gradasi panel. Panel dan mockup
           membaca sebagai satu bidang dengan depth, bukan dua objek
           yang ditempel. */
        .phone {
            position: relative;
            /* Di atas kartu laporan. Keduanya establishes stacking
               context sendiri karena punya `transform`, jadi urutan
               dokumen tidak lagi cukup untuk menentukan mana yang
               menutupi mana. */
            z-index: 2;
            /*Lebar tetap desktop, tapi dibatasi ruang yang tersedia:
               `min()` membuat HP menyusut mengikuti kolomnya, bukan
               memaksakan diri pada 280px lalu meluber keluar panel di
               layar sempit. Acuan `100%` diambil dari lebar kolom
               mockup, yang padding panel sudah potong -- jadi HP muat di
               dalam panel, bukan melewati tepinya. */
            width: min(280px, 100%);
            margin: 0 auto;
            margin-right: -34px;   /* tembus tepi kanan panel */
            padding: 11px;
            border-radius: 42px;
            /* Gradasi dari transparan (tepi) ke gelap (pusat) supaya
               siluet HP terlihat dari radius + layar, bukan dari
               warna bingkai yang solid.

               `backdrop-filter: blur()` membuat gradasi panel di
               belakang ikut terblur saat melewati bingkai -- jadi
               transisinya tidak berhenti tajam di tepi bingkai,
               tapi berlanjut mulus ke panel. Ini yang bikin HP
               terbaca "menempel" di atas gradasi, bukan ditempel
               sebagai kotak terpisah. */
            background: linear-gradient(160deg,
                rgb(30 41 59 / .55),
                rgb(15 23 42 / .78) 55%,
                rgb(2 6 23 / .90));
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
            box-shadow:
                0 0 0 1px rgb(255 255 255 / .12),
                0 40px 70px -30px rgb(2 8 20 / .70),
                0 12px 28px -14px rgb(2 132 199 / .35);
            /* Keadaan awal, naik dari bawah. Offset-nya lebih besar
               dari kartu belakang supaya HP terasa paling dekat dengan
               mata, bukan sekadar fade.

               `translateY(44px)` SESUAI dengan `--ty` di bawah, jadi
               keduanya tidak perlu diubah dua kali. */
            --ty: 44px;

            /* Tiga gerakan digabung di sini, dan hanya di sini:
               `--ty` (masuk), `--dx`/`--dy` (geser), `--fy`/`--fr`
               (ngambang). Semuanya di dalam satu `transform`, jadi
               tidak ada yang bisa saling menimpa.

               Urutannya penting: geser paling luar supaya seluruh
               perangkat -- termasuk kodingnya -- bergerak sebagai satu
               blok, dan ngambang di dalam supaya ayunan tidak ikut
               menggeser posisi koding yang sedang digeser user. */
            transform: translate3d(var(--dx), var(--dy), 0)
                       rotate(calc(-4deg + var(--fr)))
                       translateY(calc(var(--ty) + var(--fy)));

            /* Ngambangnya jalan begitu mockup selesai masuk. Tanpa
               penundaan, `rise` dan `ngambang` berebut menulis
               posisi di detik yang sama. */
            animation: ngambang-hp 7.5s var(--ease) infinite;
            animation-delay: 1.2s;

            opacity: 0;
            transition: --ty .9s var(--ease) .12s, opacity .7s var(--ease);
            cursor: grab;
            touch-action: none;
        }

        /* Notch: pil kecil di atas layar, dibuat dengan pseudo-element
           supaya tidak menambah elemen DOM.

           Warnanya `#020617` pekat, bukan mengikuti bingkai yang
           sekarang translusen. Kalau ikut translucent, notch hilang
           dan tepi atas layar terlihat datar -- bentuk "HP" hilang,
           dan yang tersisa cuma kotak dengan radius besar. */
        .phone::before {
            content: ""; position: absolute;
            top: 11px; left: 50%; transform: translateX(-50%);
            width: 86px; height: 20px;
            background: #020617;
            border-radius: 0 0 12px 12px;
            z-index: 2;
        }

        /* Layar: gradasi gelap, bukan `#0f172a` solid.

           `background: var(--slate-900)` yang solid membuat layar
           terbaca sebagai kotak hitam penuh -- itu yang bikin panel
           hero terpotus. Dengan gradasi, layar masih jelas area
           konten (ganti ke kartu putih) tapi tidak lagi menjadi
           lubang hitam di atas gradasi panel. */
        .phone__screen {
            position: relative;
            border-radius: 32px;
            overflow: hidden;
            background: linear-gradient(165deg, #1e293b, #0f172a 60%, #0b1220);
            padding-top: 26px;    /* ruang untuk notch */
        }

        /* Baris status di dalam layar, supaya HP terbaca sebagai HP. */
        .phone__status {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0 18px 10px;
            font-size: 10.5px; font-weight: 600;
            color: var(--slate-400);
        }
        .phone__status i {
            display: block; width: 34px; height: 9px;
            background: rgb(255 255 255 / .10);
            border-radius: 999px;
        }

        /* ---------- isi layar ----------

           Isinya statis dan dekoratif. TIDAK ada angka riil di sini:
           semua nilai ditandai dengan class `mockdata` -- aturan yang
           sama dengan mockup dashboard. Lihat catatan jujur di markup
           hero lama untuk alasannya -- angka di mockup boleh berubah
           sewaktu-waktu tanpa review karena tidak pernah muncul
           sebagai klaim performa.

           PENTING: penanda itu bukan `.mock`. `.mock` sudah dipakai
           kartu mockup dashboard (latar putih, radius, overflow), jadi
           memakainya di sini membuat setiap baris dan tombol ikut
           jadi kartu putih -- teks hilang di atas putih. Tabrakan nama
           seperti ini tidak melempar error, hanya merusak tampilan. */
        .phone__body {
            padding: 14px 16px 20px;
            display: grid; gap: 11px;
        }

        .phone__hi {
            font-size: 13px; font-weight: 700; color: #fff;
            letter-spacing: -.01em;
        }
        .phone__hi span { display: block; font-size: 10.5px; font-weight: 500; color: var(--slate-400); margin-top: 2px; }

        /* Kartu ringkas: bukan statistik yang dibaca, tapi bukti
           "ada isi". Tiga baris sudah cukup untuk memberi kesan
           data supaya isi layarnya tidak terlihat berantakan. */
        .phone__row {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 12px;
            background: rgb(255 255 255 / .045);
            border: 1px solid rgb(255 255 255 / .07);
            border-radius: 13px;
        }
        .phone__row > span:first-child {
            width: 30px; height: 30px; flex: none;
            display: grid; place-items: center;
            border-radius: 9px;
            background: rgb(56 189 248 / .16);
            color: var(--sky-300);
        }
        .phone__row > span:first-child svg {
            /* 15px x stroke 2 = ~1.25px efektif, masih di bawah batas
               Anti-Aliasing (lihat catatan di `.mock__ico svg`).
               2.6 menghasilkan ~1.63px. */
            width: 15px; height: 15px;
            fill: none; stroke: currentColor; stroke-width: 2.6;
            stroke-linecap: round; stroke-linejoin: round;
        }
        .phone__row b {
            font-size: 11px; font-weight: 600; color: var(--slate-400);
            display: block; line-height: 1.3;
        }
        .phone__row em {
            display: block; font-style: normal;
            font-size: 13px; font-weight: 700; color: #fff;
            font-variant-numeric: tabular-nums;
            letter-spacing: -.01em;
        }

        /* Baris dengan angka emerald -- penanda "uang", bahasa visual
           yang sama dengan mockup dashboard di bawah. */
        .phone__row--money em { color: var(--emerald-300); }

        /* Baris peringatan: tunggakan. Warna amber, bukan merah --
           merah di mockup akan terbaca sebagai "ada masalah di
           produk Anda sendiri". */
        .phone__row--warn > span:first-child {
            background: rgb(251 191 36 / .14);
            color: var(--amber-400);
        }
        .phone__row--warn em { color: var(--amber-400); }

        /* Tombol aksi besar di dasar layar. Ini yang membuat layar
           terbaca sebagai aplikasi yang bisa dipakai, bukan tabel.

           Teksnya TIDAK putih. Semula `color: #fff` di atas
           gradasi sky-500/sky-600, dan itu hanya memberi kontras
           2.77:1 -- gagal jauh di bawah ambang 4.5:1 untuk teks
           normal. Di layar 12px yang kecil, tombol biru terang itu
           praktis tidak terbaca teksnya.

           `--sky-950` di atas tombol biru terang justru jauh lebih
           kuat (sekitar 8:1) dan sesuai bahasa visual yang dipakai
           tombol utama di panel hero, yang juga gelap di atas biru
           terang. Jadi tombol "aksi utama" di dalam mockup sekarang
           konsisten dengan tombol utama di halaman. */
        .phone__cta {
            display: block; text-align: center;
            margin-top: 4px;
            padding: 11px;
            border-radius: 12px;
            font-size: 12px; font-weight: 700;
            color: var(--sky-950);
            background: linear-gradient(135deg, var(--sky-400), var(--sky-500));
            box-shadow: 0 8px 18px -8px rgb(14 165 233 / .60);
        }

        /* Setelah masuk viewport, perangkat naik ke posisi akhir.
           Delay sedikit lebih lama dari kartu belakang supaya
           tidak keduanya tiba bersamaan -- dan itu terasa seperti
           satu gerakan, bukan dua elemen yang kebetulan muncul.

           Yang ditulis di sini HANYA `--ty`, bukan `transform`.

           `--ty` punya `transition` sendiri, jadi nilainya naik dari
           44px ke 0 dengan sendirinya. Kalau `.is-in` menulis
           `transform: rotate(-4deg) translateY(0)` seperti sebelumnya,
           transform itu akan menimpa seluruh ungkapan yang di dalam
           `.phone` -- termasuk `--dx`/`--dy` dari drag dan `--fy`
           dari ngambangnya. Akibatnya mockup terkunci di tengah begitu
           masuk layar, dan drag mati total. */
        .hero-panel.is-in .phone,
        .hero-panel.is-in .art-card {
            opacity: 1;
        }
        .hero-panel.is-in .phone   { --ty: 0px; }
        .hero-panel.is-in .art-card { --ty: 0px; }

        /* ---------- section pratinjau ----------

           Layout DUA KOLOM: judul + penjelasan di kiri, mockup
           dashboard di kanan.

           Kenapa tidak ditumpuk vertikal seperti sebelumnya: mockup
           occupying full width memaksa user untuk menggulir melewati
           900px teks sebelum melihat visual sama sekali. Dengan
           bersanding, klaim ("satu sistem, semua kebutuhan") dan
           buktinya (dashboard) terbaca dalam satu layar, tanpa
           scroll. Mata bisa pindah kiri-ke-kanan dalam satu lintasan,
           bukan atas-bawah.

           `.mock` diperkecil supaya muat di kolom kanan (560px)
           tanpa membuat baris statistiknya terlalu rapat. */
        .preview { padding: 58px 0 46px; }
        .preview-grid {
            display: grid;
            gap: 56px;
            grid-template-columns: .85fr 1.15fr;
            align-items: center;
        }
        .preview-copy .eyebrow { margin-bottom: 20px; }
        .preview-copy h2 {
            margin: 0 0 16px;
            font-size: clamp(28px, 3.6vw, 40px);
            letter-spacing: -.032em; line-height: 1.12; font-weight: 800;
        }
        .preview-copy > p {
            margin: 0 0 26px;
            font-size: 17px; line-height: 1.65; color: var(--ink-soft);
            max-width: 46ch;
        }
        .preview-points {
            display: grid; gap: 14px; margin: 0; padding: 0;
            list-style: none;
        }
        .preview-points li {
            display: flex; align-items: flex-start; gap: 11px;
            font-size: 15px; line-height: 1.55; color: var(--ink-soft);
        }
        .preview-points svg {
            width: 19px; height: 19px; flex: none; margin-top: 1px;
            fill: none; stroke: var(--sky-600); stroke-width: 2.2;
            stroke-linecap: round; stroke-linejoin: round;
        }
        .preview-points strong { color: var(--ink); font-weight: 700; }

        /* ---------- mockup dashboard ----------

           KARTU PUTIH, bukan gelap.

           Semula kartu ini gelap (`--slate-900`) dengan bahasa visual
           "terminal". Sekarang dibalik terang karena tiga alasan:

           1. Letaknya bersebelahan dengan panel hero yang juga gelap.
              Dua blok gelap bersebelahan dengan jarak tipis di antaranya
              terbaca sebagai satu blok giant, bukan dua bagian.
           2. Mockup sudah punya konteks sendiri dari jaraknya -- tidak
              perlu warna gelap untuk deadly attention.
           3. Kartu putih di halaman terang jauh lebih mudah dibaca sebagai
              sebagai "gambar", bukan sebagai elemen UI sungguhan.
              Kalau gelap, orang bisa mengira itu widget embedded yang
              bisa diklik.

           Konsekuensi yang harus ikut ditangani: semua warna teks
           yang sebelumnya putih/transparan harus diganti ke versi gelap,
           dan kartu-kartu di dalamnya (`mock__stat`) tidak boleh
           putih juga -- kalau putih di atas putih, batasnya hilang.
           Karena itu `mock__stat` memakai abu sangat muda
           (`--bg-soft`), bukan `#fff`.

           INTERAKTIF (hover): isinya memang sesuatu yang dibaca --
           visual statis seperti kartu fitur akan terlihat kosong.
           Sumber kebenarannya tetap mockup, bukan data nyata.

           Berbeda dari kartu fitur: hover MEMANG boleh sedikit
           mengangkat. Batasnya jelas -- tidak ada tombol, tidak ada
           tautan, tidak ada `cursor: pointer` di dalam kartu ini. */
        /* ---------- dorong 3D (tilt) ----------

           Mockup dashboard bisa "didorong" kursor: kartu miring mengikuti
           arah mouse lalu kembali ke posisi datar dengan efek pegas.

           MENGAPA 3D DAN BUKAN SEKADAR NAIK?
           Versi sebelumnya cuma `translateY(-4px)` saat hover. Itu gerak
           satu arah yang sama untuk semua titik di kartu, jadi saat kursor
           berada di mana pun, reaksinya identik -- orang menangkapnya
           sebagai "kartu ini bisa diklik", bukan "kartu ini mengikuti
           kursor saya". Tilt memberi tahu lewat gerakan bahwa permukaan
           itu ada di ruang 3D, dan itu yang bikin mockup terbaca sebagai
           benda di depan layar, bukan gambar cetak.

           TIGA GERAK dipisah jadi tiga custom property, lalu disusun
           sekali di `transform`:

             --mx / --my   kemiringan mengikuti kursor (JS menulisnya)
             --lift         naik kecil saat hover

           Dipisah seperti `--dx`/`--dy`/`--ty`/`--fy` di mockup HP
           (lihat blok `@property` di bawah) karena alasan yang sama:
           kalau tiga-tiganya ditulis langsung ke `transform`, yang
           terakhir menimpa yang lain dan hanya satu yang bergerak.

           Sudut 7 derajat, bukan lebih besar. Ini kartu berisi teks
           kecil dan angka -- pada sudut terlalu tajam, teks di tepi
           miring cukup banyak untuk mulai tidak terbaca, dan persis
           teks itulah yang harusnya terbaca. 7 derajat sudah jelas terasa
           sebagai "dorong" tanpa mengorbankan keterbacaan.

           `transform-style: preserve-3d` WAJIB: tanpa itu, anak
           kartu (bar judul, kartu statistik) diratakan datar ke layar
           dan miringnya cuma terlihat di tepi -- efek 3D-nya
           hilang walau `rotateX/rotateY` sudah terpasang. Pointer-events
           none pada pseudo-element mockup yang sudah ada tidak
           terganggu karena anak-anaknya tidak dirotasi ke arah
           pointer.

           `will-change: transform` supaya browser membuat lapisan
           sendiri sejak awal, bukan setelah kursor pertama kali bergerak
           (yang selalu terlambat satu frame dan terasa seperti lag). */
        @property --mx { syntax: "<angle>"; inherits: false; initial-value: 0deg; }
        @property --my { syntax: "<angle>"; inherits: false; initial-value: 0deg; }
        @property --lift { syntax: "<length>"; inherits: false; initial-value: 0px; }

        .mock {
            position: relative;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--r-xl);
            box-shadow: var(--sh-lg), 0 26px 60px -34px rgb(2 132 199 / .30);
            overflow: hidden;
            transform-style: preserve-3d;
            perspective: 1100px;
            will-change: transform;

            /* Transisi HANYA untuk --lift. --mx/--my sengaja TIDAK
               ikut transisi: keduanya ditulis JS di setiap
               `pointermove`, dan kalau ikut bertransisi, kartu
               tertinggal di belakang kursor -- terasa lengket,
               bukan mengikuti jari. Persis seperti catatan di
               `.phone.is-dragging` yang sudah ada di halaman ini.

               Efek pegas tidak dibuat lewat `transition`, tapi lewat
               `--lift` yang dianimasikan oleh keyframes di bawah --
               lihat catatan `.mock.spring-back`. */
            transition: --lift .4s var(--ease), box-shadow .4s var(--ease),
                        border-color .4s var(--ease);

            transform:
                perspective(1100px)
                rotateX(var(--my))
                rotateY(var(--mx))
                translateZ(0)
                translate3d(0, calc(-1 * var(--lift)), 0);
        }

        /* Kursor sedang ada di atas kartu: siap menerima miring.
           `.is-tilting` dipasang JS saat kursor benar-benar masuk
           (bukan dari `:hover` CSS), karena handler JS butuh tahu
           bahwa yang menggeser kursor adalah kartu ini -- bukan
           elemen lain yang kebetulan hover. */
        .mock.is-tilting { cursor: grab; }

        /* Lepas: kartu kembali datar dengan pegas.

           BUKAN transisi, tapi animasi. Alasannya, `--mx`/`--my`
           harus TIDAK ditransisi (lihat catatan di `.mock`), sehingga
           begitu pointerleave, nilainya kembali ke 0 secara instan,
           dan yang terasa adalah "kartuunar_reppat". Pegasnya
           datang dari keyframes yang mengayunkan nilai sedikit
           melewati 0 sebelum berhenti, persis seperti
           `elastic.out` di SIDBM tapi tanpa GSAP.

           `backwards` fill supaya frame `from` berlaku selama delay,
           dan begitu selesai kartu berdiri sendiri di gaya dasarnya
           (nilai 0) tanpa bergantung pada state class. */
        .mock.spring-back {
            animation: mock-spring-back .78s var(--spring) both;
        }

        @keyframes mock-spring-back {
            0%   { --mx: 0deg;  --my: 0deg; }
            /* Sedikit lewat dari 0 lalu balik: ini yang bikin terasa
               "pegas" dan bukan sekadar berhenti mendadak. */
            26%  { --mx: 1.6deg; --my: -1.6deg; }
            55%  { --mx: -.9deg; --my: .9deg; }
            78%  { --mx: .35deg; --my: -.35deg; }
            100% { --mx: 0deg;  --my: 0deg; }
        }

        /* Naik + bayangan lebih dalam hanya saat kursor di atas kartu.
           Border ikut berubah seperti sebelumnya. */
        .mock:hover {
            --lift: 6px;
            border-color: var(--sky-200);
            box-shadow: var(--sh-xl), 0 38px 80px -36px rgb(2 132 199 / .42);
        }

        .mock.is-tilting:active { cursor: grabbing; }

        /* Bar judul: tiga titik + path + badge "contoh".

           Badge itu wajib, bukan hiasan: tanpa label eksplisit, angka
           mockup bisa dibaca calon pembeli sebagai performa nyata. Baris
           ini satu-satunya tempat yang jujur menyatakan status data, jadi
           tidak boleh hilang saat kartu dirapikan. */
        .mock__bar {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 14px;
            border-bottom: 1px solid var(--line);
            background: var(--bg-soft);
        }

        .mock__dots { display: flex; gap: 5px; flex: none; }
        .mock__dots i { width: 7px; height: 7px; border-radius: 50%; background: var(--sky-200); }
        .mock__dots i:nth-child(2) { background: var(--sky-300); }
        .mock__dots i:nth-child(3) { background: var(--sky-400); }

        .mock__path {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 11px; color: var(--ink-soft);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .mock__badge {
            margin-left: auto; flex: none;
            font-size: 9.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
            color: var(--sky-700);
            background: var(--sky-50);
            border: 1px solid var(--sky-200);
            padding: 2px 7px; border-radius: 999px;
        }

        .mock__body {
            display: grid; gap: 10px;
            grid-template-columns: 1fr 1fr;
            padding: 14px;
        }

        /* ---------- kartu angka ----------

           Kotak PERSGI, bukan rounded. Ini bukan pilihan estetika tapi
           meniru dashboard yang ditiru: kartu metrik di panel admin
           tenant memakai radius kecil, dan mockup yang melengkung
           terlihat seperti kartu Undangan, bukan aplikasi kerja.

           Urutan isi: ikon + label sebaris di atas, angka besar di
           tengah, delta paling bawah. Orang yang memindai dashboard
           selalu lihat kategorinya dulu, baru angkanya.

           Warna per kartu lewat `--accent` (teks ikon) dan
           `--accent-soft` (latar kotak ikon). Dua variabel ini ditulis
           sekali di sini, jadi tiap kartu cukup satu modifier class
           dan tidak ada aturan warna yang tersebar per ikon. */
        .mock__stat {
            --accent: var(--sky-700);
            --accent-soft: var(--sky-100);

            display: flex; flex-direction: column; gap: 8px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 4px;
            padding: 12px 13px 13px;
            transition: border-color .35s var(--ease), box-shadow .35s var(--ease);
        }

        .mock__stat:hover {
            border-color: var(--accent);
            box-shadow: 0 4px 14px -8px rgb(15 23 42 / .28);
        }

        /* Tiap kartu punya warnanya sendiri supaya empat kotak tidak
           terbaca sebagai satu blok seragam:

           pemasukan  hijau  -- uang masuk
           tunggakan  amber  -- perlu perhatian
           pelanggan  biru   -- netral, data identitas
           laporan    indigo -- laporan keuangan, tidak ada di skala sky

           Amber dan hijau sengaja sama-sama gelap di atas latar
           terang. `--emerald-300` yang dulu dipakai akan pudar jadi
           abu di atas putih dan berhenti terbaca sebagai status. */
        .mock__stat--in    { --accent: #047857; --accent-soft: #d1fae5; }
        .mock__stat--warn  { --accent: #b45309; --accent-soft: #fef3c7; }
        .mock__stat--user  { --accent: var(--sky-700); --accent-soft: var(--sky-100); }
        .mock__stat--docs  { --accent: #4338ca; --accent-soft: #e0e7ff; }

        .mock__head { display: flex; align-items: center; gap: 8px; min-width: 0; }

        /* Kotak ikon. Warna teksnya bukan hardcode tapi `--accent`,
           yang diwarisi dari kartu induknya.

           `stroke: currentColor` (lihat aturan di bawah) sudah
           cukup: nilainya diambil dari `color` di sini, jadi tidak
           ada warna yang ditulis dua kali dan tidak bisa meleset.

           Modifier `.mock__ico--warn` yang dulu ada persis untuk
           memaksa ikon tunggakan jadi amber dihapus -- warnanya
           sudah datang dari `.mock__stat--warn` lewat `--accent`,
           jadi selector kedua hanya menambah satu tempat yang harus
           dijaga sinkron. */

           Kalau nanti butuh ikon dengan warna berbeda di dalam kartu
           yang sama, ubah `color` pada elemen pembungkusnya dan ikon
           otomatis mengikuti -- bukan menambah selector warna lagi. */
        .mock__ico {
            width: 24px; height: 24px; flex: none;
            display: grid; place-items: center;
            border-radius: 4px;
            background: var(--accent-soft);
            color: var(--accent);
        }

        /* Ikon diberi `width`/`height` eksplisit di atas, tapi `<svg>`
           di dalam `.mock__ico` TIDAK diberi `display: block`. SVG
           inline adalah elemen sebaris, jadi ia duduk di baseline dan
           whitespace di markup ikut menambah tinggi kotak -- di 14px
           basta untuk menggeser ikon naik beberapa piksel dan membuatnya
           terlihat tidak center.

           `display: block` menutup celah baseline itu. Bukan detail
           sepele: tanpa ini, "kotak ikon" terlihat tidak simetris --
           dan di mockup yang tugasnya terlihat rapi, hal pertama yang
           mata baca justru misalignment, bukan detail 2px ini. */
        /* Ikon di dalam `.mock__ico` (14px).

           `stroke-width: 2` soundskyali cukup, tapi angka itu
           ditulis untuk `viewBox="0 0 24 24"`, BUKAN untuk ukuran
           14px yang benar-benar tampil. SVG menyalin viewBox ke
           kotak 14px dengan skala 14/24, jadi goresan 2px jadi
           benar-benar 2 x (14/24) = 1.17px di layar.

           Di bawah ~1.5px, Anti-Aliasing browser membagi satu
           goresan ke dua piksel dan masing-masingnya diberi bobot
           ~0.5 -- hasilnya bukan "tipis" tapi "kabut abu" yang
           hampir tidak kelihatan di atas latar terang. Di layar
           Retina (devicePixelRatio 2) angka efektifnya turun lagi
           ke ~0.58 device pixel, di bawah batas yang bisa digambar
           sama sekali.

           Makanya ikon yang paling kecil justru yang paling
           hilang: `.mock__ico` 14px dan `.mock__delta` 11px
           keduanya lebih buram dibanding `.card .ico` yang 23px.

           2.7 di sini menghasilkan ~1.58px -- di atas batas itu.
           Harus Cecil dari yang terlihat "tebal": simbolnya berupa
           garis outline, bukan bentuk pekat, dan digambar pada
           warna accent yang sudah gelap. Terlalu tebal akan mulai
           menutup jarak antar garis di `i-docs` yang punya tiga
           garis horizontal berdekatan (y=8, y=12, y=16). */
        .mock__ico svg {
            display: block;
            width: 14px; height: 14px;
            fill: none; stroke: currentColor;
            /* 2.7, bukan 2: lihat catatan di atas. */
            stroke-width: 2.7;
            stroke-linecap: round; stroke-linejoin: round;
        }

        .mock__label {
            font-size: 10.5px; font-weight: 500; letter-spacing: .02em;
            color: var(--ink-soft);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        /* Angka tabular: tanpanya, digit akan berganti lebar saat
           dihitung -- angka yang melompat-lompat terlihat glitch.

           Angka sengaja menempel ke delta-nya, bukan terdorong ke dasar
           kartu: kalau melompot ke bawah, jarak ke delta jadi jauh dan
           kartu sebaris terlihat tidak nyambung.

           Warna hijau tua, bukan emerald terang: angka `--emerald-300`
           yang sebelumnya terlihat bagus di atas gelap akan pudar
           di atas putih dan berhenti terbaca sebagai angka. */
        .mock__value {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 19px; font-weight: 600; letter-spacing: -.02em;
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }

        /* Delta: ikon panah, bukan karakter segitiga.

           Karakter segitiga itu glyph font -- bentuknya beda antar
           platform, masalah yang sama seperti emoji di kartu fitur,
           hanya lebih kecil dan lebih sering luput. SVG selalu sama. */
        .mock__delta {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 10.5px; color: var(--ink-mute);
        }
        /* Panah delta. 11px adalah ikon terkecil di halaman, jadi butuh
               stroke paling tebal: 3.4 menghasilkan ~1.56px goresan
               efektif. Angka ini bukan untuk "terlihat tebal" --
               untuk menolak Anti-Aliasing yang akan mengubahnya
               jadi kabut abu. Lihat catatan panjang di
               `.mock__ico svg`. */
        .mock__delta svg {
            width: 11px; height: 11px; flex: none;
            fill: none; stroke: currentColor;
            stroke-width: 3.4; stroke-linecap: round; stroke-linejoin: round;
        }
        /* Panah delta ikut warna kartu, sama seperti ikonnya. Bukan karena
           "naik = bagus": kartu tunggakan punya delta amber dengan
           panah TURUN, dan itu memang atasannya. Yang diwarnai adalah
           kartu, bukan arah trenya.

           Kedua kelas digabung karena nilai dan alasannya sama persis.
           Kalau suatu saat delta naik dan turun mau dibedakan lagi,
           aturan `.up` dan `.down` harus dipisah di sini -- bukan
           ditambahkan warna acak di markup. */
        .mock__delta.up,
        .mock__delta.down { color: var(--accent); }

        /* Tag laporan mengikuti `--accent` kartu induknya. Radius tetap
           kecil supaya konsisten dengan kotak kartu -- tag rounded
           di dalam kotak persegi terbaca sebagai elemen dari sistem
           desain yang berbeda. */
        .mock__tags { display: flex; flex-wrap: wrap; gap: 5px; }
        .mock__tags i {
            font-style: normal; font-size: 10px; font-weight: 500;
            color: var(--accent);
            background: var(--accent-soft);
            border: 1px solid transparent;
            padding: 3px 7px; border-radius: 4px;
        }

        .mock__chart { grid-column: 1 / -1; display: flex; flex-direction: column; gap: 12px; }

        /* ---------- grafik garis ----------

           Bentuk garis (menaik-turun), bukan batang. Murni CSS dan SVG
           inline: tanpa gambar, tanpa JS, tanpa charting library.

           Kenapa garis dan bukan batang: yang jadi alasan sendiri. Garis
           dibaca sebagai "perubahan dari bulan ke bulan", sedangkan batang
           dibaca sebagai "berapa banyak per bulan". Keduanya benar --
           tapi untuk DATA CONTOH yang di sini, batang keenam terlihat
           seperti kenaikan rutin setiap bulan, dan ituIGRISessages
           klaim yang lebih besar dari yang perlu diklaim mockup ini.
           Garis lebih jujur: kalau polanya turun, bentuknya
           ikut turun.

           SVG, bukan div penyusun: garis perluBelieb connecting
           kemiringan antar titik. Dengan div, penghubung itu harus
           diborphan dengan `transform: rotate()` per ruas -- enam div
           menjadi dua belas elemen, dan setiap perubahan data berarti
           hitung ulang semua sudut. `polyline` cukup satu atribut `points`.

           `viewBox` 0 0 100 100 dengan `preserveAspectRatio="none"`.
           Ini yang membuatnya bisa elastis tanpa JavaScript: koordinat
           ditulis dalam satuan tetap (0-100) dan browser yang
           meregangkan ke lebar kontainer. Kalau koordinatnya pixel asli,
           diperlukan hitung ulang tiap resize.

           Konsekuensi yang harus diingat: `preserveAspectRatio="none"`
           ALSO meregangkan STROKE secara tidak proporsional, sehingga
           garis bisa terlihat tipis atau tebal tergantung ukuran
           kotak. `vector-effect="non-scaling-stroke"` yang dipakai di
           bawah mengembalikan ketebalan ke 2px berapa pun skalanya. */

        .deck {
            display: flex; flex-direction: column; gap: 9px;
        }

        /* Area gambar. `position: relative` WAJIB: tanpa itu,
           `.deck__pts` diposisikan terhadap ancestor terdekat yang
           punya posisi, dan titiknya mengambang jauh di bawah kartu.

           Garis bantu dibuat dari CSS, bukan dari SVG, supaya tidak
           ikut teregangkan oleh `preserveAspectRatio="none"` di
           bawah -- tetap 1px di setiap lebar kartu.

           `background-size: 100% 33.333%` memecah area jadi 3
           bagian sama tinggi; tiap bagian memberi 1px garis di
           sisinya, jadi total 3 garis: 33%, 67%, dan 100%
           (yang terakhir sekaligus jadi garis dasar). */
        .deck__plot {
            position: relative;
            height: 84px;
            background-image:
                linear-gradient(to top, var(--line) 1px, transparent 1px);
            background-size: 100% 33.333%;
            background-position: 0 100%;
        }

        /* Garis "digambar" dari kiri saat section masuk layar.

           Dipakai `clip-path`, BUKAN `stroke-dasharray`.

           Versi sebelumnya memakai dash dengan `pathLength="100"`,
           dan itu hampir selalu salah: `pathLength` hanya berlaku
           untuk elemen `<path>`. Di `<polyline>` Chrome mengabaikannya,
           jadi dash dihitung dalam satuan yang sudah diregangkan
           `preserveAspectRatio="none"` -- yaitu piksel layar.

           Akibatnya `100` jadi "100px nyala, 100px mati". Panjang
           garis di layar sekitar 704px, jadi hasilnya 3-4 potong
           dengan celah di antaranya: grafik yang terlihat putus-putus.

           `clip-path` tidak butuh perkiraan panjang sama sekali.
           Yang dipotong hanya lapisan SVG (garis + isian); garis
           bantu tetap terlihat sepanjang itu, dan titiknya ada di
           elemen terpisah yang tidak ikut terpotong. */
        .deck__svg {
            display: block;
            width: 100%;
            height: 100%;
            clip-path: inset(0 100% 0 0);
            animation: deck-open 1.15s var(--ease) .1s both;
        }

        @keyframes deck-open {
            from { clip-path: inset(0 100% 0 0); }
            to   { clip-path: inset(0 0 0 0); }
        }

        /* ---------- titik: HTML, bukan `<circle>` ----------

           Alasannya satu property: `preserveAspectRatio="none"`.

           Property itu meregangkan isi SVG dengan faktor BERBEDA
           di sumbu X dan Y. Pada kartu ~840px dan tinggi 84px, X
           jadi ~8.4x sedangkan Y hanya ~0.84x. Lingkaran r=2.6
           lalu tergambar sebagai ellips selebar ~44px dan setinggi
           ~4px -- bukan titik, tapi garis horizontal pipih. Itulah
           yang membuat kartu ini terbaca rusak, bukan satu garis.

           `vector-effect` tidak menolong: property itu menormalkan
           KETEBALAN GARIS, bukan bentuk lingkarannya.

           Karena itu titik dibuat di luar SVG, di elemen HTML yang
           tidak diregangkan apa pun. `border-radius: 50%` di sana
           selalu menghasilkan bulatan sempurna.

           Konsekuensinya harus dijaga: koordinat titik (`--x`,
           `--y`) harus selalu sama persis dengan koordinat SVG.
           Kalau hanya salah satu yang diubah, titik lepas dari
           garisnya -- dan itu bukan error CSS, cuma terlihat salah. */
        .deck__pts {
            position: absolute;
            inset: 0;
            pointer-events: none;
        }

        .deck__pts i {
            position: absolute;
            left: var(--x);
            top: var(--y);
            width: 11px; height: 11px;
            transform: translate(-50%, -50%);
            border-radius: 50%;
            background: #fff;

            /* Cincin pakai `box-shadow`, bukan `border`, supaya
               diameter titik tidak ikut bertambah oleh ketebalan
               cincin -- hasilnya tetap 11px persis. */
            box-shadow: 0 0 0 2px var(--sky-600);

            opacity: 0;
            animation: deck-pop .3s var(--ease) both;
        }

        /* Titik terakhir disorot, dan label bulan terakhir juga
           ditebalkan di bawah. Isinya biru pekat, cincinya putih
           -- kebalikan dari titik lain, supaya keduanya jelas
           berbeda tanpa harus dibandingkan. */
        .deck__pts i.is-on {
            width: 13px; height: 13px;
            background: var(--sky-600);
            box-shadow: 0 0 0 2px #fff;
        }

        /* Isian dari garis turun ke dasar. Gradien vertikal supaya
           warna di puncak lebih pekat -- membuat titik tertinggi
           jadi tempat mata mendarat duluan, tanpa perlu label
           angka.

           `userSpaceOnUse` wajib: kalau tidak, gradien ikut
           dipotong ke kotak masing-masing dan tiap ruas dapat
           gradasinya sendiri -- garis jadi belang-belang. */
        .deck__fill { fill: url(#deck-fill); }

        .deck__edge {
            fill: none;
            stroke: var(--sky-600);
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;

            /* Tanpa ini, `preserveAspectRatio="none"` ikut
               meregangkan ketebalan garis: di sumbu X (~8.4x)
               garis jadi setebal ~21px. `non-scaling-stroke`
               mengembalikannya ke 2.5px berapa pun lebar kartu. */
            vector-effect: non-scaling-stroke;
        }

        /* Titik muncul setelah garisnya selesai, satu per satu.
           Selisih 60ms disengaja: lebih cepat dari itu mereka
           terlihat muncul bareng, lebih lambat grafik terasa
           lambat selesai. */
        .deck__pts i:nth-child(1) { animation-delay: .78s; }
        .deck__pts i:nth-child(2) { animation-delay: .84s; }
        .deck__pts i:nth-child(3) { animation-delay: .90s; }
        .deck__pts i:nth-child(4) { animation-delay: .96s; }
        .deck__pts i:nth-child(5) { animation-delay: 1.02s; }
        .deck__pts i:nth-child(6) { animation-delay: 1.08s; }

        @keyframes deck-pop {
            from { opacity: 0; transform: translate(-50%, -50%) scale(0); }
            to   { opacity: 1; transform: translate(-50%, -50%) scale(1); }
        }

        /* Isian muncul sedikit lebih lambat dari garisnya, dan fade
           saja tanpa gerak. Kalau ikut muncul dari bawah, mata
           melihat dua bentuk datang dari arah berbeda dan
           grafiknya terbaca berantakan. */
        .deck__fill {
            opacity: 0;
            animation: fade .7s var(--ease) .75s forwards;
        }

        /* ---------- label bulan ----------

           GRID 6 KOLOM, bukan `space-between`. Ini perbaikan,
           bukan gaya: `justify-content: space-between` +
           `flex: 1; max-width: 42px` ( versi lama) tidak pernah
           bisa sejajar dengan titik.

           Hitungannya: 6 label x 42px = 252px, sisa 588px
           dibagi 5 celah = 117.6px. Pusat label jatuh di 2.5%,
           21.5%, 40.5%, 59.5%, 78.5%, 97.5% -- sedangkan titiknya
           di 8.33%, 25%, 41.7%, 58.3%, 75%, 91.7%. Selisihnya
           sampai 3.3 persen, atau ~48px di tengah kartu. Orang
           membaca label untuk mengurutkan titik, jadi selisih
           sebesar itu berarti sebagian bulan menunjuk ke ruas
           garis yang salah.

           Di grid, pusat tiap kolom ada tepat di (i + 0.5)/6 dari
           lebar: 8.33%, 25%, 41.67%, 58.33%, 75%, 91.67% -- sama
           persis dengan koordinat titik. Keduanya memakai satu
           angka yang sama, jadi tidak mungkin berbeda.

           Konsekuensi lain yang bagus: jarak antar label selalu
           sama, dan tepi kiri/kanan ada jarak 8.33% -- label
           pertama tidak lagi menempel tepi. */
        .deck__months {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
        }

        .deck__months span {
            text-align: center;
            font-size: 10.5px;
            letter-spacing: .03em;

            /* `ink-soft`, bukan `ink-mute`: label bulan 10.5px di
               atas putih cuma 2.56:1 dengan abu terang. Ini bukan
               teks dekoratif -- orang membacanya untuk mengurutkan
               titik dari kiri ke kanan, jadi bulan yang tidak
               terbaca bikin grafik kehilangan maknanya. */
            color: var(--ink-soft);
        }

        /* Bulan terakhir disorot karena titiknya yang tertinggi,
           jadi yang paling banyak dilihat mata. Warna biru, bukan
           hijau: hijau di sini terbaca sebagai "pembaik", bukan
           "tertinggi". */
        .deck__months span:last-child { color: var(--sky-700); font-weight: 600; }

        /* Baris kecil di bawah mockup: jalan keluar untuk yang sudah jadi
           pelanggan. Tautan di teksnya sengaja minimal -- halaman ini
           untuk calon pembeli baru, dan setiap pintu masuk tambahan hanya
           menambah satu alasan untuk pergi sebelum sempat membaca. */
        .mock__foot {
            margin: 0; padding: 11px 16px 12px;
            border-top: 1px solid var(--line);
            background: var(--bg-soft);
            font-size: 11.5px; line-height: 1.55; text-align: center;
            color: var(--ink-soft);
        }
        .mock__foot strong { color: var(--ink); font-weight: 600; }
        .mock__foot a { color: var(--sky-700); text-decoration: none; border-bottom: 1px solid var(--sky-300); }
        .mock__foot a:hover { border-bottom-color: var(--sky-600); }

        /* ---------- section ----------

           Jarak antar section DIKECILKAN dari 80px ke 62px, dan tiap
           batas section diberi garis tipis yang memudar di kedua ujungnya
           -- bukan garis penuh.

           Kenapa bukan garis penuh: garis `border-bottom` yang memotong
           seluruh lebar halaman terbaca sebagai "batas dokumen", bukan
           "batas antar bagian". Yang dikehendaki adalah sinyal "konten
           berganti topik", jadi gradasi yang hilang di kedua sisi jauh
           lebih tepat: mata diberi tahu ada pergantian tanpa
           sempat merasa halaman terpotong.

            Gradasi lewat `background` pada pseudo-element supaya
           tidak menambah elemen DOM dan tidak mengganggu layout. */
        section { padding: 62px 0; position: relative; }

        /* Garis pemisah antar section.

           Pseudo-element, bukan `border-bottom` pada section itu
           sendiri: border akan ikut berubah lebarnya saat section
           memakai padding berbeda, dan ia menempel langsung di tepi
           konten -- terlalu rapat. Pseudo-element dengan
           `top: -1px` bisa berdiri sendiri sebagai garis transisi. */
        section + section::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 1px;
            background: linear-gradient(90deg,
                transparent 0%,
                var(--sky-300) 18%,
                var(--sky-300) 82%,
                transparent 100%);
            pointer-events: none;

            /* Garis tumbuh dari tengah saat section masuk layar.
               `scaleX` (bukan `width`) supaya jalan di compositor dan
               tidak memicu layout tiap frame. */
            transform: scaleX(0);
            transform-origin: center;
            transition: transform .9s var(--ease);
        }
        section.is-in::before { transform: scaleX(1); }

        /* Section bertinted (cara kerja) tetap dibedakan lewat latar,
           jadi garis pemisah di atasnya dimatikan supaya tidak ada
           dua sinyal untuk batas yang sama. */
        section.tint::before { display: none; }

        section.tint {
            background: linear-gradient(180deg, var(--bg-soft), #fff);
        }

        /* Section pratinjau (mockup) tidak perlu pemisah tambahan:
           mockup-nya sudah punya bayangan sendiri yang jelas batasnya,
           dan garis tepat di atas kartu besar terasa seperti pagar. */
        section.preview::before { display: none; }

        /* ============================================================
           KEUNGGULAN -- masalah vs solusi

           Section ini menjawab pertanyaan yang muncul setelah orang
           baca daftar fitur: "bagaimana ini mengubah pekerjaan
           saya sehari-hari?"

           Yang ditampilkan adalah PASANGAN masalah -> jawaban, bukan
           daftar fitur yang diulang. Alasannya, daftar fitur menjelaskan
           apa yang ADA di produk, sedangkan orang tidak pernah membeli
           karena fitur -- mereka membeli karena masalah yang mereka punya
           sedang ada di depan mata. Menyebut masalah mereka lebih
           cepat membuat mereka yakin bahwa produk ini paham situasinya.

           Sengaja TIDAK ada angka, bintang, atau nama pelanggan di
           section ini. Halaman ini punya aturan kejujuran yang tegas
           (lihat catatan di section pratinjau): angka yang bukan data
           nyata tidak boleh muncul sebagai klaim. Testimoni rekaan
           adalah Iterator yang paling cepat merusak kredibilitas --
           dan kredibilitas justru yang sedang dibangun halaman ini.
           Jadi daya tariknya dibangun dari pemodelan masalah nyata,
           bukan angka yang tidak bisa dipertanggungjawabkan.
           ============================================================ */

        /* `.paingrid` dua kolom: kiri daftar masalah, kanan daftar
           jawaban. Di layar lebar, dua kolom ini dibaca berdampingan --
           orang bisa mencocokkan masalah dengan jawabannya tanpa
           harus membaca satu blok penuh dulu. */
        .paingrid {
            display: grid;
            gap: 26px;
            grid-template-columns: 1fr 1fr;
            align-items: start;
        }

        /* Kolom masalah sedikit lebih redup daripada kolom jawaban.
           Bukan supaya tidak terbaca -- cukup supaya tidak bersaing
           dengan sisi yang berisi Eligibility. Masalah adalah bahan
           bakunya, bukan akhir cerita. */
        .painlist {
            list-style: none;
            margin: 0; padding: 0;
            display: grid; gap: 2px;
        }

        .painlist li {
            display: flex; gap: 13px; align-items: flex-start;
            padding: 15px 18px;
            border-radius: var(--r);
            color: var(--ink-soft);
            font-size: 15.5px; line-height: 1.55;
        }

        /* Ikon "silang" di sisi masalah: penanda bahwa baris ini
           menggambarkan kondisi yang tidak diinginkan. */
        .painlist li > svg {
            width: 19px; height: 19px; flex: none;
            margin-top: 1px;
            fill: none; stroke: var(--ink-mute);
            stroke-width: 2.1; stroke-linecap: round; stroke-linejoin: round;
        }

        /* Kolom jawaban: kartu putih dengan garis accent. Fungsinya
           membawa "ini yang berubah setelah pakai ASTALOKA" ke level
           yang sama secara visual dengan daftar fitur -- supaya yang
           terbaca sebagai dua sisi dari satu argumen. */
        .gaingrid {
            list-style: none;
            margin: 0; padding: 0;
            display: grid; gap: 14px;
        }

        .gaingrid li {
            position: relative;
            display: flex; gap: 14px; align-items: flex-start;
            padding: 20px 22px;
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            background: #fff;
            box-shadow: var(--sh-sm);
            transition: border-color .4s var(--ease), box-shadow .4s var(--ease);
        }

        .gaingrid li:hover {
            border-color: var(--sky-200);
            box-shadow: var(--sh-md);
        }

        /* Kotak centang biru: penanda "ini yang sudah ditangani". */
        .gaingrid li > svg {
            width: 22px; height: 22px; flex: none;
            margin-top: 1px;
            fill: none; stroke: var(--sky-600);
            stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round;
        }

        .gaingrid strong {
            display: block;
            margin-bottom: 3px;
            font-size: 15.5px; font-weight: 700; letter-spacing: -.012em;
            color: var(--ink);
        }

        .gaingrid span {
            display: block;
            font-size: 14.5px; line-height: 1.58;
            color: var(--ink-soft);
        }

        /* Baris pemisah di dalam kolom masalah, dibuat dengan gradient
           supaya ujungnya memudar dan tidak terbaca sebagai garis
           tabel. Tanpa ini, lima baris teks jadi satu blok abu yang
           padat dan sulit dipindai. */
        .painlist li + li {
            border-top: 1px solid var(--line);
            border-radius: 0;
            padding-top: 15px;
        }

        .sec-head--center { max-width: 68ch; margin-inline: auto; text-align: center; }

        @media (max-width: 860px) {
            .paingrid { grid-template-columns: 1fr; gap: 30px; }
            .painlist li + li { border-top: 0; padding-top: 15px; }

            /* Angka dan kalimatnya jadi satu kolom.

               Pada lebar tablet, kolom `auto` untuk angka dan `1fr`
               untuk kalimat menyisakan kalimat hanya ~140px -- dua kata
               per baris, dan barisnya jadi lebih tinggi dari angkanya
               sendiri. Ditumpuk, dengan angka tetap di atas, karena
               urutan baca angka-dulu-lalu-kalimat itu yang bikin var
               ini terbaca sebagai satu kalimat. */
            .pengguna-row { grid-template-columns: 1fr; gap: 4px 0; }
            .pengguna-ketiga { gap: 8px 22px; }
        }

        .sec-head { max-width: 64ch; margin-bottom: 38px; }
        .sec-head h2 {
            margin: 0 0 14px; font-size: clamp(27px, 3.5vw, 36px);
            letter-spacing: -.03em; line-height: 1.16; font-weight: 800;
        }
        .sec-head p { margin: 0; color: var(--ink-soft); font-size: 17.5px; line-height: 1.6; }

        /* ============================================================
           PENGGUNA -- "sudah dipakai siapa"

           Section ini berbeda dari semua section lain di halaman ini:
           tidak ada mockup, tidak ada ilustrasi, tidak ada daftar
           fitur. Yang ada hanya angka dan kalimat.

           Kesederhanaannya itu disengaja, dan alasannya sudah ditulis
           panjang di `App\Support\StatistikPengguna`: nama lokasi dan
           domainnya tidak pernah boleh tampil di halaman publik.
           Kalau section ini nanti tumbuh menjadi daftar nama, seluruh
           alasan itu hilang -- jadi tidak ada tombol "lihat semua",
           tidak ada filter, dan tidak ada alasan lain untuk menambah
           apa pun ke sini.
           ============================================================ */

        /* Angka besar di kiri, kalimat di kanan.

           Dua kolom, bukan angka di tengah dengan kalimat di bawahnya.
           Di tengah, kalimatnya berubah menjadi blok lebar yang dibaca
           sebagai paragraf, dan angkanya melayang sendiri tanpa
           konteks. Bersebelahan, orang membaca kiri ke kanan dalam satu
           lintasan mata: "12" lalu langsung "lokasi sudah memakai
           ASTALOKA" -- satu kalimat utuh, bukan dua elemen terpisah.

           Kolom pertama tidak harus berisi satu elemen. Blok
           angka + keterangan "yang pertama" diperlakukan sebagai satu
           kesatuan di sini; `align-items: center` menengahkan
           keseluruhan blok itu terhadap kalimatnya. */
        .pengguna-row {
            display: grid;
            gap: 26px 34px;
            grid-template-columns: auto 1fr;
            align-items: center;
        }

        /* Kolom angka. `min-width: 0` supaya blok ini boleh menyusut
           di kolom yang sempit -- tanpa itu, kata "yang pertama"
           yang lebarnya tetap akan memaksa kolom melebar dan
           kalimat di sebelahnya tergeser. */
        .pengguna-angka-blok {
            min-width: 0;
        }

        /* Angka tabular. Tanpa itu digit berganti lebar saat animasi
           menghitung -- angka yang melompat-lompat terlihat seperti
           glitch, bukan seperti hitungan. Alasan yang sama seperti di
           `.mock__value`. */
        .pengguna-angka {
            font-size: clamp(56px, 8vw, 84px);
            font-weight: 800;
            line-height: 1;
            letter-spacing: -.05em;
            font-variant-numeric: tabular-nums;
            color: var(--sky-700);
        }

        /* Angka nol tetap ditampilkan, hanya warnanya turun.

           Menyembunyikan angka nol membuat halaman terlihat seperti
           gagal memuat data, bukan seperti jujur belum ada yang memakai
           -- dan "0" justru jawaban yang benar saat sistem baru
           dipakai. Yang disembunyikan hanya KALIMAT yang bergantung
           pada nilainya: "0 jenis usaha terlayani" tidak menambah
           apa pun bagi pembaca, sedangkan "0 lokasi" tetap jujur. */
        .pengguna-angka--kosong { color: var(--ink-mute); }

        /* "yang pertama" menempel di bawah angka, bukan jadi elemen
           grid sendiri.

           Yang diulang di sini adalah KONTEKS angka, jadi harus
           menempel padanya secara visual. Kalau dibiarkan jadi
           elemen grid tersendiri, kata itu akan menduduki kolom
           kedua sejajar dengan kalimat -- dan terbentuk baris
           yang isinya cuma satu kata tanpa tokohnya yang jelas.

           `display: block` dipakai karena elemennya `<span>`:
           tanpa itu dia inline dan tidak akan turun ke baris
           sendiri meski sudah ada `margin-top`. */
        .pengguna-pertama {
            display: block;
            margin-top: 10px;
            font-size: 15px;
            font-weight: 600;
            letter-spacing: .01em;
            color: var(--ink-mute);
        }

        /* Angka pendukung sebagai satu baris kecil di bawah, bukan
           tiga kartu sebesar angka utama.

           "Jenis usaha terlayani" dan "sejak" adalah informasi
           pendukung, bukan judul section. Menaikkannya jadi kartu
           sebesar angka utama membuat informasi pendukung terbaca
           sama pentingnya dengan klaim utamanya -- dan halaman ini
           sudah punya cukup banyak tempat yang berlomba-lomba
           menarik perhatian. */
        .pengguna-ketiga {
            grid-column: 1 / -1;
            display: flex;
            flex-wrap: wrap;
            gap: 10px 30px;
            margin: 0;
            padding: 22px 0 0;
            border-top: 1px solid var(--line);
            list-style: none;
        }

        .pengguna-ketiga li {
            display: flex;
            align-items: baseline;
            gap: 9px;
            font-size: 15px;
            line-height: 1.5;
            color: var(--ink-soft);
        }

        /* Di baris kecil, yang ditonjolkan adalah kata-katanya, bukan
           angkanya. Angka tetap tabular supaya sejajar, tapi ukurannya
           hanya sedikit lebih besar dari teks -- kalau angkanya yang
           mendominasi, baris ini berubah menjadi judul kedua dan
           mengambil perhatian dari angka utama. */
        .pengguna-ketiga b {
            font-size: 19px;
            font-weight: 700;
            letter-spacing: -.02em;
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }

        /* Lantainya 262px, tapi `min()` membuatnya mengalah pada ruang yang
           ada: di layar yang lebih sempit dari satu kartu, kolom tetap
           ikut menyusut alih-alih memaksa dokumen melebar. */
        .grid { display: grid; gap: 22px; grid-template-columns: repeat(auto-fit, minmax(min(262px, 100%), 1fr)); }

        /* ---------- kartu fitur ----------

           Kartu INERT: bukan tautan, bukan tombol, tidak bisa diklik.
           Karena itu TIDAK BOLEH terangkat saat hover -- menghidupkan
           sesuatu yang tidak bisa diklik hanya menggoda user untuk
           mencobanya lalu kecewa.

           Yang boleh bergerak hanya yang JELAS tidak bisa diklik:
           cahaya, warna, dan isi. Semua efek di bawah dipicu hover
           pada kartu, tapi tidak pernah mengubah posisinya -- tidak ada
           `translate`, tidak ada `scale`, tidak ada shadow yang membesar.

           Shadow-nya aktif (selalu tampil, bukan nyaris transparan),
           jadi kartu terbaca sebagai panel tersendiri yang terangkat
           dari section. */

        /* Lapisan 1: kartu itu sendiri.

           `overflow: hidden` itu syarat, bukan pilihan: pseudo-element
           cahaya menyapu di bawah dipotong tepi kartu. Tanpa itu,
           cahaya akan keluar sebagai kotak tembus di atas teks
           dan merusak radius-nya. */
        .card {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            padding: 28px;
            box-shadow: var(--sh-md);
            transition: border-color .4s var(--ease), box-shadow .4s var(--ease);
        }

        /* Garis accent di tepi atas. Tumbuh dari tengah saat hover.

           Dipakai `::before` dengan `inset-inline: 0` + `scaleX`, bukan
           `width` -- width memaksa layout di tiap frame, sedangkan
           scale cuma jalan di compositor. Animasi 7 kartu sekaligus
           yang memakai width akan terasa berat dan mengganggu layout halaman. */
        .card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--sky-500), var(--sky-300), transparent);
            transform: scaleX(0);
            transform-origin: center;
            opacity: 0;
            transition: transform .55s var(--ease), opacity .35s var(--ease);
            z-index: 2;
        }

        /* Lapisan 2: cahaya yang menyapu diagonal saat hover.

           `radial-gradient` yang ikut bergerak, bukan `background-position`
           yang dianimasikan -- yang kedua memicu repaint seluruh kartu
           tiap frame, dan dengan 7 kartu sekaligus plus blur 40px di
           dalamnya, itu perbedaan yang terasa di scroll.

           Pointer-events none wajib: tanpa itu, pseudo-element jadi
           target hover dan kursor berubah jadi teks di area itu. */
        .card::after {
            content: '';
            position: absolute;
            inset: -60%;
            background: radial-gradient(
                circle at 30% 30%,
                rgb(56 189 248 / .13),
                transparent 55%
            );
            opacity: 0;
            transition: opacity .5s var(--ease);
            pointer-events: none;
            z-index: 0;
        }

        /* Isi kartu di atas cahaya. Tanpa ini, pseudo-element menutup
           teks dan hover efeknya hilang total. */
        .card > * { position: relative; z-index: 1; }

        .card:hover {
            border-color: var(--sky-200);
            /* Hanya shadow yang sedikit lebih dalam. Bukan shadow yang
               "naik" seperti kartu yang bisa diklik -- bedanya
               sengaja dibuat tipis supaya tidak terbaca sebagai
               permukaan yang menggoda untuk ditekan. */
            box-shadow: var(--sh-lg);
        }

        .card:hover::before { opacity: 1; transform: scaleX(1); }
        .card:hover::after  { opacity: 1; }

        /* Ikon: kotak warnanya bergerak, glyph di dalamnya tidak.

           Glyph ikut bergerak akan terbaca sebagai tombol yang
           "menekan" ikon -- persis hal yang harus dihindari di
           kartu inert. Yang bergerak di sini cuma gradasi, yang
           jelas-jelas dekoratif.

           Ukuran lewat CSS, bukan `font-size`. Isi kotak ini dulu
           emoji, jadi dimensinya dibuat untuk font 22px. Sekarang
           isinya <svg> yang ukuran-nya dikontrol `width`/`height`,
           dan `font-size` tidak lagi berlaku. Tanpa perubahan ini,
           ikon ikut mewarisi ukuran teks di sekitarnya. */
        .card .ico {
            width: 46px; height: 46px; border-radius: 13px;
            display: grid; place-items: center;
            background: linear-gradient(135deg, var(--sky-50), var(--sky-100));
            color: var(--sky-700); margin-bottom: 17px;
            box-shadow: var(--sh-sm);
            transition: background .45s var(--ease), box-shadow .45s var(--ease),
                        border-radius .45s var(--ease);
        }

        .card:hover .ico {
            background: linear-gradient(135deg, var(--sky-100), var(--sky-200));
            border-radius: 10px;
            box-shadow: var(--sh-md);
        }

        /* Bentuk ikon di dalam kotak.

           `fill: none` + `stroke: currentColor` membuat ikon mengikuti
           warna teksnya, jadi saat hover warnanya berubah, ikon ikut
           berubah tanpa selector tambahan. Ujung `round` supaya tidak
           tajam di ukuran kecil. */
        .card .ico svg {
            width: 23px; height: 23px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.75;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .card h3 { margin: 0 0 8px; font-size: 17.5px; letter-spacing: -.016em; font-weight: 700; }
        .card p { margin: 0; font-size: 14.5px; line-height: 1.62; color: var(--ink-soft); }

        /* ---------- animasi masuk kartu ----------

           `.reveal.shown` memakai `rise` yang sama dengan elemen lain,
           tapi kartu dapat versi sendiri: masuk dari bawah lebih jauh
           dan sedikit miring, supaya barisan kartu terasa naik sebagai
           satu blok, bukan 7 elemen yang kebetulan muncul bersamaan.

           Durasi 0.62s, bukan 0.78s seperti `rise`. Hitungannya
           berantai: 7 kartu dengan delay 70ms berarti yang terakhir
           mulai 420ms lebih lambat. Kalau tiap kartu ikut 0.78s
           penuh, baris terakhir baru selesai di 1.2s -- orang yang
           sedang scroll cepat akan lewat begitu saja lalu
           menganggap halaman lambat. */
        @keyframes card-in {
            from {
                opacity: 0;
                transform: translate3d(0, 42px, 0) rotate(-1deg);
            }
            to {
                opacity: 1;
                transform: none;
            }
        }

        .grid .card.reveal.shown { animation: card-in .62s var(--ease) var(--d, 0ms) forwards; }

        /* Ikon dan teks masuk sedikit setelah kartu selesai, supaya mata
           bisa mengikuti urutan: bayangan kartu dulu, lalu isinya.
           Delay-nya kecil (90ms) -- lebih dari itu terasa seperti
           elemen yang gagal loading. */
        .card.reveal.shown .ico { animation: fade .4s var(--ease) calc(var(--d, 0ms) + 90ms) both; }
        .card.reveal.shown h3,
        .card.reveal.shown p  { animation: fade .4s var(--ease) calc(var(--d, 0ms) + 150ms) both; }

        /* ---------- cara kerja ----------

           Panel statis, bukan elemen interaktif. Shadow aktif supaya
           menyatu dengan kartu fitur.

           Perbedaan dari versi lama: nomor langkah pindah dari pojok
           kanan ke kotak ikon besar di kiri, dan garis penghubung
           horizontal membelah antar langkah. Alasannya, tiga kartu
           bersebelahan dengan nomor di pojok kanan forced user untuk
           membaca berlawanan arah dengan alur kalimat. Nomor di kiri
           aligning dengan arah baca normal dan langsung terlihat
           sebagai urutan. */
        .steps {
            display: grid; gap: 22px;
            grid-template-columns: repeat(3, 1fr);
            counter-reset: s;
            position: relative;
        }

        .step {
            position: relative;
            padding: 30px 26px 28px;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--r-lg);
            box-shadow: var(--sh-md);
            transition: border-color .4s var(--ease), box-shadow .4s var(--ease),
                        transform .4s var(--ease);
        }
        /* Kartu boleh sedikit terangkat: ini bukan elemen yang bisa
           diklik, tapi elevate tipis cukup untuk memberi tanda "ini
           urutan" tanpa menggoda untuk ditekan. */
        .step:hover {
            border-color: var(--sky-200);
            box-shadow: var(--sh-lg);
            transform: translateY(-3px);
        }

        /* Kotak nomor: besar, di kiri, jadi penanda urutan yang dibaca
           pertama sebelum paragrafnya. */
        .step__num {
            counter-increment: s;
            width: 46px; height: 46px;
            display: grid; place-items: center;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--sky-500), var(--sky-700));
            color: #fff; font-size: 19px; font-weight: 800;
            box-shadow: var(--sh-brand);
            margin-bottom: 18px;
        }
        .step__num::before { content: counter(s); }

        .step h3 { margin: 0 0 8px; font-size: 17.5px; letter-spacing: -.018em; font-weight: 700; }
        .step p { margin: 0; font-size: 14.5px; line-height: 1.62; color: var(--ink-soft); }

        /* Tautan di dalam kartu langkah.

           Hanya langkah pertama yang punya tautan, tapi selector-nya bukan
           `.step:first-child` -- kartu yang bisa diklik adalah isi langkah
           itu, dan selector posisi akan ikut bergeser kalau nanti ada
           langkah keempat yang ditambahkan di tengah.

           Margin atas menyamakan jarak teks ke tautan dengan jarak tautan
           ke paragraf, supaya tombol kecil itu terlihat sengaja ada, bukan
           ditempel. */
        .step__cta {
            margin-top: 16px;
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 16px; border-radius: var(--r-sm);
            background: var(--sky-50); border: 1px solid var(--sky-200);
            color: var(--sky-800); font-size: 14px; font-weight: 600;
            text-decoration: none;
            transition: background .22s var(--ease), border-color .22s var(--ease),
                        transform .18s var(--ease), box-shadow .28s var(--ease);
        }
        .step__cta:hover {
            background: #fff; border-color: var(--sky-400);
            transform: translateY(-2px); box-shadow: var(--sh-md);
        }
        .step__cta svg {
            width: 16px; height: 16px; flex: none;
            fill: none; stroke: currentColor;
            stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round;
        }
        .step__cta svg.i-solid { fill: currentColor; stroke: none; }

        /* Garis penghubung antar langkah.

           Ditaruh di belakang kartu (`z-index: -1`) dan dipotong
           oleh gap antar kolom. Kalau tidak dipotong, garis akan
           terlihat masuk ke dalam kartu dan merusak tepinya.

           Hanya untuk dua langkah pertama: `:not(:last-child)` --
           Step terakhir tidak punya successor untuk disambung. */
        .step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 53px;                     /* sejajar dengan tengah kotak nomor */
            left: 100%;
            width: 22px;                   /* = gap, jadi garis hanya di celah */
            height: 2px;
            background: linear-gradient(90deg, var(--sky-300), var(--sky-200));
            z-index: -1;
        }

        /* ---------- CTA penutup ---------- */
        .closer {
            position: relative; overflow: hidden;
            background: linear-gradient(150deg, var(--sky-800), var(--sky-950));
            color: #fff; border-radius: var(--r-xl);
            padding: 48px 44px; text-align: center;
            box-shadow: 0 20px 50px -18px rgb(8 47 73 / .55), 0 4px 12px -4px rgb(8 47 73 / .3);
        }

        /* Padding di semua sisi sama. Semula bagian bawah dibiarkan 72px,
           karena panel ini dulu disambung ke footer lewat pseudo-element
           gelap dan ruang ekstra itu dipakai untuk memulai gradasi
           sebelum footer. Pseudo-element itu sudah dihapus -- sekarang
           section penutup memang gelap penuh dan slidanya langsung ke
           footer, jadi tidak ada yang perlu diberi ruang tambahan.

           Panel kaca juga lebih jujur kalau simetris: dengan 72px di
           bawah dan 48px di atas, isinya terlihat melayang ke arah
           footer, padahal tidak ada lagi sesuatu di footer yang
           menariknya ke sana. */
        .closer { padding-bottom: 48px; }
        /* Tekstur lembut tanpa geometri.

           Pola grid yang dulu ada di sini (dua `linear-gradient` 1px
           berulang) dihapus dengan alasan yang sama seperti di
           `.hero-panel::before`: garis horizontal dan vertikal yang
           berulang pasti membentuk kotak, dan di panel gelap sebesar
           ini kisi jadi hal pertama yang dilihat mata.

           Diganti satu gumpalan cahaya di belakang tombol tengah,
           tempat mata sudah diarahkan. Tidak ada tepi, jadi tidak
           mungkin jadi garis. */
        .closer::before {
            content: ""; position: absolute;
            inset: -25%;
            background:
                radial-gradient(ellipse 55% 60% at 50% 45%,
                    rgb(56 189 248 / .13), transparent 72%);
            pointer-events: none;
        }
        /* Glow berputar pelan di belakang teks. */
        .closer::after {
            content: ""; position: absolute;
            width: 520px; height: 520px; top: -180px; left: 50%; margin-left: -260px;
            background: radial-gradient(circle, rgb(56 189 248 / .26), transparent 62%);
            filter: blur(64px); pointer-events: none;
            animation: drift-b 20s ease-in-out infinite alternate;
        }
        .closer > * { position: relative; z-index: 1; }

        /* ---------- panel penutup menyatu dengan footer ----------

           Section penutup adalah blok gelap penuh yang turun
           langsung ke footer, tanpa celah putih di antaranya.

           DULU section ini terang, dan sambungan ke footer gelap
           dibuat dengan pseudo-element gelap di dasar section
           sementara padding bawah section dinolkan. Hasilnya
           cacat, dan cacatnya tidak bisa diperbaiki hanya dengan
           menyetel gradasinya.

           Sebabnya geometri, bukan warna. Kartu `.closer` lebih
           sempit dari layar, jadi di kiri dan kanannya selalu ada
           gutter yang tidak tertutup kartu. Gradasi yang dimulai
           dari `transparent` membuka celah putih tepat di balik
           dua sudut membulat bawah kartu, dan celah itu
           mengintip sebagai dua pita terang. Gradasi yang
           dimulai pekat sejak 0% menutup sudutnya, tapi
           menyisakan garis horizontal mendadak di kedua gutter.

           Dua-duanya gagal karena menyelesaikan masalah yang
           salah. Yang dibutuhkan bukan jembatan di antara dua
           bidang, tapi satu bidang: seluruh section gelap dari
           atas sampai bawah, lalu kartu jadi panel kaca di
           atasnya. Sekarang tidak ada yang perlu disambung, jadi
           tidak ada sambungan yang bisa rusak.

           Warna dasar section sengaja `#0a3d5c`, sedikit lebih
           terang dari `--sky-950` yang dipakai di dasar. Itu
           memberi konten sedikit kedalaman, dan warna akhirnya
           tetap sama persis dengan warna awal footer sehingga di
           antara keduanya tidak ada garis.

           Kartu jadi kaca karena sekarang berdiri di atas latar
           yang sama warnanya. Kalau tetap pekat, bagian bawah
           kartu lenyap ke dalam latar dan panelnya tidak
           terbaca sebagai bentuk.

           Garis pemisah antar section dimatikan di sini: batas
           terang ke gelap sudah dijelaskan oleh perubahan warna
           itu sendiri, dan garis biru terang di atas blok gelap
           hanya menambah garis yang tidak menjelaskan apa pun. */
        section#mulai {
            padding-bottom: 56px;
            background: linear-gradient(180deg, #0a3d5c, var(--sky-950));
        }
        section#mulai::before { display: none; }

        .closer {
            background: rgb(255 255 255 / .05);
            border: 1px solid rgb(125 211 252 / .22);
            box-shadow: 0 24px 60px -28px rgb(0 0 0 / .6);
        }

        .closer h2 { margin: 0 0 14px; font-size: clamp(26px, 3.2vw, 34px); letter-spacing: -.028em; font-weight: 800; }
        .closer p { margin: 0 auto 30px; max-width: 54ch; color: var(--sky-100); font-size: 17px; }
        .closer .btn-primary { background: #fff; color: var(--sky-800); box-shadow: 0 8px 22px -8px rgb(0 0 0 / .4); }
        .closer .btn-primary:hover { background: var(--sky-50); }
        .closer .btn-ghost {
            background: rgb(255 255 255 / .07); border-color: rgb(255 255 255 / .3);
            color: #fff; backdrop-filter: blur(4px);
        }
        .closer .btn-ghost:hover { border-color: #fff; background: rgb(255 255 255 / .16); }

        /* Kontak alternatif di bawah tombol.

           Satu blok dua baris: label + nomor telepon, lalu alamat email.
           Ditumpuk, bukan satu baris panjang, karena pada layar sempit
           "Atau hubungi langsung: +62 882-0066-44656 astabratamgl@gmail.com"
           tidak muat tanpa membuat satu kata per baris.

           Tipografi sengaja satu tingkat lebih kecil dari tombol: ini
           jalan cadangan, bukan tujuan utama. Tapi tetap di atas warna
           teks utama -- `--sky-100` dengan opacity penuh, bukan
           `--sky-200` seperti `.note` di hero, karena yang diklik di sini
           harus terbaca sekilas tanpa membaca seluruh blok. */
        .kontak-alt {
            margin-top: 22px;
            display: flex; flex-wrap: wrap; align-items: center;
            justify-content: center; gap: 6px 10px;
            font-size: 14.5px;
        }
        .kontak-alt__label { color: rgb(255 255 255 / .72); }

        .kontak-alt a, .kontak-email a {
            display: inline-flex; align-items: center; gap: 8px;
            /* Ikon tetap utuh, teks boleh membungkus. Tanpa ini
               alamat email yang panjang memaksa flex item melebar dan
               dokumen ikut melebar di layar sempit. */
            min-width: 0;
            color: #fff; font-weight: 600;
            text-decoration: none;
            transition: opacity .2s var(--ease);
        }
        .kontak-alt a:hover, .kontak-email a:hover { opacity: .82; text-decoration: underline; text-underline-offset: 3px; }

        /* Nomor telepon diberi garis bawah tipis, bukan hanya perubahan
           warna: di atas dasar gelap, `color` saja tidak selalu terbaca
           sebagai "ini bisa diklik". */
        .kontak-alt a { text-decoration: underline; text-decoration-color: rgb(255 255 255 / .35); text-underline-offset: 4px; }
        .kontak-alt a:hover { text-decoration-color: #fff; }

        .kontak-email { margin: 10px 0 0; display: flex; justify-content: center; gap: 8px; font-size: 14.5px; }

        /* Alamat email punya satu kata panjang tanpa spasi. Beri izin patah di
           mana saja supaya membungkus, bukan memaksa dokumen melebar. */
        .kontak-email a { overflow-wrap: anywhere; }

        .kontak-alt svg, .kontak-email svg {
            width: 16px; height: 16px; flex: none;
            fill: none; stroke: currentColor;
            /* Sama seperti `.btn svg`: 2.4 supaya goresan efektifnya
               ~1.6px, bukan ~1.27px yang praktis tak terlihat. */
            stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round;
            opacity: .85;
        }

        /* ---------- tombol kembali ke atas ----------

           Pojok kanan bawah, fixed. Muncul hanya setelah halaman
           di-scroll lewat ambang tertentu, supaya tidak menambah
           navigasi yang selalu ada di layar pertama yang masih kosong.

           Catatan: `bottom`/`right` memakai `env(safe-area-inset-*)`
           supaya tidak tertutup gesture bar / notch di iPhone. */
        .to-top {
            position: fixed;
            right: max(20px, env(safe-area-inset-right));
            bottom: max(20px, env(safe-area-inset-bottom));
            z-index: 45;

            width: 46px; height: 46px;
            display: grid; place-items: center;
            border: 1px solid rgb(255 255 255 / .22);
            border-radius: 50%;
            cursor: pointer;
            color: #fff;
            background: linear-gradient(140deg, var(--sky-500), var(--sky-700));
            box-shadow: var(--sh-brand), 0 10px 24px -10px rgb(8 47 73 / .5);

            /* Tersembunyi tanpa interaksi: opacity 0 + scale kecil +
               pointer-events none. Bukan `display: none` supaya
               transisinya tetap jalan. */
            opacity: 0;
            visibility: hidden;
            transform: translateY(14px) scale(.82);
            transition: opacity .32s var(--ease), transform .38s var(--spring),
                        visibility .32s, box-shadow .28s var(--ease);
        }
        .to-top.is-visible {
            opacity: 1; visibility: visible; transform: none;
            pointer-events: auto;
        }
        .to-top:hover {
            box-shadow: var(--sh-brand), 0 16px 34px -12px rgb(8 47 73 / .6);
        }
        .to-top:active { transform: translateY(1px) scale(.94); }

        /* Panah: dibuat dari dua garis yang berputar 45 derajat (chevron),
           bukan glyphfont -- supaya tidak bergantung font apa pun
           yang terpasang di sistem user. */
        .to-top span {
            display: block; width: 9px; height: 9px;
            border-top: 2px solid currentColor;
            border-left: 2px solid currentColor;
            transform: rotate(45deg) translate(1px, 1px);
            transition: transform .32s var(--ease);
        }
        .to-top:hover span { transform: rotate(45deg) translate(2px, 2px); }

        /* ---------- footer ----------

           DARK, dan itu keputusan yang disengaja.

           Sebelumnya putih dengan garis 1px di atas. Masalahnya
           bukan soal aesthetics: di halaman yang isinya sudah gelap di hero
           dan di blok penutup, footer putih jadi satu bidang
           terang besar di paling dasar -- dan mata yang baru saja
           keluar dari panel "Siap mulai?" yang gelap butuh beberapa
           saat untuk menyesuaikan diri sebelum membaca baris
           kecil di footer.

           Gradasi yang sama dengan `.closer` juga menyatukan
           kedua blok jadi satu bidang utuh: panel penutup dan
           footer terlihat seperti dua bagian dari satu rectangle
           gelap, dipisahkan oleh celah tipis -- bukan dua halaman
           berbeda yang kebetulan berdekatan.

           `--sky-950` di paling bawah, bukan rata. Footer datar
           dengan warna yang sama persis dengan panel penutup akan
           terlihat seperti satu blok besar yang salah dipotong;
           gradasi yang menjauh ke bawah memberi tanda bahwa
           halaman sudah selesai, dan itu yang membuat blok ini
           dibaca sebagai footer, bukan section. */
        footer {
            position: relative;
            padding: 58px 0 34px;
            background: linear-gradient(180deg, var(--sky-950), #041e30 70%, #032033);
            color: var(--sky-100);
            overflow: hidden;
        }

        /* Cahaya besar di kiri atas: ditambahkan sedikit supaya bagian
           paling padat di footer (brand) punya kedalaman, dan
           memudar ke kanan menjadi warna teks biasa.

           `inset` atas bukan lagi negatif. Saat section penutup masih
           terang, cahaya yang dimulai sebelum tepi footer tidak
           terlihat -- yang penting ada cahaya di dalam footer.

           Sekarang section penutup gelap dan warna akhirnya persis
           sama dengan warna awal footer, jadi cahaya yang dimulai
           di atas tepi itu langsung terlihat sebagai garis terang
           yang memotong bidang gelap tepat di sambungannya.

           Sample piksel di gutter kiri menunjukkan lompatan dari
           rgb(7 46 72) di section menjadi rgb(13 62 92) di baris
           pertama footer: sebuah garis terang di tengah bidang
           yang seharusnya rata. Dipulai dari 0% atas, cahaya itu
           mengikuti warna footer sendiri dan tidak ada yang bisa
           terbaca sebagai garis lagi. */
        footer::before {
            content: "";
            position: absolute;
            inset: 0 40% 30% -10%;
            background: radial-gradient(ellipse 60% 60% at 30% 30%,
                        rgb(56 189 248 / .16), transparent 70%);
            filter: blur(50px);
            pointer-events: none;
        }
        footer > * { position: relative; z-index: 1; }

        /* Tidak ada garis di tepi atas footer.

           Awalnya ada, mengikuti bahasa visual garis pemisah antar
           section. Sekarang section penutup sudah gelap penuh dan
           warna akhirnya sama persis dengan warna awal footer, jadi
           yang di sini bukan lagi transisi antara dua bagian --
           yang di sini adalah kelanjutan satu bidang. Garis terang
           di tepi atas akan memotong tepat bidang itu, dan
           terbaca sebagai sambungan yang belum selesai, padahal
           tidak ada yang sedang disambung.

           Aturannya dihapus, bukan diturunkan. Pada latar yang sama,
           garis setebal 1px hanya jadiartefak, bukan batas. */
        .foot-top {
            display: grid;
            gap: 40px 44px;
            grid-template-columns: minmax(min(280px, 100%), 1.5fr) 1fr 1fr 1.15fr;
            padding-bottom: 40px;
            border-bottom: 1px solid rgb(148 163 184 / .16);
        }

        /* ---------- kolom brand ---------- */
        .foot-brand { display: flex; flex-direction: column; gap: 16px; }

        .foot-brand .brand { color: #fff; align-self: flex-start; }
        .foot-brand .brand small { color: rgb(186 230 253 / .62); }
        .foot-brand .brand:hover .dot { transform: rotate(-8deg) scale(1.08); }

        .foot-about {
            margin: 0;
            max-width: 38ch;
            font-size: 14px; line-height: 1.68;
            color: rgb(186 230 253 / .72);
        }

        /* Janji privasi: bukan kalimat kosong, tapi poin yang memang
           dijawab sistem (lihat kartu fitur "Data Pribadi"). */
        .foot-note {
            display: inline-flex; align-items: flex-start; gap: 9px;
            margin: 0;
            font-size: 13px; line-height: 1.55;
            color: rgb(186 230 253 / .60);
        }
        .foot-note svg {
            width: 16px; height: 16px; flex: none; margin-top: 1px;
            fill: none; stroke: var(--emerald-400);
            /* 2.4, bukan 2: pada 16px, 2 hanya menghasilkan ~1.33px
               goresan efektif -- di bawah batas Anti-Aliasing. Lihat
               catatan panjang di `.mock__ico svg`. */
            stroke-width: 2.4;
            stroke-linecap: round; stroke-linejoin: round;
        }

        /* ---------- kolom tautan ---------- */
        .foot-col h4 {
            margin: 0 0 16px;
            font-size: 11.5px; font-weight: 700;
            letter-spacing: .09em; text-transform: uppercase;
            color: rgb(125 211 252 / .70);
        }

        .foot-col ul { margin: 0; padding: 0; list-style: none; }

        .foot-col li + li { margin-top: 11px; }

        .foot-col a {
            display: inline-flex; align-items: center; gap: 8px;
            color: rgb(224 242 254 / .82);
            text-decoration: none;
            font-size: 14px; line-height: 1.5;
            transition: color .2s var(--ease), transform .2s var(--ease);
        }
        .foot-col a:hover {
            color: #fff;
            transform: translateX(3px);
        }

        /* Ikon di dalam link kolom. Selector-nya `.foot-col a svg`,
           bukan class per link: daftar ikon di satu kolom akan
           selalu menunjukkan hal yang sama -- setiap link di
           dalam kolom adalah kontak -- jadi menulisnya per link
           hanya menambah satu tempat yang harus dijaga sinkron. */
        .foot-col a svg {
            width: 15px; height: 15px; flex: none;
            fill: none; stroke: currentColor;
            /* 2.6, bukan 1.9: pada 15px, 1.9 hanya menghasilkan ~1.19px
               goresan efektif -- di bawah batas Anti-Aliasing. Lihat
               catatan panjang di `.mock__ico svg`. */
            stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round;
            opacity: .72;
            transition: opacity .2s var(--ease);
        }
        .foot-col a:hover svg { opacity: 1; }
        .foot-col a svg.i-solid { fill: currentColor; stroke: none; }

        /* ---------- kolom CTA mini ----------

           Di dalam footer gelap, tombol dibuat terang: `.btn-primary`
           biru medium di atas dasar biru tua kontrasnya tidak cukup
           untuk teks putih. */
        .foot-cta {
            margin: 0;
            padding: 18px 18px 20px;
            border: 1px solid rgb(125 211 252 / .20);
            border-radius: var(--r-lg);
            background: rgb(255 255 255 / .045);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }
        .foot-cta h4 {
            margin: 0 0 6px;
            font-size: 15px; font-weight: 700; letter-spacing: -.015em;
            color: #fff;
            text-transform: none;
        }
        .foot-cta p {
            margin: 0 0 15px;
            font-size: 13px; line-height: 1.6;
            color: rgb(186 230 253 / .70);
        }
        .foot-cta .btn {
            width: 100%;
            background: linear-gradient(135deg, var(--sky-400), var(--sky-600));
            color: var(--sky-950);
            box-shadow: 0 6px 18px -8px rgb(56 189 248 / .55);
            font-size: 14px;
            padding: 11px 18px;
        }
        .foot-cta .btn:hover { transform: translateY(-1px); }
        .foot-cta .btn::after { border-radius: var(--r); }

        /* Baris "sudah punya akun": pintu masuk kedua, ditulis
           eksplisit di footer -- bukan hanya tautan di navbar yang
           selalu terlihat di layar atas.

           `white-space: nowrap` pada label menjaga "Sudah punya akun?"
           tidak terpotong di tengah di layar sempit. */
        .foot-login {
            display: inline-flex; align-items: center; gap: 9px;
            margin-top: 16px;
            padding: 10px 15px;
            border-radius: var(--r);
            border: 1px solid rgb(125 211 252 / .28);
            background: rgb(255 255 255 / .04);
            color: #fff;
            text-decoration: none;
            font-size: 13.5px; font-weight: 600;
            transition: background .25s var(--ease), border-color .25s var(--ease),
                        transform .2s var(--ease), box-shadow .28s var(--ease);
        }
        .foot-login:hover {
            background: rgb(255 255 255 / .10);
            border-color: rgb(125 211 252 / .55);
            transform: translateY(-1px);
            box-shadow: 0 8px 20px -8px rgb(2 8 20 / .6);
        }
        .foot-login svg {
            width: 15px; height: 15px; flex: none;
            fill: none; stroke: currentColor;
            /* 2.6, bukan 1.9: ~1.63px goresan efektif, bukan ~1.19px
               yang tinggal jadi kabut abu. Lihat `.mock__ico svg`. */
            stroke-width: 2.6; stroke-linecap: round; stroke-linejoin: round;
        }
        .foot-login span { white-space: nowrap; }

        /* ---------- baris bawah ---------- */
        .foot-bottom {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 20px;
            padding-top: 26px;
        }

        .foot-bottom p {
            margin: 0;
            font-size: 13px;
            color: rgb(186 230 253 / .55);
        }
        .foot-bottom strong { color: rgb(224 242 254 / .85); font-weight: 600; }

        .foot-bottom a {
            color: rgb(186 230 253 / .70);
            text-decoration: none;
            transition: color .2s var(--ease);
        }
        .foot-bottom a:hover { color: #fff; text-decoration: underline; text-underline-offset: 3px; }

        /* Versi di baris bawah: dibuat minimal, tanpa titik dan
           tanpa warna -- ini informasi teknis, bukan bagian dari
           bahasa visual badge di navbar. */
        .foot-ver {
            margin-left: auto;
            font-variant-numeric: tabular-nums;
        }

        /* ---------- responsif ---------- */
        @media (max-width: 900px) {
            .hero { padding: 28px 0 12px; }

            /* Panel kehilangan radius sebesar desktop -- di layar
               sempit, radius 34px memakan hampir seluruh lebar dan
               Tepi melengkungnya tidak terbaca lagi. */
            .hero-panel { border-radius: 24px; padding: 44px 32px; }

            .hero-inner { grid-template-columns: 1fr; gap: 52px; }

            /* Di satu kolom, mockup tidak boleh lagi menindih tepi
               kanan: `margin-right` negatifnya dinolkan supaya HP
               berada di tengah dan tidak keluar dari panel. */
            .phone { margin-right: 0; }
            .art-card { right: 2%; width: 72%; }

            /* Grid pratinjau jadi satu kolom. Mockup tetap di bawah
               teks (bukan sebaliknya) karena di layar sempit urutan
               baca yang wajar: apa yang dijanjikan dulu, baru bukti. */
            .preview { padding: 44px 0 38px; }
            .preview-grid { grid-template-columns: 1fr; gap: 40px; }
            .preview-copy > p { max-width: none; }

            /* Tiga kartu langkah jadi satu kolom.

               INI YANG SEBELUMNYA HILANG, dan akibatnya halaman bisa
               bergeser ke samping di HP.

               `.steps` tetap `repeat(3, 1fr)` sampai lebar layar turun
               ke sini. Di 390px tiap kolom hanya mendapat ~163px:
               kartu ketiga mulai di x=387 dan berakhir di x=550,
               padahal viewport berhenti di 390. `html` punya
               `overflow-x: hidden` jadi scrollbar-nya disembunyikan,
               tetapi dokumen tetap selebar 550px -- teks di luar layar
               tidak terbaca dan tidak ada cara menggulir ke sana karena
               overflow-nya dipotong.

               Satu kolom di 900px (bukan hanya di 520px seperti bagian
               mobile lain) karena tiga kartu sudah tidak terbaca sejak
               lebar tablet: paragrafnya jadi lima baris sempit di
               dalam kartu. */
            .steps { grid-template-columns: 1fr; gap: 20px; }

            /* Garis penghubung antar langkah kehilangan artinya begitu
               kartu tidak lagi bersebelahan -- tidak ada celah yang
               harus disambung. */
            .step:not(:last-child)::after { display: none; }

            /* Navbar berubah jadi panel dropdown.

               `position: absolute` relatif ke `.navbar` (yang sudah
               `position: relative`) supaya panel menempel tepat di
               bawah bar -- posisi `fixed` harus menghitung tinggi
               header sendiri dan akan meleset kalau tinggi header
               berubah. */
            .nav-toggle { display: block; }

            .nav {
                /* Panel default tersembunyi. `visibility` dipakai
                   bersama `opacity` supaya transisi tetap jalan dan
                   link di dalamnya TIDAK bisa difokus saat tertutup --
                   `display: none` akan mematikan transisi.

                   `inset` men-SEJARAKKAN tepi kiri dan kanan sekaligus.
                   Menulis `right: 0` saja membuat lebar panel ikut
                   `margin-left: auto` yang diwarisi dari aturan desktop
                   -- hasilnya panel setipis tombolnya. */
                position: absolute;
                inset: calc(100% + 8px) 24px auto 24px;
                width: auto;
                margin-left: 0;

                flex-direction: column;
                align-items: stretch;
                gap: 2px;
                padding: 10px;

                /* Background hampir sepenuhnya opaque. Header memakai
                   backdrop-filter yang transparan, dan panel yang ikut
                   transparan membuat teks hero mengintip dari belakang --
                   label jadi sulit dibaca saat ada teks gelap di bawahnya. */
                background: rgb(255 255 255 / .98);
                backdrop-filter: saturate(180%) blur(20px);
                -webkit-backdrop-filter: saturate(180%) blur(20px);
                border: 1px solid var(--line);
                border-radius: var(--r-lg);
                box-shadow: var(--sh-lg);

                opacity: 0;
                visibility: hidden;
                transform: translateY(-8px);
                transition: opacity .28s var(--ease), transform .28s var(--ease),
                            visibility .28s var(--ease);
            }
            .nav.is-open { opacity: 1; visibility: visible; transform: none; }

            /* Garis bawah penanda tidak lagi berguna di panel
               vertikal -- tautan sudah satu baris penuh. */
            .nav a.navlink::after { display: none; }
            .nav a.navlink {
                padding: 12px 14px;
                font-size: 15px;
            }
            .nav a.navlink.is-active { box-shadow: inset 3px 0 0 var(--sky-500); }

            /* Ikon di panel vertikal sedikit lebih besar dan kotak
               warnanya selalu terlihat.

               Di panel dropdown, link sudah jadi baris penuh yang
               lebar -- ikon yang transparan ikut tenggelam di lebar
               itu dan link jadi terlihat seperti teks biasa dengan
               kata yang sama. Memberi latar pada kotaknya membuat
               ikon terbaca sebagai penanda baris, dan ukuran 18px
               cukup untuk tidak terlihat sempit di panel yang
               tinggi. */
            .navlink .navico { width: 34px; height: 34px; background: var(--sky-50); }
            .navlink .navico svg { width: 18px; height: 18px; }
            .nav a.navlink .navico { color: var(--sky-600); }
            .nav a.navlink:hover .navico,
            .nav a.navlink.is-active .navico { color: var(--sky-700); background: #fff; }

            .nav .btn { width: 100%; margin-top: 6px; padding: 12px; }

            /* Tombol Masuk jadi tombol penuh, jadi bentuk pilnya
               dibulatkan penuh. Badge versi ikut keluar dari tepi
               kanan, dan di panel yang `inset`-nya 18px dari tepi
               layar ada ruang untuk itu.

               Badge versi TIDAK dianimasikan di sini. Delay
               `ver-grow` disetel untuk halaman yang dimuat dari
               atas; panel ini baru muncul setelah diklik, jadi
               badge yang ikut tumbuh dengan delay yang sama akan
               selesai menampilkannya sekitar waktu orang sudah
               menekan tombol -- tombol yang badge-nya belum jadi
               terlihat seperti tombol yang belum ter-render. */
            .nav .btn-login { border-radius: 999px; justify-content: center; }
            .nav .ver-badge { animation: none; }

            /* Panel terbuka: kunci scroll halaman di belakang.
               Tanpa ini, sentuh di panel yang lebih pendek dari
               viewport tetap ikut menggulir halaman di belakangnya. */
            html.nav-open { overflow: hidden; }

            /* ---------- footer di layar sempit ----------

               Dua kolom dulu, baru satu.

               Dua kolom bukan untuk hemat ruang saja: kolom
               "Kontak" adalah yang paling sering dicari orang di
               footer, dan menyatunya ke bawah brand -- yang
               panjang -- akan mendorong kontak melewati satu layar
               penuh di ponsel. Dengan dua kolom, kontak ada di
               baris pertama.

               Kolom CTA disembunyikan, bukan dipindah ke bawah.
               Tombol "Minta Demo Gratis" sudah ada di hero dan di
               blok penutup tepat di atas footer; mengulangnya
               sekali lagi di layar 390px hanya menambah tinggi
               halaman tanpa menambah pilihan baru. */
            .foot-top {
                grid-template-columns: 1fr 1fr;
                gap: 32px 28px;
                padding-bottom: 32px;
            }
            .foot-brand { grid-column: 1 / -1; }
            .foot-about { max-width: none; }
            .foot-cta { display: none; }

            footer { padding: 46px 0 28px; }
        }

        @media (max-width: 520px) {
            .wrap { padding-inline: 18px; }
            section { padding: 44px 0; }
            /* Padding simetris, sama seperti di desktop. Yang bawah
               tidak lagi diberi ruang ekstra: panel ini sudah
               berdiri sendiri di atas latar gelap, dan ruang
               tambahan hanya membuat isinya terlihat melayang. */
            .closer { padding: 38px 20px; }
            .card, .step { padding: 22px 20px; }
            .step::before { top: 18px; right: 18px; width: 34px; height: 34px; font-size: 14px; }
            .step h3 { padding-right: 44px; }

            /* Panel menu ikut menyempit agar sejajar dengan teks. */
            .nav { inset: calc(100% + 8px) 18px auto 18px; }

            /* Panel hero makin rapat, dan mockup HP ikut mengecil
               supaya bingkai gelapnya tidak melebihi lebar layar. */
            .hero-panel { border-radius: 20px; padding: 34px 22px; }
            /* `min()` yang sama seperti di rule dasar: batas atasnya
               diturunkan ke 240px, tapi kolom yang lebih sempit masih
               boleh mengambil alih lewat `100%`. */
            .phone { width: min(240px, 100%); }
            .art-card { padding: 20px 18px; right: 0; width: 76%; }
            .art-card strong { font-size: 15px; }
            .art-card span { font-size: 12px; }

            /* Dua kolom jadi satu di layar sempit: angka 22px dalam
               setengah lebar 390px akan terpotong. */
            .mock__body { grid-template-columns: 1fr; padding: 14px; }
            .mock__value { font-size: 19px; }

            /* Tombol ke-atas sedikit lebih kecil supaya tidak menutupi
               isi di layar 360px. */
            .to-top { width: 42px; height: 42px; }

            /* Footer jadi satu kolom penuh di bawah 520px.

               Di 520px ke bawah, dua kolom menyisakan tiap kolom hanya
               ~170px -- cukup untuk satu kata, tapi tidak untuk
               "+62 882-0066-44656" yang tidak boleh patah di tengah.
               Memakai satu kolom membuat nomor tetap utuh. */
            .foot-top { grid-template-columns: 1fr; gap: 28px; }
            .foot-bottom { flex-direction: column; align-items: flex-start; gap: 6px; }

            /* Versi pindah ke atas copyright, dan `margin-left: auto`
               ikut dimatikan: di kolom yang sudah `flex-start`, auto
               margin masih bisa mendorongnya ke tepi kanan dan
               membuatnya terlihat seperti tombol, bukan teks. */
            .foot-ver { margin-left: 0; order: -1; }
        }

        /* Layar sangat pendek (horizontal, atau jendela kecil):
           panel menu memakan tinggi viewport. Batas tinggi, bukan
           lebar, karena itu yang menentukan ruang yang tersisa. */
        @media (max-height: 520px) {
            .nav { max-height: calc(100vh - 90px); overflow-y: auto; }
        }

        /* ---------- target sentuh di layar sentuh ----------

           Tautan teks di footer (.foot-col a) hanya setinggi ~21px --
           tinggi baris teksnya, karena `inline-flex` tidak menambah
           apa pun tanpa padding eksplisit. Di desktop itu tidak
           masalah (kursor presisi), tapi di HP jari yang menekan sering
           jatuh 1-2px di bawah dan meleset ke baris lain -- beberapa
           tautan kontak hanya berjarak 11px.

           Padding vertikal 10px membuat tinggi setiap tautan jadi
           ~41px, cukup untuk jari, tanpa mengubah jarak antar baris
           yang terlihat (jarak itu datang dari `li + li { margin }`
           yang sudah ada, dikurangi sebesar padding yang ditambahkan).

           Hanya di bawah 900px: di desktop menambah padding hanya
           membuat footer terasa renggang tanpa alasan. */
        @media (max-width: 900px) {
            .foot-col a { padding-block: 10px; }
            /* `li + li` dikurangi dari 11px ke 1px supaya jarak antar
               baris tetap sama seperti sebelumnya. */
            .foot-col li + li { margin-top: 1px; }

            /* Tautan kontak di panel penutup (`.closer`): nomor telepon dan
               email. Tinggi targetnya cuma tinggi baris teks (~23-27px),
               padahal keduanya adalah CTA kedua di halaman ini -- orang
               yang tidak mau isi form demo akan menekan yang ini.

               Padding vertikal 8px+jeda label (`gap: 6px`) membuat
               tiap target ~39px tanpa mengubah jarak antar baris yang
               terlihat: `.kontak-alt` memusatkan semua dengan
               `justify-content: center`, jadi menambah tinggi hanya
               menambah ruang di atas dan bawah, bukan menggeser
               posisi relatif antar item. */
            .kontak-alt a, .kontak-email a { padding-block: 8px; }

            /* Brand di navbar (link ke atas halaman) dan brand di footer. Keduanya
               tautan teks tanpa aksi selain navigasi ke atas, tapi tetap
               target sentuh: `display: flex` sudah bikin isinya satu
               blok, jadi padding hanya menambah ruang di sekeliling. */
            .navbar .brand, .foot-brand .brand { padding-block: 4px; }

            /* Tautan di dalam paragraf mockup ("masuk ke panel pusat").
               Link inline (display inline) di dalam kalimat, jadi
               `padding-block` tidak menambah tinggi area klik-nya --
               area itu tetap setinggi tinggi huruf. Padding vertikal
               hanya menambah ruang kosong yang tetap tidak bisa diklik.

               Supaya targetnya benar-benar bisa ditekan, link dibuat
               `inline-block`: barisunya sendiri jadi kotak, dan
               padding vertikal ikut masuk ke dalamnya. Paddingnya
               dijaga 3px supaya tinggi mockup tidak bergeser -- blok
               ini sengaja dibuat rapat. */
            .mock__foot a { display: inline-block; padding-block: 3px; }
        }

        /* Aksesibilitas: matikan semua gerak kalau pengguna memintanya.

           `animation: none` (!important) penting -- hanya mematikan
           transition tidak cukup, karena loop tak berujung (glow, pulse)
           tetap berjalan dan terus membakar CPU/baterai. */
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }

            *, *::before, *::after {
                animation-duration: .001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .001ms !important;
            }

            .reveal { opacity: 1 !important; transform: none !important; }
            .closer::after, .hero-panel::after { animation: none; }

            /* Badge versi: matikan animasi masuknya.

               `animation: none` (bukan hanya `animation-duration`)
               karena `ver-grow` memakai fill-mode `backwards` --
               kalau hanya durasinya dibuat ~0, frame `from`
               (`opacity: 0`) masih sempat dipakai sesaat sebelum
               animasi dianggap selesai. `animation: none`
               mengembalikan badge ke gaya dasarnya apa adanya. */
            .ver-badge { animation: none !important; }

            /* Perangkat mockup: tanpa JS yang memasang `.is-in`,
               `opacity: 0` di gaya dasarnya akan membuat HP dan kartu
               hilang permanen. Jadi state akhir ditulis eksplisit di
               sini, bukan hasil dari observer. */
            .phone, .art-card {
                opacity: 1 !important;
                transition: none !important;
                /* `--ty` ditulis langsung ke 0, bukan lewat transisi
                   yang sudah dimatikan baris di atas: state akhir harus
                   berdiri sendiri, bukan hasil dari animasi yang tidak
                   jalan. */
                --ty: 0px !important;
                /* Ngambangnya ikut dihentikan. Rule `*` di atas sudah
                   membuat durasinya .001ms dan iterasinya 1, jadi
                   tanpa `animation: none` elemen tetap menulis frame
                   akhir `ngambang-*` -- dan frame akhir itu bukan
                   kondisi netral, melainkan posisi ayunan. */
                animation: none !important;
            }

            /* Kartu perlu `animation: none`, bukan cuma durasi ~0.

               `card-in` berakhir dengan `forwards` dan `from` punya
               `opacity: 0`. Durasi .001ms membuat animasi itu SELESAI
               dalam sekejap -- tapi tetap menuliskan frame akhir, dan
               frame akhir kartu memang opacity 1, jadi kebetulan aman.

               Namun `fade` pada .ico/.h3/.p pakai `both` sebagai
               fill-mode, yang menyalin frame `from` ke SEBELUM animasi
               dimulai. Kalau user membuka halaman langsung di section
               fitur (lewati anchor #fitur, atau restore scroll browser),
               animasi sudah lewat -- fill-mode:none tidak reset, tapi
               `animation: none` memaksa elemen kembali ke gaya
               dasarnya tanpa perlu menebak urutan waktu. */
            .grid .card.reveal.shown,
            .card.reveal.shown .ico,
            .card.reveal.shown h3,
            .card.reveal.shown p { animation: none !important; }

            /* Cahaya dan garis accent ikut hilang -- bersama transisi
               di atas sudah otomatis, tapi pseudo-element perlu
               state akhir yang eksplisit supaya kartu tidak terlihat
               seperti belum di-hover dalam mode ini. */
            .card::after { opacity: 0; }
            .card::before { transform: scaleX(1); opacity: .5; }

            /* Grafik garis: matikan animasi gambar dan titik.
            `deck-open`/`deck-pop` sama-sama pakai fill-mode
            `both`, jadi sebelum delay-nya habis isian dan titiknya
            belum terlihat. Dengan jeda 0.1-1.08s, ada detik lebih
            saat grafik tampil hampir tanpa garis.

            TITIK DAN GRAFIK BUTUH NILAWAN AKHIR EKSPLISIT.
            Mematikan animasi saja tidak cukup, karena gaya
            dasarnya memang "kosong":

            1. `.deck__svg` punya `clip-path: inset(0 100% 0 0)`
            -- seluruhnya terpotong. Kalau tidak ditulis ulang,
            garis DAN isian hilang total.
            2. Titik punya `opacity: 0`. Kalau tidak ditulis ulang,
            keenamnya hilang total.

            Dua-duanya harus ditulis di sini, karena `animation:
            none` hanya mengembalikan elemen ke gaya dasarnya --
            dan gaya itulah yang membuatnya kosong. Aturan `*` di
            atas hanya menyentuh `animation-duration`, jadi tidak
            menutupi cases ini. */
            .deck__svg,
            .deck__fill,
            .deck__pts i { animation: none !important; }
            .deck__svg { clip-path: none; }
            .deck__fill { opacity: 1; }
            .deck__pts i { opacity: 1; }

            /* Kartu dashboard: matikan tilt DAN pegasnya.

               Dua hal harus dimatikan, dan satu saja tidak cukup:

               1. `.mock.reveal.shown` masih perlu `rise-mockup` --
                  kalau ikut dihapus, opacity-nya balik ke 0 (nilai
                  dasarnya) dan kartu hilang total, karena tidak ada
                  observer yang akan memasang `.shown` lagi.
               2. Pegas saat lepas harus berhenti. Kalau tidak, kartu
                  akan mengayun sendirian terus-menerus untuk pengguna
                  yang justru meminta tidak ada gerakan sama sekali.

               Tilt sendiri tidak perlu ditulis eksplisit: `--mx`/`--my`
               tidak ditransisi dan tidak dianimasikan selama hover,
               jadi tanpa handler JS (yang memang tidak dipasang di mode
               ini) keduanya tetap di nilai awal 0deg. Yang dimatikan di
               sini adalah `transition` dan `will-change` supaya tidak
               ada sia-sia sumber daya saat kursor lewat. */
            .mock.reveal.shown { animation: rise-mockup .001ms var(--ease) forwards; }
            .mock.spring-back   { animation: none !important; }
            .mock { transition: none !important; will-change: auto; }
        }
    </style>
</head>

<body>
    {{--
        Sprite ikon SVG.

        Kenapa sprite dan bukan emoji: emoji dirender oleh font milik
        sistem operasi, jadi bentuk dan warnanya beda di tiap perangkat --
        Windows (Segoe UI Emoji) tidak sama dengan Mac (Apple Color Emoji)
        atau Android (Noto). Sebagian bahkan tampil sebagai kotak kosong
        kalau fontnya tidak ada. Halaman ini tidak punya build step, jadi
        tidak bisa mengandalkan icon font seperti Font Awesome.

        SVG stabil di semua platform: bentuknya ditentukan file ini,
        bukan font yang kebetulan terpasang, dan warnanya mengikuti
        `currentColor` sehingga otomatis ikut berubah saat hover.

        Bentuk outline (isi `none`, `stroke` saja) dipilih supaya
        konsisten dengan kartu putih dan tetap ramping di layar kecil.

        Letak di awal `<body>`, bukan akhir: `<use>` yang menunjuk simbol
        yang belum ter-parse akan menggambar apa pun atau tidak sama
        sekali. Di awal body, sprite sudah ada sebelum kartu mana pun
        dirender.

        `display: none` membuat browser modern melewati seluruh subtree
        ini, termasuk saat menghitung ulang accessibility tree.
    --}}
    <svg width="0" height="0" aria-hidden="true" focusable="false"
         style="position:absolute;display:none" xmlns="http://www.w3.org/2000/svg">
        <defs>
            {{-- Air minum: tetes air dengan garis di tengahnya. --}}
            <symbol id="i-water" viewBox="0 0 24 24">
                <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                <path d="M12 7v10"/>
            </symbol>

            {{-- Internet &amp; TV: monitor dengan antena di atasnya.

                 Gelombang antenanya dua busur dengan titik tengah sama
                 (x=12) dan radius bertingkat, jadi tiap busur terpisah
                 jelas dari yang di atasnya.

                 Semula tiap gelombang adalah busur penuh yang membentang
                 hampir selebar monitor di bawahnya. Dua bentuk sebesar
                 itu saling menindih, dan yang terbaca dari hasilnya
                 hanya garis-basanya. --}}
            <symbol id="i-internet" viewBox="0 0 24 24">
                <path d="M7.4 10.6a6.5 6.5 0 0 1 9.2 0"/>
                <path d="M4.2 7.6a11 11 0 0 1 15.6 0"/>
                <circle cx="12" cy="14.2" r="1.1"/>
                <rect x="2.5" y="16.5" width="19" height="5" rx="1.8"/>
            </symbol>

            {{-- Pembayaran sampah: lorry. --}}
            <symbol id="i-truck" viewBox="0 0 24 24">
                <path d="M10 17h4V5H2v12h3"/>
                <path d="M20 17h2v-3.34a4 4 0 0 0-1.17-2.83L19 9h-5v8"/>
                <circle cx="7.5" cy="17.5" r="2.5"/>
                <circle cx="17.5" cy="17.5" r="2.5"/>
            </symbol>

            {{-- Data pelanggan: dua orang. --}}
            <symbol id="i-users" viewBox="0 0 24 24">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </symbol>

            {{-- Keuangan: grafik batang.

                 Semula hanya tiga garis vertikal tanpa konteks apa pun --
                 tidak ada sumbu, tidak ada label, tidak ada bentuk yang
                 bisa dijadikan acuan. Di ukuran 23px bentuk itu hanya
                 terbaca sebagai tiga goresan.

                 Garis dasar dan dua titik acuan sekarang memberi awal
                 dan akhir, jadi kemiringan garis terbaca sebagai "naik atau
                 turun". Bentuk batang yang lama tidak cocok lagi dengan
                 grafik yang dipakai di bawah, dan ikon yang tidak cocok
                 membuat label dan isinya terbaca sebagai dua hal berbeda. --}}
            <symbol id="i-chart" viewBox="0 0 24 24">
                <path d="M4 20h16"/>
                <path d="M4.6 16.4 9 11.6l3.6 2.8 6.2-8"/>
            </symbol>

            {{-- Laporan: dokumen dengan garis teks. --}}
            <symbol id="i-report" viewBox="0 0 24 24">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <path d="M14 2v6h6"/>
                <path d="M16 13H8"/>
                <path d="M16 17H8"/>
                <path d="M10 9H8"/>
            </symbol>

            {{-- Data terpisah: kunci. --}}
            <symbol id="i-lock" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </symbol>

            {{-- Mockup: garis naik. --}}
            <symbol id="i-trend-up" viewBox="0 0 24 24">
                <path d="M22 7l-8.5 8.5-5-5L2 17"/>
                <path d="M16 7h6v6"/>
            </symbol>

            {{-- Mockup: garis turun. --}}
            <symbol id="i-trend-down" viewBox="0 0 24 24">
                <path d="M22 17l-8.5-8.5-5 5L2 7"/>
                <path d="M16 17h6v-6"/>
            </symbol>

            {{-- Mockup: satu orang untuk pelanggan aktif. --}}
            <symbol id="i-user" viewBox="0 0 24 24">
                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </symbol>

            {{-- Mockup: dokumen bertumpuk untuk daftar laporan. --}}
            <symbol id="i-docs" viewBox="0 0 24 24">
                <path d="M8 3H5a2 2 0 0 0-2 2v3"/>
                <path d="M16 3h3a2 2 0 0 1 2 2v3"/>
                <path d="M8 21H5a2 2 0 0 1-2-2v-3"/>
                <path d="M16 21h3a2 2 0 0 0 2-2v-3"/>
                <path d="M9 8h6"/>
                <path d="M9 12h6"/>
                <path d="M9 16h3"/>
            </symbol>

            {{-- Catatan hero: lingkaran dengan centang.

                 Semula diamond minus (&#9432;). Centang lebih tepat karena
                 isinya janji, bukan peringatan. --}}
            <symbol id="i-check-circle" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="9"/>
                <path d="M8.5 12.2l2.4 2.4 4.6-4.9"/>
            </symbol>

            {{-- Kontak: WhatsApp, telepon, email.

                 Ketiganya digambar dengan mode garis, sama seperti symbol
                 lain.

                 WhatsApp bedanya: lambang-brand, bukan ikon garis.

                 SEBELUMNYA lambang ini punya DUA `<path>` terpisah: siluet
                 gelembung dan handset di dalamnya. Di mode garis
                 (`fill: none`) hasilnya benar -- gelembung digores, dan
                 handset jadi lubang di dalamnya karena siluetnya sendiri
                 yang digores.

                 Tapi di mode SOLID (`.i-solid`, dipakai di tombol
                 "Minta Demo Gratis") keduanya dapat `fill: currentColor`
                 yang SAMA. Handset tidak lagi jadi lubang: ia terisi
                 warna yang sama dengan gelembung, jadi keduanya menyatu
                 jadi satu gumpalan bulat. Tombolnya tampil dengan ikon
                 yang terbaca sebagai Titik Hitam, bukan logo WhatsApp.

                 Perbaikannya: gabung jadi SATU `<path>` dengan
                 `fill-rule="evenodd"`. Dalam satu path, area yang
                 digambar dua kali tetap kosong -- jadi handset jadi
                 lubang otomatis, tanpa perlu warna kedua.

                 Efeknya satu jalur berlaku untuk dua mode:
                   - mode garis  -> gelembung digores, handset jadi lubang
                   - mode solid  -> gelembung penuh, handset jadi lubang

                 Dan `.i-solid` tetap dibutuhkan: tanpa `fill`, mode
                 solid hanya akan menghasilkan garis, bukan bentuk
                 penuh. --}}
            <symbol id="i-wa" viewBox="0 0 24 24">
                <path fill-rule="evenodd" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.521.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/>
            </symbol>

            <symbol id="i-telepon" viewBox="0 0 24 24">
                <path d="M21 16.9v2.5a1.9 1.9 0 0 1-2.1 1.9 18.8 18.8 0 0 1-8.2-2.9 18.5 18.5 0 0 1-5.7-5.7A18.8 18.8 0 0 1 2.1 4.4 1.9 1.9 0 0 1 4 2.3h2.5a1.9 1.9 0 0 1 1.9 1.6c.12.9.34 1.8.65 2.64a1.9 1.9 0 0 1-.43 2L7.5 9.7a15 15 0 0 0 5.7 5.7l1.16-1.16a1.9 1.9 0 0 1 2-.43c.84.31 1.73.53 2.64.65A1.9 1.9 0 0 1 21 16.9z"/>
            </symbol>

            <symbol id="i-email" viewBox="0 0 24 24">
                <rect x="2" y="4.5" width="20" height="15" rx="2.2"/>
                <path d="M2.8 6.6L12 13l9.2-6.4"/>
            </symbol>

            {{--
                Ikon navbar.

                Ditambahkan sebagai simbol terpisah, bukan simbol
                yang dipakai ulang dari kartu fitur. Alasannya
                tugasnya berbeda: ikon navbar berdiri sendiri di
                sebelah kiri teks 14.5px, jadi harus ringan dan
                punya massanya sendiri. Ikon fitur sudah tampil
                di dalam kotak 46px -- dipakai langsung di navbar
                ukurannya jadi terlalu berat dan terlihat seperti
                label yang dikecilkan, bukan ikon navigasi.

                Semua tetap gaya garis dan `currentColor`, jadi
                warnanya mengikuti state link (abu diam, biru saat
                hover, biru tua saat section aktif) tanpa satu pun
                selector warna tambahan.
            --}}
            <symbol id="i-nav-fitur" viewBox="0 0 24 24">
                <rect x="3" y="3" width="7.5" height="7.5" rx="2"/>
                <rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/>
                <rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/>
                <rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2"/>
            </symbol>

            {{-- Cara kerja: dua panah berputar, satu putaran. --}}
            <symbol id="i-nav-alur" viewBox="0 0 24 24">
                <path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/>
                <path d="M20.5 4v4.6h-4.6"/>
            </symbol>

            {{-- Harga: label dagang, bukan kalkulator.

                 Label lebih tepat untuk halaman yang menawarkan
                 paket berbayar: bentuknya langsung dibaca sebagai
                 "berapa biayanya", sementara kalkulator terbaca
                 sebagai alat hitung. --}}
            <symbol id="i-nav-harga" viewBox="0 0 24 24">
                <path d="M20.6 11.4 11.9 20a2 2 0 0 1-2.8 0l-5-5a2 2 0 0 1-.6-1.4V4.6a1.6 1.6 0 0 1 1.6-1.6h9a2 2 0 0 1 1.4.6l5.1 5.1a2 2 0 0 1 0 2.7z"/>
                <circle cx="7.6" cy="8.1" r="1.3"/>
                <path d="M11.4 8.1h5"/>
                <path d="M11.4 12.2h5"/>
                <path d="M7.6 12.2h.01"/>
                <path d="M7.6 16.3h.01"/>
                <path d="M11.4 16.3h5"/>
            </symbol>

            {{-- Pengguna: Beberapa orang dalam satu kerangka.

                 Yang dibuat di sini BUKAN lima users atau tangan
                 menggenggam. Ukuran yang dipakai di navbar adalah 17px
                 dengan `stroke-width: 1.8` -- di ukuran itu, lima
                 kepala sekaligus berubah menjadi gumpalan titik yang
                 tidak terbaca sebagai apa pun.

                 Yang lebih terbaca di ukuran kecil adalah bentuk dengan
                 satu kepala dan satu siluet di sebelahnya: "ada orang
                 lain di sini". Itu persis yang section ini tawarkan --
                 bukan direktori, bukan foto, dan tidak pernah memuat
                 nama orang sungguhan di dalamnya (lihat catatan
                 panjang di section `#pengguna`). --}}
            <symbol id="i-nav-pengguna" viewBox="0 0 24 24">
                <path d="M16.5 20v-1.8a3.4 3.4 0 0 0-3.4-3.4H6.9a3.4 3.4 0 0 0-3.4 3.4V20"/>
                <circle cx="10" cy="7.4" r="3.6"/>
                <path d="M17.4 4.2a3.6 3.6 0 0 1 0 6.4"/>
                <path d="M20.5 20v-1.8a3.4 3.4 0 0 0-2.6-3.3"/>
            </symbol>

            {{-- Masuk: kunci + panah masuk, dipisah dari `i-lock`
                 yang dipakai kartu fitur "Data Pribadi".

                 Kalau `i-lock` yang dipakai, tombol Masuk akan
                 terbaca sebagai "kunci data" -- dua arti berbeda
                 untuk ikon yang sama di satu halaman. Ditambah
                 garis panah yang menembus kunci supaya bentuknya
                 langsung berarti "masuk ke dalam", bukan sekadar
                 "terkunci". --}}
            <symbol id="i-login" viewBox="0 0 24 24">
                <path d="M14.4 4.2h3.1a2 2 0 0 1 2 2v11.6a2 2 0 0 1-2 2h-3.1"/>
                <path d="M9.7 16.4 14.2 12 9.7 7.6"/>
                <path d="M14.2 12H4.4"/>
            </symbol>

            {{-- Footer: tautan luar (panah keluar kotak). --}}
            <symbol id="i-external" viewBox="0 0 24 24">
                <path d="M13.4 4.6h6v6"/>
                <path d="M19.4 4.6 11 13"/>
                <path d="M18.2 14.4v3.9a2 2 0 0 1-2 2H5.8a2 2 0 0 1-2-2V7.9a2 2 0 0 1 2-2h3.9"/>
            </symbol>

            {{-- Footer: perisai data, untuk janji privasi usaha. --}}
            <symbol id="i-shield" viewBox="0 0 24 24">
                <path d="M12 3.1 20 6.4v5.9c0 4.6-3.2 7.9-8 9.4-4.8-1.5-8-4.8-8-9.4V6.4z"/>
                <path d="M9 12.2l2.2 2.2 4-4.3"/>
            </symbol>

            {{--
                Section keunggulan: penanda masalah (silang) dan
                penanda jawaban (lingkarancentang).

                Dua ikon ini berpasangan secara sengaja. Kalau keduanya
                memakai bentuk yang mirip, kolom masalah dan kolom
                jawaban jadi terbaca sebagai daftar yang sama dengan
                tanda berbeda -- dan orang akan menganggap kontennya
                berulang. Bentuk yang benar-benar berbeda (garis
                lurus vs lingkaran) membuat perbandingannya terbaca
                sekilas, tanpa harus membaca teksnya.

                `i-x-plain` dipakai sebagai penanda masalah dan bukan
                `i-lock` yang sudah ada: kunci berarti "terkunci",
                bukan "bermasalah", dan maknanya akan menyesatkan di
                baris yang bicara soal data yang tercecer atau
                tunggakan yang terlupa. --}}
            <symbol id="i-x-plain" viewBox="0 0 24 24">
                <path d="M6.5 6.5l11 11"/>
                <path d="M17.5 6.5l-11 11"/>
            </symbol>

            <symbol id="i-check-plain" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="8.6"/>
                <path d="M8.4 12.2l2.5 2.5 4.7-5.1"/>
            </symbol>
        </defs>
    </svg>

    {{--
        URL login panel pusat.

        Di produksi, SPA dilayani dari host yang sama dengan Blade ini, jadi
        path relatif `/central/login` benar. Di development tidak: Blade
        dilayani Apache, sementara SPA-nya hidup di Vite dev server port
        5173. Tanpa prefix host, klik "Masuk" dari landing page berakhir di
        404 -- route yang memang tidak ada di `routes/web.php`.

        Host diambil dari request, bukan dari `frontend_url`.

        Kenapa: `frontend_url` di development bernilai `http://localhost:5173`,
        dan itulah yang membuat panel pusat harus dibuka lewat `localhost`
        walau landing page-nya sendiri sudah dibuka lewat `astaloka.test`.
        Dua URL berbeda untuk satu panel, dan tidak ada yang salah --
        cuma membingungkan saat yang diharapkan USER adalah "saya sudah di
        astaloka.test, kenapa tiba-tiba di localhost?".

        Sama seperti redirect subdomain di `routes/web.php`, sumbernya satu
        sumber kebenaran: `request()->getHost()`. Panel pusat tidak punya
        subdomain, jadi host polos yang dipakai apa adanya.

        `spaPort` diambil dari port `frontend_url` (5173 di development).
        Di produksi `frontend_url` kosong, jadi prefix build-nya kosong dan
        hasilnya path relatif -- tidak ada yang perlu ditulis tangan.
    --}}
    @php
        $spaBase = rtrim((string) config('tenancy.frontend_url'), '/');
        $spaPort = $spaBase ? parse_url($spaBase, PHP_URL_PORT) : null;
        $spaOrigin = $spaPort
            ? request()->getSchemeAndHttpHost() . ':' . $spaPort
            : request()->getSchemeAndHttpHost();

        $centralLoginUrl = $spaBase ? $spaOrigin . '/central/login' : '/central/login';

        // Kontak penjualan ASTALOKA.
        //
        // Ditulis sekali di sini dan dipakai ulang di hero, langkah
        // "Hubungi kami", section penutup, dan footer. Sebelumnya tiap
        // tombol menulis alamatnya sendiri, jadi satu nomor salah ketik
        // harus dibetulkan di beberapa tempat, dan tidak ada yang
        // ingat tempat terakhir.
        //
        // Dua kanal, sesuai cara orang menghubungi usaha kecil:
        // WhatsApp/telepon untuk yang mau bicara langsung, email untuk
        // yang memang butuh dokumen (proposal, daftar fitur, contoh
        // laporan). Satu nomor untuk WA dan telepon: nomor WhatsApp
        // selalu bisa diputar, jadi tidak perlu nomor kedua.
        //
        // Bentuk link:
        // - `wa.me` butuh internasional TANPA "+" dan tanpa spasi.
        // - `tel:` butuh "+62..." agar tetap bisa dipakai dari luar
        //   negeri.
        // - `rawurlencode` untuk teks dan subjek: kalau nama usaha
        //   pemesan mengandung spasi atau "&", query string-nya rusak
        //   dan aplikasi mail membuka pesan yang terpotong.

        $kontakWaAngka = '62882006644656';       // wa.me: tanpa "+"
        $kontakWaTampil = '+62 882-0066-44656';  // yang dilihat manusia
        // tel: +62... (tanpa spasi, tanpa tanda hubung)
        $kontakTelepon = 'tel:+' . $kontakWaAngka;
        $kontakEmail = 'astabratamgl@gmail.com';

        $sapaanDemo = 'Halo Asta Brata Teknologi, saya mau minta demo gratis ASTALOKA untuk usaha saya. Mohon informasinya, terima kasih.';
        $sapaanHarga = 'Halo Asta Brata Teknologi, saya mau tanya soal paket dan harga ASTALOKA. Mohon informasinya, terima kasih.';

        $kontakWaDemo = 'https://wa.me/' . $kontakWaAngka . '?text=' . rawurlencode($sapaanDemo);
        $kontakWaHarga = 'https://wa.me/' . $kontakWaAngka . '?text=' . rawurlencode($sapaanHarga);

        $kontakEmailDemo = 'mailto:' . $kontakEmail . '?subject=' . rawurlencode('Minta Demo ASTALOKA');
        $kontakEmailHarga = 'mailto:' . $kontakEmail . '?subject=' . rawurlencode('Penawaran Harga ASTALOKA');
    @endphp

    <header>
        <div class="wrap navbar">
            <a class="brand" href="/">
                <span class="dot">A</span>
                <span>ASTALOKA<small>oleh Asta Brata Teknologi</small></span>
            </a>

            <button class="nav-toggle" type="button"
                    aria-expanded="false" aria-controls="nav-menu" aria-label="Buka menu">
                <span></span><span></span><span></span>
            </button>

            <nav class="nav" id="nav-menu">
                {{--
                    Ikon per link.

                    Dipilih sesuai isi section yang dituju, bukan asal
                    membagi tiga bentuk berbeda. Link tanpa gambar
                    lebih baik daripada link dengan gambar yang
                    tidak menjelaskan apa pun: ikon tanpa makna
                    hanya menambah dekorasi.

                    Semua ikon disembunyikan dari pembaca layar
                    (`aria-hidden`) -- teks link sudah menyebut
                    tujuan, jadi membacanya dua kali hanya
                    memperpanjang jarak antara jawaban dan
                    pertanyaan yang sama.
                --}}
                <a class="navlink" href="#fitur">
                    <span class="navico"><svg aria-hidden="true"><use href="#i-nav-fitur"/></svg></span>
                    Fitur
                </a>

                {{--
                    Letaknya PAS DI ANTARA fitur dan cara kerja, dan
                    urutannya HARUS sama dengan urutan section di
                    halaman.

                    Alasannya bukan selera: navbar adalah peta halaman.
                    Kalau urutannya berbeda dari isi halaman, orang
                    menekan link untuk section ketiga dan mendarat di
                    section kedua -- lalu mengira dia salah klik.
                    Scroll-spy di bawah juga bergantung pada urutan
                    DOM, jadi navlink yang tidak urut membuat sorotan
                    aktif melompat-lompat.

                    Kenapa section ini ada di posisi itu, bukan di
                    ujung: urutan persuade halaman ini adalah fitur
                    (apa yang ADA), lalu pengguna (ada usaha lain yang
                    sudah memakainya), lalu cara kerja (bagaimana mulai).
                    Di ujung daftar, link ini akan terbaca sebagai
                    pengikut "harga" -- dan orang yang sudah memutuskan
                    mencari harga tidak perlu tahu siapa yang sudah
                    memakai.
                --}}
                <a class="navlink" href="#pengguna">
                    <span class="navico"><svg aria-hidden="true"><use href="#i-nav-pengguna"/></svg></span>
                    Pengguna
                </a>

                <a class="navlink" href="#caranya">
                    <span class="navico"><svg aria-hidden="true"><use href="#i-nav-alur"/></svg></span>
                    Cara Kerja
                </a>

                <a class="navlink" href="#mulai">
                    <span class="navico"><svg aria-hidden="true"><use href="#i-nav-harga"/></svg></span>
                    Harga
                </a>

                {{--
                    "Masuk" menuju /central/login, bukan /login.

                    Dua-duanya halaman login SPA, tapi menyimpan token dengan
                    kunci yang berbeda: panel pusat memakai `central_token`,
                    aplikasi lokasi memakai `auth_token`. Menautkan ke /login
                    dari sini akan mengirim superadmin ke form yang salah, dan
                    kredensial yang ia ketik tidak akan pernah sampai ke
                    endpoint yang mengeluarkannya.

                    Petit di navbar, bukan tombol besar: halaman ini untuk
                    calon pelanggan yang belum punya akses. Yang sudah punya
                    akun datang dari subdomain lokasi mereka sendiri, atau
                    lewat bookmark ke /central/login.

                    Bersaudara dengan tombol "Ajukan Penawaran" yang dihapus
                    dari sini, jadi warna accent-nya ikut naik ke `primary`.
                    Kalau "Masuk" tetap ghost, navbar kehilangan satu-satunya
                    aksen warna dan navlink biasa di sebelahnya terlihat
                    sama pentingnya dengan pintu masuk.

                    Badge "1.0" di sini bukan informasi teknis untuk
                    pengembang. Yang ditunjukkan ke calon pembeli
                    adalah bentuk produk yang sudah jadi dan akan
                    terus dirawat -- kebalikan dari halaman yang
                    terlihat setengah jadi. Angkanya ditulis "1.0",
                    bukan "v1.0", karena huruf "v" tidak menambah
                    apa pun dan bikin badge terlihat seperti
                    nomor versi dari software yang diunduh, bukan
                    produk yang berbisnis.
                --}}
                <a class="btn btn-primary btn-login" href="{{ $centralLoginUrl }}">
                    <svg class="login-ico" aria-hidden="true"><use href="#i-login"/></svg>
                    Masuk
                    <span class="ver-badge">1.0</span>
                </a>
            </nav>
        </div>
    </header>

    {{-- Tombol kembali ke atas. Diletakkan DI LUAR header supaya
         tidak ikut ter-clip atau berubah opacity bersama header saat
         scroll. --}}
    <button class="to-top" type="button" aria-label="Kembali ke atas" tabindex="-1">
        <span aria-hidden="true"></span>
    </button>

    <main>
        {{-- ---------- hero ---------- --}}
        {{--
            Panel gelap besar + mockup HP yang keluar dari tepi kanan.

            Dua perangkat (HP + kartu laporan di belakangnya) dipilih
            karena bagian atas layar harus bisa menunjukkan "ini sistem
            yang jalan" dalam satu glance. Mockup HP dibaca lebih cepat
            daripada kartu mockup datar: bentuknya sudah dikenal orang
            tanpa perlu penjelasan, jadi tidak perlu kata untuk tahu
            itu aplikasi.

            PENTING SOAL KEJUJURAN: semua angka di dalam mockup HP ini
            adalah data contoh, bukan data pelanggan mana pun. Semuanya
            ditulis sebagai class `mock`, yang berarti isinya boleh
            berubah kapan saja tanpa perlu review -- karena tidak pernah
            ditampilkan sebagai angka asli. Jangan pernah menaruh
            angka riil di elemen yang TIDAK punya class `mock`: itu
            kalimat yang benar-benar diklaim ke calon pembeli.
        --}}
        <div class="hero">
            <div class="wrap">
                <div class="hero-panel">
                    <div class="hero-inner">

                        <div class="reveal">
                            <span class="eyebrow">Asta Layanan Operasional, Keuangan, dan Administrasi</span>

                            <h1>Satu sistem untuk <em>semua</em> jenis usaha.</h1>

                            <p class="lede">
                                ASTALOKA adalah platform pengelolaan usaha dan administrasi
                                dari Asta Brata Teknologi. Air minum, internet, pembayaran
                                sampah, jasa, atau usaha apa pun &mdash; semuanya berjalan
                                di atas satu sistem yang sama, lengkap dengan modulnya
                                masing-masing.
                            </p>

                            <div class="cta">
                                {{-- Di dalam panel gelap, tombol ghost memakai
                                     varian gelap (lihat `.hero-panel .btn-ghost`).

                                     Tombol pertama TIDAK lagi `#mulai` seperti
                                     sebelumnya. Menuju section penutup hanya
                                     memindahkan orang ke daftar tombol yang
                                     sama persis -- satu klik tambahan tanpa
                                     informasi baru. Link WhatsApp membawa
                                     pesannya sudah terisi, jadi dari sini
                                     calon pembeli sudah sampai di tujuan
                                     dalam satu klik.

                                     `#mulai` tidak dihapus dari halaman:
                                     masih dipakai navbar dan section cara
                                     kerja. --}}
                                <a class="btn btn-primary btn-lg"
                                   href="{{ $kontakWaDemo }}"
                                   target="_blank" rel="noopener">
                                    <svg class="i-solid" aria-hidden="true"><use href="#i-wa"/></svg>
                                    Minta Demo Gratis
                                </a>
                                <a class="btn btn-ghost btn-lg" href="#fitur">Lihat Fitur</a>
                            </div>

                            <p class="note">
                                <svg aria-hidden="true"><use href="#i-check-circle"/></svg>
                                <span>
                                    Tanpa biaya instalasi. Satu akun, semua modul &mdash;
                                    tidak perlu bolak-balik antar aplikasi.
                                </span>
                            </p>
                        </div>

                        {{-- Mockup perangkat. `aria-hidden` karena isinya
                             dekoratif dan sudah dijelaskan di teks hero. --}}
                        <div class="hero-art reveal" aria-hidden="true">
                            <div class="art-card">
                                {{-- Ikon dokumen: kartu ini bicara soal
                                     laporan keuangan yang bisa dicetak.
                                     Simbol `i-report` yang dipakai, bukan
                                     `i-docs` (dokumen bertumpuk) -- satu
                                     dokumen lebih cocok untuk satu laporan
                                     yang "siap".

                                     `div`, bukan `span`: aturan
                                     `.art-card span` di bawah menata
                                     deskripsi teks, dan `span` ikon akan
                                     ikut kena aturan itu -- `display: block`
                                     menggantikan `grid` dan warnanya jadi
                                     biru langit, bukan putih. --}}
                                <div class="art-card__ico">
                                    <svg><use href="#i-report"/></svg>
                                </div>
                                <strong>Laporan siap</strong>
                                <span>Neraca, laba rugi, buku besar, dan arus kas bisa dicetak kapan saja.</span>
                            </div>

                            <div class="phone">
                                <div class="phone__screen">
                                    <div class="phone__status">
                                        <i></i>
                                        <span class="mockdata">09:41</span>
                                    </div>

                                    <div class="phone__body">
                                        <div class="phone__hi">
                                            Halo, Admin
                                            <span class="mockdata">Ringkasan hari ini</span>
                                        </div>

                                        <div class="phone__row phone__row--money">
                                            <span><svg><use href="#i-trend-up"/></svg></span>
                                            <span>
                                                <b class="mockdata">Pemasukan hari ini</b>
                                                <em class="mockdata">Rp 1.250.000</em>
                                            </span>
                                        </div>

                                        <div class="phone__row phone__row--warn">
                                            <span><svg><use href="#i-trend-down"/></svg></span>
                                            <span>
                                                <b class="mockdata">Tagihan menunggak</b>
                                                <em class="mockdata">7 pelanggan</em>
                                            </span>
                                        </div>

                                        <div class="phone__row">
                                            <span><svg><use href="#i-docs"/></svg></span>
                                            <span>
                                                <b class="mockdata">Tagihan hari ini</b>
                                                <em class="mockdata">32 tagihan</em>
                                            </span>
                                        </div>

                                        <span class="phone__cta mockdata">Buat Tagihan</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>

        {{-- ---------- pratinjau dashboard ---------- --}}
        {{--
            Kartu mockup dashboard, bukan ringkasan teks.

            Alasan pakai visual: daftar bullet menjelaskan fitur,
            tapi calon pembeli yang melihat dashboard bisa
            langsung paham ini sistem yang jalan -- bukan mockup
            wireframe kosong.

            Letaknya sebagai section sendiri, bukan di dalam hero:
            hero sekarang dipakai untuk mockup HP (pengalaman harian),
            dan blok ini untuk angka (bukti sistemnya jalan). Dua hal
            berbeda butuh dua penekanan berbeda.

            PENTING SOAL KEJUJURAN: semua angka di sini HARUS
            memakai class `mock`. Aturan mainnya satu: kalau
            elemen punya `mock`, isinya boleh berubah kapan saja
            tanpa perlu review karena tidak pernah ditampilkan
            sebagai angka asli. Elemen tanpa `mock` berarti
            kalimat yang benar-benar diklaim ke calon pembeli, jadi
            jangan pernah menaruh angka di sana.

            Angka yang tampil BUKAN data produksi. Data ada di
            database terpisah per pelanggan dan tidak pernah dimuat
            ke halaman publik.
        --}}
        <section id="pratinjau" class="preview">
            <div class="wrap">
                <div class="preview-grid">

                    <div class="preview-copy reveal">
                        <span class="eyebrow">Ringkasan satu layar</span>

                        <h2>Satu sistem, semua kebutuhan operasional</h2>

                        <p>
                            ASTALOKA bukan aplikasi air minum. Ia landasan yang
                            bisa dipakai apa pun &mdash; air, internet, sampah,
                            atau usaha apa pun &mdash; dengan modul yang
                            sama-sama sudah siap.
                        </p>

                        <ul class="preview-points">
                            <li>
                                <svg aria-hidden="true"><use href="#i-check-circle"/></svg>
                                <span><strong>Pemasukan dan tunggakan</strong> terlihat berdampingan, bukan harus dihafal.</span>
                            </li>
                            <li>
                                <svg aria-hidden="true"><use href="#i-check-circle"/></svg>
                                <span><strong>Laporan keuangan</strong> bisa dicetak kapan saja tanpa menyusun ulang data.</span>
                            </li>
                            <li>
                                <svg aria-hidden="true"><use href="#i-check-circle"/></svg>
                                <span><strong>Semua modul menyatu</strong> &mdash; satu akun cukup, tanpa bolak-balik antar aplikasi.</span>
                            </li>
                        </ul>
                    </div>

                    <div class="mock reveal">
                    <div class="mock__bar">
                        <span class="mock__dots" aria-hidden="true"><i></i><i></i><i></i></span>
                        <span class="mock__path">astaloka &mdash; dashboard</span>
                        <span class="mock__badge">contoh tampilan</span>
                    </div>

                    <div class="mock__body">
                        <div class="mock__stat mock__stat--in">
                            <div class="mock__head">
                                <span class="mock__ico"><svg aria-hidden="true"><use href="#i-trend-up"/></svg></span>
                                <span class="mock__label">Pemasukan bulan ini</span>
                            </div>
                            <span class="mock__value" data-count="18450000" data-money>Rp 0</span>
                            <span class="mock__delta up">
                                <svg aria-hidden="true"><use href="#i-trend-up"/></svg>
                                12,4% dari bulan lalu
                            </span>
                        </div>

                        <div class="mock__stat mock__stat--warn">
                            <div class="mock__head">
                                <span class="mock__ico"><svg aria-hidden="true"><use href="#i-trend-down"/></svg></span>
                                <span class="mock__label">Tagihan belum lunas</span>
                            </div>
                            <span class="mock__value" data-count="9230000" data-money>Rp 0</span>
                            <span class="mock__delta down">
                                <svg aria-hidden="true"><use href="#i-trend-down"/></svg>
                                7 customer menunggak
                            </span>
                        </div>

                        {{-- Grafik GARIS. Murni SVG + CSS, tanpa JS dan
                             tanpa gambar.

                             Label bulan disembunyikan dari screen reader:
                             grafik sudah punya label "Penagihan 6 bulan
                             terakhir" di atasnya. Membaca "Mei Jun Jul"
                             tanpa konteks hanya menambah ketegangan. --}}
                        <div class="mock__chart">
                            <div class="mock__head">
                                <span class="mock__ico"><svg aria-hidden="true"><use href="#i-chart"/></svg></span>
                                <span class="mock__label">Penagihan 6 bulan terakhir</span>
                            </div>
                            {{--
                                Grafik GARIS, bukan batang.

                                Polanya sengaja tidak naik terus:
                                Mei 42 -> Jun 58 -> Jul 49 (turun) ->
                                Agu 71 -> Sep 64 (turun) -> Okt 88.

                                Dua penurunan di tengah itu yang membuatnya
                                terbaca sebagai data, bukan sebagai gambar
                                mockup. Grafik yang selalu naik sebenarnya
                                sedang mengklaim sesuatu yang tidak perlu
                                diklaim mockup ini -- dan halaman ini punya
                                aturan kejujuran soal angka (lihat catatan
                                panjang di section pratinjau).

                                TINGGI TITIK = 100 - persen, karena sumbu Y
                                di SVG turun ke bawah. Nilai persennya sama
                                dengan versi batang sebelumnya, jadi
                                perbandingannya sama.

                                JARAK 8.33% DARI TEPI -- ini yang membuat
                                titik pertama dan terakhir tidak lagi
                                menempel tepi, dan sekaligus yang
                                menyamakan titik dengan label bulan.

                                Alasannya:

                                1. `preserveAspectRatio="none"` meregangkan
                                   X dan Y dengan faktor BERBEDA. Pada
                                   kartu selebar ~840px dan tinggi 84px,
                                   X diregangkan ~8.4x sedangkan Y hanya
                                   ~0.84x. Lingkaran r=2.6 jadi ellips
                                   lebar 44px tapi setinggi 4px -- persis
                                   titik pipih yang terlihat di kartu.

                                   `vector-effect` tidak menolong di sini:
                                   itu cuma menormalkan KETEBALAN GARIS,
                                   bukan bentuk lingkarannya. Jadi titik
                                   dipisah ke elemen HTML sendiri (lihat
                                   `.deck__pts` di bawah) -- di luar SVG,
                                   disitunya tidak ada yang diregangkan.

                                2. Label bulan pakai GRID 6 KOLOM, dan
                                   titik diletakkan di tengah kolomnya:
                                   8.33, 25, 41.67, 58.33, 75, 91.67 persen.
                                   Kedua-duanya angka yang sama, jadi tidak
                                   mungkin tidak sejajar. Versi lama
                                   memakai `space-between` + `max-width`,
                                   yang ternyata meleset sampai ~48px di
                                   tengah kartu.

                                   Sisa 8.33% di kiri dan kanan juga
                                   memberi ruang untuk setengah jari-jari
                                   titik, jadi tidak ada yang menggantung
                                   di luar kotak.
                                --}}
                                <div class="deck">
                                    <div class="deck__plot">
                                        <svg class="deck__svg"
                                             viewBox="0 0 100 100"
                                             preserveAspectRatio="none"
                                             aria-hidden="true"
                                             focusable="false">
                                            {{--
                                                Gradien disimpan di dalam SVG yang
                                                sama, bukan di sprite ikon di
                                                <head>. Gradien yang hanya dipakai
                                                satu elemen tidak perlu id global.
                                            --}}
                                            <defs>
                                                <linearGradient id="deck-fill"
                                                                gradientUnits="userSpaceOnUse"
                                                                x1="0" y1="0" x2="0" y2="100">
                                                    <stop offset="0%" stop-color="#0ea5e9" stop-opacity=".22"/>
                                                    <stop offset="100%" stop-color="#0ea5e9" stop-opacity="0"/>
                                                </linearGradient>
                                            </defs>

                                            {{--
                                                Isian dulu, garis di atasnya. Urutan
                                                ini yang menentukan siapa menutup
                                                siapa: garis ditulis setelah, jadi
                                                dia berada di atas isian.

                                                Bentuknya tertutup ke bawah (Z)
                                                sampai y=100, tepat di garis dasar.
                                            --}}
                                            <path class="deck__fill"
                                                  d="M8.33 58 L25 42 L41.67 51 L58.33 29 L75 36 L91.67 12 L91.67 100 L8.33 100 Z"/>

                                            <polyline class="deck__edge"
                                                      points="8.33,58 25,42 41.67,51 58.33,29 75,36 91.67,12"/>
                                        </svg>

                                        {{--
                                            Titik sebagai elemen HTML, BUKAN
                                            `<circle>` di dalam SVG.

                                            Alasannya satu, dan ini yang penting:
                                            SVG di atas memakai
                                            `preserveAspectRatio="none"`, jadi
                                            apa pun yang ada di dalamnya ikut
                                            teregangkan tidak proporsional.
                                            Lingkaran menjadi ellips pipih. Di luar
                                            SVG, tidak ada transformasi apa pun,
                                            jadi `<i>` dengan `border-radius: 50%`
                                            selalu berupa lingkaran -- di lebar
                                            kartu berapa pun dan di zoom berapa
                                            pun.

                                            Posisi memakai persentase yang sama
                                            persis dengan koordinat SVG. `--x`
                                            menjadi `left`, dan angkanya adalah
                                            tengah kolom label bulan (lihat
                                            `grid-template-columns` di CSS).
                                            Jadi titik dan label berasal dari
                                            angka yang sama, bukan dua nilai yang
                                            kebetulan mirip.
                                            terpusat pada koordinatnya -- tanpa
                                            itu titik tergeser setengah lebar ke
                                            kanan dan satu piksel ke bawah dari
                                            posisi yang dimaksud.
                                        --}}
                                        <div class="deck__pts" aria-hidden="true">
                                            <i style="--x: 8.33%; --y: 58%"></i>
                                            <i style="--x: 25%;   --y: 42%"></i>
                                            <i style="--x: 41.67%; --y: 51%"></i>
                                            <i style="--x: 58.33%; --y: 29%"></i>
                                            <i style="--x: 75%;   --y: 36%"></i>
                                            <i class="is-on" style="--x: 91.67%; --y: 12%"></i>
                                        </div>
                                    </div>
                                    <div class="deck__months" aria-hidden="true">
                                        <span>Mei</span><span>Jun</span><span>Jul</span>
                                        <span>Agu</span><span>Sep</span><span>Okt</span>
                                    </div>
                                </div>
                        </div>

                        <div class="mock__stat mock__stat--user">
                            <div class="mock__head">
                                <span class="mock__ico"><svg aria-hidden="true"><use href="#i-user"/></svg></span>
                                <span class="mock__label">Pelanggan aktif</span>
                            </div>
                            <span class="mock__value" data-count="1643">0</span>
                            <span class="mock__delta">terdaftar dalam sistem</span>
                        </div>

                        <div class="mock__stat mock__stat--docs">
                            <div class="mock__head">
                                <span class="mock__ico"><svg aria-hidden="true"><use href="#i-docs"/></svg></span>
                                <span class="mock__label">Laporan siap</span>
                            </div>
                            <span class="mock__tags">
                                <i>Neraca</i><i>Laba Rugi</i><i>Buku Besar</i><i>Arus Kas</i>
                            </span>
                        </div>
                    </div>

                    <p class="mock__foot">
                        Sudah pakai ASTALOKA? Buka langsung
                        <strong>alamat usaha Anda</strong>, atau
                        <a href="{{ $centralLoginUrl }}">masuk ke panel pusat</a>.
                    </p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ---------- fitur ---------- --}}
        <section id="fitur">
            <div class="wrap">
                <div class="sec-head reveal">
                    <h2>Semua modul, satu landasan</h2>
                    <p>
                        Tiap jenis usaha punya modulnya sendiri, tapi semuanya
                        berjalan di atas data pelanggan dan keuangan yang sama &mdash;
                        jadi tidak ada pencatatan ganda antar layanan.
                    </p>
                </div>

                <div class="grid">
                    <div class="card reveal" style="--d: 0ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-water"/></svg></div>
                        <h3>Air Minum</h3>
                        <p>Pencatatan meter, tarif berjenjang, tagihan otomatis, dan riwayat pelanggan air minum dalam satu alur.</p>
                    </div>

                    <div class="card reveal" style="--d: 70ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-internet"/></svg></div>
                        <h3>Internet &amp; Tv</h3>
                        <p>Paket langganan, biaya instalasi, penagihan per bulan, dan monitoring pelanggan aktif.</p>
                    </div>

                    <div class="card reveal" style="--d: 140ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-truck"/></svg></div>
                        <h3>Pembayaran Sampah</h3>
                        <p>Tagihan angkut dan aturan retribusi per pelanggan, lengkap dengan catatan setoran dan tunggakan.</p>
                    </div>

                    <div class="card reveal" style="--d: 210ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-users"/></svg></div>
                        <h3>Data Pelanggan</h3>
                        <p>Satu basis pelanggan untuk semua layanan, dengan riwayat langganan dan status pembayaran per pelanggan.</p>
                    </div>

                    <div class="card reveal" style="--d: 280ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-chart"/></svg></div>
                        <h3>Keuangan &amp; Jurnal</h3>
                        <p>Jurnal masuk dan keluar, buku besar, laba rugi, sampai tutup buku tahunan tanpa Excel.</p>
                    </div>

                    <div class="card reveal" style="--d: 350ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-report"/></svg></div>
                        <h3>Laporan</h3>
                        <p>Laporan tagihan, tunggakan, dan keuangan siap cetak dengan kop usaha Anda sendiri.</p>
                    </div>

                    <div class="card reveal" style="--d: 420ms">
                        <div class="ico"><svg aria-hidden="true"><use href="#i-lock"/></svg></div>
                        <h3>Data Pribadi</h3>
                        <p>Data usaha Anda hanya bisa diakses oleh akun Anda sendiri. Tidak ada akun lain yang bisa melihatnya.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ---------- pengguna: sudah dipakai siapa ---------- --}}
        {{--
            NUMANGKA SAJA. Tidak ada nama lokasi, tidak ada logo, tidak
            ada tautan ke subdomain mana pun.

            Ini bukan sekadar keputusan supaya halaman ini ringan. Ada
            dua alasan yang menutupnya, dan keduanya ada di
            `App\Support\StatistikPengguna`:

              1. Lokasi belum pernah menyetujui namanya tampil di
                 halaman publik, dan registry pusat tidak punya kolom
                 untuk "boleh tampil atau tidak".

              2. Domain lokasi adalah peta. Satu host yang lolos ke sini
                 berarti peta menuju subdomain yang bisa ditebak orang
                 -- dan halaman marketing berubah menjadi alat
                 pemetaan.

            Karena itu baris "jenis usaha terlayani" ditampilkan sebagai
            ANGKA, bukan sebagai daftar nama sektor. Daftar itu akan
            membocorkan apa yang tidak boleh keluar: bahwa di wilayah
            tertentu ada lokasi sanitasi, ada lokasi sampah, dan
            seterusnya.

            ANGKA DI SINI BUKAN MOCKUP.

            Halaman ini punya aturan tegas: elemen yang punya class
            `mock` boleh menampilkan angka rekaan, elemen tanpa class
            itu berarti klaim yang benar-benar diklaim ke calon
            pembeli (lihat catatan panjang di section pratinjau).
            Section ini sengaja TIDAK memakai class `mock`, karena
            angkanya bukan rekaan -- semuanya dihitung dari database
            pusat oleh `StatistikPengguna::hitung()`.

            Konsekuensinya harus dipahami sebelum mengedit angka di
            sini: menambah angka secara manual adalah memutus aturan
            itu. Kalau butuh angka baru, tambahkan query-nya di
            `StatistikPengguna` -- bukan tulis angkanya di template.

            LETAKNYA PAS DI ANTARA fitur dan keunggulan.

            Urutan persuasi di halaman ini: fitur memberi tahu apa
            yang ADA, section ini memberi tahu bahwa produk ini bukan
            sekadar soal fitur -- orang lain sudah memakainya, dan
            datanya nyata -- baru section keunggulan menjawab kenapa
            itu penting bagi mereka. Kalau section ini diletakkan di
            bawah, orang sudah membaca semua fitur tanpa pernah punya
            alasan untuk tertarik.

            CLASS `tint` dipakai supaya section ini tidak menyatu
            dengan kartu fitur di atasnya yang juga terang dan
            berkolom. Tanpa perbedaan latar, keduanya terbaca sebagai
            satu blok panjang kartu dan batas antara "apa yang ada"
            dan "siapa yang sudah pakai" hilang.
        --}}
        <section id="pengguna" class="tint">
            <div class="wrap">
                <div class="sec-head reveal">
                    <h2>{{ \App\Support\StatistikPengguna::judulSection($pengguna['lokasi']) }}</h2>
                    <p>
                        ASTALOKA dipakai lintas jenis usaha &mdash; air minum,
                        internet, pembayaran sampah, dan layanan lainnya. Angkanya
                        di bawah dihitung langsung dari data yang berjalan, bukan
                        contoh tampilan.
                    </p>
                </div>

                <div class="pengguna-row reveal" style="--d: 80ms">
                    {{--
                        Angka dan kata "yang pertama" dibungkus jadi satu
                        blok.

                        Keduanya bicara hal yang sama tentang ANGKA yang
                        sama -- hanya bedanya cara membacanya. Kalau
                        dipisah jadi dua elemen grid, kata itu akan
                        menduduki kolom sendiri sejajar dengan kalimat,
                        dan terbentuk baris yang tidak ada isinya: satu
                        kata di sebelah kalimat panjang, tanpa tokohnya
                        yang jelas.

                        `align-items: center` pada `.pengguna-row` yang
                        menengahkan seluruh blok ini terhadap
                        kalimatnya.

                        Kata "yang pertama" TIDAK menambah klaim baru.
                        "Lokasi pertama" dan "satu lokasi memakai"
                        menyatakan fakta yang sama persis; hanya bunyinya
                        yang berhenti terdengar seperti kekurangan. Angka
                        tetap 1 -- yang berubah cuma cara kalimat itu
                        dibaca.

                        Muncul hanya kalau angkanya memang satu. Begitu
                        ada dua, kata ini jadi tidak benar dan hilang
                        -- bukan sekadar tidak perlu.

                        `data-count` dipakai animasi menghitung yang sama
                        dengan kartu mockup di section pratinjau.

                        Nilai ASLI sudah ditulis di dalam elemen, bukan
                        hanya di atribut. Kalau JS mati atau belum selesai,
                        yang terbaca adalah angka yang benar -- bukan
                        "0" yang terlihat seperti kegagalan memuat.

                        `aria-label` menulis ulang kalimatnya untuk pembaca
                        layar. Tanpa itu, yang dibacakan hanya "12" --
                        angka yang tidak berarti apa-apa tanpa tahu apa
                        yang dihitung.
                    --}}
                    <div class="pengguna-angka-blok">
                        <span class="pengguna-angka @if ($pengguna['lokasi'] === 0) pengguna-angka--kosong @endif"
                              data-count="{{ $pengguna['lokasi'] }}"
                              aria-label="{{ $pengguna['lokasi'] }} {{ \App\Support\StatistikPengguna::kalimatLokasi($pengguna['lokasi']) }}">
                            {{ number_format($pengguna['lokasi'], 0, ',', '.') }}
                        </span>

                        @if ($pengguna['pertama'])
                            <span class="pengguna-pertama">yang pertama</span>
                        @endif
                    </div>

                    <p style="margin:0; font-size:19px; line-height:1.6; color:var(--ink-soft); max-width:38ch">
                        {{ \App\Support\StatistikPengguna::kalimatLokasi($pengguna['lokasi']) }}
                    </p>

                    {{--
                        Dua angka pendukung, ditampilkan hanya kalau
                        nilainya berarti.

                        "0 jenis usaha terlayani" tidak menambah apa pun --
                        itu cuma mengulang bahwa belum ada yang mengisi
                        sektor, yang memang belum diketahui siapa pun.
                        "0 sejak tahun" jelas tidak punya arti sama sekali.
                        Angka utama di atas tetap tampil apa adanya
                        termasuk saat nol, karena "0 lokasi" adalah
                        jawaban yang jujur.
                    --}}
                    @if ($pengguna['sektor'] > 0 || $pengguna['sejak'])
                        <ul class="pengguna-ketiga">
                            @if ($pengguna['sektor'] > 0)
                                <li>
                                    <b>{{ number_format($pengguna['sektor'], 0, ',', '.') }}</b>
                                    <span>jenis usaha terlayani</span>
                                </li>
                            @endif

                            @if ($pengguna['sejak'])
                                <li>
                                    <b>{{ $pengguna['sejak'] }}</b>
                                    <span>pertama dipakai ASTALOKA</span>
                                </li>
                            @endif
                        </ul>
                    @endif
                </div>
            </div>
        </section>

        {{-- ---------- keunggulan: masalah vs yang didapat ---------- --}}
        {{--
            Section persuading yang menjawab pertanyaan yang muncul
            setelah orang selesai baca daftar fitur.

            Letaknya PAS DI ANTARA fitur dan cara kerja, bukan setelah
            semua section. Alasannya urutan persuasi: fitur memberi tahu
            apa yang ADA, section ini memberi tahu kenapa itu penting
            bagi mereka, dan baru setelah itu "cara kerja" baru masuk
            akal sebagai langkah berikutnya. Kalau section ini diletakkan
            paling bawah, orang sudah membaca semua fitur tanpa pernah
            punya alasan untuk tertarik.

            Class `tint` dipakai supaya section ini berbeda dari kartu
            fitur di atasnya yang juga terang dan berkolom. Tanpa
            perbedaan latar, keduanya menyatu jadi satu blok panjang
            kartu dan pemisahan "fitur" vs "kenapa penting" hilang.

            SOAL KONTEN: tidak ada angka, bintang, atau nama pelanggan
            di sini. Halaman ini punya aturan kejujuran yang konsisten
            (lihat catatan panjang di section pratinjau: elemen tanpa
            class `mock` berarti kalimat yang benar-benar diklaim).
            Testimoni rekaan adalah cara tercepat menghancurkan
            kredibilitas -- dan kredibilitas justru yang sedang dibangun
            halaman ini. Jadi seluruh section ini dibangun dari
            pemodelan masalah nyata yang dialami usaha kecil, bukan dari
            angka yang tidak bisa dipertanggungjawabkan. --}}
        <section id="kenapa" class="tint">
            <div class="wrap">
                <div class="sec-head reveal">
                    <h2>Berhenti mengelola usaha tanpa catatan yang rapi</h2>
                    <p>
                        Kebanyakan usaha kecil masih mencatat semuanya
                        dari kepala: pelanggan di buku tulis, transaksi
                        di buku kas, tagihan yang sudah dibayar tapi
                        tidak tercatat. Yang hilang bukan datanya, tapi pekerjaan yang sama
                        harus dikerjakan ulang setiap bulan.
                    </p>
                </div>

                <div class="paingrid">

                    {{-- Kolom kiri: masalah yang dikenali.
                         Tanpa penanda dan tanpa visual yang bergaya kartu
                         kartu fitur, supaya terbaca sebagai daftar
                         keluhan, bukan daftar fitur. --}}
                    <ul class="painlist reveal" style="--d: 60ms">
                        <li>
                            <svg aria-hidden="true"><use href="#i-x-plain"/></svg>
                            <span>Data pelanggan tercecer di beberapa buku dan WhatsApp &mdash; sulit dicari saat ada yang menagih.</span>
                        </li>
                        <li>
                            <svg aria-hidden="true"><use href="#i-x-plain"/></svg>
                            <span>Tidak tahu pelanggan mana yang sudah bayar dan mana yang menunggak.</span>
                        </li>
                        <li>
                            <svg aria-hidden="true"><use href="#i-x-plain"/></svg>
                            <span>Rekap keuangan setiap bulan harus disusun ulang dari nol.</span>
                        </li>
                        <li>
                            <svg aria-hidden="true"><use href="#i-x-plain"/></svg>
                            <span>Menyiapkan laporan untuk pajak atau pemangku kepentingan makan waktu berjam-jam.</span>
                        </li>
                        <li>
                            <svg aria-hidden="true"><use href="#i-x-plain"/></svg>
                            <span>Tidak pernah tahu pasti berapa keuntungan usaha bulan ini.</span>
                        </li>
                    </ul>

                    {{-- Kolom kanan: jawaban, satu per satu masalah
                         di atas. Pasangannya urut, bukan acak: baris
                         pertama menjawab baris pertama. Orang bisa
                         memindai ke bawah sebagai dua kolom yang
                         sejajar tanpa harus membaca tiap kalimat. --}}
                    <ul class="gaingrid reveal" style="--d: 140ms">
                        <li>
                            <svg aria-hidden="true"><use href="#i-check-plain"/></svg>
                            <div>
                                <strong>Satu basis pelanggan</strong>
                                <span>Semua data tersimpan di satu tempat, bisa dicari seketika.</span>
                            </div>
                        </li>
                        <li>
                            <svg aria-hidden="true"><use href="#i-check-plain"/></svg>
                            <div>
                                <strong>Status pembayaran jelas</strong>
                                <span>Lunas, menunggak, atau belum bayar &mdash; langsung terlihat per pelanggan.</span>
                            </div>
                        </li>
                        <li>
                            <svg aria-hidden="true"><use href="#i-check-plain"/></svg>
                            <div>
                                <strong>Rekap otomatis</strong>
                                <span>Jurnal masuk dan keluar tercatat saat transaksi, jadi bulan tutup tinggal dilihat.</span>
                            </div>
                        </li>
                        <li>
                            <svg aria-hidden="true"><use href="#i-check-plain"/></svg>
                            <div>
                                <strong>Laporan siap cetak</strong>
                                <span>Neraca, laba rugi, buku besar, dan arus kas tersedia kapan saja dengan kop usaha sendiri.</span>
                            </div>
                        </li>
                        <li>
                            <svg aria-hidden="true"><use href="#i-check-plain"/></svg>
                            <div>
                                <strong>Laba terlihat real-time</strong>
                                <span>Dashboard menunjukkan pendapatan, pengeluaran, dan tunggakan dalam satu layar.</span>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        {{-- ---------- cara kerja ---------- --}}
        <section id="caranya" class="tint">
            <div class="wrap">
                <div class="sec-head reveal">
                    <h2>Cara kerjanya sederhana</h2>
                    <p>Tiga langkah. Tidak ada migrasi data dari sistem lama yang wajib dilakukan.</p>
                </div>

                <div class="steps">
                    <div class="step reveal" style="--d: 0ms">
                        <span class="step__num" aria-hidden="true"></span>
                        <h3>Hubungi kami</h3>
                        <p>Sampaikan kebutuhan usaha Anda &mdash; air minum, internet, sampah, atau lainnya. Kami bantu pilihkan paket yang paling pas.</p>
                        <a class="step__cta" href="{{ $kontakWaHarga }}" target="_blank" rel="noopener">
                            <svg class="i-solid" aria-hidden="true"><use href="#i-wa"/></svg>
                            Chat WhatsApp
                        </a>
                    </div>

                    <div class="step reveal" style="--d: 90ms">
                        <span class="step__num" aria-hidden="true"></span>
                        <h3>Akun langsung jadi</h3>
                        <p>Akun Anda dibuat lengkap dengan modul siap pakai. Tidak perlu install, tidak perlu server, tidak perlu biaya awal.</p>
                    </div>

                    <div class="step reveal" style="--d: 180ms">
                        <span class="step__num" aria-hidden="true"></span>
                        <h3>Langsung bekerja</h3>
                        <p>Catat pembayaran, cetak tagihan, lihat laporan. Tidak ada satu pun langkah yang perlu bantuan teknis.</p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ---------- penutup ---------- --}}
        <section id="mulai">
            <div class="wrap">
                <div class="closer reveal">
                    <h2>Siap mulai?</h2>
                    <p>
                        Punya usaha yang butuh sistem operasional dan keuangan
                        yang rapi &mdash; sekecil apa pun skalanya? Hubungi Asta
                        Brata Teknologi untuk demo gratis dan penawaran harga.
                    </p>

                    {{--
                        Tiga pintu masuk, bukan satu.

                        Tombol pertama selalu WhatsApp: itu yang paling
                        sering dipakai calon pembeli, dan pesannya sudah
                        terisi, jadi tidak ada langkah tambahan untuk
                        menulis pertanyaan dari nol.

                        Email tetap jadi tombol kedua, bukan disembunyikan
                        di teks: ada yang memang lebih suka mengirim proposal,
                        dan dari aplikasi email prospective bisa lebih cepat
                        daripada lewat aplikasi pesan.

                        Nomor telepon jadi tautan kecil di bawahnya, untuk
                        yang lebih suka bicara.

                        Teks tombol menyebut kanalnya, karena ikon saja
                        tidak selalu terbaca. Ini juga satu-satunya bagian
                        halaman yang boleh menyebut nomor: nomor support
                        teknis yang dipakai di dalam aplikasi berbeda, dan
                        mencampurkannya di sini akan membuat calon pembeli
                        menghubungi orang yang salah.
                    --}}
                    <div class="cta" style="justify-content:center">
                        <a class="btn btn-primary btn-lg"
                           href="{{ $kontakWaDemo }}"
                           target="_blank" rel="noopener">
                            <svg class="i-solid" aria-hidden="true"><use href="#i-wa"/></svg>
                            Minta Demo Gratis
                        </a>
                        <a class="btn btn-ghost btn-lg" href="{{ $kontakEmailHarga }}">
                            <svg aria-hidden="true"><use href="#i-email"/></svg>
                            Tanya Harga via Email
                        </a>
                    </div>

                    <div class="kontak-alt">
                        <span class="kontak-alt__label">Atau hubungi langsung:</span>

                        <a href="{{ $kontakTelepon }}">
                            <svg aria-hidden="true"><use href="#i-telepon"/></svg>
                            {{ $kontakWaTampil }}
                        </a>
                    </div>

                    <p class="kontak-email">
                        <a href="{{ $kontakEmailDemo }}">
                            <svg aria-hidden="true"><use href="#i-email"/></svg>
                            {{ $kontakEmail }}
                        </a>
                    </p>
                </div>
            </div>
        </section>
    </main>

    {{--
        ---------- footer ----------

        Empat kolom, bukan satu baris tiga kalimat.

        Versi sebelumnya menaruh copyright, pintu masuk panel, dan
        kontak dalam satu baris yang dibungkus `space-between`.
        Ketiganya jadi tiga hal dengan bobot yang sama: mata tidak
        tahu harus mulai dari mana, dan link yang paling penting
        justru tenggelam di antara dua kalimat.

        Kolom yang ada, dan kenapa:

        1. Brand + ringkasan. Yang sedang dibaca orang yang baru
           sampai: ini produk siapa, dan apa yang dikerjakannya.
        2. Halaman. Shortcut ke section yang ada di halaman ini,
           supaya orang tidak perlu scroll lagi hanya untuk
           kembali ke bagian fitur.
        3. Kontak. Dua kanal, dengan ikon -- daftar kontak tanpa
           ikon di footer gelap terlihat seperti paragraf biasa,
           bukan seperti daftar yang bisa dipilih.
        4. Ajakan. Satu tombol. Footer bukan tempat menaruh tiga
           tombol; tombol yang paling penting sudah ada di navbar
           dan di blok penutup, dan mengulangnya tiga kali membuat
           ketiganya terasa kurang penting.

        Gradasi gelapnya disambung ke `.closer` di atas -- lihat
        catatan blok `footer` di <style>.
    --}}
    <footer>
        <div class="wrap">
            <div class="foot-top">

                <div class="foot-brand">
                    <a class="brand" href="/">
                        <span class="dot">A</span>
                        <span>ASTALOKA<small>oleh Asta Brata Teknologi</small></span>
                    </a>

                    <p class="foot-about">
                        Platform pengelolaan usaha, administrasi pelanggan,
                        transaksi, dan laporan keuangan untuk air minum,
                        internet, pembayaran sampah, dan layanan lainnya.
                    </p>

                    <p class="foot-note">
                        <svg aria-hidden="true"><use href="#i-shield"/></svg>
                        <span>Data tiap usaha tersimpan terpisah dan hanya bisa diakses oleh akun usaha tersebut.</span>
                    </p>
                </div>

                <div class="foot-col">
                    <h4>Halaman</h4>
                    <ul>
                        <li><a href="#fitur"><svg aria-hidden="true"><use href="#i-nav-fitur"/></svg>Fitur</a></li>
                        <li><a href="#pengguna"><svg aria-hidden="true"><use href="#i-nav-pengguna"/></svg>Pengguna</a></li>
                        <li><a href="#caranya"><svg aria-hidden="true"><use href="#i-nav-alur"/></svg>Cara Kerja</a></li>
                        <li><a href="#mulai"><svg aria-hidden="true"><use href="#i-nav-harga"/></svg>Harga</a></li>
                    </ul>
                </div>

                {{--
                    Kontak memakai variabel yang sama dengan hero dan
                    section penutup (bagian `@php` paling atas), jadi
                    kalau nomor berubah cukup satu tempat yang
                    diperbaiki.

                    `/central/login` untuk pintu masuk, bukan `/login`:
                    lihat catatan panjang di navbar.
                --}}
                <div class="foot-col">
                    <h4>Kontak</h4>
                    <ul>
                        <li>
                            <a href="{{ $kontakWaHarga }}" target="_blank" rel="noopener">
                                <svg class="i-solid" aria-hidden="true"><use href="#i-wa"/></svg>
                                WhatsApp
                            </a>
                        </li>
                        <li>
                            <a href="{{ $kontakTelepon }}">
                                <svg aria-hidden="true"><use href="#i-telepon"/></svg>
                                {{ $kontakWaTampil }}
                            </a>
                        </li>
                        <li>
                            <a href="mailto:{{ $kontakEmail }}">
                                <svg aria-hidden="true"><use href="#i-email"/></svg>
                                {{ $kontakEmail }}
                            </a>
                        </li>
                    </ul>

                    <a class="foot-login" href="{{ $centralLoginUrl }}">
                        <svg aria-hidden="true"><use href="#i-login"/></svg>
                        <span>Masuk ke panel</span>
                    </a>
                </div>

                {{--
                    Satu tombol, bukan tiga.

                    Tombol "Minta Demo" yang sama persis sudah ada di
                    hero dan di section penutup. Di sini ia diulang
                    hanya karena letaknya berbeda: orang yang sudah
                    membaca sampai dasar halaman tidak akan scroll
                    naik lagi -- tapi orang yang sudah mengklik tombol
                    di hero tidak akan datang ke footer. Tombol yang
                    diulang di tempat yang tepat bukan pengulangan
                    yang sia-sia.
                --}}
                <div class="foot-cta">
                    <h4>Mulai dengan demo gratis</h4>
                    <p>Tanpa biaya instalasi. Tinggal Hubungi kami, paketnya kami pilihkan.</p>

                    <a class="btn" href="{{ $kontakWaDemo }}" target="_blank" rel="noopener">
                        <svg class="i-solid" aria-hidden="true"><use href="#i-wa"/></svg>
                        Minta Demo Gratis
                    </a>
                </div>
            </div>

            <div class="foot-bottom">
                <p>&copy; {{ date('Y') }} <strong>Asta Brata Teknologi</strong>. ASTALOKA &mdash; Asta Layanan Operasional, Keuangan, dan Administrasi.</p>

                <p class="foot-ver">Versi 1.0</p>
            </div>
        </div>
    </footer>

    <script>
        /* ============================================================
           BAGIAN 1 -- ANIMASI
           Dipisah dari bagian portal di bawah. Script sebelumnya memakai
           satu IIFE dengan `return` di awal kalau form tidak ada; kalau
           animasi ikut di dalam IIFE yang sama, satu guard yang gagal
           akan mematikan animasi seluruh halaman.
           ============================================================ */
        (function () {
            var kurangiGerak = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (kurangiGerak || !('IntersectionObserver' in window)) {
                // Tanpa observer atau pengguna minta gerak minimal: tampilkan
                // semua langsung. `no-js` sudah dihapus di <head>, jadi
                // konten aman walau branch ini tidak kena.
                document.querySelectorAll('.reveal').forEach(function (el) {
                    el.classList.add('shown');
                });
                return;
            }

            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;

                    entry.target.classList.add('shown');
                    // Berhenti mengamati setelah muncul: elemen yang sudah
                    // terlihat tidak perlu dicek lagi setiap scroll.
                    observer.unobserve(entry.target);
                });
            }, {
                // Muncul saat elemen sudah ~12% terlihat, supaya animasi
                // tidak berhenti tepat di tepi layar.
                threshold: 0.12,
                rootMargin: '0px 0px -60px 0px',
            });

            document.querySelectorAll('.reveal').forEach(function (el) {
                observer.observe(el);
            });
        })();

        /* Garis pemisah antar section tumbuh saat section masuk layar.

           Dipisah dari observer `.reveal` karena objek yang diawasi
           berbeda (section, bukan elemen di dalamnya) dan ambangnya
           beda: garis baru terasa saat section menyentuh tepi atas
           viewport, bukan saat 12% isinya terlihat. Kalau ikut aturan
           yang sama, garis sudah menyala sebelum kontennya masuk --
           dan garis hilang sama sekali, karena ia menyala
           persis ketika sedang di luar pandangan. */
        (function () {
            var sections = document.querySelectorAll('main > section');
            if (!sections.length) return;

            if (!('IntersectionObserver' in window)) {
                sections.forEach(function (s) { s.classList.add('is-in'); });
                return;
            }

            var obs = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('is-in');
                    obs.unobserve(entry.target);
                });
            }, {
                /* `bottom: 5%` -- garis menyala saat section sudah
                   mulai masuk dari bawah, supaya transisinya terasa
                  sebelum kontennya sampai ke tengah layar. */
                threshold: 0,
                rootMargin: '0px 0px -5% 0px',
            });

            sections.forEach(function (s) { obs.observe(s); });
        })();

        /* ============================================================
           MOCKUP PERANGKAT DI HERO

           Terpisah dari observer `.reveal` di atas, apesar memakai
           API yang sama.

           Alasannya bukan soal teknis: HP dan kartu belakang punya
           animasi `transform` sendiri (naik + miring), sementara
           `.reveal.shown` menulis `animation` pada `opacity`. Kalau
           keduanya dipasang di elemen yang sama, aturan `rise` akan
           menimpa `transform` mockup -- HP akan straighten dan
           kehilangan kemiringannya begitu masuk layar.

           Dipisah juga karena timing-nya beda: mockup harus muncul
           bersama hero (langsung terlihat), bukan menunggu scroll.
           Kalau ikut observer, HP baru akan muncul setelah user
           scroll ke bawah -- padahal justru HP yang membuat orang
           berhenti di layar pertama.

           Fallback wajib: kalau `IntersectionObserver` tidak ada atau
           user minta gerak minimal, `.is-in` dipasang langsung --
           tanpa itu, `opacity: 0` di gaya dasar membuat mockup hilang
           permanen.
        ============================================================ */
        (function () {
            var panel = document.querySelector('.hero-panel');
            if (!panel) return;

            var kurangiGerak = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            if (kurangiGerak || !('IntersectionObserver' in window)) {
                panel.classList.add('is-in');
                return;
            }

            var obs = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    panel.classList.add('is-in');
                    obs.unobserve(panel);
                });
            }, { threshold: 0.15 });

            obs.observe(panel);
        })();


        /* ============================================================
           MOCKUP HERO DIGESER SATU-SATUNYA

           `.phone` dan `.art-card` punya handler drag masing-masing.
           Kalau keduanya ikut bergerak karena `.hero-art` yang
           digeser, memegang kartu laporan akan terasa seperti
           memegang HP-nya juga -- padahal yang disentuh cuma satu.

           Jadi yang bergeser adalah elemen yang benar-benar ditekan.
           Klik "Laporan siap" hanya kartu itu yang bergerak, HP diam
           di tempat. Ini juga alasan batas geser dihitung per elemen:
           keduanya ada di posisi berbeda, jadi ruang kosong yang
           boleh dipakai juga berbeda.

           Yang ditulis JS hanya `--dx` dan `--dy`. Susunan transform
           dengan kemiringan desain, animasi masuk, dan ngambangnya
           disusun di CSS (lihat blok `@property`) -- bukan di sini.
        ============================================================ */
        (function () {
            var panel = document.querySelector('.hero-panel');
            if (!panel) return;

            /* Drag tetap jalan meski pengguna meminta gerak minimal.
               Yang dimatikan hanya "pegas" saat dilepas. */
            var kurangiGerak = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            /* Dipisah per elemen: posisi geser dibaca dari dataset-nya
               sendiri, bukan dari satu variabel bersama. Kalau
               berbagi variabel, menggeser kartu ikut menggeser HP. */
            var daftar = document.querySelectorAll('.hero-art .phone, .hero-art .art-card');
            if (!daftar.length) return;

            /* Satu elemen saja boleh digeser pada satu waktu. */
            var sedang = null;

            /* Batas geser per elemen, dihitung tiap kali drag mulai.

               Yang dijaga: mockup boleh bergerak bebas di dalam panel,
               tapi tidak boleh hilang TOTAL -- harus selalu ada bagian
               yang masih terlihat supaya bisa ditarik kembali.

               Rumus per sisi: jarak tepi elemen ke tepi panel, ditambah
               sebagian besar ukuran elemen itu sendiri.

               Rumus itulah yang sebelumnya hilang, dan itu sebabnya
               mockup terasa terkunci. Versi pertama hanya mengizinkan
               gerak sejauh ruang kosong yang tersisa di sisi itu, dan
               ruang kosong itu hampir selalu nol: HP memang sengaja
               menempel di tepi kanan, kartu memang sengaja menutupi
               teks. Tidak ada ruang kosong untuk dimasuki, jadi mockup
               tidak bergerak meski jaraknya ditarik jauh.

               Contoh sisi kanan HP: jaraknya ke tepi panel sekitar
               -34px, lebar HP sekitar 300px, jadi batasnya sekitar
               220px. Cukup untuk menarik HP ke luar panel supaya teks
               di belakangnya terbuka.

               Faktor 0.85, bukan 1.0: mockup boleh dipindah hampir
               seluruhnya, tapi menyisakan sedikit yang menempel di
               tepi panel -- kalau 1.0, mockup bisa hilang persis di
               batas dan tidak ada lagi yang bisa dipegang. */
            function hitungBatas(el) {
                var p = panel.getBoundingClientRect();
                var e = el.getBoundingClientRect();

                return {
                    kiri: Math.max(0, (p.left - e.left) + e.width * 0.85),
                    atas: Math.max(0, (p.top - e.top) + e.height * 0.85),
                    kanan: Math.max(0, (e.right - p.right) + e.width * 0.85),
                    bawah: Math.max(0, (e.bottom - p.bottom) + e.height * 0.85),
                };
            }
            function geserX(el) { return parseFloat(el.dataset.dx || '0') || 0; }
            function geserY(el) { return parseFloat(el.dataset.dy || '0') || 0; }

            function mulaiGeser(event) {
                if (sedang) return;

                /* Abaikan yang bisa ditekan. Mockup tidak punya tombol
                   sungguhan, tapi `Buat Tagihan` terlihat seperti
                   tombol, dan drag di atasnya tidak ada gunanya. */
                if (event.target.closest && event.target.closest('a, button')) return;

                /* `currentTarget` = elemen yang punya listener ini,
                   yaitu persis yang ditekan. `target` bisa saja anak
                   dari elemen itu, dan menggeser `target` akan membuat
                   kartu ikut bergerak saat HP yang ditekan. */
                var el = event.currentTarget;

                sedang = el;
                el._batas = hitungBatas(el);
                el._penanda = event.pointerId;

                /* Titik awal dikurangi posisi sekarang, supaya mockup
                   tidak melompat ke titik kursor saat drag dimulai.
                   Titik itu adalah titik jangkar, bukan titik asal
                   koordinat. */
                el._mulaiX = event.clientX - geserX(el);
                el._mulaiY = event.clientY - geserY(el);

                /* `setPointerCapture` membuat event tetap terkirim ke
                   elemen ini walau pointer sudah keluar dari tepinya.
                   Tanpa itu, drag yang keluar sedikit dari mockup akan
                   "hilang" di tengah jalan. */
                if (el.setPointerCapture) {
                    try { el.setPointerCapture(event.pointerId); } catch (e) { /* tidak didukung */ }
                }

                el.classList.add('is-dragging');
            }

            function geserKan(event) {
                var el = sedang;
                if (!el || event.pointerId !== el._penanda) return;

                var b = el._batas;

                /* Empat sisi dibatasi terpisah. Mockup boleh keluar ke
                   kanan (memang begitu desainnya) tapi tidak boleh
                   keluar ke bawah panel. Kalau digabung jadi satu angka,
                   HP bisa hilang lewat bawah dan tidak ada tombol untuk
                   mengembalikannya. */
                var x = Math.min(Math.max(event.clientX - el._mulaiX, -b.kiri), b.kanan);
                var y = Math.min(Math.max(event.clientY - el._mulaiY, -b.atas), b.bawah);

                el.dataset.dx = x;
                el.dataset.dy = y;

                el.style.setProperty('--dx', x + 'px');
                el.style.setProperty('--dy', y + 'px');
            }

            function selesaiGeser(event) {
                var el = sedang;
                if (!el) return;
                if (el._penanda !== undefined && event.pointerId !== el._penanda) return;

                sedang = null;
                el.classList.remove('is-dragging');
                el._penanda = undefined;

                /* Lepaskan capture supaya klik berikutnya di halaman
                   lain tidak lagi dialihkan ke elemen ini. */
                if (el.releasePointerCapture && event.pointerId !== undefined) {
                    try { el.releasePointerCapture(event.pointerId); } catch (e) { /* sudah lepas */ }
                }
            }

            daftar.forEach(function (el) {
                el.addEventListener('pointerdown', mulaiGeser);
                if (kurangiGerak) el.classList.add('no-spring');
            });

            /* Listener di `window`, bukan di tiap elemen. Kalau hanya
               di elemennya, pointer yang keluar sedikit dari mockup
               tidak mengirim event apa pun, dan `.is-dragging` nyangkut
               selamanya -- mockup terkunci dan tidak bisa digeser lagi. */
            window.addEventListener('pointermove', geserKan);
            window.addEventListener('pointerup', selesaiGeser);
            window.addEventListener('pointercancel', selesaiGeser);

            /* `resize` mengubah ukuran panel, jadi batas lama tidak lagi
               benar. Keduanya dikembalikan ke tengah supaya tidak
               menggantung di posisi yang sudah tidak valid -- termasuk
               setelah layar ponsel dirotasi. */
            window.addEventListener('resize', function () {
                daftar.forEach(function (el) {
                    el.dataset.dx = '0';
                    el.dataset.dy = '0';
                    el.style.setProperty('--dx', '0px');
                    el.style.setProperty('--dy', '0px');
                });
            });
        })();

/* ============================================================
           KARTU DASHBOARD DI-DORONG KURSOR (TILT 3D)

           Port dari pola SIDBM (`onHeroMouseMove` di
           resources/js/Pages/Home.vue), tanpa GSAP. Di SIDBM peran
           `gsap.to` digantikan dua hal: `--mx`/`--my` yang ditulis
           langsung saat kursor bergerak, dan `@keyframes
           mock-spring-back` saat kursor lepas. `--spring` yang sudah
           ada di halaman ini (cubic-bezier(.34, 1.56, .64, 1))
           dipakai sebagai padanan kasar dari `elastic.out`.

           Sudut 7 derajat, sama dengan angka yang dipakai di SIDBM.

           Yang TIDAK ikut: pill dekoratif yang bergerak lebih cepat
           (`transX * 1.5`). Di SIDBM itu ada karena pill-nya lapisan
           yang sengaja bergerak lebih cepat dari kartu utamanya;
           mockup dashboard tidak punya elemen seperti itu, jadi
           tidak ada yang perlu ikut bergerak.

           Kenapa handler-nya `pointer*` dan bukan `mouse*`:
           `pointer*` otomatis mencakup pena. Di layar sentuh
           handler memang tidak dipasang (lihat guard di bawah),
           dan itu benar -- tidak ada yang bisa "mendorong" sesuatu
           dengan jari.

           Fallback: kalau pengguna minta gerak minimal, handler
           tidak dipasang sama sekali dan kartu tetap datar seperti
           biasa. Tidak ada keadaan yang harus dibersihkan. */
        (function () {
            var kartu = document.querySelector('.mock');
            if (!kartu) return;

            /* `hover: hover` + `pointer: fine` memblokir layar
               sentuh. Tanpa guard ini, di HP setiap menyentuh kartu
               akan memiringkannya sedikit lalu memegas balik saat
               jari diangkat -- dan itu terbaca sebagai lag, bukan
               sebagai dorongan. */
            if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

            var MAKS = 7;
            var setengahW = 0;
            var setengahH = 0;

            function ukur() {
                var r = kartu.getBoundingClientRect();
                setengahW = r.width / 2;
                setengahH = r.height / 2;

                /* Titik tengah disimpan dalam koordinat viewport,
                   sama dengan `clientX`/`clientY` dari event --
                   itu yang membuat perhitungannya cuma selisih. */
                kartu._cx = r.left + setengahW;
                kartu._cy = r.top + setengahH;
            }

            function miringkan(e) {
                if (!kartu._cx) ukur();

                /* `Math.max(1, ...)` menjaga pembagi tidak nol kalau
                   kartu belum sempat terukur (display:none, atau
                   diukur sebelum font selesai dimuat). */
                var dx = (e.clientX - kartu._cx) / Math.max(1, setengahW);
                var dy = (e.clientY - kartu._cy) / Math.max(1, setengahH);

                /* Batas [-1, 1]. Tanpa clamp, kursor yang sudah keluar
                   dari tepi kartu masih punya koordinat yang jauh dari
                   titik tengah -- sudutnya bisa jauh melewati 7 derajat
                   dan kartu terlihat jatuh ke samping. */
                dx = Math.max(-1, Math.min(1, dx));
                dy = Math.max(-1, Math.min(1, dy));

                /* Tanda `--my` dibalik dari koordinat kursor: kursor
                   di ATAS kartu berarti dy negatif, dan `rotateX`
                   positif membuat tepi atas kartu condong ke
                   belakang -- kartu terlihat terdorong ke bawah,
                   mengikuti arah kursor.

                   Tanpa pembalikan ini, kartunya bergerak berlawanan
                   dengan kursor: gerak yang dilakukan
                   terasa "menarik", bukan "mendorong". */
                kartu.style.setProperty('--mx', (dx * MAKS).toFixed(2) + 'deg');
                kartu.style.setProperty('--my', (dy * MAKS).toFixed(2) + 'deg');
            }

            function mulaiMiring(e) {
                /* Animasi pegas yang masih jalan harus dihentikan
                   lebih dulu. Kalau dibiarkan, keyframes lama akan
                   terus menulis `--mx`/`--my` dan menimpa nilai baru
                   dari `pointermove` -- kartu terlihat ditarik balik
                   ke tengah sesaat setelah kursor masuk lagi. */
                kartu.classList.remove('spring-back');
                kartu.classList.add('is-tilting');
                ukur();
                miringkan(e);
            }

            function berhentiMiring() {
                if (!kartu.classList.contains('is-tilting')) return;
                kartu.classList.remove('is-tilting');

                /* Pegasnya digerakkan CSS, tapi nilainya harus ditulis
                   ke 0 lebih dulu supaya keyframes mulai dari kondisi
                   datar. Tanpa baris ini, nilai terakhir (misal
                   6deg) masih aktif dan kartu tertinggal miring
                   setelah animasi selesai. */
                kartu.style.setProperty('--mx', '0deg');
                kartu.style.setProperty('--my', '0deg');

                /* Paksa ulang animasi. Melepas dan memasang class yang
                   sama dalam frame yang sama tidak akan memicu
                   animasi baru -- browser melihat tidak ada perubahan
                   dan melewati yang kedua. `offsetWidth` yang dibaca
                   lalu dibuang adalah cara standar memaksa reflow. */
                void kartu.offsetWidth;
                kartu.classList.add('spring-back');
            }

            kartu.addEventListener('pointerenter', mulaiMiring);
            kartu.addEventListener('pointermove', miringkan);
            kartu.addEventListener('pointerleave', berhentiMiring);

            /* Ada cara kursor hilang dari kartu tanpa memicu
               `pointerleave`: jendela browser kehilangan fokus
               (user klik ke jendela lain, atau alt-tab). Kartu
               akan menggantung miring sampai halaman di-refresh.
               `blur` menutup kasus itu. */
            window.addEventListener('blur', berhentiMiring);

            /* Titik tengah yang disimpan jadi basi kalau ukuran kartu
               berubah -- termasuk setelah counter angka selesai
               mengubah lebar teks di dalam kartu. */
            window.addEventListener('resize', ukur);
        })();

        /* Header mendapat border + bayangan hanya setelah di-scroll,
           supaya tidak terlihat seperti garis asing saat masih di atas. */
        (function () {
            var header = document.querySelector('header');
            if (!header) return;

            var pakaiUdah = false;

            function perbarui() {
                if (window.scrollY > 8) {
                    if (!pakaiUdah) { header.classList.add('stuck'); pakaiUdah = true; }
                } else if (pakaiUdah) {
                    header.classList.remove('stuck'); pakaiUdah = false;
                }
            }

            window.addEventListener('scroll', perbarui, { passive: true });
            perbarui();
        })();

        /* ============================================================
           MENU MOBILE

           Panel dropdown, bukan navigasi full-screen. Alasannya: halaman
           ini punya 3 tujuan (scroll) + 1 mailto. Layar penuh untuk
           3 link itu berlebihan dan menambah langkah untuk menutupnya.

           Yang DICATAT di sini karena mudah bikin bug:
           - `aria-expanded` ikut berubah, jadi screen reader tahu status.
           - Klik link HARUS menutup panel, kalau tidak user menekan
             "tutup" di layar yang scrolld-nya sudah berganti.
           - Escape menutup panel, sesuai ekspektasi keyboard.
           - Klik di luar panel menutup juga: user menekan area gelap dan
            ZIHRAD tidak terjadi apa-apa, itu membingungkan.
           ============================================================ */
        (function () {
            var tombol = document.querySelector('.nav-toggle');
            var menu = document.getElementById('nav-menu');
            if (!tombol || !menu) return;

            function buka(apa) {
                tombol.setAttribute('aria-expanded', apa ? 'true' : 'false');
                menu.classList.toggle('is-open', apa);
                document.documentElement.classList.toggle('nav-open', apa);
                tombol.setAttribute('aria-label', apa ? 'Tutup menu' : 'Buka menu');
            }

            tombol.addEventListener('click', function () {
                buka(tombol.getAttribute('aria-expanded') !== 'true');
            });

            menu.addEventListener('click', function (e) {
                // Hanya link di dalam panel. Tombol mailto juga di
                // dalam menu, jadi filter ini tetap benar.
                if (e.target.closest('a')) buka(false);
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && tombol.getAttribute('aria-expanded') === 'true') {
                    buka(false);
                    tombol.focus();
                }
            });

            document.addEventListener('click', function (e) {
                if (tombol.getAttribute('aria-expanded') !== 'true') return;
                if (menu.contains(e.target) || tombol.contains(e.target)) return;
                buka(false);
            });

            // Panel yang terbuka saat layar diperbesar ke desktop akan
            // menggantung sebagai kotak kosong: `nav` desktop pakai
            // `display: flex` normal, jadi reset saja state-nya.
            var vpLama = window.matchMedia('(max-width: 900px)');
            var gantiVp = function (e) { if (!e.matches) buka(false); };
            if (vpLama.addEventListener) vpLama.addEventListener('change', gantiVp);
            else if (vpLama.addListener) vpLama.addListener(gantiVp);
        })();

        /* ============================================================
           SCROLL-SPY NAVBAR

           Menandai link section yang sedang dibaca. Yang aktif ditentukan
           dari section yang PUNYA bagian teratas paling dekat dengan
           batas header -- bukan dari section terakhir yang terlihat.
           Aturan ini penting: kalau pakai "terakhir yang terlihat",
           section pendek yang sudah hampir keluar layar masih ditandai
           aktif padahal yang di layar sudah section berikutnya.
           ============================================================ */
        (function () {
            var link = Array.prototype.slice.call(
                document.querySelectorAll('.nav a.navlink[href^="#"]')
            );
            if (!link.length) return;

            var peta = {};
            link.forEach(function (a) {
                var id = a.getAttribute('href').slice(1);
                var seksi = document.getElementById(id);
                if (seksi) peta[id] = { a: a, el: seksi };
            });

            var daftar = Object.keys(peta).map(function (id) { return peta[id]; });
            if (!daftar.length) return;

            function perbarui() {
                // Batas ini = tinggi header + sedikit ruang. Section yang
                // sudah lewat batas ini dianggap selesai dibaca.
                var batas = headerTinggi() + 90;
                var sekarang = null;

                daftar.forEach(function (s) {
                    if (s.el.getBoundingClientRect().top <= batas) sekarang = s;
                });

                /* Section TERAKHIR hampir tidak pernah punya `top` <= batas.
                   Dekat dasar halaman, scrollHeight sudah mentok: section
                   terakhir yang pendek tidak bisa naik melewati batas itu,
                   karena footer di bawahnya sudah memenuhi sisa viewport.
                   Aturan "pilih section terakhir yang top-nya sudah lewat"
                   akan macet di section sebelumnya, dan link nav-nya tetap
                   menyala padahal yang dibaca user adalah CTA penutup.

                   Perbaikan: kalau sudah di dasar halaman, section terakhir
                   yang menang. Diukur dari scrollY supaya tidak bergantung
                   pada tinggi footer. */
                var diBawah = (window.innerHeight + window.scrollY) >=
                              (document.documentElement.scrollHeight - 2);

                if (diBawah) sekarang = daftar[daftar.length - 1];

                // Di atas section pertama: belum ada yang aktif.
                if (!sekarang) {
                    daftar.forEach(function (s) { s.a.classList.remove('is-active'); });
                    return;
                }

                daftar.forEach(function (s) {
                    s.a.classList.toggle('is-active', s === sekarang);
                });
            }

            function headerTinggi() {
                var h = document.querySelector('header');
                return h ? h.getBoundingClientRect().height : 70;
            }

            window.addEventListener('scroll', perbarui, { passive: true });
            perbarui();
        })();

        /* ============================================================
           KEMBALI KE ATAS

           Muncul setelah scroll lewat 1 viewport. Muncul lebih awal
           tidak berguna: user masih di hero, belum butuh ascend ke
           navigation atas.

           `scrollTo({ behavior: 'smooth' })` menghormati
           `prefers-reduced-motion`? Tidak -- dia tetap mulus. Karena itu
           behavior-nya dipilih manual dari media query yang sama. */
        (function () {
            var tombol = document.querySelector('.to-top');
            if (!tombol) return;

            var minimalGerak = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var sudahTampil = false;

            function perbarui() {
                var perlu = window.scrollY > window.innerHeight * 0.9;

                if (perlu === sudahTampil) return;

                sudahTampil = perlu;
                tombol.classList.toggle('is-visible', perlu);
                // Saat disembunyikan, jangan sampai masih bisa difokus --
                // invisible button masih masuk tab order dan bisa bikin
                // fokus hilang entah ke mana.
                tombol.setAttribute('tabindex', perlu ? '0' : '-1');
            }

            tombol.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: minimalGerak ? 'smooth' : 'auto' });
            });

            window.addEventListener('scroll', perbarui, { passive: true });
            perbarui();
        })();

        /* Angka pada mockup dashboard naik dari 0 ke nilai akhir saat
           kartu masuk layar.

           Format pakai id-ID: pemisah ribuan titik dan desimal koma.
           Angka harus terlihat sama dengan yang user lihat di aplikasi,
           bukan seperti format bawaan en-US.

           Angka TETAP "Rp 0" kalau JS mati. Itu disengaja: nilai
           default di markup memang sudah "Rp 0", jadi tanpa JS orang
           masih melihat angka nol yang masuk akal -- bukan "--" atau
           string mentah. Lebih aman daripada menampilkan angka
           pecahan seperti 3.402.913 kalau animasi terputus di
           tengah jalan. */
        (function () {
            var minimGerak = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            function rupiah(n) {
                return 'Rp ' + Math.round(n).toLocaleString('id-ID');
            }

            function tampilkan(el, nilai) {
                // Tagihan/pemasukan pakai Rp; pelanggan aktif tidak.
                el.textContent = el.hasAttribute('data-money') ? rupiah(nilai)
                                : nilai.toLocaleString('id-ID');
            }

            function hitung(el) {
                var target = parseInt(el.getAttribute('data-count'), 10);
                if (!target) return;

                if (minimGerak) { tampilkan(el, target); return; }

                var durasi = 1100;
                var mulai = null;

                function langkah(waktu) {
                    if (mulai === null) mulai = waktu;

                    var progres = Math.min((waktu - mulai) / durasi, 1);

                    // easeOutExpo: melambat mendekati target. Ease-out linier
                    // terasa mekanis -- angka terakhir terlihat "menempel".
                    var eased = progres === 1 ? 1 : 1 - Math.pow(2, -10 * progres);

                    tampilkan(el, target * eased);

                    if (progres < 1) requestAnimationFrame(langkah);
                }

                requestAnimationFrame(langkah);
            }

            var targetAngka = document.querySelectorAll('[data-count]');
            if (!targetAngka.length) return;

            if (!('IntersectionObserver' in window)) {
                Array.prototype.forEach.call(targetAngka, function (el) {
                    tampilkan(el, parseInt(el.getAttribute('data-count'), 10));
                });
                return;
            }

            var obs = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    hitung(entry.target);
                    obs.unobserve(entry.target);
                });
            }, { threshold: 0.4 });

            Array.prototype.forEach.call(targetAngka, function (el) { obs.observe(el); });
        })();
    </script>
</body>
    </html>
