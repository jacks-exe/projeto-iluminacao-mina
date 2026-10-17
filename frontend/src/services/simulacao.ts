import { ref, watch } from 'vue'

/* --------------------------------------------------------------------------
   Flag global do Modo Simulação (persistida no navegador).
   - simulacao:     dados vêm do backend simulado local (mockBackend.ts).
                    Ativo por padrão quando não há preferência salva para
                    permitir uso imediato com backend/Arduino desligados.
   - autoFallback:  entra em simulação automaticamente se a API real falhar.
                    Desligado quando o operador desativa a simulação manualmente.
   -------------------------------------------------------------------------- */
const KEY_SIM = 'scada.simulacao'
const KEY_AUTO = 'scada.simulacao.auto'

function read(key: string, fallback: boolean): boolean {
  try {
    const v = localStorage.getItem(key)
    return v === null ? fallback : v === '1'
  } catch {
    return fallback
  }
}

export const simulacao = ref(read(KEY_SIM, true))
export const autoFallback = ref(read(KEY_AUTO, true))

export function setSimulacaoManual(ativo: boolean) {
  simulacao.value = ativo
  // Se o operador desligar manualmente, não reativa no próximo erro de rede;
  // se religar, volta a permitir auto-fallback.
  autoFallback.value = ativo
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
