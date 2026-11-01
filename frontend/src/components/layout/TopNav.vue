<script setup lang="ts">
import { computed } from 'vue'
import { currentPermissions, currentUser, getCurrentUser } from '@/config/auth'
import { NAV_ITEMS, type NavId } from '@/config/navegacao'
import { useClock } from '@/composables/useClock'

const props = defineProps<{
  activeId: NavId
  conectadas: number
  total: number
  /** Conexão com o backend/simulação ativa */
  online: boolean
  lastSync: Date | null
}>()
const emit = defineEmits<{ navigate: [id: NavId] }>()

const { hora, data } = useClock()
const usuario = computed(() => currentUser.value ?? getCurrentUser())
const permissoes = computed(() => currentPermissions.value)
const itens = computed(() => NAV_ITEMS.filter((i) => i.roles.includes(usuario.value.role)))

const todasConectadas = computed(() => props.total > 0 && props.conectadas === props.total)
const syncLabel = computed(() =>
  props.lastSync
    ? props.lastSync.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
    : '--:--:--',
)
const iniciais = computed(() =>
  usuario.value.nome
    .replace(/\(.*?\)/g, '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0])
    .join('')
    .toUpperCase(),
)
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-4">
    <!-- Marca -->
    <div class="flex items-center gap-3">
      <div class="flex size-12 items-center justify-center rounded-2xl bg-[#0F1013] p-2 shadow-md">
        <img src="/assets/geonex-symbol.svg" alt="GEONEX" class="size-full select-none object-contain" />
      </div>
      <div class="leading-tight">
        <div class="text-base font-black tracking-wider">GEONE<span class="text-ore">X</span></div>
        <div class="text-[10px] font-semibold uppercase tracking-[0.18em] text-neutral-500">
          Mining Lighting Control
        </div>
      </div>
    </div>

    <!-- Abas em cápsula -->
    <nav
      aria-label="Seções do painel"
      class="flex items-center gap-1 rounded-full bg-neutral-200/60 p-1 dark:bg-neutral-800/60"
    >
      <button
        v-for="item in itens"
        :key="item.id"
        type="button"
        class="rounded-full px-4 py-2 text-sm font-medium transition-all duration-200"
        :class="
          activeId === item.id && !item.acao
            ? 'bg-neutral-900 text-white shadow-sm dark:bg-white dark:text-neutral-900'
            : 'text-neutral-600 hover:bg-white/70 hover:text-neutral-900 dark:text-neutral-400 dark:hover:bg-white/10 dark:hover:text-white'
        "
        @click="emit('navigate', item.id)"
      >
        {{ item.label }}
      </button>
    </nav>

    <!-- Telemetria viva + usuário -->
    <div class="flex items-center gap-3">
      <div
        class="flex items-center gap-2 rounded-full border border-black/[0.05] bg-white px-3.5 py-2 text-xs font-medium shadow-sm dark:border-white/[0.08] dark:bg-[#16171C]"
        role="status"
        aria-live="polite"
        :title="`Última sincronização: ${syncLabel}`"
      >
        <span class="relative flex size-2">
          <span
            v-if="online"
            class="absolute inline-flex size-full animate-ping rounded-full opacity-75"
            :class="todasConectadas ? 'bg-ok' : 'bg-ore'"
          />
          <span
            class="relative inline-flex size-2 rounded-full"
            :class="!online ? 'bg-fault' : todasConectadas ? 'bg-ok' : 'bg-ore'"
          />
        </span>
        <span class="tabular-nums">{{ conectadas }}/{{ total }}</span>
        <span class="text-neutral-500">torres conectadas · ao vivo</span>
      </div>

      <div class="hidden text-right leading-tight sm:block">
        <div class="font-mono text-sm font-semibold tabular-nums">{{ hora }}</div>
        <div class="text-[10px] uppercase tracking-wider text-neutral-500">{{ data }}</div>
      </div>

      <div
        class="flex items-center gap-2.5 rounded-full border border-black/[0.05] bg-white py-1.5 pl-1.5 pr-4 shadow-sm dark:border-white/[0.08] dark:bg-[#16171C]"
        :title="permissoes.descricao"
      >
        <span
          class="flex size-9 items-center justify-center rounded-full bg-neutral-900 text-xs font-bold text-white dark:bg-white dark:text-neutral-900"
          >{{ iniciais }}</span
        >
        <div class="leading-tight">
          <div class="text-xs font-semibold">{{ usuario.nome }}</div>
          <span
            class="mt-0.5 inline-block rounded-full border px-1.5 font-mono text-[9px] font-bold uppercase tracking-wider"
            :class="permissoes.badgeColor"
            >{{ permissoes.label }}</span
          >
        </div>
      </div>
    </div>
  </div>
</template>
