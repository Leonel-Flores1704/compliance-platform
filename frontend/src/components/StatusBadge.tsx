const STYLES: Record<string, string> = {
  compliant: 'bg-green-100 text-green-800 ring-green-600/30',
  pending: 'bg-gray-100 text-gray-800 ring-gray-500/30',
  at_risk: 'bg-amber-100 text-amber-800 ring-amber-600/30',
  overdue: 'bg-red-100 text-red-800 ring-red-600/30',
}

export default function StatusBadge({ code, label }: { code: string; label: string }) {
  return (
    <span
      data-testid="status-badge"
      className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ${STYLES[code] ?? STYLES.pending}`}
    >
      {label}
    </span>
  )
}
