<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { showToast } from 'vant'
import AppNavBar from '@/components/AppNavBar.vue'
import AppButton from '@/components/AppButton.vue'
import { useActivityStore } from '@/stores/activity'
import { useUserStore } from '@/stores/user'
import { useLoginGate } from '@/utils/loginGate'

const route = useRoute()
const activityStore = useActivityStore()
const user = useUserStore()
const { requireLogin } = useLoginGate()
// 仓库藏品来自用户库存
const { inventory: userInventory } = storeToRefs(user)

// MOCK_REPLACED: 原为本地 mock 活动数据 + 本地消耗/入库，
// 现从后端拉取活动详情（GET /api/synthesis/activities/:id，含材料与我持有数量），
// 提交走 POST /api/synthesis/submit（后端事务消耗材料并生成产物）。
const act = ref(null)
onMounted(async () => {
  act.value = await activityStore.fetchSynthesisDetail(route.params.id).catch(() => null)
  user.fetchInventory().catch(() => {})
})

// 选中的材料：相同藏品只保留一条，按 qty 累加（不重复占位）
const selected = ref([])
const showPicker = ref(false)

const selectedQty = (id) => {
  const f = selected.value.find((s) => s.id === id)
  return f ? f.qty : 0
}

function pick(it) {
  if (selectedQty(it.id) >= it.qty) return // 超过库存不再加
  const found = selected.value.find((s) => s.id === it.id)
  if (found) found.qty += 1
  else selected.value.push({ id: it.id, name: it.name, coverImage: it.coverImage, qty: 1 })
}

function unpick(s) {
  const found = selected.value.find((x) => x.id === s.id)
  if (!found) return
  if (found.qty > 1) found.qty -= 1
  else selected.value = selected.value.filter((x) => x.id !== s.id)
}

const synthesizing = ref(false)
const showSuccess = ref(false)

async function startSynthesis() {
  if (synthesizing.value || !selected.value.length || !act.value) return
  if (!requireLogin(route.fullPath)) return
  // 材料充足性前置校验（后端事务内为最终校验）
  const missing = (act.value.materials || []).find((m) => (m.myAvailable || 0) < m.count)
  if (missing) {
    showToast(`材料不足：《${missing.name}》需 ${missing.count} 件`)
    return
  }
  synthesizing.value = true
  try {
    await activityStore.submitSynthesis(route.params.id)
    // 刷新库存（材料已消耗、产物已入库）
    await user.fetchInventory().catch(() => {})
    synthesizing.value = false
    showSuccess.value = true
  } catch (e) {
    synthesizing.value = false
    showToast(e.message || '合成失败，请重试')
  }
}

function closeSuccess() {
  showSuccess.value = false
}
</script>

