<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { vAutoAnimate } from '@formkit/auto-animate/vue'
import { currentUser, getCurrentUser } from '@/config/auth'
import type { PrioridadeOS, Torre } from '@/types/scada'

export type RoleUsuario = 'ADMIN' | 'OPERADOR' | 'TECNICO'
export type StatusOS = 'ABERTA' | 'EM_ANDAMENTO' | 'CONCLUIDA' | 'CANCELADA'

export interface OrdemServicoItem {
  id: number
  torre_id: number
  torre_codigo: string
  torre_setor?: string
  usuario_criador_id: number
  criador_nome: string
  tecnico_responsavel_id: number | null
  tecnico_nome: string | null
  prioridade: PrioridadeOS
  status: StatusOS
  descricao_problema: string
  laudo_solucao: string | null
  data_abertura: string
  data_inicio_atendimento?: string | null
  data_conclusao?: string | null
}

const props = withDefaults(
  defineProps<{
    torres: Torre[]
    torreInicialId?: number | null
    apiUrl?: string
    usuarioAtual?: { id: number; nome: string; role: RoleUsuario }
  }>(),
  {
    torreInicialId: null,
    apiUrl: 'http://localhost:8000/ordens_servico.php',
    usuarioAtual: () => {
      const u = currentUser.value ?? getCurrentUser()
      return { id: u.id, nome: u.nome, role: u.role }
    },
  },
)

const emit = defineEmits<{
  (e: 'atualizado'): void
}>()

// Perfil RBAC ativo sincronizado com o usuário logado
const roleAtiva = ref<RoleUsuario>((currentUser.value ?? getCurrentUser()).role)
watch(
  () => currentUser.value?.role,
  (novoRole) => {
    if (novoRole) {
      roleAtiva.value = novoRole
    }
  },
  { immediate: true },
)

const usuarioIdAtivo = computed(() => {
  if (roleAtiva.value === 'ADMIN') return 1
  if (roleAtiva.value === 'OPERADOR') return 2
  return 3
})

// Permissões RBAC
const podeAbrirOS = computed(() => roleAtiva.value === 'ADMIN' || roleAtiva.value === 'OPERADOR')
const podeAtenderOS = computed(() => roleAtiva.value === 'ADMIN' || roleAtiva.value === 'TECNICO')

// Estados da UI
const abaAtiva = ref<'LISTA' | 'NOVA'>('LISTA')
const filtroStatus = ref<'TODAS' | StatusOS>('TODAS')
const carregando = ref(false)
const enviando = ref(false)
const feedback = ref<{ tipo: 'ok' | 'erro'; texto: string } | null>(null)

const ordens = ref<OrdemServicoItem[]>([])

// Formulário de Nova O.S.
const novaTorreId = ref<number | null>(props.torreInicialId)
const novaPrioridade = ref<PrioridadeOS>('MEDIA')
const novaDescricao = ref('')
const bloquearTorre = ref(true)

// Edição de Laudo / Status
const osEmEdicao = ref<OrdemServicoItem | null>(null)
const novoStatusEdicao = ref<StatusOS>('EM_ANDAMENTO')
const laudoEdicao = ref('')

watch(
  () => props.torreInicialId,
  (id) => {
    if (id) {
      novaTorreId.value = id
      if (podeAbrirOS.value) abaAtiva.value = 'NOVA'
    }
  },
)

// Ao entrar na tela, carrega os dados direto da API PHP/Docker (e abre o formulário se vier de uma torre)
onMounted(() => {
  carregarOrdens()
  if (props.torreInicialId) {
    novaTorreId.value = props.torreInicialId
    if (podeAbrirOS.value) abaAtiva.value = 'NOVA'
  }
})

const ordensFiltradas = computed(() => {
  if (filtroStatus.value === 'TODAS') return ordens.value
  return ordens.value.filter((o) => o.status === filtroStatus.value)
})

function mostrarFeedback(tipo: 'ok' | 'erro', texto: string) {
  feedback.value = { tipo, texto }
  setTimeout(() => {
    feedback.value = null
  }, 4000)
}

