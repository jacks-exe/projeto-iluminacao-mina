import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  OFFLINE_THRESHOLD,
  POLL_TORRES_MS,
  TOTAL_TORRES,
  getModo,
} from '@/config/scada'
import { torresApi } from '@/services/api'
import type { ConnectionState, ModoIluminacao, Torre } from '@/types/scada'

/* --------------------------------------------------------------------------
   Estado compartilhado (singleton de módulo) — uma única fonte de verdade
   para todos os componentes do Dashboard.
   -------------------------------------------------------------------------- */
const torres = ref<Torre[]>([])
const connection = ref<ConnectionState>('connecting')
const lastSync = ref<Date | null>(null)
const pending = ref<Record<number, boolean>>({})
const lastError = ref<string | null>(null)

const cerracaoGeralAtiva = ref(false)
let snapshotPreCerracao: Record<number, ModoIluminacao> = {}

let failures = 0
let timer: ReturnType<typeof setTimeout> | null = null
let subscribers = 0
let stopped = true

function sortByCodigo(list: Torre[]) {
  return [...list].sort((a, b) => (a.codigo ?? '').localeCompare(b.codigo ?? '', 'pt-BR', { numeric: true }))
}

/** Placeholders exibidos enquanto não há leitura do backend */
const placeholders: Torre[] = Array.from({ length: TOTAL_TORRES }, (_, i) => ({
  id: -(i + 1),
  codigo: `TR-0${i + 1}`,
  pinoPwm: 0,
  modoIluminacao: 'DESLIGADA',
  nivelBrilhoAtual: 0,
  statusOperacional: 'OPERANDO',
  setor: null,
}))

async function poll() {
  try {
    const data = await torresApi.listar()
    const incoming = Array.isArray(data) ? data : []

    // Não sobrescreve torres com comando em voo (mantém estado otimista)
    const local = new Map(torres.value.map((t) => [t.id, t]))
    torres.value = sortByCodigo(
      incoming.map((t) => (pending.value[t.id] && local.has(t.id) ? local.get(t.id)! : t)),
    )

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
  torres.value = torres.value.map((t) => (t.id === id ? { ...t, ...patch } : t))
}

/** Aplica modo em uma torre. Retorna false se bloqueado/falhou. */
async function setModo(torre: Torre, modo: ModoIluminacao): Promise<boolean> {
  if (torre.id < 0 || connection.value !== 'online') {
    lastError.value = 'Sem conexão com o controlador.'
    return false
  }
  if (torre.statusOperacional === 'EM_MANUTENCAO') {
    lastError.value = `${torre.codigo} está em manutenção — acionamento bloqueado.`
    return false
  }
  if (torre.modoIluminacao === modo) return true

  const anterior = { modoIluminacao: torre.modoIluminacao, nivelBrilhoAtual: torre.nivelBrilhoAtual }
  const pwm = getModo(modo).pwm

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
    lastError.value = `Falha ao comandar ${torre.codigo}: ${(err as Error).message}`
    return false
  } finally {
    const { [torre.id]: _, ...rest } = pending.value
    pending.value = rest
  }
}

/** Botão mestre de contingência: todas as torres operantes -> CERRAÇÃO (100%). */
async function toggleCerracaoGeral() {
  const operantes = torres.value.filter((t) => t.id > 0 && t.statusOperacional !== 'EM_MANUTENCAO')

  if (!cerracaoGeralAtiva.value) {
    snapshotPreCerracao = Object.fromEntries(operantes.map((t) => [t.id, t.modoIluminacao]))
    cerracaoGeralAtiva.value = true
    await Promise.all(operantes.map((t) => setModo(t, 'CERRACAO')))
  } else {
    cerracaoGeralAtiva.value = false
    await Promise.all(
      operantes.map((t) => setModo(t, snapshotPreCerracao[t.id] ?? 'NORMAL')),
    )
    snapshotPreCerracao = {}
  }
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
  const isOnline = computed(() => connection.value === 'online')

  return {
    torres: lista,
    connection,
    isOnline,
    lastSync,
    pending,
    lastError,
    cerracaoGeralAtiva,
    setModo,
    toggleCerracaoGeral,
    refresh: poll,
  }
}
