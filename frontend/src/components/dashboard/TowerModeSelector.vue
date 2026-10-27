<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { currentPermissions } from '@/config/auth'
import { MODOS, PINOS_POSTES, calcularConsumoW, clampPwm, getModo, pwmToPercent } from '@/config/scada'
import type { ModoIluminacao, Torre } from '@/types/scada'

const props = defineProps<{
  torre: Torre
  disabled?: boolean
  pending?: boolean
  highlighted?: boolean
}>()

const emit = defineEmits<{
  select: [modo: ModoIluminacao]
  changePwm: [pwm: number]
  togglePower: []
  toggleConn: []
}>()

const permissoes = computed(() => currentPermissions.value)
const manut = computed(() => props.torre.statusOperacional === 'EM_MANUTENCAO')
const conectado = computed(() => props.torre.conectado !== false)
const bloqueado = computed(() => props.disabled || manut.value || !conectado.value || !permissoes.value.canControlLighting)
const bloqueadoConn = computed(() => props.disabled || !permissoes.value.canManageConnectivity)
const atual = computed(() => getModo(props.torre.modoIluminacao))

const pinoDigital = computed(() => {
  const p = props.torre.pinoPwm
  if (p >= 2 && p <= 6) return p
  const idx = Math.max(0, Math.min(4, Math.abs(props.torre.id) - 1))
  return PINOS_POSTES[idx] ?? 2 + idx
})

const pwmEfetivo = computed(() => {
  if (manut.value || !conectado.value) return 0
  return clampPwm(props.torre.nivelBrilhoAtual ?? atual.value.pwm)
})

const sliderPwm = ref<number>(pwmEfetivo.value)
watch(pwmEfetivo, (novo) => {
  sliderPwm.value = novo
})

const ligada = computed(() => !manut.value && conectado.value && pwmEfetivo.value > 0)
const percent = computed(() => pwmToPercent(sliderPwm.value))
const consumoW = computed(() =>
  Math.round(
    typeof props.torre.consumoWatts === 'number' && sliderPwm.value === pwmEfetivo.value
      ? props.torre.consumoWatts
      : calcularConsumoW(sliderPwm.value, !manut.value && conectado.value),
  ),
)
const bateria = computed(() =>
  typeof props.torre.bateria === 'number' ? Math.round(props.torre.bateria) : null,
)

function aoSoltarSlider() {
  if (bloqueado.value) return
  emit('changePwm', clampPwm(sliderPwm.value))
}
</script>

