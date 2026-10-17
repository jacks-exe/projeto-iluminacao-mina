<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import TowerNode from './TowerNode.vue'
import {
  MODOS,
  PWM_MAX,
  TORRE_POSICOES,
  TWIN_ASSETS,
  getModo,
  numeroTorre,
} from '@/config/scada'
import type { Torre } from '@/types/scada'

const props = defineProps<{
  torres: Torre[]
  pending: Record<number, boolean>
  online: boolean
  selectedId?: number | null
}>()

const emit = defineEmits<{
  maintenance: [torre: Torre]
  selectNode: [id: number | null]
}>()

function brilho(t: Torre): number {
  if (t.statusOperacional === 'EM_MANUTENCAO') return 0
  const pwm = Number.isFinite(t.nivelBrilhoAtual) ? t.nivelBrilhoAtual : getModo(t.modoIluminacao).pwm
  return Math.max(0, Math.min(1, pwm / PWM_MAX))
}

const nos = computed(() =>
  props.torres.map((t, i) => {
    const n = numeroTorre(t.codigo, i + 1)
    const pos = TORRE_POSICOES[n] ?? { x: 15 + i * 17, y: 30 }
    return { torre: t, n, ...pos, opacity: brilho(t) }
  }),
)

/** Todas no máximo -> usa o render "full" pré-iluminado (mais fiel que somar camadas) */
const todasNoMaximo = computed(
  () => nos.value.length > 0 && nos.value.every((no) => no.opacity >= 1),
)

const ativas = computed(() => nos.value.filter((no) => no.opacity > 0).length)

function fechar() {
  emit('selectNode', null)
}
function onKey(e: KeyboardEvent) {
  if (e.key === 'Escape') fechar()
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <section
    class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-[10px] border border-line bg-abyss"
    style="container-type: size"
    @click="fechar"
  >
    <!-- Grade técnica de fundo -->
    <div
      class="pointer-events-none absolute inset-0 opacity-[0.07]"
      style="
        background-image: linear-gradient(#9ca3af 1px, transparent 1px),
          linear-gradient(90deg, #9ca3af 1px, transparent 1px);
        background-size: 40px 40px;
      "
    />

    <!-- Palco 16:9 que se ajusta ao contêiner (equivalente a object-contain) -->
    <div class="relative" style="aspect-ratio: 16 / 9; width: min(100cqw, calc(100cqh * 16 / 9))">
      <img
        :src="TWIN_ASSETS.base"
        alt="Maquete da mina — iluminação base"
        class="absolute inset-0 size-full select-none object-cover"
        draggable="false"
      />

      <!-- Camadas de luz por torre -->
      <img
        v-for="no in nos"
        :key="`layer-${no.n}`"
        :src="TWIN_ASSETS.layer(no.n)"
        alt=""
        aria-hidden="true"
        class="pointer-events-none absolute inset-0 size-full select-none object-cover mix-blend-screen transition-opacity duration-500"
        :style="{ opacity: todasNoMaximo ? 0 : no.opacity }"
        draggable="false"
      />

      <!-- Render com todas as luzes acesas -->
      <img
        :src="TWIN_ASSETS.full"
        alt=""
        aria-hidden="true"
        class="pointer-events-none absolute inset-0 size-full select-none object-cover transition-opacity duration-500"
        :class="todasNoMaximo ? 'opacity-100' : 'opacity-0'"
        draggable="false"
      />

      <!-- Vinheta: funde o fundo claro do render com o grafite da interface -->
      <div
        class="pointer-events-none absolute inset-0"
        style="
          background: radial-gradient(ellipse 62% 70% at 52% 52%, transparent 55%, rgb(11 14 20 / 0.85) 82%, #0b0e14 100%);
        "
      />

      <!-- Nós das torres -->
      <TowerNode
        v-for="no in nos"
        :key="`node-${no.torre.id}`"
        :torre="no.torre"
        :x="no.x"
        :y="no.y"
        :open="selectedId === no.torre.id"
        :pending="pending[no.torre.id]"
        @toggle="emit('selectNode', selectedId === no.torre.id ? null : no.torre.id)"
        @maintenance="(t) => { fechar(); emit('maintenance', t) }"
      />
    </div>

    <!-- HUD: cantoneiras de câmera -->
    <span class="pointer-events-none absolute left-3 top-3 size-5 border-l-2 border-t-2 border-ore/60" />
    <span class="pointer-events-none absolute right-3 top-3 size-5 border-r-2 border-t-2 border-ore/60" />
    <span class="pointer-events-none absolute bottom-3 left-3 size-5 border-b-2 border-l-2 border-ore/60" />
    <span class="pointer-events-none absolute bottom-3 right-3 size-5 border-b-2 border-r-2 border-ore/60" />

    <!-- HUD: título -->
    <div class="pointer-events-none absolute left-6 top-5">
      <p class="scada-label !text-clay">Digital Twin</p>
      <p class="text-sm font-semibold text-ink">Cava Principal · Maquete</p>
    </div>

    <!-- HUD: resumo -->
    <div class="pointer-events-none absolute right-6 top-5 text-right">
      <p class="scada-label">Torres ativas</p>
      <p class="font-mono text-xl font-semibold tabular-nums text-ink">
        {{ ativas }}<span class="text-mute">/{{ nos.length }}</span>
      </p>
    </div>

    <!-- HUD: legenda -->
    <div class="pointer-events-none absolute bottom-5 left-6 flex flex-wrap items-center gap-3">
      <span v-for="m in MODOS" :key="m.id" class="flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-wider text-mute">
        <span class="size-2 rounded-full" :style="{ backgroundColor: m.color }" />{{ m.sigla }}
      </span>
      <span class="flex items-center gap-1.5 text-[10px] font-medium uppercase tracking-wider text-mute">
        <span class="size-2 rounded-full bg-fault" />Manut.
      </span>
    </div>

    <!-- Overlay offline -->
    <div
      v-if="!online"
      class="pointer-events-none absolute bottom-5 right-6 rounded-md border border-fault/40 bg-fault/10 px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wider text-fault"
    >
      Telemetria indisponível · exibindo último estado
    </div>
  </section>
</template>
