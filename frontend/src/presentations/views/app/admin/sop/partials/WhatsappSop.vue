<template>
  <div class="flex flex-col gap-5!">
    <!--
      Info singkat: template ini dipakai di halaman "Daftar Tagihan" (satu per
      satu) dan popup TAGIHAN di dashboard (banyak sekaligus).
    -->
    <div
      class="px-4! py-3! bg-emerald-50! border! border-emerald-100! rounded-xl! text-xs! text-emerald-800! flex! items-start! gap-2.5!"
    >
      <font-awesome-icon icon="circle-info" class="text-emerald-500! mt-0.5! shrink-0!" />
      <p class="leading-relaxed!">
        Pesan di bawah dipakai saat mengirim pengingat WhatsApp dari
        <strong>Daftar Tagihan</strong> (satu per satu) maupun popup <strong>Tagihan</strong> di
        dashboard (banyak sekaligus). Isi pesannya mengikuti template ini, jadi cukup ubah di sini
        tanpa menyunting halaman lain.
      </p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6!">
      <BaseInput
        v-model="form.templateTagihan"
        label="Pesan Tagihan"
        type="textarea"
        placeholder="Isi pesan tagihan..."
        :rows="10"
        class="w-full!"
        hint="Gunakan tag {customer}, {desa}, {jumlah_tagihan}, {periode}, {jatuh_tempo} sebagai placeholder."
      />
      <BaseInput
        v-model="form.templatePembayaran"
        label="Pesan Pembayaran"
        type="textarea"
        placeholder="Isi pesan pembayaran..."
        :rows="10"
        class="w-full!"
        hint="Gunakan tag {customer}, {desa}, {tagihan}, {periode} sebagai placeholder."
      />
    </div>

    <!--
      Pratinjau.

      Menunjukkan hasil template setelah placeholder-nya diganti data
      CONTOH. Ini bukan data pelanggan sungguhan -- cuma supaya admin
      bisa melihat sendiri bentuk pesannya sebelum dikirim ke pelanggan.
    -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6!">
      <div class="flex flex-col gap-2!">
        <span
          class="text-xs! font-black! text-slate-400! uppercase! tracking-widest! flex! items-center! gap-2!"
        >
          <font-awesome-icon icon="eye" class="text-cyan-600!" />
          Pratinjau Pesan Tagihan
        </span>
        <div
          class="bg-slate-50! border! border-slate-100! rounded-xl! p-4! text-xs! text-slate-700! leading-relaxed! whitespace-pre-wrap! min-h-[120px]! font-sans!"
        >
          {{ previewTagihan }}
        </div>
      </div>

      <div class="flex flex-col gap-2!">
        <span
          class="text-xs! font-black! text-slate-400! uppercase! tracking-widest! flex! items-center! gap-2!"
        >
          <font-awesome-icon icon="eye" class="text-cyan-600!" />
          Pratinjau Pesan Pembayaran
        </span>
        <div
          class="bg-slate-50! border! border-slate-100! rounded-xl! p-4! text-xs! text-slate-700! leading-relaxed! whitespace-pre-wrap! min-h-[120px]! font-sans!"
        >
          {{ previewPembayaran }}
        </div>
      </div>
    </div>

    <div
      class="flex flex-col sm:flex-row! sm:justify-end! items-stretch! sm:items-center! gap-3! mt-6! md:mt-4!"
    >
      <BaseButton
        variant="info-gradient"
        size="md"
        @click="openQrModal"
        icon="qrcode"
        class="w-full! sm:w-auto! rounded-xl! shadow-lg! shadow-blue-200!"
      >
        Scan Whatsapp
      </BaseButton>

      <BaseButton
        variant="secondary"
        size="md"
        @click="onSave"
        icon="save"
        class="w-full! sm:w-auto! rounded-xl! shadow-lg! shadow-slate-200!"
      >
        Simpan
      </BaseButton>
    </div>

    <!-- Teleported WhatsApp Scan Modal -->
    <Teleport to="body">
      <div
        v-if="isQrModalOpen"
        class="fixed! inset-0! z-[100]! flex! items-center! justify-center! bg-slate-900/80! backdrop-blur-sm! p-4! transition-all!"
        @click="closeQrModal"
      >
        <div
          class="bg-white! w-full! max-w-md! rounded-[2.5rem]! shadow-2xl! relative! flex! flex-col! overflow-hidden! animate-in! fade-in! zoom-in-95! duration-300!"
          @click.stop
        >
          <!-- Header Gradient Strip -->
          <div class="h-2! bg-gradient-to-r! from-emerald-500! to-teal-600! w-full!"></div>

          <!-- Modal Header -->
          <div class="px-6! py-5! border-b! border-slate-50! flex! items-center! justify-between!">
            <div class="flex! items-center! gap-3!">
              <div
                class="w-10! h-10! rounded-xl! bg-emerald-50! flex! items-center! justify-center! text-emerald-600!"
              >
                <font-awesome-icon :icon="['fab', 'whatsapp']" class="text-xl!" />
              </div>
              <div>
                <h2 class="text-lg! font-black! text-slate-800! leading-none!">
                  Scan WhatsApp API
                </h2>
                <p class="text-[9px]! uppercase! tracking-widest! text-slate-400! mt-1.5!">
                  Hubungkan Perangkat
                </p>
              </div>
            </div>
            <button
              @click="closeQrModal"
              class="w-10! h-10! rounded-full! bg-slate-50! text-slate-400! flex! items-center! justify-center! hover:bg-red-50! hover:text-red-500! transition-all!"
            >
              <font-awesome-icon icon="times" />
            </button>
          </div>

          <!-- Modal Body Content -->
          <div class="p-6! flex! flex-col! items-center! gap-6!">
            <!-- Glowing QR Code Scanner Frame -->
            <div
              class="relative! p-4! bg-slate-50! rounded-3xl! border-2! border-slate-100! shadow-inner! group!"
            >
              <!-- Neon corner borders -->
              <div
                class="absolute! top-0! left-0! w-5! h-5! border-t-4! border-l-4! border-emerald-500! -translate-x-[2px]! -translate-y-[2px]! rounded-tl-xl!"
              ></div>
              <div
                class="absolute! top-0! right-0! w-5! h-5! border-t-4! border-r-4! border-emerald-500! translate-x-[2px]! -translate-y-[2px]! rounded-tr-xl!"
              ></div>
              <div
                class="absolute! bottom-0! left-0! w-5! h-5! border-b-4! border-l-4! border-emerald-500! -translate-x-[2px]! translate-y-[2px]! rounded-bl-xl!"
              ></div>
              <div
                class="absolute! bottom-0! right-0! w-5! h-5! border-b-4! border-r-4! border-emerald-500! translate-x-[2px]! translate-y-[2px]! rounded-br-xl!"
              ></div>

              <div
                class="relative! w-48! h-48! bg-white! rounded-2xl! flex! items-center! justify-center! p-2! overflow-hidden! shadow-sm!"
              >
                <!-- Animated Scanner Laser -->
                <div
                  class="absolute! left-0! right-0! h-1! bg-emerald-500/50! shadow-[0_0_12px_rgba(16,185,129,0.8)]! animate-[scan_2.5s_infinite_linear]! z-10!"
                ></div>

                <!-- Styled High-Quality Mock QR Code with central WA logo -->
                <svg
                  class="w-full! h-full!"
                  viewBox="0 0 100 100"
                  fill="currentColor"
                  text-slate-800
                >
                  <path
                    d="M5 5h30v30H5zm6 6h18v18H11zm6 6h6v6h-6zM65 5h30v30H65zm6 6h18v18H71zm6 6h6v6h-6zM5 65h30v30H5zm6 6h18v18H11zm6 6h6v6h-6z"
                  />
                  <path
                    d="M45 10h10v10H45zm5 25h10v10H50zm30 20h10v10H80zM45 65h10v10H45zm15 15h10v10H60zm15 0h10v10H75zM80 80h10v10H80z"
                  />
                  <path d="M45 45h10v10H45zm15-15h10v10H60zm10 20h10v10H70zm-25 5h10v10H45z" />
                </svg>

                <!-- Central WhatsApp Badge -->
                <div
                  class="absolute! w-10! h-10! bg-white! rounded-xl! flex! items-center! justify-center! shadow-md! z-20! border! border-slate-100!"
                >
                  <font-awesome-icon
                    :icon="['fab', 'whatsapp']"
                    class="text-emerald-500! text-xl!"
                  />
                </div>
              </div>
            </div>

            <!-- Scanner Connection Status -->
            <div
              class="flex! items-center! gap-2.5! bg-emerald-50! border! border-emerald-100! px-4! py-2! rounded-2xl!"
            >
              <span class="flex! h-2! w-2! relative!">
                <span
                  class="animate-ping! absolute! inline-flex! h-full! w-full! rounded-full! bg-emerald-400! opacity-75!"
                ></span>
                <span class="relative! inline-flex! rounded-full! h-2! w-2! bg-emerald-500!"></span>
              </span>
              <span class="text-[10px]! font-black! text-emerald-700! uppercase! tracking-widest!"
                >Menunggu Pemindaian...</span
              >
            </div>

            <!-- Steps Instructions -->
            <div
              class="w-full! space-y-3! bg-slate-50! p-4! rounded-2xl! border! border-slate-100!"
            >
              <h4 class="text-xs! font-black! text-slate-700! uppercase! tracking-wider! mb-1!">
                Cara Menghubungkan:
              </h4>
              <ol
                class="text-left! text-xs! text-slate-500! space-y-2! list-decimal! pl-4! font-medium!"
              >
                <li>
                  Buka aplikasi <span class="font-bold! text-slate-700!">WhatsApp</span> di telepon
                  seluler Anda.
                </li>
                <li>
                  Ketuk <span class="font-bold! text-slate-700!">Menu</span> atau
                  <span class="font-bold! text-slate-700!">Pengaturan</span> dan pilih
                  <span class="font-bold! text-slate-700!">Perangkat Tertaut</span>.
                </li>
                <li>
                  Ketuk <span class="font-bold! text-slate-700!">Tautkan Perangkat</span> lalu
                  arahkan kamera HP Anda ke layar QR Code ini.
                </li>
              </ol>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import BaseInput from '@/presentations/components/ui/BaseInput.vue'
