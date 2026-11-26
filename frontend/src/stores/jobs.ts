import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { fetchRunningJobs } from '@/api/jobs'

/**
 * 进行中的异步任务数：顶栏角标轮询共享。
 * 创建任务后调用 bump() 让角标立刻 +1，不用等下一轮轮询。
 */
export const useJobStore = defineStore('jobs', () => {
  const running = ref(0)

  const hasRunning = computed(() => running.value > 0)
  const badge = computed(() => (running.value > 99 ? '99+' : String(running.value)))

  async function refresh(): Promise<void> {
    try {
      const { count } = await fetchRunningJobs()
      running.value = count
    } catch {
      // 静默：角标轮询失败不影响操作
    }
  }

  function bump(delta = 1): void {
    running.value = Math.max(0, running.value + delta)
  }

  function reset(): void {
    running.value = 0
  }

  return { running, hasRunning, badge, refresh, bump, reset }
})
