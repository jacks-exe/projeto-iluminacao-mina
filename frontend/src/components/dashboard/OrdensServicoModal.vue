<script setup lang="ts">
import { computed, ref, watch } from 'vue'
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
    aberta: boolean
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
  (e: 'fechar'): void
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

// Sempre que o modal abrir, recarrega os dados direto da API PHP/Docker
watch(
  () => props.aberta,
  (val) => {
    if (val) {
      carregarOrdens()
      if (props.torreInicialId) novaTorreId.value = props.torreInicialId
    }
  },
)

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

    // Sucesso: recarrega a lista do banco, limpa formulário e fecha o modal automaticamente
    await carregarOrdens()
    mostrarFeedback('ok', json.mensagem || 'Ordem de Serviço registrada com sucesso.')
    
    novaDescricao.value = ''
    novaPrioridade.value = 'MEDIA'
    abaAtiva.value = 'LISTA'
    emit('atualizado')
    
    // Fecha o modal após 1.5s para o usuário ver a mensagem de sucesso
    setTimeout(() => {
      emit('fechar')
    }, 1200)

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
  <Teleport to="body">
    <Transition
      enter-active-class="transition duration-200 ease-out"
      enter-from-class="opacity-0 scale-95"
      leave-active-class="transition duration-150 ease-in"
      leave-to-class="opacity-0 scale-95"
    >
      <div
        v-if="aberta"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 p-4 backdrop-blur-sm"
        @click.self="emit('fechar')"
      >
        <div class="scada-card flex max-h-[88vh] w-full max-w-4xl flex-col overflow-hidden shadow-2xl shadow-black">
          <!-- Cabeçalho SCADA + Seletor RBAC -->
          <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-panel-2/70 px-5 py-3.5">
            <div>
              <div class="flex items-center gap-2">
                <span class="size-2 rounded-full bg-ore" />
                <h2 class="text-sm font-bold uppercase tracking-wider text-ink">
                  Gestão de Ordens de Serviço (O.S.) · GEONEX SCADA
                </h2>
              </div>
              <p class="mt-0.5 text-[11px] text-mute">
                Controle de manutenção e bloqueio operacional integrado ao MySQL
              </p>
            </div>

            <!-- Indicador / Seletor RBAC -->
            <div class="flex items-center gap-2">
              <span class="scada-label !text-[9px]">Nível de Acesso Ativo:</span>
              <div class="flex rounded-md border border-line bg-abyss p-0.5 text-[10px] font-semibold">
                <button
                  v-for="r in (['ADMIN', 'OPERADOR', 'TECNICO'] as RoleUsuario[])"
                  :key="r"
                  type="button"
                  :disabled="(currentUser?.role ?? 'ADMIN') !== 'ADMIN' && roleAtiva !== r"
                  class="rounded px-2 py-1 transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                  :class="roleAtiva === r ? 'bg-ore text-abyss' : 'text-mute hover:text-ink'"
                  :title="(currentUser?.role ?? 'ADMIN') === 'ADMIN' ? `Simular visão do perfil ${r}` : `Logado como ${roleAtiva}`"
                  @click="
                    if ((currentUser?.role ?? 'ADMIN') === 'ADMIN') {
                      roleAtiva = r;
                      if (r === 'TECNICO' && abaAtiva === 'NOVA') abaAtiva = 'LISTA';
                      carregarOrdens();
                    }
                  "
                >
                  {{ r }}
                </button>
              </div>

              <button
                type="button"
                class="ml-2 rounded-md border border-line px-2.5 py-1 text-xs text-mute hover:border-fault/50 hover:text-fault"
                @click="emit('fechar')"
              >
                ✕
              </button>
            </div>
          </header>

          <!-- Barra de Navegação e Filtros -->
          <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line bg-panel px-5 py-2.5">
            <div class="flex items-center gap-2">
              <button
                type="button"
                class="rounded-md px-3 py-1.5 text-xs font-semibold transition-colors"
                :class="abaAtiva === 'LISTA' ? 'bg-panel-2 text-ink border border-line' : 'text-mute hover:text-ink'"
                @click="abaAtiva = 'LISTA'; carregarOrdens();"
              >
                Chamados Registrados ({{ ordens.length }})
              </button>

              <button
                v-if="podeAbrirOS"
                type="button"
                class="rounded-md border px-3 py-1.5 text-xs font-semibold transition-colors"
                :class="
                  abaAtiva === 'NOVA'
                    ? 'border-ore bg-ore text-abyss'
                    : 'border-ore/50 bg-ore/10 text-ore hover:bg-ore/20'
                "
                @click="abaAtiva = 'NOVA'"
              >
                + Nova O.S.
              </button>
              <span v-else class="text-[11px] text-mute italic">
                (Perfil Técnico: abertura de O.S. restrita)
              </span>
            </div>

            <div class="flex items-center gap-2">
              <!-- Botão manual de refresh -->
              <button
                type="button"
                class="rounded border border-line bg-panel-2 px-2.5 py-1 text-[11px] text-mute hover:text-ink"
                :disabled="carregando"
                @click="carregarOrdens()"
              >
                {{ carregando ? 'Atualizando...' : '🔄 Atualizar Lista' }}
              </button>

              <!-- Filtro de Status -->
              <div v-if="abaAtiva === 'LISTA'" class="flex items-center gap-1 text-[11px]">
                <button
                  v-for="st in (['TODAS', 'ABERTA', 'EM_ANDAMENTO', 'CONCLUIDA'] as const)"
                  :key="st"
                  type="button"
                  class="rounded border px-2 py-0.5 font-mono text-[10px] transition-colors"
                  :class="
                    filtroStatus === st
                      ? 'border-ore/60 bg-ore/15 text-ore'
                      : 'border-line bg-panel-2/50 text-mute hover:text-ink'
                  "
                  @click="filtroStatus = st"
                >
                  {{ st }}
                </button>
              </div>
            </div>
          </div>

          <!-- Feedback -->
          <div
            v-if="feedback"
            class="border-b border-line px-5 py-2 text-xs font-medium"
            :class="feedback.tipo === 'ok' ? 'bg-ok/15 text-ok' : 'bg-fault/15 text-fault'"
          >
            {{ feedback.texto }}
          </div>

          <!-- LISTA DE CHAMADOS -->
          <div v-if="abaAtiva === 'LISTA'" class="min-h-0 flex-1 overflow-y-auto divide-y divide-line">
            <div
              v-for="os in ordensFiltradas"
              :key="os.id"
              class="flex flex-col gap-2.5 px-5 py-3.5 transition-colors hover:bg-panel-2/40"
            >
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2.5">
                  <span class="font-mono text-xs font-bold text-ink">OS #{{ os.id }}</span>
                  <span class="rounded bg-clay/15 px-2 py-0.5 font-mono text-xs font-semibold text-clay">
                    {{ os.torre_codigo }}
                  </span>
                  <span
                    class="rounded border px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider"
                    :class="badgePrioridade(os.prioridade)"
                  >
                    {{ os.prioridade }}
                  </span>
                  <span
                    class="rounded border px-2 py-0.5 font-mono text-[10px] font-semibold"
                    :class="badgeStatus(os.status)"
                  >
                    {{ os.status }}
                  </span>
                </div>

                <div class="flex items-center gap-3 text-[11px] text-mute">
                  <span>Aberta: <strong class="font-mono text-ink">{{ formatarData(os.data_abertura) }}</strong></span>
                  <button
                    v-if="podeAtenderOS && os.status !== 'CONCLUIDA'"
                    type="button"
                    class="rounded border border-ore/50 bg-ore/10 px-2.5 py-1 text-[11px] font-semibold text-ore hover:bg-ore/20"
                    @click="selecionarParaLaudo(os)"
                  >
                    {{ os.status === 'ABERTA' ? 'Assumir / Iniciar' : 'Concluir / Laudo' }}
                  </button>
                </div>
              </div>

              <p class="text-xs leading-relaxed text-ink">{{ os.descricao_problema }}</p>

              <div
                v-if="os.laudo_solucao"
                class="rounded-md border border-line bg-abyss/60 px-3 py-2 text-[11px] text-mute"
              >
                <span class="font-semibold uppercase tracking-wider text-ok">Laudo Técnico: </span>
                <span class="text-ink">{{ os.laudo_solucao }}</span>
              </div>

              <div class="flex flex-wrap items-center gap-4 text-[10px] text-mute">
                <span>Solicitante: <strong class="text-ink">{{ os.criador_nome }}</strong></span>
                <span>
                  Técnico:
                  <strong class="text-ink">{{ os.tecnico_nome ?? 'Aguardando atribuição' }}</strong>
                </span>
                <span v-if="os.data_conclusao">
                  Concluída em: <strong class="font-mono text-ok">{{ formatarData(os.data_conclusao) }}</strong>
                </span>
              </div>

              <!-- Formulário inline para Laudo -->
              <form
                v-if="osEmEdicao?.id === os.id"
                class="mt-2 flex flex-col gap-2.5 rounded-lg border border-ore/40 bg-panel-2 p-3"
                @submit.prevent="salvarStatusELaudo"
              >
                <div class="flex items-center justify-between">
                  <span class="scada-label text-ore">Parecer Técnico da O.S. #{{ os.id }}</span>
                  <select v-model="novoStatusEdicao" class="scada-input !w-48">
                    <option value="EM_ANDAMENTO">EM ANDAMENTO</option>
                    <option value="CONCLUIDA">CONCLUÍDA (Liberar Torre)</option>
                    <option value="CANCELADA">CANCELADA</option>
                  </select>
                </div>
                <textarea
                  v-model="laudoEdicao"
                  rows="2"
                  placeholder="Descreva o diagnóstico e a solução aplicada..."
                  class="scada-input resize-none"
                  :required="novoStatusEdicao === 'CONCLUIDA'"
                />
                <div class="flex justify-end gap-2">
                  <button
                    type="button"
                    class="rounded border border-line px-3 py-1 text-xs text-mute hover:text-ink"
                    @click="osEmEdicao = null"
                  >
                    Cancelar
                  </button>
                  <button
                    type="submit"
                    :disabled="enviando"
                    class="rounded bg-ore px-3.5 py-1 text-xs font-semibold text-abyss hover:opacity-90 disabled:opacity-40"
                  >
                    {{ enviando ? 'Salvando...' : 'Salvar Status e Laudo' }}
                  </button>
                </div>
              </form>
            </div>

            <div v-if="!ordensFiltradas.length && !carregando" class="py-12 text-center text-xs text-mute">
              Nenhuma Ordem de Serviço encontrada.
            </div>
            <div v-if="carregando" class="py-12 text-center text-xs text-mute animate-pulse">
              Carregando chamados do banco MySQL...
            </div>
          </div>

          <!-- FORMULÁRIO DE ABERTURA DE NOVA O.S. -->
          <form
            v-else
            class="flex flex-col gap-4 p-5"
            @submit.prevent="criarOrdemServico"
          >
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="flex flex-col gap-1.5">
                <span class="scada-label">Torre Afetada</span>
                <select v-model="novaTorreId" class="scada-input" required>
                  <option :value="null" disabled>Selecione a torre...</option>
                  <option v-for="t in torres" :key="t.id" :value="t.id">
                    {{ t.codigo }} — {{ t.setor ?? 'Setor Principal' }}
                  </option>
                </select>
              </label>

              <label class="flex flex-col gap-1.5">
                <span class="scada-label">Prioridade Operacional</span>
                <select v-model="novaPrioridade" class="scade-input scada-input" required>
                  <option value="BAIXA">BAIXA — Manutenção programada</option>
                  <option value="MEDIA">MÉDIA — Degradação de luminosidade</option>
                  <option value="ALTA">ALTA — Falha de driver PWM</option>
                  <option value="CRITICA">CRÍTICA — Torre apagada em rampa</option>
                </select>
              </label>
            </div>

            <label class="flex flex-col gap-1.5">
              <span class="scada-label">Descrição Detalhada da Anomalia</span>
              <textarea
                v-model="novaDescricao"
                rows="4"
                maxlength="500"
                required
                placeholder="Descreva o sintoma observado no SCADA..."
                class="scada-input resize-none"
              />
            </label>

            <label class="flex items-center gap-2 text-xs text-ink">
              <input v-model="bloquearTorre" type="checkbox" class="accent-ore" />
              <span>Bloquear acionamento remoto da torre (<code class="text-fault">EM_MANUTENCAO</code>) até a conclusão.</span>
            </label>

            <div class="mt-2 flex items-center justify-end gap-2 border-t border-line pt-4">
              <button
                type="button"
                class="rounded-md border border-line px-4 py-2 text-xs font-semibold text-mute hover:text-ink"
                @click="abaAtiva = 'LISTA'"
              >
                Voltar à Lista
              </button>
              <button
                type="submit"
                :disabled="enviando || !novaTorreId || !novaDescricao.trim()"
                class="rounded-md bg-ore px-5 py-2 text-xs font-bold text-abyss transition-opacity hover:opacity-90 disabled:opacity-40"
              >
                {{ enviando ? 'Gravando no Banco...' : 'Confirmar Abertura de O.S.' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>