import BaseButton from '@/presentations/components/ui/BaseButton.vue'

const form = defineModel({ required: true })

defineProps({
  onSave: {
    type: Function,
    required: true,
  },
})

const isQrModalOpen = ref(false)

const openQrModal = () => {
  isQrModalOpen.value = true
}

const closeQrModal = () => {
  isQrModalOpen.value = false
}

/*
 * Pratinjau pesan.
 *
 * Placeholder-nya diganti data CONTOH, bukan data pelanggan sungguhan --
 * halaman SOP ini tidak tahu pelanggan mana yang akan dikirimi pesan.
 * Tujuannya cuma supaya admin bisa melihat sendiri bentuk pesan yang akan
 * terkirim, termasuk kalau ada placeholder yang salah ketik.
 */
const CONTOH = {
  customer: 'Bapak Budi Santoso',
  desa: 'Desa Sukamaju',
  jumlah_tagihan: 'Rp. 125.000',
  tagihan: 'Rp. 125.000',
  periode: 'Agustus 2026',
  jatuh_tempo: '10 September 2026',
}

const renderPreview = (template) => {
  let teks = String(template || '')

  for (const [key, value] of Object.entries(CONTOH)) {
    teks = teks.split(`{${key}}`).join(value)
  }

  return teks.trim() || 'Belum ada pesan.'
}

const previewTagihan = computed(() => renderPreview(form.value?.templateTagihan))
const previewPembayaran = computed(() => renderPreview(form.value?.templatePembayaran))
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
