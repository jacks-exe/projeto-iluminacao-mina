import { ref } from 'vue'
import { getCurrentUser, type AuthUser, type UserRole } from '@/config/auth'
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

/** URL do Backend PHP Procedural (mysqli) */
export const PHP_API_BASE_URL =
  (import.meta.env.VITE_PHP_API_URL as string | undefined) ?? 'http://localhost:8000'

/** Endpoint PHP separado exclusivamente para envio de comandos seriais no clique (POST) */
const PHP_COMANDO_SERIAL_URL = `${PHP_API_BASE_URL}/comando_serial.php`

/** Endpoint da Ponte Serial local (115200 bps) */
const PONTE_IOT_EVENTO_URL = 'http://127.0.0.1:5005/evento'

/** Estado reativo indicando se o hardware físico do Arduino está online via Serial/Heartbeat */
export const arduinoFisicoOnline = ref<boolean>(false)

/**
 * Envia o comando para a porta serial de forma INSTANTÂNEA (< 5ms) no momento do clique,
 * comunicando-se prioritariamente com a Ponte Local Python (porta 5005) e validando bloqueio de manutenção.
 */
function enviarComandoSerialPorEvento(dados: {
  acao: string
  id?: number
  pwm?: number
  conectado?: boolean
  em_manutencao?: boolean
  bateria?: number
  ldr?: number
  comando: string
}): void {
  const payloadDireto = {
    comando: `${dados.comando.trim()}\n`,
    acao: dados.acao,
    id: dados.id,
    pwm: dados.pwm,
    em_manutencao: dados.em_manutencao,
    bateria: dados.bateria,
    ldr: dados.ldr,
  }

  fetch(PONTE_IOT_EVENTO_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payloadDireto),
  })
    .then(async (r) => {
      const j = await r.json().catch(() => null)
      if (j && typeof j.online === 'boolean') {
        arduinoFisicoOnline.value = j.online
      }
    })
    .catch(() => {
      arduinoFisicoOnline.value = false
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
        else if (parsed?.message) msg = parsed.message
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

  const emManutencao = Boolean(
    raw?.emManutencao === true ||
      raw?.em_manutencao === true ||
      Number(raw?.em_manutencao) === 1 ||
      String(raw?.statusOperacional ?? raw?.status_operacional ?? 'OPERANDO').toUpperCase() === 'EM_MANUTENCAO',
  )

  const statusOperacional = emManutencao ? 'EM_MANUTENCAO' : 'OPERANDO'

  const conectado =
    raw?.conectado !== undefined && raw?.conectado !== null
      ? Boolean(Number(raw.conectado) === 1 || raw.conectado === true)
      : true

  const pwmBruto = Number(raw?.nivelBrilhoAtual ?? raw?.nivel_brilho_atual ?? 0)
  const pwmAntesManutencao = Number(raw?.pwmAntesManutencao ?? raw?.pwm_antes_manutencao ?? pwmBruto)
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
    pwmAntesManutencao,
    pwm_antes_manutencao: pwmAntesManutencao,
    statusOperacional,
    emManutencao,
    em_manutencao: emManutencao,
    conectado,
    consumoWatts,
    bateria: typeof raw?.bateria === 'number' ? raw.bateria : Number(raw?.bateria ?? 92),
    ldrAtual: typeof raw?.ldrAtual === 'number' ? raw.ldrAtual : Number(raw?.ldr_atual ?? 310),
    atualizadoEm: raw?.atualizadoEm ?? raw?.atualizado_em ?? null,
    setor,
  }
}

async function withSimulacao<T>(
  realFn: () => Promise<T>,
  mockFn: () => Promise<T>,
  syncPhpQuietly?: () => Promise<unknown>,
): Promise<T> {
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
        const res = await fetchJson<any>(
          `${PHP_API_BASE_URL}/postes.php${simulacao.value ? '?simulacao=1' : ''}`,
        )
        if (typeof res?.arduino_online === 'boolean') {
          arduinoFisicoOnline.value = res.arduino_online
        } else {
          const arrTmp = Array.isArray(res?.dados) ? res.dados : Array.isArray(res) ? res : []
          arduinoFisicoOnline.value = arrTmp.some(
            (item: any) => Boolean(Number(item?.conectado) === 1 || item?.conectado === true),
          )
        }
        const arr = Array.isArray(res?.dados) ? res.dados : Array.isArray(res) ? res : []
        return arr.map((item: any, i: number) => normalizarPoste(item, i))
      },
      async () => {
        arduinoFisicoOnline.value = true
        return mockBackend.listarTorres()
      },
    ),

  alterarModo: (id: number, modoIluminacao: ModoIluminacao, nivelBrilhoAtual: number) => {
    const pwmClamped = clampPwm(nivelBrilhoAtual)
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

    try {
      return await fetchJson<RespostaAnalytics>(url, {}, 3500)
    } catch {
      return mockBackend.obterAnalytics(periodo, torreId, origem)
    }
  },
}

export const usuariosApi = {
  listar: async (): Promise<AuthUser[]> => {
    const res = await fetchJson<any>(`${PHP_API_BASE_URL}/usuarios.php`, { method: 'GET' }, 3500)
    return Array.isArray(res?.dados) ? res.dados : []
  },

  criar: async (payload: {
    nome: string
    username: string
    role: UserRole
    password?: string
    email?: string
  }): Promise<{ usuario: AuthUser; mensagem: string }> => {
    const res = await fetchJson<any>(
      `${PHP_API_BASE_URL}/usuarios.php`,
      {
        method: 'POST',
        body: JSON.stringify({
          nome: payload.nome,
          username: payload.username,
          role: payload.role,
          password: payload.password || 'geonex123',
          email: payload.email,
          primeiro_acesso: true,
        }),
      },
      4000,
    )
    return {
      usuario: res.dados as AuthUser,
      mensagem: res.mensagem || 'Usuário criado com sucesso.',
    }
  },

  atualizar: async (payload: {
    id: number
    nome: string
    username: string
    role: UserRole
    password?: string
    email?: string
    primeiro_acesso?: boolean
  }): Promise<{ usuario: AuthUser; mensagem: string }> => {
    const res = await fetchJson<any>(
      `${PHP_API_BASE_URL}/usuarios.php`,
      {
        method: 'PUT',
        body: JSON.stringify(payload),
      },
      4000,
    )
    return {
      usuario: res.dados as AuthUser,
      mensagem: res.mensagem || 'Usuário atualizado com sucesso.',
    }
  },

  remover: async (id: number): Promise<string> => {
    const res = await fetchJson<any>(
      `${PHP_API_BASE_URL}/usuarios.php?id=${id}`,
      {
        method: 'DELETE',
        body: JSON.stringify({ id }),
      },
      4000,
    )
    return res?.mensagem || 'Usuário removido com sucesso.'
  },
}
