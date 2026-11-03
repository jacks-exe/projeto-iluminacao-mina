<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useRouter } from 'vue-router'
import LandingDigitalTwin from '@/components/dashboard/LandingDigitalTwin.vue'
import { isAuthenticated, mustChangePassword } from '@/config/auth'

const router = useRouter()

/* Usuário já logado ignora a landing page e vai direto ao Dashboard
   (ou ao primeiro acesso, caso ainda precise trocar a senha). */
if (isAuthenticated()) {
  router.replace({ name: mustChangePassword() ? 'primeiro-acesso' : 'dashboard' })
}

/* Links centrais: atualizam a rota (hash) via Vue Router e rolam até a seção. */
async function irParaSecao(id: 'blog' | 'integrantes') {
  await router.push({ path: '/', hash: `#${id}` })
  await nextTick()
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

/* Camadas vetoriais da malha topográfica 3D (curvas de nível / cava de mineração) */
const camadasTopograficas = Array.from({ length: 18 }, (_, i) => {
  const escala = 1 - i * 0.042
  const rotacao = i * 2.6 - 18
  const opacidade = Number((0.14 + (1 - Math.abs(i - 8) / 10) * 0.34).toFixed(2))
  /* Fator de profundidade para parallax em múltiplas camadas (anéis externos movem mais que o núcleo) */
  const fatorParallax = Number(((1 - i / 18) * 1.15 + 0.25).toFixed(2))
  const delayAnimacao = `${(i * -0.55).toFixed(2)}s`
  return { id: i, escala, rotacao, opacidade, fatorParallax, delayAnimacao }
})

/* Interatividade Parallax e Brilho Reativo na Malha Hexagonal/Topográfica da Hero Section */
const heroSectionRef = ref<HTMLElement | null>(null)
const heroSvgRef = ref<SVGSVGElement | null>(null)
const heroParallaxX = ref(0)
const heroParallaxY = ref(0)
const heroCursorSvgX = ref(360)
const heroCursorSvgY = ref(340)
const heroCursorActive = ref(false)

function onHeroMouseMove(e: MouseEvent) {
  const section = heroSectionRef.value
  if (!section) return
  const rect = section.getBoundingClientRect()
  const normX = ((e.clientX - rect.left) / rect.width - 0.5) * 2
  const normY = ((e.clientY - rect.top) / rect.height - 0.5) * 2

  /* Movimento parallax suave (acompanhamento / profundidade) */
  heroParallaxX.value = Number((normX * 22).toFixed(2))
  heroParallaxY.value = Number((normY * 16).toFixed(2))

  /* Coordenadas do cursor mapeadas para o viewBox (800x680) do SVG para iluminar a borda dos hexágonos próximos */
  const svgEl = heroSvgRef.value
  if (svgEl) {
    const svgRect = svgEl.getBoundingClientRect()
    if (svgRect.width > 0 && svgRect.height > 0) {
      heroCursorSvgX.value = Math.round(((e.clientX - svgRect.left) / svgRect.width) * 800)
      heroCursorSvgY.value = Math.round(((e.clientY - svgRect.top) / svgRect.height) * 680)
      heroCursorActive.value = true
    }
  }
}

function onHeroMouseLeave() {
  heroParallaxX.value = 0
  heroParallaxY.value = 0
  heroCursorActive.value = false
}

/* Efeito Spotlight / Glow Tracking nos Cards de Módulos */
const modulosGridRef = ref<HTMLElement | null>(null)

function onModulosMouseMove(e: MouseEvent) {
  const grid = modulosGridRef.value
  if (!grid) return
  const cards = grid.querySelectorAll<HTMLElement>('.spotlight-card')
  cards.forEach((card) => {
    const rect = card.getBoundingClientRect()
    const x = e.clientX - rect.left
    const y = e.clientY - rect.top
    card.style.setProperty('--mouse-x', `${x}px`)
    card.style.setProperty('--mouse-y', `${y}px`)
  })
}

const modulos = [
  {
    codigo: 'MOD-01 · PWM',
    titulo: 'Controle de Iluminação',
    texto: 'Comando de brilho PWM (0–255), modos Eco/Normal/Cerração e acionamento em cascata por torre.',
    icone: 'raio',
    accent: 'laranja',
    spotlightRgb: '255, 107, 0',
  },
  {
    codigo: 'MOD-02 · TWIN',
    titulo: 'Gêmeo Digital',
    texto: 'Visualização interativa em tempo real do estado operacional e luminosidade de cada torre da mina.',
    icone: 'cubo',
    accent: 'verde',
    spotlightRgb: '52, 211, 153',
  },
  {
    codigo: 'MOD-03 · MANUT',
    titulo: 'Ordens de Serviço',
    texto: 'Abertura de chamados, bloqueio de segurança remoto, atendimento em campo e laudo técnico.',
    icone: 'prancheta',
    accent: 'laranja',
    spotlightRgb: '255, 107, 0',
  },
  {
    codigo: 'MOD-04 · DATA',
    titulo: 'Analytics',
    texto: 'Curva histórica de consumo em Watts, eficiência energética, auditoria PWM e exportação CSV.',
    icone: 'grafico',
    accent: 'verde',
    spotlightRgb: '52, 211, 153',
  },
] as const

/* Dados de exemplo — substituir pelos integrantes reais da equipe */
const integrantes = [
  { codigo: 'ENG-01', nome: 'Integrante 1', funcao: 'Hardware Embarcado & IoT' },
  { codigo: 'ENG-02', nome: 'Integrante 2', funcao: 'Arquitetura Backend & Banco de Dados' },
  { codigo: 'ENG-03', nome: 'Integrante 3', funcao: 'Interface SCADA & Gêmeo Digital' },
]
</script>

<template>
  <div class="relative min-h-screen overflow-x-hidden bg-[#07080C] font-sans text-white selection:bg-[#FF6B00]/40 selection:text-white">
    <!-- ================= ILUMINAÇÃO AMBIENTE E MALHA TOPOGRÁFICA 3D ================= -->
    <!-- Feixes atmosféricos sutis (verde e laranja GEONEX) para refração nos cards Glassmorphism -->
    <div
      aria-hidden="true"
      class="pointer-events-none fixed inset-0 z-0 overflow-hidden"
    >
      <div class="absolute -left-40 top-12 h-[520px] w-[520px] rounded-full bg-ok/[0.07] blur-[130px]" />
      <div class="absolute left-1/3 top-28 h-[460px] w-[580px] rounded-full bg-[#FF6B00]/[0.07] blur-[140px]" />
      <div class="absolute -right-32 bottom-20 h-[480px] w-[480px] rounded-full bg-ok/[0.05] blur-[140px]" />
    </div>

    <!-- ================= NAVBAR GLASSMORPHISM ================= -->
    <header class="sticky top-0 z-30 border-b border-white/10 bg-[#07080C]/65 backdrop-blur-xl">
      <nav class="mx-auto grid h-20 max-w-7xl grid-cols-[1fr_auto_1fr] items-center px-6">
        <!-- Logo (esquerda) -->
        <button
          type="button"
          class="flex items-center justify-self-start transition-transform hover:scale-105 active:scale-95"
          @click="$router.push('/')"
          title="GEONEX - Início"
        >
          <img
            src="/assets/geonex-full.svg"
            alt="GEONEX - Mining Lighting Control"
            class="h-12 w-auto select-none object-contain"
          />
        </button>

        <!-- Links centrais em cápsula de vidro -->
        <div class="flex items-center gap-1 rounded-full border border-white/10 bg-white/[0.04] p-1 backdrop-blur-md shadow-[inset_0_1px_0_rgba(255,255,255,0.08)]">
          <button
            type="button"
            class="rounded-full px-5 py-2 font-display text-xs font-bold uppercase tracking-wider text-neutral-300 transition-all duration-200 hover:bg-white/10 hover:text-white active:scale-95"
            @click="irParaSecao('blog')"
          >
            Blog
          </button>
          <button
            type="button"
            class="rounded-full px-5 py-2 font-display text-xs font-bold uppercase tracking-wider text-neutral-300 transition-all duration-200 hover:bg-white/10 hover:text-white active:scale-95"
            @click="irParaSecao('integrantes')"
          >
            Integrantes
          </button>
        </div>

        <!-- Botão Login (Estilo Outline de Alto Contraste) -->
        <button
          type="button"
          class="group inline-flex items-center gap-2.5 justify-self-end rounded-full border border-white/25 bg-white/[0.03] px-6 py-2.5 font-sans text-xs font-bold uppercase tracking-wider text-white backdrop-blur-md transition-all duration-200 hover:border-white/60 hover:bg-white/10 active:scale-95"
          @click="$router.push('/login')"
        >
          <span>Login</span>
          <svg class="size-4 transition-transform duration-200 group-hover:translate-x-1" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
          </svg>
        </button>
      </nav>
    </header>

    <!-- ================= HERO SECTION ================= -->
    <section
      ref="heroSectionRef"
      class="relative z-10 mx-auto grid max-w-7xl items-center gap-10 px-6 py-16 lg:grid-cols-2 lg:py-24"
      @mousemove="onHeroMouseMove"
      @mouseleave="onHeroMouseLeave"
    >
      <!-- Padrão Vetorial Topográfico / Malha 3D de Terreno atrás do Hero (Esfumado nas extremidades, com Animação Idle e Parallax) -->
      <div
        aria-hidden="true"
        class="hero-mesh-mask pointer-events-none absolute -left-24 top-1/2 -z-10 h-[680px] w-[820px] -translate-y-1/2 opacity-90"
      >
        <svg
          ref="heroSvgRef"
          viewBox="0 0 800 680"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
          class="h-full w-full"
        >
          <defs>
            <linearGradient id="geonexMeshGrad" x1="0%" y1="0%" x2="100%" y2="100%">
              <stop offset="0%" stop-color="#34D399" stop-opacity="0.85" />
              <stop offset="48%" stop-color="#10B981" stop-opacity="0.45" />
              <stop offset="100%" stop-color="#FF6B00" stop-opacity="0.85" />
            </linearGradient>
            <linearGradient id="geonexTopoLines" x1="100%" y1="0%" x2="0%" y2="100%">
              <stop offset="0%" stop-color="#FF6B00" stop-opacity="0.6" />
              <stop offset="60%" stop-color="#34D399" stop-opacity="0.38" />
              <stop offset="100%" stop-color="#34D399" stop-opacity="0" />
            </linearGradient>
            <!-- Gradiente radial dinâmico que acompanha o mouse para iluminar a borda dos hexágonos em laranja/verde -->
            <radialGradient
              id="geonexCursorRimGlow"
              gradientUnits="userSpaceOnUse"
              :cx="heroCursorSvgX"
              :cy="heroCursorSvgY"
              r="230"
            >
              <stop offset="0%" stop-color="#FF6B00" stop-opacity="0.95" />
              <stop offset="42%" stop-color="#34D399" stop-opacity="0.65" />
              <stop offset="78%" stop-color="#10B981" stop-opacity="0.18" />
              <stop offset="100%" stop-color="#10B981" stop-opacity="0" />
            </radialGradient>
            <filter id="geonexHexGlowFilter" x="-20%" y="-20%" width="140%" height="140%">
              <feGaussianBlur stdDeviation="2.4" result="blur" />
              <feMerge>
                <feMergeNode in="blur" />
                <feMergeNode in="SourceGraphic" />
              </feMerge>
            </filter>
          </defs>

          <!-- Malha geométrica rotacionada em camadas (efeito túnel / cava topográfica 3D + Parallax + Rotação Lenta Idle) -->
          <g
            class="hero-parallax-layer"
            :style="{
              transform: `translate3d(${360 + heroParallaxX}px, ${340 + heroParallaxY}px, 0)`,
            }"
          >
            <g class="hero-hex-idle-spin">
              <polygon
                v-for="camada in camadasTopograficas"
                :key="'base-hex-' + camada.id"
                points="-260,-180 -60,-250 210,-170 280,40 160,220 -110,240 -270,80"
                stroke="url(#geonexMeshGrad)"
                stroke-width="1.15"
                class="hero-hex-pulse"
                :style="{
                  '--base-opacity': camada.opacidade,
                  animationDelay: camada.delayAnimacao,
                }"
                :transform="`translate(${(heroParallaxX * (camada.fatorParallax - 0.65)).toFixed(1)}, ${(heroParallaxY * (camada.fatorParallax - 0.65)).toFixed(1)}) scale(${camada.escala}) rotate(${camada.rotacao})`"
              />

              <!-- Sobreposição reativa das bordas dos hexágonos: acende suavemente em laranja/verde próximo ao cursor -->
              <g
                filter="url(#geonexHexGlowFilter)"
                class="transition-opacity duration-300"
                :style="{ opacity: heroCursorActive ? 0.95 : 0 }"
              >
                <polygon
                  v-for="camada in camadasTopograficas"
                  :key="'glow-hex-' + camada.id"
                  points="-260,-180 -60,-250 210,-170 280,40 160,220 -110,240 -270,80"
                  stroke="url(#geonexCursorRimGlow)"
                  stroke-width="1.75"
                  :transform="`translate(${(heroParallaxX * (camada.fatorParallax - 0.65)).toFixed(1)}, ${(heroParallaxY * (camada.fatorParallax - 0.65)).toFixed(1)}) scale(${camada.escala}) rotate(${camada.rotacao})`"
                />
              </g>
            </g>
          </g>

          <!-- Curvas de nível topográficas secundárias com Parallax inverso e fluxo contínuo -->
          <g
            class="hero-parallax-layer"
            :style="{
              transform: `translate3d(${(-heroParallaxX * 0.65).toFixed(2)}px, ${(-heroParallaxY * 0.65).toFixed(2)}px, 0)`,
            }"
          >
            <path
              d="M 40 520 C 180 410, 310 580, 480 430 C 610 310, 690 380, 770 240"
              stroke="url(#geonexTopoLines)"
              stroke-width="1.15"
              stroke-dasharray="5 5"
              class="hero-topo-dash"
            />
            <path
              d="M 20 580 C 190 470, 340 620, 520 470 C 640 360, 710 420, 790 290"
              stroke="url(#geonexTopoLines)"
              stroke-width="1.1"
              class="hero-topo-float"
            />
          </g>
        </svg>
      </div>

      <!-- Coluna de texto -->
      <div class="relative space-y-7">
        <!-- Tag Superior Glassmorphism com LED Verde Pulsante -->
        <div
          class="inline-flex items-center gap-3 rounded-full border border-white/10 bg-white/[0.04] px-4 py-1.5 backdrop-blur-md shadow-[inset_0_1px_0_rgba(255,255,255,0.1)]"
        >
          <span class="relative flex size-2.5" aria-hidden="true">
            <span class="absolute inline-flex size-full animate-ping rounded-full bg-ok opacity-75" />
            <span class="relative inline-flex size-2.5 rounded-full bg-ok shadow-[0_0_8px_#34d399]" />
          </span>
          <span class="font-display text-[11px] font-bold uppercase tracking-[0.2em] text-neutral-200">
            SCADA <span class="text-[#FF6B00]">·</span> IoT <span class="text-[#FF6B00]">·</span> MINERAÇÃO
          </span>
        </div>

        <!-- Título Principal de Impacto Massivo (Peso Black / Alto Contraste) -->
        <h1 class="font-display text-4xl font-black uppercase leading-[1.04] tracking-[-0.03em] text-white drop-shadow-[0_10px_30px_rgba(0,0,0,0.8)] sm:text-5xl lg:text-[3.6rem]">
          Controle <span class="text-[#FF6B00]">SCADA</span> e Iluminação Inteligente para
          <span class="text-[#FF6B00]">Mineração</span>
        </h1>

        <!-- Subtítulo Breve -->
        <p class="max-w-xl font-sans text-base leading-relaxed text-neutral-300/90 sm:text-lg">
          Integramos hardware embarcado <strong class="font-display font-bold text-white">IoT</strong> nas torres de iluminação a um software de supervisão em tempo real,
          permitindo operar, monitorar e manter toda a planta da mina em um único painel.
        </p>

        <!-- Botões de Alto Contraste (Branco Sólido Primário + Outline Secundário) -->
        <div class="flex flex-wrap items-center gap-4 pt-2">
          <button
            type="button"
            class="group inline-flex items-center justify-center gap-3 rounded-full bg-white px-8 py-4 font-sans text-sm font-extrabold uppercase tracking-wider text-[#07080C] shadow-[0_12px_32px_rgba(255,255,255,0.14)] transition-all duration-200 hover:bg-neutral-200 active:scale-[0.98] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
            @click="$router.push('/login')"
          >
            <span>Acessar Sistema</span>
            <svg class="size-4 transition-transform duration-200 group-hover:translate-x-1" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
            </svg>
          </button>

          <button
            type="button"
            class="group inline-flex items-center justify-center gap-3 rounded-full border border-white/25 bg-white/[0.03] px-8 py-4 font-sans text-sm font-bold uppercase tracking-wider text-white backdrop-blur-md transition-all duration-200 hover:border-white/60 hover:bg-white/[0.08] active:scale-[0.98] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white/50"
            @click="irParaSecao('blog')"
          >
            <span>Explorar Módulos</span>
            <svg class="size-4 transition-transform duration-200 group-hover:translate-x-1" fill="none" stroke="currentColor" stroke-width="2.25" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Coluna da Maquete 3D Flutuante e Interativa (Sem borda, sem quadrado, mesclada ao fundo) -->
      <div class="flex h-full w-full min-h-[360px] items-center justify-center bg-transparent lg:justify-end">
        <LandingDigitalTwin />
      </div>
    </section>

    <!-- ================= BLOG (MÓDULOS DO SISTEMA COM EFEITO SPOTLIGHT / GLOW TRACKING) ================= -->
    <section id="blog" class="relative z-10 mx-auto max-w-7xl scroll-mt-24 px-6 py-20">
      <div class="mb-10 flex flex-col gap-2">
        <span class="font-mono text-xs font-bold uppercase tracking-[0.2em] text-[#FF6B00]">
          // Arquitetura Operacional
        </span>
        <h2 class="font-display text-3xl font-black uppercase tracking-tight text-white sm:text-4xl">
          Módulos do <span class="text-[#FF6B00]">Sistema</span>
        </h2>
        <p class="max-w-2xl text-sm text-neutral-400">
          Conheça os subsistemas integrados que conectam o controle físico das torres de iluminação à supervisão central.
        </p>
      </div>

      <!-- Container com captura de @mousemove para o efeito Spotlight dinâmico -->
      <div
        ref="modulosGridRef"
        class="spotlight-grid grid gap-5 sm:grid-cols-2 lg:grid-cols-4"
        @mousemove="onModulosMouseMove"
      >
        <article
          v-for="m in modulos"
          :key="m.titulo"
          class="spotlight-card group relative flex flex-col justify-between overflow-hidden rounded-[24px] border border-white/10 bg-white/[0.03] p-6 backdrop-blur-md shadow-[inset_0_1px_0_rgba(255,255,255,0.08),0_20px_40px_rgba(0,0,0,0.45)] transition-all duration-300 hover:-translate-y-1"
          :style="{ '--spotlight-rgb': m.spotlightRgb }"
          :class="
            m.accent === 'laranja'
              ? 'hover:border-[#FF6B00]/50'
              : 'hover:border-ok/50'
          "
        >
          <!-- Conteúdo posicionado acima do brilho radial (z-10) -->
          <div class="relative z-10">
            <!-- Topo do Card: Ícone SVG + Código Técnico -->
            <div class="mb-5 flex items-center justify-between">
              <div
                class="flex size-12 items-center justify-center rounded-2xl border backdrop-blur-md transition-colors duration-300"
                :class="
                  m.accent === 'laranja'
                    ? 'border-[#FF6B00]/30 bg-[#FF6B00]/10 text-[#FF6B00] group-hover:border-[#FF6B00]/60 group-hover:bg-[#FF6B00]/20'
                    : 'border-ok/30 bg-ok/10 text-ok group-hover:border-ok/60 group-hover:bg-ok/20'
                "
              >
                <!-- Ícone 1: Raio / Controle PWM -->
                <svg
                  v-if="m.icone === 'raio'"
                  class="size-6"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z" />
                </svg>

                <!-- Ícone 2: Cubo 3D / Gêmeo Digital -->
                <svg
                  v-else-if="m.icone === 'cubo'"
                  class="size-6"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" d="m21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                </svg>

                <!-- Ícone 3: Prancheta / Ordens de Serviço -->
                <svg
                  v-else-if="m.icone === 'prancheta'"
                  class="size-6"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z" />
                </svg>

                <!-- Ícone 4: Gráfico / Analytics -->
                <svg
                  v-else
                  class="size-6"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.8"
                  viewBox="0 0 24 24"
                  aria-hidden="true"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                </svg>
              </div>

              <span class="rounded-full border border-white/10 bg-white/[0.04] px-3 py-1 font-mono text-[10px] font-bold tracking-wider text-neutral-300">
                {{ m.codigo }}
              </span>
            </div>

            <h3 class="mb-2 font-display text-lg font-extrabold text-white transition-colors group-hover:text-[#FF7A1A]">
              {{ m.titulo }}
            </h3>
            <p class="text-xs leading-relaxed text-neutral-400">
              {{ m.texto }}
            </p>
          </div>

          <div class="relative z-10 mt-6 flex items-center justify-between border-t border-white/10 pt-3 font-mono text-[11px] text-neutral-400">
            <span>Status do Módulo</span>
            <span class="inline-flex items-center gap-1.5 font-semibold text-ok">
              <span class="size-1.5 rounded-full bg-ok shadow-[0_0_6px_#34d399]" />
              Ativo
            </span>
          </div>
        </article>
      </div>
    </section>

    <!-- ================= INTEGRANTES (GLASSMORPHISM) ================= -->
    <section id="integrantes" class="relative z-10 mx-auto max-w-7xl scroll-mt-24 px-6 pb-24 pt-12">
      <div class="mb-10 flex flex-col gap-2">
        <span class="font-mono text-xs font-bold uppercase tracking-[0.2em] text-ok">
          // Equipe de Desenvolvimento
        </span>
        <h2 class="font-display text-3xl font-black uppercase tracking-tight text-white sm:text-4xl">
          Integrantes do <span class="text-[#FF6B00]">Projeto</span>
        </h2>
        <p class="max-w-2xl text-sm text-neutral-400">
          Engenharia e desenvolvimento responsáveis pela concepção do sistema GEONEX SCADA.
        </p>
      </div>

      <div class="grid gap-5 sm:grid-cols-3">
        <article
          v-for="p in integrantes"
          :key="p.nome"
          class="group relative flex flex-col items-center rounded-[24px] border border-white/10 bg-white/[0.03] p-7 text-center backdrop-blur-md shadow-[inset_0_1px_0_rgba(255,255,255,0.08),0_20px_40px_rgba(0,0,0,0.45)] transition-all duration-300 hover:-translate-y-1 hover:border-[#FF6B00]/55 hover:bg-white/[0.06]"
        >
          <!-- Reflexo especular superior no hover -->
          <div class="pointer-events-none absolute inset-x-8 top-0 h-px bg-gradient-to-r from-transparent via-white/40 to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100" />

          <!-- Avatar Técnico em Vidro com Ícone SVG -->
          <div class="relative mb-4 flex size-16 items-center justify-center rounded-2xl border border-white/10 bg-white/[0.04] text-neutral-200 backdrop-blur-md transition-colors duration-300 group-hover:border-[#FF6B00]/50 group-hover:text-[#FF6B00]">
            <svg class="size-7" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
            </svg>
            <span class="absolute -bottom-1 -right-1 flex size-4 items-center justify-center rounded-full border-2 border-[#07080C] bg-ok shadow-[0_0_8px_#34d399]" title="Membro Ativo" />
          </div>

          <span class="mb-2 rounded-full border border-white/10 bg-white/[0.04] px-3 py-0.5 font-mono text-[10px] font-bold tracking-wider text-neutral-300">
            {{ p.codigo }}
          </span>
          <h3 class="font-display text-lg font-extrabold text-white transition-colors group-hover:text-[#FF7A1A]">
            {{ p.nome }}
          </h3>
          <p class="mt-1 text-xs text-neutral-400">
            {{ p.funcao }}
          </p>
        </article>
      </div>
    </section>
  </div>
