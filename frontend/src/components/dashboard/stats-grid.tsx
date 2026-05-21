import React from 'react'
import { cn } from '@/lib/utils'
import type { DashboardStats } from '@/types/dashboard'
import { formatNumber, formatPercent, formatGrowth } from '@/lib/utils'

// ── Stat Card ────────────────────────────────────────────────

interface StatCardProps {
  label: string
  value: string | number
  sub?: string
  highlight?: boolean
}

function StatCard({ label, value, sub, highlight }: StatCardProps) {
  return (
    <div className={cn(
      'bg-surface rounded-xl border border-outline/10 p-5 shadow-sm',
      'flex flex-col gap-1 transition-shadow hover:shadow-md'
    )}>
      <p className="text-xs font-semibold text-on-surface-variant uppercase tracking-wider">{label}</p>
      <p className={cn(
        'text-2xl font-extrabold text-on-surface tracking-tight',
        highlight && 'text-primary'
      )}>
        {value}
      </p>
      {sub && <p className="text-xs text-on-surface-variant">{sub}</p>}
    </div>
  )
}

// ── Stats Grid ──────────────────────────────────────────────

interface StatsGridProps {
  stats: DashboardStats
}

export function StatsGrid({ stats }: StatsGridProps) {
  const {
    total_leads = 0,
    total_accounts,
    pending_leads = 0,
    completed_this_month = 0,
    cancelled_leads = 0,
    conversion_rate,
    growth_percent,
    active_accounts,
    pending_surveys,
  } = stats

  return (
    <div className="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <StatCard
        label="Total Lead"
        value={formatNumber(total_leads, 0)}
        sub={growth_percent != null ? `${formatGrowth(growth_percent)} vs bulan lalu` : undefined}
        highlight
      />
      <StatCard
        label="Pending"
        value={formatNumber(pending_leads, 0)}
        sub={pending_surveys ? `${pending_surveys} menunggu survey` : 'Belum dijadwalkan'}
      />
      <StatCard
        label="Selesai Bulan Ini"
        value={formatNumber(completed_this_month, 0)}
      />
      {conversion_rate != null ? (
        <StatCard
          label="Conversion Rate"
          value={formatPercent(conversion_rate)}
          sub={active_accounts != null ? `${active_accounts} akun aktif` : undefined}
          highlight={conversion_rate >= 30}
        />
      ) : (
        <StatCard
          label="Batal"
          value={formatNumber(cancelled_leads, 0)}
        />
      )}
    </div>
  )
}