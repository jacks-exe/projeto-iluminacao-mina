<script setup lang="ts">
/**
 * GeonexMaquete3D — maquete 3D da mina (Three.js) com renderização SOB DEMANDA.
 */
import { onMounted, onUnmounted, ref, watch, computed } from 'vue'
import {
  ACESFilmicToneMapping, AmbientLight, BufferAttribute, Box3, CanvasTexture, Color, DirectionalLight,
  FogExp2,
  Material, Mesh, MeshStandardMaterial, Object3D, PerspectiveCamera,
  PointLight, Raycaster, Scene, SRGBColorSpace, Texture, Vector2, Vector3,
  WebGLRenderer
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

const emit = defineEmits<{ (e: 'select-tower', id: number | null): void }>()

const containerRef = ref<HTMLDivElement | null>(null)
const loading = ref(true)
const errorMsg = ref<string | null>(null)

const TOWER_COUNT = 5
// PointLights das torres (escala da maquete ~60 un; lâmpadas a ~10 un do chão)
const LIGHT_DISTANCE = 200
const LIGHT_DECAY = 1.5 // < 2 (físico): a luz alcança o chão distante com mais força
const LIGHT_INTENSITY_MAX = 800 // intensidade em PWM 255
const MAX_PIXEL_RATIO = 1.5
const CLICK_MOVE_TOLERANCE_PX = 5
const COLOR_ON = new Color('#ffd27a')
const COLOR_MAINT = new Color('#ff8a00')
const COLOR_OFFLINE = new Color('#3a3a3a')
const COLOR_SELECTED = new Color('#38bdf8')

let renderer: WebGLRenderer | null = null
let scene: Scene | null = null
let camera: PerspectiveCamera | null = null
let controls: OrbitControls | null = null
let resizeObserver: ResizeObserver | null = null
let rafId = 0
let disposed = false

const raycaster = new Raycaster()
const pointer = new Vector2()
const lampMaterials = new Map<number, MeshStandardMaterial>()
const pointLights = new Map<number, PointLight>()
const pickables: Object3D[] = []

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
    badge.style.visibility = projected.z < 1 ? 'visible' : 'hidden'
    badge.style.transform = `translate(-50%, -100%) translate(${((projected.x + 1) / 2) * w}px, ${((1 - projected.y) / 2) * h}px)`
  }
}

let downX = 0
let downY = 0