</template>

<style scoped>
/* Máscara radial que esfuma suavemente as extremidades da malha topográfica 3D */
.hero-mesh-mask {
  -webkit-mask-image: radial-gradient(circle at 50% 50%, rgba(0, 0, 0, 1) 25%, rgba(0, 0, 0, 0) 72%);
  mask-image: radial-gradient(circle at 50% 50%, rgba(0, 0, 0, 1) 25%, rgba(0, 0, 0, 0) 72%);
}

/* Transição física suave para a resposta Parallax ao mover o mouse na Hero Section */
.hero-parallax-layer {
  transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1);
  will-change: transform;
}

/* Animação Idle contínua e sutil: rotação lenta + flutuação orgânica dos hexágonos */
.hero-hex-idle-spin {
  animation: heroHexSlowRotate 46s ease-in-out infinite alternate;
  transform-origin: 0px 0px;
}

/* Pulso sutil de opacidade defasado entre cada anel hexagonal da cava */
.hero-hex-pulse {
  stroke-opacity: var(--base-opacity, 0.25);
  animation: heroHexOpacityPulse 7.5s ease-in-out infinite alternate;
}

/* Fluxo suave nas curvas topográficas tracejadas */
.hero-topo-dash {
  animation: heroTopoDashFlow 22s linear infinite;
}

