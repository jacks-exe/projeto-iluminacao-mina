import { PINOS_POSTES, POTENCIA_NOMINAL_W, calcularConsumoW, clampPwm, getModo, modoPorPwm } from '@/config/scada'
import type {
  LogAuditoriaTelemetria,
  ModoIluminacao,
  NovaOrdemManutencao,
  OrdemManutencao,
  PeriodoAnalytics,
  RespostaAnalytics,
  Setor,
  Torre,
} from '@/types/scada'

/* ==========================================================================
   Backend simulado em memória — espelha o contrato do Backend PHP mysqli / MySQL.
   Portas PWM Sequenciais do Arduino Mega: 2, 3, 4, 5 e 6.
   ========================================================================== */

const LATENCIA_MS = 140

const setores: Setor[] = [
  { id: 1, nome: 'Setor Norte - Lavra A', condicaoClimatica: 'NORMAL' },
  { id: 2, nome: 'Setor Leste - Rampa B', condicaoClimatica: 'NORMAL' },
  { id: 3, nome: 'Setor Oeste - Britagem', condicaoClimatica: 'NORMAL' },
]

const INICIAL: { modo: ModoIluminacao; bateria: number; setor: number; conectado: boolean }[] = [
  { modo: 'NORMAL', bateria: 94, setor: 1, conectado: true },
  { modo: 'NORMAL', bateria: 89, setor: 1, conectado: true },
  { modo: 'ECONOMIA', bateria: 78, setor: 2, conectado: true },
  { modo: 'DESLIGADA', bateria: 97, setor: 3, conectado: true },
  { modo: 'NORMAL', bateria: 91, setor: 2, conectado: true },
]

let torres: Torre[] = criarTorres()
let proximoIdLog = 500
let logsTelemetria: LogAuditoriaTelemetria[] = criarLogsIniciais()

function criarTorres(): Torre[] {
  return INICIAL.map((cfg, i) => {
    const pwm = getModo(cfg.modo).pwm
    return {
      id: i + 1,
      codigo: `TR-0${i + 1}`,
      pinoPwm: PINOS_POSTES[i] ?? 2 + i,
      modoIluminacao: cfg.modo,
      nivelBrilhoAtual: pwm,
      statusOperacional: 'OPERANDO',
      conectado: cfg.conectado,
      consumoWatts: calcularConsumoW(pwm, cfg.conectado),
      bateria: cfg.bateria,
      ldrAtual: 310,
      setor: setores.find((s) => s.id === cfg.setor) ?? null,
    }
  })
}

function isoMinutosAtras(min: number): string {
  return new Date(Date.now() - min * 60 * 1000).toISOString().slice(0, 19).replace('T', ' ')
}