<template>
  <div class="synthesis page--no-tabbar">
    <AppNavBar title="合成活动" @click-left="$router.back()" />

    <template v-if="act">
      <!-- 活动信息 -->
      <header class="syn-head">
        <h1 class="syn-head__title">{{ act.title }}</h1>
        <p class="syn-head__time">开始：{{ act.startTime }}　结束：{{ act.endTime }}</p>
        <p class="syn-desc">{{ act.desc }}</p>
      </header>

      <!-- 合成结果（置于材料上方） -->
      <div class="syn-result">
        <div class="syn-result__img-wrap"><img :src="act.result.coverImage" alt="" /></div>
        <span class="syn-result__tag">合成获得</span>
        <span class="syn-result__name">{{ act.result.name }}</span>
      </div>

      <!-- 材料选择 -->
      <div class="syn-materials">
        <div class="syn-materials__head">
          <span>合成材料</span>
          <span class="syn-materials__add" @click="showPicker = true">+ 添加材料</span>
        </div>

        <div class="syn-mat-row">
          <div v-for="s in selected" :key="s.id" class="syn-mat">
            <button class="syn-mat__minus" @click="unpick(s)">−</button>
            <div class="syn-mat__img-wrap"><img :src="s.coverImage" alt="" /></div>
            <span class="syn-mat__name">{{ s.name }}</span>
            <span class="syn-mat__own">×{{ s.qty }}</span>
          </div>
          <div class="syn-mat syn-mat--add" @click="showPicker = true">
            <div class="syn-mat__img-wrap syn-mat__img-wrap--add">＋</div>
            <span class="syn-mat__name">添加</span>
          </div>
        </div>
        <p v-if="!selected.length" class="syn-materials__empty">请选择仓库藏品作为合成材料</p>
      </div>

      <div class="syn-actions">
        <AppButton :disabled="!selected.length || synthesizing" @click="startSynthesis">
          {{ synthesizing ? '合成中…' : '立即合成' }}
        </AppButton>
      </div>
    </template>

    <div v-else class="syn-empty">活动不存在或已下架</div>

    <!-- 仓库藏品选择面板 -->
    <van-popup v-model:show="showPicker" position="bottom" round>
      <div class="picker">
        <div class="picker__head">
          <span class="picker__title">选择仓库藏品</span>
          <span class="picker__done" @click="showPicker = false">完成</span>
        </div>
        <div class="picker__list">
          <div
            v-for="it in userInventory"
            :key="it.id"
            class="picker__item"
            :class="{ disabled: selectedQty(it.id) >= it.qty }"
            @click="pick(it)"
          >
            <img class="picker__img" :src="it.coverImage" alt="" />
            <div class="picker__info">
              <span class="picker__name">{{ it.name }}</span>
              <span class="picker__stock">库存 {{ it.qty }} · 已选 {{ selectedQty(it.id) }}</span>
            </div>
            <span class="picker__add">＋</span>
          </div>
          <p v-if="!userInventory.length" class="picker__empty">仓库暂无藏品，先去获取吧～</p>
        </div>
      </div>
    </van-popup>

    <!-- 合成成功弹窗 -->
    <van-overlay :show="showSuccess" :z-index="100" @click="closeSuccess">
      <div class="syn-success" @click.stop>
        <div class="syn-success__img-wrap">
          <img :src="act?.result.coverImage" alt="" />
        </div>
        <p class="syn-success__title">合成成功</p>
        <p class="syn-success__name">{{ act?.result.name }}</p>
        <p class="syn-success__tip">新藏品已存入您的仓库</p>
        <button class="syn-success__btn" @click="closeSuccess">收下藏品</button>
      </div>
    </van-overlay>
  </div>
</template>

<style scoped lang="scss">
.synthesis {
  min-height: 100vh;
  background: $color-bg;
  padding-bottom: calc(env(safe-area-inset-bottom) + 24px);
}

.syn-head { margin: 14px $page-padding 0; padding: 16px; background: $color-card; border-radius: $radius-lg; }
.syn-head__title { margin: 0 0 8px; font-size: 18px; font-weight: 700; color: $color-text-primary; }
.syn-head__time { margin: 0 0 8px; font-size: 13px; color: $color-text-tertiary; }
.syn-desc { margin: 0; font-size: 13px; color: $color-text-secondary; line-height: 1.7; text-align: justify; }

/* 合成结果（上方） */
.syn-result {
  margin: 16px $page-padding 0; padding: 18px 16px 16px;
  background: $color-card; border: 1px solid $color-primary-light; border-radius: $radius-lg;
  display: flex; flex-direction: column; align-items: center; gap: 8px;
}
.syn-result__img-wrap {
  width: 96px; height: 96px; border-radius: 14px; overflow: hidden;
  background: $color-surface; display: flex; align-items: center; justify-content: center;
}
.syn-result__img-wrap img { width: 96px; height: 96px; object-fit: cover; display: block; }
.syn-result__tag {
  font-size: 11px; color: #fff; background: linear-gradient(135deg, #D00000, #B00000);
  padding: 1px 10px; border-radius: $radius-pill;
}
.syn-result__name { font-size: 15px; font-weight: 700; color: $color-text-primary; }

/* 材料 */
.syn-materials { margin: 14px $page-padding 0; background: $color-card; border-radius: $radius-lg; padding: 14px; }
.syn-materials__head {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 14px; font-weight: 700; color: $color-text-primary; margin-bottom: 12px;
}
.syn-materials__add { font-size: 13px; font-weight: 500; color: $color-primary; cursor: pointer; }

