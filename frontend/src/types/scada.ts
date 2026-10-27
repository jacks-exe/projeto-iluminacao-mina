/** Espelha o contrato de Postes/Torres e Analytics do SCADA GEONEX (PHP mysqli / MySQL) */

export type ModoIluminacao = 'DESLIGADA' | 'ECONOMIA' | 'NORMAL' | 'CERRACAO'

export type StatusOperacional = 'OPERANDO' | 'EM_MANUTENCAO'

export interface Setor {
  id: number
  nome: string
  condicaoClimatica: 'NORMAL' | 'CERRACAO' | string
}

export interface Torre {
  id: number
  codigo: string // "TR-01" .. "TR-05"
  pinoPwm: number // Portas PWM sequenciais do Arduino Mega: 2, 3, 4, 5 e 6
  modoIluminacao: ModoIluminacao
  nivelBrilhoAtual: number // 0..255
  statusOperacional: StatusOperacional
  conectado?: boolean // Status de conectividade em tempo real do poste
  consumoWatts?: number // Consumo instantâneo em Watts (0..400W)
  bateria?: number // % de carga do nobreak (telemetria)
  ldrAtual?: number // Leitura do sensor LDR (0..1023)
  atualizadoEm?: string | null
  setor?: Setor | null
}

export type PrioridadeOS = 'BAIXA' | 'MEDIA' | 'ALTA' | 'CRITICA'

export interface OrdemManutencao {
  id: number
  torreId?: number | null
  torre?: Pick<Torre, 'id' | 'codigo'> | null
  descricao: string
  prioridade?: PrioridadeOS | string
  status?: string
  dataAbertura?: string
}

export interface NovaOrdemManutencao {
  torreId: number
  descricao: string
  prioridade: PrioridadeOS
}

export type ConnectionState = 'connecting' | 'online' | 'offline'

/* ==========================================================================
   Passo 4: Tipos de Histórico de Telemetria, Auditoria e Analytics
   ========================================================================== */
export type PeriodoAnalytics = '1H' | '6H' | '24H' | '7D' | '30D' | 'TODOS'

export interface KpisAnalytics {
  consumo_atual_w: number
  consumo_atual_kw: number
  consumo_medio_periodo_w: number
  pico_consumo_w: number
  capacidade_instalada_w: number
  energia_estimada_kwh: number
  energia_poupada_kwh: number
  eficiencia_energetica_pct: number
  disponibilidade_sla_pct: number
  postes_online: number
  postes_operando: number
  postes_acesos: number
  total_postes: number
  total_eventos_periodo: number
  acionamentos_cascata: number
  bateria_media_pct: number
  ldr_atual: number
}

export interface MetricaPosteAnalytics {
  torre_id: number
  codigo: string
  setor: string
  pino_pwm: number
  modo_atual: ModoIluminacao | string
  pwm_atual: number
  status_operacional: StatusOperacional | string
  conectado: boolean
  consumo_atual_w: number
  bateria_pct: number
  atualizado_em?: string | null
  total_eventos: number
  consumo_medio_w: number
  pico_pwm: number
  amostras_aceso_pct: number
}

export interface PontoSerieTemporal {
  horario: string
  timestamp: string
  consumo_total_w: number
  pwm_medio: number
  bateria_media: number
  economia_pct: number
  eventos: number
}

export interface LogAuditoriaTelemetria {
  id: number
  torre_id: number
  torre_codigo: string
  torre_setor: string
  pino_digital: number
  nivel_pwm: number
  modo_iluminacao: ModoIluminacao | string
  status_operacional: StatusOperacional | string
  conectado: boolean
  consumo_watts: number
  bateria_pct: number
  ldr_valor: number
  origem_evento: string
  registrado_em: string
}

export interface RespostaAnalytics {
  sucesso: boolean
  dispositivo: string
  pinos_pwm: number[]
  kpis: KpisAnalytics
  por_poste: MetricaPosteAnalytics[]
  serie_temporal: PontoSerieTemporal[]
  logs_auditoria: LogAuditoriaTelemetria[]
}
