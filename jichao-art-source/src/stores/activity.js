import { defineStore } from 'pinia'
import { ref } from 'vue'
import request from '@/utils/request'

// 活动中心：合成活动数据
// MOCK_REPLACED: 原数据来自本文件内联 mock 合成活动列表，
// 现已接入真实接口：GET /api/synthesis/activities（列表）、
// GET /api/synthesis/activities/:id（详情，含材料与我持有数量）、POST /api/synthesis/submit（提交合成）
export const useActivityStore = defineStore('activity', () => {
  // 合成活动列表
  const synthesisActivities = ref([])

  async function fetchSynthesisActivities() {
    const res = await request.get('/synthesis/activities')
    synthesisActivities.value = (res.list || []).map((a) => ({
      id: String(a.activityId),
      title: a.title,
      coverImage: a.image || a.resultCollectible?.image || '',
      desc: a.rules || '',
      startTime: (a.startTime || '').slice(0, 16),
      endTime: (a.endTime || '').slice(0, 16),
      type: a.type,
      result: {
        id: String(a.resultCollectible?.id || ''),
        name: a.resultCollectible?.name || '',
        coverImage: a.resultCollectible?.image || ''
      },
      raw: a
    }))
    return synthesisActivities.value
  }

  // 合成活动详情（含材料配置与我持有数量）
  async function fetchSynthesisDetail(id) {
    const d = await request.get(`/synthesis/activities/${id}`)
    return {
      id: String(d.activityId),
      title: d.title,
      coverImage: d.resultCollectible?.image || '',
      desc: d.rules || '',
      startTime: (d.startTime || '').slice(0, 16),
      endTime: (d.endTime || '').slice(0, 16),
      type: d.type,
      myCount: d.myCount || 0,
      materials: (d.materials || []).map((m) => ({
        id: String(m.collectibleId),
        name: m.name,
        coverImage: m.image,
        count: m.count,
        myAvailable: m.myAvailable
      })),
      result: {
        id: String(d.resultCollectible?.id || ''),
        name: d.resultCollectible?.name || '',
        coverImage: d.resultCollectible?.image || ''
      },
      raw: d
    }
  }

  // 提交合成（真实接口：POST /api/synthesis/submit，后端事务消耗材料并生成产物）
  async function submitSynthesis(activityId) {
    const res = await request.post('/synthesis/submit', { activityId: Number(activityId) })
    return res // { recordId, resultCollectible: { id, name, image, no } }
  }

  function getSynthesis(id) {
    return synthesisActivities.value.find((a) => a.id === String(id)) || null
  }

  return { synthesisActivities, fetchSynthesisActivities, fetchSynthesisDetail, submitSynthesis, getSynthesis }
})
