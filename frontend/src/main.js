import './assets/css/main.css'
import './assets/css/datepicker.css'
import './assets/css/stat-card.css'
import './assets/css/ui-base.css'
import './assets/css/ui-modals.css'
import './assets/css/topnav-notifications.css'
// Palet khusus role teknisi & surveyor, disamakan dengan pamsides-v2.
// Diimpor SETELAH file-file di atas karena isinya menimpa token warna
// yang mereka tetapkan, dan `applyTheme.js` menulis token yang sama
// sebagai gaya inline -- jadi di file ini harus pakai `!important`.
// Lihat catatan panjang di file itu.
import './assets/css/role-palette.css'
// Jarak tepi + lebar maksimum isi untuk seluruh halaman panel pusat.
// Diimpor di sini, bukan dari komponen: `.central-page` harus menjangkau isi
// <RouterView> yang dirender komponen lain, jadi tidak bisa dibatasi
// <style scoped>. Lihat CentralLayout.vue untuk angka --central-gutter.
import './assets/css/central-page.css'
// Palet warna, radius, dan jarak milik panel pusat. Satu tempat untuk
// dashboard dan kedua pop-up -- lihat catatan panjangnya di file itu.
import './assets/css/central-tokens.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'

import VueSweetalert2 from 'vue-sweetalert2'
import 'sweetalert2/dist/sweetalert2.min.css'

import PrimeVue from 'primevue/config'
import Aura from '@primeuix/themes/aura'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Toast from 'primevue/toast'
import ToastService from 'primevue/toastservice'
import ToggleSwitch from 'primevue/toggleswitch'
import ProgressBar from 'primevue/progressbar'
import Chart from 'primevue/chart'
import Editor from 'primevue/editor'

// Branding lokasi. Import-nya di sini (bukan di baris paling bawah file,
// tempatnya dipanggil) supaya tidak terlihat seperti afterthought.
import { muatBranding, warnaLokasi } from './composables/useBranding'
import { terapkanTema } from './utils/applyTheme'

// CSS untuk PrimeVue Editor (Quill)
import 'primeicons/primeicons.css'
import 'quill/dist/quill.snow.css'

