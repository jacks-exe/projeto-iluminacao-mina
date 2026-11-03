import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '@/views/DashboardView.vue'
import LoginView from '@/views/LoginView.vue'
import LandingView from '@/views/LandingView.vue'
import { isAuthenticated, mustChangePassword } from '@/config/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'landing',
      component: LandingView,
      meta: { title: 'GEONEX | Boas-vindas' },
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: DashboardView,
      meta: { title: 'GEONEX | Dashboard' },
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { title: 'GEONEX | Login' },
    },
    {
      path: '/primeiro-acesso',
      name: 'primeiro-acesso',
      component: LoginView,
      meta: { title: 'GEONEX | Primeiro Acesso' },
    },
  ],
})

// Sem sessão -> /login; se primeiro_acesso = true -> trava em /primeiro-acesso até trocar a senha
router.beforeEach((to) => {
  if (to.meta?.title) {
    document.title = to.meta.title as string
  }

  const authed = isAuthenticated()
  const precisaTrocarSenha = authed && mustChangePassword()

  if (!authed && to.name !== 'login' && to.name !== 'landing') {
    return { name: 'login' }
  }
  if (precisaTrocarSenha && to.name !== 'primeiro-acesso') {
    return { name: 'primeiro-acesso' }
  }
  if (authed && !precisaTrocarSenha && (to.name === 'login' || to.name === 'primeiro-acesso')) {
    return { name: 'dashboard' }
  }
})

export default router
