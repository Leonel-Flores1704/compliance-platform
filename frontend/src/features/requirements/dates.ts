// The UI shows dates as DD/MM/YYYY; the API uses YYYY-MM-DD.

const DISPLAY_DATE = /^(\d{2})\/(\d{2})\/(\d{4})$/

/** Converts DD/MM/YYYY to YYYY-MM-DD, or returns null when the date does not exist. */
export function displayDateToIso(value: string): string | null {
  const match = DISPLAY_DATE.exec(value.trim())
  if (!match) return null
  const [, dd, mm, yyyy] = match
  const day = Number(dd)
  const month = Number(mm)
  const year = Number(yyyy)
  const date = new Date(Date.UTC(year, month - 1, day))
  const exists = date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day
  return exists && year >= 1900 ? `${yyyy}-${mm}-${dd}` : null
}

/** Converts YYYY-MM-DD to DD/MM/YYYY. */
export function isoToDisplayDate(value: string): string {
  const [yyyy, mm, dd] = value.split('-')
  return yyyy && mm && dd ? `${dd}/${mm}/${yyyy}` : value
}
