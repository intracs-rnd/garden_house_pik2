import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'

import './assets/css/main.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)

const authStore = useAuthStore()
authStore.bootstrap()

async function initApp() {
  // If already authenticated, fetch fresh permissions from the server BEFORE
  // mounting so the router guard and sidebar always use up-to-date permissions,
  // even when a superadmin has changed another role's access in the meantime.
  if (authStore.isAuthenticated) {
    await authStore.fetchUser().catch(() => {})
  }

  app.use(router)
  app.mount('#app')
}

initApp()
