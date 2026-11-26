import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { fetchNotificationSummary } from '@/api/notifications'
import type { NotificationType } from '@/types'

/**
 * 未读数放在 store 里：顶栏铃铛和消息中心页共享同一份状态，
 * 页面上标记已读后铃铛能立刻归零，不用等下一次轮询。
 */
export const useNotificationStore = defineStore('notifications', () => {
  const unread = ref(0)
  const total = ref(0)
  const byType = ref<Partial<Record<NotificationType, number>>>({})
  const loading = ref(false)

  const hasUnread = computed(() => unread.value > 0)
  /** 铃铛上最多显示 99+ */
  const badge = computed(() => (unread.value > 99 ? '99+' : String(unread.value)))

  async function refresh(): Promise<void> {
    loading.value = true
    try {
      const summary = await fetchNotificationSummary()
      unread.value = summary.unread
      total.value = summary.total
      byType.value = summary.by_type
    } catch {
      // 静默：顶栏轮询失败不影响任何操作，等下一轮
    } finally {
      loading.value = false
    }
  }

  /** 本地扣减，用于标记已读后即时反馈；下一轮 refresh 会以后端为准 */
  function decrease(count = 1): void {
    unread.value = Math.max(0, unread.value - count)
  }

  function reset(): void {
    unread.value = 0
    total.value = 0
    byType.value = {}
  }

  return { unread, total, byType, loading, hasUnread, badge, refresh, decrease, reset }
})
