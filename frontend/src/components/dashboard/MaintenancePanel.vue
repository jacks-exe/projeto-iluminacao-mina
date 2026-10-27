<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import OrdensServicoModal from '@/components/dashboard/OrdensServicoModal.vue'
import { currentPermissions, currentUser, getCurrentUser } from '@/config/auth'
import { POLL_MANUTENCAO_MS } from '@/config/scada'
import type { PrioridadeOS, Torre } from '@/types/scada'

const props = withDefaults(
  defineProps<{
    torres: Torre[]
    apiUrl?: string
  }>(),
  {
    apiUrl: 'http://localhost:8000/ordens_servico.php',
  },
)

interface OrdemServicoPainel {
  id: number
  torreId: number | null
  torreCodigo: string
  torreStatus?: string | null
  descricao: string
  prioridade: PrioridadeOS
  status: string
  dataAbertura: string
}

const ordens = ref<OrdemServicoPainel[]>([])
const carregando = ref(false)
const enviando = ref(false)
const erro = ref<string | null>(null)

const modalAberto = ref(false)
const formAberto = ref(false)
const torreId = ref<number | null>(null)
const descricao = ref('')
const prioridade = ref<PrioridadeOS>('MEDIA')
const feedback = ref<string | null>(null)
const descricaoEl = ref<HTMLTextAreaElement | null>(null)

let pollTimer: ReturnType<typeof setInterval> | null = null

const torresValidas = computed(() => props.torres.filter((t) => t.id > 0))
const usuarioAtivo = computed(() => currentUser.value ?? getCurrentUser())
const permissoes = computed(() => currentPermissions.value)

const emManutencao = computed(() => {
  const idsBloqueados = new Set<number>()
  for (const t of props.torres) {
    if (String(t.statusOperacional ?? '').toUpperCase() === 'EM_MANUTENCAO') {
      idsBloqueados.add(t.id)
    }
  }
  for (const o of ordensAtivas.value) {
    if (o.torreId && String(o.torreStatus ?? '').toUpperCase() === 'EM_MANUTENCAO') {
      idsBloqueados.add(o.torreId)
    }
  }
  return idsBloqueados.size
})

/**
 * Verifica de forma segura se o status representa uma O.S. ativa (ABERTA ou EM_ANDAMENTO),
 * tratando maiúsculas/minúsculas, espaços e variações.
 */
function isStatusAtivo(status?: string | null): boolean {
  const st = String(status ?? '')
    .trim()
    .toUpperCase()
    .replace(/\s+/g, '_')
  return st === 'ABERTA' || st === 'EM_ANDAMENTO' || st === 'PENDENTE'
}

/**
 * Lista reativa filtrada garantindo que apenas chamados ativos (ABERTA / EM_ANDAMENTO)
 * sejam exibidos e nunca sumam por divergência de capitalização.
 */
const ordensAtivas = computed(() =>
  ordens.value.filter((o) => isStatusAtivo(o.status)),
)

const PRIORIDADES: { id: PrioridadeOS; label: string; cls: string }[] = [
  { id: 'BAIXA', label: 'Baixa', cls: 'text-mute border-line' },
  { id: 'MEDIA', label: 'Média', cls: 'text-clay border-clay/40' },
  { id: 'ALTA', label: 'Alta', cls: 'text-ore border-ore/50' },
  { id: 'CRITICA', label: 'Crítica', cls: 'text-ember border-ember/60' },
]

const prioCls = (p?: string) => {
  const key = String(p ?? '').trim().toUpperCase()
  return PRIORIDADES.find((x) => x.id === key)?.cls ?? 'text-mute border-line'
}

const prioLabel = (p?: string) => {
  const key = String(p ?? '').trim().toUpperCase()
  return PRIORIDADES.find((x) => x.id === key)?.label ?? p ?? '—'
}

function statusLabel(st?: string) {
  const norm = String(st ?? '').trim().toUpperCase().replace(/\s+/g, '_')
  if (norm === 'EM_ANDAMENTO') return 'EM ANDAMENTO'
  return norm || 'ABERTA'
}

function statusCls(st?: string) {
  const norm = String(st ?? '').trim().toUpperCase().replace(/\s+/g, '_')
  if (norm === 'EM_ANDAMENTO') return 'border-ore/50 bg-ore/15 text-ore'
  return 'border-fault/40 bg-fault/10 text-fault'
}