function requestRender(): void {
  if (rafId || disposed) return
  rafId = requestAnimationFrame(() => {
    rafId = 0
    if (renderer && scene && camera) {
      renderer.render(scene, camera)
      updateBadges()
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

function applyStatus(): void {
  for (const [id, mat] of lampMaterials) {
    const torre = props.torres.find((t) => t.id === id)
    const emManutencao = torre?.emManutencao ?? torre?.em_manutencao ?? torre?.statusOperacional === 'EM_MANUTENCAO'
    const offline = !torre || torre.conectado === false
    const nivel = Math.min(Math.max((torre?.nivelBrilhoAtual ?? 0) / 255, 0), 1)
    
    const pLight = pointLights.get(id)

    // O estado real (PWM/conexão/manutenção) SEMPRE define a intensidade.
    // A seleção só troca a cor da luz quando a lâmpada está acesa — nunca congela a intensidade.
    if (offline) {
      mat.emissive.copy(COLOR_OFFLINE)
      mat.emissiveIntensity = 0.1
      if (pLight) pLight.intensity = 0
    } else if (emManutencao) {
      mat.emissive.copy(COLOR_MAINT)
      mat.emissiveIntensity = 1.5
      if (pLight) { pLight.color.copy(COLOR_MAINT); pLight.intensity = LIGHT_INTENSITY_MAX * 0.2 }
    } else {
      const cor = props.selectedId === id ? COLOR_SELECTED : COLOR_ON
      mat.emissive.copy(cor)
      mat.emissiveIntensity = nivel > 0 ? 5.0 + nivel * 2.0 : 0
      if (pLight) { pLight.color.copy(cor); pLight.intensity = nivel > 0 ? LIGHT_INTENSITY_MAX * nivel : 0 }
    }
  }
  requestRender()
}

// Cria uma assinatura de texto com os estados exatos de todas as torres
const assinaturaTorres = computed(() => {
  return props.torres.map(t => 
    `${t.id}-${t.nivelBrilhoAtual}-${t.conectado}-${t.statusOperacional}-${t.em_manutencao}`
  ).join('|')
})

// O Vue nunca ignora a mudança de uma string simples. Mudou 1% de brilho, ele dispara na hora.
watch(assinaturaTorres, applyStatus)
watch(() => props.selectedId, applyStatus)

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
  if (Math.hypot(e.clientX - downX, e.clientY - downY) > CLICK_MOVE_TOLERANCE_PX) return
  if (!renderer || !camera) return
  const rect = renderer.domElement.getBoundingClientRect()
  pointer.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1)
  raycaster.setFromCamera(pointer, camera)
  const hit = raycaster.intersectObjects(pickables, true)[0]
  const id = hit ? resolveTowerId(hit.object) : null
  if (id === null || id === props.selectedId) {
    emit('select-tower', null) // clique no vazio ou na torre já selecionada: limpa a seleção
  } else {
    emit('select-tower', id)
  }
}

function onContextLost(e: Event): void {
  e.preventDefault()
}

function onContextRestored(): void {
  requestRender()
}

onMounted(() => {
  const el = containerRef.value
  if (!el) return

  // Fundo transparente com alpha: true
  renderer = new WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'low-power' })
  renderer.outputColorSpace = SRGBColorSpace
  renderer.toneMapping = ACESFilmicToneMapping
  renderer.toneMappingExposure = 1.0
  renderer.shadowMap.enabled = false
  el.appendChild(renderer.domElement)
  
  renderer.domElement.addEventListener('pointerdown', onPointerDown)
  renderer.domElement.addEventListener('pointerup', onPointerUp)
  renderer.domElement.addEventListener('webglcontextlost', onContextLost)
  renderer.domElement.addEventListener('webglcontextrestored', onContextRestored)

  scene = new Scene()
  // Névoa exponencial nativa (custo desprezível, sem pós-processamento) na cor do painel.
  // A densidade é recalibrada após o load conforme a escala real da maquete (ver onLoad).
  scene.fog = new FogExp2('#13151E', 0.015)
  camera = new PerspectiveCamera(45, 1, 0.1, 1000)
  camera.position.set(6, 5, 8)

  // Iluminação global contida: dá leitura da forma sem ofuscar as PointLights das torres (contraste alto)
  scene.add(new AmbientLight(0xffffff, 2.5)) // base de MDF sempre visível, mesmo com todas as torres OFF
  
  const sun = new DirectionalLight(0xffeedd, 1.8) // luz principal: relevo dos degraus circulares
  sun.position.set(10, 20, 10)
  scene.add(sun)

  const fillLight = new DirectionalLight(0xaaccff, 0.9) // preenchimento: evita lado totalmente chapado
  fillLight.position.set(-10, 15, -10)
  scene.add(fillLight)

  controls = new OrbitControls(camera, renderer.domElement)
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
        if (obj.name === 'Maquete Mina MDF' && (obj as Mesh).isMesh) {
          const baseMesh = obj as Mesh
          // UV planar (X/Z normalizados) para o alphaMap radial cobrir a base inteira de forma previsível.
          // O material abaixo não usa textura de cor, então sobrescrever o UV original é seguro.
          const geo = baseMesh.geometry
          geo.computeBoundingBox()
          const bb = geo.boundingBox!
          const pos = geo.getAttribute('position')
          const uv = new Float32Array(pos.count * 2)
          for (let i = 0; i < pos.count; i++) {
            uv[i * 2] = (pos.getX(i) - bb.min.x) / (bb.max.x - bb.min.x || 1)
            uv[i * 2 + 1] = (pos.getZ(i) - bb.min.z) / (bb.max.z - bb.min.z || 1)
          }
          geo.setAttribute('uv', new BufferAttribute(uv, 2))

          baseMesh.material = new MeshStandardMaterial({
            color: 0xc9a782,
            roughness: 0.8,
            metalness: 0.1,
            alphaMap: createRadialFadeTexture(), // quinas da base somem suavemente no fundo
            transparent: true,
          })
        }

        if (/^Poste[1-5]$/.test(obj.name)) pickables.push(obj)

        const lamp = /^Luz_Poste_0([1-5])$/.exec(obj.name)
        if (lamp && (obj as Mesh).isMesh) {
          pickables.push(obj)
          
          const mesh = obj as Mesh
          const base = (Array.isArray(mesh.material) ? mesh.material[0] : mesh.material) as MeshStandardMaterial
          const mat = base.clone()
          mat.name = base.name || `Mat_Luz_0${lamp[1]}`
          
          mat.color.setHex(0x000000) 
          mesh.material = mat
          lampMaterials.set(Number(lamp[1]), mat)

          // Luz ligeiramente abaixo da esfera para a carcaça do poste não bloquear o cone; alcance largo e decay suave
          const light = new PointLight(COLOR_ON, 0, LIGHT_DISTANCE, LIGHT_DECAY)
          light.position.y -= 0.5
          mesh.add(light)
          pointLights.set(Number(lamp[1]), light)
        }
      })

      for (let n = 1; n <= TOWER_COUNT; n++) {
        const poste = gltf.scene.getObjectByName(`Poste${n}`)
        if (!poste) continue
        const b = new Box3().setFromObject(poste)
        const c = b.getCenter(new Vector3())
        badgeAnchors.set(n, new Vector3(c.x, b.max.y + (b.max.y - b.min.y) * 0.13, c.z))
      }

      const base = gltf.scene.getObjectByName('Maquete Mina MDF') ?? gltf.scene
      const box = new Box3().setFromObject(base)
      const center = box.getCenter(new Vector3())
      const size = box.getSize(new Vector3()).length() || 10
      controls.target.copy(center)
      camera.position.copy(center).add(new Vector3(size * 0.42, size * 0.36, size * 0.5))
      camera.far = size * 20
      if (scene.fog instanceof FogExp2) {
        // Densidade proporcional à escala: ~11% de névoa no centro e ~39% ao dobro da distância.
        // (0.015 fixo, numa maquete de ~60 unidades, cobriria o centro com >60% de névoa.)
        const dist = camera.position.distanceTo(center)
        scene.fog.density = 0.35 / dist
      }
      camera.updateProjectionMatrix()
      controls.update()

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

