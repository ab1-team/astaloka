<template>
  <Teleport to="body">
    <div
      v-if="show"
      class="fixed! inset-0! z-[100]! flex! items-end! md:items-center! justify-center! bg-slate-900/80! backdrop-blur-sm! p-0! md:p-4! transition-all!"
      @click="closeModal"
    >
      <div
        class="bg-white! w-full! h-full! md:h-auto! md:max-w-2xl! rounded-none! md:rounded-[2.5rem]! shadow-2xl! relative! flex! flex-col! overflow-hidden! animate-in! slide-in-from-bottom-full! md:fade-in! md:zoom-in-95! duration-300!"
        @click.stop
      >
        <div class="h-2! bg-gradient-to-r! from-cyan-500! to-blue-600! w-full! shrink-0!"></div>

        <!--
          Kepala popup.

          Di HP tombol close dibuat 44px (bukan 40px) supaya pasti kena
          jari, dan judulnya turun satu tingkat supaya baris ini tetap
          muat di layar 320px tanpa terpotong.
        -->
        <div
          class="px-4! md:px-6! py-3! md:py-5! border-b! border-slate-50! flex! items-center! justify-between! gap-3! shrink-0!"
        >
          <div class="flex! items-center! gap-3! min-w-0!">
            <div
              class="w-10! h-10! shrink-0! rounded-xl! bg-cyan-50! flex! items-center! justify-center! text-cyan-600!"
            >
              <font-awesome-icon icon="water" class="text-xl!" />
            </div>
            <div class="min-w-0!">
              <h2
                class="text-base! md:text-lg! font-black! text-slate-800! leading-tight! truncate!"
              >
                Input Pemakaian
              </h2>
              <p class="text-[10px]! uppercase! tracking-widest! text-slate-400! mt-0.5! truncate!">
                Instalasi Air Bersih
              </p>
            </div>
          </div>
          <button
            @click="closeModal"
            aria-label="Tutup"
            class="w-11! h-11! md:w-10! md:h-10! shrink-0! rounded-full! bg-slate-50! text-slate-400! flex! items-center! justify-center! hover:bg-red-50! hover:text-red-500! transition-all!"
          >
            <font-awesome-icon icon="times" />
          </button>
        </div>

        <div class="p-3.5! md:p-5! overflow-y-auto! flex-1! md:max-h-[75vh]!">
          <div
            class="flex! items-center! gap-2! p-2.5! rounded-2xl! bg-gradient-to-r! from-slate-50! to-blue-50/50! border! border-slate-100! mb-4!"
          >
            <div
              class="w-8! h-8! shrink-0! rounded-lg! bg-white! flex! items-center! justify-center! text-slate-400!"
            >
              <font-awesome-icon icon="id-card" />
            </div>
            <span class="text-xs! font-bold! text-slate-600! font-mono! shrink-0!">{{
              customer.noInduk
            }}</span>
            <span class="text-slate-300! shrink-0!">•</span>
            <span class="text-xs! font-black! text-slate-900! truncate! flex-1!">{{
              customer.nama
            }}</span>
            <span class="hidden! sm:inline! text-slate-300!">•</span>
            <span class="hidden! sm:inline! text-xs! font-bold! text-slate-500!">{{
              customer.dusun
            }}</span>
          </div>

          <div class="grid! grid-cols-1! md:grid-cols-2! gap-4!">
            <div class="space-y-4!">
              <div>
                <div class="flex! items-center! gap-2! mb-1.5!">
                  <div class="w-1! h-3! bg-slate-300! rounded-full!"></div>
                  <label class="text-[9px]! font-black! text-slate-400! uppercase! tracking-widest!"
                    >Meter Awal (Bulan Lalu)</label
                  >
                </div>
                <div
                  class="flex! items-center! bg-slate-100/60! border-2! border-transparent! rounded-xl! px-4! py-2.5! opacity-70!"
                >
                  <span class="flex-1! text-sm! font-bold! text-slate-500! font-mono!">{{
                    formatMeter(customer.meterAwal)
                  }}</span>
                  <span
                    class="text-[9px]! font-black! text-slate-400! bg-slate-200/60! px-1.5! py-0.5! rounded!"
                    >M³</span
                  >
                </div>
              </div>

              <div>
                <div class="flex! items-center! gap-2! mb-1.5!">
                  <div class="w-1! h-3! bg-cyan-500! rounded-full!"></div>
                  <label class="text-[9px]! font-black! text-slate-700! uppercase! tracking-widest!"
                    >Meter Akhir (Bulan Ini)</label
                  >
                </div>
                <div
                  class="flex! items-center! bg-white! border-2! border-slate-200! rounded-xl! px-4! py-3! md:py-2.5! shadow-sm! focus-within:border-cyan-500! focus-within:ring-4! focus-within:ring-cyan-500/10! transition-all!"
                >
                  <input
                    type="text"
                    inputmode="numeric"
                    v-model="meterText"
                    ref="meterInput"
                    placeholder="0000"
                    @input="handleMeterInput"
                    @blur="handleMeterBlur"
                    class="flex-1! min-w-0! bg-transparent! border-none! outline-none! text-lg! md:text-base! font-black! text-slate-800! placeholder-slate-200! focus:text-cyan-600! font-mono!"
                  />
                  <span
                    class="text-[9px]! font-black! text-cyan-600! bg-cyan-50! px-1.5! py-0.5! rounded! shrink-0!"
                    >M³</span
                  >
                </div>
                <p class="text-[10px] text-slate-400 italic mt-1.5! flex items-center gap-1.5!">
                  <font-awesome-icon
                    v-if="isScanning"
                    icon="spinner"
                    spin
                    class="text-indigo-500"
                  />
                  <font-awesome-icon
                    v-else-if="ocrStatus === 'ok'"
                    icon="check-circle"
                    class="text-emerald-500"
                  />
                  <font-awesome-icon
                    v-else-if="ocrStatus === 'fail'"
                    icon="exclamation-circle"
                    class="text-amber-500"
                  />
                  <span v-if="isScanning">Membaca angka…</span>
                  <span v-else-if="ocrStatus === 'ok'"
                    >Terdeteksi otomatis. Periksa sebelum simpan.</span
                  >
                  <span v-else-if="ocrStatus === 'fail'">Gagal membaca, ketik manual.</span>
                  <span v-else>* Masukkan angka meteran</span>
                </p>
              </div>

              <div>
                <div class="flex! items-center! gap-2! mb-1.5!">
                  <div class="w-1! h-3! bg-indigo-500! rounded-full!"></div>
                  <label class="text-[9px]! font-black! text-slate-700! uppercase! tracking-widest!"
                    >Scan Meteran (Otomatis)</label
                  >
                </div>
                <div
                  class="relative! flex! items-center! bg-slate-900! border-2! border-slate-800! rounded-xl! overflow-hidden!"
                  style="height: 60px"
                >
                  <video
                    ref="scanVideoRef"
                    class="absolute! inset-0! w-full! h-full! object-cover! z-0!"
                    autoplay
                    playsinline
                    muted
                  ></video>

                  <!--
                    Placeholder saat kamera belum dibuka.

                    Kotak hijau (reticle) disembunyikan kalau kamera mati:
                    memunculkan garis_target di atas layar hitam yang kosong
                    menyiratkan "kamera aktif tapi tidak dapat apa-apa", padahal
                    yang sebenarnya adalah "kamera belum dinyalakan".
                  -->
                  <div
                    v-if="!isScanCameraOpen"
                    class="absolute! inset-0! flex! items-center! justify-center! z-10! bg-slate-900!"
                  >
                    <div
                      class="flex! items-center! gap-2! px-3! py-1.5! rounded-full! bg-white/10! text-slate-400! text-[9px]! font-black! uppercase! tracking-widest!"
                    >
                      <font-awesome-icon icon="camera" class="text-[9px]!" />
                      <span>Kamera Belum Aktif</span>
                    </div>
                  </div>

                  <div
                    v-else
                    class="absolute! inset-0! flex! items-center! justify-center! pointer-events-none! z-10!"
                  >
                    <div
                      class="border-2! border-emerald-400! rounded-md! shadow-[0_0_0_9999px_rgba(0,0,0,0.55)]!"
                      style="width: 75%; height: 75%"
                    >
                      <div
                        class="absolute! top-0! left-0! w-full! h-0.5! bg-emerald-400! shadow-[0_0_10px_rgba(16,185,129,0.9)]! animate-[scan_2s_infinite]!"
                      ></div>
                    </div>
                  </div>

                  <!--
                    Tombol tutup kamera scan.

                    Same seperti kamera foto di sebelah kanan: tanpa tombol
                    tutup, kamera yang sudah menyala terus berjalan sampai
                    popup ditutup -- membiarkan kamera HP menyala saat
                    teknisi sedang mengetik angka meteran.
                  -->
                  <button
                    v-if="isScanCameraOpen"
                    @click="stopScanCamera"
                    type="button"
                    class="absolute! top-2! right-2! w-6! h-6! bg-white/20! hover:bg-red-500! backdrop-blur-md! text-white! rounded-full! flex! items-center! justify-center! shadow-lg! transition-all! z-20!"
                    title="Matikan Kamera Scan"
                  >
                    <font-awesome-icon icon="times" class="text-[9px]!" />
                  </button>

                  <!--
                    Badge petunjuk di bagian bawah area kamera.

                    Badge ini disembunyikan saat kamera belum menyala:
                    saat itu placeholder "Kamera Belum Aktif" sudah
                    menjelaskan keadaannya, dan dua label yang
                    menginformasi hal yang sama membuat area kamera
                    jadi berantakan -- terutama di layar HP yang
                    areanya hanya setinggi 60px.
                  -->
                  <div
                    v-if="isScanCameraOpen"
                    class="absolute! bottom-0.5! inset-x-0! flex! justify-center! z-20!"
                  >
                    <span
                      class="px-2! py-0.5! rounded-full! bg-black/60! text-emerald-300! text-[8px]! font-black! uppercase! tracking-widest! flex! items-center! gap-1!"
                    >
                      <font-awesome-icon
                        :icon="isScanning ? 'spinner' : 'magic'"
                        :spin="isScanning"
                        class="text-[8px]!"
                      />
                      {{ scanHint }}
                    </span>
                  </div>
                </div>
                <canvas ref="scanCanvasRef" class="hidden!"></canvas>

                <!--
                  Tombol scan. Scan TIDAK lagi jalan otomatis terus-menerus.

                  Sebelumnya kamera langsung di-loop OCR tiap 500ms, jadi
                  frame terakhir yang "terbaca" (bisa saja angka acak dari
                  objek lain di belakang meter, atau salah baca digit) langsung
                  menimpa kolom Meter Akhir -- hasilnya 8 padahal meter awal 187.

                  Sekarang scan hanya jalan saat tombol ditekan, dan hasilnya
                  tidak langsung dipakai: user harus MENGAKUI dulu lewat
                  dialog konfirmasi yang menampilkan angka hasil bacaan
                  (dengan bandingan ke meter awal).

                  Kamera scan TIDAK dibuka otomatis lagi. Yang wajib diisi
                  cuma angka meter akhir; scan dan foto sama-sama opsional.
                  Jadi tombol ini yang membuka kamera -- dan kamera yang
                  belum menyala akan dinyalakan dulu sebelum frame diambil.
                -->
                <button
                  type="button"
                  @click="handleScanClick"
                  :disabled="isScanning"
                  class="mt-2! w-full! flex! items-center! justify-center! gap-2! py-3! md:py-2! px-4! rounded-lg! bg-indigo-600! text-white! font-black! text-[10px]! uppercase! tracking-wider! shadow-md! hover:bg-indigo-700! transition-all! active:scale-95! disabled:opacity-50! disabled:cursor-not-allowed! min-h-[44px]!"
                >
                  <font-awesome-icon :icon="isScanning ? 'spinner' : 'camera'" :spin="isScanning" />
                  {{ isScanning ? 'Membaca Angka…' : 'Scan Meteran Sekarang' }}
                </button>

                <p class="text-[10px] text-slate-400 italic mt-1.5! flex items-center gap-1.5!">
                  <font-awesome-icon icon="circle-info" class="text-slate-300!" />
                  <span
                    >Tidak wajib diisi — jika scan tidak digunakan, angka meteran bisa di input
                    manual di kolom Meter Akhir.</span
                  >
                </p>
              </div>
            </div>

            <div>
              <div class="flex! items-center! gap-2! mb-1.5!">
                <div class="w-1! h-3! bg-emerald-500! rounded-full!"></div>
                <label class="text-[9px]! font-black! text-slate-400! uppercase! tracking-widest!"
                  >Bukti Foto Meteran (Opsional)</label
                >
              </div>

              <div>
                <!-- LIVE CAMERA VIEW -->
                <div
                  v-if="isCameraOpen"
                  class="relative! overflow-hidden! rounded-xl! border-2! border-slate-800! bg-black! shadow-md! w-full! aspect-square!"
                >
                  <video
                    ref="videoRef"
                    class="w-full! h-full! object-cover!"
                    autoplay
                    playsinline
                  ></video>

                  <!-- Switch Camera Button -->
                  <button
                    @click="toggleCamera"
                    type="button"
                    class="absolute! top-3! left-3! w-8! h-8! bg-white/20! hover:bg-cyan-500! backdrop-blur-md! text-white! rounded-full! flex! items-center! justify-center! shadow-lg! transition-all! z-20!"
                    title="Tukar Kamera"
                  >
                    <font-awesome-icon icon="sync-alt" class="text-sm!" />
                  </button>

                  <!-- Close Camera Button -->
                  <button
                    @click="stopLiveCamera"
                    type="button"
                    class="absolute! top-3! right-3! w-8! h-8! bg-white/20! hover:bg-red-500! backdrop-blur-md! text-white! rounded-full! flex! items-center! justify-center! shadow-lg! transition-all! z-20!"
                  >
                    <font-awesome-icon icon="times" class="text-sm!" />
                  </button>

                  <!-- Snapshot Button -->
                  <div class="absolute! bottom-4! inset-x-0! flex! justify-center! z-20!">
                    <button
                      @click="takeSnapshot"
                      type="button"
                      class="w-16! h-16! rounded-full! bg-white/30! backdrop-blur-sm! border-4! border-white! flex! items-center! justify-center! hover:scale-105! active:scale-95! transition-all! shadow-lg!"
                    >
                      <div class="w-12! h-12! rounded-full! bg-white!"></div>
                    </button>
                  </div>
                </div>

                <!-- IMAGE PREVIEW VIEW -->
                <div
                  v-else-if="photoPreview"
                  class="relative! group! overflow-hidden! rounded-2xl! border-4! border-white! shadow-2xl!"
                >
                  <img
                    :src="photoPreview"
                    class="w-full! aspect-square! object-cover! group-hover:scale-105! transition-transform! duration-700!"
                  />
                  <div
                    class="absolute! inset-0! bg-gradient-to-t! from-slate-900/60! via-transparent! to-transparent!"
                  ></div>

                  <div
                    class="absolute! top-0! left-0! w-full! h-1! bg-cyan-500/50! shadow-[0_0_15px_rgba(6,182,212,0.8)]! animate-[scan_2s_infinite]!"
                  ></div>

                  <button
                    @click="removePhoto"
                    type="button"
                    class="absolute! top-4! right-4! w-10! h-10! bg-white/20! hover:bg-red-500! backdrop-blur-md! text-white! rounded-full! flex! items-center! justify-center! shadow-lg! transition-all! hover:scale-110!"
                  >
                    <font-awesome-icon icon="times" />
                  </button>

                  <div class="absolute! bottom-4! left-4! right-4!">
                    <div
                      class="flex! items-center! gap-2! bg-white/20! backdrop-blur-md! p-2! rounded-xl! border! border-white/20!"
                    >
                      <div
                        class="w-8! h-8! rounded-lg! bg-emerald-500! flex! items-center! justify-center! text-white!"
                      >
                        <font-awesome-icon icon="check" />
                      </div>
                      <div class="flex-1!">
                        <div class="text-[10px]! font-black! text-white! uppercase!">
                          Foto Terlampir
                        </div>
                        <div class="text-[8px]! font-bold! text-white/70!">SIAP DISIMPAN</div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- EMPTY STATE (SELECT METHOD) -->
                <div
                  v-else
                  class="group! relative! border-2! border-dashed! border-slate-200! rounded-xl! p-4! text-center! hover:border-cyan-500! transition-all! md:aspect-square! flex! flex-col! items-center! justify-center!"
                >
                  <div
                    class="w-12! h-12! bg-slate-50! rounded-xl! flex! items-center! justify-center! mb-3! text-slate-300! group-hover:text-cyan-500! transition-all! group-hover:rotate-6!"
                  >
                    <font-awesome-icon icon="camera" class="text-xl!" />
                  </div>
                  <p
                    class="text-[10px]! font-black! text-slate-400! uppercase! tracking-widest! mb-3!"
                  >
                    Pilih Metode Input
                  </p>

                  <div class="grid! grid-cols-2! gap-2! w-full!">
                    <button
                      type="button"
                      @click="openLiveCamera"
                      class="w-full! flex! items-center! justify-center! gap-2! py-2.5! px-3! rounded-lg! bg-cyan-600! text-white! font-black! text-[10px]! uppercase! tracking-wider! shadow-md! hover:bg-cyan-700! transition-all! active:scale-95! min-h-[44px]!"
                    >
                      <font-awesome-icon icon="video" />
                      Kamera
                    </button>
                    <button
                      type="button"
                      @click="triggerFile"
                      class="w-full! flex! items-center! justify-center! gap-2! py-2.5! px-3! rounded-lg! bg-slate-100! text-slate-600! font-black! text-[10px]! uppercase! tracking-wider! shadow-sm! hover:bg-slate-200! transition-all! active:scale-95! min-h-[44px]!"
                    >
                      <font-awesome-icon icon="folder-open" />
                      File
                    </button>
                  </div>
                </div>

                <!-- Hidden Inputs for logic -->
                <canvas ref="canvasRef" class="hidden!"></canvas>
                <input
                  type="file"
                  ref="fileInputRef"
                  class="hidden!"
                  accept="image/*"
                  @change="handleFileChange"
                />

                <p class="text-[10px] text-slate-400 italic mt-2! flex items-center gap-1.5!">
                  <font-awesome-icon icon="circle-info" class="text-slate-300!" />
                  <span
                    >Tidak wajib diisi — foto meteran hanya bukti pendukung. Data pemakaian tetap
                    bisa disimpan tanpa foto.</span
                  >
                </p>
              </div>
            </div>
          </div>
        </div>

        <!--
          Kaki popup.

          Di HP tombolnya full width dan setinggi 48px: ini satu-satunya
          jalan menyimpan meter dari popup ini, jadi targetnya dibuat
          sebesar mungkin -- teknisi sering memakai popup ini sambil
          memegang meter di tangan yang lain.
        -->
        <div
          class="p-3! md:p-4! bg-slate-50! border-t! border-slate-100! flex! items-center! justify-end! shrink-0!"
        >
          <BaseButton
            variant="secondary"
            @click="handleSave"
            class="w-full! md:w-auto! px-6! md:px-8! py-3.5! md:py-3! rounded-xl! font-black! shadow-lg! shadow-slate-200! tracking-widest! text-xs! uppercase! transition-all! min-h-[48px]!"
          >
            Simpan Data Pemakaian
          </BaseButton>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, onUnmounted, nextTick, onMounted } from 'vue'
