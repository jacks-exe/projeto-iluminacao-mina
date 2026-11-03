<script setup lang="ts">
/**
 * GeonexMaquete3D — maquete 3D da mina (Three.js) com renderização SOB DEMANDA.
 *
 * Convenção de nomes no GLB:
 *   Base: "Maquete Mina MDF" | Postes: "Poste1".."Poste5"
 *   Lâmpadas: "Luz_Poste_01".."Luz_Poste_05" | Materiais: "Mat_Luz_01".."Mat_Luz_05"
 * O id emitido em `select-tower` é o número N do poste (1..5), igual a Torre.id.
 */
import { onMounted, onUnmounted, ref, watch } from 'vue'
import {
  ACESFilmicToneMapping,
  AmbientLight,
  Box3,
  Color,
  DirectionalLight,
  HemisphereLight,
  Material,
  Mesh,
  MeshStandardMaterial,
  Object3D,
  PerspectiveCamera,
  Raycaster,
  Scene,
  SRGBColorSpace,
  Texture,
  Vector2,
  Vector3,
  WebGLRenderer,
} from 'three'
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js'
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js'
import type { Torre } from '@/types/scada'

const props = withDefaults(
  defineProps<{
    torres: Torre[]
    selectedId?: number | null
    modelUrl?: string
  }>(),
  { selectedId: null, modelUrl: '/models/maquete_geonex.glb' },
)

const emit = defineEmits<{ (e: 'select-tower', id: number): void }>()

const containerRef = ref<HTMLDivElement | null>(null)
const loading = ref(true)
const errorMsg = ref<string | null>(null)

const TOWER_COUNT = 5
const LAMP_SCALE = 2.2
const MAX_PIXEL_RATIO = 1.5 // Critério 3: limita custo de fragmentos em telas HiDPI
const CLICK_MOVE_TOLERANCE_PX = 5 // diferencia clique de arraste da órbita
const COLOR_ON = new Color('#ffd27a')
const COLOR_MAINT = new Color('#ff8a00')
const COLOR_OFFLINE = new Color('#3a3a3a')
const COLOR_SELECTED = new Color('#38bdf8')

// Objetos Three.js ficam FORA de ref()/reactive: o proxy do Vue causaria overhead e bugs.
let renderer: WebGLRenderer | null = null
let scene: Scene | null = null
let camera: PerspectiveCamera | null = null
let controls: OrbitControls | null = null
let resizeObserver: ResizeObserver | null = null
let rafId = 0
let disposed = false

const raycaster = new Raycaster()
const pointer = new Vector2()
const lampMaterials = new Map<number, MeshStandardMaterial>() // id -> material exclusivo
const pickables: Object3D[] = [] // somente postes e lâmpadas entram no raycast
// Badges HTML: topo de cada poste (3D) projetado para 2D a cada render
const badgeEls: Record<number, HTMLElement | null> = {}
const badgeAnchors = new Map<number, Vector3>()
const projected = new Vector3()
function setBadgeEl(id: number, el: unknown): void {
  badgeEls[id] = el as HTMLElement | null
}
function badgeLabel(id: number): string {
  return props.torres.find((t) => t.id === id)?.codigo ?? `TR-0${id}`
}
function updateBadges(): void {
  const el = containerRef.value
  if (!el || !camera) return
  const w = el.clientWidth
  const h = el.clientHeight
  for (const [id, anchor] of badgeAnchors) {
    const badge = badgeEls[id]
    if (!badge) continue
    projected.copy(anchor).project(camera)
    badge.style.visibility = projected.z < 1 ? 'visible' : 'hidden' // atrás da câmera: oculta
    badge.style.transform = `translate(-50%, -100%) translate(${((projected.x + 1) / 2) * w}px, ${((1 - projected.y) / 2) * h}px)`
  }
}

let downX = 0
let downY = 0

