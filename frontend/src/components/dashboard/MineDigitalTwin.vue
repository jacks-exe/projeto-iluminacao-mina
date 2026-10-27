<script setup lang="ts">
import { PINOS_POSTES, pwmToPercent } from '@/config/scada'
import type { Torre } from '@/types/scada'

const props = defineProps<{
  torres: Torre[]
  pending: Record<number, boolean> | boolean
  online: boolean
  selectedId: number | null
}>()

const emit = defineEmits<{
  (e: 'select-node', id: number): void
  (e: 'maintenance', torre: Torre): void
  (e: 'toggle-power', torre: Torre): void
}>()

// Coordenadas calibradas e definitivas sobre a maquete
const coordenadasFixas: Record<number, { x: number; y: number }> = {
  1: { x: 52, y: 6 },
  2: { x: 36, y: 12 },
  3: { x: 65, y: 12 },
  4: { x: 22, y: 40 },
  5: { x: 72, y: 40 },
}

function getNumericId(torre: any, index?: number): number {
  const raw = torre?.id ?? torre?.numero ?? torre?.codigo ?? (index !== undefined ? index + 1 : 1)
  const num = Number(String(raw).replace(/\D/g, ''))
  return num >= 1 && num <= 5 ? num : (index !== undefined ? index + 1 : 1)
}

function getPinoDigital(torre: any, index?: number): number {
  const p = Number(torre?.pinoPwm ?? torre?.pino_pwm ?? 0)
  if (p >= 2 && p <= 6) return p
  const id = getNumericId(torre, index)
  return PINOS_POSTES[id - 1] ?? 1 + id
}

function getPosicao(torre: any, index: number) {
  const id = getNumericId(torre, index)
  return coordenadasFixas[id] || { x: 50, y: 50 }
}

function isEmManutencao(torre: any): boolean {
  return String(torre?.statusOperacional ?? torre?.status_operacional ?? '').toUpperCase() === 'EM_MANUTENCAO'
}

function isConectado(torre: any): boolean {
  if (torre?.conectado === undefined || torre?.conectado === null) return true
  return Boolean(torre.conectado === true || Number(torre.conectado) === 1)
}

// Lê o PWM real da torre (0..255) considerando conectividade e manutenção
function getPwm(torre: any): number {
  if (!torre || typeof torre !== 'object') return 0
  if (isEmManutencao(torre) || !isConectado(torre)) return 0

  // 1. Checa propriedades numéricas diretas de PWM (incluindo nivelBrilhoAtual do slider)
  const pwmKeys = [
    'nivelBrilhoAtual',
    'nivel_brilho_atual',
    'pwm',
    'pwmAtual',
    'pwm_atual',
    'valorPwm',
    'duty',
    'dutyCycle',
  ]
  for (const k of pwmKeys) {
    if (k in torre && typeof torre[k] === 'number' && Number.isFinite(torre[k])) {
      return Math.max(0, Math.min(255, torre[k]))
    }
  }

  // 2. Checa propriedades de Modo ('OFF' | 'ECO' | 'NORM' | 'CERR')
  const modoKeys = ['modoIluminacao', 'modo_iluminacao', 'modo', 'modoAtual', 'mode', 'estado', 'status']
  for (const k of modoKeys) {
    if (k in torre && typeof torre[k] === 'string') {
      const m = torre[k].toUpperCase()
      if (m.includes('OFF') || m.includes('DESL')) return 0
      if (m.includes('ECO')) return 70
      if (m.includes('NORM')) return 170
      if (m.includes('CERR')) return 255
    }
  }

  return 0
}

function getOpacity(torre: Torre): number {
  const pwm = getPwm(torre)
  if (pwm <= 0) return 0
  return Number((pwm / 255).toFixed(2))
}

function isLightVisible(torre: Torre): boolean {
  return getPwm(torre) > 0
}
</script>

