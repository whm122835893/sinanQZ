import { defineStore } from 'pinia'
import { ref } from 'vue'

// 活动中心：合成活动数据（mock）
export const useActivityStore = defineStore('activity', () => {
  // 合成活动列表
  const synthesisActivities = ref([
    {
      id: '1',
      title: '龙虎合璧',
      coverImage: '/images/collections/cover-1.jpg',
      desc: '集齐「龙纹罗盘」与「虎纹卡牌」，合成稀有藏品「龙虎合璧」，限量发行。',
      startTime: '2026-08-20 10:00',
      endTime: '2026-08-31 23:59',
      materials: [
        { id: '1', name: '龙纹罗盘', coverImage: '/images/collections/cover-1.jpg' },
        { id: '2', name: '虎纹卡牌', coverImage: '/images/collections/cover-2.jpg' }
      ],
      result: { id: '101', name: '龙虎合璧', coverImage: '/images/collections/cover-4.jpg' }
    },
    {
      id: '2',
      title: '飞天面具',
      coverImage: '/images/collections/cover-3.jpg',
      desc: '集齐「敦煌飞天」与「青铜面具」，合成稀有藏品「飞天面具」。',
      startTime: '2026-08-25 10:00',
      endTime: '2026-09-10 23:59',
      materials: [
        { id: '6', name: '敦煌飞天', coverImage: '/images/collections/cover-1.jpg' },
        { id: '7', name: '青铜面具', coverImage: '/images/collections/cover-2.jpg' }
      ],
      result: { id: '102', name: '飞天面具', coverImage: '/images/collections/cover-5.jpg' }
    }
  ])

  function getSynthesis(id) {
    return synthesisActivities.value.find((a) => a.id === id) || null
  }

  return { synthesisActivities, getSynthesis }
})
