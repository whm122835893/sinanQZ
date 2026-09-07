<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import { getDecoration, saveDecoration, uploadImage } from '@/api'
import { useSiteStore } from '@/stores/site'

// ============================================================
// 站点装修：C 端全局风格（名称 / 头像 / 主题色 / 背景 / 按钮 / SEO）
// 左侧表单 + 右侧手机实时预览；保存后同步 B 端品牌（侧栏/登录页/标题）
// ============================================================

const siteStore = useSiteStore()
const loading = ref(true)
const saving = ref(false)

// 键名与后端 DECORATION_KEYS 白名单一致
const form = reactive({
  site_name: '',
  site_logo: '',
  site_avatar: '',
  theme_color: '',
  bg_color: '',
  button_color: '',
  button_radius: 8,
  seo_title: '',
  seo_description: '',
  seo_keywords: ''
})

const DEFAULTS = { theme_color: '#C00000', bg_color: '#F7F8FA' }
const themeColor = computed(() => form.theme_color || DEFAULTS.theme_color)
const bgColor = computed(() => form.bg_color || DEFAULTS.bg_color)
const buttonColor = computed(() => form.button_color || themeColor.value)

onMounted(load)

async function load() {
  loading.value = true
  const res = await getDecoration()
  if (res.code === 0 && res.data) {
    Object.assign(form, res.data)
    form.button_radius = Number(form.button_radius) || 0
  }
  loading.value = false
}

// ---- 图片上传（site_logo / site_avatar）----
const uploadingKey = ref('')
async function onUpload(field, { file }) {
  if (!file) return
  if (file.size > 5 * 1024 * 1024) return ElMessage.warning('图片大小不能超过 5MB')
  uploadingKey.value = field
  const res = await uploadImage(file, 'content')
  uploadingKey.value = ''
  if (res.code === 0 && res.data?.url) {
    form[field] = res.data.url
    ElMessage.success('已上传')
  } else {
    ElMessage.error(res.message || '上传失败，请重试')
  }
}

async function onSave() {
  if (!form.site_name.trim()) return ElMessage.warning('请输入站点名称')
  saving.value = true
  const res = await saveDecoration({ ...form, site_name: form.site_name.trim() })
  saving.value = false
  if (res.code === 0) {
    // 站点名/头像同步到 B 端侧边栏、登录页与浏览器标题
    siteStore.setBrand(form.site_name.trim(), form.site_avatar)
    ElMessage.success('站点配置已保存，C 端刷新后生效')
  }
}
</script>

