// ── Dashboard Types ─────────────────────────────────────────

export interface DashboardStats {
  total_leads?: number
  total_accounts?: number
  pending_leads?: number
  completed_this_month?: number
  cancelled_leads?: number
  conversion_rate?: number
  growth_percent?: number
  active_accounts?: number
  avg_conversion?: number
  pending_surveys?: number
  [key: string]: number | string | undefined
}

export interface StatusDistribution {
  id: number
  name: string
  count: number
  css_class: string | null
}

export interface NeedsDistribution {
  id: number
  name: string
  count: number
}

export interface RecentConsultation {
  id: number
  consultation_id: string
  client_name: string
  phone: string | null
  type: string | null
  status: string | null
  status_css_class: string | null
  scheduled_at: string | null
  created_at: string | null
  account_name: string | null
}

export interface UpcomingItem {
  id: number
  consultation_id: string
  client_name: string
  phone: string | null
  scheduled_at: string | null
  account_name: string | null
  duration_minutes: number
}

export interface AccountRanking {
  id: number
  name: string
  total_leads: number
  deals: number
  conversion_rate: number
  admins: Array<{ id: number; name: string }>
}

export interface AdminAttendance {
  id: number
  name: string
  account_name: string | null
  has_reported: boolean
  reported_at: string | null
  report_category: string | null
}

export interface DashboardData {
  stats: DashboardStats
  recent_consultations: RecentConsultation[]
  upcoming: UpcomingItem[]
  status_distribution: StatusDistribution[]
  needs_distribution: NeedsDistribution[]
  accounts: AccountRanking[]
  admin_attendances: AdminAttendance[]
  top_admin: { id: number; name: string; deal_count: number } | null
  account: { id: number; name: string } | null
}