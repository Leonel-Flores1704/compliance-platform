import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import axios from 'axios'
import { forwardRef, useMemo, useState } from 'react'
import { type FieldErrors, useForm } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { createOrganization, fetchOrganizationCatalogs, type CatalogOption } from '../features/organizations/api'
import { ORGANIZATION_LABELS as L, ORGANIZATION_MESSAGES as M } from '../features/organizations/messages'
import {
  EMPTY_ORGANIZATION_FORM,
  ORGANIZATION_FIELDS,
  organizationSchema,
  toPayload,
  type OrganizationField,
  type OrganizationFormValues,
} from '../features/organizations/schema'
import { focusFirstInvalidField } from '../features/organizations/focus'
import { isAdministrator, useCurrentUser } from '../lib/auth'

const inputClass =
  'mt-1 block w-full rounded-md border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-600'

function borderClass(hasError: boolean) {
  return hasError ? 'border-red-600' : 'border-gray-300'
}

function FieldError({ id, message }: { id: string; message?: string }) {
  if (!message) return null
  return (
    <p id={id} className="mt-1 text-sm text-red-600">
      {message}
    </p>
  )
}

// forwardRef is required so React Hook Form can read the value of the native <select>.
const Select = forwardRef<HTMLSelectElement, { id: string; options: CatalogOption[] } & React.SelectHTMLAttributes<HTMLSelectElement>>(
  function Select({ id, options, ...props }, ref) {
    return (
      <select id={id} ref={ref} {...props}>
        <option value="">Select</option>
        {options.map((option) => (
          <option key={option.code} value={option.code}>
            {option.name}
          </option>
        ))}
      </select>
    )
  },
)