import { library } from '@fortawesome/fontawesome-svg-core'
import { FontAwesomeIcon } from '@fortawesome/vue-fontawesome'
import {
  faFilter,
  faCalendar,
  faFileAlt,
  faFileExport,
  faSave,
  faBolt,
  faDatabase,
  faChartLine,
  faDownload,
  faCloudDownloadAlt,
  faEye,
  faBars,
  faChartBar,
  faCog,
  faChevronDown,
  faChevronRight,
  faUsers,
  faHome,
  faFileSignature,
  faBuilding,
  faArchive,
  faDollarSign,
  faTint,
  faPlus,
  faSearch,
  faBell,
  faQuestionCircle,
  faUser,
  faSignOutAlt,
  faTimes,
  faLock,
  faUnlock,
  faEyeSlash,
  faSpinner,
  faArrowLeft,
  faArrowRight,
  faArrowDown,
  faArrowUp,
  faInfoCircle,
  faWater,
  faChartColumn,
  faBuildingUser,
  faBalanceScale,
  faFileInvoice,
  faFilePowerpoint,
  faAssistiveListeningSystems,
  faCreditCard,
  faUserPlus,
  faShieldHalved,
  faCheckCircle,
  faTag,
  faExclamationTriangle,
  faHourglassHalf,
  faChevronUp,
  faIdCard,
  faMapMarkerAlt,
  faQrcode,
  faFrown,
  faCheck,
  faEdit,
  faTrash,
  faPrint,
  faCamera,
  faUserEdit,
  faKey,
  faChevronLeft,
  faImage,
  faImages,
  faFileInvoiceDollar,
  faFaucet,
  faWrench,
  faHandHoldingWater,
  faCalendarDay,
  faRedoAlt,
  faRobot,
  faHistory,
  faFileImage,
  faWeightHanging,
  faDroplet,
  faShieldAlt,
  faCloud,
  faServer,
  faGlobe,
  faEnvelope,
  faPhone,
  faBan,
  faTimesCircle,
  faSyncAlt,
  faCalendarAlt,
  faHashtag,
  faSitemap,
  faCalendarCheck,
  faClipboardList,
  faClock,
  faCheckDouble,
  faMapMarkedAlt,
  faExpand,
  faUserClock,
  faMapPin,
  faTools,
  faPlusCircle,
  faProjectDiagram,
  faBoxes,
  faHardHat,
  faWallet,
  faReceipt,
  faHeadset,
  faBullhorn,
  faSliders,
  faPaperPlane,
  faPhoneVolume,
  faShareAlt,
  faTachometerAlt,
  faTasks,
  faChartPie,
  faExclamationCircle,
  faToolbox,
  faBox,
  faClipboardCheck,
  faMoneyBillWave,
  faIdBadge,
  faLightbulb,
  faUserCircle,
  faFolderOpen,
  faFolder,
  faCirclePlus,
  faCircleInfo,
  faCircleDot,
  faCalendarDays,
  faCalendarTimes,
  faClockRotateLeft,
  faFileLines,
  faList,
  faTrashAlt,
  faTrashCan,
  faUsersSlash,
  faVenusMars,
  faVideo,
  faSync,
  faInbox,
  faMagic,
  // `book` datang dari katalog menu backend (`config/menu_access.php`) untuk
  // "Jurnal Umum" -- bukan dari penulisan manual di sidebar. Tanpa baris ini
  // modul ini gagal total saat dimuat (ReferenceError), karena `library.add`
  // berjalan sebelum `app.mount` -- gejalanya layar putih kosong di SELURUH
  // route, bukan hanya halaman yang ikonnya hilang.
  faBook,
  faBookOpen,
  faPiggyBank,
  faPeopleGroup,
  faFileContract,
  faStamp,
  faMoneyBill,
  faCube,
  faLayerGroup,
  faRetweet,
  faCommentDots,
  faPenToSquare,
  faTriangleExclamation,
  faArrowsRotate,
  faXmark,
  faUserPen,
  faCalculator,
  faPenFancy,
  faSignature,
  faPenNib,
  faUpload,
  faWandMagicSparkles,
  faRefresh,
  faBold,
  faItalic,
  faUnderline,
  faAlignLeft,
  faAlignCenter,
  faAlignRight,
  faListUl,
  faListOl,
  faTable,
  faRotateLeft,
  faRotateRight,
  faGem,
  faCode,
  faRocket,
  faQuoteLeft,

  // Ikon yang dipakai halaman Panel Pusat (`/central/*`).
  //
  // FontAwesome hanya merender ikon yang ada di `library`. Yang tidak
  // terdaftar TIDAK gagal diam-diam -- komponennya render kosong, jadi
  // tombol dan menu kehilangan ikonnya tanpa error di console.
  // Daftar di bawah ini dicocokkan dengan pemakaian di
  // `presentations/views/central/` dan `layouts/central/CentralLayout.vue`.
  faAddressBook,
  faAnglesLeft,
  faAnglesRight,
  faArrowUpRightFromSquare,
  faCircleCheck,
  faCircleExclamation,
  faGaugeHigh,
  faLink,
  faLocationDot,
  faMagnifyingGlass,
  faPen,
  faQuestion,
  faRightFromBracket,
  faRotate,
  faUserShield,
  // Halaman Domain: penanda wildcard di depan host, dan tombol "jadikan
  // domain utama". Tanpa keduanya ikon merender kosong tanpa error.
  faAsterisk,
  faStar,
  // Dashboard pusat (bento): "Langganan sudah habis" dibedakan dari
  // "Hampir habis" dengan ikon silang, bukan jam. `faCalendarTimes` di
  // halaman tagihan berarti hal lain (jadwal tagihannya), jadi keduanya
  // sengaja tidak ditukar.
  faCalendarXmark,
  faCloudArrowUp,
  faCircleXmark,
  faTableList,
  // Section "Profil Usaha" di form tenant (buat + ubah). `faBuilding` sudah
  // dipakai untuk "Nama Lembaga" di section identitas, jadi memakai ikon yang
  // sama di section yang berbeda akan membuat keduanya terlihat seperti satu
  // kelompok.
  faBriefcase,
  // Rekening bank, di tiga tempat: baris "Database" di detail tenant, baris
  // nomor rekening di bawah pilihan pada dialog pembayaran, dan baris
  // "Diterima lewat" di pop-up detail tagihan.
  //
  // Ketiganya sudah memakai `icon="building-columns"` sejak sebelum ikon ini
  // terdaftar -- dan ikon yang tidak ada di `library` TIDAK gagal, hanya
  // merender kosong. Jadi ketiga tempat itu tampil tanpa ikon sama sekali
  // tanpa ada yang complain. `faBuilding` (yang sudah terdaftar) bukan
  // pengganti: bentuknya gedung, dan yang dimaksud di sini adalah kolom
  // gerbang bank tempat nomor rekening dibaca.
  faBuildingColumns,
  // Halaman SOP > Pemakaian Air. Ikon "ketik manual" untuk pilihan kamera
  // yang TIDAK dibuka otomatis. Ikon yang tidak terdaftar di `library` TIDAK
  // gagal -- komponennya merender kosong, jadi kartu pilihan kehilangan
  // ikonnya tanpa ada error di console.
  faKeyboard,
} from '@fortawesome/free-solid-svg-icons'

