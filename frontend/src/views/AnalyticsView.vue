<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import SlidingTabs from '@/components/ui/SlidingTabs.vue'
import { pwmToPercent } from '@/config/scada'
import { analyticsApi } from '@/services/api'
import type {
  LogAuditoriaTelemetria,
  PeriodoAnalytics,
  RespostaAnalytics,
  Torre,
} from '@/types/scada'

defineProps<{
  torres: Torre[]
}>()

const PERIODOS: { id: PeriodoAnalytics; label: string }[] = [
  { id: '1H', label: 'Última 1h' },
  { id: '6H', label: 'Últimas 6h' },
  { id: '24H', label: 'Últimas 24h' },
  { id: '7D', label: '7 Dias' },
  { id: 'TODOS', label: 'Todo Histórico' },
]

const ORIGENS = [
  { id: 'TODOS', label: 'Todas as Origens' },
  { id: 'DASHBOARD_WEB', label: 'Painel Web SCADA' },
  { id: 'CASCATA', label: 'Efeito Cascata (2→6)' },
  { id: 'ARDUINO_IOT', label: 'Arduino Mega IoT' },
  { id: 'SENSORES_SOFTWARE', label: 'Sensores Virtuais' },
  { id: 'CONTINGENCIA', label: 'Alerta Cerração' },
]

const periodoAtivo = ref<PeriodoAnalytics>('24H')
const filtroTorreId = ref<number>(0)
const filtroOrigem = ref<string>('TODOS')
const abaVisualizacao = ref<'GRAFICOS' | 'AUDITORIA'>('GRAFICOS')

const carregando = ref(false)
const dados = ref<RespostaAnalytics | null>(null)

async function carregarAnalytics() {
  carregando.value = true
  try {
    dados.value = await analyticsApi.consultar(
      periodoAtivo.value,
      filtroTorreId.value,
      filtroOrigem.value,
    )
  } finally {
    carregando.value = false
  }
}

/* Qualquer filtro (período, poste, origem) recarrega os dados; o gráfico reage a periodoAtivo */
watch([periodoAtivo, filtroTorreId, filtroOrigem], () => {
  pontoAtivoIdx.value = null
  carregarAnalytics()
})
onMounted(carregarAnalytics)

/* ---------- Dataset por período ---------- */
type PontoSerie = RespostaAnalytics['serie_temporal'][number]
const CAPACIDADE_W = 2000
const HORAS_PERIODO: Record<PeriodoAnalytics, number> = { '1H': 1, '6H': 6, '24H': 24, '7D': 168, '30D': 720, TODOS: 720 }
const CONFIG_SERIE: Record<PeriodoAnalytics, { n: number; passoMin: number }> = {
  '1H': { n: 12, passoMin: 5 },
  '6H': { n: 12, passoMin: 30 },
  '24H': { n: 24, passoMin: 60 },
  '7D': { n: 7, passoMin: 1440 },
  '30D': { n: 15, passoMin: 2880 },
  TODOS: { n: 12, passoMin: 43200 },
}

/** Pseudo-aleatório determinístico: o mesmo período sempre gera a mesma curva */
function ruido(i: number, seed: number) {
  const x = Math.sin(i * 12.9898 + seed * 78.233) * 43758.5453
  return x - Math.floor(x)
}

function gerarSerieMock(periodo: PeriodoAnalytics, consumoAgoraW: number): PontoSerie[] {
  const { n, passoMin } = CONFIG_SERIE[periodo]
  const agora = new Date()
  const seed = n + passoMin
  return Array.from({ length: n }, (_, k) => {
    const ultimo = k === n - 1
    const d = new Date(agora.getTime() - (n - 1 - k) * passoMin * 60_000)
    const h = d.getHours() + d.getMinutes() / 60
    const carga =
      passoMin >= 1440 ? 0.55 : h >= 18 || h < 6 ? 1 : h >= 17 || h < 7 ? 0.5 : 0.05
    const w = ultimo
      ? consumoAgoraW
      : Math.min(CAPACIDADE_W, CAPACIDADE_W * carga * (0.65 + 0.35 * ruido(k, seed)))
    const consumo = Math.round(w * 10) / 10
    const horario = ultimo
      ? 'Agora'
      : passoMin >= 43200
        ? d.toLocaleDateString('pt-BR', { month: 'short' })
        : passoMin >= 1440
          ? d.toLocaleDateString('pt-BR', { weekday: 'short' })
          : d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
    return {
      horario,
      consumo_total_w: consumo,
      pwm_medio: Math.round((consumo / CAPACIDADE_W) * 255),
      bateria_media: Math.round(100 - ruido(k, seed + 1) * 18),
      economia_pct: Math.round((1 - consumo / CAPACIDADE_W) * 1000) / 10,
      eventos: Math.round(2 + ruido(k, seed + 2) * 6),
      timestamp: d.toISOString(),
    }
  })
}

