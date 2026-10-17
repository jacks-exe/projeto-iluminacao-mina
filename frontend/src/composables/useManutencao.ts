import { onBeforeUnmount, onMounted, ref } from 'vue'
import { POLL_MANUTENCAO_MS } from '@/config/scada'
import { manutencaoApi } from '@/services/api'
import type { NovaOrdemManutencao, OrdemManutencao } from '@/types/scada'

export function useManutencao() {
  const ordens = ref<OrdemManutencao[]>([])
  const carregando = ref(false)
  const enviando = ref(false)
  const erro = ref<string | null>(null)
  let timer: ReturnType<typeof setInterval> | null = null

  async function carregar() {
    carregando.value = true
    try {
      const data = await manutencaoApi.pendentes()
      ordens.value = Array.isArray(data) ? data : []
      erro.value = null
    } catch (e) {
      erro.value = (e as Error).message
    } finally {
      carregando.value = false
    }
  }

  async function abrir(ordem: NovaOrdemManutencao): Promise<boolean> {
    enviando.value = true
    try {
      await manutencaoApi.abrir(ordem)
      await carregar()
      return true
    } catch (e) {
      erro.value = (e as Error).message
      return false
    } finally {
      enviando.value = false
    }
  }

  onMounted(() => {
    carregar()
    timer = setInterval(carregar, POLL_MANUTENCAO_MS)
  })
  onBeforeUnmount(() => {
    if (timer) clearInterval(timer)
  })

  return { ordens, carregando, enviando, erro, carregar, abrir }
}
