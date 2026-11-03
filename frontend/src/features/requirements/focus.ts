import type { FieldErrors } from 'react-hook-form'
import { REQUIREMENT_FIELDS, type RequirementFormValues } from './schema'

// Error keys use the API notation: "name", "required_documents", "required_documents.0.category".
export function clientErrorKeys(errors: FieldErrors<RequirementFormValues>): string[] {
  const keys: string[] = []
  for (const field of REQUIREMENT_FIELDS) {
    const error = errors[field]
    if (!error) continue
    if (field === 'required_documents' && Array.isArray(error)) {
      error.forEach((row, index) => {
        if (row?.name) keys.push(`required_documents.${index}.name`)
        if (row?.category) keys.push(`required_documents.${index}.category`)
      })
      if ((error as { root?: unknown }).root) keys.push('required_documents')
    } else {
      keys.push(field)
    }
  }
  return keys
}

function order(key: string): number {
  const [field, index = '0', part = ''] = key.split('.')
  const position = REQUIREMENT_FIELDS.indexOf(field as (typeof REQUIREMENT_FIELDS)[number])
  return position * 10_000 + Number(index) * 10 + (part === 'category' ? 1 : 0)
}

// Element id of a field: "required_documents.0.name" -> "required_documents-0-name".
export function elementIdForKey(key: string): string {
  if (key === 'required_documents') {
    return document.getElementById('required_documents-0-name') ? 'required_documents-0-name' : 'add-required-document'
  }
  return key.split('.').join('-')
}

// Scrolls to and focuses the first invalid field, following the order of the form.
export function focusFirstInvalid(keys: string[]) {
  const first = [...keys].sort((a, b) => order(a) - order(b))[0]
  if (!first) return
  const element = document.getElementById(elementIdForKey(first))
  element?.scrollIntoView?.({ behavior: 'smooth', block: 'center' })
  element?.focus()
}