/* ------------------------------------------------------------------ */
/* Render sob demanda (Critério 1)                                     */
/* ------------------------------------------------------------------ */

/** Agenda no máximo UM frame; múltiplos gatilhos no mesmo tick são coalescidos. Não há loop contínuo. */
function requestRender(): void {
  if (rafId || disposed) return
  rafId = requestAnimationFrame(() => {
    rafId = 0
    if (renderer && scene && camera) {
      renderer.render(scene, camera)
      updateBadges() // badges acompanham a câmera, mas só são reposicionados quando há render
    }
  })
}

function resize(): void {
  const el = containerRef.value
  if (!el || !renderer || !camera) return
  const w = Math.max(el.clientWidth, 1)
  const h = Math.max(el.clientHeight, 1)
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, MAX_PIXEL_RATIO))
  renderer.setSize(w, h, false)
  camera.aspect = w / h
  camera.updateProjectionMatrix()
  requestRender()
}

/* ------------------------------------------------------------------ */
/* Estado das lâmpadas a partir das props                              */
/* ------------------------------------------------------------------ */

function applyStatus(): void {
  for (const [id, mat] of lampMaterials) {
    const torre = props.torres.find((t) => t.id === id)
    const emManutencao = torre?.emManutencao ?? torre?.em_manutencao ?? torre?.statusOperacional === 'EM_MANUTENCAO'
    const offline = !torre || torre.conectado === false
    const nivel = Math.min(Math.max((torre?.nivelBrilhoAtual ?? 0) / 255, 0), 1)

    if (props.selectedId === id) {
      mat.emissive.copy(COLOR_SELECTED)
      mat.emissiveIntensity = 2.5
    } else if (offline) {
      mat.emissive.copy(COLOR_OFFLINE)
      mat.emissiveIntensity = 0.1
    } else if (emManutencao) {
      mat.emissive.copy(COLOR_MAINT)
      mat.emissiveIntensity = 1.5
    } else {
      mat.emissive.copy(COLOR_ON)
      mat.emissiveIntensity = nivel > 0 ? 1.5 + nivel : 0 // aceso: 1.5..2.5, contrasta com o poste escuro
    }
  }
  requestRender()
}

// Critério 1: mutação das props de status dispara um único render.
watch(() => props.torres, applyStatus, { deep: true })
watch(() => props.selectedId, applyStatus)

/* ------------------------------------------------------------------ */
/* Raycasting (Critério 4)                                             */
/* ------------------------------------------------------------------ */

/** Sobe a hierarquia até achar "PosteN" ou "Luz_Poste_0N" e devolve N. */
function resolveTowerId(obj: Object3D | null): number | null {
  for (let o = obj; o; o = o.parent) {
    const m = /^(?:Poste|Luz_Poste_0?)(\d)$/.exec(o.name)
    if (m) {
      const id = Number(m[1])
      if (id >= 1 && id <= TOWER_COUNT) return id
    }
  }
  return null
}

function onPointerDown(e: PointerEvent): void {
  downX = e.clientX
  downY = e.clientY
}

function onPointerUp(e: PointerEvent): void {
  // Ignora arrastes (órbita/pan): só cliques "parados" selecionam.
  if (Math.hypot(e.clientX - downX, e.clientY - downY) > CLICK_MOVE_TOLERANCE_PX) return
  if (!renderer || !camera) return

  const rect = renderer.domElement.getBoundingClientRect()
  pointer.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1)
  raycaster.setFromCamera(pointer, camera)
  // Apenas 10 alvos (sem a base MDF), recursive=true para submeshes; custo desprezível e sem travar a UI.
  const hit = raycaster.intersectObjects(pickables, true)[0]
  const id = hit ? resolveTowerId(hit.object) : null
  if (id !== null) emit('select-tower', id)
}

/* ------------------------------------------------------------------ */
/* Ciclo de vida                                                       */
/* ------------------------------------------------------------------ */

