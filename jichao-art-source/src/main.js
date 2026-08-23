import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import Vant from 'vant'
import { Lazyload } from 'vant'
import 'vant/lib/index.css'
import './styles/global.scss'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.use(Vant)
app.use(Lazyload, { lazyComponent: true })
app.mount('#app')