import BaseButton from '@/presentations/components/ui/BaseButton.vue'
import { MySwal } from '@/utils/swal'
import { useSettingsStore } from '@/stores/settingsStore.js'
import { bukaKameraPadaVideo, matikanKamera, jelaskanErrorKamera } from '@/utils/cameraStream.js'

const props = defineProps({
  show: Boolean,
  customer: {
    type: Object,
    default: () => ({}),
  },
})

const emit = defineEmits(['close', 'save'])

/*
 * Pengaturan "kamera meteran terbuka otomatis".
 *
 * Store yang sama dipakai form pembayaran dan halaman tagihan bulanan, jadi
 * ini bukan request tambahan per popup: nilainya sudah diambil sekali saat
 * halaman dimuat. Endpoint-nya juga bukan `settings/sop` yang `role:admin` --
 * popup ini dipakai teknisi juga, jadi technisi harus bisa membacanya.
 */
const settingsStore = useSettingsStore()

/**
 * Angka meteran dibulatkan ke bilangan bulat.
 *
 * Meter memang dihitung dengan angka bulat (m³), tapi backend kadang
 * mengirimnya sebagai pecahan desimal -- misalnya "76.00" untuk meter
 * yang 76 m³. Kalau angka itu diteruskan mentah ke kolom input, teknisi
 * melihat "76.00" di meteran, lalu saat mengetik angka berikutnya jadi
 * "76.005" dan nilainya tidak sama dengan meter awal.
 *
 * Jadi semua angka meteran yang ditampilkan dan yang masuk ke kolom
 * input dibulatkan dulu.
 */
