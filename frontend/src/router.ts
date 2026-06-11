import { createRouter, createWebHistory } from 'vue-router'
import LoginView from './views/LoginView.vue'
import AufgabenView from './views/AufgabenView.vue'
import KundenView from './views/KundenView.vue'
import SettingsView from './views/SettingsView.vue'
import SucheView from './views/SucheView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: LoginView },
    { path: '/', redirect: '/aufgaben' },
    { path: '/aufgaben', component: AufgabenView, meta: { auth: true } },
    { path: '/kunden', component: KundenView, meta: { auth: true } },
    { path: '/suche', component: SucheView, meta: { auth: true } },
    { path: '/einstellungen', component: SettingsView, meta: { auth: true } },
  ],
})

router.beforeEach((to) => {
  const token = localStorage.getItem('token')
  if (to.meta.auth && !token) return '/login'
  if (to.path === '/login' && token) return '/aufgaben'
})

export default router
