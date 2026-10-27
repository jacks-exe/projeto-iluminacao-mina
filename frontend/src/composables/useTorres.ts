import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  OFFLINE_THRESHOLD,
  PINOS_POSTES,
  POLL_TORRES_MS,
  PWM_LIGAR_PADRAO,
  TOTAL_TORRES,
  calcularConsumoW,
  clampPwm,
  getModo,
  modoPorPwm,
} from '@/config/scada'
import { torresApi } from '@/services/api'
import { mockBackend } from '@/services/mockBackend'
import { setSimulacaoManual, simulacao } from '@/services/simulacao'
import type { ConnectionState, ModoIluminacao, Torre } from '@/types/scada'

/* --------------------------------------------------------------------------
   Estado compartilhado (singleton de módulo) — uma única fonte de verdade
   para todos os componentes do Dashboard (Passo 3: Pinos 8, 9, 10, 11 e 12).
   -------------------------------------------------------------------------- */
const torres = ref<Torre[]>([])
const connection = ref<ConnectionState>(simulacao.value ? 'online' : 'connecting')
const lastSync = ref<Date | null>(null)
const pending = ref<Record<number, boolean>>({})
const lastError = ref<string | null>(null)

const cerracaoGeralAtiva = ref(false)
const cascataEmAndamento = ref(false)
let snapshotPreCerracao: Record<number, { modo: ModoIluminacao; pwm: number }> = {}
const memoriaUltimoPwm: Record<number, number> = {}

let failures = 0
let timer: ReturnType<typeof setTimeout> | null = null
let subscribers = 0
let stopped = true

function sortByCodigo(list: Torre[]) {
  return [...list].sort((a, b) => (a.codigo ?? '').localeCompare(b.codigo ?? '', 'pt-BR', { numeric: true }))
}

/** Placeholders exibidos enquanto não há leitura do backend (Pinos PWM 2 a 6) */
const placeholders: Torre[] = Array.from({ length: TOTAL_TORRES }, (_, i) => ({
  id: -(i + 1),
  codigo: `TR-0${i + 1}`,
  pinoPwm: PINOS_POSTES[i] ?? 2 + i,
  modoIluminacao: 'DESLIGADA',
  nivelBrilhoAtual: 0,
  statusOperacional: 'OPERANDO',
  conectado: true,
  consumoWatts: 0,
  setor: null,
}))

async function poll() {
  if (timer) {
    clearTimeout(timer)
    timer = null
  }
  try {
    const data = await torresApi.listar()
    const incoming = Array.isArray(data) ? data : []

    // Não sobrescreve postes com comando ou efeito cascata em voo (mantém estado otimista)
    const local = new Map(torres.value.map((t) => [t.id, t]))
    torres.value = sortByCodigo(
      incoming.map((t, idx) => {
        const normalizada: Torre = {
          ...t,
          pinoPwm: t.pinoPwm >= 2 && t.pinoPwm <= 6 ? t.pinoPwm : (PINOS_POSTES[idx] ?? 2 + idx),
          conectado: t.conectado !== false,
          consumoWatts:
            typeof t.consumoWatts === 'number'
              ? t.consumoWatts
              : calcularConsumoW(
                  t.nivelBrilhoAtual,
                  t.statusOperacional !== 'EM_MANUTENCAO' && t.conectado !== false,
                ),
        }
        if (normalizada.nivelBrilhoAtual > 0) {
          memoriaUltimoPwm[normalizada.id] = normalizada.nivelBrilhoAtual
        }
        return (pending.value[t.id] || cascataEmAndamento.value) && local.has(t.id)
          ? local.get(t.id)!
          : normalizada
      }),
    )

    // Se estiver em modo cerração e alguma torre operante estiver abaixo de 255, sai de cerração
    const operantes = torres.value.filter((t) => t.id > 0 && t.statusOperacional !== 'EM_MANUTENCAO' && t.conectado !== false)
    if (cerracaoGeralAtiva.value && operantes.some((t) => (t.nivelBrilhoAtual ?? 0) < 255)) {
      cerracaoGeralAtiva.value = false
      snapshotPreCerracao = {}
    }

    failures = 0
    connection.value = 'online'
    lastSync.value = new Date()
  } catch {
    failures++
    if (failures >= OFFLINE_THRESHOLD) connection.value = 'offline'
  } finally {
    if (!stopped) timer = setTimeout(poll, POLL_TORRES_MS)
  }
}