const serie = computed(() =>
  gerarSerieMock(periodoAtivo.value, dados.value?.kpis.consumo_atual_w ?? 0),
)

/* KPIs de período recalculados a partir da série ativa */
const kpis = computed(() => {
  const base = dados.value?.kpis
  if (!base) return undefined
  const valores = serie.value.map((p) => p.consumo_total_w)
  const media = Math.round((valores.reduce((s, v) => s + v, 0) / Math.max(1, valores.length)) * 10) / 10
  const horas = HORAS_PERIODO[periodoAtivo.value]
  const energia = Math.round(((media * horas) / 1000) * 100) / 100
  const maxKwh = (CAPACIDADE_W * horas) / 1000
  return {
    ...base,
    consumo_medio_periodo_w: media,
    pico_consumo_w: Math.max(...valores),
    energia_estimada_kwh: energia,
    energia_poupada_kwh: Math.max(0, Math.round((maxKwh - energia) * 100) / 100),
  }
})
const porPoste = computed(() => dados.value?.por_poste ?? [])
const logs = computed(() => dados.value?.logs_auditoria ?? [])

/* Geometria do Gráfico SVG de Série Temporal de Consumo (W) */
const SVG_W = 680
const SVG_H = 190
const PAD_X = 36
const PAD_Y = 20

const pontosGrafico = computed(() => {
  const pts = serie.value
  if (!pts.length) return []
  const maxW = Math.max(2000, ...pts.map((p) => p.consumo_total_w))
  const n = pts.length
  return pts.map((p, i) => {
    const x = n === 1 ? SVG_W / 2 : PAD_X + (i / (n - 1)) * (SVG_W - PAD_X * 2)
    const ratio = Math.max(0, Math.min(1, p.consumo_total_w / maxW))
    const y = SVG_H - PAD_Y - ratio * (SVG_H - PAD_Y * 2)
    return {
      idx: i,
      x: Math.round(x * 10) / 10,
      y: Math.round(y * 10) / 10,
      raw: p,
    }
  })
})

/* Estado do Tooltip Interativo ao passar o mouse sobre os pontos do gráfico */
const pontoAtivoIdx = ref<number | null>(null)
const pontoHover = computed(() => {
  if (pontoAtivoIdx.value === null) return null
  return pontosGrafico.value[pontoAtivoIdx.value] ?? null
})

const tooltipStyle = computed(() => {
  const pt = pontoHover.value
  if (!pt) return {}
  const pctX = Math.max(14, Math.min(86, (pt.x / SVG_W) * 100))
  const pctY = Math.max(8, (pt.y / SVG_H) * 100)
  return {
    left: `${pctX}%`,
    top: `${pctY}%`,
  }
})

function onSvgMouseMove(e: MouseEvent) {
  const svg = e.currentTarget as SVGSVGElement | null
  const pts = pontosGrafico.value
  if (!svg || !pts.length) return
  const rect = svg.getBoundingClientRect()
  if (!rect.width) return
  const relX = ((e.clientX - rect.left) / rect.width) * SVG_W
  let maisProximo = 0
  let menorDist = Infinity
  for (let i = 0; i < pts.length; i++) {
    const dist = Math.abs(pts[i]!.x - relX)
    if (dist < menorDist) {
      menorDist = dist
      maisProximo = i
    }
  }
  pontoAtivoIdx.value = maisProximo
}