.hero-topo-float {
  animation: heroTopoSubtleFloat 9s ease-in-out infinite alternate;
}

@keyframes heroHexSlowRotate {
  0% {
    transform: rotate(-3.5deg) scale(0.985);
  }
  50% {
    transform: rotate(2.5deg) scale(1.015);
  }
  100% {
    transform: rotate(5.5deg) scale(0.995);
  }
}

@keyframes heroHexOpacityPulse {
  0% {
    stroke-opacity: calc(var(--base-opacity, 0.25) * 0.78);
  }
  100% {
    stroke-opacity: min(0.92, calc(var(--base-opacity, 0.25) * 1.45));
  }
}

@keyframes heroTopoDashFlow {
  0% {
    stroke-dashoffset: 0;
  }
  100% {
    stroke-dashoffset: -120;
  }
}

@keyframes heroTopoSubtleFloat {
  0% {
    opacity: 0.55;
    transform: translateY(-3px);
  }
  100% {
    opacity: 0.95;
    transform: translateY(4px);
  }
}

/* Efeito Spotlight / Glow Tracking nos Cards de Módulos */
.spotlight-card::before {
  content: '';
  pointer-events: none;
  position: absolute;
  inset: 0;
  z-index: 1;
  border-radius: inherit;
  opacity: 0;
  transition: opacity 0.35s ease;
  background: radial-gradient(
    360px circle at var(--mouse-x, -500px) var(--mouse-y, -500px),
    rgba(var(--spotlight-rgb, 255, 107, 0), 0.16),
    rgba(var(--spotlight-rgb, 255, 107, 0), 0.04) 45%,
    transparent 75%
  );
}

.spotlight-grid:hover .spotlight-card::before {
  opacity: 1;
}
</style>