function start() {
  if (!stopped) return
  stopped = false
  poll()
}

function stop() {
  stopped = true
  if (timer) clearTimeout(timer)
  timer = null
}

function patchLocal(id: number, patch: Partial<Torre>) {
  torres.value = torres.value.map((t) => {
    if (t.id !== id) return t
    const atualizado = { ...t, ...patch }
    const operante = atualizado.statusOperacional !== 'EM_MANUTENCAO' && atualizado.conectado !== false
    atualizado.consumoWatts = calcularConsumoW(atualizado.nivelBrilhoAtual, operante)
    return atualizado
  })
}

/**
 * Define diretamente a intensidade PWM (0..255) e o modo de um poste (Portas 8 a 12).
 */
async function setPwm(torre: Torre, novoPwm: number, modoForcado?: ModoIluminacao): Promise<boolean> {
  if (torre.id < 0 || (connection.value !== 'online' && !simulacao.value)) {
    lastError.value = 'Sem conexão com o controlador SCADA.'
    return false
  }
  if (torre.statusOperacional === 'EM_MANUTENCAO') {
    lastError.value = `${torre.codigo} (Porta D${torre.pinoPwm}) está em manutenção — acionamento bloqueado.`
    return false
  }
  if (torre.conectado === false) {
    lastError.value = `${torre.codigo} (Porta D${torre.pinoPwm}) está offline — verifique a conectividade do poste.`
    return false
  }

  const pwm = clampPwm(novoPwm)
  const modo = modoForcado ?? modoPorPwm(pwm)

  // Ao alterar individualmente qualquer torre durante o Modo Cerração Geral:
  // 1. Sai imediatamente do Modo Cerração Geral (desliga alerta visual no dashboard)
  // 2. Limpa o snapshot anterior para não sobrepor as torres
  // 3. Mantém a configuração das outras torres (permanecem intactas em 255 / ON)
  if (cerracaoGeralAtiva.value) {
    cerracaoGeralAtiva.value = false
    snapshotPreCerracao = {}
  }

  if (torre.nivelBrilhoAtual === pwm && torre.modoIluminacao === modo) return true

  if (pwm > 0) {
    memoriaUltimoPwm[torre.id] = pwm
  }

  const anterior = {
    modoIluminacao: torre.modoIluminacao,
    nivelBrilhoAtual: torre.nivelBrilhoAtual,
    consumoWatts: torre.consumoWatts,
  }

  pending.value = { ...pending.value, [torre.id]: true }
  patchLocal(torre.id, { modoIluminacao: modo, nivelBrilhoAtual: pwm })

  try {
    const atualizada = await torresApi.alterarModo(torre.id, modo, pwm)
    if (atualizada && typeof atualizada === 'object' && 'id' in atualizada) {
      patchLocal(torre.id, atualizada)
    }
    return true
  } catch (err) {
    patchLocal(torre.id, anterior)
    lastError.value = `Falha ao comandar ${torre.codigo} (D${torre.pinoPwm}): ${(err as Error).message}`
    return false
  } finally {
    const { [torre.id]: _, ...rest } = pending.value
    pending.value = rest
  }
}

/** Aplica um modo predefinido (OFF, ECO, NORM, CERR) em um poste. */
async function setModo(torre: Torre, modo: ModoIluminacao): Promise<boolean> {
  const pwm = getModo(modo).pwm
  return setPwm(torre, pwm, modo)
}