// ============================================================================
// Comunicação com o Backend PHP (GET / POST / PUT)
// ============================================================================

async function carregarOrdens() {
  carregando.value = true
  try {
    const res = await fetch(props.apiUrl, {
      method: 'GET',
      headers: {
        Accept: 'application/json',
        'X-User-Id': String(usuarioIdAtivo.value),
        'X-User-Role': roleAtiva.value,
      },
    })
    const json = await res.json()
    if (res.ok && json.sucesso && Array.isArray(json.dados)) {
      ordens.value = json.dados
    }
  } catch (err) {
    console.error('Erro ao buscar O.S.:', err)
    mostrarFeedback('erro', 'Falha ao conectar com o servidor PHP/Docker.')
  } finally {
    carregando.value = false
  }
}

async function criarOrdemServico() {
  if (!podeAbrirOS.value) {
    mostrarFeedback('erro', 'RBAC: Apenas ADMIN e OPERADOR podem abrir Ordens de Serviço.')
    return
  }
  if (!novaTorreId.value || !novaDescricao.value.trim()) return

  enviando.value = true
  const payload = {
    torre_id: novaTorreId.value,
    usuario_criador_id: usuarioIdAtivo.value,
    usuario_role: roleAtiva.value,
    prioridade: novaPrioridade.value,
    descricao_problema: novaDescricao.value.trim(),
    bloquear_torre: bloquearTorre.value,
  }

  try {
    const res = await fetch(props.apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-User-Id': String(usuarioIdAtivo.value),
        'X-User-Role': roleAtiva.value,
      },
      body: JSON.stringify(payload),
    })
    const json = await res.json()
    if (!res.ok || !json.sucesso) {
      throw new Error(json.erro || 'Erro ao registrar O.S.')
    }

    // Sucesso: recarrega a lista do banco, limpa o formulário e volta para a lista
    await carregarOrdens()
    mostrarFeedback('ok', json.mensagem || 'Ordem de Serviço registrada com sucesso.')

    novaDescricao.value = ''
    novaPrioridade.value = 'MEDIA'
    abaAtiva.value = 'LISTA'
    emit('atualizado')

  } catch (err: any) {
    mostrarFeedback('erro', err.message || 'Erro ao salvar O.S. no servidor.')
  } finally {
    enviando.value = false
  }
}

function selecionarParaLaudo(os: OrdemServicoItem) {
  if (!podeAtenderOS.value) {
    mostrarFeedback('erro', 'RBAC: Apenas TÉCNICO ou ADMIN podem alterar status e preencher laudo.')
    return
  }
  osEmEdicao.value = os
  novoStatusEdicao.value = os.status === 'ABERTA' ? 'EM_ANDAMENTO' : 'CONCLUIDA'
  laudoEdicao.value = os.laudo_solucao ?? ''
}

async function salvarStatusELaudo() {
  if (!osEmEdicao.value) return
  if (novoStatusEdicao.value === 'CONCLUIDA' && !laudoEdicao.value.trim()) {
    mostrarFeedback('erro', 'O preenchimento do Laudo Técnico é obrigatório para concluir a O.S.')
    return
  }

  enviando.value = true
  const alvo = osEmEdicao.value
  const payload = {
    id: alvo.id,
    status: novoStatusEdicao.value,
    laudo_solucao: laudoEdicao.value.trim(),
    tecnico_responsavel_id: usuarioIdAtivo.value,
    usuario_role: roleAtiva.value,
  }

  try {
    const res = await fetch(props.apiUrl, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-User-Id': String(usuarioIdAtivo.value),
        'X-User-Role': roleAtiva.value,
      },
      body: JSON.stringify(payload),
    })
    const json = await res.json()
    if (!res.ok || !json.sucesso) {
      throw new Error(json.erro || 'Erro ao atualizar O.S.')
    }

    // Sucesso: recarrega a lista direto do banco atualizado
    await carregarOrdens()
    mostrarFeedback('ok', json.mensagem || `O.S. #${alvo.id} atualizada com sucesso.`)
    osEmEdicao.value = null
    emit('atualizado')

  } catch (err: any) {
    mostrarFeedback('erro', err.message || 'Erro ao atualizar O.S. no servidor.')
  } finally {
    enviando.value = false
  }
}

