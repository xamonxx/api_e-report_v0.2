// ── Utilities ──────────────────────────────────────────────

import { type ClassValue, clsx } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export function formatDate(dateStr: string | null | undefined): string {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  if (isNaN(d.getTime())) return '—'
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
}

export function formatDateTime(dateStr: string | null | undefined): string {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  if (isNaN(d.getTime())) return '—'
  return d.toLocaleString('id-ID', {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  })
}

export function formatNumber(value: number | undefined | null, decimals = 1): string {
  if (value == null) return '0'
  return Number(value).toFixed(decimals).replace(/\.0+$/, '')
}

export function formatPercent(value: number | undefined | null): string {
  if (value == null) return '0%'
  return `${formatNumber(value, 1)}%`
}

export function formatGrowth(value: number | undefined | null): string {
  if (value == null) return '—'
  const sign = value >= 0 ? '+' : ''
  return `${sign}${formatNumber(value)}%`
}

export function statusLabel(cssClass: string | null | undefined): string {
  const map: Record<string, string> = {
    'badge-pending': 'Pending',
    'badge-progress': 'Progress',
    'badge-deal': 'Selesai/Deal',
    'badge-cancel': 'Batal',
    'badge-survey': 'Survey',
  }
  return cssClass ? (map[cssClass] ?? cssClass) : '—'
}

export function truncate(str: string | null | undefined, maxLen = 30): string {
  if (!str) return ''
  return str.length > maxLen ? str.slice(0, maxLen) + '…' : str
}