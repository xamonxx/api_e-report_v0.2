// ── Dashboard Query Hook ──────────────────────────────────────

import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { DashboardData } from '@/types/dashboard'

interface ApiResponse {
  data: DashboardData
}

export function useDashboard() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api.get<ApiResponse>('/api/v1/dashboard'),
    staleTime: 5 * 60 * 1000,   // 5 min
    retry: 2,
  })
}