function onContextLost(e: Event): void {
  e.preventDefault() // permite restauração
}
function onContextRestored(): void {
  requestRender()
}

onMounted(() => {
  const el = containerRef.value
  if (!el) return

  renderer = new WebGLRenderer({ antialias: true, powerPreference: 'low-power' })
  renderer.outputColorSpace = SRGBColorSpace
  renderer.toneMapping = ACESFilmicToneMapping
  renderer.shadowMap.enabled = false // sombras em tempo real são caras e desnecessárias aqui
  el.appendChild(renderer.domElement)
  renderer.domElement.addEventListener('pointerdown', onPointerDown)
  renderer.domElement.addEventListener('pointerup', onPointerUp)
  renderer.domElement.addEventListener('webglcontextlost', onContextLost)
  renderer.domElement.addEventListener('webglcontextrestored', onContextRestored)

  scene = new Scene()
  camera = new PerspectiveCamera(45, 1, 0.1, 1000)
  camera.position.set(6, 5, 8)

  scene.add(new AmbientLight(0xffffff, 1.0))
  // Luz hemisférica: clareia as faces voltadas para cima (MDF/curvas de nível) sem custo de sombras
  scene.add(new HemisphereLight(0xffffff, 0x333333, 1.3))
  const sun = new DirectionalLight(0xffffff, 1.4)
  sun.position.set(10, 20, 10) // diagonal de cima: relevo nos degraus/chanfros sem sombras dinâmicas
  scene.add(sun)

  controls = new OrbitControls(camera, renderer.domElement)
  // Damping exigiria loop contínuo de frames; desligado para manter render 100% sob demanda.
  controls.enableDamping = false
  controls.maxPolarAngle = Math.PI / 2.05
  controls.addEventListener('change', requestRender)

  resizeObserver = new ResizeObserver(resize)
  resizeObserver.observe(el)
  resize()

  new GLTFLoader().load(
    props.modelUrl,
    (gltf) => {
      if (disposed || !scene || !camera || !controls) {
        disposeObject(gltf.scene)
        return
      }
      scene.add(gltf.scene)

      gltf.scene.traverse((obj) => {
        if (/^Poste[1-5]$/.test(obj.name)) pickables.push(obj)

        const lamp = /^Luz_Poste_0([1-5])$/.exec(obj.name)
        if (lamp && (obj as Mesh).isMesh) {
          pickables.push(obj)
          obj.scale.multiplyScalar(LAMP_SCALE) // esferas maiores para destaque no enquadramento padrão
          const mesh = obj as Mesh
          // Clona o material para que cada lâmpada seja controlada de forma independente.
          const base = (Array.isArray(mesh.material) ? mesh.material[0] : mesh.material) as MeshStandardMaterial
          const mat = base.clone()
          mat.name = base.name || `Mat_Luz_0${lamp[1]}`
          mesh.material = mat
          lampMaterials.set(Number(lamp[1]), mat)
        }
      })

      // Âncora dos badges: centro do topo de cada poste, com margem acima da lâmpada
      for (let n = 1; n <= TOWER_COUNT; n++) {
        const poste = gltf.scene.getObjectByName(`Poste${n}`)
        if (!poste) continue
        const b = new Box3().setFromObject(poste)
        const c = b.getCenter(new Vector3())
        badgeAnchors.set(n, new Vector3(c.x, b.max.y + (b.max.y - b.min.y) * 0.13, c.z))
      }

      // Enquadra a câmera no modelo carregado.
      const base = gltf.scene.getObjectByName('Maquete Mina MDF') ?? gltf.scene
      const box = new Box3().setFromObject(base)
      const center = box.getCenter(new Vector3())
      const size = box.getSize(new Vector3()).length() || 10
      controls.target.copy(center)
      camera.position.copy(center).add(new Vector3(size * 0.42, size * 0.36, size * 0.5))
      camera.far = size * 20
      camera.updateProjectionMatrix()
      controls.update() // dispara 'change' -> requestRender

      loading.value = false
      applyStatus()
    },
    undefined,
    (err) => {
      console.error('[GeonexMaquete3D] Falha ao carregar GLB', err)
      errorMsg.value = 'Não foi possível carregar a maquete 3D.'
      loading.value = false
    },
  )
})

