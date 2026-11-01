<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { currentPermissions, currentUser, getCurrentUser, logoutSession } from '@/config/auth'
import { ICONES, NAV_ITEMS, type NavId } from '@/config/navegacao'
import { POTENCIA_NOMINAL_W, PWM_MAX, getModo } from '@/config/scada'
import SlidingTabs from '@/components/ui/SlidingTabs.vue'
import { useClock } from '@/composables/useClock'
import type { ConnectionState, Torre } from '@/types/scada'

const props = defineProps<{
  connection: ConnectionState
  lastSync: Date | null
  simulacao?: boolean
  torres?: Torre[]
  /** Aba ativa no painel */
  activeId: NavId
}>()

const emit = defineEmits<{ navigate: [id: NavId] }>()

const router = useRouter()
const { hora, data } = useClock()

const usuarioLogado = computed(() => currentUser.value ?? getCurrentUser())
const permissoes = computed(() => currentPermissions.value)

/* Estado do Modal Dark Mode de Confirmação de Logout */
const mostrarConfirmacaoLogout = ref(false)

function solicitarLogout() {
  mostrarConfirmacaoLogout.value = true
}

async function confirmarLogout() {
  mostrarConfirmacaoLogout.value = false
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
  if (props.connection === 'online') {
    return {
      label: 'Conexão: Online',
      dot: 'bg-ok',
      ring: 'bg-ok',
      chip: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-400',
    }
  }
  switch (props.connection) {
    case 'offline':
      return {
        label: 'Conexão: Offline',
        dot: 'bg-fault',
        ring: '',
        chip: 'border-rose-500/30 bg-rose-500/10 text-rose-500',
      }
    default:
      return {
        label: 'Conectando…',
        dot: 'bg-ore',
        ring: 'bg-ore',
        chip: 'border-ore/30 bg-ore/10 text-ore',
      }
  }
})

/* Abas em pílula: Visão Geral, Ordens de Serviço, Configurações e Analytics (filtradas por perfil RBAC) */
const ORDEM_ABAS: NavId[] = ['visao', 'os', 'config', 'analytics']
const abas = computed(() =>
  ORDEM_ABAS.map((id) => NAV_ITEMS.find((i) => i.id === id)!).filter((i) => i.roles.includes(usuarioLogado.value.role)),
)
</script>

<template>
  <header class="flex shrink-0 items-center justify-between border-b border-white/5 bg-abyss px-4 py-4 lg:px-6">
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
        <p class="mt-1 text-[10px] font-semibold tracking-[0.22em] text-neutral-500">
          MINING LIGHTING CONTROL · MEGA PWM 2–6
        </p>
      </div>
    </div>

    <!-- Abas em pílula -->
    <SlidingTabs
      aria-label="Seções do painel"
      container-class="rounded-full bg-white/[0.04] p-1 ring-1 ring-inset ring-white/5"
      :items="abas.map((a) => ({ id: a.id, label: a.label }))"
      :model-value="activeId"
      @update:model-value="(id) => emit('navigate', id)"
    />

    <!-- KPIs de Desempenho + Status + Perfil RBAC + Botão Sair -->
    <div class="flex items-center gap-3">
      <!-- Indicadores KPI de Desempenho em Tempo Real -->
      <div class="hidden 2xl:flex items-center gap-2 rounded-full border border-white/5 bg-panel px-4 py-1.5 text-[11px]">
        <span class="text-mute">SLA: <strong class="font-mono" :class="kpiResumo.slaPct >= 80 ? 'text-emerald-400' : 'text-rose-500'">{{ kpiResumo.slaPct }}%</strong></span>
        <span class="text-line">|</span>
        <span class="text-mute">Economia: <strong class="font-mono text-emerald-400">{{ kpiResumo.eficienciaPct }}%</strong></span>
        <span class="text-line">|</span>
        <span class="text-mute">Demanda: <strong class="font-mono text-white">{{ kpiResumo.cargaW }}W</strong></span>
      </div>

      <div
        class="flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-medium"
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
        class="flex items-center gap-2.5 rounded-full border border-white/5 bg-panel py-1.5 px-4"
        :title="permissoes.descricao"
      >
        <div class="flex flex-col text-right leading-tight">
          <span class="text-xs font-bold text-ink">{{ usuarioLogado.nome }}</span>
          <span
            class="mt-0.5 inline-flex items-center justify-end gap-1 rounded-full border px-2 py-px text-[9px] font-bold uppercase tracking-wider"
            :class="permissoes.badgeColor"
          >
            {{ permissoes.label }}
          </span>
        </div>
      </div>

      <div class="hidden sm:block h-8 w-px bg-line" />

      <div class="hidden sm:block text-right leading-tight">
        <div class="font-mono text-lg font-bold tabular-nums text-white">{{ hora }}</div>
        <div class="text-[10px] uppercase tracking-wider text-mute">{{ data }}</div>
      </div>

      <!-- Sair: ícone minimalista com popup de confirmação -->
      <button
        type="button"
        class="flex size-9 items-center justify-center rounded-full text-neutral-500 transition-colors hover:bg-rose-500/10 hover:text-rose-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500 active:scale-95"
        title="Sair"
        aria-label="Sair"
        @click="solicitarLogout"
      >
        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONES.logout" />
        </svg>
      </button>
    </div>

    <!-- Modal Dark Mode de Confirmação de Logout -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="opacity-0"
        leave-active-class="transition duration-100 ease-in"
        leave-to-class="opacity-0"
      >
        <div
          v-if="mostrarConfirmacaoLogout"
          class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 p-4 backdrop-blur-sm"
          role="dialog"
          aria-modal="true"
          aria-labelledby="logout-modal-title"
          @click.self="mostrarConfirmacaoLogout = false"
        >
          <div class="w-full max-w-sm rounded-2xl border border-white/10 bg-panel p-5 shadow-2xl shadow-black/90">
            <div class="flex items-center gap-2.5">
              <span class="flex size-8 items-center justify-center rounded-full border border-fault/40 bg-fault/15 text-fault">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONES.logout" />
                </svg>
              </span>
              <h3 id="logout-modal-title" class="text-sm font-bold text-ink">
                Confirmar saída do sistema
              </h3>
            </div>

            <p class="mt-3 text-xs leading-relaxed text-mute">
              Deseja realmente encerrar sua sessão atual (<strong class="text-ink">{{ usuarioLogado.nome }}</strong>) e sair do painel GEONEX SCADA?
            </p>

            <div class="mt-5 flex items-center justify-end gap-2">
              <button
                type="button"
                class="rounded-xl border border-line bg-panel-2 px-4 py-2 text-xs font-semibold text-mute transition-colors hover:text-ink"
                @click="mostrarConfirmacaoLogout = false"
              >
                Cancelar
              </button>
              <button
                type="button"
                class="rounded-xl bg-fault px-4 py-2 text-xs font-bold text-white transition-opacity hover:opacity-90"
                @click="confirmarLogout"
              >
                Sim, sair
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </header>
</template>
