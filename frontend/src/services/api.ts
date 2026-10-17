import { REQUEST_TIMEOUT_MS } from '@/config/scada'
import { mockBackend } from '@/services/mockBackend'
import { autoFallback, simulacao } from '@/services/simulacao'
import type {
  ModoIluminacao,
  NovaOrdemManutencao,
  OrdemManutencao,
  Torre,
} from '@/types/scada'

/** Em dev o Vite faz proxy de /api -> http://localhost:8080 */
const BASE_URL = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? '/api'

export class ApiError extends Error {
  constructor(
    message: string,
    public status?: number,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

async function request<T>(path: string, init: RequestInit = {}, timeoutMs = REQUEST_TIMEOUT_MS): Promise<T> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeoutMs)

  try {
    const res = await fetch(`${BASE_URL}${path}`, {
      ...init,
      signal: controller.signal,
      headers: {
        Accept: 'application/json',
        ...(init.body ? { 'Content-Type': 'application/json' } : {}),
        ...init.headers,
      },
    })

    if (!res.ok) {
      const text = await res.text().catch(() => '')
      throw new ApiError(text || `HTTP ${res.status}`, res.status)
    }

    if (res.status === 204) return undefined as T
    const text = await res.text()
    return (text ? JSON.parse(text) : undefined) as T
  } catch (err) {
    if (err instanceof ApiError) throw err
    if ((err as Error).name === 'AbortError') throw new ApiError('Timeout de comunicação')
    throw new ApiError((err as Error).message || 'Falha de rede')
  } finally {
    clearTimeout(timer)
  }
}

/**
 * Executa a chamada real; se estiver em Modo Simulação (ou se a chamada real
 * falhar e o autoFallback estiver habilitado), delega para o mockBackend local.
 */
async function withSimulacao<T>(realFn: () => Promise<T>, mockFn: () => Promise<T>): Promise<T> {
  if (simulacao.value) {
    return mockFn()
  }
  try {
    return await realFn()
  } catch (err) {
    if (autoFallback.value) {
      simulacao.value = true
      return mockFn()
    }
    throw err
  }
}

export const torresApi = {
  listar: () =>
    withSimulacao(
      () => request<Torre[]>('/torres'),
      () => mockBackend.listarTorres(),
    ),

  /**
   * Corpo segue os nomes de campo da entidade Torre, para binding direto
   * com @RequestBody no Spring Boot.
   */
  alterarModo: (id: number, modoIluminacao: ModoIluminacao, nivelBrilhoAtual: number) =>
    withSimulacao(
      () =>
        request<Torre>(
          `/torres/${id}/modo`,
          { method: 'PUT', body: JSON.stringify({ modoIluminacao, nivelBrilhoAtual }) },
          4000,
        ),
      () => mockBackend.alterarModo(id, modoIluminacao, nivelBrilhoAtual),
    ),
}

export const manutencaoApi = {
  pendentes: () =>
    withSimulacao(
      () => request<OrdemManutencao[]>('/manutencao/pendentes', {}, 4000),
      () => mockBackend.pendentes(),
    ),

  abrir: (ordem: NovaOrdemManutencao) =>
    withSimulacao(
      () => request<OrdemManutencao>('/manutencao', { method: 'POST', body: JSON.stringify(ordem) }, 4000),
      () => mockBackend.abrirOrdem(ordem),
    ),
}