import { faWhatsapp } from '@fortawesome/free-brands-svg-icons'

library.add(
  faFilter,
  faCalendarAlt,
  faCalendar,
  faFileAlt,
  faFileExport,
  faSave,
  faBolt,
  faDatabase,
  faChartLine,
  faDownload,
  faCloudDownloadAlt,
  faEye,
  faBars,
  faChartBar,
  faCog,
  faChevronDown,
  faChevronRight,
  faUsers,
  faHome,
  faFileSignature,
  faBuilding,
  faArchive,
  faDollarSign,
  faTint,
  faPlus,
  faSearch,
  faBell,
  faQuestionCircle,
  faUser,
  faSignOutAlt,
  faTimes,
  faLock,
  faUnlock,
  faEyeSlash,
  faSpinner,
  faArrowLeft,
  faArrowRight,
  faArrowDown,
  faArrowUp,
  faInfoCircle,
  faWater,
  faChartColumn,
  faBuildingUser,
  faBalanceScale,
  faFileInvoice,
  faFilePowerpoint,
  faAssistiveListeningSystems,
  faCreditCard,
  faUserPlus,
  faShieldHalved,
  faCheckCircle,
  faTag,
  faExclamationTriangle,
  faHourglassHalf,
  faChevronUp,
  faIdCard,
  faMapMarkerAlt,
  faQrcode,
  faFrown,
  faCheck,
  faEdit,
  faTrash,
  faPrint,
  faCamera,
  faUserEdit,
  faKey,
  faChevronLeft,
  faImage,
  faImages,
  faWhatsapp,
  faFileInvoiceDollar,
  faFaucet,
  faWrench,
  faHandHoldingWater,
  faCalendarDay,
  faRedoAlt,
  faRobot,
  faHistory,
  faFileImage,
  faWeightHanging,
  faDroplet,
  faShieldAlt,
  faCloud,
  faServer,
  faGlobe,
  faEnvelope,
  faPhone,
  faBan,
  faTimesCircle,
  faSyncAlt,
  faHashtag,
  faSitemap,
  faCalendarCheck,
  faClipboardList,
  faClock,
  faCheckDouble,
  faMapMarkedAlt,
  faExpand,
  faUserClock,
  faMapPin,
  faTools,
  faPlusCircle,
  faProjectDiagram,
  faBoxes,
  faHardHat,
  faWallet,
  faReceipt,
  faHeadset,
  faBullhorn,
  faSliders,
  faPaperPlane,
  faPhoneVolume,
  faShareAlt,
  faTachometerAlt,
  faTasks,
  faChartPie,
  faExclamationCircle,
  faToolbox,
  faBox,
  faClipboardCheck,
  faMoneyBillWave,
  faIdBadge,
  faLightbulb,
  faUserCircle,
  faFolderOpen,
  faFolder,
  faCirclePlus,
  faCircleInfo,
  faCircleDot,
  faCalendarDays,
  faCalendarTimes,
  faClockRotateLeft,
  faFileLines,
  faList,
  faTrashAlt,
  faUsersSlash,
  faVenusMars,
  faVideo,
  faSync,
  faInbox,
  faMagic,
  faFileSignature,
  faBookOpen,
  faPiggyBank,
  faPeopleGroup,
  // Ikon Jurnal Umum. Datang dari katalog menu backend
  // (`config/menu_access.php`), yang jadi sumber kebenaran -- bukan dari
  // penulisan manual di sidebar. Ikon yang tidak terdaftar di library ini
  // merender kosong tanpa error di console, jadi tidak ada yang memperingatkan.
  faBook,
  faFileContract,
  faStamp,
  faTrashCan,
  faMoneyBill,
  faCube,
  faLayerGroup,
  faRetweet,
  faCommentDots,
  faPenToSquare,
  faTriangleExclamation,
  faArrowsRotate,
  faXmark,
  faUserPen,
  faCalculator,
  faPenFancy,
  faSignature,
  faPenNib,
  faUpload,
  faWandMagicSparkles,
  faRefresh,
  faBold,
  faItalic,
  faUnderline,
  faAlignLeft,
  faAlignCenter,
  faAlignRight,
  faListUl,
  faListOl,
  faTable,
  faRotateLeft,
  faRotateRight,
  faGem,
  faCode,
  faRocket,
  faQuoteLeft,
  faAddressBook,
  faAnglesLeft,
  faAnglesRight,
  faArrowUpRightFromSquare,
  faCircleCheck,
  faCircleExclamation,
  faGaugeHigh,
  faLink,
  faLocationDot,
  faMagnifyingGlass,
  faPen,
  faQuestion,
  faRightFromBracket,
  faRotate,
  faUserShield,
  faAsterisk,
  faStar,
  faCalendarXmark,
  faBriefcase,
  faBuildingColumns,
  // Halaman Migrasi Data (`/central/migrasi-data`). Ikon yang tidak
  // terdaftar TIDAK gagal -- komponennya render kosong, jadi area unggah
  // kehilangan ikon besarnya tanpa ada error di console. `faCloudArrowUp`
  // justru sudah dipakai di template halaman sejak fitur ini dibuat.
  faCloudArrowUp,
  faCircleXmark,
  faTableList,
  faKeyboard,
)

