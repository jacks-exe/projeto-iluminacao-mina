<script setup lang="ts">
import { computed } from 'vue'
import { MODOS, getModo, pwmToPercent } from '@/config/scada'
import type { ModoIluminacao, Torre } from '@/types/scada'

const props = defineProps<{
  torre: Torre
  disabled?: boolean
  pending?: boolean
  highlighted?: boolean
}>()

const emit = defineEmits<{
  select: [modo: ModoIluminacao]
}>()

const manut = computed(() => props.torre.statusOperacional === 'EM_MANUTENCAO')
const bloqueado = computed(() => props.disabled || manut.value)
const atual = computed(() => getModo(props.torre.modoIluminacao))
const percent = computed(() => (manut.value ? 0 : pwmToPercent(props.torre.nivelBrilhoAtual ?? atual.value.pwm)))
const bateria = computed(() =>
  typeof props.torre.bateria === 'number' ? Math.round(props.torre.bateria) : null,
)
</script>

<template>
  <div
    class="flex min-w-0 flex-col gap-2 rounded-lg border p-2.5 transition-all duration-300"
    :class="
      manut
        ? 'border-fault/40 bg-panel-2/60'
        : highlighted
          ? 'border-ore bg-ore/10 ring-1 ring-ore/60 shadow-[0_0_20px_-4px_rgba(217,119,6,0.55)]'
          : 'border-line bg-panel-2/60'
    "
  >
    <div class="flex items-center justify-between gap-1.5">
      <div class="flex items-center gap-1.5">
        <span
          class="size-1.5 rounded-full transition-colors duration-500"
          :style="{ backgroundColor: manut ? '#EF4444' : atual.color, boxShadow: !manut && atual.pwm ? `0 0 6px ${atual.color}` : 'none' }"
        />
        <span class="font-mono text-xs font-semibold" :class="highlighted ? 'text-ore' : 'text-ink'">
          {{ torre.codigo }}
        </span>
        <span
          v-if="bateria !== null"
          class="rounded border border-line/80 bg-abyss/70 px-1 py-px font-mono text-[9px] tabular-nums"
          :class="bateria < 30 ? 'text-fault' : 'text-clay'"
          title="Carga da bateria / nobreak da torre"
        >
          {{ bateria }}%
        </span>
        <span v-if="pending" class="size-1.5 animate-pulse rounded-full bg-ore" title="Comando em andamento" />
      </div>
      <span v-if="manut" class="text-[9px] font-semibold uppercase tracking-wider text-fault">Manutenção</span>
      <span v-else class="font-mono text-[10px] tabular-nums text-mute">
        PWM <span class="text-ink">{{ torre.nivelBrilhoAtual ?? 0 }}</span> · {{ percent }}%
      </span>
    </div>

    <!-- Seletor segmentado -->
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
        :aria-checked="atual.id === m.id && !manut"
        :disabled="bloqueado"
        :title="`${m.label} — PWM ${m.pwm}`"
        class="rounded-[5px] py-1.5 font-mono text-[10px] font-semibold tracking-wider transition-all duration-200 focus-visible:outline-1 focus-visible:outline-ore disabled:cursor-not-allowed"
        :class="
          atual.id === m.id && !manut
            ? 'text-abyss'
            : bloqueado
              ? 'text-mute/30'
              : 'text-mute hover:bg-panel-2 hover:text-ink'
        "
        :style="
          atual.id === m.id && !manut
            ? { backgroundColor: m.id === 'DESLIGADA' ? '#6B7280' : m.color, boxShadow: m.pwm ? `0 0 12px -2px ${m.color}` : 'none' }
            : undefined
        "
        @click="emit('select', m.id)"
      >
        {{ m.sigla }}
      </button>
    </div>
  </div>
</template>
