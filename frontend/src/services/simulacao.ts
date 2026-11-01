import { ref, watch } from 'vue'

/* --------------------------------------------------------------------------
   Flag global do Modo Simulação (persistida no navegador).
   - simulacao:     false por padrão (Modo Real integrado ao Arduino/MySQL),
                    evitando falso positivo de conexão quando o cabo USB é desconectado.
   - autoFallback:  false por padrão para que quedas físicas do Arduino
                    mudem imediatamente o status para "Offline" em vez de mascarar.
   -------------------------------------------------------------------------- */
const KEY_SIM = 'scada.modo_simulacao_v2'
const KEY_AUTO = 'scada.modo_simulacao_auto_v2'

function read(key: string, fallback: boolean): boolean {
  try {
    const v = localStorage.getItem(key)
    return v === null ? fallback : v === '1'
  } catch {
    return fallback
  }
}

export const simulacao = ref(read(KEY_SIM, false))
export const autoFallback = ref(read(KEY_AUTO, false))

export function setSimulacaoManual(ativo: boolean) {
  simulacao.value = ativo
  autoFallback.value = false
}

watch(simulacao, (v) => {
  try {
    localStorage.setItem(KEY_SIM, v ? '1' : '0')
  } catch {
    /* ignore */
  }
})
watch(autoFallback, (v) => {
  try {
    localStorage.setItem(KEY_AUTO, v ? '1' : '0')
  } catch {
    /* ignore */
  }
})
