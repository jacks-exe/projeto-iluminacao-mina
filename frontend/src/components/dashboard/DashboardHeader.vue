<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { currentPermissions, currentUser, getCurrentUser, logoutSession } from '@/config/auth'
import { POTENCIA_NOMINAL_W, PWM_MAX, getModo } from '@/config/scada'
import { useClock } from '@/composables/useClock'
import type { ConnectionState, Torre } from '@/types/scada'

const props = defineProps<{
  connection: ConnectionState
  lastSync: Date | null
  simulacao?: boolean
  torres?: Torre[]
}>()

const emit = defineEmits<{
  toggleSimulacao: []
  resetSimulacao: []
  abrirAnalytics: []
}>()

const router = useRouter()
const { hora, data } = useClock()

const usuarioLogado = computed(() => currentUser.value ?? getCurrentUser())
const permissoes = computed(() => currentPermissions.value)

async function handleLogout() {
  try {
    await fetch('/api/auth/logout', { method: 'POST' })
  } catch {
    /* ignore se servidor offline */
  }
  logoutSession()
  await router.replace({ name: 'login' })
}

/* KPIs rápidos de desempenho da planta no cabeçalho */
const kpiResumo = computed(() => {
  const lista = props.torres ?? []
  if (!lista.length) return { slaPct: 100, eficienciaPct: 100, cargaW: 0 }

  const operantes = lista.filter((t) => t.conectado !== false && t.statusOperacional !== 'EM_MANUTENCAO').length
  const slaPct = Math.round((operantes / lista.length) * 100)

  const capacidadeMaxW = lista.length * POTENCIA_NOMINAL_W
  const cargaW = lista.reduce((soma, t) => {
    if (t.conectado === false || t.statusOperacional === 'EM_MANUTENCAO') return soma
    const pwm = t.nivelBrilhoAtual ?? getModo(t.modoIluminacao).pwm
    return soma + (POTENCIA_NOMINAL_W * pwm) / PWM_MAX
  }, 0)

  const eficienciaPct = Math.max(0, Math.round((1 - cargaW / capacidadeMaxW) * 100))
  return {
    slaPct,
    eficienciaPct,
    cargaW: Math.round(cargaW),
  }
})

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
  <header class="flex shrink-0 items-center justify-between border-b border-line bg-panel px-4 py-3 lg:px-6">
    <!-- Marca -->
    <div class="flex items-center gap-4">
      <img
        src="/assets/geonex-symbol.svg"
        alt="GEONEX"
        class="h-11 w-auto select-none object-contain sm:h-12"
      />
      <div class="flex flex-col justify-center">
        <h1 class="text-2xl font-black tracking-wider text-ink leading-none">
          GEONE<span class="text-ore">X</span>
        </h1>
        <p class="mt-1 text-[10px] font-semibold tracking-[0.22em] text-gray-400">
          MINING LIGHTING CONTROL · MEGA PWM 2–6
        </p>
      </div>
    </div>

    <!-- KPIs de Desempenho + Botão Analytics + Status + Perfil RBAC + Botão Sair -->
    <div class="flex items-center gap-3">
      <!-- Indicadores KPI de Desempenho em Tempo Real -->
      <div class="hidden 2xl:flex items-center gap-2 rounded-lg border border-line bg-panel-2/70 px-3 py-1 font-mono text-[11px]">
        <span class="text-mute">SLA: <strong :class="kpiResumo.slaPct >= 80 ? 'text-ok' : 'text-fault'">{{ kpiResumo.slaPct }}%</strong></span>
        <span class="text-line">|</span>
        <span class="text-mute">Economia: <strong class="text-ok">{{ kpiResumo.eficienciaPct }}%</strong></span>
        <span class="text-line">|</span>
        <span class="text-mute">Demanda: <strong class="text-clay">{{ kpiResumo.cargaW }}W</strong></span>
      </div>

      <!-- Botão Principal: Analytics & Histórico de Telemetria -->
      <button
        type="button"
        class="flex items-center gap-1.5 rounded-lg border border-ore/60 bg-ore/15 px-3 py-1.5 font-mono text-[11px] font-bold uppercase tracking-wider text-ore transition-all hover:bg-ore hover:text-abyss shadow-[0_0_16px_-4px_rgba(217,119,6,0.5)]"
        title="Abrir Painel de Histórico de Telemetria, Gráficos de Consumo e Auditoria Operacional"
        @click="emit('abrirAnalytics')"
      >
        <span>📊 Analytics & Histórico</span>
      </button>

      <div class="hidden items-center gap-2 font-mono text-[11px] text-mute xl:flex">
        <span class="scada-label !tracking-[0.14em]">Sync</span>
        <span class="tabular-nums text-ink/80">{{ syncLabel }}</span>
      </div>

      <!-- Toggle do Modo Simulação / Real (Somente Administrador) -->
      <div v-if="permissoes.canToggleSimulacao" class="flex items-center gap-1">
        <button
          type="button"
          class="flex items-center gap-1.5 rounded-full border px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wider transition-all"
          :class="
            simulacao
              ? 'border-ore/50 bg-ore/15 text-ore shadow-[0_0_12px_-3px_rgba(217,119,6,0.45)]'
              : 'border-line bg-panel-2 text-mute hover:border-clay hover:text-ink'
          "
          :aria-pressed="simulacao"
          title="Alternar Modo Simulação vs Modo Real (Backend PHP mysqli + Arduino Mega)"
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

      <!-- Identificação do Usuário Logado e Nível de Acesso (RBAC) -->
      <div
        class="flex items-center gap-2.5 rounded-lg border border-line bg-panel-2/80 px-3 py-1.5"
        :title="permissoes.descricao"
      >
        <div class="flex flex-col text-right leading-tight">
          <span class="text-xs font-bold text-ink">{{ usuarioLogado.nome }}</span>
          <span
            class="mt-0.5 inline-flex items-center justify-end gap-1 rounded border px-1.5 py-px font-mono text-[9px] font-bold uppercase tracking-wider"
            :class="permissoes.badgeColor"
          >
            {{ permissoes.label }}
          </span>
        </div>

        <!-- Botão de Sair (Logout com redirecionamento obrigatório para /login) -->
        <button
          type="button"
          class="ml-1 flex items-center gap-1.5 rounded-md border border-fault/50 bg-fault/15 px-2.5 py-1.5 font-mono text-[11px] font-bold uppercase tracking-wider text-fault transition-all hover:bg-fault hover:text-white active:scale-95"
          title="Encerrar sessão atual e retornar à tela de login"
          @click="handleLogout"
        >
          <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
          </svg>
          <span>Sair</span>
        </button>
      </div>

      <div class="hidden sm:block h-8 w-px bg-line" />

      <div class="hidden sm:block text-right leading-tight">
        <div class="font-mono text-lg font-semibold tabular-nums text-ink">{{ hora }}</div>
        <div class="text-[10px] uppercase tracking-wider text-mute">{{ data }}</div>
      </div>
    </div>
  </header>
</template>