function criarLogsIniciais(): LogAuditoriaTelemetria[] {
  const semente: Omit<LogAuditoriaTelemetria, 'id'>[] = [
    {
      torre_id: 1,
      torre_codigo: 'TR-01',
      torre_setor: 'Setor Norte - Lavra A',
      pino_digital: 2,
      nivel_pwm: 0,
      modo_iluminacao: 'DESLIGADA',
      status_operacional: 'OPERANDO',
      conectado: true,
      consumo_watts: 0,
      bateria_pct: 98,
      ldr_valor: 680,
      origem_evento: 'SENSORES_SOFTWARE',
      registrado_em: isoMinutosAtras(360),
    },
    {
      torre_id: 1,
      torre_codigo: 'TR-01',
      torre_setor: 'Setor Norte - Lavra A',
      pino_digital: 2,
      nivel_pwm: 170,
      modo_iluminacao: 'NORMAL',
      status_operacional: 'OPERANDO',
      conectado: true,
      consumo_watts: 266.7,
      bateria_pct: 95,
      ldr_valor: 290,
      origem_evento: 'CASCATA',
      registrado_em: isoMinutosAtras(240),
    },
    {
      torre_id: 2,
      torre_codigo: 'TR-02',
      torre_setor: 'Setor Norte - Lavra A',
      pino_digital: 3,
      nivel_pwm: 170,
      modo_iluminacao: 'NORMAL',
      status_operacional: 'OPERANDO',
      conectado: true,
      consumo_watts: 266.7,
      bateria_pct: 94,
      ldr_valor: 290,
      origem_evento: 'CASCATA',
      registrado_em: isoMinutosAtras(240),
    },
    {
      torre_id: 3,
      torre_codigo: 'TR-03',
      torre_setor: 'Setor Leste - Rampa B',
      pino_digital: 4,
      nivel_pwm: 255,
      modo_iluminacao: 'CERRACAO',
      status_operacional: 'OPERANDO',
      conectado: true,
      consumo_watts: 400,
      bateria_pct: 88,
      ldr_valor: 180,
      origem_evento: 'CONTINGENCIA',
      registrado_em: isoMinutosAtras(150),
    },
    {
      torre_id: 4,
      torre_codigo: 'TR-04',
      torre_setor: 'Setor Oeste - Britagem',
      pino_digital: 5,
      nivel_pwm: 70,
      modo_iluminacao: 'ECONOMIA',
      status_operacional: 'OPERANDO',
      conectado: true,
      consumo_watts: 109.8,
      bateria_pct: 84,
      ldr_valor: 250,
      origem_evento: 'DASHBOARD_WEB',
      registrado_em: isoMinutosAtras(90),
    },
    {
      torre_id: 5,
      torre_codigo: 'TR-05',
      torre_setor: 'Setor Leste - Rampa B',
      pino_digital: 6,
      nivel_pwm: 170,
      modo_iluminacao: 'NORMAL',
      status_operacional: 'OPERANDO',
      conectado: true,
      consumo_watts: 266.7,
      bateria_pct: 91,
      ldr_valor: 310,
      origem_evento: 'ARDUINO_IOT',
      registrado_em: isoMinutosAtras(25),
    },
  ]

  return semente.reverse().map((item, idx) => ({
    id: 400 + idx,
    ...item,
  }))
}

function registrarLogLocal(t: Torre, origem: string) {
  const operante = t.statusOperacional !== 'EM_MANUTENCAO' && t.conectado !== false
  const pwm = operante ? clampPwm(t.nivelBrilhoAtual) : 0
  const novo: LogAuditoriaTelemetria = {
    id: proximoIdLog++,
    torre_id: t.id,
    torre_codigo: t.codigo,
    torre_setor: t.setor?.nome ?? 'Setor Principal',
    pino_digital: t.pinoPwm,
    nivel_pwm: pwm,
    modo_iluminacao: t.modoIluminacao,
    status_operacional: t.statusOperacional,
    conectado: t.conectado !== false,
    consumo_watts: calcularConsumoW(pwm, operante),
    bateria_pct: Math.round((t.bateria ?? 92) * 10) / 10,
    ldr_valor: t.ldrAtual ?? 310,
    origem_evento: origem,
    registrado_em: isoMinutosAtras(0),
  }
  logsTelemetria = [novo, ...logsTelemetria].slice(0, 200)
}

const agora = Date.now()
let proximoIdOS = 103
let ordens: OrdemManutencao[] = [
  {
    id: 101,
    torreId: 3,
    descricao: 'Luminária oscilando em modo economia (Pino PWM 4) — verificar driver MOSFET.',
    prioridade: 'MEDIA',
    status: 'PENDENTE',
    dataAbertura: new Date(agora - 1000 * 60 * 95).toISOString(),
  },
  {
    id: 102,
    torreId: 5,
    descricao: 'Poeira acumulada na lente do refletor após detonação (Pino PWM 6).',
    prioridade: 'BAIXA',
    status: 'PENDENTE',
    dataAbertura: new Date(agora - 1000 * 60 * 30).toISOString(),
  },
]