<template>
  <div class="adm-page deco">
    <el-skeleton v-if="loading" :rows="8" animated style="padding: 20px" />
    <template v-else>
      <div class="deco__split">
        <!-- 左列：配置表单 -->
        <div class="deco__forms">
          <!-- 基本信息 -->
          <div class="adm-card">
            <div class="adm-card__title">基本信息</div>
            <el-form label-width="90px" class="deco__form">
              <el-form-item label="站点名称" required>
                <el-input v-model="form.site_name" placeholder="如：司南珍藏" maxlength="20" show-word-limit />
                <div class="t-tertiary deco__tip">C 端导航栏与 B 端侧边栏/登录页/标题同步展示</div>
              </el-form-item>
              <el-form-item label="站点 Logo">
                <div class="deco__upload-row">
                  <div v-if="form.site_logo" class="deco__thumb">
                    <img :src="form.site_logo" alt="logo" />
                    <div class="deco__thumb-ops">
                      <el-upload
                        :show-file-list="false"
                        :http-request="(o) => onUpload('site_logo', o)"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                      >
                        <el-button link type="primary" size="small" :loading="uploadingKey === 'site_logo'">重新上传</el-button>
                      </el-upload>
                      <el-button link type="danger" size="small" @click="form.site_logo = ''">删除</el-button>
                    </div>
                  </div>
                  <el-upload
                    v-else
                    class="deco__uploader"
                    :show-file-list="false"
                    :http-request="(o) => onUpload('site_logo', o)"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                  >
                    <div class="deco__uploader-box">
                      <el-icon :size="20"><Plus /></el-icon>
                      <div class="t-tertiary">上传 Logo</div>
                    </div>
                  </el-upload>
                  <div class="t-tertiary deco__tip">横版图片，展示于 C 端页面顶部</div>
                </div>
              </el-form-item>
              <el-form-item label="平台头像">
                <div class="deco__upload-row">
                  <div v-if="form.site_avatar" class="deco__thumb deco__thumb--round">
                    <img :src="form.site_avatar" alt="avatar" />
                    <div class="deco__thumb-ops">
                      <el-upload
                        :show-file-list="false"
                        :http-request="(o) => onUpload('site_avatar', o)"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                      >
                        <el-button link type="primary" size="small" :loading="uploadingKey === 'site_avatar'">重新上传</el-button>
                      </el-upload>
                      <el-button link type="danger" size="small" @click="form.site_avatar = ''">删除</el-button>
                    </div>
                  </div>
                  <el-upload
                    v-else
                    class="deco__uploader deco__uploader--round"
                    :show-file-list="false"
                    :http-request="(o) => onUpload('site_avatar', o)"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                  >
                    <div class="deco__uploader-box">
                      <el-icon :size="20"><Plus /></el-icon>
                      <div class="t-tertiary">上传头像</div>
                    </div>
                  </el-upload>
                  <div class="t-tertiary deco__tip">正方形图标，C 端导航栏与 B 端侧边栏展示；留空用默认平台图</div>
                </div>
              </el-form-item>
            </el-form>
          </div>

          <!-- 主题风格 -->
          <div class="adm-card">
            <div class="adm-card__title">主题风格</div>
            <el-form label-width="90px" class="deco__form">
              <el-form-item label="主题色">
                <div class="deco__color">
                  <el-color-picker v-model="form.theme_color" show-alpha :predefine="['#C00000', '#D4A574', '#1F2C4C', '#2E7D32', '#6A1B9A']" />
                  <el-input v-model="form.theme_color" placeholder="#C00000" class="deco__color-input" maxlength="7" />
                  <el-button v-if="form.theme_color" link type="tertiary" size="small" @click="form.theme_color = ''">恢复默认</el-button>
                </div>
                <div class="t-tertiary deco__tip">C 端按钮、标签、强调色；留空用默认 #C00000</div>
              </el-form-item>
              <el-form-item label="页面背景">
                <div class="deco__color">
                  <el-color-picker v-model="form.bg_color" show-alpha :predefine="['#F7F8FA', '#FFFFFF', '#F5F0E8', '#FAF7F2']" />
                  <el-input v-model="form.bg_color" placeholder="#F7F8FA" class="deco__color-input" maxlength="7" />
                  <el-button v-if="form.bg_color" link type="tertiary" size="small" @click="form.bg_color = ''">恢复默认</el-button>
                </div>
                <div class="t-tertiary deco__tip">C 端页面底色；留空用默认 #F7F8FA</div>
              </el-form-item>
              <el-form-item label="按钮颜色">
                <div class="deco__color">
                  <el-color-picker v-model="form.button_color" show-alpha :predefine="['#C00000', '#D4A574', '#1F2C4C', '#B8860B']" />
                  <el-input v-model="form.button_color" placeholder="跟随主题色" class="deco__color-input" maxlength="7" />
                  <el-button v-if="form.button_color" link type="tertiary" size="small" @click="form.button_color = ''">跟随主题色</el-button>
                </div>
                <div class="t-tertiary deco__tip">C 端主按钮底色；留空跟随主题色</div>
              </el-form-item>
              <el-form-item label="按钮圆角">
                <div class="deco__radius">
                  <el-slider v-model="form.button_radius" :min="0" :max="24" :step="1" show-stops />
                  <span class="deco__radius-val">{{ form.button_radius }}px</span>
                </div>
              </el-form-item>
            </el-form>
          </div>

          <!-- SEO 设置 -->
          <div class="adm-card">
            <div class="adm-card__title">SEO 设置</div>
            <el-form label-width="90px" class="deco__form">
              <el-form-item label="SEO 标题">
                <el-input v-model="form.seo_title" placeholder="浏览器标签页标题，留空用站点名称" maxlength="60" />
              </el-form-item>
              <el-form-item label="SEO 描述">
                <el-input
                  v-model="form.seo_description"
                  type="textarea"
                  :rows="2"
                  placeholder="搜索引擎展示的站点描述"
                  maxlength="200"
                  show-word-limit
                />
              </el-form-item>
              <el-form-item label="SEO 关键词">
                <el-input v-model="form.seo_keywords" placeholder="逗号分隔，如：数字藏品,国潮,艺术品" maxlength="120" />
              </el-form-item>
            </el-form>
          </div>

          <div class="deco__actions">
            <el-button type="primary" :loading="saving" @click="onSave">保存配置</el-button>
            <el-button @click="load">重置</el-button>
          </div>
        </div>

        <!-- 右列：手机预览 -->
        <div class="deco__preview-wrap">
          <div class="deco__preview-title">C 端效果预览</div>
          <div class="deco__phone" :style="{ background: bgColor }">
            <!-- 导航栏 -->
            <div class="deco__navbar">
              <img v-if="form.site_avatar || form.site_logo" class="deco__navbar-avatar" :src="form.site_avatar || form.site_logo" alt="" />
              <div v-else class="deco__navbar-avatar deco__navbar-avatar--ph">{{ (form.site_name || '司南')[0] }}</div>
              <span class="deco__navbar-name">{{ form.site_name || '司南珍藏' }}</span>
              <span class="deco__navbar-dot" :style="{ background: themeColor }" />
            </div>

            <!-- 内容区 -->
            <div class="deco__body">
              <div class="deco__banner" :style="{ background: `linear-gradient(135deg, ${themeColor}, ${themeColor}CC)` }">
                <div class="deco__banner-title">{{ form.seo_title || form.site_name || '司南珍藏' }}</div>
                <div class="deco__banner-sub">{{ form.seo_description || '数字藏品 · 每一件都值得珍藏' }}</div>
              </div>

              <div class="deco__grid">
                <div v-for="i in 4" :key="i" class="deco__card">
                  <div class="deco__card-img" :style="{ background: `${themeColor}${i % 2 ? '22' : '13'}` }" />
                  <div class="deco__card-name">藏品 {{ i }}</div>
                  <div
                    class="deco__card-btn"
                    :style="{
                      background: buttonColor,
                      borderRadius: `${form.button_radius}px`,
                      '--hover-shadow': themeColor
                    }"
                  >
                    立即购买
                  </div>
                </div>
              </div>
            </div>

            <!-- 底部 Tab -->
            <div class="deco__tabbar">
              <div v-for="(t, i) in ['首页', '市场', '藏品', '我的']" :key="t" class="deco__tab" :class="{ 'is-active': i === 0 }" :style="i === 0 ? { color: themeColor } : {}">
                <span class="deco__tab-dot" :style="i === 0 ? { background: themeColor } : {}" />
                {{ t }}
              </div>
            </div>
          </div>
          <div class="t-tertiary deco__preview-note">预览仅为示意，保存后 C 端全站生效</div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.deco__split {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 14px;
  align-items: start;
}

