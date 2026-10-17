import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

export function useClock() {
  const now = ref(new Date())
  let timer: ReturnType<typeof setInterval> | null = null

  onMounted(() => {
    timer = setInterval(() => (now.value = new Date()), 1000)
  })
  onBeforeUnmount(() => {
    if (timer) clearInterval(timer)
  })

  const hora = computed(() =>
    now.value.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
  )
  const data = computed(() =>
    now.value.toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }),
  )

  return { now, hora, data }
}
