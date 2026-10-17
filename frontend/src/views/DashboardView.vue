<script setup lang="ts">
import { ref, watch } from 'vue'
import DashboardHeader from '@/components/dashboard/DashboardHeader.vue'
import MineDigitalTwin from '@/components/dashboard/MineDigitalTwin.vue'
import MaintenancePanel from '@/components/dashboard/MaintenancePanel.vue'
import EnergyMetrics from '@/components/dashboard/EnergyMetrics.vue'
import ControlDock from '@/components/dashboard/ControlDock.vue'
import { useTorres } from '@/composables/useTorres'
import type { ModoIluminacao, Torre } from '@/types/scada'

const {
  torres,
  connection,
  isOnline,
  lastSync,
  pending,
  lastError,
  cerracaoGeralAtiva,
  setModo,
  toggleCerracaoGeral,
} = useTorres()

const maintenancePanel = ref<InstanceType<typeof MaintenancePanel> | null>(null)

function onSelect(torre: Torre, modo: ModoIluminacao) {
  setModo(torre, modo)
}

function onMaintenance(torre: Torre) {
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
    <DashboardHeader :connection="connection" :last-sync="lastSync" />

    <main class="grid min-h-0 flex-1 grid-cols-[320px_minmax(0,1fr)] gap-3 p-3">
      <!-- Lateral esquerda -->
      <aside class="flex min-h-0 flex-col gap-3">
        <MaintenancePanel ref="maintenancePanel" :torres="torres" />
        <EnergyMetrics :torres="torres" />
      </aside>

      <!-- Centro + dock -->
      <div class="flex min-h-0 min-w-0 flex-col gap-3">
        <MineDigitalTwin
          :torres="torres"
          :pending="pending"
          :online="isOnline"
          @maintenance="onMaintenance"
        />
        <ControlDock
          :torres="torres"
          :pending="pending"
          :online="isOnline"
          :cerracao-ativa="cerracaoGeralAtiva"
          @select="onSelect"
          @cerracao="toggleCerracaoGeral"
        />
      </div>
    </main>

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
