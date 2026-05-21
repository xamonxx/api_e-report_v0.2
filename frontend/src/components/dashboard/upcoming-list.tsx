import React from 'react'
import { formatDateTime } from '@/lib/utils'
import type { UpcomingItem } from '@/types/dashboard'
import { cn } from '@/lib/utils'

// ── Upcoming List ─────────────────────────────────────────────

interface UpcomingListProps {
  items: UpcomingItem[]
}

export function UpcomingList({ items }: UpcomingListProps) {
  return (
    <div className="bg-surface rounded-xl border border-outline/10 shadow-sm overflow-hidden">
      {/* Header */}
      <div className="flex items-center justify-between px-5 py-4 border-b border-outline/10">
        <h2 className="text-base font-bold text-on-surface">Mendatang</h2>
        <span className="text-xs text-on-surface-variant">
          {items.length > 0 ? `${items.length} terjadwal` : 'Tidak ada'}
        </span>
      </div>

      {/* List */}
      <div className="divide-y divide-outline/5">
        {items.length === 0 ? (
          <div className="px-5 py-10 text-center">
            <p className="text-sm text-on-surface-variant">Tidak ada konsultasi mendatang</p>
          </div>
        ) : (
          items.map((item) => (
            <a
              key={item.id}
              href={`/consultations/${item.id}`}
              className="flex items-start gap-3 px-5 py-3.5 hover:bg-surface-variant/50 transition-colors no-print"
            >
              {/* Icon */}
              <div className="w-9 h-9 rounded-lg bg-tertiary-container flex items-center justify-center shrink-0 mt-0.5">
                <svg className="w-4 h-4 text-tertiary" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                  <line x1="16" y1="2" x2="16" y2="6"/>
                  <line x1="8" y1="2" x2="8" y2="6"/>
                  <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
              </div>
              {/* Info */}
              <div className="flex-1 min-w-0">
                <p className="font-semibold text-sm text-on-surface truncate">
                  {item.client_name ?? '—'}
                </p>
                <div className="flex items-center gap-1 mt-0.5 text-xs text-on-surface-variant">
                  <span>{formatDateTime(item.scheduled_at ?? null)}</span>
                  {item.duration_minutes && (
                    <>
                      <span>·</span>
                      <span>{item.duration_minutes} min</span>
                    </>
                  )}
                </div>
                {item.account_name && (
                  <p className="text-xs text-on-surface-variant mt-0.5 truncate">
                    {item.account_name}
                  </p>
                )}
              </div>
              {/* Phone */}
              {item.phone && (
                <a
                  href={`tel:${item.phone}`}
                  className="shrink-0 text-primary text-sm font-medium hover:underline"
                  onClick={(e) => e.stopPropagation()}
                >
                  {item.phone}
                </a>
              )}
            </a>
          ))
        )}
      </div>
    </div>
  )
}