<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useManutencao } from '@/composables/useManutencao'
import type { OrdemManutencao, PrioridadeOS, Torre } from '@/types/scada'

const props = defineProps<{ torres: Torre[] }>()

const { ordens, enviando, erro, abrir } = useManutencao()

const formAberto = ref(false)
const torreId = ref<number | null>(null)
const descricao = ref('')
const prioridade = ref<PrioridadeOS>('MEDIA')
const feedback = ref<string | null>(null)
const descricaoEl = ref<HTMLTextAreaElement | null>(null)

const torresValidas = computed(() => props.torres.filter((t) => t.id > 0))
const emManutencao = computed(() => props.torres.filter((t) => t.statusOperacional === 'EM_MANUTENCAO').length)

const PRIORIDADES: { id: PrioridadeOS; label: string; cls: string }[] = [
  { id: 'BAIXA', label: 'Baixa', cls: 'text-mute border-line' },
  { id: 'MEDIA', label: 'Média', cls: 'text-clay border-clay/40' },
  { id: 'ALTA', label: 'Alta', cls: 'text-ore border-ore/50' },
  { id: 'CRITICA', label: 'Crítica', cls: 'text-ember border-ember/60' },
]
const prioCls = (p?: string) => PRIORIDADES.find((x) => x.id === p)?.cls ?? 'text-mute border-line'
const prioLabel = (p?: string) => PRIORIDADES.find((x) => x.id === p)?.label ?? p ?? '—'

function codigoDa(o: OrdemManutencao) {
  if (o.torre?.codigo) return o.torre.codigo
  const t = props.torres.find((x) => x.id === (o.torreId ?? o.torre?.id))
  return t?.codigo ?? '—'
}

function quando(iso?: string) {
  if (!iso) return ''
  const d = new Date(iso)
  return Number.isNaN(d.getTime())
    ? ''
    : d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

/** Chamado externamente (popover do nó) para abrir OS pré-preenchida */
async function abrirPara(torre: Torre) {
  formAberto.value = true
  torreId.value = torre.id
  await nextTick()
  descricaoEl.value?.focus()
}
defineExpose({ abrirPara })

async function enviar() {
  if (!torreId.value || !descricao.value.trim()) return
  const ok = await abrir({ torreId: torreId.value, descricao: descricao.value.trim(), prioridade: prioridade.value })
  if (ok) {
    feedback.value = 'Ordem de serviço registrada.'
    descricao.value = ''
    prioridade.value = 'MEDIA'
    formAberto.value = false
    setTimeout(() => (feedback.value = null), 3000)
  }
}
</script>

<template>
  <section class="scada-card flex min-h-0 flex-1 flex-col">
    <header class="flex items-center justify-between border-b border-line px-4 py-3">
      <div>
        <h2 class="scada-label">Ordens de serviço</h2>
        <p class="mt-0.5 text-[11px] text-mute">
          <span class="font-mono text-ink">{{ ordens.length }}</span> pendentes ·
          <span class="font-mono" :class="emManutencao ? 'text-fault' : 'text-ink'">{{ emManutencao }}</span> torre(s) bloqueada(s)
        </p>
      </div>
      <button
        type="button"
        class="rounded-md border px-2.5 py-1 text-[11px] font-semibold transition-colors"
        :class="formAberto ? 'border-line text-mute hover:text-ink' : 'border-ore/50 bg-ore/10 text-ore hover:bg-ore/20'"
        @click="formAberto = !formAberto"
      >
        {{ formAberto ? 'Cancelar' : '+ Nova OS' }}
      </button>
    </header>

    <!-- Formulário -->
    <form v-if="formAberto" class="flex flex-col gap-2 border-b border-line bg-panel-2/40 px-4 py-3" @submit.prevent="enviar">
      <div class="grid grid-cols-2 gap-2">
        <label class="flex flex-col gap-1">
          <span class="scada-label !text-[9px]">Torre</span>
          <select v-model="torreId" class="scada-input" required>
            <option :value="null" disabled>Selecione</option>
            <option v-for="t in torresValidas" :key="t.id" :value="t.id">{{ t.codigo }}</option>
          </select>
        </label>
        <label class="flex flex-col gap-1">
          <span class="scada-label !text-[9px]">Prioridade</span>
          <select v-model="prioridade" class="scada-input">
            <option v-for="p in PRIORIDADES" :key="p.id" :value="p.id">{{ p.label }}</option>
          </select>
        </label>
      </div>
      <label class="flex flex-col gap-1">
        <span class="scada-label !text-[9px]">Descrição da falha</span>
        <textarea
          ref="descricaoEl"
          v-model="descricao"
          rows="2"
          maxlength="280"
          required
          placeholder="Ex.: luminária oscilando, driver superaquecido…"
          class="scada-input resize-none"
        />
      </label>
      <button
        type="submit"
        :disabled="enviando || !torreId || !descricao.trim()"
        class="rounded-md bg-ore py-1.5 text-xs font-semibold text-abyss transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40"
      >
        {{ enviando ? 'Registrando…' : 'Registrar ordem de serviço' }}
      </button>
    </form>

    <p v-if="feedback" class="border-b border-line bg-ok/10 px-4 py-1.5 text-[11px] text-ok">{{ feedback }}</p>
    <p v-else-if="erro && !ordens.length" class="px-4 py-2 text-[11px] text-fault/80">Não foi possível carregar: {{ erro }}</p>

    <!-- Lista -->
    <ul class="min-h-0 flex-1 divide-y divide-line overflow-y-auto">
      <li v-for="o in ordens" :key="o.id" class="flex gap-3 px-4 py-2.5 transition-colors hover:bg-panel-2/50">
        <span class="mt-0.5 font-mono text-[11px] font-semibold text-clay">{{ codigoDa(o) }}</span>
        <div class="min-w-0 flex-1">
          <p class="line-clamp-2 text-xs text-ink">{{ o.descricao }}</p>
          <p class="mt-1 flex items-center gap-2 text-[10px] text-mute">
            <span class="rounded border px-1.5 py-px font-semibold uppercase tracking-wider" :class="prioCls(o.prioridade)">
              {{ prioLabel(o.prioridade) }}
            </span>
            <span class="font-mono">OS #{{ o.id }}</span>
            <span v-if="quando(o.dataAbertura)" class="font-mono">{{ quando(o.dataAbertura) }}</span>
          </p>
        </div>
      </li>
      <li v-if="!ordens.length && !erro" class="px-4 py-8 text-center text-[11px] text-mute">
        Nenhuma ordem pendente.
      </li>
    </ul>
  </section>
</template>
