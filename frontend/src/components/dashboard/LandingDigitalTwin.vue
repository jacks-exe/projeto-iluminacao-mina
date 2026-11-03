<script setup lang="ts">
import { ref } from 'vue'

interface PosteHitbox {
  id: number
  /** Coordenadas calibradas sobre o palco 3D (scale 1.4), idênticas ao MineDigitalTwin */
  x: number
  y: number
  /** Centro e raios elípticos (%) da penumbra radial do cone volumétrico sobre a maquete */
  falloffCenter: string
  falloffRadius: string
}

const postes: PosteHitbox[] = [
  { id: 1, x: 52, y: 8, falloffCenter: '52% 33%', falloffRadius: '28% 26%' },
  { id: 2, x: 31, y: 22, falloffCenter: '46% 40%', falloffRadius: '28% 26%' },
  { id: 3, x: 70, y: 20, falloffCenter: '55% 39%', falloffRadius: '28% 26%' },
  { id: 4, x: 19, y: 40, falloffCenter: '43% 44%', falloffRadius: '28% 26%' },
  { id: 5, x: 76, y: 35, falloffCenter: '57% 42%', falloffRadius: '28% 26%' },
]

/**
 * Níveis nativos de PWM (0..255) da maquete 3D:
 * - Idle (Sem Mouse): iluminação base nativa calibrada para manter a geometria 3D,
 *   o relevo da cava e todas as 5 torres (incluindo a torre 1 ao fundo) bem definidos.
 * - Hover (Com Mouse): modo cerração nativo (PWM 255 = feixe volumétrico máximo do render 3D).
 */
const PWM_IDLE = 92
const PWM_CERRACAO = 255

const hoveredId = ref<number | null>(null)

function getPwm(id: number): number {
  return hoveredId.value === id ? PWM_CERRACAO : PWM_IDLE
}

function getOpacity(id: number): number {
  const pwm = getPwm(id)
  return Number((pwm / 255).toFixed(2))
}
</script>

<template>
  <div class="landing-twin-container w-full h-full flex items-center justify-center bg-transparent select-none">
    <!-- Pipeline de transferência nativa do render 3D (calibração de exposição ambiente da malha 3D
         e restauração do canal alfa proporcional à luminância nativa das texturas volumétricas 3D) -->
    <svg aria-hidden="true" class="pointer-events-none absolute size-0 overflow-hidden">
      <defs>
        <filter id="geonex-3d-ambient-exposure" color-interpolation-filters="sRGB">
          <feComponentTransfer>
            <feFuncR type="linear" slope="1.38" intercept="0.01" />
            <feFuncG type="linear" slope="1.38" intercept="0.01" />
            <feFuncB type="linear" slope="1.38" intercept="0.01" />
          </feComponentTransfer>
        </filter>
        <filter id="geonex-3d-light-transfer" color-interpolation-filters="sRGB">
          <feColorMatrix
            type="matrix"
            values="1 0 0 0 0
                    0 1 0 0 0
                    0 0 1 0 0
                    0.75 0.75 0.75 -0.09 0"
          />
        </filter>
      </defs>
    </svg>

    <div class="landing-twin-stage">
      <!-- 1. Malha 3D Base da Mina (mesma camada base e máscara de borda do MineDigitalTwin) -->
      <img
        src="/assets/mina-base.png"
        alt="Gêmeo Digital 3D da Mina GEONEX"
        draggable="false"
        class="twin-image base-layer transform origin-center scale-[1.4] mask-fade-base"
      />

      <!-- 2. Iluminação Nativa 3D de cada Torre (exclusivamente as texturas de luz 3D do sistema com mix-blend-screen e decaimento radial contínuo) -->
      <img
        v-for="poste in postes"
        :key="'beam-' + poste.id"
        :src="`/assets/luz-torre-${poste.id}.png`"
        alt=""
        aria-hidden="true"
        draggable="false"
        class="twin-image light-layer transform origin-center scale-[1.4] mix-blend-screen mask-fade-edges"
        :style="{
          opacity: getOpacity(poste.id),
          '--falloff-center': poste.falloffCenter,
          '--falloff-radius': poste.falloffRadius,
        }"
      />

      <!-- 3. Áreas de Interação (Hitboxes 100% invisíveis sobre cada poste, sem tags ou elementos visuais) -->
      <div
        v-for="poste in postes"
        :key="'hitbox-' + poste.id"
        class="pole-hitbox"
        :style="{ left: `${poste.x}%`, top: `${poste.y}%` }"
        @mouseenter="hoveredId = poste.id"
        @mouseleave="hoveredId = null"
      />
    </div>
  </div>