const caminhoLinha = computed(() => {
  const pts = pontosGrafico.value
  if (!pts.length) return ''
  return pts.map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x} ${p.y}`).join(' ')
})

const caminhoArea = computed(() => {
  const pts = pontosGrafico.value
  if (!pts.length) return ''
  const primeiro = pts[0]!
  const ultimo = pts[pts.length - 1]!
  const base = SVG_H - PAD_Y
  return `${caminhoLinha.value} L ${ultimo.x} ${base} L ${primeiro.x} ${base} Z`
})

function formatarDataHora(iso?: string | null) {
  if (!iso) return '—'
  const d = new Date(iso.replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      })
}

function corModo(modo: string) {
  switch (String(modo).toUpperCase()) {
    case 'CERRACAO':
      return 'border-ember/50 bg-ember/15 text-ember'
    case 'NORMAL':
      return 'border-ore/50 bg-ore/15 text-ore'
    case 'ECONOMIA':
      return 'border-clay/50 bg-clay/15 text-clay'
    default:
      return 'border-line bg-panel-2 text-mute'
  }
}

function corOrigem(origem: string) {
  switch (String(origem).toUpperCase()) {
    case 'CASCATA':
      return 'border-ore/50 bg-ore/10 text-ore'
    case 'CONTINGENCIA':
      return 'border-ember/50 bg-ember/15 text-ember'
    case 'ARDUINO_IOT':
      return 'border-ok/40 bg-ok/10 text-ok'
    case 'SENSORES_SOFTWARE':
      return 'border-clay/40 bg-clay/10 text-clay'
    default:
      return 'border-line bg-panel-2 text-ink'
  }
}

function exportarCsv() {
  if (!logs.value.length) return
  const cabecalho = [
    'ID',
    'Data_Hora',
    'Poste',
    'Setor',
    'Pino_PWM_Mega',
    'Modo_Iluminacao',
    'PWM_0_255',
    'Intensidade_Pct',
    'Consumo_W',
    'Bateria_Pct',
    'LDR_0_1023',
    'Status_Operacional',
    'Conectado',
    'Origem_Evento',
  ]
  const linhas = logs.value.map((l: LogAuditoriaTelemetria) => [
    l.id,
    l.registrado_em,
    l.torre_codigo,
    `"${l.torre_setor}"`,
    `D${l.pino_digital}`,
    l.modo_iluminacao,
    l.nivel_pwm,
    `${pwmToPercent(l.nivel_pwm)}%`,
    l.consumo_watts,
    l.bateria_pct,
    l.ldr_valor,
    l.status_operacional,
    l.conectado ? 'ONLINE' : 'OFFLINE',
    l.origem_evento,
  ])

  const conteudo = [cabecalho.join(';'), ...linhas.map((r) => r.join(';'))].join('\n')
  const blob = new Blob(['\uFEFF' + conteudo], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `geonex_relatorio_telemetria_${periodoAtivo.value.toLowerCase()}.csv`
  link.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <section class="flex min-h-0 flex-1 flex-col p-4">
    <div class="flex min-h-0 w-full flex-1 flex-col overflow-hidden rounded-[20px] border border-white/5 bg-panel shadow-[0_8px_30px_rgb(0,0,0,0.25)]">
      <!-- Cabeçalho Analytics Passo 4 -->
      <header class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-line bg-panel-2/80 px-5 py-3.5">
        <div>
          <div class="flex items-center gap-2">
            <span class="size-2.5 rounded-full bg-ore shadow-[0_0_10px_#D97706]" />
            <h2 class="text-sm font-bold uppercase tracking-wider text-ink">
              Analytics de Consumo, Telemetria Avançada & Auditoria Operacional · Passo 4
            </h2>
          </div>
          <p class="mt-0.5 text-[11px] text-mute">
            Fluxo Integrado: Arduino Mega (Pinos PWM 2 a 6) → Ponte Python → PHP mysqli → MySQL → Vue.js 3
          </p>
        </div>

        <div class="flex items-center gap-2">
          <button
            type="button"
            class="rounded-xl border border-ore/50 bg-ore/15 px-3 py-1.5 text-[11px] font-semibold text-ore hover:bg-ore/25"
            :disabled="!logs.length"
            @click="exportarCsv"
          >
            Exportar CSV
          </button>
          <button
            type="button"
            class="rounded-xl border border-line bg-panel px-3 py-1.5 text-[11px] text-mute hover:border-clay hover:text-ink"
            :disabled="carregando"
            @click="carregarAnalytics"
          >
            {{ carregando ? 'Sincronizando...' : 'Atualizar' }}
          </button>
        </div>
      </header>

      <!-- Barra de Filtros de Período, Poste e Origem -->
      <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-b border-line bg-panel px-5 py-2.5">
        <!-- Abas Gráficos vs Tabela de Auditoria -->
        <SlidingTabs
          v-model="abaVisualizacao"
          aria-label="Visualização"
          container-class="rounded-xl bg-panel-2 p-1"
          button-class="rounded-lg px-3 py-1.5 text-xs font-semibold"
          pill-class="rounded-lg bg-ore"
          active-text-class="text-abyss"
          inactive-text-class="text-mute hover:text-ink"
          :items="[
            { id: 'GRAFICOS', label: 'Gráficos & Desempenho por Poste' },
            { id: 'AUDITORIA', label: `Relatório de Auditoria PWM (${logs.length})` },
          ]"
        />

        <!-- Filtros -->
        <div class="flex flex-wrap items-center gap-2">
          <!-- Período -->
          <SlidingTabs
            v-model="periodoAtivo"
            aria-label="Período"
            container-class="rounded-xl border border-line bg-abyss p-1"
            button-class="rounded-lg px-2.5 py-1 text-[10px] font-semibold"
            pill-class="rounded-lg bg-ore"
            active-text-class="text-abyss"
            inactive-text-class="text-mute hover:text-ink"
            :items="PERIODOS"
          />

          <!-- Filtro por Poste -->
          <select v-model.number="filtroTorreId" class="scada-input !w-40 !py-1 !text-[11px]">
            <option :value="0">Todos os Postes (D2–D6)</option>
            <option v-for="t in torres" :key="t.id" :value="t.id">
              {{ t.codigo }} (Pino D{{ t.pinoPwm }})
            </option>
          </select>

          <!-- Filtro por Origem -->
          <select v-model="filtroOrigem" class="scada-input !w-44 !py-1 !text-[11px]">
            <option v-for="o in ORIGENS" :key="o.id" :value="o.id">{{ o.label }}</option>
          </select>
        </div>
      </div>

      <!-- Corpo Flexível -->
      <div
        class="flex min-h-0 flex-1 flex-col gap-5 p-5"
        :class="abaVisualizacao === 'GRAFICOS' ? 'overflow-y-auto' : 'overflow-hidden'"
      >
        <!-- 1. LINHA DE KPIS DE DESEMPENHO GERAL DA MAQUETE -->
        <div v-if="kpis" class="grid shrink-0 grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          <div class="rounded-2xl border border-line bg-panel-2/70 p-3">
            <span class="scada-label !text-[9px]">Potência Atual</span>
            <div class="mt-1 font-mono text-xl font-bold text-ink">
              {{ kpis.consumo_atual_w }}<span class="ml-1 text-xs text-mute">W</span>
            </div>
            <div class="mt-0.5 text-[10px] text-mute">
              Pico: <strong class="text-clay">{{ kpis.pico_consumo_w }} W</strong>
            </div>
          </div>

          <div class="rounded-2xl border border-line bg-panel-2/70 p-3">
            <span class="scada-label !text-[9px]">Eficiência Energética</span>
            <div class="mt-1 font-mono text-xl font-bold text-ok">
              {{ kpis.eficiencia_energetica_pct }}%
            </div>
            <div class="mt-0.5 text-[10px] text-mute">
              Poupado: <strong class="text-ok">{{ kpis.energia_poupada_kwh }} kWh</strong>
            </div>
          </div>

          <div class="rounded-2xl border border-line bg-panel-2/70 p-3">
            <span class="scada-label !text-[9px]">Consumo no Período</span>
            <div class="mt-1 font-mono text-xl font-bold text-ore">
              {{ kpis.energia_estimada_kwh }}<span class="ml-1 text-xs text-mute">kWh</span>
            </div>
            <div class="mt-0.5 text-[10px] text-mute">
              Média: <strong class="text-ink">{{ kpis.consumo_medio_periodo_w }} W</strong>
            </div>
          </div>

          <div class="rounded-2xl border border-line bg-panel-2/70 p-3">
            <span class="scada-label !text-[9px]">Disponibilidade (SLA)</span>
            <div
              class="mt-1 font-mono text-xl font-bold"
              :class="kpis.disponibilidade_sla_pct >= 80 ? 'text-ok' : 'text-fault'"
            >
              {{ kpis.disponibilidade_sla_pct }}%
            </div>
            <div class="mt-0.5 text-[10px] text-mute">
              {{ kpis.postes_operando }}/{{ kpis.total_postes }} operantes · {{ kpis.postes_acesos }} acesos
            </div>
          </div>

          <div class="rounded-2xl border border-line bg-panel-2/70 p-3">
            <span class="scada-label !text-[9px]">Eventos Auditados</span>
            <div class="mt-1 font-mono text-xl font-bold text-ink">
              {{ kpis.total_eventos_periodo }}
            </div>
            <div class="mt-0.5 text-[10px] text-mute">
              Cascata/Cerração: <strong class="text-ore">{{ kpis.acionamentos_cascata }}</strong>
            </div>
          </div>

          <div class="rounded-2xl border border-line bg-panel-2/70 p-3">
            <span class="scada-label !text-[9px]">Bateria UPS & LDR</span>
            <div class="mt-1 font-mono text-xl font-bold text-clay">
              {{ kpis.bateria_media_pct }}%
            </div>
            <div class="mt-0.5 text-[10px] text-mute">
              LDR Virtual: <strong class="text-ink">{{ kpis.ldr_atual }}</strong>/1023
            </div>
          </div>
        </div>

        <!-- 2. ABA DE GRÁFICOS E MÉTRICAS POR POSTE (PINOS PWM 2 A 6) -->
        <div v-if="abaVisualizacao === 'GRAFICOS'" class="grid grid-cols-1 gap-4 lg:grid-cols-12">
          <!-- Gráfico de Série Temporal de Consumo (7 colunas) -->
          <div class="flex flex-col justify-between rounded-2xl border border-line bg-panel-2/50 p-4 lg:col-span-7">
            <div class="mb-2 flex items-center justify-between">
              <div>
                <h3 class="text-xs font-bold uppercase tracking-wider text-ink">
                  Curva Histórica de Consumo da Planta (Watts)
                </h3>
                <p class="text-[11px] text-mute">
                  Evolução da carga dos 5 postes (Capacidade máxima instalada: 2000 W)
                </p>
              </div>
              <span class="rounded-lg border border-ore/40 bg-ore/10 px-2 py-0.5 text-[10px] text-ore">
                Período: {{ periodoAtivo }}
              </span>
            </div>

            <div class="relative w-full rounded-lg border border-line/60 bg-abyss p-2">
              <svg
                :viewBox="`0 0 ${SVG_W} ${SVG_H}`"
                class="h-48 w-full overflow-visible"
                @mousemove="onSvgMouseMove"
                @mouseleave="pontoAtivoIdx = null"
              >
                <defs>
                  <linearGradient id="gradConsumo" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="#D97706" stop-opacity="0.45" />
                    <stop offset="100%" stop-color="#D97706" stop-opacity="0.0" />
                  </linearGradient>
                </defs>

                <!-- Linhas de grade horizontais -->
                <line :x1="PAD_X" :y1="PAD_Y" :x2="SVG_W - PAD_X" :y2="PAD_Y" stroke="#262B31" stroke-dasharray="3 3" />
                <line
                  :x1="PAD_X"
                  :y1="SVG_H / 2"
                  :x2="SVG_W - PAD_X"
                  :y2="SVG_H / 2"
                  stroke="#262B31"
                  stroke-dasharray="3 3"
                />
                <line
                  :x1="PAD_X"
                  :y1="SVG_H - PAD_Y"
                  :x2="SVG_W - PAD_X"
                  :y2="SVG_H - PAD_Y"
                  stroke="#2E353D"
                />

                <!-- Rótulos eixo Y -->
                <text x="4" :y="PAD_Y + 4" fill="#6B7280" font-size="9" font-family="monospace">2000W</text>
                <text x="4" :y="SVG_H / 2 + 3" fill="#6B7280" font-size="9" font-family="monospace">1000W</text>
                <text x="14" :y="SVG_H - PAD_Y + 3" fill="#6B7280" font-size="9" font-family="monospace">0W</text>

                <!-- Linha guia vertical no ponto sob o mouse -->
                <line
                  v-if="pontoHover"
                  :x1="pontoHover.x"
                  :y1="PAD_Y"
                  :x2="pontoHover.x"
                  :y2="SVG_H - PAD_Y"
                  stroke="#F59E0B"
                  stroke-width="1"
                  stroke-opacity="0.45"
                  stroke-dasharray="3 3"
                />

                <!-- Área e Linha -->
                <g :key="periodoAtivo">
                  <path v-if="caminhoArea" :d="caminhoArea" fill="url(#gradConsumo)" class="chart-draw" />
                  <path
                    v-if="caminhoLinha"
                    :d="caminhoLinha"
                    pathLength="1"
                    class="chart-line"
                    fill="none"
                    stroke="#F59E0B"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />

                  <!-- Pontos e Rótulos -->
                  <g v-for="(pt, idx) in pontosGrafico" :key="idx" class="chart-draw">
                    <circle
                      :cx="pt.x"
                      :cy="pt.y"
                      :r="pontoAtivoIdx === idx ? 6 : 4"
                      :fill="pontoAtivoIdx === idx ? '#F59E0B' : '#111315'"
                      stroke="#F59E0B"
                      stroke-width="2"
                      class="transition-all duration-150"
                    />
                    <!-- Área de captura ampliada para hover fácil em cada ponto -->
                    <circle
                      :cx="pt.x"
                      :cy="pt.y"
                      r="14"
                      fill="transparent"
                      class="cursor-pointer"
                      @mouseenter="pontoAtivoIdx = idx"
                      @focus="pontoAtivoIdx = idx"
                    />
                    <text
                      v-if="idx === 0 || idx === pontosGrafico.length - 1 || idx % Math.max(1, Math.floor(pontosGrafico.length / 5)) === 0"
                      :x="pt.x"
                      :y="SVG_H - 4"
                      text-anchor="middle"
                      fill="#9CA3AF"
                      font-size="9"
                      font-family="monospace"
                    >
                      {{ pt.raw.horario }}
                    </text>
                  </g>
                </g>
              </svg>

              <!-- Popup / Tooltip Dark Mode ao passar o mouse sobre os pontos do gráfico -->
              <Transition
                enter-active-class="transition duration-150 ease-out"
                enter-from-class="opacity-0 scale-95"
                leave-active-class="transition duration-100 ease-in"
                leave-to-class="opacity-0 scale-95"
              >
                <div
                  v-if="pontoHover"
                  :style="tooltipStyle"
                  class="pointer-events-none absolute z-30 w-56 -translate-x-1/2 -translate-y-full -mt-2.5 rounded-xl border border-ore/50 bg-panel/95 p-3 text-[11px] text-ink shadow-2xl shadow-black/80 backdrop-blur-md"
                >
                  <div class="mb-1.5 flex items-center justify-between border-b border-line pb-1">
                    <span class="font-mono font-bold text-ore">{{ pontoHover.raw.horario }}</span>
                    <span class="font-mono text-[10px] text-mute">{{ formatarDataHora(pontoHover.raw.timestamp) }}</span>
                  </div>
                  <div class="grid grid-cols-2 gap-x-2 gap-y-1 font-mono text-[10px]">
                    <span class="text-mute">Consumo:</span>
                    <strong class="text-right text-ink">{{ pontoHover.raw.consumo_total_w }} W</strong>
                    <span class="text-mute">PWM Médio:</span>
                    <strong class="text-right text-clay">
                      {{ pontoHover.raw.pwm_medio }}/255 ({{ pwmToPercent(pontoHover.raw.pwm_medio) }}%)
                    </strong>
                    <span class="text-mute">Bateria UPS:</span>
                    <strong class="text-right text-ok">{{ pontoHover.raw.bateria_media }}%</strong>
                    <span class="text-mute">Economia:</span>
                    <strong class="text-right text-ok">{{ pontoHover.raw.economia_pct }}%</strong>
                    <span class="text-mute">Eventos:</span>
                    <strong class="text-right text-ink">{{ pontoHover.raw.eventos }}</strong>
                  </div>
                </div>
              </Transition>
            </div>

            <!-- Resumo rápido sob o gráfico -->
            <div class="mt-3 grid grid-cols-3 gap-2 text-center font-mono text-[11px]">
              <div class="rounded-lg border border-line bg-abyss/60 py-1.5">
                <span class="block text-[9px] uppercase text-mute">Capacidade Nominal</span>
                <strong class="text-ink">2.00 kW (5x 400W)</strong>
              </div>
              <div class="rounded-lg border border-line bg-abyss/60 py-1.5">
                <span class="block text-[9px] uppercase text-mute">Demanda Média</span>
                <strong class="text-ore">{{ kpis?.consumo_medio_periodo_w ?? 0 }} W</strong>
              </div>
              <div class="rounded-lg border border-line bg-abyss/60 py-1.5">
                <span class="block text-[9px] uppercase text-mute">Redução de Carga</span>
                <strong class="text-ok">{{ kpis?.eficiencia_energetica_pct ?? 0 }}%</strong>
              </div>
            </div>
          </div>

          <!-- Desempenho Individual por Poste - Pinos PWM 2 a 6 (5 colunas) -->
          <div class="flex flex-col gap-2.5 rounded-2xl border border-line bg-panel-2/50 p-4 lg:col-span-5">
            <div>
              <h3 class="text-xs font-bold uppercase tracking-wider text-ink">
                Relatório de Desempenho por Poste (Pinos PWM 2 a 6)
              </h3>
              <p class="text-[11px] text-mute">
                Comparativo entre consumo instantâneo, média histórica e acionamentos
              </p>
            </div>

            <div class="flex flex-col gap-2.5">
              <div
                v-for="p in porPoste"
                :key="p.torre_id"
                class="rounded-xl border border-line bg-abyss/80 p-2.5"
              >
                <div class="flex items-center justify-between text-xs">
                  <div class="flex items-center gap-2">
                    <span
                      class="size-2 rounded-full"
                      :class="
                        !p.conectado || p.status_operacional === 'EM_MANUTENCAO'
                          ? 'bg-fault'
                          : p.pwm_atual > 0
                            ? 'bg-ok'
                            : 'bg-mute'
                      "
                    />
                    <span class="font-mono font-bold text-ink">{{ p.codigo }}</span>
                    <span class="rounded-lg border border-ore/40 bg-ore/10 px-1.5 py-px text-[10px] font-bold text-ore">
                      Pino PWM {{ p.pino_pwm }}
                    </span>
                    <span class="truncate text-[10px] text-mute">{{ p.setor }}</span>
                  </div>
                  <span class="font-mono text-[11px] font-semibold text-clay">
                    {{ p.consumo_atual_w }} W
                  </span>
                </div>

                <!-- Barra dupla: Consumo Atual vs Média -->
                <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-panel-2">
                  <div
                    class="h-full rounded-full bg-ore transition-all duration-500"
                    :style="{ width: `${Math.min(100, (p.consumo_atual_w / 400) * 100)}%` }"
                  />
                </div>

                <div class="mt-1.5 flex flex-wrap items-center justify-between text-[10px] text-mute">
                  <span>PWM: <strong class="text-ink">{{ p.pwm_atual }}/255</strong> (Pico {{ p.pico_pwm }})</span>
                  <span>Média: <strong class="text-ink">{{ p.consumo_medio_w }} W</strong></span>
                  <span>Eventos: <strong class="text-ore">{{ p.total_eventos }}</strong></span>
                  <span>Ativo: <strong class="text-ok">{{ p.amostras_aceso_pct }}%</strong></span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 3. TABELA DE AUDITORIA OPERACIONAL (Exibida exclusivamente na aba AUDITORIA e ocupando 100% da altura) -->
        <div
          v-else-if="abaVisualizacao === 'AUDITORIA'"
          class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border border-line bg-panel-2/40"
        >
          <div class="flex shrink-0 items-center justify-between border-b border-line bg-panel-2 px-4 py-2.5">
            <span class="scada-label">
              Log de Auditoria de Telemetria e Acionamentos PWM ({{ logs.length }} registros)
            </span>
            <span class="text-[10px] text-mute">
              Persistido em `mina_iot_db.telemetria_postes` (mysqli procedural)
            </span>
          </div>

          <div class="min-h-0 flex-1 overflow-y-auto">
            <table class="w-full border-collapse text-left text-xs">
              <thead class="sticky top-0 z-10 border-b border-line bg-panel text-[10px] uppercase text-mute">
                <tr>
                  <th class="px-3 py-2">#ID</th>
                  <th class="px-3 py-2">Horário</th>
                  <th class="px-3 py-2">Poste</th>
                  <th class="px-3 py-2">Pino Mega</th>
                  <th class="px-3 py-2">Modo</th>
                  <th class="px-3 py-2">PWM / Brilho</th>
                  <th class="px-3 py-2">Consumo</th>
                  <th class="px-3 py-2">Bateria / LDR</th>
                  <th class="px-3 py-2">Conectividade</th>
                  <th class="px-3 py-2">Origem</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-line font-mono text-[11px]">
                <tr
                  v-for="item in logs"
                  :key="item.id"
                  class="transition-colors hover:bg-panel-2/60"
                >
                  <td class="px-3 py-2 text-mute">#{{ item.id }}</td>
                  <td class="px-3 py-2 text-ink">{{ formatarDataHora(item.registrado_em) }}</td>
                  <td class="px-3 py-2 font-bold text-clay">{{ item.torre_codigo }}</td>
                  <td class="px-3 py-2">
                    <span class="rounded-lg border border-ore/40 bg-ore/10 px-1.5 py-0.5 text-[10px] text-ore">
                      D{{ item.pino_digital }}
                    </span>
                  </td>
                  <td class="px-3 py-2">
                    <span class="rounded-lg border px-1.5 py-0.5 text-[10px] font-semibold" :class="corModo(item.modo_iluminacao)">
                      {{ item.modo_iluminacao }}
                    </span>
                  </td>
                  <td class="px-3 py-2 text-ink">
                    {{ item.nivel_pwm }} <span class="text-mute">({{ pwmToPercent(item.nivel_pwm) }}%)</span>
                  </td>
                  <td class="px-3 py-2 text-ink">{{ item.consumo_watts }} W</td>
                  <td class="px-3 py-2 text-mute">
                    BAT {{ item.bateria_pct }}% · LDR {{ item.ldr_valor }}
                  </td>
                  <td class="px-3 py-2">
                    <span
                      class="rounded px-1.5 py-0.5 text-[10px] font-bold"
                      :class="
                        item.status_operacional === 'EM_MANUTENCAO'
                          ? 'bg-fault/15 text-fault'
                          : item.conectado
                            ? 'bg-ok/15 text-ok'
                            : 'bg-fault/15 text-fault'
                      "
                    >
                      {{
                        item.status_operacional === 'EM_MANUTENCAO'
                          ? 'MANUTENÇÃO'
                          : item.conectado
                            ? 'ONLINE'
                            : 'OFFLINE'
                      }}
                    </span>
                  </td>
                  <td class="px-3 py-2">
                    <span class="rounded-lg border px-1.5 py-0.5 text-[10px]" :class="corOrigem(item.origem_evento)">
                      {{ item.origem_evento }}
                    </span>
                  </td>
                </tr>
                <tr v-if="!logs.length && !carregando">
                  <td colspan="10" class="py-8 text-center text-mute">
                    Nenhum evento encontrado para os filtros selecionados.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
