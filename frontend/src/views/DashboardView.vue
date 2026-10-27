<script setup lang="ts">
import { ref, watch } from 'vue'
import DashboardHeader from '@/components/dashboard/DashboardHeader.vue'
import MineDigitalTwin from '@/components/dashboard/MineDigitalTwin.vue'
import MaintenancePanel from '@/components/dashboard/MaintenancePanel.vue'
import EnergyMetrics from '@/components/dashboard/EnergyMetrics.vue'
import ControlDock from '@/components/dashboard/ControlDock.vue'
import AnalyticsHistoricoModal from '@/components/dashboard/AnalyticsHistoricoModal.vue'
import { currentPermissions } from '@/config/auth'
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
} = useTorres()

const maintenancePanel = ref<InstanceType<typeof MaintenancePanel> | null>(null)
const selectedTowerId = ref<number | null>(null)
const analyticsAberta = ref(false)

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
  maintenancePanel.value?.abrirPara(torre)
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
      @toggle-simulacao="toggleSimulacao"
      @reset-simulacao="resetSimulacao"
      @abrir-analytics="analyticsAberta = true"
    />

    <main class="grid min-h-0 flex-1 grid-cols-[320px_minmax(0,1fr)] gap-3 p-3">
      <!-- Lateral esquerda -->
      <aside class="flex min-h-0 flex-col gap-3">
        <MaintenancePanel ref="maintenancePanel" :torres="torres" />
        <EnergyMetrics :torres="torres" @abrir-analytics="analyticsAberta = true" />
      </aside>

      <!-- Centro + dock -->
      <div class="flex min-h-0 min-w-0 flex-col gap-3">
        <!-- Digital Twin com telemetria e conectividade dos pinos PWM 2 a 6 -->
        <MineDigitalTwin
          :torres="torres"
          :pending="pending"
          :online="isOnline"
          :selected-id="selectedTowerId"
          @select-node="(id) => (selectedTowerId = id)"
          @maintenance="onMaintenance"
          @toggle-power="onTogglePower"
        />

        <ControlDock
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
      </div>
    </main>

    <!-- Modal Analytics, Histórico de Telemetria e Auditoria Operacional (Passo 4) -->
    <AnalyticsHistoricoModal
      :aberta="analyticsAberta"
      :torres="torres"
      @fechar="analyticsAberta = false"
    />

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
        class="fixed right-4 top-16 z-50 flex max-w-sm items-start gap-2.5 rounded-lg border border-fault/40 bg-panel/95 px-3.5 py-2.5 text-xs text-ink shadow-2xl shadow-black/60 backdrop-blur"
      >
        <span class="mt-0.5 size-2 shrink-0 animate-pulse rounded-full bg-fault" />
        {{ toast }}
      </div>
    </Transition>
  </div>
</template>
