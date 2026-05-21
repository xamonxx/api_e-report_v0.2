import React from 'react'
import { useDashboard } from '@/hooks/dashboard/use-dashboard'
import { StatsGrid } from '@/components/dashboard/stats-grid'
import { RecentConsultations } from '@/components/dashboard/recent-consultations'
import { UpcomingList } from '@/components/dashboard/upcoming-list'
import { Skeleton } from '@/components/ui/skeleton'
import { DashboardSkeleton } from '@/components/dashboard/dashboard-skeleton'
import { cn } from '@/lib/utils'

// ── Admin Dashboard ──────────────────────────────────────────

function AdminDashboard() {
  const { data, isLoading, isError, error } = useDashboard()

  if (isLoading) return <DashboardSkeleton />
  if (isError) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="text-center space-y-2">
          <div className="text-error text-4xl">⚠</div>
          <p className="text-on-surface-variant font-medium">Gagal memuat dashboard</p>
          <p className="text-on-surface-variant text-sm">
            {(error as { message?: string })?.message ?? 'Silakan refresh halaman'}
          </p>
        </div>
      </div>
    )
  }

  const stats = data?.data?.stats ?? {}
  const recent = data?.data?.recent_consultations ?? []
  const upcoming = data?.data?.upcoming ?? []
  const statusDist = data?.data?.status_distribution ?? []

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-extrabold text-on-surface tracking-tight font-headline">
            Dashboard
          </h1>
          <p className="text-sm text-on-surface-variant mt-1">
            {data?.data?.account
              ? `Mengelola lead untuk akun ${data.data.account.name}`
              : 'Selamat datang di E-REPORT'}
          </p>
        </div>
        <a
          href="/consultations/create"
          className={cn(
            'bg-primary text-on-primary px-6 py-3 rounded-xl font-bold',
            'flex items-center justify-center gap-2',
            'hover:bg-primary-dim transition-all shadow-xl shadow-primary/10',
            'no-print w-full md:w-auto text-sm'
          )}
        >
          <span>+ Tambah Konsultasi Baru</span>
        </a>
      </div>

      {/* Stats grid */}
      <StatsGrid stats={stats} />

      {/* Recent + Upcoming */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <RecentConsultations consultations={recent} />
        <UpcomingList items={upcoming} />
      </div>
    </div>
  )
}

// ── Super Admin Dashboard ─────────────────────────────────────

function SuperAdminDashboard() {
  const { data, isLoading, isError } = useDashboard()

  if (isLoading) return <DashboardSkeleton />
  if (isError) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="text-center space-y-2">
          <div className="text-error text-4xl">⚠</div>
          <p className="text-on-surface-variant font-medium">Gagal memuat dashboard</p>
          <p className="text-on-surface-variant text-sm">Silakan refresh halaman</p>
        </div>
      </div>
    )
  }

  const stats = data?.data?.stats ?? {}
  const accounts = data?.data?.accounts ?? []
  const adminAttendances = data?.data?.admin_attendances ?? []
  const statusDist = data?.data?.status_distribution ?? []

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-extrabold text-on-surface tracking-tight font-headline">
            Dashboard Super Admin
          </h1>
          <p className="text-sm text-on-surface-variant mt-1">
            Ringkasan kinerja seluruh akun
          </p>
        </div>
        <a
          href="/accounts"
          className={cn(
            'bg-secondary text-on-secondary px-6 py-3 rounded-xl font-bold',
            'flex items-center justify-center gap-2',
            'hover:opacity-90 transition-all',
            'no-print w-full md:w-auto text-sm'
          )}
        >
          <span>Kelola Akun</span>
        </a>
      </div>

      {/* Stats grid */}
      <StatsGrid stats={stats} />

      {/* Account ranking */}
      <div className="bg-surface rounded-xl border border-outline/10 p-6 shadow-sm">
        <h2 className="text-lg font-bold text-on-surface mb-4">Peringkat Akun</h2>
        {accounts.length === 0 ? (
          <p className="text-sm text-on-surface-variant">Belum ada data akun</p>
        ) : (
          <div className="space-y-3">
            {accounts.map((account, idx) => (
              <div
                key={account.id}
                className="flex items-center justify-between p-3 rounded-lg bg-surface-dim hover:bg-surface-variant transition-colors"
              >
                <div className="flex items-center gap-3">
                  <span className={cn(
                    'w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold',
                    idx === 0 ? 'bg-primary text-on-primary' : 'bg-surface-container text-on-surface-variant'
                  )}>
                    {idx + 1}
                  </span>
                  <div>
                    <p className="font-semibold text-sm text-on-surface">{account.name}</p>
                    <p className="text-xs text-on-surface-variant">
                      {account.total_leads} leads · {account.deals} deal
                    </p>
                  </div>
                </div>
                <span className={cn(
                  'text-sm font-bold',
                  account.conversion_rate >= 30 ? 'text-primary' : 'text-on-surface-variant'
                )}>
                  {account.conversion_rate}%
                </span>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Admin attendances */}
      <div className="bg-surface rounded-xl border border-outline/10 p-6 shadow-sm">
        <h2 className="text-lg font-bold text-on-surface mb-4">Absensi Admin Hari Ini</h2>
        {adminAttendances.length === 0 ? (
          <p className="text-sm text-on-surface-variant">Belum ada data absensi</p>
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            {adminAttendances.map((att) => (
              <div
                key={att.id}
                className={cn(
                  'flex items-center gap-3 p-3 rounded-lg border',
                  att.has_reported
                    ? 'bg-primary-container/10 border-primary/20'
                    : 'bg-error-container/10 border-error/20'
                )}
              >
                <div className={cn(
                  'w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold',
                  att.has_reported
                    ? 'bg-primary/20 text-primary'
                    : 'bg-error/20 text-error'
                )}>
                  {att.name.charAt(0).toUpperCase()}
                </div>
                <div className="flex-1 min-w-0">
                  <p className="font-semibold text-sm text-on-surface truncate">{att.name}</p>
                  <p className="text-xs text-on-surface-variant truncate">
                    {att.account_name ?? '—'}
                  </p>
                </div>
                <span className={cn(
                  'text-xs font-bold px-2 py-1 rounded-full',
                  att.has_reported
                    ? 'bg-primary/20 text-primary'
                    : 'bg-error/20 text-error'
                )}>
                  {att.has_reported ? att.report_category ?? 'Hadir' : 'Belum'}
                </span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

// ── Root App ──────────────────────────────────────────────

export function DashboardApp() {
  // Detect role from the page — injected by Laravel blade
  const isSuperAdmin = document.body.dataset.role === 'super_admin'

  return isSuperAdmin ? <SuperAdminDashboard /> : <AdminDashboard />
}