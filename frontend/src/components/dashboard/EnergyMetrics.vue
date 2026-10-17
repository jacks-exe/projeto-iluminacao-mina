<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { POTENCIA_NOMINAL_W, PWM_MAX, getModo } from '@/config/scada'
import type { Torre } from '@/types/scada'

const props = defineProps<{ torres: Torre[] }>()

const porTorre = computed(() =>
  props.torres.map((t) => {
    const manut = t.statusOperacional === 'EM_MANUTENCAO'
    const pwm = manut ? 0 : (t.nivelBrilhoAtual ?? getModo(t.modoIluminacao).pwm)
    return {
      codigo: t.codigo,
      w: (POTENCIA_NOMINAL_W * pwm) / PWM_MAX,
      ratio: pwm / PWM_MAX,
      color: manut ? '#EF4444' : getModo(t.modoIluminacao).color,
    }
  }),
)

const capacidadeW = computed(() => POTENCIA_NOMINAL_W * Math.max(props.torres.length, 1))
const cargaW = computed(() => porTorre.value.reduce((s, t) => s + t.w, 0))
const cargaPct = computed(() => Math.round((cargaW.value / capacidadeW.value) * 100))
const economiaPct = computed(() => 100 - cargaPct.value)

/** Integração simples da potência ao longo da sessão (kWh) */
const energiaSessaoKwh = ref(0)
let timer: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  timer = setInterval(() => {
    energiaSessaoKwh.value += cargaW.value / 1000 / 3600
  }, 1000)
})
onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})

const fmt = (n: number, d = 2) => n.toLocaleString('pt-BR', { minimumFractionDigits: d, maximumFractionDigits: d })
</script>

<template>
  <section class="scada-card flex flex-col gap-3 p-4">
    <div class="flex items-center justify-between">
      <h2 class="scada-label">Consumo energético</h2>
      <span class="text-[9px] uppercase tracking-wider text-mute/60">estimado</span>
    </div>

    <div class="flex items-end justify-between">
      <div>
        <div class="font-mono text-3xl font-semibold leading-none tabular-nums text-ink">
          {{ fmt(cargaW / 1000) }}<span class="ml-1 text-sm text-mute">kW</span>
        </div>
        <div class="mt-1 text-[11px] text-mute">
          de {{ fmt(capacidadeW / 1000, 1) }} kW instalados
        </div>
      </div>
      <div class="text-right">
        <div class="font-mono text-lg font-semibold tabular-nums text-clay">{{ cargaPct }}%</div>
        <div class="text-[10px] uppercase tracking-wider text-mute">carga</div>
      </div>
    </div>

    <!-- Barra segmentada por torre -->
    <div class="flex h-2 gap-0.5 overflow-hidden rounded-full bg-panel-2">
      <div
        v-for="t in porTorre"
        :key="t.codigo"
        class="h-full transition-all duration-500"
        :style="{ width: `${(t.w / capacidadeW) * 100}%`, backgroundColor: t.color }"
        :title="`${t.codigo}: ${Math.round(t.w)} W`"
      />
    </div>

    <!-- Mini colunas -->
    <div class="grid grid-cols-5 gap-2">
      <div v-for="t in porTorre" :key="t.codigo" class="flex flex-col items-center gap-1">
        <div class="relative h-10 w-full overflow-hidden rounded-sm bg-panel-2">
          <div
            class="absolute inset-x-0 bottom-0 transition-all duration-500"
            :style="{ height: `${t.ratio * 100}%`, backgroundColor: t.color, opacity: 0.85 }"
          />
        </div>
        <span class="font-mono text-[9px] text-mute">{{ t.codigo.replace('TR-', '') }}</span>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-2 border-t border-line pt-3">
      <div>
        <div class="scada-label !text-[9px]">Economia vs 100%</div>
        <div class="font-mono text-sm font-semibold tabular-nums text-ok">{{ economiaPct }}%</div>
      </div>
      <div class="text-right">
        <div class="scada-label !text-[9px]">Energia sessão</div>
        <div class="font-mono text-sm font-semibold tabular-nums text-ink">{{ fmt(energiaSessaoKwh, 3) }} kWh</div>
      </div>
    </div>
  </section>
</template>
