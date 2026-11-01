<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    /** 0..100 */
    value: number
    size?: number
  }>(),
  { size: 220 },
)

/* Arco de 270° (abre embaixo), iniciando em 135° */
const R = 80
const C = 2 * Math.PI * R
const ARC = C * 0.75
const pct = computed(() => Math.min(100, Math.max(0, props.value)))
const preenchido = computed(() => (ARC * pct.value) / 100)

/* Posição do marcador na ponta do arco */
const marcador = computed(() => {
  const ang = ((135 + 270 * (pct.value / 100)) * Math.PI) / 180
  return { x: 100 + R * Math.cos(ang), y: 100 + R * Math.sin(ang) }
})
</script>

<template>
  <div class="relative mx-auto" :style="{ width: `${size}px`, height: `${size * 0.9}px` }">
    <svg viewBox="0 0 200 180" class="size-full" role="img" :aria-label="`${pct}%`">
      <defs>
        <linearGradient id="gauge-grad" x1="0" y1="1" x2="1" y2="0">
          <stop offset="0%" stop-color="#6b7280" />
          <stop offset="100%" stop-color="#ffffff" />
        </linearGradient>
      </defs>
      <circle
        cx="100" cy="100" :r="R" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="14"
        stroke-linecap="round" :stroke-dasharray="`${ARC} ${C}`" transform="rotate(135 100 100)"
      />
      <circle
        cx="100" cy="100" :r="R" fill="none" stroke="url(#gauge-grad)" stroke-width="14"
        stroke-linecap="round" :stroke-dasharray="`${preenchido} ${C}`" transform="rotate(135 100 100)"
        class="transition-all duration-700 ease-out"
      />
      <circle :cx="marcador.x" :cy="marcador.y" r="8" fill="#0F1013" stroke="#fff" stroke-width="3"
        class="transition-all duration-700 ease-out" />
    </svg>
    <div class="absolute inset-0 flex flex-col items-center justify-center pt-4">
      <span class="text-5xl font-semibold tabular-nums tracking-tight">{{ Math.round(pct) }}</span>
      <slot />
    </div>
  </div>
</template>