/** Alterna o acionamento (Liga/Desliga) individual do poste mantendo memória de brilho */
async function toggleAcionamento(torre: Torre): Promise<boolean> {
  const estaLigada =
    torre.statusOperacional !== 'EM_MANUTENCAO' &&
    torre.conectado !== false &&
    (torre.nivelBrilhoAtual ?? 0) > 0

  if (estaLigada) {
    return setPwm(torre, 0, 'DESLIGADA')
  }
  const brilhoRestaurado = memoriaUltimoPwm[torre.id] ?? PWM_LIGAR_PADRAO
  return setPwm(torre, brilhoRestaurado > 0 ? brilhoRestaurado : PWM_LIGAR_PADRAO)
}

/** Alterna o estado de conectividade (Online / Offline) de um poste específico */
async function toggleConectividade(torre: Torre): Promise<boolean> {
  if (torre.id < 0) return false
  if (cerracaoGeralAtiva.value) {
    cerracaoGeralAtiva.value = false
    snapshotPreCerracao = {}
  }
  const novoEstado = torre.conectado === false
  const anterior = torre.conectado

  pending.value = { ...pending.value, [torre.id]: true }
  patchLocal(torre.id, {
    conectado: novoEstado,
    ...(novoEstado ? {} : { nivelBrilhoAtual: 0, modoIluminacao: 'DESLIGADA' }),
  })

  try {
    const atualizada = await torresApi.alterarConectividade(torre.id, novoEstado)
    if (atualizada && typeof atualizada === 'object' && 'id' in atualizada) {
      patchLocal(torre.id, atualizada)
    }
    return true
  } catch (err) {
    patchLocal(torre.id, { conectado: anterior })
    lastError.value = `Erro ao atualizar conectividade de ${torre.codigo}: ${(err as Error).message}`
    return false
  } finally {
    const { [torre.id]: _, ...rest } = pending.value
    pending.value = rest
  }
}

/**
 * Efeito Cascata Progressivo (Portas Digitais 8 -> 9 -> 10 -> 11 -> 12):
 * Aciona ou desliga os 5 postes em sequência com intervalo visual de 180ms,
 * replicando no Digital Twin o mesmo efeito cascata gravado no Arduino Uno.
 */
async function acionarCascata(pwmAlvo?: number): Promise<void> {
  if (cascataEmAndamento.value) return
  const operantes = sortByCodigo(torres.value).filter(
    (t) => t.id > 0 && t.statusOperacional !== 'EM_MANUTENCAO' && t.conectado !== false,
  )
  if (!operantes.length) {
    lastError.value = 'Nenhum poste operante e online disponível para acionamento em cascata.'
    return
  }

  // Se todos já estão ligados e nenhum alvo foi forçado, alterna entre Normal (170), Cerração (255) ou Desligar (0)
  const todosLigados = operantes.every((t) => (t.nivelBrilhoAtual ?? 0) > 0)
  const alvoFinal =
    typeof pwmAlvo === 'number'
      ? clampPwm(pwmAlvo)
      : todosLigados
        ? 0
        : PWM_LIGAR_PADRAO

  const modoFinal = modoPorPwm(alvoFinal)
  cascataEmAndamento.value = true

  try {
    for (let i = 0; i < operantes.length; i++) {
      const poste = operantes[i]!
      pending.value = { ...pending.value, [poste.id]: true }
      patchLocal(poste.id, {
        nivelBrilhoAtual: alvoFinal,
        modoIluminacao: modoFinal,
      })
      if (alvoFinal > 0) {
        memoriaUltimoPwm[poste.id] = alvoFinal
      }
      await new Promise((r) => setTimeout(r, 180))
      const { [poste.id]: _, ...rest } = pending.value
      pending.value = rest
    }

    await torresApi.acionarCascata(alvoFinal)
  } catch (err) {
    lastError.value = `Falha na sincronização do efeito cascata: ${(err as Error).message}`
  } finally {
    cascataEmAndamento.value = false
  }
}

