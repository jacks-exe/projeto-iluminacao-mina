<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  USUARIOS_PADRAO,
  autenticarLocalmente,
  clearFirstAccessFlag,
  currentUser,
  getCurrentUser,
  logoutSession,
  mustChangePassword,
  setAuthSession,
  type AuthUser,
  type UserRole,
} from '@/config/auth'
import { PHP_API_BASE_URL } from '@/services/api'

const router = useRouter()
const route = useRoute()
const AUTH_ENDPOINT = '/api/auth/login'
const PHP_AUTH_ENDPOINT = `${PHP_API_BASE_URL}/login.php`

type ModoTelaAuth = 'LOGIN' | 'PRIMEIRO_ACESSO' | 'RECUPERAR'
const modoTela = ref<ModoTelaAuth>('LOGIN')

const form = reactive({
  username: '',
  password: '',
  remember: false,
})

/* Estado: Forçar Troca de Senha no Primeiro Acesso */
const formPrimeiroAcesso = reactive({
  novaSenha: '',
  confirmarSenha: '',
})

/* Estado: Esqueci a Senha (Solicitação e Confirmação via Token/Link Temporário) */
const formReset = reactive({
  identificador: '',
  token: '',
  novaSenha: '',
  confirmarSenha: '',
  tokenGerado: '',
  linkTemporario: '',
  etapaConfirmacao: false,
})

const showPassword = ref(false)
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

function sincronizarModoComRota() {
  const tokenQuery = typeof route.query.reset_token === 'string' ? route.query.reset_token : ''
  const userQuery = typeof route.query.user === 'string' ? route.query.user : ''

  if (tokenQuery) {
    modoTela.value = 'RECUPERAR'
    formReset.token = tokenQuery.toUpperCase()
    if (userQuery) formReset.identificador = userQuery
    formReset.etapaConfirmacao = true
    return
  }

  if (route.name === 'primeiro-acesso' || mustChangePassword()) {
    modoTela.value = 'PRIMEIRO_ACESSO'
  } else {
    modoTela.value = 'LOGIN'
  }
}

onMounted(sincronizarModoComRota)
watch(() => [route.name, route.query.reset_token], sincronizarModoComRota)

function normalizarRespostaServidor(data: any): AuthUser | null {
  const roleStr = String(data?.role ?? '').toUpperCase() as UserRole
  if (roleStr !== 'ADMIN' && roleStr !== 'TECNICO' && roleStr !== 'OPERADOR') {
    return null
  }
  const base = USUARIOS_PADRAO[roleStr]
  const flagPrimeiro = Boolean(data?.primeiro_acesso === true || data?.primeiroAcesso === true || Number(data?.primeiro_acesso) === 1)
  return {
    id: typeof data?.id === 'number' ? data.id : base.id,
    username: String(data?.username ?? base.username),
    nome: String(data?.nome ?? base.nome),
    email: data?.email ? String(data.email) : base.email,
    matricula: data?.matricula ? String(data.matricula) : base.matricula,
    cargo: String(data?.cargo ?? base.cargo),
    role: roleStr,
    primeiro_acesso: flagPrimeiro,
    primeiroAcesso: flagPrimeiro,
  }
}

async function handleLogin() {
  errorMessage.value = ''
  successMessage.value = ''
  const username = form.username.trim()
  const password = form.password.trim()

  if (!username || !password) {
    errorMessage.value = 'Preencha usuário e senha.'
    return
  }

  loading.value = true
  try {
    let usuarioAutenticado: AuthUser | null = null

    // 1. Valida credenciais prioritariamente no Backend PHP/MySQL (/login.php)
    try {
      const resPhp = await fetch(PHP_AUTH_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ acao: 'LOGIN', username, password }),
      })
      if (resPhp.ok) {
        const jsonPhp = await resPhp.json()
        usuarioAutenticado = normalizarRespostaServidor(jsonPhp)
      } else if (resPhp.status === 401) {
        errorMessage.value = 'Usuário ou senha incorretos.'
        return
      }
    } catch {
      /* Segue para tentativa no Spring Boot */
    }

    // 2. Valida credenciais no Backend Spring Boot (/api/auth/login)
    if (!usuarioAutenticado) {
      try {
        const response = await fetch(AUTH_ENDPOINT, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            username,
            password,
            remember: form.remember,
          }),
        })
        if (response.ok) {
          const json = await response.json()
          usuarioAutenticado = normalizarRespostaServidor(json)
        } else if (response.status === 401) {
          errorMessage.value = 'Usuário ou senha incorretos.'
          return
        }
      } catch {
        /* Fallback caso os servidores estejam inacessíveis */
      }
    }

    // 3. Fallback local caso servidores estejam offline
    if (!usuarioAutenticado) {
      usuarioAutenticado = autenticarLocalmente(username, password)
    }

    if (usuarioAutenticado) {
      setAuthSession(usuarioAutenticado, form.remember)
      if (usuarioAutenticado.primeiroAcesso || usuarioAutenticado.primeiro_acesso) {
        modoTela.value = 'PRIMEIRO_ACESSO'
        await router.replace({ name: 'primeiro-acesso' })
      } else {
        await router.replace({ name: 'dashboard' })
      }
    } else {
      errorMessage.value = 'Usuário ou senha incorretos.'
    }
  } catch {
    errorMessage.value = 'Erro ao conectar com o servidor.'
  } finally {
    loading.value = false
  }
}

