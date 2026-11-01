<script setup lang="ts" generic="T extends string">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    items: { id: T; label: string }[]
    /** Id ativo; 
ull = nenhum ativo (pílula oculta) */
    modelValue: T | null
    disabled?: boolean
    ariaLabel?: string
    /** Classes do contêiner (já recebe `relative`) */
    containerClass?: string
    /** Classes do botão (padding, tamanho de fonte, raio) */
    buttonClass?: string
    /** Classes da pílula deslizante (cor, sombra, raio) */
    pillClass?: string
    activeTextClass?: string
    inactiveTextClass?: string
  }>(),
  {
    ariaLabel: undefined,
    disabled: false,
    containerClass: '',
    buttonClass: 'px-4 py-2 text-sm font-medium rounded-full',
    pillClass: 'bg-white rounded-full shadow-md',
    activeTextClass: 'text-neutral-900',
    inactiveTextClass: 'text-neutral-400 hover:text-white',
  },
)
const emit = defineEmits<{ 'update:modelValue': [id: T] }>()

const temAtivo = computed(() => props.modelValue !== null && !!btns.value[props.modelValue])
const btns = ref<Record<string, HTMLElement>>({})
const pill = ref({ left: 0, width: 0 })
/** Só liga a transição depois da 1ª medição, para a pílula não "voar" desde 0 */
const ready = ref(false)

function medir() {
  const el = props.modelValue === null ? undefined : btns.value[props.modelValue]
  if (!el) return
  pill.value = { left: el.offsetLeft, width: el.offsetWidth }
}

function selecionar(id: T, ev: MouseEvent) {
  const el = ev.currentTarget as HTMLElement
  pill.value = { left: el.offsetLeft, width: el.offsetWidth }
  emit('update:modelValue', id)
}

let ro: ResizeObserver | null = null
const root = ref<HTMLElement | null>(null)

onMounted(async () => {
  await nextTick()
  medir()
  requestAnimationFrame(() => (ready.value = true))
  ro = new ResizeObserver(medir) // reposiciona se a fonte carregar ou a janela mudar
  if (root.value) ro.observe(root.value)
  Object.values(btns.value).forEach((b) => ro?.observe(b))
})
onBeforeUnmount(() => ro?.disconnect())
watch(() => [props.modelValue, props.items], () => nextTick(medir), { deep: true })
</script>

<template>
  <div ref="root" role="group" :aria-label="ariaLabel" class="relative flex items-center" :class="containerClass">
    <!-- Pílula deslizante: fica atrás dos textos e desliza/estica até o botão ativo -->
    <div
      aria-hidden="true"
      class="pointer-events-none absolute inset-y-1 left-0"
      :class="[pillClass, ready ? 'transition-all duration-300 ease-[cubic-bezier(0.4,0,0.2,1)] motion-reduce:transition-none' : '']"
      :style="{ left: `${pill.left}px`, width: `${pill.width}px`, opacity: pill.width && temAtivo ? 1 : 0 }"
    />
    <button
      v-for="item in items"
      :key="item.id"
      :ref="(el) => { if (el) btns[item.id] = el as HTMLElement }"
      type="button"
      :disabled="disabled"
      class="relative z-10 transition-colors duration-300 active:scale-95 disabled:cursor-not-allowed"
      :class="[buttonClass, modelValue === item.id ? activeTextClass : inactiveTextClass]"
      :aria-current="modelValue === item.id ? 'page' : undefined"
      @click="selecionar(item.id, $event)"
    >
      {{ item.label }}
    </button>
  </div>
</template>
