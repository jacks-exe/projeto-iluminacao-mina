import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { POLL_MANUTENCAO_MS } from '@/config/scada'
import { mockBackend } from '@/services/mockBackend'
import { simulacao } from '@/services/simulacao'
import type { NovaOrdemManutencao, OrdemManutencao, PrioridadeOS } from '@/types/scada'

const PHP_OS_API_URL = 'http://localhost:8000/ordens_servico.php'

export interface OrdemServicoApiRaw {
  id: number | string
  torre_id?: number | string | null
  torreId?: number | null
  torre_codigo?: string | null
  torre_setor?: string | null
  torre_status?: string | null
  torre?: { id: number; codigo: string } | null
  prioridade?: PrioridadeOS | string
  status?: string
  descricao_problema?: string
  descricao?: string
  data_abertura?: string
  dataAbertura?: string
}

/**
 * Verifica de forma segura se o status da O.S. representa um chamado ativo/pendente,
 * ignorando diferenças de maiúsculas/minúsculas ou espaços.
 */
export function isStatusAtivo(status?: string | null): boolean {
  const st = String(status ?? 'ABERTA')
    .trim()
    .toUpperCase()
    .replace(/\s+/g, '_')
  return st === 'ABERTA' || st === 'EM_ANDAMENTO' || st === 'PENDENTE'
}

/**
 * Normaliza o item retornado pelo PHP (snake_case) ou mock/legado (camelCase)
 * para a interface OrdemManutencao consumida pelo painel.
 */
export function normalizarOrdem(raw: OrdemServicoApiRaw): OrdemManutencao {
  const torreId = raw.torre_id != null ? Number(raw.torre_id) : (raw.torreId ?? raw.torre?.id ?? null)
  const codigo = raw.torre_codigo ?? raw.torre?.codigo ?? undefined

  return {
    id: Number(raw.id),
    torreId,
    torre: torreId && codigo ? { id: torreId, codigo } : raw.torre ?? null,
    descricao: (raw.descricao_problema ?? raw.descricao ?? '').trim(),
    prioridade: (String(raw.prioridade ?? 'MEDIA').trim().toUpperCase() as PrioridadeOS),
    status: String(raw.status ?? 'ABERTA').trim().toUpperCase().replace(/\s+/g, '_'),
    dataAbertura: raw.data_abertura ?? raw.dataAbertura ?? '',
  }
}

export function useManutencao() {
  const ordens = ref<OrdemManutencao[]>([])
  const carregando = ref(false)
  const enviando = ref(false)
  const erro = ref<string | null>(null)
  let timer: ReturnType<typeof setInterval> | null = null

  async function carregar() {
    carregando.value = true
    try {
      const res = await fetch(PHP_OS_API_URL, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
          'X-User-Id': '1',
          'X-User-Role': 'ADMIN',
        },
      })

      const json = await res.json()

      if (!res.ok || json?.sucesso === false) {
        throw new Error(json?.erro || `Erro HTTP ${res.status}`)
      }

      // Garante extração do array tanto no formato { sucesso: true, dados: [...] } quanto [...]
      const listaBruta: OrdemServicoApiRaw[] = Array.isArray(json?.dados)
        ? json.dados
        : Array.isArray(json)
          ? json
          : []

      ordens.value = listaBruta
        .filter((item) => isStatusAtivo(item.status))
        .map(normalizarOrdem)

      erro.value = null
    } catch (e) {
      // Fallback para o mock apenas se a simulação estiver ativa e o Docker/PHP inacessível
      if (simulacao.value) {
        try {
          const mockData = await mockBackend.pendentes()
          ordens.value = (Array.isArray(mockData) ? mockData : [])
            .filter((item) => isStatusAtivo(item.status))
            .map(normalizarOrdem)
          erro.value = null
          return
        } catch {
          /* continua para erro abaixo */
        }
      }
      erro.value = (e as Error).message || 'Falha ao buscar Ordens de Serviço'
    } finally {
      carregando.value = false
    }
  }

  async function abrir(ordem: NovaOrdemManutencao): Promise<boolean> {
    enviando.value = true
    try {
      const res = await fetch(PHP_OS_API_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-User-Id': '1',
          'X-User-Role': 'ADMIN',
        },
        body: JSON.stringify({
          torre_id: ordem.torreId,
          usuario_criador_id: 1,
          usuario_role: 'ADMIN',
          prioridade: ordem.prioridade,
          descricao_problema: ordem.descricao,
          bloquear_torre: true,
        }),
      })

      const json = await res.json()
      if (!res.ok || !json?.sucesso) {
        throw new Error(json?.erro || `Erro HTTP ${res.status}`)
      }

      await carregar()
      erro.value = null
      return true
    } catch (e) {
      if (simulacao.value) {
        await mockBackend.abrirOrdem(ordem)
        await carregar()
        erro.value = null
        return true
      }
      erro.value = (e as Error).message || 'Erro ao registrar O.S.'
      return false
    } finally {
      enviando.value = false
    }
  }

  watch(simulacao, () => {
    carregar()
  })

  onMounted(() => {
    carregar()
    timer = setInterval(carregar, POLL_MANUTENCAO_MS)
  })

  onBeforeUnmount(() => {
    if (timer) clearInterval(timer)
  })

  return { ordens, carregando, enviando, erro, carregar, abrir }
}
