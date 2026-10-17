import type { ModoIluminacao } from '@/types/scada'

/* ==========================================================================
   Modos PWM — fonte única da regra de negócio no frontend
   ========================================================================== */
export interface ModoConfig {
  id: ModoIluminacao
  sigla: 'OFF' | 'ECO' | 'NORM' | 'CERR'
  label: string
  pwm: number
  /** Cor (hex) usada em glow/inline styles */
  color: string
}

export const MODOS: readonly ModoConfig[] = [
  { id: 'DESLIGADA', sigla: 'OFF', label: 'Desligada', pwm: 0, color: '#4B5563' },
  { id: 'ECONOMIA', sigla: 'ECO', label: 'Economia', pwm: 70, color: '#C68B59' },
  { id: 'NORMAL', sigla: 'NORM', label: 'Operação Normal', pwm: 170, color: '#D97706' },
  { id: 'CERRACAO', sigla: 'CERR', label: 'Cerração / Alta Visib.', pwm: 255, color: '#EA580C' },
] as const

const FALLBACK_MODO = MODOS[0]!

export function getModo(id: string | null | undefined): ModoConfig {
  return MODOS.find((m) => m.id === id) ?? FALLBACK_MODO
}

export const PWM_MAX = 255

export function pwmToPercent(pwm: number): number {
  return Math.round((Math.max(0, Math.min(PWM_MAX, pwm)) / PWM_MAX) * 100)
}

/* ==========================================================================
   Digital Twin — assets e posição dos nós sobre a maquete
   Os assets atuais são .png; troque a extensão aqui ao migrar para .webp.
   ========================================================================== */
const EXT = 'png'

export const TWIN_ASSETS = {
  base: `/assets/mina-base.${EXT}`,
  full: `/assets/mina-full-ligada.${EXT}`,
  layer: (n: number) => `/assets/luz-torre-${n}.${EXT}`,
}

/** Proporção nativa dos renders (1920x1080) */
export const TWIN_ASPECT = '16 / 9'

/**
 * Posição (%) do cabeçote de cada torre no render 1920x1080.
 * Mapeado a partir do pico de luminância de cada luz-torre-N.png.
 */
export const TORRE_POSICOES: Record<number, { x: number; y: number }> = {
  1: { x: 63.2, y: 21.5 },
  2: { x: 54.0, y: 15.5 },
  3: { x: 37.0, y: 24.0 },
  4: { x: 24.2, y: 35.5 },
  5: { x: 70.4, y: 31.0 },
}

/** Extrai o número da torre a partir do código ("TR-03" -> 3) */
export function numeroTorre(codigo: string | undefined, fallback: number): number {
  const n = Number.parseInt((codigo ?? '').replace(/\D/g, ''), 10)
  return Number.isFinite(n) && n > 0 ? n : fallback
}

/* ==========================================================================
   Métricas de consumo (estimativa) — potência nominal por torre a 100%
   ========================================================================== */
export const POTENCIA_NOMINAL_W = 400
export const TOTAL_TORRES = 5

/* ==========================================================================
   Telemetria
   ========================================================================== */
export const POLL_TORRES_MS = 2000
export const POLL_MANUTENCAO_MS = 10000
export const REQUEST_TIMEOUT_MS = 1800
/** Falhas consecutivas antes de declarar "Offline" (evita flicker) */
export const OFFLINE_THRESHOLD = 2