function badgePrioridade(p: PrioridadeOS) {
  switch (p) {
    case 'CRITICA': return 'border-fault/60 bg-fault/15 text-fault'
    case 'ALTA': return 'border-ember/60 bg-ember/15 text-ember'
    case 'MEDIA': return 'border-ore/50 bg-ore/10 text-ore'
    default: return 'border-line bg-panel-2 text-mute'
  }
}

function badgeStatus(s: StatusOS) {
  switch (s) {
    case 'ABERTA': return 'border-fault/40 bg-fault/10 text-fault'
    case 'EM_ANDAMENTO': return 'border-ore/50 bg-ore/15 text-ore'
    case 'CONCLUIDA': return 'border-ok/40 bg-ok/10 text-ok'
    default: return 'border-line text-mute'
  }
}

function formatarData(iso?: string | null) {
  if (!iso) return '—'
  const d = new Date(iso.replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? iso
    : d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <section class="flex min-h-0 flex-1 flex-col font-sans p-4">
    <div class="flex min-h-0 w-full flex-1 flex-col overflow-hidden rounded-[24px] border border-white/8 bg-panel shadow-[0_12px_40px_rgb(0,0,0,0.35)]">
      <!-- Cabeçalho SCADA (sem o seletor redundante de nível de acesso) -->
      <header class="flex flex-wrap items-center justify-between gap-4 border-b border-line bg-panel-2/80 px-6 py-4">
        <div>
          <div class="flex items-center gap-2.5">
            <span class="size-2.5 rounded-full bg-[#FF6B00] shadow-[0_0_10px_#FF6B00]" />
            <h2 class="font-display text-base font-bold uppercase tracking-wider text-ink">
              Gestão de Ordens de Serviço (O.S.) · <span class="text-[#FF7A1A]">GEONEX SCADA</span>
            </h2>
          </div>
          <p class="mt-1 font-sans text-xs text-mute">
            Controle de manutenção preventiva/corretiva e bloqueio operacional integrado ao MySQL
          </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
          <button
            type="button"
            class="btn-secondary btn-sm"
            :disabled="carregando"
            @click="carregarOrdens()"
          >
            <span>{{ carregando ? 'Atualizando...' : 'Atualizar Lista' }}</span>
            <svg class="btn-arrow" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
            </svg>
          </button>

          <button
            v-if="podeAbrirOS && abaAtiva !== 'NOVA'"
            type="button"
            class="btn-primary btn-sm"
            @click="abaAtiva = 'NOVA'"
          >
            <span>Nova Ordem de Serviço</span>
            <svg class="btn-arrow" fill="none" stroke="currentColor" stroke-width="2.25" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
            </svg>
          </button>
        </div>
      </header>

      <!-- Barra de Navegação e Filtros -->
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-panel px-6 py-3">
        <div class="flex items-center gap-2">
          <button
            type="button"
            class="rounded-[18px] px-4 py-2 font-display text-xs font-bold tracking-wide transition-all duration-200"
            :class="
              abaAtiva === 'LISTA'
                ? 'border border-[#FF6B00]/50 bg-[#FF6B00]/15 text-[#FF7A1A] shadow-sm'
                : 'border border-transparent bg-panel-2/60 text-mute hover:border-white/10 hover:text-ink'
            "
            @click="abaAtiva = 'LISTA'; carregarOrdens();"
          >
            Chamados Registrados ({{ ordens.length }})
          </button>

          <button
            v-if="podeAbrirOS"
            type="button"
            class="rounded-[18px] px-4 py-2 font-display text-xs font-bold tracking-wide transition-all duration-200"
            :class="
              abaAtiva === 'NOVA'
                ? 'border border-[#FF6B00] bg-[#FF6B00] text-white shadow-[0_4px_16px_rgba(255,107,0,0.35)]'
                : 'border border-white/10 bg-panel-2/60 text-mute hover:border-[#FF6B00]/40 hover:text-ink'
            "
            @click="abaAtiva = 'NOVA'"
          >
            + Abrir Nova O.S.
          </button>
          <span v-else class="font-sans text-xs text-mute italic">
            (Perfil Técnico: abertura de O.S. restrita)
          </span>
        </div>

        <!-- Filtro de Status -->
        <div v-if="abaAtiva === 'LISTA'" class="flex flex-wrap items-center gap-1.5">
          <span class="mr-1 font-display text-[11px] font-semibold uppercase tracking-wider text-mute">Status:</span>
          <button
            v-for="st in (['TODAS', 'ABERTA', 'EM_ANDAMENTO', 'CONCLUIDA'] as const)"
            :key="st"
            type="button"
            class="rounded-full border px-3 py-1 font-display text-[11px] font-semibold tracking-wider transition-all duration-200"
            :class="
              filtroStatus === st
                ? 'border-[#FF6B00]/60 bg-[#FF6B00]/15 text-[#FF7A1A]'
                : 'border-white/8 bg-panel-2/70 text-mute hover:border-white/20 hover:text-ink'
            "
            @click="filtroStatus = st"
          >
            {{ st.replace('_', ' ') }}
          </button>
        </div>
      </div>

      <!-- Feedback -->
      <div
        v-if="feedback"
        class="border-b border-line px-6 py-2.5 font-sans text-xs font-semibold"
        :class="feedback.tipo === 'ok' ? 'bg-ok/15 text-ok' : 'bg-fault/15 text-fault'"
      >
        {{ feedback.texto }}
      </div>

      <!-- LISTA DE CHAMADOS EM CARDS INDEPENDENTES -->
      <div
        v-if="abaAtiva === 'LISTA'"
        v-auto-animate
        class="min-h-0 flex-1 overflow-y-auto bg-abyss/55 p-6"
      >
        <div class="flex flex-col gap-4">
          <article
            v-for="os in ordensFiltradas"
            :key="os.id"
            class="flex flex-col gap-4 rounded-2xl border border-white/8 bg-panel p-5 shadow-[0_8px_26px_rgba(0,0,0,0.35)] transition-all duration-200 hover:border-[#FF6B00]/40 hover:shadow-[0_12px_34px_rgba(0,0,0,0.5)]"
          >
            <!-- Topo do Card: Identificadores e Ação -->
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/[0.06] pb-3.5">
              <div class="flex flex-wrap items-center gap-2.5">
                <span class="rounded-xl border border-white/10 bg-abyss px-3 py-1 font-mono text-xs font-bold tracking-wider text-ink">
                  OS #{{ os.id }}
                </span>
                <span class="rounded-xl border border-[#FF6B00]/35 bg-[#FF6B00]/12 px-3 py-1 font-mono text-xs font-bold tracking-wider text-[#FF7A1A]">
                  {{ os.torre_codigo }}
                </span>
                <span
                  class="rounded-full border px-3 py-1 font-display text-[10px] font-bold uppercase tracking-wider"
                  :class="badgePrioridade(os.prioridade)"
                >
                  Prioridade {{ os.prioridade }}
                </span>
                <span
                  class="rounded-full border px-3 py-1 font-display text-[10px] font-bold uppercase tracking-wider"
                  :class="badgeStatus(os.status)"
                >
                  {{ os.status.replace('_', ' ') }}
                </span>
              </div>

              <div class="flex flex-wrap items-center gap-3 text-xs text-mute">
                <span class="rounded-lg bg-abyss/60 px-2.5 py-1">
                  Aberta em: <strong class="font-mono text-ink">{{ formatarData(os.data_abertura) }}</strong>
                </span>
                <button
                  v-if="podeAtenderOS && os.status !== 'CONCLUIDA'"
                  type="button"
                  :class="os.status === 'ABERTA' ? 'btn-tinted btn-sm' : 'btn-primary-ok btn-sm'"
                  @click="selecionarParaLaudo(os)"
                >
                  <span>{{ os.status === 'ABERTA' ? 'Assumir / Iniciar' : 'Concluir / Laudo' }}</span>
                  <svg class="btn-arrow" fill="none" stroke="currentColor" stroke-width="2.25" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
                  </svg>
                </button>
              </div>
            </div>

            <!-- Corpo do Card: Descrição do Problema -->
            <div class="rounded-xl border border-white/[0.05] bg-panel-2/75 px-4 py-3.5">
              <span class="mb-1 block font-display text-[10px] font-bold uppercase tracking-wider text-mute">
                Descrição da Ocorrência
              </span>
              <p class="font-sans text-sm leading-relaxed text-ink">{{ os.descricao_problema }}</p>
            </div>

            <!-- Laudo Técnico (se preenchido) -->
            <div
              v-if="os.laudo_solucao"
              class="rounded-xl border border-ok/35 bg-ok/[0.08] px-4 py-3 text-xs"
            >
              <span class="mb-1 block font-display text-[10px] font-bold uppercase tracking-wider text-ok">
                Laudo Técnico de Solução
              </span>
              <p class="font-sans text-sm leading-relaxed text-ink">{{ os.laudo_solucao }}</p>
            </div>

            <!-- Rodapé do Card: Metadados -->
            <div class="flex flex-wrap items-center justify-between gap-4 pt-0.5 text-xs text-mute">
              <div class="flex flex-wrap items-center gap-5">
                <span>Solicitante: <strong class="font-semibold text-ink">{{ os.criador_nome }}</strong></span>
                <span>
                  Técnico Responsável:
                  <strong class="font-semibold text-ink">{{ os.tecnico_nome ?? 'Aguardando atribuição' }}</strong>
                </span>
              </div>
              <span v-if="os.data_conclusao" class="rounded-lg border border-ok/25 bg-ok/10 px-2.5 py-0.5 text-[11px] text-ok">
                Concluída em: <strong class="font-mono">{{ formatarData(os.data_conclusao) }}</strong>
              </span>
            </div>

            <!-- Formulário inline para Laudo -->
            <form
              v-if="osEmEdicao?.id === os.id"
              class="mt-1 flex flex-col gap-3 rounded-2xl border border-[#FF6B00]/45 bg-abyss/90 p-4"
              @submit.prevent="salvarStatusELaudo"
            >
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-display text-xs font-bold uppercase tracking-wider text-[#FF7A1A]">
                  Parecer Técnico da O.S. #{{ os.id }} ({{ os.torre_codigo }})
                </span>
                <select v-model="novoStatusEdicao" class="scada-input !w-56 font-display font-semibold">
                  <option value="EM_ANDAMENTO">EM ANDAMENTO</option>
                  <option value="CONCLUIDA">CONCLUÍDA (Liberar Torre)</option>
                  <option value="CANCELADA">CANCELADA</option>
                </select>
              </div>
              <textarea
                v-model="laudoEdicao"
                rows="3"
                placeholder="Descreva o diagnóstico técnico e a solução aplicada na torre..."
                class="scada-input resize-none text-sm"
                :required="novoStatusEdicao === 'CONCLUIDA'"
              />
              <div class="flex justify-end gap-3 pt-1">
                <button
                  type="button"
                  class="btn-secondary btn-sm"
                  @click="osEmEdicao = null"
                >
                  <span>Cancelar</span>
                </button>
                <button
                  type="submit"
                  :disabled="enviando"
                  class="btn-primary btn-sm"
                >
                  <span>{{ enviando ? 'Salvando...' : 'Salvar Status e Laudo' }}</span>
                  <svg class="btn-arrow" fill="none" stroke="currentColor" stroke-width="2.25" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
                  </svg>
                </button>
              </div>
            </form>
          </article>
        </div>

        <div
          v-if="!ordensFiltradas.length && !carregando"
          class="rounded-2xl border border-dashed border-white/10 bg-panel p-12 text-center font-display text-sm text-mute"
        >
          Nenhuma Ordem de Serviço encontrada para o filtro selecionado.
        </div>
        <div
          v-if="carregando"
          class="rounded-2xl border border-white/8 bg-panel p-12 text-center font-display text-sm text-mute animate-pulse"
        >
          Carregando chamados do banco MySQL...
        </div>
      </div>

      <!-- FORMULÁRIO DE ABERTURA DE NOVA O.S. -->
      <form
        v-else
        class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto bg-abyss/40 p-6"
        @submit.prevent="criarOrdemServico"
      >
        <div class="rounded-2xl border border-white/8 bg-panel p-6 shadow-lg space-y-5">
          <div class="border-b border-white/[0.06] pb-3">
            <h3 class="font-display text-base font-bold text-ink">Abertura de Chamado Técnico</h3>
            <p class="text-xs text-mute">Preencha os dados da ocorrência para acionar a equipe de manutenção.</p>
          </div>

          <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <label class="flex flex-col gap-2">
              <span class="font-display text-xs font-bold uppercase tracking-wider text-mute">Torre Afetada</span>
              <select v-model="novaTorreId" class="scada-input !py-2.5 !text-sm font-mono" required>
                <option :value="null" disabled>Selecione a torre...</option>
                <option v-for="t in torres" :key="t.id" :value="t.id">
                  {{ t.codigo }} — {{ typeof t.setor === 'string' ? t.setor : (t.setor?.nome ?? 'Setor Principal') }}
                </option>
              </select>
            </label>

            <label class="flex flex-col gap-2">
              <span class="font-display text-xs font-bold uppercase tracking-wider text-mute">Prioridade Operacional</span>
              <select v-model="novaPrioridade" class="scada-input !py-2.5 !text-sm font-sans" required>
                <option value="BAIXA">BAIXA — Manutenção programada</option>
                <option value="MEDIA">MÉDIA — Degradação de luminosidade</option>
                <option value="ALTA">ALTA — Falha de driver PWM</option>
                <option value="CRITICA">CRÍTICA — Torre apagada em rampa</option>
              </select>
            </label>
          </div>

          <label class="flex flex-col gap-2">
            <span class="font-display text-xs font-bold uppercase tracking-wider text-mute">Descrição Detalhada da Anomalia</span>
            <textarea
              v-model="novaDescricao"
              rows="4"
              maxlength="500"
              required
              placeholder="Descreva o sintoma observado no SCADA..."
              class="scada-input resize-none !p-3.5 !text-sm"
            />
          </label>

          <label class="flex items-center gap-2.5 rounded-xl border border-white/6 bg-abyss/60 px-4 py-3 text-xs text-ink cursor-pointer">
            <input v-model="bloquearTorre" type="checkbox" class="size-4 accent-[#FF6B00]" />
            <span>Bloquear acionamento remoto da torre (<code class="font-mono font-bold text-fault">EM_MANUTENCAO</code>) até a conclusão do chamado.</span>
          </label>

          <div class="flex flex-wrap items-center justify-end gap-3 border-t border-line pt-4">
            <button
              type="button"
              class="btn-secondary"
              @click="abaAtiva = 'LISTA'"
            >
              <span>Voltar à Lista</span>
              <svg class="btn-arrow" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
              </svg>
            </button>
            <button
              type="submit"
              :disabled="enviando || !novaTorreId || !novaDescricao.trim()"
              class="btn-primary"
            >
              <span>{{ enviando ? 'Gravando no Banco...' : 'Confirmar Abertura de O.S.' }}</span>
              <svg class="btn-arrow" fill="none" stroke="currentColor" stroke-width="2.25" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
              </svg>
            </button>
          </div>
        </div>
      </form>
    </div>
  </section>
</template>