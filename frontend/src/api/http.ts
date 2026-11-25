import axios, { AxiosError, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios'
import { ElMessage } from '@/plugins/element'
import type { ApiEnvelope, TokenPair } from '@/types'
import { clearTokens, getAccessToken, getRefreshToken, setTokens } from '@/utils/token'
import { emitUnauthorized } from '@/utils/session'

export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '/api'

/** silent: 不弹全局错误提示，由调用方自己处理（用于后台静默请求） */
type RequestConfig = AxiosRequestConfig & { silent?: boolean }
type RetriableConfig = InternalAxiosRequestConfig & { _retry?: boolean; silent?: boolean }

export class RequestError extends Error {
  constructor(
    public readonly status: number,
    message: string,
    public readonly code?: number,
  ) {
    super(message)
    this.name = 'RequestError'
  }
}

const service = axios.create({ baseURL: API_BASE_URL, timeout: 15000 })

/** 刷新 token 用的裸实例：不挂拦截器，避免 401 递归 */
const refreshClient = axios.create({ baseURL: API_BASE_URL, timeout: 15000 })

let refreshing: Promise<string> | null = null

async function requestNewToken(): Promise<string> {
  const refreshToken = getRefreshToken()
  if (!refreshToken) {
    throw new RequestError(401, '没有可用的刷新凭证')
  }

  const { data } = await refreshClient.post<ApiEnvelope<TokenPair>>('/auth/refresh', {
    refresh_token: refreshToken,
  })
  if (data.code !== 0) {
    throw new RequestError(401, data.message, data.code)
  }

  setTokens(data.data)
  return data.data.access_token
}

/**
 * 单飞刷新：并发多个 401 时只发一次刷新请求，其余请求等同一个 Promise。
 */
export function refreshAccessToken(): Promise<string> {
  if (!refreshing) {
    refreshing = requestNewToken().finally(() => {
      refreshing = null
    })
  }
  return refreshing
}

service.interceptors.request.use((config) => {
  const token = getAccessToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

service.interceptors.response.use(
  (response) => response,
  async (error: AxiosError<ApiEnvelope<unknown>>) => {
    const config = error.config as RetriableConfig | undefined
    const status = error.response?.status ?? 0
    const payload = error.response?.data
    const message =
      payload?.message || (status === 0 ? '网络异常，请确认后端服务（9501）是否可用' : error.message)
    // 登录/刷新接口自身的 401 属于业务失败，不能触发自动刷新
    const isAuthEndpoint = Boolean(config?.url && /\/auth\/(login|refresh)$/.test(config.url))

    if (status === 401 && !isAuthEndpoint) {
      if (config && !config._retry) {
        config._retry = true
        try {
          const token = await refreshAccessToken()
          config.headers.Authorization = `Bearer ${token}`
          return await service.request(config)
        } catch {
          clearTokens()
          emitUnauthorized()
          return Promise.reject(new RequestError(401, '登录已过期，请重新登录', 401))
        }
      }

      clearTokens()
      emitUnauthorized()
      return Promise.reject(new RequestError(401, '登录已过期，请重新登录', 401))
    }

    if (!config?.silent) {
      ElMessage.error(message)
    }

    return Promise.reject(new RequestError(status, message, payload?.code))
  },
)

async function request<T>(config: RequestConfig): Promise<T> {
  const response = await service.request<ApiEnvelope<T>>(config)
  const body = response.data

  if (body.code !== 0) {
    throw new RequestError(response.status, body.message, body.code)
  }

  return body.data
}

export const http = {
  get<T>(url: string, config?: RequestConfig): Promise<T> {
    return request<T>({ ...config, url, method: 'get' })
  },
  post<T>(url: string, data?: unknown, config?: RequestConfig): Promise<T> {
    return request<T>({ ...config, url, data, method: 'post' })
  },
  put<T>(url: string, data?: unknown, config?: RequestConfig): Promise<T> {
    return request<T>({ ...config, url, data, method: 'put' })
  },
  delete<T>(url: string, config?: RequestConfig): Promise<T> {
    return request<T>({ ...config, url, method: 'delete' })
  },
}

/**
 * 下载二进制文件（带鉴权头），返回 Blob；错误仍然走统一拦截器（401 自动刷新等）。
 */
export async function downloadFile(url: string, params?: Record<string, unknown>): Promise<Blob> {
  const response = await service.get(url, { params, responseType: 'blob' })
  return response.data as Blob
}
