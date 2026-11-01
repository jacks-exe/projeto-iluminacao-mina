<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { currentUser, getCurrentUser, logoutSession } from '@/config/auth'
import { ICONES, NAV_ITEMS, type NavId } from '@/config/navegacao'
import { useTema } from '@/composables/useTema'

defineProps<{ activeId: NavId }>()
const emit = defineEmits<{ navigate: [id: NavId] }>()

const router = useRouter()

/* Entrada: o dock desliza a partir da borda esquerda ao montar */
const entrou = ref(false)
onMounted(() => requestAnimationFrame(() => (entrou.value = true)))
const { isDark, alternarTema } = useTema()

const role = computed(() => (currentUser.value ?? getCurrentUser()).role)
const itens = computed(() => NAV_ITEMS.filter((i) => i.roles.includes(role.value)))

async function sair() {
  if (!window.confirm('Deseja realmente encerrar a sessão e sair do sistema GEONEX?')) {
    return
  }
  try {
    await fetch('/api/auth/logout', { method: 'POST' })
  } catch {
    /* ignora se o servidor estiver offline */
  }
  logoutSession()
  await router.push('/login')
}
</script>

<template>
  <nav
    aria-label="Navegação principal"
    class="fixed left-5 top-1/2 z-40 flex -translate-y-1/2 flex-col items-center gap-2 rounded-full border border-white/10 bg-[#0D0D11] p-2 shadow-2xl shadow-black/40 transition-all duration-500 ease-out"
    :class="entrou ? 'translate-x-0 opacity-100' : '-translate-x-full opacity-0'"
  >
    <button
      v-for="item in itens"
      :key="item.id"
      type="button"
      :title="item.label"
      :aria-label="item.label"
      :aria-current="activeId === item.id ? 'page' : undefined"
      class="group relative flex size-11 items-center justify-center rounded-full transition-all duration-200 active:scale-95"
      :class="
        activeId === item.id
          ? 'bg-white text-[#0D0D11] shadow-md'
          : 'text-white/60 hover:bg-white/10 hover:text-white'
      "
      @click="emit('navigate', item.id)"
    >
      <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
      </svg>
      <span
        class="pointer-events-none absolute left-full ml-3 whitespace-nowrap rounded-full bg-[#0D0D11] px-3 py-1 text-xs font-medium text-white opacity-0 shadow-lg ring-1 ring-white/10 transition-opacity group-hover:opacity-100"
        >{{ item.label }}</span
      >
    </button>

    <span class="my-1 h-px w-6 bg-white/10" />

    <button
      type="button"
      :title="isDark ? 'Tema claro' : 'Tema escuro'"
      :aria-label="isDark ? 'Ativar tema claro' : 'Ativar tema escuro'"
      class="flex size-11 items-center justify-center rounded-full text-white/60 transition-all hover:bg-white/10 hover:text-white active:scale-95"
      @click="alternarTema"
    >
      <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" :d="isDark ? ICONES.sun : ICONES.moon" />
      </svg>
    </button>

    <!-- Logout: encerra a sessão (store de auth) e redireciona para /login -->
    <button
      type="button"
      title="Sair"
      aria-label="Encerrar sessão"
      class="flex size-11 items-center justify-center rounded-full text-red-400/80 transition-all hover:bg-red-500 hover:text-white active:scale-95"
      @click="sair"
    >
      <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONES.logout" />
      </svg>
    </button>
  </nav>
</template>
