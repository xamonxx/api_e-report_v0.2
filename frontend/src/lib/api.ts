// ── API Client ─────────────────────────────────────────────

const API_BASE = import.meta.env.VITE_API_URL ?? ''

type HttpMethod = 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'

interface ApiError {
  response: { data: { success?: boolean; message?: string; errors?: Record<string, string[]> } }
}

async function request<T>(url: string, options?: RequestInit): Promise<T> {
  const res = await fetch(`${API_BASE}${url}`, {
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...options?.headers,
    },
    ...options,
  })

  if (!res.ok) {
    let message = res.statusText
    try {
      const body = await res.json()
      message = body?.message ?? body?.errors ? JSON.stringify(body.errors) : message
    } catch {
      // ignore parse error
    }
    const error: ApiError = { response: { data: { message } } }
    throw error
  }

  return res.json() as Promise<T>
}

export const api = {
  get<T>(url: string, params?: Record<string, string | number>): Promise<T> {
    const query = params
      ? '?' + new URLSearchParams(params as Record<string, string>).toString()
      : ''
    return request<T>(`${url}${query}`, { method: 'GET' })
  },

  post<T>(url: string, data?: unknown): Promise<T> {
    return request<T>(url, {
      method: 'POST',
      body: data != null ? JSON.stringify(data) : undefined,
    })
  },

  patch<T>(url: string, data?: unknown): Promise<T> {
    return request<T>(url, {
      method: 'PATCH',
      body: data != null ? JSON.stringify(data) : undefined,
    })
  },

  put<T>(url: string, data?: unknown): Promise<T> {
    return request<T>(url, {
      method: 'PUT',
      body: data != null ? JSON.stringify(data) : undefined,
    })
  },

  delete<T>(url: string): Promise<T> {
    return request<T>(url, { method: 'DELETE' })
  },
}

export type { ApiError }