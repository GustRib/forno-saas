import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './app/App.vue'
import { createAppRouter } from './router'
import { setUnauthorizedHandler } from './shared/api/http'
import { useSessionStore } from './app/stores/session'
import './app/styles.css'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)

const router = createAppRouter()
app.use(router)

// Sessão expirada em qualquer chamada → volta ao login preservando o destino.
setUnauthorizedHandler(() => {
  useSessionStore(pinia).clear()
  const current = router.currentRoute.value
  if (current.meta.public !== true) {
    void router.replace({ name: 'login', query: { redirect: current.fullPath } })
  }
})

app.mount('#app')