export { MySwal } from './utils/swal'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)
app.use(VueSweetalert2, {
  // Pastikan SweetAlert konfirmasi selalu di depan modal package (z-[3000])
  customClass: {
    container: 'swal-container-front',
  },
})
app.use(PrimeVue, {
  theme: {
    preset: Aura,

    /*
     * Matikan deteksi dark mode PrimeVue.
     *
     * Tanpa blok `options` ini, PrimeVue memakai bawaannya, yaitu
     * `darkModeSelector: 'system'`. Itu membuat Aura ikut juror ke
     * media query prefers-color-scheme milik sistem operasi: di HP dan
     * laptop yang OS-nya gelap, token dark ikut terpasang --
     * --p-text-color jadi #fff dan --p-primary-color jadi hijau
     * emerald, bukan biru.
     *
     * Aplikasi ini memulai dari warna sendiri lewat ":root {
     * color-scheme: light }" di main.css dan tidak pernah punya varian
     * dark. Yang terjadi bukan "tema gelap setengah-setengah", tapi
     * tema gelap yang menimpa warna teks tanpa menimpa latar, sehingga
     * teks putih berada di atas kartu putih. Select PrimeVue yang
     * paling dulu menampakkan ini: placeholder-nya Abu-abu dan
     * terbaca, tapi begitu ada nilai terpilih warnanya berubah jadi
     * putih dan isinya hilang di layar.
     *
     * PENTING: opsi ini HARUS di dalam `options`. Ditulis langsung di
     * `theme`, property-nya diabaikan dan aplikasi tetap memakai
     * 'system' -- tidak ada error, tidak ada peringatan.
     *
     * Nilai 'none' berarti jangan pernah membuat blok dark sama
     * sekali. Nilai boolean false juga diterima PrimeVue dengan
     * arti yang sama, tapi 'none' lebih jelas dibaca maksudnya dan
     * tidak berubah makna kalau cara perbandingannya berubah.
     */
    options: {
      darkModeSelector: 'none',
    },
  },
  zIndex: {
    modal: 1100,
    overlay: 10000,
    menu: 1000,
    tooltip: 1100,
  },
})
app.use(ToastService)

app.component('PrimeButton', Button)
app.component('DatePicker', DatePicker)
app.component('InputNumber', InputNumber)
app.component('PrimeSelect', Select)
app.component('PrimeToast', Toast)
app.component('ProgressBar', ProgressBar)
app.component('PrimeChart', Chart)
app.component('PrimeEditor', Editor)
app.component('ToggleSwitch', ToggleSwitch)
app.component('font-awesome-icon', FontAwesomeIcon)

app.mount('#app')

/*
 * Warna lokasi.
 *
 * Dijalankan SETELAH `mount`, dan SETELAH `app.use(PrimeVue)`.
 *
 * Urutannya bukan estetika. PrimeVue menulis token `--p-primary-*` ke
 * `document.documentElement.style` saat `app.use(PrimeVue)` berjalan, jadi
 * tema yang dipasang sebelum itu akan ditimpa seluruhnya -- hasilnya tombol
 * PrimeVue tetap hijau emerald sementara sidebar dan tombol kustom sudah
 * hijau lokasi, dan keduanya terlihat di layar yang sama.
 *
 * Sesudah `mount` karena `document.documentElement` baru tentu ada setelah
 * aplikasi terpasang, dan karena branding diambil lewat request yang jelas
 * lebih lambat dari render pertama.
 *
 * Kegagalan memuat branding tidak menghentikan apa pun: `terapkanTema()` ada
 * di dalam `.then()`, jadi request yang gagal membuat warnanya tetap
 * bawaan, dan aplikasi tetap terbuka.
 */
muatBranding().then(() => {
  terapkanTema(warnaLokasi())
})
