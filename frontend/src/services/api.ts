import { getCurrentUser } from '@/config/auth'
import { PINOS_POSTES, REQUEST_TIMEOUT_MS, calcularConsumoW, clampPwm, modoPorPwm } from '@/config/scada'
import { mockBackend } from '@/services/mockBackend'
import { autoFallback, simulacao } from '@/services/simulacao'
import type {
  ModoIluminacao,
  NovaOrdemManutencao,
  OrdemManutencao,
  PeriodoAnalytics,
  RespostaAnalytics,
  Torre,
} from '@/types/scada'

/** URL do Backend PHP Procedural (mysqli) - Passo 3 */
export const PHP_API_BASE_URL =
  (import.meta.env.VITE_PHP_API_URL as string | undefined) ?? 'http://localhost:8000'

/** Endpoint PHP separado exclusivamente para envio de comandos seriais no clique (POST) */
const PHP_COMANDO_SERIAL_URL = `${PHP_API_BASE_URL}/comando_serial.php`

/** Fallback direto para a Ponte Serial local (115200 bps) caso o front esteja em Modo Simulação */
const PONTE_IOT_EVENTO_URL = 'http://127.0.0.1:5005/evento'

/**
 * Envia o comando para a porta serial de forma INSTANTÂNEA (< 5ms) no momento do clique,
 * comunicando-se prioritariamente com a Ponte Local Python (porta 5005) sem passar
 * por gargalos do PHP em fila única.
 */
function enviarComandoSerialPorEvento(dados: {
  acao: string
  id?: number
  pwm?: number
  conectado?: boolean
  bateria?: number
  ldr?: number
  comando: string
}): void {
  const payloadDireto = {
    comando: `${dados.comando.trim()}\n`,
    acao: dados.acao,
    id: dados.id,
    pwm: dados.pwm,
    bateria: dados.bateria,
    ldr: dados.ldr,
  }

  // 1. Envio de ultra-baixa latência diretamente para a Ponte IoT (115200 bps)
  fetch(PONTE_IOT_EVENTO_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payloadDireto),
  }).catch(() => {
    // 2. Fallback via PHP caso o endpoint 5005 não esteja acessível
    const usr = getCurrentUser()
    fetch(PHP_COMANDO_SERIAL_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-User-Id': String(usr.id),
        'X-User-Role': usr.role,
      },
      body: JSON.stringify(dados),
    }).catch(() => undefined)
  })
}

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