const angkaMeter = (val) => {
  const n = Number(val)
  if (!Number.isFinite(n)) return 0
  return Math.round(n)
}

/**
 * Format angka meteran untuk ditampilkan.
 *
 * Pemisah ribuan gaya Indonesia, dan tanpa angka desimal -- meter
 * selalu bilangan bulat.
 */
const formatMeter = (val) => angkaMeter(val).toLocaleString('id-ID')

const meterAkhir = ref(0)
const fileInputRef = ref(null)
const photoPreview = ref(null)
const selectedFile = ref(null)
const meterInput = ref(null)
const isScanning = ref(false)
const ocrStatus = ref('idle')
const isScanCameraOpen = ref(false)
const scanVideoRef = ref(null)
const scanCanvasRef = ref(null)
const scanFacingMode = ref('environment')
let scanStream = null

/**
 * Nilai terakhir yang berasal dari scan (bukan diketik user).
 *
 * Dipakai untuk tahu apakah angka di kolom Meter Akhir masih hasil scan
 * atau sudah diubah manual — supaya label status ("Terdeteksi otomatis")
 * tidak menampilkan info yang sudah tidak benar.
 */
let lastScannedValue = null

// Live Camera State
const isCameraOpen = ref(false)
const videoRef = ref(null)
const canvasRef = ref(null)
const facingMode = ref('environment')
let videoStream = null

