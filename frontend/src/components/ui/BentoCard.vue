<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    title?: string
    /** Tooltip do ícone "i" ao lado do título */
    info?: string
    /** default = branco | inverted = Hero preto sólido | soft = cinza translúcido */
    variant?: 'default' | 'inverted' | 'soft'
    /** false = corpo sem padding (mapas, tabelas) */
    padded?: boolean
  }>(),
  { variant: 'default', padded: true },
)

const variantCls = computed(() => {
  switch (props.variant) {
    case 'inverted':
      return 'bg-[#0F1013] text-white border-white/[0.08] shadow-xl shadow-black/20'
    case 'soft':
      return 'bg-neutral-200/50 dark:bg-white/[0.04] text-neutral-900 dark:text-neutral-100 border-black/[0.05] dark:border-white/[0.08]'
    default:
      return 'bg-white dark:bg-[#16171C] text-neutral-900 dark:text-neutral-100 border-black/[0.05] dark:border-white/[0.08] shadow-[0_1px_2px_rgba(0,0,0,0.03)]'
  }
})
</script>

<template>
  <section
    class="relative flex min-w-0 flex-col overflow-hidden rounded-[26px] border"
    :class="[variantCls, padded ? 'p-6' : '']"
  >
    <header
      v-if="title || $slots.action"
      class="flex items-center justify-between gap-3"
      :class="padded ? 'mb-4' : 'px-6 pb-4 pt-6'"
    >
      <h2 v-if="title" class="flex items-center gap-1.5 text-sm font-semibold tracking-tight">
        {{ title }}
        <span
          v-if="info"
          :title="info"
          class="inline-flex size-4 cursor-help items-center justify-center rounded-full border border-current text-[9px] font-bold opacity-40"
          >i</span
        >
      </h2>
      <div v-if="$slots.action" class="flex items-center gap-2"><slot name="action" /></div>
    </header>

    <div class="min-h-0 flex-1"><slot /></div>

    <footer
      v-if="$slots.footer"
      class="mt-4 border-t border-dashed pt-3 text-xs"
      :class="[
        variant === 'inverted' ? 'border-white/15 text-white/60' : 'border-black/10 text-neutral-500 dark:border-white/10 dark:text-neutral-400',
        padded ? '' : 'mx-6 mb-5',
      ]"
    >
      <slot name="footer" />
    </footer>
  </section>
</template>
