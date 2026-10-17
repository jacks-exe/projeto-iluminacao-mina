import { getModo } from '@/config/scada'
import type {
  ModoIluminacao,
  NovaOrdemManutencao,
  OrdemManutencao,
  Setor,
  Torre,
} from '@/types/scada'

/* ==========================================================================
   Backend simulado em memória — espelha o contrato do Spring Boot.
   Usado quando o Modo Simulação está ativo (backend/Arduino desligados).
   ========================================================================== */

const LATENCIA_MS = 180

const setores: Setor[] = [
  { id: 1, nome: 'Setor Norte - Lavra A', condicaoClimatica: 'NORMAL' },
  { id: 2, nome: 'Setor Leste - Rampa B', condicaoClimatica: 'NORMAL' },
  { id: 3, nome: 'Setor Oeste - Britagem', condicaoClimatica: 'NORMAL' },
]

const PINOS = [3, 5, 6, 9, 10]

const INICIAL: { modo: ModoIluminacao; bateria: number; setor: number }[] = [
  { modo: 'NORMAL', bateria: 92, setor: 1 },
  { modo: 'NORMAL', bateria: 85, setor: 1 },
  { modo: 'ECONOMIA', bateria: 78, setor: 2 },
  { modo: 'DESLIGADA', bateria: 96, setor: 3 },
  { modo: 'NORMAL', bateria: 88, setor: 2 },
]

let torres: Torre[] = criarTorres()

function criarTorres(): Torre[] {
  return INICIAL.map((cfg, i) => ({
    id: i + 1,
    codigo: `TR-0${i + 1}`,
    pinoPwm: PINOS[i] ?? 3,
    modoIluminacao: cfg.modo,
    nivelBrilhoAtual: getModo(cfg.modo).pwm,
    statusOperacional: 'OPERANDO',
    bateria: cfg.bateria,
    setor: setores.find((s) => s.id === cfg.setor) ?? null,
  }))
}

const agora = Date.now()
let proximoIdOS = 103
let ordens: OrdemManutencao[] = [
  {
    id: 101,
    torreId: 3,
    descricao: 'Luminária oscilando em modo economia — verificar driver PWM.',
    prioridade: 'MEDIA',
    status: 'PENDENTE',
    dataAbertura: new Date(agora - 1000 * 60 * 95).toISOString(),
  },
  {
    id: 102,
    torreId: 5,
    descricao: 'Poeira acumulada na lente do refletor após detonação.',
    prioridade: 'BAIXA',
    status: 'PENDENTE',
    dataAbertura: new Date(agora - 1000 * 60 * 30).toISOString(),
  },
]

const delay = <T>(v: T) => new Promise<T>((r) => setTimeout(() => r(v), LATENCIA_MS))
const clone = <T>(v: T): T => JSON.parse(JSON.stringify(v)) as T

/** Dinâmica da bateria (nobreak da torre): descarrega com o PWM, recarrega desligada */
function tickBaterias() {
  torres = torres.map((t) => {
    const carga = t.statusOperacional === 'EM_MANUTENCAO' ? 0 : t.nivelBrilhoAtual / 255
    const delta = carga > 0 ? -0.04 * carga - Math.random() * 0.02 : 0.08
    const bateria = Math.max(5, Math.min(100, (t.bateria ?? 90) + delta))
    return { ...t, bateria: Math.round(bateria * 10) / 10 }
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
    if (!t) throw new MockHttpError('Torre não encontrada', 404)
    if (t.statusOperacional === 'EM_MANUTENCAO')
      throw new MockHttpError('Torre em manutenção — acionamento bloqueado', 409)

    torres = torres.map((x) => (x.id === id ? { ...x, modoIluminacao: modo, nivelBrilhoAtual: pwm } : x))
    return delay(clone(torres.find((x) => x.id === id)!))
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
  },
}
