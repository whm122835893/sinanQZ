import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import Vant from 'vant'
import { Lazyload } from 'vant'
import 'vant/lib/index.css'
import './styles/global.scss'
import { useSiteStore } from './stores/site'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.use(Vant)
app.use(Lazyload, { lazyComponent: true })

// 应用站点装修（B 端配置的全局风格：颜色/名称/圆角）
useSiteStore().init()

app.mount('#app')
