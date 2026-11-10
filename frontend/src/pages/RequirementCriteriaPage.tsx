import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import axios from 'axios'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { previewCriteria, saveCriteria, type CriteriaPayload } from '../features/criteria/api'
import { CRITERIA_MESSAGES as M, CRITERIA_TEXTS as T } from '../features/criteria/messages'
import {
  FIELD_LABELS,
  FIELD_OPERATORS,
  OPERATOR_LABELS,
  criteriaToRows,
  emptyValue,
  newRow,
  rowErrorKey,
  rowsToCriteria,
  validateRows,
  valueKind,
  type ConditionRow,
  type CriteriaField,
  type CriteriaOperator,
  type MatchMode,
} from '../features/criteria/model'
import { fetchOrganizationCatalogs, type CatalogOption, type OrganizationCatalogs } from '../features/organizations/api'
import { fetchRequirement } from '../features/requirements/api'
import { canCreateRequirements, useCurrentUser } from '../lib/auth'

const control =
  'block rounded-md border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-600'
const border = (error?: string) => (error ? 'border-red-600' : 'border-gray-300')

function optionsFor(field: CriteriaField | '', catalogs?: OrganizationCatalogs): CatalogOption[] {
  if (!catalogs) return []
  switch (field) {
    case 'organization_type':
      return catalogs.organization_types
    case 'industry':
      return catalogs.industries
    case 'state':
      return catalogs.states
    case 'business_activities':
      return catalogs.business_activities.map((a) => ({ code: a.code, name: `${a.code} - ${a.name}` }))
    default:
      return []
  }
}

function FieldError({ message }: { message?: string }) {
  return message ? <p className="mt-1 text-sm text-red-600">{message}</p> : null
}

function ValueInput({
  row,
  index,
  catalogs,
  error,
  onChange,
}: {
  row: ConditionRow
  index: number
  catalogs?: OrganizationCatalogs
  error?: string
  onChange: (value: string | string[]) => void
}) {
  const kind = valueKind(row.field, row.op)
  const label = `Condition ${index + 1} value`

  if (!kind) {
    return <input aria-label={label} disabled className={`${control} w-full border-gray-200 bg-gray-50`} placeholder="Value" />
  }
  if (kind === 'number') {
    return (
      <input
        aria-label={label}
        type="number"
        min={1}
        max={1000000}
        step={1}
        value={row.value as string}
        onChange={(e) => onChange(e.target.value)}
        className={`${control} w-full ${border(error)}`}
      />
    )
  }
  if (kind === 'range') {
    const [min, max] = row.value as string[]
    return (
      <div className="flex gap-2">
        <input aria-label={`${label} from`} type="number" placeholder="Min" min={1} value={min} onChange={(e) => onChange([e.target.value, max])} className={`${control} w-full ${border(error)}`} />
        <input aria-label={`${label} to`} type="number" placeholder="Max" min={1} value={max} onChange={(e) => onChange([min, e.target.value])} className={`${control} w-full ${border(error)}`} />
      </div>
    )
  }
  const options = optionsFor(row.field, catalogs)
  if (kind === 'single') {
    return (
      <select aria-label={label} value={row.value as string} onChange={(e) => onChange(e.target.value)} className={`${control} w-full ${border(error)}`}>
        <option value="">Select</option>
        {options.map((o) => (
          <option key={o.code} value={o.code}>
            {o.name}
          </option>
        ))}
      </select>
    )
  }
  const selected = row.value as string[]
  return (
    <fieldset aria-label={label} className={`max-h-40 overflow-y-auto rounded-md border p-2 ${border(error)}`}>
      {options.map((o) => (
        <label key={o.code} className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={selected.includes(o.code)}
            onChange={(e) => onChange(e.target.checked ? [...selected, o.code] : selected.filter((c) => c !== o.code))}
          />
          {o.name}
        </label>
      ))}
    </fieldset>
  )
}