async function fetchJson<T>(url: string, init: RequestInit = {}, timeoutMs = REQUEST_TIMEOUT_MS): Promise<T> {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), timeoutMs)

  try {
    const usr = getCurrentUser()
    const res = await fetch(url, {
      ...init,
      signal: controller.signal,
      headers: {
        Accept: 'application/json',
        ...(init.body ? { 'Content-Type': 'application/json' } : {}),
        'X-User-Id': String(usr.id),
        'X-User-Role': usr.role,
        ...init.headers,
      },
    })

    if (!res.ok) {
      const text = await res.text().catch(() => '')
      let msg = text || `HTTP ${res.status}`
      try {
        const parsed = JSON.parse(text)
        if (parsed?.erro) msg = parsed.erro
      } catch {
        /* ignore */
      }
      throw new ApiError(msg, res.status)
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

/** Normaliza o objeto de poste vindo do PHP mysqli ou Spring Boot para o contrato do Dashboard */
function normalizarPoste(raw: any, index = 0): Torre {
  const id = Number(raw?.id ?? index + 1)
  const idx = Math.max(0, Math.min(4, id - 1))
  const pinoBruto = Number(raw?.pinoPwm ?? raw?.pino_pwm ?? 0)
  const pinoPwm = pinoBruto >= 2 && pinoBruto <= 6 ? pinoBruto : (PINOS_POSTES[idx] ?? 2 + idx)

  const statusOperacional =
    String(raw?.statusOperacional ?? raw?.status_operacional ?? 'OPERANDO').toUpperCase() === 'EM_MANUTENCAO'
      ? 'EM_MANUTENCAO'
      : 'OPERANDO'

  const conectado =
    raw?.conectado !== undefined && raw?.conectado !== null
      ? Boolean(Number(raw.conectado) === 1 || raw.conectado === true)
      : true

  const pwmBruto = Number(raw?.nivelBrilhoAtual ?? raw?.nivel_brilho_atual ?? 0)
  const nivelBrilhoAtual =
    statusOperacional === 'EM_MANUTENCAO' || !conectado ? 0 : clampPwm(pwmBruto)

  const modoRaw = String(raw?.modoIluminacao ?? raw?.modo_iluminacao ?? '').toUpperCase()
  const modoIluminacao: ModoIluminacao =
    modoRaw === 'DESLIGADA' || modoRaw === 'ECONOMIA' || modoRaw === 'NORMAL' || modoRaw === 'CERRACAO'
      ? (modoRaw as ModoIluminacao)
      : modoPorPwm(nivelBrilhoAtual)

  const operante = statusOperacional === 'OPERANDO' && conectado
  const consumoWatts =
    typeof raw?.consumoWatts === 'number'
      ? raw.consumoWatts
      : typeof raw?.consumo_watts === 'number'
        ? raw.consumo_watts
        : calcularConsumoW(nivelBrilhoAtual, operante)

  const setorRaw = raw?.setor
  const setor =
    typeof setorRaw === 'string'
      ? { id, nome: setorRaw, condicaoClimatica: modoIluminacao === 'CERRACAO' ? 'CERRACAO' : 'NORMAL' }
      : (setorRaw ?? null)

  return {
    id,
    codigo: String(raw?.codigo ?? `TR-0${id}`),
    pinoPwm,
    modoIluminacao,
    nivelBrilhoAtual,
    statusOperacional,
    conectado,
    consumoWatts,
    bateria: typeof raw?.bateria === 'number' ? raw.bateria : Number(raw?.bateria ?? 92),
    ldrAtual: typeof raw?.ldrAtual === 'number' ? raw.ldrAtual : Number(raw?.ldr_atual ?? 310),
    atualizadoEm: raw?.atualizadoEm ?? raw?.atualizado_em ?? null,
    setor,
  }
}

/**
 * Executa a chamada prioritariamente na API PHP (`postes.php`);
 * se estiver em Modo Simulação (ou se a API real falhar com autoFallback),
 * delega para o mockBackend local mantendo sincronia silenciosa com o PHP quando disponível.
 */
async function withSimulacao<T>(realFn: () => Promise<T>, mockFn: () => Promise<T>, syncPhpQuietly?: () => Promise<unknown>): Promise<T> {
  if (simulacao.value) {
    if (syncPhpQuietly) {
      syncPhpQuietly().catch(() => undefined)
    }
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
      async () => {
        try {
          const res = await fetchJson<any>(`${PHP_API_BASE_URL}/postes.php`)
          const arr = Array.isArray(res?.dados) ? res.dados : Array.isArray(res) ? res : []
          return arr.map((item: any, i: number) => normalizarPoste(item, i))
        } catch {
          const resSpring = await fetchJson<any[]>(`${BASE_URL}/torres`)
          return (Array.isArray(resSpring) ? resSpring : []).map((item, i) => normalizarPoste(item, i))
        }
      },
      () => mockBackend.listarTorres(),
    ),

  alterarModo: (id: number, modoIluminacao: ModoIluminacao, nivelBrilhoAtual: number) => {
    const pwmClamped = clampPwm(nivelBrilhoAtual)
    // Envio por evento via POST para o arquivo separado `comando_serial.php` no momento do clique
    enviarComandoSerialPorEvento({
      acao: 'POSTE',
      id,
      pwm: pwmClamped,
      comando: `POSTE:${id},${pwmClamped}`,
    })

    const payload = {
      id,
      modoIluminacao,
      modo_iluminacao: modoIluminacao,
      nivelBrilhoAtual: pwmClamped,
      nivel_brilho_atual: pwmClamped,
    }

    const callPhp = async () => {
      const res = await fetchJson<any>(
        `${PHP_API_BASE_URL}/postes.php`,
        { method: 'PUT', body: JSON.stringify(payload) },
        4000,
      )
      return normalizarPoste(res?.dados ?? res, id - 1)
    }

    return withSimulacao(
      async () => {
        try {
          return await callPhp()
        } catch (err) {
          if (err instanceof ApiError && err.status === 409) throw err
          const resSpring = await fetchJson<any>(
            `${BASE_URL}/torres/${id}/modo`,
            { method: 'PUT', body: JSON.stringify({ modoIluminacao, nivelBrilhoAtual }) },
            4000,
          )
          return normalizarPoste(resSpring, id - 1)
        }
      },
      () => mockBackend.alterarModo(id, modoIluminacao, nivelBrilhoAtual),
      callPhp,
    )
  },

  alterarConectividade: (id: number, conectado: boolean) => {
    enviarComandoSerialPorEvento({
      acao: 'CONECTIVIDADE',
      id,
      conectado,
      comando: `CONN:${id},${conectado ? 1 : 0}`,
    })
    const callPhp = async () => {
      const res = await fetchJson<any>(
        `${PHP_API_BASE_URL}/postes.php`,
        { method: 'PUT', body: JSON.stringify({ id, conectado }) },
        3000,
      )
      return normalizarPoste(res?.dados ?? res, id - 1)
    }

    return withSimulacao(
      callPhp,
      () => mockBackend.alterarConectividade(id, conectado),
      callPhp,
    )
  },

  acionarCascata: (nivelBrilhoAtual: number) => {
    const pwmVal = clampPwm(nivelBrilhoAtual)
    const modoIluminacao = modoPorPwm(pwmVal)
    enviarComandoSerialPorEvento({
      acao: 'CASCATA',
      pwm: pwmVal,
      comando: `CASCATA:${pwmVal}`,
    })
    const callPhp = async () => {
      const res = await fetchJson<any>(
        `${PHP_API_BASE_URL}/postes.php`,
        {
          method: 'POST',
          body: JSON.stringify({
            acao: 'CASCATA',
            nivelBrilhoAtual: pwmVal,
            modoIluminacao,
          }),
        },
        4000,
      )
      const arr = Array.isArray(res?.dados) ? res.dados : []
      return arr.map((item: any, i: number) => normalizarPoste(item, i))
    }

    return withSimulacao(
      callPhp,
      () => mockBackend.acionarCascata(pwmVal),
      callPhp,
    )
  },

  atualizarSensoresVirtuais: (bateria: number, ldr: number, aplicarRegraAuto = true) => {
    const batClamped = Math.max(0, Math.min(100, Math.round(bateria)))
    const ldrClamped = Math.max(0, Math.min(1023, Math.round(ldr)))
    const alvoPwm = aplicarRegraAuto
      ? (batClamped < 5 || ldrClamped >= 450 ? 0 : batClamped < 25 ? 70 : 170)
      : undefined

    enviarComandoSerialPorEvento({
      acao: 'SENSORES_VIRTUAIS',
      bateria: batClamped,
      ldr: ldrClamped,
      pwm: alvoPwm,
      comando: `TELEM:${batClamped},${ldrClamped}`,
    })
    const callPhp = async () => {
      const res = await fetchJson<any>(
        `${PHP_API_BASE_URL}/postes.php`,
        {
          method: 'POST',
          body: JSON.stringify({
            acao: 'SENSORES_VIRTUAIS',
            bateria: batClamped,
            ldr: ldrClamped,
            aplicar_regra_auto: aplicarRegraAuto,
          }),
        },
        4000,
      )
      const arr = Array.isArray(res?.dados) ? res.dados : []
      return arr.map((item: any, i: number) => normalizarPoste(item, i))
    }

    return withSimulacao(
      callPhp,
      () => mockBackend.atualizarSensoresVirtuais(batClamped, ldrClamped, aplicarRegraAuto),
      callPhp,
    )
  },
}

export const manutencaoApi = {
  pendentes: () =>
    withSimulacao(
      () => fetchJson<OrdemManutencao[]>(`${BASE_URL}/manutencao/pendentes`, {}, 4000),
      () => mockBackend.pendentes(),
    ),

  abrir: (ordem: NovaOrdemManutencao) =>
    withSimulacao(
      () =>
        fetchJson<OrdemManutencao>(
          `${BASE_URL}/manutencao`,
          { method: 'POST', body: JSON.stringify(ordem) },
          4000,
        ),
      () => mockBackend.abrirOrdem(ordem),
    ),
}

export const analyticsApi = {
  consultar: async (
    periodo: PeriodoAnalytics = '24H',
    torreId = 0,
    origem = 'TODOS',
  ): Promise<RespostaAnalytics> => {
    const params = new URLSearchParams({
      periodo,
      torre_id: String(torreId),
      origem,
      limite: '100',
    })
    const url = `${PHP_API_BASE_URL}/historico_telemetria.php?${params.toString()}`

    if (!simulacao.value) {
      try {
        return await fetchJson<RespostaAnalytics>(url, {}, 3500)
      } catch {
        return mockBackend.obterAnalytics(periodo, torreId, origem)
      }
    }

    // Em modo simulação, tenta buscar do PHP se estiver rodando; caso contrário usa o mock em tempo real
    try {
      return await fetchJson<RespostaAnalytics>(url, {}, 1500)
    } catch {
      return mockBackend.obterAnalytics(periodo, torreId, origem)
    }
  },
}
