import React from 'react'
import { formatDate } from '@/lib/utils'
import type { RecentConsultation } from '@/types/dashboard'
import { cn } from '@/lib/utils'

// ── Status Badge ──────────────────────────────────────────────

function StatusBadge({ cssClass }: { cssClass: string | null | undefined }) {
  const labels: Record<string, string> = {
    'badge-pending': 'Pending',
    'badge-progress': 'Progress',
    'badge-deal': 'Deal',
    'badge-cancel': 'Batal',
    'badge-survey': 'Survey',
    'badge-inquiry': 'Inquiry',
    'badge-hold': 'Hold',
  }

  const label = cssClass ? (labels[cssClass] ?? cssClass) : '—'

  return (
    <span className={cn(
      'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold',
      cssClass
        ? cssClass.includes('pending') ? 'bg-surface-container text-on-surface-variant' :
          cssClass.includes('deal') ? 'bg-primary/15 text-primary' :
          cssClass.includes('cancel') ? 'bg-error/15 text-error' :
          cssClass.includes('survey') ? 'bg-tertiary/15 text-tertiary' :
          'bg-surface-container text-on-surface-variant'
        : 'bg-surface-container text-on-surface-variant'
    )}>
      {label}
    </span>
  )
}

// ── Recent Consultations ──────────────────────────────────────

interface RecentConsultationsProps {
  consultations: RecentConsultation[]
}

export function RecentConsultations({ consultations }: RecentConsultationsProps) {
  return (
    <div className="bg-surface rounded-xl border border-outline/10 shadow-sm overflow-hidden">
      {/* Header */}
      <div className="flex items-center justify-between px-5 py-4 border-b border-outline/10">
        <h2 className="text-base font-bold text-on-surface">Konsultasi Terbaru</h2>
        <a
          href="/consultations"
          className="text-xs text-primary font-medium hover:underline no-print"
        >
          Lihat semua →
        </a>
      </div>

      {/* List */}
      <div className="divide-y divide-outline/5">
        {consultations.length === 0 ? (
          <div className="px-5 py-10 text-center">
            <p className="text-sm text-on-surface-variant">Belum ada konsultasi</p>
          </div>
        ) : (
          consultations.map((c) => (
            <a
              key={c.id}
              href={`/consultations/${c.id}`}
              className="flex items-center justify-between px-5 py-3.5 hover:bg-surface-variant/50 transition-colors no-print"
            >
              <div className="flex items-center gap-3 flex-1 min-w-0">
                {/* Avatar */}
                <div className="w-9 h-9 rounded-full bg-primary-container flex items-center justify-center text-sm font-bold text-primary shrink-0">
                  {c.client_name?.charAt(0)?.toUpperCase() ?? '?'}
                </div>
                {/* Info */}
                <div className="flex-1 min-w-0">
                  <p className="font-semibold text-sm text-on-surface truncate">
                    {c.client_name ?? '—'}
                  </p>
                  <div className="flex items-center gap-2 text-xs text-on-surface-variant">
                    {c.type && <span className="truncate">{c.type}</span>}
                    {c.account_name && (
                      <>
                        <span>·</span>
                        <span className="truncate">{c.account_name}</span>
                      </>
                    )}
                  </div>
                </div>
              </div>
              {/* Right side */}
              <div className="flex flex-col items-end gap-1 shrink-0 ml-4">
                <StatusBadge cssClass={c.status_css_class} />
                <span className="text-xs text-on-surface-variant">
                  {formatDate(c.scheduled_at ?? c.created_at ?? null)}
                </span>
              </div>
            </a>
          ))
        )}
      </div>
    </div>
  )
}