export default function RequirementCriteriaPage() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { data: user, isLoading: userLoading } = useCurrentUser()
  const allowed = canCreateRequirements(user?.role)

  const { data: requirement } = useQuery({ queryKey: ['requirements', id], queryFn: () => fetchRequirement(id), enabled: allowed })
  const { data: catalogs } = useQuery({ queryKey: ['catalogs', 'organization'], queryFn: fetchOrganizationCatalogs, enabled: allowed })

  const [loaded, setLoaded] = useState(false)
  const [useCriteria, setUseCriteria] = useState(false)
  const [match, setMatch] = useState<MatchMode>('all')
  const [rows, setRows] = useState<ConditionRow[]>([])
  const [hasNestedGroups, setHasNestedGroups] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitted, setSubmitted] = useState(false)
  const [preview, setPreview] = useState<boolean | null>(null)
  const [formError, setFormError] = useState<string | null>(null)

  // Load the stored criteria once.
  useEffect(() => {
    if (requirement && !loaded) {
      const state = criteriaToRows(requirement.applicability_criteria)
      setUseCriteria(state.useCriteria)
      setMatch(state.match)
      setRows(state.rows.length > 0 ? state.rows : [newRow()])
      setHasNestedGroups(state.hasNestedGroups)
      setLoaded(true)
    }
  }, [requirement, loaded])

  const clientErrors = useMemo(() => validateRows(rows, useCriteria), [rows, useCriteria])
  const payload: CriteriaPayload = useMemo(
    () => ({ use_criteria: useCriteria, criteria: rowsToCriteria(rows, match, useCriteria) }),
    [rows, match, useCriteria],
  )

  // Live preview: asks the API whether the current criteria apply to the organization.
  useEffect(() => {
    if (!loaded || Object.keys(clientErrors).length > 0) {
      setPreview(null)
      return
    }
    let cancelled = false
    const timer = setTimeout(() => {
      previewCriteria(payload)
        .then((applies) => !cancelled && setPreview(applies))
        .catch(() => !cancelled && setPreview(null))
    }, 300)
    return () => {
      cancelled = true
      clearTimeout(timer)
    }
  }, [payload, clientErrors, loaded])

  // After the first save attempt, errors follow the edits.
  useEffect(() => {
    if (submitted) setErrors(clientErrors)
  }, [clientErrors, submitted])

  const mutation = useMutation({
    mutationFn: () => saveCriteria(Number(id), payload),
    onSuccess: async (response) => {
      await queryClient.invalidateQueries({ queryKey: ['requirements'] })
      await queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      navigate('/requirements', { state: { toast: response.message ?? M.saved } })
    },
    onError: (error) => {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        const serverErrors = (error.response.data?.errors ?? {}) as Record<string, string[]>
        setErrors(Object.fromEntries(Object.entries(serverErrors).map(([key, messages]) => [rowErrorKey(key), messages[0]])))
        return
      }
      setFormError(axios.isAxiosError(error) ? (error.response?.data?.message ?? error.message) : String(error))
    },
  })

  const save = () => {
    setSubmitted(true)
    setFormError(null)
    setErrors(clientErrors)
    if (Object.keys(clientErrors).length === 0) mutation.mutate()
  }

  const updateRow = (index: number, patch: Partial<ConditionRow>) =>
    setRows((current) => current.map((row, i) => (i === index ? { ...row, ...patch } : row)))

  if (userLoading) return <main className="p-8">Loading...</main>

  if (!allowed) {
    return (
      <main className="mx-auto max-w-3xl p-8">
        <h1 className="text-2xl font-bold">Applicability criteria</h1>
        <p role="alert" className="mt-4 rounded-md border border-red-300 bg-red-50 p-4 text-red-700">
          {M.forbidden}
        </p>
      </main>
    )
  }

  return (
    <main className="mx-auto max-w-4xl p-8">
      <h1 className="text-2xl font-bold">Applicability criteria</h1>
      {requirement && <p className="mt-1 text-sm text-gray-600">{requirement.name}</p>}

      {formError && (
        <p role="alert" className="mt-4 rounded-md border border-red-300 bg-red-50 p-4 text-red-700">
          {formError}
        </p>
      )}
      {hasNestedGroups && (
        <p className="mt-4 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
          These criteria contain nested groups. Saving from this page replaces them with the conditions shown below.
        </p>
      )}

      <fieldset className="mt-6 flex flex-wrap gap-6">
        <legend className="sr-only">Applicability</legend>
        <label className="flex items-center gap-2 text-sm">
          <input type="radio" name="use_criteria" checked={!useCriteria} onChange={() => setUseCriteria(false)} />
          {T.everyOrganization}
        </label>
        <label className="flex items-center gap-2 text-sm">
          <input type="radio" name="use_criteria" checked={useCriteria} onChange={() => setUseCriteria(true)} />
          {T.useCriteria}
        </label>
      </fieldset>

      {useCriteria && (
        <section className="mt-6 space-y-4">
          <div role="radiogroup" aria-label="Match" className="inline-flex rounded-md border border-gray-300 p-1">
            {(['all', 'any'] as const).map((mode) => (
              <button
                key={mode}
                type="button"
                role="radio"
                aria-checked={match === mode}
                onClick={() => setMatch(mode)}
                className={`rounded px-3 py-1 text-sm ${match === mode ? 'bg-blue-700 font-semibold text-white' : 'text-gray-700'}`}
              >
                {mode === 'all' ? T.matchAll : T.matchAny}
              </button>
            ))}
          </div>

          {rows.map((row, index) => (
            <div key={row.key} data-testid="condition-row" className="grid gap-3 rounded-md border border-gray-200 p-3 sm:grid-cols-[1fr_1fr_2fr_auto]">
              <div>
                <label className="block text-xs font-medium text-gray-600" htmlFor={`field-${row.key}`}>
                  Field
                </label>
                <select
                  id={`field-${row.key}`}
                  aria-label={`Condition ${index + 1} field`}
                  value={row.field}
                  onChange={(e) => updateRow(index, { field: e.target.value as CriteriaField | '', op: '', value: '' })}
                  className={`${control} mt-1 w-full ${border(errors[`${index}.field`])}`}
                >
                  <option value="">Select</option>
                  {(Object.keys(FIELD_LABELS) as CriteriaField[]).map((field) => (
                    <option key={field} value={field}>
                      {FIELD_LABELS[field]}
                    </option>
                  ))}
                </select>
                <FieldError message={errors[`${index}.field`]} />
              </div>
              <div>
                <label className="block text-xs font-medium text-gray-600" htmlFor={`op-${row.key}`}>
                  Operator
                </label>
                <select
                  id={`op-${row.key}`}
                  aria-label={`Condition ${index + 1} operator`}
                  value={row.op}
                  disabled={!row.field}
                  onChange={(e) => {
                    const op = e.target.value as CriteriaOperator | ''
                    updateRow(index, { op, value: emptyValue(valueKind(row.field, op)) })
                  }}
                  className={`${control} mt-1 w-full ${border(errors[`${index}.op`])}`}
                >
                  <option value="">Select</option>
                  {(row.field ? FIELD_OPERATORS[row.field] : []).map((op) => (
                    <option key={op} value={op}>
                      {OPERATOR_LABELS[op]}
                    </option>
                  ))}
                </select>
                <FieldError message={errors[`${index}.op`]} />
              </div>
              <div>
                <span className="block text-xs font-medium text-gray-600">Value</span>
                <div className="mt-1">
                  <ValueInput row={row} index={index} catalogs={catalogs} error={errors[`${index}.value`]} onChange={(value) => updateRow(index, { value })} />
                </div>
                <FieldError message={errors[`${index}.value`]} />
              </div>
              <div className="flex items-end">
                <button
                  type="button"
                  onClick={() => setRows((current) => current.filter((_, i) => i !== index))}
                  aria-label={`Remove condition ${index + 1}`}
                  className="rounded-md border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50"
                >
                  {T.remove}
                </button>
              </div>
            </div>
          ))}

          <button
            type="button"
            onClick={() => setRows((current) => [...current, newRow()])}
            className="rounded-md border border-blue-700 px-3 py-2 text-sm font-medium text-blue-700 hover:bg-blue-50"
          >
            {T.addCondition}
          </button>
        </section>
      )}

      {errors.criteria && (
        <p role="alert" className="mt-3 text-sm text-red-600">
          {errors.criteria}
        </p>
      )}

      <p data-testid="applicability-preview" className="mt-6 text-sm">
        {T.previewLabel}{' '}
        <strong className={preview === null ? 'text-gray-400' : preview ? 'text-green-700' : 'text-red-700'}>
          {preview === null ? '—' : preview ? 'Yes' : 'No'}
        </strong>
      </p>

      <button
        type="button"
        onClick={save}
        disabled={mutation.isPending || !loaded}
        className="mt-6 rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50"
      >
        {T.save}
      </button>
    </main>
  )
}
