<template>
  <div class="flex flex-col gap-6! py-2!">
    <div class="relative! p-1! bg-slate-50/50! border! border-slate-100! rounded-3xl! shadow-sm!">
      <div class="grid grid-cols-1! gap-1!">
        <div class="p-5! hover:bg-white! transition-colors! rounded-3xl!">
          <div class="flex! flex-col! gap-3!">
            <div class="flex! items-center! gap-2.5!">
              <div
                class="w-8! h-8! rounded-lg! bg-cyan-600! text-white! flex! items-center! justify-center! text-xs! shadow-lg! shadow-cyan-100!"
              >
                <font-awesome-icon icon="tachometer-alt" />
              </div>
              <div class="flex-1!">
                <h4 class="text-xs! font-black! text-slate-800! leading-tight!">
                  Kamera Otomatis saat Input Meter
                </h4>
                <p class="text-[10px]! text-slate-400!">
                  Kamera scan langsung terbuka begitu popup input pemakaian dibuka?
                </p>
              </div>
            </div>

            <!--
              Dua pilihan, bukan satu toggle.

              Alasannya: yang di sini sebenarnya ada TIGA keadaan -- belum
              pernah diatur, sengaja hidup, dan sengaja mati -- dan toggle
              hanya bisa Mewakilkan dua. Kalau toggle dipakai, "baru dibuka
              sekilas oleh admin lalu dimatikan lagi" dan "tidak pernah
              disentuh" akan berakhir di nilai yang sama, padahal yang
              pertama terlihat jelas oleh teknisi (kamera menyala sendiri)
              dan yang kedua tidak.

              Bawaan tetap "tidak dibuka otomatis": yang wajib diisi di popup
              cuma angka meter akhir. Foto meteran dan hasil scan OCR
              sama-sama opsional, jadi memaksa teknisi menunggu kamera
              terbuka setiap kali popup muncul hanya menambah waktu, bukan
              data.
            -->
            <div class="grid grid-cols-1 md:grid-cols-2! gap-4!">
              <div
                @click="form.autoOpenMeterCamera = false"
                class="group! relative! cursor-pointer! py-3.5! px-5! rounded-2xl! border-2! transition-all! duration-300!"
                :class="[
                  !form.autoOpenMeterCamera
                    ? 'border-cyan-600! bg-cyan-50/30! shadow-lg! shadow-cyan-100!'
                    : 'border-slate-100! bg-white! hover:border-cyan-200! hover:bg-slate-50/50!',
                ]"
              >
                <div class="flex! items-center! gap-4!">
                  <div
                    class="w-10! h-10! rounded-xl! flex! items-center! justify-center! transition-colors! shrink-0!"
                    :class="
                      !form.autoOpenMeterCamera
                        ? 'bg-cyan-600! text-white!'
                        : 'bg-slate-100! text-slate-400! group-hover:bg-cyan-100! group-hover:text-cyan-600!'
                    "
                  >
                    <font-awesome-icon icon="keyboard" class="text-base!" />
                  </div>
                  <div class="flex-1!">
                    <div class="flex! items-center! justify-between! mb-1!">
                      <span
                        class="font-bold! text-sm!"
                        :class="!form.autoOpenMeterCamera ? 'text-cyan-900!' : 'text-slate-700!'"
                      >
                        A. Tidak Otomatis
                      </span>
                      <font-awesome-icon
                        v-if="!form.autoOpenMeterCamera"
                        icon="check-circle"
                        class="text-cyan-600!"
                      />
                    </div>
                    <p
                      class="text-[11px]! leading-relaxed!"
                      :class="!form.autoOpenMeterCamera ? 'text-cyan-700/70!' : 'text-slate-400!'"
                    >
                      Kamera tidak menyala sendiri. Teknisi menekan tombol scan atau kamera kalau
                      memang dibutuhkan.
                    </p>
                  </div>
                </div>
              </div>

              <div
                @click="form.autoOpenMeterCamera = true"
                class="group! relative! cursor-pointer! py-3.5! px-5! rounded-2xl! border-2! transition-all! duration-300!"
                :class="[
                  form.autoOpenMeterCamera
                    ? 'border-cyan-600! bg-cyan-50/30! shadow-lg! shadow-cyan-100!'
                    : 'border-slate-100! bg-white! hover:border-cyan-200! hover:bg-slate-50/50!',
                ]"
              >
                <div class="flex! items-center! gap-4!">
                  <div
                    class="w-10! h-10! rounded-xl! flex! items-center! justify-center! transition-colors! shrink-0!"
                    :class="
                      form.autoOpenMeterCamera
                        ? 'bg-cyan-600! text-white!'
                        : 'bg-slate-100! text-slate-400! group-hover:bg-cyan-100! group-hover:text-cyan-600!'
                    "
                  >
                    <font-awesome-icon icon="camera" class="text-base!" />
                  </div>
                  <div class="flex-1!">
                    <div class="flex! items-center! justify-between! mb-1!">
                      <span
                        class="font-bold! text-sm!"
                        :class="form.autoOpenMeterCamera ? 'text-cyan-900!' : 'text-slate-700!'"
                      >
                        B. Otomatis
                      </span>
                      <font-awesome-icon
                        v-if="form.autoOpenMeterCamera"
                        icon="check-circle"
                        class="text-cyan-600!"
                      />
                    </div>
                    <p
                      class="text-[11px]! leading-relaxed!"
                      :class="form.autoOpenMeterCamera ? 'text-cyan-700/70!' : 'text-slate-400!'"
                    >
                      Kamera scan langsung menyala saat popup dibuka, siap diarahkan ke angka
                      meteran.
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <p class="text-[10px]! text-slate-400! leading-relaxed!">
              Berlaku untuk admin dan teknisi. Foto meteran dan hasil scan tetap opsional di kedua
              mode -- yang wajib diisi tetap hanya
              <strong>angka meter akhir</strong>. Perbedaannya cuma apakah teknisi perlu menekan
              tombol dulu sebelum kamera menyala.
            </p>
          </div>
        </div>
      </div>
    </div>

    <div
      class="flex! items-center! justify-center! md:justify-end! pt-4! border-t! border-slate-50!"
    >
      <BaseButton
        variant="secondary"
        size="md"
        @click="onSave"
        icon="save"
        class="w-full! md:w-auto! rounded-xl! px-8! shadow-lg! shadow-slate-200!"
      >
        Simpan Pengaturan Pemakaian
      </BaseButton>
    </div>
  </div>
</template>

<script setup>
import BaseButton from '@/presentations/components/ui/BaseButton.vue'

const form = defineModel({ required: true })

defineProps({
  onSave: {
    type: Function,
    required: true,
  },
})
</script>
