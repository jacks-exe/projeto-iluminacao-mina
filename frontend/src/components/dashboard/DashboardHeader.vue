<script setup lang="ts">
import { computed } from 'vue'
import { useClock } from '@/composables/useClock'
import type { ConnectionState } from '@/types/scada'

const props = defineProps<{
  connection: ConnectionState
  lastSync: Date | null
  simulacao?: boolean
}>()

const emit = defineEmits<{
  toggleSimulacao: []
  resetSimulacao: []
}>()

const { hora, data } = useClock()

const status = computed(() => {
  if (props.simulacao || props.connection === 'online') {
    return {
      label: 'Conexão: Online',
      dot: 'bg-ok',
      ring: 'bg-ok',
      chip: 'border-ok/30 bg-ok/10 text-ok',
    }
  }
  switch (props.connection) {
    case 'offline':
      return { label: 'Conexão: Offline', dot: 'bg-fault', ring: '', chip: 'border-fault/30 bg-fault/10 text-fault' }
    default:
      return { label: 'Conectando…', dot: 'bg-ore', ring: 'bg-ore', chip: 'border-ore/30 bg-ore/10 text-ore' }
  }
})

const syncLabel = computed(() =>
  props.lastSync
    ? props.lastSync.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
    : '--:--:--',
)
</script>

<template>
  <header class="flex h-14 shrink-0 items-center justify-between border-b border-line bg-panel px-4">
    <!-- Marca -->
    <div class="flex items-center gap-3">
      <div
        class="grid size-9 place-items-center rounded-lg border border-ore/40 bg-gradient-to-br from-ore/25 to-ember/10 shadow-[0_0_18px_-4px_rgba(217,119,6,0.55)]"
      >
        <svg viewBox="0 0 24 24" class="size-5 text-ore" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path d="M12 2v3M5.6 5.6l2.1 2.1M18.4 5.6l-2.1 2.1" stroke-linecap="round" />
          <path d="M8 13a4 4 0 1 1 8 0c0 1.6-.8 2.5-1.5 3.3-.4.5-.5 1-.5 1.7h-4c0-.7-.1-1.2-.5-1.7C8.8 15.5 8 14.6 8 13Z" />
          <path d="M10 21h4" stroke-linecap="round" />
        </svg>
      </div>
      <div class="leading-tight">
        <h1 class="text-[15px] font-semibold tracking-wide text-ink">
          MINA<span class="text-ore">·</span>IoT
          <span class="ml-1 font-normal text-mute">Controle de Iluminação</span>
        </h1>
        <p class="scada-label !text-[9px] !tracking-[0.22em] text-clay/80">SCADA · Cava Principal · Arduino PWM</p>
      </div>
    </div>

    <!-- Status + relógio -->
    <div class="flex items-center gap-4">
      <div class="hidden items-center gap-2 font-mono text-[11px] text-mute lg:flex">
        <span class="scada-label !tracking-[0.14em]">Últ. sync</span>
        <span class="tabular-nums text-ink/80">{{ syncLabel }}</span>
      </div>

      <!-- Toggle discreto do Modo Simulação / Demo -->
      <div class="flex items-center gap-1">
        <button
          type="button"
          class="flex items-center gap-1.5 rounded-full border px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wider transition-all"
          :class="
            simulacao
              ? 'border-ore/50 bg-ore/15 text-ore shadow-[0_0_12px_-3px_rgba(217,119,6,0.45)]'
              : 'border-line bg-panel-2 text-mute hover:border-clay hover:text-ink'
          "
          :aria-pressed="simulacao"
          title="Alternar Modo Simulação (Mock Local vs Backend Spring Boot)"
          @click="emit('toggleSimulacao')"
        >
          <span class="size-1.5 rounded-full" :class="simulacao ? 'bg-ore animate-pulse' : 'bg-mute/50'" />
          {{ simulacao ? 'Modo Simulação' : 'Modo Real' }}
        </button>

        <button
          v-if="simulacao"
          type="button"
          class="rounded-full border border-line bg-panel-2 px-2 py-1 font-mono text-[10px] text-mute transition-colors hover:border-ore/50 hover:text-ink"
          title="Restaurar estado inicial da simulação"
          @click="emit('resetSimulacao')"
        >
          Reset
        </button>
      </div>

      <div
        class="flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium"
        :class="status.chip"
        role="status"
        aria-live="polite"
      >
        <span class="relative flex size-2">
          <span v-if="status.ring" class="absolute inline-flex size-full animate-ping rounded-full opacity-75" :class="status.ring" />
          <span class="relative inline-flex size-2 rounded-full" :class="status.dot" />
        </span>
        {{ status.label }}
      </div>

      <div class="h-8 w-px bg-line" />

      <div class="text-right leading-tight">
        <div class="font-mono text-lg font-semibold tabular-nums text-ink">{{ hora }}</div>
        <div class="text-[10px] uppercase tracking-wider text-mute">{{ data }}</div>
      </div>
    </div>
  </header>
</template>