</template>

<style scoped>
.landing-twin-container {
  position: relative;
  min-height: 360px;
  background: transparent;
  overflow: visible;
}

.landing-twin-stage {
  position: relative;
  width: 100%;
  aspect-ratio: 16 / 9;
  background: transparent;
  overflow: visible;
}

.twin-image {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
  pointer-events: none;
}

.base-layer {
  position: relative;
  z-index: 1;
  filter: url(#geonex-3d-ambient-exposure);
}

.light-layer {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  z-index: 2;
  pointer-events: none;
  filter: url(#geonex-3d-light-transfer);
  transition: opacity 0.28s ease-in-out;
}

/* Atenuação radial contínua (Radial Falloff / Penumbra) do cone volumétrico 3D:
   decaimento suave do núcleo da cava até 0% de opacidade sobre o piso da maquete,
   eliminando qualquer corte seco nas extremidades. */
.mask-fade-edges {
  -webkit-mask-image: radial-gradient(
    ellipse var(--falloff-radius, 28% 26%) at var(--falloff-center, 52% 42%),
    rgba(0, 0, 0, 1) 0%,
    rgba(0, 0, 0, 0.96) 50%,
    rgba(0, 0, 0, 0.72) 68%,
    rgba(0, 0, 0, 0.36) 82%,
    rgba(0, 0, 0, 0.1) 92%,
    rgba(0, 0, 0, 0) 100%
  );
  mask-image: radial-gradient(
    ellipse var(--falloff-radius, 28% 26%) at var(--falloff-center, 52% 42%),
    rgba(0, 0, 0, 1) 0%,
    rgba(0, 0, 0, 0.96) 50%,
    rgba(0, 0, 0, 0.72) 68%,
    rgba(0, 0, 0, 0.36) 82%,
    rgba(0, 0, 0, 0.1) 92%,
    rgba(0, 0, 0, 0) 100%
  );
  -webkit-mask-repeat: no-repeat;
  mask-repeat: no-repeat;
  -webkit-mask-size: 100% 100%;
  mask-size: 100% 100%;
}

/* Desvanecimento radial da camada base 3D preservando 100% da geometria das 5 torres
   (incluindo a torre superior ao fundo) e da base do terreno, esfumando apenas o fundo externo */
.mask-fade-base {
  -webkit-mask-image: radial-gradient(
    ellipse 44% 42% at 53% 49%,
    rgba(0, 0, 0, 1) 0%,
    rgba(0, 0, 0, 1) 74%,
    rgba(0, 0, 0, 0.6) 86%,
    rgba(0, 0, 0, 0.15) 94%,
    rgba(0, 0, 0, 0) 100%
  );
  mask-image: radial-gradient(
    ellipse 44% 42% at 53% 49%,
    rgba(0, 0, 0, 1) 0%,
    rgba(0, 0, 0, 1) 74%,
    rgba(0, 0, 0, 0.6) 86%,
    rgba(0, 0, 0, 0.15) 94%,
    rgba(0, 0, 0, 0) 100%
  );
  -webkit-mask-repeat: no-repeat;
  mask-repeat: no-repeat;
  -webkit-mask-size: 100% 100%;
  mask-size: 100% 100%;
}

/* Hitbox invisível posicionado sobre a estrutura de cada poste na maquete 3D */
.pole-hitbox {
  position: absolute;
  transform: translate(-50%, -25%);
  width: 13%;
  height: 32%;
  z-index: 4;
  cursor: pointer;
  background: transparent;
}
</style>