<template>
  <div class="map-container">
    <!-- 1. Imagem Base (Mina totalmente apagada) -->
    <img
      src="/assets/mina-base.png"
      alt="Maquete Base"
      class="map-image base-layer"
    />

    <!-- 2. Camada do Feixe Isolado da Torre (luz-fx-X.png) com opacidade contínua PWM -->
    <img
      v-for="(torre, idx) in torres"
      :key="'beam-' + getNumericId(torre, idx)"
      v-show="isLightVisible(torre)"
      :src="`/assets/luz-torre-${getNumericId(torre, idx)}.png`"
      alt=""
      class="map-image light-layer"
      :style="{ opacity: getOpacity(torre) }"
    />

    <!-- 3. HUD Superior Esquerdo: Status de Conectividade em Tempo Real (Pinos PWM 2 a 6) -->
    <div class="telemetry-hud">
      <div class="hud-title">
        <span class="hud-dot" :class="{ online: online }" />
        <span>ARDUINO MEGA · PINOS PWM 2–6</span>
      </div>
      <div class="hud-poles">
        <button
          v-for="(torre, idx) in torres"
          :key="'hud-' + getNumericId(torre, idx)"
          type="button"
          class="hud-pole-chip"
          :class="{
            selected: torre.id === selectedId,
            offline: !isConectado(torre),
            manut: isEmManutencao(torre),
            lit: isLightVisible(torre),
          }"
          :title="`${torre.codigo} (Porta D${getPinoDigital(torre, idx)}) — Clique para selecionar, duplo clique para Ligar/Desligar`"
          @click="emit('select-node', torre.id)"
          @dblclick.stop="emit('toggle-power', torre)"
        >
          <span class="chip-status-dot" />
          <span class="chip-code">{{ torre.codigo }}</span>
          <span class="chip-pin">D{{ getPinoDigital(torre, idx) }}</span>
          <span class="chip-pwm">
            {{
              !isConectado(torre)
                ? 'OFFLINE'
                : isEmManutencao(torre)
                  ? 'MANUT'
                  : `${pwmToPercent(getPwm(torre))}%`
            }}
          </span>
        </button>
      </div>
    </div>

    <!-- 4. Marcadores Interativos sobre os Postes na Maquete -->
    <div
      v-for="(torre, idx) in torres"
      :key="'marker-' + getNumericId(torre, idx)"
      class="pole-marker"
      :class="{ selected: torre.id === selectedId }"
      :style="{ left: `${getPosicao(torre, idx).x}%`, top: `${getPosicao(torre, idx).y}%` }"
      :title="`${torre.codigo} · Porta D${getPinoDigital(torre, idx)} · PWM ${getPwm(torre)}/255 (${isConectado(torre) ? 'Online' : 'Offline'})`"
      @click="emit('select-node', torre.id)"
      @dblclick="emit('maintenance', torre)"
    >
      <div
        class="pole-core"
        :class="{
          active: isLightVisible(torre),
          manut: isEmManutencao(torre),
          offline: !isConectado(torre),
        }"
      >
        <span class="pole-tag">{{ getNumericId(torre, idx) }}</span>
      </div>
      <span class="pole-pin-badge">D{{ getPinoDigital(torre, idx) }}</span>
    </div>

    <!-- Aviso de Conexão Offline -->
    <div v-if="!online" class="offline-overlay">
      <span class="offline-text">⚠️ SISTEMA OFFLINE</span>
    </div>
  </div>
</template>

<style scoped>
.map-container {
  position: relative;
  isolation: isolate;
  width: 100%;
  height: 100%;
  min-height: 400px;
  background-color: #111315;
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid #2E3338;
}

.map-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.base-layer {
  position: relative;
  z-index: 1;
}

.light-layer {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  z-index: 2;
  pointer-events: none;
  transition: opacity 0.18s ease-in-out;
  mix-blend-mode: screen;
}

.telemetry-hud {
  position: absolute;
  top: 10px;
  left: 10px;
  z-index: 6;
  display: flex;
  flex-direction: column;
  gap: 6px;
  background: rgba(13, 16, 20, 0.84);
  border: 1px solid rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(8px);
  border-radius: 8px;
  padding: 8px 10px;
}

.hud-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-family: monospace;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.08em;
  color: #9ca3af;
}

.hud-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background-color: #ef4444;
}

.hud-dot.online {
  background-color: #22c55e;
  box-shadow: 0 0 8px #22c55e;
}

.hud-poles {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}

.hud-pole-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 7px;
  border-radius: 5px;
  border: 1px solid #2e3338;
  background: rgba(24, 28, 34, 0.9);
  font-family: monospace;
  font-size: 10px;
  color: #d1d5db;
  cursor: pointer;
  transition: all 0.15s ease;
}

.hud-pole-chip:hover {
  border-color: #d97706;
}

.hud-pole-chip.selected {
  border-color: #f59e0b;
  background: rgba(217, 119, 6, 0.18);
}

.chip-status-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background-color: #22c55e;
}

.hud-pole-chip.offline .chip-status-dot,
.hud-pole-chip.manut .chip-status-dot {
  background-color: #ef4444;
}

.chip-code {
  font-weight: 700;
  color: #f3f4f6;
}

.chip-pin {
  color: #f59e0b;
  font-weight: 600;
}

.chip-pwm {
  color: #9ca3af;
}

.hud-pole-chip.lit .chip-pwm {
  color: #fbbf24;
  font-weight: 700;
}

.hud-pole-chip.offline .chip-pwm,
.hud-pole-chip.manut .chip-pwm {
  color: #ef4444;
  font-weight: 700;
}

.pole-marker {
  position: absolute;
  transform: translate(-50%, -50%);
  z-index: 4;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.pole-core {
  position: relative;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background-color: #1A1D20;
  border: 2px solid #555;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
}

.pole-core.active {
  background-color: #FF6B00;
  border-color: #FFF;
  box-shadow: 0 0 12px #FF6B00;
}

.pole-core.manut,
.pole-core.offline {
  background-color: #991B1B;
  border-color: #FCA5A5;
  box-shadow: 0 0 10px rgba(239, 68, 68, 0.7);
}

.pole-marker.selected .pole-core {
  outline: 3px solid rgba(255, 107, 0, 0.6);
  outline-offset: 3px;
  transform: scale(1.15);
}

.pole-tag {
  font-size: 0.7rem;
  font-weight: bold;
  color: #FFF;
}

.pole-pin-badge {
  font-family: monospace;
  font-size: 9px;
  font-weight: 700;
  color: #fbbf24;
  background: rgba(13, 16, 20, 0.88);
  border: 1px solid rgba(245, 158, 11, 0.45);
  border-radius: 3px;
  padding: 0 4px;
  line-height: 1.3;
}

.offline-overlay {
  position: absolute;
  top: 10px;
  right: 10px;
  background-color: rgba(220, 38, 38, 0.9);
  padding: 4px 12px;
  border-radius: 4px;
  z-index: 10;
}

.offline-text {
  color: white;
  font-weight: bold;
  font-size: 0.8rem;
}
</style>
