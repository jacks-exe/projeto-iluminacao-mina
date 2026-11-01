import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '@/views/DashboardView.vue'
import LoginView from '@/views/LoginView.vue'
import { isAuthenticated, mustChangePassword } from '@/config/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'dashboard',
      component: DashboardView,
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
    },
    {
      path: '/primeiro-acesso',
      name: 'primeiro-acesso',
      component: LoginView,
    },
  ],
})

// Sem sessão -> /login; se primeiro_acesso = true -> trava em /primeiro-acesso até trocar a senha
router.beforeEach((to) => {
  const authed = isAuthenticated()
  const precisaTrocarSenha = authed && mustChangePassword()

  if (!authed && to.name !== 'login') {
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