export default function OrganizationCreatePage() {
  const navigate = useNavigate()
  const { data: user, isLoading: userLoading } = useCurrentUser()
  const canRegister = isAdministrator(user)
  const [formError, setFormError] = useState<string | null>(null)

  const { data: catalogs } = useQuery({
    queryKey: ['catalogs', 'organization'],
    queryFn: fetchOrganizationCatalogs,
    enabled: canRegister,
  })

  const schema = useMemo(() => organizationSchema(catalogs), [catalogs])

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<OrganizationFormValues>({
    resolver: zodResolver(schema),
    defaultValues: EMPTY_ORGANIZATION_FORM,
    shouldFocusError: false,
  })

  const onInvalid = (formErrors: FieldErrors<OrganizationFormValues>) => {
    focusFirstInvalidField(ORGANIZATION_FIELDS, formErrors)
  }

  const onValid = async (values: OrganizationFormValues) => {
    setFormError(null)
    try {
      const organization = await createOrganization(toPayload(values))
      navigate(`/organizations/${organization.id}`, { state: { toast: M.created } })
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        const serverErrors = (error.response.data?.errors ?? {}) as Record<string, string[]>
        const fieldErrors: Partial<Record<OrganizationField, string>> = {}
        for (const field of ORGANIZATION_FIELDS) {
          const message = serverErrors[field]?.[0]
          if (message) {
            fieldErrors[field] = message
            setError(field, { type: 'server', message })
          }
        }
        focusFirstInvalidField(ORGANIZATION_FIELDS, fieldErrors)
        return
      }
      if (axios.isAxiosError(error) && error.response?.status === 403) {
        setFormError(error.response.data?.message ?? M.forbidden)
        return
      }
      setFormError(axios.isAxiosError(error) ? (error.response?.data?.message ?? error.message) : String(error))
    }
  }

  if (userLoading) {
    return <main className="p-8">Loading...</main>
  }

  if (!canRegister) {
    return (
      <main className="mx-auto max-w-2xl p-8">
        <h1 className="text-2xl font-bold">Register organization</h1>
        <p role="alert" className="mt-4 rounded-md border border-red-300 bg-red-50 p-4 text-red-700">
          {M.forbidden}
        </p>
      </main>
    )
  }

  const describedBy = (field: OrganizationField) => (errors[field] ? `${field}-error` : undefined)

  return (
    <main className="mx-auto max-w-2xl p-8">
      <h1 className="text-2xl font-bold">Register organization</h1>

      {formError && (
        <p role="alert" className="mt-4 rounded-md border border-red-300 bg-red-50 p-4 text-red-700">
          {formError}
        </p>
      )}

      <form noValidate className="mt-6 space-y-5" onSubmit={handleSubmit(onValid, onInvalid)}>
        <div>
          <label htmlFor="legal_name" className="block text-sm font-medium">
            {L.legal_name}
          </label>
          <input
            id="legal_name"
            type="text"
            className={`${inputClass} ${borderClass(!!errors.legal_name)}`}
            aria-invalid={!!errors.legal_name}
            aria-describedby={describedBy('legal_name')}
            {...register('legal_name')}
          />
          <FieldError id="legal_name-error" message={errors.legal_name?.message} />
        </div>

        <div>
          <label htmlFor="organization_type" className="block text-sm font-medium">
            {L.organization_type}
          </label>
          <Select
            id="organization_type"
            options={catalogs?.organization_types ?? []}
            className={`${inputClass} ${borderClass(!!errors.organization_type)}`}
            aria-invalid={!!errors.organization_type}
            aria-describedby={describedBy('organization_type')}
            {...register('organization_type')}
          />
          <FieldError id="organization_type-error" message={errors.organization_type?.message} />
        </div>

        <div>
          <label htmlFor="industry" className="block text-sm font-medium">
            {L.industry}
          </label>
          <Select
            id="industry"
            options={catalogs?.industries ?? []}
            className={`${inputClass} ${borderClass(!!errors.industry)}`}
            aria-invalid={!!errors.industry}
            aria-describedby={describedBy('industry')}
            {...register('industry')}
          />
          <FieldError id="industry-error" message={errors.industry?.message} />
        </div>

        <div className="grid gap-5 sm:grid-cols-2">
          <div>
            <label htmlFor="state" className="block text-sm font-medium">
              {L.state}
            </label>
            <Select
              id="state"
              options={catalogs?.states ?? []}
              className={`${inputClass} ${borderClass(!!errors.state)}`}
              aria-invalid={!!errors.state}
              aria-describedby={describedBy('state')}
              {...register('state')}
            />
            <FieldError id="state-error" message={errors.state?.message} />
          </div>
          <div>
            <label htmlFor="municipality" className="block text-sm font-medium">
              {L.municipality}
            </label>
            <input
              id="municipality"
              type="text"
              className={`${inputClass} ${borderClass(!!errors.municipality)}`}
              aria-invalid={!!errors.municipality}
              aria-describedby={describedBy('municipality')}
              {...register('municipality')}
            />
            <FieldError id="municipality-error" message={errors.municipality?.message} />
          </div>
        </div>

        <div>
          <label htmlFor="employees_count" className="block text-sm font-medium">
            {L.employees_count}
          </label>
          <input
            id="employees_count"
            type="number"
            min={1}
            max={1000000}
            step={1}
            inputMode="numeric"
            className={`${inputClass} ${borderClass(!!errors.employees_count)}`}
            aria-invalid={!!errors.employees_count}
            aria-describedby={describedBy('employees_count')}
            {...register('employees_count')}
          />
          <FieldError id="employees_count-error" message={errors.employees_count?.message} />
        </div>

        <fieldset aria-describedby={describedBy('business_activities')}>
          <legend className="block text-sm font-medium">{L.business_activities}</legend>
          <div
            className={`mt-1 max-h-64 space-y-1 overflow-y-auto rounded-md border p-3 ${borderClass(!!errors.business_activities)}`}
          >
            {(catalogs?.business_activities ?? []).map((activity, index) => (
              <label key={activity.code} className="flex items-center gap-2 text-sm">
                <input
                  id={`business_activities-${index}`}
                  type="checkbox"
                  value={activity.code}
                  {...register('business_activities')}
                />
                <span>
                  {activity.code} - {activity.name}
                </span>
              </label>
            ))}
          </div>
          <FieldError id="business_activities-error" message={errors.business_activities?.message} />
        </fieldset>

        <button
          type="submit"
          disabled={isSubmitting}
          className="rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50"
        >
          Register organization
        </button>
      </form>
    </main>
  )
}
