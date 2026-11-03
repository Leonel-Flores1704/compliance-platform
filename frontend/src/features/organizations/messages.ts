// User-facing texts for organization registration (CMP-14).
// They must stay identical to backend/app/Support/OrganizationMessages.php.
export const ORGANIZATION_MESSAGES = {
  legalNameRequired: 'Legal name is required.',
  legalNameLength: 'Legal name must be between 3 and 150 characters.',
  legalNameTaken: 'An organization with this legal name already exists.',
  organizationTypeRequired: 'Select an organization type.',
  organizationTypeInvalid: 'The selected organization type is not valid.',
  industryRequired: 'Select an industry.',
  industryInvalid: 'The selected industry is not valid.',
  stateRequired: 'Select a state.',
  municipalityRequired: 'Municipality is required.',
  municipalityLength: 'Municipality must be between 2 and 100 characters.',
  employeesRequired: 'Number of employees is required.',
  employeesInvalid: 'Number of employees must be a whole number between 1 and 1,000,000.',
  activitiesRequired: 'Select at least one business activity.',
  activitiesMax: 'You can select up to 10 business activities.',
  activitiesInvalid: 'The selected business activity is not valid.',
  forbidden: 'You do not have permission to register organizations.',
  created: 'Organization registered successfully.',
} as const

export const ORGANIZATION_LABELS = {
  legal_name: 'Legal name',
  organization_type: 'Organization type',
  industry: 'Industry',
  state: 'State',
  municipality: 'Municipality',
  employees_count: 'Number of employees',
  business_activities: 'Business activities',
} as const
