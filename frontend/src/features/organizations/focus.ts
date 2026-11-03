import type { OrganizationField } from './schema'

// Scrolls to and focuses the first invalid field, following the order of the form.
export function focusFirstInvalidField(fields: readonly OrganizationField[], errors: Partial<Record<OrganizationField, unknown>>) {
  const first = fields.find((field) => errors[field])
  if (!first) return
  const element = document.getElementById(first === 'business_activities' ? 'business_activities-0' : first)
  element?.scrollIntoView?.({ behavior: 'smooth', block: 'center' })
  element?.focus()
}
