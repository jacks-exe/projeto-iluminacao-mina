<script setup lang="ts">
import TowerModeSelector from './TowerModeSelector.vue'
import type { ModoIluminacao, Torre } from '@/types/scada'

defineProps<{
  torres: Torre[]
  pending: Record<number, boolean>
  online: boolean
  cerracaoAtiva: boolean
  selectedId?: number | null
}>()

const emit = defineEmits<{
  select: [torre: Torre, modo: ModoIluminacao]
  cerracao: []
}>()
</script>

<template>
  <div class="scada-card flex shrink-0 items-stretch gap-3 p-3">
    <div class="flex min-w-0 flex-1 flex-col gap-2">
      <div class="flex items-center justify-between">
        <span class="scada-label">Comando individual · Torres</span>
        <span class="font-mono text-[10px] text-mute/70">OFF 0 · ECO 70 · NORM 170 · CERR 255</span>
      </div>
      <div class="grid grid-cols-5 gap-2">
        <TowerModeSelector
          v-for="t in torres"
          :key="t.id"
          :torre="t"
          :pending="pending[t.id]"
          :highlighted="selectedId === t.id"
          :disabled="!online || t.id < 0"
          @select="(m) => emit('select', t, m)"
        />
      </div>
    </div>

    <div class="w-px bg-line" />

    <!-- Contingência -->
    <button
      type="button"
      :disabled="!online"
      class="group relative flex w-52 shrink-0 flex-col items-center justify-center gap-1 overflow-hidden rounded-lg border-2 px-4 text-center transition-all duration-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ember disabled:cursor-not-allowed disabled:opacity-40"
      :class="
        cerracaoAtiva
          ? 'hazard-stripes border-ember bg-ember text-abyss shadow-[0_0_32px_-4px_rgba(234,88,12,0.8)]'
          : 'border-ember/60 bg-ember/10 text-ember hover:bg-ember/20 hover:shadow-[0_0_24px_-6px_rgba(234,88,12,0.7)]'
      "
      :aria-pressed="cerracaoAtiva"
      @click="emit('cerracao')"
    >
      <svg viewBox="0 0 24 24" class="size-6" :class="cerracaoAtiva ? 'animate-pulse' : ''" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="M12 3 2 20h20L12 3Z" stroke-linejoin="round" />
        <path d="M12 10v4M12 17h.01" stroke-linecap="round" />
      </svg>
      <span class="text-sm font-bold uppercase tracking-wider">
        {{ cerracaoAtiva ? 'Cerração Ativa' : 'Alerta Cerração Geral' }}
      </span>
      <span class="text-[10px] font-medium opacity-80">
        {{ cerracaoAtiva ? 'Clique para restaurar modos' : 'Todas as torres → 100%' }}
      </span>
    </button>
  </div>
</template>
