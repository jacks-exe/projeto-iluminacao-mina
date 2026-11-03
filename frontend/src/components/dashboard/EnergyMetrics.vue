<script lang="ts">
import { ref as refGlobal } from 'vue'

/* Energia da sessão fica fora do setup para não zerar ao trocar de aba */
const energiaSessaoGlobal = refGlobal(0)
</script>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { currentPermissions } from '@/config/auth'
import { PINOS_POSTES, POTENCIA_NOMINAL_W, PWM_MAX, getModo } from '@/config/scada'
import { useTorres } from '@/composables/useTorres'
import type { Torre } from '@/types/scada'

const props = defineProps<{ torres: Torre[] }>()

defineEmits<{
  (e: 'abrir-analytics'): void
}>()

const { atualizarSensoresVirtuais } = useTorres()
const permissoes = computed(() => currentPermissions.value)

const porTorre = computed(() =>
  props.torres.map((t, idx) => {
    const manut = t.statusOperacional === 'EM_MANUTENCAO'
    const offline = t.conectado === false
    const inoperante = manut || offline
    const pwm = inoperante ? 0 : (t.nivelBrilhoAtual ?? getModo(t.modoIluminacao).pwm)
    const pino = t.pinoPwm >= 2 && t.pinoPwm <= 6 ? t.pinoPwm : (PINOS_POSTES[idx] ?? 2 + idx)
    return {
      codigo: t.codigo,
      pino,
      w: (POTENCIA_NOMINAL_W * pwm) / PWM_MAX,
      ratio: pwm / PWM_MAX,
      color: inoperante ? '#EF4444' : getModo(t.modoIluminacao).color,
    }
  }),
)

const capacidadeW = computed(() => POTENCIA_NOMINAL_W * Math.max(props.torres.length, 1))
const cargaW = computed(() => porTorre.value.reduce((s, t) => s + t.w, 0))
const cargaPct = computed(() => Math.round((cargaW.value / capacidadeW.value) * 100))
const economiaPct = computed(() => 100 - cargaPct.value)

/* Controle Virtual de Bateria (UPS) e Luminosidade (LDR) via Software */
const bateriaVirtual = ref(92)
const ldrVirtual = ref(310)
const editandoSensores = ref(false)

watch(
  () => props.torres,
  (lista) => {
    if (editandoSensores.value || !lista.length) return
    const t0 = lista[0]
    if (typeof t0?.bateria === 'number') bateriaVirtual.value = Math.round(t0.bateria)
    if (typeof t0?.ldrAtual === 'number') ldrVirtual.value = Math.round(t0.ldrAtual)
  },
  { immediate: true },
)

const condicaoLuzLabel = computed(() => (ldrVirtual.value >= 450 ? 'Dia (Luz Alta)' : 'Noite (Escuro)'))

async function aplicarSensores(bateria = bateriaVirtual.value, ldr = ldrVirtual.value) {
  if (!permissoes.value.canAdjustVirtualSensors) return
  bateriaVirtual.value = bateria
  ldrVirtual.value = ldr
  editandoSensores.value = false
  await atualizarSensoresVirtuais(bateria, ldr, true)
}

