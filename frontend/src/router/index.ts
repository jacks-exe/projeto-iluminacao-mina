import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '@/views/DashboardView.vue'
import LoginView from '@/views/LoginView.vue'
import { isAuthenticated } from '@/config/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'dashboard',
      component: DashboardView,
    },
    { path: '/login', name: 'login', component: LoginView },
  ],
})

// Sem sessão -> /login; já autenticado em /login -> dashboard.
router.beforeEach((to) => {
  const authed = isAuthenticated()
  if (to.name !== 'login' && !authed) return { name: 'login' }
  if (to.name === 'login' && authed) return { name: 'dashboard' }
})

export default router