watch(
  () => props.show,
  async (newVal) => {
    if (newVal) {
      meterAkhir.value = angkaMeter(props.customer.meterAwal)
      meterText.value = String(angkaMeter(props.customer.meterAwal))
      photoPreview.value = null
      selectedFile.value = null
      isCameraOpen.value = false
      facingMode.value = 'environment'
      isScanning.value = false
      ocrStatus.value = 'idle'
      lastScannedValue = null
      scanFacingMode.value = 'environment'
      stopScanCamera()
      document.body.style.overflow = 'hidden'

      /*
       * Kamera scan TIDAK dibuka otomatis.
       *
       * Dulu `openScanCamera()` dipanggil di sini, jadi setiap popup input
       * meter langsung menyalakan kamera. Padahal yang wajib diisi cuma
       * angka meter akhir -- foto dan scan OCR sama-sama opsional. Efeknya
       * di lapangan: tiap pencatatan meter harus menunggu kamera siap,
       * lalu menutup kamera yang tidak pernah dipakai.
       *
       * Sekarang kamera hanya menyala kalau admin menyalakannya lewat
       * pengaturan "Kamera Otomatis" di halaman SOP. Tanpa pengaturan itu,
       * popup ini langsung siap diisi.
       *
       * Setting-nya dibaca dari store, bukan dari props: yang perlu tahu
       * perilaku ini sama untuk seluruh role -- teknisi maupun admin -- dan
       * store sudah dipakai bersama di halaman lain.
       *
       * `await` di sini penting. Pengaturan diambil dari server saat popup
       * ini di-mount, jadi ada jendela waktu di mana request-nya belum
       * selesai. Kalau pemanggilannya tidak ditunggu, popup yang kebetulan
       * dibuka duluan akan membaca nilai bawaan (`false`) -- dan kameranya
       * tidak menyala padahal admin sudah menyalakannya.
       */
      await settingsStore.loadPemakaianAir()

      if (settingsStore.autoOpenMeterCamera) {
        openScanCamera()
      }
    } else {
      document.body.style.overflow = ''
      stopLiveCamera()
      stopScanCamera()
    }
  },
)