/** Integração simples da potência ao longo da sessão (kWh) */
const energiaSessaoKwh = energiaSessaoGlobal
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
  <section class="scada-card flex flex-col gap-4 p-6">
    <div class="flex items-center justify-between">
      <h2 class="scada-label">Consumo & Sensores Virtuais</h2>
      <span class="text-[9px] uppercase tracking-wider text-neutral-500">MEGA · Pinos PWM 2–6</span>
    </div>

    <div class="flex items-end justify-between">
      <div>
        <div class="font-mono text-2xl font-extrabold leading-none tabular-nums text-white">
          {{ fmt(cargaW / 1000) }}<span class="ml-1 text-xs text-mute">kW</span>
        </div>
        <div class="mt-0.5 text-[10px] text-mute">
          {{ Math.round(cargaW) }} W de {{ fmt(capacidadeW / 1000, 1) }} kW instalados
        </div>
      </div>
      <div class="text-right">
        <div class="font-mono text-base font-bold tabular-nums text-white">{{ cargaPct }}%</div>
        <div class="text-[9px] uppercase tracking-wider text-mute">carga</div>
      </div>
    </div>

    <!-- Barra segmentada por torre -->
    <div class="flex h-2 gap-0.5 overflow-hidden rounded-full bg-panel-2">
      <div
        v-for="t in porTorre"
        :key="t.codigo"
        class="h-full transition-all duration-500"
        :style="{ width: `${(t.w / capacidadeW) * 100}%`, backgroundColor: t.color }"
        :title="`${t.codigo} (D${t.pino}): ${Math.round(t.w)} W`"
      />
    </div>

    <!-- Mini colunas -->
    <div class="grid grid-cols-5 gap-2">
      <div v-for="t in porTorre" :key="t.codigo" class="flex flex-col items-center gap-1">
        <div class="relative h-8 w-full overflow-hidden rounded-lg bg-panel-2">
          <div
            class="absolute inset-x-0 bottom-0 transition-all duration-500"
            :style="{ height: `${t.ratio * 100}%`, backgroundColor: t.color, opacity: 0.85 }"
          />
        </div>
        <span class="font-mono text-[9px] font-semibold tracking-wider text-mute">
          {{ t.codigo }}·D{{ t.pino }}
        </span>
      </div>
    </div>

    <!-- Simulador de Bateria e Luminosidade via Software (Substitui LDR e Potenciômetro Físicos) -->
    <div class="flex flex-col gap-3 rounded-2xl border border-white/5 bg-panel-2/60 p-3.5">
      <div class="flex items-center justify-between">
        <span class="scada-label !text-[9px]">Telemetria via Software (Sem LDR/Pot.)</span>
        <span class="text-[9px] text-neutral-400">{{ condicaoLuzLabel }}</span>
      </div>

      <!-- Controle Virtual de Luminosidade (LDR 0..1023) -->
      <div class="flex flex-col gap-1">
        <div class="flex justify-between text-[10px]">
          <span class="text-mute">Luminosidade (LDR)</span>
          <span class="font-mono text-ink">{{ ldrVirtual }} <span class="text-mute">/ 1023</span></span>
        </div>
        <input
          v-model.number="ldrVirtual"
          type="range"
          min="0"
          max="1023"
          step="10"
          :disabled="!permissoes.canAdjustVirtualSensors"
          class="h-1.5 w-full cursor-pointer appearance-none rounded-lg bg-abyss accent-ore disabled:cursor-not-allowed disabled:opacity-40"
          @mousedown="editandoSensores = true"
          @change="aplicarSensores(bateriaVirtual, ldrVirtual)"
        />
      </div>

      <!-- Controle Virtual de Bateria (UPS 0..100%) -->
      <div class="flex flex-col gap-1">
        <div class="flex justify-between text-[10px]">
          <span class="text-mute">Bateria Nobreak (UPS)</span>
          <span class="font-mono" :class="bateriaVirtual < 25 ? 'text-rose-500 font-bold' : 'text-emerald-400'">{{ bateriaVirtual }}%</span>
        </div>
        <input
          v-model.number="bateriaVirtual"
          type="range"
          min="0"
          max="100"
          step="1"
          :disabled="!permissoes.canAdjustVirtualSensors"
          class="h-1.5 w-full cursor-pointer appearance-none rounded-lg bg-abyss accent-ore disabled:cursor-not-allowed disabled:opacity-40"
          @mousedown="editandoSensores = true"
          @change="aplicarSensores(bateriaVirtual, ldrVirtual)"
        />
      </div>

      <!-- Atalhos de Cenário de Software -->
      <div class="grid grid-cols-3 gap-2 pt-1">
        <button
          type="button"
          :disabled="!permissoes.canAdjustVirtualSensors"
          class="rounded-full border border-white/10 bg-abyss py-1.5 text-[9px] text-mute hover:border-ore hover:text-ink disabled:cursor-not-allowed disabled:opacity-40"
          title="Simular Dia (LDR 750 -> Desliga Postes)"
          @click="aplicarSensores(95, 750)"
        >
          Dia (OFF)
        </button>
        <button
          type="button"
          :disabled="!permissoes.canAdjustVirtualSensors"
          class="rounded-full border border-white/10 bg-abyss py-1.5 text-[9px] text-mute hover:border-ore hover:text-ink disabled:cursor-not-allowed disabled:opacity-40"
          title="Simular Noite Normal (LDR 250, Bat 92% -> PWM 170)"
          @click="aplicarSensores(92, 250)"
        >
          Noite (170)
        </button>
        <button
          type="button"
          :disabled="!permissoes.canAdjustVirtualSensors"
          class="rounded-full border border-white/10 bg-abyss py-1.5 text-[9px] text-mute hover:border-ore hover:text-ink disabled:cursor-not-allowed disabled:opacity-40"
          title="Simular Bateria Baixa (LDR 250, Bat 18% -> Economia PWM 70)"
          @click="aplicarSensores(18, 250)"
        >
          Eco (70)
        </button>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-2 border-t border-white/5 pt-3">
      <div>
        <div class="scada-label !text-[9px]">Economia vs 100%</div>
        <div class="font-mono text-xs font-bold tabular-nums text-emerald-400">{{ economiaPct }}%</div>
      </div>
      <div class="text-right">
        <div class="scada-label !text-[9px]">Energia sessão</div>
        <div class="font-mono text-xs font-bold tabular-nums text-white">{{ fmt(energiaSessaoKwh, 3) }} kWh</div>
      </div>
    </div>
  </section>
</template>
