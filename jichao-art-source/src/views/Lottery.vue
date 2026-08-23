<script setup>
import { ref } from 'vue'
import AppNavBar from '@/components/AppNavBar.vue'

// 六个奖品：图片 / 数量（剩余） / 奖项名称
const prizes = [
  { name: '龙纹罗盘', img: '/images/collections/cover-1.jpg', qty: 5 },
  { name: '100 司南币', img: '/images/platform-logo.png', qty: 20 },
  { name: '云端法器', img: '/images/collections/cover-2.jpg', qty: 3 },
  { name: '谢谢参与', img: '/images/tab/tab-gift-line.png', qty: 50 },
  { name: '青铜面具', img: '/images/collections/cover-3.jpg', qty: 8 },
  { name: '5 司南币', img: '/images/brand-logo.png', qty: 100 }
]

const SECTOR = 360 / prizes.length // 60°

// 扇形底色（交替柔和暖色，深色文字易读）
const sectorColors = ['#FFF1F1', '#FDEAEA', '#FFF1F1', '#FDEAEA', '#FFF1F1', '#FDEAEA']
const wheelBg = `conic-gradient(${sectorColors
  .map((c, i) => `${c} ${i * SECTOR}deg ${(i + 1) * SECTOR}deg`)
  .join(', ')})`

const rotation = ref(0)
const spinning = ref(false)
const result = ref(null)
const showResult = ref(false)

const records = ref([])        // 抽奖记录：{ name, time }
const spinDuration = ref(4200) // 转盘转动时长（抽多次时缩短）

function nowStr() {
  const d = new Date()
  const p = (x) => String(x).padStart(2, '0')
  return `${d.getMonth() + 1}月${d.getDate()}日 ${p(d.getHours())}:${p(d.getMinutes())}`
}

// 抽 n 次：转盘依次转动，每次结果写入抽奖记录；仅最后一次弹窗
function drawTimes(n) {
  if (spinning.value) return
  spinDuration.value = n > 1 ? 1400 : 4200
  let left = n
  const runOne = () => {
    spinning.value = true
    result.value = null
    showResult.value = false

    const index = Math.floor(Math.random() * prizes.length)
    const sectorCenter = index * SECTOR + SECTOR / 2 // 该扇形中心角（顺时针，自顶部起）
    const desired = (360 - sectorCenter) % 360        // 让该中心转到顶部指针处所需的轮转角
    const current = ((rotation.value % 360) + 360) % 360
    let delta = (((desired - current) % 360) + 360) % 360
    rotation.value += 360 * 6 + delta // 6 整圈 + 对齐偏移

    setTimeout(() => {
      spinning.value = false
      const prize = prizes[index]
      records.value.unshift({ name: prize.name, time: nowStr() })
      result.value = prize
      left -= 1
      if (left > 0) {
        runOne()
      } else {
        showResult.value = true
      }
    }, spinDuration.value + 100)
  }
  runOne()
}

function closeResult() {
  showResult.value = false
}

function onAgain() {
  closeResult()
  drawTimes(1)
}
</script>

<template>
  <div class="lottery page--no-tabbar">
    <AppNavBar title="幸运抽奖" @click-left="$router.back()" />

    <div class="lottery__hero">
      <p class="lottery__tip">每日免费 1 次 · 转出专属好礼</p>
    </div>

    <!-- 转盘 -->
    <div class="wheel-wrap">
      <span class="wheel-pointer"></span>
      <div
        class="wheel"
        :class="{ spinning }"
        :style="{ transform: `rotate(${rotation}deg)`, background: wheelBg, transitionDuration: spinDuration + 'ms' }"
      >
        <div
          v-for="(p, i) in prizes"
          :key="i"
          class="wheel-sector"
          :style="{ transform: `rotate(${i * SECTOR + SECTOR / 2}deg)` }"
        >
          <div class="wheel-prize">
            <img class="wheel-prize__img" :src="p.img" alt="" />
            <span class="wheel-prize__name">{{ p.name }}</span>
          </div>
        </div>
      </div>
      <button v-show="!showResult" class="wheel-go" :disabled="spinning" @click="drawTimes(1)">
        {{ spinning ? '抽奖中' : '抽奖' }}
      </button>
    </div>

    <!-- 抽奖次数按钮 -->
    <div class="lottery__actions">
      <button class="lottery__btn" :disabled="spinning" @click="drawTimes(1)">抽一次</button>
      <button class="lottery__btn lottery__btn--gold" :disabled="spinning" @click="drawTimes(5)">抽五次</button>
    </div>

    <!-- 抽奖记录 -->
    <div class="lottery__records">
      <div class="lottery__records-head">
        <span>抽奖记录</span>
        <span v-if="records.length" class="lottery__records-clear" @click="records = []">清空</span>
      </div>
      <div v-if="records.length" class="lottery__record-list">
        <div v-for="(r, i) in records" :key="i" class="record-item">
          <span class="record-item__dot"></span>
          <span class="record-item__name">{{ r.name }}</span>
          <span class="record-item__time">{{ r.time }}</span>
        </div>
      </div>
      <p v-else class="lottery__records-empty">暂无抽奖记录，快来试试手气吧～</p>
    </div>

    <p class="lottery__rule">活动规则：每次抽奖消耗 1 次机会，奖品数量有限，抽完即止。</p>

    <!-- 结果弹窗 -->
    <van-overlay :show="showResult" @click="closeResult">
      <div class="result" @click.stop>
        <div class="result__img-wrap">
          <img class="result__img" :src="result?.img" alt="" />
        </div>
        <p class="result__title">恭喜中奖</p>
        <p class="result__name">{{ result?.name }}</p>
        <div class="result__btns">
          <button class="result__btn result__btn--ghost" @click="closeResult">收下奖品</button>
          <button class="result__btn" @click="onAgain">再来一次</button>
        </div>
      </div>
    </van-overlay>
  </div>
