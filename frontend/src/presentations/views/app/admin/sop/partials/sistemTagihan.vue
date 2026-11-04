<template>
  <div class="flex flex-col gap-6! py-2!">
    <!-- Dua aturan, dua kolom: kiri tanggal, kanan jumlah bulan. -->
    <div class="grid grid-cols-1! md:grid-cols-2! gap-4!">
      <div
        class="relative! p-1! bg-slate-50/50! border! border-slate-100! rounded-3xl! shadow-sm! h-full!"
      >
        <div class="p-5! hover:bg-white! transition-colors! rounded-3xl! h-full!">
          <div class="flex! flex-col! gap-3! h-full!">
            <div class="flex! items-center! gap-2.5!">
              <div
                class="w-8! h-8! rounded-lg! bg-amber-500! text-white! flex! items-center! justify-center! text-xs! shadow-lg! shadow-amber-100!"
              >
                <font-awesome-icon icon="calendar-day" />
              </div>
              <h4 class="text-xs! font-black! text-slate-800! leading-tight!">
                Toleransi Menunggak
              </h4>
            </div>
            <p class="text-[10px]! text-slate-400!">
              Tanggal (1-31) untuk semua aturan tagihan: jatuh tempo tagihan bulanan sekaligus
              tanggal generate jurnal piutang.
            </p>
            <BaseInput
              v-model="form.toleransiTunggakan"
              placeholder="Tanggal"
              type="number"
              :min="1"
              :max="31"
              size="md"
              prefix-icon="calendar"
              :no-margin="true"
              class="w-full! mt-auto!"
            />
          </div>
        </div>
      </div>

      <div
        class="relative! p-1! bg-slate-50/50! border! border-slate-100! rounded-3xl! shadow-sm! h-full!"
      >
        <div class="p-5! hover:bg-white! transition-colors! rounded-3xl! h-full!">
          <div class="flex! flex-col! gap-3! h-full!">
            <div class="flex! items-center! gap-2.5!">
              <div
                class="w-8! h-8! rounded-lg! bg-violet-500! text-white! flex! items-center! justify-center! text-xs! shadow-lg! shadow-violet-100!"
              >
                <font-awesome-icon icon="calendar-xmark" />
              </div>
              <h4 class="text-xs! font-black! text-slate-800! leading-tight!">
                Batas Tagihan Bulanan
              </h4>
            </div>
            <p class="text-[10px]! text-slate-400!">
              Berapa bulan tunggakan yang masih ditoleransi sebelum sambungan pelanggan diputus.
            </p>
            <BaseInput
              v-model="form.batasBulanTunggakan"
              placeholder="3"
              type="number"
              :min="0"
              :max="24"
              size="md"
              prefix-icon="calendar-xmark"
              :no-margin="true"
              class="w-full! mt-auto!"
            />
            <p class="text-[10px]! text-slate-400!">
              Isi <strong class="text-slate-600!">0</strong> bila sambungan tidak boleh diputus
              otomatis sama sekali.
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Penjelasan singkat, juga dua kolom. -->
    <div class="grid grid-cols-1! md:grid-cols-2! gap-4!">
      <div
        class="p-5! bg-linear-to-r! from-indigo-900/5! to-blue-900/5! border! border-indigo-100! rounded-2xl! relative! overflow-hidden!"
      >
        <div
          class="absolute! -top-8! -right-8! w-32! h-32! bg-indigo-600/5! rounded-full! blur-3xl!"
        ></div>

        <div class="flex! items-start! gap-4! relative! z-10!">
          <div
            class="w-10! h-10! rounded-xl! bg-indigo-600! flex! items-center! justify-center! text-white! shadow-lg! shadow-indigo-100! shrink-0!"
          >
            <font-awesome-icon icon="robot" class="text-lg!" />
          </div>
          <div>
            <h4 class="text-xs! font-black! text-indigo-900! uppercase! tracking-widest! mb-1!">
              Generate Piutang Otomatis
            </h4>
            <p class="text-[11px]! font-medium! text-slate-500! leading-relaxed!">
              Jurnal piutang (abodemen, pemakaian, denda) dibuat otomatis setiap kali ada staff
              login <strong class="text-indigo-700!">pada tanggal Toleransi Menunggak</strong>.
              Tanggal 29-31 berjalan di hari terakhir bulan.
            </p>
          </div>
        </div>
      </div>

      <div
        class="p-5! bg-linear-to-r! from-violet-900/5! to-blue-900/5! border! border-violet-100! rounded-2xl! relative! overflow-hidden!"
      >
        <div
          class="absolute! -top-8! -right-8! w-32! h-32! bg-violet-600/5! rounded-full! blur-3xl!"
        ></div>

        <div class="flex! items-start! gap-4! relative! z-10!">
          <div
            class="w-10! h-10! rounded-xl! bg-violet-600! flex! items-center! justify-center! text-white! shadow-lg! shadow-violet-100! shrink-0!"
          >
            <font-awesome-icon icon="bolt" class="text-lg!" />
          </div>
          <div>
            <h4 class="text-xs! font-black! text-violet-900! uppercase! tracking-widest! mb-1!">
              Pemutusan Sambung Otomatis
            </h4>
            <p class="text-[11px]! font-medium! text-slate-500! leading-relaxed!">
              Dihitung setiap kali teknisi mencatat meter. Pelanggan yang menunggak melewati batas
              bulan di atas berstatus
              <span class="text-rose-600! font-bold!">SUSPENDED (BLOKIR)</span> sampai tunggakan
              lunas.
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
        Simpan Sistem Tagihan
      </BaseButton>
    </div>
  </div>
</template>

<script setup>
import BaseInput from '@/presentations/components/ui/BaseInput.vue'
import BaseButton from '@/presentations/components/ui/BaseButton.vue'

const form = defineModel({ required: true })

defineProps({
  onSave: {
    type: Function,
    required: true,
  },
})
</script>
