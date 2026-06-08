import { defineStore } from 'pinia'
import api from '../api'

export interface Me {
  id: number
  email: string
  firstName: string
  lastName: string
  name: string
  roles: string[]
}

export const useAuth = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('token') as string | null,
    me: null as Me | null,
  }),
  getters: {
    isAuthed: (s) => !!s.token,
  },
  actions: {
    async login(email: string, password: string) {
      const { data } = await api.post('/api/login', { email, password })
      this.token = data.token
      localStorage.setItem('token', data.token)
      await this.fetchMe()
    },
    async fetchMe() {
      const { data } = await api.get('/api/me')
      this.me = data
    },
    logout() {
      this.token = null
      this.me = null
      localStorage.removeItem('token')
    },
  },
})