/**
 * Nyalakan kamera foto dan pasang ke pratinjau.
 *
 * `facingMode` yang dipakai adalah `ideal` -- bukan nilai string persis.
 * Alasannya ada di `utils/cameraStream.js`: `facingMode` sebagai string
 * diperlakukan `{ exact: true }` oleh browser, jadi di laptop yang kameranya
 * cuma webcam depan, permintaan "kamera belakang" gagal even though ada
 * kamera yang bisa dipakai. Dengan `ideal`, browser bebas memakai webcam
 * depan -- dan teknisi tetap bisa scan.
 */
const openLiveCamera = async () => {
  try {
    isCameraOpen.value = true
    await nextTick() // wait for video element to render
    videoStream = await bukaKameraPadaVideo({
      facingMode: facingMode.value,
      video: videoRef.value,
    })
  } catch (error) {
    isCameraOpen.value = false
    const pesan = jelaskanErrorKamera(error)
    MySwal.fire({
      icon: 'error',
      title: pesan.title,
      text: pesan.text,
      confirmButtonColor: '#f59e0b',
    })
  }
}

const toggleCamera = async () => {
  // Stream lama harus berhenti dulu: di HP, kamera depan dan belakang itu
  // SATU perangkat fisik, jadi kamera yang belum dilepas akan membuat
  // permintaan berikutnya ditolak dengan `NotReadableError`.
  matikanKamera(videoStream, videoRef.value)
  videoStream = null
  facingMode.value = facingMode.value === 'environment' ? 'user' : 'environment'

  try {
    videoStream = await bukaKameraPadaVideo({
      facingMode: facingMode.value,
      video: videoRef.value,
    })
  } catch (error) {
    const pesan = jelaskanErrorKamera(error)
    MySwal.fire({
      icon: 'warning',
      title: 'Tukar Kamera Gagal',
      text: pesan.text,
      confirmButtonColor: '#f59e0b',
    })
  }
}

