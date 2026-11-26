import { ref } from 'vue'

const STORAGE_KEY = 'app-theme'

const isDark = ref(false)

function apply(): void {
  document.documentElement.classList.toggle('dark', isDark.value)
}

/**
 * 深色模式开关：偏好持久化到 localStorage，跟随 Element Plus 的 dark CSS vars。
 */
export function useTheme() {
  if (localStorage.getItem(STORAGE_KEY) !== null) {
    isDark.value = localStorage.getItem(STORAGE_KEY) === 'dark'
  } else {
    // 未设置过偏好时跟随系统
    isDark.value = window.matchMedia('(prefers-color-scheme: dark)').matches
  }
  apply()

  function toggle(): void {
    isDark.value = !isDark.value
    localStorage.setItem(STORAGE_KEY, isDark.value ? 'dark' : 'light')
    apply()
  }

  return { isDark, toggle }
}
