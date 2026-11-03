// User-facing texts for compliance requirement creation (CMP-15).
// They must stay identical to backend/app/Support/RequirementMessages.php.
export const REQUIREMENT_MESSAGES = {
  nameRequired: 'Requirement name is required.',
  nameLength: 'Requirement name must be between 3 and 150 characters.',
  descriptionRequired: 'Description is required.',
  descriptionLength: 'Description must be between 10 and 2,000 characters.',
  regulationRequired: 'Regulation is required.',
  regulationLength: 'Regulation must be between 2 and 150 characters.',
  deadlineRequired: 'Deadline is required.',
  deadlineInvalid: 'Enter a valid deadline date.',
  documentsRequired: 'Add at least one required document.',
  documentIncomplete: 'Each required document needs a name and a category.',
  documentNameLength: 'Required document name must be at most 150 characters.',
  responsibleRequired: 'Select a responsible user.',
  responsibleInvalid: 'The selected responsible user is not valid.',
  statusInvalid: 'Status must be one of: Compliant, Pending, At Risk, Overdue.',
  forbidden: 'You do not have permission to create compliance requirements.',
  created: 'Compliance requirement created successfully.',
} as const

export const REQUIREMENT_LABELS = {
  name: 'Name',
  description: 'Description',
  regulation: 'Regulation',
  deadline: 'Deadline',
  required_documents: 'Required documents',
  responsible_user_id: 'Responsible user',
  status: 'Initial status',
} as const

// Stored value -> label (BR-004). Same order as the status message.
export const REQUIREMENT_STATUSES = [
  { code: 'compliant', label: 'Compliant' },
  { code: 'pending', label: 'Pending' },
  { code: 'at_risk', label: 'At Risk' },
  { code: 'overdue', label: 'Overdue' },
] as const

export type RequirementStatus = (typeof REQUIREMENT_STATUSES)[number]['code']