async function handleDefinirNovaSenhaPrimeiroAcesso() {
  errorMessage.value = ''
  successMessage.value = ''

  const nova = formPrimeiroAcesso.novaSenha.trim()
  const conf = formPrimeiroAcesso.confirmarSenha.trim()

  if (nova.length < 4) {
    errorMessage.value = 'A nova senha deve possuir pelo menos 4 caracteres.'
    return
  }
  if (nova !== conf) {
    errorMessage.value = 'A confirmação de senha não confere.'
    return
  }

  const usr = currentUser.value ?? getCurrentUser()
  loading.value = true
  try {
    const res = await fetch(PHP_AUTH_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        acao: 'DEFINIR_NOVA_SENHA',
        usuario_id: usr.id,
        username: usr.username,
        nova_senha: nova,
      }),
    })
    const json = await res.json()
    if (!res.ok || !json?.sucesso) {
      errorMessage.value = json?.erro || 'Não foi possível salvar a nova senha.'
      return
    }

    clearFirstAccessFlag()
    formPrimeiroAcesso.novaSenha = ''
    formPrimeiroAcesso.confirmarSenha = ''
    await router.replace({ name: 'dashboard' })
  } catch {
    errorMessage.value = 'Erro ao comunicar com o servidor de autenticação.'
  } finally {
    loading.value = false
  }
}

function cancelarPrimeiroAcesso() {
  logoutSession()
  modoTela.value = 'LOGIN'
  router.replace({ name: 'login' })
}

function abrirEsqueciSenha() {
  errorMessage.value = ''
  successMessage.value = ''
  formReset.identificador = form.username.trim()
  formReset.token = ''
  formReset.novaSenha = ''
  formReset.confirmarSenha = ''
  formReset.tokenGerado = ''
  formReset.linkTemporario = ''
  formReset.etapaConfirmacao = false
  modoTela.value = 'RECUPERAR'
}

function voltarAoLogin() {
  errorMessage.value = ''
  successMessage.value = ''
  modoTela.value = 'LOGIN'
  if (route.query.reset_token) {
    router.replace({ name: 'login', query: {} })
  }
}

async function handleSolicitarTokenReset() {
  errorMessage.value = ''
  successMessage.value = ''

  const id = formReset.identificador.trim()
  if (!id) {
    errorMessage.value = 'Informe seu usuário, matrícula ou e-mail.'
    return
  }

  loading.value = true
  try {
    const res = await fetch(PHP_AUTH_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        acao: 'SOLICITAR_RESET',
        identificador: id,
      }),
    })
    const json = await res.json()
    if (!res.ok || !json?.sucesso) {
      errorMessage.value = json?.erro || 'Usuário não encontrado para recuperação.'
      return
    }

    formReset.tokenGerado = String(json.token_reset ?? '')
    formReset.linkTemporario = String(json.link_temporario ?? '')
    formReset.token = formReset.tokenGerado
    formReset.etapaConfirmacao = true
    successMessage.value = json.mensagem || 'Token de recuperação gerado com sucesso.'
  } catch {
    errorMessage.value = 'Falha ao conectar com o servidor para recuperação de senha.'
  } finally {
    loading.value = false
  }
}

