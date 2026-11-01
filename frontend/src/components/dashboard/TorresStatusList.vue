<script setup lang="ts">
import { PINOS_POSTES, PWM_MAX, getModo, pwmToPercent } from '@/config/scada'
import type { Torre } from '@/types/scada'

defineProps<{ torres: Torre[] }>()

function estado(t: Torre) {
  if (t.conectado === false) return { label: 'Offline', cls: 'bg-fault/10 text-fault' }
  if (t.statusOperacional === 'EM_MANUTENCAO') return { label: 'Manutenção', cls: 'bg-ore/15 text-ore' }
  return { label: 'Operando', cls: 'bg-ok/10 text-ok' }
}
function pwmDe(t: Torre) {
  if (t.conectado === false || t.statusOperacional === 'EM_MANUTENCAO') return 0
  return t.nivelBrilhoAtual ?? getModo(t.modoIluminacao).pwm
}
function pino(t: Torre, idx: number) {
  return t.pinoPwm >= 2 && t.pinoPwm <= 6 ? t.pinoPwm : (PINOS_POSTES[idx] ?? 2 + idx)
}
</script>

<template>
  <ul class="flex flex-col divide-y divide-black/[0.06] dark:divide-white/[0.08]">
    <li v-for="(t, idx) in torres" :key="t.id" class="flex items-center gap-4 py-3 first:pt-0 last:pb-0">
      <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-neutral-100 font-mono text-xs font-bold dark:bg-white/10">
        D{{ pino(t, idx) }}
      </div>
      <div class="min-w-0 flex-1">
        <div class="flex items-center justify-between gap-2">
          <span class="truncate text-sm font-semibold">{{ t.codigo }}</span>
          <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="estado(t).cls">{{ estado(t).label }}</span>
        </div>
        <div class="mt-2 flex items-center gap-3">
          <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-neutral-200/80 dark:bg-white/10">
            <div
              class="h-full rounded-full bg-neutral-900 transition-all duration-500 dark:bg-white"
              :style="{ width: `${(pwmDe(t) / PWM_MAX) * 100}%` }"
            />
          </div>
          <span class="w-20 text-right font-mono text-[11px] tabular-nums text-neutral-500">
            {{ pwmToPercent(pwmDe(t)) }}% · PWM {{ pwmDe(t) }}
          </span>
        </div>
      </div>
    </li>
  </ul>
</template>
