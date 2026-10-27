<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  USUARIOS_PADRAO,
  autenticarLocalmente,
  setAuthSession,
  type AuthUser,
  type UserRole,
} from '@/config/auth'
import { PHP_API_BASE_URL } from '@/services/api'

const router = useRouter()
const AUTH_ENDPOINT = '/api/auth/login'
const PHP_AUTH_ENDPOINT = `${PHP_API_BASE_URL}/login.php`

const form = reactive({
  username: '',
  password: '',
  remember: false,
})

const showPassword = ref(false)
const loading = ref(false)
const errorMessage = ref('')

function normalizarRespostaServidor(data: any): AuthUser | null {
  const roleStr = String(data?.role ?? '').toUpperCase() as UserRole
  if (roleStr !== 'ADMIN' && roleStr !== 'TECNICO' && roleStr !== 'OPERADOR') {
    return null
  }
  const base = USUARIOS_PADRAO[roleStr]
  return {
    id: typeof data?.id === 'number' ? data.id : base.id,
    username: String(data?.username ?? base.username),
    nome: String(data?.nome ?? base.nome),
    cargo: String(data?.cargo ?? base.cargo),
    role: roleStr,
  }
}

async function handleLogin() {
  errorMessage.value = ''
  const username = form.username.trim()
  const password = form.password.trim()

  if (!username || !password) {
    errorMessage.value = 'Preencha usuário e senha.'
    return
  }

  loading.value = true
  try {
    let usuarioAutenticado: AuthUser | null = null

    // 1. Valida credenciais no Backend PHP/MySQL (/login.php)
    try {
      const resPhp = await fetch(PHP_AUTH_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password }),
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

    // 3. Fallback de validação estrita das credenciais digitadas manualmente
    if (!usuarioAutenticado) {
      usuarioAutenticado = autenticarLocalmente(username, password)
    }

    if (usuarioAutenticado) {
      setAuthSession(usuarioAutenticado, form.remember)
      await router.replace({ name: 'dashboard' })
    } else {
      errorMessage.value = 'Usuário ou senha incorretos.'
    }
  } catch {
    errorMessage.value = 'Erro ao conectar com o servidor.'
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
        <div class="text-center mb-8 pt-2">
          <img
            src="/assets/geonex-full.svg"
            alt="GEONEX - Mining Lighting Control"
            class="h-12 mx-auto drop-shadow-[0_0_15px_rgba(255,107,0,0.4)]"
          />
        </div>

        <form @submit.prevent="handleLogin" class="space-y-5">
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
            <a href="#" @click.prevent class="hover:text-white transition-colors">Esqueci a senha</a>
          </div>

          <!-- Botão de Entrar (Laranja GEONEX com Sombra Neon Suave) -->
          <button
            type="submit"
            :disabled="loading"
            class="w-full h-12 mt-3 rounded-xl bg-gradient-to-b from-[#FF8533] to-[#E65100] hover:from-[#FF6B00] hover:to-[#CC4800] text-white font-bold tracking-wide shadow-[0_0_20px_rgba(255,107,0,0.35)] transition-all active:scale-[0.98] disabled:opacity-50"
          >
            {{ loading ? 'Autenticando...' : 'ACESSAR SISTEMA' }}
          </button>

          <!-- Mensagem de Erro -->
          <p v-if="errorMessage" class="text-center text-xs text-red-400 pt-2 font-medium">{{ errorMessage }}</p>
        </form>
      </div>
    </main>
  </div>
</template>
