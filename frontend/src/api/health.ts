import { http } from '@/api/http'

export interface HealthOverview {
  database: {
    version?: string
    threads_connected?: number
    slow_queries?: number
    uptime_days?: number | null
    error?: string
  }
  redis: {
    used_memory_human?: string
    connected_clients?: number
    total_keys?: number
    hit_rate?: number | null
    uptime_days?: number | null
    error?: string
  }
  runtime: {
    php: string
    swoole: string
    memory_used: number
    memory_peak: number
  }
  queue: {
    waiting?: number
    delayed?: number
    failed?: number
    error?: string
  }
  disk: {
    total_gb?: number
    free_gb?: number
    used_percent?: number | null
    error?: string
  }
  crontab: {
    enabled?: number
    failing?: number
    items?: Array<{
      id: number
      name: string
      rule: string
      consecutive_failures: number
      last_run_at: string | null
      next_run_at: string | null
    }>
    error?: string
  }
  api_stats: {
    total?: number
    avg_ms?: number | null
    p95_ms?: number | null
    slowest?: Array<{ path: string; count: number; avg_ms: number; max_ms: number }>
    error?: string
  }
  recent_errors: string[]
  collected_at: string
}

export function fetchHealth(): Promise<HealthOverview> {
  return http.get<HealthOverview>('/health')
}