.deco__forms {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-width: 0;
}

.deco__form {
  margin-top: 6px;
}

.deco__tip {
  font-size: 12px;
  line-height: 1.5;
  width: 100%;
  margin-top: 4px;
}

// ---- 上传 ----
.deco__upload-row {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  width: 100%;
}

.deco__thumb {
  display: flex;
  align-items: center;
  gap: 10px;

  img {
    width: 72px;
    height: 72px;
    border-radius: 8px;
    object-fit: cover;
    background: $color-surface;
    border: 1px solid $color-border;
  }

  &--round img {
    border-radius: 50%;
  }
}

.deco__thumb-ops {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}

.deco__uploader {
  :deep(.el-upload) {
    width: 72px;
    height: 72px;
    border: 1px dashed var(--el-border-color);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: border-color 0.2s;

    &:hover { border-color: $color-primary; }
  }

  &--round :deep(.el-upload) { border-radius: 50%; }
}

.deco__uploader-box {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  font-size: 11px;
  color: $color-text-tertiary;
}

// ---- 颜色行 ----
.deco__color {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
}

.deco__color-input {
  width: 130px;
  font-family: monospace;
}

.deco__radius {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;

  :deep(.el-slider) { flex: 1; }
}

.deco__radius-val {
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;
  width: 38px;
  text-align: right;
}

.deco__actions {
  display: flex;
  gap: 10px;
}

// ---- 手机预览 ----
.deco__preview-wrap {
  position: sticky;
  top: 16px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.deco__preview-title {
  font-size: 13px;
  font-weight: 600;
  color: $color-text-primary;
}

.deco__phone {
  width: 280px;
  height: 520px;
  border-radius: 28px;
  border: 6px solid #1a1a1a;
  box-shadow: 0 12px 36px rgba(26, 26, 26, 0.16);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: background 0.2s;
}

.deco__navbar {
  height: 48px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 14px;
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: blur(6px);
  border-bottom: 1px solid rgba(0, 0, 0, 0.04);
  flex-shrink: 0;
}

.deco__navbar-avatar {
  width: 26px;
  height: 26px;
  border-radius: 7px;
  object-fit: cover;
  flex-shrink: 0;

  &--ph {
    background: $color-surface;
    color: $color-text-secondary;
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
  }
}

.deco__navbar-name {
  font-size: 14px;
  font-weight: 700;
  color: #1a1a1a;
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.deco__navbar-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}

.deco__body {
  flex: 1;
  overflow: hidden;
  padding: 10px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.deco__banner {
  border-radius: 12px;
  padding: 14px 12px;
  color: #fff;
  flex-shrink: 0;

  &-title {
    font-size: 15px;
    font-weight: 700;
  }

  &-sub {
    margin-top: 4px;
    font-size: 10px;
    opacity: 0.85;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
}

.deco__grid {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  align-content: start;
  overflow: hidden;
}

.deco__card {
  background: #fff;
  border-radius: 10px;
  padding: 8px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.deco__card-img {
  height: 64px;
  border-radius: 8px;
  transition: background 0.2s;
}

.deco__card-name {
  font-size: 11px;
  color: #333;
  font-weight: 600;
}

.deco__card-btn {
  height: 24px;
  border-radius: 8px;
  color: #fff;
  font-size: 11px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
}

.deco__tabbar {
  height: 46px;
  background: rgba(255, 255, 255, 0.95);
  border-top: 1px solid rgba(0, 0, 0, 0.05);
  display: flex;
  flex-shrink: 0;
}

.deco__tab {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  font-size: 9px;
  color: #999;
  transition: color 0.2s;

  &.is-active { font-weight: 600; }
}

.deco__tab-dot {
  width: 14px;
  height: 3px;
  border-radius: 2px;
  background: transparent;
}

.deco__preview-note {
  font-size: 11px;
}

@media (max-width: 1100px) {
  .deco__split { grid-template-columns: 1fr; }
  .deco__preview-wrap { position: static; }
}
</style>
