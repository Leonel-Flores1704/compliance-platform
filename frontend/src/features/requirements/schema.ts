import { z } from 'zod'
import type { CatalogOption } from '../organizations/api'
import type { ComplianceRequirementPayload, UserOption } from './api'
import { displayDateToIso } from './dates'
import { REQUIREMENT_MESSAGES as M, REQUIREMENT_STATUSES, type RequirementStatus } from './messages'

// Same rules as backend/app/Http/Requests/StoreComplianceRequirementRequest.php (the source of truth).
export function requirementSchema(options?: { users?: UserOption[]; categories?: CatalogOption[] }) {
  const categoryExists = (code: string) => !options?.categories || code === '' || options.categories.some((c) => c.code === code)
  const userExists = (id: string) => !options?.users || id === '' || options.users.some((u) => String(u.id) === id)

  return z.object({
    name: z.string().trim().min(1, M.nameRequired).min(3, M.nameLength).max(150, M.nameLength),
    description: z.string().trim().min(1, M.descriptionRequired).min(10, M.descriptionLength).max(2000, M.descriptionLength),
    regulation: z.string().trim().min(1, M.regulationRequired).min(2, M.regulationLength).max(150, M.regulationLength),
    deadline: z
      .string()
      .trim()
      .min(1, M.deadlineRequired)
      .refine((value) => value === '' || displayDateToIso(value) !== null, M.deadlineInvalid),
    required_documents: z
      .array(
        z.object({
          name: z.string().trim().min(1, M.documentIncomplete).max(150, M.documentNameLength),
          category: z.string().min(1, M.documentIncomplete).refine(categoryExists, M.documentIncomplete),
        }),
      )
      .min(1, M.documentsRequired),
    responsible_user_id: z.string().min(1, M.responsibleRequired).refine(userExists, M.responsibleInvalid),
    status: z.enum(REQUIREMENT_STATUSES.map((s) => s.code) as [RequirementStatus, ...RequirementStatus[]], M.statusInvalid),
  })
}

export type RequirementFormValues = z.input<ReturnType<typeof requirementSchema>>

export const REQUIREMENT_FIELDS = [
  'name',
  'description',
  'regulation',
  'deadline',
  'required_documents',
  'responsible_user_id',
  'status',
] as const satisfies readonly (keyof RequirementFormValues)[]

export type RequirementField = (typeof REQUIREMENT_FIELDS)[number]

export const EMPTY_REQUIREMENT_FORM: RequirementFormValues = {
  name: '',
  description: '',
  regulation: '',
  deadline: '',
  required_documents: [{ name: '', category: '' }],
  responsible_user_id: '',
  status: 'pending',
}

export function toRequirementPayload(values: RequirementFormValues): ComplianceRequirementPayload {
  return {
    name: values.name.trim(),
    description: values.description.trim(),
    regulation: values.regulation.trim(),
    deadline: displayDateToIso(values.deadline) ?? values.deadline,
    required_documents: values.required_documents.map((d) => ({ name: d.name.trim(), category: d.category })),
    responsible_user_id: Number(values.responsible_user_id),
    status: values.status,
  }
}
