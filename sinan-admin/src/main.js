import { createApp } from 'vue'
import { createPinia } from 'pinia'
import Vant from 'vant'
import 'vant/lib/index.css'
import App from './App.vue'
import router from './router'
import { useAppStore } from './stores/app'
import '@/styles/global.scss'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.use(Vant)

// 初始化响应式断点（移动端 TabBar / 桌面侧边栏切换）
useAppStore().initResponsive()

app.mount('#app')
