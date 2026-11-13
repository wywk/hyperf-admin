import { createApp } from 'vue'
import { createPinia } from 'pinia'
import 'element-plus/dist/index.css'
import './styles/index.css'
import App from './App.vue'
import router from './router'
import { setupElementPlus } from './plugins/element'
import { permissionDirective } from './directives/permission'
import { useAuthStore } from './stores/auth'
import { onUnauthorized } from './utils/session'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)
setupElementPlus(app)
app.directive('permission', permissionDirective)

// token 彻底失效（refresh 也失败）时的统一出口：清状态 + 回登录页
onUnauthorized(() => {
  const auth = useAuthStore(pinia)
  auth.reset()
  const current = router.currentRoute.value
  if (current.name === 'login') {
    return
  }
  router.replace({ name: 'login', query: { redirect: current.fullPath } })
})

app.mount('#app')
