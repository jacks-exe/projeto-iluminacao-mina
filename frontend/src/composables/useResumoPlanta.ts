import { computed, toValue, type MaybeRefOrGetter } from 'vue'
import { POTENCIA_NOMINAL_W, PWM_MAX, getModo } from '@/config/scada'
import type { Torre } from '@/types/scada'

/**
 * KPIs derivados da telemetria das torres (somente leitura / derivação pura).
 * Mesma regra de cálculo usada anteriormente no cabeçalho (SLA, economia, demanda).
 */
export function useResumoPlanta(torres: MaybeRefOrGetter<Torre[]>) {
  return computed(() => {
    const lista = toValue(torres) ?? []
    const total = lista.length
    const conectadas = lista.filter((t) => t.conectado !== false).length
    const emManutencao = lista.filter((t) => t.statusOperacional === 'EM_MANUTENCAO').length
    const operantes = lista.filter(
      (t) => t.conectado !== false && t.statusOperacional !== 'EM_MANUTENCAO',
    ).length
    const acesos = lista.filter(
      (t) =>
        t.conectado !== false &&
        t.statusOperacional !== 'EM_MANUTENCAO' &&
        (t.nivelBrilhoAtual ?? 0) > 0,
    ).length

    const capacidadeW = Math.max(total, 1) * POTENCIA_NOMINAL_W
    const cargaW = lista.reduce((soma, t) => {
      if (t.conectado === false || t.statusOperacional === 'EM_MANUTENCAO') return soma
      const pwm = t.nivelBrilhoAtual ?? getModo(t.modoIluminacao).pwm
      return soma + (POTENCIA_NOMINAL_W * pwm) / PWM_MAX
    }, 0)

    return {
      total,
      conectadas,
      emManutencao,
      operantes,
      acesos,
      capacidadeW,
      cargaW: Math.round(cargaW),
      cargaPct: Math.round((cargaW / capacidadeW) * 100),
      slaPct: total ? Math.round((operantes / total) * 100) : 100,
      eficienciaPct: total ? Math.max(0, Math.round((1 - cargaW / capacidadeW) * 100)) : 100,
    }
  })
}
