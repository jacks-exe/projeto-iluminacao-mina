<script setup lang="ts">
import { ref, watch } from 'vue'
import DashboardHeader from '@/components/dashboard/DashboardHeader.vue'
import MineDigitalTwin from '@/components/dashboard/MineDigitalTwin.vue'
import ControlDock from '@/components/dashboard/ControlDock.vue'
import OrdensServicoView from '@/views/OrdensServicoView.vue'
import AnalyticsView from '@/views/AnalyticsView.vue'
import ConfiguracoesView from '@/views/ConfiguracoesView.vue'
import { currentPermissions } from '@/config/auth'
import type { NavId } from '@/config/navegacao'
import { useTorres } from '@/composables/useTorres'
import type { ModoIluminacao, Torre } from '@/types/scada'

const {
  torres,
  connection,
  isOnline,
  simulacao,
  lastSync,
  pending,
  lastError,
  cerracaoGeralAtiva,
  cascataEmAndamento,
  setModo,
  setPwm,
  toggleAcionamento,
  toggleConectividade,
  acionarCascata,
  toggleCerracaoGeral,
  toggleSimulacao,
  resetSimulacao,
  refresh,
} = useTorres()

const selectedTowerId = ref<number | null>(null)

/* Cada aba é uma view de tela cheia que substitui o conteúdo principal (sem modais) */
type Aba = Exclude<NavId, 'telemetria'>
const tab = ref<Aba>('visao')
/** Torre que originou a abertura da tela de O.S. (duplo clique no mapa) */
const osTorreId = ref<number | null>(null)

function onNavigate(id: NavId) {
  if (id === 'telemetria') return
  if (id === 'os') osTorreId.value = null
  tab.value = id
}

/* Entrada em cascata: cada card "cai" com um pequeno atraso sequencial */
const stagger = (i: number) => ({ animationDelay: `${i * 75}ms` })
function onSelect(torre: Torre, modo: ModoIluminacao) {
  selectedTowerId.value = torre.id
  if (!currentPermissions.value.canControlLighting) {
    lastError.value = `Acesso restrito (${currentPermissions.value.label}): controle de iluminação permitido apenas para Administrador e Operador.`
    return
  }
  setModo(torre, modo)
}

function onChangePwm(torre: Torre, pwm: number) {
  selectedTowerId.value = torre.id
  if (!currentPermissions.value.canControlLighting) {
    lastError.value = `Acesso restrito (${currentPermissions.value.label}): ajuste de PWM permitido apenas para Administrador e Operador.`
    return
  }
  setPwm(torre, pwm)
}

function onTogglePower(torre: Torre) {
  selectedTowerId.value = torre.id
  if (!currentPermissions.value.canControlLighting) {
    lastError.value = `Acesso restrito (${currentPermissions.value.label}): acionamento remoto permitido apenas para Administrador e Operador.`
    return
  }
  toggleAcionamento(torre)
}

function onToggleConn(torre: Torre) {
  selectedTowerId.value = torre.id
  if (!currentPermissions.value.canManageConnectivity) {
    lastError.value = `Acesso restrito (${currentPermissions.value.label}): gerenciamento de conectividade permitido apenas para Administrador e Técnico.`
    return
  }
  toggleConectividade(torre)
}

function onMaintenance(torre: Torre) {
  selectedTowerId.value = torre.id
  const p = currentPermissions.value
  if (!p.canOpenOS && !p.canAttendOS) {
    lastError.value = `Acesso restrito (${p.label}): sem permissão para Ordens de Serviço.`
    return
  }
  osTorreId.value = torre.id
  tab.value = 'os'
}

/* Toast de alarme para comandos bloqueados/falhos */
const toast = ref<string | null>(null)
let toastTimer: ReturnType<typeof setTimeout> | null = null
watch(lastError, (msg) => {
  if (!msg) return
  toast.value = msg
  if (toastTimer) clearTimeout(toastTimer)
  toastTimer = setTimeout(() => {
    toast.value = null
    lastError.value = null
  }, 4000)
})
</script>

<template>
  <div class="flex h-full min-h-[720px] flex-col bg-abyss">
    <DashboardHeader
      :connection="connection"
      :last-sync="lastSync"
      :simulacao="simulacao"
      :torres="torres"
      :active-id="tab"
      @navigate="onNavigate"
    />

    <Transition
      mode="out-in"
      enter-active-class="transition-all duration-300 ease-out motion-reduce:transition-none"
      enter-from-class="opacity-0 translate-y-4 scale-95"
      leave-active-class="transition-all duration-200 ease-in motion-reduce:transition-none"
      leave-to-class="opacity-0 -translate-y-4 scale-95"
    >
      <!-- Visão Geral: Digital Twin ocupa toda a largura + dock de controle -->
      <main
        v-if="tab === 'visao'"
        key="visao"
        class="flex min-h-0 min-w-0 flex-1 flex-col gap-6 p-4"
      >
        <MineDigitalTwin
          class="bento-in"
          :style="stagger(0)"
          :torres="torres"
          :pending="pending"
          :online="isOnline"
          :selected-id="selectedTowerId"
          @select-node="(id) => (selectedTowerId = id)"
          @maintenance="onMaintenance"
          @toggle-power="onTogglePower"
        />

        <ControlDock
          class="bento-in"
          :style="stagger(1)"
          :torres="torres"
          :pending="pending"
          :online="isOnline"
          :cerracao-ativa="cerracaoGeralAtiva"
          :cascata-ativa="cascataEmAndamento"
          :selected-id="selectedTowerId"
          @select="onSelect"
          @change-pwm="onChangePwm"
          @toggle-power="onTogglePower"
          @toggle-conn="onToggleConn"
          @cascata="acionarCascata"
          @cerracao="toggleCerracaoGeral"
        />
      </main>

      <!-- Ordens de Serviço (tela cheia) -->
      <OrdensServicoView
        v-else-if="tab === 'os'"
        key="os"
        :torres="torres"
        :torre-inicial-id="osTorreId"
        @atualizado="refresh"
      />

      <!-- Configurações: modo de operação + gestão de usuários (ADMIN) -->
      <ConfiguracoesView
        v-else-if="tab === 'config'"
        key="config"
        :simulacao="simulacao"
        @toggle-simulacao="toggleSimulacao"
        @reset-simulacao="resetSimulacao"
      />

      <!-- Analytics, histórico de telemetria e auditoria (tela cheia) -->
      <AnalyticsView v-else key="analytics" :torres="torres" />
    </Transition>
    <!-- Toast de alarme -->
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0 translate-y-2"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0 translate-y-2"
    >
      <div
        v-if="toast"
        role="alert"
        class="fixed right-4 top-16 z-50 flex max-w-sm items-start gap-2.5 rounded-2xl border border-rose-500/40 bg-panel/95 px-4 py-3 text-xs text-ink shadow-2xl shadow-black/60 backdrop-blur"
      >
        <span class="mt-0.5 size-2 shrink-0 animate-pulse rounded-full bg-fault" />
        {{ toast }}
      </div>
    </Transition>
  </div>
</template>
