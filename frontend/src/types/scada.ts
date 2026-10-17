/** Espelha com.iluminacao.model.Torre / Setor (Spring Boot) */

export type ModoIluminacao = 'DESLIGADA' | 'ECONOMIA' | 'NORMAL' | 'CERRACAO'

export type StatusOperacional = 'OPERANDO' | 'EM_MANUTENCAO'

export interface Setor {
  id: number
  nome: string
  condicaoClimatica: 'NORMAL' | 'CERRACAO' | string
}

export interface Torre {
  id: number
  codigo: string // "TR-01"
  pinoPwm: number
  modoIluminacao: ModoIluminacao
  nivelBrilhoAtual: number // 0..255
  statusOperacional: StatusOperacional
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
