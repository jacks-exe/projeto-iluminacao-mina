<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { vAutoAnimate } from '@formkit/auto-animate/vue'
import {
  USUARIOS_PADRAO,
  currentPermissions,
  currentUser,
  getCurrentUser,
  setAuthSession,
  type AuthUser,
  type UserRole,
} from '@/config/auth'
import { usuariosApi } from '@/services/api'

defineProps<{ simulacao: boolean }>()
const emit = defineEmits<{ 'toggle-simulacao': []; 'reset-simulacao': [] }>()

/** Cargo do usuário logado: controla a exibição do painel de Gestão de Usuários */
const userRole = computed<UserRole>(() => (currentUser.value ?? getCurrentUser()).role)
const meuId = computed(() => (currentUser.value ?? getCurrentUser()).id)

const CARGOS: { id: UserRole; label: string }[] = [
  { id: 'OPERADOR', label: 'Operador' },
  { id: 'TECNICO', label: 'Técnico' },
  { id: 'ADMIN', label: 'Admin' },
]
const CARGO_CLS: Record<UserRole, string> = {
  ADMIN: 'border-ore/50 bg-ore/10 text-ore',
  TECNICO: 'border-ok/40 bg-ok/10 text-ok',
  OPERADOR: 'border-clay/50 bg-clay/10 text-clay',
}

const usuarios = ref<AuthUser[]>([
  { ...USUARIOS_PADRAO.ADMIN },
  { ...USUARIOS_PADRAO.OPERADOR },
  { ...USUARIOS_PADRAO.TECNICO },
])

const carregando = ref(false)
const salvando = ref(false)
const novoNome = ref('')
const novoUsername = ref('')
const novaSenhaInicial = ref('')
const novoCargo = ref<UserRole>('OPERADOR')

const erro = ref<string | null>(null)
const sucesso = ref<string | null>(null)
const removendoId = ref<number | null>(null)

/* Estado de Edição de Usuário (Dados + Alteração de Senha pelo Admin via Bcrypt) */
const usuarioEmEdicao = ref<AuthUser | null>(null)
const editNome = ref('')
const editUsername = ref('')
const editRole = ref<UserRole>('OPERADOR')
const editNovaSenha = ref('')
const editPrimeiroAcesso = ref(false)

const totalAdmins = computed(() => usuarios.value.filter((u) => u.role === 'ADMIN').length)

function cargoLabel(role: UserRole) {
  return CARGOS.find((c) => c.id === role)?.label ?? role
}

function exibirMensagem(tipo: 'ok' | 'erro', msg: string) {
  if (tipo === 'ok') {
    sucesso.value = msg
    erro.value = null
  } else {
    erro.value = msg
    sucesso.value = null
  }
  setTimeout(() => {
    if (sucesso.value === msg) sucesso.value = null
  }, 4500)
}

async function carregarUsuarios() {
  if (userRole.value !== 'ADMIN') return
  carregando.value = true
  try {
    const lista = await usuariosApi.listar()
    if (lista.length) {
      usuarios.value = lista
    }
  } catch (e) {
    exibirMensagem('erro', (e as Error).message || 'Falha ao carregar usuários do banco MySQL.')
  } finally {
    carregando.value = false
  }
}

onMounted(() => {
  carregarUsuarios()
})

async function criarUsuario() {
  const nome = novoNome.value.trim()
  const username = novoUsername.value.trim().toLowerCase()
  const password = novaSenhaInicial.value.trim() || 'geonex123'

  if (!nome || !username) return
  if (password.length < 4) {
    exibirMensagem('erro', 'A senha inicial deve ter pelo menos 4 caracteres.')
    return
  }

  salvando.value = true
  try {
    const { usuario, mensagem } = await usuariosApi.criar({
      nome,
      username,
      role: novoCargo.value,
      password,
    })

    const idx = usuarios.value.findIndex((u) => u.id === usuario.id)
    if (idx >= 0) {
      usuarios.value[idx] = usuario
    } else {
      usuarios.value.push(usuario)
    }

    novoNome.value = ''
    novoUsername.value = ''
    novaSenhaInicial.value = ''
    novoCargo.value = 'OPERADOR'
    exibirMensagem('ok', mensagem)
  } catch (e) {
    exibirMensagem('erro', (e as Error).message || 'Erro ao criar usuário no banco.')
  } finally {
    salvando.value = false
  }
}

