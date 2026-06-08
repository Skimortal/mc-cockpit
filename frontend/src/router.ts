import { createRouter, createWebHistory } from 'vue-router'
import LoginView from './views/LoginView.vue'
import BoardView from './views/BoardView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: LoginView },
    { path: '/', component: BoardView, meta: { auth: true } },
  ],
})

router.beforeEach((to) => {
  const token = localStorage.getItem('token')
  if (to.meta.auth && !token) return '/login'
  if (to.path === '/login' && token) return '/'
})

export default router