function codigoDa(o: OrdemServicoPainel) {
  if (o.torreCodigo && o.torreCodigo !== '—') return o.torreCodigo
  const t = props.torres.find((x) => x.id === o.torreId)
  return t?.codigo ?? (o.torreId ? `TR-0${o.torreId}` : '—')
}

function quando(iso?: string) {
  if (!iso) return ''
  // Trata formato DATETIME do MySQL ("YYYY-MM-DD HH:MM:SS") e ISO 8601
  const d = new Date(iso.replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

/**
 * Busca as Ordens de Serviço diretamente da API PHP/Docker
 * tratando o envelope JSON { sucesso: true, dados: [...] }
 */
async function carregar() {
  carregando.value = true
  try {
    const usr = usuarioAtivo.value
    const res = await fetch(props.apiUrl, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        'X-User-Id': String(usr.id),
        'X-User-Role': usr.role,
      },
    })

    const json = await res.json()

    if (!res.ok || json?.sucesso === false) {
      throw new Error(json?.erro || `Erro HTTP ${res.status}`)
    }

    // Extrai o array com segurança de json.dados (ou caso venha array direto)
    const listaBruta: any[] = Array.isArray(json?.dados)
      ? json.dados
      : Array.isArray(json)
        ? json
        : []

    ordens.value = listaBruta
      .map((item): OrdemServicoPainel => {
        const idTorre =
          item.torre_id != null
            ? Number(item.torre_id)
            : item.torreId != null
              ? Number(item.torreId)
              : (item.torre?.id ?? null)

        const statusNorm = String(item.status ?? 'ABERTA')
          .trim()
          .toUpperCase()
          .replace(/\s+/g, '_')

        const prioNorm = String(item.prioridade ?? 'MEDIA')
          .trim()
          .toUpperCase() as PrioridadeOS

        return {
          id: Number(item.id),
          torreId: idTorre,
          torreCodigo: item.torre_codigo ?? item.torre?.codigo ?? '',
          torreStatus: item.torre_status ?? null,
          descricao: String(item.descricao_problema ?? item.descricao ?? '').trim(),
          prioridade: prioNorm,
          status: statusNorm,
          dataAbertura: String(item.data_abertura ?? item.dataAbertura ?? ''),
        }
      })
      .filter((o) => isStatusAtivo(o.status))

    erro.value = null
  } catch (e) {
    console.error('Erro ao carregar O.S. no painel lateral:', e)
    erro.value = (e as Error).message || 'Falha ao conectar com a API PHP'
  } finally {
    carregando.value = false
  }
}

/** Chamado externamente (popover do nó) para abrir OS pré-preenchida */
async function abrirPara(torre: Torre) {
  torreId.value = torre.id
  if (!permissoes.value.canOpenOS) {
    modalAberto.value = true
    return
  }
  formAberto.value = true
  await nextTick()
  descricaoEl.value?.focus()
}
defineExpose({ abrirPara, carregar })

async function enviar() {
  if (!permissoes.value.canOpenOS) {
    erro.value = 'Seu perfil (Técnico) não possui permissão para abrir novas O.S.'
    return
  }
  if (!torreId.value || !descricao.value.trim()) return
  enviando.value = true
  erro.value = null

  try {
    const usr = usuarioAtivo.value
    const res = await fetch(props.apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-User-Id': String(usr.id),
        'X-User-Role': usr.role,
      },
      body: JSON.stringify({
        torre_id: torreId.value,
        usuario_criador_id: usr.id,
        usuario_role: usr.role,
        prioridade: prioridade.value,
        descricao_problema: descricao.value.trim(),
        bloquear_torre: true,
      }),
    })

    const json = await res.json()
    if (!res.ok || !json?.sucesso) {
      throw new Error(json?.erro || 'Não foi possível registrar a O.S.')
    }

    feedback.value = json.mensagem || 'Ordem de serviço registrada.'
    descricao.value = ''
    prioridade.value = 'MEDIA'
    formAberto.value = false

    // Recarrega a lista do backend imediatamente após registrar
    await carregar()

    setTimeout(() => (feedback.value = null), 3000)
  } catch (e) {
    erro.value = (e as Error).message || 'Erro ao registrar O.S.'
  } finally {
    enviando.value = false
  }
}

