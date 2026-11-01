import { computed, ref } from 'vue'

const TEMA_KEY = 'geonex.tema'
export type Tema = 'light' | 'dark'

function lerTema(): Tema {
  const salvo = localStorage.getItem(TEMA_KEY)
  return salvo === 'dark' ? 'dark' : 'light'
}

/** Estado compartilhado (singleton) do tema visual do painel. */
const tema = ref<Tema>(lerTema())

export function useTema() {
  const isDark = computed(() => tema.value === 'dark')
  function alternarTema() {
    tema.value = tema.value === 'dark' ? 'light' : 'dark'
    localStorage.setItem(TEMA_KEY, tema.value)
  }
  return { tema, isDark, alternarTema }
}