/** Libera geometrias, materiais e texturas de uma árvore de objetos. */
function disposeObject(root: Object3D): void {
  root.traverse((obj) => {
    const mesh = obj as Mesh
    if (!mesh.isMesh) return
    mesh.geometry?.dispose()
    const mats: Material[] = Array.isArray(mesh.material) ? mesh.material : [mesh.material]
    for (const m of mats) {
      for (const value of Object.values(m)) {
        if (value instanceof Texture) value.dispose()
      }
      m.dispose()
    }
  })
}

// Critério 2: nada permanece vivo após desmontar.
onUnmounted(() => {
  disposed = true
  if (rafId) cancelAnimationFrame(rafId)
  rafId = 0

  resizeObserver?.disconnect()
  resizeObserver = null

  controls?.removeEventListener('change', requestRender)
  controls?.dispose()
  controls = null

  if (scene) {
    disposeObject(scene)
    scene.clear()
  }
  badgeAnchors.clear()
  lampMaterials.clear() // os clones já foram descartados via traverse
  pickables.length = 0

  if (renderer) {
    const canvas = renderer.domElement
    canvas.removeEventListener('pointerdown', onPointerDown)
    canvas.removeEventListener('pointerup', onPointerUp)
    canvas.removeEventListener('webglcontextlost', onContextLost)
    canvas.removeEventListener('webglcontextrestored', onContextRestored)
    renderer.dispose()
    renderer.forceContextLoss() // libera o contexto WebGL imediatamente (limite do navegador)
    canvas.remove()
    renderer = null
  }
  scene = null
  camera = null
})
</script>

<template>
  <div ref="containerRef" class="geonex-3d" role="img" aria-label="Maquete 3D da mina com cinco postes de iluminação">
    <button
      v-for="n in 5"
      :key="n"
      :ref="(el) => setBadgeEl(n, el)"
      type="button"
      class="geonex-3d__badge"
      :class="{ 'geonex-3d__badge--selected': selectedId === n }"
      @click.stop="emit('select-tower', n)"
    >
      {{ badgeLabel(n) }}
    </button>
    <div v-if="loading" class="geonex-3d__overlay">Carregando maquete…</div>
    <div v-else-if="errorMsg" class="geonex-3d__overlay geonex-3d__overlay--error">{{ errorMsg }}</div>
  </div>
</template>

<style scoped>
.geonex-3d {
  position: relative;
  width: 100%;
  height: 100%;
  min-height: 320px;
  overflow: hidden;
  contain: strict; /* isola layout/paint do canvas do resto da página */
}
.geonex-3d :deep(canvas) {
  display: block;
  width: 100%;
  height: 100%;
  outline: none;
  touch-action: none; /* gestos do OrbitControls sem rolagem da página */
  cursor: grab;
}
.geonex-3d__badge {
  position: absolute;
  left: 0;
  top: 0;
  visibility: hidden; /* revelado em updateBadges após o primeiro render */
  padding: 1px 8px;
  border-radius: 9999px;
  border: 1px solid rgba(255, 255, 255, 0.35);
  background: rgba(13, 16, 20, 0.85);
  color: #fff;
  font: 700 11px/1.5 monospace;
  cursor: pointer;
  will-change: transform;
}
.geonex-3d__badge--selected {
  background: #ff6b00;
  border-color: #fff;
}
.geonex-3d__overlay {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  font-size: 0.875rem;
  color: #94a3b8;
  pointer-events: none;
}
.geonex-3d__overlay--error {
  color: #f87171;
}
</style>