async function mudarCargo(u: AuthUser, role: UserRole) {
  if (u.role === 'ADMIN' && role !== 'ADMIN' && totalAdmins.value <= 1) {
    exibirMensagem('erro', 'É necessário manter ao menos um Administrador.')
    return
  }

  salvando.value = true
  try {
    const { usuario, mensagem } = await usuariosApi.atualizar({
      id: u.id,
      nome: u.nome,
      username: u.username,
      role,
    })
    const idx = usuarios.value.findIndex((item) => item.id === u.id)
    if (idx >= 0) {
      usuarios.value[idx] = usuario
    }
    if (u.id === meuId.value && currentUser.value) {
      setAuthSession({ ...currentUser.value, role: usuario.role, cargo: usuario.cargo }, true)
    }
    exibirMensagem('ok', mensagem)
  } catch (e) {
    exibirMensagem('erro', (e as Error).message || 'Erro ao atualizar cargo no banco.')
    await carregarUsuarios()
  } finally {
    salvando.value = false
  }
}

function abrirEdicao(u: AuthUser) {
  usuarioEmEdicao.value = u
  editNome.value = u.nome
  editUsername.value = u.username
  editRole.value = u.role
  editNovaSenha.value = ''
  editPrimeiroAcesso.value = Boolean(u.primeiro_acesso || u.primeiroAcesso)
  erro.value = null
}

function cancelarEdicao() {
  usuarioEmEdicao.value = null
  editNovaSenha.value = ''
}

async function salvarEdicaoUsuario() {
  const alvo = usuarioEmEdicao.value
  if (!alvo) return

  const nome = editNome.value.trim()
  const username = editUsername.value.trim().toLowerCase()
  const novaSenha = editNovaSenha.value.trim()

  if (!nome || !username) {
    exibirMensagem('erro', 'Nome e login são obrigatórios.')
    return
  }

  if (novaSenha && novaSenha.length < 4) {
    exibirMensagem('erro', 'A nova senha deve possuir ao menos 4 caracteres.')
    return
  }

  if (alvo.role === 'ADMIN' && editRole.value !== 'ADMIN' && totalAdmins.value <= 1) {
    exibirMensagem('erro', 'É necessário manter ao menos um Administrador.')
    return
  }

  salvando.value = true
  try {
    const { usuario, mensagem } = await usuariosApi.atualizar({
      id: alvo.id,
      nome,
      username,
      role: editRole.value,
      password: novaSenha || undefined,
      primeiro_acesso: editPrimeiroAcesso.value,
    })

    const idx = usuarios.value.findIndex((item) => item.id === alvo.id)
    if (idx >= 0) {
      usuarios.value[idx] = usuario
    }

    if (alvo.id === meuId.value && currentUser.value) {
      setAuthSession(
        {
          ...currentUser.value,
          nome: usuario.nome,
          username: usuario.username,
          role: usuario.role,
          cargo: usuario.cargo,
        },
        true,
      )
    }

    usuarioEmEdicao.value = null
    editNovaSenha.value = ''
    exibirMensagem('ok', mensagem)
  } catch (e) {
    exibirMensagem('erro', (e as Error).message || 'Erro ao salvar alterações do usuário.')
  } finally {
    salvando.value = false
  }
}

function podeRemover(u: AuthUser) {
  return u.id !== meuId.value && !(u.role === 'ADMIN' && totalAdmins.value <= 1)
}

async function removerUsuario(u: AuthUser) {
  if (!podeRemover(u)) return
  if (removendoId.value !== u.id) {
    removendoId.value = u.id // 1º clique: pede confirmação
    return
  }

  salvando.value = true
  try {
    const msg = await usuariosApi.remover(u.id)
    usuarios.value = usuarios.value.filter((x) => x.id !== u.id)
    if (usuarioEmEdicao.value?.id === u.id) {
      usuarioEmEdicao.value = null
    }
    exibirMensagem('ok', msg)
  } catch (e) {
    exibirMensagem('erro', (e as Error).message || 'Erro ao remover usuário.')
  } finally {
    removendoId.value = null
    salvando.value = false
  }
}
</script>