const stopLiveCamera = () => {
  matikanKamera(videoStream, videoRef.value)
  videoStream = null
  isCameraOpen.value = false
}

watch(meterAkhir, (val) => {
  if (ocrStatus.value === 'ok' && Number(val) !== lastScannedValue) {
    ocrStatus.value = 'idle'
  }
})

/**
 * Teks yang benar-benar tampil di kolom input.
 *
 * Dipisah dari `meterAkhir` karena meteran sering ditulis dengan nol
 * di depan ("00187"), dan nol di depan itu berarti -- teknisi
 * membacanya langsung dari dial meter. Kalau kolomnya selalu
 * menampilkan hasil `Number(...)`, digit yang diketik jadi "187"
 * begitu saja di tengah pengetikan, dan angka yang sudah diketik
 * hilang.
 *
 * Jadi:
 *   - `meterText` = persis apa yang diketik user (untuk ditampilkan)
 *   - `meterAkhir` = angkanya (untuk dibandingkan dan disimpan)
 *
 * Nol di depan baru dibersihkan saat kolom ditinggalkan (`onBlur`),
 * bukan saat mengetik.
 */
const meterText = ref('0')

/**
 * Terapkan angka yang diketik user ke state.
 *
 * Karakter non-angka dibuang: `inputmode="numeric"` cuma menunjukkan
 * papan angka di HP, tapi keyboard fisik yang kadang muncul masih
 * bisa mengirim huruf.
 */
const handleMeterInput = () => {
  const bersih = String(meterText.value ?? '').replace(/[^\d]/g, '')
  meterText.value = bersih
  meterAkhir.value = bersih === '' ? 0 : parseInt(bersih, 10)
}

/**
 * Bersihkan kolom setelah user selesai mengetik.
 *
 * Nol di depan dihapus supaya angkanya tampil rapi ("00187" -> "187"),
 * tapi nilainya sendiri tidak berubah.
 */
const handleMeterBlur = () => {
  meterText.value = String(angkaMeter(meterAkhir.value))
}

const takeSnapshot = () => {
  if (videoRef.value && canvasRef.value) {
    const video = videoRef.value
    const canvas = canvasRef.value

    // Set canvas size to match video resolution
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight

    const ctx = canvas.getContext('2d')
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height)

    // Get image data
    photoPreview.value = canvas.toDataURL('image/jpeg', 0.8)

    // Convert to File object for upload
    canvas.toBlob(
      (blob) => {
        if (blob) {
          selectedFile.value = new File([blob], `meter_${Date.now()}.jpg`, { type: 'image/jpeg' })
        }
      },
      'image/jpeg',
      0.8,
    )

    // Stop camera feed
    stopLiveCamera()
  }
}

const triggerFile = () => {
  fileInputRef.value?.click()
}

const handleFileChange = (event) => {
  const file = event.target.files[0]
  if (file) {
    selectedFile.value = file
    const reader = new FileReader()
    reader.onload = (e) => {
      photoPreview.value = e.target.result
    }
    reader.readAsDataURL(file)
  }
}

const removePhoto = () => {
  photoPreview.value = null
  selectedFile.value = null
  if (fileInputRef.value) fileInputRef.value.value = ''
}

const handleSave = () => {
  const finalMeter = parseInt(meterAkhir.value, 10)

  if (isNaN(finalMeter) || finalMeter < 0) {
    MySwal.fire({
      icon: 'warning',
      title: 'Validasi Gagal',
      text: 'Angka meteran harus diisi dengan angka bulat yang valid!',
      confirmButtonColor: '#f59e0b',
    })
    return
  }

  if (finalMeter < angkaMeter(props.customer.meterAwal)) {
    MySwal.fire({
      icon: 'warning',
      title: 'Validasi Gagal',
      text: 'Meter akhir tidak boleh lebih kecil dari meter awal!',
      confirmButtonColor: '#f59e0b',
    })
    return
  }

  // Foto meteran hanya bukti pendukung — TIDAK wajib. Frontend tidak
  // memaksa lampiran foto; backend juga menerima `photo` kosong.
  emit('save', {
    meterValue: finalMeter,
    photo: selectedFile.value,
  })
}