</template>

<style scoped lang="scss">
.lottery {
  min-height: 100vh;
  background:
    radial-gradient(120% 60% at 50% 0%, rgba(192, 0, 0, 0.12), rgba(192, 0, 0, 0) 60%),
    $color-bg;
}

.lottery__hero { padding: 18px $page-padding 4px; text-align: center; }
.lottery__tip { margin: 0; font-size: 13px; color: $color-text-tertiary; }

.wheel-wrap {
  position: relative;
  width: 320px; height: 320px;
  margin: 24px auto 8px;
}

.wheel-pointer {
  position: absolute;
  top: -4px; left: 50%;
  transform: translateX(-50%);
  width: 0; height: 0;
  border-left: 14px solid transparent;
  border-right: 14px solid transparent;
  border-top: 22px solid $color-primary;
  z-index: 5;
  filter: drop-shadow(0 2px 3px rgba(0, 0, 0, 0.25));
}

.wheel {
  width: 100%; height: 100%;
  border-radius: 50%;
  border: 6px solid #fff;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);
  position: relative;
  transition: transform 4.2s cubic-bezier(0.17, 0.67, 0.12, 0.99);
}

.wheel-sector {
  position: absolute; inset: 0;
  transform-origin: 50% 50%;
}
.wheel-prize {
  position: absolute;
  top: 16px; left: 50%;
  transform: translateX(-50%);
  width: 86px;
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  text-align: center;
}
.wheel-prize__img {
  width: 42px; height: 42px; border-radius: 8px; object-fit: cover;
  background: #fff; border: 1px solid $color-border;
}
.wheel-prize__name {
  font-size: 12px; font-weight: 700; color: $color-primary; line-height: 1.2;
}

.wheel-go {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 80px; height: 80px; border-radius: 50%;
  border: 4px solid #fff;
  background: linear-gradient(135deg, #D00000, #B00000);
  color: #fff; font-size: 17px; font-weight: 700;
  cursor: pointer; z-index: 6;
  box-shadow: 0 4px 14px rgba(176, 0, 0, 0.45);
  &:disabled { opacity: 0.75; cursor: default; }
}

.lottery__actions {
  display: flex; gap: 12px; margin: 18px $page-padding 0;
}
.lottery__btn {
  flex: 1; height: 46px; border: none; border-radius: $radius-pill;
  background: linear-gradient(135deg, #D00000, #B00000); color: #fff;
  font-size: 16px; font-weight: 700; cursor: pointer;
  box-shadow: 0 6px 16px rgba(176, 0, 0, 0.35);
  &:disabled { opacity: 0.6; cursor: default; }
  &--gold {
    background: linear-gradient(135deg, #E8B873, #D4A574);
    box-shadow: 0 6px 16px rgba(212, 165, 116, 0.4);
  }
}

.lottery__records {
  margin: 18px $page-padding 0;
  background: $color-card; border-radius: $radius-lg; padding: 14px 14px 6px;
}
.lottery__records-head {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 14px; font-weight: 700; color: $color-text-primary;
  padding-bottom: 10px; border-bottom: 1px solid $color-border;
}
.lottery__records-clear {
  font-size: 12px; font-weight: 400; color: $color-text-tertiary; cursor: pointer;
}
.lottery__record-list { max-height: 320px; overflow-y: auto; }
.record-item {
  display: flex; align-items: center; gap: 8px;
  padding: 11px 0; border-bottom: 1px solid $color-border;
  &:last-child { border-bottom: none; }
}
.record-item__dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: $color-primary; flex-shrink: 0;
}
.record-item__name { flex: 1; font-size: 14px; color: $color-text-primary; }
.record-item__time { font-size: 12px; color: $color-text-tertiary; }
.lottery__records-empty {
  margin: 14px 0; font-size: 13px; color: $color-text-tertiary; text-align: center;
}

.lottery__rule {
  margin: 18px $page-padding 24px;
  font-size: 12px; color: $color-text-tertiary; line-height: 1.6; text-align: center;
}

/* 结果弹窗 */
.result {
  position: absolute; top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 300px; background: #fff; border-radius: 20px;
  padding: 26px 22px 22px; text-align: center;
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.22);
  .result__img-wrap {
    width: 120px; height: 120px; margin: 0 auto 14px;
    border-radius: 16px; background: $color-surface;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
  }
  .result__img {
    width: 112px; height: 112px; border-radius: 12px; object-fit: cover;
    display: block; background: $color-surface;
  }
  .result__title { margin: 0; font-size: 14px; color: $color-text-tertiary; }
  .result__name { margin: 6px 0 20px; font-size: 22px; font-weight: 800; color: $color-primary; }
  .result__btns { display: flex; gap: 12px; }
  .result__btn {
    flex: 1; height: 44px; border: none; border-radius: $radius-pill;
    background: linear-gradient(135deg, #D00000, #B00000); color: #fff;
    font-size: 15px; font-weight: 600; cursor: pointer;
    &--ghost { background: #f2f2f2; color: $color-text-primary; }
  }
}
</style>