const delay = <T>(v: T) => new Promise<T>((r) => setTimeout(() => r(v), LATENCIA_MS))
const clone = <T>(v: T): T => JSON.parse(JSON.stringify(v)) as T

/** Dinâmica da bateria (nobreak da torre) e consumo em tempo real */
function tickBaterias() {
  torres = torres.map((t) => {
    const operante = t.statusOperacional !== 'EM_MANUTENCAO' && t.conectado !== false
    const pwmEfetivo = operante ? t.nivelBrilhoAtual : 0
    const carga = pwmEfetivo / 255
    const delta = carga > 0 ? -0.04 * carga - Math.random() * 0.02 : 0.08
    const bateria = Math.max(5, Math.min(100, (t.bateria ?? 90) + delta))
    return {
      ...t,
      bateria: Math.round(bateria * 10) / 10,
      consumoWatts: calcularConsumoW(pwmEfetivo, operante),
    }
  })
}

export class MockHttpError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message)
  }
}

export const mockBackend = {
  async listarTorres(): Promise<Torre[]> {
    tickBaterias()
    return delay(clone(torres))
  },

  async alterarModo(id: number, modo: ModoIluminacao, pwm: number): Promise<Torre> {
    const t = torres.find((x) => x.id === id)
    if (!t) throw new MockHttpError('Poste não encontrado', 404)
    if (t.statusOperacional === 'EM_MANUTENCAO') {
      throw new MockHttpError('Poste em manutenção — acionamento bloqueado', 409)
    }

    const pwmVal = clampPwm(pwm)
    const modoVal = modo || modoPorPwm(pwmVal)
    const operante = t.conectado !== false

    torres = torres.map((x) =>
      x.id === id
        ? {
            ...x,
            modoIluminacao: modoVal,
            nivelBrilhoAtual: pwmVal,
            consumoWatts: calcularConsumoW(pwmVal, operante),
          }
        : x,
    )
    const atualizada = torres.find((x) => x.id === id)!
    registrarLogLocal(atualizada, 'DASHBOARD_WEB')
    return delay(clone(atualizada))
  },

  async alterarConectividade(id: number, conectado: boolean): Promise<Torre> {
    const t = torres.find((x) => x.id === id)
    if (!t) throw new MockHttpError('Poste não encontrado', 404)

    torres = torres.map((x) =>
      x.id === id
        ? {
            ...x,
            conectado,
            consumoWatts: calcularConsumoW(
              x.nivelBrilhoAtual,
              conectado && x.statusOperacional !== 'EM_MANUTENCAO',
            ),
          }
        : x,
    )
    const atualizada = torres.find((x) => x.id === id)!
    registrarLogLocal(atualizada, 'DASHBOARD_WEB')
    return delay(clone(atualizada))
  },

  async acionarCascata(pwmAlvo: number): Promise<Torre[]> {
    const pwmVal = clampPwm(pwmAlvo)
    const modoVal = modoPorPwm(pwmVal)

    torres = torres.map((x) => {
      if (x.statusOperacional === 'EM_MANUTENCAO' || x.conectado === false) return x
      const nova: Torre = {
        ...x,
        modoIluminacao: modoVal,
        nivelBrilhoAtual: pwmVal,
        consumoWatts: calcularConsumoW(pwmVal, true),
      }
      registrarLogLocal(nova, pwmVal === 255 ? 'CONTINGENCIA' : 'CASCATA')
      return nova
    })
    return delay(clone(torres))
  },

  async atualizarSensoresVirtuais(bateria: number, ldr: number, aplicarRegraAuto = true): Promise<Torre[]> {
    const batClamped = Math.max(0, Math.min(100, Math.round(bateria)))
    const ldrClamped = Math.max(0, Math.min(1023, Math.round(ldr)))

    torres = torres.map((x) => {
      const operante = x.statusOperacional !== 'EM_MANUTENCAO' && x.conectado !== false
      let pwm = x.nivelBrilhoAtual
      let modo = x.modoIluminacao

      if (aplicarRegraAuto && operante) {
        if (batClamped < 5 || ldrClamped >= 450) {
          pwm = 0
          modo = 'DESLIGADA'
        } else if (batClamped < 25) {
          pwm = 70
          modo = 'ECONOMIA'
        } else {
          pwm = 170
          modo = 'NORMAL'
        }
      } else if (!operante) {
        pwm = 0
      }

      const nova: Torre = {
        ...x,
        bateria: batClamped,
        ldrAtual: ldrClamped,
        nivelBrilhoAtual: pwm,
        modoIluminacao: modo,
        consumoWatts: calcularConsumoW(pwm, operante),
      }
      registrarLogLocal(nova, 'SENSORES_SOFTWARE')
      return nova
    })
    return delay(clone(torres))
  },

  async obterAnalytics(
    periodo: PeriodoAnalytics = '24H',
    torreId = 0,
    origem = 'TODOS',
  ): Promise<RespostaAnalytics> {
    const logsFiltrados = logsTelemetria.filter((l) => {
      if (torreId > 0 && l.torre_id !== torreId) return false
      if (origem && origem !== 'TODOS' && l.origem_evento !== origem) return false
      return true
    })

    const capacidadeTotalW = POTENCIA_NOMINAL_W * torres.length
    const consumoAtualW = torres.reduce((acc, t) => acc + (t.consumoWatts ?? 0), 0)
    const postesOnline = torres.filter((t) => t.conectado !== false).length
    const postesOperando = torres.filter((t) => t.conectado !== false && t.statusOperacional !== 'EM_MANUTENCAO').length
    const postesAcesos = torres.filter(
      (t) => t.conectado !== false && t.statusOperacional !== 'EM_MANUTENCAO' && t.nivelBrilhoAtual > 0,
    ).length
    const bateriaMedia =
      torres.reduce((acc, t) => acc + (t.bateria ?? 92), 0) / Math.max(1, torres.length)

    const porPoste = torres.map((t) => {
      const eventosPoste = logsFiltrados.filter((l) => l.torre_id === t.id)
      const mediaW = eventosPoste.length
        ? eventosPoste.reduce((s, e) => s + e.consumo_watts, 0) / eventosPoste.length
        : (t.consumoWatts ?? 0)
      const picoPwm = eventosPoste.reduce((m, e) => Math.max(m, e.nivel_pwm), t.nivelBrilhoAtual)
      const acesoPct = eventosPoste.length
        ? Math.round((eventosPoste.filter((e) => e.nivel_pwm > 0).length / eventosPoste.length) * 100)
        : t.nivelBrilhoAtual > 0
          ? 100
          : 0

      return {
        torre_id: t.id,
        codigo: t.codigo,
        setor: t.setor?.nome ?? 'Setor Principal',
        pino_pwm: t.pinoPwm,
        modo_atual: t.modoIluminacao,
        pwm_atual: t.nivelBrilhoAtual,
        status_operacional: t.statusOperacional,
        conectado: t.conectado !== false,
        consumo_atual_w: Math.round((t.consumoWatts ?? 0) * 10) / 10,
        bateria_pct: Math.round((t.bateria ?? 92) * 10) / 10,
        atualizado_em: new Date().toISOString().slice(0, 19).replace('T', ' '),
        total_eventos: eventosPoste.length,
        consumo_medio_w: Math.round(mediaW * 10) / 10,
        pico_pwm: picoPwm,
        amostras_aceso_pct: acesoPct,
      }
    })

    const serieBase = [
      { horario: '06:00', consumo_total_w: 0, pwm_medio: 0, bateria_media: 98, economia_pct: 100, eventos: 5 },
      { horario: '12:00', consumo_total_w: 0, pwm_medio: 0, bateria_media: 99, economia_pct: 100, eventos: 5 },
      { horario: '18:00', consumo_total_w: 1333.3, pwm_medio: 170, bateria_media: 95, economia_pct: 33.3, eventos: 5 },
      { horario: '20:00', consumo_total_w: 2000, pwm_medio: 255, bateria_media: 88, economia_pct: 0, eventos: 5 },
      { horario: '22:00', consumo_total_w: 549, pwm_medio: 70, bateria_media: 82, economia_pct: 72.5, eventos: 4 },
      {
        horario: 'Agora',
        consumo_total_w: Math.round(consumoAtualW * 10) / 10,
        pwm_medio: Math.round((consumoAtualW / capacidadeTotalW) * 255),
        bateria_media: Math.round(bateriaMedia * 10) / 10,
        economia_pct: Math.round((1 - consumoAtualW / capacidadeTotalW) * 1000) / 10,
        eventos: logsFiltrados.length,
      },
    ].map((p) => ({ ...p, timestamp: new Date().toISOString() }))

    const horasPeriodo = periodo === '1H' ? 1 : periodo === '6H' ? 6 : periodo === '7D' ? 168 : 24
    const consumoMedioPeriodoW =
      Math.round((serieBase.reduce((s, p) => s + p.consumo_total_w, 0) / serieBase.length) * 10) / 10
    const energiaEstimadaKwh = Math.round(((consumoMedioPeriodoW * horasPeriodo) / 1000) * 100) / 100
    const energiaMaxKwh = (capacidadeTotalW * horasPeriodo) / 1000

    return delay(
      clone({
        sucesso: true,
        dispositivo: 'ARDUINO_MEGA_UNIFICADO',
        pinos_pwm: [...PINOS_POSTES],
        kpis: {
          consumo_atual_w: Math.round(consumoAtualW * 10) / 10,
          consumo_atual_kw: Math.round((consumoAtualW / 1000) * 100) / 100,
          consumo_medio_periodo_w: consumoMedioPeriodoW,
          pico_consumo_w: Math.max(consumoAtualW, ...serieBase.map((s) => s.consumo_total_w)),
          capacidade_instalada_w: capacidadeTotalW,
          energia_estimada_kwh: energiaEstimadaKwh,
          energia_poupada_kwh: Math.max(0, Math.round((energiaMaxKwh - energiaEstimadaKwh) * 100) / 100),
          eficiencia_energetica_pct: Math.max(0, Math.round((1 - consumoAtualW / capacidadeTotalW) * 1000) / 10),
          disponibilidade_sla_pct: Math.round((postesOperando / Math.max(1, torres.length)) * 1000) / 10,
          postes_online: postesOnline,
          postes_operando: postesOperando,
          postes_acesos: postesAcesos,
          total_postes: torres.length,
          total_eventos_periodo: logsFiltrados.length,
          acionamentos_cascata: logsFiltrados.filter((l) =>
            ['CASCATA', 'CONTINGENCIA'].includes(l.origem_evento),
          ).length,
          bateria_media_pct: Math.round(bateriaMedia * 10) / 10,
          ldr_atual: torres[0]?.ldrAtual ?? 310,
        },
        por_poste: porPoste,
        serie_temporal: serieBase,
        logs_auditoria: logsFiltrados,
      }),
    )
  },

  async pendentes(): Promise<OrdemManutencao[]> {
    return delay(clone([...ordens].sort((a, b) => b.id - a.id)))
  },

  async abrirOrdem(nova: NovaOrdemManutencao): Promise<OrdemManutencao> {
    const torre = torres.find((t) => t.id === nova.torreId)
    const ordem: OrdemManutencao = {
      id: proximoIdOS++,
      torreId: nova.torreId,
      torre: torre ? { id: torre.id, codigo: torre.codigo } : null,
      descricao: nova.descricao,
      prioridade: nova.prioridade,
      status: 'PENDENTE',
      dataAbertura: new Date().toISOString(),
    }
    ordens = [ordem, ...ordens]
    return delay(clone(ordem))
  },

  /** Restaura o estado inicial da simulação */
  reset() {
    torres = criarTorres()
    logsTelemetria = criarLogsIniciais()
  },
}