const closeModal = () => {
  emit('close')
}

const openScanCamera = async () => {
  try {
    isScanCameraOpen.value = true
    await nextTick()
    scanStream = await bukaKameraPadaVideo({
      facingMode: scanFacingMode.value,
      video: scanVideoRef.value,
    })
    // TIDAK ada auto-scan di sini. Kamera hanya menampilkan pratinjau;
    // pembacaan OCR jalan saat user menekan tombol "Scan Meteran Sekarang".
  } catch (error) {
    isScanCameraOpen.value = false
    const pesan = jelaskanErrorKamera(error)
    MySwal.fire({
      icon: 'error',
      title: pesan.title,
      text: pesan.text,
      confirmButtonColor: '#f59e0b',
    })
  }
}

const stopScanCamera = () => {
  matikanKamera(scanStream, scanVideoRef.value)
  scanStream = null
  isScanCameraOpen.value = false
}

/**
 * Ambil frame dari pratinjau kamera, potong bagian tengah (kotak hijau),
 * lalu kirim ke OCR.
 *
 * Mengembalikan ANGKA hasil bacaan (atau null kalau gagal). Nilai TIDAK
 * langsung ditulis ke `meterAkhir` — pemanggil wajib memastikannya dulu ke
 * user lewat dialog konfirmasi.
 */
const captureAndScan = async () => {
  if (!scanVideoRef.value || !scanCanvasRef.value || isScanning.value || !isScanCameraOpen.value)
    return null

  const video = scanVideoRef.value
  const canvas = scanCanvasRef.value

  // Video mungkin belum punya dimensi (kamera belum siap) — frame dengan
  // lebar 0 akan menghasilkan gambar kosong yang di-OCR jadi noise.
  if (!video.videoWidth || !video.videoHeight) return null

  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  const ctx = canvas.getContext('2d')
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height)

  const cw = canvas.width
  const ch = canvas.height
  const rw = Math.round(cw * 0.75)
  const rh = Math.round(ch * 0.45)
  const rx = Math.round((cw - rw) / 2)
  const ry = Math.round((ch - rh) / 2)
  const crop = document.createElement('canvas')
  crop.width = rw
  crop.height = rh
  crop.getContext('2d').drawImage(canvas, rx, ry, rw, rh, 0, 0, rw, rh)
  const dataUrl = crop.toDataURL('image/jpeg', 0.9)

  return await scanMeterFromPhoto(dataUrl)
}

/**
 * Scan dipicu user (tombol), bukan otomatis.
 *
 * Alur: nyalakan kamera (kalau belum) → ambil frame → OCR → cek hasilnya →
 * minta konfirmasi ke user.
 *
 * Kamera dinyalakan di sini, bukan saat popup dibuka, karena yang memicu
 * scan adalah tombolnya -- bukan kemunculan popup. Kalau kameranya belum
 * menyala, `captureAndScan()` akan mengembalikan `null` dan user akan melihat
 * "Gagal Membaca Meter" padahal masalahnya cuma kamera yang belum dinyalakan.
 *
 * Konfirmasi wajib karena OCR sering salah baca (angka di belakang, kode
 * QR, label di meter). Tanpa konfirmasi, angka salah bisa langsung
 * tersimpan sebagai meter akhir dan menggeser seluruh tagihan.
 */
