<script setup lang="ts">
import { computed } from 'vue'
import { currentPermissions } from '@/config/auth'
import TowerModeSelector from './TowerModeSelector.vue'
import type { ModoIluminacao, Torre } from '@/types/scada'

const props = defineProps<{
  torres: Torre[]
  pending: Record<number, boolean>
  online: boolean
  cerracaoAtiva: boolean
  cascataAtiva?: boolean
  selectedId?: number | null
}>()

const emit = defineEmits<{
  select: [torre: Torre, modo: ModoIluminacao]
  changePwm: [torre: Torre, pwm: number]
  togglePower: [torre: Torre]
  toggleConn: [torre: Torre]
  cascata: [pwmAlvo?: number]
  cerracao: []
}>()

const permissoes = computed(() => currentPermissions.value)
const postesConectados = computed(() => props.torres.filter((t) => t.conectado !== false).length)
const postesLigados = computed(
  () =>
    props.torres.filter(
      (t) => t.conectado !== false && t.statusOperacional !== 'EM_MANUTENCAO' && (t.nivelBrilhoAtual ?? 0) > 0,
    ).length,
)
</script>

<template>
  <div class="flex shrink-0 items-stretch gap-6 rounded-3xl border border-white/5 bg-[#1A1D27] p-6">
    <div class="flex min-w-0 flex-1 flex-col gap-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2.5">
          <span class="scada-label">Controle PWM & Conectividade · Arduino Mega (Pinos PWM 2 a 6)</span>
          <span
            class="rounded-full border px-2 py-0.5 text-[10px] font-semibold"
            :class="
              postesConectados === torres.length
                ? 'border-emerald-400/30 bg-emerald-400/10 text-emerald-400'
                : 'border-rose-500/40 bg-rose-500/10 text-rose-400'
            "
          >
            {{ postesConectados }}/{{ torres.length }} Online · {{ postesLigados }} Acesos
          </span>
        </div>
        <span class="text-[10px] text-mute/70">
          PINOS PWM 2–6 · OFF 0 · ECO 70 · NORM 170 · CERR 255
        </span>
      </div>

      <div class="grid grid-cols-5 gap-4">
        <TowerModeSelector
          v-for="t in torres"
          :key="t.id"
          :torre="t"
          :pending="pending[t.id]"
          :highlighted="selectedId === t.id"
          :disabled="!online || t.id < 0"
          @select="(m) => emit('select', t, m)"
          @change-pwm="(pwm) => emit('changePwm', t, pwm)"
          @toggle-power="emit('togglePower', t)"
          @toggle-conn="emit('toggleConn', t)"
        />
      </div>
    </div>

    <div class="w-px bg-line" />

    <!-- Ações Sequenciais (Cascata D8->D12) e Contingência (Cerração) -->
    <div class="flex w-52 shrink-0 flex-col gap-4">
      <!-- Botão Efeito Cascata Sequencial -->
      <button
        type="button"
        :disabled="!online || cascataAtiva || !permissoes.canControlLighting"
        :title="!permissoes.canControlLighting ? 'Acesso restrito: Apenas Administrador e Operador controlam acionamento luminotécnico' : 'Acionar efeito cascata sequencial'"
        class="relative flex flex-1 flex-col items-center justify-center gap-0.5 overflow-hidden rounded-2xl border px-3 py-2 text-center transition-all duration-300 active:scale-[0.97] active:duration-150 focus-visible:outline-2 focus-visible:outline-ore disabled:cursor-not-allowed disabled:opacity-40 disabled:active:scale-100"
        :class="
          cascataAtiva
            ? 'border-ore text-ore shadow-[0_0_24px_-4px_rgba(217,119,6,0.75)]'
            : 'border-ore/60 bg-ore/10 text-ore hover:bg-ore/20'
        "
        @click="emit('cascata')"
      >
        <span aria-hidden="true" class="pointer-events-none absolute inset-0 bg-gradient-to-r from-orange-500/10 to-orange-400/20 transition-opacity duration-300" :class="cascataAtiva ? 'opacity-100 motion-safe:animate-pulse' : 'opacity-0'" />
        <span class="relative text-xs font-bold uppercase tracking-wider">
          {{ cascataAtiva ? 'Acionando D2 → D6...' : 'Efeito Cascata (2→6)' }}
        </span>
        <span class="relative text-[9px] opacity-85">
          {{ permissoes.canControlLighting ? 'Acionamento sequencial progressivo' : 'Somente Admin / Operador' }}
        </span>
      </button>

      <!-- Contingência: Cerração Geral -->
      <button
        type="button"
        :disabled="!online || !permissoes.canControlLighting"
        :title="!permissoes.canControlLighting ? 'Acesso restrito: Apenas Administrador e Operador acionam alerta de cerração' : 'Acionar contingência de cerração geral'"
        class="group relative flex flex-1 flex-col items-center justify-center gap-0.5 overflow-hidden rounded-2xl border-2 px-3 py-2 text-center transition-all duration-300 active:scale-[0.97] active:duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ember disabled:cursor-not-allowed disabled:opacity-40 disabled:active:scale-100"
        :class="
          cerracaoAtiva
            ? 'border-ember text-ember shadow-[0_0_32px_-4px_rgba(234,88,12,0.8)]'
            : 'border-ember/60 bg-ember/10 text-ember hover:bg-ember/20 hover:shadow-[0_0_24px_-6px_rgba(234,88,12,0.7)]'
        "
        :aria-pressed="cerracaoAtiva"
        @click="emit('cerracao')"
      >
        <span aria-hidden="true" class="pointer-events-none absolute inset-0 bg-gradient-to-r from-orange-500/10 to-orange-400/20 transition-opacity duration-300" :class="cerracaoAtiva ? 'opacity-100 motion-safe:animate-pulse' : 'opacity-0'" />
        <span class="relative text-xs font-bold uppercase tracking-wider">
          {{ cerracaoAtiva ? 'Cerração Ativa (255)' : 'Alerta Cerração Geral' }}
        </span>
        <span class="relative text-[9px] font-medium opacity-80">
          {{ cerracaoAtiva ? 'Clique para restaurar modos' : 'Todas as torres → PWM 255' }}
        </span>
      </button>
    </div>
  </div>
</template>
