import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'

import './assets/css/main.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)

// Restore the authenticated session (token + user) before the router boots,
// so navigation guards have the correct auth state on first load.
const authStore = useAuthStore()
authStore.bootstrap()

app.use(router)

app.mount('#app')
