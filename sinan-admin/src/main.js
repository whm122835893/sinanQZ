import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import * as ElementPlusIconsVue from '@element-plus/icons-vue'
import App from './App.vue'
import router from './router'
import permissionDirective from './directives/permission'
import { useAppStore } from './stores/app'
import '@/styles/global.scss'

const app = createApp(App)

// Element Plus 图标全局注册（图标名即组件名，如 <el-icon><Dashboard /></el-icon>）
for (const [key, component] of Object.entries(ElementPlusIconsVue)) {
  app.component(key, component)
}

app.use(createPinia())
app.use(router)
app.use(ElementPlus, { locale: zhCn })

// v-permission="['user.freeze']" 自定义指令
app.use(permissionDirective)

// 初始化响应式断点（移动端抽屉侧栏 / 桌面侧栏切换）
useAppStore().initResponsive()

app.mount('#app')