async function handleConfirmarResetSenha() {
  errorMessage.value = ''
  successMessage.value = ''

  const token = formReset.token.trim().toUpperCase()
  const nova = formReset.novaSenha.trim()
  const conf = formReset.confirmarSenha.trim()

  if (!token) {
    errorMessage.value = 'Informe o token de recuperação.'
    return
  }
  if (nova.length < 4) {
    errorMessage.value = 'A nova senha deve possuir no mínimo 4 caracteres.'
    return
  }
  if (nova !== conf) {
    errorMessage.value = 'As senhas informadas não conferem.'
    return
  }

  loading.value = true
  try {
    const res = await fetch(PHP_AUTH_ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        acao: 'CONFIRMAR_RESET',
        identificador: formReset.identificador.trim(),
        token,
        nova_senha: nova,
      }),
    })
    const json = await res.json()
    if (!res.ok || !json?.sucesso) {
      errorMessage.value = json?.erro || 'Token inválido ou expirado.'
      return
    }

    form.username = json.username || formReset.identificador.trim()
    form.password = ''
    modoTela.value = 'LOGIN'
    if (route.query.reset_token) {
      await router.replace({ name: 'login', query: {} })
    }
    successMessage.value = json.mensagem || 'Senha redefinida com sucesso! Entre com sua nova senha.'
  } catch {
    errorMessage.value = 'Falha ao redefinir a senha no servidor.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="relative h-screen w-screen overflow-hidden bg-black font-sans text-white">
    <!-- Vídeo de Fundo 3D em Loop -->
    <video
      class="fixed inset-0 z-0 h-full w-full object-cover pointer-events-none"
      autoplay
      muted
      loop
      playsinline
    >
      <source src="/assets/animacao-login.mkv" type="video/x-matroska" />
    </video>

    <!-- Camada de Vinheta Escura para Dar Profundidade ao 3D -->
    <div class="fixed inset-0 z-[1] bg-gradient-to-t from-black/80 via-black/30 to-black/50 pointer-events-none"></div>

    <!-- Container Centralizado -->
    <main class="relative z-[2] grid h-screen place-items-center p-4">
      <!-- CARD INDUSTRIAL DARK GLASS (O VERDADEIRO VIDRO ESCURO FUMÊ) -->
      <div class="relative w-[400px] rounded-[28px] p-8 bg-zinc-950/60 backdrop-blur-2xl saturate-150 border border-white/10 border-t-white/30 shadow-[0_30px_60px_rgba(0,0,0,0.9)] overflow-hidden">
        <!-- Reflexo Especular Superior (Luz batendo no vidro) -->
        <div class="absolute inset-x-0 top-0 h-[1px] bg-gradient-to-r from-transparent via-white/50 to-transparent"></div>

        <!-- Logotipo GEONEX -->
        <div class="text-center mb-6 pt-2">
          <img
            src="/assets/geonex-full.svg"
            alt="GEONEX - Mining Lighting Control"
            class="h-20 mx-auto drop-shadow-[0_0_20px_rgba(255,107,0,0.45)] cursor-pointer select-none transition-transform hover:scale-105 active:scale-95"
            @click="$router.push('/')"
            title="Voltar para a página inicial"
          />
        </div>

        <!-- ================================================================
             MODO 1: TELA DE LOGIN PADRÃO
             ================================================================ -->
        <form v-if="modoTela === 'LOGIN'" @submit.prevent="handleLogin" class="space-y-5">
          <!-- Campo Usuário / ID -->
          <div class="space-y-1.5">
            <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">Usuário / ID</label>
            <input
              v-model="form.username"
              type="text"
              required
              autocomplete="username"
              placeholder="Digite seu usuário"
              class="w-full h-12 px-4 rounded-xl bg-black/70 border border-zinc-800 text-white placeholder-zinc-600 shadow-[inset_0_2px_6px_rgba(0,0,0,0.8)] outline-none transition-all duration-300 focus:border-[#FF6B00] focus:ring-1 focus:ring-[#FF6B00] focus:shadow-[0_0_15px_rgba(255,107,0,0.3),inset_0_2px_6px_rgba(0,0,0,0.8)]"
            />
          </div>

          <!-- Campo Senha -->
          <div class="space-y-1.5">
            <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">Senha</label>
            <div class="relative">
              <input
                v-model="form.password"
                :type="showPassword ? 'text' : 'password'"
                required
                autocomplete="current-password"
                placeholder="••••••••"
                class="w-full h-12 px-4 pr-12 rounded-xl bg-black/70 border border-zinc-800 text-white placeholder-zinc-600 shadow-[inset_0_2px_6px_rgba(0,0,0,0.8)] outline-none transition-all duration-300 focus:border-[#FF6B00] focus:ring-1 focus:ring-[#FF6B00] focus:shadow-[0_0_15px_rgba(255,107,0,0.3),inset_0_2px_6px_rgba(0,0,0,0.8)]"
              />
              <button
                type="button"
                @click="showPassword = !showPassword"
                class="absolute right-3.5 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-white transition-colors"
                title="Mostrar/Ocultar senha"
              >
                <svg v-if="!showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a10.05 10.05 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
              </button>
            </div>
          </div>

          <!-- Opções Secundárias -->
          <div class="flex items-center justify-between text-xs text-zinc-400 pt-1">
            <label class="flex items-center gap-2 cursor-pointer hover:text-zinc-200 select-none">
              <input v-model="form.remember" type="checkbox" class="rounded border-zinc-700 bg-black text-[#FF6B00] focus:ring-[#FF6B00]" />
              Lembrar-me
            </label>
            <button
              type="button"
              @click="abrirEsqueciSenha"
              class="font-semibold text-[#FF7A1A] hover:text-[#FF9447] transition-colors"
            >
              Esqueci a senha →
            </button>
          </div>

          <!-- Botão de Entrar -->
          <button
            type="submit"
            :disabled="loading"
            class="btn-primary mt-3 w-full"
          >
            <span>{{ loading ? 'Autenticando...' : 'Acessar Sistema' }}</span>
            <svg class="btn-arrow" fill="none" stroke="currentColor" stroke-width="2.25" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
            </svg>
          </button>

          <p v-if="errorMessage" class="text-center text-xs text-red-400 pt-2 font-medium">{{ errorMessage }}</p>
          <p v-if="successMessage" class="text-center text-xs text-emerald-400 pt-2 font-medium">{{ successMessage }}</p>
        </form>

        <!-- ================================================================
             MODO 2: DEFINIR NOVA SENHA OBRIGATÓRIA (PRIMEIRO ACESSO)
             ================================================================ -->
        <form v-else-if="modoTela === 'PRIMEIRO_ACESSO'" @submit.prevent="handleDefinirNovaSenhaPrimeiroAcesso" class="space-y-4">
          <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-200">
            <strong class="block uppercase tracking-wider text-[#FF8533]">Primeiro Acesso Detectado</strong>
            <span class="mt-1 block text-zinc-300">
              Olá, <strong>{{ currentUser?.nome }}</strong>. Defina uma nova senha pessoal para liberar o acesso ao sistema SCADA.
            </span>
          </div>

          <div class="space-y-1.5">
            <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">Nova Senha</label>
            <input
              v-model="formPrimeiroAcesso.novaSenha"
              :type="showPassword ? 'text' : 'password'"
              required
              minlength="4"
              autocomplete="new-password"
              placeholder="Digite sua nova senha"
              class="w-full h-11 px-4 rounded-xl bg-black/70 border border-zinc-800 text-white placeholder-zinc-600 outline-none transition-all focus:border-[#FF6B00] focus:ring-1 focus:ring-[#FF6B00]"
            />
          </div>

          <div class="space-y-1.5">
            <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">Confirmar Nova Senha</label>
            <input
              v-model="formPrimeiroAcesso.confirmarSenha"
              :type="showPassword ? 'text' : 'password'"
              required
              minlength="4"
              autocomplete="new-password"
              placeholder="Repita a nova senha"
              class="w-full h-11 px-4 rounded-xl bg-black/70 border border-zinc-800 text-white placeholder-zinc-600 outline-none transition-all focus:border-[#FF6B00] focus:ring-1 focus:ring-[#FF6B00]"
            />
          </div>

          <button
            type="submit"
            :disabled="loading"
            class="btn-primary w-full"
          >
            <span>{{ loading ? 'Salvando...' : 'Definir Nova Senha' }}</span>
            <svg class="btn-arrow" fill="none" stroke="currentColor" stroke-width="2.25" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
            </svg>
          </button>

          <button
            type="button"
            class="btn-secondary w-full !py-2.5 text-xs"
            @click="cancelarPrimeiroAcesso"
          >
            <span>Sair e voltar para o login</span>
            <svg class="btn-arrow" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
            </svg>
          </button>

          <p v-if="errorMessage" class="text-center text-xs text-red-400 pt-1 font-medium">{{ errorMessage }}</p>
        </form>

        <!-- ================================================================
             MODO 3: RECUPERAÇÃO DE SENHA (ESQUECI A SENHA)
             ================================================================ -->
        <div v-else class="space-y-4">
          <div class="flex items-center justify-between border-b border-white/10 pb-2">
            <span class="font-display text-xs font-bold uppercase tracking-wider text-[#FF8533]">
              Recuperação de Senha
            </span>
            <button
              type="button"
              class="text-xs font-semibold text-[#FF7A1A] hover:text-white transition-colors"
              @click="voltarAoLogin"
            >
              Voltar ao Login →
            </button>
          </div>

          <!-- Etapa 1: Gerar Token / Link Temporário -->
          <form v-if="!formReset.etapaConfirmacao" @submit.prevent="handleSolicitarTokenReset" class="space-y-4">
            <p class="text-xs text-zinc-400">
              Informe seu login, matrícula ou e-mail para gerar um token/link temporário de redefinição.
            </p>

            <div class="space-y-1.5">
              <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">
                Usuário / Matrícula / E-mail
              </label>
              <input
                v-model="formReset.identificador"
                type="text"
                required
                placeholder="Ex.: adm, operador, ADM-001"
                class="w-full h-11 px-4 rounded-xl bg-black/70 border border-zinc-800 text-white placeholder-zinc-600 outline-none transition-all focus:border-[#FF6B00] focus:ring-1 focus:ring-[#FF6B00]"
              />
            </div>

            <button
              type="submit"
              :disabled="loading"
              class="btn-primary w-full"
            >
              <span>{{ loading ? 'Gerando...' : 'Gerar Token de Recuperação' }}</span>
              <svg class="btn-arrow" fill="none" stroke="currentColor" stroke-width="2.25" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
              </svg>
            </button>
          </form>

          <!-- Etapa 2: Informar Token e Nova Senha -->
          <form v-else @submit.prevent="handleConfirmarResetSenha" class="space-y-3.5">
            <div
              v-if="formReset.tokenGerado"
              class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-2.5 text-[11px] text-emerald-300"
            >
              <div>Token temporário gerado: <strong class="font-mono text-white">{{ formReset.tokenGerado }}</strong></div>
              <a
                v-if="formReset.linkTemporario"
                :href="formReset.linkTemporario"
                class="mt-1 inline-block underline text-amber-300 hover:text-white"
                @click.prevent="formReset.token = formReset.tokenGerado"
              >
                Link temporário: {{ formReset.linkTemporario }}
              </a>
            </div>

            <div class="space-y-1">
              <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">Token de Reset</label>
              <input
                v-model="formReset.token"
                type="text"
                required
                placeholder="Código de 6 dígitos"
                class="w-full h-10 px-3 rounded-xl bg-black/70 border border-zinc-800 font-mono uppercase text-white outline-none focus:border-[#FF6B00]"
              />
            </div>

            <div class="space-y-1">
              <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">Nova Senha</label>
              <input
                v-model="formReset.novaSenha"
                :type="showPassword ? 'text' : 'password'"
                required
                minlength="4"
                placeholder="Digite a nova senha"
                class="w-full h-10 px-3 rounded-xl bg-black/70 border border-zinc-800 text-white outline-none focus:border-[#FF6B00]"
              />
            </div>

            <div class="space-y-1">
              <label class="text-[11px] font-bold uppercase tracking-wider text-zinc-400">Confirmar Nova Senha</label>
              <input
                v-model="formReset.confirmarSenha"
                :type="showPassword ? 'text' : 'password'"
                required
                minlength="4"
                placeholder="Confirme a nova senha"
                class="w-full h-10 px-3 rounded-xl bg-black/70 border border-zinc-800 text-white outline-none focus:border-[#FF6B00]"
              />
            </div>

            <button
              type="submit"
              :disabled="loading"
              class="btn-primary w-full"
            >
              <span>{{ loading ? 'Salvando...' : 'Confirmar Nova Senha' }}</span>
              <svg class="btn-arrow" fill="none" stroke="currentColor" stroke-width="2.25" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
              </svg>
            </button>
          </form>

          <p v-if="errorMessage" class="text-center text-xs text-red-400 pt-1 font-medium">{{ errorMessage }}</p>
          <p v-if="successMessage" class="text-center text-xs text-emerald-400 pt-1 font-medium">{{ successMessage }}</p>
        </div>
      </div>
    </main>
  </div>
</template>
