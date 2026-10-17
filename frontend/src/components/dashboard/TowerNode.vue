<script setup lang="ts">
import { computed } from 'vue'
import { getModo, pwmToPercent } from '@/config/scada'
import type { Torre } from '@/types/scada'

const props = defineProps<{
  torre: Torre
  x: number
  y: number
  open: boolean
  pending?: boolean
}>()

const emit = defineEmits<{
  toggle: []
  maintenance: [torre: Torre]
}>()

const manut = computed(() => props.torre.statusOperacional === 'EM_MANUTENCAO')
const modo = computed(() => getModo(props.torre.modoIluminacao))
const ligada = computed(() => !manut.value && modo.value.pwm > 0)
const color = computed(() => (manut.value ? '#EF4444' : ligada.value ? modo.value.color : '#4B5563'))
const percent = computed(() => pwmToPercent(props.torre.nivelBrilhoAtual ?? modo.value.pwm))

/** Popover abre para baixo e se alinha pela borda mais próxima */
const popoverAlign = computed(() => (props.x > 60 ? 'right-0' : props.x < 30 ? 'left-0' : 'left-1/2 -translate-x-1/2'))
</script>

<template>
  <div
    class="absolute z-10 -translate-x-1/2 -translate-y-1/2"
    :class="open ? 'z-30' : ''"
    :style="{ left: `${x}%`, top: `${y}%` }"
  >
    <button
      type="button"
      class="group relative flex items-center gap-1.5 outline-none"
      :aria-expanded="open"
      :aria-label="`Torre ${torre.codigo}`"
      @click.stop="emit('toggle')"
    >
      <!-- Beacon -->
      <span class="relative grid size-5 place-items-center">
        <span
          v-if="ligada || manut"
          class="absolute inset-0 rounded-full"
          :class="modo.id === 'CERRACAO' && !manut ? 'animate-beacon-fast' : 'animate-beacon'"
          :style="{ backgroundColor: color }"
        />
        <span
          class="relative size-3 rounded-full border-2 border-abyss transition-colors duration-500"
          :style="{ backgroundColor: color, boxShadow: ligada ? `0 0 12px 2px ${color}` : 'none' }"
        />
      </span>

      <!-- Tag -->
      <span
        class="rounded border bg-abyss/85 px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-wider backdrop-blur-sm transition-colors group-hover:border-ore group-focus-visible:border-ore"
        :class="open ? 'border-ore text-ink' : 'border-line text-clay'"
      >
        {{ torre.codigo }}
        <span v-if="pending" class="ml-0.5 inline-block size-1.5 animate-pulse rounded-full bg-ore align-middle" />
      </span>
    </button>

    <!-- Popover -->
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0 translate-y-1"
      leave-active-class="transition duration-100 ease-in"
      leave-to-class="opacity-0 translate-y-1"
    >
      <div
        v-if="open"
        class="absolute top-7 w-56 rounded-[10px] border border-line bg-panel/95 p-3 shadow-2xl shadow-black/60 backdrop-blur-md"
        :class="popoverAlign"
        @click.stop
      >
        <div class="mb-2 flex items-center justify-between">
          <span class="font-mono text-sm font-semibold text-ink">{{ torre.codigo }}</span>
          <span
            class="rounded-full border px-2 py-0.5 text-[9px] font-semibold uppercase tracking-wider"
            :class="manut ? 'border-fault/40 bg-fault/10 text-fault' : 'border-ok/40 bg-ok/10 text-ok'"
          >
            {{ manut ? 'Em manutenção' : 'Operando' }}
          </span>
        </div>

        <dl class="grid grid-cols-2 gap-x-3 gap-y-1.5 text-[11px]">
          <dt class="text-mute">Modo</dt>
          <dd class="text-right font-medium" :style="{ color: manut ? undefined : modo.color }">{{ modo.label }}</dd>
          <dt class="text-mute">PWM</dt>
          <dd class="text-right font-mono tabular-nums text-ink">{{ torre.nivelBrilhoAtual ?? 0 }}/255</dd>
          <dt class="text-mute">Pino Arduino</dt>
          <dd class="text-right font-mono text-ink">D{{ torre.pinoPwm }}</dd>
          <dt class="text-mute">Setor</dt>
          <dd class="truncate text-right text-ink" :title="torre.setor?.nome">{{ torre.setor?.nome ?? '—' }}</dd>
        </dl>

        <div class="mt-2.5">
          <div class="mb-1 flex justify-between text-[10px] text-mute">
            <span>Intensidade</span><span class="font-mono tabular-nums text-ink">{{ percent }}%</span>
          </div>
          <div class="h-1.5 overflow-hidden rounded-full bg-panel-2">
            <div
              class="h-full rounded-full transition-all duration-500"
              :style="{ width: `${percent}%`, backgroundColor: modo.color }"
            />
          </div>
        </div>

        <button
          v-if="!manut && torre.id > 0"
          type="button"
          class="mt-3 w-full rounded-md border border-line bg-panel-2 py-1.5 text-[11px] font-medium text-mute transition-colors hover:border-clay hover:text-ink"
          @click="emit('maintenance', torre)"
        >
          Abrir ordem de serviço
        </button>
      </div>
    </Transition>
  </div>
</template>