/** Textura radial 256x256: branco opaco no centro, desvanecendo para preto (transparente) nas bordas. */
function createRadialFadeTexture(): CanvasTexture {
  const size = 256
  const canvas = document.createElement('canvas')
  canvas.width = canvas.height = size
  const ctx = canvas.getContext('2d')!
  const half = size / 2
  const grad = ctx.createRadialGradient(half, half, 0, half, half, half)
  grad.addColorStop(0, '#fff')
  grad.addColorStop(0.55, '#fff') // núcleo totalmente visível
  grad.addColorStop(1, '#000') // borda do círculo inscrito: invisível (cantos ficam fora)
  ctx.fillStyle = grad
  ctx.fillRect(0, 0, size, size)
  return new CanvasTexture(canvas) // liberada em disposeObject (alphaMap é um Texture do material)
}

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
  lampMaterials.clear()
  pointLights.clear()
  pickables.length = 0

  if (renderer) {
    const canvas = renderer.domElement
    canvas.removeEventListener('pointerdown', onPointerDown)
    canvas.removeEventListener('pointerup', onPointerUp)
    canvas.removeEventListener('webglcontextlost', onContextLost)
    canvas.removeEventListener('webglcontextrestored', onContextRestored)
    renderer.dispose()
    renderer.forceContextLoss()
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
      @click.stop="emit('select-tower', selectedId === n ? null : n)"
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
  contain: strict;
}
.geonex-3d :deep(canvas) {
  display: block;
  width: 100%;
  height: 100%;
  outline: none;
  touch-action: none;
  cursor: grab;
}
.geonex-3d__badge {
  position: absolute;
  left: 0;
  top: 0;
  visibility: hidden;
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