<template>
  <section class="min-h-0 flex-1 overflow-y-auto p-4">
    <div class="mx-auto flex w-full max-w-5xl flex-col gap-4">
      <!-- Modo de operação -->
      <div class="scada-card flex flex-col gap-5 p-6">
        <h2 class="scada-label">Modo de operação</h2>
        <p class="text-sm text-neutral-400">
          Alterna entre Modo Simulação e Modo Real (Backend PHP mysqli + Arduino Mega).
        </p>
        <div v-if="currentPermissions.canToggleSimulacao" class="flex items-center gap-3">
          <button
            type="button"
            class="flex items-center gap-2 rounded-full border px-4 py-2 text-xs font-semibold uppercase tracking-wider transition-all"
            :class="
              simulacao
                ? 'border-ore/50 bg-ore/15 text-ore shadow-[0_0_12px_-3px_rgba(217,119,6,0.45)]'
                : 'border-white/10 bg-panel-2 text-neutral-400 hover:border-ore/50 hover:text-white'
            "
            :aria-pressed="simulacao"
            title="Alternar Modo Simulação vs Modo Real (Backend PHP mysqli + Arduino Mega)"
            @click="emit('toggle-simulacao')"
          >
            <span class="size-1.5 rounded-full" :class="simulacao ? 'animate-pulse bg-ore' : 'bg-neutral-500'" />
            {{ simulacao ? 'Modo Simulação' : 'Modo Real' }}
          </button>
          <button
            v-if="simulacao"
            type="button"
            class="rounded-full border border-white/10 bg-panel-2 px-4 py-2 text-xs font-semibold text-neutral-400 transition-colors hover:border-ore/50 hover:text-white"
            title="Restaurar estado inicial da simulação"
            @click="emit('reset-simulacao')"
          >
            Reset
          </button>
        </div>
        <p v-else class="text-xs text-neutral-500">Acesso restrito ao Administrador.</p>
      </div>

      <!-- Gestão de Usuários (somente ADMIN) -->
      <div v-if="userRole === 'ADMIN'" class="scada-card flex flex-col gap-5 p-6">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 class="scada-label">Gestão de usuários</h2>
            <p class="mt-1 text-sm text-neutral-400">
              Crie contas (com troca de senha no 1º acesso), edite perfis, redefina senhas (Bcrypt) e remova acessos.
            </p>
          </div>
          <button
            type="button"
            class="rounded-xl border border-line bg-panel-2 px-3 py-1.5 text-xs text-mute hover:text-ink"
            :disabled="carregando"
            @click="carregarUsuarios"
          >
            {{ carregando ? 'Sincronizando...' : 'Recarregar Banco' }}
          </button>
        </div>

        <!-- Novo usuário -->
        <form
          class="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_140px_auto]"
          @submit.prevent="criarUsuario"
        >
          <label class="flex flex-col gap-1.5">
            <span class="scada-label">Nome</span>
            <input
              v-model="novoNome"
              required
              maxlength="60"
              autocomplete="off"
              class="scada-input user-invalid:border-rose-500"
              placeholder="Ex.: Ana Ribeiro"
            />
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="scada-label">Login</span>
            <input
              v-model="novoUsername"
              required
              minlength="3"
              maxlength="30"
              autocomplete="off"
              pattern="[A-Za-z0-9._\-]+"
              class="scada-input user-invalid:border-rose-500"
              placeholder="ana.ribeiro"
            />
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="scada-label">Senha Inicial (1º Acesso)</span>
            <input
              v-model="novaSenhaInicial"
              type="password"
              autocomplete="new-password"
              class="scada-input"
              placeholder="Padrão: geonex123"
            />
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="scada-label">Cargo</span>
            <select v-model="novoCargo" class="scada-input">
              <option v-for="c in CARGOS" :key="c.id" :value="c.id">{{ c.label }}</option>
            </select>
          </label>
          <button
            type="submit"
            :disabled="salvando"
            class="rounded-xl bg-ore px-5 py-2 text-xs font-bold text-abyss transition-opacity hover:opacity-90 active:scale-95 disabled:opacity-50"
          >
            {{ salvando ? 'Salvando...' : 'Criar usuário' }}
          </button>
        </form>

        <p
          v-if="erro"
          role="alert"
          class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-400"
        >
          {{ erro }}
        </p>
        <p
          v-if="sucesso"
          role="status"
          class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-xs text-emerald-400"
        >
          {{ sucesso }}
        </p>

        <!-- Painel de Edição de Usuário e Redefinição de Senha pelo Admin -->
        <form
          v-if="usuarioEmEdicao"
          class="flex flex-col gap-3 rounded-2xl border border-ore/40 bg-panel-2 p-4"
          @submit.prevent="salvarEdicaoUsuario"
        >
          <div class="flex items-center justify-between border-b border-line pb-2">
            <span class="scada-label text-ore">
              Editando Usuário #{{ usuarioEmEdicao.id }} — {{ usuarioEmEdicao.nome }}
            </span>
            <button
              type="button"
              class="text-xs text-mute hover:text-ink"
              @click="cancelarEdicao"
            >
              Fechar
            </button>
          </div>

          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="flex flex-col gap-1">
              <span class="scada-label">Nome Completo</span>
              <input v-model="editNome" required class="scada-input" />
            </label>
            <label class="flex flex-col gap-1">
              <span class="scada-label">Login (Username)</span>
              <input v-model="editUsername" required class="scada-input" />
            </label>
            <label class="flex flex-col gap-1">
              <span class="scada-label">Cargo / Perfil</span>
              <select v-model="editRole" class="scada-input">
                <option v-for="c in CARGOS" :key="c.id" :value="c.id">{{ c.label }}</option>
              </select>
            </label>
            <label class="flex flex-col gap-1">
              <span class="scada-label">Nova Senha (Bcrypt)</span>
              <input
                v-model="editNovaSenha"
                type="password"
                autocomplete="new-password"
                placeholder="Deixe vazio para manter atual"
                class="scada-input"
              />
            </label>
          </div>

          <div class="flex flex-wrap items-center justify-between gap-3 pt-1">
            <label class="flex items-center gap-2 text-xs text-mute cursor-pointer">
              <input v-model="editPrimeiroAcesso" type="checkbox" class="accent-ore" />
              <span>Exigir troca de senha no próximo login (<code class="text-ore">primeiro_acesso = true</code>)</span>
            </label>

            <div class="flex items-center gap-2">
              <button
                type="button"
                class="rounded-xl border border-line px-3.5 py-1.5 text-xs text-mute hover:text-ink"
                @click="cancelarEdicao"
              >
                Cancelar
              </button>
              <button
                type="submit"
                :disabled="salvando"
                class="rounded-xl bg-ore px-4 py-1.5 text-xs font-bold text-abyss hover:opacity-90 disabled:opacity-50"
              >
                {{ salvando ? 'Gravando...' : 'Salvar Alterações' }}
              </button>
            </div>
          </div>
        </form>

        <!-- Tabela -->
        <div tabindex="0" role="region" aria-label="Tabela de usuários" class="overflow-x-auto rounded-2xl border border-white/5">
          <table class="w-full min-w-[620px] border-collapse text-left text-xs">
            <caption class="sr-only">Usuários cadastrados</caption>
            <thead class="bg-panel-2 text-[10px] uppercase tracking-wider text-neutral-500">
              <tr>
                <th scope="col" class="px-4 py-2.5 font-semibold">Usuário</th>
                <th scope="col" class="px-4 py-2.5 font-semibold">Login</th>
                <th scope="col" class="px-4 py-2.5 font-semibold">Cargo</th>
                <th scope="col" class="px-4 py-2.5 text-right font-semibold">Ações</th>
              </tr>
            </thead>
            <tbody v-auto-animate class="divide-y divide-white/5">
              <tr v-for="u in usuarios" :key="u.id" class="transition-colors hover:bg-white/[0.02]">
                <td class="px-4 py-3">
                  <div class="font-semibold text-ink">{{ u.nome }}</div>
                  <div class="mt-1 flex flex-wrap items-center gap-1.5">
                    <span
                      class="inline-block rounded-full border px-2 py-px text-[9px] font-bold uppercase tracking-wider"
                      :class="CARGO_CLS[u.role]"
                    >
                      {{ cargoLabel(u.role) }}
                    </span>
                    <span
                      v-if="u.primeiro_acesso || u.primeiroAcesso"
                      class="inline-block rounded-full border border-ore/40 bg-ore/10 px-2 py-px text-[9px] font-semibold text-ore"
                    >
                      1º Acesso Pendente
                    </span>
                  </div>
                </td>
                <td class="px-4 py-3 font-mono text-neutral-400">{{ u.username }}</td>
                <td class="px-4 py-3">
                  <select
                    :value="u.role"
                    class="scada-input !w-36 !py-1.5"
                    :aria-label="`Cargo de ${u.nome}`"
                    @change="mudarCargo(u, ($event.target as HTMLSelectElement).value as UserRole)"
                  >
                    <option v-for="c in CARGOS" :key="c.id" :value="c.id">{{ c.label }}</option>
                  </select>
                </td>
                <td class="px-4 py-3 text-right">
                  <div class="inline-flex items-center justify-end gap-2">
                    <button
                      type="button"
                      class="rounded-full border border-white/10 px-3 py-1 text-[11px] font-semibold text-neutral-300 transition-colors hover:border-ore/60 hover:text-ore"
                      @click="abrirEdicao(u)"
                    >
                      Editar / Senha
                    </button>
                    <button
                      type="button"
                      :disabled="!podeRemover(u)"
                      class="rounded-full border px-3 py-1 text-[11px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-30"
                      :class="
                        removendoId === u.id
                          ? 'border-rose-500 bg-rose-500 text-white'
                          : 'border-white/10 text-neutral-400 hover:border-rose-500/50 hover:text-rose-500'
                      "
                      :title="u.id === meuId ? 'Você não pode remover a própria conta' : undefined"
                      @click="removerUsuario(u)"
                      @blur="removendoId = null"
                    >
                      {{ removendoId === u.id ? 'Confirmar?' : 'Remover' }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
</template>