.syn-mat-row { display: flex; flex-wrap: wrap; gap: 10px; }
.syn-mat {
  position: relative; width: 88px;
  background: $color-surface; border-radius: $radius-lg;
  padding: 12px 6px 10px; display: flex; flex-direction: column; align-items: center; gap: 6px;
}
.syn-mat__img-wrap {
  width: 64px; height: 64px; border-radius: 12px; overflow: hidden;
  background: #fff; display: flex; align-items: center; justify-content: center;
}
.syn-mat__img-wrap img { width: 64px; height: 64px; object-fit: cover; display: block; }
.syn-mat__img-wrap--add {
  border: 1px dashed $color-primary; background: transparent;
  color: $color-primary; font-size: 28px; font-weight: 700;
}
.syn-mat__name { font-size: 12px; font-weight: 600; color: $color-text-primary; text-align: center; }
.syn-mat__own { font-size: 11px; color: $color-text-tertiary; }
.syn-mat__minus {
  position: absolute; top: 4px; right: 4px;
  width: 20px; height: 20px; border-radius: 50%; border: none; padding: 0;
  background: rgba(0, 0, 0, 0.55); color: #fff; font-size: 14px; line-height: 20px;
  display: flex; align-items: center; justify-content: center; cursor: pointer;
}
.syn-materials__empty { margin: 4px 0 2px; font-size: 13px; color: $color-text-tertiary; text-align: center; }

.syn-actions { margin: 20px $page-padding 0; }
.syn-empty { text-align: center; color: $color-text-tertiary; font-size: 14px; margin-top: 80px; }

/* 仓库选择面板 */
.picker { max-height: 70vh; display: flex; flex-direction: column; }
.picker__head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px; border-bottom: 1px solid $color-border;
}
.picker__title { font-size: 15px; font-weight: 700; color: $color-text-primary; }
.picker__done { font-size: 14px; color: $color-primary; cursor: pointer; }
.picker__list { overflow-y: auto; padding: 4px 16px 16px; }
.picker__item {
  display: flex; align-items: center; gap: 10px; padding: 12px 0;
  border-bottom: 1px solid $color-border; cursor: pointer;
  &:last-child { border-bottom: none; }
  &.disabled { opacity: 0.5; cursor: default; }
}
.picker__img { width: 44px; height: 44px; border-radius: 8px; object-fit: cover; background: $color-surface; flex-shrink: 0; }
.picker__info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.picker__name { font-size: 14px; color: $color-text-primary; }
.picker__stock { font-size: 12px; color: $color-text-tertiary; }
.picker__add {
  width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
  background: linear-gradient(135deg, #D00000, #B00000); color: #fff;
  font-size: 18px; font-weight: 700; display: flex; align-items: center; justify-content: center;
}
.picker__empty { text-align: center; color: $color-text-tertiary; font-size: 13px; margin-top: 30px; }

/* 成功弹窗 */
.syn-success {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 300px; background: #fff; border-radius: 20px;
  padding: 26px 22px 22px; text-align: center;
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.22);
  .syn-success__img-wrap {
    width: 120px; height: 120px; margin: 0 auto 14px;
    border-radius: 16px; background: $color-surface;
    display: flex; align-items: center; justify-content: center; overflow: hidden;
  }
  .syn-success__img-wrap img {
    width: 112px; height: 112px; border-radius: 12px; object-fit: cover; display: block;
  }
  .syn-success__title { margin: 0; font-size: 14px; color: $color-text-tertiary; }
  .syn-success__name { margin: 6px 0 6px; font-size: 22px; font-weight: 800; color: $color-primary; }
  .syn-success__tip { margin: 0 0 20px; font-size: 12px; color: $color-text-tertiary; }
  .syn-success__btn {
    width: 100%; height: 44px; border: none; border-radius: $radius-pill;
    background: linear-gradient(135deg, #D00000, #B00000); color: #fff;
    font-size: 15px; font-weight: 600; cursor: pointer;
  }
}
</style>