const handleScanClick = async () => {
  if (isScanning.value) return

  if (!isScanCameraOpen.value) {
    await openScanCamera()

    // Kamera gagal dinyalakan (izin ditolak, perangkat tidak punya kamera).
    // `openScanCamera` sudah memberi tahu user lewat dialog, jadi di sini
    // cukup berhenti -- jangan lanjut ke OCR dengan kamera yang mati.
    if (!isScanCameraOpen.value) return
  }

  const detected = await captureAndScan()

  if (detected === null) {
    ocrStatus.value = 'fail'
    MySwal.fire({
      icon: 'warning',
      title: 'Gagal Membaca Meter',
      text: 'Angka meter tidak terbaca. Arahkan kamera ke angka pada meter, atau ketik manual.',
      confirmButtonColor: '#f59e0b',
    })
    return
  }

  const meterAwal = angkaMeter(props.customer.meterAwal)

  // Sanity check: meteran tidak pernah mundur. Hasil OCR yang lebih
  // kecil dari meter awal hampir pasti salah baca (mis. "87" terbaca "8").
  if (detected < meterAwal) {
    ocrStatus.value = 'fail'
    MySwal.fire({
      icon: 'error',
      title: 'Hasil Scan Tidak Masuk Akal',
      html: `
        <div class="text-left! text-sm! leading-relaxed!">
          <p class="mb-2!">Angka yang terbaca <strong>${detected}</strong> lebih kecil dari
            meter awal <strong>${meterAwal}</strong>. Meteran tidak boleh mundur, jadi
            hasil ini kemungkinan salah baca.</p>
          <p class="text-slate-500!">Coba scan ulang dengan angka lebih jelas, atau ketik manual.</p>
        </div>
      `,
      confirmButtonColor: '#ef4444',
    })
    return
  }

  const confirmed = await MySwal.fire({
    icon: 'question',
    title: 'Konfirmasi Meter Akhir',
    html: `
      <div class="text-left! text-sm! leading-relaxed!">
        <p class="mb-3!">Meter akhir terbaca <strong>${detected} m³</strong>
          (meter awal ${meterAwal} m³, pemakaian ${detected - meterAwal} m³).</p>
        <p class="text-slate-500!">Pastikan angka ini sudah benar. Hasil scan bisa salah baca —
          kalau tidak sesuai, pilih <strong>Ulangi Scan</strong> atau isi manual.</p>
      </div>
    `,
    showCancelButton: true,
    confirmButtonText: 'Ya, Pakai Angka Ini',
    cancelButtonText: 'Ulangi Scan',
    confirmButtonColor: '#0EA5E9',
    cancelButtonColor: '#64748b',
  })

  if (confirmed.isConfirmed) {
    lastScannedValue = detected
    meterAkhir.value = detected
    meterText.value = String(detected)
    ocrStatus.value = 'ok'
  } else {
    // User menolak: angka TIDAK diubah, kolom dibiarkan apa adanya supaya
    // bisa diketik manual atau scan lagi.
    ocrStatus.value = 'idle'
  }
}

/**
 * Petunjuk singkat di area kamera.
 */
const scanHint = computed(() => {
  if (isScanning.value) return 'Membaca…'
  if (!isScanCameraOpen.value) return 'Kamera tidak aktif'
  return 'Arahkan angka ke kotak → Scan'
})

// ponytail: OCR pakai tesseract.js dari CDN (worker + core fetched on demand).
// Upgrade path: pindah ke paket npm + custom traineddata 7-segment saat akurasi rendah.
const loadTesseract = () => {
  if (window.Tesseract) return Promise.resolve(window.Tesseract)
  return new Promise((resolve, reject) => {
    const s = document.createElement('script')
    s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js'
    s.onload = () => resolve(window.Tesseract)
    s.onerror = reject
    document.head.appendChild(s)
  })
}

/**
 * Bersihkan teks hasil OCR jadi angka.
 *
 * Memakai pola 7-segment: meter biasanya menampilkan 1-6 digit
 * berurutan (mis. "00187"). Ambil digit TERAKHIR yang membentuk
 * rangkaian panjang, lalu buang digit yang obviously bukan meter (mis.
 * tahun pada stempel).
 */
const extractDigits = (text) => {
  const cleaned = (text || '').replace(/[^\d]/g, '')
  if (!cleaned) return null
  const n = parseInt(cleaned, 10)
  return Number.isFinite(n) ? n : null
}

/**
 * Jalankan OCR pada satu gambar.
 *
 * Mengembalikan angka hasil bacaan, atau null kalau tidak ada angka yang
 * terbaca / OCR gagal. Tidak pernah menulis ke `meterAkhir` — itu
 * keputusan user lewat dialog konfirmasi.
 */
const scanMeterFromPhoto = async (dataUrl) => {
  if (!dataUrl || isScanning.value) return null
  isScanning.value = true
  ocrStatus.value = 'idle'
  try {
    const Tesseract = await loadTesseract()
    const { data } = await Tesseract.recognize(dataUrl, 'eng', {
      tessedit_char_whitelist: '0123456789',
    })
    const digits = extractDigits(data?.text)
    if (digits === null) {
      ocrStatus.value = 'fail'
      return null
    }
    return digits
  } catch {
    ocrStatus.value = 'fail'
    return null
  } finally {
    isScanning.value = false
  }
}

onMounted(() => {
  /*
   * Ambil pengaturan kamera otomatis.
   *
   * Dipanggil di sini, bukan hanya di watcher `props.show`, karena popup-nya
   * bisa dibuka berkali-kali dan pemanggilannya tidak perlu berulang --
   * store sendiri yang menahan request kedua (sudah pernah di-load).
   *
   * Yang dipanggil `loadPemakaianAir()`, bukan `loadSettings()`: endpoint
   * yang terakhir itu `role:admin`, sementara popup ini dipakai teknisi
   * juga. Kalau popup bergantung pada `loadSettings()`, teknisi selalu
   * kena 403 dan pengaturannya tidak pernah masuk.
   *
   * Kegagalan tidak menggagalkan apa pun: store sudah menyimpan nilai bawaan
   * (`false` = kamera tidak dibuka otomatis), jadi popup tetap bisa dipakai.
   */
  settingsStore.loadPemakaianAir()
})

onUnmounted(() => {
  stopLiveCamera()
  stopScanCamera()
})
</script>

<style scoped>
@keyframes scan {
  0% {
    top: 0%;
    opacity: 1;
  }
  50% {
    top: 100%;
    opacity: 0.5;
  }
  100% {
    top: 0%;
    opacity: 1;
  }
}
</style>