// Carrega as ordens ao montar o componente e mantém sincronia periódica
onMounted(() => {
  carregar()
  pollTimer = setInterval(carregar, POLL_MANUTENCAO_MS)
})

onBeforeUnmount(() => {
  if (pollTimer) clearInterval(pollTimer)
})
</script>

<template>
  <section class="scada-card flex min-h-0 flex-1 flex-col">
    <header class="flex items-center justify-between border-b border-line px-4 py-3">
      <div>
        <h2 class="scada-label">Ordens de serviço</h2>
        <p class="mt-0.5 text-[11px] text-mute">
          <span class="font-mono text-ink">{{ ordensAtivas.length }}</span> pendentes ·
          <span class="font-mono" :class="emManutencao ? 'text-fault' : 'text-ink'">{{ emManutencao }}</span> torre(s) bloqueada(s)
        </p>
      </div>
      <div class="flex items-center gap-1.5">
        <button
          type="button"
          title="Abrir central completa de O.S. e Laudos (RBAC)"
          class="rounded-md border border-line bg-panel-2 px-2 py-1 text-[11px] font-semibold text-mute transition-colors hover:border-clay/50 hover:text-ink"
          @click="modalAberto = true"
        >
          {{ permissoes.canAttendOS && !permissoes.canOpenOS ? '🔧 Atender / Laudos' : 'Gerenciar' }}
        </button>
        <button
          v-if="permissoes.canOpenOS"
          type="button"
          class="rounded-md border px-2.5 py-1 text-[11px] font-semibold transition-colors"
          :class="formAberto ? 'border-line text-mute hover:text-ink' : 'border-ore/50 bg-ore/10 text-ore hover:bg-ore/20'"
          @click="formAberto = !formAberto"
        >
          {{ formAberto ? 'Cancelar' : '+ Nova OS' }}
        </button>
      </div>
    </header>

    <!-- Formulário Rápido -->
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
    <p v-else-if="erro && !ordensAtivas.length" class="px-4 py-2 text-[11px] text-fault/80">
      Não foi possível carregar: {{ erro }}
    </p>

    <!-- Lista de Ordens Pendentes (ABERTA / EM_ANDAMENTO) -->
    <ul class="min-h-0 flex-1 divide-y divide-line overflow-y-auto">
      <li
        v-for="o in ordensAtivas"
        :key="o.id"
        class="flex gap-3 px-4 py-2.5 transition-colors hover:bg-panel-2/50"
      >
        <span class="mt-0.5 font-mono text-[11px] font-semibold text-clay">{{ codigoDa(o) }}</span>
        <div class="min-w-0 flex-1">
          <p class="line-clamp-2 text-xs text-ink">{{ o.descricao }}</p>
          <p class="mt-1 flex flex-wrap items-center gap-1.5 text-[10px] text-mute">
            <span class="rounded border px-1.5 py-px font-semibold uppercase tracking-wider" :class="prioCls(o.prioridade)">
              {{ prioLabel(o.prioridade) }}
            </span>
            <span class="rounded border px-1.5 py-px font-mono text-[9px] font-semibold" :class="statusCls(o.status)">
              {{ statusLabel(o.status) }}
            </span>
            <span class="font-mono">OS #{{ o.id }}</span>
            <span v-if="quando(o.dataAbertura)" class="font-mono">{{ quando(o.dataAbertura) }}</span>
          </p>
        </div>
      </li>
      <li v-if="!ordensAtivas.length && !erro && !carregando" class="px-4 py-8 text-center text-[11px] text-mute">
        Nenhuma ordem pendente.
      </li>
      <li v-if="carregando && !ordensAtivas.length" class="px-4 py-8 text-center text-[11px] text-mute animate-pulse">
        Sincronizando O.S. com o banco...
      </li>
    </ul>

    <OrdensServicoModal
      :aberta="modalAberto"
      :torres="torres"
      :torre-inicial-id="torreId"
      :api-url="props.apiUrl"
      :usuario-atual="usuarioAtivo"
      @fechar="modalAberto = false"
      @atualizado="carregar"
    />
  </section>
</template>