/**
 * Atualiza via Software os níveis virtuais de Bateria (%) e Luminosidade (LDR 0..1023),
 * substituindo o potenciômetro e o LDR físicos no painel Vue.js / MySQL.
 */
async function atualizarSensoresVirtuais(bateria: number, ldr: number, aplicarRegraAuto = true): Promise<void> {
  try {
    if (aplicarRegraAuto) {
      if (cerracaoGeralAtiva.value) {
        cerracaoGeralAtiva.value = false
        snapshotPreCerracao = {}
      }
      const batClamped = Math.max(0, Math.min(100, Math.round(bateria)))
      const ldrClamped = Math.max(0, Math.min(1023, Math.round(ldr)))
      const alvo = batClamped < 5 || ldrClamped >= 450 ? 0 : batClamped < 25 ? 70 : 170
      const modo = modoPorPwm(alvo)
      torres.value = torres.value.map((t) => {
        if (t.statusOperacional === 'EM_MANUTENCAO' || t.conectado === false) return t
        return {
          ...t,
          nivelBrilhoAtual: alvo,
          modoIluminacao: modo,
          consumoWatts: calcularConsumoW(alvo, true),
          bateria: batClamped,
          ldrAtual: ldrClamped,
        }
      })
    }
    const atualizadas = await torresApi.atualizarSensoresVirtuais(bateria, ldr, aplicarRegraAuto)
    if (Array.isArray(atualizadas) && atualizadas.length) {
      torres.value = sortByCodigo(atualizadas)
    }
  } catch (err) {
    lastError.value = `Falha ao aplicar sensores virtuais: ${(err as Error).message}`
  }
}

/** Botão mestre de contingência: todas as torres operantes -> CERRAÇÃO (100% / PWM 255). */
async function toggleCerracaoGeral() {
  const operantes = torres.value.filter(
    (t) => t.id > 0 && t.statusOperacional !== 'EM_MANUTENCAO' && t.conectado !== false,
  )

  if (!cerracaoGeralAtiva.value) {
    snapshotPreCerracao = Object.fromEntries(
      operantes.map((t) => [t.id, { modo: t.modoIluminacao, pwm: t.nivelBrilhoAtual }]),
    )
    cerracaoGeralAtiva.value = true
    await acionarCascata(255)
  } else {
    cerracaoGeralAtiva.value = false
    await Promise.all(
      operantes.map((t) => {
        const snap = snapshotPreCerracao[t.id]
        return setPwm(t, snap?.pwm ?? PWM_LIGAR_PADRAO, snap?.modo ?? 'NORMAL')
      }),
    )
    snapshotPreCerracao = {}
  }
}

/** Alterna o Modo Simulação manualmente e ressincroniza imediatamente. */
async function toggleSimulacao() {
  const proximo = !simulacao.value
  setSimulacaoManual(proximo)
  if (proximo) {
    connection.value = 'online'
  } else {
    connection.value = 'connecting'
    failures = 0
  }
  await poll()
}

async function resetSimulacao() {
  mockBackend.reset()
  cerracaoGeralAtiva.value = false
  snapshotPreCerracao = {}
  await poll()
}

export function useTorres() {
  onMounted(() => {
    subscribers++
    start()
  })
  onBeforeUnmount(() => {
    subscribers--
    if (subscribers <= 0) stop()
  })

  const lista = computed(() => (torres.value.length ? torres.value : placeholders))
  const isOnline = computed(() => connection.value === 'online' || simulacao.value)

  return {
    torres: lista,
    connection,
    isOnline,
    simulacao,
    lastSync,
    pending,
    lastError,
    cerracaoGeralAtiva,
    cascataEmAndamento,
    setModo,
    setPwm,
    toggleAcionamento,
    toggleConectividade,
    acionarCascata,
    atualizarSensoresVirtuais,
    toggleCerracaoGeral,
    toggleSimulacao,
    resetSimulacao,
    refresh: poll,
  }
}