<template>
  <div
    class="flex min-w-0 flex-col gap-2 rounded-lg border p-2.5 transition-all duration-300"
    :class="
      manut
        ? 'border-fault/40 bg-panel-2/60'
        : !conectado
          ? 'border-fault/30 bg-abyss/80 opacity-85'
          : highlighted
            ? 'border-ore bg-ore/10 ring-1 ring-ore/60 shadow-[0_0_20px_-4px_rgba(217,119,6,0.55)]'
            : 'border-line bg-panel-2/60'
    "
  >
    <!-- Linha 1: Identificação do Poste + Porta Digital (8..12) + Status de Conectividade + Botão Liga/Desliga -->
    <div class="flex items-center justify-between gap-1">
      <div class="flex min-w-0 items-center gap-1.5">
        <span
          class="size-2 shrink-0 rounded-full transition-colors duration-300"
          :style="{
            backgroundColor: manut || !conectado ? '#EF4444' : ligada ? atual.color : '#4B5563',
            boxShadow: ligada ? `0 0 8px ${atual.color}` : 'none',
          }"
        />
        <span class="font-mono text-xs font-bold" :class="highlighted ? 'text-ore' : 'text-ink'">
          {{ torre.codigo }}
        </span>
        <span
          class="rounded border border-ore/40 bg-ore/10 px-1 py-px font-mono text-[9px] font-bold text-ore"
          :title="`Porta Digital ${pinoDigital} do Arduino Uno`"
        >
          D{{ pinoDigital }}
        </span>
        <span v-if="pending" class="size-1.5 animate-pulse rounded-full bg-ore" title="Sincronizando com Arduino/MySQL" />
      </div>

      <div class="flex items-center gap-1">
        <!-- Badge interativo de Conectividade em Tempo Real (Admin e Técnico) -->
        <button
          type="button"
          :disabled="bloqueadoConn"
          class="flex items-center gap-1 rounded border px-1.5 py-0.5 font-mono text-[9px] font-semibold uppercase tracking-wider transition-colors disabled:cursor-not-allowed disabled:opacity-55"
          :class="
            conectado
              ? 'border-ok/40 bg-ok/10 text-ok hover:bg-ok/20'
              : 'border-fault/50 bg-fault/15 text-fault hover:bg-fault/25'
          "
          :title="
            !permissoes.canManageConnectivity
              ? 'Apenas Administrador e Técnico podem alterar a conectividade física do poste'
              : conectado
                ? 'Poste Online — clique para simular queda/reconexão'
                : 'Poste Offline — clique para reconectar'
          "
          @click="emit('toggleConn')"
        >
          <span class="size-1.5 rounded-full" :class="conectado ? 'bg-ok animate-pulse' : 'bg-fault'" />
          {{ conectado ? 'ON' : 'OFFLINE' }}
        </button>

        <!-- Botão de Acionamento Rápido (Power ON / OFF) -->
        <button
          type="button"
          :disabled="bloqueado"
          :aria-pressed="ligada"
          :title="ligada ? `Desligar ${torre.codigo} (Porta D${pinoDigital})` : `Acionar ${torre.codigo} (Porta D${pinoDigital})`"
          class="rounded border px-1.5 py-0.5 font-mono text-[9px] font-bold uppercase transition-all disabled:cursor-not-allowed disabled:opacity-35"
          :class="
            ligada
              ? 'border-ore bg-ore text-abyss shadow-[0_0_10px_-2px_rgba(217,119,6,0.7)]'
              : 'border-line bg-abyss text-mute hover:border-clay hover:text-ink'
          "
          @click="emit('togglePower')"
        >
          {{ ligada ? 'LIGADO' : 'ACIONAR' }}
        </button>
      </div>
    </div>

    <!-- Linha 2: Telemetria de PWM, Percentual, Consumo (W) e Bateria -->
    <div class="flex items-center justify-between text-[10px]">
      <span v-if="manut" class="font-semibold uppercase tracking-wider text-fault">
        ⚠ Em Manutenção
      </span>
      <span v-else-if="!conectado" class="font-semibold uppercase tracking-wider text-fault">
        ⚡ Sem Telemetria
      </span>
      <span v-else class="font-mono tabular-nums text-mute">
        PWM <strong class="text-ink">{{ sliderPwm }}</strong>
        <span class="text-mute/70">({{ percent }}%)</span>
      </span>

      <div class="flex items-center gap-1.5 font-mono text-[10px] tabular-nums">
        <span class="text-clay" title="Consumo instantâneo do poste">{{ consumoW }}W</span>
        <span
          v-if="bateria !== null"
          class="rounded border border-line/80 bg-abyss/70 px-1 py-px text-[9px]"
          :class="bateria < 30 ? 'text-fault' : 'text-mute'"
          title="Carga da bateria / nobreak da torre"
        >
          🔋{{ bateria }}%
        </span>
      </div>
    </div>

    <!-- Linha 3: Controle Interativo de Intensidade (Slider PWM 0..255) -->
    <div class="flex items-center gap-2">
      <input
        v-model.number="sliderPwm"
        type="range"
        min="0"
        max="255"
        step="5"
        :disabled="bloqueado"
        :aria-label="`Intensidade PWM de ${torre.codigo} (Porta D${pinoDigital})`"
        class="h-1.5 w-full cursor-pointer appearance-none rounded-lg bg-abyss accent-ore disabled:cursor-not-allowed disabled:opacity-35"
        @change="aoSoltarSlider"
      />
    </div>

    <!-- Linha 4: Seletor Segmentado de Modos Predefinidos -->
    <div
      class="grid grid-cols-4 gap-0.5 rounded-md border border-line bg-abyss p-0.5"
      role="radiogroup"
      :aria-label="`Modo da torre ${torre.codigo}`"
      :aria-disabled="bloqueado"
    >
      <button
        v-for="m in MODOS"
        :key="m.id"
        type="button"
        role="radio"
        :aria-checked="atual.id === m.id && !manut && conectado"
        :disabled="bloqueado"
        :title="`${m.label} — PWM ${m.pwm}`"
        class="rounded-[5px] py-1 font-mono text-[10px] font-semibold tracking-wider transition-all duration-200 focus-visible:outline-1 focus-visible:outline-ore disabled:cursor-not-allowed"
        :class="
          atual.id === m.id && !manut && conectado
            ? 'text-abyss'
            : bloqueado
              ? 'text-mute/30'
              : 'text-mute hover:bg-panel-2 hover:text-ink'
        "
        :style="
          atual.id === m.id && !manut && conectado
            ? {
                backgroundColor: m.id === 'DESLIGADA' ? '#6B7280' : m.color,
                boxShadow: m.pwm ? `0 0 12px -2px ${m.color}` : 'none',
              }
            : undefined
        "
        @click="emit('select', m.id)"
      >
        {{ m.sigla }}
      </button>
    </div>
  </div>
</template>
