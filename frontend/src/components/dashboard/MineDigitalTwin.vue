<script setup lang="ts">
import { defineAsyncComponent, ref } from 'vue'
// Carregamento sob demanda: mantém o three.js fora do bundle principal
const GeonexMaquete3D = defineAsyncComponent(() => import('@/components/dashboard/GeonexMaquete3D.vue'))
import { PINOS_POSTES } from '@/config/scada'
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

// Modo de visualização: 3D (Three.js, render sob demanda) ou 2D legado (imagens sobrepostas)
const modo = ref<'3d' | '2d'>('3d')

// Proporção real da maquete: o palco se ajusta ao espaço disponível sem cortar as torres das extremidades
const mapRatio = ref(16 / 9)
function onBaseLoad(e: Event) {
  const img = e.target as HTMLImageElement
  if (img.naturalWidth && img.naturalHeight) mapRatio.value = img.naturalWidth / img.naturalHeight
}

// Coordenadas calibradas e definitivas sobre a maquete
const coordenadasFixas: Record<number, { x: number; y: number }> = {
  1: { x: 52, y: 8 },
  2: { x: 31, y: 22 },
  3: { x: 70, y: 20 },
  4: { x: 19, y: 40 },
  5: { x: 76, y: 35 },
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
  <!-- Bento card do Gêmeo Digital: fundo sólido #13151E, centralizado e sem cortes secos -->
  <div class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-3xl border border-white/8 bg-[#13151E] p-2">
    <!-- Alternância 3D/2D -->
    <div class="absolute left-4 top-4 z-20 flex gap-1 rounded-full bg-black/50 p-1 text-xs font-semibold text-white"><button v-for="m in ['3d','2d'] as const" :key="m" type="button" class="rounded-full px-3 py-1" :class="modo === m ? 'bg-[#FF6B00]' : 'opacity-70'" @click="modo = m">{{ m.toUpperCase() }}</button></div>
    <div v-if="!online && modo === '3d'" class="offline-overlay"><span class="offline-text">SISTEMA OFFLINE</span></div>
    <div class="map-container">
      <GeonexMaquete3D v-if="modo === '3d'" class="absolute inset-0" :torres="torres" :selected-id="selectedId" @select-tower="(id) => emit('select-node', id)" />
      <div v-else class="map-stage" :style="{ '--ar': mapRatio }">
        <!-- 1. Imagem Base (Mina) integrada ao fundo #13151E com Radial Fade Mask Próprio (SEM mix-blend-screen) -->
        <img
          src="/assets/mina-base.png"
          alt="Maquete Base do Gêmeo Digital"
          @load="onBaseLoad"
          class="map-image base-layer w-full h-full object-contain transform origin-center scale-[1.4] transition-transform duration-300 mask-fade-base"
        />

        <!-- 2. Camada do Feixe Isolado da Torre com opacidade contínua PWM, Screen + Radial Fade Mask -->
        <img
          v-for="(torre, idx) in torres"
          :key="'beam-' + getNumericId(torre, idx)"
          v-show="isLightVisible(torre)"
          :src="`/assets/luz-torre-${getNumericId(torre, idx)}.png`"
          alt=""
          class="map-image light-layer w-full h-full object-contain transform origin-center scale-[1.4] transition-transform duration-300 mix-blend-screen mask-fade-edges"
          :style="{ opacity: getOpacity(torre) }"
        />

    <!-- 3. Marcadores Interativos sobre os Postes na Maquete -->
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
      <span class="pole-pin-badge">{{ torre.codigo || `TR-0${getNumericId(torre, idx)}` }} · D{{ getPinoDigital(torre, idx) }}</span>
    </div>

    <!-- Aviso de Conexão Offline (2D) -->
    <div v-if="!online && modo === '2d'" class="offline-overlay">
      <span class="offline-text inline-flex items-center gap-1.5"><svg class="size-4" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" /></svg>SISTEMA OFFLINE</span>
    </div>
    </div>
  </div>
  </div>
</template>

<style scoped>
.map-container {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  container-type: size;
  flex: 1 1 0%;
  width: 100%;
  height: 100%;
  min-height: 360px;
  background-color: #13151E;
  overflow: hidden;
}

/* Máscara radial preservando pelo menos 68% da área central (maquete 100% visível) e suavizando apenas os 32% finais */
.mask-fade-edges {
  -webkit-mask-image: radial-gradient(circle, rgba(0,0,0,1) 60%, rgba(0,0,0,0) 80%);
  mask-image: radial-gradient(circle, rgba(0,0,0,1) 60%, rgba(0,0,0,0) 80%);
  -webkit-mask-repeat: no-repeat;
  mask-repeat: no-repeat;
  -webkit-mask-size: 100% 100%;
  mask-size: 100% 100%;
}

.mask-fade-base {
  -webkit-mask-image: radial-gradient(circle, rgba(0,0,0,1) 70%, rgba(0,0,0,0) 90%);
  mask-image: radial-gradient(circle, rgba(0,0,0,1) 70%, rgba(0,0,0,0) 90%);
  -webkit-mask-repeat: no-repeat;
  mask-repeat: no-repeat;
  -webkit-mask-size: 100% 100%;
  mask-size: 100% 100%;
}

/* Palco com a proporção real da imagem: o que cabe no contêiner, sem corte lateral */
.map-stage {
  position: relative;
  flex: none;
  width: min(100cqw, calc(100cqh * var(--ar, 1.78)));
  aspect-ratio: var(--ar, 1.78);
}

.map-image {
  width: 100%;
  height: 100%;
  object-fit: contain;
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
  font-family: var(--font-mono), 'Consolas', monospace;
  font-size: 0.7rem;
  font-weight: bold;
  color: #FFF;
}

.pole-pin-badge {
  font-family: var(--font-mono), 'Consolas', monospace;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: #fbbf24;
  background: rgba(13, 16, 20, 0.92);
  border: 1px solid rgba(245, 158, 11, 0.45);
  border-radius: 9999px;
  padding: 1px 7px;
  line-height: 1.3;
  white-space: nowrap;
}

.offline-overlay {
  position: absolute;
  top: 10px;
  right: 10px;
  background-color: rgba(220, 38, 38, 0.9);
  padding: 4px 14px;
  border-radius: 9999px;
  z-index: 10;
}

.offline-text {
  color: white;
  font-weight: bold;
  font-size: 0.8rem;
}
</style>
